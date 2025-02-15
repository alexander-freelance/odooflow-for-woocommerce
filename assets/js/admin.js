jQuery(document).ready(function($) {
    $('.refresh-databases').on('click', function(e) {
        e.preventDefault();
        
        const $button = $(this);
        const $wrapper = $button.closest('.odoo-database-wrapper');
        
        $button.prop('disabled', true);
        
        $.ajax({
            url: odooflow.ajax_url,
            type: 'POST',
            data: {
                action: 'refresh_odoo_databases',
                nonce: odooflow.nonce
            },
            success: function(response) {
                if (response.success) {
                    $wrapper.find('select').replaceWith(response.data.html);
                } else {
                    $wrapper.find('.notice').remove();
                    $wrapper.prepend('<div class="notice notice-error"><p>' + response.data.message + '</p></div>');
                }
            },
            error: function() {
                $wrapper.find('.notice').remove();
                $wrapper.prepend('<div class="notice notice-error"><p>Error refreshing databases. Please try again.</p></div>');
            },
            complete: function() {
                $button.prop('disabled', false);
            }
        });
    });

    $('.list-modules').on('click', function(e) {
        e.preventDefault();
        
        const $button = $(this);
        const $modulesList = $('#odoo-modules-list');
        
        $button.prop('disabled', true);
        $modulesList.html('<div class="notice notice-info"><p>Loading modules...</p></div>');
        
        $.ajax({
            url: odooflow.ajax_url,
            type: 'POST',
            data: {
                action: 'list_odoo_modules',
                nonce: odooflow.nonce
            },
            success: function(response) {
                if (response.success) {
                    $modulesList.html(response.data.html);
                } else {
                    $modulesList.html('<div class="notice notice-error"><p>' + response.data.message + '</p></div>');
                }
            },
            error: function() {
                $modulesList.html('<div class="notice notice-error"><p>Error fetching modules. Please try again.</p></div>');
            },
            complete: function() {
                $button.prop('disabled', false);
            }
        });
    });
}); 