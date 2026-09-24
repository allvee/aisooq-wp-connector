# AI Sooq — admin design system

An **application shell** in wp-admin's clothing: a blue accent, ring-outlined cards on white, and
one scrolling column of settings behind a sidebar.

Two stylesheets, and the split is by *what*, not by *where*:

| File | Holds |
|---|---|
| `assets/css/aisooq-admin.css` | the token block, the control base layer, and what is left of the pre-redesign component rules |
| `assets/css/aisooq-app.css` | the application shell and its components — app bar, sidebar, cards, tables, toolbars |

Both load on all four plugin screens. `design-tokens.json` and `design-preview.html` are
**generated** by `bin/make-design-tokens.php` from the token block; neither is hand-edited.

This document explains *why* each decision is what it is, so the next person changing a colour knows
what they are trading away.

> **History.** Through 2.15 this system was "X/Twitter's language, AI Sooq's navy palette": flat,
> hairline-separated, `#08294C` primary with gold as the only accent. It was replaced wholesale to
> implement the settings redesign, and the other three screens — Blocked, Failed syncs, Abandoned
> carts — adopted the result immediately after. The rules below are the current ones; where an old
> rule survived the change it says so, because "we thought about this and kept it" and "nobody
> revisited it" are different states and only one is safe to change.

---

## The four rules everything else serves

**THE RING IS THE EDGE.** Every card's outline and its elevation are the same declaration:
`--sh-sm` is `0 0 0 1px` and no blur. Removing that layer does not soften a card, it deletes its
border. Cards therefore never also carry a `border`, and the page stays visually flat while every
surface is still bounded. Blur starts at `--sh-md` and is reserved for the three things that
genuinely float above the page — the search popover, the unsaved bar, the toast.

**PILLS.** Every button is fully rounded (`--pill`); containers are 16px (`--radius`); inputs are
6px (`--radius-sm`). The 6px is the one deliberate exception to the roundness: a fully-rounded text
field reads as a search box, and most of these are not.

**ONE VOICE.** Blue carries every primary action, active state and focus ring. There is no second
accent inside the plugin's own screens — gold survives for exactly one job, the admin-menu glyph in
wp-admin's sidebar, which this design does not cover and which has to stay distinguishable from
WordPress's own blue hover.

**LIGHT, ON PURPOSE.** These pages sit inside wp-admin's light chrome. A dark panel against a light
sidebar reads as a broken embed rather than a deliberate theme. The one dark surface is the unsaved
bar, and it is dark *because* it is the exception.

---

## The shell

All four screens are the same frame, printed by `AI_Sooq_Admin_Shell`:

```
app bar          identity + connection pill  (+ Verify and sync, settings only)
├─ sidebar       the screen's own nav, then links to the other three screens
└─ main          a single column of cards, then the footer
```

**One frame, four screens, and the reason it is one.** Each screen used to draw its own header and
its own table, and two of them carried their own palette in `style=` attributes. The header is the
part that must not drift: four copies is how four connection pills start disagreeing about whether
the store is connected. `open()` / `close()` are a printed pair rather than a callback wrapper
because every one of these screens builds its body as a long procedural run of `echo`, and a
callback would have meant restructuring four classes to buy nothing.

**The sidebar is the same shape everywhere and holds different things.** On Settings it is the six
sections as a tablist. On Blocked it is the two halves of the screen. On Failed syncs it is the
cause rail — which is where the counts belong, because "30 / 5 / 3" is the finding this screen
exists to deliver. On Abandoned carts it is the status filter. Under all of them sit links to the
other three screens, and **never to itself**: a link that appears to do nothing reads as a broken
page.

**Legacy classes are kept on purpose.** The abandoned-carts wrapper still carries `aisooq-ab`
alongside `aisooq aisooq-app`, because its cart modal, row action menu and courier cell are still
drawn by rules scoped to the old class. The shell's own rules are `.wrap.aisooq.aisooq-app .x` and
outrank them wherever they overlap. Drop a legacy class only once its components have been ported —
not before.

---

## Colour

Two ramps, both ordered **dark → light**, so `-100` is the deepest ink and `-900` the palest wash.
That is the opposite of a Tailwind scale and the same as the design document this implements. Read
the number as "how much surface", not "how much ink".

