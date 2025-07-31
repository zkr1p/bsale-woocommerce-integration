<?php
/**
 * Plugin Name:         Integración Bsale y WooCommerce - Dot
 * Plugin URI:          https://whydot.co
 * Description:         Sincroniza productos, stock, pedidos y facturación entre Bsale y WooCommerce basado en la documentación actualizada de la API de Bsale.
 * Version:             3.1.0
 * Author:              WHYDOTCO
 * Author URI:          https://whydot.co
 * License:             GPLv2 or later
 * License URI:         https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:         bsale-woocommerce-integration-lov
 * Domain Path:         /languages
 * WC requires at least: 3.0
 * WC tested up to:     8.0
 */

//coment

// Evitar el acceso directo al archivo
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}
// Declarar la compatibilidad de forma programática, que es el método más robusto.
add_action( 'before_woocommerce_init', function() {
    if ( class_exists( \Automattic\WooCommerce\Utilities\FeaturesUtil::class ) ) {
        \Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'custom_order_tables', __FILE__, true );
    }
} );

// Definir constantes del plugin para rutas y URLs
define( 'BWI_PLUGIN_PATH', plugin_dir_path( __FILE__ ) );
define( 'BWI_PLUGIN_URL', plugin_dir_url( __FILE__ ) );
// Registrar los hooks de activación y desactivación del plugin.
register_activation_hook( __FILE__, 'bwi_activate_plugin' );
register_deactivation_hook( __FILE__, 'bwi_deactivate_plugin' );
/**
 * Función que se ejecuta UNA VEZ cuando el plugin se activa.
 */
function bwi_activate_plugin() {
    // Programar el evento de sincronización si no existe ya.
    if ( ! wp_next_scheduled( 'bwi_cron_sync_products' ) ) {
        wp_schedule_event( time(), 'hourly', 'bwi_cron_sync_products' );
    }

    // Configurar las opciones de WooCommerce para impuestos y moneda.
    // Esto asegura que la tienda esté lista para operar con IVA incluido desde el inicio.
    update_option('woocommerce_calc_taxes', 'yes'); // Activar tasas de impuestos y sus cálculos
    update_option('woocommerce_price_num_decimals', '2'); // Número de decimales en 2
    update_option('woocommerce_prices_include_tax', 'yes'); // Introducir precios con impuestos incluidos
    update_option('woocommerce_tax_based_on', 'shipping'); // Calcular impuesto basado en la dirección de envío del cliente
    update_option('woocommerce_shipping_tax_class', ''); // Asignar la clase de impuesto estándar al envío. Vacío significa 'estándar'.
    update_option('woocommerce_tax_display_shop', 'incl'); // Mostrar precios en la tienda con impuestos incluidos
    update_option('woocommerce_tax_display_cart', 'incl'); // Mostrar precios en carrito/pago con impuestos incluidos
    update_option('woocommerce_price_display_suffix', ' IVA incluido'); // Sufijo a mostrar en el precio. Espacio inicial para separación.
    update_option('woocommerce_tax_total_display', 'single'); // Visualización del total de impuestos como un total único

    // Crear la tasa de impuesto estándar si no existe.
    // Se llama a una función separada para mantener el código limpio.
    bwi_install_standard_tax_rate();
}

/**
 * Crea la tasa de impuesto estándar del 19% para Chile.
 * Verifica si ya existe una tasa similar para no crear duplicados.
 */
function bwi_install_standard_tax_rate() {
    // Asegurarse de que WooCommerce está activo y sus funciones disponibles.
    if ( ! function_exists( 'WC' ) ) {
        return;
    }

    $tax_rate = [
        'tax_rate_country'  => '', // Aplicar a todos los países
        'tax_rate_state'    => '', // Aplicar a todos los estados/regiones
        'tax_rate'          => '19.0000', // Tasa del 19%
        'tax_rate_name'     => 'IVA', // Nombre del impuesto
        'tax_rate_priority' => 1,
        'tax_rate_compound' => 0, // No es un impuesto compuesto
        'tax_rate_shipping' => 1, // Aplicar esta tasa al envío
        'tax_rate_class'    => 'standard', // Clase de impuesto estándar
    ];

    // Verificar si ya existe una tasa con estas características para no duplicarla
    $existing_rates = WC_Tax::find_rates($tax_rate);

    if ( empty( $existing_rates ) ) {
        // Si no existe, se crea la tasa.
        WC_Tax::create_rate( $tax_rate );
    }
}

/**
 * Función que se ejecuta UNA VEZ cuando el plugin se desactiva.
 */
