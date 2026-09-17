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
		// The refused-checkout log holds a name, e-mail, phone and IP for every
		// checkout the fraud gate turned away. It was never registered here, so a
		// data-subject request came back looking complete while silently omitting
		// the one table that records a person being refused service.
		$exporters['aisooq-connector-refusals'] = array(
			'exporter_friendly_name' => __( 'AI Sooq Connector — refused checkouts', 'aisooq-connector' ),
			'callback'               => array( $this, 'export_refusals' ),
		);
		return $exporters;
	}

	public function register_eraser( $erasers ) {
		$erasers['aisooq-connector'] = array(
			'eraser_friendly_name' => __( 'AI Sooq Connector — abandoned carts', 'aisooq-connector' ),
			'callback'             => array( $this, 'erase' ),
		);
		$erasers['aisooq-connector-refusals'] = array(
			'eraser_friendly_name' => __( 'AI Sooq Connector — refused checkouts', 'aisooq-connector' ),
			'callback'             => array( $this, 'erase_refusals' ),
		);
		return $erasers;
	}

	/** True when a plugin table exists — a fresh install may not have activated yet. */
	private static function table_exists( $table ) {
		global $wpdb;
		return $table === $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ); // phpcs:ignore WordPress.DB
	}

	/** The billing phone of the registered customer with this e-mail, or ''. */
	private static function known_phone( $email ) {
		$user = get_user_by( 'email', $email );
		return $user ? trim( (string) get_user_meta( $user->ID, 'billing_phone', true ) ) : '';
	}

	/**
	 * Refused-checkout rows for one person, one page at a time.
	 *
	 * Matched on e-mail — the key WordPress hands a privacy request — and also on
	 * the billing phone of a registered customer with that e-mail. In this market
	 * a refusal is very often recorded against a phone number alone, so matching
	 * e-mail only would still leave most of a person's refusals behind.
	 */
	private function refusal_rows( $email, $page ) {
		global $wpdb;
		$table = AI_Sooq_Blocklist::log_table_name();
		if ( ! self::table_exists( $table ) ) {
			return array();
		}
		$where = 'email = %s';
		$args  = array( $email );
		// A log row keeps the phone as the caller passed it, so match both the
		// raw billing phone and the store's normalised form — '+8801712…' and
		// '01712…' are the same person and must both be found.
		$phones = self::phone_forms( self::known_phone( $email ) );
		if ( $phones ) {
			$where .= ' OR phone IN (' . implode( ',', array_fill( 0, count( $phones ), '%s' ) ) . ')';
			$args   = array_merge( $args, $phones );
		}
		$args[] = self::PER_PAGE;
		$args[] = max( 0, ( (int) $page - 1 ) * self::PER_PAGE );

		return (array) $wpdb->get_results( // phpcs:ignore WordPress.DB
			$wpdb->prepare( "SELECT * FROM {$table} WHERE {$where} ORDER BY id ASC LIMIT %d OFFSET %d", $args ) // phpcs:ignore WordPress.DB
		);
	}

	/** Entries on the store's own block/allow list that name this person. */
	private function list_entries( $email ) {
		global $wpdb;
		$table = AI_Sooq_Blocklist::table_name();
		if ( ! self::table_exists( $table ) ) {
			return array();
		}
		// Entries are stored NORMALISED by AI_Sooq_Blocklist::add(), so a raw
		// e-mail or phone would miss them. Normalise with the same function, and
		// pair each value with its type so a phone never matches an e-mail row.
		$norm_email = AI_Sooq_Blocklist::normalize( 'email', $email );
		$norm_phone = AI_Sooq_Blocklist::normalize( 'phone', self::known_phone( $email ) );

		$where = array();
		$args  = array();
		if ( '' !== $norm_email ) {
			$where[] = '( type = %s AND value = %s )';
			array_push( $args, 'email', $norm_email );
		}
		if ( '' !== $norm_phone ) {
			$where[] = '( type = %s AND value = %s )';
			array_push( $args, 'phone', $norm_phone );
		}
		if ( ! $where ) {
			return array();
		}
		return (array) $wpdb->get_results( // phpcs:ignore WordPress.DB
			$wpdb->prepare( "SELECT * FROM {$table} WHERE " . implode( ' OR ', $where ), $args ) // phpcs:ignore WordPress.DB
		);
	}

	/** The raw phone plus its normalised form, de-duplicated; empty for no phone. */
	private static function phone_forms( $phone ) {
		if ( '' === $phone ) {
			return array();
		}
		return array_values( array_unique( array_filter( array( $phone, AI_Sooq_Blocklist::normalize( 'phone', $phone ) ), 'strlen' ) ) );
	}

	public function export_refusals( $email, $page = 1 ) {
		$rows = $this->refusal_rows( $email, $page );
		$out  = array();

		foreach ( $rows as $row ) {
			$out[] = array(
				'group_id'    => 'aisooq-refused-checkouts',
				'group_label' => __( 'Refused checkouts', 'aisooq-connector' ),
				'item_id'     => 'aisooq-refusal-' . (int) $row->id,
				'data'        => self::non_empty(
					array(
						array( 'name' => __( 'Refused at', 'aisooq-connector' ), 'value' => $row->created_at ),
						array( 'name' => __( 'Refused by', 'aisooq-connector' ), 'value' => $row->gate ),
						array( 'name' => __( 'Reason', 'aisooq-connector' ), 'value' => $row->reason ),
						array( 'name' => __( 'Name', 'aisooq-connector' ), 'value' => $row->name ),
						array( 'name' => __( 'Email', 'aisooq-connector' ), 'value' => $row->email ),
						array( 'name' => __( 'Phone', 'aisooq-connector' ), 'value' => $row->phone ),
						array( 'name' => __( 'IP address', 'aisooq-connector' ), 'value' => $row->ip ),
					)
				),
			);
		}

		// The block list is exported on page 1 only: it is short, and repeating
		// it on every page would duplicate it in the archive.
		if ( 1 === (int) $page ) {
			foreach ( $this->list_entries( $email ) as $entry ) {
				$out[] = array(
					'group_id'    => 'aisooq-block-list',
					'group_label' => __( 'Store block list', 'aisooq-connector' ),
					'item_id'     => 'aisooq-list-' . (int) $entry->id,
					'data'        => self::non_empty(
						array(
							array( 'name' => __( 'Matched on', 'aisooq-connector' ), 'value' => $entry->type ),
							array( 'name' => __( 'Value', 'aisooq-connector' ), 'value' => $entry->value ),
							array( 'name' => __( 'Action', 'aisooq-connector' ), 'value' => $entry->mode ),
							array( 'name' => __( 'Note', 'aisooq-connector' ), 'value' => $entry->reason ),
							array( 'name' => __( 'Added at', 'aisooq-connector' ), 'value' => $entry->created_at ),
						)
					),
				);
			}
		}

		return array(
			'data' => $out,
			'done' => count( $rows ) < self::PER_PAGE,
		);
	}

	/**
	 * Erase the refusal log; keep — and say so — the store's block list.
	 *
	 * A refusal record is history about a person and goes on request. The block
	 * list is different: it is the store's fraud control, and deleting an entry
	 * because the person it blocks asked would switch that control off for the
	 * exact customer it was added to stop. WordPress's eraser has a first-class
	 * way to report that — items_retained plus a message — so the operator sees
	 * what was kept and why, instead of assuming the erasure was complete.
	 */
	public function erase_refusals( $email, $page = 1 ) {
		global $wpdb;
		$messages = array();
		$removed  = false;

		// Always page 1: each pass deletes what it found.
		$rows = $this->refusal_rows( $email, 1 );
		if ( $rows ) {
			$table = AI_Sooq_Blocklist::log_table_name();
			foreach ( $rows as $row ) {
				if ( $wpdb->delete( $table, array( 'id' => (int) $row->id ), array( '%d' ) ) ) { // phpcs:ignore WordPress.DB
					$removed = true;
				}
			}
			$messages[] = sprintf(
				/* translators: %d: number of refused-checkout records removed. */
				__( 'Removed %d refused-checkout record(s) held by AI Sooq Connector.', 'aisooq-connector' ),
				count( $rows )
			);
		}

		$retained = false;
		if ( 1 === (int) $page && $this->list_entries( $email ) ) {
			$retained   = true;
			$messages[] = __( 'Kept this person\'s entry on the store block list. It is a fraud-prevention control, and removing it at their request would lift the block. Remove it under AI Sooq → Blocked if that is intended.', 'aisooq-connector' );
		}

		return array(
			'items_removed'  => $removed,
			'items_retained' => $retained,
			'messages'       => $messages,
			'done'           => count( $rows ) < self::PER_PAGE,
		);
	}

	/** Drop empty fields so an export does not list blank rows. */
	private static function non_empty( array $fields ) {
		return array_values(
			array_filter(
				$fields,
				function ( $f ) {
					return null !== $f['value'] && '' !== (string) $f['value'];
				}
			)
		);
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
