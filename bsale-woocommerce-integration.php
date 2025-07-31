<?php
/**
 * Plugin Name:         Integración Bsale y WooCommerce 
 * Plugin URI:          https://whydot.co
 * Description:         Sincroniza productos, stock, pedidos y facturación entre Bsale y WooCommerce.
 * Version:             3.1.9
 * Author:              WHYDOTCO
 * Author URI:          https://whydot.co
 * License:             GPLv2 or later
 * License URI:         https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:         bsale-woocommerce-integration-lov
 * Domain Path:         /languages
 * WC requires at least: 3.0
 * WC tested up to:     8.0
 */

// Evitar el acceso directo al archivo
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}
// Declarar la compatibilidad de forma programática.
add_action( 'before_woocommerce_init', function() {
    if ( class_exists( \Automattic\WooCommerce\Utilities\FeaturesUtil::class ) ) {
        \Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'custom_order_tables', __FILE__, true );
    }
} );

// Definir constantes del plugin
define( 'BWI_PLUGIN_PATH', plugin_dir_path( __FILE__ ) );
define( 'BWI_PLUGIN_URL', plugin_dir_url( __FILE__ ) );
// Registrar los hooks de activación y desactivación.
register_activation_hook( __FILE__, 'bwi_activate_plugin' );
register_deactivation_hook( __FILE__, 'bwi_deactivate_plugin' );

/**
 * Función que se ejecuta UNA VEZ cuando el plugin se activa.
 */
function bwi_activate_plugin() {
    if ( ! wp_next_scheduled( 'bwi_cron_sync_products' ) ) {
        wp_schedule_event( time(), 'hourly', 'bwi_cron_sync_products' );
    }
    set_transient( 'bwi_run_setup', true, MINUTE_IN_SECONDS );
}

/**
 * Hook que se ejecuta en cada carga del panel de admin para verificar la bandera.
 */
add_action( 'admin_init', 'bwi_run_setup_if_needed' );

/**
 * Ejecuta la configuración de WooCommerce si la bandera de activación está presente.
 */
function bwi_run_setup_if_needed() {
    if ( ! get_transient( 'bwi_run_setup' ) ) {
        return;
    }
    delete_transient( 'bwi_run_setup' );

    update_option('woocommerce_calc_taxes', 'yes');
    update_option('woocommerce_price_num_decimals', '2');
    update_option('woocommerce_prices_include_tax', 'yes');
    update_option('woocommerce_tax_based_on', 'shipping');
    update_option('woocommerce_shipping_tax_class', '');
    update_option('woocommerce_tax_display_shop', 'incl');
    update_option('woocommerce_tax_display_cart', 'incl');
    update_option('woocommerce_price_display_suffix', ' IVA incluido');
    update_option('woocommerce_tax_total_display', 'single');

    bwi_install_standard_tax_rate();
}

/**
 * Crea la tasa de impuesto estándar del 19% para Chile.
 */
function bwi_install_standard_tax_rate() {
    if ( ! function_exists( 'WC' ) || ! isset( $GLOBALS['wpdb'] ) ) {
        return;
    }

    global $wpdb;
    $table_name = $wpdb->prefix . 'woocommerce_tax_rates';
    $tax_rate_country  = '';
    $tax_rate_state    = '';
    $tax_rate_name     = 'IVA';
    $tax_rate_class    = '';

    $sql = $wpdb->prepare(
        "SELECT tax_rate_id FROM `{$table_name}` WHERE tax_rate_country = %s AND tax_rate_state = %s AND tax_rate_name = %s AND tax_rate_class = %s",
        $tax_rate_country,
        $tax_rate_state,
        $tax_rate_name,
        $tax_rate_class
    );
    $existing_rate_id = $wpdb->get_var( $sql );

    if ( null === $existing_rate_id ) {
        $wpdb->insert(
            $table_name,
            [
                'tax_rate_country'  => $tax_rate_country,
                'tax_rate_state'    => $tax_rate_state,
                'tax_rate'          => '19.0000',
                'tax_rate_name'     => $tax_rate_name,
                'tax_rate_priority' => 1,
                'tax_rate_compound' => 0,
                'tax_rate_shipping' => 1,
                'tax_rate_order'    => 0,
                'tax_rate_class'    => $tax_rate_class,
            ]
        );
    }
}

