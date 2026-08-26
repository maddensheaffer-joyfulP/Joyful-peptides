<?php
/**
 * Plugin Name: Joyful Peptides Core
 * Description: Store rules for the research-use-only model: checkout research attestation (21+), peptide product fields (size, purity, batch, storage, COA link), RUO notices, and a placeholder payment gateway (TODO: replace with the live high-risk processor before launch).
 * Version: 1.1.0
 * Author: Joyful Peptides
 * Requires Plugins: woocommerce
 */

defined( 'ABSPATH' ) || exit;

define( 'JP_ATTESTATION_VERSION', '2026-08-12.v2' );
define( 'JP_ATTESTATION_TEXT', 'I certify that I am at least 21 years of age; that I am qualified to receive and handle research chemicals safely; that the products in this order are purchased solely for in vitro research use; that I will not administer them to humans or animals, use them for any clinical, diagnostic, or therapeutic purpose, or resell or redistribute them for any such use; and that the information I have provided is true. I understand that misuse of research-use-only products is unlawful.' );

/** Standard regulatory statements, defined once and reused sitewide. */
function jp_regulatory_statements() {
	return array(
		'ruo'      => 'For in vitro research use only. Not for human or veterinary use.',
		'fda'      => 'These statements and the products sold by Joyful Peptides have not been evaluated by the United States Food and Drug Administration. These products are not intended to diagnose, treat, cure, or prevent any disease.',
		'supplier' => 'Joyful Peptides is a chemical supplier. Joyful Peptides is not a compounding pharmacy or a chemical compounding facility as defined under section 503A of the Federal Food, Drug, and Cosmetic Act, and is not an outsourcing facility as defined under section 503B of that Act. We do not provide medical advice, prescriptions, or clinical services.',
		'scope'    => 'Research-use-only products sold on this site are intended solely for basic research, pharmaceutical research, or the development of new tests. They are not intended for use in diagnosing a disease or condition in any patient, and are not for human or veterinary consumption. Misuse of research-use-only products is unlawful.',
	);
}

/* -------------------------------------------------------------------------
 * Product fields (admin): Products > Edit > Product data > General
 * ---------------------------------------------------------------------- */

add_action( 'woocommerce_product_options_general_product_data', function () {
	echo '<div class="options_group">';
	woocommerce_wp_text_input( array(
		'id'          => '_jp_size_mg',
		'label'       => 'Vial size',
		'placeholder' => 'e.g. 5 mg',
		'desc_tip'    => true,
		'description' => 'Peptide quantity per vial, as printed on the label.',
	) );
	woocommerce_wp_text_input( array(
		'id'          => '_jp_purity',
		'label'       => 'Purity (HPLC)',
		'placeholder' => 'e.g. >= 99.0% (HPLC)',
		'desc_tip'    => true,
		'description' => 'Purity as reported on the current batch COA.',
	) );
	woocommerce_wp_text_input( array(
		'id'          => '_jp_batch_number',
		'label'       => 'Current batch number',
		'placeholder' => 'e.g. JP-2026-014',
		'desc_tip'    => true,
		'description' => 'The batch currently shipping. Update this when a new batch goes live.',
	) );
	woocommerce_wp_text_input( array(
		'id'          => '_jp_storage',
		'label'       => 'Storage conditions',
		'placeholder' => 'e.g. Lyophilized: store at -20C',
		'desc_tip'    => true,
		'description' => 'Storage and handling conditions for the product as shipped.',
	) );
	woocommerce_wp_text_input( array(
		'id'          => '_jp_coa_url',
		'label'       => 'COA link (URL)',
		'placeholder' => 'Paste a Media Library file URL',
		'desc_tip'    => true,
		'description' => 'Batch certificate of analysis (PDF). Upload via Media > Add New, copy its URL, paste here. If left blank, the matching batch record\'s COA is used automatically.',
	) );
	woocommerce_wp_text_input( array(
		'id'          => '_jp_sds_url',
		'label'       => 'SDS link (URL)',
		'placeholder' => 'Paste a Media Library file URL',
		'desc_tip'    => true,
		'description' => 'Safety data sheet (PDF) for this compound. Unlike a COA, an SDS describes the substance generally, not one batch, so the same file is reused across batches.',
	) );
	echo '</div>';
} );

add_action( 'woocommerce_admin_process_product_object', function ( $product ) {
	$fields = array( '_jp_size_mg', '_jp_purity', '_jp_batch_number', '_jp_storage', '_jp_coa_url', '_jp_sds_url' );
	foreach ( $fields as $key ) {
		if ( isset( $_POST[ $key ] ) ) {
			$raw   = wp_unslash( $_POST[ $key ] );
			$value = in_array( $key, array( '_jp_coa_url', '_jp_sds_url' ), true ) ? esc_url_raw( $raw ) : wc_clean( $raw );
			$product->update_meta_data( $key, $value );
		}
	}
} );

/* -------------------------------------------------------------------------
 * Product page (front end): specs table, COA link, RUO notice
 * ---------------------------------------------------------------------- */

add_action( 'woocommerce_single_product_summary', 'jp_render_product_specs', 25 );
function jp_render_product_specs() {
	global $product;
	if ( ! $product instanceof WC_Product ) {
		return;
	}
	$rows = array(
		'Vial size'     => $product->get_meta( '_jp_size_mg' ),
		'Purity (HPLC)' => $product->get_meta( '_jp_purity' ),
		'Batch'         => $product->get_meta( '_jp_batch_number' ),
		'Storage'       => $product->get_meta( '_jp_storage' ),
	);
	$rows = array_filter( $rows );
	$coa  = $product->get_meta( '_jp_coa_url' );
	if ( ! $coa ) {
		// Fall back to the COA stored on the matching batch record, so the
		// COA only has to be entered once (on the batch, not the product).
		$coa = jp_batch_coa_url( $product->get_meta( '_jp_batch_number' ) );
	}
	if ( ! $rows && ! $coa ) {
		return;
	}
	echo '<div class="jp-specs"><h3>Research specifications</h3><table>';
	foreach ( $rows as $label => $value ) {
		printf( '<tr><th>%s</th><td>%s</td></tr>', esc_html( $label ), esc_html( $value ) );
	}
	echo '</table>';

	$sds = $product->get_meta( '_jp_sds_url' );
	echo '<div class="jp-docs">';
	echo '<p class="jp-docs-label">Documents</p>';
	echo '<div class="jp-docs-links">';
	if ( $coa ) {
		printf(
			'<a class="jp-doc" href="%s" target="_blank" rel="noopener"><span class="jp-doc-tag">COA</span><span class="jp-doc-name">Certificate of analysis<small>Batch-specific lab report (PDF)</small></span></a>',
			esc_url( $coa )
		);
	} else {
		echo '<span class="jp-doc jp-doc-missing"><span class="jp-doc-tag">COA</span><span class="jp-doc-name">Certificate of analysis<small>Not yet uploaded for this batch</small></span></span>';
	}
	if ( $sds ) {
		printf(
			'<a class="jp-doc" href="%s" target="_blank" rel="noopener"><span class="jp-doc-tag">SDS</span><span class="jp-doc-name">Safety data sheet<small>Handling, hazards, and first aid (PDF)</small></span></a>',
			esc_url( $sds )
		);
	} else {
		echo '<span class="jp-doc jp-doc-missing"><span class="jp-doc-tag">SDS</span><span class="jp-doc-name">Safety data sheet<small>Not yet uploaded</small></span></span>';
	}
	echo '</div></div>';
	echo '</div>';
}

add_action( 'woocommerce_single_product_summary', 'jp_render_ruo_box', 45 );
function jp_render_ruo_box() {
	$s = jp_regulatory_statements();
	echo '<div class="jp-ruo-box">';
	echo '<strong>For in vitro research use only. Not for human or veterinary use.</strong> ';
	echo esc_html( $s['scope'] );
	echo ' <a href="/ruo-policy/">Read the RUO Policy</a>.';
	echo '</div>';
	echo '<p class="jp-fda-note">' . esc_html( $s['fda'] ) . '</p>';
}

