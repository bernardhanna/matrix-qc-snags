<?php

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Insert a system comment (author "QC Agent") on a snag.
 *
 * @param int    $post_id
 * @param string $content
 * @return int|false comment id
 */
function matrix_qc_snag_add_system_comment($post_id, $content) {
    return wp_insert_comment(array(
        'comment_post_ID'      => (int) $post_id,
        'comment_content'      => wp_kses_post($content),
        'comment_type'         => 'qc_comment',
        'comment_author'       => 'QC Agent',
        'comment_author_email' => '',
        'user_id'              => 0,
        'comment_approved'     => 1,
    ));
}

/**
 * Resolve the WordPress post/page that a snag's page URL points to.
 *
 * @param array<string,mixed> $snag
 * @return int 0 when not resolvable
 */
function matrix_qc_content_target_post($snag) {
    $id = url_to_postid((string) $snag['page_url']);
    if (!$id && !empty($snag['page_path'])) {
        $id = url_to_postid(home_url((string) $snag['page_path']));
    }
    return (int) $id;
}

/**
 * Validate and resolve a content-fix request.
 *
 * @param int    $snag_id
 * @param string $new_text
 * @return array{snag:array,old:string,new:string,target:int}|WP_Error
 */
function matrix_qc_content_resolve($snag_id, $new_text) {
    $post = get_post($snag_id);
    if (!$post || $post->post_type !== MATRIX_QC_SNAG_CPT) {
        return new WP_Error('not_found', 'Snag not found.');
    }
    $snag = matrix_qc_snag_to_array($post);
    $old  = trim((string) $snag['element_text']);
    $new  = trim((string) $new_text);

    if ($old === '') {
        return new WP_Error('no_old', 'This snag has no captured element text to match. Fix via the agent instead.');
    }
    if ($new === '') {
        return new WP_Error('no_new', 'Enter the replacement text first (and save the snag).');
    }
    if ($old === $new) {
        return new WP_Error('same', 'The replacement is identical to the current text.');
    }
    $target = matrix_qc_content_target_post($snag);
    if (!$target) {
        return new WP_Error('no_target', 'Could not resolve this page to an editable post/page (front page, archive, or template).');
    }
    return array('snag' => $snag, 'old' => $old, 'new' => $new, 'target' => $target);
}

/**
 * Find every place the old text occurs in the target post's content + meta.
 *
 * @param int    $target
 * @param string $old
 * @return array<int,array<string,mixed>> each: type, label, before, count, key?
 */
function matrix_qc_content_scan($target, $old) {
    $found   = array();
    $content = (string) get_post_field('post_content', $target);
    if ($content !== '' && mb_strpos($content, $old) !== false) {
        $found[] = array(
            'type'   => 'post',
            'label'  => 'Page content',
            'before' => $content,
            'count'  => substr_count($content, $old),
        );
    }

    $all = get_post_meta($target);
    foreach ($all as $key => $values) {
        foreach ((array) $values as $value) {
            if (is_string($value) && $value !== '' && mb_strpos($value, $old) !== false) {
                $found[] = array(
                    'type'   => 'meta',
                    'key'    => $key,
                    'label'  => 'Field: ' . $key,
                    'before' => $value,
                    'count'  => substr_count($value, $old),
                );
            }
        }
    }
    return $found;
}

/**
 * Build a short before/after context snippet around the first match.
 *
 * @param string $haystack
 * @param string $old
 * @param string $new
 * @return string HTML-escaped snippet
 */
function matrix_qc_content_snippet($haystack, $old, $new) {
    $pos = mb_strpos($haystack, $old);
    if ($pos === false) {
        return '';
    }
    $start  = max(0, $pos - 30);
    $before = mb_substr($haystack, $start, $pos - $start);
    $after  = mb_substr($haystack, $pos + mb_strlen($old), 30);

    return ($start > 0 ? '&hellip;' : '') . esc_html($before)
        . '<del style="color:#b32d2e">' . esc_html($old) . '</del> '
        . '<ins style="color:#1d6b3f;text-decoration:none">' . esc_html($new) . '</ins>'
        . esc_html($after) . '&hellip;';
}

