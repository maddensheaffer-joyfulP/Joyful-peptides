<?php
/**
 * Storefront infrastructure rendered from the theme.
 *
 * Everything here reads business details through jp_info() / jp_info_has() and
 * never hardcodes them.
 *
 * @package joyful-peptides
 */

defined( 'ABSPATH' ) || exit;

/**
 * Phone, rendered as a tel: link only when there is a real number.
 *
 * An unfilled value must not become a tel: href wrapping a bracketed prompt -
 * broken link that looks fine in markup, so the placeholder is emitted as
 * plain text instead and the dashed outline makes it obvious.
 */
function jp_phone_markup( $classes = '' ) {
	$class = trim( 'jp-utility-phone ' . $classes );
	if ( ! jp_info_has( 'phone' ) ) {
		return '<span class="' . esc_attr( $class ) . '">' . jp_info( 'phone' ) . '</span>';
	}
	$raw = jp_info_raw( 'phone' );
	$tel = preg_replace( '/[^0-9+]/', '', $raw );
	return '<a class="' . esc_attr( $class ) . '" href="tel:' . esc_attr( $tel ) . '">' . esc_html( $raw ) . '</a>';
}

/**
 * Email, rendered as a mailto: link only when there is a real address.
 */
function jp_email_markup( $key = 'email', $classes = '' ) {
	$class = trim( 'jp-mail ' . $classes );
	if ( ! jp_info_has( $key ) ) {
		return '<span class="' . esc_attr( $class ) . '">' . jp_info( $key ) . '</span>';
	}
	$raw = jp_info_raw( $key );
	return '<a class="' . esc_attr( $class ) . '" href="mailto:' . esc_attr( $raw ) . '">' . esc_html( $raw ) . '</a>';
}

/**
 * The free-shipping note. Returns '' while the threshold is unfilled or zero,
 * so nothing about free shipping renders anywhere on the site.
 */
function jp_free_shipping_note() {
	$threshold = jp_free_shipping_threshold();
	if ( $threshold <= 0 ) {
		return '';
	}
	return sprintf(
		/* translators: %s: formatted currency amount */
		esc_html__( 'Free shipping over %s', 'joyful-peptides' ),
		wp_strip_all_tags( wc_price( $threshold ) )
	);
}

/**
 * Header utility bar: phone left, shipping note right.
 *
 * Sits below the RUO banner - that line stays the topmost element on every
 * page - and directly above the masthead.
 */
add_shortcode( 'jp_utility_bar', function () {
	$right = array();

	if ( jp_info_has( 'ship_time' ) || jp_info_is_placeholder( 'ship_time' ) ) {
		$right[] = 'Ships in ' . jp_info( 'ship_time' );
	}
	if ( jp_info_has( 'ship_cutoff' ) || jp_info_is_placeholder( 'ship_cutoff' ) ) {
		$right[] = 'Order cutoff ' . jp_info( 'ship_cutoff' );
	}
	$free = jp_free_shipping_note();
	if ( '' !== $free ) {
		$right[] = esc_html( $free );
	}

	$out  = '<div class="jp-utility">';
	$out .= '<div class="jp-utility-inner">';
	$out .= '<p class="jp-utility-side jp-utility-left">' . jp_phone_markup() . '</p>';
	if ( $right ) {
		$out .= '<p class="jp-utility-side jp-utility-right">'
			. implode( '<span class="jp-utility-sep" aria-hidden="true">&middot;</span>', $right )
			. '</p>';
	}
	$out .= '</div></div>';
	return $out;
} );

/**
 * Header product search.
 *
 * Scoped with a hidden post_type=product, so results come from the catalog
 * only - the generic search template queries posts, and the COA library has
 * its own client-side batch filter on its own page. Typing a batch number
 * here runs a product search and returns product results; it never redirects
 * to, or interferes with, the COA lookup. Nothing on the site hooks
 * pre_get_posts or template_redirect for search.
 */
add_shortcode( 'jp_product_search', function () {
	$term = '';
	if ( is_search() && isset( $_GET['post_type'] ) && 'product' === $_GET['post_type'] ) {
		$term = get_search_query();
	}

	$icon = '<svg viewBox="0 0 24 24" width="15" height="15" fill="none" stroke="currentColor"'
		. ' stroke-width="1.8" stroke-linecap="round" aria-hidden="true">'
		. '<circle cx="11" cy="11" r="6.5"/><path d="M16 16l4.2 4.2"/></svg>';

	return '<form role="search" method="get" class="jp-search" action="' . esc_url( home_url( '/' ) ) . '">'
		. '<label class="screen-reader-text" for="jp-product-search">Search products</label>'
		. '<input type="search" id="jp-product-search" class="jp-search-input" name="s"'
		. ' value="' . esc_attr( $term ) . '" placeholder="Search products" autocomplete="off" />'
		. '<input type="hidden" name="post_type" value="product" />'
		. '<button type="submit" class="jp-search-btn"><span class="screen-reader-text">Search</span>'
		. $icon . '</button>'
		. '</form>';
} );

