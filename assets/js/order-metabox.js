jQuery(document).ready(function($) {
    const modal = $('#odooflow-create-order-modal');
    const form = $('#odooflow-create-order-form');
    const customerSelect = $('#odoo-customer');
    const submitButton = $('.create-order-submit');
    let currentOrderId = null;
    
    // Handle create order button click
    $(document).on('click', '.create-odoo-order', function() {
        const orderId = $(this).data('order-id');
        if (!orderId) {
            return;
        }
        
        currentOrderId = orderId;
        loadCustomers();
        modal.show();
    });

    // Close modal when clicking the close button or cancel button
    $('.odooflow-modal-close, .cancel-create-order').on('click', function() {
        modal.hide();
        resetForm();
    });

    // Close modal when clicking outside
    $(window).on('click', function(event) {
        if ($(event.target).is(modal)) {
            modal.hide();
            resetForm();
        }
    });

    // Load customers from Odoo
    function loadCustomers() {
        const spinner = customerSelect.siblings('.spinner');
        customerSelect.prop('disabled', true);
        spinner.show();

        $.post(odooflowMetabox.ajaxurl, {
            action: 'get_odoo_customers',
            nonce: odooflowMetabox.nonce
        })
        .done(function(response) {
            if (response.success && response.data.customers) {
                populateCustomerSelect(response.data.customers);
            } else {
                alert(response.data.message || odooflowMetabox.i18n.errorLoadingCustomers);
            }
        })
        .fail(function() {
            alert(odooflowMetabox.i18n.errorLoadingCustomers);
        })
        .always(function() {
            customerSelect.prop('disabled', false);
            spinner.hide();
        });
    }

    // Populate customer select dropdown
    function populateCustomerSelect(customers) {
        customerSelect.find('option:not(:first)').remove();
        
        customers.forEach(function(customer) {
            const optionText = customer.name + (customer.email ? ` (${customer.email})` : '');
            customerSelect.append(
                $('<option></option>')
                    .val(customer.id)
                    .text(optionText)
            );
        });
    }

    // Enable/disable submit button based on form validation
    customerSelect.on('change', function() {
        submitButton.prop('disabled', !$(this).val());
    });

    // Reset form
    function resetForm() {
        form[0].reset();
        submitButton.prop('disabled', true);
        currentOrderId = null;
    }
}); 