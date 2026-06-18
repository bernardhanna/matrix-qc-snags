<?php

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Custom columns for the snag list table.
 *
 * @param array<string,string> $columns
 * @return array<string,string>
 */
function matrix_qc_snag_columns($columns) {
    $new = array(
        'cb'        => isset($columns['cb']) ? $columns['cb'] : '',
        'title'     => 'Snag',
        'qc_page'   => 'Page',
        'qc_view'   => 'Viewport',
        'qc_type'   => 'Category',
        'qc_sev'    => 'Severity',
        'qc_prio'   => 'Priority',
        'qc_status' => 'Status',
        'qc_figma'  => 'Figma',
        'date'      => isset($columns['date']) ? $columns['date'] : 'Date',
    );
    return $new;
}
add_filter('manage_' . MATRIX_QC_SNAG_CPT . '_posts_columns', 'matrix_qc_snag_columns');

/**
 * Render custom column values.
 *
 * @param string $column
 * @param int    $post_id
 */
function matrix_qc_snag_render_column($column, $post_id) {
    switch ($column) {
        case 'qc_page':
            $path = get_post_meta($post_id, '_qc_page_path', true);
            $url  = get_post_meta($post_id, '_qc_page_url', true);
            if ($url) {
                printf('<a href="%s" target="_blank">%s</a>', esc_url($url), esc_html($path));
            } else {
                echo esc_html($path);
            }
            break;
        case 'qc_view':
            echo esc_html(get_post_meta($post_id, '_qc_viewport', true));
            break;
        case 'qc_type':
            echo esc_html(matrix_qc_snag_type_label(get_post_meta($post_id, '_qc_type', true)));
            break;
        case 'qc_sev':
            echo esc_html(get_post_meta($post_id, '_qc_severity', true));
            break;
        case 'qc_prio':
            $prio = absint(get_post_meta($post_id, '_qc_priority', true));
            echo $prio ? 'P' . esc_html($prio) : '&mdash;';
            break;
        case 'qc_status':
            echo esc_html(matrix_qc_snag_status_label(get_post_meta($post_id, '_qc_status', true)));
            break;
        case 'qc_figma':
            $figma = get_post_meta($post_id, '_qc_figma_node', true);
            if ($figma) {
                printf('<a href="%s" target="_blank">open</a>', esc_url($figma));
            } else {
                echo '&mdash;';
            }
            break;
    }
}
add_action('manage_' . MATRIX_QC_SNAG_CPT . '_posts_custom_column', 'matrix_qc_snag_render_column', 10, 2);

/**
 * Make the priority column sortable.
 *
 * @param array<string,string> $columns
 * @return array<string,string>
 */
function matrix_qc_snag_sortable_columns($columns) {
    $columns['qc_prio'] = 'qc_prio';
    return $columns;
}
add_filter('manage_edit-' . MATRIX_QC_SNAG_CPT . '_sortable_columns', 'matrix_qc_snag_sortable_columns');

/**
 * Order the snag list by priority when requested (unset priorities last).
 *
 * @param WP_Query $query
 */
function matrix_qc_snag_orderby($query) {
    if (!is_admin() || !$query->is_main_query()) {
        return;
    }
    if ($query->get('orderby') === 'qc_prio') {
        $query->set('meta_key', '_qc_priority');
        $query->set('orderby', 'meta_value_num');
    }
}
add_action('pre_get_posts', 'matrix_qc_snag_orderby');

/**
 * Allow filtering the snag list by page path via a dropdown.
 */
