<?php

if (!defined('ABSPATH')) {
    exit;
}

define('MATRIX_QC_SNAG_REST_NS', 'matrix-qc/v1');

/**
 * Register REST routes for the overlay.
 */
function matrix_qc_snag_register_rest() {
    register_rest_route(MATRIX_QC_SNAG_REST_NS, '/snags', array(
        array(
            'methods'             => WP_REST_Server::READABLE,
            'callback'            => 'matrix_qc_snag_rest_list',
            'permission_callback' => 'matrix_qc_snag_user_can_review',
        ),
        array(
            'methods'             => WP_REST_Server::CREATABLE,
            'callback'            => 'matrix_qc_snag_rest_create',
            'permission_callback' => 'matrix_qc_snag_user_can_review',
        ),
    ));

    register_rest_route(MATRIX_QC_SNAG_REST_NS, '/snags/(?P<id>\d+)/status', array(
        'methods'             => WP_REST_Server::CREATABLE,
        'callback'            => 'matrix_qc_snag_rest_update_status',
        'permission_callback' => 'matrix_qc_snag_user_can_review',
    ));

    register_rest_route(MATRIX_QC_SNAG_REST_NS, '/snags/(?P<id>\d+)', array(
        array(
            'methods'             => WP_REST_Server::EDITABLE,
            'callback'            => 'matrix_qc_snag_rest_update',
            'permission_callback' => 'matrix_qc_snag_user_can_review',
        ),
        array(
            'methods'             => WP_REST_Server::DELETABLE,
            'callback'            => 'matrix_qc_snag_rest_delete',
            'permission_callback' => 'matrix_qc_snag_user_can_review',
        ),
    ));

    register_rest_route(MATRIX_QC_SNAG_REST_NS, '/snags/(?P<id>\d+)/reopen', array(
        'methods'             => WP_REST_Server::CREATABLE,
        'callback'            => 'matrix_qc_snag_rest_reopen',
        'permission_callback' => 'matrix_qc_snag_user_can_review',
    ));

    register_rest_route(MATRIX_QC_SNAG_REST_NS, '/snags/(?P<id>\d+)/comments', array(
        array(
            'methods'             => WP_REST_Server::READABLE,
            'callback'            => 'matrix_qc_snag_rest_list_comments',
            'permission_callback' => 'matrix_qc_snag_user_can_review',
        ),
        array(
            'methods'             => WP_REST_Server::CREATABLE,
            'callback'            => 'matrix_qc_snag_rest_add_comment',
            'permission_callback' => 'matrix_qc_snag_user_can_review',
        ),
    ));

    register_rest_route(MATRIX_QC_SNAG_REST_NS, '/screenshot', array(
        'methods'             => WP_REST_Server::CREATABLE,
        'callback'            => 'matrix_qc_snag_rest_screenshot',
        'permission_callback' => 'matrix_qc_snag_user_can_review',
    ));
}
add_action('rest_api_init', 'matrix_qc_snag_register_rest');

/**
 * List snags, optionally filtered by page path.
 *
 * @param WP_REST_Request $request
 * @return WP_REST_Response
 */
function matrix_qc_snag_rest_list($request) {
    $args = array(
        'post_type'      => MATRIX_QC_SNAG_CPT,
        'post_status'    => 'publish',
        'posts_per_page' => 200,
        'orderby'        => 'date',
        'order'          => 'DESC',
    );

    $path = $request->get_param('path');
    if (!empty($path)) {
        $args['meta_query'] = array(array(
            'key'   => '_qc_page_path',
            'value' => matrix_qc_snag_normalize_path($path),
        ));
    }

    $query = new WP_Query($args);
    $items = array();
    foreach ($query->posts as $post) {
        $items[] = matrix_qc_snag_to_array($post);
    }

    return rest_ensure_response(array('snags' => $items));
}

/**
 * Create a snag from the overlay payload.
 *
 * @param WP_REST_Request $request
 * @return WP_REST_Response|WP_Error
 */
