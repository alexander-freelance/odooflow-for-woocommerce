<?php
/**
 * Plugin Name: OdooFlow for WooCommerce
 * Plugin URI: https://odooflow.com
 * Description: The most comprehensive solution for syncing between WooCommerce and Odoo
 * Version: 1.0.3
 * Author: OdooFlow
 * Author URI: https://odooflow.com
 * Text Domain: odooflow
 * Domain Path: /languages
 * Requires at least: 5.8
 * Requires PHP: 7.4
 * WC requires at least: 5.0
 * WC tested up to: 8.5
 *
 * @package OdooFlow
 */

// Exit if accessed directly
if (!defined('ABSPATH')) {
    exit;
}

// Require Composer autoload (for Polyfill-XMLRPC)
if (file_exists(__DIR__ . '/vendor/autoload.php')) {
    require_once __DIR__ . '/vendor/autoload.php';
}

// Define plugin constants
define('ODOOFLOW_VERSION', '1.0.2');
define('ODOOFLOW_PLUGIN_DIR', plugin_dir_path(__FILE__));
define('ODOOFLOW_PLUGIN_URL', plugin_dir_url(__FILE__));

/**
 * Main OdooFlow Class
 */
class OdooFlow {
    /**
     * @var OdooFlow The single instance of the class
     */
    protected static $_instance = null;

    /**
     * Main OdooFlow Instance
     * 
     * Ensures only one instance of OdooFlow is loaded or can be loaded.
     */
    public static function instance() {
        if (is_null(self::$_instance)) {
            self::$_instance = new self();
        }
        return self::$_instance;
    }

    /**
     * OdooFlow Constructor.
     */
    public function __construct() {
        $this->init_hooks();
    }

    /**
     * Initialize hooks
     */
    private function init_hooks() {
        add_action('admin_init', array($this, 'check_woocommerce_dependency'));
        add_action('admin_menu', array($this, 'add_admin_menu'));
        add_filter('plugin_action_links_' . plugin_basename(__FILE__), array($this, 'add_settings_link'));
        add_action('plugins_loaded', array($this, 'init_plugin'));
    }

    /**
     * Check WooCommerce Dependency
     */
    public function check_woocommerce_dependency() {
        if (!class_exists('WooCommerce')) {
            add_action('admin_notices', array($this, 'woocommerce_missing_notice'));
        }
    }

    /**
     * WooCommerce missing notice
     */
    public function woocommerce_missing_notice() {
        ?>
        <div class="error">
            <p><?php _e('OdooFlow requires WooCommerce to be installed and active.', 'odooflow'); ?></p>
        </div>
        <?php
    }

    /**
     * Add settings link to plugin list
     */
    public function add_settings_link($links) {
        $settings_link = '<a href="admin.php?page=odooflow-settings">' . __('Settings', 'odooflow') . '</a>';
        array_unshift($links, $settings_link);
        return $links;
    }

    /**
     * Add admin menu items
     */
    public function add_admin_menu() {
        add_menu_page(
            __('OdooFlow', 'odooflow'),
            __('OdooFlow', 'odooflow'),
            'manage_options',
            'odooflow-settings',
            array($this, 'settings_page'),
            'dashicons-randomize',
            56
        );
    }

    /**
     * Initialize plugin
     */
    public function init_plugin() {
        load_plugin_textdomain('odooflow', false, dirname(plugin_basename(__FILE__)) . '/languages');
    }

