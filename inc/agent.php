<?php

if (!defined('ABSPATH')) {
    exit;
}

define('MATRIX_QC_AGENT_ENDPOINT', 'https://api.cursor.com/v1/agents');
define('MATRIX_QC_AGENT_CRON', 'matrix_qc_agent_poll_event');

/**
 * Agent configuration from options (with sensible defaults).
 *
 * @return array<string,mixed>
 */
function matrix_qc_agent_config() {
    return array(
        'api_key' => (string) get_option('matrix_qc_agent_api_key', ''),
        'repo'    => (string) get_option('matrix_qc_agent_repo', 'https://github.com/bernardhanna/st-patricks'),
        'ref'     => (string) get_option('matrix_qc_agent_ref', 'main'),
        'model'   => (string) get_option('matrix_qc_agent_model', ''),
        'auto_pr' => get_option('matrix_qc_agent_autopr', '1') === '1',
    );
}

/**
 * Whether the agent integration is configured.
 *
 * @return bool
 */
function matrix_qc_agent_ready() {
    $cfg = matrix_qc_agent_config();
    return $cfg['api_key'] !== '' && $cfg['repo'] !== '';
}

/**
 * Call the Cursor Cloud Agents API to create an agent.
 *
 * @param string $prompt
 * @return array<string,mixed>|WP_Error
 */
function matrix_qc_agent_create($prompt) {
    $cfg = matrix_qc_agent_config();
    if ($cfg['api_key'] === '') {
        return new WP_Error('no_key', 'Set the Cursor API key in QC Snags > Agent.');
    }

    $body = array(
        'prompt'       => array('text' => $prompt),
        'repos'        => array(array('url' => $cfg['repo'], 'startingRef' => $cfg['ref'])),
        'autoCreatePR' => (bool) $cfg['auto_pr'],
    );

    $model = matrix_qc_agent_valid_model($cfg['model']);
    if ($model !== '') {
        $body['model'] = array('id' => $model);
    }

    $resp = wp_remote_post(MATRIX_QC_AGENT_ENDPOINT, array(
        'timeout' => 30,
        'headers' => array(
            'Authorization' => 'Basic ' . base64_encode($cfg['api_key'] . ':'),
            'Content-Type'  => 'application/json',
        ),
        'body'    => wp_json_encode($body),
    ));

    if (is_wp_error($resp)) {
        return $resp;
    }
    $code = wp_remote_retrieve_response_code($resp);
    $data = json_decode(wp_remote_retrieve_body($resp), true);
    if ($code < 200 || $code >= 300) {
        return new WP_Error('api_error', 'Cursor API ' . $code . ': ' . wp_remote_retrieve_body($resp));
    }

    return is_array($data) ? $data : array();
}

/**
 * Return the model id only if the account actually offers it; otherwise ''.
 * Keeps dispatch resilient against a stale/invalid saved model id.
 *
 * @param string $model
 * @return string
 */
function matrix_qc_agent_valid_model($model) {
    if ($model === '') {
        return '';
    }
    $models = matrix_qc_agent_models();
    if (is_wp_error($models) || empty($models)) {
        return $model;
    }
    foreach ($models as $m) {
        if (isset($m['id']) && $m['id'] === $model) {
            return $model;
        }
        if (!empty($m['aliases']) && is_array($m['aliases']) && in_array($model, $m['aliases'], true)) {
            return $model;
        }
    }
    if ((string) get_option('matrix_qc_agent_model', '') === $model) {
        update_option('matrix_qc_agent_model', '');
    }
    return '';
}

/**
 * List models available to the account.
 *
 * @return array<int,array<string,mixed>>|WP_Error
 */
function matrix_qc_agent_models() {
    $cfg = matrix_qc_agent_config();
    if ($cfg['api_key'] === '') {
        return new WP_Error('no_key', 'No API key');
    }
    $resp = wp_remote_get('https://api.cursor.com/v1/models', array(
        'timeout' => 20,
        'headers' => array(
            'Authorization' => 'Basic ' . base64_encode($cfg['api_key'] . ':'),
        ),
    ));
    if (is_wp_error($resp)) {
        return $resp;
    }
    $code = wp_remote_retrieve_response_code($resp);
    $data = json_decode(wp_remote_retrieve_body($resp), true);
    if ($code < 200 || $code >= 300) {
        return new WP_Error('api_error', 'Cursor API ' . $code . ': ' . wp_remote_retrieve_body($resp));
    }
    return isset($data['items']) && is_array($data['items']) ? $data['items'] : array();
}

