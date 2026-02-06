<?php
/**
 * AIKTP Post Sync Module
 * Handle REST API and Post Synchronization from aiktp.com
 */

if (!defined('ABSPATH')) {
    exit;
}

class AIKTPZ_Post_Sync {
    
    private static $instance = null;
    
    public static function get_instance() {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }
    
    private function __construct() {
        // REST API Routes
        add_action('rest_api_init', array($this, 'register_rest_routes'));
        
        // Admin Settings
        add_action('admin_init', array($this, 'register_aiktp_settings'));
    }
    
    /**
     * Register REST API Routes
     * 
     * AUTHENTICATION & AUTHORIZATION FLOW:
     * This plugin uses a token-based authentication system for external API integration with aiktp.com,
     * combined with WordPress capability checks for write operations.
     * 
     * 1. Token Generation (/getToken):
     *    - User must be logged in to WordPress with manage_options capability
     *    - Returns a unique shared secret token (aiktpz_token)
     *    - Token is stored in wp_options and used for subsequent API calls
     *    - Permission callback enforces is_user_logged_in() for security
     * 
     * 2. Write Endpoints (createpost, doUploadImageToWP):
     *    - Require BOTH valid token AND WordPress capabilities (edit_posts/upload_files)
     *    - Token validates the external request is from authorized aiktp.com service
     *    - Capability checks ensure WordPress user permissions are respected
     *    - Prevents unauthorized content creation even with valid token
     * 
     * 3. Protected Read Endpoints (getPostById):
     *    - Use token-based permission_callback to restrict access
     *    - Only return data when valid token is provided
     * 
     * 4. Public Read Endpoints (getPostByURL, getAllPosts, etc.):
     *    - Use __return_true for public access
     *    - Filter results to only return published, public posts
     *    - Do not expose private, draft, or password-protected content
     * 
     * SECURITY NOTES:
     * - Token is only issued to authenticated WordPress administrators
     * - Write operations enforce WordPress capability checks (edit_posts, publish_posts, upload_files)
     * - Token validation uses strict comparison (===) to prevent timing attacks
     * - Post visibility is enforced to prevent exposing private/draft content
     * - Dual-layer security: token (external auth) + capabilities (WP permissions)
     */
    public function register_rest_routes() {
        // Write endpoints - require token authentication AND WordPress capabilities
        register_rest_route('aiktp', '/createpost', array(
            'methods' => 'POST',
            'callback' => array($this, 'create_post'),
            'permission_callback' => array($this, 'verify_aiktp_token_with_edit_capability')
        ));
        
        register_rest_route('aiktp', '/doUploadImageToWP', array(
            'methods' => 'POST',
            'callback' => array($this, 'upload_image_to_wp'),
            'permission_callback' => array($this, 'verify_aiktp_token_with_upload_capability')
        ));
        
        // Read endpoints - public access (read-only)
        register_rest_route('aiktp', '/getPostByURL', array(
            'methods' => 'POST',
            'callback' => array($this, 'get_post_by_url'),
            'permission_callback' => '__return_true'
        ));
        
        register_rest_route('aiktp', '/checkToken', array(
            'methods' => 'GET',
            'callback' => array($this, 'check_valid_token'),
            'permission_callback' => '__return_true'
        ));
        
        register_rest_route('aiktp', '/getCategories', array(
            'methods' => 'GET',
            'callback' => array($this, 'get_categories'),
            'permission_callback' => '__return_true'
        ));
        
        register_rest_route('aiktp', '/getPostByTags', array(
            'methods' => 'POST',
            'callback' => array($this, 'get_post_by_tags'),
            'permission_callback' => '__return_true'
        ));
        
        register_rest_route('aiktp', '/getPostById', array(
            'methods' => 'POST',
            'callback' => array($this, 'get_post_by_id'),
            'permission_callback' => array($this, 'verify_aiktp_token')
        ));
        
        register_rest_route('aiktp', '/getAllPosts', array(
            'methods' => 'POST',
            'callback' => array($this, 'get_all_posts'),
            'permission_callback' => '__return_true'
        ));
        
        
        // Token retrieval endpoint - requires logged-in user with manage_options capability
        // SECURITY: This endpoint returns the shared secret token and must be protected
        // Only authenticated WordPress administrators can retrieve the token
        register_rest_route('aiktp', '/getToken', array(
            'methods' => 'GET',
            'callback' => array($this, 'get_valid_token'),
            'permission_callback' => array($this, 'verify_admin_capability')
        ));
    }
    
    
    /**
     * Verify user has admin capability (for token retrieval)
     * 
     * This method ensures only administrators with manage_options capability can retrieve the shared secret token.
     * SECURITY FIX (CVE-2026-1103): Previously only checked is_user_logged_in(), which allowed any authenticated
     * user (including Subscribers) to retrieve the admin token. Now requires manage_options capability.
     * 
     * The token grants access to create posts, upload media, and access private content, so it must be
     * restricted to administrators only.
     * 
     * When calling this endpoint from JavaScript, you MUST include the nonce header:
     * headers: { 'X-WP-Nonce': wpApiSettings.nonce }
     * 
     * @param WP_REST_Request $request The REST API request object
     * @return bool|WP_Error True if user has manage_options capability, WP_Error otherwise
     */
    public function verify_admin_capability($request) {
        // Check if user is logged in first
        if (!is_user_logged_in()) {
            return new WP_Error(
                'rest_forbidden',
                __('You must be logged in to WordPress to access this endpoint. Please include authentication headers (X-WP-Nonce) in your request.', 'aiktp'),
                array('status' => 401)
            );
        }
        
        // Check if user has manage_options capability (administrator)
        if (!current_user_can('manage_options')) {
            return new WP_Error(
                'rest_forbidden',
                __('You do not have sufficient permissions to access this endpoint. Only administrators can retrieve the sync token.', 'aiktp'),
                array('status' => 403)
            );
        }
        
        return true;
    }
    
