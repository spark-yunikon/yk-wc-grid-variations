# CLAUDE.md

This repository is the WooCommerce plugin **YK WC Grid Variations** (v2.0.0).
This document records the structure and the rules to follow when working in this codebase.

---

## 1. What the plugin does

It replaces WooCommerce's default product card and renders its own `.yk-card` on category,
archive and tag pages. The card provides:

- **Every variation attribute, shown automatically** (STEP 3+) — all attributes marked
  "Used for variations" are rendered in the back-office order. Nothing is hardcoded to colour
  and size any more, so an antenna's "Frequenz" attribute appears like any other.
- **Swatches** (`.yk-swatch`) — attributes with `style === 'swatch'`. Round chips, click to
  select.
- **Pills** (`.yk-size`) — attributes with `style === 'pill'`. Values that are out of stock in
  the current combination get `is-disabled` automatically.
- **Quantity stepper** (`.yk-qty-wrap`) — `+` / `−` buttons, clamped to the selected
  variation's `max_qty`.
- **AJAX add-to-cart** (`.yk-add-to-cart`) — POSTs to WooCommerce's own
  `?wc-ajax=add_to_cart` endpoint, adds without a page reload, and fires `added_to_cart` so the
  theme's cart fragments refresh.
- **Card thumbnail swapped to match the selected colour** — the payload ships a single `src`,
  and the JS strips `srcset` / `sizes` from the markup before swapping.

On the **single product page** (`is_product()`) it additionally replaces WooCommerce's default
variation `<select>` dropdowns with swatches and pills in the same style, and adds `+` / `−`
steppers to `div.quantity`. This part can be switched off with the `enable_product_page`
setting.

Bundle products show only a "Zum Produkt" link on the card instead of the cart UI.

---
## 2. Data flow

### Archive / category pages

```
WooCommerce loop
  └─ woocommerce_before_shop_loop_item
       └─ YK_WCGV_Data::collect_product_id()
            → $GLOBALS['yk_wcgv_product_ids'][] = $product->get_id()

  └─ wc_get_template_part filter
       └─ YK_WCGV_Template::override_template_part()
            → swapped for woocommerce/content-product.php (prints the .yk-card markup)

wp_footer (priority 1)   ← ahead of wp_print_footer_scripts() at 20
  └─ YK_WCGV_Assets::inject_data()
       └─ YK_WCGV_Data::build_product_data( $ids )   ← yk_wcgv_build_product_data() wrapper
            → wp_add_inline_script( 'yk-wcgv', 'window.ykWcgv = {...}', 'before' )

Browser: DOMContentLoaded
  └─ assets/js/yk-wcgv.js
       → reads window.ykWcgv and binds events on every .yk-card
```

Key points:

- Product IDs **accumulate in a global array while the loop runs**, and the payload is built
  only for the products actually printed on the page. No second query is issued.
- Injection sits at `wp_footer` **priority 1** because `wp_add_inline_script( …, 'before' )`
  must be registered before the `yk-wcgv.js` tag is printed. Change that priority and
  `window.ykWcgv` becomes `undefined`, so the JS exits silently at the early return at the top
  of `yk-wcgv.js`.
- Variation data — images, stock, max quantity — is **serialised entirely in PHP**, so clicking
  a swatch or a pill costs no extra AJAX request. Only add-to-cart touches the network.

### Single product page

```
wp_footer (priority 1)
  └─ YK_WCGV_Assets::inject_product_page_data()
       ├─ YK_WCGV_Data::build_product_page_attributes( $product )   ← same source as archives
       └─ YK_WCGV_Data::build_product_page_colors( $product )       ← legacy compatibility
            → window.ykWcgvProduct = { attributes: {...}, colors: {...}, i18n: {...} }

assets/js/yk-wcgv-product.js
  → replaces **every** variation select inside variations_form with swatches/pills (STEP 5+)
     style and swatch data come from the payload. No keyword matching
  → writes the select's value and dispatches a change event
     (WooCommerce's own variation logic keeps running untouched)
  → syncFromSelect() only **mirrors** WooCommerce's disabled/value state into the visuals
```

**★ WooCommerce is the single source of state.** `syncFromSelect()` must be **read-only** — it
must never write to a select or dispatch an event. An earlier version cleared the select
(`select.value = ''`) whenever the active option became disabled; that fought WooCommerce's own
matching and silently wiped the shopper's choice on three-attribute products (confirmed by
measurement on dev). **Do not copy the archive's cascade logic in here.** This page has a
`variations_form`, and it already knows the right answer.

- Three watchers, deliberately: `MutationObserver` (options added/removed, `disabled`
  toggled), the select's `change` event (a value change mutates no attribute, so the observer
  cannot see it), and jQuery's `reset_data` (Clear, or no matching combination).
  **On `reset_data`, do not blindly clear the visual state — re-read it from the select.**
  WooCommerce may well have kept a value, and the two must not drift apart.
- An attribute missing from the payload (a translated taxonomy, for instance) **falls back to
  pills**. A native select must never be left visible.

### Resolving swatch colours (term meta, STEP 2+)

An administrator can set a HEX value (single or two-tone) or an image on each colour term.
Storage is term meta; lookups all go through one helper,
`yk_wcgv_get_swatch( $taxonomy, $term_slug )`.

```
Admin: attribute term screens (edit-tags.php / term.php, pa_* only)
  └─ YK_WCGV_Term_Meta  ← loaded only when is_admin()
       ├─ {$tax}_add_form_fields / {$tax}_edit_form_fields  → render fields
       ├─ created_{$tax} / edited_{$tax}                    → save
       └─ manage_edit-{$tax}_columns / _custom_column       → list preview

Shared lookup, front end and admin (includes/functions-helpers.php)
  └─ yk_wcgv_get_swatch()
       image (valid attachment) > term meta HEX > yk_wcgv_resolve_color() name map > none
       → [ type, image, color, color2, is_light ]
```

