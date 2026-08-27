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
 * An unfilled value must not become href="tel:[[ INPUT ... ]]" - that is a
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
