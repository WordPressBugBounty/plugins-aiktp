<?php
/**
 * Plugin Name: AIKTP
 * Plugin URI: https://aiktp.com/wordpress
 * Description: AIKTP - AI powered WordPress content automation. Create SEO optimized articles, bulk generate WooCommerce product descriptions, and sync posts directly from aiktp.com.
 * Version: 5.0.5
 * Author: John Luke - aiktp.com
 * License: GPL v2 or later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: aiktp
 * Domain Path: /languages
 * Requires at least: 5.8
 * Requires PHP: 7.0
 * WC requires at least: 5.0
 * WC tested up to: 9.0
 */


if (!defined('ABSPATH')) {
    exit;
}

// Define constants
define('AIKTPZ_VERSION', '5.0.5');
define('AIKTPZ_PLUGIN_DIR', plugin_dir_path(__FILE__));
define('AIKTPZ_PLUGIN_URL', plugin_dir_url(__FILE__));
// Include modules
require_once AIKTPZ_PLUGIN_DIR . 'includes/aiktp-helpers.php';
require_once AIKTPZ_PLUGIN_DIR . 'includes/aiktp-settings.php';
require_once AIKTPZ_PLUGIN_DIR . 'includes/aiktp-sync.php';

class AIKTPZ_AI_Content_Generator {
    
    private static $instance = null;
    private $api_key = '';
    
    public static function get_instance() {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }
    
    private function __construct() {
        // Hooks - WooCommerce AI Content
        add_action('admin_menu', array($this, 'add_admin_menu'));
        add_action('admin_enqueue_scripts', array($this, 'enqueue_admin_scripts'));
        add_action('add_meta_boxes', array($this, 'add_meta_box'));
        add_action('wp_ajax_aiktpz_generate_content', array($this, 'ajax_generate_content'));
        add_action('wp_ajax_aiktpz_bulk_generate', array($this, 'ajax_bulk_generate'));
        add_action('wp_ajax_aiktpz_get_bulk_products', array($this, 'ajax_get_bulk_products'));
        add_action('wp_ajax_aiktp_regenerate_token', array($this, 'ajax_regenerate_token'));
        add_action('admin_init', array('AIKTPZ_Settings', 'register_all_settings'));
        
        // Custom bulk actions
        add_filter('bulk_actions-edit-product', array($this, 'add_bulk_actions'));
        add_filter('handle_bulk_actions-edit-product', array($this, 'handle_bulk_actions'), 10, 3);
        add_action('restrict_manage_posts', array($this, 'add_custom_bulk_actions_ui'));
        add_action('admin_notices', array($this, 'bulk_action_admin_notice'));
        
        // AIKTP Post Sync - REST API routes are registered in AIKTPZ_Post_Sync class
        add_filter('rest_authentication_errors', function($result) { return true; }, 9999);
        
        // Load API key
        $this->api_key = get_option('aiktp_api_key', '');
    }
    
    /**
     * Add menu to admin
     */
    public function add_admin_menu() {
        // Add main AIKTP menu
        add_menu_page(
            'AIKTP',
            'AIKTP',
            'manage_options',
            'aiktp-settings',
            array($this, 'aiktp_settings_page'),
            AIKTPZ_PLUGIN_URL . 'assets/img/logo.aiktp.svg?ver=1.0.1',
            30
        );
        
        // Add Token page (hidden from menu, accessible via URL only)
        add_submenu_page(
            null, // Parent slug = null means hidden from sidebar
            __('Sync Token', 'aiktp'),
            __('Sync Token', 'aiktp'),
            'manage_options',
            'aiktp-token',
            array($this, 'aiktp_token_page')
        );
    }
    
    
    /**
     * AIKTP Main Settings Page
     */
    public function aiktp_settings_page() {
        AIKTPZ_Settings::render_settings_page();
    }
    