function matrix_qc_snag_rest_create($request) {
    $enums    = matrix_qc_snag_enums();
    $path     = matrix_qc_snag_normalize_path((string) $request->get_param('page_path'));
    $viewport = in_array($request->get_param('viewport'), $enums['viewport'], true)
        ? $request->get_param('viewport')
        : 'desktop';
    $type = in_array($request->get_param('type'), $enums['type'], true)
        ? $request->get_param('type')
        : 'frontend';
    $severity = in_array($request->get_param('severity'), $enums['severity'], true)
        ? $request->get_param('severity')
        : 'medium';

    $description  = sanitize_textarea_field((string) $request->get_param('description'));
    $page_title   = sanitize_text_field((string) $request->get_param('page_title'));
    $custom_title = sanitize_text_field((string) $request->get_param('title'));

    $title = $custom_title !== '' ? $custom_title : sprintf(
        '[%s] %s: %s',
        strtoupper($viewport),
        $page_title !== '' ? $page_title : $path,
        wp_trim_words($description, 8, '...')
    );

    $post_id = wp_insert_post(array(
        'post_type'    => MATRIX_QC_SNAG_CPT,
        'post_status'  => 'publish',
        'post_title'   => $title,
        'post_content' => $description,
        'post_author'  => get_current_user_id(),
    ), true);

    if (is_wp_error($post_id)) {
        return $post_id;
    }

    $figma = sanitize_text_field((string) $request->get_param('figma_node'));
    if ($figma === '') {
        $figma = matrix_qc_snag_get_figma_node($path, $viewport);
    }

    $figma_element = matrix_qc_snag_parse_figma_url((string) $request->get_param('figma_element'));

    $meta = array(
        '_qc_page_url'       => esc_url_raw((string) $request->get_param('page_url')),
        '_qc_page_path'      => $path,
        '_qc_page_title'     => $page_title,
        '_qc_selector'       => sanitize_text_field((string) $request->get_param('selector')),
        '_qc_xpath'          => sanitize_text_field((string) $request->get_param('xpath')),
        '_qc_component'      => sanitize_text_field((string) $request->get_param('component')),
        '_qc_classes'        => sanitize_text_field((string) $request->get_param('classes')),
        '_qc_element_text'   => sanitize_textarea_field((string) $request->get_param('element_text')),
        '_qc_styles'         => matrix_qc_snag_sanitize_json($request->get_param('styles')),
        '_qc_bbox'           => matrix_qc_snag_sanitize_json($request->get_param('bbox')),
        '_qc_viewport'       => $viewport,
        '_qc_viewport_width' => absint($request->get_param('viewport_width')),
        '_qc_screenshot_id'  => absint($request->get_param('screenshot_id')),
        '_qc_figma_node'     => $figma,
        '_qc_figma_element'  => $figma_element['url'],
        '_qc_figma_file_key' => $figma_element['file_key'],
        '_qc_figma_node_id'  => $figma_element['node_id'],
        '_qc_type'           => $type,
        '_qc_severity'       => $severity,
        '_qc_priority'       => absint($request->get_param('priority')),
        '_qc_status'         => 'new',
    );
    foreach ($meta as $key => $value) {
        update_post_meta($post_id, $key, $value);
    }

    return rest_ensure_response(array(
        'ok'   => true,
        'snag' => matrix_qc_snag_to_array(get_post($post_id)),
    ));
}

/**
 * Update a snag status (used by the admin review screen and overlay).
 *
 * @param WP_REST_Request $request
 * @return WP_REST_Response|WP_Error
 */
function matrix_qc_snag_rest_update_status($request) {
    $id    = absint($request['id']);
    $post  = get_post($id);
    if (!$post || $post->post_type !== MATRIX_QC_SNAG_CPT) {
        return new WP_Error('not_found', 'Snag not found', array('status' => 404));
    }

    $enums  = matrix_qc_snag_enums();
    $status = $request->get_param('status');
    if (!in_array($status, $enums['status'], true)) {
        return new WP_Error('bad_status', 'Invalid status', array('status' => 400));
    }

    update_post_meta($id, '_qc_status', $status);

    return rest_ensure_response(array('ok' => true, 'status' => $status));
}

/**
 * Update editable snag fields (description, type, severity, status).
 *
 * @param WP_REST_Request $request
 * @return WP_REST_Response|WP_Error
 */
