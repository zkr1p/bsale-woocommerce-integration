<?php
/**
 * Clase para manejar la sincronización de pedidos de WooCommerce a Bsale.
 *
 * @package Bsale_WooCommerce_Integration
 */

// Evitar el acceso directo al archivo.
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * BWI_Order_Sync Class
 */
final class BWI_Order_Sync {

    /** Instancia única de la clase. */
    private static $instance;

    /** Opciones del plugin. */
    private $options;

    /**
     * Constructor.
     */
    private function __construct() {
        $this->options = get_option( 'bwi_options' );

        // Hook que se dispara cuando el estado de una orden cambia.
        add_action( 'woocommerce_order_status_changed', [ $this, 'trigger_document_creation' ], 10, 4 );
        
        // Hook para la acción asíncrona que crea el documento en segundo plano.
        add_action( 'bwi_create_document_for_order', [ $this, 'process_document_creation' ], 10, 1 );
        // Hooks para la creación de Notas de Crédito.
        add_action( 'woocommerce_order_status_refunded', [ $this, 'trigger_credit_note_creation' ], 10, 1 );
        add_action( 'woocommerce_order_status_cancelled', [ $this, 'trigger_credit_note_creation' ], 10, 1 );
        add_action( 'bwi_create_credit_note_for_order', [ $this, 'process_credit_note_creation' ], 10, 1 );
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
     * Dispara la creación del documento en Bsale encolando una tarea asíncrona.
     *
     * @param int      $order_id    ID del pedido.
     * @param string   $old_status  Estado anterior del pedido.
     * @param string   $new_status  Nuevo estado del pedido.
     * @param WC_Order $order       Objeto del pedido.
     */
    public function trigger_document_creation( $order_id, $old_status, $new_status, $order ) {
        // 1. Validar si la funcionalidad está activada.
        if ( empty( $this->options['enable_billing'] ) ) {
            return;
        }

        // 2. Validar si el estado del pedido es el disparador correcto.
        $trigger_status = ! empty( $this->options['trigger_status'] ) ? $this->options['trigger_status'] : 'processing';
        if ( $new_status !== $trigger_status ) {
            return;
        }

        // 3. Validar para evitar duplicados (si ya existe un documento o una tarea en cola).
        if ( $order->get_meta( '_bwi_document_id' ) || as_next_scheduled_action( 'bwi_create_document_for_order', [ 'order_id' => $order_id ] ) ) {
            return;
        }

        // 4. MEJORA DE RENDIMIENTO: Encolar la tarea para que se ejecute en segundo plano.
        as_enqueue_async_action( 'bwi_create_document_for_order', [ 'order_id' => $order_id ], 'bwi-orders' );
        
        $order->add_order_note( __( 'Solicitud de creación de documento en Bsale ha sido encolada para procesamiento en segundo plano.', 'bsale-woocommerce-integration' ) );
    }

    /**
     * Procesa la creación del documento. Esta función es ejecutada por Action Scheduler.
     *
     * @param int $order_id ID del pedido a procesar.
     */
    public function process_document_creation( $order_id ) {
        $order = wc_get_order( $order_id );
        if ( ! $order ) {
            return;
        }

        // Construir el payload.
        $payload = $this->build_bsale_payload( $order );
        if ( is_wp_error( $payload ) ) {
            $order->add_order_note( 'Error al construir payload para Bsale: ' . $payload->get_error_message() );
            return;
        }

        // Enviar la solicitud a la API.
        $api_client = BWI_API_Client::get_instance();
        $response = $api_client->post( 'documents.json', $payload );

        // Manejar la respuesta.
        if ( is_wp_error( $response ) ) {
            $order->add_order_note( '<strong>Error al crear documento en Bsale:</strong> ' . $response->get_error_message() );
        } else if ( isset( $response->id ) ) {
            $order->update_meta_data( '_bwi_document_id', $response->id );
            $order->update_meta_data( '_bwi_document_number', $response->number );
            $order->update_meta_data( '_bwi_document_url', $response->urlPdf );
            $order->save();

            $note = sprintf(
                'Documento creado exitosamente en Bsale. Folio: %s. <a href="%s" target="_blank">Ver PDF</a>',
                esc_html( $response->number ),
                esc_url( $response->urlPdf )
            );
            $order->add_order_note( $note );
        } else {
             $order->add_order_note( '<strong>Respuesta inesperada de la API de Bsale al crear documento.</strong>' );
        }
    }

    /**
     * Construye el array del payload para enviar a la API de Bsale.
     *
     * @param WC_Order $order Objeto del pedido de WooCommerce.
     * @return array|WP_Error El payload o un error.
     */
    private function build_bsale_payload( $order ) {
        $document_type = $order->get_meta( '_bwi_document_type' );
        $billing_rut = $order->get_meta( '_bwi_billing_rut' );

        if ( 'factura' === $document_type ) {
            $code_sii = ! empty( $this->options['factura_codesii'] ) ? absint($this->options['factura_codesii']) : 33;
            $client_rut = ! empty( $billing_rut ) ? $billing_rut : '';
            if ( empty($client_rut) ) {
                return new WP_Error( 'missing_rut_for_invoice', 'Se intentó crear una factura sin un RUT de cliente.' );
            }
        } else {
            $code_sii = ! empty( $this->options['boleta_codesii'] ) ? absint($this->options['boleta_codesii']) : 39;
            // CORRECCIÓN DE BUG: Usar RUT genérico para boletas.
            $client_rut = '1-9';
        }
        
        $client_data = [
            'code'           => $client_rut,
            'city'           => $order->get_billing_city(),
            'company'        => $order->get_billing_company() ?: $order->get_formatted_billing_full_name(),
            'municipality'   => $order->get_billing_state(),
            'activity'       => ( 'factura' === $document_type ) ? 'Giro de la empresa' : 'Consumidor Final',
            'address'        => $order->get_billing_address_1(),
            'email'          => $order->get_billing_email(),
            'phone'          => $order->get_billing_phone(),
            'companyOrPerson' => ( 'factura' === $document_type ) ? 1 : 0,
        ];

        $details = [];
        foreach ( $order->get_items() as $item ) {
            $product = $item->get_product();
            $sku = $product->get_sku();

            if ( empty( $sku ) ) {
                return new WP_Error( 'missing_sku', 'El producto "' . $product->get_name() . '" no tiene un SKU y no puede ser facturado.' );
            }

            $details[] = [
                'code'         => $sku,
                'quantity'     => $item->get_quantity(),
                'netUnitValue' => wc_get_price_excluding_tax( $product, [ 'qty' => 1 ] ),
            ];
        }

        // Añadir el envío como una línea de detalle si existe.
        if ( $order->get_shipping_total() > 0 ) {
            $details[] = [
                'comment' => 'Costo de Envío: ' . $order->get_shipping_method(),
                'quantity' => 1,
                'netUnitValue' => wc_get_price_excluding_tax( $order, ['price' => $order->get_shipping_total()] ),
            ];
        }

        $payload = [
            'salesId'      => $order->get_order_key(), // Idempotencia para evitar documentos duplicados
            'codeSii'      => $code_sii,
            'officeId'     => ! empty( $this->options['office_id_stock'] ) ? absint($this->options['office_id_stock']) : 1,
            'priceListId'  => 1, // Se puede hacer configurable en el futuro.
            'emissionDate' => time(),
            'client'       => $client_data,
            'details'      => $details,
        ];

        return $payload;
    }

    /**
     * Procesa la creación de la Nota de Crédito.
     * @param int $order_id
     */
    public function process_credit_note_creation( $order_id ) {
        $order = wc_get_order( $order_id );
        if ( ! $order ) return;

        $bsale_document_id = $order->get_meta( '_bwi_document_id' );
        if ( ! $bsale_document_id ) return;

        $api_client = BWI_API_Client::get_instance();

        // Para crear una devolución, necesitamos los IDs de los detalles del documento original.
        $original_document = $api_client->get( "documents/{$bsale_document_id}.json", [ 'expand' => '[details]' ] );

        if ( is_wp_error( $original_document ) || empty( $original_document->details->items ) ) {
            $order->add_order_note( '<strong>Error:</strong> No se pudo obtener el detalle del documento original de Bsale para crear la Nota de Crédito.' );
            return;
        }
        
        $payload = [
            'documentId' => (int) $bsale_document_id,
            'officeId'   => ! empty( $this->options['office_id_stock'] ) ? absint($this->options['office_id_stock']) : 1,
            'motive'     => 'Anulación de venta desde WooCommerce. Pedido #' . $order->get_order_number(),
            'details'    => []
        ];

        // Mapear los productos reembolsados a los detalles de la devolución.
        // En este ejemplo, asumimos una devolución total.
        foreach ( $original_document->details->items as $detail_item ) {
            $payload['details'][] = [
                'detailId' => $detail_item->id,
                'quantity' => $detail_item->quantity
            ];
        }

        $response = $api_client->create_return( $payload );

        if ( is_wp_error( $response ) ) {
            $order->add_order_note( '<strong>Error al crear Nota de Crédito en Bsale:</strong> ' . $response->get_error_message() );
        } else if ( isset( $response->id ) ) {
            $order->update_meta_data( '_bwi_return_id', $response->id );
            // La respuesta de la API de devoluciones puede variar, ajusta según sea necesario.
            $note = 'Nota de Crédito creada exitosamente en Bsale. ID de Devolución: ' . $response->id;
            if(isset($response->credit_note->urlPdf)) {
                $note .= sprintf(' <a href="%s" target="_blank">Ver PDF</a>', esc_url($response->credit_note->urlPdf));
            }
            $order->add_order_note( $note );
            $order->save();
        }
    }
}