function matrix_qc_snag_page_filter() {
    global $typenow;
    if ($typenow !== MATRIX_QC_SNAG_CPT) {
        return;
    }
    global $wpdb;
    $paths = $wpdb->get_col($wpdb->prepare(
        "SELECT DISTINCT meta_value FROM {$wpdb->postmeta} WHERE meta_key = %s ORDER BY meta_value ASC",
        '_qc_page_path'
    ));
    $current = isset($_GET['qc_page_path']) ? sanitize_text_field(wp_unslash($_GET['qc_page_path'])) : '';
    echo '<select name="qc_page_path"><option value="">All pages</option>';
    foreach ($paths as $path) {
        printf(
            '<option value="%s"%s>%s</option>',
            esc_attr($path),
            selected($current, $path, false),
            esc_html($path)
        );
    }
    echo '</select>';
}
add_action('restrict_manage_posts', 'matrix_qc_snag_page_filter');

/**
 * Apply the page-path filter to the admin query.
 *
 * @param WP_Query $query
 */
function matrix_qc_snag_filter_query($query) {
    global $pagenow;
    if (!is_admin() || $pagenow !== 'edit.php' || $query->get('post_type') !== MATRIX_QC_SNAG_CPT) {
        return;
    }
    if (!empty($_GET['qc_page_path'])) {
        $query->set('meta_query', array(array(
            'key'   => '_qc_page_path',
            'value' => sanitize_text_field(wp_unslash($_GET['qc_page_path'])),
        )));
    }
}
add_action('pre_get_posts', 'matrix_qc_snag_filter_query');

/**
 * Detail metabox on the snag edit screen.
 */
function matrix_qc_snag_add_metabox() {
    add_meta_box(
        'matrix_qc_snag_detail',
        'Snag detail',
        'matrix_qc_snag_render_metabox',
        MATRIX_QC_SNAG_CPT,
        'normal',
        'high'
    );
}
add_action('add_meta_boxes', 'matrix_qc_snag_add_metabox');

/**
 * Render the snag detail metabox (read-only capture data + status control).
 *
 * @param WP_Post $post
 */
