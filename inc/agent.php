<?php

if (!defined('ABSPATH')) {
    exit;
}

define('MATRIX_QC_AGENT_ENDPOINT', 'https://api.cursor.com/v1/agents');
define('MATRIX_QC_AGENT_CRON', 'matrix_qc_agent_poll_event');

/**
 * Find the git repository root by walking up from the active theme.
 *
 * @return string Absolute path to the repo root, or '' if not found.
 */
function matrix_qc_agent_git_root() {
    $starts = array(get_stylesheet_directory(), get_template_directory());
    foreach ($starts as $start) {
        $dir = $start;
        for ($i = 0; $i < 8 && $dir && $dir !== '/' && $dir !== '.'; $i++) {
            if (is_dir($dir . '/.git')) {
                return $dir;
            }
            $parent = dirname($dir);
            if ($parent === $dir) {
                break;
            }
            $dir = $parent;
        }
    }
    return '';
}

/**
 * Normalise a git remote URL to the https GitHub form.
 *
 * @param string $url
 * @return string
 */
function matrix_qc_agent_normalize_remote($url) {
    $url = trim($url);
    if (preg_match('#^git@([^:]+):(.+?)(?:\.git)?$#', $url, $m)) {
        return 'https://' . $m[1] . '/' . $m[2];
    }
    if (preg_match('#^ssh://git@([^/]+)/(.+?)(?:\.git)?$#', $url, $m)) {
        return 'https://' . $m[1] . '/' . $m[2];
    }
    if (preg_match('#^https?://#', $url)) {
        return preg_replace('#\.git$#', '', $url);
    }
    return $url;
}

/**
 * Best-effort detect the GitHub repo URL from the theme repo's git config.
 *
 * @return string
 */
function matrix_qc_agent_detect_repo() {
    static $cached = null;
    if ($cached !== null) {
        return $cached;
    }
    $cached = '';
    $root   = matrix_qc_agent_git_root();
    if ($root === '') {
        return $cached;
    }
    $config = $root . '/.git/config';
    if (!is_readable($config)) {
        return $cached;
    }
    $contents = (string) file_get_contents($config);
    if (preg_match('#\[remote "origin"\][^\[]*?url\s*=\s*(\S+)#s', $contents, $m)) {
        $cached = matrix_qc_agent_normalize_remote($m[1]);
    }
    return $cached;
}

/**
 * Agent configuration from options (with sensible defaults).
 *
 * @return array<string,mixed>
 */
function matrix_qc_agent_config() {
    return array(
        'api_key'   => (string) get_option('matrix_qc_agent_api_key', ''),
        'repo'      => (string) get_option('matrix_qc_agent_repo', matrix_qc_agent_detect_repo()),
        'ref'       => (string) get_option('matrix_qc_agent_ref', 'main'),
        'model'     => (string) get_option('matrix_qc_agent_model', ''),
        'auto_pr'   => get_option('matrix_qc_agent_autopr', '1') === '1',
        'site_user' => (string) get_option('matrix_qc_agent_site_user', 'matrix'),
        'site_pass' => (string) get_option('matrix_qc_agent_site_pass', (string) gmdate('Y')),
    );
}

/**
 * A note about staging HTTP basic auth, for inclusion in agent prompts.
 *
 * @return string
 */
