=== AI Sooq Connector ===
Contributors: aisooq
Tags: woocommerce, orders, analytics, sync, abandoned cart
Requires at least: 5.8
Tested up to: 6.7
Requires PHP: 7.4
WC requires at least: 6.0
WC tested up to: 9.9
Stable tag: 2.6.3
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Mirror WooCommerce orders, incomplete/abandoned carts and analytics into your AI Sooq store and manage them from the platform.

== Description ==

AI Sooq Connector links any WooCommerce store to a single AI Sooq
store using an OAuth app credential. It pushes:

* **Orders** — every order (paid and unpaid/incomplete) is mirrored as a native
  order on the platform, idempotently. Re-delivery updates, never duplicates.
* **Abandoned carts** — carts idle past a configurable threshold are pushed so
  they land in the platform's recovery inbox.
* **Analytics** — WooCommerce events (PageView, ViewContent, AddToCart,
  InitiateCheckout, Purchase, CompleteRegistration) are forwarded to the
  platform, which fans them out to Meta CAPI / TikTok / GA4.
* **Fraud screening** — an optional 4-layer check (phone/name/address, IP
  velocity, courier delivery history) runs at checkout and can block, hold, or
  flag risky orders. Fails open on API errors.

Order pushes run through Action Scheduler with automatic retry, so a slow API
call never blocks checkout. An optional sync-back poll can reconcile
WooCommerce order status from the platform.

== Installation ==

1. Upload and activate the plugin (WooCommerce must be active).
2. Open **AI Sooq** in the admin menu.
3. Enter the Platform API base URL (host only), Store SID, OAuth Client ID and
   Client Secret. Register the OAuth app on the platform with scopes for the
   features you enable (orders, customers, products, brands, categories,
   collections) — orders-only is not enough once product/customer/catalog sync
   is turned on. See SETUP.md for the full list.
4. Choose what to sync and click **Test connection**.

== Frequently Asked Questions ==

= Do I need to map WooCommerce products to platform products? =

No. Order lines are pushed as free-text (title/sku/price), so no mapping is
required and ingestion never affects platform inventory.

= Can one plugin connect to multiple AI Sooq stores? =

No — one WooCommerce site connects to one AI Sooq store (one OAuth app = one
store). Run separate sites for separate stores.

== Changelog ==

= 2.6.3 =
* **The delivery bar is shorter on a phone.** It was stretching to fill whatever width it was given — most of an order panel — for a figure that is readable at a third of that. A bar only has to be long enough to compare at a glance; past that it is width taken from everything around it.
* **The abandoned-cart list is a proper card on a phone.** Nine label-and-value rows made a card you had to scroll to read one cart, with every value pinned to the far right and a column of dead space down the middle. The same information now reads as a card: who it is on top, the courier evidence under it, then the short facts two-up with each label above its value, and the actions closing the card next to when it was last updated. About a fifth shorter and far quicker to scan.
* Nothing was dropped to get there — every column, control, label and bulk-select is still present, just placed. Selecting a cart is now a full-size tap target instead of a 22px checkbox.

= 2.6.2 =
* **The AI Sooq column is icons now, matching the Courier column beside it.** A cloud-upload to push an order, a green tick once it is through, and circular arrows to push it again — at the same size as the courier controls, so two neighbouring columns stop reading as two different kinds of thing. Between them they were taking about 170px of a crowded table.
* Nothing is lost with the text: every word survives in the tooltip and for screen readers, and the tooltip is still where the platform order id and the sync time live. The arrows turn while a push is in flight, so a slow sync looks like work rather than a dead button, and a failed push puts the control back exactly as it was instead of leaving the row claiming a state the platform never reached.
* Resync still sits beside the tick, so a corrected address or an edited line can be pushed again without waiting for a status change.

