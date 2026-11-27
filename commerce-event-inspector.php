<?php
/**
 * Plugin Name: Commerce Event Inspector
 * Description: Checks the GA4 e-commerce events your WooCommerce shop pushes against the real orders, cart and products: missing or duplicate purchase events, wrong transaction IDs, values, currencies and items.
 * Version: 0.3.0
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
define( 'CEVI_VERSION', '0.3.0' );
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
	wp_enqueue_script( 'cevi-capture', plugins_url( 'assets/capture.js', CEVI_FILE ), array(), CEVI_VERSION, true );
	if ( cevi_inspector_on() ) {
		wp_enqueue_style( 'cevi-inspector', plugins_url( 'assets/inspector.css', CEVI_FILE ), array(), CEVI_VERSION );
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
			// Lets the report tell views by shop managers from customer views; customers get no nonce.
			'nonce'     => current_user_can( 'manage_woocommerce' ) ? wp_create_nonce( 'wp_rest' ) : '',
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
		wp_enqueue_style( 'cevi-admin', plugins_url( 'assets/admin.css', CEVI_FILE ), array( 'dashicons' ), CEVI_VERSION );
	}
	if ( 'woocommerce_page_commerce-event-inspector' === $hook ) {
		wp_enqueue_script( 'cevi-admin', plugins_url( 'assets/admin.js', CEVI_FILE ), array(), CEVI_VERSION, true );
		wp_localize_script(
			'cevi-admin',
			'CEVI_ADMIN',
			array(
				/* translators: 1: number of orders shown, 2: number of all orders. */
				'shown'    => __( '%1$d of %2$d orders shown.', 'commerce-event-inspector' ),
				/* translators: %s: active filters, separated by commas. */
				'filter'   => __( 'Filter: %s', 'commerce-event-inspector' ),
				/* translators: %s: search text. */
				'search'   => __( 'Search "%s"', 'commerce-event-inspector' ),
				'none'     => __( 'No order matches the search and filters.', 'commerce-event-inspector' ),
				'copied'   => __( 'Copied to the clipboard.', 'commerce-event-inspector' ),
				'copyNone' => __( 'No order with a problem or note to copy.', 'commerce-event-inspector' ),
				'copyFail' => __( 'Copying was not possible. The text is selected below and can be copied with Ctrl+C.', 'commerce-event-inspector' ),
			)
		);
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
 * Status badge with icon.
 *
 * @param string $status none, missing, ok, warn or fail.
 */
function cevi_print_badge( $status ) {
	echo '<span class="cevi-badge cevi-badge-' . esc_attr( $status ) . '"><span class="dashicons dashicons-' . esc_attr( cevi_status_icon( $status ) ) . '" aria-hidden="true"></span>' . esc_html( cevi_status_label( $status ) ) . '</span>';
}

/**
 * Checks of a verdict as list items, problems first.
 *
 * @param array $verdict Verdict.
 */
function cevi_print_checks( $verdict ) {
	if ( 'none' === $verdict['status'] ) {
		echo '<p class="cevi-muted">' . esc_html__( 'The order confirmation page has not been viewed while recording was on.', 'commerce-event-inspector' ) . '</p>';
		return;
	}
	$order  = array(
		'fail' => 0,
		'warn' => 1,
		'ok'   => 2,
	);
	$checks = array_values( $verdict['checks'] );
	$keys   = array_keys( $checks );
	// Stable: within the same level the original order stays.
	usort(
		$keys,
		function ( $a, $b ) use ( $checks, $order ) {
			$la = isset( $order[ $checks[ $a ][0] ] ) ? $order[ $checks[ $a ][0] ] : 3;
			$lb = isset( $order[ $checks[ $b ][0] ] ) ? $order[ $checks[ $b ][0] ] : 3;
			return $la === $lb ? $a - $b : $la - $lb;
		}
	);
	$words = array(
		'ok'   => __( 'OK', 'commerce-event-inspector' ),
		'warn' => __( 'Note', 'commerce-event-inspector' ),
		'fail' => __( 'Problem', 'commerce-event-inspector' ),
	);
	echo '<ul class="cevi-checks">';
	foreach ( $keys as $k ) {
		$check = $checks[ $k ];
		$word  = isset( $words[ $check[0] ] ) ? $words[ $check[0] ] : '';
		echo '<li class="cevi-' . esc_attr( $check[0] ) . '"><span class="screen-reader-text">' . esc_html( $word ) . ': </span>' . esc_html( CEVI_Store::describe( $check ) ) . '</li>';
	}
	echo '</ul>';
}

/**
 * Status icons (dashicons) per verdict.
 *
 * @param string $status none, missing, ok, warn or fail.
 * @return string Dashicon name without prefix.
 */
