=== AIKTP ===
Contributors: aiktp
Tags: ai, content, seo, woocommerce, automation
Requires at least: 5.8
Tested up to: 6.9
Requires PHP: 7.0
Stable tag: 5.0.5
License: GPL v2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

AI-powered content automation. Generate SEO-optimized articles and WooCommerce product descriptions with bulk generation support.

== Description ==

AIKTP - Content SEO is a powerful AI-powered WordPress plugin that helps you automate content creation and optimize your website for SEO.

= Features =

**WooCommerce AI Content Generator**
* Generate product descriptions with AI
* Generate short descriptions
* Bulk generation for multiple products
* SEO optimization with RankMath/Yoast integration
* Auto-insert main keyword link
* Auto-add product images with SEO alt text
* Custom prompt support

**Post Sync from aiktp.com**
* REST API endpoints for post synchronization
* Auto-download and attach images
* Support for RankMath and Yoast SEO meta
* Custom author and category selection
* Token-based authentication

== Installation ==

1. Upload the plugin folder to `/wp-content/plugins/`
2. Activate the plugin through the 'Plugins' menu in WordPress
3. Go to AIKTP menu in admin to configure settings

== Frequently Asked Questions ==

= Do I need an API key? =

Yes, you need an API key from aiktp.com to use the AI content generation features.

= Is WooCommerce required? =

WooCommerce is only required if you want to use the product description generation features. The post sync features work without WooCommerce.

= Which SEO plugins are supported? =

The plugin supports both RankMath and Yoast SEO for automatic keyword optimization.

== External Services ==

This plugin relies on the AIKTP API service (https://aiktp.com) to provide AI-powered content generation functionality. This is a required external service for the plugin to function.

**What the service is used for:**
The AIKTP API is used to generate AI-powered content including:
* WooCommerce product descriptions (short and long)
* SEO-optimized article content
* Post synchronization from aiktp.com to your WordPress site

**What data is sent and when:**
The following data is transmitted to https://aiktp.com/api/ai.php when you use the plugin's features:
* Your API key (for authentication)
* Product information (title, categories, attributes) when generating WooCommerce product descriptions
* Custom prompts and content parameters you configure
* Your WordPress site URL and token when setting up post synchronization
* Content generation requests initiated by you through the plugin interface

Data is only sent when you actively use the plugin's content generation features or configure synchronization settings. No data is transmitted automatically or in the background without your action.

**Service provider information:**
* Service: AIKTP API
* Provider: aiktp.com
* Terms of Service: https://aiktp.com/terms
* Privacy Policy: https://aiktp.com/privacy-policy

== Screenshots ==

1. AIKTP Settings Page
2. Product Description Generator
3. Bulk Generation Interface

== Changelog ==

= 5.0.05 =
* SECURITY FIX: Fixed authorization vulnerability (CVE-2026-1103) in /getToken endpoint
* Changed permission callback from verify_user_logged_in to verify_admin_capability
* Now requires manage_options capability to retrieve sync token
* Prevents subscribers and low-privilege users from accessing admin token
* Comprehensive security review and WordPress coding standards compliance

= 5.0.04 =
* Improved REST API authentication and authorization
* Enhanced token-based security for external integrations

= 4.0.3 =
* Added GPL v2 license
* Created languages folder for translations
* Improved WordPress coding standards compliance

= 4.0.0 =
* Merged WooCommerce AI Content Generator with Post Sync
* Modular structure with includes/
* Added custom prompt support
* Added main keyword internal linking
* Added product image insertion with SEO alt text
* Improved SEO plugin integration

= 3.0.5 =
* Post sync features
* REST API endpoints

= 1.0.0 =
* Initial WooCommerce AI Content Generator

== Upgrade Notice ==

= 4.0.3 =
This version improves WordPress coding standards compliance and adds proper licensing information.

== Support ==

For support, please visit https://aiktp.com or email support@aiktp.com