function matrix_qc_snag_rest_update($request) {
    $id   = absint($request['id']);
    $post = get_post($id);
    if (!$post || $post->post_type !== MATRIX_QC_SNAG_CPT) {
        return new WP_Error('not_found', 'Snag not found', array('status' => 404));
    }

    $enums = matrix_qc_snag_enums();

    $post_update = array('ID' => $id);
    if ($request->get_param('description') !== null) {
        $post_update['post_content'] = sanitize_textarea_field((string) $request->get_param('description'));
    }
    if ($request->get_param('title') !== null) {
        $new_title = sanitize_text_field((string) $request->get_param('title'));
        if ($new_title !== '') {
            $post_update['post_title'] = $new_title;
        }
    }
    if (count($post_update) > 1) {
        wp_update_post($post_update);
    }

    foreach (array('type', 'severity', 'status') as $field) {
        $value = $request->get_param($field);
        if ($value !== null && in_array($value, $enums[$field], true)) {
            update_post_meta($id, '_qc_' . $field, $value);
        }
    }

    if ($request->get_param('priority') !== null) {
        update_post_meta($id, '_qc_priority', absint($request->get_param('priority')));
    }

    if ($request->get_param('figma_element') !== null) {
        $figma_element = matrix_qc_snag_parse_figma_url((string) $request->get_param('figma_element'));
        update_post_meta($id, '_qc_figma_element', $figma_element['url']);
        update_post_meta($id, '_qc_figma_file_key', $figma_element['file_key']);
        update_post_meta($id, '_qc_figma_node_id', $figma_element['node_id']);
    }

    return rest_ensure_response(array(
        'ok'   => true,
        'snag' => matrix_qc_snag_to_array(get_post($id)),
    ));
}

/**
 * Delete a snag (and its screenshot attachment).
 *
 * @param WP_REST_Request $request
 * @return WP_REST_Response|WP_Error
 */
function matrix_qc_snag_rest_delete($request) {
    $id   = absint($request['id']);
    $post = get_post($id);
    if (!$post || $post->post_type !== MATRIX_QC_SNAG_CPT) {
        return new WP_Error('not_found', 'Snag not found', array('status' => 404));
    }

    $screenshot_id = absint(get_post_meta($id, '_qc_screenshot_id', true));
    if ($screenshot_id) {
        wp_delete_attachment($screenshot_id, true);
    }

    wp_delete_post($id, true);

    return rest_ensure_response(array('ok' => true, 'deleted' => $id));
}

/**
 * List comments for a snag.
 *
 * @param WP_REST_Request $request
 * @return WP_REST_Response|WP_Error
 */
function matrix_qc_snag_rest_list_comments($request) {
    $id   = absint($request['id']);
    $post = get_post($id);
    if (!$post || $post->post_type !== MATRIX_QC_SNAG_CPT) {
        return new WP_Error('not_found', 'Snag not found', array('status' => 404));
    }

    $comments = get_comments(array(
        'post_id' => $id,
        'type'    => 'qc_comment',
        'status'  => 'approve',
        'orderby' => 'comment_date',
        'order'   => 'ASC',
    ));

    $items = array_map('matrix_qc_snag_comment_to_array', $comments);

    return rest_ensure_response(array('comments' => $items));
}

/**
 * Add a comment to a snag. The commenter may set their own email.
 *
 * @param WP_REST_Request $request
 * @return WP_REST_Response|WP_Error
 */
function matrix_qc_snag_rest_add_comment($request) {
    $id   = absint($request['id']);
    $post = get_post($id);
    if (!$post || $post->post_type !== MATRIX_QC_SNAG_CPT) {
        return new WP_Error('not_found', 'Snag not found', array('status' => 404));
    }

    $content = sanitize_textarea_field((string) $request->get_param('content'));
    if ($content === '') {
        return new WP_Error('empty', 'Comment is empty', array('status' => 400));
    }

    $user  = wp_get_current_user();
    $email = sanitize_email((string) $request->get_param('email'));
    if ($email === '' || !is_email($email)) {
        $email = $user->user_email;
    }

    $comment_id = wp_insert_comment(array(
        'comment_post_ID'      => $id,
        'comment_content'      => $content,
        'comment_type'         => 'qc_comment',
        'comment_author'       => $user->display_name,
        'comment_author_email' => $email,
        'user_id'              => $user->ID,
        'comment_approved'     => 1,
    ));

    if (!$comment_id) {
        return new WP_Error('insert_failed', 'Could not save comment', array('status' => 500));
    }

    $comment = get_comment($comment_id);
    matrix_qc_snag_notify_comment($post, $comment, $user);

    return rest_ensure_response(array(
        'ok'      => true,
        'comment' => matrix_qc_snag_comment_to_array($comment),
    ));
}

