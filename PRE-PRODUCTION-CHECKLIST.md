# Pre-production checklist — YK WC Grid Variations 2.0.0

QA was completed on a development site, but **some items could not be verified there because
the site has no product or page that exercises them.** A production catalogue probably does, so
work through the list below right after deploying.

Each item states **what to look at and what counts as a pass**, plus what to do when it fails.

---

## A. Not verified because the dev data did not cover it

### A1. Bundle product cards

- **Why not verified:** none of the archives visited contained a bundle-type product
- **How to check:** open a category that contains a bundle product
- **Pass:** the card shows **no** quantity stepper and no "In den Warenkorb" button, only a
  **"Zum Produkt" link** (`a.yk-btn--outline`). In the payload the product is the single line
  `{ "type": "bundle" }`
- **If it fails:** check that the `$is_bundle` branch in `woocommerce/content-product.php`
  matches the product type string `'bundle'`. If the bundle plugin uses a different type slug,
  that slug has to be added

### A2. `[products]` shortcode grids

- **Why not verified:** no page using the shortcode was found
- **How to check:** open a page containing `[products limit="8"]`
- **Pass:** the **default WooCommerce card** is rendered (by design, not `.yk-card`), and
  **every product still shows its add-to-cart button**
- **If it fails** (the cart buttons are gone): the loop hook removal has leaked outside the
  archive. Verify that `YK_WCGV_Template::remove_default_loop_hooks()` only runs on
  `woocommerce_before_shop_loop`. **Do not move it to a global hook such as `wp` or `init`** —
  that was a real, shipped bug in 1.x
- Related: related products, up-sells and cross-sells were **verified as passing** on dev (all
  five up-sell items kept their cart button)

### A3. A product where every option is out of stock

- **Why not verified:** every variable product on dev was in stock
- **How to check:** open an archive containing a variable product whose variations are all out
  of stock
- **Pass:** **every option carries `is-disabled`**, clicking changes nothing, add-to-cart shows
  the error message, and the card height does not change
- **Note:** if WooCommerce's "hide out of stock items" setting is on, the product disappears
  from the archive entirely and this item cannot be tested

### A4. A three-attribute product on a **category** page

- **Why not verified:** the only three-attribute product on the dev site **has no category
  assigned**, so it never appears in an archive (it passed on its own product page)
- **How to check:** open a category containing a three-attribute product
- **Pass:** the card shows **all three groups** in the same order as the product edit screen,
  and the cascade behaves (the first attribute is never disabled by a later selection)
- For reference: the product page was verified for three-group rendering, cascade behaviour and
  price/SKU updates

### A5. Image swatch rendering — **partly verified**

- **Passed** on the dev product page: `background-image` + `background-size: cover` +
  `border-radius: 50%`, so the image fills the circle, and the `has-image` class is applied
- **Still open:** image swatches on the **archive card** (no such term appeared in the archives
  visited)
- **Pass:** `.yk-swatch.has-image` on the card fills the circle exactly as on the product page
- **If it fails:** the image may be missing or its attachment deleted — see A6

### A6. Fallback when an attachment is deleted

- **Why not verified:** no client media was deleted
- **How to check:** delete an attachment used as a swatch image from the media library and open
  the card (**do this on staging only**, never in production)
- **Pass:** not an empty chip, but a fallback in the order **term meta HEX → name map →
  `#cccccc`**
- Basis: `yk_wcgv_get_swatch()` treats a `false` from `wp_get_attachment_image_url()` as "no
  image"

### A7. Contrast border on light colours (`is-light`)

- **Why not verified:** no light-coloured term appeared on the pages visited (though `is-light`
  was confirmed on the `#cccccc` fallback on the French pages)
- **How to check:** open a category with white/beige colour terms
- **Pass:** light chips carry the `is-light` class and a visible border, so they do not
  disappear into the white card

### A8. Mobile at 375px

- **Why not verified:** the viewport was never resized
- **How to check:** narrow the browser to 375px, or use a real device, and open a category
- **Pass:**
  - swatches and pills wrap without being clipped (the group is `flex-wrap: wrap`,
    `overflow: visible`)
  - **the selection badge is not clipped after wrapping either** (it overhangs the chip by 3px)
  - the quantity stepper and cart button fit on one line, or fold cleanly
  - card height does not change between the unselected and selected states
- Reference: the engagement folder has 375px screenshots from an earlier step in
  `docs/badge-check/mobile-375*.png`

---

## B. Not verified for lack of access (needs wp-cli)

SSH/wp-cli was **not available** on the dev site, so the items below are unverified. Run them
at production deploy time if wp-cli is available there.

### B1. Cache invalidation — 2 of 8 verified

| Trigger | Status | How to check |
|---|---|---|
| Product saved | ✅ **PASS** (dev, via wp-admin) | Save the product → refresh the archive → the value is reflected immediately |
| Stock changed | ✅ **PASS** (dev, via wp-admin) | Save `_stock_status` as outofstock → payload shows `is_in_stock: false` immediately; reverting restores it immediately |
| Variation saved | ⬜ Unverified | Change a variation's SKU/stock and save → selecting that combination on a card reflects it immediately |
| Price changed | ⬜ Unverified | Save a price → the card price changes immediately, and the parent price range updates while no option is selected |
| Term meta changed | ⬜ Unverified | Change an attribute term's swatch colour and save → the card chip colour changes immediately (the `flush_all()` path) |
| Sale starts/ends | ⬜ Unverified | Let a scheduled sale's start time pass and check for the strikethrough + sale price. **The key question is whether `cache_ttl()` refuses to span the next sale boundary** |
| Category edited → **no** invalidation | ⬜ Unverified | Use something like `wp transient list` to confirm the `yk_wcgv_v*` keys are **still alive** (correct scoping) |
| WPML per-language split | 🟡 Indirect | The dev French pages carry `lang:"fr"` with labels `couleur`/`Taille`, so they are fully separated. Confirming the key split itself needs a look at `yk_wcgv_v1_{id}_de/_fr/_en` |

