<?php
/**
 * Paid-traffic landing page at /research.
 *
 * One template, two variants. Everything on it is built from copy that already
 * exists elsewhere on the site - the trust claims, the regulatory statements,
 * the FAQ answers - so the landing page cannot drift into saying something the
 * policy pages do not. Where a value is not known yet it renders as a visible
 * placeholder rather than an invented number.
 *
 * This is a page on the main domain using the site's own theme and tokens. It
 * is deliberately not a microsite.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

const JP_LANDING_SLUG = 'research';

/** True on the landing page itself. */
function jp_is_landing() {
	return is_page( JP_LANDING_SLUG );
}

/**
 * Which variant to render.
 *
 * Query parameter first so a single ad can target either audience without a
 * second page; the page's own setting is the default when no parameter is
 * present. Anything unrecognised falls back to researcher rather than erroring,
 * because this URL is handed to ad platforms that rewrite query strings.
 */
function jp_landing_variant() {
	$allowed = array( 'researcher', 'institutional' );

	if ( isset( $_GET['variant'] ) ) {
		$v = sanitize_key( wp_unslash( $_GET['variant'] ) );
		if ( in_array( $v, $allowed, true ) ) {
			return $v;
		}
	}

	$set = get_post_meta( get_queried_object_id(), '_jp_landing_variant', true );
	return in_array( $set, $allowed, true ) ? $set : 'researcher';
}

/* -------------------------------------------------------------------------
 * UTM capture
 *
 * Stored server-side in a cookie on first arrival and read back when the order
 * is created, so the attribution survives the whole journey: landing -> gate ->
 * catalogue -> account -> checkout. A hidden field on the landing page would
 * not, because the buyer leaves the page long before they pay.
 * ---------------------------------------------------------------------- */

const JP_UTM_COOKIE = 'jp_utm';
const JP_UTM_KEYS   = array( 'utm_source', 'utm_medium', 'utm_campaign', 'utm_term', 'utm_content', 'gclid', 'fbclid' );

add_action( 'template_redirect', function () {
	if ( is_admin() ) {
		return;
	}
	$found = array();
	foreach ( JP_UTM_KEYS as $k ) {
		if ( ! empty( $_GET[ $k ] ) ) {
			$found[ $k ] = sanitize_text_field( wp_unslash( $_GET[ $k ] ) );
		}
	}
	if ( ! $found ) {
		return;
	}
	/* Last touch wins, which is the same rule the ad platforms report on. */
	setcookie(
		JP_UTM_COOKIE,
		wp_json_encode( $found ),
		array(
			'expires'  => time() + 30 * DAY_IN_SECONDS,
			'path'     => COOKIEPATH ? COOKIEPATH : '/',
			'secure'   => is_ssl(),
			'httponly' => true,
			'samesite' => 'Lax',
		)
	);
	$_COOKIE[ JP_UTM_COOKIE ] = wp_json_encode( $found );
}, 1 );

/** The stored attribution, as an array. Empty when the visit was organic. */
function jp_utm_stored() {
	if ( empty( $_COOKIE[ JP_UTM_COOKIE ] ) ) {
		return array();
	}
	$raw = json_decode( sanitize_textarea_field( wp_unslash( $_COOKIE[ JP_UTM_COOKIE ] ) ), true );
	if ( ! is_array( $raw ) ) {
		return array();
	}
	$out = array();
	foreach ( JP_UTM_KEYS as $k ) {
		if ( ! empty( $raw[ $k ] ) ) {
			$out[ $k ] = sanitize_text_field( $raw[ $k ] );
		}
	}
	return $out;
}

/** Carry the attribution onto an internal link, so it is visible in the URL too. */
function jp_utm_link( $url ) {
	$utm = jp_utm_stored();
	return $utm ? add_query_arg( $utm, $url ) : $url;
}

/* Write it onto the order. This is the point of the whole mechanism. */
add_action( 'woocommerce_checkout_create_order', function ( $order ) {
	foreach ( jp_utm_stored() as $k => $v ) {
		$order->update_meta_data( '_jp_' . $k, $v );
	}
}, 10, 1 );

