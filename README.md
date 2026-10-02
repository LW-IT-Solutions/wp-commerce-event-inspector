# Commerce Event Inspector

Check the GA4 e-commerce events of a WooCommerce shop against the real orders, cart and products.

- Purchase check on the order confirmation page: purchase present or missing, pushed more than once, transaction_id against the order number, currency, value (which reading of the order total matched), items, and purchase events on failed or unpaid orders
- Order report under WooCommerce > Event Inspector and a box on the order screen
- Inspector panel for shop managers: every e-commerce event in the shop, checked against the GA4 rules and against the product, cart or order on the page
- Reads dataLayer, other dataLayer arrays (such as dataLayerPYS) and gtag() calls; works with GTM4WP, Google Analytics for WooCommerce, PixelYourSite or theme code
- Off until switched on; no cookies, no requests to other services; compatible with the WooCommerce order tables (HPOS)

Requires WordPress 6.5, PHP 7.4 and WooCommerce 8.0. Details in [readme.txt](readme.txt).

## License

GPL-2.0-or-later, see [LICENSE](LICENSE).
