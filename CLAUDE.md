# CLAUDE.md

이 저장소는 WooCommerce 플러그인 **YK WC Grid Variations** (v2.0.0) 입니다.
이 문서는 Claude Code가 이 코드베이스에서 작업할 때 따라야 할 구조·규칙을 정리한 것입니다.

---

## 1. 플러그인이 하는 일

WooCommerce의 기본 상품 카드를 대체해서, 카테고리 / 아카이브 / 태그 페이지에
자체 제작한 `.yk-card` 카드를 렌더링합니다. 카드가 제공하는 기능:

- **variation 속성 전부 자동 표시** (STEP 3~) — "Used for variations"가 체크된 속성을
  백엔드 정렬 순서대로 모두 렌더합니다. 색상/사이즈 하드코딩은 없어졌고,
  안테나의 "Frequenz" 같은 속성도 그대로 나옵니다.
- **스와치** (`.yk-swatch`) — `style === 'swatch'`인 속성. 원형 칩, 클릭 시 선택
- **필** (`.yk-size`) — `style === 'pill'`인 속성. pill 버튼, 선택된 조합에서
  재고가 없는 값은 자동으로 `is-disabled` 처리
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
       └─ YK_WCGV_Data::collect_product_id()
            → $GLOBALS['yk_wcgv_product_ids'][] = $product->get_id()

  └─ wc_get_template_part 필터
       └─ YK_WCGV_Template::override_template_part()
            → woocommerce/content-product.php 로 교체 (.yk-card 마크업 출력)

wp_footer (priority 1)   ※ wp_print_footer_scripts()의 20보다 먼저
  └─ YK_WCGV_Assets::inject_data()
       └─ YK_WCGV_Data::build_product_data( $ids )   ← yk_wcgv_build_product_data() wrapper
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
  └─ YK_WCGV_Assets::inject_product_page_data()
       └─ YK_WCGV_Data::build_product_page_colors( $product )
            → window.ykWcgvProduct = { colors: { slug: '#hex', ... } }

assets/js/yk-wcgv-product.js
  → form.variations_form 안의 select 를 스와치/필로 치환
  → select 의 value 를 바꾸고 change 이벤트를 dispatch
     (WooCommerce 기본 variation 로직은 그대로 사용)
  → MutationObserver 로 WC가 disable 시킨 option 상태를 시각 상태와 동기화
```

### 스와치 색상 해석 (term meta, STEP 2~)

색상 term마다 관리자가 HEX(단색/투톤) 또는 이미지를 직접 지정할 수 있습니다.
저장은 term meta, 조회는 `yk_wcgv_get_swatch( $taxonomy, $term_slug )` 하나로 통일합니다.

```
관리자: 속성 term 화면 (edit-tags.php / term.php, pa_* 만)
  └─ YK_WCGV_Term_Meta  ※ is_admin() 일 때만 로드
       ├─ {$tax}_add_form_fields / {$tax}_edit_form_fields  → 필드 렌더
       ├─ created_{$tax} / edited_{$tax}                    → 저장
       └─ manage_edit-{$tax}_columns / _custom_column       → 목록 미리보기

프론트/관리자 공통 조회 (includes/functions-helpers.php)
  └─ yk_wcgv_get_swatch()
       이미지(첨부 유효) > term meta HEX > yk_wcgv_resolve_color() 이름 맵 > none
       → [ type, image, color, color2, is_light ]
```

- **훅 등록 시점**: `pa_*` taxonomy는 WooCommerce가 init(5)에서 등록하므로
  `wc_get_attribute_taxonomies()`를 도는 것은 `woocommerce_after_register_taxonomy`
  (+ init 20 폴백) 이후여야 합니다. 더 일찍 걸면 필드가 아예 렌더되지 않습니다.
- **`type` vs `color`**: `type`은 렌더 방식이고, `color` / `color2`는 해석 가능하면
  `type === 'image'`일 때도 채워집니다(테두리·대비 판단용 힌트). `type === 'none'`이면
  호출측이 `yk_wcgv_resolve_color()`(= `#cccccc`)로 폴백합니다.
- 첨부파일이 삭제돼 `wp_get_attachment_image_url()`이 false를 반환하면 이미지가 없는
  것으로 보고 HEX/이름 맵으로 폴백합니다.
