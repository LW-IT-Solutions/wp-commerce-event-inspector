<?php
/**
 * Plugin Name: Commerce Event Inspector
 * Description: Checks the GA4 e-commerce events your WooCommerce shop pushes against the real orders, cart and products: missing or duplicate purchase events, wrong transaction IDs, values, currencies and items.
 * Version: 0.1.0
 * Requires at least: 6.5
 * Requires PHP: 7.4
 * Requires Plugins: woocommerce
 * Author: LW IT Solutions
 * Author URI: https://www.lukaswojcik.com/
 * License: GPL-2.0-or-later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: commerce-event-inspector
 * WC requires at least: 8.0
 * WC tested up to: 11.1
 *
 * @package CommerceEventInspector
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
define( 'CEVI_FILE', __FILE__ );
require_once __DIR__ . '/includes/class-cevi-context.php';
require_once __DIR__ . '/includes/class-cevi-store.php';

add_action( 'before_woocommerce_init', 'cevi_declare_compatibility' );
add_action( 'rest_api_init', array( 'CEVI_Store', 'routes' ) );
add_action( 'woocommerce_thankyou', 'cevi_remember_order', 1 );
add_action( 'wp_enqueue_scripts', 'cevi_enqueue' );
add_action( 'wp_footer', 'cevi_localize', 1 );
add_action( 'admin_menu', 'cevi_admin_menu' );
add_action( 'admin_enqueue_scripts', 'cevi_admin_assets' );
add_action( 'admin_post_cevi_save', 'cevi_handle_save' );
add_action( 'add_meta_boxes', 'cevi_meta_boxes' );
add_action( 'admin_init', 'cevi_privacy_text' );

/** Declare support for the order tables and the cart and checkout blocks. */
function cevi_declare_compatibility() {
	if ( class_exists( '\Automattic\WooCommerce\Utilities\FeaturesUtil' ) ) {
		\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'custom_order_tables', CEVI_FILE, true );
		\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'cart_checkout_blocks', CEVI_FILE, true );
	}
}

/**
 * The order shown on the confirmation page. WooCommerce has checked access to it at this point.
 *
 * @param int|WC_Order|null $order Order ID or order.
 * @return WC_Order|null
 */
function cevi_remember_order( $order = null ) {
	static $current = null;
	if ( null !== $order ) {
		$found   = $order instanceof WC_Order ? $order : wc_get_order( $order );
		$current = $found ? $found : null;
	}
	return $current;
}

/**
 * Is the inspector panel on for this user?
 *
 * @return bool
 */
function cevi_inspector_on() {
	return is_user_logged_in() && current_user_can( 'manage_woocommerce' ) && get_user_meta( get_current_user_id(), 'cevi_inspector', true );
}

/** Load the capture script where it is needed: the order confirmation page while recording, or everywhere for the inspector. */
function cevi_enqueue() {
	if ( ! function_exists( 'is_order_received_page' ) ) {
		return;
	}
	$record = CEVI_Store::settings()['record'] && is_order_received_page();
	if ( ! $record && ! cevi_inspector_on() ) {
		return;
	}
	wp_enqueue_script( 'cevi-capture', plugins_url( 'assets/capture.js', CEVI_FILE ), array(), '0.1.0', true );
	if ( cevi_inspector_on() ) {
		wp_enqueue_style( 'cevi-inspector', plugins_url( 'assets/inspector.css', CEVI_FILE ), array(), '0.1.0' );
	}
}

/** Pass settings, shop data and texts to the script; runs before the footer scripts are printed. */
function cevi_localize() {
	if ( ! wp_script_is( 'cevi-capture', 'enqueued' ) ) {
		return;
	}
	$order   = cevi_remember_order();
	$context = null;
	if ( $order ) {
		$context = CEVI_Context::order( $order );
	} elseif ( is_product() ) {
		$product = wc_get_product( get_queried_object_id() );
		$context = $product ? CEVI_Context::product( $product ) : null;
	} elseif ( ( is_cart() || is_checkout() ) && WC()->cart ) {
		$context = CEVI_Context::cart( WC()->cart );
	}
	$record = CEVI_Store::settings()['record'] && $order;
	wp_localize_script(
		'cevi-capture',
		'CEVI',
		array(
			'rest'      => rest_url( 'cevi/v1/observe' ),
			'currency'  => get_woocommerce_currency(),
			'staff'     => current_user_can( 'manage_woocommerce' ),
			'inspector' => cevi_inspector_on(),
			'order'     => $record ? array(
				'id'  => $order->get_id(),
				'key' => $order->get_order_key(),
			) : null,
			'context'   => cevi_inspector_on() ? $context : null,
			'text'      => cevi_script_texts(),
		)
	);
}

