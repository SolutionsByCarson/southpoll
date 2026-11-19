# South Poll Brand & Style Guide (for the plugin UI)

Derived 2026-10-18 from the live theme configuration, rendered pages, and child-theme CSS. Every value lists its source so it can be
re-verified. The plugin must look native to southpoll.com: it should consume these tokens rather than invent new ones.

## Sources of truth

| Source | Where | What it defines |
|---|---|---|
| Cornerstone global colors | option `cornerstone_color_items` | Color 1 `#8d183c`, Color 2 `#b2892e`, Color 3 `rgb(0,0,0)`, Color 4 `rgb(255,255,255)` |
| Cornerstone font manager | option `cornerstone_font_items` | "Headings" = Oswald (Google), normal 600 / bold 700; "Body Copy" = Montserrat (Google), normal 400 / bold 700 |
| Pro theme options | options `x_*` (see `../research/db-theme-gf-raw.md`) | body/heading colors, nav, buttons, layout widths, letter spacing, root font size |
| Compiled theme CSS | inline `<style id="cs-inline-css">` on every page (41 KB) | the above compiled; `:root` CSS vars |
| Google Fonts request | `<link>` on every page | `Oswald:600,600i,700,700i,500,500i,400,400i | Montserrat:400,400i,700,700i,600,600i` (latin, latin-ext, display=auto) |
| RTCL style settings | option `rtcl_style_settings` → inline `<style id="rtcl-public-inline-css">` | `--rtcl-primary-color:#8d183c` etc. (will disappear with the plugin) |
| Customizer Additional CSS | post 566 → `<style id="wp-custom-css">` | mobile heading sizes |
| Child theme | `pro-child/style.css`, `functions.php` inline styles | `#8d183c`, `#791534` hover, `#009da5` focus outline, grid/card styles |
| Cornerstone page data | `_cornerstone_data` meta on pages | usage frequency of colors/sizes/transforms across builder pages |
| Logo files | `uploads/2024/04/SouthPoll-HorizontalLogo.png` (960×211, header), `uploads/2024/04/South-Poll-logo-WEB.png` | logo assets |

## Color palette

| Token | Value | Role | Source |
|---|---|---|---|
| `--sp-maroon` | `#8d183c` | Primary brand color: links, headline accents, RTCL buttons, button hover, pagination | Cornerstone Color 1; `x_site_link_color`; `rtcl_style_settings.primary`; 24 uses in home CSS |
| `--sp-maroon-dark` | `#781433` | Darker maroon (RTCL `--rtcl-primary-dark`); child theme uses `#791534` for reset-button hover | RTCL inline CSS; `functions.php` |
| `--sp-gold` | `#b2892e` | Secondary/accent: default button background and border, link hover | Cornerstone Color 2; `x_button_background_color`; `x_site_link_color_hover`; 14 uses |
| `--sp-gold-wash` | `rgba(178,137,46,0.15)` | Soft gold section/background wash | 78 uses in builder pages |
| `--sp-ink` | `#272727` | Headings, logo text, nav hover | `x_headings_font_color`, `x_logo_font_color`, `x_navbar_link_color_hover` |
| `--sp-body` | `rgb(90,90,90)` / `#5a5a5a` | Body text | `x_body_font_color` |
| `--sp-muted` | `#999999` | Nav link default, secondary text | `x_navbar_link_color` |
| `--sp-black` | `#000000` | Cornerstone Color 3 (overlays) | `cornerstone_color_items` |
| `--sp-white` | `#ffffff` | Page background, button text | `x_design_bg_color` (Color 4), `x_button_color` |
| `--sp-sky` | `#e3f0fc` | Light blue background used in builder pages (91 uses) | `_cornerstone_data` |
| `--sp-maroon-tint-8` | `rgba(141,24,60,0.08)` | Hover/selection tint | RTCL `--rtcl-primary-tint` |
| `--sp-maroon-tint-4` | `rgba(141,24,60,0.04)` | Row/soft tint | RTCL `--rtcl-primary-soft` |
| `--sp-border` | `#dddddd` / `#eeeeee` | Card borders, dividers | child theme `.store-card` border `#ddd`; `#eee` table rules |
| `--sp-focus` | `#009da5` | Focus outline used on the classifieds search input (one-off teal) | `functions.php` `[classifieds_search]` CSS |
| `--sp-danger` | `#a71000` | Legacy button bottom color (Pro default), usable as error red | `x_button_bottom_color` |
| Status tints (optional) | new `#f3fbf6/#c9e9d6`, featured `#fffbf5/#ffe8cc`, top `#fffdea/#ffd274`, popular `#fff6f7/#fbd5da` | RTCL card badges | RTCL inline CSS |

