# YK WC Grid Variations

Custom WooCommerce product cards for archive and category pages. Renders interactive colour swatches, option pills, a quantity stepper, and AJAX add-to-cart — all without a page reload.

Also upgrades the single product page with visual swatch/pill replacements for the native variation `<select>` dropdowns, and a +/− quantity stepper.

---

## Requirements

| Dependency | Minimum |
|------------|---------|
| WordPress  | 6.5     |
| WooCommerce | 7.0    |
| PHP        | 7.4     |

---

## Installation

1. Copy or symlink the `yk-wc-grid-variations/` folder into `wp-content/plugins/`.
2. Activate the plugin in **WP Admin → Plugins**.
3. Configure it under **WooCommerce → YK Grid Variations** (see [Settings](#settings)).
4. If you are upgrading and swatch images look oversized, regenerate thumbnails once — see [Swatch image size](#swatch-image-size).
5. Work through [PRE-PRODUCTION-CHECKLIST.md](PRE-PRODUCTION-CHECKLIST.md) — it lists what to
   verify on a live catalogue (bundles, out-of-stock products, WPML strings, page-cache rules).

No theme changes are required. The card template ships inside the plugin.

---

## Limitations

Read this section before rolling the plugin out; each item fails by showing the *default* WooCommerce card rather than by throwing an error, which is easy to miss.

### Block-based product grids are not supported

The plugin renders its cards through WooCommerce's **classic template** path (`wc_get_template_part( 'content', 'product' )`), and forces that path on via the `option_wc_blocks_use_blockified_product_grid_block_as_template` filter.

So **shop, category, and tag archives must use the classic / template-based product grid — not the blockified Product Collection block** in the Site Editor. The Product Collection block emits its own markup and never reaches the template hook, so `.yk-card` swatches, pills, the quantity stepper and AJAX add-to-cart simply do not appear.

That option name is WooCommerce-internal. **Re-test after every major WooCommerce upgrade** — if the filter stops working, the cards disappear wholesale.

### Only archive-type pages get cards

Cards are rendered when `is_shop()`, `is_product_category()`, `is_product_tag()` or `is_product_taxonomy()` is true. **Search results, related/upsell/cross-sell blocks and `[products]` shortcode grids keep the default WooCommerce card** — deliberately, so the loop-hook removal cannot leak into loops the plugin does not own.

### Swatch overrides need global attributes

Explicit swatch colours and images live in **term meta**, and only global attributes (`pa_*`) have terms. A product-level *custom* attribute has no terms, so it can never carry a swatch override and always falls back to the built-in colour-name map — which cannot express two-tone colours.

If a client needs a two-tone swatch, that attribute has to become a global attribute.

### WPML does not copy term meta

Translated colour terms do not inherit the source term's swatch colour or image, because WPML does not copy term meta to translations. A translated term therefore resolves through the colour-name map only, and may render grey where the source term renders a two-tone swatch. Set the swatch on each translated term as well.

---

## Settings

**WooCommerce → YK Grid Variations.** All settings live in a single option, `yk_wcgv_settings`.

### Product Cards — archive and category pages

| Setting | Key | Default | Effect |
|---|---|---|---|
| Sale badge | `show_sale_badge` | on | `.yk-badge-sale` on discounted products |
| SKU | `show_sku` | on | `Art.-Nr.` line beneath the title |
| Variation swatches & pills | `show_variations` | on | Off leaves a plain card with title, price and add-to-cart |
| Quantity stepper | `show_qty_stepper` | on | Off drops `.yk-qty-wrap`; the card adds quantity 1 |

Attributes are discovered automatically — see [Attribute detection](#attribute-detection). There is no attribute whitelist to maintain.

### Archive paging

| Setting | Key | Default | Effect |
|---|---|---|---|
| Mode | `pagination_mode` | `pagination` | `pagination` = WooCommerce's own links, `infinite` = [infinite scroll](#infinite-scroll) |
| Prefetch distance | `prefetch_distance` | `400` (px) | How far before the end of the list the next page starts loading. 0–5000 |

Individual categories override the mode on their own edit screen (**Products → Categories → edit**), where the default is *Inherit*.

### Theme style variation

| Setting | Key | Default | Effect |
|---|---|---|---|
| Active variation | `style_variant` | *(none)* | Adds `body.yk-variant-{slug}` — see [Style variants](#style-variants) |

### Single Product Page

| Setting | Key | Default | Effect |
|---|---|---|---|
| Enhanced variation UI | `enable_product_page` | on | Replaces the native variation dropdowns with swatches/pills and adds the stepper |

### Filters

```php
// Force a paging mode per archive. $context: is_shop, taxonomy, term_id, term_slug.
add_filter( 'yk_wcgv_pagination_mode', fn( $mode, $context ) => $mode, 10, 2 );

// Prefetch distance in pixels.
add_filter( 'yk_wcgv_prefetch_distance', fn( $px ) => $px );

// Style variation title; has the last word over the setting.
add_filter( 'yk_wcgv_style_variant', fn( $title ) => 'Shop Attack' );
```

---

## How it works

### Archive / category pages

1. **PHP** hooks into the WooCommerce product loop and collects the ID of every product rendered.
2. `wc_get_template_part` is filtered so the loop renders the plugin's own `woocommerce/content-product.php` — the `.yk-card` markup.
3. At `wp_footer` (priority 1) a single payload, `window.ykWcgv`, is built for exactly those products: attributes, variations, image and price pools, stock, and translated strings.
4. **`yk-wcgv.js`** reads it on `DOMContentLoaded` and wires up every `.yk-card`.

All variation data is serialised server-side, so clicking a swatch or pill costs no network request. Only add-to-cart talks to the server, through WooCommerce's own `?wc-ajax=add_to_cart` endpoint.

### Single product page

1. **PHP** injects `window.ykWcgvProduct` — the same attribute/swatch data as the archive, keyed for direct lookup.
2. **`yk-wcgv-product.js`** replaces WooCommerce's native variation `<select>` dropdowns with `.yk-sp-swatch` / `.yk-sp-size` controls, and upgrades `div.quantity` with +/− stepper buttons.

The native selects stay in the DOM as the state holders. Clicking a swatch writes into the select and dispatches `change`; **WooCommerce's `variations_form` remains the single source of truth** for which combinations are available, and for the price and SKU shown on the page. The plugin only mirrors that state visually.

---

## Template

The card template lives **inside the plugin**:

```
yk-wc-grid-variations/woocommerce/content-product.php
```

It is injected by filtering `wc_get_template_part`, so **no theme override is required** and none should be created — a theme-level `woocommerce/content-product.php` would compete with this filter.

The template calls `YK_WCGV_Data::build_attributes()`, the same method that builds the JS payload, so the markup and `window.ykWcgv` can never disagree about which attributes exist, what their options are, or in what order they appear.

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

    <!-- One group per variation attribute, in back-office order. -->
    <div class="yk-swatches" role="group" data-attribute="attribute_pa_farbe" aria-label="Farbe">
      <span class="yk-swatch is-active" data-value="rot" data-label="Rot"
            style="background-color:#e53e3e;" title="Rot" aria-label="Rot"></span>
    </div>

    <div class="yk-sizes" role="group" data-attribute="attribute_pa_grosse" aria-label="Grösse">
      <button class="yk-size is-active" data-value="m">M</button>
    </div>

    <!-- Title -->
    <a class="yk-card__title-link">
      <h2 class="yk-card__title">Product Name</h2>
    </a>

    <!-- Price -->
    <div class="yk-price">
      <span class="yk-price__value"><!-- WooCommerce price markup --></span>
      <span class="yk-price__tax">inkl. MwSt.</span>
    </div>

    <!-- SKU -->
    <p class="yk-card__sku">Art.-Nr. <strong>ABC-123</strong></p>

    <!-- Qty + Add to cart -->
    <div class="yk-cart-row">
      <div class="yk-qty-wrap">
        <button class="yk-qty-minus">…</button>
        <input  class="yk-qty-input" type="number" value="1" min="1" readonly>
        <button class="yk-qty-plus">…</button>
      </div>
      <button class="yk-add-to-cart yk-btn yk-btn--primary" data-product-id="123">In den Warenkorb</button>
    </div>

    <!-- Bundle products: link instead of qty/cart -->
    <a class="yk-btn yk-btn--outline">Zum Produkt</a>

  </div>
</li>
```

**`data-attribute` is the contract.** It is the only thing joining a group in the markup to its entry in the payload; renaming or removing it makes the JS unable to find the group. The JS finds groups by `[data-attribute]` and options by `.yk-swatch, .yk-size`.

The class names are historical: `.yk-sizes` / `.yk-size` mean **"pill style"**, not "size attribute". A `Frequenz` attribute renders in them too. They are kept so existing site CSS keeps working.

Swatch fills are inline styles, always as background **longhands** (`background-color`, `background-image`) — never the `background:` shorthand, which would reset a colour painted underneath. No CSS file edit is needed to add a colour.

### Single product page classes

Single-product controls use the **`.yk-sp-`** prefix, so archive CSS and product-page CSS never collide:

```html
<div class="yk-sp-swatches" role="group" data-attribute="attribute_pa_farbe" aria-label="Farbe">
  <span class="yk-sp-swatch is-active" data-value="rot"></span>
</div>
<div class="yk-sp-sizes" role="group" data-attribute="attribute_pa_grosse">
  <button class="yk-sp-size" data-value="m">M</button>
</div>
```

State classes are shared between both screens: `is-active`, `is-disabled`, `is-light`, `has-image`.

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
      // Every attribute marked "Used for variations", in back-office order.
      attributes: [
        {
          name:  "attribute_pa_farbe",   // matches the keys in variations[].attributes
          label: "Farbe",
          style: "swatch",               // "swatch" | "pill"
          options: [
            {
              value: "schwarz",          // EXACT value stored on the variation
              label: "Schwarz",
              // Present ONLY on options of a style: "swatch" attribute — see below.
              swatch: {
                type:     "color",       // "image" | "color" | "gradient" | "none"
                color:    "#1a1a1a",
                color2:   "",            // only for "gradient"
                image:    "",            // yk_wcgv_swatch size (96×96)
                is_light: false
              }
            }
          ]
        },
        {
          name:  "attribute_pa_grosse",
          label: "Grösse",
          style: "pill",
          options: [
            { value: "m", label: "M" }   // no `swatch` key on pill options
          ]
        }
      ],
      // Image pool for this product. Variations reference it by index instead of
      // repeating the URL, which otherwise dominates the payload size.
      images: [
        { url: "https://…/image-300x300.jpg" }   // url only — no srcset/sizes
      ],

      // Price markup pool, same idea as images[]. Most variations of a product share a
      // price (measured: 149 variations → 44 distinct strings), and each string is ~230
      // bytes of WooCommerce markup, so they are stored once.
      prices: [
        "<span class=\"woocommerce-Price-amount\">CHF 19.10</span><small class=\"woocommerce-price-suffix\">inkl. MwSt.</small>",
        "<del>…</del> <ins>…</ins><small class=\"woocommerce-price-suffix\">inkl. MwSt.</small>"
      ],

      variations: [
        {
          variation_id: 43,
          attributes:   { "attribute_pa_farbe": "rot", "attribute_pa_grosse": "m" },
          img:          0,        // index into images[], or null when there is no image
          price:        0,        // index into prices[], or null when there is no price
          sku:          "ART-1",  // plain string: SKUs are unique, a pool would cost more
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
    select_variation: "Bitte alle Optionen wählen.",
    loading:          "Produkte werden geladen …",
    all_loaded:       "Alle Produkte geladen.",
    load_error:       "Laden fehlgeschlagen.",
    retry:            "Erneut versuchen"
  }
}
```

**Reading the payload:**

- `options[].swatch` is **optional**. It exists only when the attribute's `style` is
  `"swatch"`; pills never carry it. Always guard with `option.swatch && …`.
- `swatch.color` is filled in even when `swatch.type === "image"`. There it is a
  contrast/border hint (from term meta or the colour-name map), **not** the swatch fill —
  render the image in that case. On `"none"`, fall back to `#cccccc`.
- `variations[].img` is an index into that product's `images[]` array, or `null` when the
  variation has no image (no own image, no parent image, or the attachment was deleted).
  Variations sharing an attachment share one pool entry — including the common case where
  they all fall back to the parent product image, which then appears in the pool once.
  Entries carry `url` only: the swap happens inside a fixed-size card slot, so `srcset`
  and `sizes` bought nothing and cost roughly 80% of the payload.
- `variations[].price` works the same way against `prices[]`, and is `null` when the
  variation has no price. `sku` stays a plain string: SKUs measured 100% unique, so pooling
  them would add index bytes without removing any.
- Price and SKU are applied only once **every** attribute is chosen. While a selection is
  incomplete the card keeps the server-rendered parent values (price range, parent SKU) —
  blanking them mid-selection makes the whole grid flicker.

**Selection is a cascade.** The first attribute is always fully clickable, and each further attribute is filtered **only by the attributes above it**. Changing one attribute clears every selection below it. Constraining upwards would dead-end the card: a sold-out size would disable every other colour, leaving no way out.

### `window.ykWcgvProduct` (single product page)

Same information as the archive `attributes[]`, keyed for direct lookup: attributes by the
`attribute_*` name the variation `<select>` carries in `data-attribute_name`, options by
their value. `style` and `swatch` come from the same PHP that builds the archive payload,
so a given attribute renders identically on both screens.

```js
{
  attributes: {
    "attribute_pa_farbe": {
      label: "Farbe",
      style: "swatch",                    // "swatch" | "pill"
      options: {
        "schwarz": {
          label: "Schwarz",
          swatch: { type: "color", color: "#2d5016", color2: "", image: "", is_light: false }
        }
      }
    },
    "attribute_pa_frequenzband": {
      label: "Frequenzband",
      style: "pill",
      options: {
        "uhf-470-530-mhz": { label: "UHF (470 - 530 MHz)" }   // no `swatch` key on pills
      }
    }
  },

  // Legacy slug => hex map. Kept for backwards compatibility only — it cannot express
  // two-tone or image swatches. New code reads `attributes`.
  colors: { "rot": "#e53e3e", "weiss": "#ffffff" },

  // Same strings as the archive payload, from the same PHP source.
  i18n: { reset_selection: "Auswahl zurücksetzen", … }
}
```

An attribute missing from `attributes` (a translated taxonomy, say) falls back to **pills**, so a native `<select>` is never left visible.

### Escaping a dead end

On a product with three or more attributes an invalid combination leaves WooCommerce disabling every remaining option — and WC hides its own *Clear* link unless a variation has resolved, so nothing on screen offers a way out. (Re-clicking the still-active option does escape, but shoppers do not discover that.)

The plugin therefore renders a `.yk-sp-reset` button under the attribute table, **visible only once something is selected**. It resets nothing itself: it clicks WooCommerce's own `.reset_variations` link. If a theme or WC version ships no such link, the button is not rendered at all rather than growing its own reset logic — a second writer on the selects is what silently wiped selections in earlier versions.

This applies to the single product page only. Archive cards cascade (each attribute filtered only by the ones above it), so they cannot dead-end and carry no reset control.

---

## Attribute detection

**Which attributes are shown** — every attribute of the product that is marked *Used for
variations* in the back office, in the order set there. Nothing is hardcoded to colour and
size, so e.g. an antenna's "Frequenz" attribute appears like any other. Attributes that are
not used for variations are descriptive only and are skipped.

**How an attribute is rendered** (`style`), in this order:

1. Any of its terms has an explicit swatch override in term meta → `swatch`
2. The attribute slug/label matches `YK_WCGV_COLOR_KEYS` → `swatch`
3. Everything else → `pill`

```php
YK_WCGV_COLOR_KEYS = [ 'color', 'colour', 'farbe' ]   // style fallback (rule 2)
```

Rule 1 reads the raw term meta, never `yk_wcgv_get_swatch()` — that helper falls back to the
colour-name map, so a "Rot"-named frequency option would otherwise be mistaken for a colour.

To add support for another language, add the translated keyword to `YK_WCGV_COLOR_KEYS` in
`yk-wc-grid-variations.php`. That constant is the only place it has to go: pills need no
keyword list, since a pill is simply "not a swatch".

> `YK_WCGV_SIZE_KEYS` still exists but the plugin no longer reads it. It is kept only for
> 1.x-era theme snippets that reference it.

**Option values:** taxonomy attributes use the term slug, custom (non-taxonomy) attributes
use the raw option string — exactly what WooCommerce stores on the variation. Never
`sanitize_title()` a custom attribute value; it would no longer match and add-to-cart fails.

---

## Colour resolution

Lookup order in `yk_wcgv_get_swatch()`: **image → term meta hex → colour-name map → none.**

The name map in `yk_wcgv_resolve_color()` recognises common colour names in English, German
and French. If a slug is not in the map, the function checks whether it is already a valid
hex value (e.g. `#ff0000` or `ff0000`) and uses it directly. Unrecognised values fall back to
`#cccccc`.

### Setting a colour or image on a term

This is how two-tone colours such as "Schwarz/Gelb" are handled — no name map can express them.

1. Go to **Products → Attributes**.
2. On the attribute's row (e.g. *Farbe*), click **Configure terms**.
3. Click the term you want to change, or use the *Add new* form on the left.
4. **Swatch colour** — type a hex value, or use the two colour pickers:
   - single colour: `#000000`
   - two-tone: `#000000,#ffcc00` — rendered as a 135° split
   - a third value onwards is ignored
   - press **Single colour** to drop the second colour again
   - leave it empty to fall back to the built-in colour-name map
5. **Swatch image** — **Select image** opens the media library; **Remove** clears it.
   An image takes precedence over the colour above it. Keep the colour filled in anyway:
   it is used as a contrast hint for the chip border.
6. **Update**. The round preview next to the field, and the **Swatch** column in the term
   list, show the result immediately.

Saving a term flushes the plugin's product cache, so the front end updates on the next page load.

Fields appear on `pa_*` attribute terms only — product categories and tags have no swatches.

### Swatch image size

Swatch images are delivered at the registered `yk_wcgv_swatch` size (96×96, cropped).
Images uploaded **before** this size existed do not have that file yet; WordPress then falls
back to the full-size image, so swatches still render but download far more than needed.

After upgrading, regenerate thumbnails once — for example with the *Regenerate Thumbnails*
plugin, or `wp media regenerate --only-missing` on the CLI.

---

## Infinite scroll

With **Archive paging → Mode** set to *Infinite scroll* (globally or per category), `yk-wcgv-infinite.js` watches a sentinel element with an `IntersectionObserver` and appends the next page before the shopper reaches the end. Newly inserted cards are initialised through `window.ykWcgvArchive.initCards( node )`; `initCard()` is idempotent, guarded by `data-yk-init`.

WooCommerce's pagination links are **not removed** — they are only hidden with `.yk-pagination--hidden`, so crawlers, users without JS, and anyone hitting a failed request still have a working control. If an archive contains no cards at all (a category page showing only subcategory tiles), no sentinel is created, so there is no empty-response loop.

### The endpoint

| | |
|---|---|
| Action | `wp_ajax_yk_wcgv_load_page` / `wp_ajax_nopriv_…` on `admin-ajax.php` |
| Nonce | `yk_wcgv_infinite`, required |
| Returns | Card markup plus the payload for those products — **not** a full page |
| Query vars | Whitelisted: `product_cat`, `product_tag`, `paged`, `orderby`, `s`, `min_price`, `max_price`, `yk_taxonomy`, `yk_term`, plus `filter_*` / `query_type_*` for layered nav |

`post_status`, `posts_per_page` and `meta_query` are **never** taken from the request.

Products per page is read from an option (`yk_wcgv_loop_per_page`) recorded by the front-end loop, not from `apply_filters( 'loop_shop_per_page', … )`. Themes commonly register that filter behind `! is_admin()`, so admin-ajax computes a different number than the front end — and page 2 would then start in the middle of page 1.

### Page caching — important

The endpoint sends `nocache_headers()` and `Cache-Control: no-store`. Make sure your page cache, CDN and any reverse proxy honour that for `admin-ajax.php`:

- **Never cache `admin-ajax.php`.** A cached response serves one shopper's page 2 to everyone, with stale prices and stock.
- The nonce is embedded in the *archive HTML*. If the archive page itself is served from a full-page cache for longer than the nonce lifetime (12–24 h), the nonce expires and infinite scroll stops loading — pagination links still work, so the failure is quiet. Either exclude archives from full-page caching, or keep the cache TTL below 12 hours.
- Exclude cart, checkout and account pages from caching as usual. On this stack `/warenkorb/` was observed being cached, which breaks the AJAX cart fragments.

The payload itself is cached in transients (`yk_wcgv_v…`, 12 h) and invalidated on product, stock, term, price, currency and tax changes. The TTL is additionally shortened so it never spans the start of a scheduled sale.

---

## Style variants

The plugin adds a `yk-variant-{slug}` class to `<body>`. CSS in `yk-wcgv.css` and `yk-wcgv-product.css` uses it to override the `--yk-*` design tokens per site, without touching individual rules.

Pick the variation under **WooCommerce → YK Grid Variations → Theme style variation**. The dropdown lists the titles the active theme ships (`yk-theme/styles/*.json`), plus *— None —*. Selecting *Shop Attack* produces `body.yk-variant-shop-attack`.

> **Why a setting and not auto-detection?** WordPress does not record which style variation is active. When the Site Editor applies one, it copies that file's `settings` and `styles` into the user global styles and drops the `title`; the global-styles post is always called *Custom Styles*. There is nothing left to read, so the variation has to be named explicitly. Choosing here does **not** switch the variation — it only tells the plugin which one is in use.

The filter still overrides the setting, for sites that configure this in code:

```php
add_filter( 'yk_wcgv_style_variant', fn() => 'Shop Attack' );  // → body.yk-variant-shop-attack
```

**Registered variant:** `body.yk-variant-shop-attack`

Overrides: button colours, border radii, sale badge colour, add-to-cart confirmation colour, sale price colour, and error text colour for the Shop Attack brand palette.

To add a new variant, add a CSS block scoped to `body.yk-variant-{your-slug}` in `yk-wcgv.css` and/or `yk-wcgv-product.css`.

---

## WPML

On `init` (priority 20), the plugin registers all user-facing strings with `icl_register_string()` under the context `yk-wc-grid-variations`. Translations set in the WPML String Translation screen are picked up automatically.

Attribute group labels are not registered here — they come from the WooCommerce attribute label and are translated by WPML's taxonomy translation.

The payload cache is keyed per language. **Multi-currency caveat:** if a site uses WCML to switch *currency* without switching language, the cache key does not distinguish currencies and can serve the wrong one. Add the currency code to `YK_WCGV_Data::cache_key()` before deploying to such a site. Language-per-currency setups (the tested configuration) are safe.

---

## File structure

```
yk-wc-grid-variations/
├── yk-wc-grid-variations.php       Bootstrap: constants, includes, init(), HPOS declaration
├── includes/
│   ├── functions-helpers.php       Swatch resolution, colour map, asset versioning
│   ├── class-yk-wcgv-i18n.php      Text domain, JS strings, WPML registration
│   ├── class-yk-wcgv-settings.php  Settings page, defaults, sanitisation, migrations
│   ├── class-yk-wcgv-data.php      Loop ID collection, payload builder, transient cache
│   ├── class-yk-wcgv-assets.php    Enqueues and inline payload injection
│   ├── class-yk-wcgv-template.php  Template override, loop hooks, body_class
│   ├── class-yk-wcgv-ajax.php      Infinite-scroll endpoint
│   ├── class-yk-wcgv-term-meta.php Swatch colour/image fields on pa_* terms   (admin only)
│   └── class-yk-wcgv-category-meta.php  Per-category paging override          (admin only)
├── woocommerce/
│   └── content-product.php         Card markup, injected via wc_get_template_part
├── assets/
│   ├── css/
│   │   ├── yk-wcgv.css             Archive styles + --yk-* tokens + variant overrides
│   │   └── yk-wcgv-product.css     Single product page styles (.yk-sp-*)
│   └── js/
│       ├── yk-wcgv.js              Archive cards (swatches, pills, qty, AJAX cart)
│       ├── yk-wcgv-product.js      Single product page (select → swatch/pill, stepper)
│       ├── yk-wcgv-infinite.js     Infinite scroll
│       └── yk-wcgv-admin.js        Term screen (colour sync, media frame)
└── languages/                      .po / .mo translation files (optional)
```

Development note: assets are enqueued through `yk_wcgv_asset_version()`, which returns `filemtime()` while `WP_DEBUG` is on and `YK_WCGV_VERSION` in production. Saving a CSS or JS file is enough to bust the cache on a dev site.

---

## Branches

`main` is the trunk. Feature and fix branches merge into it by pull request.

**`feat/colour-browser-card` is not one of them.** It rebuilds the archive card for one
client, Lavie: the card becomes a colour browser — a scroll-snap slider with one slide per
colour, a swatch row bound to those slides, and a link into the product page with that
colour preselected — and add-to-cart, the quantity stepper and the size pills are removed
from the card entirely. That is a deliberate choice for that catalogue, not a direction for
the plugin. Every other shop running this plugin sells from the card.

**Never merge `feat/colour-browser-card` into `main`.** It carries a breaking change to the
template, to the markup contract and to the shape of the JS payload, and merging it would
take the buy box off every client's archive. Keep it as the branch it is — that is where
Lavie's card lives. If a piece of it turns out to be useful to every client, lift that one
piece onto its own branch off `main` and open a pull request for it.

---

## Changelog

See [CHANGELOG.md](CHANGELOG.md), and [CLAUDE.md](CLAUDE.md) for the reasoning behind the
architecture.

Per-step measurement records (query counts, payload sizes, screenshots) are **kept outside
this repository**: they are specific to the site they were measured on, and this plugin is
deployed to more than one client. Ask the maintainer for the engagement folder if you need
the raw numbers — every decision they support is written up in `CLAUDE.md`.