/**
 * Validate the API key by calling the identity endpoint.
 *
 * @return array<string,mixed>|WP_Error
 */
function matrix_qc_agent_me() {
    $cfg = matrix_qc_agent_config();
    if ($cfg['api_key'] === '') {
        return new WP_Error('no_key', 'Save an API key first, then test.');
    }
    $resp = wp_remote_get('https://api.cursor.com/v1/me', array(
        'timeout' => 20,
        'headers' => array(
            'Authorization' => 'Basic ' . base64_encode($cfg['api_key'] . ':'),
        ),
    ));
    if (is_wp_error($resp)) {
        return $resp;
    }
    $code = wp_remote_retrieve_response_code($resp);
    $data = json_decode(wp_remote_retrieve_body($resp), true);
    if ($code < 200 || $code >= 300) {
        return new WP_Error('api_error', 'Cursor API ' . $code . ': ' . wp_remote_retrieve_body($resp));
    }
    return is_array($data) ? $data : array();
}

/**
 * Fetch an agent's current record (to read PR/branch status).
 *
 * @param string $agent_id
 * @return array<string,mixed>|WP_Error
 */
function matrix_qc_agent_get($agent_id) {
    $cfg = matrix_qc_agent_config();
    if ($cfg['api_key'] === '') {
        return new WP_Error('no_key', 'No API key');
    }
    $resp = wp_remote_get(MATRIX_QC_AGENT_ENDPOINT . '/' . rawurlencode($agent_id), array(
        'timeout' => 20,
        'headers' => array(
            'Authorization' => 'Basic ' . base64_encode($cfg['api_key'] . ':'),
        ),
    ));
    if (is_wp_error($resp)) {
        return $resp;
    }
    $data = json_decode(wp_remote_retrieve_body($resp), true);
    return is_array($data) ? $data : array();
}

/**
 * Extract a PR URL from an agent record, if one exists yet.
 *
 * @param array<string,mixed> $info
 * @return string
 */
function matrix_qc_agent_extract_pr($info) {
    if (!empty($info['target']['prUrl'])) {
        return (string) $info['target']['prUrl'];
    }
    if (!empty($info['git']['branches']) && is_array($info['git']['branches'])) {
        foreach ($info['git']['branches'] as $branch) {
            if (!empty($branch['prUrl'])) {
                return (string) $branch['prUrl'];
            }
        }
    }
    return '';
}

/**
 * Build the prompt for a single snag.
 *
 * @param array<string,mixed> $snag
 * @return string
 */
function matrix_qc_agent_prompt_single($snag) {
    return "You are fixing a single QC snag on a WordPress theme (ACF blocks + Tailwind). "
        . "Make the minimal, focused change to resolve it, matching the existing code patterns and the Figma reference. "
        . "Do not refactor unrelated code. When done, open a pull request.\n\n"
        . matrix_qc_snag_agent_instruction($snag);
}

/**
 * Dispatch a single snag to the agent.
 *
 * @param int $id
 * @return array<string,mixed>|WP_Error
 */
function matrix_qc_agent_dispatch_snag($id) {
    $post = get_post($id);
    if (!$post || $post->post_type !== MATRIX_QC_SNAG_CPT) {
        return new WP_Error('not_found', 'Snag not found');
    }
    $snag = matrix_qc_snag_to_array($post);
    $res  = matrix_qc_agent_create(matrix_qc_agent_prompt_single($snag));
    if (is_wp_error($res)) {
        return $res;
    }
    $agent = matrix_qc_agent_obj($res);
    update_post_meta($id, '_qc_agent_id', isset($agent['id']) ? (string) $agent['id'] : '');
    update_post_meta($id, '_qc_agent_url', matrix_qc_agent_url($agent));
    update_post_meta($id, '_qc_status', 'in_progress');
    matrix_qc_agent_ensure_cron();
    return $res;
}

/**
 * The Create response nests the agent under `agent`; normalise it.
 *
 * @param array<string,mixed> $res
 * @return array<string,mixed>
 */
function matrix_qc_agent_obj($res) {
    if (isset($res['agent']) && is_array($res['agent'])) {
        return $res['agent'];
    }
    return is_array($res) ? $res : array();
}