- **Hook timing.** WooCommerce registers the `pa_*` taxonomies on `init` (5), so iterating
  `wc_get_attribute_taxonomies()` must happen after `woocommerce_after_register_taxonomy`
  (with an `init` 20 fallback). Hook it any earlier and the fields never render at all.
- **`type` vs `color`.** `type` is how the swatch is rendered; `color` / `color2` are filled in
  whenever they can be resolved — including when `type === 'image'`, where they serve as a
  border/contrast hint. On `type === 'none'` the caller falls back to
  `yk_wcgv_resolve_color()` (`#cccccc`).
- If the attachment was deleted and `wp_get_attachment_image_url()` returns false, the swatch
  is treated as having no image and falls back to the HEX value or the name map.
- The same term is looked up many times per page, so there is a **per-request static cache**.
  Swatches also ride inside the per-product transient from STEP 6, and the term meta save
  handler invalidates with `YK_WCGV_Data::flush_all()` — there is no cheap term → products
  reverse map, so a full flush was the right trade (editing a swatch is a rare admin action).

**How to check whether the term meta path is actually being used.** When a term's meta HEX
happens to equal the name-map value (`schwarz` → `#1a1a1a` either way), **you cannot tell the
two paths apart by looking at the screen.** This is exactly where people conclude "the swatch
renders, so the meta works" and are wrong:

```bash
# 1) does raw meta exist? true means the meta path, not the name-map fallback
wp eval 'var_dump( yk_wcgv_term_has_swatch_meta( 1632 ) );'

# 2) change the value briefly and see whether it lands (the conclusive test)
wp eval 'update_term_meta(1632,"yk_wcgv_swatch_color","#123456");
         YK_WCGV_Data::flush_all();
         print_r( yk_wcgv_get_swatch("pa_farbe","schwarz") );'
```

Forget the `flush_all()` and you get the stale transient value and misdiagnose it as "the meta
does not work".

### Which attributes are shown, and how style is decided (STEP 3+)

```
YK_WCGV_Data::build_attributes( $product )
  └─ iterate $product->get_attributes() (keeps the back-office order)
       ├─ $attribute->get_variation() !== true  → skip
       │    (an attribute not marked "Used for variations" is never shown)
       ├─ build options[]
       │    taxonomy         → value = term slug
       │    custom attribute → value = the raw option string (never sanitize_title!)
       └─ decide style (determine_attribute_style, fixed order)
            1. any term carries raw swatch term meta   → 'swatch'
            2. attribute slug/label matches YK_WCGV_COLOR_KEYS → 'swatch'
            3. everything else                          → 'pill'
```

- **★ Do not use `yk_wcgv_get_swatch()` to decide style.** That helper fills in `color` from
  the name map even when no term meta exists, so a Frequenz term that happens to be named
  "Rot" would be mistaken for a colour attribute. Style must be decided with
  `yk_wcgv_term_has_swatch_meta()`, which reads `get_term_meta` directly.
- **Custom (non-taxonomy) attribute values.** WooCommerce stores the raw string on the
  variation (`'868 MHz'`). Apply `sanitize_title()` and the value no longer matches
  `variations[].attributes`, so **add-to-cart fails silently**. This is a real bug that was
  fixed in STEP 3.
- `YK_WCGV_SIZE_KEYS` is not used for style decisions. A pill is simply "not a swatch", so no
  keyword list is needed at all (the constant survives only for 1.x-era snippets).
- **Options of a `style === 'pill'` attribute carry no `swatch` key at all.** Pills do not use
  swatch data, so it was dropped from the payload. Renderers must treat `swatch` as
  **optional** (`option.swatch && …`). A welcome side effect: the per-option term lookup for
  pills disappeared too.
- **The custom (non-taxonomy) attribute branch is defensive code.** A full site scan in
  2026-08 found **0 of 208 products** using a custom attribute for variations. **Do not remove
  it** — it is what stops add-to-cart from failing silently the day someone adds one.

### The variation image pool (STEP 3.5+)

Repeating `image_url` / `image_srcset` / `image_sizes` on every variation was replaced by a
**per-product image pool with index references**.

```
'images' => [                                  // per product, sequential index from 0
   0 => [ 'url' => '...' ],                    // url only — srcset/sizes are not shipped
   1 => [ ... ],
],
'variations' => [
   [ 'variation_id' => 43, 'attributes' => {...}, 'img' => 0, ... ],
   [ 'variation_id' => 44, 'attributes' => {...}, 'img' => null, ... ],
]
```

- Pool keys are a **sequential index from 0, not the attachment ID** (kept short). The
  attachment ID → index mapping lives in `$image_lookup` and only during the build.
- Variations sharing an attachment **share one index**. That includes the common case where
  they all fall back to the parent product image, which then appears in the pool once.
- No image, or a deleted attachment leaving an empty URL, means **`'img' => null`**.
- **`srcset` and `sizes` were dropped from the payload entirely.** They accounted for roughly
  80% of it, and the swap happens inside a fixed-size card slot where one `src` is enough.
  In exchange, **the JS must remove the `srcset` and `sizes` attributes before swapping** — a
  browser prefers a `srcset` candidate over `src`, so leaving them in place keeps the previous
  image on screen (`updateImage()` in `yk-wcgv.js`).
- This landed before the STEP 6 caching work because STEP 7 (infinite scroll) accumulates the
  payload page after page.

### The card markup ↔ JS contract (STEP 4+)

Template and payload come from **the same source**. `content-product.php` calls
`YK_WCGV_Data::build_attributes( $product )` directly (which is why that method is public), so
the markup and `window.ykWcgv` cannot disagree about which attributes exist, what their values
are, or in what order they appear.

