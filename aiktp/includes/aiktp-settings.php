<?php
/**
 * AIKTP Settings Page
 * Admin settings interface for AIKTP plugin
 */

if (!defined('ABSPATH')) {
    exit;
}

class AIKTPZ_Settings {
    
    private static $instance = null;
    
    public static function get_instance() {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }
    
    private function __construct() {
        add_action('admin_init', array($this, 'handle_settings_form'));
        add_action('admin_enqueue_scripts', array($this, 'enqueue_settings_assets'));
        add_action('wp_ajax_aiktp_connect', array($this, 'ajax_connect'));
    }
    
    /**
     * Enqueue scripts and styles for settings page
     */
    public function enqueue_settings_assets($hook) {
        // Only load on AIKTP settings page
        if ($hook !== 'toplevel_page_aiktp-settings') {
            return;
        }
        
        // Enqueue CSS
        wp_enqueue_style(
            'aiktp-settings',
            AIKTPZ_PLUGIN_URL . 'assets/aiktp.css',
            array(),
            AIKTPZ_VERSION
        );
        
        // Enqueue JS
        wp_enqueue_script(
            'aiktp-settings',
            AIKTPZ_PLUGIN_URL . 'assets/aiktp.js',
            array('jquery'),
            AIKTPZ_VERSION,
            true
        );
        
        // Localize script with nonce and connection status
        $aiktp_api_key = get_option('aiktp_api_key', '');
        $is_connected = !empty($aiktp_api_key);
        
        wp_localize_script('aiktp-settings', 'aiktpSettings', array(
            'connectNonce' => wp_create_nonce('aiktp_connect'),
            'isConnected' => $is_connected
        ));
    }
    
