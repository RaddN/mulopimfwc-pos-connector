<?php
if (!defined('ABSPATH')) {
    exit;
}

class MULOPIMFWC_POS_Connector
{
    const OPTION_ENABLED = 'mulopimfwc_pos_connector_enabled';
    const OPTION_OPENPOS_MAPPINGS = 'mulopimfwc_pos_openpos_mappings';

    private static $instance = null;
    private $openpos_provider = null;

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

        $provider = $this->openpos_provider instanceof MULOPIMFWC_POS_OpenPOS_Provider
            ? $this->openpos_provider
            : new MULOPIMFWC_POS_OpenPOS_Provider();

        if (!$provider->is_available()) {
            $missing[] = __('OpenPOS', 'mulopimfwc-pos-connector');
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
        $locations = taxonomy_exists('mulopimfwc_store_location')
            ? get_terms([
                'taxonomy' => 'mulopimfwc_store_location',
                'hide_empty' => false,
            ])
            : [];
        ?>
        <div class="wrap mulopimfwc-pos-connector-page">
            <h1><?php echo esc_html__('Multi Location POS Connector', 'mulopimfwc-pos-connector'); ?></h1>
            <p><?php echo esc_html__('Map POS outlets to store locations. Mapped outlets use Multi Location stock and pricing as the source of truth.', 'mulopimfwc-pos-connector'); ?></p>

            <?php if (!$openpos_active) : ?>
                <div class="notice notice-warning">
                    <p><?php echo esc_html__('OpenPOS is not active. The connector is installed safely, but no POS integration hooks are running.', 'mulopimfwc-pos-connector'); ?></p>
                </div>
            <?php endif; ?>

            <?php if (isset($mappings['0'])) : ?>
                <div class="notice notice-info">
                    <p><?php echo esc_html__('The OpenPOS default online store outlet is mapped. The connector will protect WooCommerce global stock by reducing mapped location stock before OpenPOS stock reduction runs.', 'mulopimfwc-pos-connector'); ?></p>
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

                <?php submit_button(__('Save POS Connector Settings', 'mulopimfwc-pos-connector')); ?>
            </form>
        </div>
        <style>
            .mulopimfwc-openpos-mapping-table select {
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
        $warehouse_id = $this->current_query_warehouse_id !== null ? absint($this->current_query_warehouse_id) : 0;
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

        $location_tax_query = [
            'taxonomy' => 'mulopimfwc_store_location',
            'field' => 'term_id',
            'terms' => [$location_id],
            'operator' => 'IN',
            'include_children' => false,
        ];

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

            $quantity = $this->get_order_item_stock_quantity($order, $item);
            if ($quantity <= 0) {
                continue;
            }

            $new_stock = $this->apply_location_stock_delta($target_id, (int) $location->term_id, $quantity);
            $item->update_meta_data('_mulopimfwc_location', (string) $location->slug);
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

            $backorders = function_exists('mulopimfwc_get_effective_location_backorders')
                ? mulopimfwc_get_effective_location_backorders($target_product_id, $location_id)
                : (method_exists($product, 'get_backorders') ? $product->get_backorders() : 'no');
            $backorders_allowed = $this->is_location_backorder_enabled() && function_exists('mulopimfwc_is_backorder_allowed')
                ? mulopimfwc_is_backorder_allowed($backorders)
                : in_array($backorders, ['yes', 'notify'], true);

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
                    if ($variation_product && $this->is_location_stock_enabled()) {
                        $stock = $this->get_location_stock($variation_product, $location_id);
                        if (is_numeric($stock)) {
                            $stock_qty += (float) $stock;
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
                    $data['variations'][$variation_group_index]['options'][$option_index]['stock_status'] = $stock_qty > 0 ? 'instock' : 'outofstock';
                    $total_stock += $stock_qty;
                }
            }
        }

        if ($this->is_location_stock_enabled()) {
            $data['qty'] = $total_stock;
            $data['stock_quantity'] = $total_stock;
            $data['stock_status'] = $total_stock > 0 ? 'instock' : 'outofstock';
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

        foreach (array_unique($ids_to_check) as $id) {
            $terms = wp_get_object_terms($id, 'mulopimfwc_store_location', ['fields' => 'ids']);
            if (is_wp_error($terms)) {
                continue;
            }

            if (in_array($location_id, array_map('intval', $terms), true)) {
                return true;
            }
        }

        return false;
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
