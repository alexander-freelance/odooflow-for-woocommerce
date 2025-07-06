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
