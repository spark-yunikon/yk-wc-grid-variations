# CLAUDE.md

이 저장소는 WooCommerce 플러그인 **YK WC Grid Variations** (v1.4.0) 입니다.
이 문서는 Claude Code가 이 코드베이스에서 작업할 때 따라야 할 구조·규칙을 정리한 것입니다.

---

## 1. 플러그인이 하는 일

WooCommerce의 기본 상품 카드를 대체해서, 카테고리 / 아카이브 / 태그 페이지에
자체 제작한 `.yk-card` 카드를 렌더링합니다. 카드가 제공하는 기능:

- **색상 스와치** (`.yk-swatch`) — 색상 속성 term을 원형 칩으로 표시, 클릭 시 선택
- **사이즈 필** (`.yk-size`) — 사이즈 속성을 pill 버튼으로 표시, 선택한 색상에서
  재고가 없는 조합은 자동으로 `is-disabled` 처리
- **수량 스테퍼** (`.yk-qty-wrap`) — `+` / `−` 버튼, 선택된 variation의
  `max_qty`로 상한을 clamp
- **AJAX 장바구니** (`.yk-add-to-cart`) — WooCommerce의 `?wc-ajax=add_to_cart`
  엔드포인트로 POST, 페이지 리로드 없이 담고 `added_to_cart` 이벤트를 트리거해
  테마의 cart fragment를 갱신
- **선택한 색상에 맞춰 카드 썸네일 교체** — variation 이미지의 `src` / `srcset` / `sizes`

추가로 **단일 상품 페이지**(`is_product()`)에서는 WooCommerce 기본 variation
`<select>` 드롭다운을 같은 스타일의 스와치/필로 치환하고, `div.quantity`에
`+` / `−` 스테퍼를 붙입니다. 이 기능은 설정에서 `enable_product_page`로 끌 수 있습니다.

bundle 타입 상품은 카드에서 장바구니 UI 대신 "Zum Produkt" 링크만 표시합니다.

---

## 2. 데이터 흐름

### 아카이브 / 카테고리 페이지

```
WooCommerce 루프
  └─ woocommerce_before_shop_loop_item
       └─ yk_wcgv_collect_product_id()
            → $GLOBALS['yk_wcgv_product_ids'][] = $product->get_id()

  └─ wc_get_template_part 필터
       └─ yk_wcgv_override_template_part()
            → woocommerce/content-product.php 로 교체 (.yk-card 마크업 출력)

wp_footer (priority 1)   ※ wp_print_footer_scripts()의 20보다 먼저
  └─ yk_wcgv_inject_data()
       └─ yk_wcgv_build_product_data( $ids )
            → wp_add_inline_script( 'yk-wcgv', 'window.ykWcgv = {...}', 'before' )

브라우저: DOMContentLoaded
  └─ assets/js/yk-wcgv.js
       → window.ykWcgv 를 읽어 모든 .yk-card 에 이벤트 바인딩
```

핵심 포인트:

- 상품 ID는 **루프가 도는 동안 전역 배열에 누적**되고, 페이지에 실제로 출력된
  상품에 대해서만 payload를 만듭니다. 별도 쿼리를 다시 돌리지 않습니다.
- 주입 시점이 `wp_footer` **priority 1**인 이유는 `wp_add_inline_script(...,
  'before')`가 `yk-wcgv.js` 태그가 출력되기 전에 등록되어야 하기 때문입니다.
  이 우선순위를 바꾸면 `window.ykWcgv`가 `undefined`가 되어 JS가 조용히 종료합니다
  (`yk-wcgv.js` 상단의 early return).
- 이미지·재고·최대수량 같은 variation 데이터는 **PHP에서 전부 직렬화**되어
  내려가므로, 스와치/사이즈 클릭 시 추가 AJAX 요청이 없습니다.
  장바구니 담기만 네트워크를 탑니다.

### 단일 상품 페이지

