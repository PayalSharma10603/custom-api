<?php
/**
 * Plugin Name: Custom API Plugin
 * Description: Custom REST API Endpoints
 * Version: 1.0
 * Author: Author
 */

// Register a custom REST API endpoint to fetch menu items and submenus
add_action('rest_api_init', function () {
    register_rest_route('custom-api/v1', '/menu/(?P<id>\d+)', [
        'methods'  => 'GET',
        'callback' => 'get_menu_with_submenus',
        'permission_callback' => '__return_true', // Adjust permissions as needed
    ]);
});

function get_menu_with_submenus($data) {
    $menu_id = $data['id']; // Get the menu ID from the request
    $menu_items = wp_get_nav_menu_items($menu_id); // Fetch menu items

    if (empty($menu_items)) {
        return new WP_Error('no_menu', 'Menu not found', ['status' => 404]);
    }

    // Organize menu items by parent ID
    $menu_by_parent = [];
    foreach ($menu_items as $item) {
        $menu_by_parent[$item->menu_item_parent][] = $item;
    }

    // Recursive function to build menu and submenu structure
    function build_menu_tree($parent_id, $menu_by_parent) {
        $menu_tree = [];

        if (isset($menu_by_parent[$parent_id])) {
            foreach ($menu_by_parent[$parent_id] as $item) {
                $menu_item = [
                    'id'   => $item->ID,
                    'name' => $item->title,
                    'slug' => sanitize_title($item->title),
                    'children' => build_menu_tree($item->ID, $menu_by_parent), // Recursively fetch submenus
                ];

                $menu_tree[] = $menu_item;
            }
        }

        return $menu_tree;
    }

    // Build the menu tree starting from the root (parent_id = 0)
    $menu_tree = build_menu_tree(0, $menu_by_parent);

    return rest_ensure_response($menu_tree);
}




// Register a custom REST API endpoint to search
function custom_search_endpoint() {
    register_rest_route('custom/v1', '/search', array(
        'methods' => 'GET',
        'callback' => 'custom_search_handler',
        'permission_callback' => '__return_true', 
        'args' => array(
            'query' => array(
                'required' => true,
                'validate_callback' => function($param, $request, $key) {
                    return is_string($param);
                },
                'sanitize_callback' => 'sanitize_text_field',
            ),
        ),
    ));
}
add_action('rest_api_init', 'custom_search_endpoint');
function custom_search_handler(WP_REST_Request $request) {
    $query = $request->get_param('query');

    add_filter('posts_search', function($search, $wp_query) use ($query) {
        global $wpdb;
        if (!empty($query) && $wp_query->is_search()) {
            $search = $wpdb->prepare(" AND {$wpdb->posts}.post_title LIKE %s ", '%' . $wpdb->esc_like($query) . '%');
        }
        return $search;
    }, 10, 2);

    $args = array(
        's' => $query, 
        'post_status' => 'publish', 
        'post_type' => 'post', 
        'posts_per_page' => 20, 
    );

    $query_results = new WP_Query($args);
    $posts = $query_results->posts;

    if (empty($posts)) {
        return new WP_REST_Response(array('message' => 'No results found'), 404);
    }

    $response_data = array_map(function($post) {
        $categories = get_the_category($post->ID);
        $category_names = wp_list_pluck($categories, 'name');
        
        // Fetching the post thumbnail URL
        $image_url = get_the_post_thumbnail_url($post->ID, 'full');
        $author_name = get_the_author_meta('display_name', $post->post_author);
        
        // Fetching custom fields
        $custom_fields = get_post_meta($post->ID);
        
        // Clean up custom fields by ensuring no duplicate values
        $formatted_custom_fields = array();
        foreach ($custom_fields as $key => $values) {
            // Remove duplicate values
            $formatted_custom_fields[$key] = array_values(array_unique($values));
        }

        return array(
            'id' => $post->ID,
            'title' => get_the_title($post),
            'plain_text'  => trim(str_replace("\n", ' ', wp_strip_all_tags($post->post_content))), // Plain text without newlines
            'content' => wp_kses_post($post->post_content), // Full post content
            'published_date' => $post->post_date, // Published date
            'categories' => $category_names,
            'url' => get_permalink($post),
            'image_url' => $image_url, // Added image URL to the response
            'author' => $author_name, // Added author name to the response
            'custom_fields' => $formatted_custom_fields, // Cleaned-up custom fields
        );
    }, $posts);

    return new WP_REST_Response($response_data, 200);
}



//Register a custom REST API endpoint to fetch posts by category title
add_action('rest_api_init', function () {
    register_rest_route('custom-api/v1', '/category-posts', [
        'methods'  => 'GET',
        'callback' => 'get_category_posts_by_title',
        'permission_callback' => '__return_true', 
    ]);
});

// Callback function for the REST API endpoint
function get_category_posts_by_title($data)
{
    $category_title = isset($data['category_title']) ? sanitize_text_field($data['category_title']) : '';

    // Validate category title
    if (empty($category_title)) {
        return new WP_Error('no_category_title', 'Category title is required', ['status' => 400]);
    }

    // Search for the category by title
    $categories = get_categories([
        'search' => $category_title,
        'number' => 1, 
    ]);

    if (empty($categories)) {
        return new WP_Error('category_not_found', 'Category not found', ['status' => 404]);
    }

    $category = $categories[0];

    // Pagination parameters
    $paged = isset($data['paged']) ? intval($data['paged']) : 1;
    $posts_per_page = 20;

    // Query posts in the category
    $args = [
        'cat'            => $category->term_id,
        'posts_per_page' => $posts_per_page,
        'paged'          => $paged,
    ];

    $query = new WP_Query($args);

    if ($query->have_posts()) {
        $posts_data = [];

        while ($query->have_posts()) {
            $query->the_post();

            // Get custom fields (meta data) for the post
            $custom_fields = get_post_meta(get_the_ID());

            // Prepare custom fields data
            $custom_field_data = [];
            foreach ($custom_fields as $key => $value) {
                $custom_field_data[$key] = maybe_unserialize($value[0]);  // Handle serialized values
            }

            // Add post data to response
            $posts_data[] = [
                'id'            => get_the_ID(),
                'title'         => get_the_title(),
                'content'       => get_the_content(),
                'plain_text'    => trim(str_replace("\n", ' ', wp_strip_all_tags(get_the_content()))), 
                'url'           => get_permalink(),
                'image_url'     => get_the_post_thumbnail_url(get_the_ID(), 'full'), 
                'published_date' => get_post_field('post_date', get_the_ID()), // Alternative method
                'author'        => get_userdata(get_post_field('post_author', get_the_ID()))->display_name, // Post author
                'custom_fields' => $custom_field_data, // Add custom fields to the response
            ];
        }

        $total_pages = $query->max_num_pages;

        return rest_ensure_response([
            'posts'       => $posts_data,
            'total_pages' => $total_pages,
            'current_page' => $paged,
        ]);
    }

    return new WP_Error('no_posts', 'No posts found in this category', ['status' => 404]);
}