/* -------------------------------------------------------------------------
 * Free-shipping progress.
 *
 * Driven entirely by jp_info( 'free_shipping_threshold' ). Every render path
 * returns nothing at all when the threshold is unfilled, non-numeric or zero,
 * when the cart is empty, or when the threshold has already been met.
 *
 * NOTE: this is a DISPLAY figure. It does not configure a WooCommerce Free
 * Shipping method - setting the key alone tells customers something the store
 * will not honour at checkout unless a matching shipping zone is also set up.
 * ---------------------------------------------------------------------- */

/**
 * @return array|null  threshold/subtotal/remaining, or null when nothing should render.
 */
function jp_shipping_progress_state() {
	$threshold = jp_free_shipping_threshold();
	if ( $threshold <= 0 ) {
		return null;
	}
	if ( ! function_exists( 'WC' ) || ! WC()->cart || WC()->cart->is_empty() ) {
		return null;
	}
	/* Line-item subtotal excluding tax and shipping - the figure a customer
	   reads off the cart, and the one WooCommerce's own free-shipping minimum
	   compares against by default. */
	$subtotal  = (float) WC()->cart->get_subtotal();
	$remaining = $threshold - $subtotal;
	if ( $remaining <= 0 ) {
		return null;
	}
	return array(
		'threshold' => $threshold,
		'subtotal'  => $subtotal,
		'remaining' => $remaining,
	);
}

/**
 * Factual statement plus a progress meter. No urgency, no scarcity.
 */
function jp_shipping_progress_markup( $context = 'cart' ) {
	$state = jp_shipping_progress_state();
	if ( ! $state ) {
		return '';
	}
	$pct = max( 0, min( 100, ( $state['subtotal'] / $state['threshold'] ) * 100 ) );

	return '<div class="jp-ship-progress jp-ship-progress--' . esc_attr( $context ) . '">'
		. '<p class="jp-ship-progress-text">'
		. sprintf(
			/* translators: %s: remaining amount, already formatted as currency */
			esc_html__( '%s to free shipping', 'joyful-peptides' ),
			/* wc_price() returns the currency symbol as an HTML entity inside a
			   span. Stripping tags leaves "&#036;", which esc_html() would then
			   double-escape into a literal "&#036;" on screen - so decode first. */
			esc_html( html_entity_decode( wp_strip_all_tags( wc_price( $state['remaining'] ) ), ENT_QUOTES, 'UTF-8' ) )
		)
		. '</p>'
		/* Decorative. wp_kses_post() strips aria-valuenow, which would leave a
		   role="progressbar" with no value - a broken contract. The sentence
		   above already states the fact, so the meter is hidden from AT. */
		. '<div class="jp-ship-progress-track" aria-hidden="true">'
		. '<span class="jp-ship-progress-fill" style="width:' . esc_attr( round( $pct, 2 ) ) . '%"></span>'
		. '</div></div>';
}

/* Cart page is the classic [woocommerce_cart] shortcode, so a PHP hook is
   accurate on every load and after every quantity update. */
add_action( 'woocommerce_before_cart_totals', function () {
	echo wp_kses_post( jp_shipping_progress_markup( 'cart' ) );
} );


/**
 * Mini-cart free-shipping progress.
 *
 * The Mini-Cart block cannot be extended through its template part: WooCommerce
 * keeps a hardcoded allowlist (MiniCart::MINI_CART_TEMPLATE_BLOCKS) and
 * process_template_contents() strips every block that is not on it, so a
 * wp:shortcode placed there is silently removed. The drawer is also hydrated
 * client-side, so server-rendered markup would go stale the moment a quantity
 * changed.
 *
 * So the mini-cart figure is computed in the browser from the Store API, using
 * the same threshold value. Nothing is printed at all when the threshold is
 * unfilled or zero, which means the script does not exist on the page either.
 */