```html
<div class="yk-swatches" data-attribute="attribute_pa_farbe" aria-label="Farbe">
  <span class="yk-swatch is-active" data-value="schwarz" style="background-color:#1a1a1a;"></span>
</div>
<div class="yk-sizes" data-attribute="attribute_pa_grosse" aria-label="Grösse">
  <button class="yk-size" data-value="m">M</button>
</div>
```

- **`data-attribute` is the only key joining a group to the payload.** Remove it or rename it
  and the JS cannot find the group. The JS locates groups by `[data-attribute]` and options by
  `.yk-swatch, .yk-size`.
- The class hooks are reused as-is: `style='swatch'` → `.yk-swatches`/`.yk-swatch`,
  `style='pill'` → `.yk-sizes`/`.yk-size`. **These are not size-specific classes — they mean
  "pill style"** (a Frequenz attribute renders in `.yk-size` too). They are kept for
  compatibility with existing theme CSS.
- Swatch fills are inline styles (`background-color` for a single colour, `linear-gradient` for
  two-tone, `background-image` for an image), so no CSS file needs editing to add a colour.

**Selection is a cascade.** The first attribute is always clickable, and each attribute is
filtered **only by the attributes before it**. Constraining upwards — say, disabling every
colour that is sold out in the currently selected size — dead-ends the card, leaving no way to
pick a different colour. When an attribute changes, the selections after it are
**recalculated**: those still valid in the new combination are **kept**, and only the invalid
ones are cleared. `variationId` resolves and add-to-cart is enabled only once every attribute
is selected.

> **Correction (measured on dev, 2026-08):** this paragraph used to claim that changing an
> attribute clears **all** selections after it. **That is not true.** On the ASCENSION card,
> going from `schwarz + linkshaender` to `gelb` keeps `linkshaender` and resolves straight to
> B17ALA. Keeping a valid selection beats making the shopper choose again, so **do not revert
> this to "clear everything".**

### Payload shape

Examples of the `window.ykWcgv` and `window.ykWcgvProduct` structures live in the "JS data
payload" section of `README.md`. When you change a payload field, update
**PHP (`yk_wcgv_build_product_data`), the JS, and the README together.**

### ★★ The payload injection must never die (a real outage)

**`inject_data()` runs on `wp_footer` priority 1**, and `wp_print_footer_scripts()` runs at
priority 20. So **a fatal here removes every script from the page** — not just ours, but
WooCommerce's `add-to-cart-variation.js` and the theme's scripts as well. The symptom is not
"the plugin is missing"; it is **"the whole site is broken"**.

This actually happened:

- When `_upsell_ids` / `_crosssell_ids` reference a **variation ID**, WooCommerce renders that
  variation in an ordinary shop loop. (Measured on dev: of 32,828 references, 1,439 pointed at
  variations, and 210 products / 633 references actually rendered on the front end — **this is
  not a rare data glitch**.)
- `WC_Product_Variation::get_attributes()` returns a **plain string array**
  (`['attribute_pa_farbe' => 'schwarz']`), not `WC_Product_Attribute` objects. Calling
  `->get_variation()` on those strings threw.

There are four layers of defence. **Do not remove any of them:**

| Layer | What it does |
|---|---|
| a. Block at the source | `collect_product_id()` never collects `is_type('variation')` / `product_variation` post types |
| b. Type guard | `is_attribute_object()` = `is_object()` + `method_exists(get_variation/is_taxonomy)`. Applied in **all three** of `compute_attributes()`, `prime()` and `build_product_page_colors()` |
| c. Fail quietly | A guard hit returns an empty array and logs through `error_log` only under `WP_DEBUG`, so production logs do not explode |
| d. Safety net | The whole of `inject_data()` is wrapped in `try/catch ( Throwable )` |

**★ Catch `Throwable`, not `Exception`.** `TypeError` is an `Error`, not an `Exception`, and
the fatal above was exactly a `TypeError`. Change this to `catch ( Exception )` and the safety
net catches nothing.

If you add code that iterates `$product->get_attributes()`, **it must go through
`is_attribute_object()`.** WooCommerce returns different shapes from that method depending on
the product type.

### Performance decisions that are easy to undo (STEP 6+)

Each of these came out of measurement, which makes them easy to "tidy away". Mind the **do not**
in every item. (The raw measurements live in the engagement folder outside this repository —
see §5.)

- **Do not use `get_available_variations()`.** It builds price HTML, an availability string and
  a six-size image array for every variation. Read `get_visible_children()` plus the five
  fields actually needed.
- **The cache key contains `CACHE_VERSION`** (`yk_wcgv_v{N}_{id}_{lang}`, 12 h). **Bump that
  constant whenever you change the payload schema** — that alone invalidates everything, so no
  migration code is needed. Take it out of the key and every schema change leaves the old shape
  cached, silently breaking the JS.
- **Taxonomy priming covers only what is needed** (`prime_attribute_terms`). Measured across 7
  products: no priming 52 queries / all 64 taxonomies of the product type 41 / **only the 8
  needed, 23**. "Surely priming everything is faster" is **wrong** — it adds lookups for
  taxonomies nobody reads.
- **★ Do not replace the term queries with `get_the_terms()`.** Of the 182 queries on the
  cache-miss path, 75 are `WP_Term_Query`, because `wc_get_product_terms()` calls
  `wp_get_post_terms()` internally, which **does not use the object term cache**. Yes, you
  could remove most of them with `get_the_terms()` plus your own sorting — but this site's
  attributes mix **three different orderings** (`name`, `menu_order`, `name_num`), so
  hand-rolling the sort shifts option order in ways that are hard to notice. It is a cost paid
  once every twelve hours, and it was **left in place deliberately.**
- **38% of the miss path is WPML** (69 `icl_translations` queries). Nothing on our side can
  reduce it, so account for it when reading cache-miss numbers.
