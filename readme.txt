=== Toloka for monobank ===
Contributors: bulhakov
Tags: monobank, installments, woocommerce, cash on delivery, prepayment
Requires at least: 6.5
Tested up to: 7.1
Requires PHP: 7.4
Stable tag: 0.1.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

monobank installments and cash on delivery with online prepayment for WooCommerce. Free, no paid version.

== Description ==

Adds what the official "plata by mono" plugin is missing:

* **monobank installments** (Покупка частинами). The customer picks the number of payments at checkout and confirms in the monobank app.
* **Cash on delivery with online prepayment.** The customer pays a fixed amount or a percentage online and the rest on delivery.
* **"4 payments of ~672 ₴"** under the price on product pages.

Card payments stay with the official plugin. Toloka works next to it.

Works with the classic checkout and the checkout block. English and Ukrainian.

Free and open source. There is no paid version.

= External services =

This plugin connects to monobank:

* **Installments API** (u2.monobank.com.ua) to create, check, confirm, cancel and refund installment applications. It sends the order number, total, products and the customer's phone number when the customer chooses installments.
* **plata by mono API** (api.monobank.ua) to create the prepayment invoice. It sends the order number and prepayment amount when the customer chooses cash on delivery with prepayment.

monobank [terms](https://www.monobank.ua/terms) and [privacy policy](https://www.monobank.ua/privacy).

== Installation ==

1. Install and activate the plugin.
2. Go to WooCommerce > Settings > Payments.
3. **monobank installments**: enter the Store ID and secret from the bank and the number of payments from your contract.
4. **Cash on delivery**: set the prepayment. The token comes from the "plata by mono" plugin.

Cash on delivery works as before until you set a prepayment above 0.

== Frequently Asked Questions ==

= Do I need the official plata by mono plugin? =

Only for card payments and for the prepayment token. Installments work without it.

= How do I test it? =

Choose the Sandbox environment. Store ID `test_store_with_confirm`, secret `secret_98765432--123-123`. A phone number ending in 4 is approved.

= Is there a paid version? =

No.

== Changelog ==

= 0.1.0 =
* First release.
