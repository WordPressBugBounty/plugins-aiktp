<?php
/**
 * AIKTP Helper Functions
 * Common utility functions used across the plugin
 */

if (!defined('ABSPATH')) {
    exit;
}

class AIKTPZ_Helpers {
    
    /**
     * Sanitize title for URL slug
     */
    public static function sanitize_slug($title) {
        $replacement = '-';
        $map = array();
        $quoted_replacement = preg_quote($replacement, '/');
        $default = array(
            '/à|á|ạ|ả|ã|â|ầ|ấ|ậ|ẩ|ẫ|ă|ằ|ắ|ặ|ẳ|ẵ|À|Á|Ạ|Ả|Ã|Â|Ầ|Ấ|Ậ|Ẩ|Ẫ|Ă|Ằ|Ắ|Ặ|Ẳ|Ẵ|å/' => 'a',
            '/è|é|ẹ|ẻ|ẽ|ê|ề|ế|ệ|ể|ễ|È|É|Ẹ|Ẻ|Ẽ|Ê|Ề|Ế|Ệ|Ể|Ễ|ë/' => 'e',
            '/ì|í|ị|ỉ|ĩ|Ì|Í|Ị|Ỉ|Ĩ|î/' => 'i',
            '/ò|ó|ọ|ỏ|õ|ô|ồ|ố|ộ|ổ|ỗ|ơ|ờ|ớ|ợ|ở|ỡ|Ò|Ó|Ọ|Ỏ|Õ|Ô|Ồ|Ố|Ộ|Ổ|Ỗ|Ơ|Ờ|Ớ|Ợ|Ở|Ỡ|ø/' => 'o',
            '/ù|ú|ụ|ủ|ũ|ư|ừ|ứ|ự|ử|ữ|Ù|Ú|Ụ|Ủ|Ũ|Ư|Ừ|Ứ|Ự|Ử|Ữ|ů|û/' => 'u',
            '/ỳ|ý|ỵ|ỷ|ỹ|Ỳ|Ý|Ỵ|Ỷ|Ỹ/' => 'y',
            '/đ|Đ/' => 'd',
            '/ç/' => 'c',
            '/ñ/' => 'n',
            '/ä|æ/' => 'ae',
            '/ö/' => 'oe',
            '/ü/' => 'ue',
            '/Ä/' => 'Ae',
            '/Ü/' => 'Ue',
            '/Ö/' => 'Oe',
            '/ß/' => 'ss',
            '/[^\s\p{Ll}\p{Lm}\p{Lo}\p{Lt}\p{Lu}\p{Nd}]/mu' => ' ',
            '/\\s+/' => $replacement,
            sprintf('/^[%s]+|[%s]+$/', $quoted_replacement, $quoted_replacement) => '',
        );
        
        $title = urldecode($title);
        $map = array_merge($map, $default);
        return strtolower(preg_replace(array_keys($map), array_values($map), $title));
    }
    
    /**
     * Download remote image
     */
    public static function download_remote_image($img_url, $post_title) {
        $image_name = basename($img_url);
        $filetype = wp_check_filetype($image_name);
        $upload_dir = wp_upload_dir();
        $extension = $filetype['ext'] ? $filetype['ext'] : 'jpg';
        
        if (empty($extension)) {
            $extension = 'jpg';
        }
        
        $unique_file_name = self::sanitize_slug($post_title) . '-' . uniqid() . '.' . $extension;
        $filename = $upload_dir['path'] . '/' . $unique_file_name;
        $baseurl = $upload_dir['baseurl'] . $upload_dir['subdir'] . '/' . $unique_file_name;
        
        // Use WordPress HTTP API
        $response = wp_safe_remote_get($img_url, array(
            'timeout' => 30,
            'redirection' => 5,
            'sslverify' => false,
        ));
        
        if (is_wp_error($response)) {
            return null;
        }
        
        $image_content = wp_remote_retrieve_body($response);
        $response_code = wp_remote_retrieve_response_code($response);
        
        if ($response_code == 200 && !empty($image_content)) {
            // Initialize WP_Filesystem
            global $wp_filesystem;
            if (empty($wp_filesystem)) {
                require_once(ABSPATH . '/wp-admin/includes/file.php');
                WP_Filesystem();
            }
            
            $saved = $wp_filesystem->put_contents($filename, $image_content, FS_CHMOD_FILE);
            
            if ($saved !== false && $wp_filesystem->size($filename) > 100) {
                return array(
                    'url' => $img_url,
                    'file_name' => $unique_file_name,
                    'path' => $filename,
                    'baseurl' => $baseurl
                );
            }
        }
        
        return null;
    }
    
    /**
     * Save base64 image
     */
    public static function save_base64_to_image($base64_data, $post_title) {
        $upload_dir = wp_upload_dir();
        $unique_file_name = self::sanitize_slug($post_title) . '-' . uniqid() . '.webp';
        $filename = $upload_dir['path'] . '/' . $unique_file_name;
        $baseurl = $upload_dir['baseurl'] . $upload_dir['subdir'] . '/' . $unique_file_name;
        
        // Initialize WP_Filesystem
        global $wp_filesystem;
        if (empty($wp_filesystem)) {
            require_once(ABSPATH . '/wp-admin/includes/file.php');
            WP_Filesystem();
        }
        
        $data = explode(',', $base64_data);
        $decoded_data = base64_decode($data[1]);
        $wp_filesystem->put_contents($filename, $decoded_data, FS_CHMOD_FILE);
        
        return array(
            'file_name' => $unique_file_name,
            'path' => $filename,
            'baseurl' => $baseurl
        );
    }
    
    /**
     * Attach image to post
     */
    public static function attach_image_to_post($image_data, $post_id) {
        require_once(ABSPATH . 'wp-admin/includes/image.php');
        
        $filename = $image_data['path'];
        $wp_filetype = wp_check_filetype($filename, null);
        
        $attachment = array(
            'post_mime_type' => $wp_filetype['type'],
            'post_title' => sanitize_file_name($image_data['file_name']),
            'post_content' => '',
            'post_author' => 1,
            'post_status' => 'inherit'
        );
        
        $attachment_id = wp_insert_attachment($attachment, $filename, $post_id);
        $attach_data = wp_generate_attachment_metadata($attachment_id, $filename);
        wp_update_attachment_metadata($attachment_id, $attach_data);
        
        return $attachment_id;
    }
    
    /**
     * Extract images from HTML
     */
    public static function extract_images($html) {
        $html = stripslashes($html);
        preg_match_all('/<img[^>]+src\s*=\s*["\']([^"\']+)["\']/i', $html, $matches);
        return $matches[1];
    }
}