    /**
     * Render AIKTP Settings Page
     */
    public static function render_settings_page() {
        // Get current API key and connection status
        $aiktp_api_key = get_option('aiktp_api_key', '');
        
        $is_connected = !empty($aiktp_api_key);
        
        // Post Sync Settings
        $aiktp_author = get_option('aiktp_author', 0);
        $aiktp_category = get_option('aiktp_category', '');
        
        // Get users
        $list_users = get_users();
        $user_options = '<option value="0">Randomly choose an author</option>';
        foreach ($list_users as $user) {
            $selected = ($aiktp_author == $user->ID) ? 'selected' : '';
            $user_options .= '<option ' . $selected . ' value="' . esc_attr($user->ID) . '">' . esc_html($user->user_nicename) . '</option>';
        }
        
        // Get categories
        $args = array('hide_empty' => false);
        $list_cat = get_categories($args);
        $arr_categories = !empty($aiktp_category) ? explode(',', $aiktp_category) : array();
        $cat_options_li = '';
        
        foreach ($list_cat as $cat) {
            $checked = in_array($cat->cat_ID, $arr_categories) ? 'checked' : '';
            $cat_options_li .= '<li><label><input type="checkbox" name="aiktp_categories[]" ' . $checked . ' value="' . esc_attr($cat->cat_ID) . '">' . esc_html($cat->cat_name) . '</label></li>';
        }
        
        // Get WordPress site URL
        $wp_url = home_url();
        
        ?>
        
        
        <div class="wrap aiktp-settings-wrapper">
            <div class="aiktp-header">
            <h1>Settings</h1>
                Connect to aiktp.com, configure post synchronization, and enable WooCommerce AI SEO features powered by <a href="https://aiktp.com" target="_blank">aiktp.com</a>
            </div>
            
            <!-- Tabs Navigation -->
            <div class="aiktp-tabs">
                <button class="aiktp-tab active" data-tab="connect">Connect</button>
                <button class="aiktp-tab" data-tab="post-sync">Post Sync</button>
                <button class="aiktp-tab" data-tab="woocommerce-ai">WooCommerce AI</button>
            </div>
            
            <!-- Tab: Connect -->
            <div id="aiktp-tab-connect" class="aiktp-tab-content active">
                <div class="aiktp-settings-card">
                    <h2>Connect Your WordPress Site to aiktp.com</h2>
                    
                    <div class="aiktp-form-group">
                        <label for="aiktp_api_key">API Key</label>
                        <input type="password" 
                               id="aiktp_api_key" 
                               value="<?php echo esc_attr($aiktp_api_key); ?>" 
                               placeholder="Enter your API key"
                        />
                        <p class="description">
                            Visit <a href="https://aiktp.com/user-api" target="_blank">aiktp.com/user-api</a> to get your API key. Paste it into the field above and click “Connect” to link this website with AIKTP.
                        </p>
                    </div>
                    
                    <div class="aiktp-form-group">
                        <button type="button" class="aiktp-connect-button" id="aiktp-connect-btn">
                            Connect
                        </button>
                        <div id="aiktp-connect-status" class="aiktp-connect-status <?php echo $is_connected ? 'connected' : 'not-connected'; ?>" style="display: none;">
                            <?php echo $is_connected ? 'Connected to AIKTP' : 'Not Connected'; ?>
                        </div>
                    </div>
                    
                    <div class="aiktp-form-group">
                        <p class="description">
                            <strong>Site URL:</strong> <code><?php echo esc_url($wp_url); ?></code>
                        </p>
                    </div>
                    
                    <?php if ($is_connected): ?>
                    <div class="aiktp-credit-info" id="aiktp-credit-info">
                        <h3>Account Information</h3>
                        <div class="aiktp-credit-loading" id="aiktp-credit-loading">
                            Loading credit information...
                        </div>
                        <div id="aiktp-credit-content" style="display: none;">
                            <div class="aiktp-credit-stats">
                                <div class="aiktp-credit-stat">
                                    <div class="aiktp-credit-stat-label">Remaining Credits</div>
                                    <div class="aiktp-credit-stat-value" id="aiktp-credits-value">-</div>
                                </div>
                                <div class="aiktp-credit-stat">
                                    <div class="aiktp-credit-stat-label">Remaining Posts</div>
                                    <div class="aiktp-credit-stat-value" id="aiktp-posts-value">-</div>
                                </div>
                            </div>
                            <div class="aiktp-credit-intro">
                                <strong>Note:</strong> 1 credit = 1 word. <a href="https://aiktp.com/pricing" target="_blank">Buy more credits</a> at aiktp.com/pricing
                            </div>
                        </div>
                        <div id="aiktp-credit-error" style="display: none;" class="aiktp-credit-error"></div>
                    </div>
                    <?php endif; ?>
                </div>
            </div>
            
            <!-- Tab: Post Sync -->
            <div id="aiktp-tab-post-sync" class="aiktp-tab-content">
                <form method="post" action="options.php">
                    <?php settings_fields('aiktp_all_settings'); ?>
                    
                    <div class="aiktp-settings-card">
                        <h2>Post Sync Configuration</h2>
                        
                        <div class="aiktp-form-group">
                            <label for="aiktp_author">Default Author</label>
                            <select name="aiktp_author" id="aiktp_author">
                                <?php echo wp_kses($user_options, array('option' => array('value' => array(), 'selected' => array()))); ?>
                            </select>
                            <p class="description">Choose an author for posts synced from aiktp.com</p>
                        </div>
                        
                        <div class="aiktp-form-group">
                            <label>Default Category</label>
                            <input type="hidden" name="aiktp_category" value="" />
                            <ul class="aiktp-categories-list"><?php echo wp_kses($cat_options_li, array('li' => array(), 'label' => array(), 'input' => array('type' => array(), 'name' => array(), 'checked' => array(), 'value' => array()))); ?></ul>
                            <p class="description">Select default categories for synced posts</p>
                        </div>
                        
                        <div class="aiktp-submit-wrapper">
                            <?php submit_button('Save Settings', 'primary', 'submit', false); ?>
                        </div>
                    </div>
                </form>
                <div class="aiktp-info-card">
                    <h3>Post Sync - How to use</h3>
                    <p>Publish AI-generated posts from <a href="https://aiktp.com" target="_blank">aiktp.com</a> directly to this website.</p>
                    <p style="margin-top: 15px;"><strong>Features:</strong></p>
                    <ul>
                        <li>AI-generated posts are automatically published to your website</li>
                        <li>Automatic image upload and optimization</li>
                        <li>SEO optimization for better search rankings</li>
                        <li>Publish individual posts or bulk posts quickly</li>
                    </ul>
                    <p style="margin-top: 15px;">
                        <strong>How to use:</strong> Go to <a href="https://aiktp.com" target="_blank">aiktp.com</a> to create and publish your AI-generated content.
                    </p>
                </div>
            </div>
            
            <!-- Tab: WooCommerce AI -->
            <div id="aiktp-tab-woocommerce-ai" class="aiktp-tab-content">
                <form method="post" action="options.php">
                    <?php settings_fields('aiktp_all_settings'); ?>
                    
                    <div class="aiktp-settings-card">
                        <h2>WooCommerce AI Content Generator</h2>
                        
                        <div class="aiktp-form-group">
                            <label for="aiktpz_content_length">Content Length</label>
                            <select id="aiktpz_content_length" name="aiktpz_content_length">
                                <option value="short" <?php selected(get_option('aiktpz_content_length', 'medium'), 'short'); ?>>
                                    Short (500-800 words)
                                </option>
                                <option value="medium" <?php selected(get_option('aiktpz_content_length', 'medium'), 'medium'); ?>>
                                    Medium (800-1.200 words)
                                </option>
                                <option value="long" <?php selected(get_option('aiktpz_content_length', 'medium'), 'long'); ?>>
                                    More than 1.200 words
                                </option>
                            </select>
                        </div>
                        
                        <div class="aiktp-form-group">
                            <label for="aiktpz_content_tone">Content Tone</label>
                            <select id="aiktpz_content_tone" name="aiktpz_content_tone">
                                <option value="professional" <?php selected(get_option('aiktpz_content_tone', 'friendly'), 'professional'); ?>>
                                    Professional
                                </option>
                                <option value="friendly" <?php selected(get_option('aiktpz_content_tone', 'friendly'), 'friendly'); ?>>
                                    Friendly
                                </option>
                                <option value="casual" <?php selected(get_option('aiktpz_content_tone', 'friendly'), 'casual'); ?>>
                                    Casual
                                </option>
                                <option value="persuasive" <?php selected(get_option('aiktpz_content_tone', 'friendly'), 'persuasive'); ?>>
                                    Persuasive
                                </option>
                            </select>
                        </div>
                        
                        <div class="aiktp-form-group">
                            <label for="aiktpz_content_language">Target Language</label>
                            <select id="aiktpz_content_language" name="aiktpz_content_language">
                                <?php 
                                $site_language = get_locale();
                                
                                // Map common locale codes to language names
                                $locale_map = array(
                                    'en_US' => 'English',
                                    'vi' => 'Vietnamese',
                                    'es_ES' => 'Spanish',
                                    'fr_FR' => 'French',
                                    'de_DE' => 'German',
                                    'zh_CN' => 'Chinese (Simplified)',
                                    'zh_TW' => 'Chinese (Traditional)',
                                    'ja' => 'Japanese',
                                    'ko_KR' => 'Korean',
                                    'pt_BR' => 'Portuguese (Brazil)',
                                    'pt_PT' => 'Portuguese (Portugal)',
                                    'it_IT' => 'Italian',
                                    'ru_RU' => 'Russian',
                                    'th' => 'Thai',
                                    'id_ID' => 'Indonesian',
                                    'nl_NL' => 'Dutch',
                                    'pl_PL' => 'Polish',
                                    'tr_TR' => 'Turkish',
                                    'ar' => 'Arabic',
                                    'hi_IN' => 'Hindi',
                                );
                                
                                // Get site language name
                                $site_language_name = isset($locale_map[$site_language]) ? $locale_map[$site_language] : 'English';
                                
                                // If aiktpz_content_language is empty, use site language name as default
                                $selected_language = get_option('aiktpz_content_language', '');
                                if (empty($selected_language)) {
                                    $selected_language = $site_language_name;
                                }
                                ?>
                                <option value="English" <?php selected($selected_language, 'English'); ?>>
                                    English
                                </option>
                                <option value="Vietnamese" <?php selected($selected_language, 'Vietnamese'); ?>>
                                    Vietnamese
                                </option>
                                <option value="Spanish" <?php selected($selected_language, 'Spanish'); ?>>
                                    Spanish
                                </option>
                                <option value="French" <?php selected($selected_language, 'French'); ?>>
                                    French
                                </option>
                                <option value="German" <?php selected($selected_language, 'German'); ?>>
                                    German
                                </option>
                                <option value="Chinese (Simplified)" <?php selected($selected_language, 'Chinese (Simplified)'); ?>>
                                    Chinese (Simplified)
                                </option>
                                <option value="Chinese (Traditional)" <?php selected($selected_language, 'Chinese (Traditional)'); ?>>
                                    Chinese (Traditional)
                                </option>
                                <option value="Japanese" <?php selected($selected_language, 'Japanese'); ?>>
                                    Japanese
                                </option>
                                <option value="Korean" <?php selected($selected_language, 'Korean'); ?>>
                                    Korean
                                </option>
                                <option value="Portuguese (Brazil)" <?php selected($selected_language, 'Portuguese (Brazil)'); ?>>
                                    Portuguese (Brazil)
                                </option>
                                <option value="Portuguese (Portugal)" <?php selected($selected_language, 'Portuguese (Portugal)'); ?>>
                                    Portuguese (Portugal)
                                </option>
                                <option value="Italian" <?php selected($selected_language, 'Italian'); ?>>
                                    Italian
                                </option>
                                <option value="Russian" <?php selected($selected_language, 'Russian'); ?>>
                                    Russian
                                </option>
                                <option value="Thai" <?php selected($selected_language, 'Thai'); ?>>
                                    Thai
                                </option>
                                <option value="Indonesian" <?php selected($selected_language, 'Indonesian'); ?>>
                                    Indonesian
                                </option>
                                <option value="Dutch" <?php selected($selected_language, 'Dutch'); ?>>
                                    Dutch
                                </option>
                                <option value="Polish" <?php selected($selected_language, 'Polish'); ?>>
                                    Polish
                                </option>
                                <option value="Turkish" <?php selected($selected_language, 'Turkish'); ?>>
                                    Turkish
                                </option>
                                <option value="Arabic" <?php selected($selected_language, 'Arabic'); ?>>
                                    Arabic
                                </option>
                                <option value="Hindi" <?php selected($selected_language, 'Hindi'); ?>>
                                    Hindi
                                </option>
                            </select>
                            <p class="description">Select the language for AI-generated content. Default uses your site's configured language.</p>
                        </div>
                        
                        <div class="aiktp-form-group">
                            <label for="aiktpz_custom_prompt">Custom Prompt</label>
                            <textarea 
                                id="aiktpz_custom_prompt" 
                                name="aiktpz_custom_prompt" 
                                rows="5" 
                                placeholder="Customize your own prompt..."
                            ><?php echo esc_textarea(get_option('aiktpz_custom_prompt', '')); ?></textarea>
                            <p class="description">
                                Customize your own prompt, for example by adding company information, promotions, or warranty policies.
                            </p>
                        </div>
                        
                        <div class="aiktp-submit-wrapper">
                            <?php submit_button('Save Settings', 'primary', 'submit', false); ?>
                        </div>
                    </div>
                </form>
                <div class="aiktp-info-card">
                    <h3>WooCommerce AI - How to use</h3>
                    <ol>
                        <li>Connect your API key in the Connect tab</li>
                        <li>Configure content settings in WooCommerce AI tab</li>
                        <li>Go to WooCommerce product edit page</li>
                        <li>Open Bulk Actions dropdown and find "AI Content Generator" meta box in sidebar</li>
                        <li>Click "Generate Description" or "Generate Short Description" and wait for completion</li>
                    </ol>
                </div>
            </div>
           
        </div>
        <?php
    }
    