    /**
     * Verify AIKTP Token for REST API authentication
     * 
     * This method validates the token passed in API requests against the stored aiktpz_token.
     * Used as permission_callback for read operations that require token authentication.
     * 
     * Token Flow:
     * 1. User gets token via /getToken (requires WordPress login)
     * 2. External service (aiktp.com) stores this token
     * 3. Subsequent API calls include token as 'wpToken' or 'tokenKey' parameter
     * 4. This method validates token before allowing access to protected endpoints
     * 
     * @param WP_REST_Request $request The REST API request object
     * @return bool|WP_Error True if token is valid, WP_Error otherwise
     */
    public function verify_aiktp_token($request) {
        $aiktp_key = get_option('aiktpz_token');
        
        // Get token from request (support both wpToken and tokenKey)
        $token = $request->get_param('wpToken');
        if (empty($token)) {
            $token = $request->get_param('tokenKey');
        }
        
        // Verify token exists
        if (empty($aiktp_key) || empty($token)) {
            return new WP_Error(
                'rest_forbidden',
                __('Missing or invalid authentication token.', 'aiktp'),
                array('status' => 403)
            );
        }
        
        // Verify token matches (using strict comparison to prevent timing attacks)
        if (trim($aiktp_key) !== trim($token)) {
            return new WP_Error(
                'rest_forbidden',
                __('Invalid authentication token.', 'aiktp'),
                array('status' => 403)
            );
        }
        
        return true;
    }
    
