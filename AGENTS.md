# OdooFlow mapping notes for Codex

This repo contains a WordPress plugin that syncs WooCommerce data with Odoo. Mapping rules are scattered across the `odooflow.php` file. Use the following guidelines when modifying mapping logic.

## Product export
- Handled in `export_product_to_odoo()` around line 1870.
- Maps WooCommerce product fields to Odoo `product.template`:
  - `get_name()` -> `name`
  - `get_sku()` -> `default_code`
  - `get_regular_price()` -> `list_price` (only if the field is selected)
  - `get_description()` -> `description`
  - `get_stock_quantity()` -> `qty_available`
  - `get_weight()` -> `weight`
- Category and image helpers are placeholders in `handle_product_categories()` and `handle_product_image()`.

## Customer export/import
- Export is in `ajax_export_woo_customers()` (~2400). Core mapping:
  - WooCommerce first and last names form `name`.
  - `get_email()` -> `email`
  - `get_billing_phone()` -> `phone`
  - Billing address fields -> `street`, `street2`, `city`.
  - Adds `customer_rank` = 1 and `type` = `contact`.
  - Function `oflow_add_col_fields()` enriches payload with `vat` and `l10n_latam_identification_type_id` from user meta `billing_id` and `tipo_identificacion`.
- Import is in `ajax_import_odoo_customers()` (~2120). It reads Odoo fields (`name`, `email`, `phone`, `street`, `street2`, `city`, `vat`, `mobile`, `company_name`, `l10n_latam_identification_type_id`) and creates or updates WooCommerce customers using the same meta keys.

## Order sync
- The `sync_order_to_odoo()` method (~2680) prepares order data and either calls `update_odoo_order()` or `create_odoo_order()`.
- `prepare_order_data()` (~2790) maps WooCommerce order to Odoo `sale.order`:
  - Generates `partner_id` via `get_or_create_odoo_customer()`.
  - `name` => `'WC' . order number`.
  - `date_order` from `get_date_created()`.
  - `state` derived from WooCommerce status via `map_order_status()`.
  - `order_line` built by `prepare_order_lines()`.
  - `amount_tax`, `amount_total`, `currency_id`, `note`.
  - Adds shipping line if needed via `prepare_shipping_line()`.
- Order lines include product mapping through `get_or_create_odoo_product()` and taxes via `get_tax_ids()`.

## Misc
- Placeholder methods exist for tax/currency/country lookup; implement when needed.
- All AJAX handlers validate nonces using `odooflow_ajax_nonce` or `odooflow_metabox_nonce`.
- When adding new mapping, keep comments consistent with the existing style.



This is how WooCommerce sends orders through the API.


