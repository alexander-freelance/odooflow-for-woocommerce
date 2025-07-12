# AGENTS.md for Codex Agent

Este archivo `AGENTS.md` proporciona a OpenAI Codex instrucciones precisas para la integración **WooCommerce ↔ Odoo 18 Enterprise**, con énfasis en la **sincronización de pedidos** vía XML-RPC, reutilizando la lógica existente de clientes y productos.

---

## Descripción General

- **Objetivo**: Sincronizar clientes, productos y pedidos de WooCommerce a Odoo 18 Enterprise autohospedado.
- **Alcance Actual**:
  - La **sincronización individual** de clientes y productos ya funciona correctamente (reutilice `get_or_create_odoo_customer()` y `get_or_create_odoo_product()`).
  - **Pedidos**: implementar flujo que invoque esas funciones para cada línea, y luego llame a `sale.order.create` vía XML-RPC.
- **Problema Actual**:
  - Al hacer clic en “Sync to Odoo” en la interfaz de pedidos, WooCommerce devuelve una pantalla en blanco con error crítico y no se crea nada en Odoo.

# AL crear la orden
- Si `get_or_create_odoo_customer()` **no encuentra** al cliente, **lo crea** y guarda `user_meta` `_odoo_customer_id`.
- Si `get_or_create_odoo_product()` **no encuentra** el producto o variante, **lo crea** y guarda `post_meta` `_odoo_product_id`.
- En ambos casos, si ya existe, **devuelven el ID** y se **asignan** a la orden.

  
# El plugin no envía JSON: **construye y envía solicitudes XML-RPC** 

-te dejo un ejemplo de un json con las claves que envia woocommerce al octener un pedido, para que puedas reutilizar las que consideres necesarias
Ejemplo Simplificado de JSON (WooCommerce)

```json
{
  "id": 82800,
  "status": "on-hold",
  "currency": "COP",
  "dateCreated": "2025-07-08T20:50:15.000Z",
  "total": "244800.00",
  "totalTax": "0.00",
  "shippingTotal": "10000.00",

  "billing": {
    "email": "doris423@hotmail.com",
    "firstName": "doris",
    "lastName": "",
    "address1": "Calle 56 A #1 F42 las Mercedes",
    "city": "Neiva",
    "state": "HUI",
    "country": "CO",
    "tipoIdentificacion": "13",
    "billingId": "222222222222"
  },

  "shipping": {
    "firstName": "Gloria camacho",
    "address1": "Calle 56 A #1 F42 las Mercedes",
    "city": "Neiva",
    "state": "HUI",
    "country": "CO",
    "phone": "3212199850"
  },

  "metaData": [
    { "key": "external_id", "value": "863b1410015c5d7ee598b5d1f8154579449c374a46c13106614812ef26fcbbc7" },
    { "key": "tipo_identificacion", "value": "13" },
    { "key": "billing_id", "value": "222222222222" }
  ],

  "lineItems": [
    {
      "sku": "FL091",
      "quantity": 1,
      "price": 234800.00,
      "taxes": []
    }
  ],

  "shippingLines": [
    {
      "methodId": "filters_by_cities_shipping_method",
      "total": "10000.00",
      "taxes": []
    }
  ]
}