    /**
     * AIKTP Token Page
     */
    public function aiktp_token_page() {
        // Get or generate token
        $token = get_option('aiktpz_token');
        if (empty($token)) {
            $token = uniqid();
            update_option('aiktpz_token', $token);
        }
        ?>
        <div class="wrap">
            <div class="card" style="max-width: 700px; margin: 20px auto; border:1px #f4f4f4 solid; border-radius:10px; padding:30px;">
                <h2 style="display: flex; align-items: center;"><img src="<?php echo esc_url(AIKTPZ_PLUGIN_URL . 'assets/img/logo.aiktp.svg?ver=1.0.1'); ?>" alt="AIKTP" style="width: 20px; height: 20px; margin-right: 5px;"> <?php echo esc_html__('Your Sync Token', 'aiktp'); ?></h2>
                <p><?php echo esc_html__('Use this token to authenticate sync requests from aiktp.com to your WordPress site.', 'aiktp'); ?></p>
                
                <div style="background: #f0f0f1; padding: 15px; border-radius: 4px; margin: 20px 0; position: relative;">
                    <code id="aiktp-token-value" style="font-size: 14px; word-break: break-all; user-select: all; display: block; text-align: center;">
                        <?php echo esc_html($token); ?>
                    </code>
                </div>
                <div style="display: flex; justify-content: center;">
                    <button type="button" id="aiktp-copy-token" class="button button-primary button-large" style="gap:10px; display: flex; align-items: center;">
                        <span class="dashicons dashicons-clipboard" style="font-size:12px; width:12px; height:12px"></span>
                        <span><?php echo esc_html__('Copy Token', 'aiktp'); ?></span>
                    </button>
                    
                </div>
                
                <div id="aiktp-token-message" style="margin-top: 15px; display: none;"></div>
            </div>
            
            
        </div>
        <?php
    }
    
    
    /**
     * Add meta box to product edit page
     */
    public function add_meta_box() {
        // Only add meta box if WooCommerce is ready
        if (!class_exists('WooCommerce')) {
            return;
        }
        
        add_meta_box(
            'aiktpz_generator',
            __('AI Content Generator', 'aiktp'),
            array($this, 'render_meta_box'),
            'product',
            'side',
            'high'
        );
    }
    
    /**
     * Render meta box
     */
    public function render_meta_box($post) {
        wp_nonce_field('aiktpz_meta_box', 'aiktpz_meta_box_nonce');
        
        if (empty($this->api_key)) {
            echo '<p style="color: red;">' . esc_html__('Please configure API key first!', 'aiktp') . '</p>';
            echo '<a href="' . esc_url(admin_url('admin.php?page=aiktp-ai-content')) . '">' . esc_html__('Go to Settings', 'aiktp') . '</a>';
            return;
        }
        
        ?>
        <div class="wcai-generator-controls">
            <p>
                <button type="button" class="button button-aiktp-primary wcai-generate-btn" data-type="description">
                <span class="icon-text">✨</span> <?php esc_html_e('Generate Product Description', 'aiktp'); ?>
                </button>
            </p>
            
            <p>
                <button type="button" class="button button-aiktp-primary wcai-generate-btn" data-type="short_description">
                    <span class="icon-text">🪄</span>
                    <?php esc_html_e('Generate Short Description', 'aiktp'); ?>
                </button>
            </p>
            
            <div class="wcai-status" style="margin-top: 15px; display: none;">
                <p class="wcai-message"></p>
            </div>
        </div>
        
        <?php
    }
    
