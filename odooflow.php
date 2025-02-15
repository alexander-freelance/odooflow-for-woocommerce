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
        add_action('wp_ajax_get_odoo_products_count', array($this, 'ajax_get_odoo_products'));
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
        wp_localize_script('odooflow-admin', 'odooflow', array(
            'ajax_url' => admin_url('admin-ajax.php'),
            'nonce' => wp_create_nonce('odooflow_refresh_databases'),
        ));
    }

    /**
     * AJAX handler for refreshing Odoo databases
     */
    public function ajax_refresh_odoo_databases() {
        check_ajax_referer('odooflow_refresh_databases', 'nonce');

        $odoo_url = get_option('odooflow_odoo_url', '');
        $username = get_option('odooflow_username', '');
        $api_key = get_option('odooflow_api_key', '');
        $current_db = get_option('odooflow_database', '');

        $databases = $this->get_odoo_databases($odoo_url, $username, $api_key);
        
        if (is_string($databases)) {
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
        check_ajax_referer('odooflow_refresh_databases', 'nonce');

        $odoo_url = get_option('odooflow_odoo_url', '');
        $username = get_option('odooflow_username', '');
        $api_key = get_option('odooflow_api_key', '');
        $database = get_option('odooflow_database', '');

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

            update_option('odooflow_odoo_url', $odoo_url);
            update_option('odooflow_username', $username);
            update_option('odooflow_api_key', $api_key);
            update_option('odooflow_database', $database);
            update_option('odooflow_manual_db', $manual_db);

            add_settings_error('odooflow_messages', 'odooflow_message', __('Settings Saved', 'odooflow'), 'updated');
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

        // Try the server info method which is more commonly available
        $request = xmlrpc_encode_request('server_version', array());
        error_log('OdooFlow: Making XML-RPC request to ' . rtrim($odoo_url, '/') . '/xmlrpc/2/db');
        
        $response = wp_remote_post(rtrim($odoo_url, '/') . '/xmlrpc/2/db', [
            'body' => $request,
            'headers' => [
                'Content-Type' => 'text/xml',
            ],
            'timeout' => 30,
            'sslverify' => false // Only for development/testing. Remove in production.
        ]);

        if (is_wp_error($response)) {
            error_log('OdooFlow: Error connecting to server - ' . $response->get_error_message());
            return __('Error connecting to Odoo server.', 'odooflow');
        }

        $body = wp_remote_retrieve_body($response);
        if (empty($body)) {
            error_log('OdooFlow: Empty response from Odoo server');
            return __('Empty response from Odoo server.', 'odooflow');
        }

        error_log('OdooFlow: Response body - ' . $body);

        // For Odoo SaaS or restricted instances, we'll try to authenticate and get the database name
        // from the credentials
        if (!empty($username) && !empty($api_key)) {
            $auth_request = xmlrpc_encode_request('authenticate', array(
                $username,  // database name (same as username for SaaS)
                $username,
                $api_key,
                array()
            ));

            $auth_response = wp_remote_post(rtrim($odoo_url, '/') . '/xmlrpc/2/common', [
                'body' => $auth_request,
                'headers' => [
                    'Content-Type' => 'text/xml',
                ],
                'timeout' => 30,
                'sslverify' => false
            ]);

            if (!is_wp_error($auth_response)) {
                $auth_body = wp_remote_retrieve_body($auth_response);
                error_log('OdooFlow: Auth response - ' . $auth_body);
                
                // If authentication succeeds, we know the database exists
                if (strpos($auth_body, 'fault') === false) {
                    error_log('OdooFlow: Authentication successful, using username as database');
                    return array($username);
                }
            }
        }

        // If we can't determine the database through other means,
        // and we have a database saved in options, use that
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
                    <div class="odoo-products-list">
                        <!-- Products will be loaded here -->
                    </div>
                </div>
                <div class="odoo-modal-footer">
                    <div class="selection-controls">
                        <button type="button" class="button select-all-products">
                            <?php _e('Select All', 'odooflow'); ?>
                        </button>
                        <button type="button" class="button deselect-all-products">
                            <?php _e('Deselect All', 'odooflow'); ?>
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
        check_ajax_referer('odooflow_refresh_databases', 'nonce');

        $odoo_url = get_option('odooflow_odoo_url', '');
        $username = get_option('odooflow_username', '');
        $api_key = get_option('odooflow_api_key', '');
        $database = get_option('odooflow_database', '');

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

        // Get products from Odoo with name, internal reference, and price
        $products_request = xmlrpc_encode_request('execute_kw', array(
            $database,
            $uid,
            $api_key,
            'product.product',
            'search_read',
            array(
                array(
                    array('active', '=', true)
                )
            ),
            array(
                'fields' => array('name', 'default_code', 'list_price', 'id'),
                'order' => 'name ASC'
            )
        ));

        $products_response = wp_remote_post(rtrim($odoo_url, '/') . '/xmlrpc/2/object', [
            'body' => $products_request,
            'headers' => ['Content-Type' => 'text/xml'],
            'timeout' => 30,
            'sslverify' => false
        ]);

        if (is_wp_error($products_response)) {
            wp_send_json_error(array('message' => __('Error fetching products.', 'odooflow')));
            return;
        }

        $products_body = wp_remote_retrieve_body($products_response);
        $products_xml = simplexml_load_string($products_body);
        if ($products_xml === false) {
            wp_send_json_error(array('message' => __('Error parsing products response.', 'odooflow')));
            return;
        }

        $products_data = json_decode(json_encode($products_xml), true);
        if (isset($products_data['fault'])) {
            wp_send_json_error(array('message' => __('Error retrieving products: ', 'odooflow') . $products_data['fault']['value']['struct']['member'][1]['value']['string']));
            return;
        }

        // Build the HTML for the products list
        $html = '<table class="wp-list-table widefat fixed striped products-list">';
        $html .= '<thead><tr>';
        $html .= '<th class="check-column"><input type="checkbox" id="select-all-products"></th>';
        $html .= '<th>' . __('Product Name', 'odooflow') . '</th>';
        $html .= '<th>' . __('SKU', 'odooflow') . '</th>';
        $html .= '<th>' . __('Price', 'odooflow') . '</th>';
        $html .= '</tr></thead><tbody>';

        $products = $products_data['params']['param']['value']['array']['data']['value'] ?? array();
        
        if (empty($products)) {
            $html .= '<tr><td colspan="4">' . __('No products found.', 'odooflow') . '</td></tr>';
        } else {
            foreach ($products as $product) {
                $product_struct = $product['struct']['member'];
                $product_data = array();
                
                // Convert the product data structure to a more usable format
                foreach ($product_struct as $member) {
                    $name = $member['name'];
                    $value = current($member['value']);
                    $product_data[$name] = $value;
                }

                $html .= sprintf(
                    '<tr>
                        <td><input type="checkbox" name="import_products[]" value="%s"></td>
                        <td>%s</td>
                        <td>%s</td>
                        <td>%s</td>
                    </tr>',
                    esc_attr($product_data['id']),
                    esc_html($product_data['name']),
                    esc_html($product_data['default_code'] ?? ''),
                    esc_html(number_format($product_data['list_price'], 2))
                );
            }
        }

        $html .= '</tbody></table>';

        wp_send_json_success(array('html' => $html));
    }

    /**
     * AJAX handler for importing selected products
     */
    public function ajax_import_selected_products() {
        check_ajax_referer('odooflow_refresh_databases', 'nonce');

        if (!isset($_POST['product_ids']) || !is_array($_POST['product_ids'])) {
            wp_send_json_error(array('message' => __('No products selected.', 'odooflow')));
            return;
        }

        $product_ids = array_map('intval', $_POST['product_ids']);
        
        // Get the product details from Odoo
        $odoo_products = $this->get_odoo_products($product_ids);
        
        if (is_wp_error($odoo_products)) {
            wp_send_json_error(array('message' => $odoo_products->get_error_message()));
            return;
        }

        $imported_count = 0;
        $failed_imports = array();

        foreach ($odoo_products as $odoo_product) {
            $result = $this->create_woo_product($odoo_product);
            if (is_wp_error($result)) {
                $failed_imports[] = array(
                    'name' => $odoo_product['name'],
                    'error' => $result->get_error_message()
                );
            } else {
                $imported_count++;
            }
        }

        wp_send_json_success(array(
            'imported' => $imported_count,
            'failed' => $failed_imports,
            'message' => sprintf(
                __('Successfully imported %d products. %d failed.', 'odooflow'),
                $imported_count,
                count($failed_imports)
            )
        ));
    }

    /**
     * Get product details from Odoo
     */
    private function get_odoo_products($product_ids) {
        $odoo_url = get_option('odooflow_odoo_url', '');
        $username = get_option('odooflow_username', '');
        $api_key = get_option('odooflow_api_key', '');
        $database = get_option('odooflow_database', '');

        if (empty($odoo_url) || empty($username) || empty($api_key) || empty($database)) {
            return new WP_Error('missing_credentials', __('Please configure all Odoo connection settings first.', 'odooflow'));
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
            return new WP_Error('connection_error', __('Error connecting to Odoo server.', 'odooflow'));
        }

        $auth_body = wp_remote_retrieve_body($auth_response);
        $auth_xml = simplexml_load_string($auth_body);
        if ($auth_xml === false) {
            return new WP_Error('parse_error', __('Error parsing authentication response.', 'odooflow'));
        }

        $auth_data = json_decode(json_encode($auth_xml), true);
        if (isset($auth_data['fault'])) {
            return new WP_Error('auth_failed', __('Authentication failed. Please check your credentials.', 'odooflow'));
        }

        $uid = $auth_data['params']['param']['value']['int'] ?? null;
        if (!$uid) {
            return new WP_Error('no_uid', __('Could not get user ID from authentication response.', 'odooflow'));
        }

        // Get specific products from Odoo
        $products_request = xmlrpc_encode_request('execute_kw', array(
            $database,
            $uid,
            $api_key,
            'product.product',
            'search_read',
            array(
                array(
                    array('id', 'in', $product_ids)
                )
            ),
            array(
                'fields' => array('name', 'default_code', 'list_price', 'id', 'description', 'type', 'categ_id'),
            )
        ));

        $products_response = wp_remote_post(rtrim($odoo_url, '/') . '/xmlrpc/2/object', [
            'body' => $products_request,
            'headers' => ['Content-Type' => 'text/xml'],
            'timeout' => 30,
            'sslverify' => false
        ]);

        if (is_wp_error($products_response)) {
            return new WP_Error('fetch_error', __('Error fetching products.', 'odooflow'));
        }

        $products_body = wp_remote_retrieve_body($products_response);
        error_log('Raw Products Response: ' . $products_body);

        $products_xml = simplexml_load_string($products_body);
        if ($products_xml === false) {
            return new WP_Error('parse_error', __('Error parsing products response.', 'odooflow'));
        }

        $products_data = json_decode(json_encode($products_xml), true);
        error_log('Decoded Products Data: ' . print_r($products_data, true));

        if (isset($products_data['fault'])) {
            return new WP_Error('fetch_error', __('Error retrieving products: ', 'odooflow') . $products_data['fault']['value']['struct']['member'][1]['value']['string']);
        }

        $products = array();
        
        // Check if we have a single product or multiple products
        if (isset($products_data['params']['param']['value']['array']['data']['value']['struct'])) {
            // Single product
            $product = $products_data['params']['param']['value']['array']['data']['value']['struct'];
            $product_data = $this->parse_product_data($product);
            if (!empty($product_data)) {
                $products[] = $product_data;
            }
        } elseif (isset($products_data['params']['param']['value']['array']['data']['value'])) {
            // Multiple products
            $data = $products_data['params']['param']['value']['array']['data']['value'];
            if (is_array($data)) {
                foreach ($data as $product) {
                    if (isset($product['struct'])) {
                        $product_data = $this->parse_product_data($product['struct']);
                        if (!empty($product_data)) {
                            $products[] = $product_data;
                        }
                    }
                }
            }
        }

        error_log('Formatted Products: ' . print_r($products, true));
        return $products;
    }

    /**
     * Parse product data from XML-RPC response
     */
    private function parse_product_data($product_struct) {
        $product_data = array();
        
        if (!isset($product_struct['member']) || !is_array($product_struct['member'])) {
            return array();
        }

        foreach ($product_struct['member'] as $member) {
            if (!isset($member['name']) || !isset($member['value'])) {
                continue;
            }

            $name = $member['name'];
            $value = $member['value'];

            switch ($name) {
                case 'id':
                    $product_data[$name] = isset($value['int']) ? (int)$value['int'] : 0;
                    break;
                case 'name':
                    $product_data[$name] = isset($value['string']) ? (string)$value['string'] : '';
                    break;
                case 'default_code':
                    $product_data[$name] = isset($value['string']) ? (string)$value['string'] : '';
                    break;
                case 'list_price':
                    $product_data[$name] = isset($value['double']) ? (float)$value['double'] : 0.0;
                    break;
                case 'description':
                    // Handle both string and boolean cases
                    if (isset($value['string'])) {
                        $product_data[$name] = (string)$value['string'];
                    } elseif (isset($value['boolean'])) {
                        $product_data[$name] = '';  // Set empty string if boolean false
                    }
                    break;
                case 'type':
                    $product_data[$name] = isset($value['string']) ? (string)$value['string'] : 'product';
                    break;
                case 'categ_id':
                    // Handle both array and boolean cases
                    if (isset($value['array'])) {
                        $product_data[$name] = $value['array'];
                    } elseif (isset($value['boolean'])) {
                        $product_data[$name] = array();  // Set empty array if boolean false
                    }
                    break;
            }
        }

        return $product_data;
    }

    /**
     * Create WooCommerce product from Odoo product data
     */
    private function create_woo_product($odoo_product) {
        error_log('Creating product with data: ' . print_r($odoo_product, true));

        if (empty($odoo_product['name'])) {
            return new WP_Error('invalid_product', __('Product name is required', 'odooflow'));
        }

        try {
            $product = new WC_Product_Simple();
            
            // Set basic product data
            $product->set_name($odoo_product['name']);
            
            // Set SKU if available
            if (!empty($odoo_product['default_code'])) {
                $existing_id = wc_get_product_id_by_sku($odoo_product['default_code']);
                if ($existing_id) {
                    return new WP_Error('duplicate_sku', sprintf(__('Product with SKU "%s" already exists.', 'odooflow'), $odoo_product['default_code']));
                }
                $product->set_sku($odoo_product['default_code']);
            }
            
            // Set price
            if (isset($odoo_product['list_price']) && is_numeric($odoo_product['list_price'])) {
                $product->set_regular_price(number_format($odoo_product['list_price'], 2, '.', ''));
            }
            
            // Set description
            if (!empty($odoo_product['description'])) {
                $product->set_description($odoo_product['description']);
            }
            
            $product->set_status('publish');
            
            // Save Odoo ID as meta
            if (!empty($odoo_product['id'])) {
                $product->update_meta_data('_odoo_product_id', $odoo_product['id']);
            }

            $result = $product->save();
            
            if (!$result) {
                error_log('Failed to create product: ' . print_r($odoo_product, true));
                return new WP_Error('product_creation_failed', __('Failed to create product in WooCommerce', 'odooflow'));
            }

            return $result;

        } catch (Exception $e) {
            error_log('Exception creating product: ' . $e->getMessage());
            return new WP_Error('product_creation_exception', $e->getMessage());
        }
    }
}

// Initialize the plugin
function OdooFlow() {
    return OdooFlow::instance();
}
$GLOBALS['odooflow'] = OdooFlow();