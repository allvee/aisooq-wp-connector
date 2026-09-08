# AI Sooq — admin design system

The visual language is **X/Twitter's**. The palette is **AI Sooq's**.

Source of truth is `assets/css/aisooq-admin.css`. `design-tokens.json` is generated from its token
block; `design-preview.html` renders every token and component on one page with no build step.

This document explains *why* each decision is what it is, so the next person changing a colour knows
what they are trading away.

---

## The four rules everything else serves

**FLAT.** No drop shadow on anything that sits *in* the page. X separates content with a 1px
hairline and nothing else. Elevation is reserved for things that float *above* the page — menus,
modals — where it carries meaning: "this is temporary." One shadow token exists (`--sh-elev`) and it
is only for those.

**HAIRLINES.** `--line` (`#eff3f4`) is the only divider. It is deliberately lighter than a WordPress
border: structure should be *felt* rather than drawn.

**PILLS.** Every button is fully rounded (`--pill`). Containers are 16px (`--radius`), matching X's
own sidebar modules.

**ONE VOICE.** Navy carries the role X gives its blue — every primary action, active state and focus
ring. Gold is the single accent, used the way X uses nothing else: to say "you are here" and to mark
unsaved work.

The surface stays light on purpose. These pages sit inside wp-admin's light chrome, and a dark panel
against a light sidebar reads as a broken embed rather than a deliberate theme.

---

## Colour

### Brand

| Token | Value | Role |
|---|---|---|
| `--pri` | `#08294C` | AI Sooq navy — plays X-blue's role |
| `--pri-d` | `#04162B` | hover / active |
| `--pri-fg` | `#ffffff` | ink on navy |
| `--pri-wash` | `rgba(8,41,76,.1)` | ghost-button hover |
| `--pri-tint` | `#e9f0f8` | opaque counterpart, for pills |
| `--hl` | `#FDC137` | gold — the only accent |
| `--hl-d` | `#E0A81F` | gold, pressed |
| `--hl-fg` | `#3d2c00` | ink on gold |

**Gold is a surface, never ink.** `#FDC137` is **1.63:1** on white and fails as text at any size.
It is only ever a background, and it only ever carries `--hl-fg`, which is **8.25:1** on it. This is
the single most load-bearing rule in the palette, and the reason `--hl-fg` exists as a named token
rather than a literal.

### Measured contrast

Every text token, computed against `--bg` (`#ffffff`). AA needs 4.5:1 for body text, 3:1 for UI
boundaries.

| Token | Ratio | Verdict |
|---|---|---|
| `--fg` `#0f1419` | **18.51** | Pass |
| `--pri` `#08294C` | **14.67** | Pass |
| `--pri-fg` on `--pri` | **14.67** | Pass |
| `--hl-fg` on `--hl` | **8.25** | Pass |
| `--muted` `#536471` | **6.12** | Pass |
| `--warn` `#8a5a00` | **5.93** | Pass |
| `--ok` `#00754b` | **5.76** | Pass |
| `--info` `#1f6f9c` | **5.51** | Pass |
| `--err` `#c0392b` | **5.44** | Pass |
| `--hl` `#FDC137` | 1.63 | **Background only — never text** |

`--line` (1.12:1) and `--edge` (1.44:1) are below the 3:1 UI-component threshold. That is acceptable
*only* because no control is identified by those borders alone: inputs are identified by their
recessed fill, and the focus state swaps in a navy boundary at 14.67:1. If you ever ship a control
whose only affordance is an `--edge` outline, it needs a darker token.

### Contrast is measured, not assumed

The table above is computed. Any colour *not* in it has to be rendered before it is known.

The courier ratio bar used to build its fill from `hsl($hue 62% 38%)`, hue tracking the percentage,
and printed a white figure on that fill whenever the bar was long enough to hold the label. Every hue
in that range failed AA — **2.88:1** at hue 60. No amount of reading the stylesheet would have caught
it: the colour does not exist until PHP computes it. It was found by rendering real orders.

**Both halves are now fixed, and both live in `AI_Sooq_Order_Courier`:**

| Constant | Value | Guarantees |
|---|---|---|
| `RATIO_FILL_LIGHT` | `29` | white clears 4.5:1 at *every* hue 0–120 (worst 4.66:1 at hue 60). At 30 it drops to 4.41 — 29 is the ceiling, not a round number. |
| `RATIO_LABEL_INSIDE_MIN` | `72` | the figure only moves inside once the fill actually reaches it |