### Accent

| Token | Value | | Token | Value |
|---|---|---|---|---|
| `--pri-100` | `#0b2a4a` | | `--pri-600` | `#62a9ee` |
| `--pri-200` | `#103d6b` | | `--pri-700` | `#a3cdf6` |
| `--pri-300` | `#1766b3` | | `--pri-800` | `#d6e9fc` |
| `--pri-400` | `#1f74c7` | | `--pri-900` | `#eef6fe` |
| `--pri-500` | `#2b8ae6` | | | |

### Brand aliases

| Token | Value | Role |
|---|---|---|
| `--pri` | `#2b8ae6` | the accent — dots, glyphs, rings, fills nothing prints on |
| `--pri-solid` | `#1f74c7` | **the accent when it carries white text** — see below |
| `--pri-d` | `#1f74c7` | hover |
| `--pri-dd` | `#1766b3` | active / pressed |
| `--pri-fg` | `#ffffff` | ink on the accent |
| `--pri-wash` | `rgba(43,138,230,.1)` | ghost-button hover |
| `--pri-tint` | `#eef6fe` | opaque counterpart, for pills |
| `--hl` | `#FDC137` | gold — admin-menu glyph only |
| `--hl-d` | `#E0A81F` | gold, pressed |
| `--hl-fg` | `#3d2c00` | ink on gold |

**`--pri` and `--pri-solid` are not interchangeable, and the difference is an accessibility bug
waiting to happen.** White on `--pri` is **3.57:1**. That clears the **3:1** a graphical object
needs — a status dot, an icon, a progress fill — and *fails* the **4.5:1** body text needs. The
primary button's label is 15px bold, which is **not** "large text" under WCAG (that starts at
18.66px bold / 24px regular), so a Save button filled with `--pri` ships a label below AA. Anything
that prints text on the accent uses `--pri-solid` (**4.79:1**) instead. Nothing new was invented for
this: it is the design's own hover shade promoted to the resting state for those two components.

**Gold is a surface, never ink.** `#FDC137` is **1.63:1** on white and fails as text at any size. It
is only ever a background, and it only ever carries `--hl-fg`, which is **8.25:1** on it.

### Neutrals

`--n-100` `#14171c` · `--n-200` `#2f3439` · `--n-300` `#56626e` · `--n-400` `#6c7884` ·
`--n-500` `#8b98a5` · `--n-600` `#aab5c0` · `--n-700` `#d3dbe1` · `--n-800` `#e6ebef` ·
`--n-900` `#f6f8fa`

`--fg`, `--muted`, `--line` and `--edge` are aliases into this ramp (`n-100`, `n-300`, `n-800`,
`n-700`). They are spelled as literals in the token block rather than as `var()` references, because
`design-tokens.json` and `AI_Sooq_Palette` both mirror that block and a design tool cannot resolve
an indirection.

### Measured contrast

Every text token, computed against `--bg` (`#ffffff`) unless stated. AA needs **4.5:1** for body
text and **3:1** for UI components and graphical objects.

| Pair | Ratio | Verdict |
|---|---|---|
| `--fg` `#14171c` | **17.96** | Pass |
| `--n-200` `#2f3439` — field labels | **12.57** | Pass |
| `--muted` `#56626e` | **6.24** | Pass |
| `--n-400` `#6c7884` — stat captions, meta | **4.51** | Pass, with no margin |
| `--pri-300` `#1766b3` — link / ghost-button ink | **5.85** | Pass |
| `--pri-fg` on `--pri-solid` | **4.79** | Pass |
| `--pri-fg` on `--pri` | 3.57 | **Graphical objects only — never text** |
| `--pri-200` on `--pri-800` — tags | **8.90** | Pass |
| `--pri-300` on `--pri-900` — connection pill | **5.36** | Pass |
| `--n-900` on `--n-100` — unsaved bar | **16.87** | Pass |
| `--pri-600` on `--n-100` — unsaved bar glyph | **7.21** | Pass |
| `--bg` on `--fg` — active mobile pill | **17.96** | Pass |
| `--warn` `#8a5a00` | **5.93** | Pass |
| `--ok` `#00754b` | **5.76** | Pass |
| `--err` `#c0392b` | **5.44** | Pass |
| `--info` `#1766b3` | **5.85** | Pass |
| `--hl-fg` on `--hl` | **8.25** | Pass |
| `--hl` `#FDC137` | 1.63 | **Background only — never text** |