/**
 * Texts of the inspector panel.
 *
 * @return array
 */
function cevi_script_texts() {
	return array(
		'title'            => __( 'Event Inspector', 'commerce-event-inspector' ),
		'none'             => __( 'No GA4 e-commerce event on this page yet. Events from clicks appear when they happen.', 'commerce-event-inspector' ),
		'noContext'        => __( 'Checked against the GA4 rules only; this page has no product, cart or order to compare with.', 'commerce-event-inspector' ),
		'context'          => array(
			'product' => __( 'Compared with this product and its variations.', 'commerce-event-inspector' ),
			'cart'    => __( 'Compared with the current cart.', 'commerce-event-inspector' ),
			'order'   => __( 'Compared with this order.', 'commerce-event-inspector' ),
		),
		'legacy'           => __( 'Older Universal Analytics format; GA4 expects transaction_id, value, currency and items.', 'commerce-event-inspector' ),
		'noTransaction'    => __( 'transaction_id is missing.', 'commerce-event-inspector' ),
		'valueNotNumber'   => __( 'value is not a number.', 'commerce-event-inspector' ),
		/* translators: %s: value as sent. */
		'valueText'        => __( 'value "%s" is text, not a number; GA4 expects a number with a decimal point, such as 143.70.', 'commerce-event-inspector' ),
		'noCurrency'       => __( 'currency is missing; GA4 needs it together with value.', 'commerce-event-inspector' ),
		/* translators: 1: currency in the event, 2: shop currency. */
		'otherCurrency'    => __( 'Currency %1$s, the shop uses %2$s.', 'commerce-event-inspector' ),
		'noItems'          => __( 'items is missing or empty.', 'commerce-event-inspector' ),
		'itemNoId'         => __( 'An item has neither item_id nor item_name.', 'commerce-event-inspector' ),
		/* translators: 1: item, 2: quantity. */
		'badQty'           => __( 'Item %1$s: quantity %2$s is not a whole number above zero.', 'commerce-event-inspector' ),
		/* translators: %s: item ID or name. */
		'itemUnknown'      => __( 'Item %s does not match this product, cart or order (no SKU, product ID or variation ID).', 'commerce-event-inspector' ),
		/* translators: 1: item, 2: quantity in the event, 3: quantity in the shop. */
		'itemQty'          => __( 'Item %1$s: quantity %2$s, the shop has %3$s.', 'commerce-event-inspector' ),
		/* translators: %s: item. */
		'itemNoPrice'      => __( 'Item %s has no price.', 'commerce-event-inspector' ),
		/* translators: 1: item, 2: price. */
		'itemGross'        => __( 'Item %1$s: price %2$s with tax.', 'commerce-event-inspector' ),
		/* translators: 1: item, 2: price. */
		'itemNet'          => __( 'Item %1$s: price %2$s without tax.', 'commerce-event-inspector' ),
		/* translators: 1: item, 2: price. */
		'itemFull'         => __( 'Item %1$s: price %2$s before discount.', 'commerce-event-inspector' ),
		/* translators: 1: item, 2: price in the event, 3: price with tax, 4: price without tax. */
		'itemPrice'        => __( 'Item %1$s: price %2$s, the shop has %3$s with tax or %4$s without.', 'commerce-event-inspector' ),
		/* translators: %s: product name. */
		'itemMissing'      => __( '%s is missing in the event.', 'commerce-event-inspector' ),
		/* translators: 1: value, 2: which total it equals. */
		'valueMatch'       => __( 'value %1$s equals %2$s.', 'commerce-event-inspector' ),
		/* translators: 1: value, 2: total. */
		'valueNoMatch'     => __( 'value %1$s matches no reading of the total %2$s.', 'commerce-event-inspector' ),
		'values'           => array(
			'total'           => __( 'the total including tax and shipping', 'commerce-event-inspector' ),
			'no_shipping'     => __( 'the total including tax, without shipping', 'commerce-event-inspector' ),
			'net'             => __( 'the total without tax, including shipping', 'commerce-event-inspector' ),
			'net_no_shipping' => __( 'the total without tax and shipping', 'commerce-event-inspector' ),
		),
		/* translators: %s: transaction ID. */
		'transactionOk'    => __( 'transaction_id %s matches the order.', 'commerce-event-inspector' ),
		/* translators: 1: transaction ID, 2: order number. */
		'transactionOther' => __( 'transaction_id %1$s does not match the order number %2$s.', 'commerce-event-inspector' ),
		/* translators: %s: number of purchase events. */
		'duplicate'        => __( 'purchase pushed %s times on this page.', 'commerce-event-inspector' ),
		'allOk'            => __( 'No problem found.', 'commerce-event-inspector' ),
		/* translators: %s: path of the previous page. */
		'previous'         => __( 'Previous page in this tab: %s', 'commerce-event-inspector' ),
	);
}