add_action( 'wp_footer', function () {
	$threshold = jp_free_shipping_threshold();
	if ( $threshold <= 0 ) {
		return;
	}
	?>
	<script>
	(function () {
		var THRESHOLD = <?php echo wp_json_encode( (float) $threshold ); ?>;
		var node = null, busy = false;

		function drop() {
			if ( node && node.parentNode ) { node.parentNode.removeChild( node ); }
			node = null;
		}

		function paint( text, pct, footer ) {
			if ( ! node ) {
				node = document.createElement( 'div' );
				node.className = 'jp-ship-progress jp-ship-progress--mini';
				node.innerHTML = '<p class="jp-ship-progress-text"></p>' +
					'<div class="jp-ship-progress-track" aria-hidden="true">' +
					'<span class="jp-ship-progress-fill"></span></div>';
			}
			if ( node.parentNode !== footer.parentNode ) {
				footer.parentNode.insertBefore( node, footer );
			}
			node.querySelector( '.jp-ship-progress-text' ).textContent = text;
			node.querySelector( '.jp-ship-progress-fill' ).style.width = pct.toFixed( 2 ) + '%';
		}

		function render() {
			var footer = document.querySelector( '.wc-block-mini-cart__footer' );
			if ( ! footer || busy ) { return; }
			busy = true;
			fetch( '/wp-json/wc/store/v1/cart', { credentials: 'same-origin' } )
				.then( function ( r ) { return r.json(); } )
				.then( function ( d ) {
					busy = false;
					if ( ! d || ! d.totals ) { return; }
					if ( ! d.items_count ) { drop(); return; }
					var minor = d.totals.currency_minor_unit;
					var sub   = parseInt( d.totals.total_items, 10 ) / Math.pow( 10, minor );
					var left  = THRESHOLD - sub;
					if ( left <= 0 ) { drop(); return; }
					var pct  = Math.max( 0, Math.min( 100, ( sub / THRESHOLD ) * 100 ) );
					var text = ( d.totals.currency_prefix || '' ) + left.toFixed( minor ) +
						( d.totals.currency_suffix || '' ) + ' to free shipping';
					paint( text, pct, footer );
				} )
				.catch( function () { busy = false; } );
		}

		/* Opening the drawer, and any cart change while it is open. */
		document.addEventListener( 'click', function ( e ) {
			if ( e.target.closest && e.target.closest( '.wc-block-mini-cart__button' ) ) {
				setTimeout( render, 400 );
			}
		} );
		document.body.addEventListener( 'wc-blocks_added_to_cart', function () {
			setTimeout( render, 400 );
		} );

		/* The items list re-renders on every quantity change. Our node lives
		   outside it, so observing it cannot re-trigger us. */
		var watch = new MutationObserver( function () { render(); } );
		var start = new MutationObserver( function () {
			var items = document.querySelector( '.wc-block-mini-cart__items' );
			if ( items && ! items.dataset.jpWatched ) {
				items.dataset.jpWatched = '1';
				watch.observe( items, { childList: true, subtree: true, characterData: true } );
				render();
			}
		} );
		start.observe( document.body, { childList: true, subtree: true } );
	})();
	</script>
	<?php
}, 20 );

/**
 * [jp_learn_panel] - the homepage education section, as a two-column split.
 *
 * Copy frames verification literacy only: reading a COA, what third-party
 * testing does and does not establish, and vetting any supplier including this
 * one. No product claims, no purity figures - the graphic is deliberately
 * abstract line art with no numbers on it, so nothing in this panel can be read
 * as a statement about what is in a vial.
 */
add_shortcode( 'jp_learn_panel', function () {
	$href = jp_info_has( 'learn_destination' ) ? jp_info_raw( 'learn_destination' ) : '/learn/';

	$art = '<svg class="jp-split-art" viewBox="0 0 220 200" fill="none" aria-hidden="true"'
		. ' stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">'
		/* document */
		. '<path d="M46 24h74l32 32v120a8 8 0 0 1-8 8H46a8 8 0 0 1-8-8V32a8 8 0 0 1 8-8z"/>'
		. '<path d="M120 24v32h32"/>'
		/* ruled lines - no values, no figures */
		. '<path d="M58 80h56M58 98h74M58 116h44" opacity=".55"/>'
		/* magnifier over the record */
		. '<circle cx="132" cy="132" r="30"/>'
		. '<path d="M154 154l18 18"/>'
		/* check inside the lens */
		. '<path d="M120 132l9 9 18-19"/>'
		. '</svg>';

	ob_start();
	?>
	<div class="jp-split-panel">
		<div class="jp-split-media" aria-hidden="true">
			<?php echo $art; // phpcs:ignore WordPress.Security.EscapeOutput ?>
		</div>
		<div class="jp-split-body">
			<p class="jp-kicker jp-kicker-light">06 &mdash; Education</p>
			<h2 class="jp-split-title">Explore the <em>research.</em></h2>
			<p class="jp-split-copy">Read a certificate of analysis line by line, and know which parts of it carry weight. Understand what independent testing establishes about a batch &mdash; and what it leaves unanswered. Then apply the same questions to any supplier you buy from, this one included.</p>
			<p class="jp-split-copy jp-split-copy-sub">No dosing guidance, no outcome claims. Only the checks a careful buyer should be able to run themselves.</p>
			<p class="jp-split-cta">
				<a class="jp-split-link" href="<?php echo esc_url( $href ); ?>">Browse the Learn hub</a>
			</p>
		</div>
	</div>
	<?php
	return ob_get_clean();
} );