function matrix_qc_snag_render_metabox($post) {
    $data  = matrix_qc_snag_to_array($post);
    $enums = matrix_qc_snag_enums();

    echo '<table class="form-table"><tbody>';
    $rows = array(
        'Page'     => $data['page_url'] ? '<a href="' . esc_url($data['page_url']) . '" target="_blank">' . esc_html($data['page_path']) . '</a>' : esc_html($data['page_path']),
        'Viewport' => esc_html($data['viewport'] . ' (' . $data['viewport_width'] . 'px)'),
        'Type'     => esc_html($data['type']),
        'Severity' => esc_html($data['severity']),
        'Block'    => $data['component'] !== '' ? '<code>' . esc_html($data['component']) . '</code>' : '&mdash;',
        'Likely template' => $data['component'] !== '' ? '<code>' . esc_html(matrix_qc_snag_template_hint($data['component'])) . '</code>' : '&mdash;',
        'Selector' => '<code>' . esc_html($data['selector']) . '</code>',
        'Classes'  => $data['classes'] !== '' ? '<code>' . esc_html($data['classes']) . '</code>' : '&mdash;',
        'XPath'    => '<code>' . esc_html($data['xpath']) . '</code>',
        'Element text' => $data['element_text'] !== '' ? esc_html($data['element_text']) : '&mdash;',
        'Styles'   => matrix_qc_snag_format_styles($data['styles']),
        'Figma page'    => $data['figma_node'] ? '<a href="' . esc_url(matrix_qc_snag_figma_view_url($data['figma_node'])) . '" target="_blank">open reference</a>' : '&mdash;',
        'Figma element' => $data['figma_element'] ? '<a href="' . esc_url(matrix_qc_snag_figma_view_url($data['figma_element'])) . '" target="_blank">open element</a>' : '&mdash;',
        'Figma node id' => $data['figma_node_id'] !== '' ? '<code>' . esc_html($data['figma_file_key'] . ' / ' . $data['figma_node_id']) . '</code>' : '&mdash;',
        'PR'       => $data['pr_url'] ? '<a href="' . esc_url($data['pr_url']) . '" target="_blank">' . esc_html($data['pr_url']) . '</a>' : '&mdash;',
    );
    foreach ($rows as $label => $value) {
        echo '<tr><th>' . esc_html($label) . '</th><td>' . wp_kses_post($value) . '</td></tr>';
    }

    echo '<tr><th>Status</th><td>';
    wp_nonce_field('matrix_qc_snag_status', 'matrix_qc_snag_status_nonce');
    echo '<select name="matrix_qc_snag_status">';
    foreach ($enums['status'] as $status) {
        printf(
            '<option value="%s"%s>%s</option>',
            esc_attr($status),
            selected($data['status'], $status, false),
            esc_html(matrix_qc_snag_status_label($status))
        );
    }
    echo '</select></td></tr>';

    echo '<tr><th>Priority</th><td>';
    printf(
        '<input type="number" min="0" max="99" name="matrix_qc_snag_priority" value="%s" style="width:80px" /> <span class="description">1 = highest, 0 = none</span>',
        esc_attr((string) absint($data['priority']))
    );
    echo '</td></tr>';

    if ($data['screenshot_url']) {
        echo '<tr><th>Screenshot</th><td><img src="' . esc_url($data['screenshot_url']) . '" style="max-width:100%;height:auto;border:1px solid #ddd" /></td></tr>';
    }

    $comments = get_comments(array(
        'post_id' => $post->ID,
        'type'    => 'qc_comment',
        'status'  => 'approve',
        'orderby' => 'comment_date',
        'order'   => 'ASC',
    ));
    if (!empty($comments)) {
        echo '<tr><th>Comments</th><td>';
        foreach ($comments as $c) {
            echo '<p style="margin:0 0 10px">';
            echo '<strong>' . esc_html($c->comment_author) . '</strong> ';
            if ($c->comment_author_email !== '') {
                echo '<span style="color:#888">&lt;' . esc_html($c->comment_author_email) . '&gt;</span> ';
            }
            echo '<span style="color:#888">' . esc_html(mysql2date('M j, Y H:i', $c->comment_date)) . '</span><br>';
            echo nl2br(esc_html($c->comment_content));
            echo '</p>';
        }
        echo '</td></tr>';
    }

    $instruction = matrix_qc_snag_agent_instruction($data);
    if ($instruction !== '') {
        echo '<tr><th>Agent instruction</th><td>';
        echo '<textarea readonly rows="6" style="width:100%;font-family:monospace" onclick="this.select()">' . esc_textarea($instruction) . '</textarea>';
        echo '<p class="description">Ready to paste into a Figma-MCP enabled coding agent at fix time.</p>';
        echo '</td></tr>';
    }
    echo '</tbody></table>';
}

/**
 * Render the captured computed-style snapshot as a compact list.
 *
 * @param string $json
 * @return string
 */
function matrix_qc_snag_format_styles($json) {
    if (empty($json)) {
        return '&mdash;';
    }
    $styles = json_decode($json, true);
    if (!is_array($styles) || empty($styles)) {
        return '&mdash;';
    }
    $parts = array();
    foreach ($styles as $key => $value) {
        if ($value === '' || $value === null) {
            continue;
        }
        $parts[] = '<code>' . esc_html($key) . ': ' . esc_html($value) . '</code>';
    }
    return implode(' ', $parts);
}

/**
 * Persist the status field from the metabox.
 *
 * @param int $post_id
 */
function matrix_qc_snag_save_metabox($post_id) {
    if (!isset($_POST['matrix_qc_snag_status_nonce']) ||
        !wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['matrix_qc_snag_status_nonce'])), 'matrix_qc_snag_status')) {
        return;
    }
    if (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) {
        return;
    }
    if (!current_user_can(MATRIX_QC_SNAG_CAP)) {
        return;
    }
    $enums  = matrix_qc_snag_enums();
    $status = isset($_POST['matrix_qc_snag_status']) ? sanitize_text_field(wp_unslash($_POST['matrix_qc_snag_status'])) : '';
    if (in_array($status, $enums['status'], true)) {
        update_post_meta($post_id, '_qc_status', $status);
    }

    if (isset($_POST['matrix_qc_snag_priority'])) {
        update_post_meta($post_id, '_qc_priority', absint(wp_unslash($_POST['matrix_qc_snag_priority'])));
    }
}
add_action('save_post_' . MATRIX_QC_SNAG_CPT, 'matrix_qc_snag_save_metabox');