[
    {
        "id": 82800,
        "status": "on-hold",
        "currency": "COP",
        "version": "9.9.5",
        "total": "244800.00",
        "billing": {
            "company": null,
            "city": "Neiva",
            "state": "HUI",
            "postcode": null,
            "country": "CO",
            "email": "doris423@hotmail.com",
            "phone": "320 8501613",
            "firstName": "doris",
            "lastName": null,
            "address1": "Calle 56 A # 1 F 42 las Mercedes",
            "address2": null,
            "tipoIdentificacion": "13",
            "billingId": "222222222222"
        },
        "shipping": {
            "company": null,
            "city": "Neiva",
            "state": "HUI",
            "postcode": null,
            "country": "CO",
            "phone": "3212199850",
            "firstName": "Gloria camacho",
            "lastName": null,
            "address1": "Calle 56 A # 1 F 42 las Mercedes",
            "address2": null,
            "shippingPhone": null
        },
        "number": "82800",
        "refunds": [],
        "parentId": 0,
        "pricesIncludeTax": false,
        "dateCreated": "2025-07-08T20:50:15.000Z",
        "dateModified": "2025-07-08T20:50:16.000Z",
        "discountTotal": "0.00",
        "discountTax": "0.00",
        "shippingTotal": "10000.00",
        "shippingTax": "0.00",
        "cartTax": "0.00",
        "totalTax": "0.00",
        "customerId": 284,
        "orderKey": "wc_order_lV8tEzEQK70Xa",
        "paymentMethod": "bacs",
        "paymentMethodTitle": "Consignación o Transferencia Bancaria y Giros",
        "transactionId": null,
        "customerIpAddress": "152.202.166.208",
        "customerUserAgent": "Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/138.0.0.0 Safari/537.36",
        "createdVia": "checkout",
        "customerNote": null,
        "dateCompleted": null,
        "datePaid": null,
        "cartHash": "fe4745afa1c1b3ec9d9016b22a259ff8",
        "metaData": [
            {
                "id": 1635271,
                "key": "_billing_id",
                "value": "222222222222"
            },
            {
                "id": 1635272,
                "key": "_shipping_index_for_vendor_id_0",
                "value": "0"
            },
            {
                "id": 1635273,
                "key": "_shipping_method[0]",
                "value": null
            },
            {
                "id": 1635274,
                "key": "is_vat_exempt",
                "value": "no"
            },
            {
                "id": 1635275,
                "key": "wpml_language",
                "value": "es"
            },
            {
                "id": 1635276,
                "key": "wmc_order_info",
                "value": {
                    "COP": {
                        "rate": "1",
                        "pos": "left",
                        "decimals": null,
                        "custom": "COP$",
                        "hide": "0",
                        "thousandSep": null,
                        "decimalSep": null,
                        "isMain": 1
                    },
                    "USD": {
                        "rate": "0.00028979640147649",
                        "pos": "left",
                        "decimals": "2",
                        "custom": "USD$",
                        "hide": "0",
                        "thousandSep": null,
                        "decimalSep": null
                    }
                }
            },
            {
                "id": 1635277,
                "key": "pys_enrich_data",
                "value": {
                    "pysLanding": "https://feriadeflores.co/",
                    "pysSource": "direct",
                    "pysUtm": "utm_source:undefined|utm_medium:undefined|utm_campaign:undefined|utm_content:undefined|utm_term:undefined",
                    "pysUtmId": "fbadid:undefined|gadid:undefined|padid:undefined|bingid:undefined",
                    "lastPysLanding": "https://feriadeflores.co/",
                    "lastPysSource": "direct",
                    "lastPysUtm": "utm_source:undefined|utm_medium:undefined|utm_campaign:undefined|utm_content:undefined|utm_term:undefined",
                    "lastPysUtmId": "fbadid:undefined|gadid:undefined|padid:undefined|bingid:undefined",
                    "pysBrowserTime": "15-16|Tuesday|July"
                }
            },
            {
                "id": 1635278,
                "key": "Fecha de Entrega",
                "value": "8 Julio, 2025"
            },
            {
                "id": 1635279,
                "key": "_orddd_delivery_date",
                "value": "8 Julio, 2025"
            },
            {
                "id": 1635280,
                "key": "_orddd_delivery_date_label",
                "value": "Fecha de Entrega"
            },
            {
                "id": 1635281,
                "key": "_orddd_delivery_date_meta_id",
                "value": "1635278"
            },
            {
                "id": 1635282,
                "key": "_orddd_delivery_schedule_id",
                "value": "0"
            },
            {
                "id": 1635283,
                "key": "_total_delivery_charges",
                "value": "0"
            },
            {
                "id": 1635284,
                "key": "_orddd_timestamp",
                "value": "1752007815"
            },
            {
                "id": 1635285,
                "key": "_orddd_vendor_id",
                "value": [
                    0
                ]
            },
            {
                "id": 1635286,
                "key": "Entrega",
                "value": "17:00 - 22:00"
            },
            {
                "id": 1635287,
                "key": "_orddd_time_slot",
                "value": "17:00 - 22:00"
            },
            {
                "id": 1635288,
                "key": "_orddd_time_slot_label",
                "value": "Entrega"
            },
            {
                "id": 1635289,
                "key": "_orddd_time_slot_meta_id",
                "value": "1635286"
            },
            {
                "id": 1635290,
                "key": "_orddd_timeslot_timestamp",
                "value": "1752012000"
            },
            {
                "id": 1635291,
                "key": "external_id",
                "value": "863b1410015c5d7ee598b5d1f8154579449c374a46c13106614812ef26fcbbc7"
            },
            {
                "id": 1635292,
                "key": "pys_fb_cookie",
                "value": {
                    "fbc": null,
                    "fbp": "fb.1.1752007662404.8273273071"
                }
            },
            {
                "id": 1635293,
                "key": "pys_ga_cookie",
                "value": {
                    "clientId": "381828673.1752007663"
                }
            },
            {
                "id": 1635294,
                "key": "_wacv_send_reminder_email",
                "value": "0"
            },
            {
                "id": 1635295,
                "key": "_wacv_send_reminder_sms",
                "value": "0"
            },
            {
                "id": 1635296,
                "key": "_wacv_check_phone_number",
                "value": null
            },
            {
                "id": 1635297,
                "key": "_wacv_reminder_unsubscribe",
                "value": null
            },
            {
                "id": 1635298,
                "key": "_thwcfe_ship_to_billing",
                "value": "0"
            },
            {
                "id": 1635299,
                "key": "tipo_identificacion",
                "value": "13"
            },
            {
                "id": 1635300,
                "key": "billing_id",
                "value": "222222222222"
            },
            {
                "id": 1635301,
                "key": "dedicatoria_para",
                "value": "1"
            },
            {
                "id": 1635302,
                "key": "mensaje_tarjeta",
                "value": "esperanza, diana, Julian y guillermo"
            },
            {
                "id": 1635303,
                "key": "_cfw",
                "value": "true"
            },
            {
                "id": 1635304,
                "key": "_wc_order_attribution_source_type",
                "value": "typein"
            },
            {
                "id": 1635305,
                "key": "_wc_order_attribution_utm_source",
                "value": "(direct)"
            },
            {
                "id": 1635306,
                "key": "_wc_order_attribution_session_entry",
                "value": "https://feriadeflores.co/"
            },
            {
                "id": 1635307,
                "key": "_wc_order_attribution_session_start_time",
                "value": "2025-07-09T01:47:42.000Z"
            },
            {
                "id": 1635308,
                "key": "_wc_order_attribution_session_pages",
                "value": "4"
            },
            {
                "id": 1635309,
                "key": "_wc_order_attribution_session_count",
                "value": "1"
            },
            {
                "id": 1635310,
                "key": "_wc_order_attribution_user_agent",
                "value": "Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/138.0.0.0 Safari/537.36"
            },
            {
                "id": 1635311,
                "key": "_wc_order_attribution_device_type",
                "value": "Desktop"
            },
            {
                "id": 1635312,
                "key": "_orddd_lockout_reduced",
                "value": "yes"
            },
            {
                "id": 1635313,
                "key": "_wcpdf_invoice_settings",
                "value": {
                    "footer": {
                        "default": "¡Gracias por apoyar el Comercio Nacional!"
                    },
                    "displayShippingAddress": "always",
                    "displayEmail": "1",
                    "displayCustomerNotes": "1",
                    "displayDate": "invoice_date",
                    "useLatestSettings": "1",
                    "headerLogo": "75863",
                    "headerLogoHeight": "2cm",
                    "vatNumber": null,
                    "cocNumber": null,
                    "shopName": {
                        "default": "Floristería FeriadeFlores.co ramilletes, arreglos florales, fúnebres y toda ocasión"
                    },
                    "shopPhoneNumber": null,
                    "shopAddressLine1": null,
                    "shopAddressLine2": null,
                    "shopAddressCountry": null,
                    "shopAddressState": null,
                    "shopAddressCity": null,
                    "shopAddressPostcode": null,
                    "shopAddressAdditional": {
                        "default": "Rut 1102381242-0\r\nTeléfono 3022452533\r\n"
                    },
                    "extra1": {
                        "default": null
                    },
                    "extra2": {
                        "default": null
                    },
                    "extra3": {
                        "default": null
                    }
                }
            },
            {
                "id": 1635314,
                "key": "_wcpdf_invoice_display_date",
                "value": "document_date"
            },
            {
                "id": 1635315,
                "key": "_wcpdf_invoice_creation_trigger",
                "value": "email_attachment"
            },
            {
                "id": 1635316,
                "key": "_wcpdf_invoice_date",
                "value": "1752007816"
            },
            {
                "id": 1635317,
                "key": "_wcpdf_invoice_date_formatted",
                "value": "2025-07-08T20:50:16.000Z"
            },
            {
                "id": 1635318,
                "key": "_wcpdf_invoice_number",
                "value": "1158"
            },
            {
                "id": 1635319,
                "key": "_wcpdf_invoice_number_data",
                "value": {
                    "number": 1158,
                    "prefix": null,
                    "suffix": null,
                    "padding": null,
                    "formattedNumber": "1158",
                    "documentType": "invoice",
                    "orderId": 82800
                }
            },
            {
                "id": 1635320,
                "key": "_wpml_word_count",
                "value": "4"
            },
            {
                "id": 1635321,
                "key": "_pys_purchase_event_fired",
                "value": "1"
            },
            {
                "id": 1635337,
                "key": "pys_enrich_data_analytics",
                "value": {
                    "ltv": 244800,
                    "ordersCount": 1,
                    "avgOrderValue": 244800
                }
            }
        ],
        "lineItems": [
            {
                "id": 7368,
                "name": "Canasta con Frutas Canes",
                "quantity": 1,
                "subtotal": "234800.00",
                "total": "234800.00",
                "taxes": [],
                "sku": "FL091",
                "price": 234800,
                "image": {
                    "id": "75835",
                    "src": "https://feriadeflores.co/wp-content/uploads/2021/01/FL091-Canasta-con-Frutas-Canes-2.jpg"
                },
                "productId": 73876,
                "variationId": 0,
                "taxClass": null,
                "subtotalTax": "0.00",
                "totalTax": "0.00",
                "metaData": [],
                "parentName": null
            }
        ],
        "taxLines": [],
        "shippingLines": [
            {
                "id": 7369,
                "total": "10000.00",
                "taxes": [],
                "methodTitle": "Costo Domicilio",
                "methodId": "filters_by_cities_shipping_method",
                "instanceId": "52",
                "totalTax": "0.00",
                "taxStatus": "taxable",
                "metaData": [
                    {
                        "id": 64750,
                        "key": "Artículos",
                        "value": "Canasta con Frutas Canes &times; 1",
                        "displayKey": "Artículos",
                        "displayValue": "Canasta con Frutas Canes &times; 1"
                    }
                ]
            }
        ],
        "feeLines": [],
        "couponLines": [],
        "paymentUrl": "https://feriadeflores.co/facturacion/order-pay/82800/?pay_for_order=true&key=wc_order_lV8tEzEQK70Xa",
        "isEditable": true,
        "needsPayment": false,
        "needsProcessing": true,
        "tarjetaRegalo": {
            "title": null,
            "dedicatoriaPara": "1",
            "mensajeTarjeta": "esperanza, diana, Julian y guillermo",
            "quienEnvia": null
        },
        "currencySymbol": "COP$"
    }
]
