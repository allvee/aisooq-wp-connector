/**
 * AI Sooq Connector — visitor attribution tracker.
 *
 * Persists FIRST-touch and LAST-touch attribution in cookies (landing page,
 * referrer, traffic source, utm_*), a visit counter, device/language, and a
 * client-local browser-time snapshot (hour range, weekday, month, timezone).
 * The plugin reads these cookies server-side when an order is placed and
 * forwards them to the platform.
 */
( function () {
	'use strict';

	var DAYS = 180;
	var WEEK = [ 'Sunday', 'Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday' ];
	var MON = [ 'January', 'February', 'March', 'April', 'May', 'June', 'July', 'August', 'September', 'October', 'November', 'December' ];

	function setCookie( name, value, days ) {
		var d = new Date();
		d.setTime( d.getTime() + days * 864e5 );
		document.cookie = name + '=' + encodeURIComponent( value ) + ';expires=' + d.toUTCString() + ';path=/;SameSite=Lax';
	}
	function getCookie( name ) {
		var m = document.cookie.match( '(^|;)\\s*' + name + '\\s*=\\s*([^;]+)' );
		return m ? decodeURIComponent( m.pop() ) : '';
	}

	/**
	 * Read a tracker cookie, falling back to the names used before the plugin
	 * was renamed. A returning visitor is still carrying `sp_*` (or `wafi_*`)
	 * cookies; without this their first touch and visit count would reset to
	 * zero on the update, which would look like a wave of brand-new traffic.
	 * The legacy names can go once DAYS has rolled over for everyone.
	 */
	function getTracked( name ) {
		var suffix = name.replace( /^aisooq_/, '' );
		return getCookie( name ) || getCookie( 'sp_' + suffix ) || getCookie( 'wafi_' + suffix );
	}

	function utmParams() {
		var out = {};
		try {
			new URLSearchParams( window.location.search ).forEach( function ( v, k ) {
				if ( /^utm_/i.test( k ) || k === 'gclid' || k === 'fbclid' || k === 'ttclid' ) {
					out[ k.toLowerCase() ] = v.slice( 0, 256 );
				}
			} );
		} catch ( e ) {}
		return out;
	}

	// Referrer-based traffic source (independent of utm_source), matching the
	// "Traffic source: direct/organic/social/referral" bucket.
	function trafficSource( ref ) {
		if ( ! ref ) {
			return 'direct';
		}
		try {
			var h = new URL( ref ).hostname.replace( /^www\./, '' );
			if ( h === location.hostname ) { return 'internal'; }
			if ( /(google|bing|yahoo|duckduckgo|ecosia)\./.test( h ) ) { return 'organic'; }
			if ( /(facebook|instagram|tiktok|twitter|x\.com|linkedin|youtube|pinterest|t\.co|snapchat)/.test( h ) ) { return 'social'; }
			return 'referral';
		} catch ( e ) {
			return 'referral';
		}
	}

	var now = new Date();
	var utm = utmParams();
	var ref = document.referrer || '';

	var touch = {
		landing_page: location.href,
		landing_path: location.pathname + location.search,
		referrer: ref,
		traffic_source: trafficSource( ref ),
		utm_source: utm.utm_source || '',
		utm_medium: utm.utm_medium || '',
		utm_campaign: utm.utm_campaign || '',
		utm_content: utm.utm_content || '',
		utm_term: utm.utm_term || '',
		gclid: utm.gclid || '',
		fbclid: utm.fbclid || '',
		ttclid: utm.ttclid || '',
		at: now.toISOString()
	};

	// First touch: write once, keep forever (within DAYS). Carries a legacy
	// cookie forward under the new name rather than overwriting it, so the
	// original landing survives the rename.
	var firstTouch = getTracked( 'aisooq_first' );
	if ( ! getCookie( 'aisooq_first' ) ) {
		setCookie( 'aisooq_first', firstTouch || JSON.stringify( touch ), DAYS );
	}

	// Last touch: refresh whenever this landing carries campaign info, an
	// external referrer, or there is no last touch yet.
	if ( utm.utm_source || ( ref && trafficSource( ref ) !== 'internal' ) || ! getTracked( 'aisooq_last' ) ) {
		setCookie( 'aisooq_last', JSON.stringify( touch ), DAYS );
	} else if ( ! getCookie( 'aisooq_last' ) ) {
		setCookie( 'aisooq_last', getTracked( 'aisooq_last' ), DAYS );
	}

	// Visit counter — continues from the legacy count instead of restarting.
	var vc = parseInt( getTracked( 'aisooq_vc' ) || '0', 10 ) + 1;
	setCookie( 'aisooq_vc', String( vc ), DAYS );

	// Client-local browser time snapshot (captured every page; the order picks
	// up the most recent one).
	var hour = now.getHours();
	var bt = {
		hour: hour,
		hour_range: hour + '-' + ( ( hour + 1 ) % 24 ),
		day: WEEK[ now.getDay() ],
		month: MON[ now.getMonth() ],
		iso: now.toISOString(),
		tz_offset_min: now.getTimezoneOffset()
	};
	try { bt.timezone = Intl.DateTimeFormat().resolvedOptions().timeZone || ''; } catch ( e ) {}
	setCookie( 'aisooq_bt', JSON.stringify( bt ), 1 );

	// Device + language (cheap signals).
	setCookie( 'aisooq_dev', /Mobi|Android|iPhone|iPad|Windows Phone/i.test( navigator.userAgent ) ? 'mobile' : 'desktop', DAYS );
	setCookie( 'aisooq_lang', ( navigator.language || '' ).slice( 0, 16 ), DAYS );
} )();
