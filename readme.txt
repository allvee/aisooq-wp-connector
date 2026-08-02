=== AI Sooq Connector ===
Contributors: aisooq
Tags: woocommerce, orders, analytics, sync, abandoned cart
Requires at least: 5.8
Tested up to: 6.7
Requires PHP: 7.4
WC requires at least: 6.0
WC tested up to: 9.9
Stable tag: 2.0.0
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
