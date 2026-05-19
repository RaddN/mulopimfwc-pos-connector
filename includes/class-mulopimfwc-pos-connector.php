<?php
if (!defined('ABSPATH')) {
    exit;
}

class MULOPIMFWC_POS_Connector
{
    const OPTION_ENABLED = 'mulopimfwc_pos_connector_enabled';
    const OPTION_OPENPOS_MAPPINGS = 'mulopimfwc_pos_openpos_mappings';
    const OPTION_WCPOS_LEGACY_MAPPINGS = 'mulopimfwc_pos_wcpos_mappings';
    const OPTION_WCPOS_LAST_WARNING = 'mulopimfwc_pos_wcpos_last_warning';

    private static $instance = null;
    private $openpos_provider = null;
    private $wcpos_provider = null;

    public static function instance()
    {
        if (self::$instance === null) {
            self::$instance = new self();
        }

        return self::$instance;
    }

    private function __construct()
    {
        add_action('plugins_loaded', [$this, 'init'], 30);
        add_action('admin_menu', [$this, 'register_admin_page'], 80);
        add_action('admin_init', [$this, 'save_settings']);
        add_action('admin_notices', [$this, 'dependency_notice']);
        add_filter('plugin_action_links_' . plugin_basename(MULOPIMFWC_POS_CONNECTOR_FILE), [$this, 'plugin_action_links']);
    }

    public function init()
    {
        $this->openpos_provider = new MULOPIMFWC_POS_OpenPOS_Provider();
        $this->openpos_provider->init();

        $this->wcpos_provider = new MULOPIMFWC_POS_WCPOS_Provider();
        $this->wcpos_provider->init();
    }

    public function register_admin_page()
    {
        $parent_slug = defined('MULOPIMFWC_VERSION') ? 'multi-location-product-and-inventory-management-pro' : 'woocommerce';

        add_submenu_page(
            $parent_slug,
            __('POS Connector', 'mulopimfwc-pos-connector'),
            __('POS Connector', 'mulopimfwc-pos-connector'),
            'manage_options',
            'mulopimfwc-pos-connector',
            [$this, 'render_settings_page']
        );
    }

    public function plugin_action_links($links)
    {
        $settings_url = admin_url('admin.php?page=mulopimfwc-pos-connector');
        array_unshift($links, '<a href="' . esc_url($settings_url) . '">' . esc_html__('Settings', 'mulopimfwc-pos-connector') . '</a>');
        return $links;
    }

    public function dependency_notice()
    {
        if (!current_user_can('activate_plugins')) {
            return;
        }

        $missing = [];

        if (!class_exists('WooCommerce')) {
            $missing[] = __('WooCommerce', 'mulopimfwc-pos-connector');
        }

        if (!defined('MULOPIMFWC_VERSION')) {
            $missing[] = __('Multi Location Product & Inventory Management Pro', 'mulopimfwc-pos-connector');
        }

        $openpos_provider = $this->openpos_provider instanceof MULOPIMFWC_POS_OpenPOS_Provider
            ? $this->openpos_provider
            : new MULOPIMFWC_POS_OpenPOS_Provider();

        $wcpos_provider = $this->wcpos_provider instanceof MULOPIMFWC_POS_WCPOS_Provider
            ? $this->wcpos_provider
            : new MULOPIMFWC_POS_WCPOS_Provider();

        if (!$openpos_provider->is_available() && !$wcpos_provider->is_available()) {
            $missing[] = __('OpenPOS or WCPOS', 'mulopimfwc-pos-connector');
        }

        if (empty($missing)) {
            return;
        }

        echo '<div class="notice notice-warning"><p>';
        printf(
            esc_html__('Multi Location POS Connector is installed safely, but these dependencies are inactive or unavailable: %s.', 'mulopimfwc-pos-connector'),
            esc_html(implode(', ', $missing))
        );
        echo '</p></div>';
    }

    public function save_settings()
    {
        if (!isset($_POST['mulopimfwc_pos_connector_nonce'])) {
            return;
        }

        if (!current_user_can('manage_options')) {
            return;
        }

        check_admin_referer('mulopimfwc_pos_connector_save', 'mulopimfwc_pos_connector_nonce');

        $enabled = isset($_POST['mulopimfwc_pos_connector_enabled']) ? 'yes' : 'no';
        update_option(self::OPTION_ENABLED, $enabled);

        $raw_mappings = isset($_POST['mulopimfwc_openpos_mapping']) && is_array($_POST['mulopimfwc_openpos_mapping'])
            ? wp_unslash($_POST['mulopimfwc_openpos_mapping'])
            : [];

        $mappings = [];
        foreach ($raw_mappings as $warehouse_id => $location_id) {
            $warehouse_id = (string) absint($warehouse_id);
            $location_id = absint($location_id);

            if ($location_id <= 0) {
                continue;
            }

            $location = get_term($location_id, 'mulopimfwc_store_location');
            if (!$location || is_wp_error($location)) {
                continue;
            }

            $mappings[$warehouse_id] = $location_id;
        }

        update_option(self::OPTION_OPENPOS_MAPPINGS, $mappings);

        add_settings_error('mulopimfwc_pos_connector', 'saved', __('POS connector settings saved.', 'mulopimfwc-pos-connector'), 'updated');
    }

    public function render_settings_page()
    {
        if (!current_user_can('manage_options')) {
            wp_die(esc_html__('You do not have permission to manage POS connector settings.', 'mulopimfwc-pos-connector'));
        }

        settings_errors('mulopimfwc_pos_connector');

        $enabled = get_option(self::OPTION_ENABLED, 'yes') === 'yes';
        $provider = $this->openpos_provider instanceof MULOPIMFWC_POS_OpenPOS_Provider
            ? $this->openpos_provider
            : new MULOPIMFWC_POS_OpenPOS_Provider();
        $openpos_active = $provider->is_available();
        $warehouses = $openpos_active ? $provider->get_warehouses() : [];
        $mappings = $provider->get_mappings();

        $wcpos_provider = $this->wcpos_provider instanceof MULOPIMFWC_POS_WCPOS_Provider
            ? $this->wcpos_provider
            : new MULOPIMFWC_POS_WCPOS_Provider();
        $wcpos_active = $wcpos_provider->is_available();
        $wcpos_stores = $wcpos_active ? $wcpos_provider->get_stores() : [];
        $wcpos_warning = $wcpos_provider->get_last_warning();
        $locations = taxonomy_exists('mulopimfwc_store_location')
            ? get_terms([
                'taxonomy' => 'mulopimfwc_store_location',
                'hide_empty' => false,
            ])
            : [];
        ?>
        <div class="wrap mulopimfwc-pos-connector-page">
            <h1><?php echo esc_html__('Multi Location POS Connector', 'mulopimfwc-pos-connector'); ?></h1>
            <p><?php echo esc_html__('Connect POS inventory contexts to Multi Location stock and pricing without changing POS core files.', 'mulopimfwc-pos-connector'); ?></p>

            <?php if (!$openpos_active && !$wcpos_active) : ?>
                <div class="notice notice-warning">
                    <p><?php echo esc_html__('No supported POS plugin is active. The connector is installed safely, but no POS integration hooks are running.', 'mulopimfwc-pos-connector'); ?></p>
                </div>
            <?php endif; ?>

            <?php if (isset($mappings['0'])) : ?>
                <div class="notice notice-info">
                    <p><?php echo esc_html__('The OpenPOS default online store outlet is mapped. The connector will protect WooCommerce global stock by reducing mapped location stock before OpenPOS stock reduction runs.', 'mulopimfwc-pos-connector'); ?></p>
                </div>
            <?php endif; ?>

            <?php if (!empty($wcpos_warning['message'])) : ?>
                <div class="notice notice-warning">
                    <p>
                        <?php
                        printf(
                            esc_html__('WCPOS notice: %s', 'mulopimfwc-pos-connector'),
                            esc_html((string) $wcpos_warning['message'])
                        );
                        ?>
                    </p>
                </div>
            <?php endif; ?>

            <form method="post">
                <?php wp_nonce_field('mulopimfwc_pos_connector_save', 'mulopimfwc_pos_connector_nonce'); ?>

                <table class="form-table" role="presentation">
                    <tr>
                        <th scope="row"><?php echo esc_html__('Connector Status', 'mulopimfwc-pos-connector'); ?></th>
                        <td>
                            <label>
                                <input type="checkbox" name="mulopimfwc_pos_connector_enabled" value="yes" <?php checked($enabled); ?>>
                                <?php echo esc_html__('Enable POS connector hooks', 'mulopimfwc-pos-connector'); ?>
                            </label>
                        </td>
                    </tr>
                </table>

                <h2><?php echo esc_html__('OpenPOS Outlet Mapping', 'mulopimfwc-pos-connector'); ?></h2>

                <?php if (!$openpos_active) : ?>
                    <p><?php echo esc_html__('Activate OpenPOS to configure outlet mappings.', 'mulopimfwc-pos-connector'); ?></p>
                <?php elseif (empty($warehouses)) : ?>
                    <p><?php echo esc_html__('No OpenPOS outlets were found.', 'mulopimfwc-pos-connector'); ?></p>
                <?php else : ?>
                    <table class="widefat striped mulopimfwc-openpos-mapping-table">
                        <thead>
                            <tr>
                                <th><?php echo esc_html__('OpenPOS Outlet', 'mulopimfwc-pos-connector'); ?></th>
                                <th><?php echo esc_html__('Outlet ID', 'mulopimfwc-pos-connector'); ?></th>
                                <th><?php echo esc_html__('Store Location', 'mulopimfwc-pos-connector'); ?></th>
                                <th><?php echo esc_html__('Behavior', 'mulopimfwc-pos-connector'); ?></th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($warehouses as $warehouse) : ?>
                                <?php
                                $warehouse_id = isset($warehouse['id']) ? absint($warehouse['id']) : 0;
                                $selected_location = isset($mappings[(string) $warehouse_id]) ? absint($mappings[(string) $warehouse_id]) : 0;
                                ?>
                                <tr>
                                    <td>
                                        <strong><?php echo esc_html(isset($warehouse['name']) ? (string) $warehouse['name'] : __('Unnamed outlet', 'mulopimfwc-pos-connector')); ?></strong>
                                        <?php if ($warehouse_id === 0) : ?>
                                            <span class="description"><?php echo esc_html__('Default outlet', 'mulopimfwc-pos-connector'); ?></span>
                                        <?php endif; ?>
                                    </td>
                                    <td><?php echo esc_html((string) $warehouse_id); ?></td>
                                    <td>
                                        <select name="mulopimfwc_openpos_mapping[<?php echo esc_attr((string) $warehouse_id); ?>]">
                                            <option value="0"><?php echo esc_html__('Do not map', 'mulopimfwc-pos-connector'); ?></option>
                                            <?php foreach ($locations as $location) : ?>
                                                <option value="<?php echo esc_attr((string) $location->term_id); ?>" <?php selected($selected_location, $location->term_id); ?>>
                                                    <?php echo esc_html($location->name); ?>
                                                </option>
                                            <?php endforeach; ?>
                                        </select>
                                    </td>
                                    <td>
                                        <?php if ($selected_location > 0) : ?>
                                            <span class="mulopimfwc-pos-status mulopimfwc-pos-status--mapped"><?php echo esc_html__('Uses mapped location stock and price', 'mulopimfwc-pos-connector'); ?></span>
                                        <?php else : ?>
                                            <span class="mulopimfwc-pos-status"><?php echo esc_html__('OpenPOS default behavior', 'mulopimfwc-pos-connector'); ?></span>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                <?php endif; ?>

                <h2><?php echo esc_html__('WCPOS Store Inventory Connector', 'mulopimfwc-pos-connector'); ?></h2>

                <?php if (!$wcpos_active) : ?>
                    <p><?php echo esc_html__('Activate WCPOS - Point of Sale for WooCommerce to enable the WCPOS integration.', 'mulopimfwc-pos-connector'); ?></p>
                <?php elseif (empty($wcpos_stores)) : ?>
                    <p><?php echo esc_html__('No WCPOS stores were found.', 'mulopimfwc-pos-connector'); ?></p>
                <?php else : ?>
                    <p><?php echo esc_html__('WCPOS does not use OpenPOS-style outlets. This connector follows the WCPOS/ATUM model: each WCPOS Pro store can be linked to one Multi Location inventory in the WCPOS store editor, and only mapped store requests use location stock and pricing.', 'mulopimfwc-pos-connector'); ?></p>

                    <?php if (!$wcpos_provider->has_mappable_stores($wcpos_stores)) : ?>
                        <div class="notice notice-warning inline">
                            <p><?php echo esc_html__('This WCPOS install currently exposes only the default Store ID 0. The default store cannot safely represent multiple physical inventories, so the connector leaves it on normal WCPOS/WooCommerce stock behavior. Use WCPOS Pro stores to map each store to a Multi Location inventory.', 'mulopimfwc-pos-connector'); ?></p>
                        </div>
                    <?php endif; ?>

                    <table class="widefat striped mulopimfwc-wcpos-store-table">
                        <thead>
                            <tr>
                                <th><?php echo esc_html__('WCPOS Store', 'mulopimfwc-pos-connector'); ?></th>
                                <th><?php echo esc_html__('Store ID', 'mulopimfwc-pos-connector'); ?></th>
                                <th><?php echo esc_html__('Multi Location Inventory', 'mulopimfwc-pos-connector'); ?></th>
                                <th><?php echo esc_html__('Pricing Source', 'mulopimfwc-pos-connector'); ?></th>
                                <th><?php echo esc_html__('Behavior', 'mulopimfwc-pos-connector'); ?></th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($wcpos_stores as $store) : ?>
                                <?php
                                $store_id = isset($store['id']) ? absint($store['id']) : 0;
                                $store_summary = $wcpos_provider->get_store_location_summary($store_id);
                                ?>
                                <tr>
                                    <td>
                                        <strong><?php echo esc_html(isset($store['name']) && $store['name'] !== '' ? (string) $store['name'] : __('Default WCPOS Store', 'mulopimfwc-pos-connector')); ?></strong>
                                        <?php if ($store_id === 0) : ?>
                                            <span class="description"><?php echo esc_html__('Default store', 'mulopimfwc-pos-connector'); ?></span>
                                        <?php endif; ?>
                                    </td>
                                    <td><?php echo esc_html((string) $store_id); ?></td>
                                    <td>
                                        <?php echo esc_html($store_summary['location_name']); ?>
                                    </td>
                                    <td><?php echo esc_html($store_summary['pricing_source_label']); ?></td>
                                    <td>
                                        <?php if ($store_summary['mapped']) : ?>
                                            <span class="mulopimfwc-pos-status mulopimfwc-pos-status--mapped"><?php echo esc_html__('Uses mapped location stock and price', 'mulopimfwc-pos-connector'); ?></span>
                                        <?php elseif ($store_id === 0) : ?>
                                            <span class="mulopimfwc-pos-status"><?php echo esc_html__('Default WCPOS behavior', 'mulopimfwc-pos-connector'); ?></span>
                                        <?php else : ?>
                                            <span class="mulopimfwc-pos-status"><?php echo esc_html__('Configure in WCPOS store editor', 'mulopimfwc-pos-connector'); ?></span>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                    <p class="description"><?php echo esc_html__('Open each WCPOS Pro store and use the Multi Location Inventory section in the sidebar. Store ID 0 is intentionally not mapped because it cannot distinguish multiple physical inventories.', 'mulopimfwc-pos-connector'); ?></p>
                <?php endif; ?>

                <?php submit_button(__('Save POS Connector Settings', 'mulopimfwc-pos-connector')); ?>
            </form>
        </div>
        <style>
            .mulopimfwc-openpos-mapping-table select,
            .mulopimfwc-wcpos-store-table select {
                min-width: 240px;
            }
            .mulopimfwc-pos-status {
                background: #f0f0f1;
                border-radius: 999px;
                color: #50575e;
                display: inline-block;
                font-size: 12px;
                font-weight: 600;
                line-height: 1;
                padding: 7px 10px;
            }
            .mulopimfwc-pos-status--mapped {
                background: #edfaef;
                color: #0a6b21;
            }
        </style>
        <?php
    }
}

