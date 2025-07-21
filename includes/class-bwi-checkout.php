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
        add_action( 'wp_enqueue_scripts', [ $this, 'enqueue_checkout_assets' ] );

        // Hook para modificar los campos y títulos del checkout.
        add_filter( 'woocommerce_checkout_fields', [ $this, 'customize_checkout_fields' ] );
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
     * Modifica los campos y títulos del checkout.
     *
     * @param array $fields Campos originales del checkout.
     * @return array Campos modificados.
     */
    public function customize_checkout_fields( $fields ) {
        // MEJORA: Cambiar el título de la sección de facturación.
        if ( isset( $fields['billing']['billing_first_name'] ) ) { // Verificamos que la sección exista.
            $fields['billing']['billing_address_1']['label'] = __('Dirección de Envío', 'bsale-woocommerce-integration');
        }

        return $fields;
    }

    /**
     * Añade los campos personalizados al formulario de checkout.
     */
    public function add_custom_checkout_fields( $checkout ) {
        echo '<div id="bwi-billing-options-wrapper"><h3>' . esc_html__( 'Documento Tributario', 'bsale-woocommerce-integration' ) . '</h3>';
        // MEJORA UX/UI: Usamos un campo 'select' en lugar de 'radio'
        woocommerce_form_field( 'bwi_document_type', [
            'type'    => 'select',
            'class'   => [ 'form-row-wide' ],
            'label'   => esc_html__( '¿Qué tipo de documento necesitas?', 'bsale-woocommerce-integration' ),
            'options' => [
                'boleta'  => esc_html__( 'Boleta', 'bsale-woocommerce-integration' ),
                'factura' => esc_html__( 'Factura', 'bsale-woocommerce-integration' ),
            ],
            'default' => 'boleta',
        ], $checkout->get_value( 'bwi_document_type' ) );

        // Contenedor para los campos de factura
        echo '<div id="bwi-factura-fields" style="display:none;">';

        // Campo para Razón Social
        woocommerce_form_field( 'bwi_billing_company_name', [
            'type'        => 'text',
            'class'       => [ 'form-row-wide' ],
            'label'       => esc_html__( 'Razón Social', 'bsale-woocommerce-integration' ),
            'placeholder' => esc_html__( 'Nombre legal de la empresa', 'bsale-woocommerce-integration' ),
            'required'    => true,
        ], $checkout->get_value( 'bwi_billing_company_name' ) );

        // Campo para RUT
        woocommerce_form_field( 'bwi_billing_rut', [
            'type'        => 'text',
            'class'       => [ 'form-row-wide' ],
            'label'       => esc_html__( 'RUT Empresa', 'bsale-woocommerce-integration' ),
            'placeholder' => esc_html__( 'Ej: 76.123.456-7', 'bsale-woocommerce-integration' ),
            'required'    => true,
        ], $checkout->get_value( 'bwi_billing_rut' ) );
        
        // Campo para Giro
        woocommerce_form_field( 'bwi_billing_activity', [
            'type'        => 'text',
            'class'       => [ 'form-row-wide' ],
            'label'       => esc_html__( 'Giro', 'bsale-woocommerce-integration' ),
            'placeholder' => esc_html__( 'Ej: Servicios Informáticos', 'bsale-woocommerce-integration' ),
            'required'    => true,
        ], $checkout->get_value( 'bwi_billing_activity' ) );

        echo '<p class="form-row form-row-wide" style="font-weight:bold; margin-top:1em;">' . esc_html__( 'Dirección de Facturación', 'bsale-woocommerce-integration' ) . '</p>';

        woocommerce_form_field( 'bwi_fiscal_address', [
            'type'        => 'text',
            'class'       => [ 'form-row-wide' ],
            'label'       => esc_html__( 'Dirección Fiscal', 'bsale-woocommerce-integration' ),
            'placeholder' => esc_html__( 'Calle y número', 'bsale-woocommerce-integration' ),
            'required'    => true,
        ], $checkout->get_value( 'bwi_fiscal_address' ) );
        
        woocommerce_form_field( 'bwi_fiscal_city', [
            'type'        => 'text',
            'class'       => [ 'form-row-first' ],
            'label'       => esc_html__( 'Ciudad', 'bsale-woocommerce-integration' ),
            'required'    => true,
        ], $checkout->get_value( 'bwi_fiscal_city' ) );

        woocommerce_form_field( 'bwi_fiscal_municipality', [
            'type'        => 'text',
            'class'       => [ 'form-row-last' ],
            'label'       => esc_html__( 'Comuna', 'bsale-woocommerce-integration' ),
            'required'    => true,
        ], $checkout->get_value( 'bwi_fiscal_municipality' ) );

        echo '</div></div>';
    }

    /**
     * Valida los campos personalizados cuando se envía el formulario.
     */
    public function validate_custom_fields() {
        if ( isset($_POST['bwi_document_type']) && 'factura' === $_POST['bwi_document_type'] ) {
            if ( empty( $_POST['bwi_billing_company_name'] ) ) wc_add_notice( 'La <strong>Razón Social</strong> es un campo requerido para la factura.', 'error' );
            if ( empty( $_POST['bwi_billing_rut'] ) ) wc_add_notice( 'El <strong>RUT</strong> es un campo requerido para la factura.', 'error' );
            if ( empty( $_POST['bwi_billing_activity'] ) ) wc_add_notice( 'El <strong>Giro</strong> es un campo requerido para la factura.', 'error' );
            if ( empty( $_POST['bwi_fiscal_address'] ) ) wc_add_notice( 'La <strong>Dirección Fiscal</strong> es un campo requerido para la factura.', 'error' );
            if ( empty( $_POST['bwi_fiscal_city'] ) ) wc_add_notice( 'La <strong>Ciudad</strong> de facturación es un campo requerido.', 'error' );
            if ( empty( $_POST['bwi_fiscal_municipality'] ) ) wc_add_notice( 'La <strong>Comuna</strong> de facturación es un campo requerido.', 'error' );
        }
    }

    /**
     * Guarda los datos de los campos personalizados como metadatos de la orden.
     * @param int $order_id
     */
    public function save_custom_checkout_fields( $order, $data ) {
        if ( isset( $_POST['bwi_document_type'] ) ) {
            $order->update_meta_data( '_bwi_document_type', sanitize_text_field( $_POST['bwi_document_type'] ) );
        }
        if ( isset( $_POST['bwi_document_type'] ) && 'factura' === $_POST['bwi_document_type'] ) {
            if ( ! empty( $_POST['bwi_billing_company_name'] ) ) $order->update_meta_data( '_bwi_billing_company_name', sanitize_text_field( $_POST['bwi_billing_company_name'] ) );
            if ( ! empty( $_POST['bwi_billing_rut'] ) ) $order->update_meta_data( '_bwi_billing_rut', sanitize_text_field( $_POST['bwi_billing_rut'] ) );
            if ( ! empty( $_POST['bwi_billing_activity'] ) ) $order->update_meta_data( '_bwi_billing_activity', sanitize_text_field( $_POST['bwi_billing_activity'] ) );
            if ( ! empty( $_POST['bwi_fiscal_address'] ) ) $order->update_meta_data( '_bwi_fiscal_address', sanitize_text_field( $_POST['bwi_fiscal_address'] ) );
            if ( ! empty( $_POST['bwi_fiscal_city'] ) ) $order->update_meta_data( '_bwi_fiscal_city', sanitize_text_field( $_POST['bwi_fiscal_city'] ) );
            if ( ! empty( $_POST['bwi_fiscal_municipality'] ) ) $order->update_meta_data( '_bwi_fiscal_municipality', sanitize_text_field( $_POST['bwi_fiscal_municipality'] ) );
        }
    }

    /**
     * Muestra los datos personalizados en la página de edición de la orden.
     * @param WC_Order $order
     */
    public function display_custom_fields_in_admin_order( $order ) {
        $document_type = $order->get_meta( '_bwi_document_type' );
        if ( empty($document_type) ) return;

        echo '<div class="order_data_column">';
        echo '<h4>' . esc_html__( 'Datos de Facturación Bsale', 'bsale-woocommerce-integration' ) . '</h4>';
        echo '<p><strong>' . esc_html__( 'Tipo Documento:', 'bsale-woocommerce-integration' ) . '</strong> ' . esc_html( ucfirst( $document_type ) ) . '</p>';

        if ( 'factura' === $document_type ) {
            echo '<p><strong>' . esc_html__( 'Razón Social:', 'bsale-woocommerce-integration' ) . '</strong> ' . esc_html( $order->get_meta( '_bwi_billing_company_name' ) ) . '</p>';
            echo '<p><strong>' . esc_html__( 'RUT Empresa:', 'bsale-woocommerce-integration' ) . '</strong> ' . esc_html( $order->get_meta( '_bwi_billing_rut' ) ) . '</p>';
            echo '<p><strong>' . esc_html__( 'Giro:', 'bsale-woocommerce-integration' ) . '</strong> ' . esc_html( $order->get_meta( '_bwi_billing_activity' ) ) . '</p>';
            echo '<p><strong>' . esc_html__( 'Dirección Fiscal:', 'bsale-woocommerce-integration' ) . '</strong> ' . esc_html( $order->get_meta( '_bwi_fiscal_address' ) ) . '</p>';
            echo '<p><strong>' . esc_html__( 'Ciudad Fiscal:', 'bsale-woocommerce-integration' ) . '</strong> ' . esc_html( $order->get_meta( '_bwi_fiscal_city' ) ) . '</p>';
            echo '<p><strong>' . esc_html__( 'Comuna Fiscal:', 'bsale-woocommerce-integration' ) . '</strong> ' . esc_html( $order->get_meta( '_bwi_fiscal_municipality' ) ) . '</p>';
        }
        echo '</div>';
    }

    /**
     * Encola el script JS para el checkout.
     */
    public function enqueue_checkout_assets() {
        // Solo cargar en la página de checkout.
        if ( is_checkout() ) {
            // Encolar el archivo JavaScript.
            wp_enqueue_script(
                'bwi-checkout-script',
                BWI_PLUGIN_URL . 'assets/js/bwi-checkout.js', 
                [ 'jquery' ],
                '2.8.0',
                true
            );

            // Encolar el archivo CSS.
            wp_enqueue_style(
                'bwi-checkout-style',
                BWI_PLUGIN_URL . 'assets/css/bwi-checkout.css',
                [], // Sin dependencias de otros CSS
                '2.9.0'
            );
        }
    }
}
