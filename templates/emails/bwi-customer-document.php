<?php
/**
 * Plantilla para el correo de Documento Bsale Generado.
 *
 * Este template se puede sobreescribir copiándolo a: yourtheme/woocommerce/emails/bwi-customer-document.php.
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

// Determinar si están activadas las mejoras de email de WC.
$email_improvements_enabled = function_exists('wc_email_improvements_is_enabled') && wc_email_improvements_is_enabled();

/*
 * @hooked WC_Emails::email_header() - Imprime el encabezado del correo.
 */
do_action( 'woocommerce_email_header', $email_heading, $email );

// Obtener los datos del documento desde los metadatos del pedido.
$pdf_url = $order->get_meta('_bwi_document_url');
$doc_number = $order->get_meta('_bwi_document_number');
$doc_type = $order->get_meta('_bwi_document_type') === 'factura' ? 'Factura' : 'Boleta';

?>

<?php // Usamos la misma clase que WC para la introducción. ?>
<?php echo $email_improvements_enabled ? '<div class="email-introduction">' : ''; ?>

<p>
<?php
// Saludo personalizado, igual que en el correo de WC.
if ( ! empty( $order->get_billing_first_name() ) ) {
    /* translators: %s: Customer first name */
    printf( esc_html__( 'Hi %s,', 'woocommerce' ), esc_html( $order->get_billing_first_name() ) );
} else {
    printf( esc_html__( 'Hi,', 'woocommerce' ) );
}
?>
</p>

<p><?php printf( esc_html__( 'Hemos generado tu %s electrónica para el pedido #%s. Puedes descargarla usando el siguiente botón.', 'bsale-woocommerce-integration' ), esc_html( strtolower($doc_type) ), esc_html( $order->get_order_number() ) ); ?></p>

<?php echo $email_improvements_enabled ? '</div>' : ''; ?>


<?php if ( ! empty( $pdf_url ) && ! empty( $doc_number ) ) : ?>
    <table cellspacing="0" cellpadding="10" style="width: 100%; font-family: 'Helvetica Neue', Helvetica, Roboto, Arial, sans-serif; margin-bottom: 40px; border: 1px solid #e5e5e5; border-radius: 3px;" border="1">
        <tbody>
            <tr>
                <td style="text-align: center; padding: 20px;">
                    <a href="<?php echo esc_url( $pdf_url ); ?>" target="_blank" style="font-size: 14px; font-weight: bold; color: #ffffff; background-color: <?php echo esc_attr( get_option( 'woocommerce_email_base_color', '#7f54b3' ) ); ?>; text-decoration: none; padding: 12px 20px; border-radius: 5px; display: inline-block;">
                        <?php printf( esc_html__( 'Descargar %s (Folio %s)', 'bsale-woocommerce-integration' ), esc_html( $doc_type ), esc_html( $doc_number ) ); ?>
                    </a>
                </td>
            </tr>
        </tbody>
    </table>
<?php endif; ?>


<?php
/*
 * @hooked WC_Emails::order_details()
 * @hooked WC_Structured_Data::generate_order_data()
 * @hooked WC_Structured_Data::output_structured_data()
 */
do_action( 'woocommerce_email_order_details', $order, $sent_to_admin, $plain_text, $email );

/*
 * @hooked WC_Emails::customer_details()
 */
do_action( 'woocommerce_email_customer_details', $order, $sent_to_admin, $plain_text, $email );

/**
 * Muestra contenido adicional definido por el usuario.
 */
if ( ! empty( $additional_content ) ) {
    echo $email_improvements_enabled ? '<table border="0" cellpadding="0" cellspacing="0" width="100%"><tr><td class="email-additional-content">' : '';
    echo wp_kses_post( wpautop( wptexturize( $additional_content ) ) );
    echo $email_improvements_enabled ? '</td></tr></table>' : '';
}

/*
 * @hooked WC_Emails::email_footer() - Imprime el pie de página del correo.
 */
do_action( 'woocommerce_email_footer', $email );