    /**
     * Register All Settings
     */
    public static function register_all_settings() {
        // Post Sync Settings
        // Note: aiktpz_token is NOT registered here because it should only be saved via AJAX
        // in the ajax_connect() method. If registered here, it would be overwritten with empty value
        // when other settings forms are submitted (since there's no input field for it in those forms).
        register_setting('aiktp_all_settings', 'aiktp_author', array(
            'type' => 'string',
            'sanitize_callback' => 'sanitize_text_field',
            'default' => '0'
        ));
        register_setting('aiktp_all_settings', 'aiktp_category', array(
            'type' => 'string',
            'sanitize_callback' => array('AIKTPZ_Settings', 'sanitize_categories'),
            'default' => ''
        ));
        
        // WooCommerce AI Content Settings
        // Note: aiktp_api_key is NOT registered here because it should only be saved via AJAX Connect button
        // to prevent it from being saved as empty when other settings forms are submitted
        register_setting('aiktp_all_settings', 'aiktpz_content_length', array(
            'type' => 'string',
            'sanitize_callback' => 'sanitize_text_field',
            'default' => 'medium'
        ));
        register_setting('aiktp_all_settings', 'aiktpz_content_tone', array(
            'type' => 'string',
            'sanitize_callback' => 'sanitize_text_field',
            'default' => 'friendly'
        ));
        register_setting('aiktp_all_settings', 'aiktpz_content_language', array(
            'type' => 'string',
            'sanitize_callback' => 'sanitize_text_field',
            'default' => ''
        ));
        register_setting('aiktp_all_settings', 'aiktpz_custom_prompt', array(
            'type' => 'string',
            'sanitize_callback' => 'wp_kses_post',
            'default' => ''
        ));
    }
    