- **`content-visibility: auto` + `contain-intrinsic-size: auto 520px` on `.yk-card`**
  (`yk-wcgv.css`). The 520px comes from **measured card heights of 467–546px** — do not round
  it back to something like 600px, and do not delete it. Effect: one forced layout costs
  0.20–0.62 ms versus 1.40–3.70 ms without it (roughly 6–7×). Anchor drift across 144 cards is
  **0 px**. In a headless browser, rAF FPS is unrelated to actual painting and tells you
  nothing — **measure forced layout time instead.**
---

## 3. File structure

```
yk-wc-grid-variations.php      Bootstrap: constants, require of includes, class init() calls,
                               HPOS compatibility declaration
includes/
├── functions-helpers.php      yk_wcgv_attr_matches(), yk_wcgv_resolve_color()
├── class-yk-wcgv-i18n.php     Text domain, JS strings, WPML registration
├── class-yk-wcgv-settings.php Settings page, get/sanitize
├── class-yk-wcgv-data.php     Loop ID collection, payload builder
├── class-yk-wcgv-assets.php   wp_enqueue_scripts, inline script injection
├── class-yk-wcgv-template.php wc_get_template_part override, loop hook removal, body_class
├── class-yk-wcgv-ajax.php     Infinite-scroll endpoint (nonce + query var whitelist)
├── class-yk-wcgv-term-meta.php Swatch colour/image admin UI for attribute terms (pa_*)
│                              ★ required and init'd only when is_admin()
└── class-yk-wcgv-category-meta.php Per-product_cat paging mode override
                               ★ required and init'd only when is_admin()
woocommerce/
└── content-product.php        Card markup template (injected via the wc_get_template_part filter)
assets/
├── css/
│   ├── yk-wcgv.css            Archive card styles + design token (--yk-*) definitions
│   └── yk-wcgv-product.css    Single product page styles (reuses the yk-wcgv.css tokens)
└── js/
    ├── yk-wcgv.js             Card interaction (swatches/pills/quantity/AJAX cart)
    ├── yk-wcgv-product.js     Single product page (select → swatch/pill, stepper)
    ├── yk-wcgv-infinite.js    Infinite scroll (IntersectionObserver, card append)
    └── yk-wcgv-admin.js       Admin term screen (colour input sync, media frame)
languages/                     .po / .mo (optional)
```

> **The template lives inside the plugin.** `woocommerce/content-product.php` is injected
> through the `wc_get_template_part` filter, so a theme override is **neither needed nor
> allowed** — a theme-side `woocommerce/content-product.php` competes with this filter.
> (1.x used the theme-override approach and the README said so. The README was corrected to
> match reality in 2.0.0.)

> **Load order.** `includes/` files are `require_once`'d in the order shown above. The helpers
> (`functions-helpers.php`) must always load first; each class registers its hooks in its own
> `init()`, called at the bottom of the main file.
> `YK_WCGV_COLOR_KEYS` / `YK_WCGV_SIZE_KEYS` may be referenced directly by child-theme
> templates and site snippets, so **keep them as global `const`s at the top of the main file**.
> Do not move them into class constants.
>
> **Keep the global wrapper functions.** `yk_wcgv_get_settings()`, `yk_wcgv_i18n()` and
> `yk_wcgv_build_product_data()` moved into classes, but identically named global wrappers
> remain. `woocommerce/content-product.php` calls them through `function_exists()`, so removing
> a wrapper breaks the card silently.
> (`yk_wcgv_sanitize_settings()` had no call sites at all and was removed in 2.0.0.)

### Key symbols

| Symbol | Role |
|------|------|
| `YK_WCGV_VERSION` / `YK_WCGV_FILE` / `YK_WCGV_DIR` / `YK_WCGV_URL` | Constants. The version doubles as asset cache busting |
| `YK_WCGV_COLOR_KEYS` | Colour keyword list (en/de/fr) for the style fallback (rule 2) |
| `YK_WCGV_SIZE_KEYS` | **Read nowhere in the plugin.** Kept as a public constant for 1.x-era snippets only |
| `YK_WCGV_Settings::style_variations()` | Style variation `title`s of the active theme. The only source for the settings dropdown |
| `YK_WCGV_SWATCH_IMAGE_SIZE` | `yk_wcgv_swatch` (96×96 crop). The default `$image_size` of `yk_wcgv_get_swatch()`, so it applies **everywhere** — payload swatch URLs *and* the admin preview column |
| `yk_wcgv_term_has_swatch_meta()` | Whether raw term meta holds a valid swatch value. **Style decisions must use this** |
| `yk_wcgv_attr_matches()` | Whether a slug or label contains one of the keywords |
| `yk_wcgv_build_product_data()` | IDs collected in the loop → JS payload array (`YK_WCGV_Data::build_product_data()` wrapper) |
| `yk_wcgv_resolve_color()` | Colour slug/label → hex. On no match, tests for a hex string, then `#cccccc` (`YK_WCGV_COLOR_FALLBACK`) |
| `yk_wcgv_get_swatch()` | A term's final swatch representation. Image > term meta HEX > name map > none. Per-request static cache |
| `yk_wcgv_parse_swatch_colors()` | `"#000,#ffcc00"` → an array of 0–2 validated hex values (a third and beyond are discarded) |
| `yk_wcgv_is_light_color()` | True when the WCAG relative luminance exceeds 0.5 |
| `YK_WCGV_META_SWATCH_COLOR` / `_IMAGE_ID` | Term meta key constants. Single source for the admin class and the lookup helper |
| `yk_wcgv_get_settings()` | Option `yk_wcgv_settings` merged with the defaults (`YK_WCGV_Settings::get()` wrapper) |
| `yk_wcgv_i18n()` | Single source for the translated strings sent to JS (`YK_WCGV_I18n::strings()` wrapper; WPML registration reuses it) |
| `$GLOBALS['yk_wcgv_product_ids']` | Product ID array shared between the loop hook and the `wp_footer` injection. Do not turn this into a class property |

