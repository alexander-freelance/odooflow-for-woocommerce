# OdooFlow — Mapping «Agents» Guide

> \*\*Purpose  This document centralises *all* the field‑mapping rules (our “agents”) that the OdooFlow plugin applies when synchronising WooCommerce with Odoo.

**Target Odoo version:** **Odoo 18 Enterprise** (self‑hosted) with Colombian localisation modules `l10n_co`, `l10n_co_edi`.

---

## 1. Quick Start

| Task                               | PHP entry‑point                             | Odoo model         | Helper(s)                                                                    |
| ---------------------------------- | ------------------------------------------- | ------------------ | ---------------------------------------------------------------------------- |
| **Export product → Odoo**          | `export_product_to_odoo()`  (\~L 1 870)     | `product.template` | `handle_product_categories()` · `handle_product_image()`                     |
| **Import / update product ← Odoo** | *Not yet implemented*                       | `product.template` | —                                                                            |
| **Export customers → Odoo**        | `ajax_export_woo_customers()`  (\~L 2 400)  | `res.partner`      | `oflow_add_col_fields()`                                                     |
| **Import customers ← Odoo**        | `ajax_import_odoo_customers()`  (\~L 2 120) | `res.partner`      | `update_wc_customer()`                                                       |
| **Push order → Odoo**              | `sync_order_to_odoo()`  (\~L 2 680)         | `sale.order`       | `prepare_order_data()` · `prepare_order_lines()` · `prepare_shipping_line()` |

> **Tip**  Use the line numbers as anchors when browsing *odooflow\.php* in your IDE.

---

## 2. Mapping Reference

### 2·1  Product (`product.template`)

| WooCommerce getter     | Odoo field      | Notes                                                              |
| ---------------------- | --------------- | ------------------------------------------------------------------ |
| `get_name()`           | `name`          | Always exported                                                    |
| `get_sku()`            | `default_code`  | 1‑to‑1                                                             |
| `get_regular_price()`  | `list_price`    | Export only when the *“Send price”* checkbox is ticked in settings |
| `get_description()`    | `description`   | HTML allowed                                                       |
| `get_stock_quantity()` | `qty_available` | Stock must be enabled in WC                                        |
| `get_weight()`         | `weight`        | Sent as *kg*                                                       |

**Categories & Images**  The helper stubs live in `handle_product_categories()` and `handle_product_image()`.  They currently resolve WC category IDs to Odoo `product.category` IDs and push the *featured image* only.  Extend as needed.

---

### 2·2  Customer (`res.partner`)

#### Export (WC → Odoo)

| Woo meta / getter              | Odoo field                          | Logic                                                   |
| ------------------------------ | ----------------------------------- | ------------------------------------------------------- |
| First + Last name              | `name`                              | Concatenate with space                                  |
| `get_email()`                  | `email`                             | Mandatory                                               |
| `get_billing_phone()`          | `phone`                             | Fallback to `mobile` if empty                           |
| Billing `address_1`            | `street`                            |                                                         |
| Billing `address_2`            | `street2`                           |                                                         |
| Billing `city`                 | `city`                              |                                                         |
| **`billing_id` meta**          | `vat`                               | Sanitised (only 0‑9A‑Z)                                 |
| **`tipo_identificacion` meta** | `l10n_latam_identification_type_id` | Resolved via ***oflow\_add\_col\_fields()*** → see §2·4 |
| (implicit)                     | `customer_rank` = 1                 | Marks as customer                                       |
| (implicit)                     | `type` = `contact`                  | Root contact, no company yet                            |

#### Import (Odoo → WC)

The inverse mapping lives in `ajax_import_odoo_customers()` and `update_wc_customer()`.  It updates or creates a WC *customer* role user and stores:

* `_odoo_customer_id`   (bridge key)
* `tipo_identificacion` meta   ← resolved from Odoo → WC numeric code (via reverse map)
* `billing_id`              ← from `vat`
* `billing_country`, `billing_departamento` meta for DIAN helper

---

### 2·3  Order (`sale.order`)

*Prepared in* **`prepare_order_data()`**

