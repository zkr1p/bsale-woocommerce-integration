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
    $limit = 50; // Máximo permitido por la API
    $logger = wc_get_logger();
    $logger->info( "Procesando lote de productos. Offset: {$offset}", [ 'source' => 'bwi-sync' ] );

    $params = [ 'limit' => $limit, 'offset' => $offset, 'expand' => '[variants]' ];
    $response = $this->api_client->get( 'products.json', $params );

    if ( is_wp_error( $response ) ) {
        $logger->error( 'Error de API al obtener lote de productos: ' . $response->get_error_message(), [ 'source' => 'bwi-sync' ] );
        return; // Detener si hay un error
    }
    
    // Si no hay más items, la sincronización ha terminado.
    if ( empty( $response->items ) ) {
        $logger->info( '== FIN DE SINCRONIZACIÓN MASIVA: No hay más productos que procesar. ==', [ 'source' => 'bwi-sync' ] );
        return;
    }

    foreach ( $response->items as $bsale_product ) {
        if ( ! empty( $bsale_product->variants ) && is_object( $bsale_product->variants ) && ! empty( $bsale_product->variants->items ) ) {
            foreach ( $bsale_product->variants->items as $variant ) {
                $variant_data_array = json_decode(json_encode($variant), true);
                // Pasamos el nombre del producto padre para un mejor logging.
                $product_name = isset($bsale_product->name) ? $bsale_product->name : 'N/A';
                as_enqueue_async_action( 'bwi_sync_single_variant', [ 'variant_data' => $variant_data_array, 'product_name' => $product_name ], 'bwi-sync' );
            }
        }
    }

    // Si la respuesta indica que hay una página siguiente, encolamos el próximo lote.
    if ( isset($response->next) && !empty($response->next) ) {
        as_enqueue_async_action( 'bwi_sync_products_batch', [ 'offset' => $offset + $limit ], 'bwi-sync' );
    } else {
        $logger->info( '== FIN DE SINCRONIZACIÓN MASIVA: Se procesó la última página de productos. ==', [ 'source' => 'bwi-sync' ] );
    }
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
    
    $logger->info( "--- Iniciando procesamiento para SKU: [{$sku}] (Producto: {$product_name}, VariantID: {$variant_id}) ---", [ 'source' => 'bwi-sync' ] );

    if ( empty( $sku ) ) {
        $logger->warning( "OMITIENDO: La variante con ID [{$variant_id}] no tiene un SKU (code) en Bsale.", [ 'source' => 'bwi-sync' ] );
        return;
    }

    $product_id = wc_get_product_id_by_sku( $sku );
    if ( ! $product_id ) {
        $logger->info( "OMITIENDO: No se encontró producto en WooCommerce con SKU [{$sku}].", [ 'source' => 'bwi-sync' ] );
        return;
    }

    try {
        $product = wc_get_product( $product_id );
        if ( ! $product ) {
            $logger->error( "ERROR: Se encontró un ID de producto para el SKU [{$sku}], pero no se pudo cargar el objeto del producto.", [ 'source' => 'bwi-sync' ] );
            return;
        }

        $has_changes = false;
        $log_details = [];

        // --- SINCRONIZACIÓN DE STOCK ---
        $stock_bsale = $this->get_stock_for_variant( $variant_id );
        if ( is_wp_error( $stock_bsale ) ) {
            $logger->error("Error al obtener stock para SKU [{$sku}]: " . $stock_bsale->get_error_message(), ['source' => 'bwi-sync']);
        } else {
            $stock_wc = (int) $product->get_stock_quantity();
            $log_details[] = "Stock Bsale: {$stock_bsale} / Stock WooCommerce: {$stock_wc}";
            if ( $stock_wc !== (int) $stock_bsale ) {
                $product->set_manage_stock( true );
                $product->set_stock_quantity( $stock_bsale );
                $has_changes = true;
                $log_details[] = "-> ¡Cambio de stock detectado!";
            }
        }
        
        // --- SINCRONIZACIÓN DE PRECIOS ---
        $price_list_id = ! empty( $this->options['price_list_id'] ) ? absint($this->options['price_list_id']) : 0;
        if ( $price_list_id > 0 ) {
            $price_bsale = $this->get_price_from_cache( $variant_id, $price_list_id );
            $price_wc = (float) $product->get_regular_price();
            
            if ( $price_bsale === null ) {
                $log_details[] = "Precio Bsale: No encontrado en la lista / Precio WooCommerce: {$price_wc}";
            } else {
                $log_details[] = "Precio Bsale: {$price_bsale} / Precio WooCommerce: {$price_wc}";
                // Comparamos precios con un margen pequeño para evitar problemas con decimales.
                if ( abs( $price_wc - $price_bsale ) > 0.001 ) {
                    $product->set_regular_price( $price_bsale );
                    $has_changes = true;
                    $log_details[] = "-> ¡Cambio de precio detectado!";
                }
            }
        } else {
            $log_details[] = "Sincronización de precios no activada.";
        }
        
        // --- GUARDAR Y CONCLUIR ---
        if ( $has_changes ) {
            $product->save();
            $logger->info("ÉXITO: Producto SKU [{$sku}] actualizado. Detalles: " . implode(' | ', $log_details), ['source' => 'bwi-sync']);
        } else {
            $logger->info("SIN CAMBIOS: No se requirió actualización para SKU [{$sku}]. Detalles: " . implode(' | ', $log_details), ['source' => 'bwi-sync']);
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
    $transient_key = 'bwi_price_list_cache_' . $price_list_id;

    // Intentar obtener el mapa de precios desde la caché de WordPress (transient)
    $price_map = get_transient( $transient_key );

    if ( false === $price_map ) {
        $logger->info("Caché de lista de precios [{$price_list_id}] no encontrada. Obteniendo de la API...", ['source' => 'bwi-sync']);
        $price_map = [];
        $offset = 0;
        $limit = 50; // Máximo permitido por la API

        do {
            $endpoint = sprintf('price_lists/%d/details.json', $price_list_id);
            $response = $this->api_client->get( $endpoint, ['limit' => $limit, 'offset' => $offset] );

            if ( is_wp_error( $response ) || empty( $response->items ) ) {
                $logger->error("No se pudo obtener la lista de precios [{$price_list_id}] desde Bsale. Offset: {$offset}", ['source' => 'bwi-sync']);
                // Si falla, guardamos un valor para no reintentar por un tiempo y salimos del bucle.
                set_transient( $transient_key, [], 5 * MINUTE_IN_SECONDS );
                break;
            }

            foreach( $response->items as $item ) {
                if (isset($item->variant->id) && isset($item->variantValueWithTaxes)) {
                    // Usamos variantValueWithTaxes para obtener el precio final con impuestos.
                    // Si necesitas el precio neto, usa: $item->variantValue
                    $price_map[$item->variant->id] = (float) $item->variantValueWithTaxes;
                }
            }
            
            $offset += $limit;

        } while ( isset($response->next) && !empty($response->next) );
        
        // Guardar el mapa de precios completo en la caché de WordPress por 1 hora.
        set_transient( $transient_key, $price_map, HOUR_IN_SECONDS );
        $logger->info("Lista de precios [{$price_list_id}] cargada y cacheada con " . count($price_map) . " precios.", ['source' => 'bwi-sync']);
    }

    if ( isset( $price_map[$variant_id] ) ) {
        return $price_map[$variant_id];
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

