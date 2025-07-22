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
    private static $price_list_cache = []; // Caché estática para la lista de precios
    /**
     * Constructor.
     */
    private function __construct() {
        $this->api_client = BWI_API_Client::get_instance();
        $this->options = get_option( 'bwi_options' );
        add_action( 'bwi_cron_sync_products', [ $this, 'schedule_full_sync' ] );
        add_action( 'wp_ajax_bwi_manual_sync', [ $this, 'handle_manual_sync' ] );
        add_action( 'bwi_sync_products_batch', [ $this, 'process_sync_batch' ], 10, 1 );
        add_action( 'bwi_sync_single_variant', [ $this, 'update_product_from_variant' ], 10, 2 );
        // Hook para limpiar la caché al inicio de la sincronización
        add_action( 'bwi_before_full_sync', [ $this, 'clear_price_list_cache' ] );
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
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( [ 'message' => 'No tienes permisos suficientes.' ], 403 );
        }
        $this->schedule_full_sync();
        wp_send_json_success( [ 'message' => 'Sincronización masiva iniciada en segundo plano.' ] );
    }

    public function schedule_full_sync() {
        $logger = wc_get_logger();
        $logger->info( '== INICIO DE SINCRONIZACIÓN MASIVA DE PRODUCTOS ==', [ 'source' => 'bwi-sync' ] );
        // Disparamos una acción para limpiar la caché de precios antes de empezar
        do_action( 'bwi_before_full_sync' );
        as_enqueue_async_action( 'bwi_sync_products_batch', [ 'offset' => 0 ], 'bwi-sync' );
    }

    public function clear_price_list_cache() {
        self::$price_list_cache = [];
        $logger = wc_get_logger();
        $logger->info( 'Caché de lista de precios limpiada para el nuevo ciclo de sincronización.', ['source' => 'bwi-sync'] );
    }
    /**
     * Procesa un lote de productos y encola tareas por variante (lógica restaurada y funcional).
     */
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
            // LA CORRECCIÓN CLAVE ESTÁ AQUÍ:
            // Nos aseguramos de iterar sobre el array 'items' dentro del objeto 'variants'.
            if ( ! empty( $bsale_product->variants ) && is_object( $bsale_product->variants ) && ! empty( $bsale_product->variants->items ) ) {
                foreach ( $bsale_product->variants->items as $variant ) {
                    // Convertimos los objetos a arrays ANTES de encolarlos para consistencia.
                    $variant_data_array = json_decode(json_encode($variant), true);
                    $product_data_array = json_decode(json_encode($bsale_product), true);
                    as_enqueue_async_action( 'bwi_sync_single_variant', [ 'variant_data' => $variant_data_array, 'product_data' => $product_data_array ], 'bwi-sync' );
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
     /**
     * Actualiza un producto existente en WooCommerce (lógica restaurada y mejorada).
     */
    public function update_product_from_variant( $variant_data, $product_name ) {
        $logger = wc_get_logger();
        $sku = isset($variant_data['code']) ? $variant_data['code'] : null;
        $variant_id = isset($variant_data['id']) ? $variant_data['id'] : null;
        
        $logger->info( "--- Iniciando procesamiento para SKU: [{$sku}] (VariantID: {$variant_id}) ---", [ 'source' => 'bwi-sync' ] );

        if ( empty( $sku ) ) { /* ... */ return; }
        $product_id = wc_get_product_id_by_sku( $sku );
        if ( ! $product_id ) {
            $logger->info( "OMITIENDO: No se encontró producto en WooCommerce con SKU [{$sku}].", [ 'source' => 'bwi-sync' ] );
            return;
        }

        try {
            $product = wc_get_product( $product_id );
            $has_changes = false;

            // --- SINCRONIZACIÓN DE STOCK (lógica funcional restaurada) ---
            $stock = $this->get_stock_for_variant( $variant_id );
            if ( ! is_wp_error( $stock ) ) {
                $current_stock = (int) $product->get_stock_quantity();
                if ( $current_stock !== (int) $stock ) {
                    $product->set_manage_stock( true );
                    $product->set_stock_quantity( $stock );
                    $has_changes = true;
                    $logger->info("CAMBIO DE STOCK para SKU [{$sku}]: de {$current_stock} a {$stock}", ['source' => 'bwi-sync']);
                }
            }
            
            // --- SINCRONIZACIÓN DE PRECIOS (lógica nueva y eficiente) ---
            $price_list_id = ! empty( $this->options['price_list_id'] ) ? absint($this->options['price_list_id']) : 0;
            if ( $price_list_id > 0 ) {
                $price = $this->get_price_from_cache( $variant_id, $price_list_id );
                $current_price = (float) $product->get_regular_price();
                if ( $price !== null && abs( $current_price - $price ) > 0.001 ) {
                    $product->set_regular_price( $price );
                    $has_changes = true;
                    $logger->info("CAMBIO DE PRECIO para SKU [{$sku}]: de {$current_price} a {$price}", ['source' => 'bwi-sync']);
                }
            }
            
            if ( $has_changes ) {
                $product->save();
                $logger->info("ÉXITO: Producto SKU [{$sku}] guardado.", ['source' => 'bwi-sync']);
            }

        } catch ( Exception $e ) {
            $logger->error( 'EXCEPCIÓN al procesar SKU ' . $sku . ': ' . $e->getMessage(), [ 'source' => 'bwi-sync' ] );
        }
    }

    private function get_stock_for_variant( $variant_id ) {
        $office_id = ! empty( $this->options['office_id_stock'] ) ? absint($this->options['office_id_stock']) : 0;
        if ( empty( $office_id ) ) {
            return new WP_Error('config_error', 'No hay una sucursal configurada.');
        }
        $params = [ 'variantid' => $variant_id, 'officeid'  => $office_id ];
        $response = $this->api_client->get( 'stocks.json', $params );
        if ( is_wp_error( $response ) ) return $response;
        if ( ! empty( $response->items ) ) {
            return (int) $response->items[0]->quantityAvailable;
        }
        return 0;
    }
    
    
    /**
     * Obtiene el precio de una variante desde una lista de precios específica.
     */
    private function get_price_for_variant( $variant_id, $price_list_id ) {
        $logger = wc_get_logger();
        $endpoint = sprintf('price_lists/%d/details.json', $price_list_id);
        $params = [ 'variantId' => $variant_id ];
        
        $logger->info("Consultando precio en Bsale para variantId [{$variant_id}] en priceListId [{$price_list_id}]", ['source' => 'bwi-sync']);
        $response = $this->api_client->get( $endpoint, $params );

        if ( is_wp_error( $response ) ) {
            return $response;
        }

        if ( ! empty( $response->items ) ) {
            // CORRECCIÓN CLAVE: La propiedad correcta es "variantValue".
            if ( isset( $response->items[0]->variantValue ) ) {
                $price = (float) $response->items[0]->variantValue;
                $logger->info("Respuesta de API: Precio encontrado para variantId [{$variant_id}] es {$price}.", ['source' => 'bwi-sync']);
                return $price;
            }
        }
        
        $logger->warning("Respuesta de API: No se encontró precio para la variante ID [{$variant_id}].", ['source' => 'bwi-sync']);
        return new WP_Error('price_not_found', "No se encontró el precio para la variante ID [{$variant_id}] en la lista ID [{$price_list_id}].");
    }
    
    /**
     * NUEVO: Obtiene el precio de una variante desde una caché local de la lista de precios.
     */
    private function get_price_from_cache( $variant_id, $price_list_id ) {
        $logger = wc_get_logger();
        
        if ( ! isset( self::$price_list_cache[$price_list_id] ) ) {
            $logger->info("Caché de lista de precios [{$price_list_id}] no encontrada. Obteniendo de la API...", ['source' => 'bwi-sync']);
            $endpoint = sprintf('price_lists/%d/details.json', $price_list_id);
            $response = $this->api_client->get( $endpoint, ['limit' => 500] );

            if ( is_wp_error( $response ) || empty( $response->items ) ) {
                $logger->error("No se pudo obtener la lista de precios [{$price_list_id}] de Bsale.", ['source' => 'bwi-sync']);
                self::$price_list_cache[$price_list_id] = false;
                return null;
            }

            $price_map = [];
            foreach( $response->items as $item ) {
                if (isset($item->variant->id) && isset($item->value)) {
                    $price_map[$item->variant->id] = (float) $item->value;
                }
            }
            self::$price_list_cache[$price_list_id] = $price_map;
            $logger->info("Lista de precios [{$price_list_id}] cargada y cacheada con " . count($price_map) . " precios.", ['source' => 'bwi-sync']);
        }

        if ( self::$price_list_cache[$price_list_id] === false ) return null;

        if ( isset( self::$price_list_cache[$price_list_id][$variant_id] ) ) {
            return self::$price_list_cache[$price_list_id][$variant_id];
        }

        $logger->warning("No se encontró el precio para la variante ID [{$variant_id}] en la caché de la lista de precios [{$price_list_id}].", ['source' => 'bwi-sync']);
        return null;
    }


        
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