> **Why the admin preview also uses 96×96 and not `thumbnail`.**
> `render_preview_column()` calls `yk_wcgv_get_swatch()` without a size argument, so it inherits
> the 96×96 default — and that is correct, not an oversight. The preview chips are 32px (list
> column) and 28px (edit form), so 96px is a clean 3× source that stays sharp on retina
> displays, while still being **smaller than WordPress's `thumbnail`** (150×150 by default).
> Switching to `thumbnail` would download more bytes for a blurrier chip. **Do not "fix" this
> back to `thumbnail`.**

The CSS defines `--yk-*` custom properties on `:root`, whose values reference the theme's
`--wp--preset--color--*`. Per-site colour adjustments are made by **overriding tokens only**
inside a `body.yk-variant-{slug}` block, never by editing individual rules
(`yk_wcgv_variant_body_class()` adds that class from the active style variant title).

---

## 4. Coding rules

### PHP

- Follow the **WordPress Coding Standards**. Indent with **tabs**, never spaces. Keep the
  existing style: spaces inside parentheses (`func( $arg )`), Yoda conditions
  (`'bundle' === $type`).
- **Always escape output.**
  - Text: `esc_html()` / `esc_html_e()`
  - Attribute values: `esc_attr()` / `esc_attr_e()`
  - URLs: `esc_url()`
  - Where HTML must be allowed (price markup, for instance): `wp_kses_post()`
  - Print unescaped only where core already returns safe markup, such as
    `$product->get_image()`.
- **Every form and AJAX request needs nonce verification, and a capability check where it
  applies.**
  - The settings form uses the Settings API (`settings_fields()` + `register_setting()`), which
    handles the nonce, and the render function starts with
    `current_user_can( 'manage_woocommerce' )`. The menu registration uses the same capability.
  - Any new AJAX endpoint (`wp_ajax_*` / `wc-ajax`) must verify a nonce. The infinite-scroll
    endpoint (`YK_WCGV_Ajax`) is a **public endpoint that logged-out visitors use**, so it is
    protected by `check_ajax_referer()` plus a query var whitelist and deliberately has no
    capability check. An admin-only endpoint must have `current_user_can()` as well.
    Add-to-cart uses WooCommerce's own `add_to_cart` endpoint — do not build a new one; keep
    using core endpoints wherever possible.
  - Sanitise stored values in a whitelist-style callback, as `YK_WCGV_Settings::sanitize()`
    does (`sanitize_key()`, normalising to `'1'`/`'0'`, and so on).
- **Use exactly one text domain: `'yk-wc-grid-variations'`.** Never hardcode a user-facing
  string.
- **Prefix new functions and constants with `yk_wcgv_` / `YK_WCGV_`, and classes with
  `YK_WCGV_`.** Unprefixed names are forbidden — they pollute the global namespace.
- The PHP floor is **7.4**. Do not use 8.0+ syntax: no `?->`, `match`, enums or named
  arguments. (`??`, arrow functions and typed properties are fine.)

### JS

- There is no build step. Browsers load `assets/js/*.js` as-is. **Do not introduce a bundler or
  a transpiler.**
- Keep each file wrapped in an IIFE with `'use strict'`.
- Use vanilla JS for DOM work. jQuery is for **WooCommerce event interop only** (firing
  `added_to_cart`, listening for `reset_data`).
- Insert user data with `textContent` or `escHtml()`. **Never put an unsanitised value into
  `innerHTML`.**

### CSS

- Do not hardcode new colour or radius values — define a `--yk-*` token or use an existing one.
- Limit `!important` to cases that must beat WooCommerce or theme defaults (the existing uses
  are the examples).
- Archive classes use the `.yk-` prefix; single product page classes use `.yk-sp-`.

### Backwards compatibility

- **Never delete the existing option key `yk_wcgv_settings`.** If the schema has to change,
  handle it with migration code: read the old value, convert it, save it, and let the
  `wp_parse_args()` defaults in `yk_wcgv_get_settings()` fill in missing keys. Renaming the
  option key, deleting values, or calling `delete_option()` is forbidden.
- The existing CSS class hooks (`.yk-card`, `.yk-swatch`, `.yk-size`, `.yk-qty-*`,
  `.yk-add-to-cart`, …) may be relied on by theme and site CSS. Do not rename them; add new
  ones if you need them.
- When you change a user-facing string, update the lists in `yk_wcgv_i18n()` **and**
  `yk_wcgv_register_wpml_strings()` together. If the two drift apart, WPML translation breaks.

### Version — 2.0.0 is the confirmed release

> **2.0.0 is confirmed as the release number.** It was decided by reviewing the full set of
> changes across STEP 1–9, and it is set in both the plugin header `Version:` and the
> `YK_WCGV_VERSION` constant. The change log is in `CHANGELOG.md`.
>
> During development the rule was not to bump the version per STEP — a rule that was actually
> broken in STEP 2 and STEP 3, taking it from 2.0.0 to 2.1.0 to 2.2.0 before it had to be
> reverted. **This is the one deliberate bump, at release time.**

**Rules from the next release onward:**

- Bump the version **only when releasing.** Do not bump it because you added a feature or
  edited CSS/JS — cache problems during development are already solved by
  `yk_wcgv_asset_version()` below.
- When you do bump it, update **all three**: the plugin header `Version:`, the
  `YK_WCGV_VERSION` constant, and a new section in `CHANGELOG.md`.
- Follow SemVer. In particular, **a payload schema change, removing a settings key, or
  removing a global function is a major bump** — that is exactly why 2.0.0 is major
  (`color_attr`/`size_attr` → `attributes`, the `visible_attrs` UI removed,
  `yk_wcgv_sanitize_settings()` removed).
- Comments and documentation now reference history by **version number** (`since 2.0.0`), not
  by STEP number. STEP numbers survive only inside the development records in the engagement
  folder outside this repository (see §5, "Measurement records live outside this repository").