    /**
     * Enqueue scripts
     */
    public function enqueue_admin_scripts($hook) {
        global $post_type;
        
        // For AIKTP Token page
        if ($hook === 'admin_page_aiktp-token') {
            wp_enqueue_script(
                'aiktp-token',
                AIKTPZ_PLUGIN_URL . 'assets/aiktp-token.js',
                array('jquery'),
                AIKTPZ_VERSION,
                true
            );
            
            wp_localize_script('aiktp-token', 'aiktpTokenData', array(
                'ajaxUrl' => admin_url('admin-ajax.php'),
                'nonce' => wp_create_nonce('aiktp_regenerate_token'),
                'i18n' => array(
                    'copied' => __('Token copied to clipboard!', 'aiktp'),
                    'copyFailed' => __('Failed to copy token. Please copy manually.', 'aiktp'),
                    'confirmRegenerate' => __('Are you sure? This will invalidate the old token and you will need to update it on aiktp.com', 'aiktp'),
                    'regenerating' => __('Regenerating...', 'aiktp'),
                    'regenerated' => __('Token regenerated successfully!', 'aiktp'),
                    'regenerateFailed' => __('Failed to regenerate token.', 'aiktp'),
                    'regenerateButton' => __('Regenerate Token', 'aiktp'),
                )
            ));
        }
        
        // For product edit page
        if (('post.php' === $hook || 'post-new.php' === $hook) && 'product' === $post_type) {
            // Enqueue CSS
            wp_enqueue_style(
                'aiktp-admin',
                AIKTPZ_PLUGIN_URL . 'assets/aiktp.css',
                array(),
                AIKTPZ_VERSION
            );
            
            // Enqueue JS
            wp_enqueue_script(
                'aiktpz-admin',
                AIKTPZ_PLUGIN_URL . 'assets/aiktp.js',
                array('jquery'),
                AIKTPZ_VERSION,
                true
            );
            
            wp_localize_script('aiktpz-admin', 'aiktpzData', array(
                'ajaxUrl' => admin_url('admin-ajax.php'),
                'nonce' => wp_create_nonce('aiktpz_generate_nonce'),
                'postId' => get_the_ID(),
                'i18n' => array(
                    'generating' => __('Generating...', 'aiktp'),
                    'success' => __('Successfully generated!', 'aiktp'),
                    'error' => __('An error occurred!', 'aiktp'),
                )
            ));
        }
        
        // For product list page (bulk action)
        if ('edit.php' === $hook && 'product' === $post_type) {
            wp_enqueue_script(
                'aiktpz-bulk',
                AIKTPZ_PLUGIN_URL . 'assets/aiktp_bulk.js',
                array('jquery'),
                AIKTPZ_VERSION,
                true
            );
            
            wp_localize_script('aiktpz-bulk', 'aiktpzBulkData', array(
                'ajaxUrl' => admin_url('admin-ajax.php'),
                'nonce' => wp_create_nonce('aiktpz_bulk_nonce'),
                'i18n' => array(
                    'processing' => __('Processing...', 'aiktp'),
                    'generating' => __('Generating descriptions...', 'aiktp'),
                    'success' => __('Successfully generated!', 'aiktp'),
                    'error' => __('Error:', 'aiktp'),
                    'completed' => __('Completed', 'aiktp'),
                    'of' => __('of', 'aiktp'),
                )
            ));
        }
    }
    
    /**
     * AJAX generate content
     */
    public function ajax_generate_content() {
        check_ajax_referer('aiktpz_generate_nonce', 'nonce');
        
        // Check if WooCommerce is ready
        if (!class_exists('WooCommerce')) {
            wp_send_json_error(array('message' => 'WooCommerce is not active'));
        }
        
        if (!current_user_can('edit_products')) {
            wp_send_json_error(array('message' => 'Unauthorized'));
        }
        
        $post_id = isset($_POST['post_id']) ? absint(wp_unslash($_POST['post_id'])) : 0;
        $type = isset($_POST['type']) ? sanitize_text_field(wp_unslash($_POST['type'])) : '';
        
        $product = wc_get_product($post_id);
        if (!$product) {
            wp_send_json_error(array('message' => 'Product not found'));
        }
        
        // Get product information
        $product_info = $this->get_product_info($product, $post_id);
        
        // Determine task based on type
        $task = ($type === 'short_description') ? 'genProductShortDescription' : 'genProductDescription';
        
        // Call AIKTP API
        $content = $this->call_aiktp_api($task, $product_info);
        
        if (is_wp_error($content)) {
            $error_message = $content->get_error_message();
            $error_data = array('message' => $error_message);
            
            // Check if error is about insufficient credits
            if ($error_message === 'NOT_ENOUGH_CREDITS' || strpos($error_message, 'NOT_ENOUGH_CREDITS') !== false) {
                $error_data['not_enough_credits'] = true;
                $error_data['message'] = __('Not enough credits to generate content. Please <a href="https://aiktp.com/pricing" target="_blank">purchase more credits</a> to continue.', 'aiktp');
            }
            
            wp_send_json_error($error_data);
        }
        
        // Add internal link to main keyword for full description
        if ($task === 'genProductDescription' && !empty($product_info['mainKeyword'])) {
            $content = $this->add_internal_link_to_keyword($content, $product_info['mainKeyword'], $post_id);
            $content = $this->add_product_image_to_content($content, $product_info['mainKeyword'], $product, $post_id);
        }
        
        // Update product
        if ($type === 'description') {
            if (!empty($content)){            
                wp_update_post(array(
                    'ID' => $post_id,
                    'post_content' => $content
                ));
            }
        } elseif ($type === 'short_description') {
            if (!empty($content)){        
                update_post_meta($post_id, '_product_short_description', $content);
            }
        }
        
        wp_send_json_success(array(
            'message' => __('Content generated successfully!', 'aiktp'),
            'content' => $content,
            'type' => $type
        ));
    }
    