/* And show it on the order screen, or it is data nobody can read. */
add_action( 'woocommerce_admin_order_data_after_billing_address', function ( $order ) {
	$rows = array();
	foreach ( JP_UTM_KEYS as $k ) {
		$v = $order->get_meta( '_jp_' . $k );
		if ( $v ) {
			$rows[] = esc_html( $k ) . ': ' . esc_html( $v );
		}
	}
	if ( $rows ) {
		echo '<p><strong>Traffic source:</strong> ' . implode( '<br>', $rows ) . '</p>';
	}
}, 20 );

/* -------------------------------------------------------------------------
 * Conversion pixel: INSERTION POINT ONLY. Nothing is installed.
 *
 * When a pixel is chosen, hook these. They fire in the document head on the
 * landing page and on the order-received page respectively, and the second one
 * is handed the order so a value can be reported.
 *
 *   add_action( 'jp_landing_head_pixel', function () { ... } );
 *   add_action( 'jp_conversion_pixel', function ( $order ) { ... }, 10, 1 );
 *
 * Nothing third-party is loaded until one of those is hooked, which is what
 * keeps the landing page free of render-blocking external script.
 * ---------------------------------------------------------------------- */

add_action( 'wp_head', function () {
	if ( jp_is_landing() ) {
		/** Fires in <head> on the landing page. No default subscriber. */
		do_action( 'jp_landing_head_pixel' );
	}
}, 5 );

add_action( 'woocommerce_thankyou', function ( $order_id ) {
	$order = wc_get_order( $order_id );
	if ( $order ) {
		/** Fires once on the order-received page. No default subscriber. */
		do_action( 'jp_conversion_pixel', $order );
	}
}, 20 );

/* -------------------------------------------------------------------------
 * Performance
 *
 * This is paid traffic arriving mostly on phones, so the landing page is put
 * on a diet the rest of the site does not need. Measured on the homepage
 * before this: nine render-blocking stylesheets and eight scripts in <head>,
 * almost all of it WooCommerce.
 *
 * The landing page shows products but sells nothing - the tiles are links, not
 * add-to-cart forms - so none of that cart machinery is required. It is
 * dropped rather than deferred, because deferring still costs a request.
 * ---------------------------------------------------------------------- */

add_action( 'wp_enqueue_scripts', function () {
	if ( ! jp_is_landing() ) {
		return;
	}

	foreach ( array(
		'wc-blocks-style', 'wc-blocks-style-mini-cart-contents', 'wc-blocks-packages-style',
		'wc-blocks-style-mini-cart', 'woocommerce-blocktheme', 'woocommerce-layout',
		'woocommerce-smallscreen', 'woocommerce-general', 'wc-blocks-integration',
	) as $h ) {
		wp_dequeue_style( $h );
		wp_deregister_style( $h );
	}

	foreach ( array(
		'wc-add-to-cart', 'woocommerce', 'wc-cart-fragments', 'jquery-blockui',
		'js-cookie', 'wc-single-product', 'wc-blocks-middleware',
	) as $h ) {
		wp_dequeue_script( $h );
	}
}, 99 );

/**
 * Critical CSS inline, the rest asynchronous.
 *
 * The theme stylesheet is ~128KB, far too much to inline, but it is also the
 * only thing standing between the visitor and a styled hero. So the handful of
 * rules the first screen needs are inlined and the full sheet is fetched
 * without blocking the render.
 */
add_filter( 'style_loader_tag', function ( $tag, $handle ) {
	if ( 'joyful-peptides' !== $handle || ! jp_is_landing() ) {
		return $tag;
	}
	/* The print-media swap is the standard non-blocking pattern: the browser
	   fetches at low priority and does not wait for it to paint. noscript keeps
	   the page styled when scripting is off. */
	$async = str_replace( "media='all'", "media='print' onload=\"this.media='all';this.onload=null\"", $tag );
	if ( $async === $tag ) {
		$async = str_replace( '>', " media='print' onload=\"this.media='all';this.onload=null\">", $tag );
	}
	return $async . '<noscript>' . $tag . '</noscript>';
}, 10, 2 );

