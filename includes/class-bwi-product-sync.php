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
        add_action( 'bwi_cron_sync_products', [ $this, 'schedule_full_sync' ] );
        add_action( 'wp_ajax_bwi_manual_sync', [ $this, 'handle_manual_sync' ] );
        
        // Hooks para las tareas en segundo plano
        add_action( 'bwi_sync_products_batch', [ $this, 'process_sync_batch' ], 10, 1 );
        add_action( 'bwi_sync_single_variant', [ $this, 'update_or_create_product_from_variant' ], 10, 2 );

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

    public function handle_manual_sync() {
        check_ajax_referer( 'bwi_manual_sync_nonce', 'security' );

        //Verificar que solo los administradores puedan ejecutar esta acción.
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( [ 'message' => 'No tienes permisos suficientes para realizar esta acción.' ], 403 );
        }

        $this->schedule_full_sync();
        wp_send_json_success( [ 'message' => 'Sincronización masiva iniciada en segundo plano. Los productos se actualizarán progresivamente.' ] );
    }

    public function schedule_full_sync() {
        $logger = wc_get_logger();
        $logger->info( 'Iniciando la programación de sincronización masiva de productos.', [ 'source' => 'bwi-sync' ] );
        
        // Iniciar con el primer lote de productos.
        as_enqueue_async_action( 'bwi_sync_products_batch', [ 'offset' => 0 ], 'bwi-sync' );
    }

    public function process_sync_batch( $offset = 0 ) {
        $limit = 50;
        $logger = wc_get_logger();
        $logger->info( "Procesando lote de productos. Offset: {$offset}", [ 'source' => 'bwi-sync' ] );
        $params = [ 'limit' => $limit, 'offset' => $offset, 'expand' => '[variants]' ];
        $response = $this->api_client->get( 'products.json', $params );
        if ( is_wp_error( $response ) || empty( $response->items ) ) {
            $logger->info( '== FIN DE SINCRONIZACIÓN MASIVA ==', [ 'source' => 'bwi-sync' ] );
            return;
        }
        foreach ( $response->items as $bsale_product ) {
            if ( ! empty( $bsale_product->variants ) ) {
                foreach ( $bsale_product->variants as $variant ) {
                    as_enqueue_async_action( 'bwi_sync_single_variant', [ 'variant_data' => $variant, 'product_data' => $bsale_product ], 'bwi-sync' );
                }
            }
        }
        as_enqueue_async_action( 'bwi_sync_products_batch', [ 'offset' => $offset + $limit ], 'bwi-sync' );
    }
    
    /**
     * Crea o actualiza un producto en WooCommerce basado en los datos de una variante de Bsale.
     *
     * @param object $variant_data Datos de la variante de Bsale.
     * @param object $product_data Datos del producto padre de Bsale.
     */
    public function update_product_from_variant( $variant_data, $product_data ) {
        $logger = wc_get_logger();
        $sku = $variant_data->code;
        $logger->info( "--- Iniciando procesamiento para SKU de Bsale: [{$sku}] ---", [ 'source' => 'bwi-sync' ] );
        if ( empty( $sku ) ) {
            $logger->warning( "PROCESO DETENIDO: Variante de Bsale sin SKU. Producto padre: " . $product_data->name, [ 'source' => 'bwi-sync' ] );
            return;
        }
        $product_id = wc_get_product_id_by_sku( $sku );
        if ( ! $product_id ) {
            $logger->info( "OMITIENDO: No se encontró producto en WooCommerce con SKU [{$sku}].", [ 'source' => 'bwi-sync' ] );
            return;
        }
        try {
            $product = wc_get_product( $product_id );
            $logger->info( "Producto encontrado en WC: '{$product->get_name()}' (ID: {$product_id}).", [ 'source' => 'bwi-sync' ] );
            $has_changes = false;
            $logger->info( "Sincronización de stock activada para SKU [{$sku}]. Obteniendo stock de Bsale...", [ 'source' => 'bwi-sync' ] );
            $stock = $this->get_stock_for_variant( $variant_data->id );
            if ( is_wp_error( $stock ) ) {
                $logger->error( "API ERROR al obtener stock para SKU [{$sku}]: " . $stock->get_error_message(), [ 'source' => 'bwi-sync' ] );
            } else {
                $current_stock = $product->get_stock_quantity();
                $logger->info( "Stock actual en WC: {$current_stock}. Stock obtenido de Bsale: {$stock}.", [ 'source' => 'bwi-sync' ] );
                if ( (int) $current_stock !== (int) $stock ) {
                    $product->set_manage_stock( true );
                    $product->set_stock_quantity( $stock );
                    $has_changes = true;
                    $logger->info( "CAMBIO DETECTADO: El stock para SKU [{$sku}] será actualizado a {$stock}.", [ 'source' => 'bwi-sync' ] );
                } else {
                    $logger->info( "No se detectaron cambios de stock para SKU [{$sku}].", [ 'source' => 'bwi-sync' ] );
                }
            }
            if ( $has_changes ) {
                $product->save();
                $logger->info( "ÉXITO: Producto SKU [{$sku}] guardado en la base de datos.", [ 'source' => 'bwi-sync' ] );
            } else {
                $logger->info( "No hubo cambios que guardar para el producto SKU [{$sku}].", [ 'source' => 'bwi-sync' ] );
            }
        } catch ( Exception $e ) {
            $logger->error( 'EXCEPCIÓN al procesar SKU ' . $sku . ': ' . $e->getMessage(), [ 'source' => 'bwi-sync' ] );
        }
    }
    /*
    public function update_or_create_product_from_variant( $variant_data, $product_data ) {
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
                $product = wc_get_product( $product_id );
                $product->set_name( $product_name );
            } else {
                $product = new WC_Product_Simple();
                $product->set_name( $product_name );
                $product->set_sku( $sku );
                $product->set_status( 'draft' );
            }

            if ( ! empty( $this->options['enable_stock_sync'] ) ) {
                $stock = $this->get_stock_for_variant( $variant_data->id );
                if ( ! is_wp_error( $stock ) ) {
                    $product->set_manage_stock( true );
                    $product->set_stock_quantity( $stock );
                }
            }
            
            $product->save();

        } catch ( Exception $e ) {
            $logger->error( 'Error al guardar producto ' . $sku . ': ' . $e->getMessage(), [ 'source' => 'bwi-sync' ] );
        }
    }*/

    /* private function update_or_create_product( $variant_data, $product_data ) {
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
    */
    /**
     * Obtiene y suma el stock de una variante desde todas las sucursales configuradas.
     *
     * @param int $variant_id El ID de la variante de Bsale.
     * @return int|WP_Error El stock total o un error.
     */
    private function get_stock_for_variant( $variant_id ) {
        $logger = wc_get_logger();
        $office_id = ! empty( $this->options['office_id_stock'] ) ? absint($this->options['office_id_stock']) : 0;
        if ( empty( $office_id ) ) {
            return new WP_Error('config_error', 'No hay una sucursal configurada.');
        }
        $params = [ 'variantid' => $variant_id, 'officeid'  => $office_id ];
        $logger->info( "Consultando stock en Bsale para variantid: [{$variant_id}] en officeid: [{$office_id}]", [ 'source' => 'bwi-sync' ] );
        $response = $this->api_client->get( 'stocks.json', $params );
        if ( is_wp_error( $response ) ) {
            return $response;
        }
        if ( ! empty( $response->items ) ) {
            $stock = (int) $response->items[0]->quantityAvailable;
            $logger->info( "Respuesta de API: Stock encontrado para la variante ID [{$variant_id}] es {$stock}.", [ 'source' => 'bwi-sync' ] );
            return $stock;
        }
        $logger->info( "Respuesta de API: No se encontró entrada de stock para la variante ID [{$variant_id}]. Asumiendo stock 0.", [ 'source' => 'bwi-sync' ] );
        return 0;
    }
    /*
    private function get_stock_for_variant( $variant_id ) {
        $logger = wc_get_logger();
        $office_id = ! empty( $this->options['office_id_stock'] ) ? absint($this->options['office_id_stock']) : 0;
        
        if ( empty( $office_id ) ) {
            $logger->warning( "No se puede obtener stock para la variante ID [{$variant_id}] porque no hay una sucursal configurada.", [ 'source' => 'bwi-sync' ] );
            return 0;
        }

        //Usamos 'variantid' en lugar de 'code'.
        $params = [
            'variantid' => $variant_id,
            'officeid'  => $office_id
        ];
        
        $logger->info( "Consultando stock en Bsale para variantid: [{$variant_id}] en officeid: [{$office_id}]", [ 'source' => 'bwi-sync' ] );
        $response = $this->api_client->get( 'stocks.json', $params );

        if ( is_wp_error( $response ) ) {
            $logger->error( "API ERROR al obtener stock para la variante ID [{$variant_id}]: " . $response->get_error_message(), [ 'source' => 'bwi-sync' ] );
            return $response;
        }

        if ( ! empty( $response->items ) ) {
            // La documentación confirma que el campo es 'quantityAvailable'.
            $stock = (int) $response->items[0]->quantityAvailable;
            $logger->info( "Respuesta de API: Stock encontrado para la variante ID [{$variant_id}] es {$stock}.", [ 'source' => 'bwi-sync' ] );
            return $stock;
        }

        $logger->info( "Respuesta de API: No se encontró una entrada de stock para la variante ID [{$variant_id}] en la sucursal [{$office_id}]. Asumiendo stock 0.", [ 'source' => 'bwi-sync' ] );
        return 0; // Si la API no devuelve items, asumimos que el stock es 0.
    }
        */


    /**
     * Obtiene el precio de una variante desde una lista de precios específica.
     */
    private function get_price_for_variant( $variant_id, $price_list_id ) {
        $endpoint = sprintf('price_lists/%d/details.json', $price_list_id);
        $params = [ 'variantid' => $variant_id ];
        
        $response = $this->api_client->get( $endpoint, $params );

        if ( ! is_wp_error( $response ) && ! empty( $response->items ) ) {
            // Asumimos que la API devuelve el precio con impuestos incluidos.
            return (float) $response->items[0]->value; 
        }

        return new WP_Error('price_not_found', 'No se encontró el precio para la variante en la lista especificada.');
    }
    /*
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
        */
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
    /*
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
    }*/
        
    public function update_stock_from_webhook( $payload ) {
        $logger = wc_get_logger();

        if ( ! isset( $payload['resource'] ) ) {
            $logger->warning( 'Payload de webhook de stock sin la clave "resource".', [ 'source' => 'bwi-webhooks' ] );
            return;
        }

        // El webhook notifica un recurso de la API. Hacemos una llamada GET para obtener los detalles completos.
        $stock_details = $this->api_client->get( $payload['resource'] );

        if ( is_wp_error( $stock_details ) || ! isset( $stock_details->variant->code ) ) {
            $logger->error( 'No se pudieron obtener los detalles del stock desde el webhook.', [ 'source' => 'bwi-webhooks', 'resource' => $payload['resource'] ] );
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