/**
 * Función que se ejecuta UNA VEZ cuando el plugin se desactiva.
 */
function bwi_deactivate_plugin() {
    wp_clear_scheduled_hook( 'bwi_cron_sync_products' );
}

/**
 * Oculta los decimales en los precios mostrados en la tienda (front-end),
 * pero mantiene 2 decimales para los cálculos del sistema y el panel de administración.
 * Esto asegura la compatibilidad con Bsale sin afectar la experiencia del cliente.
 */
add_filter( 'wc_price_args', 'bwi_hide_decimals_on_frontend' );

function bwi_hide_decimals_on_frontend( $args ) {
    // Solo aplicar el cambio en el front-end (la tienda que ve el cliente).
    if ( ! is_admin() ) {
        $args['decimals'] = 0;
    }
    return $args;
}


/**
 * Clase principal de la Integración.
 * (El resto del código no cambia)
 */
final class Bsale_WooCommerce_Integration {
    private static $instance;
    private function __construct() {
        add_action( 'plugins_loaded', [ $this, 'init_plugin' ] );
    }
    public static function get_instance() {
        if ( ! isset( self::$instance ) ) {
            self::$instance = new self();
        }
        return self::$instance;
    }
    public function init_plugin() {
        if ( ! class_exists( 'WooCommerce' ) ) {
            add_action( 'admin_notices', [ $this, 'notice_woocommerce_not_active' ] );
            return;
        }
        $this->load_textdomain();
        $this->load_dependencies();
        $this->init_classes();
    }
    public function load_textdomain() {
        load_plugin_textdomain(
            'bsale-woocommerce-integration-lov',
            false,
            dirname( plugin_basename( __FILE__ ) ) . '/languages'
        );
    }
    private function load_dependencies() {
        require_once BWI_PLUGIN_PATH . 'includes/class-bwi-admin.php';
        require_once BWI_PLUGIN_PATH . 'includes/class-bwi-api-client.php';
        require_once BWI_PLUGIN_PATH . 'includes/class-bwi-product-sync.php';
        require_once BWI_PLUGIN_PATH . 'includes/class-bwi-order-sync.php';
        require_once BWI_PLUGIN_PATH . 'includes/class-bwi-checkout.php';
        require_once BWI_PLUGIN_PATH . 'includes/class-bwi-webhooks.php';
    }
    private function init_classes() {
        BWI_Admin::get_instance();
        BWI_Product_Sync::get_instance();
        BWI_Order_Sync::get_instance();
        BWI_Checkout::get_instance();
        BWI_Webhooks::get_instance();
        add_filter( 'woocommerce_email_classes', [ $this, 'add_bsale_email_to_woocommerce' ] );
    }
    public function notice_woocommerce_not_active() {
        ?>
        <div class="error">
            <p>
                <strong><?php _e( 'Integración Bsale y WooCommerce', 'bsale-woocommerce-integration' ); ?></strong>
                <?php _e( 'requiere que WooCommerce esté instalado y activo.', 'bsale-woocommerce-integration' ); ?>
            </p>
        </div>
        <?php
    }
    public function add_bsale_email_to_woocommerce( $email_classes ) {
        $email_classes['BWI_Email_Customer_Document'] = include( BWI_PLUGIN_PATH . 'emails/class-bwi-email-customer-document.php' );
        return $email_classes;
    }
}
function bwi_run_plugin() {
    return Bsale_WooCommerce_Integration::get_instance();
}
bwi_run_plugin();