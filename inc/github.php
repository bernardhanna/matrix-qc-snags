<?php

if (!defined('ABSPATH')) {
    exit;
}

/**
 * GitHub PR-state sync.
 *
 * The Cursor agent reports the PR it opens, but once a PR exists its lifecycle
 * (merged / closed) lives on GitHub. This module pulls that state so a snag's
 * status advances automatically: open -> PR open, merged -> Fixed,
 * closed-without-merge -> back to Triaged. It also works for PRs opened
 * manually on GitHub, as long as the snag has a PR URL (or the agent pushed a
 * branch we can match a PR to).
 */

/**
 * GitHub personal access token (needs `repo` read scope for private repos).
 *
 * @return string
 */
function matrix_qc_github_token() {
    return (string) get_option('matrix_qc_agent_github_token', '');
}

/**
 * Owner/repo derived from the configured agent repository URL.
 *
 * @return array{owner:string,repo:string}|null
 */
function matrix_qc_github_owner_repo() {
    $cfg = matrix_qc_agent_config();
    return matrix_qc_github_parse_repo_url($cfg['repo']);
}

/**
 * Parse owner/repo out of a GitHub repository URL (https or ssh form).
 *
 * @param string $url
 * @return array{owner:string,repo:string}|null
 */
function matrix_qc_github_parse_repo_url($url) {
    $url = trim((string) $url);
    if ($url === '' || stripos($url, 'github.com') === false) {
        return null;
    }
    if (preg_match('#github\.com[/:]([^/]+)/([^/#?]+?)(?:\.git)?/?$#i', $url, $m)) {
        return array('owner' => $m[1], 'repo' => $m[2]);
    }
    return null;
}

/**
 * Parse owner/repo/number out of a GitHub pull-request URL.
 *
 * @param string $url
 * @return array{owner:string,repo:string,number:int}|null
 */
function matrix_qc_github_parse_pr_url($url) {
    if (preg_match('#github\.com/([^/]+)/([^/]+)/pull/(\d+)#i', (string) $url, $m)) {
        return array('owner' => $m[1], 'repo' => $m[2], 'number' => (int) $m[3]);
    }
    return null;
}

/**
 * Perform an authenticated GET against the GitHub REST API.
 *
 * @param string $path API path beginning with "/".
 * @return array<string,mixed>|WP_Error
 */
function matrix_qc_github_request($path) {
    $headers = array(
        'Accept'               => 'application/vnd.github+json',
        'User-Agent'           => 'matrix-qc-snag',
        'X-GitHub-Api-Version' => '2022-11-28',
    );
    $token = matrix_qc_github_token();
    if ($token !== '') {
        $headers['Authorization'] = 'Bearer ' . $token;
    }

    $resp = wp_remote_get('https://api.github.com' . $path, array(
        'timeout' => 20,
        'headers' => $headers,
    ));
    if (is_wp_error($resp)) {
        return $resp;
    }

    $code = wp_remote_retrieve_response_code($resp);
    $data = json_decode(wp_remote_retrieve_body($resp), true);
    if ($code < 200 || $code >= 300) {
        $msg = is_array($data) && isset($data['message']) ? $data['message'] : wp_remote_retrieve_body($resp);
        return new WP_Error('gh_error', 'GitHub API ' . $code . ': ' . $msg);
    }
    return $data;
}

/**
 * Whether GitHub sync can run at all (a repo must be resolvable).
 *
 * @return bool
 */
function matrix_qc_github_ready() {
    return matrix_qc_github_owner_repo() !== null;
}

/**
 * Fetch a single pull request.
 *
 * @param string $owner
 * @param string $repo
 * @param int    $number
 * @return array<string,mixed>|WP_Error
 */
function matrix_qc_github_get_pr($owner, $repo, $number) {
    return matrix_qc_github_request(
        '/repos/' . rawurlencode($owner) . '/' . rawurlencode($repo) . '/pulls/' . (int) $number
    );
}

/**
 * Find the most recent PR (any state) for a given head branch.
 *
 * @param string $owner
 * @param string $repo
 * @param string $branch
 * @return array<string,mixed>|WP_Error|null
 */
