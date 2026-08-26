<?php
/**
 * Joyful Peptides theme setup.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

function joyful_peptides_setup() {
	add_theme_support( 'post-thumbnails' );
	add_theme_support( 'responsive-embeds' );
	add_theme_support( 'editor-styles' );
	add_editor_style( 'style.css' );
}
add_action( 'after_setup_theme', 'joyful_peptides_setup' );

function joyful_peptides_styles() {
	wp_enqueue_style( 'joyful-peptides', get_stylesheet_uri(), array(), '2.0.0' );
}
add_action( 'wp_enqueue_scripts', 'joyful_peptides_styles' );

/**
 * SVG favicon (copper hexagon mark) + font preloads for fast first paint.
 */
function joyful_peptides_head_extras() {
	echo '<link rel="icon" type="image/svg+xml" href="data:image/svg+xml,%3Csvg xmlns=%22http://www.w3.org/2000/svg%22 viewBox=%220 0 32 32%22%3E%3Cpath d=%22M16 2 L28 9 L28 23 L16 30 L4 23 L4 9 Z%22 fill=%22%23B5651D%22/%3E%3Ccircle cx=%2216%22 cy=%2216%22 r=%225%22 fill=%22%23FAF9F6%22/%3E%3C/svg%3E">' . "\n";
	foreach ( array( 'fraunces-var.woff2', 'inter-var.woff2', 'jetbrains-mono-var.woff2' ) as $font ) {
		echo '<link rel="preload" href="' . esc_url( get_theme_file_uri( 'assets/fonts/' . $font ) ) . '" as="font" type="font/woff2" crossorigin>' . "\n";
	}
}
add_action( 'wp_head', 'joyful_peptides_head_extras', 1 );

/**
 * Branded product-image placeholder (replaces WooCommerce's gray default
 * until real photography is uploaded).
 */
function joyful_peptides_wc_placeholder( $src ) {
	return get_theme_file_uri( 'assets/img/placeholder-product.svg' );
}
add_filter( 'woocommerce_placeholder_img_src', 'joyful_peptides_wc_placeholder' );

/**
 * Sticky add-to-cart bar shown once the main buy button scrolls out of view.
 */
function joyful_peptides_buybar() {
	if ( ! function_exists( 'is_product' ) || ! is_product() ) {
		return;
	}
	global $product;
	if ( ! $product instanceof WC_Product || ! $product->is_purchasable() || ! $product->is_in_stock() ) {
		return;
	}
	$meta = array_filter( array( $product->get_meta( '_jp_size_mg' ), $product->get_meta( '_jp_batch_number' ) ) );
	?>
	<div class="jp-buybar" id="jp-buybar" aria-hidden="true">
		<div class="jp-buybar-info">
			<span class="jp-buybar-name"><?php echo esc_html( $product->get_name() ); ?></span>
			<?php if ( $meta ) : ?>
				<span class="jp-buybar-meta"><?php echo implode( ' &middot; ', array_map( 'esc_html', $meta ) ); ?></span>
			<?php endif; ?>
		</div>
		<div class="jp-buybar-right">
			<span class="jp-buybar-price"><?php echo wp_kses_post( $product->get_price_html() ); ?></span>
			<a class="jp-buybar-btn" href="#jp-buy" id="jp-buybar-btn">Add to cart</a>
		</div>
	</div>
	<?php
}
add_action( 'wp_footer', 'joyful_peptides_buybar' );

/**
 * Reading-progress bar (articles) and back-to-top button.
 */
function joyful_peptides_chrome() {
	echo '<a class="jp-skip" href="#jp-main">Skip to content</a>';
	if ( is_singular( 'post' ) ) {
		echo '<div class="jp-progress" aria-hidden="true"><span id="jp-progress-bar"></span></div>';
	}
	echo '<button type="button" class="jp-top" id="jp-top" aria-label="Back to top">&#8593;</button>';
}
add_action( 'wp_body_open', 'joyful_peptides_chrome' );

/**
 * Scroll-reveal for page sections + buy-bar visibility. Progressive
 * enhancement only: without JS everything stays visible and usable.
 */
