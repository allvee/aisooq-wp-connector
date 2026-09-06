<?php
/**
 * Bidirectional customer sync.
 *
 *  - Push (WC → platform): WordPress user hooks enqueue an async POST to
 *    /connect/customers. Gated on a content hash so a pull-applied change never
 *    bounces back.
 *  - Pull (platform → WC): a WP-Cron poll of GET /connect/customers reconciles
 *    WordPress users, applying only changes newer than what we last saw
 *    (last-write-wins), under a suppression flag so the write doesn't re-push.
 *
 * Direction is operator-controlled (push / pull / both).
 *
 * @package AISooq
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class AI_Sooq_Customer_Sync {

	const CURSOR_OPTION        = 'aisooq_customer_cursor';
	const META_PLATFORM_ID     = '_aisooq_platform_customer_id';
	const META_HASH            = '_aisooq_cust_hash';
	const META_PLATFORM_UPDATED = '_aisooq_cust_platform_updated';

	/** True while applying a platform change, so the WP user hooks don't echo it back. */
	private static $suppress = false;

	/** @var AI_Sooq_Settings */
	private $settings;
	/** @var AI_Sooq_Api_Client */
	private $api;
	/** @var AI_Sooq_Logger */
	private $logger;

	public function __construct( AI_Sooq_Settings $settings, AI_Sooq_Api_Client $api, AI_Sooq_Logger $logger ) {
		$this->settings = $settings;
		$this->api      = $api;
		$this->logger   = $logger;
	}

	/**
	 * Hash of the payload's CONTENT, ignoring fields that change every call.
	 *
	 * `sourceUpdatedAt` is stamped with the current time, so hashing the whole
	 * payload produced a different digest on every run and the unchanged-skip
	 * gate below could never match. The effect was that every customer was
	 * re-uploaded on every trigger — burning the platform's rate limit on
	 * payloads identical to the ones already stored.
	 *
	 * @param array $payload
	 * @return string
	 */
	private static function content_hash( array $payload ) {
		unset( $payload['sourceUpdatedAt'] );
		return md5( (string) wp_json_encode( $payload ) );
	}

	public function register() {
		if ( ! $this->settings->get( 'enable_customer_sync' ) ) {
			return;
		}
		$dir = $this->settings->get( 'customer_sync_dir', 'both' );

		if ( 'push' === $dir || 'both' === $dir ) {
			add_action( 'user_register', array( $this, 'on_change' ), 20, 1 );
			add_action( 'profile_update', array( $this, 'on_change' ), 20, 1 );
			add_action( 'woocommerce_created_customer', array( $this, 'on_change' ), 20, 1 );
			add_action( 'woocommerce_save_account_details', array( $this, 'on_change' ), 20, 1 );
			add_action( AISOOQ_CUSTOMER_SYNC_ACTION, array( $this, 'handle_push' ), 10, 1 );
		}
		if ( 'pull' === $dir || 'both' === $dir ) {
			add_action( AISOOQ_CUSTOMER_PULL_CRON, array( $this, 'pull' ) );
		}
	}

	public function on_change( $user_id ) {
		if ( self::$suppress ) {
			return;
		}
		$user_id = (int) $user_id;
		if ( function_exists( 'as_enqueue_async_action' ) ) {
			if ( function_exists( 'as_has_scheduled_action' )
				&& as_has_scheduled_action( AISOOQ_CUSTOMER_SYNC_ACTION, array( $user_id ), AISOOQ_AS_GROUP ) ) {
				return;
			}
			as_enqueue_async_action( AISOOQ_CUSTOMER_SYNC_ACTION, array( $user_id ), AISOOQ_AS_GROUP );
		} else {
			$this->push_user( $user_id );
		}
	}

	public function handle_push( $user_id ) {
		$this->push_user( (int) $user_id );
	}

	/**
	 * Manual backfill: enqueue the most recent customers for a push (the "Sync
	 * customers" button). Returns how many were queued. No-op unless customer
	 * sync is enabled with a push direction.
	 *
	 * @param int $limit
	 * @return int
	 */
	public function backfill( $limit = 500 ) {
		$dir = $this->settings->get( 'customer_sync_dir', 'both' );
		if ( ! $this->settings->get( 'enable_customer_sync' ) || ( 'push' !== $dir && 'both' !== $dir ) ) {
			return 0;
		}
		$ids = get_users( array(
			'role__in' => array( 'customer' ),
			'number'   => max( 1, (int) $limit ),
			'orderby'  => 'registered',
			'order'    => 'DESC',
			'fields'   => 'ID',
		) );
		$n = 0;
		foreach ( (array) $ids as $id ) {
			$this->on_change( (int) $id );
			$n++;
		}
		$this->logger->debug( 'Backfill queued ' . $n . ' customers.' );
		return $n;
	}

	public function push_user( $user_id ) {
		$user = get_userdata( $user_id );
		if ( ! $user ) {
			return;
		}
		// `on_change` is bound to user_register/profile_update, which fire for
		// EVERY account — so without this an administrator editing their own
		// profile shipped their name, e-mail and address to the platform as a
		// "customer". backfill() already restricts itself to the customer role;
		// this makes the hooked path agree with it.
		if ( ! self::is_writable_customer( $user ) ) {
			return;
		}
		/**
		 * Whether a WordPress user should be mirrored as a platform customer.
		 *
		 * Stores that use a custom role for shoppers, or that want to exclude
		 * staff accounts more aggressively, can decide here.
		 *
		 * @param bool    $sync Whether to push this user.
		 * @param WP_User $user The user in question.
		 */
		if ( ! apply_filters( 'aisooq_should_sync_customer', true, $user ) ) {
			return;
		}
		$payload = $this->map_user( $user );
		$hash    = self::content_hash( $payload );
		if ( get_user_meta( $user_id, self::META_HASH, true ) === $hash ) {
			return; // unchanged since last sync (incl. a value we just pulled)
		}
		$res = $this->api->post( '/connect/customers', $payload );
		if ( is_wp_error( $res ) ) {
			$this->logger->error( 'Customer ' . $user_id . ' push failed: ' . $res->get_error_message() );
			return;
		}
		update_user_meta( $user_id, self::META_HASH, $hash );
		if ( ! empty( $res['id'] ) ) {
			update_user_meta( $user_id, self::META_PLATFORM_ID, (int) $res['id'] );
		}
		update_user_meta( $user_id, '_aisooq_cust_synced_at', current_time( 'mysql' ) );
		$this->logger->debug( 'Customer ' . $user_id . ' pushed (platform id ' . ( isset( $res['id'] ) ? $res['id'] : '?' ) . ').' );
	}

	private function map_user( $user ) {
		$id   = $user->ID;
		$name = trim( (string) $user->display_name );
		if ( '' === $name ) {
			$name = trim( get_user_meta( $id, 'first_name', true ) . ' ' . get_user_meta( $id, 'last_name', true ) );
		}
		return array_filter(
			array(
				'externalSource'  => 'woocommerce',
				'externalId'      => (string) $id,
				'email'           => $user->user_email,
				'phone'           => get_user_meta( $id, 'billing_phone', true ),
				'name'            => $name,
				'state'           => 'enabled',
				// WP users carry no modified timestamp; the change is happening
				// now, so "now" is the correct last-write-wins clock.
				'sourceUpdatedAt' => gmdate( 'c' ),
			),
			function ( $v ) {
				return '' !== $v && null !== $v;
			}
		);
	}

	/** WP-Cron: reconcile WordPress users from the platform. */
	public function pull() {
		$dir = $this->settings->get( 'customer_sync_dir', 'both' );
		if ( 'pull' !== $dir && 'both' !== $dir ) {
			return;
		}
		$cursor = get_option( self::CURSOR_OPTION, '' );
		$path   = '/connect/customers?limit=100' . ( $cursor ? '&updatedSince=' . rawurlencode( $cursor ) : '' );
		$res    = $this->api->get( $path );
		if ( is_wp_error( $res ) ) {
			$this->logger->error( 'Customer pull failed: ' . $res->get_error_message() );
			return;
		}
		$rows = isset( $res['customers'] ) && is_array( $res['customers'] ) ? $res['customers'] : array();
		$max  = $cursor;
		foreach ( $rows as $c ) {
			$this->apply_platform_customer( $c );
			if ( ! empty( $c['updatedAt'] ) && $c['updatedAt'] > $max ) {
				$max = $c['updatedAt'];
			}
		}
		if ( $max && $max !== $cursor ) {
			update_option( self::CURSOR_OPTION, $max, false );
		}
	}

	/**
	 * A pull may only write to ordinary shoppers.
	 *
	 * Anything that can edit content, manage the shop, or manage the site is
	 * out of scope for a customer record — that includes administrators, shop
	 * managers, editors and authors. This is deliberately a capability test
	 * rather than a role-name test, so a custom or renamed role with elevated
	 * capabilities is still protected.
	 *
	 * @param WP_User $user
	 * @return bool
	 */
	private static function is_writable_customer( $user ) {
		foreach ( array( 'manage_options', 'manage_woocommerce', 'edit_posts', 'edit_shop_orders', 'promote_users', 'edit_users' ) as $cap ) {
			if ( user_can( $user, $cap ) ) {
				return false;
			}
		}
		return true;
	}

	private function apply_platform_customer( $c ) {
		$platform_updated = isset( $c['updatedAt'] ) ? (string) $c['updatedAt'] : '';
		$source           = isset( $c['externalSource'] ) ? $c['externalSource'] : '';

		$user = null;
		if ( ! empty( $c['externalId'] ) && 'woocommerce' === $source ) {
			$user = get_user_by( 'id', (int) $c['externalId'] );
		}
		if ( ! $user && ! empty( $c['email'] ) ) {
			$user = get_user_by( 'email', $c['email'] );
		}

		if ( $user ) {
			// NEVER let a remote record rewrite a privileged account.
			//
			// The match is by WordPress user id (`externalId`) or by e-mail, and
			// both are values the platform holds — so a bad row, a re-keyed
			// site, or a compromised platform response could hand back an
			// administrator's id and rewrite `user_email`. Whoever controls the
			// address controls the password-reset link, i.e. the whole site.
			// Only accounts that cannot manage the site are writable here.
			if ( ! self::is_writable_customer( $user ) ) {
				$this->logger->error(
					'Refused platform customer write to privileged user ' . $user->ID .
					' (' . $user->user_login . '); only non-privileged customer accounts are writable.'
				);
				return;
			}

			// Last-write-wins: skip anything we've already applied or older.
			$last = get_user_meta( $user->ID, self::META_PLATFORM_UPDATED, true );
			if ( $last && $platform_updated && $platform_updated <= $last ) {
				return;
			}
			self::$suppress = true;
			$fields = array( 'ID' => $user->ID );
			if ( ! empty( $c['email'] ) ) {
				$fields['user_email'] = $c['email'];
			}
			if ( ! empty( $c['name'] ) ) {
				$fields['display_name'] = $c['name'];
			}
			wp_update_user( $fields );
			if ( ! empty( $c['phone'] ) ) {
				update_user_meta( $user->ID, 'billing_phone', $c['phone'] );
			}
			update_user_meta( $user->ID, self::META_PLATFORM_ID, (int) $c['id'] );
			update_user_meta( $user->ID, self::META_PLATFORM_UPDATED, $platform_updated );
			// Refresh the push hash so this pulled state isn't pushed straight back.
			$fresh = get_userdata( $user->ID );
			if ( $fresh ) {
				update_user_meta( $user->ID, self::META_HASH, self::content_hash( $this->map_user( $fresh ) ) );
			}
			self::$suppress = false;
			$this->logger->debug( 'Customer WP#' . $user->ID . ' updated from platform.' );
			return;
		}

		// No WP user — create one for a platform-native customer (both-way only).
		if ( ! empty( $c['email'] ) && 'both' === $this->settings->get( 'customer_sync_dir', 'both' ) && function_exists( 'wc_create_new_customer' ) ) {
			self::$suppress = true;
			$uid = wc_create_new_customer( $c['email'], '', '', array( 'display_name' => isset( $c['name'] ) ? $c['name'] : '' ) );
			if ( ! is_wp_error( $uid ) ) {
				if ( ! empty( $c['phone'] ) ) {
					update_user_meta( $uid, 'billing_phone', $c['phone'] );
				}
				update_user_meta( $uid, self::META_PLATFORM_ID, (int) $c['id'] );
				update_user_meta( $uid, self::META_PLATFORM_UPDATED, $platform_updated );
				$this->logger->debug( 'Customer created in WP (#' . $uid . ') from platform.' );
			}
			self::$suppress = false;
		}
	}
}