= 2.6.1 =
* **The Courier column is laid out to be read down a list, and takes less width doing it.** The figures sit on one line separated by colons, the bar goes underneath them, and the two controls sit under that — so the eye runs figures → quantity → action instead of hunting across a wrapped row.
* **The bar now shows the split, not just the headline.** Delivered runs from the left, returned continues straight on in a contrasting tone, and the track shows through for anything still in transit. A 70% with a long returned band is a different parcel from a 70% with three still moving, and that was invisible before.
* Both tones are deliberately muted rather than pure red and green — this column is read all day, and saturated pairs are tiring side by side. The percentage stays on the bar and the figures stay in labelled pills, so nothing depends on colour alone.
* **Recheck is an icon now**, paired with the eye. As text it was the widest thing in the column while being the least-used control in it. Both keep their names for screen readers, and both are 40px targets on touch.
* The same layout is now used on the abandoned-cart worklist, so a delivery ratio does not read as two different measurements on two screens.

= 2.6.0 =
* **The Courier column now leads with the numbers.** Every order row shows parcels sent, delivered and returned, followed by how many orders that customer has placed at *your* shop before — then the success bar underneath. The bar alone could not say how much evidence was behind it: 100% over one parcel and 100% over forty look identical and are a completely different decision.
* A customer with no previous order here is marked **first** rather than shown a zero, because "0 orders" reads as a data problem and "first" reads as a fact.
* **The eye opens the breakdown with each courier's logo**, so a row reads like the courier's own dashboard instead of a list of names. Steadfast, Pathao, RedX, Paperfly, eCourier, Sundarban and CarryBee ship with artwork; anything else — BDCourier adds couriers without notice — gets a coloured initial tile rather than a broken image.
* **Built for a phone.** On the stacked orders list the figures get larger, the eye becomes a 40px tap target, and the breakdown table scrolls on its own instead of stretching the row. The popup is a bottom sheet under 600px.
* No extra BDCourier calls: every figure comes from the answer already stored on the order. Only **Recheck** spends money, as before.
* **A courier check no longer comes back empty when BDCourier is down.** The platform now falls back to Steadfast's own fraud check, and failing that to your shop's own delivery outcomes for that number. Both are narrower than the national picture, so the column says which one you are looking at — a 100% built from three of your own parcels is not the same evidence as 100% across every courier in the country.

= 2.5.0 =
* **The orders-list courier cell is the bar and the actions, nothing else.** "25 parcels" and "Checked 3 hours ago" is detail nobody reads while scanning rows, and that column competes with a dozen others for width — the text only squeezed the bar carrying the meaning. Both still show in the order's own panel, where there is room.
* **An eye button opens the full picture**: the per-courier breakdown, the parcel count, and when it was checked. It reads what is already stored and never looks anything up — every BDCourier call is billed, and opening a panel to re-read a number you have already paid for must not buy it again. **Recheck** stays the one explicit, paid action.
* **The panel answers what a national ratio cannot: has this customer bought from *you* before.** A 55% national ratio reads very differently when they have kept four of your parcels and refused none. Kept and cancelled counts are coloured apart, as are the delivered/returned columns in the courier table — two adjacent integers need a cue about which way is good.

= 2.4.0 =
* **A synced order is no longer a dead end.** The cell used to show a badge and nothing else, so after correcting an address or editing a line the only way to push the fix was to wait for a status change to trigger a re-push. The button now survives a successful sync and becomes **Resync**. The server always force-synced — this only surfaces what the endpoint already did.
* **The delivery ratio is a bar with the figure on it**, not a coloured pill. It is read in a hurry, one row at a time, to answer "do I trust this COD order?" — and 72% beside 68% is not comparable at a glance, where two bar lengths are. Colour runs continuously red to green rather than in three steps, because the old banding put 79% and 61% in the same bucket while 80% and 79% looked unrelated. Below about a third the figure moves outside the fill, since a low ratio is precisely the one that must stay readable. Screen readers get the number, not a coloured rectangle.
* **Orders now carry their real creation time.** The payload had no date, so the platform stamped the moment the push arrived — a backfill of past orders landed on the hour the plugin was installed, collapsing every sales report, cohort and date filter onto one afternoon. One store had 69 orders stamped inside the same minute. The time is converted to UTC rather than relabelled, so a Dhaka shop's 18:06 reads back as 18:06 Dhaka.
* Touch: the click handler uses `closest()`, because on a phone the press usually lands on a node inside the button and the old check dropped it silently. Controls wrap instead of overflowing and get a real tap target on any coarse pointer.
* `readme.txt`'s stable tag had drifted to 2.2.0 while the plugin was 2.3.0; all three version spots agree again.

