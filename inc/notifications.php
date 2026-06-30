<?php

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Notification extras: an optional CC list copied on all QC emails (and an
 * opt-in email on every status change), plus a scheduled weekly status report
 * for stakeholders / clients.
 */

define('MATRIX_QC_WEEKLY_REPORT_HOOK', 'matrix_qc_weekly_report_event');

/**
 * Parse a free-form list of emails (comma / semicolon / whitespace separated)
 * into a de-duplicated array of valid addresses.
 *
 * @param string $raw
 * @return string[]
 */
function matrix_qc_parse_emails($raw) {
    $parts = preg_split('/[,;\s]+/', (string) $raw);
    $out   = array();
    foreach ((array) $parts as $part) {
        $part = trim($part);
        if ($part !== '' && is_email($part)) {
            $out[strtolower($part)] = $part;
        }
    }
    return array_values($out);
}

/**
 * Addresses to CC on every QC notification email.
 *
 * @return string[]
 */
function matrix_qc_notify_cc_list() {
    return matrix_qc_parse_emails((string) get_option('matrix_qc_notify_cc', ''));
}

/**
 * Weekly report recipients.
 *
 * @return string[]
 */
function matrix_qc_report_recipients() {
    return matrix_qc_parse_emails((string) get_option('matrix_qc_report_recipients', ''));
}

/**
 * Cc: headers built from the configured CC list (for use in wp_mail).
 *
 * @return string[]
 */
function matrix_qc_notify_cc_headers() {
    $headers = array();
    foreach (matrix_qc_notify_cc_list() as $email) {
        $headers[] = 'Cc: ' . $email;
    }
    return $headers;
}

/* -------------------------------------------------------------------------
 * Status-change notifications (optional)
 * ---------------------------------------------------------------------- */

/**
 * Capture a snag's status before it is overwritten, so the post-update hook can
 * report an accurate "from -> to". Runs on the pre-update filter without
 * short-circuiting the write (returns the original $check).
 *
 * @param mixed  $check
 * @param int    $object_id
 * @param string $meta_key
 * @param mixed  $meta_value
 * @return mixed
 */
function matrix_qc_capture_prev_status($check, $object_id, $meta_key, $meta_value) {
    if ($meta_key === '_qc_status' && get_post_type($object_id) === MATRIX_QC_SNAG_CPT) {
        if (!isset($GLOBALS['matrix_qc_prev_status']) || !is_array($GLOBALS['matrix_qc_prev_status'])) {
            $GLOBALS['matrix_qc_prev_status'] = array();
        }
        $GLOBALS['matrix_qc_prev_status'][(int) $object_id] = (string) get_post_meta($object_id, '_qc_status', true);
    }
    return $check;
}
add_filter('update_post_metadata', 'matrix_qc_capture_prev_status', 10, 4);

/**
 * After a snag's status changes, email the CC list (when enabled) a short note.
 *
 * @param int    $meta_id
 * @param int    $object_id
 * @param string $meta_key
 * @param mixed  $meta_value
 */
function matrix_qc_status_changed($meta_id, $object_id, $meta_key, $meta_value) {
    if ($meta_key !== '_qc_status' || get_post_type($object_id) !== MATRIX_QC_SNAG_CPT) {
        return;
    }
    if (get_option('matrix_qc_notify_on_status_change', '0') !== '1') {
        return;
    }
    $recipients = matrix_qc_notify_cc_list();
    if (empty($recipients)) {
        return;
    }

    $prev = isset($GLOBALS['matrix_qc_prev_status'][$object_id])
        ? (string) $GLOBALS['matrix_qc_prev_status'][$object_id]
        : '';
    $new = (string) $meta_value;
    if ($new === '' || $prev === '' || $prev === $new) {
        return;
    }

    $post = get_post($object_id);
    if (!$post) {
        return;
    }

    $actor      = wp_get_current_user();
    $actor_name = ($actor && $actor->exists()) ? $actor->display_name : 'Automated sync';
    $title      = get_the_title($post);
    $page_url   = (string) get_post_meta($object_id, '_qc_page_url', true);
    $edit       = get_edit_post_link($object_id, 'raw');

    $subject = sprintf(
        '[%s] Snag status: %s -> %s',
        get_bloginfo('name'),
        matrix_qc_snag_status_label($prev),
        matrix_qc_snag_status_label($new)
    );

    $lines   = array();
    $lines[] = sprintf('Snag "%s" changed status.', $title);
    $lines[] = '';
    $lines[] = 'From: ' . matrix_qc_snag_status_label($prev);
    $lines[] = 'To:   ' . matrix_qc_snag_status_label($new);
    $lines[] = 'By:   ' . $actor_name;
    if ($page_url !== '') {
        $lines[] = 'Page: ' . $page_url;
    }
    if ($edit) {
        $lines[] = '';
        $lines[] = 'View: ' . $edit;
    }

    wp_mail($recipients, $subject, implode("\n", $lines));
}
add_action('updated_post_meta', 'matrix_qc_status_changed', 10, 4);

