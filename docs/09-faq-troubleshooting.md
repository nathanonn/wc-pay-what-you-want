# FAQ & Troubleshooting

Quick answers to common questions and solutions for issues you might encounter with the WC Pay What You Want plugin.

---

## Frequently Asked Questions

### General

**Q: What product types does PWYW support?**

A: Simple products, Virtual products, Downloadable products, and Variable products (with per-variation control). These cover the vast majority of WooCommerce store setups. Support for additional product types (such as Grouped or External/Affiliate) is not included in v1.

---

**Q: Does the plugin work without WooCommerce?**

A: No. WooCommerce is a required dependency. The plugin checks for WooCommerce on activation and will display an admin notice if WooCommerce is not installed or not active. You must install and activate WooCommerce before the PWYW plugin will function.

---

**Q: Can I use PWYW alongside regular-priced products in my store?**

A: Yes. You enable PWYW on a per-product basis. Products without PWYW enabled continue to work normally with their standard WooCommerce pricing. There is no requirement to use PWYW on every product in your catalog.

---

**Q: What happens if I disable the master toggle in settings?**

A: All PWYW functionality is turned off globally. Products will revert to showing their regular WooCommerce prices on the frontend. Your per-product settings (minimum prices, maximum prices, suggested prices, preset buttons, etc.) are all preserved in the database and will take effect again when you re-enable the master toggle. Think of it as a pause button, not a reset.

---

### Pricing

**Q: How are prices calculated if I don't set overrides on a product?**

A: The global percentage defaults are applied to the product's regular price. For example, if the global minimum is set to 50% and a product's regular WooCommerce price is $100, the calculated minimum PWYW price for that product is $50. The same logic applies to the suggested price and maximum price percentages. You only need to set product-level overrides when a specific product needs different boundaries.

---

**Q: Can customers pay $0?**

A: Only if you explicitly enable the "Allow $0 price" option on the product. This is disabled by default for obvious reasons. When enabled, the minimum price floor is removed and customers may enter $0 (or any amount up to the maximum). This is useful for donation-style products, free trials, or community-supported pricing models.

---

**Q: What's the difference between the "suggested price" and the "regular price"?**

A: These serve different purposes:

- **Regular price** is the standard WooCommerce price field found in the General tab. It is used as the base for percentage calculations (e.g., "minimum is 50% of regular price") and may also be displayed as a reference point.
- **Suggested price** is what you recommend customers pay. It pre-fills the price input field on the product page and is shown as a visual hint. It can be the same as the regular price, or different.

For example, a product with a regular price of $100 might have a suggested price of $80 if you want to nudge customers toward a slightly lower entry point while still using $100 as the calculation base.

---

**Q: Can I set a maximum price to prevent fraud?**

A: Yes, in two ways:

1. **Per-product maximum price** -- Set in the product's Pay What You Want tab. This controls the ceiling for that specific product.
2. **Absolute maximum price cap** -- Set in the global Security settings (WooCommerce > Settings > Pay What You Want). This acts as a hard ceiling across all products, regardless of individual product settings. The default is $9,999.

Both limits are enforced server-side, so they cannot be bypassed by manipulating the frontend.

---

### Coupons

**Q: Can customers use coupons with PWYW products?**

A: It depends on how you configure the coupon behavior setting. There are three modes:

| Mode | Behavior |
|------|----------|
| **Allow freely** | Coupons apply normally with no restrictions. The final price can go below the minimum. |
| **Allow with floor** | Coupons apply, but the final price never drops below the product's minimum price. |
| **Block** | Coupons are not applied to PWYW items at all. |

This setting can be configured globally and overridden on a per-product basis.

---

**Q: If I allow coupons with a floor, how does it work?**

A: The coupon discount is applied, but the final price is clamped so it never goes below the product's minimum PWYW price.

**Example:** A customer enters $25 as their price. The product's minimum price is $15. They apply a 50% coupon. Without the floor, the price would be $12.50. With the floor active, the price is held at $15 (the minimum).

This protects your pricing boundaries while still letting customers feel the benefit of a coupon.

---

### Cart & Checkout

**Q: Can customers change their price after adding to cart?**

A: Yes. The cart page shows an editable price input for PWYW items. Customers can adjust the price up or down within the allowed min/max boundaries directly from the cart. The cart totals update in real time as they change the value.

---

**Q: What is the "mixed cart" restriction?**