    /**
     * Settings page
     */
    public function settings_page() {
        if (!current_user_can('manage_options')) {
            return;
        }

        if (isset($_POST['odooflow_settings_submit'])) {
            check_admin_referer('odooflow_settings_nonce');

            $odoo_url = isset($_POST['odoo_instance_url']) ? sanitize_text_field($_POST['odoo_instance_url']) : '';
            $username = isset($_POST['odoo_username']) ? sanitize_text_field($_POST['odoo_username']) : '';
            $api_key = isset($_POST['odoo_api_key']) ? sanitize_text_field($_POST['odoo_api_key']) : '';

            update_option('odooflow_odoo_url', $odoo_url);
            update_option('odooflow_username', $username);
            update_option('odooflow_api_key', $api_key);

            add_settings_error('odooflow_messages', 'odooflow_message', __('Settings Saved', 'odooflow'), 'updated');
        }

        $xmlrpc_status = isset($_POST['check_xmlrpc_status']) ? $this->check_xmlrpc_status() : '';
        $odoo_version_info = isset($_POST['check_odoo_version']) ? $this->get_odoo_version(get_option('odooflow_odoo_url', '')) : '';

        ?>
        <div class="wrap">
            <h1><?php echo esc_html(get_admin_page_title()); ?></h1>
            <?php settings_errors('odooflow_messages'); ?>

            <form method="post" action="">
                <?php wp_nonce_field('odooflow_settings_nonce'); ?>
                <table class="form-table">
                    <tr>
                        <th><label for="odoo_instance_url"><?php _e('Odoo Instance URL', 'odooflow'); ?></label></th>
                        <td><input type="url" name="odoo_instance_url" id="odoo_instance_url" value="<?php echo esc_attr(get_option('odooflow_odoo_url', '')); ?>" class="regular-text"></td>
                    </tr>
                    <tr>
                        <th><label for="odoo_username"><?php _e('Username', 'odooflow'); ?></label></th>
                        <td><input type="text" name="odoo_username" id="odoo_username" value="<?php echo esc_attr(get_option('odooflow_username', '')); ?>" class="regular-text"></td>
                    </tr>
                    <tr>
                        <th><label for="odoo_api_key"><?php _e('API Key', 'odooflow'); ?></label></th>
                        <td><input type="password" name="odoo_api_key" id="odoo_api_key" value="<?php echo esc_attr(get_option('odooflow_api_key', '')); ?>" class="regular-text"></td>
                    </tr>
                </table>

                <?php submit_button(__('Save Settings', 'odooflow'), 'primary', 'odooflow_settings_submit'); ?>
                <input type="submit" name="check_xmlrpc_status" class="button-secondary" value="<?php _e('Check XML-RPC Status', 'odooflow'); ?>">
                <input type="submit" name="check_odoo_version" class="button-secondary" value="<?php _e('Check Odoo Version', 'odooflow'); ?>">
            </form>

            <?php if ($xmlrpc_status): ?>
                <div class="notice notice-info"><p><?php echo esc_html($xmlrpc_status); ?></p></div>
            <?php endif; ?>

            <?php if ($odoo_version_info): ?>
                <div class="notice notice-info"><p><?php echo esc_html($odoo_version_info); ?></p></div>
            <?php endif; ?>
        </div>
        <?php
    }

    /**
     * Get Odoo version using XML-RPC
     */
    private function get_odoo_version($odoo_url) {
        if (empty($odoo_url)) {
            return __('Odoo URL is not set.', 'odooflow');
        }

        if (!function_exists('xmlrpc_encode_request')) {
            return __('XML-RPC polyfill is missing. Run `composer require phpxmlrpc/polyfill-xmlrpc`.', 'odooflow');
        }

        $request = xmlrpc_encode_request('version', []);
        $response = wp_remote_post(rtrim($odoo_url, '/') . '/xmlrpc/2/common', [
            'body' => $request,
            'headers' => ['Content-Type' => 'text/xml'],
        ]);

        if (is_wp_error($response)) {
            return __('Error fetching Odoo version.', 'odooflow');
        }

        $body = wp_remote_retrieve_body($response);
        if (empty($body)) {
            return __('Empty response from Odoo server.', 'odooflow');
        }

        // Parse the XML response
        $xml = simplexml_load_string($body);
        if ($xml === false) {
            return __('Error parsing XML response.', 'odooflow');
        }

        // Convert SimpleXMLElement to JSON and then to an array
        $response_array = json_decode(json_encode($xml), true);

        // Extract server version
        $server_version = $response_array['params']['param']['value']['struct']['member'][0]['value']['string'] ?? 'Unknown';

        return sprintf(__('Odoo version: %s', 'odooflow'), $server_version);
    }

    private function check_xmlrpc_status() {
        $response = wp_remote_get(site_url('/xmlrpc.php'));
        if (is_wp_error($response)) {
            return __('Error checking XML-RPC status.', 'odooflow');
        }

        $body = wp_remote_retrieve_body($response);
        if (strpos($body, 'XML-RPC server accepts POST requests only.') !== false) {
            return __('XML-RPC is enabled.', 'odooflow');
        } else {
            return __('XML-RPC is disabled.', 'odooflow');
        }
    }
}

// Initialize the plugin
function OdooFlow() {
    return OdooFlow::instance();
}
$GLOBALS['odooflow'] = OdooFlow();