/**
 * Notify the snag's original author (and any prior thread participants) that a
 * new comment was added, with a link to log in and reply on the page itself.
 *
 * The commenter is never notified about their own comment.
 *
 * @param WP_Post    $post      The snag the comment belongs to.
 * @param WP_Comment $comment   The newly inserted comment.
 * @param WP_User    $commenter The user who left the comment.
 */
function matrix_qc_snag_notify_comment($post, $comment, $commenter) {
    if (!$post || !$comment) {
        return;
    }

    // Recipients keyed by lowercase email so we de-dupe naturally.
    $recipients = array();

    $author_id = (int) $post->post_author;
    if ($author_id) {
        $author = get_userdata($author_id);
        if ($author && is_email($author->user_email)) {
            $recipients[strtolower($author->user_email)] = true;
        }
    }

    // Loop in anyone who already took part in this snag's thread.
    $prior = get_comments(array(
        'post_id' => $post->ID,
        'type'    => 'qc_comment',
        'status'  => 'approve',
    ));
    foreach ($prior as $c) {
        $email = strtolower((string) $c->comment_author_email);
        if ($email !== '' && is_email($email)) {
            $recipients[$email] = true;
        }
    }

    // Never email the person who just commented.
    $commenter_email = strtolower((string) $comment->comment_author_email);
    if ($commenter_email !== '') {
        unset($recipients[$commenter_email]);
    }
    if ($commenter && is_email($commenter->user_email)) {
        unset($recipients[strtolower($commenter->user_email)]);
    }

    $recipients = apply_filters(
        'matrix_qc_snag_comment_recipients',
        array_keys($recipients),
        $post,
        $comment,
        $commenter
    );
    if (empty($recipients)) {
        return;
    }

    $page_url = (string) get_post_meta($post->ID, '_qc_page_url', true);
    if ($page_url === '') {
        $page_url = home_url('/');
    }
    // Deep link opens the snag in the overlay; login wrapper forces auth first
    // and returns the user to the snag afterwards.
    $deep_link  = add_query_arg('qc_snag', (int) $post->ID, $page_url);
    $reply_link = wp_login_url($deep_link);
    $edit_link  = get_edit_post_link($post->ID, 'raw');

    $title        = get_the_title($post);
    $commenter_nm = $comment->comment_author !== ''
        ? $comment->comment_author
        : ($commenter ? $commenter->display_name : 'Someone');

    $subject = sprintf('[%s] New comment on QC snag: %s', get_bloginfo('name'), $title);

    $lines   = array();
    $lines[] = sprintf('%s added a comment to a snag you raised:', $commenter_nm);
    $lines[] = '';
    $lines[] = 'Snag: ' . $title;
    $lines[] = 'Page: ' . $page_url;
    $lines[] = '';
    $lines[] = 'Comment:';
    $lines[] = wp_strip_all_tags($comment->comment_content);
    $lines[] = '';
    $lines[] = 'Log in and reply on the page:';
    $lines[] = $reply_link;
    if ($edit_link) {
        $lines[] = '';
        $lines[] = 'Or review it in the dashboard:';
        $lines[] = $edit_link;
    }
    $message = implode("\n", $lines);

    $subject = apply_filters('matrix_qc_snag_comment_subject', $subject, $post, $comment);
    $message = apply_filters('matrix_qc_snag_comment_message', $message, $post, $comment, $reply_link);

    wp_mail($recipients, $subject, $message, matrix_qc_notify_cc_headers());
}

/**
 * REST: reopen a snag (send it back to the open queue) and notify whoever
 * marked it resolved.
 *
 * @param WP_REST_Request $request
 * @return WP_REST_Response|WP_Error
 */
