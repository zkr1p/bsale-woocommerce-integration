<?php
/**
 * Plugin Name:         Integración Bsale y WooCommerce - LOV
 * Plugin URI:          https://whydot.co
 * Description:         Sincroniza productos, stock, pedidos y facturación entre Bsale y WooCommerce basado en la documentación actualizada de la API de Bsale.
 * Version:             2.9.1
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
        // MEJORA CRÍTICA: No cargar nada aquí. Solo enganchar la inicialización al hook correcto.
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
        // 1. Verificar si WooCommerce está activo.
        if ( ! class_exists( 'WooCommerce' ) ) {
            add_action( 'admin_notices', [ $this, 'notice_woocommerce_not_active' ] );
            return;
        }

        // --- INICIO DE LA MODIFICACIÓN ---
        $this->load_textdomain();
        // --- FIN DE LA MODIFICACIÓN ---

        // 2. Ahora que sabemos que WC está activo, cargamos nuestras dependencias.
        $this->load_dependencies();

        // 3. Inicializamos nuestras clases.
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
     * Añade nuestro correo personalizado a la lista de correos de WooCommerce.
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

// ¡Iniciamos el plugin!
bwi_run_plugin();