/** Register the report under WooCommerce. */
function cevi_admin_menu() {
	add_submenu_page( 'woocommerce', __( 'Commerce Event Inspector', 'commerce-event-inspector' ), __( 'Event Inspector', 'commerce-event-inspector' ), 'manage_woocommerce', 'commerce-event-inspector', 'cevi_admin_page' );
}

/**
 * Admin styles on our screens.
 *
 * @param string $hook Current screen.
 */
function cevi_admin_assets( $hook ) {
	$screen = get_current_screen();
	if ( 'woocommerce_page_commerce-event-inspector' === $hook || ( $screen && in_array( $screen->id, array( 'shop_order', 'woocommerce_page_wc-orders' ), true ) ) ) {
		wp_enqueue_style( 'cevi-admin', plugins_url( 'assets/admin.css', CEVI_FILE ), array(), '0.1.0' );
	}
}

/**
 * Status label.
 *
 * @param string $status none, missing, ok, warn or fail.
 * @return string
 */
function cevi_status_label( $status ) {
	switch ( $status ) {
		case 'ok':
			return __( 'Matches the order', 'commerce-event-inspector' );
		case 'warn':
			return __( 'Matches with notes', 'commerce-event-inspector' );
		case 'fail':
			return __( 'Does not match', 'commerce-event-inspector' );
		case 'missing':
			return __( 'No purchase event', 'commerce-event-inspector' );
		default:
			return __( 'Not observed', 'commerce-event-inspector' );
	}
}

/**
 * Checks of a verdict as list items.
 *
 * @param array $verdict Verdict.
 */
function cevi_print_checks( $verdict ) {
	if ( 'none' === $verdict['status'] ) {
		echo '<p class="cevi-muted">' . esc_html__( 'The order confirmation page has not been viewed while recording was on.', 'commerce-event-inspector' ) . '</p>';
		return;
	}
	echo '<ul class="cevi-checks">';
	foreach ( $verdict['checks'] as $check ) {
		echo '<li class="cevi-' . esc_attr( $check[0] ) . '">' . esc_html( CEVI_Store::describe( $check ) ) . '</li>';
	}
	echo '</ul>';
}