add_action( 'wp_head', function () {
	if ( ! jp_is_landing() ) {
		return;
	}
	/* Only what the first screen needs: the tokens it references, the RUO bar,
	   the hero and its two buttons. Everything below the fold can arrive with
	   the full sheet. */
	?>
	<style id="jp-landing-critical">
	:root{--jp-paper:#F7F9FB;--jp-ink:#12181F;--jp-muted:#4A5A68;--jp-accent:#076069;--jp-accent-strong:#05474E;
	--jp-ground-glow:#80DEEA;--jp-glow-center:#B2ECF3;--jp-hairline:rgba(7,96,105,.28);--jp-radius-pill:999px;
	--jp-serif:"Fraunces",ui-serif,Georgia,serif;--jp-sans:"Inter",-apple-system,BlinkMacSystemFont,sans-serif;
	--jp-mono:"JetBrains Mono",ui-monospace,Menlo,monospace;--jp-touch-min:46px}
	*{box-sizing:border-box}
	body{margin:0;background:var(--jp-paper);color:var(--jp-ink);font-family:var(--jp-sans);line-height:1.6}
	.jp-lp-ruo{background:var(--jp-ground-glow);background-image:radial-gradient(ellipse 120% 80% at 50% 0%,var(--jp-glow-center) 0%,var(--jp-ground-glow) 65%);
	color:var(--jp-ink);font-family:var(--jp-mono);font-size:.62rem;letter-spacing:.08em;text-transform:uppercase;
	text-align:center;padding:.5rem 1rem;line-height:1.5}
	.jp-lp-brand{display:flex;align-items:center;gap:.6rem;padding:.9rem 1.25rem;border-bottom:1px solid var(--jp-hairline)}
	.jp-lp-brand-name{font-family:var(--jp-serif);font-size:1.05rem;font-weight:580}
	.jp-lp-brand-name em{font-style:italic;color:var(--jp-accent)}
	.jp-lp-hero{padding:1.75rem 1.25rem 2rem}
	.jp-lp-hero h1{font-family:var(--jp-serif);font-weight:580;font-size:clamp(1.75rem,1.379rem + 1.52vw,2.75rem);line-height:1.15;margin:0 0 .6rem;text-wrap:balance}
	.jp-lp-hero h1 em{font-style:italic;color:var(--jp-accent)}
	.jp-lp-sub{font-size:clamp(1.125rem,1.032rem + 0.38vw,1.375rem);color:var(--jp-muted);margin:0 0 1.25rem;max-width:46ch}
	.jp-lp-ctas{display:flex;flex-wrap:wrap;gap:.6rem}
	.jp-lp-btn{display:inline-flex;align-items:center;justify-content:center;box-sizing:border-box;min-height:var(--jp-touch-min);
	padding:.7rem 1.5rem;border-radius:var(--jp-radius-pill);font-weight:600;font-size:.95rem;text-decoration:none;border:1px solid var(--jp-accent)}
	.jp-lp-btn-primary{background:var(--jp-accent);color:#fff}
	.jp-lp-btn-secondary{background:transparent;color:var(--jp-accent)}
	@media(max-width:480px){.jp-lp-btn{flex:1 1 100%}}
	</style>
	<?php
}, 6 );

/* -------------------------------------------------------------------------
 * The page itself. [jp_landing] renders all eight sections in order.
 *
 * Copy discipline: the proof strip, the regulatory block and the FAQ all read
 * from the same functions the rest of the site uses, so none of them can say
 * something the policy pages do not. Nothing here states a purity figure, and
 * the only numbers on the page are prices and whatever a real batch record
 * carries.
 * ---------------------------------------------------------------------- */

add_shortcode( 'jp_landing', function () {
	$variant = jp_landing_variant();
	$is_inst = ( 'institutional' === $variant );

	$catalog_url = jp_utm_link( $is_inst ? '/bulk-orders/' : '/shop/' );
	$coa_url     = jp_utm_link( '/coa-library/' );

	ob_start();
	?>
	<div class="jp-lp" data-variant="<?php echo esc_attr( $variant ); ?>">

	<?php /* 1. HERO ------------------------------------------------------ */ ?>
	<section class="jp-lp-hero">
		<?php if ( $is_inst ) : ?>
			<h1>Buy by the lot, with the <em>paperwork</em>.</h1>
			<p class="jp-lp-sub">Volume orders, reserved lots and documentation packages &mdash; every batch tied to an independent laboratory report you can file.</p>
		<?php else : ?>
			<h1>Peptides you can <em>verify.</em></h1>
			<p class="jp-lp-sub">Every batch is tested by an independent laboratory before release, and the certificate is published against the batch number on the vial.</p>
		<?php endif; ?>
		<div class="jp-lp-ctas">
			<a class="jp-lp-btn jp-lp-btn-primary" href="<?php echo esc_url( $catalog_url ); ?>"><?php echo $is_inst ? 'Talk to us about volume' : 'Browse the catalog'; ?></a>
			<a class="jp-lp-btn jp-lp-btn-secondary" href="<?php echo esc_url( $coa_url ); ?>">See a real COA</a>
		</div>
	</section>

	<?php /* 2. PROOF STRIP - the site's own approved claims, not new ones */ ?>
	<section class="jp-lp-proof">
		<ul class="jp-lp-proof-list">
			<?php
			$shown = 0;
			foreach ( jp_trust_claims() as $claim ) {
				if ( empty( $claim['on'] ) || $shown >= 3 ) {
					continue;
				}
				$shown++;
				?>
				<li>
					<?php /* Not wp_kses_post(): it strips <svg> outright, and the icon
					         vanished silently when it was used here. The markup is
					         built by jp_line_icon() from a fixed internal list - no
					         user input reaches it - which is why the trust bar prints
					         it the same way. */ ?>
					<span class="jp-lp-proof-icon" aria-hidden="true"><?php echo $claim['icon']; // phpcs:ignore WordPress.Security.EscapeOutput ?></span>
					<strong><?php echo esc_html( $claim['title'] ); ?></strong>
					<span><?php echo esc_html( $claim['copy'] ); ?></span>
				</li>
				<?php
			}
			?>
		</ul>
	</section>

	<?php /* 3. HOW VERIFICATION WORKS - the whole pitch ------------------ */ ?>
	<section class="jp-lp-section">
		<p class="jp-lp-kicker">01 &mdash; Verification</p>
		<h2>How verification works</h2>
		<ol class="jp-lp-steps">
			<li>
				<span class="jp-lp-step-n">1</span>
				<h3>An outside laboratory tests the batch</h3>
				<p>Testing happens before release, at a laboratory we do not own. A batch that fails is not shipped.</p>
			</li>
			<li>
				<span class="jp-lp-step-n">2</span>
				<h3>The certificate is published against the lot</h3>
				<p>Each report is posted to the public COA library and tied to the batch number, including the batches that failed.</p>
			</li>
			<li>
				<span class="jp-lp-step-n">3</span>
				<h3>You match the vial to the record</h3>
				<p>The batch number printed on your vial is the one you look up. If the two do not match, something is wrong.</p>
			</li>
		</ol>

		<?php if ( ! $is_inst ) : ?>
			<div class="jp-lp-note">
				<h3>What a certificate does and does not tell you</h3>
				<p><strong>It reports</strong> identity and purity for the sample the laboratory tested, from that lot, on that date, by the stated method.</p>
				<p><strong>It does not</strong> establish that a compound is safe, effective, or suitable for any particular use, and it says nothing about any lot other than its own. It is a test result, not an endorsement.</p>
			</div>
		<?php else : ?>
			<div class="jp-lp-note">
				<h3>What comes with a volume order</h3>
				<p>Documentation package per lot, lot reservation while an order is agreed, and account terms set case by case.</p>
				<ul class="jp-lp-terms">
					<li><strong>Minimum order</strong> <?php echo jp_info( 'bulk_moq' ); ?></li>
					<li><strong>Lead time</strong> <?php echo jp_info( 'bulk_lead_time' ); ?></li>
					<li><strong>Lot hold</strong> <?php echo jp_info( 'bulk_lot_hold' ); ?></li>
				</ul>
			</div>
		<?php endif; ?>
	</section>

	<?php /* 4. THE ARTIFACT ---------------------------------------------- */ ?>
	<section class="jp-lp-section jp-lp-coa">
		<p class="jp-lp-kicker">02 &mdash; The record</p>
		<h2>The COA library</h2>
		<p class="jp-lp-lead">Every batch we have released, and every batch we have not. Look up any lot by its number.</p>
		<?php echo jp_landing_coa_preview(); ?>
		<p class="jp-lp-more"><a href="<?php echo esc_url( $coa_url ); ?>">Open the full COA library</a></p>
	</section>

	<?php /* 5. CATALOG PREVIEW ------------------------------------------- */ ?>
	<section class="jp-lp-section">
		<p class="jp-lp-kicker">03 &mdash; Catalog</p>
		<h2>A sample of the catalog</h2>
		<div class="jp-lp-grid">
			<?php
			$products = function_exists( 'jp_featured_products' ) ? jp_featured_products( 4 ) : array();
			foreach ( $products as $product ) {
				jp_render_product_tile( $product );
			}
			?>
		</div>
		<p class="jp-lp-more"><a href="<?php echo esc_url( jp_utm_link( '/shop/' ) ); ?>">See all products</a></p>
	</section>

	<?php /* 6. RUO AND WHO WE SELL TO -------------------------------------- */ ?>
	<section class="jp-lp-section jp-lp-ruo-block">
		<p class="jp-lp-kicker">04 &mdash; Terms of sale</p>
		<h2>Research use only</h2>
		<?php
		$reg = jp_regulatory_statements();
		?>
		<p class="jp-lp-ruo-lead"><?php echo esc_html( $reg['ruo'] ); ?></p>
		<p><?php echo esc_html( $reg['scope'] ); ?></p>
		<p><?php echo esc_html( $reg['supplier'] ); ?></p>
		<p class="jp-lp-fine"><?php echo esc_html( $reg['fda'] ); ?></p>
		<p class="jp-lp-fine">Sold to buyers aged 21 or over who are equipped to handle research chemicals. Ordering requires an account and a per-order attestation; every shipment goes out Adult Signature Required. US shipping only.</p>
	</section>

	<?php /* 7. FAQ - the questions that block a purchase ------------------ */ ?>
	<section class="jp-lp-section">
		<p class="jp-lp-kicker">05 &mdash; Questions</p>
		<h2>Before you order</h2>
		<div class="jp-lp-faq">
			<?php
			/* Answers are the FAQ page's own, verbatim, so the two cannot drift. */
			$faq = array(
				array( 'What does &ldquo;research use only&rdquo; mean?', 'All products sold on this site are intended strictly for laboratory and research use. They are not for human consumption, and are not sold, marketed, or intended for any diagnostic or therapeutic purpose. By purchasing, you attest that you are acquiring products solely for research use.' ),
				array( 'What is a COA, and how do I check one?', 'A certificate of analysis (COA) is an independent lab&rsquo;s report on a batch&rsquo;s purity and identity. You can look up any batch&rsquo;s COA in our COA library using the batch number printed on your product.' ),
				array( 'Why do I need an account to order?', 'Every order requires a research account and a signed research-use attestation. This is how we record that each order was placed for in vitro research use by an eligible buyer.' ),
				array( 'Do you ship internationally?', 'We currently ship within the United States only. All orders ship with Adult Signature Required, and the recipient name must match the name on the account.' ),
				array( 'Do you provide dosing or research protocol guidance?', 'No. We do not provide dosing, protocol, or usage guidance of any kind. For your research design, consult the peptide&rsquo;s COA and independent scientific literature.' ),
			);
			foreach ( $faq as $row ) {
				echo '<details><summary>' . wp_kses_post( $row[0] ) . '</summary><p>' . wp_kses_post( $row[1] ) . '</p></details>';
			}
			?>
		</div>
	</section>

	<?php /* 8. FINAL CTA --------------------------------------------------- */ ?>
	<section class="jp-lp-final">
		<h2><?php echo $is_inst ? 'Start a volume conversation' : 'Start with a batch record'; ?></h2>
		<p><?php echo $is_inst
			? 'Tell us the compound, the quantity and the timeline, and we will come back with what we can hold and when.'
			: 'Open any product, read its lot report, then decide.'; ?></p>
		<div class="jp-lp-ctas">
			<a class="jp-lp-btn jp-lp-btn-primary" href="<?php echo esc_url( $catalog_url ); ?>"><?php echo $is_inst ? 'Talk to us about volume' : 'Browse the catalog'; ?></a>
			<a class="jp-lp-btn jp-lp-btn-secondary" href="<?php echo esc_url( $coa_url ); ?>">See a real COA</a>
		</div>
	</section>

	</div>
	<?php
	return ob_get_clean();
} );

/**
 * COA preview for the landing page.
 *
 * Deliberately NOT [jp_coa_library]. That shortcode lists every batch row and
 * prints whatever purity value the record carries, whether or not a
 * certificate exists behind it - which on a paid-traffic page would put a
 * figure like "91.2%" in front of a buyer with nothing to check it against.
 * Two of the batch records in this install are sample data with no COA file,
 * and that is exactly what happened when the shortcode was used here.
 *
 * So this renderer inverts the rule: a row can only appear if it has a real
 * certificate to link to, and the purity column is read from the same record
 * that supplies the link. No certificate, no number, no row. When nothing
 * qualifies it says so plainly rather than showing an empty table.
 *
 * It also ignores the limit attribute problem: [jp_coa_library] takes no
 * attributes at all, so the "limit" passed to it earlier was silently doing
 * nothing.
 */
function jp_landing_coa_preview( $limit = 4 ) {
	$batches = get_posts( array(
		'post_type'      => 'jp_batch',
		'post_status'    => 'publish',
		'posts_per_page' => -1,
		'orderby'        => 'date',
		'order'          => 'DESC',
	) );

	$rows = array();
	foreach ( $batches as $batch ) {
		$coa = get_post_meta( $batch->ID, '_jp_coa_url', true );
		if ( ! $coa ) {
			continue; /* No certificate: nothing about this lot is shown here. */
		}
		$rows[] = array(
			'batch'   => $batch->post_title,
			'product' => get_post_meta( $batch->ID, '_jp_product_name', true ),
			'date'    => get_post_meta( $batch->ID, '_jp_test_date', true ),
			'lab'     => get_post_meta( $batch->ID, '_jp_lab_name', true ),
			'result'  => get_post_meta( $batch->ID, '_jp_result', true ),
			'purity'  => get_post_meta( $batch->ID, '_jp_purity_result', true ),
			'coa'     => $coa,
		);
		if ( count( $rows ) >= $limit ) {
			break;
		}
	}

	ob_start();

	if ( ! $rows ) {
		/* Honest empty state. The pitch of this page is that the record exists
		   and is public, so an invented row would undo the argument it is
		   making. */
		echo '<p class="jp-lp-coa-empty">No published certificates yet. Every batch we release is posted here with its lab report attached, and the batches that fail stay on the record too.</p>';
	} else {
		echo '<div class="jp-lp-coa-wrap"><table class="jp-lp-coa-table">';
		echo '<thead><tr><th scope="col">Batch</th><th scope="col">Product</th><th scope="col">Tested</th><th scope="col">Result</th><th scope="col">Report</th></tr></thead><tbody>';
		foreach ( $rows as $r ) {
			echo '<tr>';
			echo '<td><strong>' . esc_html( $r['batch'] ) . '</strong></td>';
			echo '<td>' . esc_html( $r['product'] ) . '</td>';
			echo '<td>' . esc_html( $r['date'] ) . '</td>';
			echo '<td>' . esc_html( ucfirst( $r['result'] ? $r['result'] : 'pending' ) );
			if ( $r['purity'] ) {
				/* Only reachable when a certificate is attached, because a row
				   without one never got this far. */
				echo ' <span class="jp-lp-coa-purity">' . esc_html( $r['purity'] ) . '</span>';
			}
			echo '</td>';
			echo '<td><a href="' . esc_url( $r['coa'] ) . '" target="_blank" rel="noopener">Open COA</a></td>';
			echo '</tr>';
		}
		echo '</tbody></table></div>';
	}

	return ob_get_clean();
}
