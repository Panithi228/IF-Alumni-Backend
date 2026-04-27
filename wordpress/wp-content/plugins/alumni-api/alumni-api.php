<?php
/*
    Plugin Name: Alumni API
    Description: A plugin to create API for Alumni website
    Version: 1.0
*/

add_action('rest_api_init', function () {
    register_rest_route('alumni-api/v1', '/submit', array(
        'methods' => 'POST',
        'callback' => 'handle_guest_alumni_submission',
        'permission_callback' => function () {
            return true; 
        },
    ));

    register_rest_route('alumni-api/v1', '/update/(?P<id>\d+)', array(
        'methods' => 'POST',
        'callback' => 'handle_guest_alumni_update',
        'permission_callback' => function () {
            return true; 
        },
    ));

    register_rest_route('alumni-api/v1', '/donation', array(
        'methods' => 'POST',
        'callback' => 'handle_donation_submit',
        'permission_callback' => function () {
            return true;
        },
    ));

    register_rest_route('alumni-api/v1', '/alumni-all', array(
        'methods' => 'GET',
        'callback' => 'handle_get_all_alumni',
        'permission_callback' => function () {
            return true;
        },
    ));
});

// --- ฟังก์ชันสำหรับสร้าง Post ใหม่ ---
function handle_guest_alumni_submission($request) {
    $params = $request->get_params();
    $acf_data = $request->get_param('acf');
    $files = $request->get_file_params();

    $status = 'pending';
    if (is_user_logged_in() && current_user_can('administrator')) {
        $status = 'publish';
    }

    $post_args = array(
        'post_title'   => sanitize_text_field($params['title']),
        'post_type'    => 'alumni', 
        'post_status'  => $status, 
    );

    $post_id = wp_insert_post($post_args);

    if (is_wp_error($post_id)) {
        return new WP_Error('save_error', 'Cannot save alumni', array('status' => 500));
    }

    // จัดการรูปภาพ
    if (!empty($files['featured_image'])) {
        require_once(ABSPATH . 'wp-admin/includes/image.php');
        require_once(ABSPATH . 'wp-admin/includes/file.php');
        require_once(ABSPATH . 'wp-admin/includes/media.php');
        $attachment_id = media_handle_upload('featured_image', $post_id);
        if (!is_wp_error($attachment_id)) {
            set_post_thumbnail($post_id, $attachment_id);
        }
    }

    // อัปเดต ACF
    if ($acf_data && function_exists('update_field')) {
        foreach ($acf_data as $key => $value) {
            update_field($key, $value, $post_id);
        }
    }

    return new WP_REST_Response(array(
        'success' => true,
        'message' => 'Submitted successfully. Waiting for approval.',
        'id' => $post_id
    ), 200);
}

// --- ฟังก์ชันสำหรับแก้ไข Post เดิม ---
function handle_guest_alumni_update($request) {
    $post_id = $request['id'];
    $params = $request->get_params();
    $files = $request->get_file_params();
    
    // ดึงข้อมูลเดิมมาเช็คความถูกต้อง
    if (function_exists('get_field')) {
        $stored_student_id = get_field('student_id', $post_id);
        $stored_email = get_field('email', $post_id);
    } else {
        $stored_student_id = get_post_meta($post_id, 'student_id', true);
        $stored_email = get_post_meta($post_id, 'email', true);
    }

    // ตรวจสอบว่า "รหัสนิสิต" และ "อีเมล" ตรงกับของเดิมในฐานข้อมูลไหม
    if ($params['check_student_id'] !== $stored_student_id || $params['check_email'] !== $stored_email) {
        return new WP_Error('auth_failed', 'รหัสนิสิตหรืออีเมลไม่ถูกต้อง ไม่สามารถแก้ไขข้อมูลได้', array('status' => 403));
    }

    // อัปเดตข้อมูลเบื้องต้น
    $update_args = array(
        'ID'           => $post_id,
        'post_title'   => sanitize_text_field($params['title']),
        'post_status'  => 'publish',
    );
    wp_update_post($update_args);

    // จัดการรูปภาพใหม่ (ถ้ามี)
    if (!empty($files['featured_image'])) {
        require_once(ABSPATH . 'wp-admin/includes/image.php');
        require_once(ABSPATH . 'wp-admin/includes/file.php');
        require_once(ABSPATH . 'wp-admin/includes/media.php');
        $attachment_id = media_handle_upload('featured_image', $post_id);
        if (!is_wp_error($attachment_id)) {
            set_post_thumbnail($post_id, $attachment_id);
        }
    }

    // อัปเดต ACF Fields
    if (isset($params['acf']) && is_array($params['acf'])) {
        foreach ($params['acf'] as $key => $value) {
            update_field($key, $value, $post_id);
        }
    }

    return array('success' => true, 'message' => 'Updated successfully.');
}

add_filter('rest_authentication_errors', function ($result) {
    if (!empty($result)) {
        return $result;
    }

    return null;
});

function generate_receipt_no($post_id) {
    $date = date('Ymd');

    $receipt_no = 'DON-' . $date . '-' . str_pad($post_id, 6, '0', STR_PAD_LEFT);

    return $receipt_no;
}