/* -------------------------------------------------------------------------
 * Weekly status report
 * ---------------------------------------------------------------------- */

/**
 * Available report frequencies (schedule key => label + interval seconds).
 *
 * @return array<string,array{label:string,interval:int}>
 */
function matrix_qc_report_frequencies() {
    return array(
        'hourly'     => array('label' => 'Every hour',     'interval' => HOUR_IN_SECONDS),
        'qc_2hours'  => array('label' => 'Every 2 hours',  'interval' => 2 * HOUR_IN_SECONDS),
        'qc_6hours'  => array('label' => 'Every 6 hours',  'interval' => 6 * HOUR_IN_SECONDS),
        'qc_12hours' => array('label' => 'Every 12 hours', 'interval' => 12 * HOUR_IN_SECONDS),
        'daily'      => array('label' => 'Daily',          'interval' => DAY_IN_SECONDS),
        'weekly'     => array('label' => 'Weekly',         'interval' => WEEK_IN_SECONDS),
    );
}

/**
 * The configured frequency key, validated (defaults to weekly).
 *
 * @return string
 */
function matrix_qc_report_frequency_key() {
    $key = (string) get_option('matrix_qc_report_frequency', 'weekly');
    return isset(matrix_qc_report_frequencies()[$key]) ? $key : 'weekly';
}

/**
 * Register cron intervals for every report frequency that isn't already known.
 *
 * @param array<string,array<string,mixed>> $schedules
 * @return array<string,array<string,mixed>>
 */
function matrix_qc_report_cron_interval($schedules) {
    foreach (matrix_qc_report_frequencies() as $key => $freq) {
        if (!isset($schedules[$key])) {
            $schedules[$key] = array('interval' => $freq['interval'], 'display' => $freq['label']);
        }
    }
    return $schedules;
}
add_filter('cron_schedules', 'matrix_qc_report_cron_interval');

/**
 * Schedule the report based on current settings. Always clears any existing
 * event first so a frequency change takes effect immediately.
 */
function matrix_qc_report_reschedule() {
    $next = wp_next_scheduled(MATRIX_QC_WEEKLY_REPORT_HOOK);
    if ($next) {
        wp_unschedule_event($next, MATRIX_QC_WEEKLY_REPORT_HOOK);
    }

    $enabled = get_option('matrix_qc_report_enabled', '0') === '1' && !empty(matrix_qc_report_recipients());
    if (!$enabled) {
        return;
    }

    $freq = matrix_qc_report_frequency_key();
    wp_schedule_event(matrix_qc_report_first_run($freq), $freq, MATRIX_QC_WEEKLY_REPORT_HOOK);
}

/**
 * First-run timestamp (UTC) for a given frequency: a tidy boundary for the
 * daily/weekly cadence, otherwise one interval from now.
 *
 * @param string $freq
 * @return int
 */
function matrix_qc_report_first_run($freq) {
    $now    = current_time('timestamp');
    $offset = (int) (get_option('gmt_offset', 0) * HOUR_IN_SECONDS);

    if ($freq === 'weekly') {
        $local = strtotime('next monday 08:00', $now);
    } elseif ($freq === 'daily') {
        $local = strtotime('tomorrow 08:00', $now);
    } else {
        $freqs    = matrix_qc_report_frequencies();
        $interval = isset($freqs[$freq]) ? $freqs[$freq]['interval'] : HOUR_IN_SECONDS;
        $local    = $now + $interval;
    }
    if ($local === false) {
        $local = $now + HOUR_IN_SECONDS;
    }
    return $local - $offset;
}

/**
 * Send the weekly report to the configured recipients.
 *
 * @return bool
 */