### Accessibility rules (WCAG 2.x AA), adopted 2026-10-19

Measured with `temp/scripts/contrast-check.php` (run it after any token change; it exits non-zero when a shipped pair fails).

| Rule | Why |
|---|---|
| Gold `#b2892e` is a **surface** colour only. Text on gold is ink `#272727` (4.63:1). Never white on gold (3.23:1 fails). | Secondary buttons, "New listing" badges, gold banners. |
| Gold as **text** on white uses `--sp-gold-deep` `#8a6a1c` (5.05:1). | Eyebrow labels, warning badge/alert text. |
| Smallest text grey is `--sp-muted` `#6f6f6f` (5.02:1 on white, 4.69:1 on `#f7f7f7`). `#8a8a8a` (3.45:1) is reserved for non-text UI. | Helper text, table headers, stat labels, email footers. |
| Link hover is `--sp-maroon-dark` with underline, not gold. | Gold on white fails for link text. |
| Form control borders and the toggle track use `--sp-border-strong` `#8a8a8a` (3.45:1 non-text). `#ddd` stays for decorative dividers. | WCAG 1.4.11 component boundaries. |
| Focus ring is solid: 2px white gap + 4px maroon (9:1). | Visible keyboard focus on every control. |
| Status lights: on `#1e8e3e` (4.21:1), off `#c62828` (5.62:1), always accompanied by the listing count so colour is not the only cue. | WCAG 1.4.1 + 1.4.11. |
| Disabled buttons (50% opacity) are exempt from contrast requirements but must never be the only path to an action. | WCAG exception for inactive components. |

Reference ratios: maroon on white 9.02:1; white on maroon 9.02:1; white on maroon-dark 10.79:1; ink on white 14.94:1; body `#5a5a5a` on white 6.90:1;
white on danger `#a71000` 7.73:1. The theme's own gold buttons with white text on the public site fail AA and are outside the plugin's scope.

## Typography

| Element | Family | Weight | Size / spacing | Transform | Source |
|---|---|---|---|---|---|
| Root | — | — | 15px desktop (xl/lg/md), 16px sm/xs (stepped) | — | `x_root_font_size_stepped_*` |
| Body | Montserrat, sans-serif | 400 (bold 700) | 1rem; color `#5a5a5a` | — | `cornerstone_font_items` Body Copy; `x_content_font_size_rem` |
| Headings h1–h6 | Oswald, sans-serif | 600 (bold 700) | letter-spacing `-0.035em` (theme) / `-0.025em` (builder); color `#272727` | Builder headlines are uppercase (1,722 of ~1,730 headline uses) | `x_headings_*`, `_cornerstone_data` |
| Mobile headings (≤767px) | — | — | h1 30px, h2 25px, h3 20px, h4 20px, h5 18px, h6 16px (`!important`) | — | Customizer CSS |
| Builder headline sizes | — | — | common: 1.125em, 2em, 2.5em, 3em, 4.2em, 5.5em (hero) | uppercase | `_cornerstone_data` |
| Navigation | inherit (Montserrat) | 700 | 13px, letter-spacing `0.085em`, color `#999` → `#272727` | uppercase | `x_navbar_*` |
| Logo text (unused; image logo) | — | 700 | 42px | — | `x_logo_*` |

Practical scale for plugin screens (desktop): h1 2.5em Oswald 600 uppercase; h2 2em; h3 1.5em; h4 1.125em; body 1rem/1.6 Montserrat; small 0.875rem;
labels 0.8em uppercase Montserrat 700 letter-spacing 0.05em.

## Layout & spacing

| Token | Value | Source |
|---|---|---|
| Site width | 88%, max 1200px (`--x-layout-site-width`, `--x-layout-site-max-width`) | `x_layout_site_*` |
| Content/sidebar | content 72%, sidebar 250px (`x-main` calc) | `x_layout_content_width`, `x_layout_sidebar_width` |
| Breakpoints | 480 / 767 / 979 / 1200 px (Pro: xs <480, sm 480–766, md 767–978, lg 979–1199, xl ≥1200) | `x_breakpoint_ranges` |
| Grid (directory cards) | 4 cols ≥1024, 3 ≤1024, 2 ≤768, 1 ≤480; gap 20px | child `[member_grid]` CSS |
| Section padding (builder) | gaps 0em / 3em / 12em-2em used on page tops | `_cornerstone_data` |
| Card | 1px solid `#ddd`, padding 15px, centered text, logo box max-height 150px | child `.store-card` |
| Shadows | card `0 3px 25px 0 rgba(0,0,0,0.15)`; modal/hero `0 0.15em 0.65em rgba(0,0,0,0.25)` | compiled CSS |
| Radius | buttons square (0) per theme; child theme uses 5px on filter buttons; RTCL alerts 6px | `x_button_shape`, child CSS, mu-plugin notice |

