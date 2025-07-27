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
        // Las opciones se cargan bajo demanda para asegurar que siempre estén actualizadas.
        add_action( 'bwi_cron_sync_products', [ $this, 'schedule_full_sync' ] );
        add_action( 'wp_ajax_bwi_manual_sync', [ $this, 'handle_manual_sync' ] );
        add_action( 'bwi_sync_products_batch', [ $this, 'process_sync_batch' ], 10, 1 );
        add_action( 'bwi_sync_single_variant', [ $this, 'update_product_from_variant' ], 10, 2 );
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

    /**
     * Función auxiliar para verificar si el logging está activado.
     * Carga las opciones solo cuando es necesario.
     * @return bool
     */
    private function is_logging_enabled() {
        if ( ! isset( $this->options ) ) {
            $this->options = get_option( 'bwi_options' );
        }
        return ! empty( $this->options['enable_logging'] );
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
        if ( $this->is_logging_enabled() ) {
            wc_get_logger()->info( '== INICIO DE SINCRONIZACIÓN MASIVA DE PRODUCTOS ==', [ 'source' => 'bwi-sync' ] );
        }
        do_action( 'bwi_before_full_sync' );
        as_enqueue_async_action( 'bwi_sync_products_batch', [ 'offset' => 0 ], 'bwi-sync' );
    }

    public function clear_price_list_cache() {
        self::$price_list_cache = [];
        if ( $this->is_logging_enabled() ) {
            wc_get_logger()->info( 'Caché de lista de precios limpiada para el nuevo ciclo de sincronización.', ['source' => 'bwi-sync'] );
        }
    }
    
    public function process_sync_batch( $offset = 0 ) {
        $limit = 50;
        
        $log_message = "Procesando lote de productos. Offset: {$offset}.";
        $this->options = get_option( 'bwi_options' );
        $product_type_id_sync = ! empty( $this->options['product_type_id_sync'] ) ? absint($this->options['product_type_id_sync']) : 0;
        
        $params = [ 'limit' => $limit, 'offset' => $offset, 'expand' => '[variants]' ];
        
        if ( $product_type_id_sync > 0 ) {
            $params['producttypeid'] = $product_type_id_sync;
            $log_message .= " Filtrando por Tipo de Producto ID: {$product_type_id_sync}.";
        }

        if ( $this->is_logging_enabled() ) {
            wc_get_logger()->info( $log_message, [ 'source' => 'bwi-sync' ] );
        }
        
        $response = $this->api_client->get( 'products.json', $params );

        if ( is_wp_error( $response ) ) {
            if ( $this->is_logging_enabled() ) {
                wc_get_logger()->error( 'Error de API al obtener lote de productos: ' . $response->get_error_message(), [ 'source' => 'bwi-sync' ] );
            }
            return;
        }
        
        if ( empty( $response->items ) ) {
            if ( $this->is_logging_enabled() ) {
                wc_get_logger()->info( '== FIN DE SINCRONIZACIÓN MASIVA: No hay más productos que procesar. ==', [ 'source' => 'bwi-sync' ] );
            }
            return;
        }

        foreach ( $response->items as $bsale_product ) {
            if ( ! empty( $bsale_product->variants ) && is_object( $bsale_product->variants ) && ! empty( $bsale_product->variants->items ) ) {
                foreach ( $bsale_product->variants->items as $variant ) {
                    $variant_data_array = json_decode(json_encode($variant), true);
                    $product_name = isset($bsale_product->name) ? $bsale_product->name : 'N/A';
                    as_enqueue_async_action( 'bwi_sync_single_variant', [ 'variant_data' => $variant_data_array, 'product_name' => $product_name ], 'bwi-sync' );
                }
            }
        }

        if ( isset($response->next) && !empty($response->next) ) {
            as_enqueue_async_action( 'bwi_sync_products_batch', [ 'offset' => $offset + $limit ], 'bwi-sync' );
        } else {
            if ( $this->is_logging_enabled() ) {
                wc_get_logger()->info( '== FIN DE SINCRONIZACIÓN MASIVA: Se procesó la última página de productos. ==', [ 'source' => 'bwi-sync' ] );
            }
        }
    }

    public function update_product_from_variant( $variant_data, $product_name ) {
        $sku = isset($variant_data['code']) ? $variant_data['code'] : null;
        $variant_id = isset($variant_data['id']) ? $variant_data['id'] : null;
        
        if ( $this->is_logging_enabled() ) {
            wc_get_logger()->info( "--- Iniciando procesamiento para SKU: [{$sku}] (Producto: {$product_name}, VariantID: {$variant_id}) ---", [ 'source' => 'bwi-sync' ] );
        }

        if ( empty( $sku ) ) {
            if ( $this->is_logging_enabled() ) {
                wc_get_logger()->warning( "OMITIENDO: La variante con ID [{$variant_id}] no tiene un SKU (code) en Bsale.", [ 'source' => 'bwi-sync' ] );
            }
            return;
        }

        $product_id = wc_get_product_id_by_sku( $sku );
        if ( ! $product_id ) {
            if ( $this->is_logging_enabled() ) {
                wc_get_logger()->info( "OMITIENDO: No se encontró producto en WooCommerce con SKU [{$sku}].", [ 'source' => 'bwi-sync' ] );
            }
            return;
        }

        try {
            $product = wc_get_product( $product_id );
            if ( ! $product ) {
                if ( $this->is_logging_enabled() ) {
                    wc_get_logger()->error( "ERROR: Se encontró un ID de producto para el SKU [{$sku}], pero no se pudo cargar el objeto del producto.", [ 'source' => 'bwi-sync' ] );
                }
                return;
            }

            $has_changes = false;
            $log_details = [];

            $bsale_state = isset($variant_data['state']) ? (int) $variant_data['state'] : 0;
            $wc_status = $product->get_status();

            if ( $bsale_state === 1 && $wc_status === 'publish' ) {
                $product->set_status('draft');
                $has_changes = true;
                $log_details[] = "CAMBIO DE ESTADO: Producto desactivado (pasado a borrador).";
            } elseif ( $bsale_state === 0 && $wc_status !== 'publish' ) {
                $product->set_status('publish');
                $has_changes = true;
                $log_details[] = "CAMBIO DE ESTADO: Producto activado (publicado).";
            }

            $stock_bsale = $this->get_stock_for_variant( $variant_id );
            if ( is_wp_error( $stock_bsale ) ) {
                if ( $this->is_logging_enabled() ) {
                    wc_get_logger()->error("Error al obtener stock para SKU [{$sku}]: " . $stock_bsale->get_error_message(), ['source' => 'bwi-sync']);
                }
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
            
            $this->options = get_option( 'bwi_options' );
            $price_list_id = ! empty( $this->options['price_list_id'] ) ? absint($this->options['price_list_id']) : 0;
            if ( $price_list_id > 0 ) {
                $price_bsale = $this->get_price_from_cache( $variant_id, $price_list_id );
                $price_wc = (float) $product->get_regular_price();
                
                if ( $price_bsale === null ) {
                    $log_details[] = "Precio Bsale: No encontrado en la lista / Precio WooCommerce: {$price_wc}";
                } else {
                    $log_details[] = "Precio Bsale: {$price_bsale} / Precio WooCommerce: {$price_wc}";
                    if ( abs( $price_wc - $price_bsale ) > 0.001 ) {
                        $product->set_regular_price( $price_bsale );
                        $has_changes = true;
                        $log_details[] = "-> ¡Cambio de precio detectado!";
                    }
                }
            } else {
                $log_details[] = "Sincronización de precios no activada.";
            }
            
            if ( $has_changes ) {
                $product->save();
                if ( $this->is_logging_enabled() ) {
                    wc_get_logger()->info("ÉXITO: Producto SKU [{$sku}] actualizado. Detalles: " . implode(' | ', $log_details), ['source' => 'bwi-sync']);
                }
            } else {
                if ( $this->is_logging_enabled() ) {
                    wc_get_logger()->info("SIN CAMBIOS: No se requirió actualización para SKU [{$sku}]. Detalles: " . implode(' | ', $log_details), ['source' => 'bwi-sync']);
                }
            }
        } catch ( Exception $e ) {
            if ( $this->is_logging_enabled() ) {
                wc_get_logger()->error( 'EXCEPCIÓN al procesar SKU ' . $sku . ': ' . $e->getMessage(), [ 'source' => 'bwi-sync' ] );
            }
        }
    }

    private function get_stock_for_variant( $variant_id ) {
        $this->options = get_option( 'bwi_options' );
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
    
    private function get_price_from_cache( $variant_id, $price_list_id ) {
        $transient_key = 'bwi_price_list_cache_' . $price_list_id;
        $price_map = get_transient( $transient_key );

        if ( false === $price_map ) {
            if ( $this->is_logging_enabled() ) {
                wc_get_logger()->info("Caché de lista de precios [{$price_list_id}] no encontrada. Obteniendo de la API...", ['source' => 'bwi-sync']);
            }
            $price_map = [];
            $offset = 0;
            $limit = 50;
            do {
                $endpoint = sprintf('price_lists/%d/details.json', $price_list_id);
                $response = $this->api_client->get( $endpoint, ['limit' => $limit, 'offset' => $offset] );
                if ( is_wp_error( $response ) || empty( $response->items ) ) {
                    if ( $this->is_logging_enabled() ) {
                        wc_get_logger()->error("No se pudo obtener la lista de precios [{$price_list_id}] desde Bsale. Offset: {$offset}", ['source' => 'bwi-sync']);
                    }
                    set_transient( $transient_key, [], 5 * MINUTE_IN_SECONDS );
                    break;
                }
                foreach( $response->items as $item ) {
                    if (isset($item->variant->id) && isset($item->variantValueWithTaxes)) {
                        $price_map[$item->variant->id] = (float) $item->variantValueWithTaxes;
                    }
                }
                $offset += $limit;
            } while ( isset($response->next) && !empty($response->next) );
            
            set_transient( $transient_key, $price_map, HOUR_IN_SECONDS );
            if ( $this->is_logging_enabled() ) {
                wc_get_logger()->info("Lista de precios [{$price_list_id}] cargada y cacheada con " . count($price_map) . " precios.", ['source' => 'bwi-sync']);
            }
        }

        if ( isset( $price_map[$variant_id] ) ) {
            return $price_map[$variant_id];
        }
        if ( $this->is_logging_enabled() ) {
            wc_get_logger()->warning("No se encontró el precio para la variante ID [{$variant_id}] en la caché de la lista de precios [{$price_list_id}].", ['source' => 'bwi-sync']);
        }
        return null;
    }

    public function update_stock_from_webhook( $payload ) {
        if ( ! isset( $payload['resourceId'], $payload['officeId'] ) ) {
            if ( $this->is_logging_enabled() ) {
                wc_get_logger()->warning( 'Webhook de stock inválido: no contiene resourceId (variantId) u officeId.', [ 'source' => 'bwi-webhooks' ] );
            }
            return;
        }

        $variant_id_webhook = absint($payload['resourceId']);
        $office_id_webhook = absint($payload['officeId']);
        
        $this->options = get_option( 'bwi_options' );
        $office_id_settings = ! empty( $this->options['office_id_stock'] ) ? absint( $this->options['office_id_stock'] ) : 0;

        if ( $office_id_webhook !== $office_id_settings ) {
            if ( $this->is_logging_enabled() ) {
                wc_get_logger()->info( "Webhook de Stock ignorado: El cambio ocurrió en la sucursal [{$office_id_webhook}], pero la tienda está configurada para sincronizar con la sucursal [{$office_id_settings}].", [ 'source' => 'bwi-webhooks' ] );
            }
            return;
        }
        
        $product_type_id_sync = ! empty( $this->options['product_type_id_sync'] ) ? absint($this->options['product_type_id_sync']) : 0;
        $variant_details = $this->api_client->get("variants/{$variant_id_webhook}.json", ['expand' => '[product]']);

        if ( is_wp_error( $variant_details ) || empty( $variant_details->code ) ) {
            if ( $this->is_logging_enabled() ) {
                wc_get_logger()->error( "Error de Webhook de Stock: No se pudo obtener detalles para la variante Bsale ID [{$variant_id_webhook}].", [ 'source' => 'bwi-webhooks' ] );
            }
            return;
        }

        if ( $product_type_id_sync > 0 && isset($variant_details->product->product_type->id) ) {
            $variant_product_type_id = absint($variant_details->product->product_type->id);
            if ($variant_product_type_id !== $product_type_id_sync) {
                if ( $this->is_logging_enabled() ) {
                    wc_get_logger()->info( "Webhook de Stock ignorado: La variante [{$variant_details->code}] pertenece al tipo de producto [{$variant_product_type_id}], que no es el tipo [{$product_type_id_sync}] configurado para sincronizar.", [ 'source' => 'bwi-webhooks' ] );
                }
                return;
            }
        }
        
        $sku = sanitize_text_field( $variant_details->code );
        $product_id = wc_get_product_id_by_sku( $sku );

        if ( $product_id ) {
            $params = [ 'variantid' => $variant_id_webhook, 'officeid'  => $office_id_webhook ];
            $stock_details = $this->api_client->get( 'stocks.json', $params );
            
            $quantity = null;
            if ( !is_wp_error($stock_details) && !empty($stock_details->items) && isset($stock_details->items[0]->quantityAvailable) ) {
                $quantity = intval($stock_details->items[0]->quantityAvailable);
            }

            if ( $quantity !== null ) {
                try {
                    $product = wc_get_product( $product_id );
                    $stock_before = $product->get_stock_quantity();

                    if ( ! $product->get_manage_stock() ) {
                        $product->set_manage_stock( true );
                        if ( $this->is_logging_enabled() ) {
                            wc_get_logger()->info( "Se activó la gestión de inventario para el SKU {$sku}.", [ 'source' => 'bwi-webhooks' ] );
                        }
                    }
                    
                    wc_update_product_stock( $product, $quantity, 'set' );
                    clean_post_cache( $product_id );
                    wc_delete_product_transients( $product_id );
                    
                    $product_after = wc_get_product( $product_id );
                    $stock_after = $product_after->get_stock_quantity();

                    if ( $stock_after == $quantity ) {
                        if ( $this->is_logging_enabled() ) {
                            wc_get_logger()->info( "ÉXITO Webhook: Stock actualizado para SKU {$sku}. Antes: {$stock_before}, Ahora: {$stock_after}. Caché limpiada.", [ 'source' => 'bwi-webhooks' ] );
                        }
                    } else {
                        if ( $this->is_logging_enabled() ) {
                            wc_get_logger()->error( "FALLO Webhook: Se intentó actualizar el stock para SKU {$sku} de {$stock_before} a {$quantity}, pero el valor final es {$stock_after}. Posible conflicto de plugin o caché.", [ 'source' => 'bwi-webhooks' ] );
                        }
                    }
                } catch ( Exception $e ) {
                    if ( $this->is_logging_enabled() ) {
                        wc_get_logger()->error( 'EXCEPCIÓN en Webhook de Stock para SKU ' . $sku . ': ' . $e->getMessage(), [ 'source' => 'bwi-webhooks' ] );
                    }
                }
            } else {
                if ( $this->is_logging_enabled() ) {
                    $error_message = is_wp_error($stock_details) ? $stock_details->get_error_message() : 'La respuesta de la API v1 no contenía un formato de stock válido.';
                    wc_get_logger()->error( "Error de Webhook de Stock: No se pudo obtener el nuevo stock para SKU [{$sku}]. Razón: " . $error_message, [ 'source' => 'bwi-webhooks' ] );
                }
            }
        } else {
            if ( $this->is_logging_enabled() ) {
                wc_get_logger()->info( "Webhook de Stock recibido para SKU {$sku}, pero no se encontró el producto en WooCommerce.", [ 'source' => 'bwi-webhooks' ] );
            }
        }
    }

    public function update_price_from_webhook( $payload ) {
        $price_list_id_webhook = isset( $payload['priceListId'] ) ? absint( $payload['priceListId'] ) : 0;
        $variant_id_webhook = isset( $payload['resourceId'] ) ? absint( $payload['resourceId'] ) : 0;
        
        $this->options = get_option( 'bwi_options' );
        $price_list_id_settings = ! empty( $this->options['price_list_id'] ) ? absint( $this->options['price_list_id'] ) : 0;
        $product_type_id_sync = ! empty( $this->options['product_type_id_sync'] ) ? absint($this->options['product_type_id_sync']) : 0;

        if ( ! $price_list_id_webhook || $price_list_id_webhook !== $price_list_id_settings ) {
            if ( $this->is_logging_enabled() ) {
                wc_get_logger()->info( "Webhook de precio ignorado: La lista [{$price_list_id_webhook}] no es la configurada [{$price_list_id_settings}].", [ 'source' => 'bwi-webhooks' ] );
            }
            return;
        }

        $variant_details = $this->api_client->get("variants/{$variant_id_webhook}.json", ['expand' => '[product]']);
        if ( is_wp_error( $variant_details ) || empty( $variant_details->code ) ) {
            if ( $this->is_logging_enabled() ) {
                wc_get_logger()->error( "Error de Webhook de Precio: No se pudo obtener el SKU para la variante Bsale ID [{$variant_id_webhook}].", [ 'source' => 'bwi-webhooks' ] );
            }
            return;
        }
        
        if ( $product_type_id_sync > 0 && isset($variant_details->product->product_type->id) ) {
            $variant_product_type_id = absint($variant_details->product->product_type->id);
            if ($variant_product_type_id !== $product_type_id_sync) {
                if ( $this->is_logging_enabled() ) {
                    wc_get_logger()->info( "Webhook de Precio ignorado: La variante [{$variant_details->code}] pertenece al tipo de producto [{$variant_product_type_id}], que no es el tipo [{$product_type_id_sync}] configurado para sincronizar.", [ 'source' => 'bwi-webhooks' ] );
                }
                return;
            }
        }

        $sku = $variant_details->code;
        $product_id = wc_get_product_id_by_sku( $sku );
        if ( ! $product_id ) {
            if ( $this->is_logging_enabled() ) {
                wc_get_logger()->info( "Webhook de Precio ignorado: No se encontró producto en WooCommerce con SKU [{$sku}].", [ 'source' => 'bwi-webhooks' ] );
            }
            return;
        }

        $endpoint = sprintf('price_lists/%d/details.json', $price_list_id_webhook);
        $params = [ 'variantid' => $variant_id_webhook ];
        $price_details = $this->api_client->get( $endpoint, $params );
        
        if ( $this->is_logging_enabled() ) {
            wc_get_logger()->info( 'Respuesta de la API de Precios (Webhook): ' . wp_json_encode($price_details), [ 'source' => 'bwi-webhooks' ] );
        }

        $new_price = null;
        if ( !is_wp_error($price_details) && !empty($price_details->items) && isset($price_details->items[0]->variantValueWithTaxes) ) {
            if ( is_numeric($price_details->items[0]->variantValueWithTaxes) ) {
                $new_price = (float) $price_details->items[0]->variantValueWithTaxes;
            }
        }

        if ( $new_price !== null ) {
            try {
                $product = wc_get_product( $product_id );
                $product->set_regular_price( $new_price );
                $product->save();
                if ( $this->is_logging_enabled() ) {
                    wc_get_logger()->info( "ÉXITO Webhook: Precio actualizado para SKU [{$sku}]. Nuevo precio: {$new_price}", [ 'source' => 'bwi-webhooks' ] );
                }
            } catch ( Exception $e ) {
                if ( $this->is_logging_enabled() ) {
                    wc_get_logger()->error( 'EXCEPCIÓN en Webhook de Precio para SKU ' . $sku . ': ' . $e->getMessage(), [ 'source' => 'bwi-webhooks' ] );
                }
            }
        } else {
            if ( $this->is_logging_enabled() ) {
                $error_message = is_wp_error($price_details) ? $price_details->get_error_message() : 'La respuesta de la API v1 no contenía un formato de precio válido.';
                wc_get_logger()->error( "Error de Webhook de Precio: No se pudo obtener el nuevo precio para SKU [{$sku}]. Razón: " . $error_message, [ 'source' => 'bwi-webhooks' ] );
            }
        }
    }
    
    public function handle_variant_update_webhook( $payload ) {
        if ( ! isset( $payload['resourceId'] ) ) {
            if ( $this->is_logging_enabled() ) {
                wc_get_logger()->warning( 'Webhook de variante sin resourceId.', [ 'source' => 'bwi-webhooks' ] );
            }
            return;
        }

        $variant_id_webhook = absint( $payload['resourceId'] );
        $variant_details = $this->api_client->get( "variants/{$variant_id_webhook}.json" );

        if ( is_wp_error( $variant_details ) || empty( $variant_details->code ) ) {
            if ( $this->is_logging_enabled() ) {
                wc_get_logger()->error( "Error de Webhook de Variante: No se pudo obtener detalles para la variante Bsale ID [{$variant_id_webhook}].", [ 'source' => 'bwi-webhooks' ] );
            }
            return;
        }
        
        $sku = $variant_details->code;
        $product_id = wc_get_product_id_by_sku( $sku );

        if ( ! $product_id ) {
            if ( $this->is_logging_enabled() ) {
                wc_get_logger()->info( "Webhook de Variante ignorado: No se encontró producto en WooCommerce con SKU [{$sku}].", [ 'source' => 'bwi-webhooks' ] );
            }
            return;
        }

        try {
            $product = wc_get_product( $product_id );
            if ( ! $product ) { return; }

            $bsale_state = isset( $variant_details->state ) ? (int) $variant_details->state : 0;
            $wc_status = $product->get_status();

            $status_changed = false;
            if ( $bsale_state === 1 && $wc_status === 'publish' ) {
                $product->set_status('draft');
                $status_changed = true;
                if ( $this->is_logging_enabled() ) {
                    wc_get_logger()->info( "ÉXITO Webhook: Producto SKU [{$sku}] desactivado (pasado a borrador).", [ 'source' => 'bwi-webhooks' ] );
                }
            } elseif ( $bsale_state === 0 && $wc_status !== 'publish' ) {
                $product->set_status('publish');
                $status_changed = true;
                if ( $this->is_logging_enabled() ) {
                    wc_get_logger()->info( "ÉXITO Webhook: Producto SKU [{$sku}] activado (publicado).", [ 'source' => 'bwi-webhooks' ] );
                }
            }
            
            if ( $status_changed ) {
                $product->save();
            }

        } catch ( Exception $e ) {
            if ( $this->is_logging_enabled() ) {
                wc_get_logger()->error( 'EXCEPCIÓN en Webhook de Variante para SKU ' . $sku . ': ' . $e->getMessage(), [ 'source' => 'bwi-webhooks' ] );
            }
        }
    }
}