function matrix_qc_weekly_report_send() {
    $recipients = matrix_qc_report_recipients();
    if (empty($recipients)) {
        return false;
    }
    $report  = matrix_qc_weekly_report_build();
    $headers = array('Content-Type: text/html; charset=UTF-8');
    return wp_mail($recipients, $report['subject'], $report['html'], $headers);
}
add_action(MATRIX_QC_WEEKLY_REPORT_HOOK, 'matrix_qc_weekly_report_send');

/**
 * Build the weekly report subject + HTML body from current snag data.
 *
 * @return array{subject:string,html:string}
 */
function matrix_qc_weekly_report_build() {
    $enums = matrix_qc_snag_enums();
    $query = new WP_Query(array(
        'post_type'      => MATRIX_QC_SNAG_CPT,
        'post_status'    => 'publish',
        'posts_per_page' => -1,
        'no_found_rows'  => true,
    ));

    $totals    = array('total' => 0, 'open' => 0, 'high_open' => 0, 'new_week' => 0);
    $by_status = array();
    foreach ($enums['status'] as $s) {
        $by_status[$s] = 0;
    }
    $open_list     = array();
    $resolved_list = array();
    $week_ago      = time() - WEEK_IN_SECONDS;

    foreach ($query->posts as $post) {
        $d      = matrix_qc_snag_to_array($post);
        $status = $d['status'] !== '' ? $d['status'] : 'new';
        $totals['total']++;
        if (isset($by_status[$status])) {
            $by_status[$status]++;
        }
        if (matrix_qc_snag_is_open($status)) {
            $totals['open']++;
            if ($d['severity'] === 'high') {
                $totals['high_open']++;
            }
            $open_list[] = $d;
        } else {
            $resolved_list[] = $d;
        }
        if (get_post_time('U', true, $post) >= $week_ago) {
            $totals['new_week']++;
        }
    }

    // Open: most urgent first. Resolved: most recently raised first.
    usort($open_list, 'matrix_qc_snag_priority_cmp');
    usort($resolved_list, static function ($a, $b) {
        return strcmp($b['created'], $a['created']);
    });

    $site    = get_bloginfo('name');
    $subject = sprintf('[%s] QC snag report - %s', $site, date_i18n('j M Y, H:i'));

    $dash_url = admin_url('edit.php?post_type=' . MATRIX_QC_SNAG_CPT . '&page=matrix-qc-dashboard');

    $cards = array(
        'Open'             => $totals['open'],
        'Ready for review' => $by_status['ready_for_review'],
        'High (open)'      => $totals['high_open'],
        'Fixed'            => $by_status['fixed'],
        'New (7d)'         => $totals['new_week'],
        'Total'            => $totals['total'],
    );

    $h  = '<div style="font-family:Arial,Helvetica,sans-serif;color:#1d2327;max-width:680px;margin:0 auto">';
    $h .= '<h1 style="font-size:20px;margin:0 0 4px">QC snag status report</h1>';
    $h .= '<p style="color:#646970;margin:0 0 16px">' . esc_html($site) . ' &middot; ' . esc_html(date_i18n('j M Y, H:i')) . '</p>';

    $h .= '<table role="presentation" style="border-collapse:collapse;width:100%;margin:0 0 20px"><tr>';
    foreach ($cards as $label => $value) {
        $h .= '<td style="border:1px solid #dcdcde;border-radius:8px;padding:10px 12px;text-align:center">'
            . '<div style="font-size:22px;font-weight:700">' . (int) $value . '</div>'
            . '<div style="font-size:11px;color:#646970">' . esc_html($label) . '</div></td>';
    }
    $h .= '</tr></table>';

    $h .= '<h2 style="font-size:15px;margin:18px 0 8px">Status breakdown</h2>';
    $h .= '<table role="presentation" style="border-collapse:collapse;width:100%;font-size:13px">';
    foreach ($enums['status'] as $s) {
        if ($by_status[$s] === 0) {
            continue;
        }
        $h .= '<tr><td style="padding:4px 8px;border-bottom:1px solid #f0f0f1">' . esc_html(matrix_qc_snag_status_label($s)) . '</td>'
            . '<td style="padding:4px 8px;border-bottom:1px solid #f0f0f1;text-align:right;font-weight:600">' . (int) $by_status[$s] . '</td></tr>';
    }
    $h .= '</table>';

    $h .= matrix_qc_report_snag_table('Open snags', $open_list, false);
    $h .= matrix_qc_report_snag_table('Resolved snags', $resolved_list, true);

    $h .= '<p style="margin:22px 0 0"><a href="' . esc_url($dash_url) . '" style="background:#2271b1;color:#fff;padding:9px 16px;border-radius:4px;text-decoration:none;font-size:13px">Open the QC dashboard</a></p>';
    $h .= '<p style="color:#8c8f94;font-size:11px;margin:18px 0 0">Automated report from the Matrix QC Snag plugin.</p>';
    $h .= '</div>';

    return array('subject' => $subject, 'html' => $h);
}