/**
 * [jp_info key="phone"] - lets page content (which lives in the database) pull
 * a business detail from inc/site-info.php instead of hardcoding it.
 *
 * Without this, a value typed into a page would be invisible to the pre-launch
 * grep, which only scans the theme. Now the placeholder lives in site-info.php
 * where the gate can see it, and the page stays editable in wp-admin.
 */
add_shortcode( 'jp_info', function ( $atts ) {
	$atts = shortcode_atts( array( 'key' => '' ), $atts, 'jp_info' );
	return $atts['key'] ? jp_info( $atts['key'] ) : '';
} );

/**
 * [jp_bulk_contact] - the bulk enquiry address as a mailto: link when real,
 * plain outlined text while unfilled.
 */
add_shortcode( 'jp_bulk_contact', function () {
	return jp_email_markup( 'bulk_contact' );
} );

/**
 * [jp_about_cards] - the shipping and contact summary pair on /about/.
 *
 * Card A condenses /shipping-returns/ and links to it for the full terms. Every
 * factual line is taken from that page: US-only shipping, Adult Signature
 * Required (21+) with a matching recipient name, and no returns on opened
 * product. Handling time and order cutoff are NOT stated there, so they come
 * from jp_info() as placeholders, and the damaged / defective / incorrect rule
 * is a placeholder too because the policy page marks it unfinalised - asserting
 * one here would contradict the page this card links to.
 */
add_shortcode( 'jp_about_cards', function () {
	ob_start();
	?>
	<div class="jp-infocards">

		<section class="jp-infocard">
			<h2 class="jp-infocard-title">Shipping and returns</h2>
			<dl class="jp-infocard-list">
				<dt>Where we ship</dt>
				<dd>Within the United States only. We do not ship internationally.</dd>

				<dt>Handling time</dt>
				<dd><?php echo wp_kses_post( jp_info( 'ship_time' ) ); ?></dd>

				<dt>Order cutoff</dt>
				<dd><?php echo wp_kses_post( jp_info( 'ship_cutoff' ) ); ?></dd>

				<dt>Delivery</dt>
				<dd>Every order ships Adult Signature Required (21+). The recipient name
					must match the name on the account that placed the order, and a package
					cannot be left unattended.</dd>

				<dt>Returns</dt>
				<dd>Opened product cannot be returned, because integrity depends on storage
					and handling after it leaves our control.</dd>

				<dt>Damaged, defective or incorrect</dt>
				<dd><?php echo wp_kses_post( jp_info( 'returns_damaged' ) ); ?></dd>
			</dl>
			<p class="jp-infocard-more">
				<a href="/shipping-returns/">Full shipping and returns terms</a>
			</p>
		</section>

		<section class="jp-infocard">
			<h2 class="jp-infocard-title">Contact</h2>
			<dl class="jp-infocard-list">
				<dt>Registered entity</dt>
				<dd><?php echo wp_kses_post( jp_info( 'legal_entity' ) ); ?></dd>

				<dt>Address</dt>
				<dd><address class="jp-infocard-address" itemscope itemtype="https://schema.org/PostalAddress"><span itemprop="streetAddress"><?php echo wp_kses_post( jp_info( 'address' ) ); ?></span></address></dd>

				<dt>Phone</dt>
				<dd><?php echo wp_kses_post( jp_phone_markup( 'jp-infocard-link' ) ); ?></dd>

				<dt>Email</dt>
				<dd><?php echo wp_kses_post( jp_email_markup( 'email', 'jp-infocard-link' ) ); ?></dd>

				<dt>Hours</dt>
				<dd><?php echo wp_kses_post( jp_info( 'hours' ) ); ?></dd>
			</dl>
			<p class="jp-infocard-more">
				<a href="/contact/">Contact page</a>
			</p>
		</section>

	</div>
	<?php
	return ob_get_clean();
} );