/**
 * Compute a no-write preview of a content fix.
 *
 * @param int    $snag_id
 * @param string $new_text
 * @return array<string,mixed>|WP_Error
 */
function matrix_qc_content_preview($snag_id, $new_text) {
    $r = matrix_qc_content_resolve($snag_id, $new_text);
    if (is_wp_error($r)) {
        return $r;
    }
    $matches = matrix_qc_content_scan($r['target'], $r['old']);
    return array(
        'target'  => $r['target'],
        'title'   => get_the_title($r['target']),
        'old'     => $r['old'],
        'new'     => $r['new'],
        'matches' => $matches,
        'total'   => array_sum(wp_list_pluck($matches, 'count')),
    );
}

/**
 * Apply a low-risk content fix and store a before snapshot for revert.
 *
 * @param int    $snag_id
 * @param string $new_text
 * @return array<string,mixed>|WP_Error
 */
function matrix_qc_content_apply($snag_id, $new_text) {
    $r = matrix_qc_content_resolve($snag_id, $new_text);
    if (is_wp_error($r)) {
        return $r;
    }
    $target = $r['target'];
    $old    = $r['old'];
    $new    = $r['new'];

    $scan = matrix_qc_content_scan($target, $old);
    if (empty($scan)) {
        return new WP_Error('not_matched', 'Couldn\'t find that exact text in the page content or fields. It may be split across elements, come from a global/option, or live in code — use the agent for those.');
    }

    $changes = array();
    foreach ($scan as $c) {
        if ($c['type'] === 'post') {
            wp_update_post(array('ID' => $target, 'post_content' => str_replace($old, $new, $c['before'])));
            $changes[] = array('type' => 'post', 'before' => $c['before']);
        } else {
            update_post_meta($target, $c['key'], str_replace($old, $new, $c['before']), $c['before']);
            $changes[] = array('type' => 'meta', 'key' => $c['key'], 'before' => $c['before']);
        }
    }

    $payload = array(
        'target'  => $target,
        'old'     => $old,
        'new'     => $new,
        'changes' => $changes,
        'applied' => current_time('mysql'),
        'by'      => get_current_user_id(),
    );
    update_post_meta($snag_id, '_qc_revert_payload', wp_json_encode($payload));
    update_post_meta($snag_id, '_qc_fix_text', $new);
    update_post_meta($snag_id, '_qc_status', 'fixed');

    matrix_qc_snag_add_system_comment(
        $snag_id,
        sprintf('Content fix applied directly to "%s": "%s" → "%s" (%d field(s)).', get_the_title($target), $old, $new, count($changes))
    );

    return array('changes' => count($changes), 'target' => $target);
}

/**
 * Revert a previously applied content fix from its stored snapshot.
 *
 * @param int $snag_id
 * @return true|WP_Error
 */
function matrix_qc_content_revert($snag_id) {
    $raw     = (string) get_post_meta($snag_id, '_qc_revert_payload', true);
    $payload = json_decode($raw, true);
    if (empty($payload['changes']) || empty($payload['target'])) {
        return new WP_Error('no_payload', 'No content revert data stored for this snag.');
    }
    $target = (int) $payload['target'];

    foreach ($payload['changes'] as $change) {
        if (($change['type'] ?? '') === 'post') {
            wp_update_post(array('ID' => $target, 'post_content' => (string) $change['before']));
        } elseif (($change['type'] ?? '') === 'meta' && !empty($change['key'])) {
            update_post_meta($target, $change['key'], (string) $change['before']);
        }
    }

    update_post_meta($snag_id, '_qc_status', 'reverted');
    delete_post_meta($snag_id, '_qc_revert_payload');
    matrix_qc_snag_add_system_comment($snag_id, 'Content fix reverted to the previous values.');

    return true;
}

/**
 * Transient key for a stored preview.
 *
 * @param int $snag_id
 * @return string
 */
function matrix_qc_content_preview_key($snag_id) {
    return 'matrix_qc_preview_' . get_current_user_id() . '_' . (int) $snag_id;
}

/**
 * admin-post: compute a preview and stash it for the edit screen.
 */