function matrix_qc_agent_site_note() {
    $cfg = matrix_qc_agent_config();
    if ($cfg['site_user'] === '' && $cfg['site_pass'] === '') {
        return '';
    }
    return 'If you load the live staging site (' . home_url('/') . ') and it is password protected, use HTTP basic auth username "'
        . $cfg['site_user'] . '" and password "' . $cfg['site_pass'] . '".';
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

    $note = matrix_qc_agent_site_note();
    if ($note !== '') {
        $prompt .= "\n\n" . $note;
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
 * Dispatch a set of snags as a single combined agent (one PR).
 *
 * @param array<int> $ids
 * @return array<string,mixed>|WP_Error
 */
function matrix_qc_agent_dispatch_ids($ids) {
    $snags = array();
    foreach ($ids as $id) {
        $post = get_post((int) $id);
        if ($post && $post->post_type === MATRIX_QC_SNAG_CPT) {
            $snags[] = matrix_qc_snag_to_array($post);
        }
    }
    if (empty($snags)) {
        return new WP_Error('none', 'No valid snags to dispatch.');
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
 * Dispatch all open snags as a single combined agent (one PR).
 *
 * @return array<string,mixed>|WP_Error
 */
function matrix_qc_agent_dispatch_open() {
    $snags = matrix_qc_snag_fetch_sorted(true);
    if (empty($snags)) {
        return new WP_Error('none', 'No open snags to dispatch.');
    }
    return matrix_qc_agent_dispatch_ids(wp_list_pluck($snags, 'id'));
}

/**
 * List GitHub repositories Cursor can access (rate limited: 1/min, 30/hr).
 *
 * @return array<int,array<string,mixed>>|WP_Error
 */
function matrix_qc_agent_repositories() {
    $cfg = matrix_qc_agent_config();
    if ($cfg['api_key'] === '') {
        return new WP_Error('no_key', 'No API key');
    }
    $resp = wp_remote_get('https://api.cursor.com/v1/repositories', array(
        'timeout' => 45,
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
                $prev = (string) get_post_meta($sid, '_qc_pr_url', true);
                update_post_meta($sid, '_qc_pr_url', $pr);
                update_post_meta($sid, '_qc_status', 'pr_open');
                if ($prev !== $pr) {
                    matrix_qc_snag_add_system_comment($sid, 'Pull request opened: ' . $pr);
                }
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
 * Ask the agent to open a PR that reverts a previously merged/opened fix PR.
 *
 * @param int $snag_id
 * @return array<string,mixed>|WP_Error
 */
function matrix_qc_agent_revert_pr($snag_id) {
    $post = get_post($snag_id);
    if (!$post || $post->post_type !== MATRIX_QC_SNAG_CPT) {
        return new WP_Error('not_found', 'Snag not found');
    }
    $pr = (string) get_post_meta($snag_id, '_qc_pr_url', true);
    if ($pr === '') {
        return new WP_Error('no_pr', 'No PR is recorded for this snag.');
    }
    $snag   = matrix_qc_snag_to_array($post);
    $prompt = "Revert the code change made for this QC snag. Open a NEW pull request that cleanly reverts the changes from this PR: " . $pr
        . " (revert the merge/commits; do not reintroduce the snag). Keep the revert minimal.\n\nOriginal snag context:\n"
        . matrix_qc_agent_prompt_single($snag);

    $res = matrix_qc_agent_create($prompt);
    if (is_wp_error($res)) {
        return $res;
    }
    $agent = matrix_qc_agent_obj($res);
    update_post_meta($snag_id, '_qc_agent_id', isset($agent['id']) ? (string) $agent['id'] : '');
    update_post_meta($snag_id, '_qc_agent_url', matrix_qc_agent_url($agent));
    update_post_meta($snag_id, '_qc_status', 'in_progress');
    matrix_qc_snag_add_system_comment($snag_id, 'Requested an agent revert PR for ' . $pr . '.');
    matrix_qc_agent_ensure_cron();
    return $res;
}

/**
 * admin-post: request an agent revert PR.
 */
function matrix_qc_agent_handle_revert_pr() {
    if (!current_user_can(MATRIX_QC_SNAG_CAP)) {
        wp_die('Forbidden');
    }
    check_admin_referer('matrix_qc_agent_dispatch');
    $id  = isset($_GET['snag']) ? absint($_GET['snag']) : 0;
    $res = matrix_qc_agent_revert_pr($id);
    matrix_qc_agent_redirect_with_notice(
        get_edit_post_link($id, 'url'),
        is_wp_error($res) ? 'Revert request failed: ' . $res->get_error_message() : 'Requested an agent revert PR.'
    );
}
add_action('admin_post_matrix_qc_agent_revert_pr', 'matrix_qc_agent_handle_revert_pr');

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
        $prev = (string) get_post_meta($id, '_qc_pr_url', true);
        update_post_meta($id, '_qc_pr_url', $pr);
        update_post_meta($id, '_qc_status', 'pr_open');
        if ($prev !== $pr) {
            matrix_qc_snag_add_system_comment($id, 'Pull request opened: ' . $pr);
        }
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
 * The CI gate workflow YAML (project-agnostic).
 *
 * @return string
 */
function matrix_qc_agent_ci_yaml() {
    return <<<'YAML'
name: QC PR Gate

# Quality gate for pull requests (including agent-opened "cursor/*" PRs).
# Must pass before a fix PR is merged into the base branch.
# Installed by the Matrix QC Snag plugin.

on:
  pull_request:
  workflow_dispatch:

concurrency:
  group: qc-pr-${{ github.ref }}
  cancel-in-progress: true

jobs:
  php:
    name: PHP lint + unit tests
    runs-on: ubuntu-latest
    steps:
      - uses: actions/checkout@v4

      - name: Set up PHP
        uses: shivammathur/setup-php@v2
        with:
          php-version: '8.3'
          coverage: none
          tools: composer

      - name: Lint all PHP files
        run: |
          find . -type f -name '*.php' \
            -not -path './vendor/*' \
            -not -path './node_modules/*' \
            -print0 | xargs -0 -n1 -P4 php -l

      - name: Install Composer dependencies
        if: hashFiles('composer.json') != ''
        run: composer install --no-interaction --prefer-dist --no-progress

      - name: Run unit tests (Pest)
        if: hashFiles('vendor/bin/pest') != ''
        run: php vendor/bin/pest tests/Unit

  assets:
    name: Build assets
    runs-on: ubuntu-latest
    if: hashFiles('package.json') != ''
    steps:
      - uses: actions/checkout@v4

      - name: Set up Node
        uses: actions/setup-node@v4
        with:
          node-version: '20'
          cache: npm

      - name: Install npm dependencies
        run: npm ci --ignore-scripts

      - name: Build CSS + JS
        run: npm run build --if-present
YAML;
}

/**
 * Absolute path where the CI workflow should live.
 *
 * @return string '' when no git root is detected.
 */
function matrix_qc_agent_ci_path() {
    $root = matrix_qc_agent_git_root();
    return $root === '' ? '' : $root . '/.github/workflows/qc-pr.yml';
}

/**
 * Write the CI workflow into the repo (best effort).
 *
 * @param bool $overwrite
 * @return true|WP_Error
 */
function matrix_qc_agent_install_ci($overwrite = false) {
    $path = matrix_qc_agent_ci_path();
    if ($path === '') {
        return new WP_Error('no_git', 'No git repository found above the active theme.');
    }
    if (file_exists($path) && !$overwrite) {
        return new WP_Error('exists', 'Workflow already present at ' . $path);
    }
    $dir = dirname($path);
    if (!wp_mkdir_p($dir)) {
        return new WP_Error('mkdir', 'Could not create ' . $dir);
    }
    if (file_put_contents($path, matrix_qc_agent_ci_yaml()) === false) {
        return new WP_Error('write', 'Could not write ' . $path);
    }
    return true;
}

/**
 * admin-post: install/overwrite the CI workflow.
 */
function matrix_qc_agent_handle_install_ci() {
    if (!current_user_can(MATRIX_QC_SNAG_CAP)) {
        wp_die('Forbidden');
    }
    check_admin_referer('matrix_qc_agent_install_ci');
    $res  = matrix_qc_agent_install_ci(true);
    $dest = admin_url('edit.php?post_type=' . MATRIX_QC_SNAG_CPT . '&page=matrix-qc-agent');
    matrix_qc_agent_redirect_with_notice(
        $dest,
        is_wp_error($res) ? 'CI install failed: ' . $res->get_error_message() : 'CI workflow written to ' . matrix_qc_agent_ci_path() . ' &mdash; commit it to your repo.'
    );
}
add_action('admin_post_matrix_qc_agent_install_ci', 'matrix_qc_agent_handle_install_ci');

/**
 * admin-post: download the CI workflow YAML.
 */
function matrix_qc_agent_handle_download_ci() {
    if (!current_user_can(MATRIX_QC_SNAG_CAP)) {
        wp_die('Forbidden');
    }
    check_admin_referer('matrix_qc_agent_download_ci');
    header('Content-Type: text/yaml; charset=utf-8');
    header('Content-Disposition: attachment; filename="qc-pr.yml"');
    echo matrix_qc_agent_ci_yaml();
    exit;
}
add_action('admin_post_matrix_qc_agent_download_ci', 'matrix_qc_agent_handle_download_ci');

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
        $actions['matrix_qc_agent_send']          = 'Send to agent (one PR each)';
        $actions['matrix_qc_agent_send_combined'] = 'Send to agent (one combined PR)';
    }
    return $actions;
}
add_filter('bulk_actions-edit-' . MATRIX_QC_SNAG_CPT, 'matrix_qc_agent_bulk_action');

/**
 * Handle the bulk "Send to agent" actions.
 *
 * @param string     $redirect
 * @param string     $action
 * @param array<int> $ids
 * @return string
 */
function matrix_qc_agent_bulk_handle($redirect, $action, $ids) {
    if ($action !== 'matrix_qc_agent_send' && $action !== 'matrix_qc_agent_send_combined') {
        return $redirect;
    }
    if (!current_user_can(MATRIX_QC_SNAG_CAP)) {
        wp_die('Forbidden');
    }

    if ($action === 'matrix_qc_agent_send_combined') {
        $res     = matrix_qc_agent_dispatch_ids(array_map('intval', $ids));
        $message = is_wp_error($res)
            ? 'Combined dispatch failed: ' . $res->get_error_message()
            : count($ids) . ' snags dispatched to the agent in one combined PR.';
        matrix_qc_agent_redirect_with_notice($redirect, $message);
        return $redirect;
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
        update_option('matrix_qc_agent_site_user', sanitize_text_field(wp_unslash($_POST['matrix_qc_agent_site_user'] ?? '')));
        update_option('matrix_qc_agent_site_pass', sanitize_text_field(wp_unslash($_POST['matrix_qc_agent_site_pass'] ?? '')));
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

    if (isset($_POST['matrix_qc_agent_repos']) &&
        check_admin_referer('matrix_qc_agent_settings', 'matrix_qc_agent_settings_nonce')) {
        $repos = matrix_qc_agent_repositories();
        if (is_wp_error($repos)) {
            echo '<div class="notice notice-error"><p>Repo check failed: ' . esc_html($repos->get_error_message()) . '</p></div>';
        } else {
            $want  = rtrim(strtolower(get_option('matrix_qc_agent_repo', '')), '/');
            $found = false;
            $urls  = array();
            foreach ($repos as $r) {
                if (!empty($r['url'])) {
                    $urls[] = (string) $r['url'];
                    if (rtrim(strtolower((string) $r['url']), '/') === $want) {
                        $found = true;
                    }
                }
            }
            if ($found) {
                echo '<div class="notice notice-success"><p>Cursor can access the configured repo &mdash; agents can open PRs on it.</p></div>';
            } else {
                echo '<div class="notice notice-warning"><p>The configured repo is <strong>not</strong> in Cursor\'s accessible list. Connect it in Cursor &rarr; Integrations &rarr; GitHub. ' . (count($urls) ? 'Visible repos: ' . esc_html(implode(', ', array_slice($urls, 0, 25))) : 'No repos visible to this key.') . '</p></div>';
            }
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
    printf(
        '<tr><th><label>Staging auth user</label></th><td><input type="text" name="matrix_qc_agent_site_user" value="%s" class="regular-text" /><p class="description">If the staging site is behind HTTP basic auth, this is passed to the agent so it can load pages. Leave blank if not protected.</p></td></tr>',
        esc_attr($cfg['site_user'])
    );
    printf(
        '<tr><th><label>Staging auth password</label></th><td><input type="text" name="matrix_qc_agent_site_pass" value="%s" class="regular-text" /><p class="description">Defaults to the current year.</p></td></tr>',
        esc_attr($cfg['site_pass'])
    );

    echo '</tbody></table>';
    echo '<p><button class="button button-primary" name="matrix_qc_agent_save" value="1">Save settings</button> ';
    echo '<button class="button" name="matrix_qc_agent_test" value="1">Test connection</button> ';
    echo '<button class="button" name="matrix_qc_agent_repos" value="1">Check repo access</button>';
    echo '<span class="description" style="display:block;margin-top:6px">Repo check is rate limited by Cursor (about 1/min) and can take a few seconds.</span></p>';
    echo '</form>';
    echo '<p>' . ($cfg['api_key'] !== '' ? 'Status: <strong>configured</strong>.' : 'Status: <strong>not configured</strong>.') . '</p>';

    echo '<hr><h2>CI gate workflow</h2>';
    echo '<p>The agent\'s PRs are gated by a GitHub Actions workflow (PHP lint + Pest unit tests + asset build) that lives in your repo at <code>.github/workflows/qc-pr.yml</code>.</p>';
    $ci_path = matrix_qc_agent_ci_path();
    if ($ci_path === '') {
        echo '<p class="notice notice-warning" style="padding:8px 12px">No git repository detected above the active theme, so the workflow can\'t be installed automatically. Use Download and add it to your repo manually.</p>';
    } else {
        $present = file_exists($ci_path);
        echo '<p>Target: <code>' . esc_html($ci_path) . '</code> &mdash; ' . ($present ? '<strong>present</strong>' : '<strong>not installed</strong>') . '.</p>';
        $install_url = wp_nonce_url(admin_url('admin-post.php?action=matrix_qc_agent_install_ci'), 'matrix_qc_agent_install_ci');
        echo '<p><a class="button button-primary" href="' . esc_url($install_url) . '">' . ($present ? 'Reinstall / overwrite workflow' : 'Install workflow into repo') . '</a> ';
    }
    $download_url = wp_nonce_url(admin_url('admin-post.php?action=matrix_qc_agent_download_ci'), 'matrix_qc_agent_download_ci');
    echo '<a class="button" href="' . esc_url($download_url) . '">Download qc-pr.yml</a></p>';
    echo '<p class="description">After installing, commit and push the file so the gate runs on PRs. The workflow is project-agnostic: PHP/Pest/npm steps are skipped automatically if those files don\'t exist.</p>';
    echo '</div>';
}