The second one is the subtler half and darkening alone would not have fixed it. The label is a
*sibling* of the fill, not a child, and `.inside` centres it on the **track** — so at the old
threshold of 32 the white figure floated past the fill's right edge onto bare `--track`, at
**1.14:1**. Invisible, not merely low. Measured in a browser at both widths the bar renders at: the
glyphs clear the fill from 58% on a 168px track, and only from 72% on the 56px minimum.

Two lessons, and the second is the one that nearly got missed:

1. A generated colour needs its worst case computed across the whole input range, not sampled at one
   comfortable value.
2. Contrast is a property of what is *behind the glyphs*, not of the element you think they are on.
   Check the geometry before trusting the colour maths.

### Status — one named pair each

Ink on the left, its bed on the right. A badge is one pair, never two hand-picked hexes.

| Ink | Surface | Border |
|---|---|---|
| `--ok` `#00754b` | `--ok-wash` `#e8f3ec` | `--ok-edge` |
| `--warn` `#8a5a00` | `--warn-wash` `#fdf3e0` | `--warn-edge` |
| `--err` `#c0392b` | `--err-wash` `#fcebea` | `--err-edge` |
| `--info` `#1f6f9c` | `--info-wash` `#f0f6fc` | — |

Before this existed, the *same* badge had three different pale greens across three screens
(`#edfaef`, `#e3f2ec`, `#e8f3ec`), two pale blues, and two warm tints one shade apart. Nobody could
have told them apart deliberately — they were drift, not design.

### Surfaces

`--bg` / `--card` (`#ffffff`) → `--sunk` (`#fbfbfc`) → `--sunk-2` (`#f6f7f7`) → `--track` (`#f0f0f1`).

Ordered on purpose: `--sunk` reads as "behind", `--sunk-2` as "behind that", `--track` is the empty
half of a progress bar. Three steps is the whole ladder; a fourth would be invisible.

---

## Type

`--text` is **15px** with `--lh` **1.35** — X's exact base metric, and tighter than wp-admin's
default, which is what makes a long settings form readable.

| Token | Size | Use |
|---|---|---|
| `--text-xs` | 11px | uppercase table heads, meta |
| `--text-sm` | 13px | dense table cells |
| `--text` | **15px** | body |
| `--text-lg` | 17px | card and section headings |
| `--text-xl` | 20px | page and panel titles |
| `--text-2xl` | 26px | KPI numerals |

**Known drift:** 34 declarations still use literals — 12px (×12), 10px (×5), 14px (×5), 16px (×5),
plus 18/22/24/28px. They were left as literals deliberately: collapsing them onto the scale changes
rendered layout, and that should be done with the screens visible, not blind. The 56 declarations
that *exactly* matched a scale step were converted, which is a zero-risk change.

Note the direction of the drift. Before this pass the sheet used **fourteen** sizes and the most
common was **13px** — wp-admin's default, not X's 15px. The file's own header committed to
"15px/20px base"; the shipped stylesheet did not honour it.

---

## Space

A **4px** base: `--sp-1` 4 · `--sp-2` 8 · `--sp-3` 12 · `--sp-4` 16 · `--sp-5` 20 · `--sp-6` 24.

The sheet previously used **57 distinct px values**, including nearly every integer from 10 to 16.
That is freehand, not rhythm. Reach for a step; add a literal only when a real optical correction
demands it, and write down why.

## Radius

`--radius-sm` 4px (inputs) · `--radius-md` 8px (thumbnails, nested tables, menus) ·
`--radius` 16px (modules) · `--pill` 9999px (every button).

## Elevation

One token, `--sh-elev`, and only for things that float. If you are reaching for it on something that
sits in the page, the answer is a hairline.

## Breakpoints

Three, and only three. They cannot be custom properties inside a media query, so they are fixed by
convention:

| Width | Why |
|---|---|
| **782px** | wp-admin's own admin-bar / table breakpoint. Non-negotiable — matching it is what keeps the plugin from fighting the platform. |
| **600px** | one column |
| **400px** | smallest phone |

**Known drift:** 480px, 560px and 860px are still in the sheet. Like the type literals, moving them
changes layout at specific widths and should be done with the screens visible.

---

## Motion

