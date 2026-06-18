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
 * Apply a low-risk content fix: replace the captured element text with the
 * provided text in the target post's content and ACF/meta fields. Stores a
 * before snapshot for one-click revert. No git involved.
 *
 * @param int    $snag_id
 * @param string $new_text
 * @return array<string,mixed>|WP_Error
 */
function matrix_qc_content_apply($snag_id, $new_text) {
    $post = get_post($snag_id);
    if (!$post || $post->post_type !== MATRIX_QC_SNAG_CPT) {
        return new WP_Error('not_found', 'Snag not found.');
    }
    $snag = matrix_qc_snag_to_array($post);

    $old = trim((string) $snag['element_text']);
    $new = trim((string) $new_text);
    if ($old === '') {
        return new WP_Error('no_old', 'This snag has no captured element text to match. Capture a snag on a text element, or fix via the agent instead.');
    }
    if ($new === '') {
        return new WP_Error('no_new', 'Enter the replacement text first (and save the snag).');
    }
    if ($old === $new) {
        return new WP_Error('same', 'The replacement is identical to the current text.');
    }

    $target = matrix_qc_content_target_post($snag);
    if (!$target) {
        return new WP_Error('no_target', 'Could not resolve this page to an editable post/page (it may be the front page, an archive, or a template).');
    }

    $changes = array();

    $content = (string) get_post_field('post_content', $target);
    if ($content !== '' && mb_strpos($content, $old) !== false) {
        $changes[] = array('type' => 'post', 'before' => $content);
        wp_update_post(array('ID' => $target, 'post_content' => str_replace($old, $new, $content)));
    }

    $all = get_post_meta($target);
    foreach ($all as $key => $values) {
        foreach ((array) $values as $value) {
            if (is_string($value) && $value !== '' && mb_strpos($value, $old) !== false) {
                $changes[] = array('type' => 'meta', 'key' => $key, 'before' => $value);
                update_post_meta($target, $key, str_replace($old, $new, $value), $value);
            }
        }
    }

    if (empty($changes)) {
        return new WP_Error('not_matched', 'Couldn\'t find that exact text in the page content or fields. It may be split across elements, come from a global/option, or live in code — use the agent for those.');
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