/** Render the report and settings. */
function cevi_admin_page() {
	if ( ! current_user_can( 'manage_woocommerce' ) ) {
		return;
	}
	$settings = CEVI_Store::settings();
	$mine     = (bool) get_user_meta( get_current_user_id(), 'cevi_inspector', true );
	$orders   = array();
	if ( $settings['since'] ) {
		$orders = wc_get_orders(
			array(
				'type'         => 'shop_order',
				'limit'        => 100,
				'orderby'      => 'date',
				'order'        => 'DESC',
				'date_created' => '>=' . $settings['since'],
			)
		);
		// Orders placed shortly before recording started can still be observed; they are listed too.
		$seen  = array_map(
			function ( $o ) {
				return $o->get_id();
			},
			$orders
		);
		$index = get_option( CEVI_Store::INDEX );
		foreach ( array_slice( array_reverse( is_array( $index ) ? $index : array() ), 0, 100 ) as $id ) {
			$order = in_array( (int) $id, $seen, true ) ? false : wc_get_order( (int) $id );
			if ( $order ) {
				$orders[] = $order;
			}
		}
		usort(
			$orders,
			function ( $a, $b ) {
				return $b->get_id() - $a->get_id();
			}
		);
	}
	$count = array(
		'ok'      => 0,
		'warn'    => 0,
		'fail'    => 0,
		'missing' => 0,
		'none'    => 0,
	);
	$rows  = array();
	foreach ( $orders as $order ) {
		$verdict = CEVI_Store::verdict( $order );
		++$count[ $verdict['status'] ];
		$rows[] = array( $order, $verdict );
	}
	$saved = get_transient( 'cevi_saved_' . get_current_user_id() );
	delete_transient( 'cevi_saved_' . get_current_user_id() );
	?>
	<div class="wrap cevi-wrap">
		<h1><?php esc_html_e( 'Commerce Event Inspector', 'commerce-event-inspector' ); ?></h1>
		<?php if ( $saved ) : ?>
			<div class="notice notice-success"><p><?php esc_html_e( 'Settings saved.', 'commerce-event-inspector' ); ?></p></div>
		<?php endif; ?>
		<p><?php esc_html_e( 'Do the GA4 e-commerce events of this shop match the real orders? The plugin reads what tracking plugins and tags push to the dataLayer or send with gtag() and compares it with WooCommerce: transaction ID, value, currency and items of each order, and on product, cart and checkout pages the products and prices.', 'commerce-event-inspector' ); ?></p>

		<h2><?php esc_html_e( 'Settings', 'commerce-event-inspector' ); ?></h2>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
			<input type="hidden" name="action" value="cevi_save">
			<?php wp_nonce_field( 'cevi_save' ); ?>
			<p>
				<label>
					<input type="checkbox" name="cevi_record" value="1" <?php checked( $settings['record'] ); ?>>
					<strong><?php esc_html_e( 'Check purchase events on the order confirmation page', 'commerce-event-inspector' ); ?></strong>
				</label><br>
				<span class="cevi-muted"><?php esc_html_e( 'When a customer sees the order confirmation, a small script reads the e-commerce events on that page and sends them to this site, where they are stored with the order. No cookies, no requests to other services; only event names, IDs, amounts, currencies and item IDs are kept.', 'commerce-event-inspector' ); ?></span>
			</p>
			<p>
				<label>
					<input type="checkbox" name="cevi_inspector" value="1" <?php checked( $mine ); ?>>
					<strong><?php esc_html_e( 'Show the inspector panel in the shop for my account', 'commerce-event-inspector' ); ?></strong>
				</label><br>
				<span class="cevi-muted"><?php esc_html_e( 'While logged in, a panel lists every e-commerce event with its checks. Some tracking plugins skip logged-in shop managers; in that case the panel stays empty.', 'commerce-event-inspector' ); ?></span>
			</p>
			<?php submit_button( __( 'Save settings', 'commerce-event-inspector' ), 'primary', 'submit', false ); ?>
		</form>

		<h2><?php esc_html_e( 'Orders', 'commerce-event-inspector' ); ?></h2>
		<?php if ( ! $settings['since'] ) : ?>
			<p><?php esc_html_e( 'Recording has not been switched on yet. The report lists orders placed since then.', 'commerce-event-inspector' ); ?></p>
		<?php else : ?>
			<p>
				<?php
				echo esc_html(
					sprintf(
						/* translators: 1: date, 2: number of orders, 3: matching, 4: with notes, 5: not matching, 6: without purchase event, 7: not observed. */
						__( '%2$d orders placed since %1$s or observed since then. %3$d match, %4$d match with notes, %5$d do not match, %6$d without purchase event, %7$d not observed.', 'commerce-event-inspector' ),
						wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), $settings['since'] ),
						count( $rows ),
						$count['ok'],
						$count['warn'],
						$count['fail'],
						$count['missing'],
						$count['none']
					)
				);
				?>
			</p>
			<?php if ( $rows ) : ?>
				<div class="cevi-table">
					<table class="widefat striped">
						<thead><tr>
							<th scope="col"><?php esc_html_e( 'Order', 'commerce-event-inspector' ); ?></th>
							<th scope="col"><?php esc_html_e( 'Total', 'commerce-event-inspector' ); ?></th>
							<th scope="col"><?php esc_html_e( 'Result', 'commerce-event-inspector' ); ?></th>
							<th scope="col"><?php esc_html_e( 'Checks', 'commerce-event-inspector' ); ?></th>
						</tr></thead>
						<tbody>
						<?php foreach ( $rows as $row ) : ?>
							<?php list( $order, $verdict ) = $row; ?>
							<tr>
								<td>
									<a href="<?php echo esc_url( $order->get_edit_order_url() ); ?>">#<?php echo esc_html( $order->get_order_number() ); ?></a><br>
									<span class="cevi-muted"><?php echo esc_html( $order->get_date_created() ? wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), $order->get_date_created()->getTimestamp() ) : '' ); ?></span>
								</td>
								<td><?php echo wp_kses_post( wc_price( $order->get_total(), array( 'currency' => $order->get_currency() ) ) ); ?></td>
								<td><span class="cevi-badge cevi-badge-<?php echo esc_attr( $verdict['status'] ); ?>"><?php echo esc_html( cevi_status_label( $verdict['status'] ) ); ?></span></td>
								<td><?php cevi_print_checks( $verdict ); ?></td>
							</tr>
						<?php endforeach; ?>
						</tbody>
					</table>
				</div>
			<?php endif; ?>
		<?php endif; ?>

		<h2><?php esc_html_e( 'Limits', 'commerce-event-inspector' ); ?></h2>
		<p><?php esc_html_e( 'The plugin sees what the page pushes to the dataLayer or sends with gtag(), not whether Google Analytics received it. Ad blockers and missing consent can still stop the request. Events sent only from the server, or by a tag inside a Tag Manager container that the page does not push, are not visible. Orders without a viewed confirmation page, such as orders created in the admin, are listed as not observed.', 'commerce-event-inspector' ); ?></p>
	</div>
	<?php
}

