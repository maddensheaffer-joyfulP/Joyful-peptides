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

### Logo

`logo_dark` and `logo_light` are the only place a logo asset is named. Header,
footer and favicon all resolve through `jp_logo_uri()`, so dropping the real
renders into `assets/img/` and changing those two lines updates every instance.

`logo_dark` is the **dark** mark, used on **light** grounds (the masthead).
`logo_light` is the **light** mark, used on **dark** grounds (footer, CTA band,
trust strip).

Both currently point at disposable placeholders whose filenames contain
`logo-placeholder`, so this check refuses to let one ship:

    ! grep -rn --exclude=README.md -e '\[\[ ' wp-content/themes/joyful-peptides \
      && ! grep -n 'logo-placeholder' wp-content/themes/joyful-peptides/inc/site-info.php

Use that command as the pre-launch gate — it covers unfilled values **and** a
placeholder logo in a single pass.

The site also ships an in-admin scan at **Tools → Pre-Launch Check**, which
covers sample data, the placeholder gateway and unreviewed legal pages. The
grep above covers what that cannot see.
