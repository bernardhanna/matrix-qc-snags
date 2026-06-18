<?php

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Enqueue the overlay assets on the front end for QC reviewers only.
 */
function matrix_qc_snag_enqueue_overlay() {
    if (is_admin() || !matrix_qc_snag_user_can_review()) {
        return;
    }

    wp_enqueue_style(
        'matrix-qc-snag-overlay',
        MATRIX_QC_SNAG_URL . 'assets/overlay.css',
        array(),
        MATRIX_QC_SNAG_VERSION
    );

    wp_enqueue_script(
        'html2canvas',
        'https://cdnjs.cloudflare.com/ajax/libs/html2canvas/1.4.1/html2canvas.min.js',
        array(),
        '1.4.1',
        true
    );

    wp_enqueue_script(
        'matrix-qc-snag-overlay',
        MATRIX_QC_SNAG_URL . 'assets/overlay.js',
        array('html2canvas'),
        MATRIX_QC_SNAG_VERSION,
        true
    );

    $path = matrix_qc_snag_normalize_path(
        isset($_SERVER['REQUEST_URI']) ? wp_unslash($_SERVER['REQUEST_URI']) : '/'
    );

    wp_localize_script('matrix-qc-snag-overlay', 'MatrixQCSnag', array(
        'toggleId'    => 'matrix-qc-mode',
        'restUrl'     => esc_url_raw(rest_url(MATRIX_QC_SNAG_REST_NS)),
        'nonce'       => wp_create_nonce('wp_rest'),
        'pagePath'    => $path,
        'pageUrl'     => home_url(add_query_arg(array(), $path)),
        'pageTitle'   => wp_get_document_title(),
        'figmaDesktop'=> matrix_qc_snag_get_figma_node($path, 'desktop'),
        'figmaMobile' => matrix_qc_snag_get_figma_node($path, 'mobile'),
        'mobileMax'   => 767,
        'enums'       => matrix_qc_snag_enums(),
    ));
}
add_action('wp_enqueue_scripts', 'matrix_qc_snag_enqueue_overlay');

/**
 * Add a "QC Mode" toggle to the WordPress admin toolbar (front end).
 *
 * @param WP_Admin_Bar $bar
 */
function matrix_qc_snag_admin_bar_toggle($bar) {
    if (is_admin() || !matrix_qc_snag_user_can_review()) {
        return;
    }

    $bar->add_node(array(
        'id'    => 'matrix-qc-mode',
        'title' => '<span class="ab-icon dashicons dashicons-warning" style="top:2px"></span><span class="ab-label" data-qc-toggle-label>QC Mode: Off</span>',
        'href'  => '#',
        'meta'  => array(
            'class' => 'matrix-qc-mode-toggle',
            'title' => 'Toggle the QC snagging overlay',
        ),
    ));
}
add_action('admin_bar_menu', 'matrix_qc_snag_admin_bar_toggle', 100);