function handle_donation_submit($request) {
    $params = $request->get_params();
    $files = $request->get_file_params();

    $post_id = wp_insert_post([
        'post_type'   => 'donation',
        'post_title'  => sanitize_text_field($params['full_name']) . ' - ' . current_time('Y-m-d H:i:s'),
        'post_status' => 'publish',
    ]);

    if (is_wp_error($post_id)) {
        return new WP_Error('create_failed', 'Cannot create donation', ['status' => 500]);
    }

    $receipt_no = generate_receipt_no($post_id);

    // --- ACF SAVE (ต้องตรง field จริงใน WP) ---
    update_field('receipt_no', $receipt_no, $post_id);
    update_field('receipt', $params['receipt'] === '1', $post_id);
    update_field('donation_type', $params['donation_type'], $post_id);
    update_field('prefix', $params['prefix'], $post_id);
    update_field('full_name', $params['full_name'], $post_id);
    update_field('id', $params['tax_id'], $post_id);
    update_field('phone_number', $params['phone_number'], $post_id);
    update_field('postal_code', $params['postal_code'], $post_id);
    update_field('house_number', $params['house_number'], $post_id);
    update_field('address', $params['address'], $post_id);
    update_field('sub_district', $params['sub_district'], $post_id);
    update_field('district', $params['district'], $post_id);
    update_field('province', $params['province'], $post_id);
    update_field('donation_amount', $params['amount'], $post_id);
    update_field('donation_email', $params['email'], $post_id);
    update_field('additional_info', $params['additional_info'], $post_id);
    update_field('project_id', $params['project_id'], $post_id);
    update_field('payment_method', $params['payment_method'], $post_id);

    // -------------------------
    // FILE UPLOAD (หลักฐานโอน)
    // -------------------------
    $attachment_id = null;
    if (!empty($files['donation_receipt'])) {
        require_once(ABSPATH . 'wp-admin/includes/file.php');
        require_once(ABSPATH . 'wp-admin/includes/media.php');
        require_once(ABSPATH . 'wp-admin/includes/image.php');

        $attachment_id = media_handle_upload('donation_receipt', $post_id);

        if (!is_wp_error($attachment_id)) {
            update_field('donation_receipt', $attachment_id, $post_id);
        }
    }

    // -------------------------
    // EMAIL + ATTACH FILE
    // -------------------------
    $admin_email = get_option('admin_email');

    $subject = "มีการบริจาคใหม่ - $receipt_no";

    $message = "
    มีรายการบริจาคใหม่

    เลขที่ใบเสร็จ: $receipt_no
    ชื่อ: {$params['full_name']}
    จำนวนเงิน: {$params['amount']} บาท
    เบอร์โทร: {$params['phone_number']}
    อีเมล: {$params['email']}
    ";

    $headers = ['Content-Type: text/plain; charset=UTF-8'];

    $attachments = [];

    if ($attachment_id) {
        $file_path = get_attached_file($attachment_id);
        if ($file_path) {
            $attachments[] = $file_path;
        }
    }

    wp_mail($admin_email, $subject, $message, $headers, $attachments);

    return new WP_REST_Response([
        'success' => true,
        'receipt_no' => $receipt_no,
        'post_id' => $post_id
    ], 200);
}

function handle_get_all_alumni($request) {
    $page = $request->get_param('page') ?: 1;
    $per_page = $request->get_param('per_page') ?: 100;

    $args = array(
        'post_type'      => 'alumni',
        'post_status'    => array('publish', 'pending'),
        'posts_per_page' => $per_page,
        'paged'          => $page,
    );

    $query = new WP_Query($args);
    $posts = array();

    foreach ($query->posts as $post) {
        $acf = function_exists('get_fields') ? get_fields($post->ID) : [];
        $thumbnail_id = get_post_thumbnail_id($post->ID);
        $thumbnail_url = $thumbnail_id ? wp_get_attachment_image_url($thumbnail_id, 'full') : null;

        $posts[] = array(
            'id'     => $post->ID,
            'title'  => array('rendered' => $post->post_title),
            'status' => $post->post_status,
            'acf'    => $acf,
            '_embedded' => array(
                'wp:featuredmedia' => $thumbnail_url ? array(array('source_url' => $thumbnail_url)) : array()
            ),
        );
    }

    $response = new WP_REST_Response($posts, 200);
    $response->header('X-WP-TotalPages', $query->max_num_pages);

    return $response;
}

