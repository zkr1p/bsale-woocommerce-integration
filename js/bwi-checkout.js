(function($) {
    'use strict';

    $(function() {
        // Función para mostrar u ocultar los campos de factura
        function toggleFacturaFields() {
            if ($('input[name="bwi_document_type"]:checked').val() === 'factura') {
                $('#bwi-factura-fields').slideDown();
            } else {
                $('#bwi-factura-fields').slideUp();
            }
        }

        // Ejecutar al cargar la página y cuando cambie la selección
        $('body').on('change', 'input[name="bwi_document_type"]', toggleFacturaFields);
        
        // Ejecutar también en el evento 'updated_checkout' para compatibilidad con temas
        $(document.body).on('updated_checkout', toggleFacturaFields);

        // Llamada inicial
        toggleFacturaFields();
    });

})(jQuery);