class MULOPIMFWC_POS_OpenPOS_Provider
{
    private $mappings = null;
    private $current_query_warehouse_id = null;
    private $default_stock_before_update = [];

    public function init()
    {
        if (!$this->is_enabled() || !$this->is_available()) {
            return;
        }

        add_filter('op_warehouse_get_qty', [$this, 'filter_warehouse_qty'], 20, 4);
        add_filter('op_warehouse_is_instore', [$this, 'filter_warehouse_is_instore'], 20, 4);
        add_filter('op_load_product_args', [$this, 'capture_load_product_args'], 20, 1);
        add_filter('op_get_products_args', [$this, 'filter_product_query_args'], 20, 1);
        add_filter('op_get_products_s_args', [$this, 'filter_product_query_args'], 20, 1);
        add_filter('op_product_data', [$this, 'filter_product_data'], 20, 3);
        add_filter('op_new_order_data', [$this, 'stamp_order_data'], 20, 2);
        add_filter('op_get_online_order_data', [$this, 'filter_formatted_order_data'], 20, 2);
        add_filter('op_order_item_data_before', [$this, 'prepare_order_item_data'], 20, 2);
        add_action('op_add_order_item_meta', [$this, 'stamp_order_item_meta'], 20, 2);
        add_action('op_add_order_final_after', [$this, 'stamp_created_order_location'], 4, 1);
        add_action('op_add_order_final_after', [$this, 'reduce_mapped_location_stock'], 5, 1);
        add_action('op_woocommerce_cancelled_order', [$this, 'restore_mapped_location_stock'], 5, 2);
        add_action('woocommerce_order_status_cancelled', [$this, 'restore_mapped_location_stock_from_wc_status'], 5, 2);
        add_action('op_before_update_warehouse_qty', [$this, 'capture_before_openpos_stock_update'], 20, 3);
        add_action('op_after_update_warehouse_qty', [$this, 'sync_after_openpos_stock_update'], 20, 3);
    }

    public function is_enabled(): bool
    {
        return get_option(MULOPIMFWC_POS_Connector::OPTION_ENABLED, 'yes') === 'yes';
    }

    public function is_available(): bool
    {
        return class_exists('OP_Warehouse') || defined('OPENPOS_DIR');
    }

    public function get_mappings(): array
    {
        if ($this->mappings !== null) {
            return $this->mappings;
        }

        $saved = get_option(MULOPIMFWC_POS_Connector::OPTION_OPENPOS_MAPPINGS, []);
        $mappings = [];

        if (is_array($saved)) {
            foreach ($saved as $warehouse_id => $location_id) {
                $warehouse_id = (string) absint($warehouse_id);
                $location_id = absint($location_id);

                if ($location_id > 0) {
                    $mappings[$warehouse_id] = $location_id;
                }
            }
        }

        $this->mappings = $mappings;
        return $this->mappings;
    }

    public function get_warehouses(): array
    {
        global $op_warehouse;

        if (is_object($op_warehouse) && method_exists($op_warehouse, 'warehouses')) {
            $warehouses = $op_warehouse->warehouses();
            return is_array($warehouses) ? $warehouses : [];
        }

        $warehouses = [
            [
                'id' => 0,
                'name' => __('Default online store', 'mulopimfwc-pos-connector'),
                'status' => 'publish',
            ],
        ];

        $posts = get_posts([
            'post_type' => '_op_warehouse',
            'post_status' => ['publish', 'draft'],
            'numberposts' => -1,
        ]);

        foreach ($posts as $post) {
            $warehouses[] = [
                'id' => (int) $post->ID,
                'name' => $post->post_title,
                'status' => $post->post_status,
            ];
        }

        return $warehouses;
    }

    public function filter_warehouse_qty($qty, $warehouse_id, $product_id, $warehouse)
    {
        if (!$this->is_location_stock_enabled()) {
            return $qty;
        }

        $location_id = $this->get_location_id_for_warehouse($warehouse_id);
        if (!$location_id) {
            return $qty;
        }

        $product = wc_get_product($product_id);
        if (!$product) {
            return $qty;
        }

        $location_stock = $this->get_location_stock($product, $location_id);
        return $location_stock === null ? $qty : $location_stock;
    }

    public function filter_warehouse_is_instore($is_instore, $warehouse_id, $product_id, $warehouse)
    {
        $location_id = $this->get_location_id_for_warehouse($warehouse_id);
        if (!$location_id) {
            return $is_instore;
        }

        return $this->product_is_assigned_to_location((int) $product_id, $location_id);
    }

    public function capture_load_product_args($args)
    {
        global $op_session_data;

        if (isset($args['warehouse_id'])) {
            $this->current_query_warehouse_id = absint($args['warehouse_id']);
        } elseif (is_array($op_session_data) && isset($op_session_data['login_warehouse_id'])) {
            $this->current_query_warehouse_id = absint($op_session_data['login_warehouse_id']);
        } else {
            $this->current_query_warehouse_id = 0;
        }

        return $args;
    }

    public function filter_product_query_args($args)
    {
        $warehouse_id = is_array($args) && isset($args['warehouse_id'])
            ? absint($args['warehouse_id'])
            : ($this->current_query_warehouse_id !== null ? absint($this->current_query_warehouse_id) : 0);
        $location_id = $this->get_location_id_for_warehouse($warehouse_id);

        if (!$location_id || !is_array($args)) {
            return $args;
        }

        if (!empty($args['meta_query']) && is_array($args['meta_query'])) {
            $args['meta_query'] = $this->remove_openpos_warehouse_visibility_meta_query($args['meta_query'], $warehouse_id);
            if (empty($args['meta_query'])) {
                unset($args['meta_query']);
            }
        }

        $location_tax_query = $this->build_location_tax_query($location_id);

        if (empty($args['tax_query']) || !is_array($args['tax_query'])) {
            $args['tax_query'] = [$location_tax_query];
            return $args;
        }

        if (!isset($args['tax_query']['relation'])) {
            $args['tax_query']['relation'] = 'AND';
        }

        $args['tax_query'][] = $location_tax_query;

        return $args;
    }

    public function filter_product_data($data, $_product, $warehouse_id)
    {
        $location_id = $this->get_location_id_for_warehouse($warehouse_id);
        if (!$location_id || !is_array($data)) {
            return $data;
        }

        $product_id = $this->resolve_product_id($_product, $data);
        if ($product_id <= 0) {
            return $data;
        }

        $data = $this->apply_location_payload($data, $product_id, 0, $location_id);

        if (isset($data['child_products']) && is_array($data['child_products'])) {
            foreach ($data['child_products'] as $key => $child_product) {
                if (!is_array($child_product)) {
                    continue;
                }

                $variation_id = isset($child_product['id']) ? absint($child_product['id']) : absint($key);
                if ($variation_id > 0) {
                    $data['child_products'][$key] = $this->apply_location_payload($child_product, $variation_id, $product_id, $location_id);
                }
            }
        }

        $data = $this->sync_variable_payload_from_location($data, $product_id, $location_id);

        return $data;
    }

    public function stamp_order_data($order_data, $session_data)
    {
        $warehouse_id = isset($session_data['login_warehouse_id']) ? absint($session_data['login_warehouse_id']) : 0;
        $location = $this->get_location_for_warehouse($warehouse_id);

        if (!$location) {
            return $order_data;
        }

        if (!isset($order_data['addition_information']) || !is_array($order_data['addition_information'])) {
            $order_data['addition_information'] = [];
        }

        $order_data['addition_information']['mulopimfwc_pos_location'] = [
            'id' => (int) $location->term_id,
            'slug' => (string) $location->slug,
            'name' => (string) $location->name,
        ];

        return $order_data;
    }

    public function filter_formatted_order_data($order_data, $order)
    {
        if (!is_array($order_data) || !is_object($order) || !method_exists($order, 'get_meta')) {
            return $order_data;
        }

        $location_id = absint($order->get_meta('_mulopimfwc_pos_location_id'));
        if (!$location_id) {
            return $order_data;
        }

        $location = get_term($location_id, 'mulopimfwc_store_location');
        if (!$location || is_wp_error($location)) {
            return $order_data;
        }

        if (!isset($order_data['addition_information']) || !is_array($order_data['addition_information'])) {
            $order_data['addition_information'] = [];
        }

        $order_data['addition_information']['mulopimfwc_pos_location'] = [
            'id' => (int) $location->term_id,
            'slug' => (string) $location->slug,
            'name' => (string) $location->name,
        ];

        return $order_data;
    }

    public function prepare_order_item_data($item_data, $order_parse_data)
    {
        if (!is_array($item_data)) {
            return $item_data;
        }

        $location = $this->get_location_from_order_parse_data($order_parse_data);
        if (!$location) {
            return $item_data;
        }

        $item_data['mulopimfwc_location'] = (string) $location->slug;
        $item_data['mulopimfwc_location_id'] = (int) $location->term_id;

        return $item_data;
    }

    public function stamp_order_item_meta($order_item, $item_data)
    {
        if (!is_object($order_item) || !is_array($item_data) || empty($item_data['mulopimfwc_location'])) {
            return;
        }

        $order_item->add_meta_data('_mulopimfwc_location', sanitize_title((string) $item_data['mulopimfwc_location']), true);
        $order_item->add_meta_data('_store_location', sanitize_title((string) $item_data['mulopimfwc_location']), true);
        $order_item->add_meta_data('_mulopimfwc_pos_location_id', absint($item_data['mulopimfwc_location_id'] ?? 0), true);
    }