function matrix_qc_github_find_pr_for_branch($owner, $repo, $branch) {
    $path = '/repos/' . rawurlencode($owner) . '/' . rawurlencode($repo)
        . '/pulls?state=all&per_page=1&head=' . rawurlencode($owner . ':' . $branch);
    $data = matrix_qc_github_request($path);
    if (is_wp_error($data)) {
        return $data;
    }
    if (is_array($data) && isset($data[0]) && is_array($data[0])) {
        return $data[0];
    }
    return null;
}

/**
 * Apply a GitHub PR object to a snag's stored PR URL + status.
 *
 * @param int                 $snag_id
 * @param array<string,mixed> $pr
 * @return bool True when the snag was touched.
 */
function matrix_qc_github_apply_pr_state($snag_id, $pr) {
    if (!is_array($pr)) {
        return false;
    }

    $url = isset($pr['html_url']) ? (string) $pr['html_url'] : '';
    if ($url !== '') {
        $prev_url = (string) get_post_meta($snag_id, '_qc_pr_url', true);
        if ($prev_url !== $url) {
            update_post_meta($snag_id, '_qc_pr_url', $url);
            matrix_qc_snag_add_system_comment($snag_id, 'Pull request linked from GitHub: ' . $url);
        }
    }

    $current = (string) get_post_meta($snag_id, '_qc_status', true);
    $merged  = !empty($pr['merged']) || !empty($pr['merged_at']);
    $state   = isset($pr['state']) ? (string) $pr['state'] : '';

    if ($merged) {
        // Merged & closed on GitHub: hand it to a human to verify on the live
        // site rather than auto-closing it.
        if ($current !== 'ready_for_review') {
            update_post_meta($snag_id, '_qc_status', 'ready_for_review');
            matrix_qc_snag_add_system_comment($snag_id, 'Pull request successfully merged and closed on GitHub - ready for human review.');
            matrix_qc_snag_queue_review_notification($snag_id);
        }
        return true;
    }

    if ($state === 'open') {
        if ($current !== 'pr_open') {
            update_post_meta($snag_id, '_qc_status', 'pr_open');
        }
        return true;
    }

    if ($state === 'closed') {
        // Closed without merging: surface it and re-open the snag for triage.
        if (in_array($current, array('in_progress', 'pr_open'), true)) {
            update_post_meta($snag_id, '_qc_status', 'triaged');
            matrix_qc_snag_add_system_comment($snag_id, 'Pull request closed without merging on GitHub - back to Triaged.');
        }
        return true;
    }

    return false;
}

/**
 * Sync one snag from GitHub. Uses the recorded PR URL when present, otherwise
 * tries to discover a PR from the agent's pushed branch.
 *
 * @param int $snag_id
 * @return bool|WP_Error True when synced, false when nothing to do.
 */
function matrix_qc_github_sync_snag($snag_id) {
    $pr_url = (string) get_post_meta($snag_id, '_qc_pr_url', true);
    if ($pr_url !== '') {
        $parts = matrix_qc_github_parse_pr_url($pr_url);
        if ($parts) {
            $pr = matrix_qc_github_get_pr($parts['owner'], $parts['repo'], $parts['number']);
            if (is_wp_error($pr)) {
                return $pr;
            }
            return matrix_qc_github_apply_pr_state($snag_id, $pr);
        }
    }

    $branch = (string) get_post_meta($snag_id, '_qc_agent_branch', true);
    $repo   = matrix_qc_github_owner_repo();
    if ($branch !== '' && $repo) {
        $pr = matrix_qc_github_find_pr_for_branch($repo['owner'], $repo['repo'], $branch);
        if (is_wp_error($pr)) {
            return $pr;
        }
        if ($pr) {
            return matrix_qc_github_apply_pr_state($snag_id, $pr);
        }
    }

    return false;
}

/**
 * Poll GitHub for every snag that is mid-flight (in progress or PR open) and
 * has something we can match to a PR.
 */
function matrix_qc_github_poll() {
    $query = new WP_Query(array(
        'post_type'      => MATRIX_QC_SNAG_CPT,
        'post_status'    => 'publish',
        'posts_per_page' => -1,
        'no_found_rows'  => true,
        'meta_query'     => array(array(
            'key'     => '_qc_status',
            'value'   => array('in_progress', 'pr_open'),
            'compare' => 'IN',
        )),
    ));

    foreach ($query->posts as $post) {
        $has_pr     = (string) get_post_meta($post->ID, '_qc_pr_url', true) !== '';
        $has_branch = (string) get_post_meta($post->ID, '_qc_agent_branch', true) !== '';
        if (!$has_pr && !$has_branch) {
            continue;
        }
        matrix_qc_github_sync_snag($post->ID);
    }

    matrix_qc_snag_flush_review_notifications();
}

