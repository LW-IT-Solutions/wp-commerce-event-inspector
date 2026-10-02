<?php
/**
 * Shop data in the shape the event checks compare against: a product, the cart or an order.
 *
 * @package CommerceEventInspector
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Builds comparison data and checks purchase events against an order.
 */
class CEVI_Context {

	/** Amounts within one cent count as equal. */
	const CENT = 0.011;

	/**
	 * IDs a tracking plugin may use for a product: SKU, variation ID, product ID and the parent's SKU.
	 *
	 * @param WC_Product|false $product Product or variation.
	 * @param int              $product_id   Product ID.
	 * @param int              $variation_id Variation ID or 0.
	 * @return array
	 */
	private static function ids( $product, $product_id, $variation_id ) {
		$ids = array();
		if ( $product ) {
			$ids[] = (string) $product->get_sku();
			if ( $product->get_parent_id() ) {
				$parent = wc_get_product( $product->get_parent_id() );
				$ids[]  = $parent ? (string) $parent->get_sku() : '';
			}
		}
		$ids[] = $variation_id ? (string) $variation_id : '';
		$ids[] = (string) $product_id;
		return array_values( array_unique( array_filter( $ids, 'strlen' ) ) );
	}

	/**
	 * Comparison data for a product page, with all variations.
	 *
	 * @param WC_Product $product Product.
	 * @return array
	 */
	public static function product( $product ) {
		$all = array( $product );
		if ( $product->is_type( 'variable' ) ) {
			foreach ( $product->get_children() as $child ) {
				$variation = wc_get_product( $child );
				if ( $variation ) {
					$all[] = $variation;
				}
			}
		}
		$items = array();
		foreach ( $all as $p ) {
			$items[] = array(
				'ids'        => self::ids( $p, $p->get_parent_id() ? $p->get_parent_id() : $p->get_id(), $p->get_parent_id() ? $p->get_id() : 0 ),
				'name'       => wp_strip_all_tags( $p->get_name() ),
				'gross'      => self::round( wc_get_price_including_tax( $p ) ),
				'net'        => self::round( wc_get_price_excluding_tax( $p ) ),
				'gross_full' => self::round( wc_get_price_including_tax( $p, array( 'price' => $p->get_regular_price() ) ) ),
				'net_full'   => self::round( wc_get_price_excluding_tax( $p, array( 'price' => $p->get_regular_price() ) ) ),
			);
		}
		return array(
			'type'  => 'product',
			'items' => $items,
		);
	}

	/**
	 * Comparison data for the cart and the checkout.
	 *
	 * @param WC_Cart $cart Cart.
	 * @return array|null
	 */
	public static function cart( $cart ) {
		if ( ! $cart || $cart->is_empty() ) {
			return null;
		}
		$items = array();
		foreach ( $cart->get_cart() as $line ) {
			$qty     = max( 1, (int) $line['quantity'] );
			$items[] = array(
				'ids'        => self::ids( $line['data'], (int) $line['product_id'], (int) $line['variation_id'] ),
				'name'       => wp_strip_all_tags( $line['data']->get_name() ),
				'qty'        => $qty,
				'gross'      => self::round( ( $line['line_total'] + $line['line_tax'] ) / $qty ),
				'net'        => self::round( $line['line_total'] / $qty ),
				'gross_full' => self::round( ( $line['line_subtotal'] + $line['line_subtotal_tax'] ) / $qty ),
				'net_full'   => self::round( $line['line_subtotal'] / $qty ),
			);
		}
		$total    = (float) $cart->get_total( 'edit' );
		$shipping = (float) $cart->get_shipping_total();
		$ship_tax = (float) $cart->get_shipping_tax();
		$tax      = (float) $cart->get_total_tax();
		return array(
			'type'   => 'cart',
			'items'  => $items,
			'values' => self::values( $total, $shipping, $ship_tax, $tax ),
		);
	}

	/**
	 * Comparison data for an order. Stored with each observation, so later refunds do not change old results.
	 *
	 * @param WC_Order $order Order.
	 * @return array
	 */
	public static function order( $order ) {
		$items = array();
		foreach ( $order->get_items() as $line ) {
			if ( ! $line instanceof WC_Order_Item_Product ) {
				continue;
			}
			$qty     = max( 1, (int) $line->get_quantity() );
			$items[] = array(
				'ids'        => self::ids( $line->get_product(), (int) $line->get_product_id(), (int) $line->get_variation_id() ),
				'name'       => wp_strip_all_tags( $line->get_name() ),
				'qty'        => $qty,
				'gross'      => self::round( ( (float) $line->get_total() + (float) $line->get_total_tax() ) / $qty ),
				'net'        => self::round( (float) $line->get_total() / $qty ),
				'gross_full' => self::round( ( (float) $line->get_subtotal() + (float) $line->get_subtotal_tax() ) / $qty ),
				'net_full'   => self::round( (float) $line->get_subtotal() / $qty ),
			);
		}
		return array(
			'type'     => 'order',
			'id'       => $order->get_id(),
			'number'   => (string) $order->get_order_number(),
			'status'   => (string) $order->get_status(),
			'currency' => $order->get_currency(),
			'items'    => $items,
			'values'   => self::values( (float) $order->get_total(), (float) $order->get_shipping_total(), (float) $order->get_shipping_tax(), (float) $order->get_total_tax() ),
		);
	}