    public function stamp_created_order_location($order_data)
    {
        $warehouse_id = $this->resolve_current_warehouse_id($order_data);
        $location = $this->get_location_for_warehouse($warehouse_id);

        if (!$location) {
            return;
        }

        $order_id = $this->resolve_order_id($order_data);
        if ($order_id <= 0) {
            return;
        }

        $order = wc_get_order($order_id);
        if (!$order) {
            return;
        }

        $order->update_meta_data('_store_location', (string) $location->slug);
        $order->update_meta_data('_mulopimfwc_pos_location_id', (int) $location->term_id);
        $order->update_meta_data('_mulopimfwc_pos_provider', 'openpos');
        $order->update_meta_data('_mulopimfwc_pos_openpos_warehouse', (int) $warehouse_id);

        foreach ($order->get_items('line_item') as $item) {
            if (!(string) $item->get_meta('_mulopimfwc_location', true)) {
                $item->update_meta_data('_mulopimfwc_location', (string) $location->slug);
            }

            $item->update_meta_data('_store_location', (string) $location->slug);
            $item->update_meta_data('_mulopimfwc_pos_location_id', (int) $location->term_id);
            $item->update_meta_data('_mulopimfwc_pos_openpos_warehouse', (int) $warehouse_id);
            $item->save();
        }

        $order->save();
    }

    public function reduce_mapped_location_stock($order_data)
    {
        if (!$this->is_location_stock_enabled()) {
            return;
        }

        $warehouse_id = $this->resolve_current_warehouse_id($order_data);
        $location = $this->get_location_for_warehouse($warehouse_id);

        if (!$location) {
            return;
        }

        $order_id = $this->resolve_order_id($order_data);
        if ($order_id <= 0) {
            return;
        }

        $order = wc_get_order($order_id);
        if (!$order) {
            return;
        }

        $changed_products = [];

        foreach ($order->get_items('line_item') as $item) {
            if ($item->get_meta('_mulopimfwc_pos_stock_reduced', true)) {
                continue;
            }

            $product = $item->get_product();
            if (!$product) {
                continue;
            }

            $target_id = $this->get_order_item_stock_product_id($item, $product);
            if ($target_id <= 0) {
                continue;
            }

            if (!$this->product_is_assigned_to_location($target_id, (int) $location->term_id)) {
                continue;
            }

            $quantity = $this->get_order_item_stock_quantity($order, $item);
            if ($quantity <= 0) {
                continue;
            }

            $new_stock = $this->apply_location_stock_delta($target_id, (int) $location->term_id, $quantity);
            $item->update_meta_data('_mulopimfwc_location', (string) $location->slug);
            $item->update_meta_data('_store_location', (string) $location->slug);
            $item->update_meta_data('_mulopimfwc_pos_location_id', (int) $location->term_id);
            $item->update_meta_data('_mulopimfwc_pos_openpos_warehouse', (int) $warehouse_id);
            $item->update_meta_data('_mulopimfwc_pos_stock_reduced', $quantity);
            $item->update_meta_data('_reduced_stock', $quantity);
            $item->update_meta_data('_op_reduced_stock', $quantity);
            $item->save();

            $changed_products[] = $target_id;
            do_action('mulopimfwc_pos_connector_location_stock_reduced', $target_id, (int) $location->term_id, $quantity, $new_stock, $order);
        }

        if (empty($changed_products)) {
            return;
        }

        $order->update_meta_data('_store_location', (string) $location->slug);
        $order->update_meta_data('_mulopimfwc_pos_location_id', (int) $location->term_id);
        $order->update_meta_data('_mulopimfwc_pos_provider', 'openpos');
        $order->update_meta_data('_mulopimfwc_pos_openpos_warehouse', (int) $warehouse_id);
        $order->save();

        $order->get_data_store()->set_stock_reduced($order_id, true);

        foreach (array_unique($changed_products) as $product_id) {
            $this->bump_openpos_product_change((int) $product_id, (int) $warehouse_id);
        }
    }

    public function restore_mapped_location_stock($order_id, $status)
    {
        if (!$this->is_location_stock_enabled()) {
            return;
        }

        if ($status !== 'cancelled') {
            return;
        }

        $order = wc_get_order($order_id);
        if (!$order) {
            return;
        }

        $warehouse_id = absint($order->get_meta('_mulopimfwc_pos_openpos_warehouse'));
        $location_id = absint($order->get_meta('_mulopimfwc_pos_location_id'));
        $provider = (string) $order->get_meta('_mulopimfwc_pos_provider');

        if (!$location_id || $provider !== 'openpos') {
            return;
        }

        $this->remove_openpos_restore_callback_for_mapped_order();

        $changed_products = [];

        foreach ($order->get_items('line_item') as $item) {
            $quantity = (float) $item->get_meta('_mulopimfwc_pos_stock_reduced', true);
            if ($quantity <= 0) {
                continue;
            }

            $product = $item->get_product();
            if (!$product) {
                continue;
            }

            $target_id = $this->get_order_item_stock_product_id($item, $product);
            if ($target_id <= 0) {
                continue;
            }

            $this->apply_location_stock_delta($target_id, $location_id, -1 * $quantity);
            $item->update_meta_data('_mulopimfwc_pos_stock_reduced', 0);
            $item->update_meta_data('_reduced_stock', 0);
            $item->update_meta_data('_op_reduced_stock', 0);
            $item->save();

            $changed_products[] = $target_id;
        }

        if (empty($changed_products)) {
            return;
        }

        $order->get_data_store()->set_stock_reduced($order->get_id(), false);

        foreach (array_unique($changed_products) as $product_id) {
            $this->bump_openpos_product_change((int) $product_id, (int) $warehouse_id);
        }
    }

    public function restore_mapped_location_stock_from_wc_status($order_id, $order = null)
    {
        $this->restore_mapped_location_stock($order_id, 'cancelled');
    }

    public function capture_before_openpos_stock_update($warehouse_id, $product_id, $qty)
    {
        $warehouse_id = absint($warehouse_id);
        $product_id = absint($product_id);

        if (!$this->is_location_stock_enabled() || $warehouse_id !== 0 || !$this->get_location_id_for_warehouse($warehouse_id) || !$product_id) {
            return;
        }

        $product = wc_get_product($product_id);
        if ($product && method_exists($product, 'get_stock_quantity')) {
            $this->default_stock_before_update[$warehouse_id . ':' . $product_id] = $product->get_stock_quantity();
        }
    }

    public function sync_after_openpos_stock_update($warehouse_id, $product_id, $qty)
    {
        $warehouse_id = absint($warehouse_id);
        $product_id = absint($product_id);
        $location_id = $this->get_location_id_for_warehouse($warehouse_id);

        if (!$this->is_location_stock_enabled() || !$location_id || !$product_id) {
            return;
        }

        update_post_meta($product_id, '_location_stock_' . $location_id, wc_format_decimal($qty));

        if ($warehouse_id === 0) {
            $capture_key = $warehouse_id . ':' . $product_id;
            if (array_key_exists($capture_key, $this->default_stock_before_update)) {
                $product = wc_get_product($product_id);
                if ($product && method_exists($product, 'set_stock_quantity')) {
                    $product->set_stock_quantity($this->default_stock_before_update[$capture_key]);
                    $product->save();
                }

                unset($this->default_stock_before_update[$capture_key]);
            }
        }

        $this->bump_openpos_product_change($product_id, $warehouse_id);
        do_action('mulopimfwc_pos_connector_location_stock_synced', $product_id, $location_id, (float) $qty, $warehouse_id);
    }

    private function apply_location_payload(array $payload, int $target_product_id, int $parent_product_id, int $location_id): array
    {
        $product = wc_get_product($target_product_id);
        if (!$product) {
            return $payload;
        }

        if ($this->is_location_stock_enabled()) {
            $stock = $this->get_location_stock($product, $location_id);
            if ($stock !== null) {
                $payload['qty'] = $stock;
                $payload['stock_quantity'] = $stock;
            }

            $backorders_allowed = $this->location_backorders_allowed($target_product_id, $location_id, $product);

            $is_in_stock = ($stock === null || $stock > 0 || $backorders_allowed);
            $payload['stock_status'] = $is_in_stock ? 'instock' : 'outofstock';
            $payload['manage_stock'] = method_exists($product, 'managing_stock') ? $product->managing_stock() : true;
        }

        if (!$this->is_location_price_enabled()) {
            return $payload;
        }

        $price_product_id = $parent_product_id > 0 ? $parent_product_id : $target_product_id;
        $variation_id = $parent_product_id > 0 ? $target_product_id : 0;
        $price_data = function_exists('mulopimfwc_get_runtime_price_data_for_location')
            ? mulopimfwc_get_runtime_price_data_for_location($price_product_id, $variation_id, $location_id)
            : [];

        $active_price = isset($price_data['active']) && $price_data['active'] !== '' ? (float) $price_data['active'] : null;
        if ($active_price === null) {
            return $payload;
        }

        $price_excl_tax = wc_get_price_excluding_tax($product, ['price' => $active_price]);
        $price_incl_tax = wc_get_price_including_tax($product, ['price' => $active_price]);
        $regular_price = isset($price_data['regular']) && $price_data['regular'] !== '' ? (float) $price_data['regular'] : $active_price;
        $sale_price = isset($price_data['sale']) && $price_data['sale'] !== '' ? (float) $price_data['sale'] : null;

        $payload['price'] = (float) $price_excl_tax;
        $payload['final_price'] = (float) $price_excl_tax;
        $payload['price_incl_tax'] = (float) $price_incl_tax;
        $payload['final_price_incl_tax'] = (float) $price_incl_tax;
        $payload['regular_price_excl_tax'] = wc_get_price_excluding_tax($product, ['price' => $regular_price]);
        $payload['regular_price_with_tax'] = wc_get_price_including_tax($product, ['price' => $regular_price]);
        $payload['regular_price'] = (float) $regular_price;
        $payload['special_price'] = $sale_price !== null ? (float) $sale_price : '';
        $payload['price_display_html'] = $this->format_location_price($active_price, $location_id);

        return $payload;
    }

    private function sync_variable_payload_from_location(array $data, int $product_id, int $location_id): array
    {
        if (empty($data['variations']) || !is_array($data['variations'])) {
            return $data;
        }

        $price_list = [];
        $regular_price_list = [];
        $total_stock = 0.0;
        $any_backorders_allowed = false;

        foreach ($data['variations'] as $variation_group_index => $variation_group) {
            if (empty($variation_group['options']) || !is_array($variation_group['options'])) {
                continue;
            }

            foreach ($variation_group['options'] as $option_index => $option) {
                $id_values = isset($option['id_values']) && is_array($option['id_values'])
                    ? array_map('absint', $option['id_values'])
                    : [];

                if (empty($id_values)) {
                    continue;
                }

                $values = [];
                $filtered_id_values = [];
                $prices = [];
                $stock_qty = 0.0;
                $option_backorders_allowed = false;

                foreach (array_unique($id_values) as $variation_id) {
                    if ($variation_id <= 0 || !$this->product_is_assigned_to_location($variation_id, $location_id)) {
                        continue;
                    }

                    $barcode = $this->get_openpos_barcode($variation_id);
                    if ($barcode === '') {
                        continue;
                    }

                    $values[] = $barcode;
                    $filtered_id_values[] = $variation_id;

                    $variation_product = wc_get_product($variation_id);
                    if ($variation_product) {
                        if ($this->is_location_stock_enabled()) {
                            $stock = $this->get_location_stock($variation_product, $location_id);
                            if (is_numeric($stock)) {
                                $stock_qty += (float) $stock;
                            }
                        }

                        if ($this->location_backorders_allowed($variation_id, $location_id, $variation_product)) {
                            $option_backorders_allowed = true;
                            $any_backorders_allowed = true;
                        }
                    }

                    if ($this->is_location_price_enabled() && function_exists('mulopimfwc_get_runtime_price_data_for_location')) {
                        $price_data = mulopimfwc_get_runtime_price_data_for_location($product_id, $variation_id, $location_id);
                        $active_price = isset($price_data['active']) && $price_data['active'] !== '' ? (float) $price_data['active'] : null;
                        $regular_price = isset($price_data['regular']) && $price_data['regular'] !== '' ? (float) $price_data['regular'] : $active_price;

                        if ($active_price !== null) {
                            $prices[$barcode] = $active_price;
                            $price_list[] = $active_price;
                        }

                        if ($regular_price !== null) {
                            $regular_price_list[] = $regular_price;
                        }
                    } elseif (isset($option['prices'][$barcode])) {
                        $prices[$barcode] = $option['prices'][$barcode];
                    }
                }

                $data['variations'][$variation_group_index]['options'][$option_index]['values'] = array_values(array_unique($values));
                $data['variations'][$variation_group_index]['options'][$option_index]['id_values'] = array_values(array_unique($filtered_id_values));

                if (!empty($prices)) {
                    $data['variations'][$variation_group_index]['options'][$option_index]['prices'] = $prices;
                }

                if ($this->is_location_stock_enabled()) {
                    $data['variations'][$variation_group_index]['options'][$option_index]['stock_qty'] = $stock_qty;
                    $data['variations'][$variation_group_index]['options'][$option_index]['stock_status'] = ($stock_qty > 0 || $option_backorders_allowed) ? 'instock' : 'outofstock';
                    $total_stock += $stock_qty;
                }
            }
        }

        if ($this->is_location_stock_enabled()) {
            $data['qty'] = $total_stock;
            $data['stock_quantity'] = $total_stock;
            $data['stock_status'] = ($total_stock > 0 || $any_backorders_allowed) ? 'instock' : 'outofstock';
        }

        if ($this->is_location_price_enabled() && !empty($price_list)) {
            $min_price = min($price_list);
            $max_price = max($price_list);
            $data['price'] = (float) $min_price;
            $data['final_price'] = (float) $min_price;

            if (abs((float) $min_price - (float) $max_price) > 0.0001) {
                $data['price_display_html'] = wc_format_price_range(
                    $this->format_location_price($min_price, $location_id),
                    $this->format_location_price($max_price, $location_id)
                );
            } else {
                $regular_price = !empty($regular_price_list) ? max($regular_price_list) : $min_price;
                $data['price_display_html'] = $regular_price > $min_price
                    ? wc_format_sale_price(
                        $this->format_location_price($regular_price, $location_id),
                        $this->format_location_price($min_price, $location_id)
                    )
                    : $this->format_location_price($min_price, $location_id);
            }
        }

        return $data;
    }