| Source                              | Odoo field                   | Notes                                 |
| ----------------------------------- | ---------------------------- | ------------------------------------- |
| `get_or_create_odoo_customer()`     | `partner_id`                 | Many2one returned by customer export  |
| `'WC' . $order->get_order_number()` | `name`                       | Human‑readable                        |
| `get_date_created()`                | `date_order`                 | ISO 8601                              |
| `map_order_status()`                | `state`                      | Draft/confirmed/cancelled             |
| `prepare_order_lines()`             | `order_line`                 | See below                             |
| WC totals                           | `amount_tax`, `amount_total` | Rounded to 2 decimals                 |
| Currency                            | `currency_id`                | via `get_currency_id()` (placeholder) |
| Customer note                       | `note`                       | Optional                              |

#### Order lines (`sale.order.line`)

| Element            | Odoo field        | Rule                                |
| ------------------ | ----------------- | ----------------------------------- |
| Product ID         | `product_id`      | From `get_or_create_odoo_product()` |
| Name               | `name`            | Product title + variation           |
| Qty                | `product_uom_qty` | Uses default UoM                    |
| Subtotal excl. tax | `price_unit`      | Converted to order currency         |
| Taxes              | `tax_id`          | Via `get_tax_ids()` (placeholder)   |

Shipping is appended as an extra line via `prepare_shipping_line()`.

---

### 2·4  Colombian DIAN helper (`oflow_add_col_fields()`)

Responsible for enriching any **partner payload** (customer or order shipping contact) with:

1. **VAT** (`vat`) from meta `billing_id`.
2. **Tipo de identificación** (`l10n_latam_identification_type_id`):

   * Translates meta `tipo_identificacion` (values `11, 12, 13, 22, 31, 41, 42, 48` …) → the textual code stored in Odoo (`rut`, `national_citizen_id`, etc.).
   * Looks up the corresponding record in `l10n_latam.identification.type` via `l10n_co_document_code` and caches the ID.

   | DIAN code | Odoo code            |
   | --------- | -------------------- |
   | `11`      | `civil_registration` |
   | `12`      | `identity_card`      |
   | `13`      | `national_citizen_id`|
   | `22`      | `foreign_id_card`    |
   | `31`      | `rut`                |
   | `41`      | `passport`           |
   | `42`      | `diplomatic_passport`|
   | `48`      | `other`              |
3. **Country** → `country_id` (calls `lookup_country_id()`)
4. **State/Departamento** → `state_id` (calls `lookup_state_id()`)
5. **City** → `city`

> **Warning**  If country = Colombia but the tipo de identificación lookup fails (e.g. bad code), Odoo will accept the partner but localised EDIs might fail.  Always keep the map in `oflow_tipo_map()` in sync with DIAN codes.

---

## 3. Helper Look‑ups & Place‑Holders

| Helper                | Status        | Comment                               |
| --------------------- | ------------- | ------------------------------------- |
| `get_tax_ids()`       | ⚠ *TODO*      | Map Woo tax class → `account.tax` IDs |
| `get_currency_id()`   | ⚠ *TODO*      | Resolve ISO 4217 → `res.currency`     |
| `lookup_country_id()` | ✔ implemented | Cached by ISO alpha‑2 code            |
| `lookup_state_id()`   | ✔ implemented | `ilike` search within country         |

Add implementation notes here when you finish a placeholder.

---

## 4. Conventions & Style

* Keep **all mapping lists** in tables for readability.
* Use inline comments with the pattern `// <WC_field> → <Odoo_field>` inside PHP.
* When adding a new mapping, update this doc *and* the Quick Start table.
* Functions that call Odoo must log failures through `error_log('OdooFlow: …')`.
* Stick to **snake\_case** for array keys matching Odoo fields, **camelCase** for PHP variables.

---

## 5. Test Checklist (before PR)

* [ ] Product export with price and image
* [ ] Customer export (guest + registered)
* [ ] Tipo de identificación resolves correctly for DIAN codes 13 & 31
* [ ] Order with tax, coupon and shipping lines
* [ ] Import customers from Odoo creates WC users
* [ ] Error path: invalid DIAN code logs a warning but does *not* break sync

---

> *Last updated: 2025‑07‑07.*  Add your initials when you touch this file. [Codex]