/**
 * Queue a snag that just became "Ready for Human Review" so its reporter can be
 * notified. Queuing (rather than emailing immediately) lets us batch every snag
 * from the same poll/merge into a single email per person.
 *
 * @param int $snag_id
 */
function matrix_qc_snag_queue_review_notification($snag_id) {
    if (!isset($GLOBALS['matrix_qc_review_queue']) || !is_array($GLOBALS['matrix_qc_review_queue'])) {
        $GLOBALS['matrix_qc_review_queue'] = array();
    }
    $GLOBALS['matrix_qc_review_queue'][(int) $snag_id] = (int) $snag_id;
}

/**
 * Send any queued "Ready for Human Review" notifications, grouped by the snag's
 * original author so each person gets one email listing all of their snags.
 */
function matrix_qc_snag_flush_review_notifications() {
    if (empty($GLOBALS['matrix_qc_review_queue']) || !is_array($GLOBALS['matrix_qc_review_queue'])) {
        return;
    }
    $ids = array_values($GLOBALS['matrix_qc_review_queue']);
    $GLOBALS['matrix_qc_review_queue'] = array();

    $by_author = array();
    foreach ($ids as $sid) {
        $post = get_post($sid);
        if (!$post || $post->post_type !== MATRIX_QC_SNAG_CPT) {
            continue;
        }
        // Skip if a human already moved it on before the email went out.
        if ((string) get_post_meta($sid, '_qc_status', true) !== 'ready_for_review') {
            continue;
        }
        $author_id = (int) $post->post_author;
        if ($author_id) {
            $by_author[$author_id][] = $post;
        }
    }

    foreach ($by_author as $author_id => $posts) {
        $author = get_userdata($author_id);
        if ($author && is_email($author->user_email)) {
            matrix_qc_snag_send_review_email($author, $posts);
        }
    }
}

/**
 * Email one reporter the list of their snags that are ready for review, each
 * with a login-protected deep link to open the snag and mark it Fixed.
 *
 * @param WP_User   $author
 * @param WP_Post[] $posts
 */
function matrix_qc_snag_send_review_email($author, $posts) {
    $count = count($posts);
    if ($count === 0) {
        return;
    }
    $site = get_bloginfo('name');

    $subject = $count === 1
        ? sprintf('[%s] A snag is ready for your review', $site)
        : sprintf('[%s] %d snags are ready for your review', $site, $count);

    $lines   = array();
    $lines[] = sprintf('Hi %s,', $author->display_name);
    $lines[] = '';
    $lines[] = $count === 1
        ? 'A snag you raised has had its fix merged and is ready for you to review and mark as fixed:'
        : 'Snags you raised have had their fixes merged and are ready for you to review and mark as fixed:';
    $lines[] = '';

    foreach ($posts as $post) {
        $page_url = (string) get_post_meta($post->ID, '_qc_page_url', true);
        if ($page_url === '') {
            $page_url = home_url('/');
        }
        $deep   = add_query_arg('qc_snag', (int) $post->ID, $page_url);
        $review = wp_login_url($deep);
        $pr     = (string) get_post_meta($post->ID, '_qc_pr_url', true);

        $lines[] = '- ' . get_the_title($post);
        $lines[] = '  Page: ' . $page_url;
        if ($pr !== '') {
            $lines[] = '  Merged PR: ' . $pr;
        }
        $lines[] = '  Review & mark fixed: ' . $review;
        $lines[] = '';
    }

    $lines[] = 'Open each one, confirm the fix on the page, then set its status to "Fixed".';
    $message = implode("\n", $lines);

    $subject = apply_filters('matrix_qc_snag_review_subject', $subject, $author, $posts);
    $message = apply_filters('matrix_qc_snag_review_message', $message, $author, $posts);

    wp_mail($author->user_email, $subject, $message, matrix_qc_notify_cc_headers());
}