/**
 * Best-effort agent dashboard URL (API may omit `url`).
 *
 * @param array<string,mixed> $agent
 * @return string
 */
function matrix_qc_agent_url($agent) {
    if (!empty($agent['url'])) {
        return (string) $agent['url'];
    }
    if (!empty($agent['id'])) {
        return 'https://cursor.com/agents/' . rawurlencode((string) $agent['id']);
    }
    return '';
}

/**
 * Dispatch all open snags as a single batch agent (one PR).
 *
 * @return array<string,mixed>|WP_Error
 */
function matrix_qc_agent_dispatch_open() {
    $snags = matrix_qc_snag_fetch_sorted(true);
    if (empty($snags)) {
        return new WP_Error('none', 'No open snags to dispatch.');
    }
    $prompt = "You are fixing a batch of QC snags on a WordPress theme (ACF blocks + Tailwind). "
        . "Address each snag with minimal, focused changes that match existing patterns and the Figma references, grouped by template where possible. "
        . "Do not refactor unrelated code. When done, open a single pull request.\n\n"
        . matrix_qc_snag_build_brief($snags);

    $res = matrix_qc_agent_create($prompt);
    if (is_wp_error($res)) {
        return $res;
    }

    $agent = matrix_qc_agent_obj($res);
    $aid   = isset($agent['id']) ? (string) $agent['id'] : '';
    $url   = matrix_qc_agent_url($agent);
    foreach ($snags as $s) {
        update_post_meta($s['id'], '_qc_agent_id', $aid);
        update_post_meta($s['id'], '_qc_agent_url', $url);
        update_post_meta($s['id'], '_qc_status', 'in_progress');
    }
    matrix_qc_agent_ensure_cron();
    return $res;
}

/**
 * Poll in-progress agents and capture PR URLs.
 */
function matrix_qc_agent_poll() {
    if (!matrix_qc_agent_ready()) {
        return;
    }
    $query = new WP_Query(array(
        'post_type'      => MATRIX_QC_SNAG_CPT,
        'post_status'    => 'publish',
        'posts_per_page' => -1,
        'no_found_rows'  => true,
        'meta_query'     => array(
            'relation' => 'AND',
            array('key' => '_qc_status', 'value' => 'in_progress'),
            array('key' => '_qc_agent_id', 'value' => '', 'compare' => '!='),
        ),
    ));

    $by_agent = array();
    foreach ($query->posts as $post) {
        $aid = get_post_meta($post->ID, '_qc_agent_id', true);
        if ($aid !== '') {
            $by_agent[$aid][] = $post->ID;
        }
    }

    foreach ($by_agent as $aid => $ids) {
        $info = matrix_qc_agent_get($aid);
        if (is_wp_error($info)) {
            continue;
        }
        $pr = matrix_qc_agent_extract_pr($info);
        if ($pr !== '') {
            foreach ($ids as $sid) {
                update_post_meta($sid, '_qc_pr_url', $pr);
                update_post_meta($sid, '_qc_status', 'pr_open');
            }
        }
    }
}
add_action(MATRIX_QC_AGENT_CRON, 'matrix_qc_agent_poll');

/**
 * Add a 5-minute cron interval.
 *
 * @param array<string,array<string,mixed>> $schedules
 * @return array<string,array<string,mixed>>
 */
function matrix_qc_agent_cron_interval($schedules) {
    $schedules['matrix_qc_5min'] = array('interval' => 300, 'display' => 'Every 5 minutes');
    return $schedules;
}
add_filter('cron_schedules', 'matrix_qc_agent_cron_interval');

/**
 * Ensure the polling cron is scheduled.
 */
function matrix_qc_agent_ensure_cron() {
    if (!wp_next_scheduled(MATRIX_QC_AGENT_CRON)) {
        wp_schedule_event(time() + 120, 'matrix_qc_5min', MATRIX_QC_AGENT_CRON);
    }
}

/**
 * admin-post: dispatch a single snag.
 */