### Asset caching during development (solved without bumping the version)

With the version frozen, the `yk-wcgv.js?ver=2.0.0` URL never changes and browsers keep serving
the old file. In STEP 8 this led to price swapping being diagnosed as "not working" when it
worked fine as soon as the browser cache was cleared.

That is why **every** enqueue goes through `yk_wcgv_asset_version( 'assets/js/…' )`:

| Environment | Return value |
|---|---|
| `WP_DEBUG === true` | The file's `filemtime()` — save the file and the next refresh picks it up |
| `WP_DEBUG === false` (production) | `YK_WCGV_VERSION` — one cacheable URL per release |
| File unreadable | `YK_WCGV_VERSION` (fallback) |

Register every new CSS/JS file through this helper. Passing the constant directly brings the
development cache problem straight back.

---

## 5. Known limitations — incompatible with the block-based Product Collection block

This plugin renders its cards through WooCommerce's **classic template path**
(`wc_get_template_part( 'content', 'product' )`) and nothing else. The main plugin file
forces that path on:

```php
add_filter( 'option_wc_blocks_use_blockified_product_grid_block_as_template', fn() => 'no' );
```

Consequences:

- **Do not build shop / category / tag archives with the Site Editor's Product Collection
  block.** That block emits its own grid markup and never reaches the `wc_get_template_part`
  hook, so the `.yk-card` swatches, pills, quantity stepper and AJAX add-to-cart all vanish.
- Archives must stay on the classic / template-based grid.
- That option name is WooCommerce-internal. **Re-verify this filter after every major
  WooCommerce upgrade.** When it stops working, the failure mode is the cards disappearing
  wholesale — not an error you will notice in a log.

### Variation price / SKU (STEP 8)

- Each variation entry in the payload carries `sku` (a string) and `price` (an index into a
  price pool). **Prices are pooled per product** in `prices[]`, the same trick as `images[]`
  — measured: 149 variations produced only 44 distinct price strings (70% repeats) at roughly
  230 B each. **Do not pool SKUs.** All 149 were unique, so a pool plus indices makes the
  payload *bigger* (1,908 B → 2,355 B). Swap the card's price and SKU only once **every**
  attribute is chosen and the variation resolves; while the selection is incomplete, keep the
  server-rendered parent values (price range + parent SKU). Blanking them mid-selection makes
  the whole grid flicker.
- **Do not go back to `get_available_variations()`.** Build prices directly with
  `wc_get_price_to_display()` + `wc_price()` (or `wc_format_sale_price()` when on sale), and
  read them through the bulk priming path from STEP 6.
- **Always include the price suffix** (`$variation->get_price_suffix()`). The card's
  `.yk-price__tax` span is `display:none` in `yk-wcgv.css`, so the "inkl. MwSt." a shopper
  actually sees *is* the WooCommerce suffix. Drop it and the suffix disappears the moment an
  option is picked. (There is **no** global `wc_get_price_suffix()` function — it is a product
  method.)
- **The cache TTL never spans the next sale boundary** (`YK_WCGV_Data::cache_ttl()`). If a
  scheduled sale starts in two hours, the TTL shrinks to 7260 seconds. Trusting the flat
  12-hour TTL would keep showing pre-sale prices after the sale has started — a real revenue
  bug, not a cosmetic one.
- Price-related invalidation hooks: `woocommerce_product_object_updated_props`,
  `wc_scheduled_sales`, `woocommerce_settings_saved`, and `update_option_*` for the currency
  and tax options.