    /**
     * Get comprehensive product information
     */
    private function get_product_info($product, $post_id) {
        // Basic info
        $product_name = $product->get_name();
        $product_price = $product->get_price();
        $sale_price = $product->get_sale_price();
        
        // Categories and tags
        $product_categories = wp_get_post_terms($post_id, 'product_cat', array('fields' => 'names'));
        $product_tags = wp_get_post_terms($post_id, 'product_tag', array('fields' => 'names'));
        
        // Attributes
        $attributes = array();
        if ($product->is_type('variable')) {
            $variation_attributes = $product->get_variation_attributes();
            foreach ($variation_attributes as $attribute_name => $options) {
                $attributes[$attribute_name] = is_array($options) ? implode(', ', $options) : $options;
            }
        } else {
            $product_attributes = $product->get_attributes();
            foreach ($product_attributes as $attribute) {
                if ($attribute->get_variation()) {
                    continue;
                }
                $attribute_name = wc_attribute_label($attribute->get_name());
                $attribute_values = array();
                if ($attribute->is_taxonomy()) {
                    $terms = wp_get_post_terms($post_id, $attribute->get_name(), array('fields' => 'names'));
                    $attribute_values = $terms;
                } else {
                    $attribute_values = $attribute->get_options();
                }
                $attributes[$attribute_name] = implode(', ', $attribute_values);
            }
        }
        
        // Additional info
        $sku = $product->get_sku();
        $stock_status = $product->get_stock_status();
        $stock_quantity = $product->get_stock_quantity();
        $weight = $product->get_weight();
        $dimensions = $product->get_dimensions(false);
        
        // Get length and tone settings
        $length = get_option('aiktpz_content_length', 'medium');
        $tone = get_option('aiktpz_content_tone', 'friendly');
        $custom_prompt = get_option('aiktpz_custom_prompt', '');
        
        // Get target language (stored as language name, e.g., 'English', 'Vietnamese')
        $target_language = get_option('aiktpz_content_language', '');
        if (empty($target_language)) {
            $target_language = 'auto';
        }
        
        // Length map
        $length_map = array(
            'short' => '500-800 words',
            'medium' => '800-1.200 words',
            'long' => 'more than 1.200 words'
        );
        
        // Tone map
        $tone_map = array(
            'professional' => 'professional',
            'friendly' => 'friendly and approachable',
            'casual' => 'casual and cheerful',
            'persuasive' => 'persuasive and engaging'
        );
        
        // Get main keyword from SEO plugins
        $main_keyword = $this->get_main_keyword($post_id, $product_name);
        
        // Build product info array
        $product_info = array(
            'name' => $product_name,
            'price' => $product_price ? $product_price : '',
            'sale-price' => $sale_price ? $sale_price : '',
            'attributes' => !empty($attributes) ? json_encode($attributes) : '',
            'categories' => !empty($product_categories) ? implode(', ', $product_categories) : '',
            'tags' => !empty($product_tags) ? implode(', ', $product_tags) : '',
            'sku' => $sku ? $sku : '',
            'stock-status' => $stock_status ? $stock_status : '',
            'stock-quantity' => $stock_quantity ? $stock_quantity : '',
            'weight' => $weight ? $weight : '',
            'dimensions' => !empty($dimensions) ? json_encode($dimensions) : '',
            'length' => $length,
            'lengthTxt' => $length_map[$length],
            'tone' => $tone_map[$tone],
            'custom-prompt' => $custom_prompt,
            'mainKeyword' => $main_keyword,
            'targetLanguage' => $target_language,
        );
        
        return $product_info;
    }
    