function matrix_qc_content_handle_preview() {
    if (!current_user_can(MATRIX_QC_SNAG_CAP)) {
        wp_die('Forbidden');
    }
    check_admin_referer('matrix_qc_content_fix');
    $id      = isset($_GET['snag']) ? absint($_GET['snag']) : 0;
    $new     = (string) get_post_meta($id, '_qc_fix_text', true);
    $preview = matrix_qc_content_preview($id, $new);

    if (is_wp_error($preview)) {
        matrix_qc_agent_redirect_with_notice(get_edit_post_link($id, 'url'), 'Preview failed: ' . $preview->get_error_message());
    }
    set_transient(matrix_qc_content_preview_key($id), $preview, 300);
    wp_safe_redirect(get_edit_post_link($id, 'url'));
    exit;
}
add_action('admin_post_matrix_qc_content_preview', 'matrix_qc_content_handle_preview');

/**
 * Render a stored preview (called from the snag metabox). Clears it after.
 *
 * @param int $snag_id
 */
function matrix_qc_content_render_preview($snag_id) {
    $key     = matrix_qc_content_preview_key($snag_id);
    $preview = get_transient($key);
    if (!$preview || !is_array($preview)) {
        return;
    }
    delete_transient($key);

    echo '<div style="border:1px solid #c3c4c7;border-left:4px solid #2271b1;background:#fff;padding:10px 12px;margin:8px 0">';
    echo '<p style="margin:0 0 6px"><strong>Preview</strong> &mdash; target: <em>' . esc_html($preview['title']) . '</em>, ' . (int) $preview['total'] . ' occurrence(s) across ' . count($preview['matches']) . ' field(s).</p>';
    if (empty($preview['matches'])) {
        echo '<p style="margin:0;color:#b32d2e">No matches found &mdash; applying would do nothing. The text may be split across elements or come from code.</p>';
    } else {
        echo '<table class="widefat striped" style="margin:6px 0"><thead><tr><th>Where</th><th>Count</th><th>Change</th></tr></thead><tbody>';
        foreach ($preview['matches'] as $m) {
            echo '<tr><td><code>' . esc_html($m['label']) . '</code></td><td>' . (int) $m['count'] . '</td><td>'
                . matrix_qc_content_snippet((string) $m['before'], (string) $preview['old'], (string) $preview['new'])
                . '</td></tr>';
        }
        echo '</tbody></table>';
        echo '<p style="margin:0" class="description">Review above, then click <strong>Apply content fix</strong>.</p>';
    }
    echo '</div>';
}

/**
 * admin-post: apply a content fix.
 */
function matrix_qc_content_handle_apply() {
    if (!current_user_can(MATRIX_QC_SNAG_CAP)) {
        wp_die('Forbidden');
    }
    check_admin_referer('matrix_qc_content_fix');
    $id  = isset($_GET['snag']) ? absint($_GET['snag']) : 0;
    $new = (string) get_post_meta($id, '_qc_fix_text', true);
    $res = matrix_qc_content_apply($id, $new);
    matrix_qc_agent_redirect_with_notice(
        get_edit_post_link($id, 'url'),
        is_wp_error($res)
            ? 'Content fix failed: ' . $res->get_error_message()
            : sprintf('Content fix applied (%d field(s) changed). Use Revert to undo.', $res['changes'])
    );
}
add_action('admin_post_matrix_qc_content_apply', 'matrix_qc_content_handle_apply');

/**
 * admin-post: revert a content fix.
 */
function matrix_qc_content_handle_revert() {
    if (!current_user_can(MATRIX_QC_SNAG_CAP)) {
        wp_die('Forbidden');
    }
    check_admin_referer('matrix_qc_content_fix');
    $id  = isset($_GET['snag']) ? absint($_GET['snag']) : 0;
    $res = matrix_qc_content_revert($id);
    matrix_qc_agent_redirect_with_notice(
        get_edit_post_link($id, 'url'),
        is_wp_error($res) ? 'Revert failed: ' . $res->get_error_message() : 'Content fix reverted.'
    );
}
add_action('admin_post_matrix_qc_content_revert', 'matrix_qc_content_handle_revert');