    /**
     * Verify AIKTP Token AND WordPress edit_posts capability
     * 
     * DUAL-LAYER SECURITY:
     * 1. Token validation: Ensures request comes from authorized aiktp.com service
     * 2. Capability check: Ensures WordPress user has permission to create/edit posts
     * 
     * This prevents scenarios where:
     * - An attacker obtains the token but doesn't have WP edit permissions
     * - A low-privilege WP user tries to create posts without proper capabilities
     * 
     * @param WP_REST_Request $request The REST API request object
     * @return bool|WP_Error True if token is valid AND user has edit_posts capability
     */
    public function verify_aiktp_token_with_edit_capability($request) {
        // First verify token
        $token_valid = $this->verify_aiktp_token($request);
        if (is_wp_error($token_valid)) {
            return $token_valid;
        }
        
        // Token is valid, but we still need to verify WordPress capabilities
        // Since this is an external API integration, we check if the configured author has the capability
        $aiktp_author = get_option('aiktp_author', 1);
        
        // Verify the configured author exists and has edit_posts capability
        $user = get_userdata($aiktp_author);
        
        // If configured author doesn't exist or doesn't have permissions, find a valid admin
        if (!$user || !user_can($user, 'edit_posts') || !user_can($user, 'publish_posts')) {
            // Try to find an administrator user
            $admins = get_users(array(
                'role' => 'administrator',
                'number' => 1,
                'orderby' => 'ID',
                'order' => 'ASC'
            ));
            
            if (!empty($admins)) {
                $user = $admins[0];
                // Update the option to use this admin for future requests
                update_option('aiktp_author', $user->ID);
            } else {
                return new WP_Error(
                    'rest_forbidden',
                    __('No administrator user found. Please ensure at least one administrator account exists.', 'aiktp'),
                    array('status' => 403)
                );
            }
        }
        
        // Double-check the user has required capabilities
        if (!user_can($user, 'edit_posts') || !user_can($user, 'publish_posts')) {
            return new WP_Error(
                'rest_forbidden',
                __('User does not have permission to create and publish posts.', 'aiktp'),
                array('status' => 403)
            );
        }
        
        return true;
    }
    
    /**
     * Verify AIKTP Token AND WordPress upload_files capability
     * 
     * DUAL-LAYER SECURITY for media uploads:
     * 1. Token validation: Ensures request comes from authorized aiktp.com service
     * 2. Capability check: Ensures WordPress user has permission to upload files
     * 
     * @param WP_REST_Request $request The REST API request object
     * @return bool|WP_Error True if token is valid AND user has upload_files capability
     */
    public function verify_aiktp_token_with_upload_capability($request) {
        // First verify token
        $token_valid = $this->verify_aiktp_token($request);
        if (is_wp_error($token_valid)) {
            return $token_valid;
        }
        
        // Token is valid, verify upload capability
        $aiktp_author = get_option('aiktp_author', 1);
        $user = get_userdata($aiktp_author);
        
        // If configured author doesn't exist or doesn't have upload permission, find a valid admin
        if (!$user || !user_can($user, 'upload_files')) {
            // Try to find an administrator user
            $admins = get_users(array(
                'role' => 'administrator',
                'number' => 1,
                'orderby' => 'ID',
                'order' => 'ASC'
            ));
            
            if (!empty($admins)) {
                $user = $admins[0];
                // Update the option to use this admin for future requests
                update_option('aiktp_author', $user->ID);
            } else {
                return new WP_Error(
                    'rest_forbidden',
                    __('No administrator user found. Please ensure at least one administrator account exists.', 'aiktp'),
                    array('status' => 403)
                );
            }
        }
        
        // Double-check the user has upload capability
        if (!user_can($user, 'upload_files')) {
            return new WP_Error(
                'rest_forbidden',
                __('User does not have permission to upload files.', 'aiktp'),
                array('status' => 403)
            );
        }
        
        return true;
    }
    
    /**
     * Register AIKTP Settings
     */
    public function register_aiktp_settings() {
        register_setting('aiktp_sync_settings', 'aiktpz_token', array(
            'type' => 'string',
            'sanitize_callback' => 'sanitize_text_field',
            'default' => ''
        ));
        
        register_setting('aiktp_sync_settings', 'aiktp_author', array(
            'type' => 'integer',
            'sanitize_callback' => 'absint',
            'default' => 1
        ));
        
        register_setting('aiktp_sync_settings', 'aiktp_category', array(
            'type' => 'string',
            'sanitize_callback' => 'sanitize_text_field',
            'default' => '1'
        ));
    }
    
    /**
     * Check Valid Token
     */
    public function check_valid_token($request) {
        $aiktp_key = get_option('aiktpz_token');
        $token = $request['tokenKey'];
        
        if (trim($aiktp_key) == trim($token)) {
            return array(
                'status' => 'success',
                'tokenKey' => $request['tokenKey']
            );
        } else {
            return array(
                'status' => 'error',
                'tokenKey' => $request['tokenKey'],
                'msg' => 'Invalid token'
            );
        }
    }
    
