<?php
/**
 * Receives what the order confirmation page pushed, stores it with the order and turns it into a verdict.
 *
 * @package CommerceEventInspector
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Observations of order confirmation pages.
 */
class CEVI_Store {

	const SETTINGS         = 'cevi_settings';
	const INDEX            = 'cevi_orders';
	const META             = '_cevi_observations';
	const MAX_OBSERVATIONS = 5;
	const MAX_AGE          = 2 * DAY_IN_SECONDS;
	const EVENTS           = array( 'view_item_list', 'select_item', 'view_item', 'add_to_wishlist', 'add_to_cart', 'remove_from_cart', 'view_cart', 'begin_checkout', 'add_shipping_info', 'add_payment_info', 'purchase', 'refund', 'view_promotion', 'select_promotion' );

	/**
	 * Settings with defaults.
	 *
	 * @return array record (bool), since (int).
	 */
	public static function settings() {
		$s = get_option( self::SETTINGS );
		$s = is_array( $s ) ? $s : array();
		return array(
			'record' => ! empty( $s['record'] ),
			'since'  => isset( $s['since'] ) ? (int) $s['since'] : 0,
		);
	}

	/**
	 * Switch recording on or off. Switching on starts a new period for the report.
	 *
	 * @param bool $record On or off.
	 */
	public static function set_record( $record ) {
		$s = self::settings();
		if ( $record && ! $s['record'] ) {
			$s['since'] = time();
		}
		$s['record'] = (bool) $record;
		update_option( self::SETTINGS, $s, false );
	}

	/** Register the endpoint the order confirmation page reports to. */
	public static function routes() {
		register_rest_route(
			'cevi/v1',
			'/observe',
			array(
				'methods'             => 'POST',
				'callback'            => array( __CLASS__, 'observe' ),
				// Customers are not logged in. Access is checked in the callback with the order key,
				// which only the order confirmation page of that order knows.
				'permission_callback' => '__return_true',
			)
		);
	}

	/**
	 * Store one observation.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response
	 */
	public static function observe( $request ) {
		$body  = $request->get_json_params();
		$deny  = new WP_REST_Response( array( 'stored' => false ), 403 );
		// Only a plain positive number counts as an order ID; absint() would turn "-12" or "12abc" into 12.
		$id    = is_array( $body ) && isset( $body['order'] ) && is_scalar( $body['order'] ) && ctype_digit( (string) $body['order'] ) ? (int) $body['order'] : 0;
		$order = $id > 0 ? wc_get_order( $id ) : false;
		if ( ! self::settings()['record'] || ! $order || ! isset( $body['key'] ) || ! is_string( $body['key'] ) || ! hash_equals( $order->get_order_key(), $body['key'] ) ) {
			return $deny;
		}
		$created = $order->get_date_created();
		if ( ! $created || time() - $created->getTimestamp() > self::MAX_AGE ) {
			return $deny;
		}
		$full = new WP_REST_Response( array( 'stored' => false ), 200 );
		$order->read_meta_data( true );
		if ( count( self::rows( $order ) ) >= self::MAX_OBSERVATIONS ) {
			return $full;
		}
		$events = array();
		foreach ( array_slice( isset( $body['events'] ) && is_array( $body['events'] ) ? $body['events'] : array(), 0, 20 ) as $raw ) {
			$event = self::clean_event( $raw );
			if ( $event ) {
				$events[] = $event;
			}
		}
		// One meta row per observation: requests that arrive at the same time add rows instead of overwriting one list.
		$before = wp_list_pluck( self::rows( $order ), 'id' );
		$order->add_meta_data(
			self::META,
			array(
				'time'   => time(),
				'staff'  => ! empty( $body['staff'] ),
				'events' => $events,
				'order'  => CEVI_Context::order( $order ),
			)
		);
		$order->save_meta_data();
		$own = array_values( array_diff( wp_list_pluck( self::rows( $order ), 'id' ), $before ) );
		// Several requests can pass the count check together. The rows with the lowest IDs stay, later ones go.
		$order->read_meta_data( true );
		$keep = array_slice( wp_list_pluck( self::rows( $order ), 'id' ), 0, self::MAX_OBSERVATIONS );
		if ( $own && ! in_array( $own[0], $keep, true ) ) {
			$order->delete_meta_data_by_mid( $own[0] );
			$order->save_meta_data();
			return $full;
		}
		// Remember which orders carry observations, so uninstalling can remove them without a meta query.
		$index = get_option( self::INDEX );
		$index = is_array( $index ) ? $index : array();
		if ( ! in_array( $order->get_id(), $index, true ) ) {
			$index[] = $order->get_id();
			update_option( self::INDEX, $index, false );
		}
		return new WP_REST_Response( array( 'stored' => true ), 201 );
	}