Two entries deserve a second look before anyone edits them:

- **`--n-400` at 4.51:1** clears AA by 0.01. It is the design's colour for 12px stat captions and the
  store strip's meta. Any darkening is safe; any lightening at all breaks it. If you need a dimmer
  grey for text, there isn't one — use `--muted`.
- **`--n-500` `#8b98a5` is 2.94:1** and is therefore *not used as text anywhere*. It exists for the
  ramp's completeness. Reaching for it to dim a label is the mistake this note exists to prevent.

`--line` (1.20:1) and `--edge` (1.40:1) are below the 3:1 UI-component threshold. That is acceptable
*only* because no control is identified by those borders alone: inputs are identified by their fill
and their label, and the focus state swaps in `--pri` plus a 1px ring. If you ever ship a control
whose only affordance is an `--edge` outline, it needs a darker token.

### Contrast is measured, not assumed

The table above is computed, not eyeballed. Any colour *not* in it has to be rendered before it is
known — and the accent-fill bug above is the second time that principle has paid for itself on this
project. The first was the courier ratio bar.

The bar used to build its fill from `hsl($hue 62% 38%)`, hue tracking the percentage, and printed a
white figure on that fill whenever the bar was long enough to hold the label. Every hue in that
range failed AA — **2.88:1** at hue 60. No amount of reading the stylesheet would have caught it:
the colour does not exist until PHP computes it. It was found by rendering real orders.

**Both halves are fixed, and both live in `AI_Sooq_Order_Courier`:**

| Constant | Value | Guarantees |
|---|---|---|
| `RATIO_FILL_LIGHT` | `29` | white clears 4.5:1 at *every* hue 0–120 (worst 4.66:1 at hue 60). At 30 it drops to 4.41 — 29 is the ceiling, not a round number. |
| `RATIO_LABEL_INSIDE_MIN` | `72` | the figure only moves inside once the fill actually reaches it |

The second one is the subtler half and darkening alone would not have fixed it. The label is a
*sibling* of the fill, not a child, and `.inside` centres it on the **track** — so at the old
threshold of 32 the white figure floated past the fill's right edge onto bare `--track`, at
**1.14:1**. Invisible, not merely low. Measured in a browser at both widths the bar renders at: the
glyphs clear the fill from 58% on a 168px track, and only from 72% on the 56px minimum.

Three lessons, and the last two are the ones that keep getting missed:

1. A generated colour needs its worst case computed across the whole input range, not sampled at one
   comfortable value.
2. Contrast is a property of what is *behind the glyphs*, not of the element you think they are on.
   Check the geometry before trusting the colour maths.
3. A palette handed over from a design tool is a set of hypotheses. A mockup renders at whatever size
   its author chose; your button does not. Compute the pairs *you* ship.

### Status — one named pair each

Ink on the left, its bed on the right. A badge is one pair, never two hand-picked hexes.

| Ink | Surface | Border |
|---|---|---|
| `--ok` `#00754b` | `--ok-wash` `#e8f3ec` | `--ok-edge` |
| `--warn` `#8a5a00` | `--warn-wash` `#fdf3e0` | `--warn-edge` |
| `--err` `#c0392b` | `--err-wash` `#fcebea` | `--err-edge` |
| `--info` `#1766b3` | `--info-wash` `#eef6fe` | — |

`--info` and `--info-wash` are now the accent and its palest step, because "informational" and
"primary" are the same hue in this palette. They keep separate names so a future palette can split
them again without touching every call site.

### Surfaces

`--bg` / `--card` (`#ffffff`) → `--sunk` (`#fbfcfd`) → `--sunk-2` (`#f6f8fa`) → `--track`
(`#e6ebef`).

