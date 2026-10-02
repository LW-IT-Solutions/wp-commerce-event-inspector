=== Commerce Event Inspector ===
Contributors: lwitsolutions
Tags: woocommerce, google analytics, ga4, ecommerce tracking, datalayer
Requires at least: 6.5
Tested up to: 7.1
Requires PHP: 7.4
Stable tag: 0.1.0
License: GPL-2.0-or-later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Check the GA4 e-commerce events of your WooCommerce shop against the real orders, cart and products.

== Description ==

A WooCommerce order proves that a customer paid. It does not prove that the purchase event for Google Analytics 4 was
pushed, that it was pushed once, or that it carried the right order number, amount and products. Such errors show up
weeks later as revenue in reports that does not add up.

Commerce Event Inspector reads the GA4 e-commerce events that tracking plugins and tags push to the dataLayer or send
with gtag(), and compares them with WooCommerce:

* **Purchase check (optional):** on the order confirmation page, a small script reads the events and sends them to this site, where they are stored with the order. WooCommerce > Event Inspector lists every order since recording was switched on: purchase event present or missing, pushed more than once, transaction_id against the order number, currency, value and items. For value, the report names which reading of the order total matched: with tax and shipping, without shipping, without tax, or without both. A purchase event on a failed, cancelled or still unpaid order is flagged as well.
* **Inspector panel:** for logged-in shop managers who switch it on, a panel in the shop lists every e-commerce event as it happens, from view_item to purchase, with checks against the GA4 rules and against the product, cart or order on the page: item IDs matched to SKU, product ID or variation ID, prices with or without tax, quantities and totals.
* **Order screen:** the result of each order also appears in a box on the order screen.

Works with events in the GA4 format from any source, for example GTM4WP, Google Analytics for WooCommerce, PixelYourSite
or code in a theme. Events in the older Universal Analytics format are recognised and flagged.

= Privacy =

Recording is off until it is switched on. When it is on, the order confirmation page sends the events it found to this
site only. Stored with the order are event names, transaction ID, value, currency and the IDs, names, prices and
quantities of the items. No cookies are set, no customer data is read from the events, and nothing is sent to other
services. A suggested text for the privacy policy is added to the privacy policy guide. Uninstalling removes the setting
and all stored observations.

= Limits =

* The plugin sees what the page pushes, not whether Google Analytics received it. Ad blockers or missing consent can still stop the request, and events sent only from the server are not visible.
* Read are window.dataLayer, other arrays whose name starts with dataLayer (PixelYourSite uses dataLayerPYS) and names that a Tag Manager or gtag.js loader on the page sets with l=. Events kept elsewhere are not visible.
* Orders whose confirmation page is not viewed while recording is on, such as orders created in the admin, are listed as not observed. Observations are accepted for two days after an order is placed, and at most five per order.
* Per event, the first 100 items are stored and compared; for larger orders the report says so instead of listing the rest as missing.

== Installation ==

1. Install and activate WooCommerce, then this plugin.
2. Open WooCommerce > Event Inspector, switch on the purchase check and, if wanted, the inspector panel for your account.
3. Place a test order or wait for the next orders; the report lists them with their results.

== Frequently Asked Questions ==

= Why is the inspector panel empty while I am logged in? =

Many tracking plugins skip logged-in shop managers or administrators. Check the tracking plugin's settings, or use a
private window with a customer account and look at the order report instead.

= Does the check slow down the shop? =

The script loads only on the order confirmation page while recording is on, and for shop managers who switch on the
panel. It sends one small request per confirmation page.

= What does "not observed" mean? =

Nobody viewed the order confirmation page while recording was on, so there was nothing to check. This is normal for
orders created in the admin or by payment providers that do not return the customer to the shop.

== Screenshots ==

1. WooCommerce > Event Inspector: each order with its purchase checks, from a match to a value sent as text, a missing items list, the older Universal Analytics format, an order key used as transaction_id, and an event that only a separate array (dataLayerPYS) received.
2. The inspector panel on an order confirmation page: the purchase event compared with the order, item by item.

== Changelog ==

= 0.1.0 =
* First version: purchase check on the order confirmation page with order report and order screen box, inspector panel for shop managers.
