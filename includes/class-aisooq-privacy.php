<?php
/**
 * WordPress personal-data exporter and eraser.
 *
 * This plugin stores shopper PII that WordPress core knows nothing about: the
 * abandoned-carts table holds a name, e-mail, phone, address and cart contents
 * for people who never completed an order (so there is no WC_Order for core to
 * find), and orders carry attribution and courier meta of our own.
 *
 * Without these callbacks a store could not honour a GDPR access or erasure
 * request without hand-editing the database, and the plugin was quietly
 * undermining the site's compliance for every one of its users.
 *
 * @package AISooq
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class AI_Sooq_Privacy {

	/** Rows handled per exporter/eraser page. */
	const PER_PAGE = 50;

	const GROUP = 'aisooq-abandoned-carts';

	public function register() {
		add_filter( 'wp_privacy_personal_data_exporters', array( $this, 'register_exporter' ) );
		add_filter( 'wp_privacy_personal_data_erasers', array( $this, 'register_eraser' ) );
		add_action( 'admin_init', array( $this, 'add_policy_content' ) );
	}

	public function register_exporter( $exporters ) {
		$exporters['aisooq-connector'] = array(
			'exporter_friendly_name' => __( 'AI Sooq Connector — abandoned carts', 'aisooq-connector' ),
			'callback'               => array( $this, 'export' ),
		);
		return $exporters;
	}

	public function register_eraser( $erasers ) {
		$erasers['aisooq-connector'] = array(
			'eraser_friendly_name' => __( 'AI Sooq Connector — abandoned carts', 'aisooq-connector' ),
			'callback'             => array( $this, 'erase' ),
		);
		return $erasers;
	}

	/**
	 * Rows for one e-mail address, one page at a time.
	 *
	 * @param string $email
	 * @param int    $page 1-based.
	 * @return array Raw rows.
	 */
	private function rows( $email, $page ) {
		global $wpdb;
		$table = AI_Sooq_Abandoned_Sync::table_name();
		if ( $table !== $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ) ) { // phpcs:ignore WordPress.DB
			return array();
		}
		return (array) $wpdb->get_results( // phpcs:ignore WordPress.DB
			$wpdb->prepare(
				"SELECT * FROM {$table} WHERE email = %s ORDER BY created_at ASC LIMIT %d OFFSET %d",
				$email,
				self::PER_PAGE,
				max( 0, ( (int) $page - 1 ) * self::PER_PAGE )
			)
		);
	}

	/**
	 * @param string $email
	 * @param int    $page
	 * @return array
	 */
	public function export( $email, $page = 1 ) {
		$rows = $this->rows( $email, $page );
		$out  = array();

		foreach ( $rows as $row ) {
			$data = array(
				array( 'name' => __( 'Captured at', 'aisooq-connector' ), 'value' => $row->created_at ),
				array( 'name' => __( 'Name', 'aisooq-connector' ), 'value' => $row->customer_name ),
				array( 'name' => __( 'Email', 'aisooq-connector' ), 'value' => $row->email ),
				array( 'name' => __( 'Phone', 'aisooq-connector' ), 'value' => $row->phone ),
				array( 'name' => __( 'Address', 'aisooq-connector' ), 'value' => $row->address_json ),
				array( 'name' => __( 'Cart contents', 'aisooq-connector' ), 'value' => $row->cart_json ),
				array( 'name' => __( 'Subtotal', 'aisooq-connector' ), 'value' => $row->subtotal . ' ' . $row->currency ),
				array( 'name' => __( 'Referrer', 'aisooq-connector' ), 'value' => $row->referrer ),
				array( 'name' => __( 'Landing page', 'aisooq-connector' ), 'value' => $row->landing_path ),
				array( 'name' => __( 'Campaign', 'aisooq-connector' ), 'value' => $row->utm_campaign ),
			);
			$out[] = array(
				'group_id'    => self::GROUP,
				'group_label' => __( 'Abandoned carts', 'aisooq-connector' ),
				'item_id'     => 'aisooq-cart-' . md5( $row->session_key ),
				'data'        => array_values(
					array_filter(
						$data,
						function ( $f ) {
							return '' !== (string) $f['value'] && null !== $f['value'];
						}
					)
				),
			);
		}

		return array(
			'data' => $out,
			'done' => count( $rows ) < self::PER_PAGE,
		);
	}

	/**
	 * @param string $email
	 * @param int    $page
	 * @return array
	 */
	public function erase( $email, $page = 1 ) {
		global $wpdb;
		$removed  = false;
		$messages = array();

		// Always page 1: each pass deletes what it finds, so the next pass sees
		// the next batch at the same offset.
		$rows = $this->rows( $email, 1 );
		if ( $rows ) {
			$table = AI_Sooq_Abandoned_Sync::table_name();
			foreach ( $rows as $row ) {
				$deleted = $wpdb->delete( $table, array( 'session_key' => $row->session_key ) ); // phpcs:ignore WordPress.DB
				if ( $deleted ) {
					$removed = true;
				}
			}
			$messages[] = sprintf(
				/* translators: %d: number of abandoned-cart records removed. */
				__( 'Removed %d abandoned-cart record(s) held by AI Sooq Connector.', 'aisooq-connector' ),
				count( $rows )
			);
			// The platform holds its own copy; erasing here cannot reach it.
			$messages[] = __( 'Carts already mirrored to the AI Sooq platform must be erased there separately.', 'aisooq-connector' );
		}

		return array(
			'items_removed'  => $removed,
			'items_retained' => false,
			'messages'       => $messages,
			'done'           => count( $rows ) < self::PER_PAGE,
		);
	}

	/** Suggested privacy-policy text, per the WordPress.org requirement. */
	public function add_policy_content() {
		if ( ! function_exists( 'wp_add_privacy_policy_content' ) ) {
			return;
		}
		$content = '<p>' . __(
			'This site uses AI Sooq Connector, which sends order, customer, cart and analytics data to the AI Sooq platform so the store can be managed from there. That includes names, e-mail addresses, phone numbers, delivery addresses and cart contents — for incomplete checkouts as well as completed orders. Analytics events may be forwarded on to third-party advertising platforms.',
			'aisooq-connector'
		) . '</p>';
		wp_add_privacy_policy_content( __( 'AI Sooq Connector', 'aisooq-connector' ), wp_kses_post( $content ) );
	}
}
