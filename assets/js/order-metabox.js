jQuery(document).ready(function($) {
    const modal = $('#odooflow-create-order-modal');
    const form = $('#odooflow-create-order-form');
    const customerSelect = $('#odoo-customer');
    const submitButton = $('.create-order-submit');
    const productLinesContainer = $('.product-lines-container');
    let currentOrderId = null;
    let odooProducts = [];
    
    // Handle create order button click
    $(document).on('click', '.create-odoo-order', function() {
        const orderId = $(this).data('order-id');
        if (!orderId) {
            return;
        }
        
        currentOrderId = orderId;
        loadCustomers();
        loadProducts();
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

    // Load products from Odoo
    function loadProducts() {
        const spinner = $('.product-select-wrapper:first .spinner');
        $('.odoo-product').prop('disabled', true);
        spinner.show();

        $.post(odooflowMetabox.ajaxurl, {
            action: 'get_odoo_products_for_order',
            nonce: odooflowMetabox.nonce
        })
        .done(function(response) {
            if (response.success && response.data.products) {
                odooProducts = response.data.products;
                populateProductSelects();
            } else {
                alert(response.data.message || odooflowMetabox.i18n.errorLoadingProducts);
            }
        })
        .fail(function() {
            alert(odooflowMetabox.i18n.errorLoadingProducts);
        })
        .always(function() {
            $('.odoo-product').prop('disabled', false);
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

    // Populate product select dropdowns
    function populateProductSelects() {
        $('.odoo-product').each(function() {
            const select = $(this);
            const currentValue = select.val();
            
            select.find('option:not(:first)').remove();
            
            odooProducts.forEach(function(product) {
                const optionText = product.name + (product.default_code ? ` [${product.default_code}]` : '');
                select.append(
                    $('<option></option>')
                        .val(product.id)
                        .text(optionText)
                        .data('price', product.list_price)
                );
            });

            if (currentValue) {
                select.val(currentValue);
            }
        });
    }

    // Handle product selection change
    $(document).on('change', '.odoo-product', function() {
        const select = $(this);
        const priceInput = select.closest('.product-line').find('.price');
        const selectedOption = select.find('option:selected');
        
        if (selectedOption.val()) {
            priceInput.val(selectedOption.data('price'));
        } else {
            priceInput.val('');
        }
        
        validateForm();
    });

    // Handle quantity and price changes
    $(document).on('input', '.quantity, .price', validateForm);

    // Add new product line
    $('.add-product').on('click', function() {
        const newLine = $('.product-line:first').clone();
        newLine.find('select, input').val('');
        productLinesContainer.append(newLine);
        populateProductSelects();
    });

    // Remove product line
    $(document).on('click', '.remove-product', function() {
        const productLines = $('.product-line');
        if (productLines.length > 1) {
            $(this).closest('.product-line').remove();
        }
        validateForm();
    });

    // Enable/disable submit button based on form validation
    function validateForm() {
        let isValid = customerSelect.val() ? true : false;
        
        $('.product-line').each(function() {
            const line = $(this);
            const productId = line.find('.odoo-product').val();
            const quantity = line.find('.quantity').val();
            const price = line.find('.price').val();
            
            if (productId && (!quantity || !price)) {
                isValid = false;
                return false;
            }
        });

        submitButton.prop('disabled', !isValid);
    }

    // Reset form
    function resetForm() {
        form[0].reset();
        $('.product-line:not(:first)').remove();
        submitButton.prop('disabled', true);
        currentOrderId = null;
    }
}); 