    private function get_location_id_for_warehouse($warehouse_id): int
    {
        $mappings = $this->get_mappings();
        $key = (string) absint($warehouse_id);

        if (empty($mappings[$key])) {
            return 0;
        }

        $term = get_term(absint($mappings[$key]), 'mulopimfwc_store_location');
        if (!$term || is_wp_error($term)) {
            return 0;
        }

        return (int) $term->term_id;
    }

    private function get_location_for_warehouse($warehouse_id)
    {
        $location_id = $this->get_location_id_for_warehouse($warehouse_id);
        if (!$location_id) {
            return null;
        }

        $term = get_term($location_id, 'mulopimfwc_store_location');
        return ($term && !is_wp_error($term)) ? $term : null;
    }

    private function get_location_stock($product, int $location_id)
    {
        if (function_exists('mulopimfwc_get_location_stock_quantity_for_product')) {
            return mulopimfwc_get_location_stock_quantity_for_product($product, $location_id);
        }

        if (!is_object($product) || !method_exists($product, 'get_id')) {
            return null;
        }

        $stock = get_post_meta($product->get_id(), '_location_stock_' . $location_id, true);
        if ($stock !== '' && is_numeric($stock)) {
            return (float) $stock;
        }

        return method_exists($product, 'get_stock_quantity') ? $product->get_stock_quantity() : null;
    }

    private function get_location_from_order_parse_data($order_parse_data)
    {
        if (!is_array($order_parse_data)) {
            return null;
        }

        $location_data = $order_parse_data['addition_information']['mulopimfwc_pos_location'] ?? null;
        if (!is_array($location_data) || empty($location_data['id'])) {
            return null;
        }

        $term = get_term(absint($location_data['id']), 'mulopimfwc_store_location');
        return ($term && !is_wp_error($term)) ? $term : null;
    }

    private function remove_openpos_warehouse_visibility_meta_query(array $meta_query, int $warehouse_id): array
    {
        $openpos_visibility_key = '_hide_pos_website_' . $warehouse_id;
        $filtered = [];

        foreach ($meta_query as $key => $clause) {
            if ($key === 'relation') {
                $filtered[$key] = $clause;
                continue;
            }

            if (is_array($clause) && isset($clause['key']) && $clause['key'] === $openpos_visibility_key) {
                continue;
            }

            $filtered[$key] = $clause;
        }

        if (count($filtered) === 1 && isset($filtered['relation'])) {
            return [];
        }

        return $filtered;
    }

    private function build_location_tax_query(int $location_id): array
    {
        $assigned_to_location = [
            'taxonomy' => 'mulopimfwc_store_location',
            'field' => 'term_id',
            'terms' => [$location_id],
            'operator' => 'IN',
            'include_children' => false,
        ];

        if (!$this->is_all_locations_enabled()) {
            return $assigned_to_location;
        }

        return [
            'relation' => 'OR',
            $assigned_to_location,
            [
                'taxonomy' => 'mulopimfwc_store_location',
                'operator' => 'NOT EXISTS',
            ],
        ];
    }

    private function is_location_stock_enabled(): bool
    {
        return $this->is_main_option_enabled('enable_location_stock');
    }

    private function is_location_price_enabled(): bool
    {
        return $this->is_main_option_enabled('enable_location_price');
    }

    private function is_location_backorder_enabled(): bool
    {
        return $this->is_main_option_enabled('enable_location_backorder');
    }

    private function is_all_locations_enabled(): bool
    {
        global $mulopimfwc_options;

        $options = is_array($mulopimfwc_options ?? null)
            ? $mulopimfwc_options
            : get_option('mulopimfwc_display_options', []);

        if (function_exists('mulopimfwc_is_all_locations_enabled')) {
            return mulopimfwc_is_all_locations_enabled($options);
        }

        return isset($options['enable_all_locations']) && $options['enable_all_locations'] === 'on';
    }

    private function is_main_option_enabled(string $key): bool
    {
        global $mulopimfwc_options;

        $options = is_array($mulopimfwc_options ?? null)
            ? $mulopimfwc_options
            : get_option('mulopimfwc_display_options', []);

        return isset($options[$key]) && $options[$key] === 'on';
    }

    private function get_openpos_barcode(int $product_id): string
    {
        global $OPENPOS_CORE;

        if (is_object($OPENPOS_CORE) && method_exists($OPENPOS_CORE, 'getBarcode')) {
            return strtolower(trim((string) $OPENPOS_CORE->getBarcode($product_id)));
        }

        return (string) $product_id;
    }

    private function format_location_price(float $price, int $location_id): string
    {
        return function_exists('mulopimfwc_format_runtime_price_for_location_display')
            ? mulopimfwc_format_runtime_price_for_location_display($price, $location_id)
            : wc_price($price);
    }

    private function product_is_assigned_to_location(int $product_id, int $location_id): bool
    {
        if ($product_id <= 0 || $location_id <= 0) {
            return false;
        }

        $ids_to_check = [$product_id];
        $parent_id = wp_get_post_parent_id($product_id);
        if ($parent_id > 0) {
            $ids_to_check[] = $parent_id;
        }

        $has_any_location_terms = false;

        foreach (array_unique($ids_to_check) as $id) {
            $terms = wp_get_object_terms($id, 'mulopimfwc_store_location', ['fields' => 'ids']);
            if (is_wp_error($terms)) {
                continue;
            }

            $terms = array_map('intval', $terms);
            if (!empty($terms)) {
                $has_any_location_terms = true;
            }

            if (in_array($location_id, $terms, true)) {
                return !$this->is_location_disabled_for_product($product_id, $location_id)
                    && !$this->is_location_disabled_for_product((int) $id, $location_id);
            }
        }

        return !$has_any_location_terms
            && $this->is_all_locations_enabled()
            && !$this->is_location_disabled_for_product($product_id, $location_id);
    }

    private function is_location_disabled_for_product(int $product_id, int $location_id): bool
    {
        if ($product_id <= 0 || $location_id <= 0) {
            return false;
        }

        $disabled = get_post_meta($product_id, '_location_disabled_' . $location_id, true);
        return $disabled !== '' && $disabled !== '0' && $disabled !== 'no' && $disabled !== 'off';
    }

    private function location_backorders_allowed(int $product_id, int $location_id, $product = null): bool
    {
        if (!$product && $product_id > 0) {
            $product = wc_get_product($product_id);
        }

        $backorders = $this->is_location_backorder_enabled() && function_exists('mulopimfwc_get_effective_location_backorders')
            ? mulopimfwc_get_effective_location_backorders($product_id, $location_id)
            : (is_object($product) && method_exists($product, 'get_backorders') ? $product->get_backorders() : 'no');

        return function_exists('mulopimfwc_is_backorder_allowed')
            ? mulopimfwc_is_backorder_allowed($backorders)
            : in_array($backorders, ['yes', 'notify'], true);
    }

    private function resolve_product_id($_product, array $data): int
    {
        if (is_object($_product)) {
            if (isset($_product->ID)) {
                return absint($_product->ID);
            }

            if (method_exists($_product, 'get_id')) {
                return absint($_product->get_id());
            }
        }

        return isset($data['id']) ? absint($data['id']) : 0;
    }

    private function resolve_current_warehouse_id($order_data): int
    {
        global $_op_warehouse_id;

        if (isset($_op_warehouse_id)) {
            return absint($_op_warehouse_id);
        }

        if (is_array($order_data) && isset($order_data['register']['outlet'])) {
            return absint($order_data['register']['outlet']);
        }

        if (is_array($order_data) && isset($order_data['register']['warehouse'])) {
            return absint($order_data['register']['warehouse']);
        }

        return 0;
    }

    private function resolve_order_id($order_data): int
    {
        if (!is_array($order_data)) {
            return 0;
        }

        foreach (['order_id', 'id', 'system_order_id'] as $key) {
            if (!empty($order_data[$key])) {
                return absint($order_data[$key]);
            }
        }

        return 0;
    }

    private function get_order_item_stock_product_id($item, $product): int
    {
        $variation_id = method_exists($item, 'get_variation_id') ? absint($item->get_variation_id()) : 0;
        if ($variation_id > 0) {
            return $variation_id;
        }

        return is_object($product) && method_exists($product, 'get_id') ? absint($product->get_id()) : 0;
    }

    private function get_order_item_stock_quantity($order, $item): float
    {
        $quantity = method_exists($item, 'get_quantity') ? (float) $item->get_quantity() : 0.0;
        $weight_quantity = $item->get_meta('_op_item_weight', true);

        if (is_numeric($weight_quantity) && (float) $weight_quantity > 0) {
            $quantity = (float) $weight_quantity;
        }

        $quantity = apply_filters('woocommerce_order_item_quantity', $quantity, $order, $item);
        return max(0, (float) $quantity);
    }

    private function apply_location_stock_delta(int $product_id, int $location_id, float $quantity_delta): float
    {
        $meta_key = '_location_stock_' . $location_id;
        $current_stock = get_post_meta($product_id, $meta_key, true);

        if ($current_stock === '' || !is_numeric($current_stock)) {
            $product = wc_get_product($product_id);
            $current_stock = $product && method_exists($product, 'get_stock_quantity') ? $product->get_stock_quantity() : 0;
        }

        $current_stock = (float) $current_stock;
        $new_stock = $current_stock - $quantity_delta;

        if ($quantity_delta > 0) {
            $backorders = function_exists('mulopimfwc_get_effective_location_backorders')
                ? mulopimfwc_get_effective_location_backorders($product_id, $location_id)
                : 'no';
            $backorders_allowed = function_exists('mulopimfwc_is_backorder_allowed')
                ? mulopimfwc_is_backorder_allowed($backorders)
                : in_array($backorders, ['yes', 'notify'], true);

            if (!$backorders_allowed) {
                $new_stock = max(0, $new_stock);
            }
        }

        update_post_meta($product_id, $meta_key, wc_format_decimal($new_stock));
        return (float) $new_stock;
    }

    private function bump_openpos_product_change(int $product_id, int $warehouse_id): void
    {
        global $OPENPOS_CORE;

        if (is_object($OPENPOS_CORE) && method_exists($OPENPOS_CORE, 'addProductChange')) {
            $OPENPOS_CORE->addProductChange($product_id, $warehouse_id);
            return;
        }

        update_post_meta($product_id, '_openpos_product_version_' . $warehouse_id, time());
    }

    private function remove_openpos_restore_callback_for_mapped_order(): void
    {
        global $op_woo;

        if (is_object($op_woo) && method_exists($op_woo, 'op_maybe_increase_stock_levels')) {
            remove_action('op_woocommerce_cancelled_order', [$op_woo, 'op_maybe_increase_stock_levels'], 10);
        }
    }
}

class MULOPIMFWC_POS_WCPOS_Provider
{
    const STORE_LOCATION_META_KEY = '_mulopimfwc_wcpos_location_id';
    const STORE_PRICING_SOURCE_META_KEY = '_mulopimfwc_wcpos_pricing_source';
    const LEGACY_MIGRATED_OPTION = 'mulopimfwc_pos_wcpos_legacy_migrated';

