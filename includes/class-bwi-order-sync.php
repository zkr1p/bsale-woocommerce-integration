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
    private const BSALE_BOLETA_CODE_SII = 39;
    private const BSALE_FACTURA_CODE_SII = 33;
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

        // Lógica para definir $client_data y $code_sii (sin cambios)
        if ( 'factura' === $document_type ) {
            $code_sii = ! empty( $this->options['factura_codesii'] ) ? absint($this->options['factura_codesii']) : self::BSALE_FACTURA_CODE_SII;
            $client_data = [ 'code' => $order->get_meta( '_bwi_billing_rut' ), 'company' => $order->get_meta( '_bwi_billing_company_name' ), 'activity' => $order->get_meta( '_bwi_billing_activity' ), 'address' => $order->get_meta( '_bwi_fiscal_address' ), 'municipality' => $order->get_meta( '_bwi_fiscal_municipality' ), 'city' => $order->get_meta( '_bwi_fiscal_city' ), 'email' => $order->get_billing_email(), 'phone' => $order->get_billing_phone(), 'companyOrPerson' => 1 ];
        } else {
            $code_sii = ! empty( $this->options['boleta_codesii'] ) ? absint($this->options['boleta_codesii']) : self::BSALE_BOLETA_CODE_SII;
            $client_data = [ 'code' => '1-9', 'company' => $order->get_formatted_billing_full_name(), 'address' => $order->get_billing_address_1(), 'municipality' => $order->get_billing_state(), 'city' => $order->get_billing_city(), 'email' => $order->get_billing_email(), 'phone' => $order->get_billing_phone(), 'companyOrPerson' => 0 ];
        }

        $details = [];
        $bsale_tax_id_array = '[1]';

        foreach ( $order->get_items() as $item ) {
            $product = $item->get_product();
            $sku = $product->get_sku();

            if ( empty( $sku ) ) {
                return new WP_Error( 'missing_sku', 'El producto "' . $product->get_name() . '" no tiene un SKU y no puede ser facturado.' );
            }
            
            $total_unit_price = ( (float) $item->get_total() + (float) $item->get_total_tax() ) / $item->get_quantity();
            $net_unit_value = $total_unit_price / 1.19;

            $details[] = [
                'code'           => $sku,
                'quantity'       => $item->get_quantity(),
                'netUnitValue'   => $net_unit_value,
                'taxId'          => $bsale_tax_id_array,
            ];
        }

        if ( (float) $order->get_shipping_total() > 0 ) {
            $total_shipping_price = (float) $order->get_shipping_total() + (float) $order->get_shipping_tax();
            $net_shipping_price = $total_shipping_price / 1.19;

            $details[] = [
                'comment'      => 'Costo de Envío: ' . $order->get_shipping_method(),
                'quantity'     => 1,
                'netUnitValue' => $net_shipping_price,
                'taxId'        => $bsale_tax_id_array,
            ];
        }

        $payload = [
            'codeSii'      => $code_sii,
            'officeId'     => ! empty( $this->options['office_id_stock'] ) ? absint($this->options['office_id_stock']) : 1,
            'priceListId'  => ! empty( $this->options['price_list_id'] ) ? absint($this->options['price_list_id']) : 1,
            'emissionDate' => time(),
            'client'       => $client_data,
            'details'      => $details,
        ];

        
        // Solo añadimos la información del pago si el documento NO es una factura.
        if ( 'factura' !== $document_type ) {
            $payment_map_key = 'payment_map_' . $order->get_payment_method();
            // Usamos el mapeo que configuramos en los ajustes.
            $bsale_payment_type_id = isset( $this->options[$payment_map_key] ) ? absint( $this->options[$payment_map_key] ) : 0;
            
            // Solo si hay un mapeo válido para esta forma de pago.
            if ( $bsale_payment_type_id > 0 ) {
                $payload['payments'] = [
                    [
                        'paymentTypeId' => $bsale_payment_type_id,
                        'amount'        => $order->get_total(),
                        'recordDate'    => $order->get_date_paid() ? $order->get_date_paid()->getTimestamp() : time(),
                    ]
                ];
            }
        }
        

        return $payload;
    }
    /**
     * FUNCIÓN QUE FALTABA: Dispara la creación de la Nota de Crédito encolando una tarea asíncrona.
     * @param int $order_id
     */
    public function trigger_credit_note_creation( $order_id ) {
        if ( empty( $this->options['enable_billing'] ) ) {
            return;
        }

        $order = wc_get_order( $order_id );

        // Validar que exista un documento original y que no se haya creado ya una nota de crédito.
        if ( ! $order->get_meta( '_bwi_document_id' ) || $order->get_meta( '_bwi_return_id' ) ) {
            return;
        }
        
        // Evitar duplicados si ya hay una tarea en cola.
        if ( as_next_scheduled_action( 'bwi_create_credit_note_for_order', [ 'order_id' => $order_id ] ) ) {
            return;
        }

        as_enqueue_async_action( 'bwi_create_credit_note_for_order', [ 'order_id' => $order_id ], 'bwi-orders' );
        $order->add_order_note( __( 'Solicitud de creación de Nota de Crédito en Bsale ha sido encolada.', 'bsale-woocommerce-integration' ) );
    }


    /**
     * Procesa la creación de la Nota de Crédito.
     * @param int $order_id
     */
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
            $return_details[] = [
                'documentDetailId' => $detail_item->id,
                'quantity'         => $detail_item->quantity,
            ];
        }
        
        $client_data = [];
        $is_boleta = isset($original_document->document_type->codeSii) && in_array($original_document->document_type->codeSii, [39, 41]);

        if ( $is_boleta ) {
            $client_data = [
                'code'           => '1-9',
                'company'        => 'Consumidor Final',
                'address'        => 'N/A',
                'municipality'   => 'N/A',
                'city'           => 'N/A',
                'companyOrPerson' => 0,
            ];
        } elseif ( !empty($original_document->client) ) {
            $client_data = [
                'code'           => $original_document->client->code,
                'city'           => $original_document->client->city,
                'company'        => $original_document->client->company,
                'municipality'   => $original_document->client->municipality,
                'activity'       => $original_document->client->activity,
                'address'        => $original_document->client->address,
                'companyOrPerson'=> $original_document->client->companyOrPerson,
            ];
        }

        $payload = [
            'referenceDocumentId' => (int) $bsale_document_id,
            'documentTypeId'      => 9,
            'officeId'            => ! empty( $this->options['office_id_stock'] ) ? absint($this->options['office_id_stock']) : 1,
            'motive'              => 'Anulación de venta desde WooCommerce. Pedido #' . $order->get_order_number(),
            'client'              => $client_data,
            'details'             => $return_details,
            'declareSii'          => 1,
            // --- INICIO DE LA CORRECCIÓN ---
            'emissionDate'        => time(), // Se añade la fecha de emisión actual
            // --- FIN DE LA CORRECCIÓN ---
        ];

        $response = $api_client->post( 'returns.json', $payload );

        if ( is_wp_error( $response ) ) {
            $order->add_order_note( '<strong>Error al crear Nota de Crédito en Bsale:</strong> ' . $response->get_error_message() );
        } else if ( isset( $response->id ) && isset($response->credit_note->urlPdf) ) {
            $order->update_meta_data( '_bwi_return_id', $response->id );
            $order->update_meta_data( '_bwi_credit_note_url', $response->credit_note->urlPdf );
            
            $note = sprintf(
                'Nota de Crédito creada exitosamente en Bsale. Folio: %s. <a href="%s" target="_blank">Ver PDF</a>',
                esc_html( $response->credit_note->number ),
                esc_url( $response->credit_note->urlPdf )
            );
            $order->add_order_note( $note );
            $order->save();
        } else {
             $order->add_order_note( '<strong>Respuesta inesperada de Bsale al crear la Nota de Crédito.</strong> Respuesta: ' . wp_json_encode($response) );
        }
    }

}