function cevi_status_icon( $status ) {
	$icons = array(
		'ok'      => 'yes-alt',
		'warn'    => 'warning',
		'fail'    => 'dismiss',
		'missing' => 'minus',
		'none'    => 'hidden',
	);
	return isset( $icons[ $status ] ) ? $icons[ $status ] : 'marker';
}

/**
 * Value of the purchase event compared with the order total, from the stored observation
 * that the verdict uses (first customer view, else the first view).
 *
 * @param WC_Order $order Order.
 * @return array|null total, value (float or null if not a number), raw; null without a purchase event.
 */
function cevi_value_gap( $order ) {
	$list = CEVI_Store::observations( $order );
	if ( ! $list ) {
		return null;
	}
	$first = $list[0];
	foreach ( $list as $obs ) {
		if ( empty( $obs['staff'] ) ) {
			$first = $obs;
			break;
		}
	}
	foreach ( (array) $first['events'] as $event ) {
		if ( isset( $event['name'] ) && 'purchase' === $event['name'] ) {
			return array(
				'total' => (float) $order->get_total(),
				'value' => isset( $event['value'] ) && is_numeric( $event['value'] ) ? (float) $event['value'] : null,
				'raw'   => isset( $event['value_raw'] ) ? (string) $event['value_raw'] : '',
			);
		}
	}
	return null;
}

/**
 * One bar of the value chart: the difference between event value and order total,
 * drawn from the middle (left = less, right = more).
 *
 * @param array    $gap   Result of cevi_value_gap().
 * @param WC_Order $order Order.
 * @param float    $scale Largest difference in per cent (100 = full half width).
 */
function cevi_print_gap( $gap, $order, $scale ) {
	if ( null === $gap['value'] ) {
		/* translators: %s: value as sent. */
		echo '<span class="cevi-gap-text cevi-gap-bad">' . esc_html( sprintf( __( 'value "%s" is not a number', 'commerce-event-inspector' ), $gap['raw'] ) ) . '</span>';
		return;
	}
	$diff = $gap['value'] - $gap['total'];
	$pct  = $gap['total'] > 0 ? $diff / $gap['total'] * 100 : 0;
	if ( abs( $diff ) < 0.011 ) {
		echo '<span class="cevi-gap-text cevi-gap-ok">' . esc_html__( 'equals the order total', 'commerce-event-inspector' ) . '</span>';
		return;
	}
	$width = min( 50, abs( $pct ) / max( 1, $scale ) * 50 );
	$style = ( $diff < 0 ? 'right:50%;' : 'left:50%;' ) . 'width:' . round( max( 1.5, $width ), 1 ) . '%';
	$sign  = $diff < 0 ? '−' : '+';
	echo '<span class="cevi-gap-track" aria-hidden="true"><span class="cevi-gap-bar ' . esc_attr( $diff < 0 ? 'is-less' : 'is-more' ) . '" style="' . esc_attr( $style ) . '"></span></span>';
	echo '<span class="cevi-gap-text">' . esc_html( $sign . number_format_i18n( abs( $diff ), 2 ) . ' ' . $order->get_currency() . ' (' . $sign . number_format_i18n( abs( $pct ), 1 ) . ' %)' ) . '</span>';
}

/**
 * Status ring: one arc per result, the share of matching orders in the middle.
 * Without JavaScript it is a picture with a text alternative; the script makes the arcs filter buttons.
 *
 * @param array $count  Orders per status.
 * @param array $labels Labels per status.
 * @param int   $total  Number of orders.
 */
