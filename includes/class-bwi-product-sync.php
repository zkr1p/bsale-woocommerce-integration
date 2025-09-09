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
     * Aquí se registran todas las acciones y hooks que maneja la clase.
     */
    private function __construct() {
        $this->api_client = BWI_API_Client::get_instance();

        // Acciones para la sincronización programada y manual.
        add_action( 'bwi_cron_sync_products', [ $this, 'schedule_full_sync' ] );
        add_action( 'wp_ajax_bwi_manual_sync', [ $this, 'handle_manual_sync' ] );

        // Acciones del procesador de colas (Action Scheduler).
        add_action( 'bwi_sync_products_batch', [ $this, 'process_sync_batch' ], 10, 1 );
        add_action( 'bwi_sync_single_variant', [ $this, 'update_product_from_variant' ], 10, 2 );
        
        // Acción para la tarea de recarga de precios en segundo plano.
        add_action( 'bwi_precaching_price_list', [ $this, 'run_price_list_precaching' ], 10, 1 );

        // Acción para limpiar la caché antes de una sincronización completa.
        add_action( 'bwi_before_full_sync', [ $this, 'clear_price_list_cache' ] );
    }

    /**
     * Obtener la instancia única de la clase (Patrón Singleton).
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
     * @return bool
     */
    private function is_logging_enabled() {
        if ( ! isset( $this->options ) ) {
            $this->options = get_option( 'bwi_options' );
        }
        return ! empty( $this->options['enable_logging'] );
    }

    /**
     * Maneja la solicitud AJAX para iniciar una sincronización manual.
     */
    public function handle_manual_sync() {
        check_ajax_referer( 'bwi_manual_sync_nonce', 'security' );
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( [ 'message' => 'No tienes permisos suficientes.' ], 403 );
        }
        $this->schedule_full_sync();
        wp_send_json_success( [ 'message' => 'Sincronización masiva iniciada en segundo plano.' ] );
    }

    /**
     * Inicia una sincronización masiva de productos de forma segura.
     *
     * MEJORA CLAVE:
     * Antes de programar una nueva sincronización, verifica si ya hay una en
     * estado "pendiente" o "en ejecución". Esto evita la duplicación de tareas
     * y el error de "colas simultáneas" de Action Scheduler.
     */
    public function schedule_full_sync() {
        // Verificamos si la librería Action Scheduler está disponible.
        if ( function_exists('as_get_scheduled_actions') ) {
            $pending_actions = as_get_scheduled_actions( [
                'hook'   => 'bwi_sync_products_batch',
                'status' => [ ActionScheduler_Store::STATUS_PENDING, ActionScheduler_Store::STATUS_RUNNING ],
                'group'  => 'bwi-sync',
            ], 'ids' );
    
            // Si se encuentran acciones, significa que ya hay un proceso en marcha.
            if ( ! empty( $pending_actions ) ) {
                if ( $this->is_logging_enabled() ) {
                    wc_get_logger()->info( '== SINCRONIZACIÓN OMITIDA: Ya hay un proceso de sincronización masiva en curso. ==', [ 'source' => 'bwi-sync' ] );
                }
                return; // Salimos para no programar una nueva tarea.
            }
        }

        // Si no hay acciones en curso, iniciamos una nueva sincronización.
        if ( $this->is_logging_enabled() ) {
            wc_get_logger()->info( '== INICIO DE SINCRONIZACIÓN MASIVA DE PRODUCTOS ==', [ 'source' => 'bwi-sync' ] );
        }
        do_action( 'bwi_before_full_sync' );
        as_enqueue_async_action( 'bwi_sync_products_batch', [ 'offset' => 0 ], 'bwi-sync' );
    }

    /**
     * Limpia la caché de precios (transient) para forzar una recarga.
     */
    public function clear_price_list_cache() {
        $this->options = get_option( 'bwi_options' );
        $price_list_id = ! empty( $this->options['price_list_id'] ) ? absint($this->options['price_list_id']) : 0;
        if ($price_list_id > 0) {
            delete_transient('bwi_price_list_cache_' . $price_list_id);
        }
        if ( $this->is_logging_enabled() ) {
            wc_get_logger()->info( 'Caché de lista de precios limpiada para el nuevo ciclo de sincronización.', ['source' => 'bwi-sync'] );
        }
    }
    
    /**
     * Procesa un lote de productos de la API de Bsale.
     *
     * MEJORA CLAVE:
     * El límite ($limit) se ha reducido de 50 a 25. Esto hace que cada tarea
     * individual sea más pequeña y rápida, reduciendo la probabilidad de
     * alcanzar los límites de tiempo de ejecución del servidor.
     */
    public function process_sync_batch( $offset = 0 ) {
        $limit = 25; // Lote reducido para mayor estabilidad.
        $logger = $this->is_logging_enabled() ? wc_get_logger() : null;
        
        $log_message = "Procesando lote de productos. Offset: {$offset}, Límite: {$limit}.";

        $this->options = get_option( 'bwi_options' );
        $product_type_id_sync = ! empty( $this->options['product_type_id_sync'] ) ? absint($this->options['product_type_id_sync']) : 0;
        
        $params = [ 'limit' => $limit, 'offset' => $offset, 'expand' => '[variants]' ];
        
        if ( $product_type_id_sync > 0 ) {
            $params['producttypeid'] = $product_type_id_sync;
            $log_message .= " Filtrando por Tipo de Producto ID: {$product_type_id_sync}.";
        }

        if ( $logger ) {
            $logger->info( $log_message, [ 'source' => 'bwi-sync' ] );
        }
        
        $response = $this->api_client->get( 'products.json', $params );

        if ( is_wp_error( $response ) ) {
            if ( $logger ) {
                $logger->error( 'Error de API al obtener lote de productos: ' . $response->get_error_message(), [ 'source' => 'bwi-sync' ] );
            }
            return;
        }
        
        if ( empty( $response->items ) ) {
            if ( $logger ) {
                $logger->info( '== FIN DE SINCRONIZACIÓN MASIVA: No hay más productos que procesar. ==', [ 'source' => 'bwi-sync' ] );
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

        // Si la respuesta de la API indica que hay más páginas, programamos el siguiente lote.
        if ( isset($response->next) && !empty($response->next) ) {
            as_enqueue_async_action( 'bwi_sync_products_batch', [ 'offset' => $offset + $limit ], 'bwi-sync' );
        } else {
            if ( $logger ) {
                $logger->info( '== FIN DE SINCRONIZACIÓN MASIVA: Se procesó la última página de productos. ==', [ 'source' => 'bwi-sync' ] );
            }
        }
    }
    
    /**
     * Actualiza un único producto en WooCommerce a partir de los datos de una variante de Bsale.
     * Esta es la versión con logging detallado para depuración.
     */
    public function update_product_from_variant( $variant_data, $product_name ) {
        $logger = $this->is_logging_enabled() ? wc_get_logger() : null;
        $sku = isset($variant_data['code']) ? $variant_data['code'] : null;
        $variant_id = isset($variant_data['id']) ? $variant_data['id'] : null;
        
        if ( !$logger || !$sku ) {
            return;
        }

        $logger->info( "--- [INICIO] Procesando SKU [{$sku}] (VariantID: {$variant_id}) ---", [ 'source' => 'bwi-sync' ] );

        $product_id = wc_get_product_id_by_sku( $sku );
        if ( ! $product_id ) {
            $logger->info( "[OMITIENDO] SKU [{$sku}] no encontrado en WooCommerce.", [ 'source' => 'bwi-sync' ] );
            return;
        }

        $logger->info( "[PASO 1/7] SKU [{$sku}] encontrado. ID de producto en WC: [{$product_id}]. Cargando objeto del producto...", [ 'source' => 'bwi-sync' ] );

        try {
            $product = wc_get_product( $product_id );
            if ( ! $product ) {
                $logger->error( "[ERROR] Se encontró ID para SKU [{$sku}], pero wc_get_product() devolvió un objeto inválido.", [ 'source' => 'bwi-sync' ] );
                return;
            }

            $logger->info( "[PASO 2/7] Objeto del producto para SKU [{$sku}] cargado exitosamente.", [ 'source' => 'bwi-sync' ] );
            $has_changes = false;

            // Verificación de estado
            $bsale_state = isset($variant_data['state']) ? (int) $variant_data['state'] : 0;
            $wc_status = $product->get_status();
            $logger->info( "[PASO 3/7] Verificando estado para SKU [{$sku}]. Bsale: {$bsale_state}, WC: {$wc_status}.", [ 'source' => 'bwi-sync' ] );
            if ( $bsale_state === 1 && $wc_status === 'publish' ) {
                $product->set_status('draft');
                $has_changes = true;
            } elseif ( $bsale_state === 0 && $wc_status !== 'publish' ) {
                $product->set_status('publish');
                $has_changes = true;
            }

            // Verificación de stock
            $logger->info( "[PASO 4/7] Obteniendo stock de Bsale para SKU [{$sku}]...", [ 'source' => 'bwi-sync' ] );
            $stock_bsale = $this->get_stock_for_variant( $variant_id );
            if ( is_wp_error( $stock_bsale ) ) {
                $logger->error("Error al obtener stock para SKU [{$sku}]: " . $stock_bsale->get_error_message(), ['source' => 'bwi-sync']);
            } else {
                if ( (int) $product->get_stock_quantity() !== (int) $stock_bsale ) {
                    $product->set_manage_stock( true );
                    $product->set_stock_quantity( $stock_bsale );
                    $has_changes = true;
                }
            }

            // Verificación de precio
            $this->options = get_option( 'bwi_options' );
            $price_list_id = ! empty( $this->options['price_list_id'] ) ? absint($this->options['price_list_id']) : 0;
            $logger->info( "[PASO 5/7] Verificando precio para SKU [{$sku}]. Lista de precios ID: {$price_list_id}.", [ 'source' => 'bwi-sync' ] );
            if ( $price_list_id > 0 ) {
                $price_bsale = $this->get_price_from_cache( $variant_id, $price_list_id );
                if ( $price_bsale !== null && abs( (float) $product->get_regular_price() - $price_bsale ) > 0.001 ) {
                    $product->set_regular_price( $price_bsale );
                    $has_changes = true;
                }
            }
            
            $logger->info( "[PASO 6/7] Verificación de cambios completa para SKU [{$sku}]. ¿Hubo cambios?: " . ($has_changes ? 'SÍ' : 'NO'), [ 'source' => 'bwi-sync' ] );

            if ( $has_changes ) {
                $logger->info( "[PASO 7/7] Intentando guardar cambios para SKU [{$sku}]...", [ 'source' => 'bwi-sync' ] );
                $product->save();
                $logger->info( "[ÉXITO] Producto SKU [{$sku}] actualizado y guardado exitosamente.", ['source' => 'bwi-sync']);
            } else {
                $logger->info( "[SIN CAMBIOS] No se requirió actualización para SKU [{$sku}].", ['source' => 'bwi-sync']);
            }
        } catch ( Exception $e ) {
            $logger->error( 'EXCEPCIÓN CATASTRÓFICA al procesar SKU ' . $sku . ': ' . $e->getMessage(), [ 'source' => 'bwi-sync' ] );
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
    
    /**
     * Obtiene el precio de un producto desde la caché (transient).
     *
     * MEJORA CLAVE:
     * Esta función ya NO intenta recargar toda la lista de precios si la caché no existe.
     * En su lugar, delega esa tarea pesada a una acción en segundo plano para no
     * bloquear el proceso de sincronización actual y evitar timeouts.
     */
    private function get_price_from_cache( $variant_id, $price_list_id ) {
        $transient_key = 'bwi_price_list_cache_' . $price_list_id;
        $price_map = get_transient( $transient_key );

        if ( false === $price_map ) {
            if ( $this->is_logging_enabled() ) {
                wc_get_logger()->info("Caché de precios [{$price_list_id}] no encontrada. Programando su recarga.", ['source' => 'bwi-sync']);
            }
            // Delega la tarea de recargar la caché a un proceso en segundo plano.
            $this->schedule_price_list_precaching( $price_list_id );
            // Devuelve null para que la sincronización actual pueda continuar sin el precio.
            return null;
        }

        if ( isset( $price_map[$variant_id] ) ) {
            return $price_map[$variant_id];
        }
        
        if ( $this->is_logging_enabled() ) {
            wc_get_logger()->warning("No se encontró el precio para la variante ID [{$variant_id}] en la caché de la lista de precios [{$price_list_id}].", ['source' => 'bwi-sync']);
        }
        return null;
    }

    /**
     * Pone en cola una tarea para precargar la lista de precios de forma asíncrona.
     * Usa un "lock" (un transient temporal) para evitar que múltiples procesos
     * intenten recargar la misma lista de precios al mismo tiempo.
     */
    private function schedule_price_list_precaching( $price_list_id ) {
        $lock_key = 'bwi_price_list_lock_' . $price_list_id;

        // Si ya existe un lock, significa que otro proceso ya está trabajando en esto.
        if ( get_transient( $lock_key ) ) {
            return;
        }

        // Establece un lock por 15 minutos para dar tiempo a que la tarea se complete.
        set_transient( $lock_key, true, 15 * MINUTE_IN_SECONDS );

        // Programa la tarea asíncrona que hará el trabajo pesado.
        as_enqueue_async_action( 'bwi_precaching_price_list', [ 'price_list_id' => $price_list_id ], 'bwi-sync-utility' );
    }

    /**
     * Tarea que obtiene y cachea una lista de precios completa desde Bsale.
     * Esta función se ejecuta de forma aislada en segundo plano.
     */
    public function run_price_list_precaching( $price_list_id ) {
        $transient_key = 'bwi_price_list_cache_' . $price_list_id;
        $lock_key = 'bwi_price_list_lock_' . $price_list_id;

        if ( $this->is_logging_enabled() ) {
            wc_get_logger()->info( "Iniciando precaching para lista de precios [{$price_list_id}]...", ['source' => 'bwi-sync'] );
        }

        $price_map = [];
        $offset = 0;
        $limit = 50; // Para obtener precios podemos usar un límite mayor, es menos pesado que productos.
        
        do {
            $endpoint = sprintf('price_lists/%d/details.json', $price_list_id);
            $response = $this->api_client->get( $endpoint, ['limit' => $limit, 'offset' => $offset] );
            
            if ( is_wp_error( $response ) || empty( $response->items ) ) {
                delete_transient( $lock_key ); // Liberamos el lock en caso de error para permitir reintentos.
                return;
            }
            
            foreach( $response->items as $item ) {
                if (isset($item->variant->id) && isset($item->variantValueWithTaxes)) {
                    $price_map[$item->variant->id] = (float) $item->variantValueWithTaxes;
                }
            }
            $offset += $limit;
        } while ( isset($response->next) && !empty($response->next) );
        
        // Guardamos la caché por 2 horas.
        set_transient( $transient_key, $price_map, 2 * HOUR_IN_SECONDS );
        
        // Liberamos el lock una vez que el trabajo está hecho.
        delete_transient( $lock_key );

        if ( $this->is_logging_enabled() ) {
            wc_get_logger()->info("Precaching COMPLETO. Lista de precios [{$price_list_id}] cargada con " . count($price_map) . " precios.", ['source' => 'bwi-sync']);
        }
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

        // Al recibir un webhook de cambio de precio, forzamos la limpieza de la caché.
        // Esto asegura que la próxima sincronización masiva use los precios más recientes.
        $transient_key = 'bwi_price_list_cache_' . $price_list_id_webhook;
        delete_transient( $transient_key );
        if ( $this->is_logging_enabled() ) {
            wc_get_logger()->info( "¡Webhook de precio recibido! Se ha limpiado la caché para la lista de precios ID [{$price_list_id_webhook}] para forzar su actualización.", [ 'source' => 'bwi-webhooks' ] );
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