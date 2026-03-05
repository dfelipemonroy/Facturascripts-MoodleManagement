$(document).ready(function () {
    var $modal = $('#modalimport-from-moodle');
    if ($modal.length === 0) return;

    var $importMode = $modal.find('[name="import_mode"]');
    var $clienteHidden = $modal.find('[name="codcliente"]');
    if ($importMode.length === 0 || $clienteHidden.length === 0) return;

    var $clienteColumn = $clienteHidden.closest('.col-12');
    if ($clienteColumn.length === 0) return;

    function toggleClienteField() {
        var needsClient = $importMode.val() === 'assign_to_existing_client';
        if (needsClient) {
            $clienteColumn.show();
        } else {
            $clienteColumn.hide();
            $clienteHidden.val('');
            $clienteColumn.find('input[type="text"]').val('');
        }
    }

    // Use jQuery event delegation for reliability
    $modal.on('change', '[name="import_mode"]', toggleClienteField);
    $modal.on('show.bs.modal', function () {
        setTimeout(toggleClienteField, 100);
    });
    toggleClienteField();
});
