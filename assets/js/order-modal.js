jQuery(document).ready(function($) {
    const modal = $('#odooflow-order-modal');
    const form = $('#odooflow-order-form');
    const customerSelect = $('#odoo-customer');
    const lineItemsContainer = $('#line-items-container');
    let wooProducts = null;

    // Open modal
    $('#odooflow-create-order-btn').on('click', function() {
        modal.show();
        loadCustomers();
        if (!wooProducts) {
            loadWooProducts();
        }
    });

    // Close modal
    $('.odooflow-modal-close').on('click', function() {
        modal.hide();
    });

    // Close modal when clicking outside
    $(window).on('click', function(event) {
        if ($(event.target).is(modal)) {
            modal.hide();
        }
    });

    // Refresh customers
    $('#refresh-customers').on('click', function() {
        loadCustomers(true);
    });

    // Add line item
    $('#add-line-item').on('click', function() {
        addLineItemRow();
    });

    // Remove line item
    $(document).on('click', '.remove-line', function() {
        $(this).closest('.line-item').remove();
    });

    // Handle form submission
    form.on('submit', function(e) {
        e.preventDefault();

        // Validate form
        if (!customerSelect.val()) {
            alert(odooflowOrderModal.i18n.selectCustomer);
            return;
        }

        const lineItems = [];
        $('.line-item').each(function() {
            const $item = $(this);
            const productId = $item.find('.product-select').val();
            const quantity = $item.find('.quantity-input').val();
            const price = $item.find('.price-input').val();

            if (productId && quantity > 0) {
                lineItems.push({
                    product_id: productId,
                    quantity: quantity,
                    price: price
                });
            }
        });

        if (lineItems.length === 0) {
            alert(odooflowOrderModal.i18n.addLineItems);
            return;
        }

        if (!confirm(odooflowOrderModal.i18n.confirmCreate)) {
            return;
        }

        const formData = {
            action: 'create_manual_odoo_order',
            nonce: odooflowOrderModal.nonce,
            partner_id: customerSelect.val(),
            order_type: $('input[name="order_type"]:checked').val(),
            line_items: JSON.stringify(lineItems)
        };

        form.addClass('loading');

        $.post(odooflowOrderModal.ajaxurl, formData)
            .done(function(response) {
                if (response.success) {
                    alert(response.data.message);
                    modal.hide();
                    // Optionally reload the page
                    window.location.reload();
                } else {
                    alert(response.data.message || odooflowOrderModal.i18n.errorCreating);
                }
            })
            .fail(function() {
                alert(odooflowOrderModal.i18n.errorCreating);
            })
            .always(function() {
                form.removeClass('loading');
            });
    });

    // Load customers from Odoo
    function loadCustomers(forceRefresh = false) {
        customerSelect.prop('disabled', true).addClass('loading');

        // Check cache first
        const cachedCustomers = !forceRefresh && localStorage.getItem('odooflow_customers');
        if (cachedCustomers) {
            populateCustomerSelect(JSON.parse(cachedCustomers));
            customerSelect.prop('disabled', false).removeClass('loading');
            return;
        }

        $.post(odooflowOrderModal.ajaxurl, {
            action: 'get_odoo_customers',
            nonce: odooflowOrderModal.nonce
        })
        .done(function(response) {
            if (response.success && response.data.customers) {
                localStorage.setItem('odooflow_customers', JSON.stringify(response.data.customers));
                populateCustomerSelect(response.data.customers);
            } else {
                alert(odooflowOrderModal.i18n.errorLoading);
            }
        })
        .fail(function() {
            alert(odooflowOrderModal.i18n.errorLoading);
        })
        .always(function() {
            customerSelect.prop('disabled', false).removeClass('loading');
        });
    }

    // Load WooCommerce products
    function loadWooProducts() {
        $.post(odooflowOrderModal.ajaxurl, {
            action: 'get_woo_products',
            nonce: odooflowOrderModal.nonce
        })
        .done(function(response) {
            if (response.success && response.data.products) {
                wooProducts = response.data.products;
                // Add initial line item row
                addLineItemRow();
            }
        });
    }

    // Populate customer select
    function populateCustomerSelect(customers) {
        customerSelect.empty()
            .append($('<option>', {
                value: '',
                text: odooflowOrderModal.i18n.selectCustomer
            }));

        customers.forEach(function(customer) {
            customerSelect.append($('<option>', {
                value: customer.id,
                text: customer.name + (customer.email ? ` (${customer.email})` : '')
            }));
        });
    }

    // Add line item row
    function addLineItemRow() {
        if (!wooProducts) return;

        const row = $('<div>', { class: 'line-item' });

        // Product select
        const productSelect = $('<select>', {
            class: 'product-select',
            required: true
        }).append($('<option>', {
            value: '',
            text: 'Select Product'
        }));

        wooProducts.forEach(function(product) {
            productSelect.append($('<option>', {
                value: product.id,
                text: product.name,
                'data-price': product.price
            }));
        });

        // Quantity input
        const quantityInput = $('<input>', {
            type: 'number',
            class: 'quantity-input',
            min: '1',
            step: '1',
            value: '1',
            required: true
        });

        // Price input
        const priceInput = $('<input>', {
            type: 'number',
            class: 'price-input',
            min: '0',
            step: '0.01',
            required: true
        });

        // Remove button
        const removeButton = $('<span>', {
            class: 'remove-line dashicons dashicons-no-alt',
            title: 'Remove Line'
        });

        // Update price when product changes
        productSelect.on('change', function() {
            const selected = $(this).find(':selected');
            if (selected.length) {
                priceInput.val(selected.data('price'));
            }
        });

        row.append(productSelect, quantityInput, priceInput, removeButton);
        lineItemsContainer.append(row);

        // Trigger change to set initial price
        productSelect.trigger('change');
    }
}); 