function bwi_deactivate_plugin() {
    // Limpiar la tarea programada para no dejar basura.
    wp_clear_scheduled_hook( 'bwi_cron_sync_products' );
}
/**
 * Clase principal de la Integración.
 * Se encarga de cargar todas las dependencias y de inicializar el plugin.
 */
final class Bsale_WooCommerce_Integration {

    /**
     * Instancia única de la clase.
     * @var Bsale_WooCommerce_Integration
     */
    private static $instance;

    /**
     * Constructor principal.
     * Es privado para asegurar que solo exista una instancia (patrón Singleton).
     */
    private function __construct() {
        add_action( 'plugins_loaded', [ $this, 'init_plugin' ] );
    }

    /**
     * Método para obtener la instancia única de la clase.
     */
    public static function get_instance() {
        if ( ! isset( self::$instance ) ) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    /**
     * Inicializa el plugin. Se ejecuta solo cuando todos los plugins están cargados.
     */
    public function init_plugin() {
        //Verificar si WooCommerce está activo.
        if ( ! class_exists( 'WooCommerce' ) ) {
            add_action( 'admin_notices', [ $this, 'notice_woocommerce_not_active' ] );
            return;
        }

        $this->load_textdomain();

        // Ahora que sabemos que WC está activo, cargamos nuestras dependencias.
        $this->load_dependencies();

        // Inicializamos nuestras clases.
        $this->init_classes();
    }

    /**
     * Carga el text domain del plugin para la internacionalización.
     */
    public function load_textdomain() {
        load_plugin_textdomain(
            'bsale-woocommerce-integration-lov',
            false,
            dirname( plugin_basename( __FILE__ ) ) . '/languages'
        );
    }
    /**
     * Carga los archivos necesarios para el funcionamiento del plugin.
     */
    private function load_dependencies() {
        // Clase para manejar el panel de administración y configuraciones
        require_once BWI_PLUGIN_PATH . 'includes/class-bwi-admin.php';

        // Clase para manejar la comunicación con la API de Bsale
        require_once BWI_PLUGIN_PATH . 'includes/class-bwi-api-client.php';

        // Clase para la sincronización de productos
        require_once BWI_PLUGIN_PATH . 'includes/class-bwi-product-sync.php';

        // Clase para la sincronización de pedidos y facturación
        require_once BWI_PLUGIN_PATH . 'includes/class-bwi-order-sync.php';

        // Clase para modificar el checkout
        require_once BWI_PLUGIN_PATH . 'includes/class-bwi-checkout.php';

        // Clase para manejar los Webhooks entrantes de Bsale
        require_once BWI_PLUGIN_PATH . 'includes/class-bwi-webhooks.php';

    }

    /**
     * Inicializa los hooks de WordPress y las clases del plugin.
     */
    private function init_classes() {
        // Inicializar las clases principales
        BWI_Admin::get_instance();
        BWI_Product_Sync::get_instance();
        BWI_Order_Sync::get_instance();
        BWI_Checkout::get_instance();
        BWI_Webhooks::get_instance();
        add_filter( 'woocommerce_email_classes', [ $this, 'add_bsale_email_to_woocommerce' ] );
    }

    /**
     * Verifica si WooCommerce está activo. Si no, desactiva el plugin y muestra un aviso.
     */
    public function check_woocommerce_active() {
        if ( ! class_exists( 'WooCommerce' ) ) {
            add_action( 'admin_notices', [ $this, 'notice_woocommerce_not_active' ] );
            deactivate_plugins( plugin_basename( __FILE__ ) );
            if ( isset( $_GET['activate'] ) ) {
                unset( $_GET['activate'] );
            }
        }
    }

    /**
     * Muestra un aviso en el admin si WooCommerce no está activo.
     */
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

    /**
     * Añade el correo personalizado a la lista de correos de WooCommerce.
     * Carga el archivo de la clase justo en el momento necesario para evitar errores de carga.
     *
     * @param array $email_classes Las clases de correo existentes.
     * @return array La lista de clases con nuestro correo añadido.
     */
    public function add_bsale_email_to_woocommerce( $email_classes ) {
        // Incluimos el archivo de nuestra clase de correo aquí.
        // Esto garantiza que se carga solo cuando WooCommerce está listo.
        $email_classes['BWI_Email_Customer_Document'] = include( BWI_PLUGIN_PATH . 'emails/class-bwi-email-customer-document.php' );
        return $email_classes;
    }
}

/**
 * Función global para iniciar el plugin.
 * Se asegura de que el plugin se cargue solo una vez.
 */
function bwi_run_plugin() {
    return Bsale_WooCommerce_Integration::get_instance();
}

// Iniciamos el plugin
bwi_run_plugin();