```
wp_footer (priority 1)
  └─ yk_wcgv_inject_product_page_data()
       → window.ykWcgvProduct = { colors: { slug: '#hex', ... } }

assets/js/yk-wcgv-product.js
  → form.variations_form 안의 select 를 스와치/필로 치환
  → select 의 value 를 바꾸고 change 이벤트를 dispatch
     (WooCommerce 기본 variation 로직은 그대로 사용)
  → MutationObserver 로 WC가 disable 시킨 option 상태를 시각 상태와 동기화
```

### payload 형태

`window.ykWcgv` 구조와 `window.ykWcgvProduct` 구조는 `README.md`의
"JS data payload" 섹션에 예시가 있습니다. payload 필드를 바꿀 때는
**PHP(`yk_wcgv_build_product_data`) · JS · README를 함께** 수정하세요.

---

## 3. 파일 구조

```
yk-wc-grid-variations.php      메인 파일: 훅 등록, payload 빌더, 설정 화면, 헬퍼 전부
woocommerce/
└── content-product.php        카드 마크업 템플릿 (wc_get_template_part 필터로 주입)
assets/
├── css/
│   ├── yk-wcgv.css            아카이브 카드 스타일 + 디자인 토큰(--yk-*) 정의
│   └── yk-wcgv-product.css    단일 상품 페이지 스타일 (yk-wcgv.css 토큰을 재사용)
└── js/
    ├── yk-wcgv.js             카드 인터랙션 (스와치/사이즈/수량/AJAX 카트)
    └── yk-wcgv-product.js     단일 상품 페이지 (select → 스와치/필 치환, 스테퍼)
languages/                     .po / .mo (선택)
```

> **README와의 차이 주의**: `README.md`는 템플릿이 테마 쪽
> (`yk-theme/woocommerce/content-product.php`)에 있어야 한다고 적고 있지만,
> 실제 구현은 **플러그인 내부**의 `woocommerce/content-product.php`를
> `wc_get_template_part` 필터로 주입합니다. 테마 오버라이드는 필요하지 않습니다.
> README를 손볼 일이 있으면 이 부분을 함께 바로잡으세요.

### 주요 심볼

| 심볼 | 역할 |
|------|------|
| `YK_WCGV_VERSION` / `YK_WCGV_DIR` / `YK_WCGV_URL` | 상수. 버전은 asset 캐시 버스팅에도 사용 |
| `YK_WCGV_COLOR_KEYS` / `YK_WCGV_SIZE_KEYS` | 색상/사이즈 속성 탐지용 키워드 목록 (en/de/fr) |
| `yk_wcgv_attr_matches()` | slug 또는 label에 키워드가 포함되는지 판정 |
| `yk_wcgv_build_product_data()` | 루프에서 모은 ID → JS payload 배열 |
| `yk_wcgv_resolve_color()` | 색상 slug/label → hex. 미매칭 시 hex 문자열 판정 → `#cccccc` |
| `yk_wcgv_get_settings()` | 옵션 `yk_wcgv_settings` + 기본값 병합 |
| `yk_wcgv_i18n()` | JS로 내려가는 번역 문자열의 단일 출처 (WPML 등록도 여기서 재사용) |

CSS는 `--yk-*` 커스텀 프로퍼티를 `:root`에 정의하고, 값은 테마의
`--wp--preset--color--*`를 참조합니다. 사이트별 색상 조정은 개별 규칙이 아니라
`body.yk-variant-{slug}` 블록에서 **토큰만 덮어쓰는** 방식으로 합니다
(`yk_wcgv_variant_body_class()`가 활성 style variant 제목으로 클래스를 붙입니다).

---

## 4. 코딩 규칙

### PHP

- **WordPress Coding Standards**를 따릅니다. 들여쓰기는 **탭**(스페이스 금지).
  괄호 안 공백(`func( $arg )`), Yoda 조건(`'bundle' === $type`) 등 기존 파일의
  스타일을 그대로 유지하세요.
