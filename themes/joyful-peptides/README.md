# joyful-peptides

Custom block theme for Joyful Peptides. WordPress + WooCommerce.

## Business details

Every business detail on the site — address, phone, email, hours, shipping
cutoff, free-shipping threshold, bulk contact — lives in **one** file:

    inc/site-info.php

Templates never hardcode these. They call `jp_info( 'key' )` (escaped, display
safe), `jp_info_raw( 'key' )` (for `tel:` / `mailto:` / `href` attributes), or
`jp_info_has( 'key' )` (to decide whether to render an element at all).

Unfilled values are written as `[[ DESCRIPTIVE PROMPT ]]` and render inside
`.jp-placeholder`, which draws a dashed rust outline so they are obvious while
working locally.

### Free shipping

`free_shipping_threshold` is the one key with fallback behaviour rather than a
visible placeholder. Unfilled, non-numeric or zero all mean **the feature is
off**, and nothing about free shipping renders in the mini-cart or on the cart
page. Use `jp_free_shipping_threshold()`, which returns a float or `0.0`.

## Pre-launch checks

Run from the WordPress root (`app/public`). Fails with a nonzero exit code if
any unfilled placeholder is still in the theme:

    ! grep -rn --exclude=README.md '\[\[ ' wp-content/themes/joyful-peptides

Exit `0` means clean. Exit `1` prints every file and line still awaiting a real
value.

The site also ships an in-admin scan at **Tools → Pre-Launch Check**, which
covers sample data, the placeholder gateway and unreviewed legal pages. The
grep above covers what that cannot see.
