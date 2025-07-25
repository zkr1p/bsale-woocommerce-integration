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
    private $access_token; // Guardamos el token para usarlo en la clase.

    /**
     * Constructor.
     */
    private function __construct() {
        $this->access_token = defined( 'BWI_ACCESS_TOKEN' ) ? BWI_ACCESS_TOKEN : '';
        add_action( 'admin_menu', [ $this, 'add_admin_menu' ] );
        add_action( 'admin_init', [ $this, 'register_settings' ] );
        // Hook para añadir scripts a nuestra página de admin
        add_action( 'admin_enqueue_scripts', [ $this, 'enqueue_admin_scripts' ] );
        // Hook para mostrar la notificación de error en todo el admin.
        add_action( 'admin_notices', [ $this, 'display_api_error_notice' ] );
        // Hook que se activa al cargar nuestra página de ajustes para realizar la prueba.
        add_action( 'load-settings_page_bwi_settings', [ $this, 'check_api_credentials' ] );
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
    /**
     * Crea el contenido HTML de la página de ajustes con pestañas.
     */
    public function create_settings_page() {
        ?>
        <div class="wrap">
            <h1><?php echo esc_html( get_admin_page_title() ); ?></h1>
            <p><?php _e( 'Configura los parámetros para la integración entre Bsale y WooCommerce.', 'bsale-woocommerce-integration' ); ?></p>
            
            <?php
            $active_tab = isset( $_GET['tab'] ) ? $_GET['tab'] : 'settings';
            ?>
            
            <h2 class="nav-tab-wrapper">
                <a href="?page=bwi_settings&tab=settings" class="nav-tab <?php echo $active_tab == 'settings' ? 'nav-tab-active' : ''; ?>">Ajustes</a>
                <a href="?page=bwi_settings&tab=logs" class="nav-tab <?php echo $active_tab == 'logs' ? 'nav-tab-active' : ''; ?>">Logs</a>
            </h2>
            
            <?php if ( $active_tab == 'settings' ) : ?>
                <form action="options.php" method="post">
                    <?php
                    settings_fields( 'bwi_settings_group' );
                    do_settings_sections( 'bwi_settings' );
                    submit_button( 'Guardar Cambios' );
                    ?>
                </form>
            <?php else : // Pestaña de Logs ?>
                <div id="bwi-logs-viewer">
                    <h3>Visor de Logs</h3>
                    <p>Aquí se muestran las últimas 200 líneas de cada archivo de registro. Para ver el archivo completo, ve a <code>WooCommerce > Estado > Logs</code>.</p>
                    <?php $this->display_logs(); ?>
                </div>
            <?php endif; ?>
            
        </div>
        <?php
    }

    /**
     * Lee y muestra el contenido de los archivos de log del plugin.
     */
    private function display_logs() {
        $log_files = [
            'Sincronización de Productos' => 'bwi-sync',
            'Sincronización de Pedidos'  => 'bwi-orders',
            'Webhooks'                 => 'bwi-webhooks',
        ];

        foreach ( $log_files as $title => $handle ) {
            echo '<h4>' . esc_html( $title ) . ' (<code>' . esc_html( $handle ) . '</code>)</h4>';
            
            $log_path = WC_LOG_DIR . $handle . '-' . sanitize_file_name( wp_hash( $handle ) ) . '.log';

            if ( file_exists( $log_path ) ) {
                $log_content = file($log_path);
                $log_content_reversed = array_reverse($log_content);
                $latest_entries = array_slice($log_content_reversed, 0, 200);

                echo '<pre style="background: #f1f1f1; border: 1px solid #ccc; padding: 10px; border-radius: 5px; max-height: 400px; overflow-y: scroll;">';
                if ( empty( $latest_entries ) ) {
                    echo 'El archivo de log está vacío.';
                } else {
                    foreach ( $latest_entries as $line ) {
                        echo esc_html( $line );
                    }
                }
                echo '</pre>';
            } else {
                echo '<p><em>No se ha generado un archivo de log para esta categoría aún.</em></p>';
            }
        }
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
        add_settings_field( 'bwi_enable_logging', 'Modo de Depuración', [ $this, 'render_enable_logging_field' ], 'bwi_settings', 'bwi_api_settings_section' );

        // --- SECCIÓN: Sincronización de Productos ---
        add_settings_section( 'bwi_sync_settings_section', 'Sincronización de Productos', null, 'bwi_settings' );
        add_settings_field( 'bwi_office_id_stock', 'Sucursal para Stock', [ $this, 'render_office_id_stock_field' ], 'bwi_settings', 'bwi_sync_settings_section' );
        // NUEVO CAMPO: Lista de Precios
        add_settings_field( 'bwi_price_list_id', 'Lista de Precios', [ $this, 'render_price_list_id_field' ], 'bwi_settings', 'bwi_sync_settings_section' );
        // NUEVO CAMPO: Tipo de Producto a Sincronizar
        add_settings_field( 'bwi_product_type_id_sync', 'Sincronizar solo el Tipo de Producto', [ $this, 'render_product_type_id_sync_field' ], 'bwi_settings', 'bwi_sync_settings_section' );
        
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

        // --- SECCIÓN: Mapeo de Pagos ---
        add_settings_section( 'bwi_payment_mapping_section', 'Mapeo de Formas de Pago', [ $this, 'render_payment_mapping_description' ], 'bwi_settings' );

        // Obtener las pasarelas de pago activas en WooCommerce
        $payment_gateways = WC()->payment_gateways->get_available_payment_gateways();

        foreach ( $payment_gateways as $gateway ) {
            add_settings_field(
                'bwi_payment_map_' . $gateway->id, // ID único para cada campo
                $gateway->get_title(), // Nombre de la pasarela (ej. "Transferencia Bancaria")
                [ $this, 'render_payment_gateway_mapping_field' ],
                'bwi_settings',
                'bwi_payment_mapping_section',
                [ 'gateway' => $gateway ] // Pasamos el objeto de la pasarela a la función de renderizado
            );
        }
    }

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

        // Construir la URL con el token en la ruta, sin parámetros.
        $webhook_url = get_rest_url( null, 'bwi/v1/webhook/' . $secret );
        
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

        // Obtenemos todas las sucursales (activas e inactivas) para no perder ninguna.
        $transient_key = 'bwi_all_offices_' . substr(md5($this->access_token), 0, 12);
        $offices = $this->get_bsale_items_with_cache('offices.json', $transient_key);

        $office_id_1_found = false;
        if (!empty($offices)) {
            foreach ($offices as $office) {
                if (isset($office->id) && $office->id == 1) {
                    $office_id_1_found = true;
                    break;
                }
            }
        }

        // Si la Sucursal Base (ID 1) no vino en la lista de la API, la añadimos manualmente.
        if (!$office_id_1_found) {
            $base_office = new stdClass();
            $base_office->id = 1;
            $base_office->name = 'Sucursal Base (Casa Matriz)';
            $base_office->state = 0; // Asumimos que está activa.
            array_unshift($offices, $base_office);
        }

        echo '<select name="bwi_options[office_id_stock]">';
        if ( !empty($offices) ) {
            foreach ( $offices as $office ) {
                // Añadimos una etiqueta "(Inactiva)" a las sucursales que no están activas para mayor claridad.
                $status_label = (isset($office->state) && $office->state != 0) ? ' (Inactiva)' : '';
                
                // --- LÍNEA CORREGIDA ---
                printf(
                    '<option value="%d" %s>%s%s</option>',
                    esc_attr($office->id),
                    selected($selected_office_id, $office->id, false),
                    esc_html($office->name),
                    esc_html($status_label) // La variable se añade aquí como un argumento más.
                );
            }
        } else {
            // Si la API no devuelve nada, al menos mostramos la opción Base.
            echo '<option value="1">Sucursal Base (Casa Matriz)</option>';
        }
        echo '</select>';
        echo '<p class="description">Seleccione la sucursal de Bsale de la cual se tomará el stock para sincronizar.</p>';
    }
    /**
     * Renderiza el campo para seleccionar la Lista de Precios.
     */
    public function render_price_list_id_field() {
        $options = get_option('bwi_options');
        $selected_list_id = isset($options['price_list_id']) ? $options['price_list_id'] : '';

        // Usamos la misma función de ayuda, pero para otro endpoint
        $transient_key = 'bwi_active_price_lists_' . substr(md5($this->access_token), 0, 12);
        $price_lists = $this->get_bsale_items_with_cache('price_lists.json', $transient_key, ['state' => 0, 'expand' => 'coin']);

        echo '<select name="bwi_options[price_list_id]">';
        if ( !empty($price_lists) ) {
            echo '<option value="">-- No sincronizar precios --</option>';
            foreach ( $price_lists as $list ) {
                // CORRECIÓN: Verificamos que el objeto coin y symbol existan antes de usarlos.
                $currency_symbol = isset($list->coin, $list->coin->symbol) ? ' (' . esc_html($list->coin->symbol) . ')' : '';
                
                printf(
                    '<option value="%d" %s>%s%s</option>',
                    esc_attr($list->id),
                    selected($selected_list_id, $list->id, false),
                    esc_html($list->name),
                    $currency_symbol
                );
            }
        } else {
            echo '<option value="">No se pudieron cargar las listas de precios activas desde Bsale.</option>';
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
    
    /**
     * Muestra una descripción para la sección de mapeo de pagos.
     */
    public function render_payment_mapping_description() {
        echo '<p>' . esc_html__( 'Asocia cada forma de pago de WooCommerce con su equivalente en Bsale. Esto asegurará que los documentos en Bsale se registren con la forma de pago correcta.', 'bsale-woocommerce-integration' ) . '</p>';
    }

    /**
     * Muestra un campo de selección (dropdown) para mapear una pasarela de pago de WC a una de Bsale.
     *
     * @param array $args Argumentos pasados desde add_settings_field, incluyendo la pasarela de pago.
     */
    public function render_payment_gateway_mapping_field( $args ) {
        $gateway = $args['gateway'];
        $options = get_option( 'bwi_options' );
        // El valor guardado para esta pasarela específica
        $selected_bsale_id = isset( $options['payment_map_' . $gateway->id] ) ? $options['payment_map_' . $gateway->id] : '';

        // Obtener las formas de pago de Bsale usando nuestra función de ayuda
        $transient_key = 'bwi_payment_types_' . substr(md5($this->access_token), 0, 12);
        $bsale_payment_types = $this->get_bsale_items_with_cache('payment_types.json', $transient_key);

        // Nombre del campo en el array de opciones
        $field_name = 'bwi_options[payment_map_' . $gateway->id . ']';

        echo '<select name="' . esc_attr( $field_name ) . '">';
        // Opción por defecto para no asignar un pago
        echo '<option value="">-- No registrar pago --</option>';

        if ( !empty($bsale_payment_types) ) {
            foreach ( $bsale_payment_types as $bsale_type ) {
                // Solo mostrar las formas de pago activas en Bsale
                if ( $bsale_type->state === 0 ) {
                    printf(
                        '<option value="%d" %s>%s</option>',
                        esc_attr($bsale_type->id),
                        selected($selected_bsale_id, $bsale_type->id, false),
                        esc_html($bsale_type->name)
                    );
                }
            }
        } else {
            echo '<option value="">-- No se pudieron cargar las formas de pago de Bsale --</option>';
        }
        echo '</select>';
        echo '<p class="description">Selecciona la forma de pago en Bsale que corresponde a <strong>' . esc_html($gateway->get_title()) . '</strong>.</p>';
    }
    /**
     * Renderiza el campo para seleccionar el Tipo de Producto a sincronizar.
     */
    public function render_product_type_id_sync_field() {
        $options = get_option('bwi_options');
        $selected_type_id = isset($options['product_type_id_sync']) ? $options['product_type_id_sync'] : '';

        // Usamos nuestra función de ayuda para obtener los tipos de producto de Bsale
        $transient_key = 'bwi_product_types_' . substr(md5($this->access_token), 0, 12);
        $product_types = $this->get_bsale_items_with_cache('product_types.json', $transient_key, ['state' => 0]);

        echo '<select name="bwi_options[product_type_id_sync]">';
        echo '<option value="">-- Sincronizar Todos --</option>'; // Opción para no filtrar

        if ( !empty($product_types) ) {
            foreach ( $product_types as $type ) {
                printf(
                    '<option value="%d" %s>%s</option>',
                    esc_attr($type->id),
                    selected($selected_type_id, $type->id, false),
                    esc_html($type->name)
                );
            }
        } else {
            echo '<option value="">No se pudieron cargar los tipos de producto.</option>';
        }
        echo '</select>';
        echo '<p class="description">Seleccione un tipo de producto para limitar la sincronización solo a los productos de esa categoría. <strong>Recomendado si tiene muchos productos.</strong></p>';
    }

    /**
     * Muestra la casilla para activar/desactivar el modo de depuración (logging).
     */
    public function render_enable_logging_field() {
        $options = get_option( 'bwi_options' );
        $checked = isset( $options['enable_logging'] ) ? checked( 1, $options['enable_logging'], false ) : '';
        echo '<input type="checkbox" name="bwi_options[enable_logging]" value="1" ' . $checked . '>';
        echo '<label>Activar para registrar toda la actividad del plugin en los logs. <strong>Advertencia:</strong> Desactívalo en un sitio en producción si no estás depurando para evitar archivos de log grandes.</label>';
    }
    
    public function sanitize_options( $input ) {
        $new_input = [];
        // Las credenciales ya no se guardan aquí.
        if ( isset( $input['office_id_stock'] ) ) $new_input['office_id_stock'] = absint( $input['office_id_stock'] );
        if ( isset( $input['price_list_id'] ) ) $new_input['price_list_id'] = absint( $input['price_list_id'] );
        if ( isset( $input['enable_billing'] ) ) $new_input['enable_billing'] = absint( $input['enable_billing'] );
        if ( isset( $input['trigger_status'] ) ) $new_input['trigger_status'] = sanitize_text_field( $input['trigger_status'] );
        if ( isset( $input['boleta_codesii'] ) ) $new_input['boleta_codesii'] = absint( $input['boleta_codesii'] );
        if ( isset( $input['factura_codesii'] ) ) $new_input['factura_codesii'] = absint( $input['factura_codesii'] );
        if ( isset( $input['enable_email_notification'] ) ) $new_input['enable_email_notification'] = absint( $input['enable_email_notification'] );
        if ( isset( $input['enable_logging'] ) ) $new_input['enable_logging'] = absint( $input['enable_logging'] );
        if ( isset( $input['product_type_id_sync'] ) ) $new_input['product_type_id_sync'] = absint( $input['product_type_id_sync'] );
        // Recorrer todas las posibles claves de mapeo y guardarlas si existen.
        foreach ( $input as $key => $value ) {
            if ( strpos( $key, 'payment_map_' ) === 0 ) {
                $new_input[$key] = sanitize_text_field( $value );
            }
        }
        
        return $new_input;
    }

    /**
     * Función de ayuda para obtener datos desde la API de Bsale con caché (transient).
     * Centraliza la lógica de obtener y cachear listas para evitar código repetido.
     *
     * @param string $endpoint      La ruta de la API (ej. 'offices.json').
     * @param string $transient_key El nombre único para la caché de esta lista.
     * @param array  $params        Parámetros adicionales para la solicitud a la API.
     * @return array                Una lista de items obtenidos de la API, o un array vacío si falla.
     */
    private function get_bsale_items_with_cache( $endpoint, $transient_key, $params = [] ) {
        $items = get_transient( $transient_key );

        if ( false === $items ) {
            $api_client = BWI_API_Client::get_instance();
            $response = $api_client->get( $endpoint, $params );
            
            $items = []; // Por defecto, un array vacío
            if ( ! is_wp_error( $response ) && ! empty( $response->items ) ) {
                $items = $response->items;
                // Guardamos en caché por 12 horas.
                set_transient( $transient_key, $items, 12 * HOUR_IN_SECONDS );
            }
        }
        return $items;
    }

    /**
     * Realiza una prueba de conexión a la API de Bsale para validar las credenciales.
     * Si falla, guarda un transient para mostrar una notificación de error.
     */
    public function check_api_credentials() {
        // Solo ejecutar la prueba si el token está definido.
        if ( empty( $this->access_token ) ) {
            return;
        }

        $api_client = BWI_API_Client::get_instance();
        // Hacemos una llamada simple y de bajo costo a la API.
        $response = $api_client->get('offices/count.json');

        $transient_key = 'bwi_api_connection_error';

        if ( is_wp_error( $response ) ) {
            $error_code = $response->get_error_code();
            $error_data = $response->get_error_data();
            
            // Verificamos si el error es de autenticación (401) o de token inválido.
            if ( $error_code === 'bwi_api_error' && isset($error_data['status']) && $error_data['status'] == 401 ) {
                // Guardamos el error en un transient que expira en 1 hora.
                set_transient( $transient_key, 'Error de autenticación: El Access Token no es válido o ha expirado. Por favor, revísalo en tu archivo wp-config.php.', HOUR_IN_SECONDS );
            }
        } else {
            // Si la conexión es exitosa, nos aseguramos de que no haya ningún aviso de error guardado.
            delete_transient( $transient_key );
        }
    }

    /**
     * Muestra la notificación de error en el panel de administración si existe.
     */
    public function display_api_error_notice() {
        $error_message = get_transient( 'bwi_api_connection_error' );

        if ( ! empty( $error_message ) ) {
            ?>
            <div class="notice notice-error is-dismissible">
                <p>
                    <strong><?php esc_html_e( 'Error de Conexión - Bsale Integración', 'bsale-woocommerce-integration' ); ?></strong><br>
                    <?php echo esc_html( $error_message ); ?>
                </p>
            </div>
            <?php
        }
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
            BWI_PLUGIN_URL . 'assets/js/bwi-admin.js', 
            [ 'jquery' ],
            '2.7.0', // Buena práctica: incrementa la versión
            true // cargar en el footer
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