    /**
     * Generate random token (10 characters: letters and numbers)
     */
    private static function generate_random_token() {
        $characters = 'abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789';
        $token = '';
        $length = 10;
        
        for ($i = 0; $i < $length; $i++) {
            $token .= $characters[wp_rand(0, strlen($characters) - 1)];
        }
        
        return $token;
    }
    
    /**
     * AJAX handler for Connect button
     */
    public function ajax_connect() {
            // Verify nonce
        $nonce = isset($_POST['nonce']) ? sanitize_text_field(wp_unslash($_POST['nonce'])) : '';
        if (empty($nonce) || !wp_verify_nonce($nonce, 'aiktp_connect')) {
            wp_send_json_error('Invalid nonce');
            return;
            }
            
            // Check permissions
            if (!current_user_can('manage_options')) {
            wp_send_json_error('Unauthorized');
            return;
        }
        
        // Get API key
        $api_key = isset($_POST['api_key']) ? sanitize_text_field( wp_unslash( $_POST['api_key'] ) ): '';
        
        if (empty($api_key)) {
            wp_send_json_error('API key is required');
            return;
        }
        
        // Get or generate wpToken using backward-compatible method
        $wp_token = self::get_token();
        if (empty($wp_token)) {
            $wp_token = self::generate_random_token();
            self::save_token($wp_token);
        }
        
        // Get WordPress URL
        $wp_url = home_url();
        
        // Call API
        $api_url = 'https://aiktp.com/api/ai.php';
        $response = wp_remote_post($api_url, array(
            'body' => json_encode(array(
                'task' => 'addWPSiteToAIKTP',
                'wpURL' => $wp_url,
                'wpToken' => $wp_token
            )),
            'headers' => array(
                'Content-Type' => 'application/json',
                'Authorization' => 'Bearer ' . $api_key
            ),
            'timeout' => 30
        ));
        
        if (is_wp_error($response)) {
            wp_send_json_error($response->get_error_message());
            return;
        }
        
        $body = wp_remote_retrieve_body($response);
        $data = json_decode($body, true);
        
        
        // Check if successful: data.data.status = 'success' and has data.data.siteId
        if ($response['response']['code'] == 200 && 
            isset($data['data']['status']) && 
            $data['data']['status'] === 'success' && 
            isset($data['data']['siteId'])) {
            
            // Save API key
            update_option('aiktp_api_key', $api_key);
            
            wp_send_json_success(array(
                'message' => 'Successfully connected to AIKTP',
                'api_key' => $api_key,
                'siteId' => $data['data']['siteId']
            ));
        } else {
            $error_message = 'Connection failed';
            if (isset($data['data']['message'])) {
                $error_message = $data['data']['message'];
            } elseif (isset($data['message'])) {
                $error_message = $data['message'];
            } elseif (isset($data['error'])) {
                $error_message = is_array($data['error']) ? ($data['error']['message'] ?? 'Unknown error') : $data['error'];
            }
            wp_send_json_error($error_message);
        }
    }
    