- 같은 term이 한 페이지에서 여러 번 조회되므로 **요청 단위 static 캐시**가 들어 있습니다.
  persistent 캐시는 STEP 6 예정이며, 그때 term meta 저장 핸들러의
  `// TODO(v2 STEP6): invalidate product transient` 지점에서 무효화를 붙입니다.

### 표시 속성 선택과 style 판정 (STEP 3~)

```
YK_WCGV_Data::build_attributes( $product )
  └─ $product->get_attributes() 순회 (백엔드 정렬 순서 유지)
       ├─ $attribute->get_variation() !== true  → skip
       │    ("Used for variations" 미체크 속성은 절대 표시하지 않음)
       ├─ options[] 만들기
       │    taxonomy    → value = term slug
       │    커스텀 속성 → value = 원본 옵션 문자열 (sanitize_title 금지!)
       └─ style 판정 (determine_attribute_style, 순서 고정)
            1. term 중 하나라도 raw swatch term meta 보유 → 'swatch'
            2. 속성 slug/label이 YK_WCGV_COLOR_KEYS 매칭   → 'swatch'
            3. 그 외                                        → 'pill'
```

- **★ style 판정에 `yk_wcgv_get_swatch()`를 쓰지 마세요.** 이 헬퍼는 term meta가 없어도
  이름 맵 폴백으로 `color`를 채우기 때문에, "Rot"이라는 이름의 Frequenz term이
  색상 속성으로 오인됩니다. 판정은 반드시 `yk_wcgv_term_has_swatch_meta()`
  (raw `get_term_meta` 직접 조회)로 합니다.
- **커스텀(비-taxonomy) 속성 값**: WooCommerce는 variation에 원본 문자열을 저장합니다
  (`'868 MHz'`). `sanitize_title()`을 적용하면 `variations[].attributes` 값과 어긋나
  **장바구니 담기가 조용히 실패**합니다. 이건 STEP 3에서 고친 실제 버그입니다.
- `YK_WCGV_SIZE_KEYS`는 이제 style 판정에 쓰이지 않습니다(상수는 템플릿 호환용으로 유지).
- **`style === 'pill'`인 속성의 option에는 `swatch` 키가 아예 없습니다.** pill은 스와치
  데이터를 쓰지 않으므로 페이로드에서 뺐습니다. 렌더러는 `swatch`를 **optional로**
  취급해야 합니다(`option.swatch && ...`). 부수 효과로 pill 옵션마다 돌던
  term 조회도 사라집니다.
- **커스텀(비-taxonomy) 속성 분기는 방어 코드입니다.** 2026-08 실사이트 스캔 결과
  208개 상품 중 variation용 커스텀 속성을 쓰는 상품은 **0개**였습니다. 제거하지 말고
  그대로 두세요 — 나중에 커스텀 속성이 추가되면 값 불일치로 장바구니가 조용히
  실패하는 것을 막아줍니다.

### variation 이미지 풀 (STEP 3.5~)

variation마다 `image_url` / `image_srcset` / `image_sizes`를 반복해서 싣던 구조를
**상품 단위 이미지 풀 + 인덱스 참조**로 바꿨습니다.

```
'images' => [                                  // 상품 단위, 0부터의 순차 인덱스
   0 => [ 'url' => '...', 'srcset' => '...', 'sizes' => '...' ],
   1 => [ ... ],
],
'variations' => [
   [ 'variation_id' => 43, 'attributes' => {...}, 'img' => 0, ... ],
   [ 'variation_id' => 44, 'attributes' => {...}, 'img' => null, ... ],
]
```

- 풀의 키는 **attachment ID가 아니라 0부터의 순차 인덱스**입니다(짧게 유지).
  attachment ID → 인덱스 매핑은 `$image_lookup`으로 빌드 중에만 씁니다.
- 같은 attachment를 쓰는 variation은 **같은 인덱스를 공유**합니다. variation에
  자체 이미지가 없어 부모 이미지로 폴백하는 경우도 풀에는 1건만 들어갑니다.
- 이미지가 없거나 attachment가 삭제돼 URL이 비면 **`'img' => null`**.
- 이 구조는 STEP 7(무한 스크롤)에서 페이지마다 payload가 누적되기 때문에
  STEP 6 캐싱보다 먼저 적용했습니다.

### payload 형태