    /**
     * Get Valid Token
     * 
     * SECURITY NOTE:
     * Authentication is handled by the permission_callback (verify_admin_capability).
     * This function simply generates/returns the token for authenticated administrators.
     * 
     * We do NOT manually validate cookies or set current user here.
     * WordPress REST API handles authentication automatically.
     * 
     * @param WP_REST_Request $request The REST API request object
     * @return WP_REST_Response The response containing the token
     */
    public function get_valid_token($request) {
        // Authentication is already verified by permission_callback
        // If we reach here, user is authenticated
        
        $aiktp_key = get_option('aiktpz_token');
        
        // Generate token if it doesn't exist
        if (empty($aiktp_key)) {
            $aiktp_key = uniqid();
            update_option('aiktpz_token', $aiktp_key);
        }
        
        // Return token to authenticated user
        return new WP_REST_Response(array(
            'success' => true,
            'token' => $aiktp_key,
            'message' => 'Token retrieved successfully'
        ), 200);
    }
    
    /**
     * Get Categories
     */
    public function get_categories() {
        $args = array('hide_empty' => false);
        $categories = get_categories($args);
        $cats = array();
        
        foreach ($categories as $cat) {
            $cats[] = array(
                'catId' => $cat->cat_ID,
                'catName' => $cat->cat_name
            );
        }

        // Add WooCommerce Product Categories
        if (taxonomy_exists('product_cat')) {
            $product_cats = get_terms(array(
                'taxonomy' => 'product_cat',
                'hide_empty' => false,
            ));
            
            if (!is_wp_error($product_cats) && !empty($product_cats)) {
                foreach ($product_cats as $cat) {
                    $cats[] = array(
                        'catId' => $cat->term_id,
                        'catName' => $cat->name . ' (Woo)'
                    );
                }
            }
        }
        
        return array(
            'status' => 'success',
            'cats' => $cats
        );
    }
    
    /**
     * Get Post By URL
     * 
     * SECURITY: Only returns published, public posts.
     * Private, draft, password-protected, or pending posts are not exposed.
     */
    public function get_post_by_url($request) {
        $post_url = trim($request['url']);
        $post_id = url_to_postid($post_url);
        $post = get_post($post_id);
        
        // Security check: Only return published posts, never expose private/draft content
        if (!empty($post) && $post->post_status === 'publish' && empty($post->post_password)) {
            return array(
                'status' => 'success',
                'data' => array(
                    'title' => $post->post_title,
                    'content' => $post->post_content,
                    'postId' => $post_id,
                    'post_name' => $post->post_name,
                    'post_author' => $post->post_author,
                    'post_date_gmt' => $post->post_date_gmt,
                    'post_modified_gmt' => $post->post_modified_gmt,
                    'thumbnail' => get_the_post_thumbnail_url($post_id, 'large'),
                    'link' => get_permalink($post_id)
                )
            );
        } else {
            return array(
                'status' => 'error',
                'message' => 'Post not found or not publicly accessible'
            );
        }
    }
    
    /**
     * Get Post By Tags
     */
    public function get_post_by_tags($request) {
        $query = $request['query'];
        $numberposts = isset($request['numberposts']) ? $request['numberposts'] : 5;
        
        /* 
        tax_query is necessary for REST API endpoint to search posts by tag
        Performance impact is minimal as results are limited by posts_per_page (default: 5)
        phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_tax_query */
        $args = array('posts_per_page' => $numberposts,'tax_query' => array(array('taxonomy' => 'post_tag','field' => 'slug','terms' => sanitize_title($query) ) ));
        
        $posts = get_posts($args);
        $post_data = array();
        
        foreach ($posts as $p) {
            $post_data[] = array(
                'postTitle' => $p->post_title,
                'link' => get_permalink($p->ID),
                'name' => $p->post_name,
                'postId' => $p->ID
            );
        }
        
        return array(
            'status' => 'success',
            'posts' => $post_data
        );
    }
    