- **출력은 반드시 이스케이프합니다.**
  - 텍스트: `esc_html()` / `esc_html_e()`
  - 속성값: `esc_attr()` / `esc_attr_e()`
  - URL: `esc_url()`
  - HTML 허용이 필요한 곳(가격 HTML 등): `wp_kses_post()`
  - 예외적으로 `$product->get_image()`처럼 코어가 이미 안전한 마크업을 반환하는
    경우만 그대로 출력합니다.
- **모든 폼 / AJAX 요청은 nonce 검증 + capability 체크**를 거칩니다.
  - 설정 폼은 Settings API(`settings_fields()` + `register_setting()`)를 쓰므로
    nonce가 자동 처리되며, 렌더 함수는 `current_user_can( 'manage_woocommerce' )`로
    시작합니다. 메뉴 등록 capability도 동일합니다.
  - 새 AJAX 엔드포인트(`wp_ajax_*` / `wc-ajax`)를 추가한다면
    `check_ajax_referer()` + `current_user_can()`를 **둘 다** 넣으세요.
    현재 장바구니 담기는 WooCommerce 자체의 `add_to_cart` 엔드포인트를 쓰므로
    별도 커스텀 엔드포인트가 없습니다 — 새로 만들지 말고 가능하면 코어 엔드포인트를
    계속 사용하세요.
  - 저장 값은 `yk_wcgv_sanitize_settings()`처럼 sanitize 콜백에서 화이트리스트
    방식으로 정제합니다 (`sanitize_key()`, `'1'`/`'0'` 정규화 등).
- **텍스트 도메인은 `'yk-wc-grid-variations'`** 하나만 사용합니다.
  하드코딩된 사용자 노출 문자열을 만들지 마세요.
- **신규 함수/상수 prefix는 `yk_wcgv_` / `YK_WCGV_`, 클래스는 `YK_WCGV_`** 입니다.
  전역 오염을 피하기 위해 prefix 없는 이름은 금지입니다.
- PHP 최소 버전은 **7.4** 입니다. `?->`, `match`, enum, named argument 등
  8.0+ 문법을 쓰지 마세요. (`??`, arrow function, typed property는 가능)

### JS

- 빌드 스텝이 없습니다. `assets/js/*.js`는 브라우저가 그대로 로드합니다.
  번들러/트랜스파일을 도입하지 마세요.
- 파일 전체를 IIFE로 감싸고 `'use strict'`를 유지합니다.
- DOM 조작은 vanilla JS를 쓰고, jQuery는 **WooCommerce 이벤트 연동**
  (`added_to_cart` 트리거, `reset_data` 수신)에만 사용합니다.
- 사용자 데이터가 들어가는 삽입은 `textContent` 또는 `escHtml()`을 쓰고,
  `innerHTML`에 미정제 값을 넣지 마세요.

### CSS

- 새 색상/반경 값은 하드코딩하지 말고 `--yk-*` 토큰으로 정의하거나 기존 토큰을 씁니다.
- `!important`는 WooCommerce/테마 기본 스타일을 이겨야 하는 경우로 한정합니다
  (기존 코드의 사용처가 그 예시입니다).
- 아카이브 클래스는 `.yk-`, 단일 상품 페이지 클래스는 `.yk-sp-` prefix를 씁니다.

### 하위 호환

- **기존 옵션 키 `yk_wcgv_settings`는 삭제하지 않습니다.**
  스키마를 바꿔야 하면 마이그레이션 코드로 처리하세요:
  기존 값을 읽어 새 형태로 변환 → 저장하고, `yk_wcgv_get_settings()`의
  `wp_parse_args()` 기본값으로 누락 키를 메웁니다.
  옵션 키 이름 변경, 값 삭제, `delete_option()` 호출은 금지입니다.
- 기존 CSS 클래스 훅(`.yk-card`, `.yk-swatch`, `.yk-size`, `.yk-qty-*`,
  `.yk-add-to-cart` 등)도 테마·사이트 커스텀 CSS가 의존할 수 있으므로
  이름을 바꾸지 말고 필요하면 추가하세요.