`window.ykWcgv` 구조와 `window.ykWcgvProduct` 구조는 `README.md`의
"JS data payload" 섹션에 예시가 있습니다. payload 필드를 바꿀 때는
**PHP(`yk_wcgv_build_product_data`) · JS · README를 함께** 수정하세요.

---

## 3. 파일 구조

```
yk-wc-grid-variations.php      부트스트랩: 상수 정의, includes require, 클래스 init() 호출,
                               HPOS 호환 선언
includes/
├── functions-helpers.php      yk_wcgv_attr_matches(), yk_wcgv_resolve_color()
├── class-yk-wcgv-i18n.php     텍스트도메인 로드, JS 문자열, WPML 등록
├── class-yk-wcgv-settings.php 설정 페이지, get/sanitize
├── class-yk-wcgv-data.php     루프 ID 수집, payload 빌더
├── class-yk-wcgv-assets.php   wp_enqueue_scripts, 인라인 스크립트 주입
├── class-yk-wcgv-template.php wc_get_template_part 오버라이드, 루프 훅 제거, body_class
└── class-yk-wcgv-term-meta.php 속성 term별 스와치 색상/이미지 관리자 UI
                               ★ is_admin()일 때만 require + init
woocommerce/
└── content-product.php        카드 마크업 템플릿 (wc_get_template_part 필터로 주입)
assets/
├── css/
│   ├── yk-wcgv.css            아카이브 카드 스타일 + 디자인 토큰(--yk-*) 정의
│   └── yk-wcgv-product.css    단일 상품 페이지 스타일 (yk-wcgv.css 토큰을 재사용)
└── js/
    ├── yk-wcgv.js             카드 인터랙션 (스와치/사이즈/수량/AJAX 카트)
    ├── yk-wcgv-product.js     단일 상품 페이지 (select → 스와치/필 치환, 스테퍼)
    └── yk-wcgv-admin.js       관리자 term 화면 (색상 입력 동기화, 미디어 프레임)
languages/                     .po / .mo (선택)
```

> **README와의 차이 주의**: `README.md`는 템플릿이 테마 쪽
> (`yk-theme/woocommerce/content-product.php`)에 있어야 한다고 적고 있지만,
> 실제 구현은 **플러그인 내부**의 `woocommerce/content-product.php`를
> `wc_get_template_part` 필터로 주입합니다. 테마 오버라이드는 필요하지 않습니다.
> README를 손볼 일이 있으면 이 부분을 함께 바로잡으세요.

> **로드 순서 규칙**: `includes/`는 위 트리 순서대로 `require_once` 합니다.
> 헬퍼(`functions-helpers.php`)가 항상 먼저 로드되어야 하고, 클래스들은 파일 로드
> 시점에 각자의 `init()`에서 훅을 등록합니다(메인 파일 하단에서 호출).
> `YK_WCGV_COLOR_KEYS` / `YK_WCGV_SIZE_KEYS`는 템플릿이 직접 참조하므로
> **메인 파일 최상단의 전역 `const`로 유지**합니다 — 클래스 상수로 옮기지 마세요.
>
> **전역 wrapper 함수 유지**: `yk_wcgv_get_settings()`, `yk_wcgv_i18n()`,
> `yk_wcgv_build_product_data()`는 클래스 메서드로 옮겨졌지만 동일 이름의 전역
> wrapper가 남아 있습니다. `woocommerce/content-product.php`가 `function_exists()`로
> 호출하므로 wrapper를 제거하면 카드가 조용히 망가집니다.

### 주요 심볼