/**
 * Figma map tools submenu.
 */
function matrix_qc_snag_admin_menu() {
    add_submenu_page(
        'edit.php?post_type=' . MATRIX_QC_SNAG_CPT,
        'QC Dashboard',
        'Dashboard',
        MATRIX_QC_SNAG_CAP,
        'matrix-qc-dashboard',
        'matrix_qc_snag_dashboard_page'
    );
    add_submenu_page(
        'edit.php?post_type=' . MATRIX_QC_SNAG_CPT,
        'Figma Map',
        'Figma Map',
        MATRIX_QC_SNAG_CAP,
        'matrix-qc-figma-map',
        'matrix_qc_snag_figma_map_page'
    );
}
add_action('admin_menu', 'matrix_qc_snag_admin_menu');

/**
 * Severity sort weight (higher = more severe).
 *
 * @param string $severity
 * @return int
 */
function matrix_qc_snag_severity_weight($severity) {
    $map = array('high' => 3, 'medium' => 2, 'low' => 1);
    return isset($map[$severity]) ? $map[$severity] : 0;
}

/**
 * Whether a status counts as still open (not resolved).
 *
 * @param string $status
 * @return bool
 */
function matrix_qc_snag_is_open($status) {
    return !in_array($status, array('fixed', 'reverted', 'non_issue'), true);
}

/**
 * QC Dashboard: overview of all snags across all pages + prioritised list.
 */