function matrix_qc_snag_rest_reopen($request) {
    $id   = absint($request['id']);
    $post = get_post($id);
    if (!$post || $post->post_type !== MATRIX_QC_SNAG_CPT) {
        return new WP_Error('not_found', 'Snag not found', array('status' => 404));
    }

    $note = sanitize_textarea_field((string) $request->get_param('note'));
    $res  = matrix_qc_snag_reopen($id, wp_get_current_user(), $note);
    if (is_wp_error($res)) {
        return $res;
    }

    return rest_ensure_response(array(
        'ok'   => true,
        'snag' => matrix_qc_snag_to_array(get_post($id)),
    ));
}

/**
 * Reopen a resolved snag: move it back to "Triaged", log it, and email the
 * person who resolved it (falling back to the original reporter).
 *
 * @param int     $id
 * @param WP_User $actor The user performing the reopen.
 * @param string  $note  Optional reason to include in the notification.
 * @return true|WP_Error
 */
function matrix_qc_snag_reopen($id, $actor, $note = '') {
    $post = get_post($id);
    if (!$post || $post->post_type !== MATRIX_QC_SNAG_CPT) {
        return new WP_Error('not_found', 'Snag not found', array('status' => 404));
    }

    $prev        = (string) get_post_meta($id, '_qc_status', true);
    $actor_name  = ($actor && $actor->exists()) ? $actor->display_name : 'Someone';

    update_post_meta($id, '_qc_status', 'triaged');

    $comment = sprintf('Reopened by %s (was: %s).', $actor_name, matrix_qc_snag_status_label($prev !== '' ? $prev : 'new'));
    if ($note !== '') {
        $comment .= ' Reason: ' . $note;
    }
    matrix_qc_snag_add_system_comment($id, $comment);

    matrix_qc_snag_notify_reopened($post, $prev, $actor, $note);

    // Clear the recorded resolver; the next resolution will set a fresh one.
    delete_post_meta($id, '_qc_resolved_by');

    return true;
}

/**
 * Email the person who resolved a snag that it has been reopened. Falls back to
 * the original reporter when no human resolver is on record (e.g. an automated
 * merge). The user performing the reopen is never emailed.
 *
 * @param WP_Post $post
 * @param string  $prev_status
 * @param WP_User $actor
 * @param string  $note
 */
function matrix_qc_snag_notify_reopened($post, $prev_status, $actor, $note = '') {
    $recipients = array();

    $resolver_id = (int) get_post_meta($post->ID, '_qc_resolved_by', true);
    if ($resolver_id) {
        $resolver = get_userdata($resolver_id);
        if ($resolver && is_email($resolver->user_email)) {
            $recipients[strtolower($resolver->user_email)] = true;
        }
    }

    if (empty($recipients)) {
        $author = get_userdata((int) $post->post_author);
        if ($author && is_email($author->user_email)) {
            $recipients[strtolower($author->user_email)] = true;
        }
    }

    if ($actor && is_email($actor->user_email)) {
        unset($recipients[strtolower($actor->user_email)]);
    }

    $recipients = apply_filters('matrix_qc_snag_reopen_recipients', array_keys($recipients), $post, $actor);
    if (empty($recipients)) {
        return;
    }

    $page_url = (string) get_post_meta($post->ID, '_qc_page_url', true);
    if ($page_url === '') {
        $page_url = home_url('/');
    }
    $deep_link  = add_query_arg('qc_snag', (int) $post->ID, $page_url);
    $reply_link = wp_login_url($deep_link);
    $edit_link  = get_edit_post_link($post->ID, 'raw');
    $pr_url     = (string) get_post_meta($post->ID, '_qc_pr_url', true);

    $title      = get_the_title($post);
    $actor_name = ($actor && $actor->exists()) ? $actor->display_name : 'Someone';

    $subject = sprintf('[%s] QC snag reopened: %s', get_bloginfo('name'), $title);

    $lines   = array();
    $lines[] = sprintf('%s reopened a snag that was previously marked "%s".', $actor_name, matrix_qc_snag_status_label($prev_status !== '' ? $prev_status : 'new'));
    $lines[] = '';
    $lines[] = 'Snag: ' . $title;
    $lines[] = 'Page: ' . $page_url;
    if ($pr_url !== '') {
        $lines[] = 'Previous PR: ' . $pr_url;
    }
    if ($note !== '') {
        $lines[] = '';
        $lines[] = 'Reason given:';
        $lines[] = $note;
    }
    $lines[] = '';
    $lines[] = 'Open it to take another look:';
    $lines[] = $reply_link;
    if ($edit_link) {
        $lines[] = '';
        $lines[] = 'Or in the dashboard:';
        $lines[] = $edit_link;
    }
    $message = implode("\n", $lines);

    $subject = apply_filters('matrix_qc_snag_reopen_subject', $subject, $post, $actor);
    $message = apply_filters('matrix_qc_snag_reopen_message', $message, $post, $actor, $reply_link);

    wp_mail($recipients, $subject, $message, matrix_qc_notify_cc_headers());
}