function cptui_register_my_cpts() {

	/**
	 * Post Type: alumnies.
	 */

	$labels = [
		"name" => esc_html__( "alumnies", "twentytwentyfive" ),
		"singular_name" => esc_html__( "alumni", "twentytwentyfive" ),
		"menu_name" => esc_html__( "My alumnies", "twentytwentyfive" ),
		"all_items" => esc_html__( "All alumnies", "twentytwentyfive" ),
		"add_new" => esc_html__( "Add new", "twentytwentyfive" ),
		"add_new_item" => esc_html__( "Add new alumni", "twentytwentyfive" ),
		"edit_item" => esc_html__( "Edit alumni", "twentytwentyfive" ),
		"new_item" => esc_html__( "New alumni", "twentytwentyfive" ),
		"view_item" => esc_html__( "View alumni", "twentytwentyfive" ),
		"view_items" => esc_html__( "View alumnies", "twentytwentyfive" ),
		"search_items" => esc_html__( "Search alumnies", "twentytwentyfive" ),
		"not_found" => esc_html__( "No alumnies found", "twentytwentyfive" ),
		"not_found_in_trash" => esc_html__( "No alumnies found in trash", "twentytwentyfive" ),
		"parent" => esc_html__( "Parent alumni:", "twentytwentyfive" ),
		"featured_image" => esc_html__( "Featured image for this alumni", "twentytwentyfive" ),
		"set_featured_image" => esc_html__( "Set featured image for this alumni", "twentytwentyfive" ),
		"remove_featured_image" => esc_html__( "Remove featured image for this alumni", "twentytwentyfive" ),
		"use_featured_image" => esc_html__( "Use as featured image for this alumni", "twentytwentyfive" ),
		"archives" => esc_html__( "alumni archives", "twentytwentyfive" ),
		"insert_into_item" => esc_html__( "Insert into alumni", "twentytwentyfive" ),
		"uploaded_to_this_item" => esc_html__( "Upload to this alumni", "twentytwentyfive" ),
		"filter_items_list" => esc_html__( "Filter alumnies list", "twentytwentyfive" ),
		"items_list_navigation" => esc_html__( "alumnies list navigation", "twentytwentyfive" ),
		"items_list" => esc_html__( "alumnies list", "twentytwentyfive" ),
		"attributes" => esc_html__( "alumnies attributes", "twentytwentyfive" ),
		"name_admin_bar" => esc_html__( "alumni", "twentytwentyfive" ),
		"item_published" => esc_html__( "alumni published", "twentytwentyfive" ),
		"item_published_privately" => esc_html__( "alumni published privately.", "twentytwentyfive" ),
		"item_reverted_to_draft" => esc_html__( "alumni reverted to draft.", "twentytwentyfive" ),
		"item_trashed" => esc_html__( "alumni trashed.", "twentytwentyfive" ),
		"item_scheduled" => esc_html__( "alumni scheduled", "twentytwentyfive" ),
		"item_updated" => esc_html__( "alumni updated.", "twentytwentyfive" ),
		"template_name" => esc_html__( "Single alumni: alumni", "twentytwentyfive" ),
		"parent_item_colon" => esc_html__( "Parent alumni:", "twentytwentyfive" ),
	];

	$args = [
		"label" => esc_html__( "alumnies", "twentytwentyfive" ),
		"labels" => $labels,
		"description" => "",
		"public" => true,
		"publicly_queryable" => true,
		"show_ui" => true,
		"show_in_rest" => true,
		"rest_base" => "",
		"rest_controller_class" => "WP_REST_Posts_Controller",
		"rest_namespace" => "wp/v2",
		"has_archive" => false,
		"show_in_menu" => true,
		"show_in_nav_menus" => true,
		"delete_with_user" => false,
		"exclude_from_search" => false,
		"capability_type" => "post",
		"map_meta_cap" => true,
		"hierarchical" => false,
		"can_export" => false,
		"rewrite" => [ "slug" => "alumni", "with_front" => true ],
		"query_var" => true,
		"supports" => [ "title", "editor", "thumbnail", "custom-fields" ],
		"show_in_graphql" => false,
	];

	register_post_type( "alumni", $args );

	/**
	 * Post Type: projects.
	 */

	$labels = [
		"name" => esc_html__( "projects", "twentytwentyfive" ),
		"singular_name" => esc_html__( "project", "twentytwentyfive" ),
		"menu_name" => esc_html__( "My projects", "twentytwentyfive" ),
		"all_items" => esc_html__( "All projects", "twentytwentyfive" ),
		"add_new" => esc_html__( "Add new", "twentytwentyfive" ),
		"add_new_item" => esc_html__( "Add new project", "twentytwentyfive" ),
		"edit_item" => esc_html__( "Edit project", "twentytwentyfive" ),
		"new_item" => esc_html__( "New project", "twentytwentyfive" ),
		"view_item" => esc_html__( "View project", "twentytwentyfive" ),
		"view_items" => esc_html__( "View projects", "twentytwentyfive" ),
		"search_items" => esc_html__( "Search projects", "twentytwentyfive" ),
		"not_found" => esc_html__( "No projects found", "twentytwentyfive" ),
		"not_found_in_trash" => esc_html__( "No projects found in trash", "twentytwentyfive" ),
		"parent" => esc_html__( "Parent project:", "twentytwentyfive" ),
		"featured_image" => esc_html__( "Featured image for this project", "twentytwentyfive" ),
		"set_featured_image" => esc_html__( "Set featured image for this project", "twentytwentyfive" ),
		"remove_featured_image" => esc_html__( "Remove featured image for this project", "twentytwentyfive" ),
		"use_featured_image" => esc_html__( "Use as featured image for this project", "twentytwentyfive" ),
		"archives" => esc_html__( "project archives", "twentytwentyfive" ),
		"insert_into_item" => esc_html__( "Insert into project", "twentytwentyfive" ),
		"uploaded_to_this_item" => esc_html__( "Upload to this project", "twentytwentyfive" ),
		"filter_items_list" => esc_html__( "Filter projects list", "twentytwentyfive" ),
		"items_list_navigation" => esc_html__( "projects list navigation", "twentytwentyfive" ),
		"items_list" => esc_html__( "projects list", "twentytwentyfive" ),
		"attributes" => esc_html__( "projects attributes", "twentytwentyfive" ),
		"name_admin_bar" => esc_html__( "project", "twentytwentyfive" ),
		"item_published" => esc_html__( "project published", "twentytwentyfive" ),
		"item_published_privately" => esc_html__( "project published privately.", "twentytwentyfive" ),
		"item_reverted_to_draft" => esc_html__( "project reverted to draft.", "twentytwentyfive" ),
		"item_trashed" => esc_html__( "project trashed.", "twentytwentyfive" ),
		"item_scheduled" => esc_html__( "project scheduled", "twentytwentyfive" ),
		"item_updated" => esc_html__( "project updated.", "twentytwentyfive" ),
		"template_name" => esc_html__( "Single project: project", "twentytwentyfive" ),
		"parent_item_colon" => esc_html__( "Parent project:", "twentytwentyfive" ),
	];

	$args = [
		"label" => esc_html__( "projects", "twentytwentyfive" ),
		"labels" => $labels,
		"description" => "",
		"public" => true,
		"publicly_queryable" => true,
		"show_ui" => true,
		"show_in_rest" => true,
		"rest_base" => "",
		"rest_controller_class" => "WP_REST_Posts_Controller",
		"rest_namespace" => "wp/v2",
		"has_archive" => false,
		"show_in_menu" => true,
		"show_in_nav_menus" => true,
		"delete_with_user" => false,
		"exclude_from_search" => false,
		"capability_type" => "post",
		"map_meta_cap" => true,
		"hierarchical" => false,
		"can_export" => false,
		"rewrite" => [ "slug" => "project", "with_front" => true ],
		"query_var" => true,
		"supports" => [ "title", "editor", "thumbnail", "custom-fields" ],
		"show_in_graphql" => false,
	];

	register_post_type( "project", $args );

	/**
	 * Post Type: donations.
	 */

	$labels = [
		"name" => esc_html__( "donations", "twentytwentyfive" ),
		"singular_name" => esc_html__( "donation", "twentytwentyfive" ),
	];

	$args = [
		"label" => esc_html__( "donations", "twentytwentyfive" ),
		"labels" => $labels,
		"description" => "",
		"public" => true,
		"publicly_queryable" => true,
		"show_ui" => true,
		"show_in_rest" => true,
		"rest_base" => "",
		"rest_controller_class" => "WP_REST_Posts_Controller",
		"rest_namespace" => "wp/v2",
		"has_archive" => false,
		"show_in_menu" => true,
		"show_in_nav_menus" => true,
		"delete_with_user" => false,
		"exclude_from_search" => false,
		"capability_type" => "post",
		"map_meta_cap" => true,
		"hierarchical" => false,
		"can_export" => false,
		"rewrite" => [ "slug" => "donation", "with_front" => true ],
		"query_var" => true,
		"supports" => [ "title", "editor", "thumbnail", "custom-fields" ],
		"show_in_graphql" => false,
	];

	register_post_type( "donation", $args );
}

