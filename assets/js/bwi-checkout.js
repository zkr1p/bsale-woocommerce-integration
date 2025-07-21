(function($) {
    'use strict';

    $(function() {
        // Seleccionamos los elementos de forma más eficiente una sola vez.
        var facturaFieldsContainer = $('#bwi-factura-fields');
        var documentTypeSelector = $('#bwi_document_type'); // El selector <select>

        /**
         * Función para mostrar u ocultar los campos de factura
         * basado en el valor del selector.
         */
        function toggleFacturaFields() {
            // Verificamos el valor del <select>
            if ( documentTypeSelector.val() === 'factura' ) {
                facturaFieldsContainer.slideDown();
            } else {
                facturaFieldsContainer.slideUp();
            }
        }

        // --- LA CORRECCIÓN CLAVE ESTÁ AQUÍ ---
        // Nos enganchamos al evento 'change' del selector '#bwi_document_type'
        // en lugar de los antiguos botones de radio.
        $('body').on('change', '#bwi_document_type', toggleFacturaFields);
        
        // Mantener el hook 'updated_checkout' es una buena práctica para la compatibilidad con temas.
        $(document.body).on('updated_checkout', toggleFacturaFields);

        // Ejecutar la función una vez al cargar la página para establecer el estado inicial correcto.
        toggleFacturaFields();
    });

})(jQuery);