function matrix_qc_snag_dashboard_page() {
    $query = new WP_Query(array(
        'post_type'      => MATRIX_QC_SNAG_CPT,
        'post_status'    => 'publish',
        'posts_per_page' => -1,
        'no_found_rows'  => true,
    ));

    $enums   = matrix_qc_snag_enums();
    $pages   = array();
    $totals  = array('total' => 0, 'open' => 0, 'status' => array(), 'severity' => array());
    $snags   = array();

    foreach ($enums['status'] as $s) {
        $totals['status'][$s] = 0;
    }
    foreach ($enums['severity'] as $s) {
        $totals['severity'][$s] = 0;
    }

    foreach ($query->posts as $post) {
        $d = matrix_qc_snag_to_array($post);
        $snags[] = $d;
        $totals['total']++;
        $status = $d['status'] !== '' ? $d['status'] : 'new';
        if (isset($totals['status'][$status])) {
            $totals['status'][$status]++;
        }
        if (isset($totals['severity'][$d['severity']])) {
            $totals['severity'][$d['severity']]++;
        }
        if (matrix_qc_snag_is_open($status)) {
            $totals['open']++;
        }

        $path = $d['page_path'] !== '' ? $d['page_path'] : '(unknown)';
        if (!isset($pages[$path])) {
            $pages[$path] = array(
                'title'  => $d['page_title'],
                'url'    => $d['page_url'],
                'total'  => 0,
                'open'   => 0,
                'status' => array(),
                'high'   => 0,
            );
        }
        $pages[$path]['total']++;
        if (matrix_qc_snag_is_open($status)) {
            $pages[$path]['open']++;
        }
        if ($d['severity'] === 'high' && matrix_qc_snag_is_open($status)) {
            $pages[$path]['high']++;
        }
        if (!isset($pages[$path]['status'][$status])) {
            $pages[$path]['status'][$status] = 0;
        }
        $pages[$path]['status'][$status]++;
        if ($pages[$path]['title'] === '' && $d['page_title'] !== '') {
            $pages[$path]['title'] = $d['page_title'];
        }
    }

    // Pages sorted by open count, then high-severity count.
    uasort($pages, static function ($a, $b) {
        if ($b['open'] !== $a['open']) {
            return $b['open'] - $a['open'];
        }
        return $b['high'] - $a['high'];
    });

    // Open snags ordered by priority (set first, ascending), then severity, then date.
    $open = array_filter($snags, static function ($s) {
        return matrix_qc_snag_is_open($s['status'] !== '' ? $s['status'] : 'new');
    });
    usort($open, static function ($a, $b) {
        $pa = absint($a['priority']);
        $pb = absint($b['priority']);
        $ha = $pa > 0 ? 0 : 1;
        $hb = $pb > 0 ? 0 : 1;
        if ($ha !== $hb) {
            return $ha - $hb;
        }
        if ($pa !== $pb && $pa > 0 && $pb > 0) {
            return $pa - $pb;
        }
        $sa = matrix_qc_snag_severity_weight($a['severity']);
        $sb = matrix_qc_snag_severity_weight($b['severity']);
        if ($sa !== $sb) {
            return $sb - $sa;
        }
        return strcmp($a['created'], $b['created']);
    });

    $list_base = admin_url('edit.php?post_type=' . MATRIX_QC_SNAG_CPT);

    $export_url = wp_nonce_url(
        admin_url('admin-post.php?action=matrix_qc_export'),
        'matrix_qc_export'
    );

    echo '<div class="wrap"><h1>QC Dashboard <a href="' . esc_url($export_url) . '" class="page-title-action">Export CSV</a></h1>';

    // Summary cards.
    echo '<div style="display:flex;gap:12px;flex-wrap:wrap;margin:16px 0">';
    matrix_qc_snag_stat_card('Total snags', $totals['total']);
    matrix_qc_snag_stat_card('Open', $totals['open']);
    matrix_qc_snag_stat_card('Fixed', $totals['status']['fixed']);
    matrix_qc_snag_stat_card('High severity', $totals['severity']['high']);
    echo '</div>';

    // Per-page overview.
    echo '<h2>By page</h2>';
    echo '<table class="widefat striped"><thead><tr>';
    echo '<th>Page</th><th>Open</th><th>Total</th><th>New</th><th>In progress</th><th>PR open</th><th>Fixed</th><th>High</th><th>Actions</th>';
    echo '</tr></thead><tbody>';
    if (empty($pages)) {
        echo '<tr><td colspan="9">No snags logged yet.</td></tr>';
    }
    foreach ($pages as $path => $p) {
        $view = add_query_arg('qc_page_path', rawurlencode($path), $list_base);
        $name = $p['title'] !== '' ? $p['title'] : $path;
        printf(
            '<tr><td><strong>%s</strong><br><code>%s</code></td><td>%d</td><td>%d</td><td>%d</td><td>%d</td><td>%d</td><td>%d</td><td>%d</td><td><a class="button button-small" href="%s">View</a> %s</td></tr>',
            esc_html($name),
            esc_html($path),
            (int) $p['open'],
            (int) $p['total'],
            (int) (isset($p['status']['new']) ? $p['status']['new'] : 0),
            (int) (isset($p['status']['in_progress']) ? $p['status']['in_progress'] : 0),
            (int) (isset($p['status']['pr_open']) ? $p['status']['pr_open'] : 0),
            (int) (isset($p['status']['fixed']) ? $p['status']['fixed'] : 0),
            (int) $p['high'],
            esc_url($view),
            $p['url'] ? '<a class="button button-small" href="' . esc_url($p['url']) . '" target="_blank">Open</a>' : ''
        );
    }
    echo '</tbody></table>';

    // Prioritised list.
    echo '<h2 style="margin-top:28px">Priority queue (open snags)</h2>';
    echo '<p class="description">Set priority inline (1 = highest, 0 = none). Sorted by priority, then severity.</p>';
    echo '<table class="widefat striped" id="qc-priority-table"><thead><tr>';
    echo '<th style="width:90px">Priority</th><th>Snag</th><th>Page</th><th>Severity</th><th>Status</th><th>Figma</th></tr></thead><tbody>';
    if (empty($open)) {
        echo '<tr><td colspan="6">No open snags.</td></tr>';
    }
    foreach ($open as $s) {
        $edit = get_edit_post_link($s['id']);
        printf(
            '<tr>'
            . '<td><input type="number" class="qc-prio-input" data-id="%d" min="0" max="99" value="%d" style="width:64px" /></td>'
            . '<td><a href="%s">%s</a></td>'
            . '<td><code>%s</code></td>'
            . '<td>%s</td><td>%s</td>'
            . '<td>%s</td></tr>',
            (int) $s['id'],
            absint($s['priority']),
            esc_url($edit),
            esc_html($s['description'] !== '' ? wp_trim_words($s['description'], 12, '...') : $s['title']),
            esc_html($s['page_path']),
            esc_html($s['severity']),
            esc_html(matrix_qc_snag_status_label($s['status'] !== '' ? $s['status'] : 'new')),
            $s['figma_element'] ? '<a href="' . esc_url(matrix_qc_snag_figma_view_url($s['figma_element'])) . '" target="_blank">element</a>' : ($s['figma_node'] ? '<a href="' . esc_url(matrix_qc_snag_figma_view_url($s['figma_node'])) . '" target="_blank">page</a>' : '&mdash;')
        );
    }
    echo '</tbody></table>';
    echo '<p id="qc-prio-msg" style="color:#1d6b3f"></p>';
    echo '</div>';

    matrix_qc_snag_dashboard_inline_js();
}

