<?php
/**
 * Clase para manejar la sincronización de productos desde Bsale a WooCommerce.
 *
 * @package Bsale_WooCommerce_Integration
 */

// Evitar el acceso directo al archivo.
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * BWI_Product_Sync Class
 */
final class BWI_Product_Sync {

    /**
     * Instancia única de la clase.
     * @var BWI_Product_Sync
     */
    private static $instance;

    /**
     * Cliente de la API de Bsale.
     * @var BWI_API_Client
     */
    private $api_client;

    /**
     * Opciones del plugin.
     * @var array
     */
    private $options;

    /**
     * Constructor.
     */
    private function __construct() {
        $this->api_client = BWI_API_Client::get_instance();
        $this->options = get_option( 'bwi_options' );

        // Hooks para la sincronización
        add_action( 'bwi_cron_sync_products', [ $this, 'sync_all_products' ] );
        add_action( 'wp_ajax_bwi_manual_sync', [ $this, 'handle_manual_sync' ] );
        // Hooks para las tareas en segundo plano
        add_action( 'bwi_sync_products_batch', [ $this, 'process_sync_batch' ], 10, 1 );
        add_action( 'bwi_sync_single_variant', [ $this, 'update_or_create_product_from_variant' ], 10, 2 );

        // Registrar el cron si no existe
        if ( ! wp_next_scheduled( 'bwi_cron_sync_products' ) ) {
            wp_schedule_event( time(), 'hourly', 'bwi_cron_sync_products' );
        }
    }

    /**
     * Obtener la instancia única de la clase.
     * @return BWI_Product_Sync
     */
    public static function get_instance() {
        if ( ! isset( self::$instance ) ) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    /**
     * Maneja la solicitud AJAX para la sincronización manual.
     */
     /* public function handle_manual_sync() {
        // Verificar nonce de seguridad
        check_ajax_referer( 'bwi_manual_sync_nonce', 'security' );

        $result = $this->sync_all_products();

        if ( is_wp_error( $result ) ) {
            wp_send_json_error( [ 'message' => $result->get_error_message() ] );
        } else {
            wp_send_json_success( [ 'message' => 'Sincronización completada exitosamente.' ] );
        }
    } */

    public function handle_manual_sync() {
        check_ajax_referer( 'bwi_manual_sync_nonce', 'security' );
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( [ 'message' => 'No tienes permisos suficientes.' ], 403 );
        }
        $this->schedule_full_sync();
        wp_send_json_success( [ 'message' => 'Sincronización masiva iniciada en segundo plano.' ] );
    }

    public function schedule_full_sync() {
        $logger = wc_get_logger();
        $logger->info( 'Iniciando la programación de sincronización masiva de productos.', [ 'source' => 'bwi-sync' ] );
        as_enqueue_async_action( 'bwi_sync_products_batch', [ 'offset' => 0 ], 'bwi-sync' );
    }

    public function process_sync_batch( $offset = 0 ) {
        $limit = 50;
        $logger = wc_get_logger();
        $logger->info( "Procesando lote de productos. Offset: {$offset}", [ 'source' => 'bwi-sync' ] );

        $params = [ 'limit' => $limit, 'offset' => $offset, 'expand' => 'variants' ];
        $response = $this->api_client->get( 'products.json', $params );

        if ( is_wp_error( $response ) || empty( $response->items ) ) {
            $logger->info( 'Fin de la sincronización masiva o error en la API.', [ 'source' => 'bwi-sync' ] );
            return;
        }

        foreach ( $response->items as $bsale_product ) {
            if ( ! empty( $bsale_product->variants->items ) ) {
                foreach ( $bsale_product->variants->items as $variant ) {
                    as_enqueue_async_action( 'bwi_sync_single_variant', [ 'variant_data' => $variant, 'product_data' => $bsale_product ], 'bwi-sync' );
                }
            }
        }

        as_enqueue_async_action( 'bwi_sync_products_batch', [ 'offset' => $offset + $limit ], 'bwi-sync' );
    }
    /**
     * Orquesta la sincronización completa de productos, manejando la paginación.
     *
     * @return bool|WP_Error True en éxito, WP_Error en fallo.
     */
    /*public function sync_all_products() {
        $logger = wc_get_logger();
        $logger->info( 'Inicio de la sincronización de productos Bsale -> WooCommerce.', [ 'source' => 'bwi-sync' ] );

        $offset = 0;
        $limit = 50; // Máximo permitido por la API de Bsale.
        $keep_syncing = true;

        while ( $keep_syncing ) {
            $params = [
                'limit'  => $limit,
                'offset' => $offset,
                'expand' => 'variants' // Expandir variantes para obtener SKU y precios.
            ];

            $response = $this->api_client->get_products( $params );

            if ( is_wp_error( $response ) ) {
                $logger->error( 'Error al obtener productos de Bsale: ' . $response->get_error_message(), [ 'source' => 'bwi-sync' ] );
                return $response;
            }

            if ( empty( $response->items ) ) {
                $keep_syncing = false;
                continue;
            }

            foreach ( $response->items as $bsale_product ) {
                // Sincronizar cada variante como un producto individual en WooCommerce.
                if ( ! empty( $bsale_product->variants->items ) ) {
                    foreach ( $bsale_product->variants->items as $variant ) {
                        $this->update_or_create_product( $variant, $bsale_product );
                    }
                }
            }

            $offset += $limit;
        }

        $logger->info( 'Fin de la sincronización de productos.', [ 'source' => 'bwi-sync' ] );
        return true;
    }
    */