Ordered on purpose: `--sunk` reads as "behind", `--sunk-2` as "behind that", `--track` is the empty
half of a progress bar. Three steps is the whole ladder; a fourth would be invisible.

`--pri-tint`, `--ok-wash`, `--track`, `--sunk-2`, `--card` and `--hl` are **mirrored in
`AI_Sooq_Palette`** for the three screens that print inline `<style>` and cannot reach this sheet.
`tests/test-palette-drift.php` fails the build if the two copies disagree. Change both or neither.

---

## Type

`--text` is **14px** with `--lh` **1.45**. One step tighter than the 15px this sheet used before: a
settings form is denser than a timeline, and every size here comes from the design document.

| Token | Size | Use |
|---|---|---|
| `--text-xs` | 11px | uppercase column heads |
| `--text-sm` | 13px | labels, dense table cells |
| `--text` | **14px** | body |
| `--text-lg` | 16px | nav items, store name |
| `--text-xl` | 22px | panel titles |
| `--text-2xl` | 24px | stat numerals |

Off-scale literals still in use, all from the design and all deliberate: 12px (stat captions,
footer), 13.5px (sidebar links, guide steps), 10.5px (scope tags), 18px (the wordmark), 15px (the
Save label). Each is a one-off in a single component rather than a competing scale.

---

## Space

A **4px** base: `--sp-1` 4 · `--sp-2` 8 · `--sp-3` 12 · `--sp-4` 16 · `--sp-5` 20 · `--sp-6` 24 ·
`--sp-8` 32.

`--sp-8` is the shell's own step: the app bar's horizontal padding, the body gutter, and the gap
between sidebar and main column are all one value, which is what keeps the three vertical edges of
the page aligned.

The sheet previously used **57 distinct px values**, including nearly every integer from 10 to 16.
That is freehand, not rhythm. Reach for a step; add a literal only when a real optical correction
demands it, and write down why.

## Radius

`--radius-sm` 6px (inputs) · `--radius-md` 10px (menus, popovers, nested rows) ·
`--radius` 16px (cards and modules) · `--pill` 9999px (every button).

Inputs are the one thing that is not a pill, deliberately: a fully-rounded text field reads as a
search box, and most of these are not.

## Elevation

| Token | Value | Use |
|---|---|---|
| `--sh-sm` | `0 0 0 1px --n-800` | every card — **a ring, no blur** |
| `--sh-md` | ring + `0 8px 24px` | the unsaved bar |
| `--sh-lg` | ring + `0 12px 32px` | search popover, toast |
| `--sh-elev` | = `--sh-lg` | legacy name, still used by the other two screens' menus and modals |

The first layer of all four is a 1px ring rather than a blur, because it draws the card's edge in
the same declaration that lifts it — which is why cards carry no `border`. Deleting that layer does
not soften a card, it removes its outline.

Blur means "this is temporary". If you are reaching for `--sh-md` or above on something that sits in
the page rather than floating over it, the answer is `--sh-sm`.

## Breakpoints

Four, and only four. They cannot be custom properties inside a media query, so they are fixed by
convention:

| Width | Why |
|---|---|
| **960px** | the settings shell drops its sidebar and switches to the pill nav |
| **782px** | wp-admin's own admin-bar / table breakpoint. Non-negotiable — matching it is what keeps the plugin from fighting the platform. |
| **600px** | one column |
| **400px** | smallest phone |

**960px is not the design's figure and that is on purpose.** The design collapses at 720px, which is
the width of the *page*; this page never gets the viewport. wp-admin keeps a 160px menu to its left
(36px once it folds at 782px) plus 20px of body padding, so a 960px viewport is roughly the 780px of
content at which a 220px sidebar stops leaving the main column enough room for two fields abreast.
Collapsing on the design's literal number would strand the sidebar in a column too narrow to use for
the whole 720–940 range.

**Known drift:** 480px and 560px are still in the sheet, on the abandoned-carts and blocked screens.
Like the type literals, moving them changes layout at specific widths and should be done with those
screens visible.

---

## Motion

Restrained by design. If a change needs to be *noticed*, colour does that job better than movement.

The settings shell adds exactly one keyframe animation, `aisooq-found`, which flashes the field that
search just jumped to. It earns its place: a panel can be short enough that scrolling to a field is a
no-op, and without the flash the jump looks like nothing happened.