/**
 * Stream all snags as a CSV download.
 */
function matrix_qc_snag_export_csv() {
    if (!current_user_can(MATRIX_QC_SNAG_CAP)) {
        wp_die('Forbidden');
    }
    check_admin_referer('matrix_qc_export');

    $args = array(
        'post_type'      => MATRIX_QC_SNAG_CPT,
        'post_status'    => 'publish',
        'posts_per_page' => -1,
        'no_found_rows'  => true,
        'orderby'        => 'date',
        'order'          => 'DESC',
    );
    if (!empty($_GET['qc_page_path'])) {
        $args['meta_query'] = array(array(
            'key'   => '_qc_page_path',
            'value' => sanitize_text_field(wp_unslash($_GET['qc_page_path'])),
        ));
    }
    $query = new WP_Query($args);

    nocache_headers();
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename=qc-snags-' . gmdate('Ymd-His') . '.csv');

    $out = fopen('php://output', 'w');
    fputcsv($out, array(
        'ID', 'Title', 'Page', 'Page URL', 'Path', 'Viewport', 'Category',
        'Severity', 'Priority', 'Status', 'Description', 'Block', 'Likely template',
        'Selector', 'Classes', 'Element text', 'Figma element', 'Figma page',
        'Created', 'Comments',
    ));

    foreach ($query->posts as $post) {
        $d = matrix_qc_snag_to_array($post);

        $comments = get_comments(array(
            'post_id' => $post->ID,
            'type'    => 'qc_comment',
            'status'  => 'approve',
            'orderby' => 'comment_date',
            'order'   => 'ASC',
        ));
        $comment_str = implode(' | ', array_map(static function ($c) {
            return $c->comment_author . ' (' . $c->comment_author_email . '): ' . $c->comment_content;
        }, $comments));

        fputcsv($out, array(
            $d['id'],
            $d['title'],
            $d['page_title'],
            $d['page_url'],
            $d['page_path'],
            $d['viewport'],
            matrix_qc_snag_type_label($d['type']),
            $d['severity'],
            absint($d['priority']),
            matrix_qc_snag_status_label($d['status']),
            $d['description'],
            $d['component'],
            $d['component'] !== '' ? matrix_qc_snag_template_hint($d['component']) : '',
            $d['selector'],
            $d['classes'],
            $d['element_text'],
            $d['figma_element'],
            $d['figma_node'],
            $d['created'],
            $comment_str,
        ));
    }
    fclose($out);
    exit;
}
add_action('admin_post_matrix_qc_export', 'matrix_qc_snag_export_csv');

