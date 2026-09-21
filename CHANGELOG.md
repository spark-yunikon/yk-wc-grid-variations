# Changelog

All notable changes to **YK WC Grid Variations**.

The format follows [Keep a Changelog](https://keepachangelog.com/en/1.1.0/), and the project
uses [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

---

## [Unreleased]

The archive card back to what the shop renders today. Three differences against the live
site, two causes.

### Fixed

- **The quantity stepper came out as three boxes with rules between them, roughly twice as
  wide.** `.yk-qty-input` clears the field down to `border: none; padding: 0; width: 32px`,
  but a host theme may box every `<input>` as a text field, and YK Starter's
  `input:not([type="checkbox"], …)` is (0,1,1) — `:not()` carries the specificity of its
  heaviest argument — so it beat the single class and painted its own 1px border and
  10px/20px padding onto the number. The selector is now `.yk-qty-wrap .yk-qty-input`
  (0,2,0), which wins without `!important`, and it spells out `border-radius`, `min-width`
  and `line-height` as well, since that rule supplies those too. A focused field no longer
  takes the theme's neutral tint. This is not a Shop Attack matter: a component that owns a
  control has to be able to say so.
- **On the single product page the quantity sat left of the pill's centre, with a gap before
  the + button.** WooCommerce's `woocommerce-blocktheme.css` — loaded only when the active
  theme is a block theme — gives the field a `margin-right: .5em`, and the field is the
  middle of three flex items, so the two buttons stayed symmetric while the number moved.
  Measured: wrap 129.5px instead of 122px, the field 3.8px off centre. Both steppers now
  set `margin: 0`.

### Changed

- **The Shop Attack variant no longer forces uppercase on `.yk-btn`.** German is the longest
  label in the catalogue — `IN DEN WARENKORB` wrapped to two lines and changed the grid's row
  height, and `HINZUGEFÜGT` ran past the button's edge with its check icon. The base
  sentence-case label is what the site shows.
- **The Shop Attack variant no longer repaints `.yk-add-to-cart.is-added` black.** The site's
  add-to-cart confirmation is the base green, so the override only described a design the
  shop has never run.

The variant's own job is untouched: its `--yk-*` tokens, the sale badge colour, the sale
price and the error text all stay. Turning the variant off instead would not have worked —
the default `--yk-danger` is the primary preset, which on this site is yellow, so the sale
price and the validation message would have gone yellow on white.

Version constants are deliberately not bumped here; they move at release time. Note that
`yk_wcgv_asset_version()` falls back to `YK_WCGV_VERSION` whenever `WP_DEBUG` is off, so on a
production site none of this is visible until the version moves or the cache is cleared.

---

## [2.0.0] — 2026-08-14

A rewrite of how the plugin decides *what* to show. 1.x hunted for one colour attribute and
one size attribute by keyword; 2.0.0 renders every attribute marked *Used for variations*,
in back-office order, with the swatch data an editor assigns per term.

### Breaking

- **`window.ykWcgv` payload schema changed.** The per-product `color_attr` / `size_attr`
  string pair is replaced by an ordered `attributes` array, each entry carrying `name`,
  `label`, `style` (`"swatch"` | `"pill"`) and `options`. Code reading `color_attr` or
  `size_attr` must be rewritten against `attributes`. See "JS data payload" in the README.
- **Variation image fields replaced by an image pool.** `variations[].image_url` /
  `image_srcset` are gone. Variations now carry `img`, an index into a per-product
  `images[]` array, or `null`. `srcset` and `sizes` are no longer shipped at all — the swap
  happens inside a fixed-size card slot, where one `src` is enough.
- **The "Visible attributes" setting is removed.** Attributes are discovered automatically,
  so the whitelist had become a way to silently hide newly added attributes. The option key
  `yk_wcgv_settings['visible_attrs']` is **not** deleted — a one-time migration empties it
  and archives the previous value under `yk_wcgv_migrations`, so nothing is lost and no
  stored list can keep hiding attributes.
- **`window.ykWcgvProduct` is no longer a bare colour map.** It is now
  `{ attributes: {…}, colors: {…}, i18n: {…} }`. The old flat slug → hex map survives as
  `colors` for backwards compatibility, but it cannot express two-tone or image swatches;
  read `attributes` instead.
- **`yk_wcgv_sanitize_settings()` removed.** The sanitise callback is
  `YK_WCGV_Settings::sanitize()`. Nothing called the global wrapper.
- **The theme template override is no longer used.** 1.x expected
  `yk-theme/woocommerce/content-product.php`; the card template now ships inside the plugin
  and is injected by filtering `wc_get_template_part`. Delete the theme copy — leaving it in
  place competes with the filter.

`YK_WCGV_SIZE_KEYS` is kept as a public constant, though the plugin no longer reads it.

### Added

- **All variation attributes are rendered**, not just colour and size — an antenna's
  *Frequenz*, a microphone's *Frequenzband*, anything marked *Used for variations*. Order
  follows the back office.
- **Per-term swatch overrides.** Attribute terms (`pa_*`) gain *Swatch colour* and *Swatch
  image* fields: a single hex, a two-tone pair (`#000000,#ffcc00`) rendered as a 135° split,
  or an uploaded image. A **Swatch** preview column is added to the term list.
- **Infinite scroll**, off by default. Global setting plus a per-category override
  (*Inherit* by default) plus the `yk_wcgv_pagination_mode` /
  `yk_wcgv_prefetch_distance` filters. Pagination links are hidden, never removed, so
  crawlers and JS-less visitors keep a working control.
- **Variation price and SKU on the card.** Both are swapped in only once *every* attribute
  is chosen; an incomplete selection keeps the server-rendered parent values.
- **Theme style variation selector** (*WooCommerce → YK Grid Variations*). WordPress records
  the active style variation nowhere, so `body.yk-variant-*` could not be detected
  automatically; it is now an explicit choice. The `yk_wcgv_style_variant` filter still
  overrides it.
- **Reset control on the single product page** (`.yk-sp-reset`), shown only once something
  is selected. It delegates to WooCommerce's own `.reset_variations` link rather than
  resetting anything itself, and is omitted entirely if that link is absent. This is the
  only visible escape from a combination WooCommerce has locked down — WC hides *Clear*
  until a variation resolves. Archive cards need no equivalent: they cascade, so they
  cannot dead-end.
- **`yk_wcgv_swatch` image size** (96×96, cropped) for swatch images.
- Screen-reader announcements when a card's price or SKU changes.
- Per-step measurement and verification records, kept **outside** this repository because
  they are specific to the site they were measured on. Every decision they support is
  written up in `CLAUDE.md`.

### Changed

- The single 674-line plugin file is split into `includes/` classes with a documented load
  order. Global wrappers (`yk_wcgv_get_settings()`, `yk_wcgv_i18n()`,
  `yk_wcgv_build_product_data()`) are kept so existing templates keep working.
- Attribute style is decided by term meta first, keyword match second — using the raw term
  meta, never `yk_wcgv_get_swatch()`, whose colour-name fallback would misread a term named
  "Rot" on a non-colour attribute as a colour.
- The single product page now derives swatches and pills from the **same PHP** that builds
  the archive payload, instead of its own duplicated keyword lists. Both screens agree by
  construction.
- Card selection is an explicit cascade: the first attribute is always clickable, each
  further attribute is filtered only by the ones above it, and changing an attribute clears
  the selections below it.
- Assets are versioned with `yk_wcgv_asset_version()` — `filemtime()` under `WP_DEBUG`, the
  plugin version in production.
- Swatch backgrounds are declared with longhands (`background-color` / `background-image`)
  everywhere — card template, product page, and both admin previews. The `background:`
  shorthand resets `background-color`, so mixing the two made the renderers diverge.
- Pill options no longer carry a `swatch` key at all; consumers must treat `swatch` as
  optional. This also removes one term lookup per pill option.

### Fixed

- **Add-to-cart silently failed for custom (non-taxonomy) attributes.** `sanitize_title()`
  was applied to the option value, which no longer matched the raw string WooCommerce
  stores on the variation. The raw value is used now.
- **The single product page could erase the shopper's choice.** The old code cleared a
  select whose active option became disabled, fighting WooCommerce's own matching; on
  three-attribute products a selection would vanish. Visual state is now read-only —
  `variations_form` owns availability, value, price and SKU.
- **Default loop hooks were removed globally**, which stripped the add-to-cart button from
  related products, upsells, cross-sells and `[products]` shortcodes. Removal is now scoped
  to the archive loop and the hooks are restored at their original priorities — and only
  the ones this plugin actually removed.
- Infinite scroll started page 2 in the middle of page 1, because `loop_shop_per_page`
  resolves differently under `admin-ajax.php` than on the front end (themes register it
  behind `! is_admin()`). The observed front-end value is recorded and reused.
- Variation image swaps left the previous picture on screen: the browser preferred the
  original `srcset` candidate over the new `src`. `srcset` and `sizes` are now cleared
  before the swap.
- Cached prices could outlive the start of a scheduled sale. The cache TTL is shortened so
  it never spans the next sale boundary.
- Swatch images whose attachment had been deleted rendered as empty chips; they now fall
  back to the hex value or the colour-name map.
- Light swatch fills dissolved into the white card. `is-light`, derived from WCAG relative
  luminance, gives them a visible edge; image swatches always get one, since their hint
  colour says nothing about the picture's rim.
- **A fatal error (HTTP 500) that removed every script from the page.** When `_upsell_ids`
  or `_crosssell_ids` reference a *variation* instead of a product, WooCommerce renders that
  variation in a normal shop loop — and `WC_Product_Variation::get_attributes()` returns
  plain strings rather than `WC_Product_Attribute` objects, so calling `->get_variation()`
  on them threw. Because the payload is injected at `wp_footer` priority 1, the throw
  stopped `wp_print_footer_scripts()` at priority 20, taking WooCommerce's and the theme's
  JavaScript down with it. Now guarded four ways, including a `Throwable` net around the
  injection (`TypeError` is an `Error`, not an `Exception`).
- The prefetch distance was applied in the wrong axis.

### Performance

- **Payload: −57% to −77%** on realistic catalogues, from pooling images per product
  instead of repeating URL and `srcset` strings on every variation. Measured on synthetic
  grids with real-world URL lengths: 12 products × 4 colours × 4 sizes, 141.7 KB → 41.7 KB
  when variations inherit the parent image; 142.6 KB → 60.9 KB with one image per colour.
  Savings scale with image sharing — a catalogue where every variation has its own image
  sees roughly none.
- **Prices are pooled the same way.** Measured across the baseline archives, 149 variations
  produced only 44 distinct price strings at ~230 bytes each. SKUs are deliberately *not*
  pooled: all 149 were unique, so a pool plus indices grew the payload (1,908 → 2,355 B).
- **Per-product transient cache** (`yk_wcgv_v…`, 12 h), keyed per language, primed in bulk
  for the whole loop. Invalidated on product, variation, stock, term, price, currency, tax
  and settings changes, plus `wc_scheduled_sales`.
- `get_available_variations()` is no longer used. It builds price HTML, availability HTML
  and a six-size image array per variation; only five fields are actually needed, and they
  are read from primed caches.
- Pill attributes skip swatch resolution entirely, removing one term lookup per option.

### Security

- The infinite-scroll endpoint verifies a nonce and whitelists every query variable it
  accepts. `post_status`, `posts_per_page` and `meta_query` are never taken from the
  request. Responses send `nocache_headers()` and `Cache-Control: no-store`.
- Add-to-cart still goes through WooCommerce's own `?wc-ajax=add_to_cart` endpoint; the
  plugin adds no cart endpoint of its own.

### Known limitations

- Archives built with the blockified **Product Collection block** bypass the classic
  template path and show default WooCommerce cards. The plugin forces the classic path via
  `option_wc_blocks_use_blockified_product_grid_block_as_template`; re-verify that filter
  after every major WooCommerce upgrade.
- Search results, related/upsell/cross-sell blocks and `[products]` shortcodes keep the
  default card by design.
- Product-level **custom** attributes have no terms, so they cannot carry swatch overrides
  and fall back to the colour-name map. Two-tone colours require a global attribute.
- **WPML does not copy term meta to translated terms**, so a translated colour term shows
  no stored swatch until one is set on it directly.
- On a WCML site that switches *currency* without switching *language*, the cache key does
  not distinguish currencies. Add the currency code to `YK_WCGV_Data::cache_key()` before
  deploying there.

---

## [1.4.0]

Baseline release: a single plugin file rendering colour swatches, size pills, a quantity
stepper and AJAX add-to-cart, with colour and size attributes located by keyword matching
and a "Visible attributes" whitelist in the settings.