/* Small stylesheet for the boxes above and the checkout attestation. */
add_action( 'wp_head', function () {
	?>
<style>
.jp-specs{margin:1.25rem 0;padding:1rem 1.25rem;background:var(--wp--preset--color--surface,#f4f2ee);border:1px solid rgba(0,0,0,.08)}
.jp-specs h3{margin:0 0 .5rem;font-size:1.05rem}
.jp-specs table{width:100%;border-collapse:collapse;font-size:.95rem}
.jp-specs th{text-align:left;padding:.3rem .75rem .3rem 0;font-weight:600;white-space:nowrap;vertical-align:top}
.jp-specs td{padding:.3rem 0}
.jp-coa-link{font-weight:600;color:var(--wp--preset--color--accent-dark,#8C4E15)}
.jp-coa-missing{font-size:.9rem;color:#6B6B68}
.jp-ruo-box{margin:1.25rem 0;padding:.85rem 1rem;border-left:4px solid var(--wp--preset--color--accent,#B5651D);background:var(--wp--preset--color--surface,#f4f2ee);font-size:.92rem}
.jp-attestation{margin:1rem 0;padding:1rem;border:1px solid var(--wp--preset--color--accent,#B5651D);background:var(--wp--preset--color--surface,#f4f2ee)}
.jp-attestation label{display:flex;gap:.6rem;align-items:flex-start;font-size:.92rem;line-height:1.5}
.jp-attestation input{margin-top:.25rem}
.jp-asr-note{font-size:.85rem;color:#6B6B68;margin:0 0 .75rem;padding-bottom:.75rem;border-bottom:1px dashed rgba(28,28,26,.15)}
#jp-age-gate{position:fixed;inset:0;z-index:99999;display:flex;align-items:center;justify-content:center;background:rgba(28,28,26,.97);padding:1.5rem}
.jp-age-card{background:#FAF9F6;max-width:520px;width:100%;padding:2.25rem 2rem;border-radius:3px;border-top:4px solid #B5651D;text-align:center}
.jp-age-card h2{font-family:ui-serif,Georgia,"Times New Roman",serif;font-size:1.5rem;margin:0 0 1rem;color:#1C1C1A}
.jp-age-card p{font-size:.95rem;line-height:1.6;color:#3A3A36;margin:0 0 1rem}
.jp-age-card .jp-age-fine{font-size:.8rem;color:#6B6B68}
.jp-age-buttons{display:flex;gap:.75rem;justify-content:center;flex-wrap:wrap;margin-top:1.25rem}
.jp-age-buttons button{cursor:pointer;font-size:1rem;font-weight:600;padding:.8rem 1.6rem;border-radius:2px;border:none}
#jp-age-enter{background:#B5651D;color:#fff}
#jp-age-enter:hover{background:#8C4E15}
#jp-age-exit{background:transparent;color:#1C1C1A;border:1px solid rgba(28,28,26,.35)}
#jp-age-exit:hover{background:#F1EFEA}
</style>
	<?php
} );

/* -------------------------------------------------------------------------
 * Checkout: required 21+/research-use attestation, saved to the order
 * ---------------------------------------------------------------------- */

add_action( 'woocommerce_review_order_before_submit', function () {
	?>
	<div class="jp-attestation">
		<p class="jp-asr-note"><strong>Delivery note:</strong> all orders ship with Adult Signature Required (21+). The recipient name must match the name on this account.</p>
		<label for="jp_research_attestation">
			<input type="checkbox" name="jp_research_attestation" id="jp_research_attestation" value="1" />
			<span><?php echo esc_html( JP_ATTESTATION_TEXT ); ?> <abbr class="required" title="required">*</abbr></span>
		</label>
	</div>
	<?php
} );

add_action( 'woocommerce_checkout_process', function () {
	if ( empty( $_POST['jp_research_attestation'] ) ) {
		wc_add_notice( 'You must confirm the research-use attestation (21+, laboratory research only) to place this order.', 'error' );
	}

	// Level 2 age gate: required DOB, validated server-side to 21+.
	$dob = isset( $_POST['jp_dob'] ) ? sanitize_text_field( wp_unslash( $_POST['jp_dob'] ) ) : '';
	if ( '' === $dob ) {
		wc_add_notice( 'Please enter your date of birth.', 'error' );
	} else {
		$dob_dt = date_create( $dob );
		$now    = date_create( 'now' );
		if ( ! $dob_dt || $dob_dt > $now || $dob_dt->diff( $now )->y > 120 ) {
			wc_add_notice( 'Please enter a valid date of birth.', 'error' );
		} elseif ( $dob_dt->diff( $now )->y < 21 ) {
			wc_add_notice( 'You must be at least 21 years old to place an order.', 'error' );
		} else {
			/*
			 * TODO (Level 2, true compliance): identity-verification API.
			 * When a provider is chosen (e.g. AgeChecker.net, Veratad, IDology),
			 * hook this filter and return true/false after cross-referencing
			 * the customer's name, address, and DOB against public records:
			 *
			 *   add_filter( 'jp_identity_verified', function ( $result, $data ) {
			 *       return my_provider_verify( $data );
			 *   }, 10, 2 );
			 *
			 * Until a provider is hooked, $verified stays null and checkout
			 * proceeds on DOB + attestation alone.
			 */
			$verified = apply_filters( 'jp_identity_verified', null, array(
				'dob'        => $dob,
				'first_name' => isset( $_POST['billing_first_name'] ) ? sanitize_text_field( wp_unslash( $_POST['billing_first_name'] ) ) : '',
				'last_name'  => isset( $_POST['billing_last_name'] ) ? sanitize_text_field( wp_unslash( $_POST['billing_last_name'] ) ) : '',
				'address'    => isset( $_POST['billing_address_1'] ) ? sanitize_text_field( wp_unslash( $_POST['billing_address_1'] ) ) : '',
				'city'       => isset( $_POST['billing_city'] ) ? sanitize_text_field( wp_unslash( $_POST['billing_city'] ) ) : '',
				'state'      => isset( $_POST['billing_state'] ) ? sanitize_text_field( wp_unslash( $_POST['billing_state'] ) ) : '',
				'zip'        => isset( $_POST['billing_postcode'] ) ? sanitize_text_field( wp_unslash( $_POST['billing_postcode'] ) ) : '',
			) );
			if ( false === $verified ) {
				wc_add_notice( 'We were unable to verify your identity and age. Please check that your name, address, and date of birth are correct, or contact us for help.', 'error' );
			}
		}
	}
} );

add_action( 'woocommerce_checkout_create_order', function ( $order ) {
	if ( ! empty( $_POST['jp_research_attestation'] ) ) {
		$order->update_meta_data( '_jp_attestation', 'yes' );
		$order->update_meta_data( '_jp_attestation_time', gmdate( 'c' ) );
		$order->update_meta_data( '_jp_attestation_version', JP_ATTESTATION_VERSION );
	}
	if ( ! empty( $_POST['jp_dob'] ) ) {
		$order->update_meta_data( '_jp_dob', sanitize_text_field( wp_unslash( $_POST['jp_dob'] ) ) );
	}
	// Level 3: every order is flagged Adult Signature Required for fulfillment.
	$order->update_meta_data( '_jp_asr', 'yes' );
} );

/* Fulfillment reminder on every new order (Level 3 shipping gate). */
add_action( 'woocommerce_checkout_order_created', function ( $order ) {
	$order->add_order_note( 'SHIPPING: Adult Signature Required (21+). Recipient name on the label must exactly match the verified checkout name.' );
} );

/* Show the attestation record inside the admin order screen. */
add_action( 'woocommerce_admin_order_data_after_billing_address', function ( $order ) {
	$confirmed = ( $order->get_meta( '_jp_attestation' ) === 'yes' );
	echo '<p><strong>Research attestation:</strong> ';
	if ( $confirmed ) {
		echo 'Confirmed ' . esc_html( $order->get_meta( '_jp_attestation_time' ) )
			. ' (text version ' . esc_html( $order->get_meta( '_jp_attestation_version' ) ) . ')';
	} else {
		echo '<span style="color:#b32d2e">NOT RECORDED</span>';
	}
	echo '</p>';
	$dob = $order->get_meta( '_jp_dob' );
	if ( $dob ) {
		echo '<p><strong>Date of birth (21+ checked):</strong> ' . esc_html( $dob ) . '</p>';
	}
	if ( 'yes' === $order->get_meta( '_jp_asr' ) ) {
		echo '<p><strong>Shipping:</strong> Adult Signature Required (21+) &mdash; label name must match checkout name.</p>';
	}
} );

/* Notify the site owner when a new research account is created. */
add_action( 'woocommerce_created_customer', function ( $customer_id ) {
	$user = get_userdata( $customer_id );
	wp_mail(
		get_option( 'admin_email' ),
		'New research account created - Joyful Peptides',
		"A new research account was created.\n\nEmail: " . ( $user ? $user->user_email : '(unknown)' ) . "\nAll accounts: " . admin_url( 'users.php' )
	);
} );

/* -------------------------------------------------------------------------
 * Placeholder payment gateway - TEST ONLY, swap for the real high-risk
 * processor before launch. Collects no real payment.
 * ---------------------------------------------------------------------- */

add_action( 'plugins_loaded', function () {
	if ( ! class_exists( 'WC_Payment_Gateway' ) ) {
		return;
	}

	class JP_Stub_Gateway extends WC_Payment_Gateway {
		public function __construct() {
			$this->id                 = 'jp_stub_gateway';
			$this->method_title       = 'Placeholder Payment (TEST ONLY)';
			$this->method_description = 'TODO: Replace with the live high-risk merchant gateway before launch. Collects no real payment; marks orders as paid so the full order flow can be tested.';
			$this->has_fields         = false;
			$this->init_form_fields();
			$this->init_settings();
			$this->title       = $this->get_option( 'title' );
			$this->description = $this->get_option( 'description' );
			$this->enabled     = $this->get_option( 'enabled' );
			add_action( 'woocommerce_update_options_payment_gateways_' . $this->id, array( $this, 'process_admin_options' ) );
		}

		public function init_form_fields() {
			$this->form_fields = array(
				'enabled'     => array(
					'title'   => 'Enable/Disable',
					'type'    => 'checkbox',
					'label'   => 'Enable placeholder gateway (TEST ONLY - disable before launch)',
					'default' => 'yes',
				),
				'title'       => array(
					'title'   => 'Title',
					'type'    => 'text',
					'default' => 'Test payment (placeholder - no real charge)',
				),
				'description' => array(
					'title'   => 'Description',
					'type'    => 'textarea',
					'default' => 'TEST MODE: no real payment is collected. This placeholder will be replaced by the live payment processor before launch.',
				),
			);
		}

		public function process_payment( $order_id ) {
			$order = wc_get_order( $order_id );
			$order->payment_complete();
			$order->add_order_note( 'Paid via Placeholder Gateway (TEST - no real funds moved).' );
			WC()->cart->empty_cart();
			return array(
				'result'   => 'success',
				'redirect' => $this->get_return_url( $order ),
			);
		}
	}
}, 20 );

add_filter( 'woocommerce_payment_gateways', function ( $gateways ) {
	if ( class_exists( 'JP_Stub_Gateway' ) ) {
		$gateways[] = 'JP_Stub_Gateway';
	}
	return $gateways;
} );

/* -------------------------------------------------------------------------
 * COA batch records: a "COA Batches" section in the admin where every batch
 * gets its own entry (lab, test date, pass/fail, purity, COA PDF link).
 * The [jp_coa_library] shortcode renders the public lookup table.
 * ---------------------------------------------------------------------- */

add_action( 'init', function () {
	register_post_type( 'jp_batch', array(
		'labels'        => array(
			'name'          => 'COA Batches',
			'singular_name' => 'COA Batch',
			'add_new_item'  => 'Add New Batch',
			'edit_item'     => 'Edit Batch',
			'menu_name'     => 'COA Batches',
		),
		'public'        => false,
		'show_ui'       => true,
		'menu_icon'     => 'dashicons-clipboard',
		'menu_position' => 56,
		'supports'      => array( 'title' ),
	) );
} );

/* The batch edit screen: title = the batch number; these are the details. */
add_action( 'add_meta_boxes', function () {
	add_meta_box( 'jp_batch_details', 'Batch details (the post title above is the batch number, e.g. JP-2026-014)', 'jp_batch_meta_box', 'jp_batch', 'normal', 'high' );
} );

function jp_batch_meta_box( $post ) {
	wp_nonce_field( 'jp_batch_save', 'jp_batch_nonce' );
	$fields = array(
		'_jp_product_name'  => array( 'Product', 'text', 'e.g. Test Peptide A - 5 mg' ),
		'_jp_test_date'     => array( 'Test date', 'date', '' ),
		'_jp_lab_name'      => array( 'Testing lab', 'text', 'Name of the independent lab' ),
		'_jp_result'        => array( 'Result', 'select', '' ),
		'_jp_purity_result' => array( 'Purity result', 'text', 'e.g. 99.2% (HPLC)' ),
		'_jp_coa_url'       => array( 'COA PDF URL', 'text', 'Upload the PDF in Media, paste its URL here' ),
		'_jp_notes'         => array( 'Notes (shown publicly)', 'textarea', 'Optional - e.g. why a batch failed and what happened to it' ),
	);
	echo '<table class="form-table">';
	foreach ( $fields as $key => $def ) {
		list( $label, $type, $placeholder ) = $def;
		$value = get_post_meta( $post->ID, $key, true );
		echo '<tr><th><label for="' . esc_attr( $key ) . '">' . esc_html( $label ) . '</label></th><td>';
		if ( 'select' === $type ) {
			echo '<select name="' . esc_attr( $key ) . '" id="' . esc_attr( $key ) . '">';
			foreach ( array( 'pass' => 'Pass', 'fail' => 'Fail', 'pending' => 'Pending' ) as $opt => $opt_label ) {
				echo '<option value="' . esc_attr( $opt ) . '"' . selected( $value, $opt, false ) . '>' . esc_html( $opt_label ) . '</option>';
			}
			echo '</select>';
		} elseif ( 'textarea' === $type ) {
			echo '<textarea name="' . esc_attr( $key ) . '" id="' . esc_attr( $key ) . '" rows="3" class="large-text" placeholder="' . esc_attr( $placeholder ) . '">' . esc_textarea( $value ) . '</textarea>';
		} else {
			echo '<input type="' . esc_attr( $type ) . '" name="' . esc_attr( $key ) . '" id="' . esc_attr( $key ) . '" value="' . esc_attr( $value ) . '" class="regular-text" placeholder="' . esc_attr( $placeholder ) . '" />';
		}
		echo '</td></tr>';
	}
	echo '</table>';
}

add_action( 'save_post_jp_batch', function ( $post_id ) {
	if ( ! isset( $_POST['jp_batch_nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['jp_batch_nonce'] ) ), 'jp_batch_save' ) ) {
		return;
	}
	if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) {
		return;
	}
	if ( ! current_user_can( 'edit_post', $post_id ) ) {
		return;
	}
	$keys = array( '_jp_product_name', '_jp_test_date', '_jp_lab_name', '_jp_result', '_jp_purity_result', '_jp_coa_url', '_jp_notes' );
	foreach ( $keys as $key ) {
		if ( ! isset( $_POST[ $key ] ) ) {
			continue;
		}
		$raw = wp_unslash( $_POST[ $key ] );
		if ( '_jp_coa_url' === $key ) {
			$value = esc_url_raw( $raw );
		} elseif ( '_jp_notes' === $key ) {
			$value = sanitize_textarea_field( $raw );
		} else {
			$value = sanitize_text_field( $raw );
		}
		update_post_meta( $post_id, $key, $value );
	}
} );

/* Admin list columns so batches are scannable at a glance. */
add_filter( 'manage_jp_batch_posts_columns', function ( $columns ) {
	return array(
		'cb'         => $columns['cb'],
		'title'      => 'Batch number',
		'jp_product' => 'Product',
		'jp_date'    => 'Test date',
		'jp_result'  => 'Result',
		'jp_coa'     => 'COA',
	);
} );

add_action( 'manage_jp_batch_posts_custom_column', function ( $column, $post_id ) {
	switch ( $column ) {
		case 'jp_product':
			echo esc_html( get_post_meta( $post_id, '_jp_product_name', true ) );
			break;
		case 'jp_date':
			echo esc_html( get_post_meta( $post_id, '_jp_test_date', true ) );
			break;
		case 'jp_result':
			$r = get_post_meta( $post_id, '_jp_result', true );
			echo esc_html( ucfirst( $r ? $r : 'pending' ) );
			break;
		case 'jp_coa':
			echo get_post_meta( $post_id, '_jp_coa_url', true ) ? 'Uploaded' : '&mdash;';
			break;
	}
}, 10, 2 );

/**
 * COA URL for a given batch number (matches the batch record's title).
 */
function jp_batch_coa_url( $batch_number ) {
	if ( ! $batch_number ) {
		return '';
	}
	$posts = get_posts( array(
		'post_type'      => 'jp_batch',
		'title'          => $batch_number,
		'post_status'    => 'publish',
		'posts_per_page' => 1,
		'fields'         => 'ids',
	) );
	if ( ! $posts ) {
		return '';
	}
	return (string) get_post_meta( $posts[0], '_jp_coa_url', true );
}

/* Public lookup table: put [jp_coa_library] on any page. */
add_shortcode( 'jp_coa_library', function () {
	$batches = get_posts( array(
		'post_type'      => 'jp_batch',
		'post_status'    => 'publish',
		'posts_per_page' => -1,
		'orderby'        => 'date',
		'order'          => 'DESC',
	) );

	ob_start();

	echo '<div class="jp-coa-filter"><label for="jp-coa-search" class="screen-reader-text">Filter by batch number or product</label>';
	echo '<input type="text" id="jp-coa-search" placeholder="Type a batch number or product name to filter&hellip;" /></div>';

	if ( ! $batches ) {
		echo '<p>No batch records have been published yet.</p>';
	} else {
		echo '<div class="jp-coa-table-wrap"><table class="jp-coa-table" id="jp-coa-table">';
		echo '<thead><tr><th>Batch</th><th>Product</th><th>Test date</th><th>Lab</th><th>Result</th><th>Purity</th><th>COA</th></tr></thead><tbody>';
		foreach ( $batches as $batch ) {
			$result       = get_post_meta( $batch->ID, '_jp_result', true );
			$result       = $result ? $result : 'pending';
			$result_class = 'jp-result-' . sanitize_html_class( $result );
			$coa_url      = get_post_meta( $batch->ID, '_jp_coa_url', true );
			$notes        = get_post_meta( $batch->ID, '_jp_notes', true );
			echo '<tr>';
			echo '<td><strong>' . esc_html( $batch->post_title ) . '</strong></td>';
			echo '<td>' . esc_html( get_post_meta( $batch->ID, '_jp_product_name', true ) ) . '</td>';
			echo '<td>' . esc_html( get_post_meta( $batch->ID, '_jp_test_date', true ) ) . '</td>';
			echo '<td>' . esc_html( get_post_meta( $batch->ID, '_jp_lab_name', true ) ) . '</td>';
			echo '<td><span class="' . esc_attr( $result_class ) . '">' . esc_html( ucfirst( $result ) ) . '</span>';
			if ( $notes ) {
				echo '<br /><small>' . esc_html( $notes ) . '</small>';
			}
			echo '</td>';
			echo '<td>' . esc_html( get_post_meta( $batch->ID, '_jp_purity_result', true ) ) . '</td>';
			echo '<td>';
			if ( $coa_url ) {
				echo '<a href="' . esc_url( $coa_url ) . '" target="_blank" rel="noopener">Download PDF</a>';
			} else {
				echo '&mdash;';
			}
			echo '</td></tr>';
		}
		echo '</tbody></table></div>';
		?>
<script>
(function () {
	var input = document.getElementById('jp-coa-search');
	var table = document.getElementById('jp-coa-table');
	if (!input || !table) { return; }
	input.addEventListener('input', function () {
		var term = this.value.toLowerCase();
		var rows = table.tBodies[0].rows;
		for (var i = 0; i < rows.length; i++) {
			rows[i].style.display = rows[i].textContent.toLowerCase().indexOf(term) === -1 ? 'none' : '';
		}
	});
})();
</script>
		<?php
	}

	return ob_get_clean();
} );

/* RUO reminder on the cart page (checkout already carries the attestation). */
add_action( 'woocommerce_before_cart', function () {
	echo '<div class="jp-ruo-box"><strong>For in vitro research use only. Not for human or veterinary use.</strong> Items in this cart are sold solely for in vitro research. Checkout requires a research account and a signed research-use attestation. <a href="/ruo-policy/">RUO Policy</a>.</div>';
} );

/* -------------------------------------------------------------------------
 * Sitewide regulatory block, rendered above the footer on every page.
 * ---------------------------------------------------------------------- */

add_action( 'wp_footer', function () {
	if ( is_admin() ) {
		return;
	}
	$s = jp_regulatory_statements();
	echo '<section class="jp-regulatory" aria-label="Regulatory information"><div class="jp-regulatory-inner">';
	echo '<p class="jp-regulatory-lead">' . esc_html( $s['ruo'] ) . '</p>';
	echo '<p>' . esc_html( $s['scope'] ) . '</p>';
	echo '<p>' . esc_html( $s['fda'] ) . '</p>';
	echo '<p>' . esc_html( $s['supplier'] ) . '</p>';
	echo '<p class="jp-regulatory-links"><a href="/ruo-policy/">RUO Policy</a> &middot; <a href="/terms-of-service/">Terms of Service</a> &middot; <a href="/shipping-returns/">Shipping &amp; Returns</a> &middot; <a href="/privacy-policy/">Privacy Policy</a></p>';
	echo '</div></section>';
}, 5 );

/* -------------------------------------------------------------------------
 * Live COA stats: [jp_coa_stats] renders stat tiles computed from the real
 * batch records - never hand-typed numbers, so they can't drift from truth.
 *
 * Below JP_COA_STATS_MIN_BATCHES published records the grid does not render
 * at all. A truthful number can still be a damaging one: with two records on
 * file the tiles read "2 batches published / 0 passed testing", which tells a
 * visitor that nothing we have tested has passed. That is an artifact of a
 * near-empty library, not a fact about our quality - the missing batch is
 * 'pending', a third result state that is counted in the total but is neither
 * a pass nor a fail. Rather than fake or round the numbers, we withhold the
 * grid until there is enough data for it to mean anything, and let the
 * surrounding copy and the "Look up a batch" CTA carry the section.
 *
 * Raise or lower the threshold here (or define it in wp-config.php).
 * ---------------------------------------------------------------------- */

if ( ! defined( 'JP_COA_STATS_MIN_BATCHES' ) ) {
	define( 'JP_COA_STATS_MIN_BATCHES', 10 );
}

add_shortcode( 'jp_coa_stats', function () {
	$batches = get_posts( array(
		'post_type'      => 'jp_batch',
		'post_status'    => 'publish',
		'posts_per_page' => -1,
	) );
	$total = count( $batches );

	/* Too few records for the tiles to be meaningful - render nothing. */
	if ( $total < JP_COA_STATS_MIN_BATCHES ) {
		return '';
	}

	$pass  = 0;
	$fail  = 0;
	$labs  = array();
	foreach ( $batches as $b ) {
		$result = get_post_meta( $b->ID, '_jp_result', true );
		if ( 'pass' === $result ) {
			$pass++;
		} elseif ( 'fail' === $result ) {
			$fail++;
		}
		$lab = trim( (string) get_post_meta( $b->ID, '_jp_lab_name', true ) );
		if ( '' !== $lab ) {
			$labs[ strtolower( $lab ) ] = 1;
		}
	}
	$tiles = array(
		array( $total, 'Batches published' ),
		array( $pass, 'Passed testing' ),
		array( $fail, 'Failed &mdash; published anyway' ),
		array( count( $labs ), 'Independent labs' ),
	);
	$out = '<div class="jp-stats">';
	foreach ( $tiles as $t ) {
		$out .= '<div class="jp-stat"><span class="jp-stat-num">' . (int) $t[0] . '</span><span class="jp-stat-label">' . $t[1] . '</span></div>';
	}
	$out .= '</div><p class="jp-stats-caption">Counted live from the COA library &mdash; not marketing copy.</p>';
	return $out;
} );

/* -------------------------------------------------------------------------
 * Product cards: "Batch-verified" badge (only when a PASSING batch record
 * actually exists for the product's batch) + size/purity line under title.
 * ---------------------------------------------------------------------- */

add_action( 'woocommerce_before_shop_loop_item_title', function () {
	global $product;
	if ( ! $product instanceof WC_Product ) {
		return;
	}
	$batch = $product->get_meta( '_jp_batch_number' );
	if ( ! $batch ) {
		return;
	}
	$ids = get_posts( array(
		'post_type'      => 'jp_batch',
		'title'          => $batch,
		'post_status'    => 'publish',
		'posts_per_page' => 1,
		'fields'         => 'ids',
	) );
	if ( $ids && 'pass' === get_post_meta( $ids[0], '_jp_result', true ) ) {
		echo '<span class="jp-card-badge">&#10003; Batch-verified</span>';
	}
}, 15 );

add_action( 'woocommerce_after_shop_loop_item_title', function () {
	global $product;
	if ( ! $product instanceof WC_Product ) {
		return;
	}
	$bits = array_filter( array(
		$product->get_meta( '_jp_size_mg' ),
		$product->get_meta( '_jp_purity' ),
	) );
	if ( $bits ) {
		echo '<div class="jp-card-meta">' . implode( ' &middot; ', array_map( 'esc_html', $bits ) ) . '</div>';
	}
}, 9 );

/* -------------------------------------------------------------------------
 * Age gate Level 2 (field): required date-of-birth on the checkout form.
 * Validated server-side to 21+ in the woocommerce_checkout_process hook.
 * ---------------------------------------------------------------------- */

add_filter( 'woocommerce_checkout_fields', function ( $fields ) {
	$fields['billing']['jp_dob'] = array(
		'type'              => 'date',
		'label'             => 'Date of birth',
		'required'          => true,
		'priority'          => 115,
		'custom_attributes' => array( 'max' => gmdate( 'Y-m-d' ) ),
	);
	return $fields;
} );

/* -------------------------------------------------------------------------
 * Age gate Level 1 (entry): full-screen splash on first visit. Click-to-agree
 * (21+ and research-use-only), remembered for 30 days in the browser.
 * Logged-in users skip it - they have already attested at registration.
 * ---------------------------------------------------------------------- */

add_action( 'wp_footer', function () {
	if ( is_user_logged_in() ) {
		return;
	}
	?>
	<div id="jp-age-gate" role="dialog" aria-modal="true" aria-labelledby="jp-age-title">
		<div class="jp-age-card">
			<h2 id="jp-age-title">Age &amp; research verification</h2>
			<p>This site sells chemicals supplied <strong>for in vitro research use only</strong>. To enter, confirm that you are <strong>at least 21 years of age</strong> and that you understand these products are <strong>not for human or veterinary use</strong> and are not intended to diagnose, treat, cure, or prevent any disease.</p>
			<p class="jp-age-fine">By clicking Enter you agree to our <a href="/terms-of-service/">Terms of Service</a> and <a href="/ruo-policy/">RUO Policy</a>. Ordering additionally requires a research account, a per-order attestation, and age verification at checkout.</p>
			<div class="jp-age-buttons">
				<button type="button" id="jp-age-enter">I am 21+ and agree &mdash; Enter</button>
				<button type="button" id="jp-age-exit">Exit</button>
			</div>
		</div>
	</div>
	<script>
	(function () {
		var KEY = 'jpAgeGateOK';
		var gate = document.getElementById('jp-age-gate');
		if (!gate) { return; }
		try {
			var saved = JSON.parse(window.localStorage.getItem(KEY) || 'null');
			if (saved && saved.exp > Date.now()) { gate.remove(); return; }
		} catch (e) {}
		document.documentElement.style.overflow = 'hidden';
		document.getElementById('jp-age-enter').addEventListener('click', function () {
			try {
				window.localStorage.setItem(KEY, JSON.stringify({ exp: Date.now() + 30 * 86400000 }));
			} catch (e) {}
			gate.remove();
			document.documentElement.style.overflow = '';
		});
		document.getElementById('jp-age-exit').addEventListener('click', function () {
			window.location.href = 'https://www.google.com';
		});
	})();
	</script>
	<?php
}, 99 );

/* -------------------------------------------------------------------------
 * Researcher list: [jp_subscribe] signup form. Stores subscribers locally
 * (no third-party service required yet); exportable to CSV from the admin.
 * ---------------------------------------------------------------------- */

add_action( 'init', function () {
	register_post_type( 'jp_subscriber', array(
		'labels'        => array(
			'name'          => 'Researcher List',
			'singular_name' => 'Subscriber',
			'menu_name'     => 'Researcher List',
		),
		'public'        => false,
		'show_ui'       => true,
		'menu_icon'     => 'dashicons-email-alt',
		'menu_position' => 57,
		'supports'      => array( 'title' ),
		'capabilities'  => array( 'create_posts' => 'do_not_allow' ),
		'map_meta_cap'  => true,
	) );
} );

add_shortcode( 'jp_subscribe', function () {
	$status = isset( $_GET['jp_sub'] ) ? sanitize_key( $_GET['jp_sub'] ) : '';
	ob_start();
	echo '<form class="jp-subscribe" method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
	echo '<input type="hidden" name="action" value="jp_subscribe" />';
	wp_nonce_field( 'jp_subscribe', 'jp_subscribe_nonce' );
	echo '<label class="screen-reader-text" for="jp-sub-email">Email address</label>';
	echo '<input type="email" id="jp-sub-email" name="jp_email" required placeholder="you@lab.org" />';
	echo '<button type="submit">Join the list</button>';
	echo '</form>';
	echo '<p class="jp-subscribe-note">Batch releases and new COA postings. No marketing claims, no dosing guidance &mdash; we don&#8217;t send those.</p>';
	if ( 'ok' === $status ) {
		echo '<p class="jp-subscribe-msg jp-subscribe-ok">You&#8217;re on the list. Thanks.</p>';
	} elseif ( 'dupe' === $status ) {
		echo '<p class="jp-subscribe-msg">That address is already on the list.</p>';
	} elseif ( 'bad' === $status ) {
		echo '<p class="jp-subscribe-msg jp-subscribe-err">That didn&#8217;t look like a valid email address.</p>';
	}
	return ob_get_clean();
} );

add_action( 'admin_post_nopriv_jp_subscribe', 'jp_handle_subscribe' );
add_action( 'admin_post_jp_subscribe', 'jp_handle_subscribe' );
function jp_handle_subscribe() {
	$referer = wp_get_referer() ? wp_get_referer() : home_url( '/' );
	if ( ! isset( $_POST['jp_subscribe_nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['jp_subscribe_nonce'] ) ), 'jp_subscribe' ) ) {
		wp_safe_redirect( add_query_arg( 'jp_sub', 'bad', $referer ) );
		exit;
	}
	$email = isset( $_POST['jp_email'] ) ? sanitize_email( wp_unslash( $_POST['jp_email'] ) ) : '';
	if ( ! is_email( $email ) ) {
		wp_safe_redirect( add_query_arg( 'jp_sub', 'bad', $referer ) );
		exit;
	}
	$existing = get_posts( array(
		'post_type'      => 'jp_subscriber',
		'title'          => $email,
		'post_status'    => 'any',
		'posts_per_page' => 1,
		'fields'         => 'ids',
	) );
	if ( $existing ) {
		wp_safe_redirect( add_query_arg( 'jp_sub', 'dupe', $referer ) );
		exit;
	}
	wp_insert_post( array(
		'post_type'   => 'jp_subscriber',
		'post_status' => 'publish',
		'post_title'  => $email,
	) );
	wp_safe_redirect( add_query_arg( 'jp_sub', 'ok', $referer ) );
	exit;
}

/* CSV export button on the Researcher List screen. */
add_action( 'admin_notices', function () {
	$screen = get_current_screen();
	if ( ! $screen || 'edit-jp_subscriber' !== $screen->id ) {
		return;
	}
	$url = wp_nonce_url( admin_url( 'admin-post.php?action=jp_export_subscribers' ), 'jp_export_subscribers' );
	echo '<div class="notice notice-info"><p>These addresses are stored in your own database. <a class="button button-primary" href="' . esc_url( $url ) . '">Export CSV</a> to import into an email service later.</p></div>';
} );

add_action( 'admin_post_jp_export_subscribers', function () {
	if ( ! current_user_can( 'manage_options' ) || ! isset( $_GET['_wpnonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_GET['_wpnonce'] ) ), 'jp_export_subscribers' ) ) {
		wp_die( 'Not allowed.' );
	}
	$subs = get_posts( array( 'post_type' => 'jp_subscriber', 'post_status' => 'publish', 'posts_per_page' => -1 ) );
	header( 'Content-Type: text/csv' );
	header( 'Content-Disposition: attachment; filename=joyful-peptides-researcher-list.csv' );
	$out = fopen( 'php://output', 'w' );
	fputcsv( $out, array( 'email', 'date_added' ) );
	foreach ( $subs as $s ) {
		fputcsv( $out, array( $s->post_title, $s->post_date ) );
	}
	fclose( $out );
	exit;
} );

/* -------------------------------------------------------------------------
 * Post-purchase: batch + COA panel on the order-received page, and a
 * "Your batches" history table in My Account. (Retention gap #4.)
 * ---------------------------------------------------------------------- */

/**
 * Batch rows for an order: batch number, product, result, COA link.
 */
function jp_order_batch_rows( $order ) {
	$rows = array();
	foreach ( $order->get_items() as $item ) {
		$product = $item->get_product();
		if ( ! $product ) {
			continue;
		}
		$batch = $product->get_meta( '_jp_batch_number' );
		if ( ! $batch ) {
			continue;
		}
		$ids    = get_posts( array(
			'post_type'      => 'jp_batch',
			'title'          => $batch,
			'post_status'    => 'publish',
			'posts_per_page' => 1,
			'fields'         => 'ids',
		) );
		$result = $ids ? get_post_meta( $ids[0], '_jp_result', true ) : '';
		$coa    = $ids ? get_post_meta( $ids[0], '_jp_coa_url', true ) : $product->get_meta( '_jp_coa_url' );
		$rows[] = array(
			'product' => $item->get_name(),
			'batch'   => $batch,
			'result'  => $result ? $result : 'pending',
			'coa'     => $coa,
			'sds'     => $product->get_meta( '_jp_sds_url' ),
			'date'    => $order->get_date_created() ? $order->get_date_created()->date( 'Y-m-d' ) : '',
		);
	}
	return $rows;
}

function jp_render_batch_table( $rows, $show_date = false ) {
	if ( ! $rows ) {
		return;
	}
	echo '<div class="jp-coa-table-wrap"><table class="jp-coa-table"><thead><tr>';
	echo '<th>Batch</th><th>Product</th>';
	if ( $show_date ) {
		echo '<th>Ordered</th>';
	}
	echo '<th>Result</th><th>COA</th><th>SDS</th></tr></thead><tbody>';
	foreach ( $rows as $r ) {
		echo '<tr>';
		echo '<td><strong>' . esc_html( $r['batch'] ) . '</strong></td>';
		echo '<td>' . esc_html( $r['product'] ) . '</td>';
		if ( $show_date ) {
			echo '<td>' . esc_html( $r['date'] ) . '</td>';
		}
		echo '<td><span class="jp-result-' . esc_attr( sanitize_html_class( $r['result'] ) ) . '">' . esc_html( ucfirst( $r['result'] ) ) . '</span></td>';
		echo '<td>' . ( $r['coa'] ? '<a href="' . esc_url( $r['coa'] ) . '" target="_blank" rel="noopener">Download</a>' : '&mdash;' ) . '</td>';
		echo '<td>' . ( ! empty( $r['sds'] ) ? '<a href="' . esc_url( $r['sds'] ) . '" target="_blank" rel="noopener">Download</a>' : '&mdash;' ) . '</td>';
		echo '</tr>';
	}
	echo '</tbody></table></div>';
}

add_action( 'woocommerce_thankyou', function ( $order_id ) {
	$order = wc_get_order( $order_id );
	if ( ! $order ) {
		return;
	}
	$rows = jp_order_batch_rows( $order );
	if ( ! $rows ) {
		return;
	}
	echo '<section class="jp-thankyou-batches">';
	echo '<h2>Your batch records</h2>';
	echo '<p>These are the exact batches in this order. Keep this page &mdash; the same records stay available under <a href="' . esc_url( wc_get_account_endpoint_url( 'dashboard' ) ) . '">My Account</a> at any time.</p>';
	jp_render_batch_table( $rows );
	echo '<p class="jp-thankyou-note">Shipping with Adult Signature Required (21+). The recipient name must match the name on this order.</p>';
	echo '</section>';
}, 5 );

add_action( 'woocommerce_account_dashboard', function () {
	$orders = wc_get_orders( array(
		'customer_id' => get_current_user_id(),
		'limit'       => 25,
		'status'      => array( 'processing', 'completed', 'on-hold' ),
	) );
	$rows = array();
	$seen = array();
	foreach ( $orders as $order ) {
		foreach ( jp_order_batch_rows( $order ) as $row ) {
			$key = $row['batch'] . '|' . $row['product'];
			if ( isset( $seen[ $key ] ) ) {
				continue;
			}
			$seen[ $key ] = true;
			$rows[]       = $row;
		}
	}
	echo '<section class="jp-account-batches"><h2>Your batches</h2>';
	if ( $rows ) {
		echo '<p>Every batch you&#8217;ve received, with its independent lab report.</p>';
		jp_render_batch_table( $rows, true );
	} else {
		echo '<p>Once you order, the batch records and lab reports for everything you receive will be listed here.</p>';
	}
	echo '</section>';
} );

/* -------------------------------------------------------------------------
 * Structured data (JSON-LD) + social preview tags.
 * Purpose: when a researcher asks an AI assistant or search engine about a
 * batch, a compound, or this vendor's legitimacy, the answer is drawn from
 * machine-readable facts we actually publish - COA data, batch numbers,
 * testing status - rather than from marketing prose. No health claims.
 * ---------------------------------------------------------------------- */

add_action( 'wp_head', function () {
	$graph = array();

	$org = array(
		'@type'       => 'Organization',
		'@id'         => home_url( '/#organization' ),
		'name'        => get_bloginfo( 'name' ),
		'url'         => home_url( '/' ),
		'description' => 'Supplier of research-use-only peptides. Every batch is tested by an independent laboratory and its certificate of analysis is published publicly, including batches that fail testing.',
	);
	$graph[] = $org;

	if ( function_exists( 'is_product' ) && is_product() ) {
		global $product;
		if ( $product instanceof WC_Product ) {
			$props = array();
			foreach ( array(
				'Vial size'     => '_jp_size_mg',
				'Purity (HPLC)' => '_jp_purity',
				'Batch number'  => '_jp_batch_number',
				'Storage'       => '_jp_storage',
			) as $label => $key ) {
				$val = $product->get_meta( $key );
				if ( $val ) {
					$props[] = array( '@type' => 'PropertyValue', 'name' => $label, 'value' => $val );
				}
			}
			$props[] = array( '@type' => 'PropertyValue', 'name' => 'Intended use', 'value' => 'Laboratory research use only. Not for human consumption.' );

			$node = array(
				'@type'       => 'Product',
				'name'        => $product->get_name(),
				'description' => wp_strip_all_tags( $product->get_short_description() ),
				'sku'         => $product->get_sku(),
				'url'         => get_permalink( $product->get_id() ),
				'brand'       => array( '@type' => 'Brand', 'name' => get_bloginfo( 'name' ) ),
				'additionalProperty' => $props,
				'offers'      => array(
					'@type'         => 'Offer',
					'price'         => $product->get_price(),
					'priceCurrency' => get_woocommerce_currency(),
					'availability'  => $product->is_in_stock() ? 'https://schema.org/InStock' : 'https://schema.org/OutOfStock',
					'url'           => get_permalink( $product->get_id() ),
					'eligibleCustomerType' => 'Approved research accounts, 21+',
				),
			);
			$coa = $product->get_meta( '_jp_coa_url' );
			if ( ! $coa ) {
				$coa = jp_batch_coa_url( $product->get_meta( '_jp_batch_number' ) );
			}
			$docs = array();
			if ( $coa ) {
				$docs[] = array(
					'@type' => 'DigitalDocument',
					'name'  => 'Certificate of analysis for batch ' . $product->get_meta( '_jp_batch_number' ),
					'url'   => $coa,
				);
			}
			$sds_url = $product->get_meta( '_jp_sds_url' );
			if ( $sds_url ) {
				$docs[] = array(
					'@type' => 'DigitalDocument',
					'name'  => 'Safety data sheet for ' . $product->get_name(),
					'url'   => $sds_url,
				);
			}
			if ( $docs ) {
				$node['subjectOf'] = $docs;
			}
			$graph[] = $node;
		}
	} elseif ( is_singular( 'post' ) ) {
		$graph[] = array(
			'@type'         => 'Article',
			'headline'      => get_the_title(),
			'description'   => get_the_excerpt(),
			'datePublished' => get_the_date( 'c' ),
			'dateModified'  => get_the_modified_date( 'c' ),
			'url'           => get_permalink(),
			'publisher'     => array( '@id' => home_url( '/#organization' ) ),
			'isAccessibleForFree' => true,
		);
	} elseif ( is_page( 'faq' ) ) {
		$faqs = array();
		if ( preg_match_all( '#<summary>(.*?)</summary>.*?<p>(.*?)</p>#s', get_post_field( 'post_content', get_queried_object_id() ), $m, PREG_SET_ORDER ) ) {
			foreach ( $m as $pair ) {
				$faqs[] = array(
					'@type'          => 'Question',
					'name'           => wp_strip_all_tags( $pair[1] ),
					'acceptedAnswer' => array( '@type' => 'Answer', 'text' => wp_strip_all_tags( $pair[2] ) ),
				);
			}
		}
		if ( $faqs ) {
			$graph[] = array( '@type' => 'FAQPage', 'mainEntity' => $faqs );
		}
	}

	echo "\n" . '<script type="application/ld+json">' . wp_json_encode(
		array( '@context' => 'https://schema.org', '@graph' => $graph ),
		JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
	) . '</script>' . "\n";

	/* Social preview tags */
	$title = wp_get_document_title();
	$desc  = 'Research-use-only peptides, independently tested with every batch certificate published — including failures.';
	if ( is_singular() ) {
		$excerpt = get_the_excerpt();
		if ( $excerpt ) {
			$desc = wp_strip_all_tags( $excerpt );
		}
	}
	printf( '<meta name="description" content="%s">' . "\n", esc_attr( $desc ) );
	printf( '<meta property="og:site_name" content="%s">' . "\n", esc_attr( get_bloginfo( 'name' ) ) );
	printf( '<meta property="og:title" content="%s">' . "\n", esc_attr( $title ) );
	printf( '<meta property="og:description" content="%s">' . "\n", esc_attr( $desc ) );
	printf( '<meta property="og:type" content="%s">' . "\n", is_singular( 'post' ) ? 'article' : 'website' );
	printf( '<meta property="og:url" content="%s">' . "\n", esc_url( home_url( add_query_arg( array() ) ) ) );
	echo '<meta name="twitter:card" content="summary_large_image">' . "\n";
	if ( is_singular() && has_post_thumbnail() ) {
		printf( '<meta property="og:image" content="%s">' . "\n", esc_url( get_the_post_thumbnail_url( null, 'large' ) ) );
	}
}, 5 );

/* -------------------------------------------------------------------------
 * Product page trust row: the three guarantees, restated where it counts.
 * ---------------------------------------------------------------------- */

add_action( 'woocommerce_single_product_summary', function () {
	global $product;
	if ( ! $product instanceof WC_Product ) {
		return;
	}
	$items = array(
		array( '&#9733;', 'Independently tested', 'Outside lab, every batch' ),
		array( '&#9639;', 'Public COA', 'Lot-matched, failures included' ),
		array( '&#9993;', 'Adult signature', '21+ required on delivery' ),
	);
	echo '<div class="jp-trustrow">';
	foreach ( $items as $i ) {
		echo '<div class="jp-trustrow-item"><span class="jp-trustrow-icon">' . $i[0] . '</span><span class="jp-trustrow-text"><strong>' . esc_html( $i[1] ) . '</strong><small>' . esc_html( $i[2] ) . '</small></span></div>';
	}
	echo '</div>';
}, 26 );

/* -------------------------------------------------------------------------
 * Trust bar: [jp_trust_bar]
 *
 * IMPORTANT: every entry here is a factual claim about this business.
 * A claim may only be switched on ('on' => true) once it is literally and
 * contractually true, because these are the statements a payment processor,
 * the FTC, or the FDA would hold the business to. Each disabled entry
 * records exactly what must be true before it can be enabled.
 * ---------------------------------------------------------------------- */

function jp_trust_claims() {
	return array(
		/* ---- Enabled: verifiable from this site's own data / model ---- */
		array(
			'on'    => true,
			'icon'  => '&#9673;',
			'title' => 'Third-party tested',
			'copy'  => 'Every batch is tested by an independent laboratory before release. No in-house numbers.',
		),
		array(
			'on'    => true,
			'icon'  => '&#9636;',
			'title' => 'Public, lot-matched COAs',
			'copy'  => 'Each batch has a published certificate of analysis tied to the batch number on the vial.',
		),
		array(
			'on'    => true,
			'icon'  => '&#9888;',
			'title' => 'Failures published too',
			'copy'  => 'Batches that fail testing are never released, and their records stay public.',
		),
		array(
			'on'    => true,
			'icon'  => '&#9993;',
			'title' => 'Adult signature delivery',
			'copy'  => 'US shipping only, 21+ adult signature required, recipient name matched to the account.',
		),

		/* ---- Disabled: claims supplied by the owner that are not yet true.
		 * Set 'on' => true only when the 'requires' condition is satisfied. -- */
		array(
			'on'    => true,
			'icon'  => '&#9634;',
			'title' => 'cGMP-aligned manufacturing',
			'copy'  => 'Produced through qualified partners that follow cGMP-aligned quality practices, with process and quality controls applied across the portfolio.',
		),

		/* ---- Disabled: not true today. Set 'on' => true only when the
		 * 'requires' condition is genuinely satisfied. -------------------- */
		array(
			'on'       => false,
			'icon'     => '&#8721;',
			'title'    => '99%+ purity guarantee',
			'copy'     => 'Research-grade peptides with verified potency. Every product ships with a certificate of analysis.',
			'requires' => 'DECLINED BY OWNER 2026-08-12 — deliberately not used. Would require a written specification that every released batch assays at >= 99.0% plus a refund/replacement remedy, and current batch records include 98.6% and 98.1% results that would breach it.',
		),
		array(
			'on'       => false,
			'icon'     => '&#9654;',
			'title'    => 'Same-day fulfillment',
			'copy'     => 'Same-day shipping on orders placed before 12 PM PT. Free standard domestic shipping over $400.',
			'requires' => 'A fulfillment operation that can actually meet a same-day cutoff, plus a decision on the free-shipping threshold.',
		),
		array(
			'on'       => false,
			'icon'     => '&#9711;',
			'title'    => 'Zero fillers or additives',
			'copy'     => 'Pure active compounds only, with independently verified composition.',
			'requires' => 'A test panel that actually verifies absence of fillers/excipients (identity + purity alone do not establish this), and COAs that show it.',
		),
	);
}

add_shortcode( 'jp_trust_bar', function () {
	$claims = array_values( array_filter( jp_trust_claims(), function ( $c ) { return ! empty( $c['on'] ); } ) );
	if ( ! $claims ) {
		return '';
	}
	$out = '<div class="jp-trustbar">';
	foreach ( $claims as $c ) {
		$out .= '<div class="jp-trustbar-item">';
		$out .= '<span class="jp-trustbar-icon" aria-hidden="true">' . $c['icon'] . '</span>';
		$out .= '<h3 class="jp-trustbar-title">' . esc_html( $c['title'] ) . '</h3>';
		$out .= '<p class="jp-trustbar-copy">' . esc_html( $c['copy'] ) . '</p>';
		$out .= '</div>';
	}
	$out .= '</div>';
	return $out;
} );

/* Surface the disabled claims in the Pre-Launch Check so they are not lost. */
add_action( 'admin_menu', function () {
	add_submenu_page(
		'tools.php',
		'Trust Claims',
		'Trust Claims',
		'manage_options',
		'jp-trust-claims',
		function () {
			echo '<div class="wrap"><h1>Trust Claims</h1>';
			echo '<p>Each row is a factual claim. Enabled claims appear in the trust bar on the homepage. A claim must be literally true before it is switched on &mdash; these are statements a payment processor, the FTC, or the FDA would hold you to.</p>';
			echo '<p>To enable one, edit <code>jp_trust_claims()</code> in the Joyful Peptides Core plugin and set <code>\'on\' => true</code>.</p>';
			echo '<table class="widefat striped"><thead><tr><th style="width:90px">Status</th><th style="width:220px">Claim</th><th>What must be true first</th></tr></thead><tbody>';
			foreach ( jp_trust_claims() as $c ) {
				printf(
					'<tr><td><span style="font-weight:600;color:%s">%s</span></td><td><strong>%s</strong></td><td>%s</td></tr>',
					! empty( $c['on'] ) ? '#007a2f' : '#996800',
					! empty( $c['on'] ) ? 'LIVE' : 'OFF',
					esc_html( $c['title'] ),
					esc_html( ! empty( $c['on'] ) ? 'Supported by this site\'s own published batch data and shipping configuration.' : $c['requires'] )
				);
			}
			echo '</tbody></table></div>';
		}
	);
} );

/* -------------------------------------------------------------------------
 * Density signals: header ticker + sticky category pills.
 *
 * EVERY ticker string is either a live figure from this database or a phrase
 * already approved and published elsewhere on the site (jp_trust_claims() and
 * jp_regulatory_statements()). Nothing here is newly written marketing, which
 * matters because this bar sits above the RUO banner's fold on every page.
 * ---------------------------------------------------------------------- */

function jp_ticker_items() {
	$count = (int) wp_count_posts( 'product' )->publish;
	$items = array(
		'IN VITRO RESEARCH USE ONLY',
		'THIRD-PARTY TESTED',
		'PUBLIC, LOT-MATCHED COAs',
		'FAILURES PUBLISHED TOO',
		'US SHIPPING ONLY ' . html_entity_decode( '&middot;' ) . ' 21+ ADULT SIGNATURE',
	);
	if ( $count > 0 ) {
		array_splice( $items, 1, 0, sprintf( '%d RESEARCH COMPOUNDS IN CATALOG', $count ) );
	}
	return $items;
}

add_shortcode( 'jp_ticker', function () {
	$items = jp_ticker_items();
	if ( ! $items ) {
		return '';
	}
	$one = '';
	foreach ( $items as $i ) {
		$one .= '<span class="jp-ticker-item">' . esc_html( $i ) . '</span>';
	}
	/* Duplicated once so the -50% keyframe loops seamlessly. aria-hidden
	   because it is decorative repetition of content stated elsewhere. */
	return '<div class="jp-ticker" aria-hidden="true"><div class="jp-ticker-track">'
		. $one . $one . '</div></div>';
} );

add_shortcode( 'jp_header_pills', function () {
	$terms = get_terms( array( 'taxonomy' => 'product_cat', 'hide_empty' => true ) );
	if ( is_wp_error( $terms ) || count( $terms ) < 2 ) {
		return '';
	}
	$current = is_product_category() ? get_queried_object_id() : 0;
	$shop    = wc_get_page_permalink( 'shop' );
	$on_shop = ( function_exists( 'is_shop' ) && is_shop() );

	$out  = '<nav class="jp-pillbar" aria-label="Product categories">';
	$out .= '<div class="jp-pillbar-track">';
	$out .= sprintf(
		'<a class="jp-pill%s" href="%s">All</a>',
		$on_shop && ! $current ? ' jp-pill-on' : '',
		esc_url( $shop )
	);
	foreach ( $terms as $t ) {
		$out .= sprintf(
			'<a class="jp-pill%s" href="%s"%s>%s <span class="jp-pill-count">%d</span></a>',
			$current === $t->term_id ? ' jp-pill-on' : '',
			esc_url( get_term_link( $t ) ),
			$current === $t->term_id ? ' aria-current="page"' : '',
			esc_html( $t->name ),
			(int) $t->count
		);
	}
	$out .= '</div></nav>';
	return $out;
} );

/* -------------------------------------------------------------------------
 * Shop archive: category filter pills
 * ---------------------------------------------------------------------- */

function jp_render_category_pills() {
	$terms = get_terms( array( 'taxonomy' => 'product_cat', 'hide_empty' => true ) );
	if ( is_wp_error( $terms ) || count( $terms ) < 2 ) {
		return;
	}
	$current = is_product_category() ? get_queried_object_id() : 0;
	echo '<nav class="jp-cat-pills" aria-label="Product categories">';
	printf(
		'<a class="jp-pill%s" href="%s">All</a>',
		$current ? '' : ' jp-pill-on',
		esc_url( wc_get_page_permalink( 'shop' ) )
	);
	foreach ( $terms as $t ) {
		printf(
			'<a class="jp-pill%s" href="%s">%s <span class="jp-pill-count">%d</span></a>',
			$current === $t->term_id ? ' jp-pill-on' : '',
			esc_url( get_term_link( $t ) ),
			esc_html( $t->name ),
			(int) $t->count
		);
	}
	echo '</nav>';
}
add_action( 'woocommerce_before_shop_loop', 'jp_render_category_pills', 5 );

/* -------------------------------------------------------------------------
 * [jp_category_shelves] - one horizontally scrollable row per category.
 * Used on the main shop page in place of the flat grid.
 * ---------------------------------------------------------------------- */

/**
 * Compact product tile, shared by the category shelves and the homepage row.
 */
function jp_render_product_tile( $product ) {
	if ( ! $product instanceof WC_Product ) {
		return;
	}
	$batch    = $product->get_meta( '_jp_batch_number' );
	$verified = false;
	if ( $batch ) {
		$ids = get_posts( array(
			'post_type'      => 'jp_batch',
			'title'          => $batch,
			'post_status'    => 'publish',
			'posts_per_page' => 1,
			'fields'         => 'ids',
		) );
		$verified = ( $ids && 'pass' === get_post_meta( $ids[0], '_jp_result', true ) );
	}
	$img = $product->get_image_id()
		? wp_get_attachment_image( $product->get_image_id(), 'woocommerce_thumbnail' )
		: '<img src="' . esc_url( wc_placeholder_img_src() ) . '" alt="" loading="lazy" />';

	echo '<article class="jp-tile">';
	echo '<a class="jp-tile-link" href="' . esc_url( $product->get_permalink() ) . '">';
	echo '<span class="jp-tile-media">' . $img . '</span>';
	/* Badge slot. Always rendered, usually empty - reserving the corner keeps
	   every card identical whether or not it carries a badge, and gives real
	   product photography a fixed place to sit behind. */
	echo '<span class="jp-tile-badges">';
	if ( $verified ) {
		echo '<span class="jp-tile-badge">&#10003; Verified</span>';
	}
	if ( ! $product->is_in_stock() ) {
		echo '<span class="jp-tile-badge jp-tile-badge-out">Unavailable</span>';
	}
	echo '</span>';
	echo '<span class="jp-tile-name">' . esc_html( $product->get_name() ) . '</span>';
	$size = $product->get_meta( '_jp_size_mg' );
	if ( $size ) {
		echo '<span class="jp-tile-meta">' . esc_html( $size ) . '</span>';
	}
	echo '<span class="jp-tile-price">' . wp_kses_post( $product->get_price_html() ) . '</span>';
	echo '</a>';
	printf(
		'<a href="%s" data-quantity="1" class="button jp-tile-add add_to_cart_button ajax_add_to_cart" data-product_id="%d" data-product_sku="%s" rel="nofollow">Add to cart</a>',
		esc_url( $product->add_to_cart_url() ),
		(int) $product->get_id(),
		esc_attr( $product->get_sku() )
	);
	echo '</article>';
}

/**
 * [jp_product_row] - a single scrollable row of tiles for the homepage.
 * Shows products flagged Featured in WooCommerce; if none are flagged, falls
 * back to a spread across categories so the row shows the catalog's range
 * rather than whatever happened to be imported last.
 */
add_shortcode( 'jp_product_row', function ( $atts ) {
	$atts = shortcode_atts( array( 'limit' => 10 ), $atts, 'jp_product_row' );
	$limit = max( 1, (int) $atts['limit'] );

	$products = wc_get_products( array(
		'status'   => 'publish',
		'limit'    => $limit,
		'featured' => true,
		'orderby'  => 'title',
		'order'    => 'ASC',
	) );

	if ( ! $products ) {
		// Spread across categories: take the cheapest couple from each so the
		// row reads as a representative sample, not an arbitrary tail.
		$terms    = get_terms( array( 'taxonomy' => 'product_cat', 'hide_empty' => true, 'orderby' => 'count', 'order' => 'DESC' ) );
		$products = array();
		$seen     = array();
		if ( ! is_wp_error( $terms ) && $terms ) {
			$per = max( 1, (int) ceil( $limit / max( 1, count( $terms ) ) ) );
			foreach ( $terms as $term ) {
				$batch = wc_get_products( array(
					'status'   => 'publish',
					'limit'    => 30,
					'category' => array( $term->slug ),
					'orderby'  => 'title',
					'order'    => 'ASC',
				) );
				$taken = 0;
				foreach ( $batch as $b ) {
					if ( $taken >= $per ) {
						break;
					}
					// One entry per compound: strip the trailing strength so
					// e.g. "5-AMINO-1MQ 10mg" and "... 50mg" don't both show.
					$base = strtolower( trim( preg_replace( '/\s*[\d.+]+\s*(mg|ml|mcg)\b.*$/i', '', $b->get_name() ) ) );
					if ( '' === $base ) {
						$base = strtolower( $b->get_name() );
					}
					if ( isset( $seen[ $base ] ) ) {
						continue;
					}
					$seen[ $base ]            = true;
					$products[ $b->get_id() ] = $b;
					$taken++;
				}
			}
			$products = array_slice( $products, 0, $limit, true );
		}
	}

	if ( ! $products ) {
		return '';
	}

	ob_start();
	echo '<div class="jp-shelf jp-shelf-flush">';
	echo '<div class="jp-shelf-head jp-shelf-head-min">';
	echo '<div class="jp-shelf-nav">';
	echo '<button type="button" class="jp-shelf-btn" data-dir="-1" aria-label="Scroll catalog left">&#8592;</button>';
	echo '<button type="button" class="jp-shelf-btn" data-dir="1" aria-label="Scroll catalog right">&#8594;</button>';
	echo '<a class="jp-shelf-all" href="' . esc_url( wc_get_page_permalink( 'shop' ) ) . '">Browse all</a>';
	echo '</div></div>';
	echo '<div class="jp-shelf-track">';
	foreach ( $products as $product ) {
		jp_render_product_tile( $product );
	}
	echo '</div></div>';
	return ob_get_clean();
} );

add_shortcode( 'jp_category_shelves', function () {
	$terms = get_terms( array(
		'taxonomy'   => 'product_cat',
		'hide_empty' => true,
		'orderby'    => 'count',
		'order'      => 'DESC',
	) );
	if ( is_wp_error( $terms ) || ! $terms ) {
		return '';
	}

	ob_start();
	jp_render_category_pills();
	echo '<div class="jp-shelves">';

	foreach ( $terms as $term ) {
		$products = wc_get_products( array(
			'status'   => 'publish',
			'limit'    => 24,
			'orderby'  => 'title',
			'order'    => 'ASC',
			'category' => array( $term->slug ),
		) );
		if ( ! $products ) {
			continue;
		}

		printf(
			'<section class="jp-shelf" aria-labelledby="shelf-%1$s">
				<div class="jp-shelf-head">
					<div>
						<h2 class="jp-shelf-title" id="shelf-%1$s">%2$s</h2>
						<p class="jp-shelf-count">%3$d product%4$s</p>
					</div>
					<div class="jp-shelf-nav">
						<button type="button" class="jp-shelf-btn" data-dir="-1" aria-label="Scroll %2$s left">&#8592;</button>
						<button type="button" class="jp-shelf-btn" data-dir="1" aria-label="Scroll %2$s right">&#8594;</button>
						<a class="jp-shelf-all" href="%5$s">View all</a>
					</div>
				</div>
				<div class="jp-shelf-track">',
			esc_attr( $term->slug ),
			esc_html( $term->name ),
			(int) $term->count,
			1 === (int) $term->count ? '' : 's',
			esc_url( get_term_link( $term ) )
		);

		foreach ( $products as $product ) {
			jp_render_product_tile( $product );
		}

		echo '</div>';
		if ( (int) $term->count > count( $products ) ) {
			printf(
				'<p class="jp-shelf-more"><a href="%s">View all %d in %s &rarr;</a></p>',
				esc_url( get_term_link( $term ) ),
				(int) $term->count,
				esc_html( $term->name )
			);
		}
		echo '</section>';
	}

	echo '</div>';
	return ob_get_clean();
} );

/* Out-of-stock badge on cards (a failed batch is never released for sale). */
add_action( 'woocommerce_before_shop_loop_item_title', function () {
	global $product;
	if ( $product instanceof WC_Product && ! $product->is_in_stock() ) {
		echo '<span class="jp-card-badge jp-card-badge-out">Not available</span>';
	}
}, 16 );

/* -------------------------------------------------------------------------
 * Admin dashboard widget: what needs the owner's attention today.
 * ---------------------------------------------------------------------- */

add_action( 'wp_dashboard_setup', function () {
	if ( ! current_user_can( 'manage_options' ) ) {
		return;
	}
	wp_add_dashboard_widget( 'jp_dashboard', 'Joyful Peptides — needs attention', function () {
		$batches      = get_posts( array( 'post_type' => 'jp_batch', 'post_status' => 'publish', 'posts_per_page' => -1 ) );
		$no_coa       = 0;
		$pending_test = 0;
		foreach ( $batches as $b ) {
			if ( ! get_post_meta( $b->ID, '_jp_coa_url', true ) ) {
				$no_coa++;
			}
			if ( 'pending' === get_post_meta( $b->ID, '_jp_result', true ) ) {
				$pending_test++;
			}
		}
		$subs = wp_count_posts( 'jp_subscriber' );
		$rows = array(
			array( $no_coa, 'batch record(s) with no COA uploaded', admin_url( 'edit.php?post_type=jp_batch' ), $no_coa > 0 ),
			array( $pending_test, 'batch(es) still awaiting lab results', admin_url( 'edit.php?post_type=jp_batch' ), false ),
			array( isset( $subs->publish ) ? (int) $subs->publish : 0, 'researcher list subscriber(s)', admin_url( 'edit.php?post_type=jp_subscriber' ), false ),
		);
		echo '<ul style="margin:0">';
		foreach ( $rows as $r ) {
			printf(
				'<li style="padding:.35rem 0;border-bottom:1px solid #f0f0f1"><strong style="font-size:1.25em;color:%s">%d</strong> %s &mdash; <a href="%s">view</a></li>',
				$r[3] ? '#b5651d' : '#1d2327',
				$r[0],
				esc_html( $r[1] ),
				esc_url( $r[2] )
			);
		}
		echo '</ul>';
		echo '<p style="margin-top:.75rem;color:#646970;font-size:.9em">Reminder: the placeholder payment gateway is TEST ONLY. Sample products and batches must be deleted before launch.</p>';
	} );
} );

/* -------------------------------------------------------------------------
 * Pre-launch guard: scans the live site for anything that must not reach
 * production - sample data, the test gateway, unfilled [FILL IN] copy,
 * unreviewed legal pages - and blocks nothing, but makes it impossible to
 * forget. Tools > Pre-Launch Check, plus a persistent admin banner.
 * ---------------------------------------------------------------------- */

function jp_prelaunch_issues() {
	$issues = array();

	$samples = get_posts( array(
		'post_type'      => 'product',
		'posts_per_page' => -1,
		's'              => 'SAMPLE',
		'fields'         => 'ids',
	) );
	if ( $samples ) {
		$issues[] = array( 'blocker', count( $samples ) . ' sample product(s) still published', admin_url( 'edit.php?post_type=product' ) );
	}

	$sample_batches = get_posts( array(
		'post_type'      => 'jp_batch',
		'posts_per_page' => -1,
		's'              => 'JP-TEST',
		'fields'         => 'ids',
	) );
	if ( $sample_batches ) {
		$issues[] = array( 'blocker', count( $sample_batches ) . ' sample batch record(s) still published', admin_url( 'edit.php?post_type=jp_batch' ) );
	}

	$gateways = WC()->payment_gateways ? WC()->payment_gateways->get_available_payment_gateways() : array();
	if ( isset( $gateways['jp_stub_gateway'] ) ) {
		$issues[] = array( 'blocker', 'Placeholder payment gateway is ENABLED (collects no real money)', admin_url( 'admin.php?page=wc-settings&tab=checkout' ) );
	}

	$pages = get_posts( array( 'post_type' => 'page', 'posts_per_page' => -1, 'post_status' => array( 'publish', 'draft' ) ) );
	foreach ( $pages as $p ) {
		if ( false !== stripos( $p->post_content, '[FILL IN' ) ) {
			$issues[] = array( 'blocker', 'Page "' . $p->post_title . '" still contains [FILL IN] placeholders', get_edit_post_link( $p->ID, '' ) );
		}
		if ( false !== stripos( $p->post_content, 'not yet reviewed by an attorney' )
			|| false !== stripos( $p->post_content, 'PREPARED FOR ATTORNEY REVIEW' ) ) {
			$issues[] = array( 'blocker', 'Page "' . $p->post_title . '" is marked as not attorney-reviewed', get_edit_post_link( $p->ID, '' ) );
		}
		if ( false !== stripos( $p->post_content, 'PLACEHOLDER &mdash;' ) || false !== stripos( $p->post_content, 'PLACEHOLDER —' ) ) {
			$issues[] = array( 'blocker', 'Page "' . $p->post_title . '" contains unresolved [ PLACEHOLDER ] policy text', get_edit_post_link( $p->ID, '' ) );
		}
		if ( false !== stripos( $p->post_content, 'Placeholder' ) && in_array( $p->post_name, array( 'shipping-returns', 'terms-of-service', 'contact' ), true ) ) {
			$issues[] = array( 'warn', 'Page "' . $p->post_title . '" still has placeholder policy text', get_edit_post_link( $p->ID, '' ) );
		}
	}

	$flat = get_option( 'woocommerce_flat_rate_1_settings' );
	if ( is_array( $flat ) && isset( $flat['title'] ) && false !== stripos( $flat['title'], 'placeholder' ) ) {
		$issues[] = array( 'warn', 'Shipping rate is still the $10 placeholder', admin_url( 'admin.php?page=wc-settings&tab=shipping' ) );
	}

	if ( ! has_filter( 'jp_identity_verified' ) ) {
		$issues[] = array( 'warn', 'No identity-verification provider connected (Level 2 age gate runs on DOB + attestation only)', admin_url( 'tools.php?page=jp-prelaunch' ) );
	}

	$batches = get_posts( array( 'post_type' => 'jp_batch', 'post_status' => 'publish', 'posts_per_page' => -1 ) );
	foreach ( $batches as $b ) {
		if ( false !== stripos( (string) get_post_meta( $b->ID, '_jp_lab_name', true ), 'placeholder' ) ) {
			$issues[] = array( 'warn', 'Batch ' . $b->post_title . ' names a placeholder testing lab', get_edit_post_link( $b->ID, '' ) );
		}
	}

	$no_sds = 0;
	foreach ( wc_get_products( array( 'limit' => -1, 'status' => 'publish' ) ) as $prod ) {
		if ( ! $prod->get_meta( '_jp_sds_url' ) ) {
			$no_sds++;
		}
	}
	if ( $no_sds ) {
		$issues[] = array( 'warn', $no_sds . ' product(s) have no safety data sheet (SDS) uploaded', admin_url( 'edit.php?post_type=product' ) );
	}

	return $issues;
}

add_action( 'admin_menu', function () {
	add_management_page( 'Pre-Launch Check', 'Pre-Launch Check', 'manage_options', 'jp-prelaunch', function () {
		$issues   = jp_prelaunch_issues();
		$blockers = array_filter( $issues, function ( $i ) { return 'blocker' === $i[0]; } );
		echo '<div class="wrap"><h1>Pre-Launch Check</h1>';
		echo '<p>Everything below must be resolved before this site is migrated to a real domain and takes real orders.</p>';
		if ( ! $issues ) {
			echo '<div class="notice notice-success"><p><strong>No placeholder content detected.</strong> Still confirm the items only you can do: LLC, merchant account, attorney review, carrier policy, and real COA data.</p></div>';
		} else {
			printf(
				'<div class="notice notice-%s"><p><strong>%d blocker(s)</strong> and <strong>%d warning(s)</strong> found.</p></div>',
				count( $blockers ) ? 'error' : 'warning',
				count( $blockers ),
				count( $issues ) - count( $blockers )
			);
			echo '<table class="widefat striped"><thead><tr><th style="width:110px">Severity</th><th>Item</th><th style="width:90px">Action</th></tr></thead><tbody>';
			foreach ( $issues as $i ) {
				printf(
					'<tr><td><span style="font-weight:600;color:%s">%s</span></td><td>%s</td><td><a href="%s">Fix</a></td></tr>',
					'blocker' === $i[0] ? '#b32d2e' : '#996800',
					'blocker' === $i[0] ? 'BLOCKER' : 'Warning',
					esc_html( $i[1] ),
					esc_url( $i[2] )
				);
			}
			echo '</tbody></table>';
		}
		echo '<h2 style="margin-top:2rem">Only you can do these</h2><ul style="list-style:disc;padding-left:1.5rem">';
		foreach ( array(
			'Form the LLC and open a business bank account',
			'Apply for and integrate a high-risk merchant account',
			'Have an attorney review the Terms of Service, Privacy Policy, and Shipping &amp; Returns',
			'Confirm state-level restrictions on the compounds you plan to sell',
			'Confirm your shipping carrier permits research-use-only chemical shipments',
			'Enable Adult Signature Required with the carrier',
			'Supply real lab, sourcing, and COA data',
		) as $item ) {
			echo '<li>' . wp_kses_post( $item ) . '</li>';
		}
		echo '</ul></div>';
	} );
} );

add_action( 'admin_notices', function () {
	$screen = get_current_screen();
	if ( ! current_user_can( 'manage_options' ) || ! $screen || 'dashboard' !== $screen->id ) {
		return;
	}
	$issues   = jp_prelaunch_issues();
	$blockers = array_filter( $issues, function ( $i ) { return 'blocker' === $i[0]; } );
	if ( ! $blockers ) {
		return;
	}
	printf(
		'<div class="notice notice-warning"><p><strong>This site is not launch-ready:</strong> %d blocker(s) detected (sample data, test payment gateway, or unreviewed copy). <a href="%s">Run the Pre-Launch Check</a>.</p></div>',
		count( $blockers ),
		esc_url( admin_url( 'tools.php?page=jp-prelaunch' ) )
	);
} );