/**
 * Render a small stat card.
 *
 * @param string $label
 * @param int    $value
 */
function matrix_qc_snag_stat_card($label, $value) {
    printf(
        '<div style="background:#fff;border:1px solid #dcdcde;border-radius:8px;padding:14px 18px;min-width:120px"><div style="font-size:26px;font-weight:700">%d</div><div style="color:#646970">%s</div></div>',
        (int) $value,
        esc_html($label)
    );
}

/**
 * Inline JS for the dashboard priority editing (uses the plugin REST route).
 */
function matrix_qc_snag_dashboard_inline_js() {
    $endpoint = esc_url_raw(rest_url(MATRIX_QC_SNAG_REST_NS . '/snags/'));
    $nonce    = wp_create_nonce('wp_rest');
    ?>
    <script>
    (function () {
        var endpoint = <?php echo wp_json_encode($endpoint); ?>;
        var nonce = <?php echo wp_json_encode($nonce); ?>;
        var msg = document.getElementById('qc-prio-msg');
        document.querySelectorAll('.qc-prio-input').forEach(function (input) {
            input.addEventListener('change', function () {
                var id = input.getAttribute('data-id');
                fetch(endpoint + id, {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': nonce },
                    credentials: 'same-origin',
                    body: JSON.stringify({ priority: parseInt(input.value, 10) || 0 })
                }).then(function (r) { return r.json(); }).then(function (res) {
                    if (res && res.ok) {
                        msg.textContent = 'Priority saved.';
                        setTimeout(function () { msg.textContent = ''; }, 1500);
                    } else {
                        msg.textContent = 'Save failed.';
                    }
                }).catch(function () { msg.textContent = 'Save failed.'; });
            });
        });
    })();
    </script>
    <?php
}

/**
 * Render the Figma map page: re-import from CSV and show the current map.
 */
function matrix_qc_snag_figma_map_page() {
    if (isset($_POST['matrix_qc_reseed']) &&
        check_admin_referer('matrix_qc_reseed', 'matrix_qc_reseed_nonce')) {
        $count = matrix_qc_snag_seed_figma_map(true);
        echo '<div class="notice notice-success"><p>' . esc_html(sprintf('Imported %d rows from the QC CSV.', $count)) . '</p></div>';
    }

    $map = get_option(MATRIX_QC_FIGMA_MAP_OPTION, array());
    if (!is_array($map)) {
        $map = array();
    }

    echo '<div class="wrap"><h1>QC Figma Map</h1>';
    echo '<p>Maps each page path to its Figma desktop/mobile reference. Snags created on a page auto-attach the matching node.</p>';
    echo '<form method="post">';
    wp_nonce_field('matrix_qc_reseed', 'matrix_qc_reseed_nonce');
    echo '<p><button class="button button-primary" name="matrix_qc_reseed" value="1">Re-import from QC CSV</button></p>';
    echo '</form>';

    echo '<table class="widefat striped"><thead><tr><th>Path</th><th>Name</th><th>Desktop</th><th>Mobile</th></tr></thead><tbody>';
    if (empty($map)) {
        echo '<tr><td colspan="4">No mappings yet.</td></tr>';
    }
    foreach ($map as $path => $entry) {
        printf(
            '<tr><td><code>%s</code></td><td>%s</td><td>%s</td><td>%s</td></tr>',
            esc_html($path),
            esc_html(isset($entry['name']) ? $entry['name'] : ''),
            !empty($entry['desktop']) ? '<a href="' . esc_url($entry['desktop']) . '" target="_blank">desktop</a>' : '&mdash;',
            !empty($entry['mobile']) ? '<a href="' . esc_url($entry['mobile']) . '" target="_blank">mobile</a>' : '&mdash;'
        );
    }
    echo '</tbody></table></div>';
}
