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
 * Esta clase sigue el patrón Singleton para asegurar una única instancia y maneja
 * todas las solicitudes HTTP (GET, POST, etc.) hacia la API de Bsale,
 * incluyendo la autenticación y el manejo de errores.
 *
 * @package Bsale_WooCommerce_Integration
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
     * Método central que maneja toda la comunicación con la API de Bsale.
     * Construye y ejecuta la solicitud HTTP usando las funciones de WordPress.
     *
     * @param string $method   El método HTTP (GET, POST, PUT, DELETE).
     * @param string $endpoint El endpoint de la API al que se llama (ej. 'documents.json').
     * @param array  $body     El cuerpo de la solicitud para métodos POST/PUT (opcional).
     * @return object|WP_Error El cuerpo de la respuesta decodificado como un objeto, o un objeto WP_Error en caso de fallo.
     */
    private function request( $method, $endpoint, $body = [] ) {
        if ( empty( $this->access_token ) ) {
            return new WP_Error( 'bwi_api_error', 'El Access Token de Bsale no está configurado en wp-config.php.' );
        }

        $request_url = '';
        if ( preg_match('/^\/v[0-9]+\//', $endpoint) ) {
            $request_url = 'https://api.bsale.io' . $endpoint;
        } else {
            $request_url = $this->api_url . ltrim($endpoint, '/');
        }

        $args = [
            'method'  => strtoupper($method),
            'headers' => [
                'Content-Type' => 'application/json',
                'access_token' => $this->access_token,
            ],
            'timeout' => 60, // timeout a 60 segundos
        ];

        if ( ! empty( $body ) ) {
            $args['body'] = wp_json_encode( $body );
        }

        // --- Lógica de reintentos ---
        $max_retries = 3;
        $retry_delay = 5; // segundos
        for ( $attempt = 1; $attempt <= $max_retries; $attempt++ ) {
            $response = wp_remote_request( $request_url, $args );

            if ( is_wp_error( $response ) ) {
                if ( $attempt < $max_retries ) {
                    sleep( $retry_delay );
                    continue;
                }
                return $response;
            }

            $response_code = wp_remote_retrieve_response_code( $response );
            
            if ( $response_code >= 200 && $response_code < 300 ) {
                break;
            }

            if ( $response_code >= 500 && $attempt < $max_retries ) {
                sleep( $retry_delay );
                continue;
            }
            
            break;
        }

        $response_body = wp_remote_retrieve_body( $response );
        $decoded_body  = json_decode( $response_body );

        if ( $response_code >= 400 ) {
            $error_message = isset($decoded_body->error) ? $decoded_body->error : 'Error desconocido';
            return new WP_Error( 'bwi_api_error', "Error {$response_code}: {$error_message}", [ 'status' => $response_code ] );
        }

        return $decoded_body;
    }
    
     /**
     * Realiza una solicitud GET a un endpoint de la API.
     *
     * @param string $endpoint El endpoint de la API (ej. 'products.json').
     * @param array  $params   Un array de parámetros para añadir a la URL (opcional).
     * @return object|WP_Error La respuesta de la API o un error.
     */
    public function get( $endpoint, $params = [] ) {
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
        $endpoint = 'stocks.json';
        if ( ! empty( $params ) ) {
            $endpoint .= '?' . http_build_query( $params );
        }
        return $this->request( 'GET', $endpoint );
    }
    
    /**
     * Realiza una solicitud POST a un endpoint de la API.
     *
     * @param string $endpoint El endpoint de la API (ej. 'documents.json').
     * @param array  $data     El cuerpo (payload) de la solicitud.
     * @return object|WP_Error La respuesta de la API o un error.
     */
    public function post( $endpoint, $data ) {
        return $this->request( 'POST', $endpoint, $data );
    }
    /**
     * Envía una solicitud para crear una devolución (Nota de Crédito).
     *
     * @param array $data El payload para la devolución.
     * @return mixed|WP_Error
     */
    public function create_return( $data ) {
        return $this->post( 'returns.json', $data );
    }
}