/**
 * Shape a comment for API responses.
 *
 * @param WP_Comment $comment
 * @return array<string,mixed>
 */
function matrix_qc_snag_comment_to_array($comment) {
    return array(
        'id'      => (int) $comment->comment_ID,
        'author'  => $comment->comment_author,
        'email'   => $comment->comment_author_email,
        'content' => $comment->comment_content,
        'date'    => mysql2date('c', $comment->comment_date),
    );
}

/**
 * Store a base64 screenshot as a media attachment and return its id/url.
 *
 * @param WP_REST_Request $request
 * @return WP_REST_Response|WP_Error
 */
function matrix_qc_snag_rest_screenshot($request) {
    $data_url = (string) $request->get_param('image');
    if (strpos($data_url, 'data:image/') !== 0) {
        return new WP_Error('bad_image', 'Expected a data:image/* payload', array('status' => 400));
    }

    if (!preg_match('#^data:image/(png|jpeg|webp);base64,#', $data_url, $m)) {
        return new WP_Error('bad_image', 'Unsupported image type', array('status' => 400));
    }
    $ext     = $m[1] === 'jpeg' ? 'jpg' : $m[1];
    $encoded = substr($data_url, strpos($data_url, ',') + 1);
    $binary  = base64_decode($encoded, true);
    if ($binary === false) {
        return new WP_Error('bad_image', 'Could not decode image', array('status' => 400));
    }

    require_once ABSPATH . 'wp-admin/includes/file.php';
    require_once ABSPATH . 'wp-admin/includes/media.php';
    require_once ABSPATH . 'wp-admin/includes/image.php';

    $filename = 'qc-snag-' . gmdate('Ymd-His') . '-' . wp_generate_password(6, false) . '.' . $ext;
    $upload   = wp_upload_bits($filename, null, $binary);
    if (!empty($upload['error'])) {
        return new WP_Error('upload_failed', $upload['error'], array('status' => 500));
    }

    $attachment_id = wp_insert_attachment(array(
        'post_mime_type' => 'image/' . $m[1],
        'post_title'     => $filename,
        'post_status'    => 'inherit',
    ), $upload['file']);

    if (is_wp_error($attachment_id)) {
        return $attachment_id;
    }

    wp_update_attachment_metadata(
        $attachment_id,
        wp_generate_attachment_metadata($attachment_id, $upload['file'])
    );

    return rest_ensure_response(array(
        'id'  => $attachment_id,
        'url' => wp_get_attachment_url($attachment_id),
    ));
}

/**
 * Sanitize a JSON-ish param into a compact JSON string.
 *
 * @param mixed $value
 * @return string
 */
function matrix_qc_snag_sanitize_json($value) {
    if (is_array($value)) {
        return wp_json_encode($value);
    }
    $decoded = json_decode((string) $value, true);
    if (is_array($decoded)) {
        return wp_json_encode($decoded);
    }
    return '';
}

/**
 * Shape a snag post for API responses.
 *
 * @param WP_Post $post
 * @return array<string,mixed>
 */
function matrix_qc_snag_to_array($post) {
    $keys = matrix_qc_snag_meta_keys();
    $out  = array(
        'id'          => $post->ID,
        'title'       => get_the_title($post),
        'description' => $post->post_content,
        'created'     => get_the_date('c', $post),
    );
    foreach ($keys as $short => $meta_key) {
        $out[$short] = get_post_meta($post->ID, $meta_key, true);
    }
    $out['screenshot_url'] = $out['screenshot_id']
        ? wp_get_attachment_url((int) $out['screenshot_id'])
        : '';
    return $out;
}