	/**
	 * Keep only known fields with the expected types.
	 *
	 * @param mixed $raw Event from the browser.
	 * @return array|null
	 */
	public static function clean_event( $raw ) {
		if ( ! is_array( $raw ) || ! isset( $raw['name'] ) || ! in_array( $raw['name'], self::EVENTS, true ) ) {
			return null;
		}
		$items = null;
		if ( isset( $raw['items'] ) && is_array( $raw['items'] ) ) {
			$items = array();
			foreach ( array_slice( $raw['items'], 0, 100 ) as $i ) {
				$i       = is_array( $i ) ? $i : array();
				$items[] = array(
					'item_id'   => self::text( $i['item_id'] ?? '' ),
					'item_name' => self::text( $i['item_name'] ?? '' ),
					'price'     => self::number( $i['price'] ?? null ),
					'quantity'  => self::number( $i['quantity'] ?? null ),
				);
			}
		}
		return array(
			'name'           => $raw['name'],
			'source'         => isset( $raw['source'] ) && 'gtag' === $raw['source'] ? 'gtag' : 'dataLayer',
			'list'           => isset( $raw['list'] ) && is_string( $raw['list'] ) && preg_match( '/^[A-Za-z_$][A-Za-z0-9_$]{0,60}$/', $raw['list'] ) ? $raw['list'] : 'dataLayer',
			'legacy'         => ! empty( $raw['legacy'] ),
			'currency'       => strtoupper( substr( preg_replace( '/[^A-Za-z]/', '', self::text( $raw['currency'] ?? '' ) ), 0, 3 ) ),
			'value'          => self::number( $raw['value'] ?? null ),
			'value_raw'      => mb_substr( self::text( $raw['value_raw'] ?? '' ), 0, 20 ),
			'transaction_id' => self::text( $raw['transaction_id'] ?? '' ),
			'items'          => $items,
			'items_count'    => null === $items ? 0 : min( 100000, max( count( $raw['items'] ), isset( $raw['items_count'] ) && is_numeric( $raw['items_count'] ) ? (int) $raw['items_count'] : 0 ) ),
		);
	}

	/**
	 * Plain text, at most 100 characters.
	 *
	 * @param mixed $v Value.
	 * @return string
	 */
	private static function text( $v ) {
		return is_scalar( $v ) ? mb_substr( sanitize_text_field( (string) $v ), 0, 100 ) : '';
	}

	/**
	 * A finite number or null.
	 *
	 * @param mixed $v Value.
	 * @return float|null
	 */
	private static function number( $v ) {
		return is_numeric( $v ) && is_finite( (float) $v ) ? round( (float) $v, 4 ) : null;
	}

	/**
	 * Observations of an order.
	 *
	 * @param WC_Order $order Order.
	 * @return array
	 */
	public static function observations( $order ) {
		return wp_list_pluck( self::rows( $order ), 'value' );
	}

	/**
	 * The observation meta rows of an order, oldest first.
	 *
	 * @param WC_Order $order Order.
	 * @return array List of objects with id and value.
	 */
	private static function rows( $order ) {
		$rows = array();
		foreach ( $order->get_meta( self::META, false ) as $meta ) {
			$data = $meta->get_data();
			if ( is_array( $data['value'] ) && isset( $data['value']['events'] ) ) {
				$rows[] = (object) array(
					'id'    => (int) $data['id'],
					'value' => $data['value'],
				);
			}
		}
		usort(
			$rows,
			function ( $a, $b ) {
				return $a->id - $b->id;
			}
		);
		return $rows;
	}