    private $current_rest_request = null;
    private $pre_update_stock = [];
    private $product_ids_by_location = [];
    private $locations_cache = null;

    public function init()
    {
        if (!$this->is_enabled() || !$this->is_available()) {
            return;
        }

        $this->migrate_legacy_store_mappings();

        add_filter('woocommerce_pos_store_meta_fields', [$this, 'extend_store_meta_fields'], 30, 1);
        add_filter('woocommerce_pos_rest_prepare_store', [$this, 'filter_store_response'], 30, 3);
        add_filter('rest_post_dispatch', [$this, 'inject_store_fields_after_dispatch'], 30, 3);
        add_action('admin_enqueue_scripts', [$this, 'enqueue_store_edit_assets'], 20);

        add_filter('rest_request_before_callbacks', [$this, 'capture_rest_request'], 1, 3);
        add_filter('rest_request_after_callbacks', [$this, 'release_rest_request'], PHP_INT_MAX, 3);

        add_filter('woocommerce_rest_product_object_query', [$this, 'filter_product_query'], 30, 2);
        add_filter('woocommerce_rest_product_variation_object_query', [$this, 'filter_variation_query'], 30, 2);
        add_filter('woocommerce_rest_prepare_product_object', [$this, 'filter_product_response'], 30, 3);
        add_filter('woocommerce_rest_prepare_product_variation_object', [$this, 'filter_variation_response'], 30, 3);

        add_filter('woocommerce_rest_pre_insert_product_object', [$this, 'capture_stock_before_rest_product_save'], 30, 3);
        add_action('woocommerce_rest_insert_product_object', [$this, 'sync_stock_after_rest_product_save'], 30, 3);
        add_filter('woocommerce_rest_pre_insert_product_variation_object', [$this, 'capture_stock_before_rest_product_save'], 30, 3);
        add_action('woocommerce_rest_insert_product_variation_object', [$this, 'sync_stock_after_rest_product_save'], 30, 3);

        add_action('woocommerce_before_order_object_save', [$this, 'stamp_order_before_save'], 30, 1);
        add_action('woocommerce_rest_insert_shop_order_object', [$this, 'stamp_rest_order_after_save'], 30, 3);
        add_filter('woocommerce_can_reduce_order_stock', [$this, 'prevent_global_reduce_and_reduce_location_stock'], 5, 2);
        add_filter('woocommerce_can_restore_order_stock', [$this, 'prevent_global_restore_and_restore_location_stock'], 5, 2);
    }

    public function is_enabled(): bool
    {
        return get_option(MULOPIMFWC_POS_Connector::OPTION_ENABLED, 'yes') === 'yes';
    }

    public function is_available(): bool
    {
        return function_exists('wcpos_get_stores')
            || function_exists('woocommerce_pos_request')
            || defined('WCPOS\\WooCommercePOS\\VERSION')
            || class_exists('\\WCPOS\\WooCommercePOS\\API\\Products_Controller');
    }

    public function extend_store_meta_fields(array $fields): array
    {
        $fields['mulopimfwc_location_id'] = self::STORE_LOCATION_META_KEY;
        $fields['mulopimfwc_pricing_source'] = self::STORE_PRICING_SOURCE_META_KEY;

        return $fields;
    }

    public function get_stores(): array
    {
        $stores = [];

        if (function_exists('wcpos_get_stores')) {
            $wcpos_stores = wcpos_get_stores();
            if (is_array($wcpos_stores)) {
                foreach ($wcpos_stores as $store) {
                    $store_id = is_object($store) && method_exists($store, 'get_id') ? absint($store->get_id()) : 0;
                    $store_name = is_object($store) && method_exists($store, 'get_name') ? (string) $store->get_name() : '';

                    if ($store_name === '') {
                        $store_name = $store_id > 0
                            ? sprintf(__('WCPOS Store #%d', 'mulopimfwc-pos-connector'), $store_id)
                            : __('Default WCPOS Store', 'mulopimfwc-pos-connector');
                    }

                    $stores[(string) $store_id] = [
                        'id' => $store_id,
                        'name' => $store_name,
                    ];
                }
            }
        }

        if (empty($stores)) {
            $stores['0'] = [
                'id' => 0,
                'name' => __('Default WCPOS Store', 'mulopimfwc-pos-connector'),
            ];
        }

        ksort($stores, SORT_NATURAL);
        return array_values($stores);
    }

    public function has_mappable_stores(array $stores = null): bool
    {
        $stores = $stores === null ? $this->get_stores() : $stores;

        foreach ($stores as $store) {
            if (isset($store['id']) && absint($store['id']) > 0) {
                return true;
            }
        }

        return false;
    }

    public function get_store_location_summary(int $store_id): array
    {
        if ($store_id <= 0) {
            return [
                'mapped' => false,
                'location_id' => 0,
                'location_name' => __('Not mappable for default Store ID 0', 'mulopimfwc-pos-connector'),
                'pricing_source' => 'default',
                'pricing_source_label' => __('WCPOS/WooCommerce default', 'mulopimfwc-pos-connector'),
            ];
        }

        $location_id = $this->get_store_location_id($store_id);
        if (!$location_id) {
            return [
                'mapped' => false,
                'location_id' => 0,
                'location_name' => __('Not configured', 'mulopimfwc-pos-connector'),
                'pricing_source' => 'default',
                'pricing_source_label' => __('WCPOS/WooCommerce default', 'mulopimfwc-pos-connector'),
            ];
        }

        $location = get_term($location_id, 'mulopimfwc_store_location');
        $pricing_source = $this->get_store_pricing_source($store_id);

        return [
            'mapped' => true,
            'location_id' => $location_id,
            'location_name' => ($location && !is_wp_error($location)) ? (string) $location->name : sprintf(__('Location #%d', 'mulopimfwc-pos-connector'), $location_id),
            'pricing_source' => $pricing_source,
            'pricing_source_label' => $pricing_source === 'mulopimfwc'
                ? __('Multi Location price', 'mulopimfwc-pos-connector')
                : __('WCPOS/WooCommerce default', 'mulopimfwc-pos-connector'),
        ];
    }

    public function get_last_warning(): array
    {
        $warning = get_option(MULOPIMFWC_POS_Connector::OPTION_WCPOS_LAST_WARNING, []);
        return is_array($warning) ? $warning : [];
    }

    public function enqueue_store_edit_assets(string $hook_suffix): void
    {
        if (!in_array($hook_suffix, ['admin_page_wcpos-store-edit', 'pos_page_wcpos-store-edit'], true)) {
            return;
        }

        $store_edit_handle = 'woocommerce-pos-pro-store-edit';
        if (!wp_script_is($store_edit_handle, 'enqueued')) {
            return;
        }

        wp_enqueue_script(
            'mulopimfwc-wcpos-store-location',
            MULOPIMFWC_POS_CONNECTOR_URL . 'assets/js/wcpos-store-location-section.js',
            [$store_edit_handle, 'wp-element'],
            MULOPIMFWC_POS_CONNECTOR_VERSION,
            true
        );

        wp_add_inline_script(
            'mulopimfwc-wcpos-store-location',
            'window.mulopimfwcWcposStoreEdit = ' . wp_json_encode([
                'locations' => $this->get_locations_for_js(),
                'strings' => [
                    'sectionLabel' => __('Multi Location Inventory', 'mulopimfwc-pos-connector'),
                    'locationTitle' => __('Inventory location', 'mulopimfwc-pos-connector'),
                    'locationDescription' => __('Link this WCPOS store to the Multi Location inventory used at this physical register/store.', 'mulopimfwc-pos-connector'),
                    'locationDefault' => __('No location (use WCPOS stock)', 'mulopimfwc-pos-connector'),
                    'noLocations' => __('No Multi Location store locations found.', 'mulopimfwc-pos-connector'),
                    'pricingTitle' => __('Pricing source', 'mulopimfwc-pos-connector'),
                    'pricingDescription' => __('Choose whether this store receives Multi Location prices or the default WCPOS/WooCommerce prices.', 'mulopimfwc-pos-connector'),
                    'pricingLocation' => __('Multi Location price', 'mulopimfwc-pos-connector'),
                    'pricingDefault' => __('WCPOS/WooCommerce default', 'mulopimfwc-pos-connector'),
                ],
            ]) . ';',
            'before'
        );
    }

    public function capture_rest_request($response, $handler, $request)
    {
        if ($request instanceof WP_REST_Request && $this->is_wcpos_rest_request($request)) {
            $this->current_rest_request = $request;
        }

        return $response;
    }

    public function release_rest_request($response, $handler, $request)
    {
        if ($request instanceof WP_REST_Request && $this->is_wcpos_rest_request($request)) {
            $this->current_rest_request = null;
        }

        return $response;
    }

    public function filter_store_response($response, $store, WP_REST_Request $request)
    {
        $store_id = is_object($store) && method_exists($store, 'get_id') ? absint($store->get_id()) : 0;

        if ($response instanceof WP_REST_Response) {
            $data = $response->get_data();
            if (is_array($data)) {
                $response->set_data($this->add_location_fields_to_store_data($data, $store_id));
            }

            return $response;
        }

        if (is_array($response)) {
            return $this->add_location_fields_to_store_data($response, $store_id);
        }

        return $response;
    }

    public function inject_store_fields_after_dispatch($result, $server, WP_REST_Request $request)
    {
        unset($server);

        if (!$result instanceof WP_REST_Response) {
            return $result;
        }

        $route = $request->get_route();
        if (strpos($route, '/wcpos/v1/stores') !== 0 && !preg_match('#^/wcpos/v1/cashier/\d+/stores#', $route)) {
            return $result;
        }

        $data = $result->get_data();

        if (is_array($data) && $this->is_list_array($data)) {
            foreach ($data as $index => $item) {
                if (is_array($item)) {
                    $data[$index] = $this->add_location_fields_to_store_data($item);
                }
            }
        } elseif (is_array($data)) {
            $data = $this->add_location_fields_to_store_data($data);
        }

        $result->set_data($data);
        return $result;
    }

    public function filter_product_query(array $args, WP_REST_Request $request)
    {
        if (!$this->is_wcpos_rest_request($request, ['products'])) {
            return $args;
        }

        $context = $this->get_location_context_for_request($request);
        if (!$context) {
            return $args;
        }

        $location_tax_query = $this->build_location_tax_query($context['location_id']);

        if (empty($args['tax_query']) || !is_array($args['tax_query'])) {
            $args['tax_query'] = [$location_tax_query];
            return $args;
        }

        if (!isset($args['tax_query']['relation'])) {
            $args['tax_query']['relation'] = 'AND';
        }

        $args['tax_query'][] = $location_tax_query;

        return $args;
    }

    public function filter_variation_query(array $args, WP_REST_Request $request)
    {
        if (!$this->is_wcpos_rest_request($request, ['products'])) {
            return $args;
        }

        $context = $this->get_location_context_for_request($request);
        if (!$context) {
            return $args;
        }

        $location_id = (int) $context['location_id'];
        $parent_id = absint($request->get_param('product_id'));
        if (!$parent_id && preg_match('#/products/(\d+)/variations#', $request->get_route(), $matches)) {
            $parent_id = absint($matches[1]);
        }

        if ($parent_id > 0) {
            if (!$this->product_is_assigned_to_location($parent_id, $location_id)) {
                $args['post__in'] = [0];
            }

            return $args;
        }

        $parent_ids = $this->get_product_ids_for_location($location_id);
        if (empty($parent_ids)) {
            $args['post__in'] = [0];
            return $args;
        }

        if (!empty($args['post_parent__in']) && is_array($args['post_parent__in'])) {
            $args['post_parent__in'] = array_values(array_intersect(array_map('absint', $args['post_parent__in']), $parent_ids));
        } else {
            $args['post_parent__in'] = $parent_ids;
        }

        if (empty($args['post_parent__in'])) {
            $args['post__in'] = [0];
        }

        return $args;
    }

    public function filter_product_response(WP_REST_Response $response, WC_Product $product, WP_REST_Request $request): WP_REST_Response
    {
        if (!$this->is_wcpos_rest_request($request, ['products'])) {
            return $response;
        }

        $data = $response->get_data();
        if (!is_array($data)) {
            return $response;
        }

        $context = $this->get_location_context_for_request($request);
        if ($context) {
            $data = $product->is_type('variable')
                ? $this->apply_variable_product_response($data, $product, $context)
                : $this->apply_single_product_response($data, $product, $context);
            $data['mulopimfwc_active_location_id'] = (int) $context['location_id'];
        } else {
            $data['mulopimfwc_active_location_id'] = 0;
        }

        $data['mulopimfwc_inventories'] = $this->get_inventory_payload_for_product($product);
        $response->set_data($data);

        return $response;
    }