| 심볼 | 역할 |
|------|------|
| `YK_WCGV_VERSION` / `YK_WCGV_FILE` / `YK_WCGV_DIR` / `YK_WCGV_URL` | 상수. 버전은 asset 캐시 버스팅에도 사용 |
| `YK_WCGV_COLOR_KEYS` | style 판정 폴백(규칙 2)용 색상 키워드 목록 (en/de/fr) |
| `YK_WCGV_SIZE_KEYS` | **STEP 3부터 아카이브에서는 미사용.** 템플릿·단일 상품 페이지 호환용으로만 유지 |
| `YK_WCGV_SWATCH_IMAGE_SIZE` | `yk_wcgv_swatch` (96×96 crop). 페이로드 스와치 이미지 URL 크기. 관리자 목록은 `thumbnail` 유지 |
| `yk_wcgv_term_has_swatch_meta()` | raw term meta에 유효한 스와치 값이 있는지. **style 판정은 반드시 이걸로** |
| `yk_wcgv_attr_matches()` | slug 또는 label에 키워드가 포함되는지 판정 |
| `yk_wcgv_build_product_data()` | 루프에서 모은 ID → JS payload 배열 (`YK_WCGV_Data::build_product_data()` wrapper) |
| `yk_wcgv_resolve_color()` | 색상 slug/label → hex. 미매칭 시 hex 문자열 판정 → `#cccccc` (`YK_WCGV_COLOR_FALLBACK`) |
| `yk_wcgv_get_swatch()` | term의 최종 스와치 표현 조회. 이미지 > term meta HEX > 이름 맵 > none. 요청 단위 static 캐시 |
| `yk_wcgv_parse_swatch_colors()` | `"#000,#ffcc00"` → 검증된 hex 0~2개 배열 (3개째부터 버림) |
| `yk_wcgv_is_light_color()` | WCAG 상대 휘도 > 0.5 이면 true |
| `YK_WCGV_META_SWATCH_COLOR` / `_IMAGE_ID` | term meta 키 상수. 관리자 클래스와 조회 헬퍼의 단일 출처 |
| `yk_wcgv_get_settings()` | 옵션 `yk_wcgv_settings` + 기본값 병합 (`YK_WCGV_Settings::get()` wrapper) |
| `yk_wcgv_i18n()` | JS로 내려가는 번역 문자열의 단일 출처 (`YK_WCGV_I18n::strings()` wrapper, WPML 등록도 여기서 재사용) |
| `$GLOBALS['yk_wcgv_product_ids']` | 루프 훅 → `wp_footer` 주입 사이에 공유되는 상품 ID 배열. 클래스 프로퍼티로 바꾸지 말 것 |

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

### ⚠️ 버전 — 작업 단계(STEP)마다 올리지 마세요

> **STOP — 버전 번호를 건드리기 전에 읽으세요.**
>
> - **STEP 단위로 버전을 올리지 않습니다.** asset(CSS/JS)을 수정했더라도,
>   기능을 추가했더라도, 개발 중인 STEP에서는 **그대로 둡니다.**
> - **릴리스 버전은 STEP 8에서 한 번만 정합니다.** 그때 전체 변경분을 보고
>   최종 번호를 결정해 `Version:` 헤더와 `YK_WCGV_VERSION`을 함께 올립니다.
> - 문서나 주석에서 변경 이력을 가리킬 때는 버전 번호 대신 **STEP 번호**를
>   쓰세요 (`since STEP 3`, `STEP 3~`). 아직 확정되지 않은 번호를 문서에
>   박아 넣으면 STEP 8에서 전부 고쳐야 합니다.
> - 개발 중 캐시 문제는 브라우저 하드 리프레시로 해결하세요. 캐시 버스팅을
>   이유로 버전을 올리지 않습니다.
>
> 실제로 STEP 2·STEP 3에서 이 규칙을 어겨 2.0.0 → 2.1.0 → 2.2.0으로 올라갔다가
> 되돌린 적이 있습니다.

STEP 8에서 실제로 버전을 올릴 때는 플러그인 헤더의 `Version:`과 상수
`YK_WCGV_VERSION` **둘 다** 수정해야 합니다. `YK_WCGV_VERSION`은 CSS/JS
enqueue의 캐시 버스터로도 쓰입니다.

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

- **설정의 "Visible attributes"는 STEP 3에서 제거됐습니다.** 속성은 이제 자동 탐지되므로
  수동 화이트리스트가 필요 없습니다. 옵션 키 `yk_wcgv_settings['visible_attrs']`는
  **삭제하지 않고 남겨두되 빈 배열로 1회 마이그레이션**합니다
  (`YK_WCGV_Settings::maybe_migrate()`, `init` 훅, 프론트에서도 실행).
  완료 플래그와 이전 값은 별도 옵션 `yk_wcgv_migrations`에 보관합니다.
  남아 있던 화이트리스트를 지우지 않으면 신규 속성이 안 보이는 유령 버그가 됩니다.
- 카드 표시에는 `is_shop() || is_product_category() || is_product_tag() ||
  is_product_taxonomy()` 조건이 걸려 있습니다. 검색 결과·관련 상품·숏코드
  그리드에서는 기본 WooCommerce 카드가 나옵니다.