/**
 * Display name of whoever resolved a snag (or a dash when unknown / automated).
 *
 * @param int $snag_id
 * @return string
 */
function matrix_qc_report_resolver_name($snag_id) {
    $uid = (int) get_post_meta($snag_id, '_qc_resolved_by', true);
    if ($uid) {
        $user = get_userdata($uid);
        if ($user) {
            return $user->display_name;
        }
    }
    return '—';
}

/**
 * Render a detailed HTML table of snags for the report. Lists every snag with
 * its page and status; the resolved table also shows who resolved it.
 *
 * @param string                          $heading
 * @param array<int,array<string,mixed>>  $snags
 * @param bool                            $show_resolver
 * @return string
 */
function matrix_qc_report_snag_table($heading, $snags, $show_resolver) {
    if (empty($snags)) {
        return '';
    }

    $th = 'text-align:left;padding:6px 8px;border-bottom:2px solid #dcdcde;font-size:12px;color:#646970';
    $td = 'padding:6px 8px;border-bottom:1px solid #f0f0f1;font-size:13px;vertical-align:top';

    $h  = '<h2 style="font-size:15px;margin:20px 0 8px">' . esc_html($heading) . ' (' . count($snags) . ')</h2>';
    $h .= '<table role="presentation" style="border-collapse:collapse;width:100%">';
    $h .= '<thead><tr>';
    $h .= '<th style="' . $th . '">Snag</th>';
    $h .= '<th style="' . $th . '">Page</th>';
    $h .= '<th style="' . $th . '">Severity</th>';
    $h .= '<th style="' . $th . '">Status</th>';
    if ($show_resolver) {
        $h .= '<th style="' . $th . '">Resolved by</th>';
    }
    $h .= '</tr></thead><tbody>';

    foreach ($snags as $s) {
        $label  = $s['description'] !== '' ? wp_trim_words($s['description'], 12, '...') : $s['title'];
        $path   = $s['page_path'] !== '' ? $s['page_path'] : ($s['page_url'] !== '' ? $s['page_url'] : '—');
        $page   = $s['page_url'] !== ''
            ? '<a href="' . esc_url($s['page_url']) . '" style="color:#2271b1">' . esc_html($path) . '</a>'
            : esc_html($path);
        $status = matrix_qc_snag_status_label($s['status'] !== '' ? $s['status'] : 'new');

        $h .= '<tr>';
        $h .= '<td style="' . $td . '">' . esc_html($label) . '</td>';
        $h .= '<td style="' . $td . '">' . $page . '</td>';
        $h .= '<td style="' . $td . '">' . esc_html(ucfirst($s['severity'])) . '</td>';
        $h .= '<td style="' . $td . '">' . esc_html($status) . '</td>';
        if ($show_resolver) {
            $h .= '<td style="' . $td . '">' . esc_html(matrix_qc_report_resolver_name($s['id'])) . '</td>';
        }
        $h .= '</tr>';
    }

    $h .= '</tbody></table>';
    return $h;
}

/* -------------------------------------------------------------------------
 * Settings page
 * ---------------------------------------------------------------------- */

/**
 * Register the Notifications submenu.
 */
function matrix_qc_notifications_menu() {
    add_submenu_page(
        'edit.php?post_type=' . MATRIX_QC_SNAG_CPT,
        'QC Notifications',
        'Notifications',
        MATRIX_QC_SNAG_CAP,
        'matrix-qc-notifications',
        'matrix_qc_notifications_settings_page'
    );
}
add_action('admin_menu', 'matrix_qc_notifications_menu');

/**
 * Render the Notifications settings page.
 */
