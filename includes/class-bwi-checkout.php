<?php
/**
 * Clase para manejar la personalización del checkout de WooCommerce.
 * Añade campos para seleccionar Boleta/Factura y guardar los datos.
 *
 * @package Bsale_WooCommerce_Integration
 */

// Evitar el acceso directo al archivo.
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * BWI_Checkout Class
 */
final class BWI_Checkout {

    /**
     * Instancia única de la clase.
     * @var BWI_Checkout
     */
    private static $instance;

    /**
     * Constructor.
     */
    private function __construct() {
        // Hook para añadir los campos personalizados al checkout
        add_action( 'woocommerce_after_order_notes', [ $this, 'add_custom_checkout_fields' ] );

        // Hook para validar los campos personalizados
        add_action( 'woocommerce_checkout_process', [ $this, 'validate_custom_fields' ] );

        // Hook para guardar los datos personalizados en la orden
        add_action( 'woocommerce_checkout_create_order', [ $this, 'save_custom_checkout_fields' ], 10, 2 );

        // Hook para mostrar los campos en el detalle del pedido en el admin
        add_action( 'woocommerce_admin_order_data_after_billing_address', [ $this, 'display_custom_fields_in_admin_order' ], 10, 1 );

        // Hook para añadir el script JS al frontend
        add_action( 'wp_enqueue_scripts', [ $this, 'enqueue_checkout_scripts' ] );
    }

    /**
     * Obtener la instancia única de la clase.
     * @return BWI_Checkout
     */
    public static function get_instance() {
        if ( ! isset( self::$instance ) ) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    /**
     * Añade los campos personalizados al formulario de checkout.
     */
    public function add_custom_checkout_fields( $checkout ) {
        echo '<div id="bwi-billing-options"><h3>' . __( 'Documento Tributario', 'bsale-woocommerce-integration' ) . '</h3>';

        woocommerce_form_field( 'bwi_document_type', [
            'type'    => 'radio',
            'class'   => [ 'form-row-wide' ],
            'label'   => __( 'Tipo de Documento', 'bsale-woocommerce-integration' ),
            'options' => [
                'boleta'  => __( 'Boleta', 'bsale-woocommerce-integration' ),
                'factura' => __( 'Factura', 'bsale-woocommerce-integration' ),
            ],
            'default' => 'boleta',
        ], $checkout->get_value( 'bwi_document_type' ) );

        echo '<div id="bwi-factura-fields" style="display:none;">';

        woocommerce_form_field( 'bwi_billing_rut', [
            'type'        => 'text',
            'class'       => [ 'form-row-wide' ],
            'label'       => __( 'RUT Empresa', 'bsale-woocommerce-integration' ),
            'placeholder' => __( 'Ej: 76.123.456-7', 'bsale-woocommerce-integration' ),
            'required'    => true,
        ], $checkout->get_value( 'bwi_billing_rut' ) );

        // Aquí se podrían añadir más campos como Razón Social, Giro, etc.

        echo '</div></div>';
    }

    /**
     * Valida los campos personalizados cuando se envía el formulario.
     */
    public function validate_custom_fields() {
        if ( ! empty( $_POST['bwi_document_type'] ) && 'factura' === $_POST['bwi_document_type'] ) {
            if ( empty( $_POST['bwi_billing_rut'] ) ) {
                wc_add_notice( __( 'Por favor, introduce el RUT de la empresa para la factura.', 'bsale-woocommerce-integration' ), 'error' );
            }
            // Aquí se podría añadir una validación más robusta del formato del RUT.
        }
    }

    /**
     * Guarda los datos de los campos personalizados como metadatos de la orden.
     * @param int $order_id
     */
    public function save_custom_checkout_fields( $order, $data ) {
        // MEJORA HPOS: Usamos $order->update_meta_data() en lugar de la función antigua update_post_meta()
        if ( isset( $_POST['bwi_document_type'] ) ) {
            $order->update_meta_data( '_bwi_document_type', sanitize_text_field( $_POST['bwi_document_type'] ) );
        }
        if ( isset( $_POST['bwi_document_type'] ) && 'factura' === $_POST['bwi_document_type'] && ! empty( $_POST['bwi_billing_rut'] ) ) {
            $order->update_meta_data( '_bwi_billing_rut', sanitize_text_field( $_POST['bwi_billing_rut'] ) );
        }
    }

    /**
     * Muestra los datos personalizados en la página de edición de la orden.
     * @param WC_Order $order
     */
    public function display_custom_fields_in_admin_order( $order ) {
        // MEJORA HPOS: Usamos $order->get_meta() en lugar de la función antigua get_post_meta()
        $document_type = $order->get_meta( '_bwi_document_type' );
        $billing_rut = $order->get_meta( '_bwi_billing_rut' );

        if ( empty($document_type) ) {
            return;
        }

        echo '<div class="order_data_column">';
        echo '<h4>' . esc_html__( 'Datos de Facturación Bsale', 'bsale-woocommerce-integration' ) . '</h4>';
        echo '<p><strong>' . esc_html__( 'Tipo Documento:', 'bsale-woocommerce-integration' ) . '</strong> ' . esc_html( ucfirst( $document_type ) ) . '</p>';

        if ( 'factura' === $document_type && ! empty( $billing_rut ) ) {
            echo '<p><strong>' . esc_html__( 'RUT Empresa:', 'bsale-woocommerce-integration' ) . '</strong> ' . esc_html( $billing_rut ) . '</p>';
        }
        echo '</div>';
    }

    /**
     * Encola el script JS para el checkout.
     */
    public function enqueue_checkout_scripts() {
        // Solo cargar en la página de checkout
        if ( is_checkout() ) {
            wp_enqueue_script(
                'bwi-checkout-script',
                BWI_PLUGIN_URL . 'js/bwi-checkout.js',
                [ 'jquery' ],
                '1.0.0',
                true
            );
        }
    }
}
