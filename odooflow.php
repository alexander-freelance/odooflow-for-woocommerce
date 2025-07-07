<?php

/**
 * Plugin Name: OdooFlow - Odoo Integration for WooCommerce
 * Description: WooCommerce integration with Odoo
 * Version: 1.0.0
 * Author: boringplugins
 * License: GPLv2 or later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: odooflow-odoo-integration-for-woocommerce
 * Domain Path: /languages
 * Requires at least: 5.0
 * Requires PHP: 7.2
 * WC requires at least: 3.0
 * WC tested up to: 8.0
 *
 * @package odooflow
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
        add_filter('plugin_row_meta', array($this, 'plugin_row_meta'), 10, 2);
        add_filter('plugin_author', array($this, 'plugin_author_link'), 10, 2);
        add_filter('plugin_author_uri', array($this, 'plugin_author_uri'), 10, 2);
        add_action('plugins_loaded', array($this, 'init_plugin'));
        add_action('admin_enqueue_scripts', array($this, 'enqueue_admin_scripts'));
        add_action('wp_ajax_refresh_odoo_databases', array($this, 'ajax_refresh_odoo_databases'));
        add_action('wp_ajax_list_odoo_modules', array($this, 'ajax_list_odoo_modules'));
        add_action('wp_ajax_get_odoo_products', array($this, 'ajax_get_odoo_products'));
        add_action('wp_ajax_import_selected_products', array($this, 'ajax_import_selected_products'));
        add_action('wp_ajax_get_woo_products', array($this, 'ajax_get_woo_products'));
        add_action('wp_ajax_export_selected_products', array($this, 'ajax_export_selected_products'));
        add_action('manage_posts_extra_tablenav', array($this, 'add_odoo_count_button'), 20);
        add_action('wp_ajax_import_odoo_customers', array($this, 'ajax_import_odoo_customers'));
        add_action('wp_ajax_export_woo_customers', array($this, 'ajax_export_woo_customers'));
        add_action('wp_ajax_sync_order_to_odoo', array($this, 'ajax_sync_order_to_odoo'));
        add_action('wp_ajax_get_odoo_customers', array($this, 'ajax_get_odoo_customers'));
        add_action('wp_ajax_get_odoo_products_for_order', array($this, 'ajax_get_odoo_products_for_order'));
        add_action('wp_ajax_create_odoo_order', array($this, 'ajax_create_odoo_order'));
        
        // Add order sync hooks
        add_filter('woocommerce_order_actions', array($this, 'add_order_sync_action'));
        add_action('woocommerce_order_action_odoo_sync_order', array($this, 'process_order_sync_action'));
        
        // Add Odoo metabox to order page
        add_action('add_meta_boxes', array($this, 'add_odoo_order_metabox'));

        // Declare HPOS compatibility
        add_action('before_woocommerce_init', function() {
            if (class_exists(\Automattic\WooCommerce\Utilities\FeaturesUtil::class)) {
                \Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility('custom_order_tables', __FILE__, true);
            }
        });
    }

    /**
     * Check WooCommerce Dependency
     */
    public function check_woocommerce_dependency() {
        if (!in_array('woocommerce/woocommerce.php', apply_filters('active_plugins', get_option('active_plugins')))) {
            add_action('admin_notices', array($this, 'woocommerce_missing_notice'));
            
            // Only attempt to deactivate if we're in admin
            if (is_admin() && current_user_can('activate_plugins')) {
                require_once ABSPATH . 'wp-admin/includes/plugin.php';
                deactivate_plugins(plugin_basename(__FILE__));
                
                if (isset($_GET['activate'])) {
                    unset($_GET['activate']);
                }
            }
            return false;
        }
        return true;
    }

    /**
     * WooCommerce missing notice
     */
    public function woocommerce_missing_notice() {
        if (!current_user_can('activate_plugins')) {
            return;
        }
        ?>
        <div class="error">
            <p>
                <?php esc_html_e('OdooFlow requires WooCommerce to be installed and activated. Please install and activate WooCommerce before activating OdooFlow.', 'odooflow'); ?>
            </p>
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
     * Add plugin row meta
     */
    public function plugin_row_meta($links, $file) {
        if (plugin_basename(__FILE__) !== $file) {
            return $links;
        }

        $row_meta = array(
            'docs' => '<a href="https://boringplugins.co/odooflow-for-woocommerce-docs" target="_blank">' . __('Documentation', 'odooflow') . '</a>',
            'support' => '<a href="mailto:hello@boringplugins.com">' . __('Get Support', 'odooflow') . '</a>'
        );

        return array_merge($links, $row_meta);
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
        // Check WooCommerce dependency first
        if (!$this->check_woocommerce_dependency()) {
            return;
        }
        // Rest of initialization code
        load_plugin_textdomain('odooflow', false, dirname(plugin_basename(__FILE__)) . '/languages');
    }

    /**
     * Enqueue admin scripts
     */
    public function enqueue_admin_scripts($hook) {
        // Load on OdooFlow settings page, products page, and users page
        $allowed_hooks = array(
            'toplevel_page_odooflow-settings',
            'edit.php',
            'users.php',
            'post.php', // For order edit page (legacy)
            wc_get_page_screen_id('shop-order') // For order edit page (HPOS)
        );

        if (!in_array($hook, $allowed_hooks)) {
            return;
        }

        // Only load on products page if we're viewing products
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

        // Load order metabox script on order pages
        if ('post.php' === $hook || $hook === wc_get_page_screen_id('shop-order')) {
            wp_enqueue_script('odooflow-order-metabox', ODOOFLOW_PLUGIN_URL . 'assets/js/order-metabox.js', array('jquery'), ODOOFLOW_VERSION, true);
            wp_localize_script('odooflow-order-metabox', 'odooflowMetabox', array(
                'ajaxurl' => admin_url('admin-ajax.php'),
                'nonce' => wp_create_nonce('odooflow_metabox_nonce'),
                'i18n' => array(
                    'confirmSync' => __('Are you sure you want to sync this order to Odoo?', 'odooflow'),
                    'syncing' => __('Syncing...', 'odooflow'),
                    'syncToOdoo' => __('Sync to Odoo', 'odooflow'),
                    'errorSyncing' => __('Error syncing order to Odoo', 'odooflow'),
                    'errorLoadingCustomers' => __('Error loading customers from Odoo', 'odooflow'),
                    'loadingCustomers' => __('Loading customers...', 'odooflow'),
                    'selectCustomer' => __('Please select a customer', 'odooflow'),
                    'errorLoadingProducts' => __('Error loading products from Odoo', 'odooflow'),
                    'loadingProducts' => __('Loading products...', 'odooflow'),
                    'selectProduct' => __('Please select a product', 'odooflow'),
                    'enterQuantity' => __('Please enter a quantity', 'odooflow'),
                    'enterPrice' => __('Please enter a price', 'odooflow'),
                    'createOrder' => __('Create Order', 'odooflow'),
                    'creating' => __('Creating...', 'odooflow'),
                    'errorCreating' => __('Error creating order in Odoo', 'odooflow')
                )
            ));
        }
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

            $odoo_url  = isset($_POST['odoo_instance_url']) ? sanitize_text_field(wp_unslash($_POST['odoo_instance_url'])) : '';
            $username  = isset($_POST['odoo_username']) ? sanitize_text_field(wp_unslash($_POST['odoo_username'])) : '';
            $api_key   = isset($_POST['odoo_api_key']) ? sanitize_text_field(wp_unslash($_POST['odoo_api_key'])) : '';
            $database  = isset($_POST['odoo_database']) ? sanitize_text_field(wp_unslash($_POST['odoo_database'])) : '';
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
        $odoo_version_info = '';
        
        if (isset($_POST['check_odoo_version'])) {
            $odoo_version_info = $this->get_odoo_version(get_option('odooflow_odoo_url', ''));
            if ($odoo_version_info && strpos($odoo_version_info, 'Error') !== false) {
                add_settings_error(
                    'odooflow_messages',
                    'odooflow_version_error',
                    $odoo_version_info,
                    'error'
                );
            } else {
                add_settings_error(
                    'odooflow_messages',
                    'odooflow_version_success',
                    $odoo_version_info,
                    'updated'
                );
            }
        }

        $odoo_url = get_option('odooflow_odoo_url', '');
        $username = get_option('odooflow_username', '');
        $api_key = get_option('odooflow_api_key', '');

        ?>
        <div class="wrap">
            <h1><?php echo esc_html(get_admin_page_title()); ?></h1>
            <?php settings_errors('odooflow_messages'); ?>

            <div class="odoo-settings-wrapper">
                <h2><?php esc_html_e('Connection Settings', 'odooflow'); ?></h2>
                <form method="post" action="">
                    <?php wp_nonce_field('odooflow_settings_nonce'); ?>
                    <table class="form-table">
                        <tr>
                            <th><label for="odoo_instance_url"><?php esc_html_e('Odoo Instance URL', 'odooflow'); ?></label></th>
                            <td><input type="url" name="odoo_instance_url" id="odoo_instance_url" value="<?php echo esc_attr(get_option('odooflow_odoo_url', '')); ?>" class="regular-text"></td>
                        </tr>
                        <tr>
                            <th><label for="odoo_username"><?php esc_html_e('Username', 'odooflow'); ?></label></th>
                            <td><input type="text" name="odoo_username" id="odoo_username" value="<?php echo esc_attr(get_option('odooflow_username', '')); ?>" class="regular-text"></td>
                        </tr>
                        <tr>
                            <th><label for="odoo_api_key"><?php esc_html_e('API Key', 'odooflow'); ?></label></th>
                            <td><input type="password" name="odoo_api_key" id="odoo_api_key" value="<?php echo esc_attr(get_option('odooflow_api_key', '')); ?>" class="regular-text"></td>
                        </tr>
                        <tr>
                            <th><label for="odoo_database"><?php esc_html_e('Select Database', 'odooflow'); ?></label></th>
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
                                                //echo '<option value="">' . __('Select a database', 'odooflow') . '</option>';
                                                echo '<option value="">' . esc_html(__('Select a database', 'odooflow')) . '</option>';
                                                foreach ($databases as $db) {
                                                    $selected = ($db === $current_db) ? 'selected' : '';
                                                    echo sprintf('<option value="%s" %s>%s</option>', 
                                                        esc_attr($db),                                    
                                                        esc_attr($selected), // Escaped $selected
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
                                           
                                            <?php echo esc_html_e('Add DB name manually', 'odooflow'); ?>
                                        </label>
                                        <button type="button" class="button-secondary refresh-databases" <?php echo $is_manual ? 'style="display: none;"' : ''; ?>>
                                            <?php echo esc_html_e('Refresh Databases', 'odooflow'); ?>
                                        </button>
                                    </div>
                                </div>
                            </td>
                        </tr>
                    </table>

                    <div class="odoo-settings-actions">
                        
                        <?php submit_button(esc_html__('Save Settings', 'odooflow'), 'primary', 'odooflow_settings_submit', false); ?>
                        <input type="submit" name="check_xmlrpc_status" class="button-secondary" value="<?php esc_html_e('Check XML-RPC Status', 'odooflow'); ?>">
                        <input type="submit" name="check_odoo_version" class="button-secondary" value="<?php esc_html_e('Check Odoo Version', 'odooflow'); ?>">
                        <button type="button" class="button-secondary list-modules">
                            <?php esc_html_e('List Modules', 'odooflow'); ?>
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

            <div class="odoo-sync-wrapper" style="margin-top: 20px; padding: 20px; background: #fff; border: 1px solid #ccd0d4; box-shadow: 0 1px 1px rgba(0,0,0,.04);">
                <h2><?php esc_html_e('Customer Synchronization', 'odooflow'); ?></h2>
                
                <div class="sync-section">
                    <h3><?php esc_html_e('Customers Sync', 'odooflow'); ?></h3>
                    <p class="description"><?php esc_html_e('Import customers from Odoo to WooCommerce or export WooCommerce customers to Odoo.', 'odooflow'); ?></p>
                    <div class="button-group">
                        <button type="button" class="button odooflow-import-customers">
                            <span class="dashicons dashicons-download" style="margin: 4px 5px 0 -2px;"></span>
                            <?php esc_html_e('Import Customers from Odoo', 'odooflow'); ?>
                        </button>
                        <button type="button" class="button odooflow-export-customers">
                            <span class="dashicons dashicons-upload" style="margin: 4px 5px 0 -2px;"></span>
                            <?php esc_html_e('Export Customers to Odoo', 'odooflow'); ?>
                        </button>
                    </div>
                </div>
            </div>

            <div id="odoo-modules-list" class="odoo-modules-wrapper" style="margin-top: 20px;">
                <h2><?php esc_html_e('Installed Modules', 'odooflow'); ?></h2>
                <div class="modules-content">
                    <!-- Modules will be loaded here -->
                </div>
            </div>
        </div>
        <?php
    }

    /**
     * Get Odoo version from server
     */
    private function get_odoo_version($odoo_url) {
        if (empty($odoo_url)) {
            return __('Please enter Odoo Instance URL', 'odooflow');
        }

        error_log('OdooFlow: Connecting to Odoo server at ' . $odoo_url);
        
        // Clean up the Odoo URL
        $odoo_url = rtrim($odoo_url, '/');
        if (!preg_match('/^https?:\/\//', $odoo_url)) {
            $odoo_url = 'https://' . $odoo_url;
        }

        // First, verify the server is reachable
        $test_response = wp_remote_get($odoo_url, [
            'timeout' => 30,
            'sslverify' => true
        ]);

        if (is_wp_error($test_response)) {
            error_log('OdooFlow: Connection test failed - ' . $test_response->get_error_message());
            return __('Unable to connect to Odoo server. Please check the URL.', 'odooflow');
        }

        $response_code = wp_remote_retrieve_response_code($test_response);
        if ($response_code !== 200) {
            error_log('OdooFlow: Connection test failed - HTTP ' . $response_code);
            return __('Odoo URL invalid', 'odooflow');
        }

        // Check if this is a SaaS instance
        if (strpos($odoo_url, '.odoo.com') !== false) {
            error_log('OdooFlow: Detected Odoo SaaS instance');
            
            // Extract subdomain for SaaS instances
            $parsed_url = wp_parse_url($odoo_url);
            $host_parts = explode('.', $parsed_url['host']);
            $database = $host_parts[0];
            
            error_log('OdooFlow: Found SaaS database - ' . $database);

            // For SaaS instances, verify it's a valid Odoo instance by checking the version
            $version_response = wp_remote_get($odoo_url . '/web/webclient/version_info', [
                'timeout' => 30,
                'sslverify' => true
            ]);

            if (!is_wp_error($version_response) && wp_remote_retrieve_response_code($version_response) === 200) {
                $body = wp_remote_retrieve_body($version_response);
                $json_data = json_decode($body, true);
                
                if (json_last_error() === JSON_ERROR_NONE && isset($json_data['server_version'])) {
                    return sprintf(
                        // translators: %s is the Odoo server version (e.g., "16.0").
                        __('Connected successfully to Odoo %s', 'odooflow'),
                        $json_data['server_version']
                    );
                }
            }

            // If we couldn't get version info, try the common endpoint
            $common_request = xmlrpc_encode_request('version', array());
            $common_response = wp_remote_post($odoo_url . '/xmlrpc/2/common', [
                'body' => $common_request,
                'headers' => ['Content-Type' => 'text/xml'],
                'timeout' => 30,
                'sslverify' => true
            ]);

            if (!is_wp_error($common_response) && wp_remote_retrieve_response_code($common_response) === 200) {
                $result = xmlrpc_decode(wp_remote_retrieve_body($common_response));
                if (is_array($result) && isset($result['server_version'])) {
                    return sprintf(
                        // translators: %s is the Odoo server version (e.g., "16.0").
                        __('Connected successfully to Odoo %s', 'odooflow'),
                        $result['server_version']
                    );
                }
            }

            // If we got here, the instance is not valid
            error_log('OdooFlow: Invalid Odoo instance - Version check failed');
            // translators: %s is the Odoo server version (e.g., "16.0").
            return __('Odoo URL invalid', 'odooflow');
        }

        // For non-SaaS instances, try the version endpoint
        $version_response = wp_remote_get($odoo_url . '/web/webclient/version_info', [
            'timeout' => 30,
            'sslverify' => false
        ]);

        if (!is_wp_error($version_response) && wp_remote_retrieve_response_code($version_response) === 200) {
            $body = wp_remote_retrieve_body($version_response);
            $json_data = json_decode($body, true);
            
            if (json_last_error() === JSON_ERROR_NONE && isset($json_data['server_version'])) {
                return sprintf(
                    // translators: %s is the Odoo server version (e.g., "16.0").         
                    __('Connected successfully to Odoo %s', 'odooflow'),
                    $json_data['server_version']
                );
            }
        }

        error_log('OdooFlow: Unable to verify Odoo instance');
        // translators: %s is the Odoo server version (e.g., "16.0").
        return __('Odoo URL invalid', 'odooflow');
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
            $parsed_url = wp_parse_url($odoo_url);
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
                <span class="dashicons dashicons-download" style="margin: 4px 5px 0 -2px;"></span>
                <?php esc_html_e('Import from Odoo', 'odooflow'); ?>
            </button>
            <button type="button" class="button-secondary export-to-odoo">
                <span class="dashicons dashicons-upload" style="margin: 4px 5px 0 -2px;"></span>
                <?php esc_html_e('Export to Odoo', 'odooflow'); ?>
            </button>
        </div>

        <!-- Modal for product selection -->
        <div id="odoo-products-modal" class="odoo-modal" style="display: none;">
            <div class="odoo-modal-content">
                <div class="odoo-modal-header">
                    <h2><?php esc_html_e('Select Products to Import', 'odooflow'); ?></h2>
                    <span class="odoo-modal-close">&times;</span>
                </div>
                <div class="odoo-modal-body">
                    <!-- Field selection section -->
                    <div class="field-selection-section">
                        <h3><?php esc_html_e('Select Fields to Import', 'odooflow'); ?></h3>
                        <div class="field-selection-grid">
                            <label class="field-checkbox">
                                <input type="checkbox" name="import_fields[]" value="name" checked disabled>
                                <span class="checkbox-custom"></span>
                                <span class="field-label"><?php esc_html_e('Product Name', 'odooflow'); ?></span>
                                <span class="field-required"><?php esc_html_e('(Required)', 'odooflow'); ?></span>
                            </label>
                            <label class="field-checkbox">
                                <input type="checkbox" name="import_fields[]" value="default_code" checked disabled>
                                <span class="checkbox-custom"></span>
                                <span class="field-label"><?php esc_html_e('SKU', 'odooflow'); ?></span>
                                <span class="field-required"><?php esc_html_e('(Required)', 'odooflow'); ?></span>
                            </label>
                            <label class="field-checkbox">
                                <input type="checkbox" name="import_fields[]" value="list_price" checked>
                                <span class="checkbox-custom"></span>
                                <span class="field-label"><?php esc_html_e('Price', 'odooflow'); ?></span>
                            </label>
                            <label class="field-checkbox">
                                <input type="checkbox" name="import_fields[]" value="description">
                                <span class="checkbox-custom"></span>
                                <span class="field-label"><?php esc_html_e('Description', 'odooflow'); ?></span>
                            </label>
                            <label class="field-checkbox">
                                <input type="checkbox" name="import_fields[]" value="image">
                                <span class="checkbox-custom"></span>
                                <span class="field-label"><?php esc_html_e('Product Image', 'odooflow'); ?></span>
                            </label>
                            <label class="field-checkbox">
                                <input type="checkbox" name="import_fields[]" value="qty_available">
                                <span class="checkbox-custom"></span>
                                <span class="field-label"><?php esc_html_e('Stock Quantity', 'odooflow'); ?></span>
                            </label>
                            <label class="field-checkbox">
                                <input type="checkbox" name="import_fields[]" value="weight">
                                <span class="checkbox-custom"></span>
                                <span class="field-label"><?php esc_html_e('Weight', 'odooflow'); ?></span>
                            </label>
                            <label class="field-checkbox">
                                <input type="checkbox" name="import_fields[]" value="categ_id">
                                <span class="checkbox-custom"></span>
                                <span class="field-label"><?php esc_html_e('Category', 'odooflow'); ?></span>
                            </label>
                        </div>
                    </div>
                    
                    <div class="products-section">
                        <h3><?php esc_html_e('Available Products', 'odooflow'); ?></h3>
                        <div class="odoo-products-list">
                            <!-- Products will be loaded here -->
                        </div>
                    </div>
                </div>
                <div class="odoo-modal-footer">
                    <div class="selection-controls">
                        <button type="button" class="button select-all-products">
                            <?php esc_html_e('Select All Products', 'odooflow'); ?>
                        </button>
                        <button type="button" class="button deselect-all-products">
                            <?php esc_html_e('Deselect All Products', 'odooflow'); ?>
                        </button>
                        <button type="button" class="button select-all-fields">
                            <?php esc_html_e('Select All Fields', 'odooflow'); ?>
                        </button>
                        <button type="button" class="button deselect-all-fields">
                            <?php esc_html_e('Deselect All Fields', 'odooflow'); ?>
                        </button>
                    </div>
                    <button type="button" class="button-primary import-selected-products">
                        <?php esc_html_e('Import Selected Products', 'odooflow'); ?>
                    </button>
                </div>
            </div>
        </div>

        <!-- Modal for product export -->
        <div id="odoo-export-modal" class="odoo-modal" style="display: none;">
            <div class="odoo-modal-content">
                <div class="odoo-modal-header">
                    <h2><?php esc_html_e('Select Products to Export', 'odooflow'); ?></h2>
                    <span class="odoo-modal-close">&times;</span>
                </div>
                <div class="odoo-modal-body">
                    <!-- Field selection section -->
                    <div class="field-selection-section">
                        <h3><?php esc_html_e('Select Fields to Export', 'odooflow'); ?></h3>
                        <div class="field-selection-grid">
                            <label class="field-checkbox">
                                <input type="checkbox" name="export_fields[]" value="name" checked disabled>
                                <span class="checkbox-custom"></span>
                                <span class="field-label"><?php esc_html_e('Product Name', 'odooflow'); ?></span>
                                <span class="field-required"><?php esc_html_e('(Required)', 'odooflow'); ?></span>
                            </label>
                            <label class="field-checkbox">
                                <input type="checkbox" name="export_fields[]" value="default_code" checked disabled>
                                <span class="checkbox-custom"></span>
                                <span class="field-label"><?php esc_html_e('SKU', 'odooflow'); ?></span>
                                <span class="field-required"><?php esc_html_e('(Required)', 'odooflow'); ?></span>
                            </label>
                            <label class="field-checkbox">
                                <input type="checkbox" name="export_fields[]" value="list_price" checked>
                                <span class="checkbox-custom"></span>
                                <span class="field-label"><?php esc_html_e('Price', 'odooflow'); ?></span>
                            </label>
                            <label class="field-checkbox">
                                <input type="checkbox" name="export_fields[]" value="description">
                                <span class="checkbox-custom"></span>
                                <span class="field-label"><?php esc_html_e('Description', 'odooflow'); ?></span>
                            </label>
                            <label class="field-checkbox">
                                <input type="checkbox" name="export_fields[]" value="qty_available">
                                <span class="checkbox-custom"></span>
                                <span class="field-label"><?php esc_html_e('Stock Quantity', 'odooflow'); ?></span>
                            </label>
                            <label class="field-checkbox">
                                <input type="checkbox" name="export_fields[]" value="weight">
                                <span class="checkbox-custom"></span>
                                <span class="field-label"><?php esc_html_e('Weight', 'odooflow'); ?></span>
                            </label>
                        </div>
                    </div>
                    
                    <div class="products-section">
                        <h3><?php esc_html_e('WooCommerce Products', 'odooflow'); ?></h3>
                        <div class="woo-products-list">
                            <!-- Products will be loaded here -->
                        </div>
                    </div>
                </div>
                <div class="odoo-modal-footer">
                    <div class="selection-controls">
                        <button type="button" class="button select-all-woo-products">
                            <?php esc_html_e('Select All Products', 'odooflow'); ?>
                        </button>
                        <button type="button" class="button deselect-all-woo-products">
                            <?php esc_html_e('Deselect All Products', 'odooflow'); ?>
                        </button>
                        <button type="button" class="button select-all-export-fields">
                            <?php esc_html_e('Select All Fields', 'odooflow'); ?>
                        </button>
                        <button type="button" class="button deselect-all-export-fields">
                            <?php esc_html_e('Deselect All Fields', 'odooflow'); ?>
                        </button>
                    </div>
                    <button type="button" class="button-primary export-selected-products">
                        <?php esc_html_e('Export Selected Products', 'odooflow'); ?>
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
        //$selected_fields = isset($_POST['fields']) ? array_map('sanitize_text_field', $_POST['fields']) : array('name', 'default_code', 'list_price');
        $selected_fields = isset($_POST['fields']) ? array_map('sanitize_text_field', wp_unslash((array) $_POST['fields'])) : array('name', 'default_code', 'list_price');
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

        // Properly unslash and sanitize $_POST['product_ids']
        $product_ids = array_map('intval', wp_unslash($_POST['product_ids']));

        // Ensure $_POST['fields'] is set and properly sanitized
        $fields = isset($_POST['fields']) ? array_map('sanitize_text_field', wp_unslash((array) $_POST['fields'])) : [];

        error_log('OdooFlow: Importing products - IDs: ' . print_r($product_ids, true));
        error_log('OdooFlow: Selected fields - ' . print_r($fields, true));

        //$product_ids = array_map('intval', $_POST['product_ids']);
        
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
            // translators: %d is the number of products imported.
            $message_parts[] = sprintf(_n('%d product imported', '%d products imported', $new_count, 'odooflow'), $new_count);
        }
        if ($updated_count > 0) {   
            // translators: %d is the number of products updated.
            $message_parts[] = sprintf(_n('%d product updated', '%d products updated', $updated_count, 'odooflow'), $updated_count);
        }
        if (count($failed_imports) > 0) {
            // translators: %d is the number of products failed.
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
            // translators: %d is the number of products imported.
            $details .= "\n\n" . __('Imported Products:', 'odooflow') . "\n";
            foreach ($imported as $product) {
                $details .= '- ' . $product['name'] . "\n";
            }
        }
        
        // Add updated products details
        if (!empty($updated)) {
            // translators: %d is the number of products updated.
            $details .= "\n" . __('Updated Products:', 'odooflow') . "\n";
            foreach ($updated as $product) {
                $details .= '- ' . $product['name'] . "\n";
            }
        }
        
        // Add failed products details
        if (!empty($failed_imports)) {
            // translators: %d is the number of products failed.
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
            // translators: %s is the Odoo server version (e.g., "16.0").
            return new WP_Error('missing_credentials', __('Please configure all Odoo connection settings first.', 'odooflow'));
        }

        // phpcs:disable WordPress.Security.NonceVerification

        // Get selected fields from request
        $selected_fields = isset($_POST['fields']) ? array_map('sanitize_text_field', wp_unslash((array) $_POST['fields'])) : array('name', 'default_code', 'list_price');
        // phpcs:enable WordPress.Security.NonceVerification

        
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
            // translators: %s is the Odoo server version (e.g., "16.0").
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
            // translators: %s is the Odoo server version (e.g., "16.0").
            return new WP_Error('auth_failed', __('Authentication failed. Please check your credentials.', 'odooflow'));
        }

        $uid = $auth_data['params']['param']['value']['int'] ?? null;
        if (!$uid) {
            error_log('No UID in response: ' . print_r($auth_data, true));
            // translators: %s is the Odoo server version (e.g., "16.0").
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
            // translators: %s is the Odoo server version (e.g., "16.0").
            return new WP_Error('products_error', __('Error fetching products from Odoo.', 'odooflow'));
        }

        $products_body = wp_remote_retrieve_body($products_response);
        error_log('Products response body: ' . $products_body);

        $products_xml = simplexml_load_string($products_body);
        if ($products_xml === false) {
            error_log('Failed to parse products response: ' . $products_body);
            // translators: %s is the Odoo server version (e.g., "16.0").
            return new WP_Error('parse_error', __('Error parsing products response.', 'odooflow'));
        }

        $products_data = json_decode(json_encode($products_xml), true);
        if (isset($products_data['fault'])) {
            error_log('Products fetch fault: ' . print_r($products_data['fault'], true));
            // translators: %s is the Odoo server version (e.g., "16.0").
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
        // phpcs:disable WordPress.Security.NonceVerification
        // Get selected fields from the request
        //$selected_fields = isset($_POST['fields']) ? array_map('sanitize_text_field', wp_unslash((array) $_POST['fields'])) : array('name', 'default_code');
        $selected_fields = isset($_POST['fields']) ? array_map('sanitize_text_field', wp_unslash((array) $_POST['fields'])) : array('name', 'default_code', 'list_price');

        error_log('Selected fields for import: ' . print_r($selected_fields, true));
        // phpcs:enable WordPress.Security.NonceVerification
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
                // translators: %s is the product name.
                return new WP_Error('save_failed', sprintf(__('Failed to save product: %s', 'odooflow'), $odoo_product['name']));
            }

            // Store Odoo ID as meta
            update_post_meta($product_id, '_odoo_product_id', $odoo_product['id']);
            
            error_log('Successfully created/updated product: ' . $product_id);
            return $product_id;

        } catch (Exception $e) {
            error_log('Error creating product: ' . $e->getMessage());
            // translators: %s is the error message.
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

    /**
     * AJAX handler for getting WooCommerce products
     */
    public function ajax_get_woo_products() {
        error_log('Starting ajax_get_woo_products');
        
        // Use consistent nonce verification
        if (!check_ajax_referer('odooflow_ajax_nonce', 'nonce', false)) {
            error_log('OdooFlow: Nonce verification failed for get_woo_products');
            wp_send_json_error(array('message' => __('Security check failed. Please refresh the page and try again.', 'odooflow')));
            return;
        }

        // Query WooCommerce products
        $args = array(
            'post_type' => 'product',
            'post_status' => 'publish',
            'posts_per_page' => 100,
        );

        $products_query = new WP_Query($args);
        $products = array();

        if ($products_query->have_posts()) {
            while ($products_query->have_posts()) {
                $products_query->the_post();
                $product = wc_get_product(get_the_ID());
                
                if (!$product) continue;

                $products[] = array(
                    'id' => $product->get_id(),
                    'name' => $product->get_name(),
                    'sku' => $product->get_sku(),
                    'price' => $product->get_regular_price(),
                    'type' => $product->get_type()
                );
            }
            wp_reset_postdata();
        }

        // Build the HTML for the products list
        $html = '<table class="wp-list-table widefat fixed striped products-list">';
        $html .= '<thead><tr>';
        $html .= '<th class="check-column"><input type="checkbox" id="select-all-woo-products"><label for="select-all-woo-products"></label></th>';
        $html .= '<th>' . __('Product Name', 'odooflow') . '</th>';
        $html .= '<th>' . __('SKU', 'odooflow') . '</th>';
        $html .= '<th>' . __('Price', 'odooflow') . '</th>';
        $html .= '</tr></thead><tbody>';

        if (empty($products)) {
            $html .= '<tr><td colspan="4">' . __('No products found.', 'odooflow') . '</td></tr>';
        } else {
            foreach ($products as $product) {
                $is_simple = $product['type'] === 'simple';
                $html .= sprintf(
                    '<tr data-product-type="%5$s"%6$s>
                        <td class="check-column">
                            <input type="checkbox" name="export_products[]" id="product-%1$s" value="%1$s"%7$s>
                            <label for="product-%1$s"></label>
                        </td>
                        <td>%2$s</td>
                        <td>%3$s</td>
                        <td>%4$s</td>
                    </tr>',
                    esc_attr($product['id']),
                    esc_html($product['name']),
                    esc_html($product['sku']),
                    esc_html(number_format((float)$product['price'], 2)),
                    esc_attr($product['type']),
                    $is_simple ? '' : ' class="non-simple-product"',
                    $is_simple ? '' : ' disabled'
                );
            }
        }

        $html .= '</tbody></table>';

        error_log('Sending response with HTML table');
        wp_send_json_success(array('html' => $html));
    }

    /**
     * AJAX handler for exporting selected products to Odoo
     */
    public function ajax_export_selected_products() {
        error_log('OdooFlow: Starting product export');
        
        // Use consistent nonce verification
        if (!check_ajax_referer('odooflow_ajax_nonce', 'nonce', false)) {
            error_log('OdooFlow: Nonce verification failed for export_selected_products');
            wp_send_json_error(array('message' => __('Security check failed. Please refresh the page and try again.', 'odooflow')));
            return;
        }

        if (!isset($_POST['product_ids']) || !is_array($_POST['product_ids'])) {
            error_log('OdooFlow: No products selected for export');
            wp_send_json_error(array('message' => __('No products selected.', 'odooflow')));
            return;
        }

        $product_ids = array_map('intval', $_POST['product_ids']);
        //$selected_fields = isset($_POST['fields']) ? array_map('sanitize_text_field', $_POST['fields']) : array('name', 'default_code', 'list_price');
        $selected_fields = isset($_POST['fields']) ? array_map('sanitize_text_field', wp_unslash((array) $_POST['fields'])) : array('name', 'default_code', 'list_price');
        
        error_log('OdooFlow: Exporting products - IDs: ' . print_r($product_ids, true));
        error_log('OdooFlow: Selected fields - ' . print_r($selected_fields, true));

        $created_count = 0;
        $updated_count = 0;
        $failed_exports = array();
        $processed_products = array();

        foreach ($product_ids as $product_id) {
            $product = wc_get_product($product_id);
            if (!$product) {
                $failed_exports[] = array(
                    'id' => $product_id,
                    'error' => 'Product not found'
                );
                continue;
            }

            $result = $this->export_product_to_odoo($product, $selected_fields);
            
            if (is_wp_error($result)) {
                error_log('OdooFlow: Failed to export product - ' . $result->get_error_message());
                $failed_exports[] = array(
                    'name' => $product->get_name(),
                    'error' => $result->get_error_message()
                );
            } else {
                if ($result['created']) {
                    $created_count++;
                    $processed_products[] = array(
                        'name' => $product->get_name(),
                        'status' => 'created'
                    );
                } else {
                    $updated_count++;
                    $processed_products[] = array(
                        'name' => $product->get_name(),
                        'status' => 'updated'
                    );
                }
                // Store the Odoo product ID in WooCommerce
                update_post_meta($product_id, '_odoo_product_id', $result['odoo_id']);
            }
        }

        // Build response message
        $message_parts = array();
        if ($created_count > 0) {
            // translators: %d is the number of products created.
            $message_parts[] = sprintf(_n('%d product created', '%d products created', $created_count, 'odooflow'), $created_count);
        }
        if ($updated_count > 0) {
            // translators: %d is the number of products updated.
            $message_parts[] = sprintf(_n('%d product updated', '%d products updated', $updated_count, 'odooflow'), $updated_count);
        }
        if (count($failed_exports) > 0) {
            // translators: %d is the number of products failed.
            $message_parts[] = sprintf(_n('%d product failed', '%d products failed', count($failed_exports), 'odooflow'), count($failed_exports));
        }

        $response = array(
            'created' => $created_count,
            'updated' => $updated_count,
            'failed' => $failed_exports,
            'processed' => $processed_products,
            'message' => implode(', ', $message_parts) . '.',
            'details' => $this->get_detailed_export_message($processed_products, $failed_exports)
        );

        error_log('OdooFlow: Export complete - ' . print_r($response, true));
        wp_send_json_success($response);
    }

    /**
     * Export a single product to Odoo
     */
    private function export_product_to_odoo($product, $selected_fields) {
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

        // Prepare product data for Odoo
        $product_data = array();

        // Always include name and default_code (SKU)
        $product_data['name'] = $product->get_name();
        $product_data['default_code'] = $product->get_sku();

        // Add other fields based on selection
        if (in_array('list_price', $selected_fields)) {
            $product_data['list_price'] = $product->get_regular_price();
        }
        if (in_array('description', $selected_fields)) {
            $product_data['description'] = $product->get_description();
        }
        if (in_array('qty_available', $selected_fields)) {
            $product_data['qty_available'] = $product->get_stock_quantity();
        }
        if (in_array('weight', $selected_fields)) {
            $product_data['weight'] = $product->get_weight();
        }

        // Get stored Odoo ID if exists
        $stored_odoo_id = get_post_meta($product->get_id(), '_odoo_product_id', true);
        $created = false;

        // Check if product exists in Odoo by SKU
        $search_request = xmlrpc_encode_request('execute_kw', array(
            $database,
            $uid,
            $api_key,
            'product.template',
            'search_read',
            array(
                array(
                    array('default_code', '=', $product->get_sku())
                )
            ),
            array('fields' => array('id'))
        ));

        $search_response = wp_remote_post(rtrim($odoo_url, '/') . '/xmlrpc/2/object', [
            'body' => $search_request,
            'headers' => ['Content-Type' => 'text/xml'],
            'timeout' => 30,
            'sslverify' => false
        ]);

        if (is_wp_error($search_response)) {
            return new WP_Error('search_error', __('Error searching for product in Odoo.', 'odooflow'));
        }

        $search_result = xmlrpc_decode(wp_remote_retrieve_body($search_response));
        $odoo_id = null;

        if (is_array($search_result) && !empty($search_result)) {
            // Product exists in Odoo
            $odoo_id = $search_result[0]['id'];
            
            // Update existing product
            $update_request = xmlrpc_encode_request('execute_kw', array(
                $database,
                $uid,
                $api_key,
                'product.template',
                'write',
                array(array($odoo_id), $product_data)
            ));

            $update_response = wp_remote_post(rtrim($odoo_url, '/') . '/xmlrpc/2/object', [
                'body' => $update_request,
                'headers' => ['Content-Type' => 'text/xml'],
                'timeout' => 30,
                'sslverify' => false
            ]);

            if (is_wp_error($update_response)) {
                return new WP_Error('update_error', __('Error updating product in Odoo.', 'odooflow'));
            }

            $update_result = xmlrpc_decode(wp_remote_retrieve_body($update_response));
            if (!$update_result) {
                return new WP_Error('update_failed', __('Failed to update product in Odoo.', 'odooflow'));
            }
        } else {
            // Create new product
            $create_request = xmlrpc_encode_request('execute_kw', array(
                $database,
                $uid,
                $api_key,
                'product.template',
                'create',
                array($product_data)
            ));

            $create_response = wp_remote_post(rtrim($odoo_url, '/') . '/xmlrpc/2/object', [
                'body' => $create_request,
                'headers' => ['Content-Type' => 'text/xml'],
                'timeout' => 30,
                'sslverify' => false
            ]);

            if (is_wp_error($create_response)) {
                return new WP_Error('create_error', __('Error creating product in Odoo.', 'odooflow'));
            }

            $create_result = xmlrpc_decode(wp_remote_retrieve_body($create_response));
            if (!is_numeric($create_result)) {
                return new WP_Error('create_failed', __('Failed to create product in Odoo.', 'odooflow'));
            }

            $odoo_id = $create_result;
            $created = true;
        }

        // Update the stored Odoo ID in WooCommerce
        if ($odoo_id) {
            update_post_meta($product->get_id(), '_odoo_product_id', $odoo_id);
        }

        return array(
            'odoo_id' => $odoo_id,
            'created' => $created
        );
    }

    /**
     * Generate a detailed export message
     */
    private function get_detailed_export_message($processed_products, $failed_exports) {
        $details = '';
        
        // Group products by status
        $created = array_filter($processed_products, function($p) { return $p['status'] === 'created'; });
        $updated = array_filter($processed_products, function($p) { return $p['status'] === 'updated'; });
        
        // Add created products details
        if (!empty($created)) {
            $details .= "\n\n" . __('Created Products:', 'odooflow') . "\n";
            foreach ($created as $product) {
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
        if (!empty($failed_exports)) {
            $details .= "\n" . __('Failed Products:', 'odooflow') . "\n";
            foreach ($failed_exports as $product) {
                $details .= '- ' . $product['name'] . ': ' . $product['error'] . "\n";
            }
        }
        
        return $details;
    }

    /**
     * Add customer sync buttons to the WooCommerce customers page
     */
    public function add_customer_sync_buttons($which) {
        $screen = get_current_screen();
        if ($screen->id !== 'users') {
            return;
        }

        if ($which === 'top') {
            ?>
            <div class="alignleft actions odooflow-customer-actions">
                <button type="button" class="button odooflow-import-customers">
                    <span class="dashicons dashicons-download" style="margin: 4px 5px 0 -2px;"></span>
                    <?php esc_html_e('Import Customers from Odoo', 'odooflow'); ?>
                </button>
                <button type="button" class="button odooflow-export-customers">
                    <span class="dashicons dashicons-upload" style="margin: 4px 5px 0 -2px;"></span>
                    <?php esc_html_e('Export Customers to Odoo', 'odooflow'); ?>
                </button>
            </div>
            <?php
        }
    }

    /**
     * AJAX handler for importing customers from Odoo
     */
    public function ajax_import_odoo_customers() {
        if (!check_ajax_referer('odooflow_ajax_nonce', 'nonce', false)) {
            wp_send_json_error(array('message' => __('Security check failed.', 'odooflow')));
            return;
        }

        if (!current_user_can('manage_woocommerce')) {
            wp_send_json_error(array('message' => __('Permission denied.', 'odooflow')));
            return;
        }

        $odoo_url = get_option('odooflow_odoo_url', '');
        $username = get_option('odooflow_username', '');
        $api_key = get_option('odooflow_api_key', '');
        $database = get_option('odooflow_database', '');

        if (empty($odoo_url) || empty($username) || empty($api_key) || empty($database)) {
            wp_send_json_error(array('message' => __('Odoo connection settings are incomplete.', 'odooflow')));
            return;
        }

        try {
            // Get customers from Odoo
            $common_endpoint = rtrim($odoo_url, '/') . '/xmlrpc/2/common';
            $object_endpoint = rtrim($odoo_url, '/') . '/xmlrpc/2/object';

            // Authenticate
            $auth_request = xmlrpc_encode_request('authenticate', array(
                $database,
                $username,
                $api_key,
                array()
            ));

            $auth_response = wp_remote_post($common_endpoint, [
                'body' => $auth_request,
                'headers' => ['Content-Type' => 'text/xml'],
                'timeout' => 30,
                'sslverify' => false
            ]);

            if (is_wp_error($auth_response)) {
                throw new Exception(__('Failed to connect to Odoo server.', 'odooflow'));
            }

            $uid = xmlrpc_decode(wp_remote_retrieve_body($auth_response));
            if (!is_numeric($uid)) {
                throw new Exception(__('Authentication failed.', 'odooflow'));
            }

            // Get customers from Odoo with more detailed information
            $method_call = xmlrpc_encode_request('execute_kw', array(
                $database,
                $uid,
                $api_key,
                'res.partner',
                'search_read',
                array(array(array('customer_rank', '>', 0))), // Only get customers
                array(
                    'fields' => array(
                        'name',
                        'email',
                        'phone',
                        'street',
                        'street2',
                        'city',
                        'country_id',
                        'state_id',
                        'vat',
                        'mobile',
                        'company_name',
                        'l10n_latam_identification_type_id'
                    ),
                    'limit' => 500
                )
            ));

            $response = wp_remote_post($object_endpoint, [
                'body' => $method_call,
                'headers' => ['Content-Type' => 'text/xml'],
                'timeout' => 30,
                'sslverify' => false
            ]);

            if (is_wp_error($response)) {
                throw new Exception(__('Failed to fetch customers from Odoo.', 'odooflow'));
            }

            $customers = xmlrpc_decode(wp_remote_retrieve_body($response));
            if (!is_array($customers)) {
                throw new Exception(__('Invalid response from Odoo.', 'odooflow'));
            }

            $id_types = array();
            foreach ($customers as $cust) {
                $tid = $cust['l10n_latam_identification_type_id'] ?? null;
                if (is_array($tid)) $tid = $tid[0];
                if ($tid) $id_types[$tid] = true;
            }

            $type_codes = array();
            if ($id_types) {
                $read_req = xmlrpc_encode_request('execute_kw', array(
                    $database, $uid, $api_key,
                    'l10n_latam.identification.type', 'read',
                    array(array_keys($id_types), array('code'))
                ));
                $read_resp = wp_remote_post($object_endpoint, [
                    'body' => $read_req,
                    'headers' => ['Content-Type' => 'text/xml'],
                    'timeout' => 30,
                    'sslverify' => false
                ]);
                if (!is_wp_error($read_resp)) {
                    $read_data = xmlrpc_decode(wp_remote_retrieve_body($read_resp));
                    if (is_array($read_data)) {
                        foreach ($read_data as $row) {
                            if (isset($row['id'], $row['code'])) {
                                $type_codes[$row['id']] = $row['code'];
                            }
                        }
                    }
                }
            }

            foreach ($customers as &$cust) {
                $tid = $cust['l10n_latam_identification_type_id'] ?? null;
                if (is_array($tid)) $tid = $tid[0];
                $cust['tipo_identificacion'] = $tid && isset($type_codes[$tid]) ? $type_codes[$tid] : '';
                $cust['billing_id'] = $cust['vat'] ?? '';
            }
            unset($cust);

            $imported = 0;
            $skipped = 0;
            $failed = 0;

            foreach ($customers as $customer) {
                if (empty($customer['email'])) {
                    $failed++;
                    continue;
                }

                // Check if user exists by email
                $existing_user = get_user_by('email', $customer['email']);
                
                if ($existing_user) {
                    // Update existing customer
                    $this->update_wc_customer($existing_user->ID, $customer);
                    $skipped++;
                    continue;
                }

                // Create new user
                $username = sanitize_user(current(explode('@', $customer['email'])));
                $unique_username = $username;
                $counter = 1;
                while (username_exists($unique_username)) {
                    $unique_username = $username . $counter;
                    $counter++;
                }

                // Split name into first and last name
                $name_parts = explode(' ', $customer['name']);
                $first_name = array_shift($name_parts);
                $last_name = implode(' ', $name_parts);

                $user_data = array(
                    'user_login' => $unique_username,
                    'user_email' => $customer['email'],
                    'user_pass' => wp_generate_password(),
                    'first_name' => $first_name,
                    'last_name' => $last_name,
                    'display_name' => $customer['name'],
                    'role' => 'customer'
                );

                $user_id = wp_insert_user($user_data);
                if (is_wp_error($user_id)) {
                    $failed++;
                    continue;
                }

                // Create WooCommerce customer
                $wc_customer = new WC_Customer($user_id);
                
                // Set billing information
                $wc_customer->set_billing_first_name($first_name);
                $wc_customer->set_billing_last_name($last_name);
                $wc_customer->set_billing_company($customer['company_name'] ?? '');
                $wc_customer->set_billing_address_1($customer['street'] ?? '');
                $wc_customer->set_billing_address_2($customer['street2'] ?? '');
                $wc_customer->set_billing_city($customer['city'] ?? '');
                $wc_customer->set_billing_postcode($customer['zip'] ?? '');
                $wc_customer->set_billing_phone($customer['phone'] ?? $customer['mobile'] ?? '');
                $wc_customer->set_billing_email($customer['email']);

                // Set shipping information (same as billing by default)
                $wc_customer->set_shipping_first_name($first_name);
                $wc_customer->set_shipping_last_name($last_name);
                $wc_customer->set_shipping_company($customer['company_name'] ?? '');
                $wc_customer->set_shipping_address_1($customer['street'] ?? '');
                $wc_customer->set_shipping_address_2($customer['street2'] ?? '');
                $wc_customer->set_shipping_city($customer['city'] ?? '');
                $wc_customer->set_shipping_postcode($customer['zip'] ?? '');

                // Save the customer
                $wc_customer->save();
                
                // Store Odoo ID for future reference
                update_user_meta($user_id, '_odoo_customer_id', $customer['id']);
                update_user_meta($user_id, 'tipo_identificacion', $customer['tipo_identificacion'] ?? '');
                update_user_meta($user_id, 'billing_id', $customer['billing_id'] ?? '');

                $imported++;
            }

            wp_send_json_success(array(
                'message' => sprintf(
                    // translators: %1$d is the number of imported customers, %2$d is the number of updated customers, %3$d is the number of failed customers
                    __('Import completed. Imported: %1$d, Updated: %2$d, Failed: %3$d', 'odooflow'),
                    $imported,
                    $skipped,
                    $failed
                )
            ));

        } catch (Exception $e) {
            wp_send_json_error(array('message' => $e->getMessage()));
        }
    }

    /**
     * Update WooCommerce customer data
     */
    private function update_wc_customer($user_id, $odoo_customer) {
        try {
            $wc_customer = new WC_Customer($user_id);
            
            // Split name into first and last name
            $name_parts = explode(' ', $odoo_customer['name']);
            $first_name = array_shift($name_parts);
            $last_name = implode(' ', $name_parts);

            // Update billing information
            $wc_customer->set_billing_first_name($first_name);
            $wc_customer->set_billing_last_name($last_name);
            $wc_customer->set_billing_company($odoo_customer['company_name'] ?? '');
            $wc_customer->set_billing_address_1($odoo_customer['street'] ?? '');
            $wc_customer->set_billing_address_2($odoo_customer['street2'] ?? '');
            $wc_customer->set_billing_city($odoo_customer['city'] ?? '');
            $wc_customer->set_billing_postcode($odoo_customer['zip'] ?? '');
            $wc_customer->set_billing_phone($odoo_customer['phone'] ?? $odoo_customer['mobile'] ?? '');
            $wc_customer->set_billing_email($odoo_customer['email']);

            // Update shipping information
            $wc_customer->set_shipping_first_name($first_name);
            $wc_customer->set_shipping_last_name($last_name);
            $wc_customer->set_shipping_company($odoo_customer['company_name'] ?? '');
            $wc_customer->set_shipping_address_1($odoo_customer['street'] ?? '');
            $wc_customer->set_shipping_address_2($odoo_customer['street2'] ?? '');
            $wc_customer->set_shipping_city($odoo_customer['city'] ?? '');
            $wc_customer->set_shipping_postcode($odoo_customer['zip'] ?? '');

            // Save the customer
            $wc_customer->save();

            // Update Odoo ID
            update_user_meta($user_id, '_odoo_customer_id', $odoo_customer['id']);
            update_user_meta($user_id, 'tipo_identificacion', $odoo_customer['tipo_identificacion'] ?? '');
            update_user_meta($user_id, 'billing_id', $odoo_customer['billing_id'] ?? '');

            return true;
        } catch (Exception $e) {
            error_log('OdooFlow: Error updating customer - ' . $e->getMessage());
            return false;
        }
    }

    /* ==========  HELPER COLOMBIA DIAN  ========== */
    private function oflow_add_col_fields( $source, array $payload,
                                           string $database, int $uid,
                                           string $api_key, string $object_ep ): array {

        // --- 1. Lee los metadatos según sea WP_User o WC_Order ------------
        $get_meta = $source instanceof WP_User
            ? fn( $k ) => $source->get_meta( $k, true )
            : fn( $k ) => $source->get_meta( $k );

        $raw_vat  = $get_meta( 'billing_id' );           // número CC/NIT
        $id_code  = trim( (string) $get_meta( 'tipo_identificacion' ) ); // 13,22,31... o "rut"/"NIT"
        $state    = $get_meta( 'billing_departamento' );
        $city     = $get_meta( 'billing_ciudad' );
        $country  = strtoupper( $get_meta( 'billing_country' ) );

        // --- 2. Normaliza y asigna VAT ------------------------------------
        if ( $raw_vat ) {
            $payload['vat'] = preg_replace( '/[^A-Za-z0-9]/', '', $raw_vat );
        }

        // --- 3. Busca el ID many2one del tipo de documento -----------------
        static $map_dian_to_odoo = [
            '31' => 'rut',
            '13' => 'national_citizen_id',
            '22' => 'foreign_id_card',
            '41' => 'passport',
        ];

        static $alias_to_dian = [
            'nit' => '31',
            'rut' => '31',
            'cc'  => '13',
        ];

        // Normaliza y traduce el tipo de documento
        $norm_code = strtolower( preg_replace( '/\s+/', '', $id_code ) );
        $dian_code = null;

        if ( isset( $alias_to_dian[ $norm_code ] ) ) {
            $dian_code = $alias_to_dian[ $norm_code ];
        } elseif ( isset( $map_dian_to_odoo[ $id_code ] ) ) {
            $dian_code = $id_code;
        } else {
            $rev = array_flip( $map_dian_to_odoo );
            if ( isset( $rev[ $norm_code ] ) ) {
                $dian_code = $rev[ $norm_code ];
            }
        }

        if ( ! $dian_code ) {
            $dian_code = '13';
        }

        static $cache = [];                          // evita consultas repetidas
        if ( ! isset( $cache[ $dian_code ] ) ) {
            // 🗄️  Buscamos el ID usando el campo 'l10n_co_document_code'
            $search_req = xmlrpc_encode_request( 'execute_kw', [
                $database, $uid, $api_key,
                'l10n_latam.identification.type', 'search',
                [[
                    ['l10n_co_document_code', '=', $dian_code]
                ]], 0, 1
            ] );

            $resp  = wp_remote_post( $object_ep, [
                        'body' => $search_req,
                        'headers' => ['Content-Type'=>'text/xml'],
                        'timeout'=>30, 'sslverify'=>false
                     ] );
            $ids   = is_wp_error( $resp ) ? [] :
                     xmlrpc_decode( wp_remote_retrieve_body( $resp ) );
            $cache[ $dian_code ] = is_array( $ids ) && $ids ? $ids[0] : null;
        }

        if ( $cache[ $dian_code ] ) {
            $payload['l10n_latam_identification_type_id'] = $cache[ $dian_code ];
        }

        // --- 4. País, departamento y ciudad ------------------------------
        if ( $country ) {
            $cid = $this->lookup_country_id( $country, $database, $uid, $api_key, $object_ep );
            if ( $cid ) {
                $payload['country_id'] = $cid;
            }
        }

        if ( $state ) {
            $sid = $this->lookup_state_id( $state, $country ?: 'CO', $database, $uid, $api_key, $object_ep );
            if ( $sid ) {
                $payload['state_id'] = $sid;
            }
        }

        if ( $city ) {
            $payload['city'] = $city;
        }

        return $payload;
    }
    /* ==========  FIN HELPER  ========== */

    /**
     * AJAX handler for exporting customers to Odoo
     */
    public function ajax_export_woo_customers() {
        if (!check_ajax_referer('odooflow_ajax_nonce', 'nonce', false)) {
            wp_send_json_error(array('message' => __('Security check failed.', 'odooflow')));
            return;
        }

        if (!current_user_can('manage_woocommerce')) {
            wp_send_json_error(array('message' => __('Permission denied.', 'odooflow')));
            return;
        }

        $odoo_url = get_option('odooflow_odoo_url', '');
        $username = get_option('odooflow_username', '');
        $api_key = get_option('odooflow_api_key', '');
        $database = get_option('odooflow_database', '');

        if (empty($odoo_url) || empty($username) || empty($api_key) || empty($database)) {
            wp_send_json_error(array('message' => __('Odoo connection settings are incomplete.', 'odooflow')));
            return;
        }

        try {
            // Authenticate with Odoo
            $common_endpoint = rtrim($odoo_url, '/') . '/xmlrpc/2/common';
            $object_endpoint = rtrim($odoo_url, '/') . '/xmlrpc/2/object';

            $auth_request = xmlrpc_encode_request('authenticate', array(
                $database,
                $username,
                $api_key,
                array()
            ));

            $auth_response = wp_remote_post($common_endpoint, [
                'body' => $auth_request,
                'headers' => ['Content-Type' => 'text/xml'],
                'timeout' => 30,
                'sslverify' => false
            ]);

            if (is_wp_error($auth_response)) {
                throw new Exception(__('Failed to connect to Odoo server.', 'odooflow'));
            }

            $uid = xmlrpc_decode(wp_remote_retrieve_body($auth_response));
            if (!is_numeric($uid)) {
                throw new Exception(__('Authentication failed.', 'odooflow'));
            }

            // Get WooCommerce customers using WP_User_Query
            $args = array(
                'role' => 'customer',
                'orderby' => 'ID',
                'order' => 'ASC',
                'number' => 500,
                'fields' => 'ID'
            );
            
            $user_query = new WP_User_Query($args);
            $customer_ids = $user_query->get_results();
            
            $exported = 0;
            $skipped = 0;
            $failed = 0;
            $updated = 0;

            foreach ($customer_ids as $customer_id) {
                $wc_customer = new WC_Customer($customer_id);
                
                if (!$wc_customer || empty($wc_customer->get_email())) {
                    $failed++;
                    continue;
                }

                // Check if customer exists in Odoo
                $search_request = xmlrpc_encode_request('execute_kw', array(
                    $database,
                    $uid,
                    $api_key,
                    'res.partner',
                    'search',
                    array(array(array('email', '=', $wc_customer->get_email())))
                ));

                $search_response = wp_remote_post($object_endpoint, [
                    'body' => $search_request,
                    'headers' => ['Content-Type' => 'text/xml'],
                    'timeout' => 30,
                    'sslverify' => false
                ]);

                if (is_wp_error($search_response)) {
                    $failed++;
                    continue;
                }

                $existing_ids = xmlrpc_decode(wp_remote_retrieve_body($search_response));

                // Prepare customer data
                $customer_data = array(
                    'name'  => $wc_customer->get_first_name() . ' ' . $wc_customer->get_last_name(),
                    'email' => $wc_customer->get_email(),
                    'phone' => $wc_customer->get_billing_phone(),
                    'street'=> $wc_customer->get_billing_address_1(),
                    'street2'=> $wc_customer->get_billing_address_2(),
                    'city'  => $wc_customer->get_billing_city(),
                    'customer_rank' => 1,
                    'type'  => 'contact'
                );

                /* ➕  Enriquecemos con NIT + Tipo DIAN */
                $customer_data = $this->oflow_add_col_fields(
                    $wc_customer,
                    $customer_data,
                    $database, $uid, $api_key,
                    $object_endpoint
                );

                if (!empty($existing_ids)) {
                    // Update existing customer in Odoo
                    $update_request = xmlrpc_encode_request('execute_kw', array(
                        $database,
                        $uid,
                        $api_key,
                        'res.partner',
                        'write',
                        array(array($existing_ids[0]), $customer_data)
                    ));

                    $update_response = wp_remote_post($object_endpoint, [
                        'body' => $update_request,
                        'headers' => ['Content-Type' => 'text/xml'],
                        'timeout' => 30,
                        'sslverify' => false
                    ]);

                    if (!is_wp_error($update_response)) {
                        update_user_meta($customer_id, '_odoo_customer_id', $existing_ids[0]);
                        $updated++;
                    } else {
                        $failed++;
                    }
                    continue;
                }

                // Create new customer in Odoo
                $create_request = xmlrpc_encode_request('execute_kw', array(
                    $database,
                    $uid,
                    $api_key,
                    'res.partner',
                    'create',
                    array($customer_data)
                ));

                $create_response = wp_remote_post($object_endpoint, [
                    'body' => $create_request,
                    'headers' => ['Content-Type' => 'text/xml'],
                    'timeout' => 30,
                    'sslverify' => false
                ]);

                if (is_wp_error($create_response)) {
                    $failed++;
                    continue;
                }

                $odoo_id = xmlrpc_decode(wp_remote_retrieve_body($create_response));
                if (is_numeric($odoo_id)) {
                    update_user_meta($customer_id, '_odoo_customer_id', $odoo_id);
                    $exported++;
                } else {
                    $failed++;
                }
            }

            wp_send_json_success(array(
                'message' => sprintf(
                    // translators: %1$d is the number of exported customers, %2$d is the number of updated customers, %3$d is the number of skipped customers, %4$d is the number of failed customers
                    __('Export completed. Exported: %1$d, Updated: %2$d, Skipped: %3$d, Failed: %4$d', 'odooflow'),
                    $exported,
                    $updated,
                    $skipped,
                    $failed
                )
            ));

        } catch (Exception $e) {
            wp_send_json_error(array('message' => $e->getMessage()));
        }
    }

    /**
     * Add Re-sync to Odoo option to order actions
     */
    public function add_order_sync_action($actions) {
        $actions['odoo_sync_order'] = __('Re-sync to Odoo', 'odooflow');
        return $actions;
    }

    /**
     * Process the order sync action
     */
    public function process_order_sync_action($order) {
        error_log('OdooFlow: Processing order sync action for order #' . $order->get_id());
        
        $order_id = $order->get_id();
        $result = $this->sync_order_to_odoo($order);
        
        if (is_wp_error($result)) {
            error_log('OdooFlow: Order sync failed - ' . $result->get_error_message());
            // Add error notice
            add_action('admin_notices', function() use ($result) {
                echo '<div class="notice notice-error"><p>' . 
                     esc_html($result->get_error_message()) . 
                     '</p></div>';
            });
        } else {
            error_log('OdooFlow: Order sync completed successfully');
            // Add success notice
            add_action('admin_notices', function() use ($order_id) {
                echo '<div class="notice notice-success"><p>' . 
                    // translators: %s is the order ID.
                     esc_html(sprintf(__('Order #%s successfully synced to Odoo', 'odooflow'), $order_id)) 
                     .
                     '</p></div>';
            });
        }
    }

    /**
     * Sync order to Odoo
     */
    private function sync_order_to_odoo($order) {
        error_log('OdooFlow: Starting order sync for order #' . $order->get_id());
        
        $odoo_url = get_option('odooflow_odoo_url', '');
        $username = get_option('odooflow_username', '');
        $api_key = get_option('odooflow_api_key', '');
        $database = get_option('odooflow_database', '');

        if (empty($odoo_url) || empty($username) || empty($api_key) || empty($database)) {
            $error_message = 'Odoo connection settings are incomplete.';
            error_log('OdooFlow: ' . $error_message);
            //$order->add_order_note(__('Odoo Sync Failed: ' . $error_message, 'odooflow'));
            // translators: %s is the error message explaining why the Odoo sync failed.
            $order->add_order_note(sprintf(__('Odoo Sync Failed: %s', 'odooflow'), $error_message));
            //return new WP_Error('missing_credentials', __($error_message, 'odooflow'));
            // translators: %s is the specific error message detailing why credentials are missing.
            return new WP_Error('missing_credentials', sprintf(__('Missing credentials: %s', 'odooflow'), $error_message));
        }

        try {
            // Authenticate with Odoo
            error_log('OdooFlow: Authenticating with Odoo server');
            $auth_result = $this->authenticate_odoo($odoo_url, $database, $username, $api_key);
            if (is_wp_error($auth_result)) {
                $error_message = 'Authentication failed: ' . $auth_result->get_error_message();
                error_log('OdooFlow: ' . $error_message);
                
                // translators: %s is the error message explaining why the Odoo sync failed.
                $order->add_order_note(sprintf(__('Odoo Sync Failed: %s', 'odooflow'), $error_message));
                return $auth_result;
            }
            $uid = $auth_result;
            error_log('OdooFlow: Successfully authenticated with UID: ' . $uid);

            // Get order data
            error_log('OdooFlow: Preparing order data');
            $order_data = $this->prepare_order_data($order, $database, $uid, $api_key);
            error_log('OdooFlow: Order data prepared: ' . print_r($order_data, true));
            
            // Check if order exists in Odoo
            $odoo_order_id = get_post_meta($order->get_id(), '_odoo_order_id', true);
            
            if ($odoo_order_id) {
                error_log('OdooFlow: Updating existing Odoo order #' . $odoo_order_id);
                // Update existing order
                $result = $this->update_odoo_order($odoo_url, $database, $uid, $api_key, $odoo_order_id, $order_data);
                if (!is_wp_error($result)) {
                    // translators: %s is the Odoo order ID.
                    $success_message = sprintf(__('Order successfully updated in Odoo (ID: %s)', 'odooflow'), $odoo_order_id);
                    error_log('OdooFlow: ' . $success_message);
                    $order->add_order_note($success_message);
                } else {
                    $error_message = 'Failed to update order in Odoo: ' . $result->get_error_message();
                    error_log('OdooFlow: ' . $error_message);
                    //$order->add_order_note(__('Odoo Sync Failed: ' . $error_message, 'odooflow'));
                    // translators: %s is the error message explaining why the Odoo sync failed.
                    $order->add_order_note(sprintf(__('Odoo Sync Failed: %s', 'odooflow'), $error_message));
                }
            } else {
                error_log('OdooFlow: Creating new order in Odoo');
                // Create new order
                $result = $this->create_odoo_order($odoo_url, $database, $uid, $api_key, $order_data);
                if (!is_wp_error($result)) {
                    update_post_meta($order->get_id(), '_odoo_order_id', $result);
                    // translators: %s is the Odoo order ID.
                    $success_message = sprintf(__('Order successfully created in Odoo (ID: %s)', 'odooflow'), $result);
                    error_log('OdooFlow: ' . $success_message);
                    $order->add_order_note($success_message);
                } else {
                    $error_message = 'Failed to create order in Odoo: ' . $result->get_error_message();
                    error_log('OdooFlow: ' . $error_message);
                    //$order->add_order_note(__('Odoo Sync Failed: ' . $error_message, 'odooflow'));
                     // translators: %s is the error message explaining why the Odoo sync failed.
                    $order->add_order_note(sprintf(__('Odoo Sync Failed: %s', 'odooflow'), $error_message));
                }
            }

            return $result;

        } catch (Exception $e) {
            $error_message = 'Error syncing order to Odoo: ' . $e->getMessage();
            error_log('OdooFlow: ' . $error_message);
            //$order->add_order_note(__('Odoo Sync Failed: ' . $error_message, 'odooflow'));
            // translators: %s is the error message explaining why the Odoo sync failed.
            $order->add_order_note(sprintf(__('Odoo Sync Failed: %s', 'odooflow'), $error_message));
            return new WP_Error('sync_error', $e->getMessage());
        }
    }

    /**
     * Authenticate with Odoo
     */
    private function authenticate_odoo($odoo_url, $database, $username, $api_key) {
        $auth_request = xmlrpc_encode_request('authenticate', array(
            $database,
            $username,
            $api_key,
            array()
        ));

        $response = wp_remote_post(rtrim($odoo_url, '/') . '/xmlrpc/2/common', [
            'body' => $auth_request,
            'headers' => ['Content-Type' => 'text/xml'],
            'timeout' => 30,
            'sslverify' => false
        ]);

        if (is_wp_error($response)) {
            return new WP_Error('connection_error', __('Error connecting to Odoo server.', 'odooflow'));
        }

        $uid = xmlrpc_decode(wp_remote_retrieve_body($response));
        if (!is_numeric($uid)) {
            return new WP_Error('auth_failed', __('Authentication failed.', 'odooflow'));
        }

        return $uid;
    }

    /**
     * Prepare order data for Odoo
     */
    private function prepare_order_data($order, $database, $uid, $api_key) {
        error_log('OdooFlow: Preparing order data for order #' . $order->get_id());
        
        $order_status = $order->get_status();
        $order_type = $this->get_odoo_order_type($order_status);
        
        error_log('OdooFlow: Order status: ' . $order_status . ', Odoo order type: ' . $order_type);

        // Get or create customer in Odoo
        $partner_id = $this->get_or_create_odoo_customer($order, $database, $uid, $api_key);
        if (is_wp_error($partner_id)) {
            // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Log message, not HTML output.
            error_log('OdooFlow: Error getting/creating customer - ' . $partner_id->get_error_message());
            // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Log message, not HTML output.
            throw new Exception('Failed to get/create customer in Odoo: ' . $partner_id->get_error_message());
        }
        error_log('OdooFlow: Using Odoo partner ID: ' . $partner_id);

        // Prepare order lines
        error_log('OdooFlow: Preparing order lines');
        $order_lines = $this->prepare_order_lines($order);
        error_log('OdooFlow: Order lines prepared: ' . print_r($order_lines, true));
        
        $order_data = array(
            'name' => 'WC' . $order->get_order_number(),
            'partner_id' => $partner_id,
            'date_order' => $order->get_date_created()->format('Y-m-d H:i:s'),
            'state' => $this->map_order_status($order_status),
            'order_type' => $order_type,
            'order_line' => $order_lines,
            'amount_tax' => $order->get_total_tax(),
            'amount_total' => $order->get_total(),
            'currency_id' => $this->get_currency_id($order->get_currency()),
            'note' => $order->get_customer_note()
        );

        // Add shipping information if exists
        if ($order->get_shipping_total() > 0) {
            error_log('OdooFlow: Adding shipping line');
            $order_data['order_line'][] = $this->prepare_shipping_line($order);
        }

        error_log('OdooFlow: Final order data prepared: ' . print_r($order_data, true));
        return $order_data;
    }

    /**
     * Map WooCommerce order status to Odoo status
     */
    private function map_order_status($status) {
        $status_map = array(
            'pending' => 'draft',
            'processing' => 'sale',
            'on-hold' => 'sent',
            'completed' => 'done',
            'cancelled' => 'cancel',
            'refunded' => 'cancel',
            'failed' => 'cancel'
        );

        $odoo_status = isset($status_map[$status]) ? $status_map[$status] : 'draft';
        error_log('OdooFlow: Mapped WooCommerce status ' . $status . ' to Odoo status ' . $odoo_status);
        return $odoo_status;
    }

    /**
     * Get Odoo order type based on WooCommerce order status
     */
    private function get_odoo_order_type($status) {
        $type = 'order';
        switch ($status) {
            case 'completed':
                $type = 'sale';
                break;
            case 'processing':
                $type = 'order';
                break;
            case 'pending':
            case 'on-hold':
                $type = 'quote';
                break;
        }
        error_log('OdooFlow: Determined order type ' . $type . ' for status ' . $status);
        return $type;
    }

    /**
     * Prepare order line items for Odoo
     */
    private function prepare_order_lines($order) {
        $lines = array();
        
        foreach ($order->get_items() as $item) {
            $product_id = $this->get_or_create_odoo_product($item->get_product());
            if (!$product_id) continue;

            $line = array(
                'product_id' => $product_id,
                'name' => $item->get_name(),
                'product_uom_qty' => $item->get_quantity(),
                'price_unit' => $item->get_total() / $item->get_quantity(),
                'tax_id' => $this->get_tax_ids($item),
                'discount' => $item->get_subtotal() > 0 ? 
                    (1 - $item->get_total() / $item->get_subtotal()) * 100 : 0
            );

            $lines[] = array(0, 0, $line);
        }

        return $lines;
    }

    /**
     * Prepare shipping line for Odoo
     */
    private function prepare_shipping_line($order) {
        return array(0, 0, array(
            'name' => $order->get_shipping_method(),
            'price_unit' => $order->get_shipping_total(),
            'product_uom_qty' => 1,
            'tax_id' => $this->get_shipping_tax_ids($order),
            'is_delivery' => true
        ));
    }

    /**
     * Get or create Odoo product
     */
    private function get_or_create_odoo_product($product) {
        if (!$product) return false;

        $odoo_product_id = get_post_meta($product->get_id(), '_odoo_product_id', true);
        if ($odoo_product_id) return $odoo_product_id;

        // Create product in Odoo if it doesn't exist
        $product_data = array(
            'name' => $product->get_name(),
            'default_code' => $product->get_sku(),
            'list_price' => $product->get_regular_price(),
            'type' => 'product',
            'sale_ok' => true
        );

        // Create product in Odoo
        $result = $this->create_odoo_product($product_data);
        if (!is_wp_error($result)) {
            update_post_meta($product->get_id(), '_odoo_product_id', $result);
            return $result;
        }

        return false;
    }

    /**
     * Create product in Odoo
     */
    private function create_odoo_product($product_data) {
        // Implementation for creating product in Odoo
        // This would use the XML-RPC API to create a product
        // Return the Odoo product ID or WP_Error
        return 0; // Placeholder
    }

    /**
     * Get or create Odoo customer
     */
    private function get_or_create_odoo_customer($order, $database, $uid, $api_key) {
        $customer_id = $order->get_customer_id();
        if ($customer_id) {
            $odoo_customer_id = get_user_meta($customer_id, '_odoo_customer_id', true);
            if ($odoo_customer_id) return $odoo_customer_id;
        }

        // Create customer in Odoo
        $customer_data = array(
            'name'  => $order->get_formatted_billing_full_name(),
            'email' => $order->get_billing_email(),
            'phone' => $order->get_billing_phone(),
            'street'=> $order->get_billing_address_1(),
            'street2'=> $order->get_billing_address_2(),
            'city'  => $order->get_billing_city(),
            'country_id' => $this->get_country_id( $order->get_billing_country() ),
            'customer_rank' => 1,
            'type'  => 'contact'
        );

        /* ➕  Enriquecer con los metadatos del pedido (guest checkout) */
        $customer_data = $this->oflow_add_col_fields(
            $order,
            $customer_data,
            $database, $uid, $api_key,
            rtrim( get_option('odooflow_odoo_url',''), '/' ) . '/xmlrpc/2/object'
        );

        // Create customer in Odoo
        $result = $this->create_odoo_customer($customer_data);
        if (!is_wp_error($result) && $customer_id) {
            update_user_meta($customer_id, '_odoo_customer_id', $result);
            $tipo_val = $order->get_meta('tipo_identificacion');
            if ($tipo_val) {
                update_user_meta($customer_id, 'tipo_identificacion', $tipo_val);
            }
            $vat_val = $order->get_meta('billing_id');
            if ($vat_val) {
                update_user_meta($customer_id, 'billing_id', $vat_val);
            }
        }

        return $result;
    }

    /**
     * Create customer in Odoo
     */
    private function create_odoo_customer($customer_data) {
        // Implementation for creating customer in Odoo
        // This would use the XML-RPC API to create a customer
        // Return the Odoo customer ID or WP_Error
        return 0; // Placeholder
    }

    /**
     * Get tax IDs from Odoo
     */
    private function get_tax_ids($item) {
        $taxes = $item->get_taxes();
        $tax_ids = array();

        foreach ($taxes['total'] as $tax_id => $amount) {
            $odoo_tax_id = $this->get_odoo_tax_id($tax_id);
            if ($odoo_tax_id) {
                $tax_ids[] = $odoo_tax_id;
            }
        }

        return array(6, 0, $tax_ids);
    }

    /**
     * Get shipping tax IDs
     */
    private function get_shipping_tax_ids($order) {
        $taxes = $order->get_shipping_taxes();
        $tax_ids = array();

        foreach ($taxes as $tax_id => $amount) {
            $odoo_tax_id = $this->get_odoo_tax_id($tax_id);
            if ($odoo_tax_id) {
                $tax_ids[] = $odoo_tax_id;
            }
        }

        return array(6, 0, $tax_ids);
    }

    /**
     * Get Odoo tax ID
     */
    private function get_odoo_tax_id($wc_tax_id) {
        // Implementation to map WooCommerce tax to Odoo tax
        // This would need to be configured in the plugin settings
        return 0; // Placeholder
    }

    /**
     * Get currency ID from Odoo
     */
    private function get_currency_id($currency_code) {
        // Implementation to get currency ID from Odoo
        // This would need to be cached for performance
        return 0; // Placeholder
    }

    /**
     * Get country ID from Odoo
     */
    private function get_country_id($country_code) {
        $odoo_url = get_option('odooflow_odoo_url', '');
        $username = get_option('odooflow_username', '');
        $api_key  = get_option('odooflow_api_key', '');
        $database = get_option('odooflow_database', '');

        if (empty($odoo_url) || empty($username) || empty($api_key) || empty($database) || !$country_code) {
            return 0;
        }

        $common_ep = rtrim($odoo_url, '/') . '/xmlrpc/2/common';
        $object_ep = rtrim($odoo_url, '/') . '/xmlrpc/2/object';

        $auth_request = xmlrpc_encode_request('authenticate', array(
            $database,
            $username,
            $api_key,
            array()
        ));

        $auth_response = wp_remote_post($common_ep, [
            'body'    => $auth_request,
            'headers' => ['Content-Type' => 'text/xml'],
            'timeout' => 30,
            'sslverify' => false
        ]);

        if (is_wp_error($auth_response)) {
            return 0;
        }

        $uid = xmlrpc_decode(wp_remote_retrieve_body($auth_response));
        if (!is_numeric($uid)) {
            return 0;
        }

        return $this->lookup_country_id($country_code, $database, $uid, $api_key, $object_ep);
    }

    /**
     * Lookup country ID by code using Odoo RPC
     */
    private function lookup_country_id($code, $database, $uid, $api_key, $object_ep) {
        if (!$code) return 0;
        $code = strtoupper($code);

        static $cache = [];
        if (!isset($cache[$code])) {
            $req = xmlrpc_encode_request('execute_kw', [
                $database, $uid, $api_key,
                'res.country', 'search',
                [[['code', '=', $code]]], 0, 1
            ]);

            $resp = wp_remote_post($object_ep, [
                'body'    => $req,
                'headers' => ['Content-Type' => 'text/xml'],
                'timeout' => 30,
                'sslverify' => false
            ]);

            $ids = is_wp_error($resp) ? [] : xmlrpc_decode(wp_remote_retrieve_body($resp));
            $cache[$code] = is_array($ids) && $ids ? $ids[0] : 0;
        }

        return $cache[$code];
    }

    /**
     * Lookup state ID by name and country
     */
    private function lookup_state_id($name, $country_code, $database, $uid, $api_key, $object_ep) {
        if (!$name) return 0;
        $key = strtoupper(($country_code ?: 'CO') . '|' . $name);

        static $cache = [];
        if (!isset($cache[$key])) {
            $domain = [ ['name', 'ilike', $name] ];
            if ($country_code) {
                $domain[] = ['country_id.code', '=', strtoupper($country_code)];
            }

            $req = xmlrpc_encode_request('execute_kw', [
                $database, $uid, $api_key,
                'res.country.state', 'search',
                [$domain], 0, 1
            ]);

            $resp = wp_remote_post($object_ep, [
                'body'    => $req,
                'headers' => ['Content-Type' => 'text/xml'],
                'timeout' => 30,
                'sslverify' => false
            ]);

            $ids = is_wp_error($resp) ? [] : xmlrpc_decode(wp_remote_retrieve_body($resp));
            $cache[$key] = is_array($ids) && $ids ? $ids[0] : 0;
        }

        return $cache[$key];
    }

    /**
     * Create order in Odoo
     */
    private function create_odoo_order($odoo_url, $database, $uid, $api_key, $order_data) {
        error_log('OdooFlow: Creating order in Odoo with data: ' . print_r($order_data, true));
        
        $request = xmlrpc_encode_request('execute_kw', array(
            $database,
            $uid,
            $api_key,
            'sale.order',
            'create',
            array($order_data)
        ));

        $response = wp_remote_post(rtrim($odoo_url, '/') . '/xmlrpc/2/object', [
            'body' => $request,
            'headers' => ['Content-Type' => 'text/xml'],
            'timeout' => 30,
            'sslverify' => false
        ]);

        if (is_wp_error($response)) {
            error_log('OdooFlow: Error creating order - ' . $response->get_error_message());
            //return new WP_Error('create_error', __('Error creating order in Odoo: ' . $response->get_error_message(), 'odooflow'));
            // translators: %s is the error message from Odoo explaining why the order creation failed.
            return new WP_Error('create_error', sprintf(__('Error creating order in Odoo: %s', 'odooflow'), $response->get_error_message()));
        }

        $result = xmlrpc_decode(wp_remote_retrieve_body($response));
        if (!is_numeric($result)) {
            error_log('OdooFlow: Failed to create order - Invalid response: ' . print_r($result, true));
            return new WP_Error('create_failed', __('Failed to create order in Odoo: Invalid response', 'odooflow'));
        }

        error_log('OdooFlow: Successfully created order with ID: ' . $result);
        return $result;
    }

    /**
     * Update order in Odoo
     */
    private function update_odoo_order($odoo_url, $database, $uid, $api_key, $odoo_order_id, $order_data) {
        error_log('OdooFlow: Updating order in Odoo - ID: ' . $odoo_order_id);
        error_log('OdooFlow: Update data: ' . print_r($order_data, true));
        
        $request = xmlrpc_encode_request('execute_kw', array(
            $database,
            $uid,
            $api_key,
            'sale.order',
            'write',
            array(array($odoo_order_id), $order_data)
        ));

        $response = wp_remote_post(rtrim($odoo_url, '/') . '/xmlrpc/2/object', [
            'body' => $request,
            'headers' => ['Content-Type' => 'text/xml'],
            'timeout' => 30,
            'sslverify' => false
        ]);

        if (is_wp_error($response)) {
            error_log('OdooFlow: Error updating order - ' . $response->get_error_message());
            //return new WP_Error('update_error', __('Error updating order in Odoo: ' . $response->get_error_message(), 'odooflow'));
            // translators: %s is the error message from Odoo explaining why the order update failed.
            return new WP_Error('update_error', sprintf(__('Error updating order in Odoo: %s', 'odooflow'), $response->get_error_message()));
        }

        $result = xmlrpc_decode(wp_remote_retrieve_body($response));
        if (!$result) {
            error_log('OdooFlow: Failed to update order - Invalid response: ' . print_r($result, true));
            return new WP_Error('update_failed', __('Failed to update order in Odoo: Invalid response', 'odooflow'));
        }

        error_log('OdooFlow: Successfully updated order');
        return true;
    }

    /**
     * Add plugin author link
     */
    public function plugin_author_link($author_name, $plugin_file) {
        if (plugin_basename(__FILE__) === $plugin_file) {
            return 'boringplugins';
        }
        return $author_name;
    }

    /**
     * Add plugin author URI
     */
    public function plugin_author_uri($uri, $plugin_file) {
        if (plugin_basename(__FILE__) === $plugin_file) {
            return 'https://boringplugins.com';
        }
        return $uri;
    }

    /**
     * Add Odoo metabox to order page
     */
    public function add_odoo_order_metabox() {
        $screen = class_exists('\Automattic\WooCommerce\Internal\DataStores\Orders\CustomOrdersTableController') && 
                 wc_get_container()->get(\Automattic\WooCommerce\Internal\DataStores\Orders\CustomOrdersTableController::class)->custom_orders_table_usage_is_enabled()
            ? wc_get_page_screen_id('shop-order')
            : 'shop_order';

        add_meta_box(
            'odooflow_order_metabox',
            __('Odoo Order Information', 'odooflow'),
            array($this, 'render_odoo_order_metabox'),
            $screen,
            'side',
            'high'
        );
    }

    /**
     * Render Odoo order metabox content
     *
     * @param mixed $object Post object or order object depending on HPOS status
     */
    public function render_odoo_order_metabox($object) {
        // Get the WC_Order object
        $order = is_a($object, 'WP_Post') ? wc_get_order($object->ID) : $object;
        
        if (!$order) {
            return;
        }

        $odoo_order_id = $order->get_meta('_odoo_order_id');
        $odoo_url = get_option('odooflow_odoo_url', '');
        
        echo '<div class="odooflow-metabox-content">';
        
        if ($odoo_order_id) {
            $odoo_order_url = rtrim($odoo_url, '/') . '/web#id=' . $odoo_order_id . '&model=sale.order&view_type=form';
            echo '<p>' . sprintf(
                /* translators: %s: Odoo order ID */
                __('Odoo Order ID: %s', 'odooflow'),
                '<strong>' . esc_html($odoo_order_id) . '</strong>'
            ) . '</p>';
            echo '<p><a href="' . esc_url($odoo_order_url) . '" target="_blank" class="button">' . 
                 __('View in Odoo', 'odooflow') . 
                 '</a></p>';
        } else {
            echo '<p>' . __('This order has not been synced to Odoo yet.', 'odooflow') . '</p>';
            echo '<button type="button" class="button create-odoo-order" data-order-id="' . esc_attr($order->get_id()) . '">' . 
                 __('Create Order in Odoo', 'odooflow') . 
                 '</button>';
            
            // Add modal HTML
            echo '<div id="odooflow-create-order-modal" class="odooflow-modal" style="display:none;">
                <div class="odooflow-modal-content">
                    <span class="odooflow-modal-close">&times;</span>
                    <h2>' . __('Create Order in Odoo', 'odooflow') . '</h2>
                    <div class="odooflow-modal-body">
                        <form id="odooflow-create-order-form">
                            <div class="form-field">
                                <label for="odoo-customer">' . __('Customer', 'odooflow') . '</label>
                                <div class="customer-select-wrapper">
                                    <select id="odoo-customer" name="odoo_customer" class="widefat">
                                        <option value="">' . __('Select a customer...', 'odooflow') . '</option>
                                    </select>
                                    <div class="spinner" style="display:none;"></div>
                                </div>
                                <p class="description">' . __('Select the customer in Odoo to associate with this order.', 'odooflow') . '</p>
                            </div>

                            <div class="form-field">
                                <label>' . __('Type', 'odooflow') . '</label>
                                <div class="order-type-wrapper">
                                    <label class="radio-label">
                                        <input type="radio" name="order_type" value="quote" checked>
                                        ' . __('Quote', 'odooflow') . '
                                    </label>
                                    <label class="radio-label">
                                        <input type="radio" name="order_type" value="sale">
                                        ' . __('Sales Order', 'odooflow') . '
                                    </label>
                                </div>
                            </div>

                            <div class="form-field product-lines">
                                <label>' . __('Products', 'odooflow') . '</label>
                                <div class="product-lines-container">
                                    <div class="product-line">
                                        <div class="product-select-wrapper">
                                            <select name="odoo_products[]" class="widefat odoo-product">
                                                <option value="">' . __('Select a product...', 'odooflow') . '</option>
                                            </select>
                                            <div class="spinner" style="display:none;"></div>
                                        </div>
                                        <div class="product-details">
                                            <input type="number" name="quantities[]" class="quantity" min="1" step="1" placeholder="' . __('Qty', 'odooflow') . '">
                                            <input type="number" name="prices[]" class="price" min="0" step="0.01" placeholder="' . __('Price', 'odooflow') . '">
                                            <button type="button" class="button remove-product" title="' . __('Remove product', 'odooflow') . '">×</button>
                                        </div>
                                    </div>
                                </div>
                                <button type="button" class="button add-product">' . __('Add Another Product', 'odooflow') . '</button>
                            </div>
                        </form>
                    </div>
                    <div class="odooflow-modal-footer">
                        <button type="button" class="button cancel-create-order">' . __('Cancel', 'odooflow') . '</button>
                        <button type="button" class="button button-primary create-order-submit" disabled>' . 
                            __('Create Order', 'odooflow') . 
                        '</button>
                    </div>
                </div>
            </div>';
        }
        
        echo '</div>';
    }

    /**
     * AJAX handler for syncing order to Odoo
     */
    public function ajax_sync_order_to_odoo() {
        check_ajax_referer('odooflow_metabox_nonce', 'nonce');

        if (!current_user_can('edit_shop_orders')) {
            wp_send_json_error(array('message' => __('You do not have permission to perform this action.', 'odooflow')));
        }

        $order_id = isset($_POST['order_id']) ? absint($_POST['order_id']) : 0;
        if (!$order_id) {
            wp_send_json_error(array('message' => __('Invalid order ID.', 'odooflow')));
        }

        $order = wc_get_order($order_id);
        if (!$order) {
            wp_send_json_error(array('message' => __('Order not found.', 'odooflow')));
        }

        $result = $this->sync_order_to_odoo($order);
        if (is_wp_error($result)) {
            wp_send_json_error(array('message' => $result->get_error_message()));
        }

        wp_send_json_success(array(
            'message' => sprintf(
                /* translators: %s: Odoo order ID */
                __('Order successfully synced to Odoo (ID: %s)', 'odooflow'),
                $result
            )
        ));
    }

    /**
     * AJAX handler for fetching Odoo customers
     */
    public function ajax_get_odoo_customers() {
        check_ajax_referer('odooflow_metabox_nonce', 'nonce');

        if (!current_user_can('edit_shop_orders')) {
            wp_send_json_error(array('message' => __('You do not have permission to perform this action.', 'odooflow')));
        }

        $odoo_url = get_option('odooflow_odoo_url', '');
        $username = get_option('odooflow_username', '');
        $api_key = get_option('odooflow_api_key', '');
        $database = get_option('odooflow_database', '');

        if (empty($odoo_url) || empty($username) || empty($api_key) || empty($database)) {
            wp_send_json_error(array('message' => __('Odoo connection settings are incomplete.', 'odooflow')));
        }

        try {
            // Authenticate with Odoo
            $auth_result = $this->authenticate_odoo($odoo_url, $database, $username, $api_key);
            if (is_wp_error($auth_result)) {
                throw new Exception($auth_result->get_error_message());
            }
            $uid = $auth_result;

            // Search for customers
            $request = xmlrpc_encode_request('execute_kw', array(
                $database,
                $uid,
                $api_key,
                'res.partner',
                'search_read',
                array(
                    array(
                        array('customer_rank', '>', 0),
                        array('active', '=', true)
                    )
                ),
                array(
                    'fields' => array('id', 'name', 'email', 'phone'),
                    'order' => 'name ASC'
                )
            ));

            $response = wp_remote_post(rtrim($odoo_url, '/') . '/xmlrpc/2/object', array(
                'body' => $request,
                'headers' => array('Content-Type' => 'text/xml'),
                'timeout' => 30,
                'sslverify' => false
            ));

            if (is_wp_error($response)) {
                throw new Exception($response->get_error_message());
            }

            $customers = xmlrpc_decode(wp_remote_retrieve_body($response));
            if (!is_array($customers)) {
                throw new Exception(__('Invalid response from Odoo', 'odooflow'));
            }

            wp_send_json_success(array('customers' => $customers));

        } catch (Exception $e) {
            wp_send_json_error(array('message' => $e->getMessage()));
        }
    }

    /**
     * AJAX handler for fetching Odoo products
     */
    public function ajax_get_odoo_products_for_order() {
        check_ajax_referer('odooflow_metabox_nonce', 'nonce');

        if (!current_user_can('edit_shop_orders')) {
            wp_send_json_error(array('message' => __('You do not have permission to perform this action.', 'odooflow')));
        }

        $odoo_url = get_option('odooflow_odoo_url', '');
        $username = get_option('odooflow_username', '');
        $api_key = get_option('odooflow_api_key', '');
        $database = get_option('odooflow_database', '');

        if (empty($odoo_url) || empty($username) || empty($api_key) || empty($database)) {
            wp_send_json_error(array('message' => __('Odoo connection settings are incomplete.', 'odooflow')));
        }

        try {
            // Authenticate with Odoo
            $auth_result = $this->authenticate_odoo($odoo_url, $database, $username, $api_key);
            if (is_wp_error($auth_result)) {
                throw new Exception($auth_result->get_error_message());
            }
            $uid = $auth_result;

            // Search for products
            $request = xmlrpc_encode_request('execute_kw', array(
                $database,
                $uid,
                $api_key,
                'product.product',
                'search_read',
                array(
                    array(
                        array('sale_ok', '=', true),
                        array('active', '=', true)
                    )
                ),
                array(
                    'fields' => array('id', 'name', 'list_price', 'default_code'),
                    'order' => 'name ASC'
                )
            ));

            $response = wp_remote_post(rtrim($odoo_url, '/') . '/xmlrpc/2/object', array(
                'body' => $request,
                'headers' => array('Content-Type' => 'text/xml'),
                'timeout' => 30,
                'sslverify' => false
            ));

            if (is_wp_error($response)) {
                throw new Exception($response->get_error_message());
            }

            $products = xmlrpc_decode(wp_remote_retrieve_body($response));
            if (!is_array($products)) {
                throw new Exception(__('Invalid response from Odoo', 'odooflow'));
            }

            wp_send_json_success(array('products' => $products));

        } catch (Exception $e) {
            wp_send_json_error(array('message' => $e->getMessage()));
        }
    }

    /**
     * AJAX handler for creating order in Odoo
     */
    public function ajax_create_odoo_order() {
        check_ajax_referer('odooflow_metabox_nonce', 'nonce');

        if (!current_user_can('edit_shop_orders')) {
            wp_send_json_error(array('message' => __('You do not have permission to perform this action.', 'odooflow')));
        }

        $order_id = isset($_POST['order_id']) ? absint($_POST['order_id']) : 0;
        $customer_id = isset($_POST['customer_id']) ? absint($_POST['customer_id']) : 0;
        $order_type = isset($_POST['order_type']) ? sanitize_text_field($_POST['order_type']) : 'quote';
        $products = isset($_POST['products']) ? json_decode(stripslashes($_POST['products']), true) : array();

        if (!$order_id || !$customer_id || empty($products)) {
            wp_send_json_error(array('message' => __('Missing required data.', 'odooflow')));
        }

        $order = wc_get_order($order_id);
        if (!$order) {
            wp_send_json_error(array('message' => __('WooCommerce order not found.', 'odooflow')));
        }

        $odoo_url = get_option('odooflow_odoo_url', '');
        $username = get_option('odooflow_username', '');
        $api_key = get_option('odooflow_api_key', '');
        $database = get_option('odooflow_database', '');

        if (empty($odoo_url) || empty($username) || empty($api_key) || empty($database)) {
            wp_send_json_error(array('message' => __('Odoo connection settings are incomplete.', 'odooflow')));
        }

        try {
            // Authenticate with Odoo
            $auth_result = $this->authenticate_odoo($odoo_url, $database, $username, $api_key);
            if (is_wp_error($auth_result)) {
                throw new Exception($auth_result->get_error_message());
            }
            $uid = $auth_result;

            // Prepare order lines
            $order_lines = array();
            foreach ($products as $product) {
                $order_lines[] = array(0, 0, array(
                    'product_id' => $product['id'],
                    'product_uom_qty' => $product['quantity'],
                    'price_unit' => $product['price']
                ));
            }

            // Create order data
            $order_data = array(
                'partner_id' => $customer_id,
                'order_line' => $order_lines,
                'client_order_ref' => $order->get_order_number(),
                'origin' => 'WooCommerce Order #' . $order->get_order_number()
            );

            // If this is a sales order, set the state
            if ($order_type === 'sale') {
                $order_data['state'] = 'sale';
            }

            // Create order in Odoo
            $request = xmlrpc_encode_request('execute_kw', array(
                $database,
                $uid,
                $api_key,
                'sale.order',
                'create',
                array($order_data)
            ));

            $response = wp_remote_post(rtrim($odoo_url, '/') . '/xmlrpc/2/object', array(
                'body' => $request,
                'headers' => array('Content-Type' => 'text/xml'),
                'timeout' => 30,
                'sslverify' => false
            ));

            if (is_wp_error($response)) {
                throw new Exception($response->get_error_message());
            }

            $odoo_order_id = xmlrpc_decode(wp_remote_retrieve_body($response));
            if (!is_numeric($odoo_order_id)) {
                throw new Exception(__('Invalid response from Odoo', 'odooflow'));
            }

            // If this is a sales order, confirm it
            if ($order_type === 'sale') {
                $confirm_request = xmlrpc_encode_request('execute_kw', array(
                    $database,
                    $uid,
                    $api_key,
                    'sale.order',
                    'action_confirm',
                    array(array($odoo_order_id))
                ));

                $confirm_response = wp_remote_post(rtrim($odoo_url, '/') . '/xmlrpc/2/object', array(
                    'body' => $confirm_request,
                    'headers' => array('Content-Type' => 'text/xml'),
                    'timeout' => 30,
                    'sslverify' => false
                ));

                if (is_wp_error($confirm_response)) {
                    throw new Exception($confirm_response->get_error_message());
                }
            }

            // Save Odoo order ID to WooCommerce order
            $order->update_meta_data('_odoo_order_id', $odoo_order_id);
            $order->save();

            wp_send_json_success(array(
                'message' => sprintf(
                    /* translators: %s: Odoo order ID */
                    __('Order successfully created in Odoo (ID: %s)', 'odooflow'),
                    $odoo_order_id
                )
            ));

        } catch (Exception $e) {
            wp_send_json_error(array('message' => $e->getMessage()));
        }
    }
}

// Initialize the plugin
function OdooFlow() {
    return OdooFlow::instance();
}
$GLOBALS['odooflow'] = OdooFlow();