    /**
     * Add product image to content with keyword as alt text
     */
    private function add_product_image_to_content($content, $keyword, $product, $post_id) {
        if (empty($keyword) || empty($content)) {
            return $content;
        }
        
        $image_id = null;
        $image_url = '';
        
        // Get product images
        $attachment_ids = $product->get_gallery_image_ids();
        $featured_image_id = $product->get_image_id();
        
        // Build complete image array: featured + gallery
        $all_images = array();
        if ($featured_image_id) {
            $all_images[] = $featured_image_id;
        }
        if (!empty($attachment_ids)) {
            $all_images = array_merge($all_images, $attachment_ids);
        }
        
        // Remove duplicates
        $all_images = array_unique($all_images);
        
        // Count total images
        $total_images = count($all_images);
        
        // Determine which image to use
        if ($total_images >= 2) {
            // Use second image (index 1)
            $image_id = $all_images[1];
        } elseif ($total_images === 1) {
            // Use first image (index 0)
            $image_id = $all_images[0];
        } else {
            // No image, skip
            return $content;
        }
        
        // Get image URL
        $image_url = wp_get_attachment_image_url($image_id, 'full');
        if (!$image_url) {
            return $content;
        }
        
        // Get image alt text (use keyword as fallback)
        $image_alt = get_post_meta($image_id, '_wp_attachment_image_alt', true);
        if (empty($image_alt)) {
            $image_alt = $keyword;
            // Update alt text for future use
            update_post_meta($image_id, '_wp_attachment_image_alt', $keyword);
        }
        
        // Create image HTML with keyword as alt text
        $image_html = '<figure class="wp-block-image size-full">';
        $image_html .= '<img src="' . esc_url($image_url) . '" alt="' . esc_attr($keyword) . '" />';
        $image_html .= '</figure>';
        
        // Insert image after first paragraph
        // Find the first closing </p> tag
        $first_p_end = stripos($content, '</p>');
        if ($first_p_end !== false) {
            // Insert after first paragraph
            $content = substr_replace($content, $image_html, $first_p_end + 4, 0);
        } else {
            // No paragraph found, insert at the beginning
            $content = $image_html . $content;
        }
        
        return $content;
    }
    
    /**
     * Add internal link to first occurrence of main keyword
     */
    private function add_internal_link_to_keyword($content, $keyword, $post_id) {
        if (empty($keyword) || empty($content)) {
            return $content;
        }
        
        // Get product permalink
        $product_url = get_permalink($post_id);
        if (!$product_url) {
            return $content;
        }
        
        // Trim keyword
        $keyword = trim($keyword);
        
        // Check if keyword already exists in a link
        if (preg_match('/<a[^>]*>.*?' . preg_quote($keyword, '/') . '.*?<\/a>/is', $content)) {
            return $content; // Keyword already linked, skip
        }
        
        // Split content by HTML tags to avoid replacing inside tags
        $parts = preg_split('/(<[^>]+>)/i', $content, -1, PREG_SPLIT_DELIM_CAPTURE);
        
        $replaced = false;
        $current_tag = ''; // Track current open tag
        $tag_stack = array(); // Stack to track nested tags
        
        foreach ($parts as $index => $part) {
            // Check if this is an HTML tag
            if (preg_match('/^<([\/]?)(\w+)[^>]*>$/i', $part, $matches)) {
                $is_closing = ($matches[1] === '/');
                $tag_name = strtolower($matches[2]);
                
                if ($is_closing) {
                    // Closing tag - pop from stack
                    if (!empty($tag_stack) && end($tag_stack) === $tag_name) {
                        array_pop($tag_stack);
                    }
                } else {
                    // Opening tag - push to stack (only for tags that have closing tags)
                    if (!in_array($tag_name, array('br', 'hr', 'img', 'input', 'meta', 'link'))) {
                        $tag_stack[] = $tag_name;
                    }
                }
                continue;
            }
            
            // Skip if we already replaced
            if ($replaced) {
                continue;
            }
            
            // Get current parent tag (last in stack)
            $parent_tag = !empty($tag_stack) ? end($tag_stack) : '';
            
            // Only replace if we're inside <p> or <div> tags, NOT in heading tags
            $allowed_tags = array('p', 'div');
            $forbidden_tags = array('h1', 'h2', 'h3', 'h4', 'h5', 'h6', 'a');
            
            // Skip if parent is a forbidden tag
            if (in_array($parent_tag, $forbidden_tags)) {
                continue;
            }
            
            // Only proceed if parent is an allowed tag
            if (!in_array($parent_tag, $allowed_tags)) {
                continue;
            }
            
            // Check if keyword exists in this part (case-insensitive)
            $pos = stripos($part, $keyword);
            if ($pos !== false) {
                // Get the actual matched text (preserving case)
                $matched_keyword = substr($part, $pos, strlen($keyword));
                
                // Create link with the actual matched keyword (not escaped)
                $link = '<a href="' . esc_url($product_url) . '">' . $matched_keyword . '</a>';
                
                // Replace only first occurrence
                $parts[$index] = substr_replace($part, $link, $pos, strlen($keyword));
                $replaced = true;
            }
        }
        
        // Rejoin all parts
        $content = implode('', $parts);
        
        return $content;
    }
    
