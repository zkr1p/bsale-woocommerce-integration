<?php
/**
 * Clase para manejar los webhooks entrantes desde Bsale.
 *
 * @package Bsale_WooCommerce_Integration
 */

// Evitar el acceso directo al archivo.
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * BWI_Webhooks Class
 */
final class BWI_Webhooks {

    /** Instancia única de la clase. */
    private static $instance;

    /**
     * Constructor.
     */
    private function __construct() {
        // Registrar el endpoint personalizado para los webhooks.
        add_action( 'rest_api_init', [ $this, 'register_webhook_endpoint' ] );
        // Registrar la acción asíncrona que procesará el payload.
        add_action( 'bwi_process_webhook_payload', [ $this, 'process_webhook_payload' ] );
    }

    /**
     * Obtener la instancia única de la clase.
     */
    public static function get_instance() {
        if ( ! isset( self::$instance ) ) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    /**
     * Registra el endpoint en la API REST de WordPress.
     */
    public function register_webhook_endpoint() {
        register_rest_route( 'bwi/v1', '/webhook/(?P<token>\S+)', [
            'methods'             => 'POST',
            'callback'            => [ $this, 'handle_incoming_webhook' ],
            'permission_callback' => '__return_true',
        ] );
    }

    /**
     * Maneja la petición entrante del webhook de Bsale.
     *
     * @param WP_REST_Request $request El objeto de la petición.
     * @return WP_REST_Response
     */
    public function handle_incoming_webhook( WP_REST_Request $request ) {
        $logger = wc_get_logger();
        
        // Leer el token desde la ruta de la URL, no desde un parámetro.
        $received_token = $request->get_param('token');
        $secret_token = defined('BWI_WEBHOOK_SECRET') ? BWI_WEBHOOK_SECRET : '';

        if ( empty($secret_token) || ! $received_token || ! hash_equals( $secret_token, $received_token ) ) {
            $logger->error( 'Intento de acceso a Webhook con token de seguridad inválido.', [ 'source' => 'bwi-webhooks' ] );
            return new WP_REST_Response( [ 'status' => 'error', 'message' => 'Token de seguridad inválido.' ], 401 );
        }

        $payload = $request->get_json_params();
        $logger->info( 'Webhook de Bsale recibido y validado: ' . wp_json_encode( $payload ), [ 'source' => 'bwi-webhooks' ] );

        if ( ! isset( $payload['topic'] ) ) {
            return new WP_REST_Response( [ 'status' => 'error', 'message' => 'Payload inválido.' ], 400 );
        }

        as_enqueue_async_action( 'bwi_process_webhook_payload', [ 'payload' => $payload ], 'bwi-webhooks' );

        return new WP_REST_Response( [ 'status' => 'success', 'message' => 'Webhook recibido y encolado.' ], 200 );
    }

    /**
     * Procesa una actualización notificada por un webhook.
     * Esta función es ejecutada por Action Scheduler en segundo plano.
     *
     * @param array $payload El payload del webhook.
     */
    public function process_webhook_payload( $payload ) {
        $logger = wc_get_logger();

        if ( ! isset( $payload['topic'] ) ) {
            $logger->warning( 'Payload de webhook inválido: no contiene "topic".', [ 'source' => 'bwi-webhooks' ] );
            return;
        }

        $topic = $payload['topic'];
        $logger->info( "Procesando webhook para el tópico: '{$topic}'", [ 'source' => 'bwi-webhooks' ] );

        switch ( $topic ) {
            // --- INICIO DE LA CORRECCIÓN ---
            // Añadimos el caso para 'stock' que es el que envía Bsale.
            case 'stock':
            // --- FIN DE LA CORRECCIÓN ---
            case 'stock.update':
            case 'stock.created':
                BWI_Product_Sync::get_instance()->update_stock_from_webhook( $payload );
                break;
            
            case 'price':
                if ( isset($payload['action']) && $payload['action'] === 'put' ) {
                    BWI_Product_Sync::get_instance()->update_price_from_webhook( $payload );
                }
                break;

            case 'variant':
                // Solo nos interesan las actualizaciones (que incluyen la desactivación)
                if ( isset($payload['action']) && $payload['action'] === 'put' ) {
                    BWI_Product_Sync::get_instance()->handle_variant_update_webhook( $payload );
                }
                break;
                
            case 'document.created':
                // Aquí podríamos añadir lógica para, por ejemplo, actualizar el estado de un pedido.
                // BWI_Order_Sync::get_instance()->update_order_from_webhook($payload);
                break;
            
            default:
                $logger->info( "Webhook recibido para el tópico '{$topic}', pero no hay una acción configurada para él.", [ 'source' => 'bwi-webhooks' ] );
                break;
        }
    }

    private function process_stock_update( $payload ) {
        $logger = wc_get_logger();
        
        // El payload debería contener la información del stock.
        // Asumimos que Bsale envía el SKU y la nueva cantidad.
        // La estructura real del payload debe ser verificada.
        if ( ! isset( $payload['data']['sku'] ) || ! isset( $payload['data']['quantity'] ) ) {
             $logger->warning( 'Payload de webhook de stock sin SKU o cantidad.', [ 'source' => 'bwi-webhooks' ] );
            return;
        }

        $sku = sanitize_text_field( $payload['data']['sku'] );
        $quantity = intval( $payload['data']['quantity'] );

        $product_id = wc_get_product_id_by_sku( $sku );

        if ( $product_id ) {
            try {
                $product = wc_get_product( $product_id );
                $product->set_stock_quantity( $quantity );
                $product->save();
                $logger->info( "Stock actualizado por webhook para SKU {$sku}. Nueva cantidad: {$quantity}", [ 'source' => 'bwi-webhooks' ] );
            } catch ( Exception $e ) {
                $logger->error( 'Error al actualizar stock por webhook para SKU ' . $sku . ': ' . $e->getMessage(), [ 'source' => 'bwi-webhooks' ] );
            }
        } else {
            $logger->info( "Webhook de stock recibido para SKU {$sku}, pero no se encontró el producto en WooCommerce.", [ 'source' => 'bwi-webhooks' ] );
        }
    }
}
