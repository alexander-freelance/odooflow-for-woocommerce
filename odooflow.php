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
        add_action('wp_ajax_get_odoo_products_count', array($this, 'ajax_get_odoo_products_count'));
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
            <button type="button" class="button-secondary get-odoo-products-count">
                <?php _e('Get Odoo Products Count', 'odooflow'); ?>
            </button>
            <span class="odoo-products-count-result"></span>
        </div>
        <?php
    }

    /**
     * AJAX handler for getting Odoo products count
     */
    public function ajax_get_odoo_products_count() {
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

        // Get the count of all products in Odoo using product.product model
        $count_request = xmlrpc_encode_request('execute_kw', array(
            $database,
            $uid,
            $api_key,
            'product.product',  // Changed from product.template to product.product
            'search_count',
            array(
                array(
                    array('active', '=', true)  // Only count active products
                )
            )
        ));

        $count_response = wp_remote_post(rtrim($odoo_url, '/') . '/xmlrpc/2/object', [
            'body' => $count_request,
            'headers' => ['Content-Type' => 'text/xml'],
            'timeout' => 30,
            'sslverify' => false
        ]);

        if (is_wp_error($count_response)) {
            wp_send_json_error(array('message' => __('Error fetching product count.', 'odooflow')));
            return;
        }

        $count_body = wp_remote_retrieve_body($count_response);
        $count_xml = simplexml_load_string($count_body);
        if ($count_xml === false) {
            wp_send_json_error(array('message' => __('Error parsing product count response.', 'odooflow')));
            return;
        }

        $count_data = json_decode(json_encode($count_xml), true);
        if (isset($count_data['fault'])) {
            wp_send_json_error(array('message' => __('Error retrieving product count: ', 'odooflow') . $count_data['fault']['value']['struct']['member'][1]['value']['string']));
            return;
        }

        $product_count = $count_data['params']['param']['value']['int'] ?? 0;
        wp_send_json_success(array(
            'count' => $product_count,
            'message' => sprintf(__('Total Odoo Products: %d', 'odooflow'), $product_count)
        ));
    }
}

// Initialize the plugin
function OdooFlow() {
    return OdooFlow::instance();
}
$GLOBALS['odooflow'] = OdooFlow();