function matrix_qc_notifications_settings_page() {
    if (isset($_POST['matrix_qc_notify_save']) &&
        check_admin_referer('matrix_qc_notify_settings', 'matrix_qc_notify_nonce')) {
        update_option('matrix_qc_notify_cc', sanitize_textarea_field(wp_unslash($_POST['matrix_qc_notify_cc'] ?? '')));
        update_option('matrix_qc_notify_on_status_change', isset($_POST['matrix_qc_notify_on_status_change']) ? '1' : '0');
        update_option('matrix_qc_report_recipients', sanitize_textarea_field(wp_unslash($_POST['matrix_qc_report_recipients'] ?? '')));
        update_option('matrix_qc_report_enabled', isset($_POST['matrix_qc_report_enabled']) ? '1' : '0');
        $freq = sanitize_key(wp_unslash($_POST['matrix_qc_report_frequency'] ?? 'weekly'));
        if (!isset(matrix_qc_report_frequencies()[$freq])) {
            $freq = 'weekly';
        }
        update_option('matrix_qc_report_frequency', $freq);
        matrix_qc_report_reschedule();
        echo '<div class="notice notice-success"><p>Notification settings saved.</p></div>';
    }

    if (isset($_POST['matrix_qc_report_test']) &&
        check_admin_referer('matrix_qc_notify_settings', 'matrix_qc_notify_nonce')) {
        if (empty(matrix_qc_report_recipients())) {
            echo '<div class="notice notice-warning"><p>Add at least one valid report recipient first.</p></div>';
        } else {
            $sent = matrix_qc_weekly_report_send();
            echo '<div class="notice notice-' . ($sent ? 'success' : 'error') . '"><p>'
                . ($sent ? 'Test report sent to the configured recipients.' : 'Could not send the test report (check mail configuration).')
                . '</p></div>';
        }
    }

    $cc        = (string) get_option('matrix_qc_notify_cc', '');
    $on_change = get_option('matrix_qc_notify_on_status_change', '0') === '1';
    $report_to = (string) get_option('matrix_qc_report_recipients', '');
    $report_on = get_option('matrix_qc_report_enabled', '0') === '1';
    $report_fq = matrix_qc_report_frequency_key();
    $next      = wp_next_scheduled(MATRIX_QC_WEEKLY_REPORT_HOOK);

    echo '<div class="wrap"><h1>QC Notifications</h1>';
    echo '<form method="post">';
    wp_nonce_field('matrix_qc_notify_settings', 'matrix_qc_notify_nonce');
    echo '<table class="form-table"><tbody>';

    printf(
        '<tr><th><label>CC recipients</label></th><td><textarea name="matrix_qc_notify_cc" rows="2" class="large-text" placeholder="name@example.com, another@example.com">%s</textarea><p class="description">Copied (Cc) on all QC notification emails: new comments, ready-for-review and reopened. Comma or newline separated.</p></td></tr>',
        esc_textarea($cc)
    );
    printf(
        '<tr><th>Status changes</th><td><label><input type="checkbox" name="matrix_qc_notify_on_status_change" %s /> Email the CC recipients whenever a snag\'s status changes</label><p class="description">A short "from &rarr; to" note. Covers manual and automated (GitHub) changes.</p></td></tr>',
        checked($on_change, true, false)
    );

    echo '<tr><td colspan="2"><hr></td></tr>';

    printf(
        '<tr><th><label>Report recipients</label></th><td><textarea name="matrix_qc_report_recipients" rows="2" class="large-text" placeholder="client@example.com, stakeholder@example.com">%s</textarea><p class="description">Recipients of the snag status summary (stakeholders, clients). Comma or newline separated.</p></td></tr>',
        esc_textarea($report_to)
    );

    echo '<tr><th><label>Frequency</label></th><td><select name="matrix_qc_report_frequency">';
    foreach (matrix_qc_report_frequencies() as $key => $freq) {
        printf(
            '<option value="%s" %s>%s</option>',
            esc_attr($key),
            selected($report_fq, $key, false),
            esc_html($freq['label'])
        );
    }
    echo '</select><p class="description">Daily sends at 08:00 site time; weekly on Mondays at 08:00; sub-daily options run on a rolling interval.</p></td></tr>';

    printf(
        '<tr><th>Status report</th><td><label><input type="checkbox" name="matrix_qc_report_enabled" %s /> Send a recurring status report at the chosen frequency</label>%s</td></tr>',
        checked($report_on, true, false),
        $next ? '<p class="description">Next send: ' . esc_html(date_i18n('D j M Y, H:i', $next + (int) (get_option('gmt_offset', 0) * HOUR_IN_SECONDS))) . '</p>' : ''
    );

    echo '</tbody></table>';
    echo '<p><button class="button button-primary" name="matrix_qc_notify_save" value="1">Save settings</button> ';
    echo '<button class="button" name="matrix_qc_report_test" value="1">Send test report now</button></p>';
    echo '</form></div>';
}