function cevi_print_ring( $count, $labels, $total ) {
	/* translators: 1: matching, 2: with notes, 3: not matching, 4: without purchase event, 5: not observed. */
	$share  = sprintf( __( 'Matching: %1$d, with notes: %2$d, not matching: %3$d, without purchase event: %4$d, not observed: %5$d.', 'commerce-event-inspector' ), $count['ok'], $count['warn'], $count['fail'], $count['missing'], $count['none'] );
	$offset = 25;
	$ok     = $total ? (int) round( $count['ok'] / $total * 100 ) : 0;
	echo '<figure class="cevi-ring">';
	echo '<svg viewBox="0 0 42 42" class="cevi-ring-svg" role="img" aria-label="' . esc_attr( $share ) . '" focusable="false">';
	echo '<circle class="cevi-ring-bg" cx="21" cy="21" r="15.9155" fill="none" stroke-width="5"></circle>';
	foreach ( $labels as $key => $label ) {
		if ( ! $count[ $key ] ) {
			continue;
		}
		$pct = $count[ $key ] / $total * 100;
		// Small gap between arcs, but never more than the arc itself.
		$gap  = $total > 1 && $pct < 100 ? min( 0.8, $pct / 3 ) : 0;
		$tip  = sprintf(
			/* translators: 1: result, 2: number of orders, 3: share in per cent. */
			_n( '%1$s: %2$d order (%3$s %%)', '%1$s: %2$d orders (%3$s %%)', $count[ $key ], 'commerce-event-inspector' ),
			$label,
			$count[ $key ],
			number_format_i18n( $pct )
		);
		printf(
			'<circle class="cevi-ring-seg cevi-ring-%1$s" cx="21" cy="21" r="15.9155" fill="none" stroke-width="5" stroke-dasharray="%2$s %3$s" stroke-dashoffset="%4$s" data-cevi-filter="%1$s" data-cevi-tip="%5$s"><title>%5$s</title></circle>',
			esc_attr( $key ),
			esc_attr( round( $pct - $gap, 3 ) ),
			esc_attr( round( 100 - $pct + $gap, 3 ) ),
			esc_attr( round( $offset, 3 ) ),
			esc_attr( $tip )
		);
		$offset -= $pct;
	}
	echo '</svg>';
	echo '<figcaption class="cevi-ring-center" aria-hidden="true"><strong data-cevi-count="' . esc_attr( $ok ) . '" data-cevi-suffix=" %">' . esc_html( number_format_i18n( $ok ) . ' %' ) . '</strong><span>' . esc_html__( 'matching', 'commerce-event-inspector' ) . '</span></figcaption>';
	echo '</figure>';
}

/**
 * Orders per day of the last 14 days, stacked by result. Each day is a button that filters the list.
 *
 * @param array $rows   Rows of the report (order, verdict, gap, day).
 * @param array $labels Labels per status.
 */
function cevi_print_days( $rows, $labels ) {
	$per = array();
	foreach ( $rows as $r ) {
		if ( '' === $r[3] ) {
			continue;
		}
		if ( ! isset( $per[ $r[3] ] ) ) {
			$per[ $r[3] ] = array_fill_keys( array_keys( $labels ), 0 );
		}
		++$per[ $r[3] ][ $r[1]['status'] ];
	}
	if ( ! $per ) {
		return;
	}
	$tz    = wp_timezone();
	$today = new DateTimeImmutable( 'now', $tz );
	$last  = max( array_keys( $per ) );
	// Without orders in the last two weeks, the chart ends on the day of the newest order.
	$end = $last < $today->modify( '-13 days' )->format( 'Y-m-d' ) ? new DateTimeImmutable( $last, $tz ) : $today;
	$max = 1;
	foreach ( $per as $c ) {
		$max = max( $max, array_sum( $c ) );
	}
	$days = array();
	for ( $i = 13; $i >= 0; $i-- ) {
		$days[] = $end->modify( '-' . $i . ' days' );
	}
	$order = array( 'none', 'missing', 'fail', 'warn', 'ok' );
	echo '<ol class="cevi-days" style="' . esc_attr( '--cevi-days-max:' . $max ) . '">';
	foreach ( $days as $d ) {
		$key   = $d->format( 'Y-m-d' );
		$c     = isset( $per[ $key ] ) ? $per[ $key ] : array_fill_keys( array_keys( $labels ), 0 );
		$sum   = array_sum( $c );
		$date  = wp_date( get_option( 'date_format' ), $d->getTimestamp() );
		$parts = array();
		foreach ( $labels as $k => $label ) {
			if ( $c[ $k ] ) {
				$parts[] = $label . ': ' . number_format_i18n( $c[ $k ] );
			}
		}
		/* translators: 1: date, 2: number of orders. */
		$head  = sprintf( _n( '%1$s: %2$d order', '%1$s: %2$d orders', $sum, 'commerce-event-inspector' ), $date, $sum );
		$label = $head . ( $parts ? ' – ' . implode( ', ', $parts ) : '' );
		echo '<li>';
		if ( $sum ) {
			echo '<button type="button" class="cevi-day" data-cevi-day="' . esc_attr( $key ) . '" data-cevi-day-label="' . esc_attr( $date ) . '" data-cevi-tip="' . esc_attr( $label ) . '" aria-label="' . esc_attr( $label ) . '" aria-pressed="false" aria-controls="cevi-orders">';
			echo '<span class="cevi-day-bar" style="' . esc_attr( 'height:' . round( $sum / $max * 100, 1 ) . '%' ) . '">';
			foreach ( $order as $k ) {
				if ( $c[ $k ] ) {
					echo '<span class="cevi-day-part cevi-share-' . esc_attr( $k ) . '" style="' . esc_attr( 'flex-grow:' . (int) $c[ $k ] ) . '"></span>';
				}
			}
			echo '</span></button>';
		} else {
			echo '<span class="cevi-day cevi-day-empty" title="' . esc_attr( $label ) . '"><span class="screen-reader-text">' . esc_html( $label ) . '</span></span>';
		}
		echo '<span class="cevi-day-label" aria-hidden="true"><span class="cevi-day-num">' . esc_html( wp_date( 'j', $d->getTimestamp() ) ) . '</span><span class="cevi-day-wd">' . esc_html( wp_date( 'D', $d->getTimestamp() ) ) . '</span></span>';
		echo '</li>';
	}
	echo '</ol>';
}