- 사용자 노출 문자열을 바꿀 때는 `yk_wcgv_i18n()`과
  `yk_wcgv_register_wpml_strings()`의 목록을 함께 갱신하세요.
  두 곳이 어긋나면 WPML 번역이 끊깁니다.

### 버전 올릴 때

플러그인 헤더의 `Version:`과 상수 `YK_WCGV_VERSION` **둘 다** 수정해야 합니다.
`YK_WCGV_VERSION`은 CSS/JS enqueue의 캐시 버스터로도 쓰이므로, asset을
수정했으면 반드시 올리세요.

---

## 5. 알려진 제약 — 블록 기반 Product Collection 블록과 비호환

이 플러그인은 WooCommerce의 **classic 템플릿 경로**
(`wc_get_template_part( 'content', 'product' )`)를 통해서만 카드를 렌더링합니다.
그래서 메인 파일에서 다음 필터로 classic 템플릿을 강제하고 있습니다:

```php
add_filter( 'option_wc_blocks_use_blockified_product_grid_block_as_template', fn() => 'no' );
```

결과적으로:

- **shop / 카테고리 / 태그 아카이브를 사이트 편집기의 Product Collection 블록으로
  만들면 안 됩니다.** 해당 블록은 자체 마크업으로 그리드를 그리기 때문에
  `wc_get_template_part` 훅을 전혀 타지 않고, `.yk-card` 스와치·사이즈 필·
  수량 스테퍼·AJAX 장바구니가 모두 나타나지 않습니다.
- 아카이브는 classic / 템플릿 기반 그리드를 유지해야 합니다.
- 이 옵션 이름은 WooCommerce 내부 구현에 의존하므로 **WooCommerce 메이저 업그레이드
  때마다 이 필터가 여전히 유효한지 재검증**해야 합니다. 필터가 무력화되면
  카드가 통째로 사라지는 형태로 실패합니다.

### 그 외 제약

- 카드 표시에는 `is_shop() || is_product_category() || is_product_tag() ||
  is_product_taxonomy()` 조건이 걸려 있습니다. 검색 결과·관련 상품·숏코드
  그리드에서는 기본 WooCommerce 카드가 나옵니다.
- `wp` 훅에서 WooCommerce 기본 루프 래퍼 액션 3개
  (`woocommerce_template_loop_product_link_open` / `_close`,
  `woocommerce_template_loop_add_to_cart`)를 `remove_action` 합니다.
  이 제거는 **전역**이라, 다른 곳의 상품 루프에도 영향을 줍니다.
- 색상/사이즈 탐지는 속성 slug·label의 **부분 문자열 매칭**입니다.
  새 언어를 지원하려면 `YK_WCGV_COLOR_KEYS` / `YK_WCGV_SIZE_KEYS`에 키워드를
  추가하고, `yk-wcgv-product.js` 상단의 `COLOR_KEYS` / `SIZE_KEYS` 배열도
  **같이** 갱신해야 합니다 (현재 PHP와 JS에 값이 중복 정의되어 있습니다).
- HPOS(custom order tables) 호환은 선언되어 있습니다
  (`yk_wcgv_declare_hpos_compatibility`).

---

## 6. 작업 시 체크리스트

- [ ] PHP 들여쓰기가 탭인가, WPCS 스타일과 일치하는가
- [ ] 새로 출력하는 값에 escape 함수가 붙었는가
- [ ] 새 함수/상수에 `yk_wcgv_` / `YK_WCGV_` prefix가 있는가
- [ ] 새 문자열에 `'yk-wc-grid-variations'` 텍스트 도메인이 붙었는가,
      필요하면 `yk_wcgv_i18n()` / WPML 등록 목록에도 추가했는가
- [ ] 옵션 스키마를 바꿨다면 기존 `yk_wcgv_settings` 값을 마이그레이션했는가
- [ ] payload를 바꿨다면 PHP·JS·README를 모두 갱신했는가
- [ ] asset을 수정했다면 `Version:` 헤더와 `YK_WCGV_VERSION`을 올렸는가
- [ ] PHP 7.4에서 동작하는 문법만 썼는가
