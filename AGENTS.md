# AGENTS.md — Sincronización de productos WooCommerce → Odoo

Este documento es una **guía práctica y precisa** para que Codex implemente o ajuste la sincronización de productos WooCommerce → Odoo. Aquí se detallan los requisitos funcionales, los campos a sincronizar y ejemplos concretos del formato en que WooCommerce envía los datos.

---

## ✅ ¿Qué **ya funciona** y NO se debe modificar?

* La sincronización de **clientes** y **pedidos** WooCommerce → Odoo ya funciona correctamente. No cambiar nada de esa lógica.
* El sistema ya crea/actualiza productos simples con estos campos:

  * `name` → Nombre del producto.
  * `default_code` → SKU.
  * `list_price` → Precio.
  * `description` → Descripción larga.

---

## 🚩 Objetivo de este agente

**Extender la lógica de sincronización de productos para:**

* Sincronizar **categorías** de WooCommerce (crear en Odoo si no existen y asociar al producto).
* Sincronizar **imágenes** principales del producto (imagen destacada).
* Manejar **productos variables** (y sus variantes), no solo simples.

---

## 📝 Formato de datos recibido desde WooCommerce

WooCommerce envía los productos como objetos JSON con la siguiente estructura (ejemplo real):

```json
{
  "id": 76417,
  "name": "Bouquet de Rosas, Vino y Chocolates",
  "sku": "DR057",
  "type": "variable", // puede ser 'simple' o 'variable'
  "description": "<p>…</p>",
  "price": "190800.00",
  "categories": [
    { "id": 242, "name": "Día de la Mujer" },
    { "id": 241, "name": "San Valentín" }
  ],
  "images": [
    { "src": "https://feriadeflores.co/wp-content/uploads/2022/03/Bouquet-de-Rosas-Vino-y-Chocolates.jpg" }
  ],
  "attributes": [
    { "name": "Color Rosas", "variation": true, "options": ["Rojo", "Amarillo", "Blanco"] },
    { "name": "Cantidad Rosas", "variation": true, "options": ["12 Rosas", "24 Rosas"] }
  ],
  "variations": [76448, 76449, 76450], // IDs de variaciones
  // ...otros campos
}
```

---

## 🎯 Reglas de implementación para Codex

* **Sincronizar categorías:**

  * Buscar en Odoo la categoría principal (`categories[0].name`).
  * Si no existe, crearla antes del producto.
  * Asociar el producto a esa categoría (`categ_id`).
* **Sincronizar imágenes:**

  * Descargar la primera imagen de `images[0].src`.
  * Convertir a base64 y subir a Odoo en el campo `image_1920`.
* **Sincronizar productos simples:**

  * Usar los campos ya funcionales: `name`, `default_code` (SKU), `list_price`, `description`, `categ_id`, `image_1920`.
* **Sincronizar productos variables:**

  * Crear el producto padre como tipo `variable`.
  * Crear los atributos en Odoo (`attributes`).
  * Crear todas las variantes como `product.product`, asociando SKU, precio y valores de atributo para cada variante.
  * Asociar a la misma categoría y ponerle la imagen principal.
* **NO modificar lógica de clientes ni de pedidos.**

---

## 📦 Ejemplo real de datos de WooCommerce (producto variable)

```json
{
  "id": 76417,
  "name": "Bouquet de Rosas, Vino y Chocolates",
  "sku": "DR057",
  "type": "variable",
  "description": "<p>…</p>",
  "price": "190800.00",
  "categories": [
    { "id": 242, "name": "Día de la Mujer" },
    { "id": 241, "name": "San Valentín" }
  ],
  "images": [
    { "src": "https://feriadeflores.co/wp-content/uploads/2022/03/Bouquet-de-Rosas-Vino-y-Chocolates.jpg" }
  ],
  "attributes": [
    { "name": "Color Rosas", "variation": true, "options": ["Rojo", "Amarillo", "Blanco"] },
    { "name": "Cantidad Rosas", "variation": true, "options": ["12 Rosas", "24 Rosas"] }
  ],
  "variations": [76448, 76449, 76450]
}
```

---

## 🛠️ Resumen: pasos esperados para Codex

1. **Verifica si la categoría existe en Odoo. Si no, créala.**
2. **Descarga y sube la imagen principal al producto Odoo.**
3. **Si el producto es simple:** crea/actualiza con los campos `name`, `default_code`, `list_price`, `description`, `categ_id`, `image_1920`.
4. **Si el producto es variable:**

   * Crea/actualiza el producto principal y los atributos.
   * Crea una variante por cada combinación enviada en `variations` y `attributes`, asociando SKU, precio y valores de atributo.
5. **NO tocar nada de lógica de clientes ni pedidos.**

---

## 📢 Notas finales

* El sistema ya sincroniza correctamente clientes y pedidos. Solo hay que mejorar la parte de productos como se describe.
* **No necesitas preocuparte por el resto del flujo: enfócate solo en los campos y reglas arriba.**
* Usa los nombres de los campos exactamente como vienen de WooCommerce y como espera Odoo (`name`, `default_code`, `list_price`, `description`, `image_1920`, `categ_id`, etc).

---