    public function filter_variation_response(WP_REST_Response $response, WC_Data $variation, WP_REST_Request $request): WP_REST_Response
    {
        if (!$this->is_wcpos_rest_request($request, ['products']) || !$variation instanceof WC_Product) {
            return $response;
        }

        $data = $response->get_data();
        if (!is_array($data)) {
            return $response;
        }

        $context = $this->get_location_context_for_request($request);
        if ($context) {
            $data = $this->apply_single_product_response($data, $variation, $context);
            $data['mulopimfwc_active_location_id'] = (int) $context['location_id'];
        } else {
            $data['mulopimfwc_active_location_id'] = 0;
        }

        $data['mulopimfwc_inventories'] = $this->get_inventory_payload_for_product($variation);
        $response->set_data($data);

        return $response;
    }

    public function capture_stock_before_rest_product_save($object, WP_REST_Request $request, $creating)
    {
        if (!$object instanceof WC_Product || !$this->is_wcpos_rest_request($request, ['products']) || !$this->request_updates_stock($request)) {
            return $object;
        }

        $context = $this->get_location_context_for_request($request);
        if (!$context) {
            $this->record_warning(__('A WCPOS product stock update was received without a mapped WCPOS Pro store. The connector did not write Multi Location stock for the default/unmapped store.', 'mulopimfwc-pos-connector'));
            return $object;
        }

        $product_id = absint($object->get_id());
        if ($product_id > 0 && !array_key_exists($product_id, $this->pre_update_stock)) {
            $this->pre_update_stock[$product_id] = get_post_meta($product_id, '_stock', true);
        }

        return $object;
    }

    public function sync_stock_after_rest_product_save(WC_Data $object, WP_REST_Request $request, $creating): void
    {
        if (!$object instanceof WC_Product || !$this->is_wcpos_rest_request($request, ['products']) || !$this->request_updates_stock($request)) {
            return;
        }

        $context = $this->get_location_context_for_request($request);
        if (!$context) {
            return;
        }

        $stock = $request->get_param('stock_quantity');
        if (!is_numeric($stock)) {
            return;
        }

        $product_id = absint($object->get_id());
        if ($product_id <= 0) {
            return;
        }

        update_post_meta($product_id, '_location_stock_' . (int) $context['location_id'], wc_format_decimal($stock));

        if (array_key_exists($product_id, $this->pre_update_stock)) {
            $this->restore_global_stock_after_wcpos_update($product_id, $this->pre_update_stock[$product_id]);
            unset($this->pre_update_stock[$product_id]);
        }

        $this->bump_wcpos_product_modified($product_id);
        do_action('mulopimfwc_pos_connector_wcpos_location_stock_synced', $product_id, (int) $context['location_id'], (float) $stock, $request);
    }

    public function stamp_order_before_save($order): void
    {
        if (!$order instanceof WC_Order || !$this->is_wcpos_order_context($order)) {
            return;
        }

        $context = $this->get_location_context_for_order($order);
        if (!$context) {
            return;
        }

        $this->stamp_order_with_location($order, $context['location'], (int) $context['store_id'], false);
    }

    public function stamp_rest_order_after_save($order, WP_REST_Request $request, $creating): void
    {
        if (!$order instanceof WC_Order || !$this->is_wcpos_rest_request($request, ['orders'])) {
            return;
        }

        $context = $this->get_location_context_for_order($order, $request);
        if (!$context) {
            return;
        }

        $this->stamp_order_with_location($order, $context['location'], (int) $context['store_id'], true);
        $order->save_meta_data();
    }

    public function prevent_global_reduce_and_reduce_location_stock($can_reduce, $order)
    {
        if (!$can_reduce || !$order instanceof WC_Order) {
            return $can_reduce;
        }

        $context = $this->get_location_context_for_order($order);
        if (!$context) {
            return $can_reduce;
        }

        $this->reduce_mapped_order_location_stock($order, $context['location'], (int) $context['store_id']);

        return false;
    }

    public function prevent_global_restore_and_restore_location_stock($can_restore, $order)
    {
        if (!$can_restore || !$order instanceof WC_Order) {
            return $can_restore;
        }

        $context = $this->get_location_context_for_order($order);
        if (!$context) {
            return $can_restore;
        }

        $this->restore_mapped_order_location_stock($order, $context['location'], (int) $context['store_id']);

        return false;
    }

    private function add_location_fields_to_store_data(array $data, int $store_id = null): array
    {
        if ($store_id === null) {
            $store_id = isset($data['id']) ? absint($data['id']) : 0;
        }

        $location_id = $this->get_store_location_id($store_id);
        $location = $location_id ? get_term($location_id, 'mulopimfwc_store_location') : null;

        $data['mulopimfwc_location_id'] = $location_id;
        $data['mulopimfwc_location_slug'] = ($location && !is_wp_error($location)) ? (string) $location->slug : '';
        $data['mulopimfwc_location_name'] = ($location && !is_wp_error($location)) ? (string) $location->name : '';
        $data['mulopimfwc_pricing_source'] = $this->get_store_pricing_source($store_id);

        return $data;
    }

    private function apply_single_product_response(array $data, WC_Product $product, array $context): array
    {
        $location_id = (int) $context['location_id'];
        $product_id = $product->get_id();

        if ($this->is_location_stock_enabled()) {
            $stock = $this->get_location_stock($product, $location_id);
            $backorders_allowed = $this->location_backorders_allowed($product_id, $location_id, $product);

            if ($stock !== null) {
                $data['stock_quantity'] = $this->normalize_quantity_for_response($stock);
            }

            $is_in_stock = ($stock === null || (float) $stock > 0 || $backorders_allowed);
            $data['stock_status'] = $is_in_stock ? 'instock' : 'outofstock';
            $data['in_stock'] = $is_in_stock;
            $data['manage_stock'] = method_exists($product, 'managing_stock') ? $product->managing_stock() : true;
        }

        if (!$this->should_apply_location_price($context)) {
            return $data;
        }

        $parent_id = method_exists($product, 'get_parent_id') ? absint($product->get_parent_id()) : 0;
        $price_product_id = $parent_id > 0 ? $parent_id : $product_id;
        $variation_id = $parent_id > 0 ? $product_id : 0;
        $price_data = $this->get_location_price_data($price_product_id, $variation_id, $location_id);

        if (empty($price_data)) {
            return $data;
        }

        return $this->apply_price_data_to_response($data, $price_data, $location_id);
    }

    private function apply_variable_product_response(array $data, WC_Product $product, array $context): array
    {
        $location_id = (int) $context['location_id'];
        $children = method_exists($product, 'get_children') ? array_map('absint', $product->get_children()) : [];
        $active_prices = [];
        $regular_prices = [];
        $sale_prices = [];
        $visible_children = [];
        $total_stock = 0.0;
        $has_stock_value = false;
        $any_backorders_allowed = false;

        foreach ($children as $variation_id) {
            if ($variation_id <= 0 || !$this->product_is_assigned_to_location($variation_id, $location_id)) {
                continue;
            }

            $variation = wc_get_product($variation_id);
            if (!$variation) {
                continue;
            }

            $visible_children[] = $variation_id;

            if ($this->is_location_stock_enabled()) {
                $stock = $this->get_location_stock($variation, $location_id);
                if (is_numeric($stock)) {
                    $has_stock_value = true;
                    $total_stock += (float) $stock;
                }

                if ($this->location_backorders_allowed($variation_id, $location_id, $variation)) {
                    $any_backorders_allowed = true;
                }
            }

            if ($this->should_apply_location_price($context)) {
                $price_data = $this->get_location_price_data($product->get_id(), $variation_id, $location_id);
                if (!empty($price_data)) {
                    $active_prices[] = (float) $price_data['active'];
                    $regular_prices[] = (float) $price_data['regular'];
                    if ($price_data['sale'] !== '') {
                        $sale_prices[] = (float) $price_data['sale'];
                    }
                }
            }
        }

        if (isset($data['variations']) && is_array($data['variations'])) {
            $data['variations'] = array_values(array_intersect(array_map('absint', $data['variations']), $visible_children));
        }

        if ($this->is_location_stock_enabled()) {
            $data['stock_quantity'] = $this->normalize_quantity_for_response($has_stock_value ? $total_stock : 0);
            $is_in_stock = ($total_stock > 0 || $any_backorders_allowed);
            $data['stock_status'] = $is_in_stock ? 'instock' : 'outofstock';
            $data['in_stock'] = $is_in_stock;
            $data['manage_stock'] = method_exists($product, 'managing_stock') ? $product->managing_stock() : false;
        }

        if ($this->should_apply_location_price($context) && !empty($active_prices)) {
            $min_price = min($active_prices);
            $max_price = max($active_prices);
            $min_regular = !empty($regular_prices) ? min($regular_prices) : $min_price;
            $max_regular = !empty($regular_prices) ? max($regular_prices) : $max_price;
            $min_sale = !empty($sale_prices) ? min($sale_prices) : '';
            $max_sale = !empty($sale_prices) ? max($sale_prices) : '';

            $data['price'] = wc_format_decimal($min_price, wc_get_price_decimals());
            $data['regular_price'] = wc_format_decimal($min_regular, wc_get_price_decimals());
            $data['sale_price'] = $min_sale !== '' ? wc_format_decimal($min_sale, wc_get_price_decimals()) : '';
            $data['on_sale'] = $min_sale !== '' && (float) $min_sale < (float) $min_regular;

            if (abs((float) $min_price - (float) $max_price) > 0.0001) {
                $data['price_html'] = wc_format_price_range(
                    $this->format_location_price($min_price, $location_id),
                    $this->format_location_price($max_price, $location_id)
                );
            } else {
                $data['price_html'] = $data['on_sale']
                    ? wc_format_sale_price(
                        $this->format_location_price($max_regular, $location_id),
                        $this->format_location_price($min_price, $location_id)
                    )
                    : $this->format_location_price($min_price, $location_id);
            }

            $data['meta_data'] = $this->upsert_meta_data(
                isset($data['meta_data']) && is_array($data['meta_data']) ? $data['meta_data'] : [],
                '_woocommerce_pos_variable_prices',
                wp_json_encode([
                    'price' => [
                        'min' => wc_format_decimal($min_price, wc_get_price_decimals()),
                        'max' => wc_format_decimal($max_price, wc_get_price_decimals()),
                    ],
                    'regular_price' => [
                        'min' => wc_format_decimal($min_regular, wc_get_price_decimals()),
                        'max' => wc_format_decimal($max_regular, wc_get_price_decimals()),
                    ],
                    'sale_price' => [
                        'min' => $min_sale !== '' ? wc_format_decimal($min_sale, wc_get_price_decimals()) : '',
                        'max' => $max_sale !== '' ? wc_format_decimal($max_sale, wc_get_price_decimals()) : '',
                    ],
                ])
            );
        }

        return $data;
    }

    private function apply_price_data_to_response(array $data, array $price_data, int $location_id): array
    {
        $active = (float) $price_data['active'];
        $regular = (float) $price_data['regular'];
        $sale = $price_data['sale'];

        $data['price'] = wc_format_decimal($active, wc_get_price_decimals());
        $data['regular_price'] = wc_format_decimal($regular, wc_get_price_decimals());
        $data['sale_price'] = $sale !== '' ? wc_format_decimal((float) $sale, wc_get_price_decimals()) : '';
        $data['on_sale'] = $sale !== '' && (float) $sale < $regular;
        $data['price_html'] = $data['on_sale']
            ? wc_format_sale_price($this->format_location_price($regular, $location_id), $this->format_location_price($active, $location_id))
            : $this->format_location_price($active, $location_id);

        return $data;
    }

    private function get_inventory_payload_for_product(WC_Product $product): array
    {
        $locations = $this->get_store_locations();
        if (empty($locations)) {
            return [];
        }

        $payload = [];
        foreach ($locations as $location) {
            $location_id = (int) $location->term_id;
            if (!$this->product_is_assigned_to_location((int) $product->get_id(), $location_id)) {
                continue;
            }

            $summary = $product->is_type('variable')
                ? $this->get_variable_inventory_summary($product, $location_id)
                : $this->get_single_inventory_summary($product, $location_id);

            if (!$summary) {
                continue;
            }

            $payload[] = [
                'id' => $location_id,
                'term_id' => $location_id,
                'slug' => (string) $location->slug,
                'name' => (string) $location->name,
                'stock_quantity' => $this->normalize_quantity_for_response($summary['stock']),
                'stock_status' => $summary['in_stock'] ? 'instock' : 'outofstock',
                'in_stock' => (bool) $summary['in_stock'],
                'price' => $summary['price'] !== '' ? wc_format_decimal((float) $summary['price'], wc_get_price_decimals()) : '',
                'regular_price' => $summary['regular_price'] !== '' ? wc_format_decimal((float) $summary['regular_price'], wc_get_price_decimals()) : '',
                'sale_price' => $summary['sale_price'] !== '' ? wc_format_decimal((float) $summary['sale_price'], wc_get_price_decimals()) : '',
                'price_html' => $summary['price'] !== '' ? $this->format_location_price((float) $summary['price'], $location_id) : '',
            ];
        }

        return $payload;
    }

