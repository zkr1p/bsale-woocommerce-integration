<?php
/**
 * Clase para el correo electrónico personalizado que envía el documento de Bsale al cliente.
 *
 * @package Bsale_WooCommerce_Integration
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

if ( ! class_exists( 'BWI_Email_Customer_Document' ) ) :

    /**
     * @class BWI_Email_Customer_Document
     * @extends WC_Email
     */
    class BWI_Email_Customer_Document extends WC_Email {

        /**
         * Constructor.
         */
        public function __construct() {
            $this->id             = 'bwi_customer_document';
            $this->customer_email = true;
            $this->title          = __( 'Documento Bsale Generado', 'bsale-woocommerce-integration' );
            $this->description    = __( 'Este correo se envía al cliente cuando su boleta o factura de Bsale ha sido generada exitosamente.', 'bsale-woocommerce-integration' );
            
            $this->template_html  = 'emails/bwi-customer-document.php';
            $this->template_plain = 'emails/plain/bwi-customer-document.php';
            $this->template_base  = BWI_PLUGIN_PATH . 'templates/';

            // Triggers para este email.
            add_action( 'bwi_send_document_email_notification', [ $this, 'trigger' ], 10, 1 );

            // Llamar al constructor padre
            parent::__construct();
        }

        /**
         * Dispara el envío del correo.
         *
         * @param int $order_id El ID del pedido.
         */
        public function trigger( $order_id ) {
            $this->object = wc_get_order( $order_id );

            if ( ! $this->object ) {
                return;
            }
            
            $this->recipient = $this->object->get_billing_email();
            
            // Reemplazar placeholders
            $this->placeholders['{order_date}']   = wc_format_datetime( $this->object->get_date_created() );
            $this->placeholders['{order_number}'] = $this->object->get_order_number();

            if ( $this->is_enabled() && $this->get_recipient() ) {
                $this->send( $this->get_recipient(), $this->get_subject(), $this->get_content(), $this->get_headers(), $this->get_attachments() );
            }
        }

        /**
         * Obtiene el contenido HTML del correo.
         *
         * @return string
         */
        public function get_content_html() {
            return wc_get_template_html(
                $this->template_html,
                [
                    'order'         => $this->object,
                    'email_heading' => $this->get_heading(),
                    'sent_to_admin' => false,
                    'plain_text'    => false,
                    'email'         => $this,
                ],
                '',
                $this->template_base
            );
        }

        /**
         * Obtiene el contenido en texto plano del correo.
         *
         * @return string
         */
        public function get_content_plain() {
            return wc_get_template_html(
                $this->template_plain,
                [
                    'order'         => $this->object,
                    'email_heading' => $this->get_heading(),
                    'sent_to_admin' => false,
                    'plain_text'    => true,
                    'email'         => $this,
                ],
                '',
                $this->template_base
            );
        }

        /**
         * Define los campos del formulario de ajustes para este correo.
         */
        public function init_form_fields() {
            $this->form_fields = [
                'enabled' => [
                    'title'   => __( 'Activar/Desactivar', 'woocommerce' ),
                    'type'    => 'checkbox',
                    'label'   => __( 'Activar esta notificación por correo electrónico', 'woocommerce' ),
                    'default' => 'yes',
                ],
                'subject' => [
                    'title'       => __( 'Asunto', 'woocommerce' ),
                    'type'        => 'text',
                    'description' => sprintf( __( 'Placeholders disponibles: %s', 'woocommerce' ), '<code>{site_title}, {order_date}, {order_number}</code>' ),
                    'placeholder' => $this->get_default_subject(),
                    'default'     => '',
                ],
                'heading' => [
                    'title'       => __( 'Encabezado del correo', 'woocommerce' ),
                    'type'        => 'text',
                    'description' => sprintf( __( 'Placeholders disponibles: %s', 'woocommerce' ), '<code>{site_title}, {order_date}, {order_number}</code>' ),
                    'placeholder' => $this->get_default_heading(),
                    'default'     => '',
                ],
                'email_type' => [
                    'title'       => __( 'Tipo de correo', 'woocommerce' ),
                    'type'        => 'select',
                    'description' => __( 'Elija qué formato de correo electrónico enviar.', 'woocommerce' ),
                    'default'     => 'html',
                    'class'       => 'email_type wc-enhanced-select',
                    'options'     => $this->get_email_type_options(),
                ],
            ];
        }

        /**
         * Asunto por defecto.
         *
         * @return string
         */
        public function get_default_subject() {
            return __( '¡Hemos recibido tu pedido en Ovejavasca!', 'bsale-woocommerce-integration' );
        }

        /**
         * Encabezado por defecto.
         *
         * @return string
         */
        public function get_default_heading() {
            return __( 'Gracias por tu pedido', 'bsale-woocommerce-integration' );
        }
    }

endif;

return new BWI_Email_Customer_Document();