## Buttons

| Style | Background | Text | Border | Hover | Source |
|---|---|---|---|---|---|
| Theme default (`.x-btn`) | gold `#b2892e` | white | gold | maroon `#8d183c` bg + border | `x_button_*` (flat, square, regular) |
| "RedButton" template | maroon | white | — | darker maroon | cs_template 263 |
| "GoldButton" template | gold | white | — | maroon | cs_template 740 |
| RTCL `.rtcl-btn` | maroon | white | maroon | maroon | `rtcl_style_settings` |
| Child "Reset" link-button | maroon `#8d183c` | white | — | `#791534` | `functions.php` |

Recommendation for the plugin: primary = maroon bg / white text / hover `#781433`; secondary = gold bg / white text / hover maroon; tertiary = outline
maroon; all square corners (0–4px max), Montserrat 700, 13–14px, uppercase with 0.05–0.085em tracking to match the nav.

## Forms & inputs

- Current RTCL inputs: `.rtcl-form-control` (Bootstrap-like, 1px border, ~8px padding). Child search field: borderless inside a white 40.5px bar with
  inline SVG magnifier, 14px text, focus outline 2px `#009da5`.
- Recommendation: 1px `#ddd` border, 10px padding, 4px radius, Montserrat 1rem, focus ring maroon 2px (replace the teal), labels uppercase 0.8em 700
  `#272727`, required star maroon, helper text `#999`.

## Icons & imagery

- Font Awesome webfont loaded by Pro (solid, regular, light, brands + shim) — plugin may use FA classes already on the page but should not depend on
  them; prefer inline SVG (as the child search box does).
- Header logo: `SouthPoll-HorizontalLogo.png` 960×211 (transparent, used at ~235px nav width). Square/stacked: `South-Poll-logo-WEB.png`.
  No `site_icon`/favicon is set (favicon.ico in root is empty).
- Photography: full-bleed hero images (1920×1080), 470×394 cards on home; farm banners 992×300, logos 200×150, listing gallery 760×462, thumbs 320×240.

## Email styling

- Current RTCL emails: base color `#0071bd`, background `#f7f7f7`, body `#ffffff`, text `#3c3c3c`, footer "{site_title}", no header image.
- Plugin emails should use maroon header bar `#8d183c` with the horizontal logo on white, Montserrat/Arial fallback, gold `#b2892e` for buttons only with
  white text, text `#3c3c3c`, footer with association address (PO Box 260, Kearney, MO 64060 · 816-629-8010 · registry@southpoll.com — from Join page).

## Voice & labels (from existing copy)

- "Farm" not "Store"; "Member Directory"; "Classifieds"; "My Account"; "Join SPGCA"; "Active Member – Renew yearly $100/yr"; "Junior Member" (free, 8–21, handled by GF form, out of scope).
- FAQ tone: friendly second person with numbered steps and emoji callouts (💡 tip, ✅ note, ⏱️ timing).

## CSS token block to ship with the plugin

```css
:root{
  --sp-maroon:#8d183c; --sp-maroon-dark:#781433; --sp-maroon-tint:rgba(141,24,60,.08); --sp-maroon-soft:rgba(141,24,60,.04);
  --sp-gold:#b2892e; --sp-gold-deep:#8a6a1c; --sp-on-gold:#272727; --sp-gold-wash:rgba(178,137,46,.15);
  --sp-ink:#272727; --sp-body:#5a5a5a; --sp-muted:#6f6f6f; --sp-muted-light:#8a8a8a;
  --sp-border:#ddd; --sp-border-strong:#8a8a8a; --sp-border-light:#eee; --sp-bg:#fff; --sp-bg-alt:#f7f7f7; --sp-sky:#e3f0fc;
  --sp-success:#2e7d32; --sp-warning:#b2892e; --sp-danger:#a71000; --sp-light-on:#1e8e3e; --sp-light-off:#c62828;
  --sp-font-heading:"Oswald",sans-serif; --sp-font-body:"Montserrat",sans-serif;
  --sp-radius:0; --sp-radius-sm:4px; --sp-shadow:0 3px 25px 0 rgba(0,0,0,.15);
  --sp-focus:0 0 0 2px #fff, 0 0 0 4px var(--sp-maroon);
  --sp-max-width:1200px; --sp-site-width:88%;
}
```

The live token file is `wp-content/plugins/southpoll-membership/assets/css/sp-tokens.css`; this block mirrors it.

Fonts are already loaded by the theme on every page (Google Fonts link); the plugin should not enqueue a second copy, but should declare the stacks
with sans-serif fallbacks in case the theme changes.