    /**
     * Get main keyword from RankMath or Yoast SEO
     */
    private function get_main_keyword($post_id, $product_name) {
        $main_keyword = '';
        
        // Check for RankMath
        if (defined('RANK_MATH_VERSION') || class_exists('RankMath')) {
            $rankmath_keyword = get_post_meta($post_id, 'rank_math_focus_keyword', true);
            if (!empty($rankmath_keyword)) {
                $main_keyword = $rankmath_keyword;
            } else {
                // Set product name as focus keyword if not exists
                update_post_meta($post_id, 'rank_math_focus_keyword', $product_name);
                $main_keyword = $product_name;
            }
        }
        // Check for Yoast SEO
        elseif (defined('WPSEO_VERSION') || class_exists('WPSEO_Options')) {
            $yoast_keyword = get_post_meta($post_id, '_yoast_wpseo_focuskw', true);
            if (!empty($yoast_keyword)) {
                $main_keyword = $yoast_keyword;
            } else {
                // Set product name as focus keyword if not exists
                update_post_meta($post_id, '_yoast_wpseo_focuskw', $product_name);
                $main_keyword = $product_name;
            }
        }
        // No SEO plugin detected, use product name
        else {
            $main_keyword = $product_name;
        }
        
        return $main_keyword;
    }
    
    /**
     * Call AIKTP API
     */
    private function call_aiktp_api($task, $product_info) {
        
        $api_key = $this->api_key;
        
        if (empty($api_key)) {
            return new WP_Error('no_api_key', __('API key is not configured', 'aiktp'));
        }
        
        $request_data = array(
            'task' => $task,
            'productInfo' => $product_info
        );

        
        $response = wp_remote_post('https://aiktp.com/api/ai.php', array(
            'timeout' => 120,
            'headers' => array(
                'Content-Type' => 'application/json',
                'Authorization' => 'Bearer ' . $api_key
            ),
            'body' => json_encode($request_data)
        ));
        
        if (is_wp_error($response)) {
            return $response;
        }
        
        $body = json_decode(wp_remote_retrieve_body($response), true);
        
        // Check for error in top level
        if (isset($body['error'])) {
            return new WP_Error('api_error', isset($body['error']['message']) ? $body['error']['message'] : 'API error occurred');
        }
        
        // Check for status in data field
        if (isset($body['data']['status'])) {
            if ($body['data']['status'] === 'success') {
                // Success: return content
                if (isset($body['data']['content'])) {
                    return $body['data']['content'];
                }
                // Fallback: return data if content not found
                return new WP_Error('invalid_response', __('Success status but no content found', 'aiktp'));
            } elseif ($body['data']['status'] === 'error') {
                // Error: return error message
                $error_msg = isset($body['data']['msg']) ? $body['data']['msg'] : __('API returned error status', 'aiktp');
                return new WP_Error('api_error', $error_msg);
            }
        }
        
        // Fallback: check for direct content field (backward compatibility)
        if (!empty($body['data']['content'])) {
            return $body['data']['content'];
        }
        
        // Fallback: check for direct content field at root level
        if (isset($body['content'])) {
            return $body['content'];
        }
        
        // Fallback: check for data field
        if (isset($body['data'])) {
            return is_string($body['data']) ? $body['data'] : json_encode($body['data']);
        }
        
        return new WP_Error('invalid_response', __('Invalid API response format', 'aiktp'));
    }
    
    
    /**
     * Add custom bulk actions UI
     */
    /**
     * Add bulk actions to dropdown
     */
    public function add_bulk_actions($actions) {
        $api_key = get_option('aiktp_api_key', '');
        
        if (!empty($api_key)) {
            // Add header for AI Content SEO group
            $actions['aiktpz_header'] = '↓ ' . __('AI Content SEO', 'aiktp');
            // Add Gen Descriptions action
            $actions['aiktpz_gen_descriptions'] = '- ' . __('Gen Product Descriptions ✨', 'aiktp');
            $actions['aiktpz_gen_short_descriptions'] = '- ' . __('Gen Product Short Descriptions 🪄', 'aiktp');
        }
        
        return $actions;
    }
    
