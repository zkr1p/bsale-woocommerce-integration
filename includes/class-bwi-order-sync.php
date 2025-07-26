<?php
/**
 * Clase para manejar la sincronización de pedidos de WooCommerce a Bsale.
 *
 * @package Bsale_WooCommerce_Integration
 */

if ( ! defined( 'ABSPATH' ) ) exit;

final class BWI_Order_Sync {
    private static $instance;
    private $options;

    private function __construct() {
        $this->options = get_option( 'bwi_options' );
        add_action( 'woocommerce_order_status_changed', [ $this, 'trigger_document_creation' ], 10, 4 );
        add_action( 'bwi_create_document_for_order', [ $this, 'process_document_creation' ], 10, 1 );
        add_action( 'woocommerce_order_status_refunded', [ $this, 'trigger_credit_note_creation' ], 10, 1 );
        add_action( 'woocommerce_order_status_cancelled', [ $this, 'trigger_credit_note_creation' ], 10, 1 );
        add_action( 'bwi_create_credit_note_for_order', [ $this, 'process_credit_note_creation' ], 10, 1 );
    }

    public static function get_instance() {
        if ( ! isset( self::$instance ) ) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    public function trigger_document_creation( $order_id, $old_status, $new_status, $order ) {
        if ( empty( $this->options['enable_billing'] ) ) return;
        $trigger_status = ! empty( $this->options['trigger_status'] ) ? $this->options['trigger_status'] : 'processing';
        if ( $new_status !== $trigger_status ) return;
        if ( $order->get_meta( '_bwi_document_id' ) || as_next_scheduled_action( 'bwi_create_document_for_order', [ 'order_id' => $order_id ] ) ) return;
        as_enqueue_async_action( 'bwi_create_document_for_order', [ 'order_id' => $order_id ], 'bwi-orders' );
        $order->add_order_note( __( 'Solicitud de creación de documento en Bsale ha sido encolada.', 'bsale-woocommerce-integration' ) );
    }

    public function process_document_creation( $order_id ) {
        $order = wc_get_order( $order_id );
        if ( ! $order ) {
            return;
        }

        $this->options = get_option( 'bwi_options' );
        $is_logging_enabled = ! empty( $this->options['enable_logging'] );
        // Forzamos la activación del logger para este diagnóstico.
        $logger = wc_get_logger();

        $payload = $this->build_bsale_payload( $order );
        if ( is_wp_error( $payload ) ) {
            $order->add_order_note( 'Error al construir payload para Bsale: ' . $payload->get_error_message() );
            $logger->error( 'Error al construir payload para Pedido #' . $order_id . ': ' . $payload->get_error_message(), [ 'source' => 'bwi-orders' ] );
            return;
        }

        // --- INICIO DEL LOGGING DE DIAGNÓSTICO ---
        // Registramos el payload completo que vamos a enviar a Bsale.
        $logger->info( 'Payload a enviar a Bsale para Pedido #' . $order_id . ': ' . wp_json_encode( $payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ), [ 'source' => 'bwi-orders' ] );
        // --- FIN DEL LOGGING DE DIAGNÓSTICO ---

        $api_client = BWI_API_Client::get_instance();
        $response = $api_client->post( 'documents.json', $payload );

        if ( is_wp_error( $response ) ) {
            $error_message = $response->get_error_message();
            $order->add_order_note( '<strong>Error al crear documento en Bsale:</strong> ' . $error_message );
            
            // --- INICIO DEL LOGGING DE DIAGNÓSTICO ---
            // Registramos el error exacto que Bsale nos devuelve.
            $logger->error( 'Error recibido de la API de Bsale para Pedido #' . $order_id . ': ' . $error_message, [ 'source' => 'bwi-orders' ] );
            $logger->error( 'Datos completos del error: ' . wp_json_encode( $response->get_error_data(), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ), [ 'source' => 'bwi-orders' ] );
            // --- FIN DEL LOGGING DE DIAGNÓSTICO ---

        } else if ( isset( $response->id ) && isset( $response->urlPdf ) ) {
            $order->update_meta_data( '_bwi_document_id', $response->id );
            $order->update_meta_data( '_bwi_document_number', $response->number );
            $order->update_meta_data( '_bwi_document_url', $response->urlPdf );
            $note = sprintf( 'Documento creado exitosamente en Bsale. Folio: %s. <a href="%s" target="_blank">Ver PDF</a>', esc_html( $response->number ), esc_url( $response->urlPdf ) );
            $order->add_order_note( $note );
            $order->save();
            do_action( 'bwi_send_document_email_notification', $order_id );
        } else {
             $order->add_order_note( '<strong>Respuesta inesperada de la API de Bsale al crear documento.</strong>' );
             $logger->warning( 'Respuesta inesperada de la API para Pedido #' . $order_id . ': ' . wp_json_encode( $response, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ), [ 'source' => 'bwi-orders' ] );
        }
    }

    /*
    public function process_document_creation( $order_id ) {
        $order = wc_get_order( $order_id );
        if ( ! $order ) return;
        
        $this->options = get_option( 'bwi_options' );
        $is_logging_enabled = ! empty( $this->options['enable_logging'] );
        $logger = $is_logging_enabled ? wc_get_logger() : null;

        $payload = $this->build_bsale_payload( $order );
        if ( is_wp_error( $payload ) ) {
            $order->add_order_note( 'Error al construir payload para Bsale: ' . $payload->get_error_message() );
            if ($is_logging_enabled) $logger->error( 'Error al construir payload para Pedido #' . $order_id . ': ' . $payload->get_error_message(), [ 'source' => 'bwi-orders' ] );
            return;
        }

        if ($is_logging_enabled) $logger->info( 'Payload a enviar a Bsale para Pedido #' . $order_id . ': ' . wp_json_encode( $payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ), [ 'source' => 'bwi-orders' ] );

        $api_client = BWI_API_Client::get_instance();
        $response = $api_client->post( 'documents.json', $payload );

        if ( is_wp_error( $response ) ) {
            $error_message = $response->get_error_message();
            $order->add_order_note( '<strong>Error al crear documento en Bsale:</strong> ' . $error_message );
            if ($is_logging_enabled) {
                $logger->error( 'Error recibido de la API de Bsale para Pedido #' . $order_id . ': ' . $error_message, [ 'source' => 'bwi-orders' ] );
                $logger->error( 'Datos completos del error: ' . wp_json_encode( $response->get_error_data(), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ), [ 'source' => 'bwi-orders' ] );
            }
        } else if ( isset( $response->id ) && isset( $response->urlPdf ) ) {
            $order->update_meta_data( '_bwi_document_id', $response->id );
            $order->update_meta_data( '_bwi_document_number', $response->number );
            $order->update_meta_data( '_bwi_document_url', $response->urlPdf );
            $note = sprintf( 'Documento creado exitosamente en Bsale. Folio: %s. <a href="%s" target="_blank">Ver PDF</a>', esc_html( $response->number ), esc_url( $response->urlPdf ) );
            $order->add_order_note( $note );
            $order->save();
            do_action( 'bwi_send_document_email_notification', $order_id );
        } else {
             $order->add_order_note( '<strong>Respuesta inesperada de la API de Bsale al crear documento.</strong>' );
             if ($is_logging_enabled) $logger->warning( 'Respuesta inesperada de la API para Pedido #' . $order_id . ': ' . wp_json_encode( $response, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ), [ 'source' => 'bwi-orders' ] );
        }
    }
        */

      /**
     * Construye el payload para la API de Bsale a partir de una orden de WooCommerce.
     * Esta versión final está optimizada para:
     * 1. Respetar la configuración de precios con IVA incluido de WooCommerce.
     * 2. Distribuir correctamente los descuentos como un porcentaje por línea.
     * 3. Especificar explícitamente el impuesto (IVA 19%) por su código tributario en cada línea.
     * 4. Simular el cálculo de Bsale para obtener un total de pago exacto y evitar errores de redondeo.
     * 5. Enviar el pago solo para Boletas, dejando las Facturas con saldo pendiente.
     *
     * @param WC_Order $order El objeto del pedido de WooCommerce.
     * @return array|WP_Error El payload listo para enviar a Bsale o un objeto WP_Error si falta un SKU.
     */
    /**
     * Construye el payload para la API de Bsale a partir de una orden de WooCommerce.
     * VERSIÓN FINAL Y DEPURADA v3.
     *
     * @param WC_Order $order El objeto del pedido de WooCommerce.
     * @return array|WP_Error El payload listo para enviar a Bsale o un objeto WP_Error.
     */
    /**
     * Construye el payload para la API de Bsale a partir de una orden de WooCommerce.
     * VERSIÓN FINAL Y DEPURADA.
     *
     * @param WC_Order $order El objeto del pedido de WooCommerce.
     * @return array|WP_Error El payload listo para enviar a Bsale o un objeto WP_Error.
     */
    /**
     * Construye el payload para la API de Bsale a partir de una orden de WooCommerce.
     * VERSIÓN FINAL Y DEPURADA.
     *
     * @param WC_Order $order El objeto del pedido de WooCommerce.
     * @return array|WP_Error El payload listo para enviar a Bsale o un objeto WP_Error.
     */
    private function build_bsale_payload( $order ) {
        $document_type = $order->get_meta( '_bwi_document_type' );
        $payload = [
            'officeId' => !empty($this->options['office_id_stock']) ? absint($this->options['office_id_stock']) : 1,
            'priceListId' => !empty($this->options['price_list_id']) ? absint($this->options['price_list_id']) : 1,
            'emissionDate' => time(),
            'expirationDate' => time(),
            'declareSii' => 1 // Se añade para asegurar la declaración al SII
        ];

        // 1. CONFIGURACIÓN DEL CLIENTE Y TIPO DE DOCUMENTO
        if ('factura' === $document_type) {
            $payload['codeSii'] = !empty($this->options['factura_codesii']) ? absint($this->options['factura_codesii']) : 33;
            $payload['client'] = ['code' => $order->get_meta('_bwi_billing_rut'),'company' => $order->get_meta('_bwi_billing_company_name'),'activity' => $order->get_meta('_bwi_billing_activity'),'address' => $order->get_meta('_bwi_fiscal_address'),'municipality' => $order->get_meta('_bwi_fiscal_municipality'),'city' => $order->get_meta('_bwi_fiscal_city'),'email' => $order->get_billing_email(),'phone' => $order->get_billing_phone(),'companyOrPerson' => 1];
        } else {
            $payload['codeSii'] = !empty($this->options['boleta_codesii']) ? absint($this->options['boleta_codesii']) : 39;
            $first_name = trim($order->get_billing_first_name()); $last_name = trim($order->get_billing_last_name());
            if (empty($first_name) && empty($last_name)) { $first_name = 'Cliente'; $last_name = 'Tienda'; }
            $payload['client'] = ['code' => '1-9','firstName' => $first_name,'lastName' => $last_name,'email' => $order->get_billing_email(),'companyOrPerson' => 0];
        }

        $final_details = [];
        $bsale_tax_id_array = '[1]'; // ID del IVA en Bsale es 1, como confirmó el log.

        // 2. PROCESAMIENTO DE LÍNEAS DE PRODUCTO
        foreach ($order->get_items() as $item) {
            $product = $item->get_product();
            $sku = $product ? $product->get_sku() : '';
            if (empty($sku)) { return new WP_Error('missing_sku', 'Un producto en el pedido no tiene SKU.'); }

            $quantity = $item->get_quantity();
            if ($quantity == 0) { continue; }

            $line_subtotal_net = (float) $item->get_subtotal();
            $line_total_net = (float) $item->get_total();
            
            $net_unit_value = round($line_subtotal_net / $quantity, 2);
            
            $line_discount_percentage = 0;
            if ($line_subtotal_net > 0 && $line_total_net < $line_subtotal_net) {
                $line_discount_percentage = ( ($line_subtotal_net - $line_total_net) / $line_subtotal_net ) * 100;
            }

            $final_details[] = [
                'code' => $sku,
                'quantity' => $quantity,
                'netUnitValue' => $net_unit_value,
                'taxId' => $bsale_tax_id_array,
                'discount' => round($line_discount_percentage, 4)
            ];
        }

        // 3. PROCESAMIENTO DEL ENVÍO
        $shipping_total_net = (float) $order->get_shipping_total();
        if ($shipping_total_net > 0) {
            $final_details[] = [
               'comment' => 'Costo de Envío: ' . $order->get_shipping_method(),
               'quantity' => 1,
               'netUnitValue' => $shipping_total_net,
               'taxId' => $bsale_tax_id_array
           ];
        }

        $payload['details'] = $final_details;

        // 4. [SOLUCIÓN DEFINITIVA] SE ELIMINA POR COMPLETO EL NODO 'payments'.
        // Bsale se encargará de crear el documento y registrar el pago por el monto que él mismo calcule.
        // Esto elimina cualquier posible error de redondeo.
        
        return $payload;
    }

    public function trigger_credit_note_creation( $order_id ) {
        if ( empty( $this->options['enable_billing'] ) ) return;
        $order = wc_get_order( $order_id );
        if ( ! $order->get_meta( '_bwi_document_id' ) || $order->get_meta( '_bwi_return_id' ) ) return;
        if ( as_next_scheduled_action( 'bwi_create_credit_note_for_order', [ 'order_id' => $order_id ] ) ) return;
        as_enqueue_async_action( 'bwi_create_credit_note_for_order', [ 'order_id' => $order_id ], 'bwi-orders' );
        $order->add_order_note( __( 'Solicitud de creación de Nota de Crédito en Bsale ha sido encolada.', 'bsale-woocommerce-integration' ) );
    }

    public function process_credit_note_creation( $order_id ) {
        $order = wc_get_order( $order_id );
        if ( ! $order ) return;
        $bsale_document_id = $order->get_meta( '_bwi_document_id' );
        if ( ! $bsale_document_id ) {
            $order->add_order_note( '<strong>Error:</strong> No se puede crear Nota de Crédito. No se encontró ID de documento Bsale original.' );
            return;
        }
        $api_client = BWI_API_Client::get_instance();
        $original_document = $api_client->get( "documents/{$bsale_document_id}.json", [ 'expand' => '[details,client,document_type]' ] );
        if ( is_wp_error( $original_document ) || empty( $original_document->details->items ) ) {
            $order->add_order_note( '<strong>Error:</strong> No se pudo obtener el detalle del documento original de Bsale para crear la Nota de Crédito.' );
            return;
        }
        $return_details = [];
        foreach ($original_document->details->items as $detail_item) {
            $return_details[] = ['documentDetailId' => $detail_item->id, 'quantity' => $detail_item->quantity];
        }
        $client_data = [];
        $is_boleta = isset($original_document->document_type->codeSii) && in_array($original_document->document_type->codeSii, [39, 41]);
        if ($is_boleta) {
            $client_data = ['code' => '1-9', 'firstName' => 'Cliente', 'lastName' => 'Tienda', 'companyOrPerson' => 0];
        } elseif (!empty($original_document->client)) {
            $client_data = ['code' => $original_document->client->code, 'company' => $original_document->client->company, 'address' => $original_document->client->address, 'municipality' => $original_document->client->municipality, 'city' => $original_document->client->city, 'activity' => $original_document->client->activity, 'companyOrPerson' => $original_document->client->companyOrPerson];
        }
        // Obtenemos el ID del tipo de documento para la nota de crédito desde los ajustes.
        $credit_note_doc_type_id = !empty($this->options['credit_note_doc_type_id']) ? absint($this->options['credit_note_doc_type_id']) : 0;

        if ( empty( $credit_note_doc_type_id ) ) {
            $order->add_order_note( '<strong>Error:</strong> No se ha configurado un Tipo de Documento para Notas de Crédito en los ajustes de Bsale.' );
            return;
        }
        $payload = [
            'documentTypeId' => $credit_note_doc_type_id,
            'officeId' => !empty($this->options['office_id_stock']) ? absint($this->options['office_id_stock']) : 1,
            'referenceDocumentId' => (int) $bsale_document_id,
            'emissionDate' => time(),
            'expirationDate' => time(),
            'motive' => 'Anulación de venta desde WooCommerce. Pedido #' . $order->get_order_number(),
            'declareSii' => 1,
            'priceAdjustment' => 0,
            'editTexts' => 0,
            'type' => 0,
            'client' => $client_data,
            'details' => $return_details,
        ];
        $response = $api_client->post( 'returns.json', $payload );
        if ( is_wp_error( $response ) ) {
            $order->add_order_note( '<strong>Error al crear Nota de Crédito en Bsale:</strong> ' . $response->get_error_message() );
        } else if ( isset( $response->id ) && isset($response->credit_note->urlPdf) ) {
            $order->update_meta_data( '_bwi_return_id', $response->id );
            $order->update_meta_data( '_bwi_credit_note_url', $response->credit_note->urlPdf );
            $note = sprintf( 'Nota de Crédito creada exitosamente en Bsale. Folio: %s. <a href="%s" target="_blank">Ver PDF</a>', esc_html( $response->credit_note->number ), esc_url( $response->credit_note->urlPdf ) );
            $order->add_order_note( $note );
            $order->save();
        } else {
             $order->add_order_note( '<strong>Respuesta inesperada de Bsale al crear la Nota de Crédito.</strong> Respuesta: ' . wp_json_encode($response) );
        }
    }
}