- **★ WPML multi-currency.** Cache keys are already split per language (`_de` / `_fr` / `_en`)
  and prices live inside those keys, so a language-equals-currency setup is safe as-is. But on
  a site that switches **currency without switching language** (WCML's currency switcher), the
  key cannot tell currencies apart and the wrong currency gets cached. Add the currency code to
  `cache_key()` before deploying to such a site. The current dev site is three languages
  (de/fr/en), single currency (CHF).
- **Do not touch the single product page here.** WooCommerce's native `variations_form`
  already updates price and SKU (verified: `.woocommerce-variation-price` and the theme's
  "Artikelnummer" both change). Per the STEP 5 lesson, WooCommerce must remain the single
  source of state.

### Infinite scroll (STEP 7)

Configuration is **two layers plus filters**. The lower layer always defaults to "inherit".

```
Global settings (yk_wcgv_settings)
  ├─ pagination_mode: 'pagination' | 'infinite'   (default 'pagination')
  └─ prefetch_distance: px                        (default 400)
       ↓ overridden by the category unless it is 'inherit'
product_cat term meta (yk_wcgv_pagination_mode)
  └─ 'inherit' | 'pagination' | 'infinite'        (default 'inherit')
       ↓ the filter has the last word
apply_filters( 'yk_wcgv_pagination_mode', $mode, $context )
apply_filters( 'yk_wcgv_prefetch_distance', $px )
```

- The decision is made in exactly one place: `YK_WCGV_Settings::pagination_mode()`.
  `$context` carries `is_shop`, `taxonomy`, `term_id`, `term_slug`.
- **`class-yk-wcgv-category-meta.php` is `product_cat`-only; `class-yk-wcgv-term-meta.php` is
  `pa_*`-only. Do not merge these two classes.** The moment you do, paging fields leak onto
  attribute screens and swatch fields leak onto category screens.
- **The absence of a Gutenberg block option is deliberate.** This plugin forces the classic
  template through `option_wc_blocks_use_blockified_product_grid_block_as_template`, so it is
  incompatible with the Product Collection block to begin with (see the section above).
  Attaching paging options to that block would require block-compatible rendering first — a
  separate project.

**AJAX endpoint** (`class-yk-wcgv-ajax.php`)

- It returns card markup plus the payload, nothing else. **Do not fetch and parse the next
  page's full HTML** — that renders the header, menu and footer for nothing.
- Client query vars are **whitelisted** (`ALLOWED_VARS` plus the `filter_*` / `query_type_*`
  prefixes). `post_status`, `posts_per_page` and `meta_query` are **never** taken from the
  request. The nonce is mandatory.
- **Products per page comes from a value observed in the front-end loop and stored in an
  option (`yk_wcgv_loop_per_page`).** `apply_filters( 'loop_shop_per_page', … )` resolves to a
  different number under admin-ajax than on the front end, because themes commonly register
  that filter behind `! is_admin()`. On this site it is 16 on the front end and 10 under
  admin-ajax — leave it alone and page 2 starts in the middle of page 1.
- `is_shop()` and friends are all false during AJAX, so
  `YK_WCGV_Template::set_ajax_rendering( true )` is the flag that lets the template override
  through.
- Responses send `nocache_headers()` + `no-store`. This stack was observed caching
  `/warenkorb/`.

**Front end** (`assets/js/yk-wcgv-infinite.js`)

- `IntersectionObserver`'s `rootMargin` starts the request **before** the sentinel is reached.
- No cards means no sentinel. (On an archive that only shows subcategory tiles, a sentinel
  would fetch empty page after empty page.)
- `window.ykWcgvArchive.initCards()` is called on newly inserted nodes only, and `initCard()`
  is idempotent behind its `data-yk-init` guard.
- Pagination is **not removed from the DOM** — it is only hidden visually with
  `.yk-pagination--hidden` (a clip rect). Crawlers and visitors without JS use it as-is, and
  **the script un-hides it when a request fails.** That is why it must be hidden rather than
  removed.
- **Overlapping requests are prevented by a lock *and* an `AbortController`.** Even under
  aggressive scrolling, five pages cost four requests. Keep only one of the two and you get
  duplicate requests or out-of-order responses.
- The script loads with `defer` and **observer registration is deferred to
  `requestIdleCallback`.** This keeps it off the first-paint path — do not move it back to
  `DOMContentLoaded`.
- The URL is updated with `replaceState` to `?paged=N` — **not** `pushState`, which would add
  a history entry per scroll position and wreck the back button.
- **Measure the prefetch distance with a gradual scroll.** Jumping straight to the bottom puts
  the sentinel on screen already and yields a negative number (measured: −747px). With a
  gradual scroll, a configured 400px fired at an actual 386px (96.5%).
- Raise `prefetch_distance` on slow servers. The dev site answers AJAX in roughly 5 seconds
  per request, where 400px may not be enough lead (adjustable in the settings).
- **If a site uses a different per-page count per archive**, the `yk_wcgv_loop_per_page`
  option has to be extended to be per-category. Today it is a single site-wide value.

### Measurement records live outside this repository

The per-step measurement documents (STEP 6–9) and 29 screenshots are **deliberately excluded**
from this repository. They live in `~/Downloads/yk-wcgv-engagement-2026-08/`.

**Why:** this repository ships to more than one client, and those documents and screenshots
carry Shop Attack's branding, real product names, product IDs and URLs. `.gitignore` excludes
`*.csv` and `docs/` for the same reason — **do not revert that.**

**★ This document must be enough to maintain the plugin without that folder.** Before the move,
every piece of reasoning was audited out of those files and into CLAUDE.md (see "The payload
injection must never die", "Performance decisions that are easy to undo", the infinite-scroll
notes, and how to verify the term-meta path). When you measure something new, **write the
conclusion and the reasoning here** and keep only the raw data in that folder.

### Other constraints

- **The "Visible attributes" setting was removed in STEP 3.** Attributes are discovered
  automatically now, so a manual whitelist serves no purpose. The option key
  `yk_wcgv_settings['visible_attrs']` is **kept, never deleted, and emptied by a one-time
  migration** (`YK_WCGV_Settings::maybe_migrate()`, on `init`, running on the front end too).
  The completion flag and the previous value are archived in a separate option,
  `yk_wcgv_migrations`. Leave a stored whitelist in place and every newly added attribute
  silently fails to appear — a ghost bug nobody will connect to this setting.
- Cards are rendered only when `is_shop() || is_product_category() || is_product_tag() ||
  is_product_taxonomy()`. Search results, related products and shortcode grids get the default
  WooCommerce card.
- **Removing the default loop wrappers applies to the archive loop only** (fixed in STEP 4).
  Three actions are removed on `woocommerce_before_shop_loop`
  (`woocommerce_template_loop_product_link_open` / `_close`,
  `woocommerce_template_loop_add_to_cart`) and **restored at their original priorities** on
  `woocommerce_after_shop_loop`. Related products, up-sells, cross-sells and the `[products]`
  shortcode only call `woocommerce_product_loop_start()` and never fire those two actions, so
  they are unaffected. The earlier global removal on the `wp` hook stripped the add-to-cart
  button from every one of those loops — a real, shipped bug.
  **Do not move this removal back to a global hook (`wp`, `init`, or similar).**
  - Restoration only touches **what we actually removed** (`has_action()` records the original
    priority). It never resurrects an action another plugin had deliberately detached.
  - The template filter (`override_template_part`) calls the same removal as a **safety net**,
    so that a theme which never fires `woocommerce_before_shop_loop` still does not end up with
    a default `<a>` wrapper and a duplicate cart button inside `.yk-card`. Repeated calls are
    idempotent behind the `$loop_hooks_removed` flag.
- **Term swatches (term meta) work on global attributes (`pa_*`) only.** A product-level custom
  attribute has no terms, so it cannot carry term meta and its colour always comes from the
  `yk_wcgv_resolve_color()` name map. A client who needs a two-tone colour has to convert that
  attribute into a global attribute.
- **WPML does not copy term meta to translated terms.** A translated colour term shows no
  swatch colour or image. There is a `KNOWN LIMITATION` comment at the term lookup inside
  `yk_wcgv_get_swatch()`. Falling back to the source term's meta needs WPML's term-translation
  API and was **deliberately not implemented in 2.0.0** — the operational fix is to set the
  swatch on the translated term as well (documented in the README).
- Admin assets (`yk-wcgv-admin.js` + `wp_enqueue_media()`) load only on `edit-tags.php` /
  `term.php` when the taxonomy starts with `pa_`. **Do not widen that condition** — it exists
  to keep every other admin screen clean.
- The style fallback (rule 2) is still a **substring match** on the attribute slug and label.
  Supporting another language now means editing `YK_WCGV_COLOR_KEYS` and nothing else. STEP 5
  removed the duplicated `COLOR_KEYS` / `SIZE_KEYS` definitions from `yk-wcgv-product.js`, so
  archives and product pages share one PHP decision.
- **The `yk-variant-*` body class is chosen in the settings (solved in 2.0.0).**
  Auto-detection is **impossible in principle**: when the Site Editor applies a variation it
  copies only the `settings`/`styles` of the variation file
  (`yk-theme/styles/sha.json` → `"title": "Shop Attack"`) into the `wp_global_styles` post and
  drops the `title` (the post title is always `Custom Styles`), and **WordPress records the
  active variation's name nowhere else.** So `yk_wcgv_variant_body_class()` now reads the
  setting `yk_wcgv_settings['style_variant']` first. The list comes from
  `YK_WCGV_Settings::style_variations()`, built from the `title`s returned by
  `WP_Theme_JSON_Resolver::get_style_variations()`.
  - Precedence is **setting → legacy auto-detection → the `yk_wcgv_style_variant` filter**, and
    the filter wins. Sites that already force the variant through the filter keep working
    unchanged.
  - The setting stores the **title string verbatim** and is **not** whitelisted against the
    current theme's variation list — switching themes and switching back would otherwise wipe
    the choice. Output passes through `sanitize_html_class( sanitize_title() )`, so it is safe.
  - If the active theme ships no style variations, the dropdown is not rendered and a hidden
    input carries the stored value through the save. **Do not drop that hidden input**, or
    saving the settings on such a theme silently clears the choice.
- **`.yk-sp-reset` — the only way to clear a selection on the product page (2.0.0).**
  A button below the attribute table, shown **only when at least one option is selected**.

  **★ The reason for it changed (measured on dev, 2026-08).** It was introduced as an escape
  from the dead-end state, but **the dead end does not reproduce on the current WooCommerce
  version**: conflicting options are pre-disabled so the click never lands, and an invalid
  combination in a deep link (`?attribute_…`) makes WooCommerce reset everything by itself.
  (Verified exhaustively on a three-attribute, three-variation product — every reachable state
  still had a way forward. Other catalogues may still hit it, so the feature stays.)

  **The real reason it exists: in this theme WooCommerce's own `.reset_variations` ("Clear")
  is `display:none` in every state** — verified even with a variation fully resolved and the
  add-to-cart button enabled. Without this button the product page has **no way to clear a
  selection at all**.
  - **★ Do not write your own reset logic.** This button delegates to WooCommerce's
    `.reset_variations` via `native.click()` and nothing more. Clearing the selects directly
    would make this file a **second writer** on them — exactly the bug that silently wiped
    shoppers' selections in STEP 5. If a theme or WooCommerce version ships no such link,
    **the button is not rendered at all.**
  - Visibility is updated through a **jQuery binding** (`$(form).on('change','select',…)`).
    WooCommerce changes selects with jQuery's `.trigger('change')`, which runs jQuery handlers
    without dispatching a native event, so `addEventListener` would miss it.
  - **Do not add it to archive cards.** Cards cascade (each attribute is filtered only by the
    ones before it), so they cannot dead-end by construction. Card height is expensive in a
    grid, and this would only add a control nobody needs.
- **Payload size.** `window.ykWcgv` is an inline script, so its uncompressed size lands
  directly in the HTML. Measured after the image pool was introduced (synthetic data, realistic
  image URL / srcset lengths):

  | Scenario | Variations inherit the parent image | One image per colour | Unique image per variation |
  |---|---|---|---|
  | 12 products × 4 colours × 4 sizes | 141.7 → **41.7 KB** (−71%) | 142.6 → **60.9 KB** (−57%) | 143.5 → 138.7 KB (−3%) |
  | 12 products × 24 colours × 6 sizes | 1198 → **273 KB** (−77%) | 1207 → **421 KB** (−65%) | 1215 → 1199 KB (−1%) |

  The saving is **proportional to how much images are shared**. Give every variation its own
  image and the pool grows to the variation count, buying nothing. These numbers date from when
  `srcset`/`sizes` were still shipped; both were dropped afterwards, so the real payload is
  smaller than shown.
- HPOS (custom order tables) compatibility is declared
  (`yk_wcgv_declare_hpos_compatibility`).
---

## 6. Checklist before you finish

- [ ] PHP indented with tabs, matching the WPCS style of the surrounding files
- [ ] Every newly printed value goes through an escape function
- [ ] New functions/constants carry the `yk_wcgv_` / `YK_WCGV_` prefix
- [ ] New strings carry the `'yk-wc-grid-variations'` text domain, and were added to
      `yk_wcgv_i18n()` / the WPML registration list where relevant
- [ ] If the option schema changed, existing `yk_wcgv_settings` values were migrated
- [ ] If the payload changed, PHP, JS and the README were all updated
- [ ] If you touched a swatch background, you used longhands
      (`background-color` / `background-image`) — all four renderers (card template,
      product-page JS, admin PHP preview, admin JS) use longhands
- [ ] Version untouched unless this is a release. If it is, `Version:` header,
      `YK_WCGV_VERSION` and `CHANGELOG.md` were **all three** updated
- [ ] Only syntax that runs on PHP 7.4
