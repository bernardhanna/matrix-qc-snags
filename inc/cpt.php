<?php

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Meta keys used on the qc_snag CPT.
 *
 * @return array<string,string> map of short key => meta key
 */
function matrix_qc_snag_meta_keys() {
    return array(
        'page_url'       => '_qc_page_url',
        'page_path'      => '_qc_page_path',
        'page_title'     => '_qc_page_title',
        'selector'       => '_qc_selector',
        'xpath'          => '_qc_xpath',
        'component'      => '_qc_component',
        'classes'        => '_qc_classes',
        'element_text'   => '_qc_element_text',
        'styles'         => '_qc_styles',
        'bbox'           => '_qc_bbox',
        'viewport'       => '_qc_viewport',
        'viewport_width' => '_qc_viewport_width',
        'screenshot_id'  => '_qc_screenshot_id',
        'figma_node'     => '_qc_figma_node',
        'figma_element'  => '_qc_figma_element',
        'figma_file_key' => '_qc_figma_file_key',
        'figma_node_id'  => '_qc_figma_node_id',
        'type'           => '_qc_type',
        'severity'       => '_qc_severity',
        'priority'       => '_qc_priority',
        'status'         => '_qc_status',
        'pr_url'         => '_qc_pr_url',
        'agent_id'       => '_qc_agent_id',
        'agent_url'      => '_qc_agent_url',
        'fix_text'       => '_qc_fix_text',
        'revert_payload' => '_qc_revert_payload',
    );
}

/**
 * Allowed values for the constrained snag fields.
 */
function matrix_qc_snag_enums() {
    return array(
        'type'     => array('frontend', 'functionality', 'backend', 'content', 'asset', 'accessibility', 'performance', 'seo', 'other'),
        'severity' => array('low', 'medium', 'high'),
        'status'   => array('new', 'triaged', 'review_required', 'in_progress', 'pr_open', 'fixed', 'reverted', 'non_issue'),
        'viewport' => array('desktop', 'mobile'),
    );
}

/**
 * Human-friendly label for a snag category (type).
 *
 * @param string $type
 * @return string
 */
function matrix_qc_snag_type_label($type) {
    $map = array(
        'frontend'      => 'Frontend',
        'functionality' => 'Functionality',
        'backend'       => 'Backend',
        'content'       => 'Content',
        'asset'         => 'Asset',
        'accessibility' => 'Accessibility',
        'performance'   => 'Performance',
        'seo'           => 'SEO',
        'other'         => 'Other',
        'code'          => 'Design/code',
    );
    return isset($map[$type]) ? $map[$type] : ucfirst((string) $type);
}

/**
 * Human-friendly label for a snag status.
 *
 * @param string $status
 * @return string
 */
function matrix_qc_snag_status_label($status) {
    $map = array(
        'new'             => 'New',
        'triaged'         => 'Triaged',
        'review_required' => 'Review required',
        'in_progress'     => 'In progress',
        'pr_open'         => 'PR open',
        'fixed'           => 'Fixed',
        'reverted'        => 'Reverted',
        'non_issue'       => 'Non-issue',
    );
    return isset($map[$status]) ? $map[$status] : ucfirst(str_replace('_', ' ', (string) $status));
}

/**
 * Register the qc_snag custom post type and its meta.
 */
function matrix_qc_snag_register_cpt() {
    register_post_type(MATRIX_QC_SNAG_CPT, array(
        'labels' => array(
            'name'          => 'QC Snags',
            'singular_name' => 'QC Snag',
            'menu_name'     => 'QC Snags',
            'all_items'     => 'All Snags',
        ),
        'public'              => false,
        'show_ui'             => true,
        'show_in_menu'        => true,
        'show_in_rest'        => false,
        'menu_icon'           => 'dashicons-warning',
        'menu_position'       => 58,
        'supports'            => array('title', 'editor'),
        'capability_type'     => 'post',
        'map_meta_cap'        => true,
        'exclude_from_search' => true,
        'has_archive'         => false,
        'rewrite'             => false,
    ));

    $string_meta = array(
        '_qc_page_url', '_qc_page_path', '_qc_page_title', '_qc_selector',
        '_qc_xpath', '_qc_component', '_qc_classes', '_qc_element_text', '_qc_styles', '_qc_bbox', '_qc_viewport',
        '_qc_figma_node', '_qc_figma_element', '_qc_figma_file_key', '_qc_figma_node_id',
        '_qc_type', '_qc_severity', '_qc_status', '_qc_pr_url', '_qc_agent_id',
        '_qc_agent_url', '_qc_fix_text', '_qc_revert_payload',
    );
    foreach ($string_meta as $key) {
        register_post_meta(MATRIX_QC_SNAG_CPT, $key, array(
            'type'              => 'string',
            'single'            => true,
            'show_in_rest'      => false,
            'sanitize_callback' => 'matrix_qc_snag_sanitize_meta_text',
            'auth_callback'     => 'matrix_qc_snag_user_can_review',
        ));
    }

    register_post_meta(MATRIX_QC_SNAG_CPT, '_qc_viewport_width', array(
        'type'              => 'integer',
        'single'            => true,
        'show_in_rest'      => false,
        'sanitize_callback' => 'absint',
        'auth_callback'     => 'matrix_qc_snag_user_can_review',
    ));

    register_post_meta(MATRIX_QC_SNAG_CPT, '_qc_priority', array(
        'type'              => 'integer',
        'single'            => true,
        'show_in_rest'      => false,
        'sanitize_callback' => 'absint',
        'auth_callback'     => 'matrix_qc_snag_user_can_review',
    ));

    register_post_meta(MATRIX_QC_SNAG_CPT, '_qc_screenshot_id', array(
        'type'              => 'integer',
        'single'            => true,
        'show_in_rest'      => false,
        'sanitize_callback' => 'absint',
        'auth_callback'     => 'matrix_qc_snag_user_can_review',
    ));
}
add_action('init', 'matrix_qc_snag_register_cpt');

/**
 * Sanitize free-form meta text while preserving JSON blobs (bbox, revert_payload).
 *
 * @param mixed $value
 * @return string
 */
function matrix_qc_snag_sanitize_meta_text($value) {
    if (is_array($value)) {
        $value = wp_json_encode($value);
    }
    return sanitize_textarea_field((string) $value);
}