wp-cli examples:

```bash
wp eval 'var_dump( get_transient("yk_wcgv_v1_<product-id>_de") !== false );'   # is it cached?
wp eval 'YK_WCGV_Data::flush_all();'                                          # force invalidation
```

### B2. Three performance numbers

| Target | Status |
|---|---|
| Cache hit: 70 queries or fewer | ⬜ Unverified — needs `SAVEQUERIES` |
| Cache hit: build under 150 ms | ⬜ Unverified |
| Cache miss: under 400 ms | ⬜ Unverified |
| **Payload at or under 25 KB** | ✅ **PASS** — 18.3 KB accumulated across 73 products (257 B per product) |

Measurement method (identical to `docs/step6-performance.md` in the engagement folder):

```bash
wp eval '
  wp_cache_flush();
  $t=microtime(true); $q=get_num_queries();
  YK_WCGV_Data::build_product_data( $ids );
  printf("queries=%d ms=%.0f\n", get_num_queries()-$q, (microtime(true)-$t)*1000);
'
```

---

## C. Must be done at production deploy

### C1. Enter the WPML translations — **outstanding**

**On dev, every plugin string on the French pages is still German.** Registration works
correctly; **the translations have simply not been entered in WPML → String Translation.**

Translate the following into fr/en under the context `yk-wc-grid-variations`:

| String | German source |
|---|---|
| `sale_badge` | SALE |
| `art_nr` | Art.-Nr. |
| `in_den_warenkorb` | In den Warenkorb |
| `zum_produkt` | Zum Produkt |
| `incl_tax` | inkl. MwSt. |
| `quantity` / `increase_quantity` / `decrease_quantity` | Quantity / Increase … / Decrease … |
| `add_to_cart_aria` | Add %s to cart |
| `js_added` | Hinzugefügt |
| `js_error` | Fehler. Bitte erneut versuchen. |
| `js_select_variation` | Bitte alle Optionen wählen. |
| `js_loading` / `js_all_loaded` / `js_load_error` / `js_retry` | Produkte werden geladen … / Alle Produkte geladen. / Laden fehlgeschlagen. / Erneut versuchen |
| **`js_reset_selection`** | **Auswahl zurücksetzen** ← new in 2.0.0 |

Attribute labels and term names are handled by WPML's **taxonomy translation** and were
verified working on dev (`couleur`/`Taille`, `jaune`/`noir`, `Droitier`/`Gaucher`).

### C2. Regenerate swatch image thumbnails

The `yk_wcgv_swatch` size (96×96, cropped) was added in 2.0.0. Images uploaded before that do
not have the file, so **they are served at full size** — they render, but they are heavy.

```bash
wp media regenerate --only-missing
```

### C3. Select the theme style variation

Pick the active variation under **WooCommerce → YK Grid Variations → Theme style variation**.
That adds the `body.yk-variant-{slug}` class, which is what activates the brand CSS.

⚠️ **If the active theme ships no style variations, the dropdown is not rendered at all.** That
was the case on the dev site (no `styles/*.json` directory). Set it with the filter instead:

```php
add_filter( 'yk_wcgv_style_variant', fn() => 'Shop Attack' );
```

### C4. Page cache configuration

- **Never cache `admin-ajax.php`.** A cached infinite-scroll response serves one visitor's
  page 2 to everyone
- **Keep the full-page cache TTL for archives under 12 hours.** The nonce is embedded in the
  archive HTML; once it expires, infinite scroll stops silently (pagination keeps working)
- Exclude cart, checkout and account pages as usual

### C5. Confirm archives use the classic grid

**Archives built with the Site Editor's Product Collection block show no cards.** Verify that
the shop, category and tag archives use the classic template path. Re-check this after every
major WooCommerce upgrade.

---

## D. Check the catalogue data as well

These are patterns where the **product data**, not the plugin, causes display problems. Any
site can have them, so take one pass after deploying.

| Pattern | Symptom | How to find it |
|---|---|---|
| Up-sells/cross-sells referencing a **variation** instead of a product | A variation shows up in the up-sell slot as if it were a product. (Since 2.0.0 this **no longer causes a fatal** — in 1.x it removed every script from the page) | Look for `product_variation` posts in `_upsell_ids` / `_crosssell_ids` |
| **Orphan terms** — attribute values no variation uses | The option is visible but goes disabled the moment it is picked; no combination resolves | Compare each product's attribute term set against the values its variations actually use |
| **Products with no price** | The price is **blank** on the card and the product page. Not purchasable | Store API: `prices.price == 0 && is_purchasable == false` |

> The counts and target lists actually found on this site were delivered separately in the
> **`server-scan/` folder of the engagement pack** — they contain product IDs and URLs, so they
> are kept out of this repository.

---

## E. Things that differ per environment

- **`show_sale_badge` defaults to ON, but a given site may have it switched off.** If the SALE
  badge is missing, check the setting before assuming a bug
- **Unsynced uploads on staging/development** — 404ing images leave the card image blank. That
  is not a plugin problem. However, **a server configuration that boots WordPress for missing
  files** hurts production performance too (roughly 1.8 s of a worker per request). Stop static
  paths from falling back to `index.php` in `.htaccess`/nginx
- **`WP_DEBUG_LOG` writing to the default path (`wp-content/debug.log`) leaves it readable over
  the web.** In production, move it outside the document root or turn logging off