    private function get_single_inventory_summary(WC_Product $product, int $location_id)
    {
        $stock = $this->get_location_stock($product, $location_id);
        $stock = is_numeric($stock) ? (float) $stock : 0.0;
        $backorders_allowed = $this->location_backorders_allowed((int) $product->get_id(), $location_id, $product);

        $parent_id = method_exists($product, 'get_parent_id') ? absint($product->get_parent_id()) : 0;
        $price_product_id = $parent_id > 0 ? $parent_id : (int) $product->get_id();
        $variation_id = $parent_id > 0 ? (int) $product->get_id() : 0;
        $price_data = $this->get_location_price_data($price_product_id, $variation_id, $location_id);

        return [
            'stock' => $stock,
            'in_stock' => $stock > 0 || $backorders_allowed,
            'price' => isset($price_data['active']) ? $price_data['active'] : '',
            'regular_price' => isset($price_data['regular']) ? $price_data['regular'] : '',
            'sale_price' => isset($price_data['sale']) ? $price_data['sale'] : '',
        ];
    }

    private function get_variable_inventory_summary(WC_Product $product, int $location_id)
    {
        $children = method_exists($product, 'get_children') ? array_map('absint', $product->get_children()) : [];
        $stock = 0.0;
        $has_child = false;
        $in_stock = false;
        $active_prices = [];
        $regular_prices = [];
        $sale_prices = [];

        foreach ($children as $variation_id) {
            if ($variation_id <= 0 || !$this->product_is_assigned_to_location($variation_id, $location_id)) {
                continue;
            }

            $variation = wc_get_product($variation_id);
            if (!$variation) {
                continue;
            }

            $has_child = true;
            $variation_stock = $this->get_location_stock($variation, $location_id);
            if (is_numeric($variation_stock)) {
                $stock += (float) $variation_stock;
            }

            if ((is_numeric($variation_stock) && (float) $variation_stock > 0) || $this->location_backorders_allowed($variation_id, $location_id, $variation)) {
                $in_stock = true;
            }

            $price_data = $this->get_location_price_data((int) $product->get_id(), $variation_id, $location_id);
            if (!empty($price_data)) {
                $active_prices[] = (float) $price_data['active'];
                $regular_prices[] = (float) $price_data['regular'];
                if ($price_data['sale'] !== '') {
                    $sale_prices[] = (float) $price_data['sale'];
                }
            }
        }

        if (!$has_child) {
            return null;
        }

        return [
            'stock' => $stock,
            'in_stock' => $in_stock,
            'price' => !empty($active_prices) ? min($active_prices) : '',
            'regular_price' => !empty($regular_prices) ? min($regular_prices) : '',
            'sale_price' => !empty($sale_prices) ? min($sale_prices) : '',
        ];
    }

    private function get_location_context_for_request(WP_REST_Request $request = null)
    {
        $store = $this->get_store_id_from_request($request ?: $this->current_rest_request);
        if (!$store['resolved'] || $store['id'] <= 0) {
            return null;
        }

        return $this->get_location_context_for_store_id((int) $store['id']);
    }

    private function get_location_context_for_store_id(int $store_id)
    {
        $location_id = $this->get_store_location_id($store_id);
        if (!$location_id) {
            return null;
        }

        $location = get_term($location_id, 'mulopimfwc_store_location');
        if (!$location || is_wp_error($location)) {
            return null;
        }

        return [
            'store_id' => $store_id,
            'location_id' => (int) $location->term_id,
            'location' => $location,
            'pricing_source' => $this->get_store_pricing_source($store_id),
        ];
    }

    private function get_location_context_for_order(WC_Order $order, WP_REST_Request $request = null)
    {
        $provider = (string) $order->get_meta('_mulopimfwc_pos_provider', true);
        if ($provider !== '' && $provider !== 'wcpos') {
            return null;
        }

        $location_id = absint($order->get_meta('_mulopimfwc_pos_location_id', true));
        $store_id = $this->get_store_id_from_order($order);

        if (!$store_id['resolved']) {
            $request_store_id = $this->get_store_id_from_request($request ?: $this->current_rest_request);
            if ($request_store_id['resolved']) {
                $store_id = $request_store_id;
            }
        }

        if (!$location_id && $store_id['resolved'] && $store_id['id'] > 0) {
            $location_id = $this->get_store_location_id((int) $store_id['id']);
        }

        if (!$location_id || !$this->is_wcpos_order_context($order, $request)) {
            return null;
        }

        $location = get_term($location_id, 'mulopimfwc_store_location');
        if (!$location || is_wp_error($location)) {
            return null;
        }

        return [
            'location' => $location,
            'location_id' => (int) $location->term_id,
            'store_id' => $store_id['resolved'] ? absint($store_id['id']) : 0,
        ];
    }

    private function stamp_order_with_location(WC_Order $order, $location, int $store_id, bool $save_items): void
    {
        $order->update_meta_data('_store_location', (string) $location->slug);
        $order->update_meta_data('_mulopimfwc_location', (string) $location->slug);
        $order->update_meta_data('_mulopimfwc_pos_location_id', (int) $location->term_id);
        $order->update_meta_data('_mulopimfwc_pos_provider', 'wcpos');
        $order->update_meta_data('_mulopimfwc_pos_wcpos_store', $store_id);

        if ($store_id > 0 && (string) $order->get_meta('_pos_store', true) === '') {
            $order->update_meta_data('_pos_store', (string) $store_id);
        }

        foreach ($order->get_items('line_item') as $item) {
            $item->update_meta_data('_store_location', (string) $location->slug);
            $item->update_meta_data('_mulopimfwc_location', (string) $location->slug);
            $item->update_meta_data('_mulopimfwc_pos_location_id', (int) $location->term_id);
            $item->update_meta_data('_mulopimfwc_pos_provider', 'wcpos');
            $item->update_meta_data('_mulopimfwc_pos_wcpos_store', $store_id);

            if ($save_items) {
                $item->save();
            }
        }
    }

    private function reduce_mapped_order_location_stock(WC_Order $order, $location, int $store_id): void
    {
        if (!$this->is_location_stock_enabled()) {
            return;
        }

        $changed_products = [];

        $this->stamp_order_with_location($order, $location, $store_id, false);

        foreach ($order->get_items('line_item') as $item) {
            if ((float) $item->get_meta('_mulopimfwc_pos_stock_reduced', true) > 0) {
                continue;
            }

            $product = $item->get_product();
            if (!$product) {
                continue;
            }

            $target_id = $this->get_order_item_stock_product_id($item, $product);
            if ($target_id <= 0 || !$this->product_is_assigned_to_location($target_id, (int) $location->term_id)) {
                continue;
            }

            $quantity = $this->get_order_item_stock_quantity($order, $item);
            if ($quantity <= 0) {
                continue;
            }

            $new_stock = $this->apply_location_stock_delta($target_id, (int) $location->term_id, $quantity);
            $item->update_meta_data('_store_location', (string) $location->slug);
            $item->update_meta_data('_mulopimfwc_location', (string) $location->slug);
            $item->update_meta_data('_mulopimfwc_pos_location_id', (int) $location->term_id);
            $item->update_meta_data('_mulopimfwc_pos_provider', 'wcpos');
            $item->update_meta_data('_mulopimfwc_pos_wcpos_store', $store_id);
            $item->update_meta_data('_mulopimfwc_pos_stock_reduced', $quantity);
            $item->update_meta_data('_reduced_stock', $quantity);
            $item->save();

            $changed_products[] = $target_id;
            do_action('mulopimfwc_pos_connector_wcpos_location_stock_reduced', $target_id, (int) $location->term_id, $quantity, $new_stock, $order);
        }

        if (empty($changed_products)) {
            return;
        }

        $order->save_meta_data();
        $order->get_data_store()->set_stock_reduced($order->get_id(), true);

        foreach (array_unique($changed_products) as $product_id) {
            $this->bump_wcpos_product_modified((int) $product_id);
        }
    }

    private function restore_mapped_order_location_stock(WC_Order $order, $location, int $store_id): void
    {
        if (!$this->is_location_stock_enabled()) {
            return;
        }

        $changed_products = [];

        foreach ($order->get_items('line_item') as $item) {
            $quantity = (float) $item->get_meta('_mulopimfwc_pos_stock_reduced', true);
            if ($quantity <= 0) {
                continue;
            }

            $product = $item->get_product();
            if (!$product) {
                continue;
            }

            $target_id = $this->get_order_item_stock_product_id($item, $product);
            if ($target_id <= 0) {
                continue;
            }

            $this->apply_location_stock_delta($target_id, (int) $location->term_id, -1 * $quantity);
            $item->update_meta_data('_mulopimfwc_pos_stock_reduced', 0);
            $item->update_meta_data('_reduced_stock', 0);
            $item->update_meta_data('_mulopimfwc_pos_provider', 'wcpos');
            $item->update_meta_data('_mulopimfwc_pos_wcpos_store', $store_id);
            $item->save();

            $changed_products[] = $target_id;
        }

        if (empty($changed_products)) {
            return;
        }

        $order->get_data_store()->set_stock_reduced($order->get_id(), false);

        foreach (array_unique($changed_products) as $product_id) {
            $this->bump_wcpos_product_modified((int) $product_id);
        }
    }

    private function get_store_location_id(int $store_id): int
    {
        if ($store_id <= 0) {
            return 0;
        }

        $location_id = absint(get_post_meta($store_id, self::STORE_LOCATION_META_KEY, true));
        if (!$location_id) {
            return 0;
        }

        $term = get_term($location_id, 'mulopimfwc_store_location');
        if (!$term || is_wp_error($term)) {
            return 0;
        }

        return (int) $term->term_id;
    }

    private function get_store_pricing_source(int $store_id): string
    {
        if ($store_id <= 0 || !$this->get_store_location_id($store_id)) {
            return 'default';
        }

        $source = (string) get_post_meta($store_id, self::STORE_PRICING_SOURCE_META_KEY, true);
        return in_array($source, ['default', 'mulopimfwc'], true) ? $source : 'mulopimfwc';
    }

    private function should_apply_location_price(array $context): bool
    {
        return $this->is_location_price_enabled()
            && isset($context['pricing_source'])
            && $context['pricing_source'] === 'mulopimfwc';
    }

    private function get_store_id_from_request($request): array
    {
        if (!$request instanceof WP_REST_Request) {
            return ['resolved' => false, 'id' => 0];
        }

        foreach (['store_id', 'pos_store', 'pos_store_id', 'wcpos_store_id', '_pos_store'] as $param) {
            if ($request->has_param($param)) {
                return ['resolved' => true, 'id' => absint($request->get_param($param))];
            }
        }

        $meta_store = $this->get_meta_value_from_request($request, '_pos_store');
        if ($meta_store !== null && $meta_store !== '') {
            return ['resolved' => true, 'id' => absint($meta_store)];
        }

        foreach (['x-wcpos-store-id', 'x-wc-pos-store-id', 'x-woocommerce-pos-store-id'] as $header) {
            $value = $request->get_header($header);
            if ($value !== null && $value !== '') {
                return ['resolved' => true, 'id' => absint($value)];
            }
        }

        return ['resolved' => false, 'id' => 0];
    }

    private function get_store_id_from_order(WC_Order $order): array
    {
        $store_id = $order->get_meta('_pos_store', true);
        if ($store_id !== '') {
            return ['resolved' => true, 'id' => absint($store_id)];
        }

        $store_id = $order->get_meta('_mulopimfwc_pos_wcpos_store', true);
        if ($store_id !== '') {
            return ['resolved' => true, 'id' => absint($store_id)];
        }

        return ['resolved' => false, 'id' => 0];
    }

    private function get_meta_value_from_request(WP_REST_Request $request, string $key)
    {
        $meta_data = $request->get_param('meta_data');
        if (!is_array($meta_data)) {
            return null;
        }

        foreach ($meta_data as $meta) {
            if (!is_array($meta) || !isset($meta['key']) || $meta['key'] !== $key) {
                continue;
            }

            return $meta['value'] ?? null;
        }

        return null;
    }

    private function is_wcpos_rest_request($request, array $bases = []): bool
    {
        if (!$request instanceof WP_REST_Request) {
            return false;
        }

        $route = (string) $request->get_route();
        if (strpos($route, '/wcpos/v1/') !== 0) {
            return false;
        }

        if (empty($bases)) {
            return true;
        }

        foreach ($bases as $base) {
            if (strpos($route, '/wcpos/v1/' . trim($base, '/')) === 0) {
                return true;
            }
        }

        return false;
    }

