(function($) {
    'use strict';

    $(function() {
        var facturaFields = $('#bwi-factura-fields');
        var companyNameField = $('#bwi_billing_company_name_field');
        var rutField = $('#bwi_billing_rut_field');
        var activityField = $('#bwi_billing_activity_field');

        function toggleFacturaFields() {
            if ($('input[name="bwi_document_type"]:checked').val() === 'factura') {
                facturaFields.slideDown();
                // Hacemos los campos requeridos
                companyNameField.addClass('validate-required');
                rutField.addClass('validate-required');
                activityField.addClass('validate-required');
            } else {
                facturaFields.slideUp();
                // Quitamos el 'required' para que el formulario se pueda enviar.
                companyNameField.removeClass('validate-required');
                rutField.removeClass('validate-required');
                activityField.removeClass('validate-required');
            }
        }

        $('body').on('change', 'input[name="bwi_document_type"]', toggleFacturaFields);
        $(document.body).on('updated_checkout', toggleFacturaFields);

        toggleFacturaFields();
    });

})(jQuery);