= 2.3.0 =
* **The abandoned-cart drop-off panel is readable.** It rendered raw step names sorted by count, so the rows came out "Address 4, Payment 4, Contact 4, Review 3" — which is nobody's journey through a checkout. A funnel out of order is not a funnel. Steps now run in checkout order (contact → address → payment → review), each labelled with what the shopper had actually done, counted, and given as a share of open carts, with the largest group highlighted because that is where a recovery message is worth most.
* It is a compact card row now rather than a tall panel: this screen's working surface is the list of carts, and context does not get to push the work below the fold. Three carts are visible on first paint where none were.
* An unrecognised checkout step keeps its place at the end instead of being dropped, so a future step cannot silently vanish from the count.
* The store profile is exposed to the platform.

= 2.2.0 =
* **Courier history is checked automatically when an order comes in.** Turn on "Look up courier history automatically on every new order" (AI Sooq → Settings → Fraud & courier) and every order with a phone number gets its BDCourier delivery history looked up in the background, with the result in a new **Courier** column on WooCommerce → Orders and a **Courier history** box on the order screen — the full per-courier breakdown, not just a percentage. Nobody has to press a button per order to know whether to dispatch or call first.
* Off by default, because each lookup is billed to your store. The setting says so where you switch it on.
* Checked once per number and kept until you press **Recheck**. A "no history" answer is stored too, so an unknown number is not re-queried on every screen load. Edit an order's billing phone and the old answer is discarded rather than shown against the new number.
* The lookup runs on the background queue, so it never sits between a shopper pressing "place order" and seeing their confirmation.
* **The settings screen is now organised into sections.** Connection, Sync, Fraud & courier, Checkout messages, Shipping and Advanced, instead of one long form — with a "Find a setting" box that searches every section at once, a Save button that follows you down the page, and a warning if you try to leave with unsaved changes. Settings that only apply when another switch is on are dimmed and say which one. Nothing moved out of the plugin; everything is where it was, grouped by the job it belongs to.

= 2.1.0 =
* **Courier ratio checks are saved.** A check on the Abandoned carts screen now stays on the cart until you press Recheck. Previously the result only lived on screen: reloading the page — or even typing in the search box, which redraws the table — reset every checked row back to "Check ratio", and checking again spent another paid BDCourier lookup. The result now survives reloads, filters and searches, and shows when it was last checked.
* **The full courier breakdown is shown, not just the percentage.** Expand "Breakdown" on a checked cart to see every courier the number has history with — parcels sent, delivered, returned, and the per-courier success rate, with an all-couriers total. A bare "62%" doesn't tell you what to do; seeing that the failures sit with one courier does. This is the same breakdown the AI Sooq admin has always shown.
* A "no history" answer is recorded too, so a number BDCourier has never seen is not re-queried — and re-charged — on every visit to the worklist.
* A ratio checked while the shopper was still typing their number is discarded once the completed number lands, instead of being shown against it.
* Needs the platform-side update that returns the per-courier breakdown. Against an older platform the plugin still saves and shows the headline ratio and parcel count.

= 2.0.0 =
* **Renamed to AI Sooq Connector.** The plugin is now branded for the AI Sooq platform throughout — plugin name, admin screens, logos and documentation. This is a full rename: the plugin folder, its classes, constants, option keys, order meta, database table, cron hooks and asset files all move from the `shopify-pulse` / `sp_` namespace to `aisooq`.
* **Your data comes with you.** On first load after the update the plugin migrates settings, connection status, synced-order meta and the abandoned-cart table from the old names automatically. Installs still on the original "Wafi Commerce Connector" naming are migrated too. Nothing needs to be re-entered and no synced order loses its link to the platform.
* **Upgrade note — order matters.** Because the folder name changed, WordPress sees this as a new plugin rather than an update to the old one. Do it in this order:
  1. Install and **activate** AI Sooq Connector 2.0.0. The migration runs on activation.
  2. *Then* delete the old "Shopify Pulse Connector" entry from the Plugins screen.
  Deleting the old plugin first runs its uninstall routine, which drops the abandoned-cart table and removes the stored connection settings — you would lose every captured cart and have to re-enter your Store ID, Client ID and Client secret. (Synced orders keep their platform link either way; order meta is deliberately left alone on uninstall.) Done in the right order, nothing is lost.