    /**
     * Handle bulk actions
     */
    public function handle_bulk_actions($redirect_to, $action, $post_ids) {
        // Ignore header action (just for display)
        if ($action === 'aiktpz_header') {
            return $redirect_to;
        }
        
        // Check if it's one of our bulk actions
        $type = '';
        if ($action === 'aiktpz_gen_descriptions') {
            $type = 'description';
        } elseif ($action === 'aiktpz_gen_short_descriptions') {
            $type = 'short_description';
        } else {
            return $redirect_to;
        }
        
        // Check if API key is configured
        $api_key = get_option('aiktp_api_key', '');
        if (empty($api_key)) {
            $redirect_to = add_query_arg('aiktpz_bulk_error', 'no_api_key', $redirect_to);
            return $redirect_to;
        }
        
        // Store product IDs and type in transient
        set_transient('aiktpz_bulk_products', $post_ids, 300);
        set_transient('aiktpz_bulk_type', $type, 300);
        
        // Redirect to trigger bulk generation
        $redirect_to = add_query_arg(array(
            'aiktpz_bulk_generate' => '1',
            'aiktpz_product_count' => count($post_ids),
            'aiktpz_bulk_type' => $type,
            'aiktpz_nonce' => wp_create_nonce('aiktpz_bulk_action')
        ), esc_url(admin_url('edit.php?post_type=product')));
        
        return $redirect_to;
    }
    
    /**
     * Add custom bulk actions UI (only for API key notice)
     */
    public function add_custom_bulk_actions_ui() {
        global $typenow;
        
        if ($typenow !== 'product') {
            return;
        }
        
        // Check if API key is configured
        $api_key = get_option('aiktp_api_key', '');
        
        if (empty($api_key)) {
            ?>
            <div class="alignleft actions wcai-bulk-actions" style="margin-left: 10px;">
                <a href="<?php echo esc_url(admin_url('admin.php?page=aiktp-settings')); ?>" 
                   class="button button-secondary" 
                   style="height: 32px; line-height: 30px; border-radius: 0 3px 3px 0; vertical-align: top;">
                    ⚠️ <?php esc_html_e('Configure API Key', 'aiktp'); ?>
                </a>
            </div>
            <?php
        }
    }
    
    
    /**
     * Display bulk action admin notice
     */
    public function bulk_action_admin_notice() {
        if (!isset($_GET['aiktpz_bulk_generate'])) {
            return;
        }
        
        // Verify nonce
        if (!isset($_GET['aiktpz_nonce']) || !wp_verify_nonce(sanitize_text_field(wp_unslash($_GET['aiktpz_nonce'])), 'aiktpz_bulk_action')) {
            return;
        }
        
        // Get product IDs from transient (set by handle_bulk_actions)
        $product_ids = get_transient('aiktpz_bulk_products');
        $product_count = 0;
        
        if ($product_ids && is_array($product_ids)) {
            $product_count = count($product_ids);
        } else {
            // Fallback: try to get from URL (for backward compatibility)
            $product_count = isset($_GET['aiktpz_product_count']) ? absint(wp_unslash($_GET['aiktpz_product_count'])) : 0;
            $product_ids_str = isset($_GET['aiktpz_product_ids']) ? sanitize_text_field(wp_unslash($_GET['aiktpz_product_ids'])) : '';
            
            if (!empty($product_ids_str)) {
                $product_ids = array_map('intval', explode(',', $product_ids_str));
                set_transient('aiktpz_bulk_products', $product_ids, 300);
                $product_count = count($product_ids);
            }
        }
        
        if ($product_count === 0) {
            return;
        }
        
        // Get type from URL or transient
        $type = isset($_GET['aiktpz_bulk_type']) ? sanitize_text_field(wp_unslash($_GET['aiktpz_bulk_type'])) : get_transient('aiktpz_bulk_type');
        if (empty($type)) {
            $type = 'description';
        }
        
        $type_label = ($type === 'short_description') ? __('short descriptions', 'aiktp') : __('descriptions', 'aiktp');
        $title = ($type === 'short_description') ? __('AI Short Description Generation', 'aiktp') : __('AI Description Generation', 'aiktp');
        
        ?>
        <div class="notice notice-info is-dismissible" id="wcai-bulk-notice">
            <p>
                <strong><?php echo esc_html($title); ?></strong>
            </p>
            <p id="wcai-bulk-status">
                <?php 
                // translators: %s: type label, %d: product count.
                printf(esc_html__( 'Preparing to generate %1$s for %2$d products...', 'aiktp' ), esc_html($type_label),esc_attr($product_count)); 
                ?>
            </p>
            <div id="wcai-bulk-progress" style="margin-top: 10px;">
                <progress id="wcai-progress-bar" value="0" max="<?php echo esc_attr($product_count); ?>" style="width: 100%; height: 25px;"></progress>
                <p id="wcai-progress-text" style="margin-top: 5px;">0 / <?php echo esc_attr($product_count); ?></p>
            </div>
        </div>
        <?php
    }
    
