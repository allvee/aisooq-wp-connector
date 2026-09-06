<?php
/**
 * Manual block / allow list, and the record of every checkout this plugin
 * turned away.
 *
 * Two things live here, and they answer two different problems.
 *
 * THE LOG exists because a blocked checkout used to leave no trace at all.
 * When `fraud_action` is `block` the classic path adds a validation error and
 * returns, so no order is ever created and none of the `_aisooq_fraud_*` meta
 * is written — that only happens for `hold`/`flag`, which need an order. The
 * one trace was a debug line, and only with logging switched on. A merchant
 * therefore could not answer "how many customers did we turn away yesterday,
 * and were any of them real?", which is exactly the question you have to
 * answer before you dare tighten a threshold.
 *
 * THE LIST is the operator's own judgement, and it is deliberately LOCAL: no
 * API call, nothing billed, and it keeps working while the platform is
 * unreachable — the same reasoning as the duplicate-order guard. It runs
 * before every other gate, so a number you have decided about never costs a
 * paid lookup.
 *
 * `allow` beats `block`, always. An allow entry is how you rescue a real
 * customer the automatic layers keep rejecting, and it must therefore also
 * outrank the platform's own verdict — otherwise the operator has no final
 * say over their own shop.
 *
 * @package AISooq
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class AI_Sooq_Blocklist {

	/** Identifier kinds an entry can match on. */
	const TYPES = array( 'phone', 'email', 'ip' );

	/** How long a block-log row is kept. */
	const LOG_RETENTION_DAYS = 90;

	/** Rows removed per garbage-collection run. */
	const GC_BATCH = 1000;

	/** @var array<string,array>|null Per-request cache of the CIDR entries. */
	private static $cidr_cache = null;

	/** @var array<string,string> Per-request memo of decide() results. */
	private static $decision_memo = array();

	/**
	 * Truncate to a column's length without splitting a character.
	 *
	 * substr() counts BYTES. Every checkout message this plugin ships by
	 * default is Bangla, where one character is three bytes — so cutting at a
	 * byte offset lands mid-character and produces invalid UTF-8, which wpdb
	 * rejects outright ("Processing the value for the following field failed").
	 * The row is then silently dropped, which for the block log means the
	 * refusal it was recording disappears.
	 *
	 * @param string $value
	 * @param int    $len Characters.
	 * @return string
	 */
	private static function clamp( $value, $len ) {
		$value = (string) $value;
		return function_exists( 'mb_substr' ) ? mb_substr( $value, 0, $len, 'UTF-8' ) : substr( $value, 0, $len );
	}

	public static function table_name() {
		global $wpdb;
		return $wpdb->prefix . 'aisooq_blocklist';
	}

	public static function log_table_name() {
		global $wpdb;
		return $wpdb->prefix . 'aisooq_block_log';
	}

	public function register() {
		add_action( AISOOQ_BLOCK_GC_CRON, array( __CLASS__, 'gc' ) );
	}

	// ── Normalising what we compare ─────────────────────────────────────────

	/**
	 * Reduce an identifier to the one form everything compares against.
	 *
	 * Without this the list would match the punctuation rather than the
	 * person: `01712-345678` and `+8801712345678` are the same customer, and
	 * `Someone@Example.com` is the same mailbox as `someone@example.com`.
	 *
	 * @param string $type  phone|email|ip
	 * @param string $value Raw operator or checkout input.
	 * @return string Canonical form, or '' when there is nothing usable.
	 */
	public static function normalize( $type, $value ) {
		$value = trim( (string) $value );
		if ( '' === $value ) {
			return '';
		}

		switch ( $type ) {
			case 'phone':
				return class_exists( 'AI_Sooq_Order_Courier' )
					? AI_Sooq_Order_Courier::normalize_phone( $value )
					: preg_replace( '/\D+/', '', $value );

			case 'email':
				$email = strtolower( $value );
				return is_email( $email ) ? $email : '';

			case 'ip':
				// A CIDR range is stored verbatim; validity is checked here so a
				// typo cannot sit in the table matching nothing forever.
				if ( false !== strpos( $value, '/' ) ) {
					list( $net, $bits ) = array_pad( explode( '/', $value, 2 ), 2, '' );
					if ( ! filter_var( $net, FILTER_VALIDATE_IP ) || ! is_numeric( $bits ) ) {
						return '';
					}
					$max = ( false !== strpos( $net, ':' ) ) ? 128 : 32;
					$bits = (int) $bits;
					if ( $bits < 0 || $bits > $max ) {
						return '';
					}
					return $net . '/' . $bits;
				}
				return filter_var( $value, FILTER_VALIDATE_IP ) ? $value : '';
		}
		return '';
	}

	// ── The decision ────────────────────────────────────────────────────────

	/**
	 * Should this checkout be stopped by the operator's own list?
	 *
	 * @param string $phone
	 * @param string $email
	 * @param string $ip
	 * @return array{decision:string,reason:string,matched:string} decision is
	 *         'allow', 'block' or '' (no opinion).
	 */
	public static function decide( $phone, $email, $ip ) {
		$none = array( 'decision' => '', 'reason' => '', 'matched' => '' );

		$wanted = array(
			'phone' => self::normalize( 'phone', $phone ),
			'email' => self::normalize( 'email', $email ),
			'ip'    => self::normalize( 'ip', $ip ),
		);
		$wanted = array_filter( $wanted, 'strlen' );
		if ( ! $wanted ) {
			return $none;
		}

		$memo_key = wp_json_encode( $wanted );
		if ( isset( self::$decision_memo[ $memo_key ] ) ) {
			return self::$decision_memo[ $memo_key ];
		}

		try {
			$rows = self::match_rows( $wanted );

			// An allow entry always wins, whichever identifier carried it. It is
			// the operator overruling the machine, and that has to be final.
			foreach ( $rows as $row ) {
				if ( 'allow' === $row->mode ) {
					$out = array(
						'decision' => 'allow',
						'reason'   => (string) $row->reason,
						'matched'  => $row->type . ':' . $row->value,
					);
					self::$decision_memo[ $memo_key ] = $out;
					return $out;
				}
			}
			foreach ( $rows as $row ) {
				if ( 'block' === $row->mode ) {
					self::record_hit( (int) $row->id );
					$out = array(
						'decision' => 'block',
						'reason'   => (string) $row->reason,
						'matched'  => $row->type . ':' . $row->value,
					);
					self::$decision_memo[ $memo_key ] = $out;
					return $out;
				}
			}
		} catch ( \Throwable $e ) {
			// Consistent with every other gate: a fault here must never be the
			// reason a paying customer cannot check out.
			self::$decision_memo[ $memo_key ] = $none;
			return $none;
		}

		self::$decision_memo[ $memo_key ] = $none;
		return $none;
	}

	/**
	 * Every live entry matching any of these identifiers, in one query.
	 *
	 * Exact values are matched in SQL on the unique index. CIDR ranges cannot
	 * be, so they are loaded once and compared in PHP — there are only ever a
	 * handful, and the alternative is a full scan with a LIKE.
	 *
	 * @param array<string,string> $wanted type => normalised value
	 * @return array<int,object>
	 */
	private static function match_rows( array $wanted ) {
		global $wpdb;
		$table = self::table_name();

		$clauses = array();
		$params  = array();
		foreach ( $wanted as $type => $value ) {
			$clauses[] = '( type = %s AND value = %s )';
			$params[]  = $type;
			$params[]  = $value;
		}

		$sql = "SELECT id, type, value, mode, reason FROM {$table} WHERE ( "
			. implode( ' OR ', $clauses )
			. ' ) AND ( expires_at IS NULL OR expires_at > %s )';
		$params[] = current_time( 'mysql', true );

		$rows = (array) $wpdb->get_results( $wpdb->prepare( $sql, $params ) ); // phpcs:ignore WordPress.DB

		if ( ! empty( $wanted['ip'] ) && false === strpos( $wanted['ip'], '/' ) ) {
			foreach ( self::cidr_entries() as $entry ) {
				if ( self::ip_in_cidr( $wanted['ip'], $entry->value ) ) {
					$rows[] = $entry;
				}
			}
		}
		return $rows;
	}

	/** Live CIDR entries, loaded once per request. */
	private static function cidr_entries() {
		if ( null !== self::$cidr_cache ) {
			return self::$cidr_cache;
		}
		global $wpdb;
		$table = self::table_name();
		self::$cidr_cache = (array) $wpdb->get_results( // phpcs:ignore WordPress.DB
			$wpdb->prepare(
				"SELECT id, type, value, mode, reason FROM {$table}
				  WHERE type = 'ip' AND value LIKE %s AND ( expires_at IS NULL OR expires_at > %s )",
				'%/%',
				current_time( 'mysql', true )
			)
		);
		return self::$cidr_cache;
	}

	/**
	 * @param string $ip   A plain address.
	 * @param string $cidr network/bits
	 * @return bool
	 */
	public static function ip_in_cidr( $ip, $cidr ) {
		if ( false === strpos( $cidr, '/' ) ) {
			return $ip === $cidr;
		}
		list( $net, $bits ) = explode( '/', $cidr, 2 );
		$bits = (int) $bits;

		$ip_bin  = @inet_pton( $ip );  // phpcs:ignore WordPress.PHP.NoSilencedErrors
		$net_bin = @inet_pton( $net ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
		if ( false === $ip_bin || false === $net_bin || strlen( $ip_bin ) !== strlen( $net_bin ) ) {
			return false; // one is v4 and the other v6, or neither parses
		}
		if ( $bits <= 0 ) {
			return true;
		}

		$whole = intdiv( $bits, 8 );
		$rest  = $bits % 8;
		if ( $whole > 0 && 0 !== substr_compare( $ip_bin, substr( $net_bin, 0, $whole ), 0, $whole ) ) {
			return false;
		}
		if ( 0 === $rest ) {
			return true;
		}
		$mask = chr( 0xFF << ( 8 - $rest ) & 0xFF );
		return ( $ip_bin[ $whole ] & $mask ) === ( $net_bin[ $whole ] & $mask );
	}

	// ── Managing the list ───────────────────────────────────────────────────

	/**
	 * Add or update one entry.
	 *
	 * @param string      $type    phone|email|ip
	 * @param string      $value   Raw value; normalised here.
	 * @param string      $mode    block|allow
	 * @param string      $reason  Shown to nobody but the operator.
	 * @param string|null $expires 'Y-m-d H:i:s' UTC, or null for permanent.
	 * @return true|WP_Error
	 */
	public static function add( $type, $value, $mode, $reason = '', $expires = null ) {
		if ( ! in_array( $type, self::TYPES, true ) ) {
			return new WP_Error( 'aisooq_bad_type', __( 'Unknown identifier type.', 'aisooq-connector' ) );
		}
		$mode = ( 'allow' === $mode ) ? 'allow' : 'block';

		$normalised = self::normalize( $type, $value );
		if ( '' === $normalised ) {
			return new WP_Error(
				'aisooq_bad_value',
				/* translators: %s: the identifier type, e.g. phone. */
				sprintf( __( 'That does not look like a valid %s.', 'aisooq-connector' ), $type )
			);
		}

		global $wpdb;
		$now = current_time( 'mysql', true );

		// REPLACE-by-hand: changing your mind about a number should edit the
		// entry, not fail on the unique index or silently keep the old verdict.
		$existing = $wpdb->get_var( $wpdb->prepare( // phpcs:ignore WordPress.DB
			'SELECT id FROM ' . self::table_name() . ' WHERE type = %s AND value = %s',
			$type,
			$normalised
		) );

		$data = array(
			'type'       => $type,
			'value'      => $normalised,
			'mode'       => $mode,
			'reason'     => self::clamp( sanitize_text_field( (string) $reason ), 255 ),
			'expires_at' => $expires ? $expires : null,
			'created_by' => get_current_user_id(),
			'created_at' => $now,
		);

		$ok = $existing
			? $wpdb->update( self::table_name(), $data, array( 'id' => (int) $existing ) ) // phpcs:ignore WordPress.DB
			: $wpdb->insert( self::table_name(), $data ); // phpcs:ignore WordPress.DB

		self::flush_caches();
		return ( false === $ok )
			? new WP_Error( 'aisooq_db', __( 'Could not save that entry.', 'aisooq-connector' ) )
			: true;
	}

	/** @param int $id */
	public static function remove( $id ) {
		global $wpdb;
		$ok = $wpdb->delete( self::table_name(), array( 'id' => (int) $id ) ); // phpcs:ignore WordPress.DB
		self::flush_caches();
		return false !== $ok;
	}

	/** Per-request caches only; nothing here is persisted. */
	public static function flush_caches() {
		self::$cidr_cache    = null;
		self::$decision_memo = array();
	}

	private static function record_hit( $id ) {
		global $wpdb;
		$table = self::table_name();
		$wpdb->query( $wpdb->prepare( // phpcs:ignore WordPress.DB
			"UPDATE {$table} SET hits = hits + 1, last_hit_at = %s WHERE id = %d",
			current_time( 'mysql', true ),
			(int) $id
		) );
	}

	/**
	 * @param array $args status/search/paging
	 * @return array{rows:array,total:int}
	 */
	public static function entries( array $args = array() ) {
		global $wpdb;
		$table    = self::table_name();
		$per_page = max( 1, min( 200, (int) ( $args['per_page'] ?? 50 ) ) );
		$offset   = max( 0, (int) ( $args['offset'] ?? 0 ) );

		$where  = array( '1=1' );
		$params = array();
		if ( ! empty( $args['mode'] ) && in_array( $args['mode'], array( 'block', 'allow' ), true ) ) {
			$where[]  = 'mode = %s';
			$params[] = $args['mode'];
		}
		if ( ! empty( $args['type'] ) && in_array( $args['type'], self::TYPES, true ) ) {
			$where[]  = 'type = %s';
			$params[] = $args['type'];
		}
		if ( ! empty( $args['search'] ) ) {
			$where[]  = '( value LIKE %s OR reason LIKE %s )';
			$like     = '%' . $wpdb->esc_like( (string) $args['search'] ) . '%';
			$params[] = $like;
			$params[] = $like;
		}
		$where_sql = implode( ' AND ', $where );

		$total = (int) ( $params
			? $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$table} WHERE {$where_sql}", $params ) ) // phpcs:ignore WordPress.DB
			: $wpdb->get_var( "SELECT COUNT(*) FROM {$table} WHERE {$where_sql}" ) ); // phpcs:ignore WordPress.DB

		$sql       = "SELECT * FROM {$table} WHERE {$where_sql} ORDER BY created_at DESC LIMIT %d OFFSET %d";
		$params[]  = $per_page;
		$params[]  = $offset;
		$rows      = (array) $wpdb->get_results( $wpdb->prepare( $sql, $params ) ); // phpcs:ignore WordPress.DB

		return array( 'rows' => $rows, 'total' => $total );
	}

	// ── The record of what was turned away ──────────────────────────────────

	/**
	 * Record one refused checkout.
	 *
	 * Called from every gate, including the ones that block before an order
	 * exists — which is the whole point, since those were previously invisible.
	 *
	 * @param string $gate   manual|duplicate|fraud|courier
	 * @param string $reason Operator-facing explanation.
	 * @param array  $ctx    phone/email/ip/name/order_id/action
	 */
	public static function log( $gate, $reason, array $ctx = array() ) {
		try {
			global $wpdb;
			$wpdb->insert( // phpcs:ignore WordPress.DB
				self::log_table_name(),
				array(
					'gate'       => self::clamp( $gate, 32 ),
					'reason'     => self::clamp( $reason, 255 ),
					'action'     => self::clamp( $ctx['action'] ?? 'block', 16 ),
					'phone'      => self::clamp( self::normalize( 'phone', $ctx['phone'] ?? '' ), 64 ),
					'email'      => self::clamp( self::normalize( 'email', $ctx['email'] ?? '' ), 191 ),
					'ip'         => self::clamp( self::normalize( 'ip', $ctx['ip'] ?? '' ), 45 ),
					'name'       => self::clamp( sanitize_text_field( (string) ( $ctx['name'] ?? '' ) ), 191 ),
					'order_id'   => ! empty( $ctx['order_id'] ) ? (int) $ctx['order_id'] : null,
					'created_at' => current_time( 'mysql', true ),
				)
			);
		} catch ( \Throwable $e ) {
			// Bookkeeping must never break a checkout, even a refused one.
			return;
		}
	}

	/**
	 * @param array $args paging/filtering
	 * @return array{rows:array,total:int}
	 */
	public static function log_entries( array $args = array() ) {
		global $wpdb;
		$table    = self::log_table_name();
		$per_page = max( 1, min( 200, (int) ( $args['per_page'] ?? 50 ) ) );
		$offset   = max( 0, (int) ( $args['offset'] ?? 0 ) );

		$where  = array( '1=1' );
		$params = array();
		if ( ! empty( $args['gate'] ) ) {
			$where[]  = 'gate = %s';
			$params[] = (string) $args['gate'];
		}
		if ( ! empty( $args['search'] ) ) {
			$like     = '%' . $wpdb->esc_like( (string) $args['search'] ) . '%';
			$where[]  = '( phone LIKE %s OR email LIKE %s OR ip LIKE %s OR name LIKE %s )';
			$params   = array_merge( $params, array( $like, $like, $like, $like ) );
		}
		$where_sql = implode( ' AND ', $where );

		$total = (int) ( $params
			? $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$table} WHERE {$where_sql}", $params ) ) // phpcs:ignore WordPress.DB
			: $wpdb->get_var( "SELECT COUNT(*) FROM {$table} WHERE {$where_sql}" ) ); // phpcs:ignore WordPress.DB

		$sql      = "SELECT * FROM {$table} WHERE {$where_sql} ORDER BY created_at DESC LIMIT %d OFFSET %d";
		$params[] = $per_page;
		$params[] = $offset;
		$rows     = (array) $wpdb->get_results( $wpdb->prepare( $sql, $params ) ); // phpcs:ignore WordPress.DB

		return array( 'rows' => $rows, 'total' => $total );
	}

	/** Blocks per gate over the last N days, for the screen's headline. */
	public static function log_summary( $days = 7 ) {
		global $wpdb;
		$table = self::log_table_name();
		$since = gmdate( 'Y-m-d H:i:s', time() - max( 1, (int) $days ) * DAY_IN_SECONDS );
		$rows  = (array) $wpdb->get_results( $wpdb->prepare( // phpcs:ignore WordPress.DB
			"SELECT gate, COUNT(*) AS n FROM {$table} WHERE created_at >= %s GROUP BY gate",
			$since
		), ARRAY_A );

		$out = array( 'total' => 0 );
		foreach ( $rows as $r ) {
			$out[ $r['gate'] ] = (int) $r['n'];
			$out['total']     += (int) $r['n'];
		}
		return $out;
	}

	/**
	 * Prune old log rows and expired list entries.
	 *
	 * Bounded, because this table grows with attack traffic rather than with
	 * sales — precisely the case where an unbounded DELETE would lock the table
	 * at the worst possible moment.
	 */
	public static function gc() {
		global $wpdb;
		$log = self::log_table_name();
		$wpdb->query( $wpdb->prepare( // phpcs:ignore WordPress.DB
			"DELETE FROM {$log} WHERE created_at < %s LIMIT %d",
			gmdate( 'Y-m-d H:i:s', time() - self::LOG_RETENTION_DAYS * DAY_IN_SECONDS ),
			self::GC_BATCH
		) );

		$list = self::table_name();
		$wpdb->query( $wpdb->prepare( // phpcs:ignore WordPress.DB
			"DELETE FROM {$list} WHERE expires_at IS NOT NULL AND expires_at < %s LIMIT %d",
			current_time( 'mysql', true ),
			self::GC_BATCH
		) );
	}
}