    /**
     * Crea o actualiza un producto en WooCommerce basado en los datos de una variante de Bsale.
     *
     * @param object $variant_data Datos de la variante de Bsale.
     * @param object $product_data Datos del producto padre de Bsale.
     */
    private function update_or_create_product( $variant_data, $product_data ) {
        $logger = wc_get_logger();
        $sku = $variant_data->code;

        if ( empty( $sku ) ) {
            $logger->warning( 'Variante de Bsale sin SKU. Producto: ' . $product_data->name, [ 'source' => 'bwi-sync' ] );
            return;
        }

        $product_id = wc_get_product_id_by_sku( $sku );
        $product_name = $product_data->name . ( count( (array) $product_data->variants->items ) > 1 ? ' - ' . $variant_data->description : '' );

        try {
            if ( $product_id ) {
                // --- Actualizar Producto Existente ---
                $product = wc_get_product( $product_id );
                $product->set_name( $product_name );
                // Aquí se podría añadir lógica para sincronizar precios desde listas de precios.
                // $product->set_regular_price( $variant_data->price );

                $logger->info( "Actualizando producto: {$product_name} (SKU: {$sku})", [ 'source' => 'bwi-sync' ] );
            } else {
                // --- Crear Nuevo Producto ---
                $product = new WC_Product_Simple();
                $product->set_name( $product_name );
                $product->set_sku( $sku );
                $product->set_status( 'draft' ); // Crear como borrador para revisión.
                
                $logger->info( "Creando nuevo producto: {$product_name} (SKU: {$sku})", [ 'source' => 'bwi-sync' ] );
            }

            // --- Sincronizar Stock (si está activado) ---
            if ( ! empty( $this->options['enable_stock_sync'] ) ) {
                $stock = $this->get_total_stock_for_variant( $variant_data->id );
                if ( ! is_wp_error( $stock ) ) {
                    $product->set_manage_stock( true );
                    $product->set_stock_quantity( $stock );
                }
            }
            
            $product->save();

        } catch ( Exception $e ) {
            $logger->error( 'Error al guardar producto ' . $sku . ': ' . $e->getMessage(), [ 'source' => 'bwi-sync' ] );
        }
    }

    /**
     * Obtiene y suma el stock de una variante desde todas las sucursales configuradas.
     *
     * @param int $variant_id El ID de la variante de Bsale.
     * @return int|WP_Error El stock total o un error.
     */
    private function get_total_stock_for_variant( $variant_id ) {
        $office_ids_str = ! empty( $this->options['office_id_stock'] ) ? $this->options['office_id_stock'] : '';
        if ( empty( $office_ids_str ) ) {
            return 0; // Si no hay sucursales configuradas, el stock es 0.
        }

        $office_ids = array_map( 'trim', explode( ',', $office_ids_str ) );
        $total_stock = 0;

        foreach ( $office_ids as $office_id ) {
            $params = [
                'variantid' => $variant_id,
                'officeid'  => (int) $office_id
            ];
            $response = $this->api_client->get_stock( $params );

            if ( ! is_wp_error( $response ) && ! empty( $response->items ) ) {
                // Sumamos el stock disponible de la primera (y única) respuesta.
                $total_stock += $response->items[0]->quantityAvailable;
            }
        }

        return $total_stock;
    }
    /**
     * Actualiza el stock de un único producto, típicamente gatillado por un webhook.
     *
     * @param array $payload Los datos recibidos del webhook de Bsale.
     */
    /*
    public function update_stock_from_webhook( $payload ) {
        $logger = wc_get_logger();

        // La documentación de Bsale indica que el webhook envía un 'resource' URL.
        // Ejemplo: /v2/stocks/12345.json
        // Necesitamos obtener los datos completos desde esa URL.
        if ( ! isset( $payload['resource'] ) ) {
            $logger->warning( 'Payload de webhook de stock sin la clave "resource".', [ 'source' => 'bwi-webhooks' ] );
            return;
        }

        // El endpoint de stock es v2, por lo que necesitamos ajustar la URL base temporalmente.
        $api_client = BWI_API_Client::get_instance();
        // Hacemos una llamada GET al recurso que nos notificó el webhook.
        $stock_details = $api_client->get( str_replace('/v1/', '/v2/', $payload['resource']) );

        if ( is_wp_error( $stock_details ) || ! isset( $stock_details->variant->code ) ) {
            $logger->error( 'No se pudieron obtener los detalles del stock desde el webhook.', [ 'source' => 'bwi-webhooks', 'payload' => $payload ] );
            return;
        }

        $sku = sanitize_text_field( $stock_details->variant->code );
        $quantity = intval( $stock_details->quantityAvailable );

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
    */
    public function update_stock_from_webhook( $payload ) {
        $logger = wc_get_logger();

        if ( ! isset( $payload['resource'] ) ) {
            $logger->warning( 'Payload de webhook de stock sin la clave "resource".', [ 'source' => 'bwi-webhooks' ] );
            return;
        }

        // El webhook notifica un recurso de la v2.
        $resource_path = $payload['resource']; // ej. /v2/stocks/1234.json
        
        $stock_details = $this->api_client->get( $resource_path );

        if ( is_wp_error( $stock_details ) || ! isset( $stock_details->variant->code ) ) {
            $logger->error( 'No se pudieron obtener los detalles del stock desde el webhook.', [ 'source' => 'bwi-webhooks', 'payload' => $payload ] );
            return;
        }

        $sku = sanitize_text_field( $stock_details->variant->code );
        $quantity = intval( $stock_details->quantityAvailable );

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