    /**
     * AJAX bulk generate
     */
    public function ajax_bulk_generate() {
        check_ajax_referer('aiktpz_bulk_nonce', 'nonce');
        
        if (!current_user_can('edit_products')) {
            wp_send_json_error(array('message' => 'Unauthorized'));
        }
        
        $post_id = isset($_POST['post_id']) ? absint(wp_unslash($_POST['post_id'])) : 0;
        
        $product = wc_get_product($post_id);
        if (!$product) {
            wp_send_json_error(array('message' => 'Product not found: ' . $post_id));
        }
        
        // Get type from transient or default to description
        $type = get_transient('aiktpz_bulk_type');
        if (empty($type)) {
            $type = 'description';
        }
        
        // Get product information
        $product_info = $this->get_product_info($product, $post_id);
        
        // Determine task based on type
        $task = ($type === 'short_description') ? 'genProductShortDescription' : 'genProductDescription';
        
        // Call AIKTP API
        $content = $this->call_aiktp_api($task, $product_info);
        
        if (is_wp_error($content)) {
            $error_message = $content->get_error_message();
            $error_data = array(
                'message' => $error_message,
                'product_id' => $post_id,
                'product_name' => $product_info['name']
            );
            
            // Check if error is about insufficient credits
            if ($error_message === 'NOT_ENOUGH_CREDITS' || strpos($error_message, 'NOT_ENOUGH_CREDITS') !== false) {
                $error_data['not_enough_credits'] = true;
                $error_data['message'] = __('Not enough credits to generate content. Please <a href="https://aiktp.com/pricing" target="_blank">purchase more credits</a> to continue.', 'aiktp');
            }
            
            wp_send_json_error($error_data);
        }
        
        // Update product based on type
        if ($type === 'short_description') {
            update_post_meta($post_id, '_product_short_description', $content);
            $success_message = __('Short description generated successfully!', 'aiktp');
        } else {
            wp_update_post(array(
                'ID' => $post_id,
                'post_content' => $content
            ));
            $success_message = __('Description generated successfully!', 'aiktp');
        }
        
        wp_send_json_success(array(
            'message' => $success_message,
            'product_id' => $post_id,
            'product_name' => $product_info['name'],
            'content' => $content
        ));
    }
    
    /**
     * AJAX get bulk products from transient
     */
    public function ajax_get_bulk_products() {
        check_ajax_referer('aiktpz_bulk_nonce', 'nonce');
        
        if (!current_user_can('edit_products')) {
            wp_send_json_error(array('message' => 'Unauthorized'));
        }
        
        $product_ids = get_transient('aiktpz_bulk_products');
        
        if (!$product_ids) {
            wp_send_json_error(array('message' => 'No products found'));
        }
        
        // Delete transient after retrieving
        delete_transient('aiktpz_bulk_products');
        
        wp_send_json_success(array('product_ids' => $product_ids));
    }
    
    /**
     * AJAX regenerate token
     */
    public function ajax_regenerate_token() {
        // Verify nonce
        check_ajax_referer('aiktp_regenerate_token', 'nonce');
        
        // Check permission
        if (!current_user_can('manage_options')) {
            wp_send_json_error(array('message' => __('Unauthorized', 'aiktp')));
        }
        
        // Generate new token
        $new_token = uniqid();
        update_option('aiktpz_token', $new_token);
        
        wp_send_json_success(array(
            'token' => $new_token,
            'message' => __('Token regenerated successfully', 'aiktp')
        ));
    }
}

// Declare WooCommerce HPOS compatibility
add_action('before_woocommerce_init', function() {
    if (class_exists(\Automattic\WooCommerce\Utilities\FeaturesUtil::class)) {
        \Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility('custom_order_tables', __FILE__, true);
    }
});

// Run migration on plugin load (for existing installations upgrading to new prefix)
add_action('plugins_loaded', function() {
    // Check if migration has been run
    if (get_option('aiktpz_migration_done') !== '1') {
        AIKTPZ_Settings::migrate_old_options();
        update_option('aiktpz_migration_done', '1');
    }
}, 5); // Run early, before plugin initialization

// Initialize plugin
function aiktpz_init() {
    // Plugin can work without WooCommerce
    AIKTPZ_AI_Content_Generator::get_instance();
}
add_action('plugins_loaded', 'aiktpz_init', 20);