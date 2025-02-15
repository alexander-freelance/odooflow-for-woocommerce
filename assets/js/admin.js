jQuery(document).ready(function($) {
    // Handle manual database input toggle
    $('#manual_db').on('change', function() {
        const $wrapper = $('.database-select-container');
        const $refreshButton = $('.refresh-databases');
        const isManual = $(this).is(':checked');
        const currentValue = $('#odoo_database').val();
        
        if (isManual) {
            $wrapper.html('<input type="text" name="odoo_database" id="odoo_database" value="' + currentValue + '" class="regular-text manual-db-input">');
            $refreshButton.hide();
        } else {
            $refreshButton.show();
            // Make the AJAX call to refresh databases
            $.ajax({
                url: odooflow.ajax_url,
                type: 'POST',
                data: {
                    action: 'refresh_odoo_databases',
                    nonce: odooflow.nonce
                },
                success: function(response) {
                    if (response.success) {
                        $wrapper.html(response.data.html);
                    } else {
                        $wrapper.html('<div class="notice notice-error"><p>' + response.data.message + '</p></div>');
                    }
                },
                error: function() {
                    $wrapper.html('<div class="notice notice-error"><p>Error refreshing databases. Please try again.</p></div>');
                }
            });
        }
    });

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
        const $modulesList = $('#odoo-modules-list .modules-content');
        
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

    // Handle Odoo products count button
    $('.get-odoo-products-count').on('click', function(e) {
        e.preventDefault();
        
        const $button = $(this);
        const $result = $button.siblings('.odoo-products-count-result');
        
        $button.prop('disabled', true);
        $result.html('<span class="spinner is-active"></span> Loading...');
        
        $.ajax({
            url: odooflow.ajax_url,
            type: 'POST',
            data: {
                action: 'get_odoo_products_count',
                nonce: odooflow.nonce
            },
            success: function(response) {
                if (response.success) {
                    $result.html('<div class="notice notice-success"><p>' + response.data.message + '</p></div>');
                } else {
                    $result.html('<div class="notice notice-error"><p>' + response.data.message + '</p></div>');
                }
            },
            error: function() {
                $result.html('<div class="notice notice-error"><p>Error fetching product count. Please try again.</p></div>');
            },
            complete: function() {
                $button.prop('disabled', false);
            }
        });
    });
}); 