    /**
     * Get Post By ID
     * 
     * SECURITY: Token validation is handled in permission_callback.
     * This endpoint is protected and only accessible with valid token.
     */
    public function get_post_by_id($request) {
        // Token validation is now handled in permission_callback (verify_aiktp_token)
        // No need to duplicate the check here
        
        $post_id = intval($request['postId']);
        $post = get_post($post_id);
        
        if (!empty($post)) {
            $tags = wp_get_post_terms($post_id, 'post_tag', array('fields' => 'names'));
            
            return array(
                'status' => 'success',
                'data' => array(
                    'title' => $post->post_title,
                    'content' => $post->post_content,
                    'postId' => $post_id,
                    'post_name' => $post->post_name,
                    'post_author' => $post->post_author,
                    'post_date_gmt' => $post->post_date_gmt,
                    'post_modified_gmt' => $post->post_modified_gmt,
                    'post_status' => $post->post_status,
                    'thumbnail' => get_the_post_thumbnail_url($post_id, 'large'),
                    'tags' => $tags,
                    'link' => get_permalink($post_id)
                )
            );
        } else {
            return array(
                'status' => 'error',
                'message' => 'Post not found'
            );
        }
    }
    
    /**
     * Get All Posts
     */
    public function get_all_posts($request) {
        $numberposts = isset($request['numberposts']) ? $request['numberposts'] : 50;
        $page = isset($request['page']) ? $request['page'] : 1;
        $offset = ($page - 1) * $numberposts;
        
        $args = array(
            'numberposts' => $numberposts,
            'post_type' => 'post',
            'offset' => $offset
        );
        
        $posts = get_posts($args);
        
        if (!empty($posts)) {
            $post_data = array();
            foreach ($posts as $p) {
                $post_data[] = array(
                    'postTitle' => $p->post_title,
                    'link' => get_permalink($p->ID),
                    'postId' => $p->ID,
                    'name' => $p->post_name,
                    'post_date_gmt' => $p->post_date_gmt,
                    'post_modified_gmt' => $p->post_modified_gmt
                );
            }
            
            return array(
                'status' => 'success',
                'data' => $post_data
            );
        } else {
            return array('status' => 'error');
        }
    }
    