	/**
	 * The four readings of "value" that tracking setups use.
	 *
	 * @param float $total    Total including tax and shipping.
	 * @param float $shipping Shipping without tax.
	 * @param float $ship_tax Tax on shipping.
	 * @param float $tax      All tax.
	 * @return array
	 */
	private static function values( $total, $shipping, $ship_tax, $tax ) {
		return array(
			'total'           => self::round( $total ),
			'no_shipping'     => self::round( $total - $shipping - $ship_tax ),
			'net'             => self::round( $total - $tax ),
			'net_no_shipping' => self::round( $total - $tax - $shipping ),
		);
	}

	/**
	 * Round to cents.
	 *
	 * @param float $v Amount.
	 * @return float
	 */
	private static function round( $v ) {
		return round( (float) $v, 2 );
	}

	/**
	 * Equal within a cent?
	 *
	 * @param float|null $a Amount.
	 * @param float|null $b Amount.
	 * @return bool
	 */
	private static function same( $a, $b ) {
		return null !== $a && null !== $b && abs( $a - $b ) < self::CENT;
	}

	/**
	 * Check one purchase event against an order snapshot.
	 *
	 * @param array $event Normalized event.
	 * @param array $ctx   Order snapshot.
	 * @return array List of [level, code, data]; level is ok, warn or fail.
	 */
	public static function check_purchase( $event, $ctx ) {
		$out = array();
		// WooCommerce also shows the confirmation page for failed and unpaid orders.
		$status = isset( $ctx['status'] ) ? $ctx['status'] : '';
		if ( in_array( $status, array( 'failed', 'cancelled' ), true ) ) {
			$out[] = array( 'fail', 'status_failed', array( $status ) );
		} elseif ( 'pending' === $status ) {
			$out[] = array( 'warn', 'status_pending', array( $status ) );
		}
		if ( ! empty( $event['legacy'] ) ) {
			$out[] = array( 'warn', 'legacy', array() );
		}
		if ( '' === $event['transaction_id'] ) {
			$out[] = array( 'fail', 'no_transaction', array() );
		} elseif ( $event['transaction_id'] === $ctx['number'] || $event['transaction_id'] === (string) $ctx['id'] ) {
			$out[] = array( 'ok', 'transaction', array( $event['transaction_id'] ) );
		} else {
			$out[] = array( 'fail', 'transaction_other', array( $event['transaction_id'], $ctx['number'] ) );
		}
		if ( '' === $event['currency'] ) {
			$out[] = array( 'fail', 'no_currency', array() );
		} elseif ( $event['currency'] !== $ctx['currency'] ) {
			$out[] = array( 'fail', 'currency_other', array( $event['currency'], $ctx['currency'] ) );
		}
		if ( null === $event['value'] && ! empty( $event['value_raw'] ) ) {
			$out[] = array( 'fail', 'value_text', array( $event['value_raw'] ) );
		} elseif ( null === $event['value'] ) {
			$out[] = array( 'fail', 'no_value', array() );
		} else {
			$match = '';
			foreach ( $ctx['values'] as $key => $amount ) {
				if ( '' === $match && self::same( $event['value'], $amount ) ) {
					$match = $key;
				}
			}
			$out[] = '' !== $match ? array( 'ok', 'value_' . $match, array( $event['value'] ) ) : array( 'fail', 'value_other', array( $event['value'], $ctx['values']['total'] ) );
		}
		if ( ! $event['items'] ) {
			$out[] = array( 'fail', 'no_items', array() );
			return $out;
		}
		$used = array();
		foreach ( $event['items'] as $item ) {
			$label = '' !== $item['item_id'] ? $item['item_id'] : ( '' !== $item['item_name'] ? $item['item_name'] : '?' );
			$hit   = null;
			foreach ( $ctx['items'] as $k => $line ) {
				if ( null === $hit && ( ( '' !== $item['item_id'] && in_array( $item['item_id'], $line['ids'], true ) ) || ( '' === $item['item_id'] && '' !== $item['item_name'] && $item['item_name'] === $line['name'] ) ) ) {
					$hit = $k;
				}
			}
			if ( null === $hit ) {
				$out[] = array( 'warn', 'item_unknown', array( $label ) );
				continue;
			}
			$used[] = $hit;
			$line   = $ctx['items'][ $hit ];
			if ( null !== $item['quantity'] && (float) $item['quantity'] !== (float) $line['qty'] ) {
				$out[] = array( 'warn', 'item_qty', array( $label, $item['quantity'], $line['qty'] ) );
			}
			if ( null === $item['price'] ) {
				$out[] = array( 'warn', 'item_no_price', array( $label ) );
			} elseif ( ! self::same( $item['price'], $line['gross'] ) && ! self::same( $item['price'], $line['net'] ) && ! self::same( $item['price'], $line['gross_full'] ) && ! self::same( $item['price'], $line['net_full'] ) ) {
				$out[] = array( 'warn', 'item_price', array( $label, $item['price'], $line['gross'], $line['net'] ) );
			}
		}
		$cut = isset( $event['items_count'] ) && $event['items_count'] > count( $event['items'] );
		if ( $cut ) {
			// Only the first items were sent for comparison; the rest of the order cannot count as missing.
			$out[] = array( 'warn', 'items_cut', array( count( $event['items'] ), $event['items_count'] ) );
		} else {
			foreach ( $ctx['items'] as $k => $line ) {
				if ( ! in_array( $k, $used, true ) ) {
					$out[] = array( 'warn', 'item_missing', array( $line['name'] ) );
				}
			}
		}
		if ( ! $cut && ! array_filter(
			$out,
			function ( $c ) {
				return 0 === strpos( $c[1], 'item_' );
			}
		) ) {
			$out[] = array( 'ok', 'items', array( count( $event['items'] ) ) );
		}
		return $out;
	}
}