    private function is_wcpos_order_context(WC_Order $order, WP_REST_Request $request = null): bool
    {
        if ($request && $this->is_wcpos_rest_request($request, ['orders'])) {
            return true;
        }

        if ($this->current_rest_request && $this->is_wcpos_rest_request($this->current_rest_request, ['orders'])) {
            return true;
        }

        if (function_exists('woocommerce_pos_is_pos_order') && woocommerce_pos_is_pos_order($order)) {
            return true;
        }

        return method_exists($order, 'get_created_via') && $order->get_created_via() === 'woocommerce-pos';
    }

    private function request_updates_stock(WP_REST_Request $request): bool
    {
        return $request->has_param('stock_quantity') && is_numeric($request->get_param('stock_quantity'));
    }

    private function get_product_ids_for_location(int $location_id): array
    {
        if (isset($this->product_ids_by_location[$location_id])) {
            return $this->product_ids_by_location[$location_id];
        }

        $ids = get_posts([
            'post_type' => 'product',
            'post_status' => ['publish', 'private'],
            'fields' => 'ids',
            'posts_per_page' => -1,
            'no_found_rows' => true,
            'tax_query' => [$this->build_location_tax_query($location_id)],
        ]);

        $this->product_ids_by_location[$location_id] = array_map('absint', is_array($ids) ? $ids : []);

        return $this->product_ids_by_location[$location_id];
    }

    private function build_location_tax_query(int $location_id): array
    {
        $assigned_to_location = [
            'taxonomy' => 'mulopimfwc_store_location',
            'field' => 'term_id',
            'terms' => [$location_id],
            'operator' => 'IN',
            'include_children' => false,
        ];

        if (!$this->is_all_locations_enabled()) {
            return $assigned_to_location;
        }

        return [
            'relation' => 'OR',
            $assigned_to_location,
            [
                'taxonomy' => 'mulopimfwc_store_location',
                'operator' => 'NOT EXISTS',
            ],
        ];
    }

    private function get_location_stock($product, int $location_id)
    {
        if (function_exists('mulopimfwc_get_location_stock_quantity_for_product')) {
            return mulopimfwc_get_location_stock_quantity_for_product($product, $location_id);
        }

        if (!is_object($product) || !method_exists($product, 'get_id')) {
            return null;
        }

        $stock = get_post_meta($product->get_id(), '_location_stock_' . $location_id, true);
        if ($stock !== '' && is_numeric($stock)) {
            return (float) $stock;
        }

        return method_exists($product, 'get_stock_quantity') ? $product->get_stock_quantity() : null;
    }

    private function get_location_price_data(int $product_id, int $variation_id, int $location_id): array
    {
        $price_data = function_exists('mulopimfwc_get_runtime_price_data_for_location')
            ? mulopimfwc_get_runtime_price_data_for_location($product_id, $variation_id, $location_id)
            : [];

        $active = isset($price_data['active']) && $price_data['active'] !== '' ? (float) $price_data['active'] : null;
        if ($active === null) {
            return [];
        }

        $regular = isset($price_data['regular']) && $price_data['regular'] !== '' ? (float) $price_data['regular'] : $active;
        $sale = isset($price_data['sale']) && $price_data['sale'] !== '' ? (float) $price_data['sale'] : '';

        return [
            'active' => $active,
            'regular' => $regular,
            'sale' => $sale,
        ];
    }

    private function product_is_assigned_to_location(int $product_id, int $location_id): bool
    {
        if ($product_id <= 0 || $location_id <= 0) {
            return false;
        }

        $ids_to_check = [$product_id];
        $parent_id = wp_get_post_parent_id($product_id);
        if ($parent_id > 0) {
            $ids_to_check[] = $parent_id;
        }

        $has_any_location_terms = false;

        foreach (array_unique($ids_to_check) as $id) {
            $terms = wp_get_object_terms($id, 'mulopimfwc_store_location', ['fields' => 'ids']);
            if (is_wp_error($terms)) {
                continue;
            }

            $terms = array_map('intval', $terms);
            if (!empty($terms)) {
                $has_any_location_terms = true;
            }

            if (in_array($location_id, $terms, true)) {
                return !$this->is_location_disabled_for_product($product_id, $location_id)
                    && !$this->is_location_disabled_for_product((int) $id, $location_id);
            }
        }

        return !$has_any_location_terms
            && $this->is_all_locations_enabled()
            && !$this->is_location_disabled_for_product($product_id, $location_id);
    }

    private function is_location_disabled_for_product(int $product_id, int $location_id): bool
    {
        if ($product_id <= 0 || $location_id <= 0) {
            return false;
        }

        $disabled = get_post_meta($product_id, '_location_disabled_' . $location_id, true);
        return $disabled !== '' && $disabled !== '0' && $disabled !== 'no' && $disabled !== 'off';
    }

    private function location_backorders_allowed(int $product_id, int $location_id, $product = null): bool
    {
        if (!$product && $product_id > 0) {
            $product = wc_get_product($product_id);
        }

        $backorders = $this->is_location_backorder_enabled() && function_exists('mulopimfwc_get_effective_location_backorders')
            ? mulopimfwc_get_effective_location_backorders($product_id, $location_id)
            : (is_object($product) && method_exists($product, 'get_backorders') ? $product->get_backorders() : 'no');

        return function_exists('mulopimfwc_is_backorder_allowed')
            ? mulopimfwc_is_backorder_allowed($backorders)
            : in_array($backorders, ['yes', 'notify'], true);
    }

    private function is_location_stock_enabled(): bool
    {
        return $this->is_main_option_enabled('enable_location_stock');
    }

    private function is_location_price_enabled(): bool
    {
        return $this->is_main_option_enabled('enable_location_price');
    }

    private function is_location_backorder_enabled(): bool
    {
        return $this->is_main_option_enabled('enable_location_backorder');
    }

    private function is_all_locations_enabled(): bool
    {
        global $mulopimfwc_options;

        $options = is_array($mulopimfwc_options ?? null)
            ? $mulopimfwc_options
            : get_option('mulopimfwc_display_options', []);

        if (function_exists('mulopimfwc_is_all_locations_enabled')) {
            return mulopimfwc_is_all_locations_enabled($options);
        }

        return isset($options['enable_all_locations']) && $options['enable_all_locations'] === 'on';
    }

    private function is_main_option_enabled(string $key): bool
    {
        global $mulopimfwc_options;

        $options = is_array($mulopimfwc_options ?? null)
            ? $mulopimfwc_options
            : get_option('mulopimfwc_display_options', []);

        return isset($options[$key]) && $options[$key] === 'on';
    }

    private function format_location_price(float $price, int $location_id): string
    {
        return function_exists('mulopimfwc_format_runtime_price_for_location_display')
            ? mulopimfwc_format_runtime_price_for_location_display($price, $location_id)
            : wc_price($price);
    }

    private function normalize_quantity_for_response($stock)
    {
        $stock = (float) $stock;
        return abs($stock - round($stock)) < 0.000001 ? (int) round($stock) : $stock;
    }

    private function upsert_meta_data(array $meta_data, string $key, $value): array
    {
        foreach ($meta_data as $index => $meta) {
            if (is_array($meta) && isset($meta['key']) && $meta['key'] === $key) {
                $meta_data[$index]['value'] = $value;
                return $meta_data;
            }
        }

        $meta_data[] = [
            'id' => 0,
            'key' => $key,
            'value' => $value,
        ];

        return $meta_data;
    }

    private function get_order_item_stock_product_id($item, $product): int
    {
        $variation_id = method_exists($item, 'get_variation_id') ? absint($item->get_variation_id()) : 0;
        if ($variation_id > 0) {
            return $variation_id;
        }

        return is_object($product) && method_exists($product, 'get_id') ? absint($product->get_id()) : 0;
    }

    private function get_order_item_stock_quantity($order, $item): float
    {
        $quantity = method_exists($item, 'get_quantity') ? (float) $item->get_quantity() : 0.0;
        $quantity = apply_filters('woocommerce_order_item_quantity', $quantity, $order, $item);

        return max(0, (float) $quantity);
    }

    private function apply_location_stock_delta(int $product_id, int $location_id, float $quantity_delta): float
    {
        $meta_key = '_location_stock_' . $location_id;
        $current_stock = get_post_meta($product_id, $meta_key, true);

        if ($current_stock === '' || !is_numeric($current_stock)) {
            $product = wc_get_product($product_id);
            $current_stock = $product && method_exists($product, 'get_stock_quantity') ? $product->get_stock_quantity() : 0;
        }

        $current_stock = (float) $current_stock;
        $new_stock = $current_stock - $quantity_delta;

        if ($quantity_delta > 0) {
            $backorders = function_exists('mulopimfwc_get_effective_location_backorders')
                ? mulopimfwc_get_effective_location_backorders($product_id, $location_id)
                : 'no';
            $backorders_allowed = function_exists('mulopimfwc_is_backorder_allowed')
                ? mulopimfwc_is_backorder_allowed($backorders)
                : in_array($backorders, ['yes', 'notify'], true);

            if (!$backorders_allowed) {
                $new_stock = max(0, $new_stock);
            }
        }

        update_post_meta($product_id, $meta_key, wc_format_decimal($new_stock));

        return (float) $new_stock;
    }

    private function restore_global_stock_after_wcpos_update(int $product_id, $old_stock): void
    {
        if (is_numeric($old_stock)) {
            $product = wc_get_product($product_id);
            if ($product) {
                wc_update_product_stock($product, (float) $old_stock, 'set');
                return;
            }
        }

        if ($old_stock === '') {
            delete_post_meta($product_id, '_stock');
        }
    }

    private function bump_wcpos_product_modified(int $product_id): void
    {
        $ids = [$product_id];
        $parent_id = wp_get_post_parent_id($product_id);
        if ($parent_id > 0) {
            $ids[] = $parent_id;
        }

        foreach (array_unique(array_map('absint', $ids)) as $id) {
            if ($id <= 0) {
                continue;
            }

            wp_update_post([
                'ID' => $id,
                'post_modified' => current_time('mysql'),
                'post_modified_gmt' => current_time('mysql', true),
            ]);

            if (function_exists('wc_delete_product_transients')) {
                wc_delete_product_transients($id);
            }
        }
    }

    private function get_store_locations(): array
    {
        if ($this->locations_cache !== null) {
            return $this->locations_cache;
        }

        if (!taxonomy_exists('mulopimfwc_store_location')) {
            $this->locations_cache = [];
            return $this->locations_cache;
        }

        $terms = get_terms([
            'taxonomy' => 'mulopimfwc_store_location',
            'hide_empty' => false,
        ]);

        $this->locations_cache = is_wp_error($terms) || !is_array($terms) ? [] : $terms;
        return $this->locations_cache;
    }

    private function get_locations_for_js(): array
    {
        $locations = [];

        foreach ($this->get_store_locations() as $location) {
            $locations[] = [
                'value' => (string) $location->term_id,
                'label' => (string) $location->name,
            ];
        }

        return $locations;
    }

    private function is_list_array($items): bool
    {
        if (!is_array($items)) {
            return false;
        }

        $expected_key = 0;
        foreach ($items as $key => $unused) {
            if ($key !== $expected_key) {
                return false;
            }
            ++$expected_key;
        }

        return true;
    }

    private function migrate_legacy_store_mappings(): void
    {
        if (get_option(self::LEGACY_MIGRATED_OPTION, 'no') === 'yes') {
            return;
        }

        $legacy = get_option(MULOPIMFWC_POS_Connector::OPTION_WCPOS_LEGACY_MAPPINGS, []);
        $ignored_default_mapping = false;

        if (is_array($legacy)) {
            foreach ($legacy as $store_id => $location_id) {
                $store_id = absint($store_id);
                $location_id = absint($location_id);

                if ($store_id <= 0) {
                    $ignored_default_mapping = $ignored_default_mapping || $location_id > 0;
                    continue;
                }

                if (!$location_id || $this->get_store_location_id($store_id)) {
                    continue;
                }

                $location = get_term($location_id, 'mulopimfwc_store_location');
                if ($location && !is_wp_error($location)) {
                    update_post_meta($store_id, self::STORE_LOCATION_META_KEY, $location_id);
                    update_post_meta($store_id, self::STORE_PRICING_SOURCE_META_KEY, 'mulopimfwc');
                }
            }
        }

        if ($ignored_default_mapping) {
            $this->record_warning(__('A legacy WCPOS default Store ID 0 mapping was ignored. WCPOS default store cannot safely represent multiple physical inventories; configure WCPOS Pro stores instead.', 'mulopimfwc-pos-connector'));
        }

        update_option(self::LEGACY_MIGRATED_OPTION, 'yes', false);
    }

    private function record_warning(string $message): void
    {
        update_option(
            MULOPIMFWC_POS_Connector::OPTION_WCPOS_LAST_WARNING,
            [
                'message' => $message,
                'time' => time(),
            ],
            false
        );
    }
}
