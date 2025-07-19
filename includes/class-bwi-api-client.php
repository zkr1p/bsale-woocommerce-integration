<?php
/**
 * Cliente para la comunicación con la API de Bsale.
 * Maneja todas las solicitudes HTTP y la autenticación.
 *
 * @package Bsale_WooCommerce_Integration
 */

// Evitar el acceso directo al archivo.
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * BWI_API_Client Class
 */
final class BWI_API_Client {

    /**
     * Instancia única de la clase (Singleton).
     * @var BWI_API_Client
     */
    private static $instance;

    /**
     * El token de acceso para la API de Bsale.
     * @var string
     */
    private $access_token;

    /**
     * URL base de la API de Bsale.
     * @var string
     */
    private $api_url;

    /**
     * Constructor. Carga las opciones y configura el cliente.
     */
    private function __construct() {
        $options = get_option( 'bwi_options' );
        $this->access_token = defined('BWI_ACCESS_TOKEN') ? BWI_ACCESS_TOKEN : '';
        
        // Usamos la URL actualizada y oficial de la API.
        $this->api_url = 'https://api.bsale.io/v1/';
    }

    /**
     * Obtener la instancia única de la clase.
     * @return BWI_API_Client
     */
    public static function get_instance() {
        if ( ! isset( self::$instance ) ) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    /**
     * Realiza una solicitud a la API de Bsale.
     *
     * @param string $method   El método HTTP (GET, POST, PUT, DELETE).
     * @param string $endpoint El endpoint de la API al que se llama (ej. 'documents.json').
     * @param array  $body     El cuerpo de la solicitud para POST/PUT.
     * @return mixed|WP_Error  El cuerpo de la respuesta decodificado o un objeto WP_Error en caso de fallo.
     */
    private function request( $method, $endpoint, $body = [] ) {
        if ( empty( $this->access_token ) ) {
            return new WP_Error( 'bwi_api_error', 'El Access Token de Bsale no está configurado.' );
        }

        $request_url = $this->api_url . $endpoint;

        $args = [
            'method'  => $method,
            'headers' => [
                'Content-Type' => 'application/json',
                'access_token' => $this->access_token,
            ],
            'timeout' => 30, // Aumentamos el timeout a 30 segundos para operaciones largas.
        ];

        if ( ! empty( $body ) && in_array( strtoupper($method), ['POST', 'PUT'] ) ) {
            $args['body'] = wp_json_encode( $body );
        }

        // Usamos la API HTTP de WordPress para realizar la solicitud.
        $response = wp_remote_request( $request_url, $args );

        // Manejo de errores de conexión de WordPress.
        if ( is_wp_error( $response ) ) {
            return $response;
        }

        $response_code = wp_remote_retrieve_response_code( $response );
        $response_body = wp_remote_retrieve_body( $response );
        $decoded_body  = json_decode( $response_body );

        // Manejo de errores devueltos por la API de Bsale (ej. 4xx, 5xx).
        if ( $response_code >= 400 ) {
            $error_message = 'Error desconocido de la API de Bsale.';
            if ( isset( $decoded_body->error ) ) {
                 $error_message = is_string($decoded_body->error) ? $decoded_body->error : 'La API devolvió un error inesperado.';
            }
            return new WP_Error( 'bwi_api_error', $error_message, [ 'status' => $response_code ] );
        }

        return $decoded_body;
    }

    /**
     * Método público para obtener productos desde Bsale.
     *
     * @param array $params Parámetros de consulta (ej. limit, offset).
     * @return mixed|WP_Error
     */
    public function get_products( $params = [] ) {
        $endpoint = 'products.json';
        if ( ! empty( $params ) ) {
            $endpoint .= '?' . http_build_query( $params );
        }
        return $this->request( 'GET', $endpoint );
    }

    /**
     * Método público para crear un documento en Bsale.
     *
     * @param array $data Los datos del documento a crear.
     * @return mixed|WP_Error
     */
    public function create_document( $data ) {
        return $this->request( 'POST', 'documents.json', $data );
    }

    /**
     * Método público para obtener el stock de variantes.
     *
     * @param array $params Parámetros de consulta (ej. variantid, officeid).
     * @return mixed|WP_Error
     */
    public function get_stock( $params = [] ) {
        // Importante: El endpoint de stock es v2 según la documentación actualizada.
        $endpoint = 'stocks.json';
        if ( ! empty( $params ) ) {
            $endpoint .= '?' . http_build_query( $params );
        }
        // Temporalmente usamos el v1, ya que v2 no está en la URL base. Ajustar si es necesario.
        return $this->request( 'GET', $endpoint );
    }
    
    // --- Aquí se pueden añadir más métodos públicos para otros endpoints ---
    // ej. get_clients(), get_document_types(), etc.

}
