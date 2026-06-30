<?php
/**
 * Plugin Name: Matrix QC Snag
 * Description: In-site QC snagging overlay. Reviewers pin snags per page (desktop/mobile) with screenshot, selector and Figma reference. Phase 1: capture + admin review. Later phases add content auto-fix and a coding-agent PR bridge.
 * Version: 0.5.1
 * Author: Matrix
 */

if (!defined('ABSPATH')) {
    exit;
}

define('MATRIX_QC_SNAG_VERSION', '0.5.1');
define('MATRIX_QC_SNAG_FILE', __FILE__);
define('MATRIX_QC_SNAG_DIR', plugin_dir_path(__FILE__));
define('MATRIX_QC_SNAG_URL', plugin_dir_url(__FILE__));
define('MATRIX_QC_SNAG_CAP', 'manage_qc_snags');
define('MATRIX_QC_SNAG_CPT', 'qc_snag');

require_once MATRIX_QC_SNAG_DIR . 'inc/cpt.php';
require_once MATRIX_QC_SNAG_DIR . 'inc/figma-map.php';
require_once MATRIX_QC_SNAG_DIR . 'inc/rest.php';
require_once MATRIX_QC_SNAG_DIR . 'inc/admin.php';
require_once MATRIX_QC_SNAG_DIR . 'inc/overlay.php';
require_once MATRIX_QC_SNAG_DIR . 'inc/agent.php';
require_once MATRIX_QC_SNAG_DIR . 'inc/github.php';
require_once MATRIX_QC_SNAG_DIR . 'inc/notifications.php';
require_once MATRIX_QC_SNAG_DIR . 'inc/content-fix.php';

/**
 * Grant the QC capability to administrators and editors on activation,
 * register the CPT, then flush rewrite rules.
 */
function matrix_qc_snag_activate() {
    matrix_qc_snag_register_cpt();

    foreach (array('administrator', 'editor') as $role_name) {
        $role = get_role($role_name);
        if ($role && !$role->has_cap(MATRIX_QC_SNAG_CAP)) {
            $role->add_cap(MATRIX_QC_SNAG_CAP);
        }
    }

    matrix_qc_snag_seed_figma_map();

    if (function_exists('matrix_qc_report_reschedule')) {
        matrix_qc_report_reschedule();
    }

    // Best-effort: drop the CI gate workflow into the repo if it's missing.
    if (function_exists('matrix_qc_agent_install_ci') && matrix_qc_agent_ci_path() !== '') {
        matrix_qc_agent_install_ci(false);
    }

    flush_rewrite_rules();
}
register_activation_hook(__FILE__, 'matrix_qc_snag_activate');

/**
 * Clean up rewrite rules on deactivation. Capabilities and data are kept.
 */
function matrix_qc_snag_deactivate() {
    wp_clear_scheduled_hook(MATRIX_QC_AGENT_CRON);
    if (defined('MATRIX_QC_WEEKLY_REPORT_HOOK')) {
        wp_clear_scheduled_hook(MATRIX_QC_WEEKLY_REPORT_HOOK);
    }
    flush_rewrite_rules();
}
register_deactivation_hook(__FILE__, 'matrix_qc_snag_deactivate');

/**
 * Whether the current user may create/manage QC snags.
 */
function matrix_qc_snag_user_can_review() {
    return is_user_logged_in() && current_user_can(MATRIX_QC_SNAG_CAP);
}