- **[알려진 버그 — 미수정, `TODO(v2 STEP4)`]** `wp` 훅
  (`YK_WCGV_Template::remove_default_loop_hooks()`)에서 WooCommerce 기본 루프 래퍼
  액션 3개 (`woocommerce_template_loop_product_link_open` / `_close`,
  `woocommerce_template_loop_add_to_cart`)를 **조건 없이** `remove_action` 합니다.
  이 제거는 **전역**이라 아카이브가 아닌 곳 — 연관상품, 업셀/크로스셀,
  `[products]` 숏코드 그리드 — 에도 영향을 줍니다. 그 루프들은 기본 WooCommerce
  카드로 렌더되는데 상품 링크 래퍼와 기본 장바구니 버튼이 사라집니다.
  실제 버그이며 STEP4에서 아카이브 페이지로 스코프를 좁혀 수정할 예정입니다.
  (v2.0.0 리팩터링은 순수 구조 분리라 동작을 그대로 보존했습니다.)
- **term 스와치(term meta)는 글로벌 속성(`pa_*`)에만 적용됩니다.** 상품별 로컬
  커스텀 속성은 term이 존재하지 않아 term meta를 붙일 수 없고, 계속
  `yk_wcgv_resolve_color()` 이름 맵으로만 색이 결정됩니다. 투톤 색상이 필요한
  클라이언트는 해당 속성을 글로벌 속성으로 만들어야 합니다.
- **WPML: term meta는 번역 term에 자동 복사되지 않습니다.** 번역된 색상 term은
  스와치 색상/이미지가 비어 보입니다. `yk_wcgv_get_swatch()` 안에
  `TODO(v2 STEP5)` 지점을 잡아뒀고, 원본(source) term meta로 폴백하는 처리를
  거기서 추가하면 됩니다. 현재는 미구현입니다.
- 관리자 화면 자산(`yk-wcgv-admin.js` + `wp_enqueue_media()`)은
  `edit-tags.php` / `term.php` 이면서 taxonomy가 `pa_`로 시작할 때만 로드합니다.
  다른 관리자 화면을 오염시키지 않도록 이 조건을 넓히지 마세요.
- 아카이브 카드의 style 판정 폴백(규칙 2)은 여전히 속성 slug·label의
  **부분 문자열 매칭**입니다. 새 언어를 지원하려면 `YK_WCGV_COLOR_KEYS`에 키워드를
  추가하고, **단일 상품 페이지**는 아직 구 방식이므로 `yk-wcgv-product.js` 상단의
  `COLOR_KEYS` / `SIZE_KEYS` 배열도 **같이** 갱신해야 합니다
  (PHP와 JS에 값이 중복 정의되어 있습니다).
- **단일 상품 페이지는 아직 색상/사이즈 2축 하드코딩입니다.** STEP 3의 "variation 속성
  전부 표시"는 아카이브 카드(`window.ykWcgv`) 쪽만 적용됐습니다.
- **페이로드 크기**: `window.ykWcgv`는 인라인 스크립트라 압축 전 크기가 그대로 HTML에
  들어갑니다. 이미지 풀 도입 후 실측(합성 데이터, 이미지 URL/srcset 현실 길이 기준):

  | 시나리오 | variation이 부모 이미지 상속 | 색상별 이미지 | variation마다 고유 이미지 |
  |---|---|---|---|
  | 12상품 × 4색 × 4사이즈 | 141.7 → **41.7 KB** (−71%) | 142.6 → **60.9 KB** (−57%) | 143.5 → 138.7 KB (−3%) |
  | 12상품 × 24색 × 6사이즈 | 1198 → **273 KB** (−77%) | 1207 → **421 KB** (−65%) | 1215 → 1199 KB (−1%) |

  절감폭은 **이미지 공유 정도에 비례**합니다. variation마다 고유 이미지를 쓰면
  풀이 곧 variation 수와 같아져 효과가 없습니다. 그 경우가 문제가 되면
  STEP 6에서 `srcset` 자체를 줄이거나(후보 개수 축소) 지연 로딩을 검토하세요.
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
- [ ] ⚠️ 버전을 **건드리지 않았는가** (STEP 중에는 올리지 않음, STEP 8에서 한 번만)
- [ ] PHP 7.4에서 동작하는 문법만 썼는가