Restrained by design: 9 transitions, 0 keyframes, 1 animation across the whole sheet. If a change
needs to be *noticed*, colour does that job better than movement.

`prefers-reduced-motion` is honoured. It is currently answered in **three separate blocks**, which is
a fingerprint of the sheet having been assembled from per-screen fragments rather than authored as
one. Harmless, but it should collapse to one.

## Focus

`:focus-visible` gets a **2px navy outline at 1px offset** — 14.67:1, unmissable.

Three places set `outline: 0`, and all three are legitimate:

- the search input, because its *wrapper* takes the focus ring via `:focus-within` (background to
  white, border to navy) — X's own pattern;
- the tab panel, which receives focus programmatically rather than by keyboard traversal.

If you add a fourth, it needs a compensating indicator or it is simply a bug.

## Touch

Coarse-pointer targets are **44px**. The `pointer:coarse` branch exists *because* the pointer is a
finger; a 40px target inside it undoes the reason for branching. WCAG 2.2 AA (2.5.8) only demands
24px, so 44 is the comfort target, not the compliance one.

---

## What this system does not have

**Dark mode.** Zero support, deliberately deferred rather than half-built. WordPress admin has no
first-party dark mode, and a half-done one is worse than none. If it is ever added, the token block
is the only place that needs to change — which is most of the point of having one.

**A component library.** Buttons are WordPress's own `.button` / `.button-primary` (37 CSS
references, 27 in PHP), restyled rather than replaced. That is correct for a plugin: inherit the
host's controls so keyboard behaviour, ARIA and future core changes come along for free. The count of
custom button classes is **zero**: `.aisooq-btn-primary` is styled in this sheet but emitted by no
PHP, so every button the plugin actually renders is WordPress's. Keep it at zero.

**Freedom from inline styles.** There are still **26 `style="` attributes across 6 PHP files**
(`class-aisooq-abandoned-admin.php`, `class-aisooq-blocklist-admin.php`, `class-aisooq-failed-admin.php`,
`class-aisooq-order-courier.php`, `class-aisooq-settings.php`, `class-aisooq-products-column.php`).
They bypass this file entirely and will not respond to any token change made here.

The attributes are the smaller half. **Three PHP files carry entire inline `<style>` blocks with
their own hardcoded palette** — `class-aisooq-order-courier.php` (37 distinct hex),
`class-aisooq-orders-column.php` (5) and `class-aisooq-settings.php` (2): 44 between them, 38 once
duplicates across files are removed. That is not carelessness. `enqueue_admin_assets()` loads
`aisooq-admin.css` only when the admin hook contains `aisooq`, and the courier meta box and the
orders-list column render on **WooCommerce's** screens — so those two never receive the stylesheet,
and their colour has nowhere else to live.

Read the consequence plainly: **"zero raw hex" is true of the stylesheet, not of the plugin.** The
token block is the single source of truth for the screens that enqueue it and for nothing else. A
`var(--pri)` written into one of those inline blocks resolves to nothing and the colour simply
disappears; migrating them means redefining the tokens inside the same block, or making the screens
enqueue the sheet first. Either way it is the highest-value remaining cleanup.

---

## Dead rules

10 of the 127 styled `.aisooq-*` classes are emitted by no PHP at all:

`.aisooq-btn-primary` · `.aisooq-funnel` · `.aisooq-funnel__bar` · `.aisooq-funnel__row` ·
`.aisooq-funnel__track` · `.aisooq-kpi__value` · `.aisooq-modal__box` · `.aisooq-nowrap` ·
`.aisooq-panel__body` · `.aisooq-tabnav`

The four `.aisooq-funnel*` rules are the clearest case: the recovery funnel now renders as
"Stopped at …" KPI cards, so those rules style a component that no longer exists. Nothing here is
harmful, only misleading: someone reading the sheet to learn what the plugin looks like will infer
screens that are not there. Delete them with the screens visible, not blind.

---

## Changing something

1. If it is a colour, it goes in the token block or it does not go in. There are currently **zero**
   raw hex values below the token block *in this stylesheet*; keep it that way. The three inline
   `<style>` blocks in PHP are a separate, unmigrated palette — see above.
2. If it is a size, use a scale step.
3. If you need a literal, leave a comment saying what optical problem forced it.
4. Regenerate `design-tokens.json` after touching the token block.
5. Open `design-preview.html` to see every token and component at once.