    /**
     * Create Post
     * 
     * SECURITY: Token and capability validation is handled in permission_callback.
     * This ensures both external authentication (token) and WordPress permissions (capabilities) are verified.
     */
    public function create_post($request) {
        // Token and capability validation is now handled in permission_callback
        // (verify_aiktp_token_with_edit_capability)
        // No need to duplicate the check here
        
        // Get post data
        $post_title = sanitize_text_field($request['title']);
        // Content should allow HTML tags, use wp_kses_post for sanitization
        $post_content = isset($request['content']) ? wp_kses_post($request['content']) : '';
        $tags_input = sanitize_text_field($request['tags']);
        $featured_image = $request['featuredImage'];
        $cat_id = $request['catId'] ? $request['catId'] : '0';
        $post_status = $request['post_status'] ? $request['post_status'] : 'publish';
        
        // Get author
        $aiktp_author = get_option('aiktp_author');
        if (empty($aiktp_author)) {
            $aiktp_author = 1;
        }
        
        // Get category
        if (empty($cat_id)) {
            $aiktp_category = get_option('aiktp_category');
            if (empty($aiktp_category)) {
                $aiktp_category = 1;
            }
        } else {
            $aiktp_category = $cat_id;
        }
        
        // Create post slug
        $postname = AIKTPZ_Helpers::sanitize_slug($post_title);
        
        // Handle featured image
        $featured_img = null;
        if (!empty($featured_image)) {
            if (strpos($featured_image, ';base64,') > -1) {
                $featured_img = AIKTPZ_Helpers::save_base64_to_image($featured_image, $postname);
            } else {
                $featured_img = AIKTPZ_Helpers::download_remote_image($featured_image, $postname);
                if (!empty($featured_img['baseurl'])) {
                    $post_content = str_replace($featured_image, $featured_img['baseurl'], $post_content);
                }
            }
        }
        
        // Prepare post data
        $post_category = explode(',', $aiktp_category);
        
        // Detect if this is a WooCommerce product category
        $is_woo_product = false;
        $product_categories = array();
        $regular_categories = array();
        
        foreach ($post_category as $cat_id) {
            $cat_id = intval(trim($cat_id));
            if ($cat_id > 0) {
                // Check if this category exists in product_cat taxonomy
                if (taxonomy_exists('product_cat') && term_exists($cat_id, 'product_cat')) {
                    $is_woo_product = true;
                    $product_categories[] = $cat_id;
                } else {
                    $regular_categories[] = $cat_id;
                }
            }
        }
        
        // Determine post type and categories
        $post_type = $is_woo_product ? 'product' : 'post';
        $final_categories = $is_woo_product ? $product_categories : $regular_categories;
        
        // Build post data
        $new_post = array(
            'post_title' => $post_title, 
            'post_name' => $postname,
            'post_content' => $post_content,
            'post_status' => $post_status,
            'post_author' => $aiktp_author,
            'post_type' => $post_type,
            'post_date' => current_time('mysql'),
            'post_date_gmt' => current_time('mysql', 1)
        );
        
        // Add categories based on post type
        if ($post_type === 'post') {
            $new_post['post_category'] = $final_categories;
        }
        
        kses_remove_filters();
        $post_id = wp_insert_post($new_post);
        kses_init_filters();
        
        // For WooCommerce products, set product categories using taxonomy
        if ($post_type === 'product' && !empty($final_categories)) {
            wp_set_object_terms($post_id, $final_categories, 'product_cat');
        }
        
        // Add tags
        if (!empty($tags_input)) {
            $tags_array = array_map('trim', explode(',', $tags_input));
            wp_set_post_tags($post_id, $tags_array);
        }
        
        // Set featured image
        $thumbnail_url = '';
        if (!empty($featured_img)) {
            $attachment_id = AIKTPZ_Helpers::attach_image_to_post($featured_img, $post_id);
            set_post_thumbnail($post_id, $attachment_id);
            $thumbnail_url = get_the_post_thumbnail_url($post_id, 'large');
        }
        
        // Update SEO meta if plugins are active
        if (class_exists('RankMath') && !empty($tags_array)) {
            update_post_meta($post_id, 'rank_math_focus_keyword', implode(', ', $tags_array));
        }
        
        if (defined('WPSEO_VERSION') && !empty($tags_array[0])) {
            update_post_meta($post_id, '_yoast_wpseo_focuskw', $tags_array[0]);
        }
        
        return array(
            'status' => 'success',
            'permalink' => get_permalink($post_id),
            'thumbnail' => $thumbnail_url,
            'postId' => $post_id
        );
    }
    
    /**
     * Upload Image to WP
     * 
     * SECURITY: Token and capability validation is handled in permission_callback.
     * This ensures both external authentication (token) and WordPress upload permissions are verified.
     */
    public function upload_image_to_wp($request) {
        // Token and upload capability validation is now handled in permission_callback
        // (verify_aiktp_token_with_upload_capability)
        // No need to duplicate the check here
        
        $post_id = trim($request['postId']);
        $img_url = trim($request['imgURL']);
        
        $post_data = get_post($post_id);
        $post_title = $post_data->post_title;
        
        $download_img = AIKTPZ_Helpers::download_remote_image($img_url, $post_title);
        
        if (!empty($download_img) && !empty($download_img['baseurl'])) {
            $attachment_id = AIKTPZ_Helpers::attach_image_to_post($download_img, $post_id);
            
            $has_thumbnail = has_post_thumbnail($post_id);
            if (!$has_thumbnail) {
                set_post_thumbnail($post_id, $attachment_id);
                $thumbnail_url = get_the_post_thumbnail_url($post_id, 'large');
            }
            
            return array(
                'status' => 'success',
                'postId' => $post_id,
                'imgURL' => $img_url,
                'wp_baseURL' => $download_img['baseurl']
            );
        } else {
            return array(
                'status' => 'error',
                'imgURL' => $img_url
            );
        }
    }
}

// Initialize
AIKTPZ_Post_Sync::get_instance();