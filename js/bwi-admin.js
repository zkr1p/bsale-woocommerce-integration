(function($) {
    'use strict';

    $(function() {
        // Cuando se hace clic en el botón de sincronización manual
        $('#bwi-manual-sync-button').on('click', function() {
            var $button = $(this);
            var $spinner = $button.next('.spinner');
            var $status = $('#bwi-sync-status');

            // Desactivar el botón y mostrar el spinner
            $button.prop('disabled', true);
            $spinner.addClass('is-active');
            $status.text('Sincronizando, por favor espera...').css('color', 'orange');

            // Realizar la llamada AJAX
            $.ajax({
                url: bwi_ajax_object.ajax_url, // URL del AJAX de WordPress
                type: 'POST',
                data: {
                    action: 'bwi_manual_sync', // La acción que definimos en PHP
                    security: bwi_ajax_object.nonce // El nonce de seguridad
                },
                success: function(response) {
                    if (response.success) {
                        $status.text(response.data.message).css('color', 'green');
                    } else {
                        $status.text('Error: ' + response.data.message).css('color', 'red');
                    }
                },
                error: function(jqXHR, textStatus, errorThrown) {
                    $status.text('Error de comunicación con el servidor.').css('color', 'red');
                    console.error("BWI Sync Error:", textStatus, errorThrown);
                },
                complete: function() {
                    // Reactivar el botón y ocultar el spinner
                    $button.prop('disabled', false);
                    $spinner.removeClass('is-active');
                }
            });
        });
    });

})(jQuery);