`prefers-reduced-motion` is honoured, and each stylesheet answers it for its own rules.

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

**A component library — mostly.** Buttons *were* WordPress's own `.button` / `.button-primary`
throughout, restyled rather than replaced, on the principle that inheriting the host's controls
brings its keyboard behaviour, ARIA and future core changes along for free. That principle still
holds and is still the default.

The redesign added exactly one exception, `.aisooq-btn`, in three variants. It exists because the
design's buttons are pill-shaped, carry a leading glyph and come in a ghost weight WordPress has no
equivalent for, and because `.button` had accumulated enough overrides to be a reimplementation
wearing WordPress's class name. It is a plain `<button>` with a class — no JavaScript, no ARIA of
its own, nothing that WordPress was doing for us. One exception, deliberately; anything beyond
buttons should still be WordPress's.

**Freedom from inline styles.** There are still **19 `style="` attributes across 7 PHP files**,
down from 26 — the redesign removed most of the ones on the three list screens and added a handful
of dynamic widths (progress fills, funnel bars) that genuinely cannot live in a stylesheet because
their value is computed per render. Those are the acceptable kind. The rest bypass this file
entirely and will not respond to any token change made here.

The attributes are the smaller half. **Three PHP files carry entire inline `<style>` blocks with
their own palette** — `class-aisooq-order-courier.php`, `class-aisooq-orders-column.php` and
`class-aisooq-settings.php`. That is not carelessness. `enqueue_admin_assets()` loads the
stylesheets only when the admin hook contains `aisooq`, and the courier meta box and the orders-list
column render on **WooCommerce's** screens — so those two never receive them, and their colour has
nowhere else to live. `AI_Sooq_Palette` is the answer to that: the values live once in PHP and each
block emits the subset it needs, with `tests/test-palette-drift.php` holding the two copies
together.

Read the consequence plainly: **"zero raw hex" is true of the stylesheet, not of the plugin.** The
token block is the single source of truth for the screens that enqueue it and for nothing else. A
`var(--pri)` written into one of those inline blocks resolves to nothing and the colour simply
disappears; migrating them means redefining the tokens inside the same block, or making the screens
enqueue the sheet first. Either way it is the highest-value remaining cleanup.

---

## Dead rules

Largely settled. When the three list screens adopted the shell they stopped emitting the markup
behind roughly 250 lines of `.wrap.aisooq-ab` / `.wrap.aisooq-bl` rules — the old heroes, KPI
strips, filter bars and bulk bars — and those were deleted with the screens visible, which is the
only safe way to do it.

What remains is a handful of selectors that name a dead class *inside a rule whose other selectors
are live* (`.wrap.aisooq-ab .aisooq-filters a` sitting in a focus-ring list, for instance). Those
are harmless and were left rather than risk splitting a rule that four screens depend on.

The method, for next time: grep the PHP for each `.aisooq-*` class in a `class=` attribute, delete
only rules where **every** selector is dead, then look at all four screens before committing.
Nothing here is harmful on its own — the cost is that someone reading the sheet to learn what the
plugin looks like will infer screens that are not there.

---

## Changing something

1. If it is a colour, it goes in the token block or it does not go in. There are currently **zero**
   raw hex values below the token block *in this stylesheet*; keep it that way. The three inline
   `<style>` blocks in PHP are a separate, unmigrated palette — see above.
2. If it is a size, use a scale step.
3. If you need a literal, leave a comment saying what optical problem forced it.
4. Regenerate `design-tokens.json` after touching the token block:
   `php bin/make-design-tokens.php` (or `--check` to see whether it is stale).
   Until 2.16 this step named a file nobody could actually regenerate — it was hand-maintained, and
   `tests/test-palette-drift.php` failed the moment the sheet moved without it.
5. If you changed a mirrored token, change `AI_Sooq_Palette::TOKENS` in the same commit.
6. Open `design-preview.html` to see every token and component at once.
7. If you changed a colour that carries text, **compute the pair** and update the contrast table.
   `--pri` vs `--pri-solid` exists because that step was skipped once.