    /**
     * Sanitize categories (convert array to comma-separated string)
     */
   public static function sanitize_categories( $value ) {
        // Verify nonce
        if ( ! isset( $_POST['aiktp_nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['aiktp_nonce'] ) ), 'aiktp_action' ) ) {
            return '';
        }

        // Check if categories exist
        if ( ! isset( $_POST['aiktp_categories'] ) ) {
            return '';
        }

        // Sanitize array input
        $post_data = map_deep( wp_unslash( $_POST['aiktp_categories'] ), 'sanitize_text_field' );
        
        if ( is_array( $post_data ) ) {
            $cats = array_map( 'absint', $post_data );
            return implode( ',', $cats );
        }

        return '';
    }
    
    /**
     * Migrate old option names to new prefixes
     * Ensures backward compatibility for existing installations
     */
    public static function migrate_old_options() {
        // Migrate token (one-time migration from old name)
        $new_token = get_option('aiktpz_token');
        $old_token = get_option('chatgpt_aiktp_key');
        
        if (empty($new_token) && !empty($old_token)) {
            update_option('aiktpz_token', $old_token);
            // Delete old option after migration
            delete_option('chatgpt_aiktp_key');
        }
        
        // Migrate WooCommerce AI settings
        $old_to_new = array(
            'wcai_content_length' => 'aiktpz_content_length',
            'wcai_content_tone' => 'aiktpz_content_tone',
            'wcai_content_language' => 'aiktpz_content_language',
            'wcai_custom_prompt' => 'aiktpz_custom_prompt'
        );
        
        foreach ($old_to_new as $old => $new) {
            $new_value = get_option($new);
            $old_value = get_option($old);
            
            if (empty($new_value) && !empty($old_value)) {
                update_option($new, $old_value);
            }
        }
    }
    
    /**
     * Get token
     */
    public static function get_token() {
        $token = get_option('aiktpz_token');
        
        // One-time migration from old option name
        if (empty($token)) {
            $old_token = get_option('chatgpt_aiktp_key');
            
            if (!empty($old_token)) {
                update_option('aiktpz_token', $old_token);
                delete_option('chatgpt_aiktp_key');
                $token = $old_token;
            }
        }
        
        return $token;
    }
    
    /**
     * Save token
     */
    public static function save_token($token) {
        update_option('aiktpz_token', $token);
    }
    
    
    /**
     * Handle Settings Form Submission
     */
    public function handle_settings_form() {
        // Register all settings for options.php to handle
        self::register_all_settings();
    }
}

// Initialize
AIKTPZ_Settings::get_instance();