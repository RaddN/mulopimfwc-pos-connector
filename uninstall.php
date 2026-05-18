<?php
if (!defined('WP_UNINSTALL_PLUGIN')) {
    exit;
}

delete_option('mulopimfwc_pos_connector_enabled');
delete_option('mulopimfwc_pos_openpos_mappings');