A: When enabled, this setting prevents customers from having both PWYW and regular-priced items in the same cart. If a customer tries to add a regular item when a PWYW item is already in the cart (or vice versa), they will see a notice explaining that the cart types cannot be mixed.

This is an optional setting and is disabled by default. Most stores will not need it, but it can be useful if your checkout flow or payment gateway has issues processing mixed item types.

---

**Q: Can customers edit the price at checkout?**

A: No. The price is locked in at the cart stage. On the checkout page, the PWYW price is displayed as read-only. This prevents last-second changes during payment processing and ensures the price the customer confirmed in the cart is the price that gets charged.

---

### Variable Products

**Q: Can I have some variations with PWYW and others without?**

A: Yes. Each variation can independently be set to one of three modes:

- **Inherit from parent** -- Uses the parent product's PWYW configuration
- **Enable PWYW** -- Enables PWYW with its own min/max/suggested overrides
- **Disable PWYW** -- Uses standard fixed pricing regardless of parent settings

This gives you full flexibility. For example, you could offer a "pay what you want" option on a basic tier variation while keeping premium tier variations at fixed prices.

---

**Q: What does "Inherit from parent" mean for variations?**

A: The variation uses whatever PWYW configuration the parent (main) product has. If the parent product has PWYW enabled, the variation will also use PWYW. The global default percentages are applied to that specific variation's regular price to calculate its min, max, and suggested values.

For example, if the global minimum is 50% and a variation's regular price is $40, its calculated minimum PWYW price is $20 -- even though the parent product might have a different regular price.

"Inherit from parent" is the default setting for all variations, which means you only need to touch variation-level settings when you want a specific variation to behave differently.

---

### Analytics & Orders

**Q: Where can I see PWYW analytics?**

A: On your WordPress Dashboard (WP Admin > Dashboard), there is a "Pay What You Want -- Overview" widget. It shows:

- Total PWYW revenue for the selected period
- Number of PWYW orders
- Average customer price vs. suggested price
- Price distribution breakdown

The widget includes a date range selector so you can review trends over different time periods.

---

**Q: Are PWYW prices captured in the order history?**

A: Yes. Each PWYW line item in an order stores a complete snapshot of the pricing context at the time of purchase:

- The price the customer chose
- The suggested price that was shown
- The minimum and maximum boundaries that were in effect

This data is preserved even if you later change the product's PWYW settings, so your historical records always reflect what the customer actually saw and paid.

---

**Q: Can I export PWYW data?**

A: Yes. The plugin adds PWYW-specific columns to WooCommerce's built-in CSV order export. The additional columns are:

| Column | Description |
|--------|-------------|
| PWYW Enabled | Whether the line item was a PWYW purchase |
| Customer Price | The price the customer entered |
| Suggested Price | The suggested price shown at time of purchase |
| Difference | Dollar amount above or below suggested price |
| Difference % | Percentage above or below suggested price |

Use WooCommerce > Orders > Export to generate the CSV file with these columns included.

---

## Troubleshooting

### PWYW fields not showing on the product page

If customers do not see the PWYW price input on a product page, check each of these in order:

1. **Master toggle** -- Go to WooCommerce > Settings > Pay What You Want and confirm the "Enable" toggle is turned on.
2. **Product-level setting** -- Edit the product, go to the "Pay What You Want" tab in the Product Data panel, and confirm the "Enable PWYW" checkbox is checked.
3. **Regular price** -- Make sure the product has a regular price set in the General tab. PWYW needs a base price for percentage calculations. If the regular price is empty, the plugin cannot compute boundaries and will not display the input.
4. **Variable products** -- Confirm that variations exist and each variation has a regular price set. A variable product with no variations (or variations without prices) will not show PWYW fields.
5. **Caching** -- Clear your caching plugin (WP Super Cache, W3 Total Cache, LiteSpeed, etc.), any object cache (Redis, Memcached), and your CDN cache. Cached pages will serve stale HTML without the PWYW input.

---

### Price validation errors appearing incorrectly

If customers see validation errors ("Price must be at least..." or "Price cannot exceed...") that seem wrong:

