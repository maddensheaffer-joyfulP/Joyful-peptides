<?php
/**
 * Single source of truth for business details.
 *
 * Templates must NEVER hardcode any of these values - they call jp_info() and
 * friends. Filling in this one file updates the whole site.
 *
 * Unfilled values are written as a double-bracketed uppercase prompt. Anything
 * still containing "[[" is rendered inside .jp-placeholder so it is impossible
 * to miss locally, and the pre-launch grep in README.md fails the build if one
 * survives to production.
 *
 * @package joyful-peptides
 */

defined( 'ABSPATH' ) || exit;

/**
 * The raw values. Replace the bracketed strings with real details.
 */
function jp_site_info() {
	return array(
		'legal_entity'            => '[[ INPUT REGISTERED LEGAL ENTITY NAME HERE ]]',
		'address'                 => '[[ INPUT BUSINESS ADDRESS HERE ]]',
		'phone'                   => '[[ INPUT BUSINESS PHONE NUMBER HERE ]]',
		'email'                   => '[[ INPUT SUPPORT EMAIL ADDRESS HERE ]]',
		'hours'                   => '[[ INPUT SUPPORT HOURS AND TIME ZONE HERE ]]',
		'ship_cutoff'             => '[[ INPUT DAILY ORDER CUTOFF TIME AND TIME ZONE HERE ]]',
		'ship_time'               => '[[ INPUT HANDLING TIME BEFORE DISPATCH HERE ]]',
		/* Numeric, in USD. Zero or unfilled means the feature is OFF and nothing
		   about free shipping renders anywhere. See jp_free_shipping_threshold(). */
		'free_shipping_threshold' => '[[ INPUT FREE SHIPPING THRESHOLD IN USD HERE ]]',
		'bulk_contact'            => '[[ INPUT BULK AND INSTITUTIONAL ORDER CONTACT EMAIL HERE ]]',
		/* Pre-filled: /learn/ already exists, and a bracketed string in an href
		   would produce a broken link rather than a visible placeholder. */
		'learn_destination'       => '/learn/',
	);
}

/**
 * Raw, unescaped, unwrapped value. Use for attributes (tel:, mailto:, href)
 * and for logic - never echo it straight into markup.
 */
function jp_info_raw( $key ) {
	$info = jp_site_info();
	return isset( $info[ $key ] ) ? $info[ $key ] : '';
}

/**
 * Is this value still a placeholder?
 */
function jp_info_is_placeholder( $key ) {
	return false !== strpos( jp_info_raw( $key ), '[[' );
}

/**
 * Does this key hold a real, usable value? Templates use this to decide whether
 * to render an element at all, rather than emitting a broken one.
 */
function jp_info_has( $key ) {
	$raw = trim( jp_info_raw( $key ) );
	return '' !== $raw && ! jp_info_is_placeholder( $key );
}

/**
 * Display output: escaped, and wrapped in .jp-placeholder while unfilled.
 */
function jp_info( $key ) {
	$raw = jp_info_raw( $key );
	if ( '' === $raw ) {
		return '';
	}
	if ( jp_info_is_placeholder( $key ) ) {
		return '<span class="jp-placeholder">' . esc_html( $raw ) . '</span>';
	}
	return esc_html( $raw );
}

/**
 * The free-shipping threshold as a float, or 0.0 when the feature is off.
 *
 * Unfilled, non-numeric and zero all collapse to 0.0, and every caller treats
 * 0.0 as "render nothing" - a half-configured threshold must never reach the
 * cart as a bracketed prompt followed by "to free shipping".
 */
function jp_free_shipping_threshold() {
	if ( ! jp_info_has( 'free_shipping_threshold' ) ) {
		return 0.0;
	}
	$raw = preg_replace( '/[^0-9.]/', '', jp_info_raw( 'free_shipping_threshold' ) );
	$val = is_numeric( $raw ) ? (float) $raw : 0.0;
	return $val > 0 ? $val : 0.0;
}