/**
 * The purchase event next to the order data it was compared with, from the observation the verdict uses.
 *
 * @param WC_Order $order Order.
 */
function cevi_print_compare( $order ) {
	$list = CEVI_Store::observations( $order );
	if ( ! $list ) {
		return;
	}
	$first = $list[0];
	foreach ( $list as $obs ) {
		if ( empty( $obs['staff'] ) ) {
			$first = $obs;
			break;
		}
	}
	$event = null;
	foreach ( (array) $first['events'] as $e ) {
		if ( isset( $e['name'] ) && 'purchase' === $e['name'] ) {
			$event = $e;
			break;
		}
	}
	$ctx = isset( $first['order'] ) && is_array( $first['order'] ) ? $first['order'] : null;
	if ( ! $event || ! $ctx ) {
		return;
	}
	$money   = function ( $v ) {
		return null === $v ? '' : number_format_i18n( (float) $v, 2 );
	};
	$missing = '—';
	$tid     = (string) $event['transaction_id'];
	$tid_ok  = '' !== $tid && ( $tid === (string) $ctx['number'] || $tid === (string) $ctx['id'] );
	$val_ok  = false;
	foreach ( (array) $ctx['values'] as $amount ) {
		$val_ok = $val_ok || ( null !== $event['value'] && abs( (float) $event['value'] - (float) $amount ) < 0.011 );
	}
	$ev_items = array();
	foreach ( (array) $event['items'] as $i ) {
		$name       = '' !== $i['item_name'] ? $i['item_name'] : $i['item_id'];
		$ev_items[] = trim( $name . ( '' !== $i['item_id'] && $i['item_id'] !== $name ? ' (' . $i['item_id'] . ')' : '' ) ) . ' × ' . ( null === $i['quantity'] ? '?' : number_format_i18n( (float) $i['quantity'] ) ) . ' · ' . ( null === $i['price'] ? '?' : $money( $i['price'] ) );
	}
	$or_items = array();
	foreach ( (array) $ctx['items'] as $line ) {
		$or_items[] = $line['name'] . ' × ' . number_format_i18n( (float) $line['qty'] ) . ' · ' . $money( $line['gross'] );
	}
	$items_ok = true;
	foreach ( CEVI_Context::check_purchase( $event, $ctx ) as $check ) {
		if ( 'ok' !== $check[0] && ( 0 === strpos( $check[1], 'item' ) || 'no_items' === $check[1] ) ) {
			$items_ok = false;
		}
	}
	$rows = array(
		array( 'transaction_id', '' !== $tid ? $tid : $missing, $ctx['number'] . ( (string) $ctx['number'] !== (string) $ctx['id'] ? ' / ID ' . $ctx['id'] : '' ), $tid_ok ),
		array( 'value', null !== $event['value'] ? $money( $event['value'] ) : ( '' !== $event['value_raw'] ? '"' . $event['value_raw'] . '"' : $missing ), $money( $ctx['values']['total'] ), $val_ok ),
		array( 'currency', '' !== $event['currency'] ? $event['currency'] : $missing, $ctx['currency'], '' !== $event['currency'] && $event['currency'] === $ctx['currency'] ),
		array( 'items', $ev_items, $or_items, $items_ok && $ev_items ),
	);
	echo '<details class="cevi-compare"><summary>' . esc_html__( 'Event and order side by side', 'commerce-event-inspector' ) . '</summary>';
	echo '<table class="cevi-compare-table"><thead><tr><th scope="col"><span class="screen-reader-text">' . esc_html__( 'Field', 'commerce-event-inspector' ) . '</span></th><th scope="col">' . esc_html__( 'purchase event', 'commerce-event-inspector' ) . '</th><th scope="col">' . esc_html__( 'Order', 'commerce-event-inspector' ) . '</th></tr></thead><tbody>';
	foreach ( $rows as $row ) {
		$state = $row[3] ? 'is-same' : 'is-diff';
		echo '<tr class="' . esc_attr( $state ) . '"><th scope="row"><code>' . esc_html( $row[0] ) . '</code>';
		echo '<span class="cevi-compare-mark" aria-hidden="true"></span><span class="screen-reader-text">' . esc_html( $row[3] ? __( 'matches', 'commerce-event-inspector' ) : __( 'differs', 'commerce-event-inspector' ) ) . '</span></th>';
		foreach ( array( $row[1], $row[2] ) as $cell ) {
			echo '<td>';
			if ( is_array( $cell ) && $cell ) {
				echo '<ul>';
				foreach ( $cell as $line ) {
					echo '<li>' . esc_html( $line ) . '</li>';
				}
				echo '</ul>';
			} elseif ( is_array( $cell ) ) {
				echo esc_html( $missing );
			} else {
				echo esc_html( (string) $cell );
			}
			echo '</td>';
		}
		echo '</tr>';
	}
	echo '</tbody></table>';
	echo '<p class="cevi-muted">' . esc_html__( 'Item prices of the order: unit price including tax. Values within one cent count as equal; other readings of value (without tax or shipping) are named in the checks.', 'commerce-event-inspector' ) . '</p>';
	echo '</details>';
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
		$created = $order->get_date_created();
		$rows[]  = array( $order, $verdict, cevi_value_gap( $order ), $created ? wp_date( 'Y-m-d', $created->getTimestamp() ) : '' );
	}
	$saved  = get_transient( 'cevi_saved_' . get_current_user_id() );
	delete_transient( 'cevi_saved_' . get_current_user_id() );
	$labels = array(
		'ok'      => __( 'Matching', 'commerce-event-inspector' ),
		'warn'    => __( 'With notes', 'commerce-event-inspector' ),
		'fail'    => __( 'Not matching', 'commerce-event-inspector' ),
		'missing' => __( 'Without purchase event', 'commerce-event-inspector' ),
		'none'    => __( 'Not observed', 'commerce-event-inspector' ),
	);
	?>
	<div class="wrap cevi-wrap">
		<div class="cevi-head">
			<h1><?php esc_html_e( 'Commerce Event Inspector', 'commerce-event-inspector' ); ?></h1>
			<?php if ( $settings['record'] ) : ?>
				<span class="cevi-pill cevi-pill-ok"><span class="cevi-rec" aria-hidden="true"></span><?php esc_html_e( 'Recording on', 'commerce-event-inspector' ); ?></span>
			<?php else : ?>
				<span class="cevi-pill"><span class="dashicons dashicons-controls-pause" aria-hidden="true"></span><?php esc_html_e( 'Recording off', 'commerce-event-inspector' ); ?></span>
			<?php endif; ?>
			<span class="cevi-version"><?php echo esc_html( CEVI_VERSION ); ?></span>
		</div>
		<hr class="wp-header-end">
		<?php if ( $saved ) : ?>
			<div class="notice notice-success is-dismissible"><p><?php esc_html_e( 'Settings saved.', 'commerce-event-inspector' ); ?></p></div>
		<?php endif; ?>
		<p class="cevi-lead"><?php esc_html_e( 'Do the GA4 e-commerce events of this shop match the real orders? The plugin reads what tracking plugins and tags push to the dataLayer or send with gtag() and compares it with WooCommerce: transaction ID, value, currency and items of each order, and on product, cart and checkout pages the products and prices.', 'commerce-event-inspector' ); ?></p>

		<?php if ( ! $settings['since'] ) : ?>
			<div class="cevi-card cevi-empty-state">
				<span class="dashicons dashicons-chart-bar" aria-hidden="true"></span>
				<h2><?php esc_html_e( 'Orders', 'commerce-event-inspector' ); ?></h2>
				<p><?php esc_html_e( 'Recording has not been switched on yet. The report lists orders placed since then.', 'commerce-event-inspector' ); ?></p>
				<p class="cevi-muted"><?php esc_html_e( 'Switch on "Check purchase events on the order confirmation page" below and save. The report fills as customers see their order confirmation.', 'commerce-event-inspector' ); ?></p>
			</div>
		<?php else : ?>
			<?php $total = count( $rows ); ?>
			<div class="cevi-card">
				<h2 class="cevi-card-title"><span class="dashicons dashicons-chart-pie" aria-hidden="true"></span><?php esc_html_e( 'Orders', 'commerce-event-inspector' ); ?></h2>
				<p class="cevi-summary">
					<?php
					echo esc_html(
						sprintf(
							/* translators: 1: date, 2: number of orders. */
							_n( '%2$d order placed since %1$s or with stored observations.', '%2$d orders placed since %1$s or with stored observations.', $total, 'commerce-event-inspector' ),
							wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), $settings['since'] ),
							$total
						)
					);
					?>
				</p>
				<?php if ( $rows ) : ?>
					<div class="cevi-overview">
					<?php cevi_print_ring( $count, $labels, $total ); ?>
					<ul class="cevi-tiles">
						<?php foreach ( $labels as $key => $label ) : ?>
							<li>
								<button type="button" class="cevi-tile cevi-tile-<?php echo esc_attr( $key ); ?>" data-cevi-filter="<?php echo esc_attr( $key ); ?>" aria-pressed="false" aria-controls="cevi-orders"<?php disabled( 0 === $count[ $key ] ); ?>>
									<span class="dashicons dashicons-<?php echo esc_attr( cevi_status_icon( $key ) ); ?>" aria-hidden="true"></span>
									<strong data-cevi-count="<?php echo esc_attr( $count[ $key ] ); ?>"><?php echo esc_html( number_format_i18n( $count[ $key ] ) ); ?></strong>
									<span><?php echo esc_html( $label ); ?></span>
								</button>
							</li>
						<?php endforeach; ?>
					</ul>
					</div>
					<p class="cevi-legend" aria-hidden="true">
						<?php foreach ( $labels as $key => $label ) : ?>
							<?php if ( $count[ $key ] ) : ?>
								<span><span class="cevi-dot cevi-share-<?php echo esc_attr( $key ); ?>"></span><?php echo esc_html( $label . ' ' . number_format_i18n( $count[ $key ] / $total * 100 ) . ' %' ); ?></span>
							<?php endif; ?>
						<?php endforeach; ?>
					</p>
				<?php endif; ?>
			</div>

			<?php if ( $rows ) : ?>
				<div class="cevi-card">
					<h2 class="cevi-card-title"><span class="dashicons dashicons-calendar-alt" aria-hidden="true"></span><?php esc_html_e( 'Orders per day', 'commerce-event-inspector' ); ?></h2>
					<p class="cevi-muted"><?php esc_html_e( 'The last 14 days by result. A sudden change shows from which day on the events differ, for example after a plugin update. Selecting a day shows its orders in the list below.', 'commerce-event-inspector' ); ?></p>
					<?php cevi_print_days( $rows, $labels ); ?>
				</div>
			<?php endif; ?>

			<?php
			$gaps  = array_filter(
				$rows,
				function ( $r ) {
					return null !== $r[2];
				}
			);
			$scale = 10;
			foreach ( $gaps as $r ) {
				if ( null !== $r[2]['value'] && $r[2]['total'] > 0 ) {
					$scale = max( $scale, abs( ( $r[2]['value'] - $r[2]['total'] ) / $r[2]['total'] * 100 ) );
				}
			}
			?>
			<?php if ( $gaps ) : ?>
				<div class="cevi-card">
					<h2 class="cevi-card-title"><span class="dashicons dashicons-image-flip-horizontal" aria-hidden="true"></span><?php esc_html_e( 'Purchase value compared with the order total', 'commerce-event-inspector' ); ?></h2>
					<p class="cevi-muted"><?php esc_html_e( 'value of the first purchase event minus the order total including tax and shipping. Bars to the left: the event reports less, to the right: more. Notes such as "without shipping" are in the checks of each order.', 'commerce-event-inspector' ); ?></p>
					<table class="cevi-gaps">
						<caption class="screen-reader-text"><?php esc_html_e( 'Purchase value compared with the order total', 'commerce-event-inspector' ); ?></caption>
						<thead><tr>
							<th scope="col"><?php esc_html_e( 'Order', 'commerce-event-inspector' ); ?></th>
							<th scope="col"><?php esc_html_e( 'Total', 'commerce-event-inspector' ); ?></th>
							<th scope="col"><?php esc_html_e( 'Difference', 'commerce-event-inspector' ); ?></th>
						</tr></thead>
						<tbody>
						<?php foreach ( array_slice( $gaps, 0, 20 ) as $r ) : ?>
							<tr>
								<th scope="row"><a href="<?php echo esc_url( $r[0]->get_edit_order_url() ); ?>">#<?php echo esc_html( $r[0]->get_order_number() ); ?></a></th>
								<td><?php echo wp_kses_post( wc_price( $r[0]->get_total(), array( 'currency' => $r[0]->get_currency() ) ) ); ?></td>
								<td class="cevi-gap"><?php cevi_print_gap( $r[2], $r[0], $scale ); ?></td>
							</tr>
						<?php endforeach; ?>
						</tbody>
					</table>
				</div>
			<?php endif; ?>

			<?php if ( $rows ) : ?>
				<div class="cevi-card cevi-card-flat">
					<h2 class="cevi-card-title"><span class="dashicons dashicons-list-view" aria-hidden="true"></span><?php esc_html_e( 'All orders', 'commerce-event-inspector' ); ?></h2>
					<div class="cevi-toolbar" hidden>
						<div class="cevi-search">
							<label for="cevi-search"><?php esc_html_e( 'Search orders', 'commerce-event-inspector' ); ?></label>
							<span class="cevi-search-field">
								<span class="dashicons dashicons-search" aria-hidden="true"></span>
								<input type="search" id="cevi-search" class="regular-text" placeholder="<?php esc_attr_e( 'Order number, product, check …', 'commerce-event-inspector' ); ?>" aria-describedby="cevi-search-key" autocomplete="off">
								<kbd id="cevi-search-key" title="<?php esc_attr_e( 'Press / to search', 'commerce-event-inspector' ); ?>"><span aria-hidden="true">/</span><span class="screen-reader-text"><?php esc_html_e( 'Press / to search', 'commerce-event-inspector' ); ?></span></kbd>
							</span>
						</div>
						<button type="button" class="button cevi-copy" data-cevi-copy-head="<?php echo esc_attr( sprintf( /* translators: 1: site name, 2: date. */ __( 'Commerce Event Inspector – orders with problems or notes (%1$s, %2$s)', 'commerce-event-inspector' ), get_bloginfo( 'name' ), wp_date( get_option( 'date_format' ) ) ) ); ?>"><span class="dashicons dashicons-clipboard" aria-hidden="true"></span><span class="cevi-copy-text"><?php esc_html_e( 'Copy problems as text', 'commerce-event-inspector' ); ?></span></button>
					</div>
					<p class="cevi-copy-status" role="status" aria-live="polite"></p>
					<textarea class="cevi-copy-area" readonly hidden aria-label="<?php esc_attr_e( 'Problems as text', 'commerce-event-inspector' ); ?>"></textarea>
					<p class="cevi-filter-note" hidden><span class="cevi-filter-text" aria-live="polite"></span> <button type="button" class="button-link" data-cevi-filter=""><?php esc_html_e( 'Show all orders', 'commerce-event-inspector' ); ?></button></p>
					<p class="cevi-no-match" hidden><span class="dashicons dashicons-search" aria-hidden="true"></span> <?php esc_html_e( 'No order matches the search and filters.', 'commerce-event-inspector' ); ?></p>
					<div class="cevi-table" id="cevi-orders">
						<table class="widefat striped">
							<thead><tr>
								<th scope="col"><?php esc_html_e( 'Order', 'commerce-event-inspector' ); ?></th>
								<th scope="col"><?php esc_html_e( 'Total', 'commerce-event-inspector' ); ?></th>
								<th scope="col"><?php esc_html_e( 'Result', 'commerce-event-inspector' ); ?></th>
								<th scope="col"><?php esc_html_e( 'Checks', 'commerce-event-inspector' ); ?></th>
							</tr></thead>
							<tbody>
							<?php foreach ( $rows as $row ) : ?>
								<?php
								list( $order, $verdict ) = $row;
								?>
								<tr data-status="<?php echo esc_attr( $verdict['status'] ); ?>" data-day="<?php echo esc_attr( $row[3] ); ?>">
									<td data-label="<?php esc_attr_e( 'Order', 'commerce-event-inspector' ); ?>">
										<a class="cevi-order" href="<?php echo esc_url( $order->get_edit_order_url() ); ?>">#<?php echo esc_html( $order->get_order_number() ); ?></a><br>
										<span class="cevi-muted"><?php echo esc_html( $order->get_date_created() ? wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), $order->get_date_created()->getTimestamp() ) : '' ); ?></span>
									</td>
									<td data-label="<?php esc_attr_e( 'Total', 'commerce-event-inspector' ); ?>"><?php echo wp_kses_post( wc_price( $order->get_total(), array( 'currency' => $order->get_currency() ) ) ); ?></td>
									<td data-label="<?php esc_attr_e( 'Result', 'commerce-event-inspector' ); ?>"><?php cevi_print_badge( $verdict['status'] ); ?></td>
									<td data-label="<?php esc_attr_e( 'Checks', 'commerce-event-inspector' ); ?>"><?php cevi_print_checks( $verdict ); ?><?php cevi_print_compare( $order ); ?></td>
								</tr>
							<?php endforeach; ?>
							</tbody>
						</table>
					</div>
				</div>
			<?php else : ?>
				<div class="cevi-card cevi-empty-state">
					<span class="dashicons dashicons-cart" aria-hidden="true"></span>
					<p><?php esc_html_e( 'No orders yet since recording was switched on. They appear here after the next order.', 'commerce-event-inspector' ); ?></p>
				</div>
			<?php endif; ?>
		<?php endif; ?>

		<div class="cevi-card">
			<h2 class="cevi-card-title"><span class="dashicons dashicons-admin-settings" aria-hidden="true"></span><?php esc_html_e( 'Settings', 'commerce-event-inspector' ); ?></h2>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<input type="hidden" name="action" value="cevi_save">
				<?php wp_nonce_field( 'cevi_save' ); ?>
				<div class="cevi-setting">
					<label class="cevi-switch">
						<input type="checkbox" role="switch" name="cevi_record" value="1" aria-describedby="cevi-record-help" <?php checked( $settings['record'] ); ?>>
						<span class="cevi-switch-track" aria-hidden="true"></span>
						<strong><?php esc_html_e( 'Check purchase events on the order confirmation page', 'commerce-event-inspector' ); ?></strong>
					</label>
					<details class="cevi-details" id="cevi-record-help">
						<summary><?php esc_html_e( 'What is stored', 'commerce-event-inspector' ); ?></summary>
						<p class="cevi-muted"><?php esc_html_e( 'When a customer sees the order confirmation, a small script reads the e-commerce events on that page and sends them to this site, where they are stored with the order. No cookies are set and nothing is sent to other services. Stored per view of the confirmation page: the time, whether a logged-in shop manager viewed it, and for each e-commerce event its name and format, where it was found, transaction ID, value (also as sent), currency and the number, IDs, names, prices and quantities of the items; with it a copy of the order data used for the comparison: order ID and number, status, currency, the item IDs, names, quantities and prices, and the totals.', 'commerce-event-inspector' ); ?></p>
					</details>
				</div>
				<div class="cevi-setting">
					<label class="cevi-switch">
						<input type="checkbox" role="switch" name="cevi_inspector" value="1" aria-describedby="cevi-inspector-help" <?php checked( $mine ); ?>>
						<span class="cevi-switch-track" aria-hidden="true"></span>
						<strong><?php esc_html_e( 'Show the inspector panel in the shop for my account', 'commerce-event-inspector' ); ?></strong>
					</label>
					<p class="cevi-muted" id="cevi-inspector-help"><?php esc_html_e( 'While logged in, a panel lists every e-commerce event with its checks. Some tracking plugins skip logged-in shop managers; in that case the panel stays empty.', 'commerce-event-inspector' ); ?></p>
				</div>
				<?php submit_button( __( 'Save settings', 'commerce-event-inspector' ), 'primary', 'submit', false ); ?>
			</form>
		</div>

		<div class="cevi-card cevi-limits">
			<h2 class="cevi-card-title"><span class="dashicons dashicons-info-outline" aria-hidden="true"></span><?php esc_html_e( 'Limits', 'commerce-event-inspector' ); ?></h2>
			<p><?php esc_html_e( 'The plugin sees what the page pushes to the dataLayer or sends with gtag(), not whether Google Analytics received it. Ad blockers and missing consent can still stop the request. Events sent only from the server, or by a tag inside a Tag Manager container that the page does not push, are not visible. Orders without a viewed confirmation page, such as orders created in the admin, are listed as not observed. The list shows at most the latest 100 orders placed since recording was switched on and the latest 100 orders with stored observations.', 'commerce-event-inspector' ); ?></p>
		</div>
	</div>
	<?php
}