function matrix_qc_agent_handle_dispatch() {
    if (!current_user_can(MATRIX_QC_SNAG_CAP)) {
        wp_die('Forbidden');
    }
    check_admin_referer('matrix_qc_agent_dispatch');
    $id   = isset($_GET['snag']) ? absint($_GET['snag']) : 0;
    $res  = matrix_qc_agent_dispatch_snag($id);
    $dest = wp_get_referer();
    if (!$dest) {
        $dest = get_edit_post_link($id, 'url');
    }
    matrix_qc_agent_redirect_with_notice(
        $dest,
        is_wp_error($res) ? $res->get_error_message() : 'Snag dispatched to the agent.'
    );
}
add_action('admin_post_matrix_qc_agent_dispatch', 'matrix_qc_agent_handle_dispatch');

/**
 * admin-post: check a single snag's agent status now.
 */
function matrix_qc_agent_handle_check() {
    if (!current_user_can(MATRIX_QC_SNAG_CAP)) {
        wp_die('Forbidden');
    }
    check_admin_referer('matrix_qc_agent_dispatch');
    $id  = isset($_GET['snag']) ? absint($_GET['snag']) : 0;
    $aid = $id ? get_post_meta($id, '_qc_agent_id', true) : '';

    if ($aid === '') {
        matrix_qc_agent_redirect_with_notice(get_edit_post_link($id, 'url'), 'No agent has been dispatched for this snag yet.');
    }

    $info = matrix_qc_agent_get($aid);
    if (is_wp_error($info)) {
        matrix_qc_agent_redirect_with_notice(get_edit_post_link($id, 'url'), 'Check failed: ' . $info->get_error_message());
    }

    $agent_status = isset($info['status']) ? (string) $info['status'] : 'unknown';
    $pr           = matrix_qc_agent_extract_pr($info);
    if ($pr !== '') {
        update_post_meta($id, '_qc_pr_url', $pr);
        update_post_meta($id, '_qc_status', 'pr_open');
        matrix_qc_agent_redirect_with_notice(get_edit_post_link($id, 'url'), 'Agent ' . $agent_status . '. PR opened: ' . $pr);
    }

    $branch = '';
    if (!empty($info['git']['branches'][0]['branch'])) {
        $branch = (string) $info['git']['branches'][0]['branch'];
    }
    $suffix = $branch !== '' ? ' Branch pushed: ' . $branch . ' (no PR opened yet).' : ' No branch pushed yet.';
    matrix_qc_agent_redirect_with_notice(
        get_edit_post_link($id, 'url'),
        'Agent status: ' . $agent_status . '.' . $suffix
    );
}
add_action('admin_post_matrix_qc_agent_check', 'matrix_qc_agent_handle_check');

/**
 * admin-post: dispatch all open snags as one batch.
 */
function matrix_qc_agent_handle_dispatch_open() {
    if (!current_user_can(MATRIX_QC_SNAG_CAP)) {
        wp_die('Forbidden');
    }
    check_admin_referer('matrix_qc_agent_dispatch');
    $res     = matrix_qc_agent_dispatch_open();
    $dest    = admin_url('edit.php?post_type=' . MATRIX_QC_SNAG_CPT . '&page=matrix-qc-dashboard');
    $message = is_wp_error($res)
        ? $res->get_error_message()
        : 'Dispatched open snags to the agent' . (isset($res['url']) ? '. Track: ' . $res['url'] : '.');
    matrix_qc_agent_redirect_with_notice($dest, $message);
}
add_action('admin_post_matrix_qc_agent_dispatch_open', 'matrix_qc_agent_handle_dispatch_open');

/**
 * Redirect back with a transient notice.
 *
 * @param string $url
 * @param string $message
 */
function matrix_qc_agent_redirect_with_notice($url, $message) {
    set_transient('matrix_qc_agent_notice_' . get_current_user_id(), $message, 60);
    wp_safe_redirect($url ? $url : admin_url());
    exit;
}

/**
 * Show the transient notice in admin.
 */
function matrix_qc_agent_admin_notice() {
    $key    = 'matrix_qc_agent_notice_' . get_current_user_id();
    $notice = get_transient($key);
    if ($notice) {
        delete_transient($key);
        echo '<div class="notice notice-info is-dismissible"><p>' . esc_html($notice) . '</p></div>';
    }
}
add_action('admin_notices', 'matrix_qc_agent_admin_notice');

/**
 * Add a "Send to agent" row action on the snag list.
 *
 * @param array<string,string> $actions
 * @param WP_Post              $post
 * @return array<string,string>
 */