1. **Check product boundaries** -- Edit the product and verify that the minimum price is less than the maximum price. A minimum of $50 and maximum of $30 is invalid and will cause unexpected errors.
2. **Check global percentages** -- If the product uses global defaults (no product-level overrides), go to WooCommerce > Settings > Pay What You Want and verify the percentage values produce valid ranges. For example, a minimum of 80% and maximum of 50% would result in the minimum being higher than the maximum.
3. **Product-level overrides** -- If you have set product-level price overrides, confirm the values are internally consistent (min < suggested < max).
4. **Variation prices** -- For variable products, check each variation's regular price and any variation-level PWYW overrides independently.

---

### "Add to cart" button stays disabled

The Add to Cart button is intentionally disabled when the price input has a validation error. To resolve:

1. **Check the pre-filled price** -- The suggested price (which pre-fills the input) must fall within the min/max boundaries. If the suggested price is outside the range due to a configuration error, the form will start in an invalid state.
2. **Browser console** -- Open your browser's developer tools (F12 or right-click > Inspect > Console tab) and look for JavaScript errors. Errors from other plugins or theme scripts can prevent the PWYW validation script from running.
3. **Script conflicts** -- Temporarily switch to a default theme (like Storefront or Twenty Twenty-Four) and disable other plugins to isolate the issue.

---

### Cart price not updating

If the editable price field in the cart does not update the totals when changed:

1. **JavaScript blocked** -- Check if browser extensions (ad blockers, privacy tools) are blocking JavaScript execution on your site.
2. **Plugin conflicts** -- Other cart-related plugins (custom cart pages, cart optimization plugins, AJAX cart handlers) may conflict with the PWYW cart functionality. Disable them temporarily to test.
3. **AJAX not working** -- Some security plugins or server configurations block requests to `admin-ajax.php`, which is used to update cart totals. Check WooCommerce > Status > System Status for AJAX-related warnings.
4. **Theme compatibility** -- Custom cart templates in your theme may not include the hooks the PWYW plugin relies on. Try switching to a default WooCommerce-compatible theme to test.

---

### Email notifications not sending

If you have configured PWYW price alert emails but are not receiving them:

1. **Alert enabled** -- Verify the email alert is enabled in the PWYW settings.
2. **Threshold value** -- The threshold is a percentage below the suggested price, not a dollar amount. A threshold of 30% means the alert triggers when a customer pays 30% or more below the suggested price. If your threshold is very high (e.g., 90%), alerts will rarely trigger.
3. **Recipient email** -- Confirm the recipient email address is correct and can receive mail.
4. **Email delivery** -- Check WooCommerce > Status > Logs for any email-related errors. Many WordPress installations have issues with outbound email. Consider using an SMTP plugin (like WP Mail SMTP) to ensure reliable delivery.
5. **Test with an order** -- Place a test order with a price that falls below the threshold to confirm the alert fires.

---

### Dashboard widget shows no data

If the "Pay What You Want -- Overview" widget on the WordPress Dashboard is empty:

1. **No orders yet** -- PWYW analytics data is only recorded when orders reach "processing" or "completed" status. New installations start with no data. Place a test order and complete it to verify data collection.
2. **Date range** -- Check the date range selector on the widget. Make sure it covers the dates when your PWYW orders were placed.
3. **Pending orders** -- Orders in "pending" or "on-hold" status are not included in analytics. They will appear once their status changes to "processing" or "completed."

---

### Plugin deactivation -- will I lose my settings?

No. Deactivating the plugin preserves all of your settings and product configurations in the database. Nothing is deleted. Reactivating the plugin will restore everything exactly as it was. This is safe to do for troubleshooting purposes.

---

### Plugin uninstallation -- what happens to my data?

The plugin provides a choice during uninstallation to control what happens to your data:

| Option | What it does |
|--------|-------------|
| **Keep data** | All settings, product meta, and order meta are preserved in the database. If you reinstall later, everything will be restored. |
| **Delete data** | Plugin settings and product meta (PWYW configurations on products) are removed. You can separately choose whether to keep or delete order meta (the historical records of what customers paid). |

**Recommendation:** If you are uninstalling temporarily or plan to reinstall, choose "Keep data." Only choose "Delete data" if you are permanently removing the plugin and want a clean database.

---

### Getting Further Help

If your issue is not covered here:

1. Check the other documentation guides in this series for detailed information on specific features.
2. Review WooCommerce > Status > System Status for environment issues (PHP version, memory limits, plugin conflicts).
3. Test with a default theme and only WooCommerce active to rule out conflicts.
4. Check your server's PHP error log for any errors related to the plugin (look for "wcpwyw" in log entries).
