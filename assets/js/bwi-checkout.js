(function($) {
    'use strict';

    $(function() {
        // Seleccionamos los elementos de forma más eficiente una sola vez.
        var facturaFieldsContainer = $('#bwi-factura-fields');
        var documentTypeSelector = $('#bwi_document_type');

        /**
         * Función para mostrar u ocultar los campos de factura
         * basado en el valor del selector.
         */
        function toggleFacturaFields() {
            if ( documentTypeSelector.val() === 'factura' ) {
                facturaFieldsContainer.slideDown();
            } else {
                facturaFieldsContainer.slideUp();
            }
        }

        // --- INICIO DE LA NUEVA LÓGICA PARA FORMATEAR RUT ---

        /**
         * Función para formatear un RUT chileno automáticamente.
         * Ej: 123456789 -> 12.345.678-9
         */
        function formatRut(rut) {
            // Limpiar el RUT de puntos, guiones y cualquier cosa que no sea número o K.
            var valorActual = rut.replace(/[^0-9kK]/g, '').toUpperCase();
            
            // Separar el cuerpo del dígito verificador
            var cuerpo = valorActual.slice(0, -1);
            var dv = valorActual.slice(-1);
            
            // Si no hay cuerpo, no hay nada que formatear.
            if (cuerpo.length === 0) {
                return dv;
            }
            
            // Añadir puntos como separadores de miles al cuerpo
            var cuerpoFormateado = cuerpo.replace(/\B(?=(\d{3})+(?!\d))/g, ".");
            
            return cuerpoFormateado + '-' + dv;
        }

        // Nos enganchamos al evento 'keyup' del campo RUT.
        // Se usa un "delegated event" en 'body' para asegurar que funcione incluso si el checkout se recarga por AJAX.
        $('body').on('keyup', '#bwi_billing_rut', function(e) {
            var rutInput = $(this);
            // Formateamos el valor y lo volvemos a poner en el campo.
            rutInput.val(formatRut(rutInput.val()));
        });

        // Escuchamos los cambios en el selector de tipo de documento
        $('body').on('change', '#bwi_document_type', toggleFacturaFields);
        
        // Escuchamos la actualización del checkout de WooCommerce
        $(document.body).on('updated_checkout', toggleFacturaFields);

        // Ejecutar la función una vez al cargar la página para establecer el estado inicial correcto.
        toggleFacturaFields();
    });

})(jQuery);