* Visitor attribution survives the rename: the tracker reads its previous cookies and carries first-touch, last-touch and visit count forward, so returning visitors are not counted as brand-new traffic.
* The `shopify_pulse_order_payload` filter still fires for sites that hooked it, alongside the new `aisooq_order_payload`. The old name is deprecated and will be removed in 3.0.

= 1.9.0 =
* Abandoned carts now carry their campaign/social attribution to the platform. When a cart is captured, its first-touch source — utm_source/medium/campaign/term/content, referrer, landing path and traffic source — is snapshotted and pushed with the cart, so a recovered cart shows which channel/campaign it came from (previously only completed orders carried attribution). Existing installs get the new columns automatically on update.
* TikTok click id (ttclid) is now captured alongside fbclid (Meta) and gclid (Google) in the front-end tracker and carried on orders, so TikTok conversions keep their click id.

= 1.8.2 =
* Fix (mobile + desktop): the active status-filter pill on the Abandoned carts screen showed no label (teal text on the teal pill) after the redesign — its white label is now restored. Verified the full mobile layout on a phone-width viewport: KPI tiles reflow to two columns, filters stack, and each cart row becomes a clean labelled card with a tidy Actions menu.

= 1.8.1 =
* Redesign polish (verified in a live WordPress admin): links (Verify connection, per-entity Sync, Apply/Clear) and checkboxes/radios now use the teal brand accent instead of WordPress blue, so both screens read as one cohesive palette end to end.

= 1.8.0 =
* Redesigned admin UI to match the platform ("Bazaar Console"): both the Settings and Abandoned carts screens now share one design system — petrol-teal primary + marigold highlights on a warm off-white surface, softly elevated 12px cards, pill-shaped status badges, tabular figures in the KPIs and table, teal primary buttons, and clear teal focus rings on every control. The look is centralised in one stylesheet (assets/css/aisooq-admin.css) instead of being duplicated per page.
* New menu icon treatment: the AI Sooq admin-menu icon is white at rest and turns marigold when the menu is open/active, matching the platform accent.

= 1.7.7 =
* Fraud layers now block in the intended order at checkout. The courier delivery-ratio gate used to run before the basic checks; now the checkout is screened in ascending order and stops at the first failure — Layer 1 (name / address / mobile) → Layer 2 (IP rate limit) → Layer 3 (courier ratio) — so the shopper always sees the lowest-numbered reason first in the popup. A fake name/address is caught before the courier check ever runs.

= 1.7.6 =
* Live inline validation at checkout. With fraud protection on, the classic checkout now flags a fake/gibberish name, a junk delivery address or a malformed phone number right under the field as the shopper fills the form — before they press Place order — instead of only rejecting on submit. It uses the same platform heuristics as the checkout block (no duplicated rules) and never records an attempt, so typing can't trip the IP limit. Purely advisory: if the check can't run, checkout is unaffected (the order is still screened server-side at placement). Needs the platform build with the /fraud/preview endpoint.

= 1.7.5 =
* Fraud protection is now configurable from the plugin — and actually screens. The Settings page gains a Fraud Protection panel that turns the platform's multi-layer engine on and tunes it without leaving WordPress: Layer 1 basic validation (block fake/gibberish names + addresses, choose the phone-number check mode), Layer 2 IP rate-limiting (auto-block after N blocked attempts per IP within a window), and Layer 3 the existing BDCourier success-ratio gate. The single "fraud screening" switch now arms both the plugin's checkout call and the platform engine, so a fresh install can enable protection with one click (previously the platform master switch could only be set on the platform admin, so enabling it in the plugin alone did nothing). Ships opt-in.
* Stronger fake-name detection: a keyboard-mash word inside a multi-word name (e.g. "qwertgh dsfgnm") is now caught, not just single-word gibberish.
* Blocked-checkout popup can now show a Messenger button alongside Call / WhatsApp — set your m.me link under Settings → Support contact.

