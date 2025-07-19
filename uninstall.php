<?php
/**
 * Lógica de desinstalación para la Integración Bsale y WooCommerce.
 * Este archivo se ejecuta cuando el usuario hace clic en "Borrar" en la pantalla de plugins.
 *
 * @package Bsale_WooCommerce_Integration
 */

// Medida de seguridad: Salir si el archivo es accedido directamente.
if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
    exit;
}

// --- Limpieza de Opciones de la Base de Datos ---
// Elimina las opciones guardadas en la tabla wp_options.
delete_option( 'bwi_options' );

// --- Limpieza de Transients ---
// Elimina la caché de la lista de sucursales para no dejar datos temporales.
delete_transient( 'bwi_offices_list' );

// --- Limpieza de Tareas Programadas (Cron Jobs) ---
// Elimina el cron job principal que dispara la sincronización periódica.
wp_clear_scheduled_hook( 'bwi_cron_sync_products' );

// --- Limpieza de Acciones de Action Scheduler ---
// Elimina todas las acciones pendientes que nuestro plugin haya podido crear.
// Es una buena práctica para no dejar la cola de tareas con acciones huérfanas.
if ( function_exists( 'as_get_scheduled_actions' ) ) {
    $hooks_to_clear = [
        'bwi_sync_products_batch',
        'bwi_sync_single_variant',
        'bwi_create_document_for_order',
        'bwi_process_webhook_payload',
    ];

    foreach ( $hooks_to_clear as $hook ) {
        // Cancelar todas las acciones pendientes para este hook.
        as_unschedule_all_actions( $hook );
    }
}