	/**
	 * Verdict for an order from its observations.
	 *
	 * @param WC_Order $order Order.
	 * @return array status (none, missing, ok, warn, fail), checks, purchases, views.
	 */
	public static function verdict( $order ) {
		$list = self::observations( $order );
		if ( ! $list ) {
			return array(
				'status'    => 'none',
				'checks'    => array(),
				'purchases' => 0,
				'views'     => 0,
			);
		}
		// The first view by a customer counts; staff views only when no customer view exists.
		$first = $list[0];
		foreach ( $list as $obs ) {
			if ( empty( $obs['staff'] ) ) {
				$first = $obs;
				break;
			}
		}
		$purchases = array_values(
			array_filter(
				$first['events'],
				function ( $e ) {
					return 'purchase' === $e['name'];
				}
			)
		);
		if ( ! $purchases ) {
			return array(
				'status'    => 'missing',
				'checks'    => array( array( 'fail', 'no_purchase', array( implode( ', ', array_unique( wp_list_pluck( $first['events'], 'name' ) ) ) ) ) ),
				'purchases' => 0,
				'views'     => count( $list ),
			);
		}
		$checks   = CEVI_Context::check_purchase( $purchases[0], $first['order'] );
		$checks[] = array( 'ok', 'source_' . ( isset( $purchases[0]['source'] ) && 'gtag' === $purchases[0]['source'] ? 'gtag' : 'datalayer' ), array() );
		if ( ! empty( $purchases[0]['list'] ) && 'dataLayer' !== $purchases[0]['list'] ) {
			$checks[] = array( 'ok', 'list', array( $purchases[0]['list'] ) );
		}
		if ( ! empty( $first['staff'] ) ) {
			$checks[] = array( 'warn', 'staff', array() );
		}
		if ( count( $purchases ) > 1 ) {
			array_unshift( $checks, array( 'fail', 'duplicate', array( count( $purchases ) ) ) );
		}
		$again = 0;
		foreach ( $list as $obs ) {
			if ( $obs !== $first && empty( $obs['staff'] ) && in_array( 'purchase', wp_list_pluck( $obs['events'], 'name' ), true ) ) {
				++$again;
			}
		}
		if ( $again ) {
			$checks[] = array( 'warn', 'again', array( $again ) );
		}
		$levels = wp_list_pluck( $checks, 0 );
		return array(
			'status'    => in_array( 'fail', $levels, true ) ? 'fail' : ( in_array( 'warn', $levels, true ) ? 'warn' : 'ok' ),
			'checks'    => $checks,
			'purchases' => count( $purchases ),
			'views'     => count( $list ),
		);
	}