= 1.7.4 =
* Recovered carts now close on the platform automatically. When a shopper abandons the checkout (the cart is captured to the platform) and then comes back and completes the order, that order now carries the cart's fingerprint, so the platform marks the matching abandoned cart "recovered" and links it to the order — it drops out of the recovery inbox and no recovery reminder is sent for a purchase already made. Older behaviour only cleared the cart locally. Needs the platform build that accepts the order's cart fingerprint (falls back to phone/email matching otherwise).

= 1.7.3 =
* Blocked-checkout popup messages are now fully editable and Bangla by default. Under Settings → Checkout messages you can set the exact text a shopper sees for each block reason — courier delivery gate, unverified contact details, too-many-attempts, a generic fallback, and the "need help?" line above the Call / WhatsApp buttons. The courier message supports {ratio} and {parcels} tokens. Leave a box blank to keep the built-in default. Ships with sensible Bangla defaults for BD/COD stores; write English or both if you prefer.

= 1.7.2 =
* Critical fix: the plugin can no longer block a WooCommerce checkout. Every hook that runs while placing an order (fraud/courier screen, abandoned-cart capture + convert bookkeeping, attribution, order-sync scheduling) is now wrapped so any unexpected error is logged and the order still goes through. If you saw "There was an error processing your order" after installing, update to this version. Check WooCommerce → Status → Logs (source: aisooq) for the underlying cause.

= 1.7.1 =
* Fix: the connected store's name + permissions now appear automatically on the settings page (back-filled from the platform once), instead of only after a manual "Verify connection" — so a connection made before this info existed shows it without re-verifying.

