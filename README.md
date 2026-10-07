# Toloka for monobank

A free WooCommerce plugin that adds what the official "plata by mono" plugin is missing:

- **monobank installments** (Покупка частинами). The customer applies at checkout and confirms in the monobank app.
- **Cash on delivery with online prepayment.** The customer pays a fixed amount or a percentage online and the rest on delivery.
- **"4 платежі по ~672 ₴"** under the price on product pages.

Card payments stay with the official plugin. Toloka works next to it.

User guides in Ukrainian and English will be on the tolokacode docs site.

## Installation

1. Upload the `toloka-monobank` folder to `wp-content/plugins/` and activate it.
2. Go to WooCommerce > Settings > Payments:
   - **Покупка частинами monobank**: enter the Store ID and secret from the bank, and the number of payments from your contract.
   - **Накладений платіж** (cash on delivery): set the prepayment. The token comes from the "plata by mono" plugin.

Cash on delivery works as before until you set a prepayment above 0, so installing the plugin changes nothing on its own.

## Order statuses

| Method | Status | Meaning |
|---|---|---|
| Installments | On hold | The customer is confirming in the app (up to 15 minutes). Don't ship yet. |
| | Processing | The customer confirmed. Ship the order. |
| | Completed | Set this after shipping. The bank confirms the purchase and pays the shop. |
| | Cancelled | Before Completed, this cancels the application at the bank. After Completed, use Refund instead. |
| Cash on delivery | Pending payment | The prepayment isn't paid yet. Don't ship. |
| | Processing | The prepayment arrived. The waybill amount is in the "Накладений платіж (ТТН)" row. |

## Testing

Installments sandbox: Store ID `test_store_with_confirm`, secret `secret_98765432--123-123`.
The last digit of the phone number decides what happens: `1` approved, `2` waits for the customer, `3` over the limit, `4` approved and waiting for the store.

For the prepayment, use a test plata by mono token from your monobank business account.

Logs are in WooCommerce > Status > Logs, under `toloka-chast` and `toloka-prepay`.

## Translations

Texts in the code are in English. The Ukrainian translation is in `languages/`, and WordPress
picks it automatically when the site language is Ukrainian.

After you add or change a text, update the files with [WP-CLI](https://wp-cli.org/):

```bash
wp i18n make-pot . languages/toloka-monobank.pot
# add the new texts to languages/toloka-monobank-uk.po (Poedit or any text editor)
wp i18n make-mo languages
```

## Contributing

See the [contributing guide](https://github.com/tolokacode/.github/blob/main/CONTRIBUTING.md).

## License

[EUPL-1.2](LICENSE)