	/**
	 * Sentence for one check.
	 *
	 * @param array $check [level, code, data].
	 * @return string
	 */
	public static function describe( $check ) {
		list( , $code, $d ) = $check;
		$money              = function ( $v ) {
			return number_format_i18n( (float) $v, 2 );
		};
		switch ( $code ) {
			case 'status_failed':
				/* translators: %s: order status. */
				return sprintf( __( 'purchase was pushed although the order status is "%s"; the payment did not go through.', 'commerce-event-inspector' ), wc_get_order_status_name( $d[0] ) );
			case 'status_pending':
				/* translators: %s: order status. */
				return sprintf( __( 'purchase was pushed while the order status was "%s"; the payment was not confirmed yet.', 'commerce-event-inspector' ), wc_get_order_status_name( $d[0] ) );
			case 'items_cut':
				/* translators: 1: number of items compared, 2: number of items in the event. */
				return sprintf( __( 'Only the first %1$d of %2$d items were compared.', 'commerce-event-inspector' ), $d[0], $d[1] );
			case 'legacy':
				return __( 'The event uses the older Universal Analytics format (ecommerce.purchase.actionField); GA4 expects transaction_id, value, currency and items.', 'commerce-event-inspector' );
			case 'no_transaction':
				return __( 'transaction_id is missing.', 'commerce-event-inspector' );
			case 'transaction':
				/* translators: %s: transaction ID. */
				return sprintf( __( 'transaction_id %s matches the order.', 'commerce-event-inspector' ), $d[0] );
			case 'transaction_other':
				/* translators: 1: transaction ID in the event, 2: order number. */
				return sprintf( __( 'transaction_id %1$s does not match the order number %2$s.', 'commerce-event-inspector' ), $d[0], $d[1] );
			case 'no_currency':
				return __( 'currency is missing; GA4 needs it together with value.', 'commerce-event-inspector' );
			case 'currency_other':
				/* translators: 1: currency in the event, 2: order currency. */
				return sprintf( __( 'Currency %1$s, the order is in %2$s.', 'commerce-event-inspector' ), $d[0], $d[1] );
			case 'value_text':
				/* translators: %s: value as sent. */
				return sprintf( __( 'value "%s" is text, not a number; GA4 expects a number with a decimal point, such as 143.70.', 'commerce-event-inspector' ), $d[0] );
			case 'no_value':
				return __( 'value is missing or not a number.', 'commerce-event-inspector' );
			case 'value_total':
				/* translators: %s: amount. */
				return sprintf( __( 'value %s equals the order total including tax and shipping.', 'commerce-event-inspector' ), $money( $d[0] ) );
			case 'value_no_shipping':
				/* translators: %s: amount. */
				return sprintf( __( 'value %s equals the order total including tax, without shipping.', 'commerce-event-inspector' ), $money( $d[0] ) );
			case 'value_net':
				/* translators: %s: amount. */
				return sprintf( __( 'value %s equals the order total without tax, including shipping.', 'commerce-event-inspector' ), $money( $d[0] ) );
			case 'value_net_no_shipping':
				/* translators: %s: amount. */
				return sprintf( __( 'value %s equals the order total without tax and shipping.', 'commerce-event-inspector' ), $money( $d[0] ) );
			case 'value_other':
				/* translators: 1: value in the event, 2: order total. */
				return sprintf( __( 'value %1$s matches no reading of the order total %2$s (with or without tax and shipping).', 'commerce-event-inspector' ), $money( $d[0] ), $money( $d[1] ) );
			case 'no_items':
				return __( 'items is missing or empty.', 'commerce-event-inspector' );
			case 'item_unknown':
				/* translators: %s: item ID or name. */
				return sprintf( __( 'Item %s is not in the order (item_id matches no SKU, product ID or variation ID).', 'commerce-event-inspector' ), $d[0] );
			case 'item_qty':
				/* translators: 1: item, 2: quantity in the event, 3: quantity in the order. */
				return sprintf( __( 'Item %1$s: quantity %2$s, the order has %3$s.', 'commerce-event-inspector' ), $d[0], $d[1], $d[2] );
			case 'item_no_price':
				/* translators: %s: item. */
				return sprintf( __( 'Item %s has no price.', 'commerce-event-inspector' ), $d[0] );
			case 'item_price':
				/* translators: 1: item, 2: price in the event, 3: unit price with tax, 4: unit price without tax. */
				return sprintf( __( 'Item %1$s: price %2$s, the order has %3$s with tax or %4$s without.', 'commerce-event-inspector' ), $d[0], $money( $d[1] ), $money( $d[2] ), $money( $d[3] ) );
			case 'item_missing':
				/* translators: %s: product name. */
				return sprintf( __( 'Order item %s is missing in the event.', 'commerce-event-inspector' ), $d[0] );
			case 'items':
				/* translators: %d: number of items. */
				return sprintf( _n( '%d item matches the order.', 'All %d items match the order.', $d[0], 'commerce-event-inspector' ), $d[0] );
			case 'source_gtag':
				return __( 'Sent with gtag("event", "purchase").', 'commerce-event-inspector' );
			case 'source_datalayer':
				return __( 'Pushed to the dataLayer as an object with event and ecommerce.', 'commerce-event-inspector' );
			case 'list':
				/* translators: %s: name of the JavaScript array. */
				return sprintf( __( 'Found in the array %s instead of dataLayer; a Tag Manager container reading dataLayer does not see it.', 'commerce-event-inspector' ), $d[0] );
			case 'staff':
				return __( 'Only a logged-in shop manager viewed the confirmation page; tracking plugins often behave differently for customers.', 'commerce-event-inspector' );
			case 'no_purchase':
				return '' === $d[0]
					? __( 'The order confirmation page was viewed, but no e-commerce event was pushed.', 'commerce-event-inspector' )
					/* translators: %s: event names. */
					: sprintf( __( 'The order confirmation page was viewed, but no purchase event was pushed (only: %s).', 'commerce-event-inspector' ), $d[0] );
			case 'duplicate':
				/* translators: %d: number of purchase events. */
				return sprintf( __( 'purchase was pushed %d times on one page view; reports may count the order more than once.', 'commerce-event-inspector' ), $d[0] );
			default:
				/* translators: %d: number of further views. */
				return sprintf( _n( 'A later view of the confirmation page pushed purchase again (%d time).', 'Later views of the confirmation page pushed purchase again (%d times).', $d[0], 'commerce-event-inspector' ), $d[0] );
		}
	}
}
