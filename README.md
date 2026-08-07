# YK WC Grid Variations

Custom WooCommerce product cards for archive and category pages. Renders interactive colour swatches, size pills, a quantity stepper, and AJAX add-to-cart — all without a page reload.

Also upgrades the single product page with visual swatch/pill replacements for the native variation `<select>` dropdowns, and a +/− quantity stepper.

---

## Requirements

| Dependency | Minimum |
|------------|---------|
| WordPress  | 6.5     |
| WooCommerce | 7.0    |
| PHP        | 7.4     |

---

## Known limitation — block-based product grids

This plugin renders its product cards through WooCommerce's **classic template**
path (`wc_get_template_part( 'content', 'product' )`). It forces that path on via
the `option_wc_blocks_use_blockified_product_grid_block_as_template` filter.

As a result, **shop, category, and tag archives must use the classic /
template-based product grid — not the blockified Product Collection block** in the
Site Editor. If an archive is built with the Product Collection block, that block
bypasses the template hook entirely and the `.yk-card` swatches, size pills,
quantity stepper, and AJAX add-to-cart will not appear.

Re-test this behaviour after each major WooCommerce upgrade.

---

## Installation

1. Copy or symlink the `yk-wc-grid-variations/` folder into `wp-content/plugins/`.
2. Activate the plugin in **WP Admin → Plugins**.
3. Ensure the theme template override is in place (see [Template](#template) below).

---

## How it works

### Archive / category pages

1. **PHP** hooks into the WooCommerce product loop. For every product rendered, it collects the product ID.
2. At `wp_footer` (priority 1), it builds a single JSON payload — `window.ykWcgv` — containing variation data, image URLs, stock status, and translated strings for every product on the page.
3. **`yk-wcgv.js`** reads `window.ykWcgv` on `DOMContentLoaded` and wires up swatches, size pills, the qty stepper, and AJAX add-to-cart on every `.yk-card` element.

### Single product page

1. **PHP** injects `window.ykWcgvProduct` (a colour-slug → hex map) via `wp_footer`.
2. **`yk-wcgv-product.js`** replaces WooCommerce's native variation `<select>` dropdowns with visual swatches and pills, and upgrades `div.quantity` with +/− stepper buttons.

---

## Template

The plugin requires a custom WooCommerce template override at:

```
yk-theme/woocommerce/content-product.php
```

This file renders the `.yk-card` HTML structure that the JS targets. It calls `yk_wcgv_attr_matches()` and the `YK_WCGV_COLOR_KEYS` / `YK_WCGV_SIZE_KEYS` constants from the plugin to detect colour and size attributes — **the plugin must be active** for the template to work correctly.

---

## Markup structure

```html
<li class="yk-card">

  <!-- Image -->
  <a class="yk-card__img-link">
    <div class="yk-card__img-wrap">
      <span class="yk-badge-sale">SALE</span>   <!-- conditional -->
      <img class="yk-card__img">
    </div>
  </a>

  <div class="yk-card__body">

    <!-- Colour swatches -->
    <div class="yk-swatches">
      <span class="yk-swatch is-active" data-value="red" style="background-color:#e53e3e;"></span>
      ...
    </div>

    <!-- Size pills -->
    <div class="yk-sizes">
      <button class="yk-size is-active" data-value="m">M</button>
      ...
    </div>

    <!-- Title -->
    <a class="yk-card__title-link">
      <h2 class="yk-card__title">Product Name</h2>
    </a>

    <!-- Price -->
    <div class="yk-price">...</div>

    <!-- SKU -->
    <p class="yk-card__sku">Art.-Nr. <strong>ABC-123</strong></p>

    <!-- Qty + Add to cart -->
    <div class="yk-cart-row">
      <div class="yk-qty-wrap">
        <button class="yk-qty-minus">…</button>
        <input  class="yk-qty-input" type="number" value="1" min="1">
        <button class="yk-qty-plus">…</button>
      </div>
      <button class="yk-add-to-cart yk-btn" data-product-id="123">In den Warenkorb</button>
    </div>

    <!-- Bundle fallback (no qty/cart) -->
    <a class="yk-btn yk-btn--outline">Zum Produkt</a>

  </div>
</li>
```

---

## JS data payload

### `window.ykWcgv` (archive pages)

```js
{
  ajax_url: "https://example.com/?wc-ajax=%%endpoint%%",
  lang: "de",            // WPML current language, empty string if no WPML
  products: {
    "42": {
      type: "variable",
      color_attr: "attribute_pa_farbe",
      size_attr:  "attribute_pa_grosse",
      variations: [
        {
          variation_id: 43,
          attributes:   { "attribute_pa_farbe": "rot", "attribute_pa_grosse": "m" },
          image_url:    "https://…/image-300x300.jpg",
          image_srcset: "…",
          image_sizes:  "…",
          is_in_stock:  true,
          max_qty:      10
        }
      ]
    },
    "99": { type: "simple", is_in_stock: true, max_qty: 9999 },
    "77": { type: "bundle" }
  },
  i18n: {
    added:            "Hinzugefügt",
    error:            "Fehler. Bitte erneut versuchen.",
    select_variation: "Bitte Farbe und Grösse wählen."
  }
}
```

### `window.ykWcgvProduct` (single product page)

```js
{
  colors: {
    "rot":   "#e53e3e",
    "weiss": "#ffffff",
    ...
  }
}
```

---

## Attribute detection

The plugin detects colour and size attributes by matching the attribute slug or label against keyword lists defined as PHP constants:

```php
YK_WCGV_COLOR_KEYS = [ 'color', 'colour', 'farbe' ]
YK_WCGV_SIZE_KEYS  = [ 'size', 'größe', 'grösse', 'grosse', 'groesse', 'taille' ]
```

To add support for another language, add the translated keyword to the relevant constant in `yk-wc-grid-variations.php`.

---

## Colour resolution

Colour swatches are rendered using a built-in slug → hex map in `yk_wcgv_resolve_color()`. It recognises common colour names in English, German, and French.

If a slug is not in the map, the function checks whether it is already a valid hex value (e.g. `#ff0000` or `ff0000`) and uses it directly. Unrecognised values fall back to `#cccccc`.

---

## Style variants

The plugin reads the active WordPress style variant title and adds a `yk-variant-{slug}` class to `<body>`. CSS in `yk-wcgv.css` and `yk-wcgv-product.css` uses this to apply per-site token overrides.

**Registered variant:** `body.yk-variant-shop-attack`

Overrides: button colours, border radii, sale badge colour, add-to-cart confirmation colour, sale price colour, and error text colour for the Shop Attack brand palette.

To add a new variant, add a CSS block scoped to `body.yk-variant-{your-slug}` in `yk-wcgv.css` and/or `yk-wcgv-product.css`.

---

## WPML

On `init` (priority 20), the plugin registers all user-facing strings with `icl_register_string()` under the context `yk-wc-grid-variations`. Translations set in the WPML String Translation screen are picked up automatically.

---

## File structure

```
yk-wc-grid-variations/
├── yk-wc-grid-variations.php   Main plugin file: hooks, data builder, helpers
├── assets/
│   ├── css/
│   │   ├── yk-wcgv.css         Archive page styles + SHA variant overrides
│   │   └── yk-wcgv-product.css Single product page styles
│   └── js/
│       ├── yk-wcgv.js          Archive page interactivity (swatches, cart, qty)
│       └── yk-wcgv-product.js  Single product page (swatch/pill replacements, qty stepper)
└── languages/                  .po / .mo translation files (optional)
```

**Theme dependency:**

```
yk-theme/woocommerce/
└── content-product.php         Card markup template (required)
```
