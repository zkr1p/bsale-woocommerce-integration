<?php
/**
 * Clase para manejar el panel de administración, ajustes y configuraciones del plugin.
 *
 * @package Bsale_WooCommerce_Integration
 */

// Evitar el acceso directo al archivo.
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * BWI_Admin Class
 */
final class BWI_Admin {

    /**
     * Instancia única de la clase.
     * @var BWI_Admin
     */
    private static $instance;

    /**
     * Constructor.
     */
    private function __construct() {
        add_action( 'admin_menu', [ $this, 'add_admin_menu' ] );
        add_action( 'admin_init', [ $this, 'register_settings' ] );
        // Hook para añadir scripts a nuestra página de admin
        add_action( 'admin_enqueue_scripts', [ $this, 'enqueue_admin_scripts' ] );
    }

    /**
     * Obtener la instancia única de la clase.
     * @return BWI_Admin
     */
    public static function get_instance() {
        if ( ! isset( self::$instance ) ) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    /**
     * Añade la página de opciones del plugin al menú de administración de WordPress.
     */
    public function add_admin_menu() {
        add_options_page(
            'Integración Bsale y WooCommerce', // Título de la página
            'Bsale Integración',               // Título del menú
            'manage_options',                  // Capacidad requerida para ver el menú
            'bwi_settings',                    // Slug del menú
            [ $this, 'create_settings_page' ]   // Función que renderiza la página
        );
    }

    /**
     * Crea el contenido HTML de la página de ajustes.
     */
    public function create_settings_page() {
        ?>
        <div class="wrap">
            <h1><?php echo esc_html( get_admin_page_title() ); ?></h1>
            <p><?php _e( 'Configura los parámetros para la integración entre Bsale y WooCommerce.', 'bsale-woocommerce-integration' ); ?></p>
            
            <form action="options.php" method="post">
                <?php
                // Imprime los campos de seguridad de WordPress
                settings_fields( 'bwi_settings_group' );
                // Imprime las secciones y campos registrados
                do_settings_sections( 'bwi_settings' );
                // Imprime el botón de guardar
                submit_button( 'Guardar Cambios' );
                ?>
            </form>
        </div>
        <?php
    }

    /**
     * Registra las secciones y campos de ajustes usando la API de Ajustes de WordPress.
     */
    public function register_settings() {
        register_setting( 'bwi_settings_group', 'bwi_options', [ $this, 'sanitize_options' ] );

        // --- SECCIÓN: Configuración de la API ---
        add_settings_section( 'bwi_api_settings_section', 'Configuración de Credenciales (wp-config.php)', null, 'bwi_settings' );
        add_settings_field( 'bwi_access_token_info', 'Access Token', [ $this, 'render_access_token_info_field' ], 'bwi_settings', 'bwi_api_settings_section' );
        add_settings_field( 'bwi_webhook_secret_info', 'Secreto del Webhook', [ $this, 'render_webhook_secret_info_field' ], 'bwi_settings', 'bwi_api_settings_section' );

        // --- SECCIÓN: Sincronización de Productos ---
        add_settings_section( 'bwi_sync_settings_section', 'Sincronización de Productos', null, 'bwi_settings' );
        add_settings_field( 'bwi_office_id_stock', 'Sucursal para Stock', [ $this, 'render_office_id_stock_field' ], 'bwi_settings', 'bwi_sync_settings_section' );
        // NUEVO CAMPO: Lista de Precios
        add_settings_field( 'bwi_price_list_id', 'Lista de Precios', [ $this, 'render_price_list_id_field' ], 'bwi_settings', 'bwi_sync_settings_section' );
        
        // --- SECCIÓN: Facturación ---
        add_settings_section( 'bwi_billing_settings_section', 'Facturación y Documentos', null, 'bwi_settings' );
        add_settings_field( 'bwi_enable_billing', 'Activar Creación de Documentos', [ $this, 'render_enable_billing_field' ], 'bwi_settings', 'bwi_billing_settings_section' );
        add_settings_field( 'bwi_trigger_status', 'Estado para Generar Documento', [ $this, 'render_trigger_status_field' ], 'bwi_settings', 'bwi_billing_settings_section' );
        add_settings_field( 'bwi_boleta_codesii', 'Código SII para Boletas', [ $this, 'render_boleta_codesii_field' ], 'bwi_settings', 'bwi_billing_settings_section' );
        add_settings_field( 'bwi_factura_codesii', 'Código SII para Facturas', [ $this, 'render_factura_codesii_field' ], 'bwi_settings', 'bwi_billing_settings_section' );
    
        // --- SECCIÓN: Webhooks ---
        add_settings_section( 'bwi_webhooks_section', 'Configuración de Webhooks', null, 'bwi_settings' );
        add_settings_field( 'bwi_webhook_url', 'URL para Webhooks', [ $this, 'render_webhook_url_field' ], 'bwi_settings', 'bwi_webhooks_section' );

        // --- SECCIÓN: Acciones Manuales ---
        add_settings_section( 'bwi_manual_actions_section', 'Acciones Manuales', null, 'bwi_settings' );
        add_settings_field( 'bwi_manual_sync_button', 'Sincronización Manual', [ $this, 'render_manual_sync_button_field' ], 'bwi_settings', 'bwi_manual_actions_section' );
    }

    /**
     * Función de callback para el texto de las secciones.
     */ /*
    public function section_callback( $args ) {
        // ... (código existente de la función)
        switch ( $args['id'] ) {
            case 'bwi_api_settings_section':
                echo '<p>Introduce tus credenciales de la API de Bsale.</p>';
                break;
            case 'bwi_sync_settings_section':
                echo '<p>Configura cómo se sincronizará el stock y los precios desde Bsale a WooCommerce.</p>';
                break;
            case 'bwi_billing_settings_section':
                echo '<p>Configura la creación automática de documentos (boletas/facturas) en Bsale cuando se crea un pedido en WooCommerce.</p>';
                break;
            case 'bwi_manual_actions_section':
                echo '<p>Ejecuta acciones de sincronización de forma manual. Útil para la configuración inicial o para forzar una actualización.</p>';
                break;
        }
    }*/

    /**
     * Renderiza los campos de los ajustes.
     */
    public function render_access_token_info_field() {
        $is_defined = defined( 'BWI_ACCESS_TOKEN' ) && BWI_ACCESS_TOKEN;
        $status_message = $is_defined
            ? '<span style="color: green; font-weight: bold;">Definido correctamente.</span>'
            : '<span style="color: red; font-weight: bold;">No definido.</span>';
        
        echo '<p>Por seguridad, el Access Token debe ser definido en su archivo <strong>wp-config.php</strong>.</p>';
        echo '<p>Añada la siguiente línea: <code>define(\'BWI_ACCESS_TOKEN\', \'su_token_de_acceso_aqui\');</code></p>';
        echo "<p><strong>Estado actual:</strong> {$status_message}</p>";
    }

    public function render_webhook_secret_info_field() {
        $is_defined = defined( 'BWI_WEBHOOK_SECRET' ) && BWI_WEBHOOK_SECRET;
        $status_message = $is_defined
            ? '<span style="color: green; font-weight: bold;">Definido correctamente.</span>'
            : '<span style="color: red; font-weight: bold;">No definido.</span>';

        echo '<p>Por seguridad, el secreto para validar los Webhooks debe ser definido en su archivo <strong>wp-config.php</strong>.</p>';
        echo '<p>Genere una cadena aleatoria larga y segura y añada la siguiente línea: <code>define(\'BWI_WEBHOOK_SECRET\', \'su_secreto_aleatorio_aqui\');</code></p>';
        echo "<p><strong>Estado actual:</strong> {$status_message}</p>";
    }

    public function render_webhook_url_field() {
        $secret = defined('BWI_WEBHOOK_SECRET') ? BWI_WEBHOOK_SECRET : '';
        if (empty($secret)) {
            echo '<p class="description" style="color: red;">Defina el secreto del Webhook en wp-config.php para generar la URL.</p>';
            return;
        }
        $webhook_url = add_query_arg( 'token', $secret, get_rest_url( null, 'bwi/v1/webhook' ) );
        echo '<input type="text" value="' . esc_url( $webhook_url ) . '" readonly class="large-text code">';
        echo '<p class="description">Copia esta URL y pégala en la configuración de webhooks de Bsale.</p>';
    }
    public function render_access_token_field() { 
        $options = get_option( 'bwi_options' );
        $value = isset( $options['access_token'] ) ? $options['access_token'] : '';
        echo '<input type="password" name="bwi_options[access_token]" value="' . esc_attr( $value ) . '" class="regular-text">';
        echo '<p class="description">Tu token de acceso personal de la API de Bsale.</p>'; }
    public function render_enable_stock_sync_field() { 
        $options = get_option( 'bwi_options' );
        $checked = isset( $options['enable_stock_sync'] ) ? checked( 1, $options['enable_stock_sync'], false ) : '';
        echo '<input type="checkbox" name="bwi_options[enable_stock_sync]" value="1" ' . $checked . '>';
        echo '<label>Activar para que el stock de WooCommerce se actualice desde Bsale.</label>'; }
    public function render_office_id_stock_field() {
        $options = get_option('bwi_options');
        $selected_office_id = isset($options['office_id_stock']) ? $options['office_id_stock'] : '';

        // MEJORA DE RENDIMIENTO CON TRANSIENTS
        $offices = get_transient('bwi_offices_list');
        if ( false === $offices ) {
            $api_client = BWI_API_Client::get_instance();
            $response = $api_client->get('offices.json');
            
            $offices = [];
            if ( !is_wp_error($response) && !empty($response->items) ) {
                $offices = $response->items;
                set_transient( 'bwi_offices_list', $offices, 12 * HOUR_IN_SECONDS );
            }
        }

        echo '<select name="bwi_options[office_id_stock]">';
        if ( !empty($offices) ) {
            echo '<option value="">-- Seleccione una sucursal --</option>';
            foreach ( $offices as $office ) {
                printf(
                    '<option value="%d" %s>%s</option>',
                    esc_attr($office->id),
                    selected($selected_office_id, $office->id, false),
                    esc_html($office->name)
                );
            }
        } else {
            echo '<option value="">No se pudieron cargar las sucursales. Verifique el Access Token.</option>';
        }
        echo '</select>';
        echo '<p class="description">Seleccione la sucursal de Bsale. La lista se actualiza desde la API cada 12 horas.</p>';
    }
    /**
     * Renderiza el campo para seleccionar la Lista de Precios.
     */
    public function render_price_list_id_field() {
        $options = get_option('bwi_options');
        $selected_list_id = isset($options['price_list_id']) ? $options['price_list_id'] : '';

        $price_lists = get_transient('bwi_active_price_lists'); // Cambiamos el nombre del transient
        if ( false === $price_lists ) {
            $api_client = BWI_API_Client::get_instance();
            
            // MEJORA: Añadimos el parámetro 'state' => 0 para obtener solo las listas activas.
            $response = $api_client->get('price_lists.json', ['state' => 0]);
            
            $price_lists = [];
            if ( !is_wp_error($response) && !empty($response->items) ) {
                $price_lists = $response->items;
                set_transient( 'bwi_active_price_lists', $price_lists, 12 * HOUR_IN_SECONDS );
            }
        }

        echo '<select name="bwi_options[price_list_id]">';
        if ( !empty($price_lists) ) {
            echo '<option value="">-- No sincronizar precios --</option>';
            foreach ( $price_lists as $list ) {
                printf(
                    '<option value="%d" %s>%s</option>',
                    esc_attr($list->id),
                    selected($selected_list_id, $list->id, false),
                    esc_html($list->name)
                );
            }
        } else {
            echo '<option value="">No se pudieron cargar las listas de precios activas.</option>';
        }
        echo '</select>';
        echo '<p class="description">Seleccione la lista de precios de Bsale que se usará para actualizar los precios en WooCommerce.</p>';
    }
    public function render_enable_billing_field() {
        $options = get_option( 'bwi_options' );
        $checked = isset( $options['enable_billing'] ) ? checked( 1, $options['enable_billing'], false ) : '';
        echo '<input type="checkbox" name="bwi_options[enable_billing]" value="1" ' . $checked . '>';
        echo '<label>Activar para crear boletas/facturas en Bsale automáticamente.</label>'; }
    public function render_trigger_status_field() { 
        $options = get_option( 'bwi_options' );
        $current_status = isset( $options['trigger_status'] ) ? $options['trigger_status'] : 'processing';
        $wc_statuses = wc_get_order_statuses();
        
        echo '<select name="bwi_options[trigger_status]">';
        foreach ( $wc_statuses as $status_key => $status_name ) {
            // wc_get_order_statuses() devuelve las claves con el prefijo "wc-", lo quitamos para guardar.
            $status_key_clean = str_replace( 'wc-', '', $status_key );
            echo '<option value="' . esc_attr( $status_key_clean ) . '" ' . selected( $current_status, $status_key_clean, false ) . '>' . esc_html( $status_name ) . '</option>';
        }
        echo '</select>';
        echo '<p class="description">El documento en Bsale se creará cuando el pedido de WooCommerce alcance este estado.</p>';}
    public function render_default_document_type_field() { 
        $options = get_option( 'bwi_options' );
        $value = isset( $options['default_document_type'] ) ? $options['default_document_type'] : '';
        echo '<input type="text" name="bwi_options[default_document_type]" value="' . esc_attr( $value ) . '" class="regular-text">';
        echo '<p class="description">Introduce el ID del tipo de documento de Bsale para las Boletas (ej: 1).</p>'; }
    
    
        /**
     * Renderiza el botón para la sincronización manual.
     */
    public function render_manual_sync_button_field() {
        ?>
        <button type="button" id="bwi-manual-sync-button" class="button button-primary">
            Sincronizar Productos y Stock Ahora
        </button>
        <span class="spinner" style="float: none; margin-top: 4px;"></span>
        <p id="bwi-sync-status" style="display:inline-block; margin-left:10px;"></p>
        <p class="description">
            Haz clic para importar todos los productos y actualizar el stock desde Bsale.
            Este proceso puede tardar varios minutos dependiendo de la cantidad de productos.
        </p>
        <?php }
    public function render_boleta_codesii_field() {
        $options = get_option( 'bwi_options' );
        $value = isset( $options['boleta_codesii'] ) ? $options['boleta_codesii'] : '39';
        echo '<input type="number" name="bwi_options[boleta_codesii]" value="' . esc_attr( $value ) . '" class="small-text">';
        echo '<p class="description">Introduce el código SII para Boletas Electrónicas. Generalmente es <strong>39</strong>.</p>'; }

    public function render_factura_codesii_field() {
        $options = get_option( 'bwi_options' );
        $value = isset( $options['factura_codesii'] ) ? $options['factura_codesii'] : '33';
        echo '<input type="number" name="bwi_options[factura_codesii]" value="' . esc_attr( $value ) . '" class="small-text">';
        echo '<p class="description">Introduce el código SII para Facturas Electrónicas. Generalmente es <strong>33</strong>.</p>'; }
    
    public function sanitize_options( $input ) {
        $new_input = [];
        // Las credenciales ya no se guardan aquí.
        if ( isset( $input['office_id_stock'] ) ) $new_input['office_id_stock'] = absint( $input['office_id_stock'] );
        if ( isset( $input['price_list_id'] ) ) $new_input['price_list_id'] = absint( $input['price_list_id'] );
        if ( isset( $input['enable_billing'] ) ) $new_input['enable_billing'] = absint( $input['enable_billing'] );
        if ( isset( $input['trigger_status'] ) ) $new_input['trigger_status'] = sanitize_text_field( $input['trigger_status'] );
        if ( isset( $input['boleta_codesii'] ) ) $new_input['boleta_codesii'] = absint( $input['boleta_codesii'] );
        if ( isset( $input['factura_codesii'] ) ) $new_input['factura_codesii'] = absint( $input['factura_codesii'] );
        
        return $new_input;
    }
    /**
     * Encola el script JS para la página de administración.
     */
    public function enqueue_admin_scripts( $hook ) {
        // Solo cargar el script en nuestra página de ajustes
        if ( 'settings_page_bwi_settings' !== $hook ) {
            return;
        }

        // Crear un nuevo archivo js/bwi-admin.js y pegar el código JS allí
        wp_enqueue_script(
            'bwi-admin-script',
            BWI_PLUGIN_URL . 'js/bwi-admin.js',
            [ 'jquery' ], // Dependencia de jQuery
            '1.0.0',
            true // Cargar en el footer
        );

        // Pasar datos de PHP a JavaScript de forma segura
        wp_localize_script(
            'bwi-admin-script',
            'bwi_ajax_object',
            [
                'ajax_url' => admin_url( 'admin-ajax.php' ),
                'nonce'    => wp_create_nonce( 'bwi_manual_sync_nonce' ),
            ]
        );
    }
}