/** Save the settings. */
function cevi_handle_save() {
	if ( ! current_user_can( 'manage_woocommerce' ) ) {
		wp_die( esc_html__( 'You do not have permission to change these settings.', 'commerce-event-inspector' ), 403 );
	}
	check_admin_referer( 'cevi_save' );
	$record    = isset( $_POST['cevi_record'] ) && '1' === sanitize_text_field( wp_unslash( $_POST['cevi_record'] ) );
	$inspector = isset( $_POST['cevi_inspector'] ) && '1' === sanitize_text_field( wp_unslash( $_POST['cevi_inspector'] ) );
	CEVI_Store::set_record( $record );
	if ( ! $inspector ) {
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
	echo '<p>';
	cevi_print_badge( $verdict['status'] );
	echo '</p>';
	$gap = cevi_value_gap( $order );
	if ( $gap ) {
		echo '<div class="cevi-gap cevi-gap-box"><span class="cevi-gap-label">' . esc_html__( 'Purchase value compared with the order total', 'commerce-event-inspector' ) . '</span>';
		cevi_print_gap( $gap, $order, 10 );
		echo '</div>';
	}
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
		'<p>' . esc_html__( 'When checking of purchase events is switched on, the order confirmation page reads the analytics events this site prepares for the order and sends them to this site. They are stored with the order, per view of the confirmation page: the time, whether a logged-in shop manager viewed it, and for each e-commerce event its name and format, where it was found, transaction ID, value (also as sent), currency and the number, IDs, names, prices and quantities of the items; with it a copy of the order data used for the comparison: order ID and number, status, currency, the item IDs, names, quantities and prices, and the totals. No cookies are set and nothing is sent to other services. The data is deleted with the order or when the plugin is uninstalled.', 'commerce-event-inspector' ) . '</p>'
	);
}