/** Save the settings. */
function cevi_handle_save() {
	if ( ! current_user_can( 'manage_woocommerce' ) ) {
		wp_die( esc_html__( 'You do not have permission to change these settings.', 'commerce-event-inspector' ), 403 );
	}
	check_admin_referer( 'cevi_save' );
	CEVI_Store::set_record( ! empty( $_POST['cevi_record'] ) );
	if ( empty( $_POST['cevi_inspector'] ) ) {
		delete_user_meta( get_current_user_id(), 'cevi_inspector' );
	} else {
		update_user_meta( get_current_user_id(), 'cevi_inspector', 1 );
	}
	set_transient( 'cevi_saved_' . get_current_user_id(), 1, MINUTE_IN_SECONDS );
	wp_safe_redirect( admin_url( 'admin.php?page=commerce-event-inspector' ) );
	exit;
}

/** Show the result on the order screen, with or without the order tables. */
function cevi_meta_boxes() {
	foreach ( array( 'shop_order', 'woocommerce_page_wc-orders' ) as $screen ) {
		add_meta_box( 'cevi-order', __( 'GA4 purchase event', 'commerce-event-inspector' ), 'cevi_meta_box', $screen, 'side' );
	}
}

/**
 * Order screen box.
 *
 * @param WP_Post|WC_Order $object Post or order.
 */
function cevi_meta_box( $object ) {
	$order = $object instanceof WP_Post ? wc_get_order( $object->ID ) : $object;
	if ( ! $order instanceof WC_Order ) {
		return;
	}
	$verdict = CEVI_Store::verdict( $order );
	echo '<p><span class="cevi-badge cevi-badge-' . esc_attr( $verdict['status'] ) . '">' . esc_html( cevi_status_label( $verdict['status'] ) ) . '</span></p>';
	cevi_print_checks( $verdict );
	if ( $verdict['views'] ) {
		/* translators: %d: number of views. */
		echo '<p class="cevi-muted">' . esc_html( sprintf( _n( 'Confirmation page viewed %d time while recording.', 'Confirmation page viewed %d times while recording.', $verdict['views'], 'commerce-event-inspector' ), $verdict['views'] ) ) . '</p>';
	}
}

/** Suggested text for the privacy policy guide. */
function cevi_privacy_text() {
	if ( ! function_exists( 'wp_add_privacy_policy_content' ) ) {
		return;
	}
	wp_add_privacy_policy_content(
		__( 'Commerce Event Inspector', 'commerce-event-inspector' ),
		'<p>' . esc_html__( 'When checking of purchase events is switched on, the order confirmation page reads the analytics events this site prepares for the order and sends them to this site. They are stored with the order: event names, transaction ID, value, currency and the IDs, prices and quantities of the items. No cookies are set and nothing is sent to other services. The data is deleted with the order or when the plugin is uninstalled.', 'commerce-event-inspector' ) . '</p>'
	);
}
