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
        add_action('admin_enqueue_scripts', array($this, 'enqueue_admin_scripts'));
        add_action('wp_ajax_refresh_odoo_databases', array($this, 'ajax_refresh_odoo_databases'));
        add_action('wp_ajax_list_odoo_modules', array($this, 'ajax_list_odoo_modules'));
        add_action('wp_ajax_get_odoo_products', array($this, 'ajax_get_odoo_products'));
        add_action('wp_ajax_import_selected_products', array($this, 'ajax_import_selected_products'));
        add_action('manage_posts_extra_tablenav', array($this, 'add_odoo_count_button'), 20);
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
     * Enqueue admin scripts
     */
    public function enqueue_admin_scripts($hook) {
        if ('toplevel_page_odooflow-settings' !== $hook && 'edit.php' !== $hook) {
            return;
        }

        // Only load on products page
        if ('edit.php' === $hook && (!isset($_GET['post_type']) || $_GET['post_type'] !== 'product')) {
            return;
        }

        wp_enqueue_style('odooflow-admin', ODOOFLOW_PLUGIN_URL . 'assets/css/admin.css', array(), ODOOFLOW_VERSION);
        wp_enqueue_script('odooflow-admin', ODOOFLOW_PLUGIN_URL . 'assets/js/admin.js', array('jquery'), ODOOFLOW_VERSION, true);
        
        // Use a single nonce for all Odoo-related AJAX actions
        wp_localize_script('odooflow-admin', 'odooflow', array(
            'ajax_url' => admin_url('admin-ajax.php'),
            'nonce' => wp_create_nonce('odooflow_ajax_nonce'),
        ));
    }

    /**
     * AJAX handler for refreshing Odoo databases
     */
    public function ajax_refresh_odoo_databases() {
        // Use consistent nonce verification
        if (!check_ajax_referer('odooflow_ajax_nonce', 'nonce', false)) {
            wp_send_json_error(array('message' => __('Security check failed. Please refresh the page and try again.', 'odooflow')));
            return;
        }

        $odoo_url = get_option('odooflow_odoo_url', '');
        $username = get_option('odooflow_username', '');
        $api_key = get_option('odooflow_api_key', '');
        $current_db = get_option('odooflow_database', '');

        error_log('OdooFlow: Attempting to refresh databases from ' . $odoo_url);

        $databases = $this->get_odoo_databases($odoo_url, $username, $api_key);
        
        if (is_string($databases)) {
            error_log('OdooFlow: Database refresh failed - ' . $databases);
            wp_send_json_error(array('message' => $databases));
        } else {
            $html = '<select name="odoo_database" id="odoo_database" class="regular-text">';
            foreach ($databases as $db) {
                $selected = ($db === $current_db) ? 'selected' : '';
                $html .= sprintf('<option value="%s" %s>%s</option>', 
                    esc_attr($db),
                    $selected,
                    esc_html($db)
                );
            }
            $html .= '</select>';
            wp_send_json_success(array('html' => $html));
        }
    }

    /**
     * AJAX handler for listing Odoo modules
     */
    public function ajax_list_odoo_modules() {
        // Use consistent nonce verification
        if (!check_ajax_referer('odooflow_ajax_nonce', 'nonce', false)) {
            wp_send_json_error(array('message' => __('Security check failed. Please refresh the page and try again.', 'odooflow')));
            return;
        }

        $odoo_url = get_option('odooflow_odoo_url', '');
        $username = get_option('odooflow_username', '');
        $api_key = get_option('odooflow_api_key', '');
        $database = get_option('odooflow_database', '');

        error_log('OdooFlow: Attempting to list modules from ' . $odoo_url);

        if (empty($odoo_url) || empty($username) || empty($api_key) || empty($database)) {
            wp_send_json_error(array('message' => __('Please configure all Odoo connection settings first.', 'odooflow')));
            return;
        }

        // First authenticate to get the user ID
        $auth_request = xmlrpc_encode_request('authenticate', array(
            $database,
            $username,
            $api_key,
            array()
        ));

        $auth_response = wp_remote_post(rtrim($odoo_url, '/') . '/xmlrpc/2/common', [
            'body' => $auth_request,
            'headers' => ['Content-Type' => 'text/xml'],
            'timeout' => 30,
            'sslverify' => false
        ]);

        if (is_wp_error($auth_response)) {
            wp_send_json_error(array('message' => __('Error connecting to Odoo server.', 'odooflow')));
            return;
        }

        $auth_body = wp_remote_retrieve_body($auth_response);
        $auth_xml = simplexml_load_string($auth_body);
        if ($auth_xml === false) {
            wp_send_json_error(array('message' => __('Error parsing authentication response.', 'odooflow')));
            return;
        }

        $auth_data = json_decode(json_encode($auth_xml), true);
        if (isset($auth_data['fault'])) {
            wp_send_json_error(array('message' => __('Authentication failed. Please check your credentials.', 'odooflow')));
            return;
        }

        $uid = $auth_data['params']['param']['value']['int'] ?? null;
        if (!$uid) {
            wp_send_json_error(array('message' => __('Could not get user ID from authentication response.', 'odooflow')));
            return;
        }

        // Now get the list of installed modules
        $modules_request = xmlrpc_encode_request('execute_kw', array(
            $database,
            $uid,
            $api_key,
            'ir.module.module',
            'search_read',
            array(
                array(
                    array('state', '=', 'installed')
                )
            ),
            array(
                'fields' => array('name', 'shortdesc', 'state', 'installed_version'),
                'order' => 'name ASC'
            )
        ));

        $modules_response = wp_remote_post(rtrim($odoo_url, '/') . '/xmlrpc/2/object', [
            'body' => $modules_request,
            'headers' => ['Content-Type' => 'text/xml'],
            'timeout' => 30,
            'sslverify' => false
        ]);

        if (is_wp_error($modules_response)) {
            wp_send_json_error(array('message' => __('Error fetching modules.', 'odooflow')));
            return;
        }

        $modules_body = wp_remote_retrieve_body($modules_response);
        $modules_xml = simplexml_load_string($modules_body);
        if ($modules_xml === false) {
            wp_send_json_error(array('message' => __('Error parsing modules response.', 'odooflow')));
            return;
        }

        $modules_data = json_decode(json_encode($modules_xml), true);
        if (isset($modules_data['fault'])) {
            wp_send_json_error(array('message' => __('Error retrieving modules: ', 'odooflow') . $modules_data['fault']['value']['struct']['member'][1]['value']['string']));
            return;
        }

        // Build the HTML table
        $html = '<table class="wp-list-table widefat fixed striped">';
        $html .= '<thead><tr>';
        $html .= '<th>' . __('Module Name', 'odooflow') . '</th>';
        $html .= '<th>' . __('Description', 'odooflow') . '</th>';
        $html .= '<th>' . __('Version', 'odooflow') . '</th>';
        $html .= '<th>' . __('Status', 'odooflow') . '</th>';
        $html .= '</tr></thead><tbody>';

        $modules = $modules_data['params']['param']['value']['array']['data']['value'] ?? array();
        
        if (empty($modules)) {
            $html .= '<tr><td colspan="4">' . __('No modules found.', 'odooflow') . '</td></tr>';
        } else {
            foreach ($modules as $module) {
                $module_struct = $module['struct']['member'];
                $module_data = array();
                
                // Convert the module data structure to a more usable format
                foreach ($module_struct as $member) {
                    $name = $member['name'];
                    $value = current($member['value']);
                    $module_data[$name] = $value;
                }

                $html .= '<tr>';
                $html .= '<td>' . esc_html($module_data['name']) . '</td>';
                $html .= '<td>' . esc_html($module_data['shortdesc']) . '</td>';
                $html .= '<td>' . esc_html($module_data['installed_version']) . '</td>';
                $html .= '<td>' . esc_html($module_data['state']) . '</td>';
                $html .= '</tr>';
            }
        }

        $html .= '</tbody></table>';

        wp_send_json_success(array('html' => $html));
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
            $database = isset($_POST['odoo_database']) ? sanitize_text_field($_POST['odoo_database']) : '';
            $manual_db = isset($_POST['manual_db']) ? true : false;

            $has_errors = false;

            // Validate API Key length
            if (strlen($api_key) < 20) {
                add_settings_error(
                    'odooflow_messages',
                    'odooflow_api_key_error',
                    __('API Key must be at least 20 characters long.', 'odooflow'),
                    'error'
                );
                $has_errors = true;
            }

            // Validate Odoo Instance URL format
            $url_pattern = '/^https:\/\/[a-zA-Z0-9\-\.]+\.[a-zA-Z]{2,}\/$/';
            if (!preg_match($url_pattern, $odoo_url)) {
                add_settings_error(
                    'odooflow_messages',
                    'odooflow_url_error',
                    __('Odoo Instance URL must start with https:// and end with a forward slash (/).', 'odooflow'),
                    'error'
                );
                $has_errors = true;
            }

            // Only save if there are no validation errors
            if (!$has_errors) {
                update_option('odooflow_odoo_url', $odoo_url);
                update_option('odooflow_username', $username);
                update_option('odooflow_api_key', $api_key);
                update_option('odooflow_database', $database);
                update_option('odooflow_manual_db', $manual_db);

                add_settings_error('odooflow_messages', 'odooflow_message', __('Settings Saved', 'odooflow'), 'updated');
            }
        }

        $xmlrpc_status = isset($_POST['check_xmlrpc_status']) ? $this->check_xmlrpc_status() : '';
        $odoo_version_info = isset($_POST['check_odoo_version']) ? $this->get_odoo_version(get_option('odooflow_odoo_url', '')) : '';

        $odoo_url = get_option('odooflow_odoo_url', '');
        $username = get_option('odooflow_username', '');
        $api_key = get_option('odooflow_api_key', '');

        ?>
        <div class="wrap">
            <h1><?php echo esc_html(get_admin_page_title()); ?></h1>
            <?php settings_errors('odooflow_messages'); ?>

            <div class="odoo-settings-wrapper">
                <h2><?php _e('Connection Settings', 'odooflow'); ?></h2>
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
                        <tr>
                            <th><label for="odoo_database"><?php _e('Select Database', 'odooflow'); ?></label></th>
                            <td>
                                <div class="odoo-database-wrapper">
                                    <div class="database-select-container">
                                        <?php
                                        $databases = $this->get_odoo_databases($odoo_url, $username, $api_key);
                                        $current_db = get_option('odooflow_database', '');
                                        $is_manual = get_option('odooflow_manual_db', false);
                                        
                                        if ($is_manual) {
                                            echo '<input type="text" name="odoo_database" id="odoo_database" value="' . esc_attr($current_db) . '" class="regular-text manual-db-input">';
                                        } else {
                                            if (is_string($databases)) {
                                                echo '<div class="notice notice-error"><p>' . esc_html($databases) . '</p></div>';
                                            } else {
                                                echo '<select name="odoo_database" id="odoo_database" class="regular-text">';
                                                echo '<option value="">' . __('Select a database', 'odooflow') . '</option>';
                                                foreach ($databases as $db) {
                                                    $selected = ($db === $current_db) ? 'selected' : '';
                                                    echo sprintf('<option value="%s" %s>%s</option>', 
                                                        esc_attr($db),
                                                        $selected,
                                                        esc_html($db)
                                                    );
                                                }
                                                echo '</select>';
                                            }
                                        }
                                        ?>
                                    </div>
                                    <div class="database-controls">
                                        <label class="manual-db-toggle">
                                            <input type="checkbox" name="manual_db" id="manual_db" <?php checked($is_manual); ?>>
                                            <?php _e('Add DB name manually', 'odooflow'); ?>
                                        </label>
                                        <button type="button" class="button-secondary refresh-databases" <?php echo $is_manual ? 'style="display: none;"' : ''; ?>>
                                            <?php _e('Refresh Databases', 'odooflow'); ?>
                                        </button>
                                    </div>
                                </div>
                            </td>
                        </tr>
                    </table>

                    <div class="odoo-settings-actions">
                        <?php submit_button(__('Save Settings', 'odooflow'), 'primary', 'odooflow_settings_submit', false); ?>
                        <input type="submit" name="check_xmlrpc_status" class="button-secondary" value="<?php _e('Check XML-RPC Status', 'odooflow'); ?>">
                        <input type="submit" name="check_odoo_version" class="button-secondary" value="<?php _e('Check Odoo Version', 'odooflow'); ?>">
                        <button type="button" class="button-secondary list-modules">
                            <?php _e('List Modules', 'odooflow'); ?>
                        </button>
                    </div>
                </form>

                <?php if ($xmlrpc_status): ?>
                    <div class="notice notice-info"><p><?php echo esc_html($xmlrpc_status); ?></p></div>
                <?php endif; ?>

                <?php if ($odoo_version_info): ?>
                    <div class="notice notice-info"><p><?php echo esc_html($odoo_version_info); ?></p></div>
                <?php endif; ?>
            </div>

            <div id="odoo-modules-list" class="odoo-modules-wrapper" style="margin-top: 20px;">
                <h2><?php _e('Installed Modules', 'odooflow'); ?></h2>
                <div class="modules-content">
                    <!-- Modules will be loaded here -->
                </div>
            </div>
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

    private function get_odoo_databases($odoo_url, $username, $api_key) {
        if (empty($odoo_url)) {
            error_log('OdooFlow: Missing Odoo URL');
            return __('Odoo URL is not set.', 'odooflow');
        }

        if (!function_exists('xmlrpc_encode_request')) {
            error_log('OdooFlow: XML-RPC polyfill is missing');
            return __('XML-RPC polyfill is missing. Run `composer require phpxmlrpc/polyfill-xmlrpc`.', 'odooflow');
        }

        // Clean up the Odoo URL
        $odoo_url = rtrim($odoo_url, '/');
        if (!preg_match('/^https?:\/\//', $odoo_url)) {
            $odoo_url = 'https://' . $odoo_url;
        }

        error_log('OdooFlow: Connecting to Odoo server at ' . $odoo_url);

        // For Odoo SaaS, we need to authenticate first and then get the database name
        if (strpos($odoo_url, 'odoo.com') !== false) {
            error_log('OdooFlow: Detected Odoo SaaS instance');
            
            // For SaaS instances, the database name is part of the subdomain
            $parsed_url = parse_url($odoo_url);
            $host = $parsed_url['host'];
            $subdomain_parts = explode('.', $host);
            
            if (count($subdomain_parts) >= 3) {
                $database = $subdomain_parts[0];
                error_log('OdooFlow: Found SaaS database - ' . $database);
                return array($database);
            }
        }

        // Try the server info method first
        $request = xmlrpc_encode_request('server_version', array());
        error_log('OdooFlow: Testing server connection...');
        
        $response = wp_remote_post($odoo_url . '/xmlrpc/2/db', [
            'body' => $request,
            'headers' => [
                'Content-Type' => 'text/xml',
            ],
            'timeout' => 30,
            'sslverify' => true // Enable SSL verification for security
        ]);

        if (is_wp_error($response)) {
            error_log('OdooFlow: Server connection error - ' . $response->get_error_message());
            
            // Try again with SSL verification disabled (for development/testing only)
            $response = wp_remote_post($odoo_url . '/xmlrpc/2/db', [
                'body' => $request,
                'headers' => [
                    'Content-Type' => 'text/xml',
                ],
                'timeout' => 30,
                'sslverify' => false
            ]);
            
            if (is_wp_error($response)) {
                error_log('OdooFlow: Server connection failed even with SSL verification disabled');
                return __('Error connecting to Odoo server. Please check the URL and ensure the server is accessible.', 'odooflow');
            }
        }

        $body = wp_remote_retrieve_body($response);
        error_log('OdooFlow: Server response - ' . $body);

        if (empty($body)) {
            error_log('OdooFlow: Empty response from server');
            return __('Empty response from Odoo server.', 'odooflow');
        }

        // For Odoo instances with authentication required
        if (!empty($username) && !empty($api_key)) {
            error_log('OdooFlow: Attempting authentication with provided credentials');
            
            $auth_request = xmlrpc_encode_request('authenticate', array(
                $username,  // Try username as database
                $username,
                $api_key,
                array()
            ));

            $auth_response = wp_remote_post($odoo_url . '/xmlrpc/2/common', [
                'body' => $auth_request,
                'headers' => [
                    'Content-Type' => 'text/xml',
                ],
                'timeout' => 30,
                'sslverify' => false
            ]);

            if (!is_wp_error($auth_response)) {
                $auth_body = wp_remote_retrieve_body($auth_response);
                error_log('OdooFlow: Authentication response - ' . $auth_body);
                
                if (strpos($auth_body, 'fault') === false) {
                    error_log('OdooFlow: Authentication successful, using username as database');
                    return array($username);
                } else {
                    error_log('OdooFlow: Authentication failed with username as database');
                }
            }
        }

        // If we have a saved database, use it as a fallback
        $saved_db = get_option('odooflow_database', '');
        if (!empty($saved_db)) {
            error_log('OdooFlow: Using saved database - ' . $saved_db);
            return array($saved_db);
        }

        error_log('OdooFlow: Could not determine database name');
        return array();
    }

    /**
     * Add button to products page
     */
    public function add_odoo_count_button($which) {
        global $typenow;
        
        // Only add to top of products page
        if ($typenow !== 'product' || $which !== 'top') {
            return;
        }

        ?>
        <div class="alignleft actions">
            <button type="button" class="button-secondary get-odoo-products">
                <?php _e('Get Odoo Products', 'odooflow'); ?>
            </button>
        </div>

        <!-- Modal for product selection -->
        <div id="odoo-products-modal" class="odoo-modal" style="display: none;">
            <div class="odoo-modal-content">
                <div class="odoo-modal-header">
                    <h2><?php _e('Select Products to Import', 'odooflow'); ?></h2>
                    <span class="odoo-modal-close">&times;</span>
                </div>
                <div class="odoo-modal-body">
                    <!-- Field selection section -->
                    <div class="field-selection-section">
                        <h3><?php _e('Select Fields to Import', 'odooflow'); ?></h3>
                        <div class="field-selection-grid">
                            <label class="field-checkbox">
                                <input type="checkbox" name="import_fields[]" value="name" checked disabled>
                                <span class="checkbox-custom"></span>
                                <span class="field-label"><?php _e('Product Name', 'odooflow'); ?></span>
                                <span class="field-required"><?php _e('(Required)', 'odooflow'); ?></span>
                            </label>
                            <label class="field-checkbox">
                                <input type="checkbox" name="import_fields[]" value="default_code" checked disabled>
                                <span class="checkbox-custom"></span>
                                <span class="field-label"><?php _e('SKU', 'odooflow'); ?></span>
                                <span class="field-required"><?php _e('(Required)', 'odooflow'); ?></span>
                            </label>
                            <label class="field-checkbox">
                                <input type="checkbox" name="import_fields[]" value="list_price" checked>
                                <span class="checkbox-custom"></span>
                                <span class="field-label"><?php _e('Price', 'odooflow'); ?></span>
                            </label>
                            <label class="field-checkbox">
                                <input type="checkbox" name="import_fields[]" value="description">
                                <span class="checkbox-custom"></span>
                                <span class="field-label"><?php _e('Description', 'odooflow'); ?></span>
                            </label>
                            <label class="field-checkbox">
                                <input type="checkbox" name="import_fields[]" value="image">
                                <span class="checkbox-custom"></span>
                                <span class="field-label"><?php _e('Product Image', 'odooflow'); ?></span>
                            </label>
                            <label class="field-checkbox">
                                <input type="checkbox" name="import_fields[]" value="qty_available">
                                <span class="checkbox-custom"></span>
                                <span class="field-label"><?php _e('Stock Quantity', 'odooflow'); ?></span>
                            </label>
                            <label class="field-checkbox">
                                <input type="checkbox" name="import_fields[]" value="weight">
                                <span class="checkbox-custom"></span>
                                <span class="field-label"><?php _e('Weight', 'odooflow'); ?></span>
                            </label>
                            <label class="field-checkbox">
                                <input type="checkbox" name="import_fields[]" value="categ_id">
                                <span class="checkbox-custom"></span>
                                <span class="field-label"><?php _e('Category', 'odooflow'); ?></span>
                            </label>
                        </div>
                    </div>
                    
                    <div class="products-section">
                        <h3><?php _e('Available Products', 'odooflow'); ?></h3>
                        <div class="odoo-products-list">
                            <!-- Products will be loaded here -->
                        </div>
                    </div>
                </div>
                <div class="odoo-modal-footer">
                    <div class="selection-controls">
                        <button type="button" class="button select-all-products">
                            <?php _e('Select All Products', 'odooflow'); ?>
                        </button>
                        <button type="button" class="button deselect-all-products">
                            <?php _e('Deselect All Products', 'odooflow'); ?>
                        </button>
                        <button type="button" class="button select-all-fields">
                            <?php _e('Select All Fields', 'odooflow'); ?>
                        </button>
                        <button type="button" class="button deselect-all-fields">
                            <?php _e('Deselect All Fields', 'odooflow'); ?>
                        </button>
                    </div>
                    <button type="button" class="button-primary import-selected-products">
                        <?php _e('Import Selected Products', 'odooflow'); ?>
                    </button>
                </div>
            </div>
        </div>
        <?php
    }

    /**
     * AJAX handler for getting Odoo products
     */
    public function ajax_get_odoo_products() {
        error_log('Starting ajax_get_odoo_products');
        
        // Use consistent nonce verification
        if (!check_ajax_referer('odooflow_ajax_nonce', 'nonce', false)) {
            error_log('OdooFlow: Nonce verification failed for get_odoo_products');
            wp_send_json_error(array('message' => __('Security check failed. Please refresh the page and try again.', 'odooflow')));
            return;
        }

        $odoo_url = get_option('odooflow_odoo_url', '');
        $username = get_option('odooflow_username', '');
        $api_key = get_option('odooflow_api_key', '');
        $database = get_option('odooflow_database', '');

        error_log('OdooFlow: Attempting to fetch products from ' . $odoo_url);
        error_log('OdooFlow: Using database: ' . $database);

        if (empty($odoo_url) || empty($username) || empty($api_key) || empty($database)) {
            error_log('OdooFlow: Missing credentials - URL: ' . (empty($odoo_url) ? 'missing' : 'set') . 
                     ', Username: ' . (empty($username) ? 'missing' : 'set') . 
                     ', API Key: ' . (empty($api_key) ? 'missing' : 'set') . 
                     ', Database: ' . (empty($database) ? 'missing' : 'set'));
            wp_send_json_error(array('message' => __('Please configure all Odoo connection settings first.', 'odooflow')));
            return;
        }

        // Get selected fields from request
        $selected_fields = isset($_POST['fields']) ? array_map('sanitize_text_field', $_POST['fields']) : array('name', 'default_code', 'list_price');
        error_log('OdooFlow: Selected fields - ' . print_r($selected_fields, true));
        
        // Ensure required fields are included
        if (!in_array('name', $selected_fields)) {
            $selected_fields[] = 'name';
        }
        if (!in_array('default_code', $selected_fields)) {
            $selected_fields[] = 'default_code';
        }
        if (!in_array('id', $selected_fields)) {
            $selected_fields[] = 'id';
        }

        error_log('OdooFlow: Final fields list - ' . print_r($selected_fields, true));

        // First authenticate to get the user ID
        $auth_request = xmlrpc_encode_request('authenticate', array(
            $database,
            $username,
            $api_key,
            array()
        ));

        error_log('OdooFlow: Sending authentication request');
        
        $auth_response = wp_remote_post(rtrim($odoo_url, '/') . '/xmlrpc/2/common', [
            'body' => $auth_request,
            'headers' => ['Content-Type' => 'text/xml'],
            'timeout' => 30,
            'sslverify' => false
        ]);

        if (is_wp_error($auth_response)) {
            error_log('OdooFlow: Authentication request failed - ' . $auth_response->get_error_message());
            wp_send_json_error(array('message' => __('Error connecting to Odoo server.', 'odooflow')));
            return;
        }

        $auth_body = wp_remote_retrieve_body($auth_response);
        error_log('OdooFlow: Authentication response - ' . $auth_body);

        $auth_xml = simplexml_load_string($auth_body);
        if ($auth_xml === false) {
            error_log('OdooFlow: Failed to parse authentication XML response');
            wp_send_json_error(array('message' => __('Error parsing authentication response.', 'odooflow')));
            return;
        }

        $auth_data = json_decode(json_encode($auth_xml), true);
        if (isset($auth_data['fault'])) {
            error_log('OdooFlow: Authentication fault - ' . print_r($auth_data['fault'], true));
            wp_send_json_error(array('message' => __('Authentication failed. Please check your credentials.', 'odooflow')));
            return;
        }

        $uid = $auth_data['params']['param']['value']['int'] ?? null;
        if (!$uid) {
            error_log('OdooFlow: No UID in response');
            wp_send_json_error(array('message' => __('Could not get user ID from authentication response.', 'odooflow')));
            return;
        }

        error_log('OdooFlow: Successfully authenticated with UID: ' . $uid);

        // Get products from Odoo with less restrictive search criteria
        $products_request = xmlrpc_encode_request('execute_kw', array(
            $database,
            $uid,
            $api_key,
            'product.template',  // Changed from product.product to product.template
            'search_read',
            array(
                array(
                    array('active', '=', true),
                    // Removed type restriction to see all products first
                )
            ),
            array(
                'fields' => $selected_fields,
                'limit' => 100
            )
        ));

        error_log('OdooFlow: Sending products request');
        error_log('OdooFlow: Request data - ' . print_r($products_request, true));

        $products_response = wp_remote_post(rtrim($odoo_url, '/') . '/xmlrpc/2/object', [
            'body' => $products_request,
            'headers' => ['Content-Type' => 'text/xml'],
            'timeout' => 30,
            'sslverify' => false
        ]);

        if (is_wp_error($products_response)) {
            error_log('OdooFlow: Products request failed - ' . $products_response->get_error_message());
            wp_send_json_error(array('message' => __('Error fetching products.', 'odooflow')));
            return;
        }

        $products_body = wp_remote_retrieve_body($products_response);
        error_log('OdooFlow: Products response - ' . $products_body);

        $products_xml = simplexml_load_string($products_body);
        if ($products_xml === false) {
            error_log('OdooFlow: Failed to parse products XML response');
            wp_send_json_error(array('message' => __('Error parsing products response.', 'odooflow')));
            return;
        }

        $products_data = json_decode(json_encode($products_xml), true);
        if (isset($products_data['fault'])) {
            error_log('OdooFlow: Products fetch fault - ' . print_r($products_data['fault'], true));
            wp_send_json_error(array('message' => __('Error retrieving products: ', 'odooflow') . $products_data['fault']['value']['struct']['member'][1]['value']['string']));
            return;
        }

        error_log('OdooFlow: Products data structure - ' . print_r($products_data, true));

        // Parse the products data
        $products = $this->parse_product_data($products_data);
        error_log('Parsed products: ' . print_r($products, true));

        // Build the HTML for the products list
        $html = '<table class="wp-list-table widefat fixed striped products-list">';
        $html .= '<thead><tr>';
        $html .= '<th class="check-column"><input type="checkbox" id="select-all-products"><label for="select-all-products"></label></th>';
        $html .= '<th>' . __('Product Name', 'odooflow') . '</th>';
        $html .= '<th>' . __('SKU', 'odooflow') . '</th>';
        $html .= '<th>' . __('Price', 'odooflow') . '</th>';
        $html .= '</tr></thead><tbody>';

        if (empty($products)) {
            $html .= '<tr><td colspan="4">' . __('No products found.', 'odooflow') . '</td></tr>';
        } else {
            foreach ($products as $product) {
                $html .= sprintf(
                    '<tr>
                        <td class="check-column">
                            <input type="checkbox" name="import_products[]" id="product-%1$s" value="%1$s">
                            <label for="product-%1$s"></label>
                        </td>
                        <td>%2$s</td>
                        <td>%3$s</td>
                        <td>%4$s</td>
                    </tr>',
                    esc_attr($product['id']),
                    esc_html($product['name']),
                    esc_html($product['default_code'] ?? ''),
                    esc_html(number_format((float)($product['list_price'] ?? 0), 2))
                );
            }
        }

        $html .= '</tbody></table>';

        error_log('Sending response with HTML table');
        wp_send_json_success(array('html' => $html));
    }

    /**
     * AJAX handler for importing selected products
     */
    public function ajax_import_selected_products() {
        error_log('OdooFlow: Starting product import');
        
        // Use consistent nonce verification
        if (!check_ajax_referer('odooflow_ajax_nonce', 'nonce', false)) {
            error_log('OdooFlow: Nonce verification failed for import_selected_products');
            wp_send_json_error(array('message' => __('Security check failed. Please refresh the page and try again.', 'odooflow')));
            return;
        }

        if (!isset($_POST['product_ids']) || !is_array($_POST['product_ids'])) {
            error_log('OdooFlow: No products selected for import');
            wp_send_json_error(array('message' => __('No products selected.', 'odooflow')));
            return;
        }

        error_log('OdooFlow: Importing products - IDs: ' . print_r($_POST['product_ids'], true));
        error_log('OdooFlow: Selected fields - ' . print_r($_POST['fields'] ?? [], true));

        $product_ids = array_map('intval', $_POST['product_ids']);
        
        // Get the product details from Odoo
        $odoo_products = $this->get_odoo_products($product_ids);
        
        if (is_wp_error($odoo_products)) {
            error_log('OdooFlow: Error getting products from Odoo - ' . $odoo_products->get_error_message());
            wp_send_json_error(array('message' => $odoo_products->get_error_message()));
            return;
        }

        $new_count = 0;
        $updated_count = 0;
        $failed_imports = array();
        $processed_products = array();

        foreach ($odoo_products as $odoo_product) {
            error_log('OdooFlow: Processing product - ' . print_r($odoo_product, true));
            
            // Check if product exists before import
            $existing_id = !empty($odoo_product['default_code']) ? wc_get_product_id_by_sku($odoo_product['default_code']) : false;
            $is_update = (bool)$existing_id;

            $result = $this->create_woo_product($odoo_product);
            
            if (is_wp_error($result)) {
                error_log('OdooFlow: Failed to process product - ' . $result->get_error_message());
                $failed_imports[] = array(
                    'name' => $odoo_product['name'],
                    'error' => $result->get_error_message()
                );
            } else {
                if ($is_update) {
                    $updated_count++;
                    $processed_products[] = array(
                        'name' => $odoo_product['name'],
                        'status' => 'updated'
                    );
                } else {
                    $new_count++;
                    $processed_products[] = array(
                        'name' => $odoo_product['name'],
                        'status' => 'imported'
                    );
                }
                error_log('OdooFlow: Successfully ' . ($is_update ? 'updated' : 'imported') . ' product ID: ' . $result);
            }
        }

        // Build a detailed message
        $message_parts = array();
        if ($new_count > 0) {
            $message_parts[] = sprintf(_n('%d product imported', '%d products imported', $new_count, 'odooflow'), $new_count);
        }
        if ($updated_count > 0) {
            $message_parts[] = sprintf(_n('%d product updated', '%d products updated', $updated_count, 'odooflow'), $updated_count);
        }
        if (count($failed_imports) > 0) {
            $message_parts[] = sprintf(_n('%d product failed', '%d products failed', count($failed_imports), 'odooflow'), count($failed_imports));
        }

        $response = array(
            'new' => $new_count,
            'updated' => $updated_count,
            'failed' => $failed_imports,
            'processed' => $processed_products,
            'message' => implode(', ', $message_parts) . '.',
            'details' => $this->get_detailed_import_message($processed_products, $failed_imports)
        );

        error_log('OdooFlow: Import complete - ' . print_r($response, true));
        wp_send_json_success($response);
    }

    /**
     * Generate a detailed import message
     */
    private function get_detailed_import_message($processed_products, $failed_imports) {
        $details = '';
        
        // Group products by status
        $imported = array_filter($processed_products, function($p) { return $p['status'] === 'imported'; });
        $updated = array_filter($processed_products, function($p) { return $p['status'] === 'updated'; });
        
        // Add imported products details
        if (!empty($imported)) {
            $details .= "\n\n" . __('Imported Products:', 'odooflow') . "\n";
            foreach ($imported as $product) {
                $details .= '- ' . $product['name'] . "\n";
            }
        }
        
        // Add updated products details
        if (!empty($updated)) {
            $details .= "\n" . __('Updated Products:', 'odooflow') . "\n";
            foreach ($updated as $product) {
                $details .= '- ' . $product['name'] . "\n";
            }
        }
        
        // Add failed products details
        if (!empty($failed_imports)) {
            $details .= "\n" . __('Failed Products:', 'odooflow') . "\n";
            foreach ($failed_imports as $product) {
                $details .= '- ' . $product['name'] . ': ' . $product['error'] . "\n";
            }
        }
        
        return $details;
    }

    /**
     * Get product details from Odoo
     */
    private function get_odoo_products($product_ids) {
        error_log('Getting products with IDs: ' . print_r($product_ids, true));
        
        $odoo_url = get_option('odooflow_odoo_url', '');
        $username = get_option('odooflow_username', '');
        $api_key = get_option('odooflow_api_key', '');
        $database = get_option('odooflow_database', '');

        if (empty($odoo_url) || empty($username) || empty($api_key) || empty($database)) {
            return new WP_Error('missing_credentials', __('Please configure all Odoo connection settings first.', 'odooflow'));
        }

        // Get selected fields from request
        $selected_fields = isset($_POST['fields']) ? array_map('sanitize_text_field', $_POST['fields']) : array('name', 'default_code', 'list_price');
        
        // Ensure required fields are always included
        if (!in_array('name', $selected_fields)) {
            $selected_fields[] = 'name';
        }
        if (!in_array('default_code', $selected_fields)) {
            $selected_fields[] = 'default_code';
        }
        if (!in_array('id', $selected_fields)) {
            $selected_fields[] = 'id';
        }

        error_log('Selected fields: ' . print_r($selected_fields, true));

        // First authenticate to get the user ID
        $auth_request = xmlrpc_encode_request('authenticate', array(
            $database,
            $username,
            $api_key,
            array()
        ));

        $auth_response = wp_remote_post(rtrim($odoo_url, '/') . '/xmlrpc/2/common', [
            'body' => $auth_request,
            'headers' => ['Content-Type' => 'text/xml'],
            'timeout' => 30,
            'sslverify' => false
        ]);

        if (is_wp_error($auth_response)) {
            error_log('Authentication error: ' . $auth_response->get_error_message());
            return new WP_Error('connection_error', __('Error connecting to Odoo server.', 'odooflow'));
        }

        $auth_body = wp_remote_retrieve_body($auth_response);
        $auth_xml = simplexml_load_string($auth_body);
        if ($auth_xml === false) {
            error_log('Failed to parse authentication response: ' . $auth_body);
            return new WP_Error('parse_error', __('Error parsing authentication response.', 'odooflow'));
        }

        $auth_data = json_decode(json_encode($auth_xml), true);
        if (isset($auth_data['fault'])) {
            error_log('Authentication failed: ' . print_r($auth_data['fault'], true));
            return new WP_Error('auth_failed', __('Authentication failed. Please check your credentials.', 'odooflow'));
        }

        $uid = $auth_data['params']['param']['value']['int'] ?? null;
        if (!$uid) {
            error_log('No UID in response: ' . print_r($auth_data, true));
            return new WP_Error('no_uid', __('Could not get user ID from authentication response.', 'odooflow'));
        }

        // Get products from Odoo
        $products_request = xmlrpc_encode_request('execute_kw', array(
            $database,
            $uid,
            $api_key,
            'product.template',  // Changed from product.product to product.template
            'search_read',
            array(
                array(
                    array('id', 'in', $product_ids)
                )
            ),
            array(
                'fields' => $selected_fields
            )
        ));

        error_log('Products request: ' . $products_request);

        $products_response = wp_remote_post(rtrim($odoo_url, '/') . '/xmlrpc/2/object', [
            'body' => $products_request,
            'headers' => ['Content-Type' => 'text/xml'],
            'timeout' => 30,
            'sslverify' => false
        ]);

        if (is_wp_error($products_response)) {
            error_log('Products request error: ' . $products_response->get_error_message());
            return new WP_Error('products_error', __('Error fetching products from Odoo.', 'odooflow'));
        }

        $products_body = wp_remote_retrieve_body($products_response);
        error_log('Products response body: ' . $products_body);

        $products_xml = simplexml_load_string($products_body);
        if ($products_xml === false) {
            error_log('Failed to parse products response: ' . $products_body);
            return new WP_Error('parse_error', __('Error parsing products response.', 'odooflow'));
        }

        $products_data = json_decode(json_encode($products_xml), true);
        if (isset($products_data['fault'])) {
            error_log('Products fetch fault: ' . print_r($products_data['fault'], true));
            return new WP_Error('products_fault', __('Error retrieving products from Odoo.', 'odooflow'));
        }

        // Parse the products data
        $products = $this->parse_product_data($products_data);
        error_log('Parsed products: ' . print_r($products, true));

        return $products;
    }

    /**
     * Parse product data from XML-RPC response
     */
    private function parse_product_data($products_data) {
        $parsed_products = array();
        
        // Get the products array from the response structure
        $products = $products_data['params']['param']['value']['array']['data']['value'] ?? array();
        
        if (!is_array($products)) {
            error_log('Products data is not an array: ' . print_r($products, true));
            return array();
        }

        // Handle both single product and multiple products cases
        if (isset($products['struct'])) {
            // Single product case
            $products = array($products);
        }

        foreach ($products as $product) {
            if (!isset($product['struct']['member']) || !is_array($product['struct']['member'])) {
                error_log('Invalid product structure: ' . print_r($product, true));
                continue;
            }

            $product_data = array();
            foreach ($product['struct']['member'] as $member) {
                if (!isset($member['name']) || !isset($member['value'])) {
                    continue;
                }

                $name = $member['name'];
                $value = current($member['value']);

                // Handle different value types
                switch ($name) {
                    case 'id':
                        $product_data[$name] = is_array($value) ? (int)($value['int'] ?? 0) : (int)$value;
                        break;
                    case 'name':
                    case 'default_code':
                    case 'description':
                        $product_data[$name] = is_array($value) ? (string)($value['string'] ?? '') : (string)$value;
                        break;
                    case 'list_price':
                    case 'qty_available':
                    case 'weight':
                        $product_data[$name] = is_array($value) ? (float)($value['double'] ?? 0.0) : (float)$value;
                        break;
                    default:
                        $product_data[$name] = $value;
                }
            }

            // Only add products that have at least a name
            if (!empty($product_data['name'])) {
                error_log('Adding parsed product: ' . print_r($product_data, true));
                $parsed_products[] = $product_data;
            }
        }

        return $parsed_products;
    }

    /**
     * Create WooCommerce product from Odoo product data
     */
    private function create_woo_product($odoo_product) {
        error_log('Creating WooCommerce product from Odoo data: ' . print_r($odoo_product, true));

        if (empty($odoo_product['name'])) {
            return new WP_Error('missing_name', __('Product name is required.', 'odooflow'));
        }

        // Get selected fields from the request
        $selected_fields = isset($_POST['fields']) ? array_map('sanitize_text_field', $_POST['fields']) : array('name', 'default_code');
        error_log('Selected fields for import: ' . print_r($selected_fields, true));

        // Check if product already exists by SKU
        $existing_product_id = wc_get_product_id_by_sku($odoo_product['default_code']);
        
        if ($existing_product_id) {
            error_log('Product with SKU ' . $odoo_product['default_code'] . ' already exists (ID: ' . $existing_product_id . ')');
            // Update existing product
            $product = wc_get_product($existing_product_id);
        } else {
            // Create new product
            $product = new WC_Product_Simple();
        }

        try {
            // Set basic product data (name and SKU are always required)
            $product->set_name($odoo_product['name']);
            if (!empty($odoo_product['default_code'])) {
                $product->set_sku($odoo_product['default_code']);
            }
            
            // Only update price if it was selected
            if (in_array('list_price', $selected_fields) && isset($odoo_product['list_price'])) {
                error_log('Updating price: ' . $odoo_product['list_price']);
                $product->set_regular_price($odoo_product['list_price']);
            }
            
            // Only update stock quantity if it was selected
            if (in_array('qty_available', $selected_fields) && isset($odoo_product['qty_available'])) {
                error_log('Updating stock quantity: ' . $odoo_product['qty_available']);
                $product->set_manage_stock(true);
                $product->set_stock_quantity($odoo_product['qty_available']);
                $product->set_stock_status($odoo_product['qty_available'] > 0 ? 'instock' : 'outofstock');
            }
            
            // Only update description if it was selected
            if (in_array('description', $selected_fields) && !empty($odoo_product['description'])) {
                error_log('Updating description');
                $product->set_description($odoo_product['description']);
            }
            
            // Only update weight if it was selected
            if (in_array('weight', $selected_fields) && !empty($odoo_product['weight'])) {
                error_log('Updating weight: ' . $odoo_product['weight']);
                $product->set_weight($odoo_product['weight']);
            }

            // Set product status to published for new products
            if (!$existing_product_id) {
                $product->set_status('publish');
            }

            // Save the product
            $product_id = $product->save();
            
            if (!$product_id) {
                error_log('Failed to save product: ' . $odoo_product['name']);
                return new WP_Error('save_failed', sprintf(__('Failed to save product: %s', 'odooflow'), $odoo_product['name']));
            }

            // Store Odoo ID as meta
            update_post_meta($product_id, '_odoo_product_id', $odoo_product['id']);
            
            error_log('Successfully created/updated product: ' . $product_id);
            return $product_id;

        } catch (Exception $e) {
            error_log('Error creating product: ' . $e->getMessage());
            return new WP_Error('creation_error', sprintf(__('Error creating product: %s', 'odooflow'), $e->getMessage()));
        }
    }

    /**
     * Handle product category creation/mapping
     */
    private function handle_product_categories($category_data) {
        // Implementation for category handling
        // This would create or map Odoo categories to WooCommerce categories
        return array();
    }

    /**
     * Handle product image import
     */
    private function handle_product_image($image_data) {
        // Implementation for image handling
        // This would create media attachments from Odoo images
        return 0;
    }
}

// Initialize the plugin
function OdooFlow() {
    return OdooFlow::instance();
}
$GLOBALS['odooflow'] = OdooFlow();