= 1.7.0 =
* Blocked-checkout popup: when fraud screening or the courier gate stops an order, the shopper now sees a modern, clear popup explaining why — with Call / WhatsApp buttons so a genuine buyer can still reach you. Set the number under Settings → fraud (or it falls back to the connected store's contact).
* Verify connection now shows the connected store's name + profile (contact, currency, domain) and lists the OAuth permissions the plugin was granted, grouped by area with read/write badges, so you can see exactly what access it has.

= 1.6.1 =
* UX/accessibility pass (data-dense dashboard guidelines): contact icons now use dashicons instead of emoji, visible keyboard focus rings on all controls, reduced-motion support, status messages announced to screen readers, and the details popup is a proper Escape-dismissible dialog.

= 1.6.0 =
* Abandoned carts screen redesigned for a cleaner, fully responsive layout — equal-height KPIs, aligned tables, and a modernized details popup with product thumbnails, a matched WooCommerce customer, timestamps, status, and cart totals.
* Menu: Settings and Abandoned carts now show icons in the AI Sooq admin menu.
* Auto-generate missing SKUs: a product/variant with no SKU gets a unique one (SP-<id>) written to WooCommerce at sync time so the platform can map it (new toggle under Two-way sync → Products, on by default).

= 1.5.2 =
* Fix: capture any email the shopper actually enters. Email stays optional (phone-only carts are fine), but the previous strict validation could drop a valid address; now any "@"-shaped email is kept and pushed.

= 1.5.1 =
* Data quality: captured contacts and cart lines are now normalized and length-clamped to the platform's limits both when stored and when pushed — invalid emails and malformed phones are dropped, over-long titles/SKUs are trimmed, and only the platform's line fields are sent. Stops a cart with bad data from being rejected and retried forever.

= 1.5.0 =
* Block (Store API) checkout support for abandoned carts. A lightweight front-end beacon reads the contact + address + cart from the checkout data store and captures the cart as the shopper fills the form — WooCommerce Blocks fires no server hook before order placement, so classic-checkout capture alone missed these. Dedupe-safe per browser; only loads on a block-based checkout with abandoned-cart sync enabled.

= 1.4.2 =
* Abandoned carts + Settings screens are now fully mobile responsive. On phones the carts table reflows into labelled cards.
* Row actions (Details, Convert, Resync, Cancel, Fake, Delete) are consolidated into a single "Actions" menu button per row instead of a crowded button strip.

= 1.4.1 =
* Fix: carts are only captured once the shopper has a contact (email or phone) — an incomplete order needs a way to be reached. This stops the worklist filling with blank add-to-cart rows that have no customer info. Existing no-contact rows are hidden from the worklist and analytics (and garbage-collected on the normal 30-day schedule).
* Courier ratio check now shows a clear "No data" (with a tooltip) when the platform has no BDCourier history for the number — or when BDCourier isn't configured for the store on the platform. Note: the BDCourier API key lives on the platform (per store), not in the plugin; the plugin reads the ratio through the connection.

= 1.4.0 =
* Abandoned carts screen is now a full worklist matching the platform: per-row Check courier ratio (bdcourier delivery-success %), Convert to a WooCommerce order, Cancel, mark Fake, View details, Delete, and Sync/Resync.
* Bulk actions: select rows (or select-all) and Resync, Convert to order, Cancel, mark Fake, or Delete the whole selection at once.
* AJAX search (name / phone / email / product) plus filters by status, product, and captured date range — no page reload.
* Convert creates a native WooCommerce order (pending) from the captured cart — re-adding products by id/SKU with the captured price — so it flows through the normal order pipeline and mirrors to the platform. Cancel / Fake only change the local status and Delete only removes the local row; none touch the platform (the cart was already synced there on capture).
* Captured lines now record the product id, enabling the product filter and exact re-add on Convert.

= 1.3.0 =
* Abandoned carts now capture the shopper's name and full billing/shipping address at the checkout step and push them to the platform, so a recovered cart arrives with who + where (was phone-only).
* New "Abandoned carts" admin screen under AI Sooq: recovery analytics (captured / open / pushed / recovered + value), a drop-off funnel, and a filtered cart list with per-row and bulk Resync. Resync re-pushes without ever duplicating a cart on the platform (upsert on the stable cart fingerprint).
* Recovered carts are now retained (marked, not deleted) for 30 days to power the recovery-rate analytics, then garbage-collected.

= 1.2.5 =
* Fix: token now requests the app's full registered scope set instead of a hardcoded orders-only scope, so product/customer/catalog sync no longer 403s with "You don't have permission to do that". Cached token is dropped on upgrade so the fix applies immediately.

= 1.2.4 =
* Products list: a AI Sooq column showing Synced, or a per-product Sync button.

= 1.2.3 =
* Orders list: the AI Sooq column now sits right after the Status column.

= 1.2.2 =
* Orders list: a AI Sooq column showing Synced, or a per-order Sync
  button (works on both classic + HPOS order screens).

= 1.2.1 =
* Separate Sync buttons for Orders, Products, Customers and Categories.
* Proper WordPress admin-menu icon (tinted monochrome), correctly sized.

= 1.2.0 =
* Full internal rename to AI Sooq (code prefixes, slug, asset + cookie
  names). One-time data migration re-keys existing synced-order meta and
  renames the capture table, so no synced data is lost.

= 1.1.0 =
* Redesigned settings dashboard with live analytics KPIs.
* Independent two-way sync per entity (categories, brands, products,
  customers) each with its own on/off + push/pull/both direction.
* Courier delivery-ratio checkout gate (block orders below a threshold).
* Shipping mapping: map each WooCommerce shipping method to a platform
  shipping rate; delivery charges link to the rate on the platform.
* Faithful order money mirroring: fees + gift-cards, partial refunds,
  authoritative totals for platform-side reconciliation.
* COD orders mirror as unpaid until delivered; instant abandoned-cart
  push; order-push retry with backoff; two-way SEO redirects.
* Self-heals its table + cron schedules on update-in-place.

= 1.0.0 =
* Initial release: order push, abandoned-cart sweep, analytics forwarding,
  4-layer fraud screening, optional status sync-back.