function matrix_qc_agent_row_action($actions, $post) {
    if ($post->post_type !== MATRIX_QC_SNAG_CPT || !current_user_can(MATRIX_QC_SNAG_CAP) || !matrix_qc_agent_ready()) {
        return $actions;
    }
    $url = wp_nonce_url(
        admin_url('admin-post.php?action=matrix_qc_agent_dispatch&snag=' . $post->ID),
        'matrix_qc_agent_dispatch'
    );
    $label = get_post_meta($post->ID, '_qc_agent_id', true) !== '' ? 'Re-send to agent' : 'Send to agent';
    $actions['matrix_qc_agent'] = '<a href="' . esc_url($url) . '">' . esc_html($label) . '</a>';
    return $actions;
}
add_filter('post_row_actions', 'matrix_qc_agent_row_action', 10, 2);

/**
 * Register the bulk "Send to agent" action.
 *
 * @param array<string,string> $actions
 * @return array<string,string>
 */
function matrix_qc_agent_bulk_action($actions) {
    if (matrix_qc_agent_ready()) {
        $actions['matrix_qc_agent_send'] = 'Send to agent (one PR each)';
    }
    return $actions;
}
add_filter('bulk_actions-edit-' . MATRIX_QC_SNAG_CPT, 'matrix_qc_agent_bulk_action');

/**
 * Handle the bulk "Send to agent" action.
 *
 * @param string     $redirect
 * @param string     $action
 * @param array<int> $ids
 * @return string
 */
function matrix_qc_agent_bulk_handle($redirect, $action, $ids) {
    if ($action !== 'matrix_qc_agent_send') {
        return $redirect;
    }
    if (!current_user_can(MATRIX_QC_SNAG_CAP)) {
        wp_die('Forbidden');
    }

    $sent   = 0;
    $errors = array();
    foreach ($ids as $id) {
        $res = matrix_qc_agent_dispatch_snag((int) $id);
        if (is_wp_error($res)) {
            $errors[] = $res->get_error_message();
        } else {
            $sent++;
        }
    }

    $message = $sent . ' snag' . ($sent === 1 ? '' : 's') . ' dispatched to the agent.';
    if (!empty($errors)) {
        $message .= ' ' . count($errors) . ' failed: ' . implode('; ', array_unique($errors));
    }
    matrix_qc_agent_redirect_with_notice($redirect, $message);
    return $redirect;
}
add_filter('handle_bulk_actions-edit-' . MATRIX_QC_SNAG_CPT, 'matrix_qc_agent_bulk_handle', 10, 3);

/**
 * Register the Agent settings submenu.
 */
function matrix_qc_agent_menu() {
    add_submenu_page(
        'edit.php?post_type=' . MATRIX_QC_SNAG_CPT,
        'QC Agent',
        'Agent',
        MATRIX_QC_SNAG_CAP,
        'matrix-qc-agent',
        'matrix_qc_agent_settings_page'
    );
}
add_action('admin_menu', 'matrix_qc_agent_menu');

/**
 * Render the Agent settings page.
 */