add_action( 'init', 'cptui_register_my_cpts' );

add_action( 'acf/include_fields', function() {
	if ( ! function_exists( 'acf_add_local_field_group' ) ) {
		return;
	}

	acf_add_local_field_group( array(
	'key' => 'group_69e279260c597',
	'title' => 'Alumni_field',
	'fields' => array(
		array(
			'key' => 'field_69e2792d6583e',
			'label' => 'full_name',
			'name' => 'full_name',
			'aria-label' => '',
			'type' => 'text',
			'instructions' => '',
			'required' => 0,
			'conditional_logic' => 0,
			'wrapper' => array(
				'width' => '',
				'class' => '',
				'id' => '',
			),
			'default_value' => '',
			'maxlength' => '',
			'allow_in_bindings' => 0,
			'placeholder' => '',
			'prepend' => '',
			'append' => '',
			'show_in_graphql' => 1,
			'graphql_description' => '',
			'graphql_field_name' => 'fullName',
			'graphql_non_null' => 0,
		),
		array(
			'key' => 'field_69e2793d6583f',
			'label' => 'student_id',
			'name' => 'student_id',
			'aria-label' => '',
			'type' => 'text',
			'instructions' => '',
			'required' => 0,
			'conditional_logic' => 0,
			'wrapper' => array(
				'width' => '',
				'class' => '',
				'id' => '',
			),
			'default_value' => '',
			'maxlength' => '',
			'allow_in_bindings' => 0,
			'placeholder' => '',
			'prepend' => '',
			'append' => '',
			'show_in_graphql' => 1,
			'graphql_description' => '',
			'graphql_field_name' => 'studentId',
			'graphql_non_null' => 0,
		),
		array(
			'key' => 'field_69e2794765840',
			'label' => 'major',
			'name' => 'major',
			'aria-label' => '',
			'type' => 'text',
			'instructions' => '',
			'required' => 0,
			'conditional_logic' => 0,
			'wrapper' => array(
				'width' => '',
				'class' => '',
				'id' => '',
			),
			'default_value' => '',
			'maxlength' => '',
			'allow_in_bindings' => 0,
			'placeholder' => '',
			'prepend' => '',
			'append' => '',
			'show_in_graphql' => 1,
			'graphql_description' => '',
			'graphql_field_name' => 'major',
			'graphql_non_null' => 0,
		),
		array(
			'key' => 'field_69e2794f65841',
			'label' => 'graduation_year',
			'name' => 'graduation_year',
			'aria-label' => '',
			'type' => 'number',
			'instructions' => '',
			'required' => 0,
			'conditional_logic' => 0,
			'wrapper' => array(
				'width' => '',
				'class' => '',
				'id' => '',
			),
			'default_value' => '',
			'min' => '',
			'max' => '',
			'allow_in_bindings' => 0,
			'placeholder' => '',
			'step' => '',
			'prepend' => '',
			'append' => '',
			'show_in_graphql' => 1,
			'graphql_description' => '',
			'graphql_field_name' => 'graduationYear',
			'graphql_non_null' => 0,
		),
		array(
			'key' => 'field_69e2796865842',
			'label' => 'email',
			'name' => 'email',
			'aria-label' => '',
			'type' => 'email',
			'instructions' => '',
			'required' => 0,
			'conditional_logic' => 0,
			'wrapper' => array(
				'width' => '',
				'class' => '',
				'id' => '',
			),
			'default_value' => '',
			'allow_in_bindings' => 0,
			'placeholder' => '',
			'prepend' => '',
			'append' => '',
			'show_in_graphql' => 1,
			'graphql_description' => '',
			'graphql_field_name' => 'email',
			'graphql_non_null' => 0,
		),
		array(
			'key' => 'field_69e2797765843',
			'label' => 'job_position',
			'name' => 'job_position',
			'aria-label' => '',
			'type' => 'text',
			'instructions' => '',
			'required' => 0,
			'conditional_logic' => 0,
			'wrapper' => array(
				'width' => '',
				'class' => '',
				'id' => '',
			),
			'default_value' => '',
			'maxlength' => '',
			'allow_in_bindings' => 0,
			'placeholder' => '',
			'prepend' => '',
			'append' => '',
			'show_in_graphql' => 1,
			'graphql_description' => '',
			'graphql_field_name' => 'jobPosition',
			'graphql_non_null' => 0,
		),
		array(
			'key' => 'field_69e2798865844',
			'label' => 'workplace',
			'name' => 'workplace',
			'aria-label' => '',
			'type' => 'text',
			'instructions' => '',
			'required' => 0,
			'conditional_logic' => 0,
			'wrapper' => array(
				'width' => '',
				'class' => '',
				'id' => '',
			),
			'default_value' => '',
			'maxlength' => '',
			'allow_in_bindings' => 0,
			'placeholder' => '',
			'prepend' => '',
			'append' => '',
			'show_in_graphql' => 1,
			'graphql_description' => '',
			'graphql_field_name' => 'workplace',
			'graphql_non_null' => 0,
		),
		array(
			'key' => 'field_69e2799365845',
			'label' => 'additional_info',
			'name' => 'additional_info',
			'aria-label' => '',
			'type' => 'textarea',
			'instructions' => '',
			'required' => 0,
			'conditional_logic' => 0,
			'wrapper' => array(
				'width' => '',
				'class' => '',
				'id' => '',
			),
			'default_value' => '',
			'maxlength' => '',
			'allow_in_bindings' => 0,
			'rows' => '',
			'placeholder' => '',
			'new_lines' => '',
			'show_in_graphql' => 1,
			'graphql_description' => '',
			'graphql_field_name' => 'additionalInfo',
			'graphql_non_null' => 0,
		),
		array(
			'key' => 'field_69e279b265846',
			'label' => 'image_url',
			'name' => 'image_url',
			'aria-label' => '',
			'type' => 'image',
			'instructions' => '',
			'required' => 0,
			'conditional_logic' => 0,
			'wrapper' => array(
				'width' => '',
				'class' => '',
				'id' => '',
			),
			'return_format' => 'url',
			'library' => 'all',
			'min_width' => '',
			'min_height' => '',
			'min_size' => '',
			'max_width' => '',
			'max_height' => '',
			'max_size' => '',
			'mime_types' => '',
			'allow_in_bindings' => 0,
			'preview_size' => 'medium',
			'show_in_graphql' => 1,
			'graphql_description' => '',
			'graphql_field_name' => 'imageUrl',
		),
	),
	'location' => array(
		array(
			array(
				'param' => 'post_type',
				'operator' => '==',
				'value' => 'alumni',
			),
		),
	),
	'menu_order' => 0,
	'position' => 'normal',
	'style' => 'default',
	'label_placement' => 'top',
	'instruction_placement' => 'label',
	'hide_on_screen' => '',
	'active' => true,
	'description' => '',
	'show_in_rest' => 1,
	'display_title' => '',
	'allow_ai_access' => false,
	'ai_description' => '',
	'show_in_graphql' => 1,
	'graphql_field_name' => 'alumni_field',
	'map_graphql_types_from_location_rules' => 0,
	'graphql_types' => '',
) );

	acf_add_local_field_group( array(
	'key' => 'group_69e27a897898e',
	'title' => 'Donation_field',
	'fields' => array(
		array(
			'key' => 'field_69e27a8841a77',
			'label' => 'receipt',
			'name' => 'receipt',
			'aria-label' => '',
			'type' => 'true_false',
			'instructions' => '',
			'required' => 0,
			'conditional_logic' => 0,
			'wrapper' => array(
				'width' => '',
				'class' => '',
				'id' => '',
			),
			'message' => '',
			'default_value' => 0,
			'allow_in_bindings' => 0,
			'ui' => 0,
			'show_in_graphql' => 1,
			'graphql_description' => '',
			'graphql_field_name' => 'receipt',
			'graphql_non_null' => 0,
			'ui_on_text' => '',
			'ui_off_text' => '',
		),
		array(
			'key' => 'field_69e27ab841a78',
			'label' => 'donation_type',
			'name' => 'donation_type',
			'aria-label' => '',
			'type' => 'text',
			'instructions' => '',
			'required' => 0,
			'conditional_logic' => 0,
			'wrapper' => array(
				'width' => '',
				'class' => '',
				'id' => '',
			),
			'default_value' => '',
			'maxlength' => '',
			'allow_in_bindings' => 0,
			'placeholder' => '',
			'prepend' => '',
			'append' => '',
			'show_in_graphql' => 1,
			'graphql_description' => '',
			'graphql_field_name' => 'donationType',
			'graphql_non_null' => 0,
		),
		array(
			'key' => 'field_69e27ac241a79',
			'label' => 'prefix',
			'name' => 'prefix',
			'aria-label' => '',
			'type' => 'select',
			'instructions' => '',
			'required' => 0,
			'conditional_logic' => 0,
			'wrapper' => array(
				'width' => '',
				'class' => '',
				'id' => '',
			),
			'choices' => array(
				'นาย' => 'นาย',
				'นางสาว' => 'นางสาว',
				'คุณ' => 'คุณ',
			),
			'default_value' => false,
			'return_format' => 'value',
			'multiple' => 0,
			'allow_null' => 0,
			'allow_in_bindings' => 0,
			'ui' => 0,
			'show_in_graphql' => 1,
			'graphql_description' => '',
			'graphql_field_name' => 'prefix',
			'graphql_non_null' => 0,
			'ajax' => 0,
			'placeholder' => '',
			'create_options' => 0,
			'save_options' => 0,
		),
		array(
			'key' => 'field_69e27ae041a7a',
			'label' => 'full_name',
			'name' => 'full_name',
			'aria-label' => '',
			'type' => 'text',
			'instructions' => '',
			'required' => 0,
			'conditional_logic' => 0,
			'wrapper' => array(
				'width' => '',
				'class' => '',
				'id' => '',
			),
			'default_value' => '',
			'maxlength' => '',
			'allow_in_bindings' => 0,
			'placeholder' => '',
			'prepend' => '',
			'append' => '',
			'show_in_graphql' => 1,
			'graphql_description' => '',
			'graphql_field_name' => 'fullName',
			'graphql_non_null' => 0,
		),
		array(
			'key' => 'field_69e27ae841a7b',
			'label' => 'id',
			'name' => 'id',
			'aria-label' => '',
			'type' => 'text',
			'instructions' => '',
			'required' => 0,
			'conditional_logic' => 0,
			'wrapper' => array(
				'width' => '',
				'class' => '',
				'id' => '',
			),
			'default_value' => '',
			'maxlength' => '',
			'allow_in_bindings' => 0,
			'placeholder' => '',
			'prepend' => '',
			'append' => '',
			'show_in_graphql' => 1,
			'graphql_description' => '',
			'graphql_field_name' => 'id',
			'graphql_non_null' => 0,
		),
		array(
			'key' => 'field_69e27af241a7c',
			'label' => 'phone_number',
			'name' => 'phone_number',
			'aria-label' => '',
			'type' => 'text',
			'instructions' => '',
			'required' => 0,
			'conditional_logic' => 0,
			'wrapper' => array(
				'width' => '',
				'class' => '',
				'id' => '',
			),
			'default_value' => '',
			'maxlength' => '',
			'allow_in_bindings' => 0,
			'placeholder' => '',
			'prepend' => '',
			'append' => '',
			'show_in_graphql' => 1,
			'graphql_description' => '',
			'graphql_field_name' => 'phoneNumber',
			'graphql_non_null' => 0,
		),
		array(
			'key' => 'field_69e27afb41a7d',
			'label' => 'postal_code',
			'name' => 'postal_code',
			'aria-label' => '',
			'type' => 'number',
			'instructions' => '',
			'required' => 0,
			'conditional_logic' => 0,
			'wrapper' => array(
				'width' => '',
				'class' => '',
				'id' => '',
			),
			'default_value' => '',
			'min' => '',
			'max' => '',
			'allow_in_bindings' => 0,
			'placeholder' => '',
			'step' => '',
			'prepend' => '',
			'append' => '',
			'show_in_graphql' => 1,
			'graphql_description' => '',
			'graphql_field_name' => 'postalCode',
			'graphql_non_null' => 0,
		),
		array(
			'key' => 'field_69e27b1141a7e',
			'label' => 'house_number',
			'name' => 'house_number',
			'aria-label' => '',
			'type' => 'text',
			'instructions' => '',
			'required' => 0,
			'conditional_logic' => 0,
			'wrapper' => array(
				'width' => '',
				'class' => '',
				'id' => '',
			),
			'default_value' => '',
			'maxlength' => '',
			'allow_in_bindings' => 0,
			'placeholder' => '',
			'prepend' => '',
			'append' => '',
			'show_in_graphql' => 1,
			'graphql_description' => '',
			'graphql_field_name' => 'houseNumber',
			'graphql_non_null' => 0,
		),
		array(
			'key' => 'field_69e27b1441a7f',
			'label' => 'address',
			'name' => 'address',
			'aria-label' => '',
			'type' => 'textarea',
			'instructions' => '',
			'required' => 0,
			'conditional_logic' => 0,
			'wrapper' => array(
				'width' => '',
				'class' => '',
				'id' => '',
			),
			'default_value' => '',
			'maxlength' => '',
			'allow_in_bindings' => 0,
			'rows' => '',
			'placeholder' => '',
			'new_lines' => '',
			'show_in_graphql' => 1,
			'graphql_description' => '',
			'graphql_field_name' => 'address',
			'graphql_non_null' => 0,
		),
		array(
			'key' => 'field_69e27b1f41a80',
			'label' => 'sub_district',
			'name' => 'sub_district',
			'aria-label' => '',
			'type' => 'text',
			'instructions' => '',
			'required' => 0,
			'conditional_logic' => 0,
			'wrapper' => array(
				'width' => '',
				'class' => '',
				'id' => '',
			),
			'default_value' => '',
			'maxlength' => '',
			'allow_in_bindings' => 0,
			'placeholder' => '',
			'prepend' => '',
			'append' => '',
			'show_in_graphql' => 1,
			'graphql_description' => '',
			'graphql_field_name' => 'subDistrict',
			'graphql_non_null' => 0,
		),
		array(
			'key' => 'field_69e27b3041a81',
			'label' => 'district',
			'name' => 'district',
			'aria-label' => '',
			'type' => 'text',
			'instructions' => '',
			'required' => 0,
			'conditional_logic' => 0,
			'wrapper' => array(
				'width' => '',
				'class' => '',
				'id' => '',
			),
			'default_value' => '',
			'maxlength' => '',
			'allow_in_bindings' => 0,
			'placeholder' => '',
			'prepend' => '',
			'append' => '',
			'show_in_graphql' => 1,
			'graphql_description' => '',
			'graphql_field_name' => 'district',
			'graphql_non_null' => 0,
		),
		array(
			'key' => 'field_69e27b3341a82',
			'label' => 'province',
			'name' => 'province',
			'aria-label' => '',
			'type' => 'text',
			'instructions' => '',
			'required' => 0,
			'conditional_logic' => 0,
			'wrapper' => array(
				'width' => '',
				'class' => '',
				'id' => '',
			),
			'default_value' => '',
			'maxlength' => '',
			'allow_in_bindings' => 0,
			'placeholder' => '',
			'prepend' => '',
			'append' => '',
			'show_in_graphql' => 1,
			'graphql_description' => '',
			'graphql_field_name' => 'province',
			'graphql_non_null' => 0,
		),
		array(
			'key' => 'field_69e27b3e41a83',
			'label' => 'donation_amount',
			'name' => 'donation_amount',
			'aria-label' => '',
			'type' => 'number',
			'instructions' => '',
			'required' => 0,
			'conditional_logic' => 0,
			'wrapper' => array(
				'width' => '',
				'class' => '',
				'id' => '',
			),
			'default_value' => '',
			'min' => '',
			'max' => '',
			'allow_in_bindings' => 0,
			'placeholder' => '',
			'step' => '',
			'prepend' => '',
			'append' => '',
			'show_in_graphql' => 1,
			'graphql_description' => '',
			'graphql_field_name' => 'donationAmount',
			'graphql_non_null' => 0,
		),
		array(
			'key' => 'field_69e27b4a41a84',
			'label' => 'donation_email',
			'name' => 'donation_email',
			'aria-label' => '',
			'type' => 'email',
			'instructions' => '',
			'required' => 0,
			'conditional_logic' => 0,
			'wrapper' => array(
				'width' => '',
				'class' => '',
				'id' => '',
			),
			'default_value' => '',
			'allow_in_bindings' => 0,
			'placeholder' => '',
			'prepend' => '',
			'append' => '',
			'show_in_graphql' => 1,
			'graphql_description' => '',
			'graphql_field_name' => 'donationEmail',
			'graphql_non_null' => 0,
		),
		array(
			'key' => 'field_69e27b5741a85',
			'label' => 'additional_info',
			'name' => 'additional_info',
			'aria-label' => '',
			'type' => 'textarea',
			'instructions' => '',
			'required' => 0,
			'conditional_logic' => 0,
			'wrapper' => array(
				'width' => '',
				'class' => '',
				'id' => '',
			),
			'default_value' => '',
			'maxlength' => '',
			'allow_in_bindings' => 0,
			'rows' => '',
			'placeholder' => '',
			'new_lines' => '',
			'show_in_graphql' => 1,
			'graphql_description' => '',
			'graphql_field_name' => 'additionalInfo',
			'graphql_non_null' => 0,
		),
		array(
			'key' => 'field_69e27b6541a86',
			'label' => 'project_id',
			'name' => 'project_id',
			'aria-label' => '',
			'type' => 'text',
			'instructions' => '',
			'required' => 0,
			'conditional_logic' => 0,
			'wrapper' => array(
				'width' => '',
				'class' => '',
				'id' => '',
			),
			'default_value' => '',
			'maxlength' => '',
			'allow_in_bindings' => 0,
			'placeholder' => '',
			'prepend' => '',
			'append' => '',
			'show_in_graphql' => 1,
			'graphql_description' => '',
			'graphql_field_name' => 'projectId',
			'graphql_non_null' => 0,
		),
		array(
			'key' => 'field_69e27b6e41a87',
			'label' => 'donation_receipt',
			'name' => 'donation_receipt',
			'aria-label' => '',
			'type' => 'image',
			'instructions' => '',
			'required' => 0,
			'conditional_logic' => 0,
			'wrapper' => array(
				'width' => '',
				'class' => '',
				'id' => '',
			),
			'return_format' => 'url',
			'library' => 'all',
			'min_width' => '',
			'min_height' => '',
			'min_size' => '',
			'max_width' => '',
			'max_height' => '',
			'max_size' => '',
			'mime_types' => '',
			'allow_in_bindings' => 0,
			'preview_size' => 'medium',
			'show_in_graphql' => 1,
			'graphql_description' => '',
			'graphql_field_name' => 'donationReceipt',
		),
		array(
			'key' => 'field_69e27b7a41a88',
			'label' => 'payment_method',
			'name' => 'payment_method',
			'aria-label' => '',
			'type' => 'text',
			'instructions' => '',
			'required' => 0,
			'conditional_logic' => 0,
			'wrapper' => array(
				'width' => '',
				'class' => '',
				'id' => '',
			),
			'default_value' => '',
			'maxlength' => '',
			'allow_in_bindings' => 0,
			'placeholder' => '',
			'prepend' => '',
			'append' => '',
			'show_in_graphql' => 1,
			'graphql_description' => '',
			'graphql_field_name' => 'paymentMethod',
			'graphql_non_null' => 0,
		),
		array(
			'key' => 'field_69e27b8e41a89',
			'label' => 'receipt_no',
			'name' => 'receipt_no',
			'aria-label' => '',
			'type' => 'text',
			'instructions' => '',
			'required' => 0,
			'conditional_logic' => 0,
			'wrapper' => array(
				'width' => '',
				'class' => '',
				'id' => '',
			),
			'default_value' => '',
			'maxlength' => '',
			'allow_in_bindings' => 0,
			'placeholder' => '',
			'prepend' => '',
			'append' => '',
			'show_in_graphql' => 1,
			'graphql_description' => '',
			'graphql_field_name' => 'receiptNo',
			'graphql_non_null' => 0,
		),
	),
	'location' => array(
		array(
			array(
				'param' => 'post_type',
				'operator' => '==',
				'value' => 'donation',
			),
		),
	),
	'menu_order' => 0,
	'position' => 'normal',
	'style' => 'default',
	'label_placement' => 'top',
	'instruction_placement' => 'label',
	'hide_on_screen' => '',
	'active' => true,
	'description' => '',
	'show_in_rest' => 1,
	'display_title' => '',
	'allow_ai_access' => false,
	'ai_description' => '',
	'show_in_graphql' => 1,
	'graphql_field_name' => 'donation_field',
	'map_graphql_types_from_location_rules' => 0,
	'graphql_types' => '',
) );

	acf_add_local_field_group( array(
	'key' => 'group_69e279f2700a3',
	'title' => 'Project_field',
	'fields' => array(
		array(
			'key' => 'field_69e279f162555',
			'label' => 'project_name',
			'name' => 'project_name',
			'aria-label' => '',
			'type' => 'text',
			'instructions' => '',
			'required' => 0,
			'conditional_logic' => 0,
			'wrapper' => array(
				'width' => '',
				'class' => '',
				'id' => '',
			),
			'default_value' => '',
			'maxlength' => '',
			'allow_in_bindings' => 0,
			'placeholder' => '',
			'prepend' => '',
			'append' => '',
			'show_in_graphql' => 1,
			'graphql_description' => '',
			'graphql_field_name' => 'projectName',
			'graphql_non_null' => 0,
		),
		array(
			'key' => 'field_69e27a0b62556',
			'label' => 'project_info',
			'name' => 'project_info',
			'aria-label' => '',
			'type' => 'textarea',
			'instructions' => '',
			'required' => 0,
			'conditional_logic' => 0,
			'wrapper' => array(
				'width' => '',
				'class' => '',
				'id' => '',
			),
			'default_value' => '',
			'maxlength' => '',
			'allow_in_bindings' => 0,
			'rows' => '',
			'placeholder' => '',
			'new_lines' => '',
			'show_in_graphql' => 1,
			'graphql_description' => '',
			'graphql_field_name' => 'projectInfo',
			'graphql_non_null' => 0,
		),
		array(
			'key' => 'field_69e27a1662557',
			'label' => 'tax_deduction',
			'name' => 'tax_deduction',
			'aria-label' => '',
			'type' => 'number',
			'instructions' => '',
			'required' => 0,
			'conditional_logic' => 0,
			'wrapper' => array(
				'width' => '',
				'class' => '',
				'id' => '',
			),
			'default_value' => '',
			'min' => '',
			'max' => '',
			'allow_in_bindings' => 0,
			'placeholder' => '',
			'step' => '',
			'prepend' => '',
			'append' => '',
			'show_in_graphql' => 1,
			'graphql_description' => '',
			'graphql_field_name' => 'taxDeduction',
			'graphql_non_null' => 0,
		),
		array(
			'key' => 'field_69e27a2b62558',
			'label' => 'project_image',
			'name' => 'project_image',
			'aria-label' => '',
			'type' => 'image',
			'instructions' => '',
			'required' => 0,
			'conditional_logic' => 0,
			'wrapper' => array(
				'width' => '',
				'class' => '',
				'id' => '',
			),
			'return_format' => 'array',
			'library' => 'all',
			'min_width' => '',
			'min_height' => '',
			'min_size' => '',
			'max_width' => '',
			'max_height' => '',
			'max_size' => '',
			'mime_types' => '',
			'allow_in_bindings' => 0,
			'preview_size' => 'medium',
			'show_in_graphql' => 1,
			'graphql_description' => '',
			'graphql_field_name' => 'projectImage',
		),
	),
	'location' => array(
		array(
			array(
				'param' => 'post_type',
				'operator' => '==',
				'value' => 'project',
			),
		),
	),
	'menu_order' => 0,
	'position' => 'normal',
	'style' => 'default',
	'label_placement' => 'top',
	'instruction_placement' => 'label',
	'hide_on_screen' => '',
	'active' => true,
	'description' => '',
	'show_in_rest' => 1,
	'display_title' => '',
	'allow_ai_access' => false,
	'ai_description' => '',
	'show_in_graphql' => 1,
	'graphql_field_name' => 'project_field',
	'map_graphql_types_from_location_rules' => 0,
	'graphql_types' => '',
) );
} );