function joyful_peptides_scripts() {
	?>
	<script>
	(function () {
		var reduce = window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches;

		/* Reading progress + back-to-top */
		var bar = document.getElementById('jp-progress-bar');
		var top = document.getElementById('jp-top');
		function onScroll() {
			var y = window.scrollY || document.documentElement.scrollTop;
			if (bar) {
				var h = document.documentElement.scrollHeight - window.innerHeight;
				bar.style.width = (h > 0 ? Math.min(100, (y / h) * 100) : 0) + '%';
			}
			if (top) { top.classList.toggle('jp-top-in', y > 700 && !nearFooter()); }
		}

		/* Hide the button once the footer/legal band is on screen, so it never
		   sits on top of the disclaimer or the policy links. Measured per
		   scroll rather than via IntersectionObserver: the observer's first
		   callback races page layout, which left the button stuck off. */
		var footZone = document.querySelector('.jp-regulatory') || document.querySelector('.jp-footer');
		function nearFooter() {
			if (!footZone) { return false; }
			return footZone.getBoundingClientRect().top < window.innerHeight - 8;
		}
		window.addEventListener('scroll', onScroll, { passive: true });
		onScroll();
		if (top) {
			top.addEventListener('click', function () {
				window.scrollTo({ top: 0, behavior: reduce ? 'auto' : 'smooth' });
			});
		}

		/* Reveal sections on scroll */
		if (!reduce && 'IntersectionObserver' in window) {
			var targets = document.querySelectorAll('main > section, main > .wp-block-group, .jp-manifesto, .jp-cta-band');
			var seen = new IntersectionObserver(function (entries) {
				entries.forEach(function (e) {
					if (e.isIntersecting) { e.target.classList.add('jp-in'); seen.unobserve(e.target); }
				});
			}, { rootMargin: '0px 0px -8% 0px', threshold: 0.06 });
			targets.forEach(function (el, i) {
				if (i === 0) { return; }           /* never hide the hero */
				el.classList.add('jp-reveal');
				seen.observe(el);
			});
		}

		/* Category shelves: arrow buttons + disable at either end */
		document.querySelectorAll('.jp-shelf').forEach(function (shelf) {
			var track = shelf.querySelector('.jp-shelf-track');
			var btns = shelf.querySelectorAll('.jp-shelf-btn');
			if (!track || !btns.length) { return; }
			function sync() {
				var max = track.scrollWidth - track.clientWidth - 2;
				btns[0].disabled = track.scrollLeft <= 2;
				btns[1].disabled = track.scrollLeft >= max;
			}
			btns.forEach(function (b) {
				b.addEventListener('click', function () {
					var step = Math.max(track.clientWidth * 0.8, 200);
					track.scrollBy({ left: step * parseInt(b.dataset.dir, 10), behavior: reduce ? 'auto' : 'smooth' });
				});
			});
			track.addEventListener('scroll', sync, { passive: true });
			window.addEventListener('resize', sync);
			sync();
		});

		/* Sticky buy bar: show once the real add-to-cart scrolls away */
		var bar = document.getElementById('jp-buybar');
		var form = document.querySelector('.woocommerce div.product form.cart');
		if (bar && form) {
			if (!document.getElementById('jp-buy')) { form.id = 'jp-buy'; }
			var realBtn = form.querySelector('button[type="submit"], .single_add_to_cart_button');
			var barBtn = document.getElementById('jp-buybar-btn');
            if (barBtn && realBtn) {
				barBtn.addEventListener('click', function (ev) {
					ev.preventDefault();
					form.scrollIntoView({ behavior: reduce ? 'auto' : 'smooth', block: 'center' });
					realBtn.classList.add('jp-flash');
				});
			}
			var watcher = new IntersectionObserver(function (entries) {
				entries.forEach(function (e) {
					bar.classList.toggle('jp-buybar-in', !e.isIntersecting);
					bar.setAttribute('aria-hidden', e.isIntersecting ? 'true' : 'false');
				});
			}, { threshold: 0 });
			watcher.observe(form);
		}
	})();

	/* Measure the sticky header so the pill bar can sit exactly beneath it.
	   Set before first paint of the sticky state and kept in sync on resize,
	   so the bar never overlaps the nav and never leaves a gap. */
	(function () {
		var header = document.querySelector('header.jp-header');
		if (!header) { return; }
		var apply = function () {
			document.documentElement.style.setProperty(
				'--jp-header-h', Math.round(header.getBoundingClientRect().height) + 'px'
			);
		};
		apply();
		window.addEventListener('resize', apply);
		if ('ResizeObserver' in window) { new ResizeObserver(apply).observe(header); }
	})();
	</script>
	<?php
}
add_action( 'wp_footer', 'joyful_peptides_scripts', 100 );