function matrix_qc_agent_settings_page() {
    if (isset($_POST['matrix_qc_agent_save']) &&
        check_admin_referer('matrix_qc_agent_settings', 'matrix_qc_agent_settings_nonce')) {
        update_option('matrix_qc_agent_api_key', sanitize_text_field(wp_unslash($_POST['matrix_qc_agent_api_key'] ?? '')));
        update_option('matrix_qc_agent_repo', esc_url_raw(wp_unslash($_POST['matrix_qc_agent_repo'] ?? '')));
        update_option('matrix_qc_agent_ref', sanitize_text_field(wp_unslash($_POST['matrix_qc_agent_ref'] ?? 'main')));
        update_option('matrix_qc_agent_model', sanitize_text_field(wp_unslash($_POST['matrix_qc_agent_model'] ?? '')));
        update_option('matrix_qc_agent_autopr', isset($_POST['matrix_qc_agent_autopr']) ? '1' : '0');
        echo '<div class="notice notice-success"><p>Settings saved.</p></div>';
    }

    if (isset($_POST['matrix_qc_agent_test']) &&
        check_admin_referer('matrix_qc_agent_settings', 'matrix_qc_agent_settings_nonce')) {
        $me = matrix_qc_agent_me();
        if (is_wp_error($me)) {
            echo '<div class="notice notice-error"><p>Connection failed: ' . esc_html($me->get_error_message()) . '</p></div>';
        } else {
            $who = '';
            foreach (array('userEmail', 'email', 'apiKeyName', 'userId') as $k) {
                if (!empty($me[$k])) {
                    $who = (string) $me[$k];
                    break;
                }
            }
            echo '<div class="notice notice-success"><p>Connection OK' . ($who !== '' ? ' &mdash; authenticated as <strong>' . esc_html($who) . '</strong>' : '') . '.</p></div>';
        }
    }

    $cfg = matrix_qc_agent_config();
    echo '<div class="wrap"><h1>QC Agent</h1>';
    echo '<p>Connects flagged snags to the Cursor Cloud Agents API. The agent works on the theme repo and opens a pull request per dispatch.</p>';
    echo '<form method="post">';
    wp_nonce_field('matrix_qc_agent_settings', 'matrix_qc_agent_settings_nonce');
    echo '<table class="form-table"><tbody>';

    printf(
        '<tr><th><label>Cursor API key</label></th><td><input type="password" name="matrix_qc_agent_api_key" value="%s" class="regular-text" autocomplete="off" /><p class="description">Create an Integrations API key in the Cursor dashboard. Stored in the WP database, never in git.</p></td></tr>',
        esc_attr($cfg['api_key'])
    );
    printf(
        '<tr><th><label>Repository URL</label></th><td><input type="url" name="matrix_qc_agent_repo" value="%s" class="regular-text" /></td></tr>',
        esc_attr($cfg['repo'])
    );
    printf(
        '<tr><th><label>Base branch / ref</label></th><td><input type="text" name="matrix_qc_agent_ref" value="%s" class="regular-text" /></td></tr>',
        esc_attr($cfg['ref'])
    );
    $models = $cfg['api_key'] !== '' ? matrix_qc_agent_models() : new WP_Error('no_key', '');
    echo '<tr><th><label>Model</label></th><td>';
    if (!is_wp_error($models) && !empty($models)) {
        echo '<select name="matrix_qc_agent_model" class="regular-text">';
        echo '<option value="">(account default)</option>';
        $found = false;
        foreach ($models as $m) {
            $id = isset($m['id']) ? (string) $m['id'] : '';
            if ($id === '') {
                continue;
            }
            $name = isset($m['displayName']) ? (string) $m['displayName'] : $id;
            if ($id === $cfg['model']) {
                $found = true;
            }
            printf(
                '<option value="%s" %s>%s</option>',
                esc_attr($id),
                selected($cfg['model'], $id, false),
                esc_html($name . ' (' . $id . ')')
            );
        }
        if (!$found && $cfg['model'] !== '') {
            printf('<option value="%s" selected>%s (saved, not in list)</option>', esc_attr($cfg['model']), esc_html($cfg['model']));
        }
        echo '</select><p class="description">Models your account can use. Leave on default if unsure.</p>';
    } else {
        $hint = is_wp_error($models) && $models->get_error_code() !== 'no_key'
            ? ' <span class="description">Could not load models: ' . esc_html($models->get_error_message()) . '</span>'
            : ' <span class="description">Save a valid API key to load the model list.</span>';
        printf(
            '<input type="text" name="matrix_qc_agent_model" value="%s" class="regular-text" placeholder="(account default)" />%s',
            esc_attr($cfg['model']),
            $hint
        );
    }
    echo '</td></tr>';
    printf(
        '<tr><th><label>Auto-create PR</label></th><td><label><input type="checkbox" name="matrix_qc_agent_autopr" %s /> Have the agent open a pull request automatically</label><p class="description">Each dispatch works on a new <code>cursor/&hellip;</code> branch off the base branch and opens a PR &mdash; your base branch is never committed to directly.</p></td></tr>',
        checked($cfg['auto_pr'], true, false)
    );

    echo '</tbody></table>';
    echo '<p><button class="button button-primary" name="matrix_qc_agent_save" value="1">Save settings</button> ';
    echo '<button class="button" name="matrix_qc_agent_test" value="1">Test connection</button></p>';
    echo '</form>';
    echo '<p>' . ($cfg['api_key'] !== '' ? 'Status: <strong>configured</strong>.' : 'Status: <strong>not configured</strong>.') . '</p>';
    echo '</div>';
}
