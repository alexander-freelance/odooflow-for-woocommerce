jQuery(document).ready(function($) {
    // Handle sync to Odoo button click
    $(document).on('click', '.sync-to-odoo', function() {
        const $button = $(this);
        const orderId = $button.data('order-id');
        
        if (!orderId) {
            return;
        }

        if (!confirm(odooflowMetabox.i18n.confirmSync)) {
            return;
        }

        $button.prop('disabled', true)
               .text(odooflowMetabox.i18n.syncing);

        $.post(odooflowMetabox.ajaxurl, {
            action: 'sync_order_to_odoo',
            nonce: odooflowMetabox.nonce,
            order_id: orderId
        })
        .done(function(response) {
            if (response.success) {
                alert(response.data.message);
                // Reload the page to show updated metabox
                window.location.reload();
            } else {
                alert(response.data.message || odooflowMetabox.i18n.errorSyncing);
                $button.prop('disabled', false)
                       .text(odooflowMetabox.i18n.syncToOdoo);
            }
        })
        .fail(function() {
            alert(odooflowMetabox.i18n.errorSyncing);
            $button.prop('disabled', false)
                   .text(odooflowMetabox.i18n.syncToOdoo);
        });
    });
}); 