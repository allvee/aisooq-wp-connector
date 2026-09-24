/**
 * AI Sooq — the date-range picker.
 *
 * A trigger button showing the current range, and a popover holding preset
 * shortcuts beside a two-month calendar. It replaced a pair of
 * `<input type="date">`, which were two questions pretending to be one and
 * offered no way to say "last 30 days" — see AI_Sooq_Admin_Shell::date_range()
 * for the rest of that argument.
 *
 * IT DRIVES HIDDEN INPUTS, IT DOES NOT REPLACE THEM
 * -------------------------------------------------
 * The screen that hosts this already reads `.value` off `#aisooq-from` and
 * `#aisooq-to` and listens for `change` on them. Both still exist, hidden, and
 * this dispatches that same event on apply. Nothing downstream knows the
 * control was replaced, which is what let the abandoned-carts screen adopt it
 * without touching its query code.
 *
 * DATES ARE STRINGS, NEVER `Date` OBJECTS, ACROSS THE BOUNDARY
 * ------------------------------------------------------------
 * Everything crossing into or out of this file is 'YYYY-MM-DD'. `new Date(
 * '2026-09-07' )` parses as UTC midnight while `new Date( 2026, 8, 7 )` is
 * local midnight, so a browser west of Greenwich turns the former into the 6th
 * the moment you format it. Internally we use Date objects built from parts,
 * and we format by reading the local parts back — never `toISOString()`, which
 * is the same bug wearing a different hat.
 *
 * @package AISooq
 */
( function () {
	'use strict';

	var MS_DAY = 86400000;

	/** 'YYYY-MM-DD' from a Date's LOCAL parts. Never toISOString(). */
	function fmt( d ) {
		var m = String( d.getMonth() + 1 ).padStart( 2, '0' );
		var day = String( d.getDate() ).padStart( 2, '0' );
		return d.getFullYear() + '-' + m + '-' + day;
	}

	/** A local-midnight Date from 'YYYY-MM-DD', or null if it is not one. */
	function parse( s ) {
		var m = /^(\d{4})-(\d{2})-(\d{2})$/.exec( String( s || '' ) );
		if ( ! m ) {
			return null;
		}
		var d = new Date( +m[ 1 ], +m[ 2 ] - 1, +m[ 3 ] );
		// Rejects 2026-02-31, which the constructor would roll into March.
		return ( d.getMonth() === +m[ 2 ] - 1 && d.getDate() === +m[ 3 ] ) ? d : null;
	}

	function addDays( d, n ) {
		return new Date( d.getFullYear(), d.getMonth(), d.getDate() + n );
	}

	function startOfMonth( d ) {
		return new Date( d.getFullYear(), d.getMonth(), 1 );
	}

	function addMonths( d, n ) {
		return new Date( d.getFullYear(), d.getMonth() + n, 1 );
	}

	function sameDay( a, b ) {
		return !! a && !! b && a.getTime() === b.getTime();
	}

	function Picker( root ) {
		var cfg;
		try {
			cfg = JSON.parse( root.getAttribute( 'data-aisooq-daterange' ) );
		} catch ( e ) {
			return;
		}

		var fromEl = document.getElementById( cfg.fromId );
		var toEl = document.getElementById( cfg.toId );
		var trigger = root.querySelector( '.aisooq-dr__trigger' );
		var labelEl = root.querySelector( '.aisooq-dr__label' );
		if ( ! fromEl || ! toEl || ! trigger ) {
			return;
		}

		var today = parse( cfg.today ) || new Date();
		var pop = null;
		// The committed range, and the one being edited in the open popover.
		var start = parse( fromEl.value );
		var end = parse( toEl.value );
		var draftStart = start;
		var draftEnd = end;
		var hover = null;
		var view = startOfMonth( start || today );
		var focused = null;

		/* ── The trigger's label ─────────────────────────────────────────── */

		function pretty( d ) {
			return d.getDate() + ' ' + cfg.months[ d.getMonth() ].slice( 0, 3 ) + ' ' + d.getFullYear();
		}

		function syncLabel() {
			if ( ! start && ! end ) {
				labelEl.textContent = cfg.i18n.anyDate;
				root.classList.remove( 'is-set' );
				return;
			}
			root.classList.add( 'is-set' );
			if ( start && end && sameDay( start, end ) ) {
				labelEl.textContent = pretty( start );
			} else if ( start && end ) {
				labelEl.textContent = pretty( start ) + ' – ' + pretty( end );
			} else if ( start ) {
				labelEl.textContent = pretty( start ) + ' –';
			} else {
				labelEl.textContent = '– ' + pretty( end );
			}
		}

		/* ── Presets ─────────────────────────────────────────────────────── */

		/** Resolve a preset to [start, end], both possibly null. */
		function resolve( preset ) {
			var d = preset.days;
			if ( d === null ) {
				return [ null, null ];
			}
			if ( d === 'thismonth' ) {
				return [ startOfMonth( today ), today ];
			}
			if ( d === 'lastmonth' ) {
				var first = addMonths( startOfMonth( today ), -1 );
				return [ first, new Date( first.getFullYear(), first.getMonth() + 1, 0 ) ];
			}
			if ( d === 0 ) {
				return [ today, today ];
			}
			if ( d === -1 ) {
				var y = addDays( today, -1 );
				return [ y, y ];
			}
			// "Last N days" INCLUDES today, so it spans N days and not N+1.
			return [ addDays( today, -( d - 1 ) ), today ];
		}

		function activePreset() {
			for ( var i = 0; i < cfg.presets.length; i++ ) {
				var r = resolve( cfg.presets[ i ] );
				var s = r[ 0 ];
				var e = r[ 1 ];
				if ( ( ! s && ! draftStart && ! e && ! draftEnd ) || ( sameDay( s, draftStart ) && sameDay( e, draftEnd ) ) ) {
					return cfg.presets[ i ].key;
				}
			}
			return '';
		}

		/* ── Rendering ───────────────────────────────────────────────────── */

		function weekdayRow() {
			var out = '';
			for ( var i = 0; i < 7; i++ ) {
				var name = cfg.days[ ( i + cfg.startOfWeek ) % 7 ];
				out += '<span role="columnheader" abbr="' + name + '">' + name.slice( 0, 2 ) + '</span>';
			}
			return out;
		}

		function monthGrid( month ) {
			var first = startOfMonth( month );
			// How many blanks before the 1st, given where the week starts.
			var lead = ( first.getDay() - cfg.startOfWeek + 7 ) % 7;
			var days = new Date( month.getFullYear(), month.getMonth() + 1, 0 ).getDate();
			var cells = '';
			var i;

			for ( i = 0; i < lead; i++ ) {
				cells += '<span class="aisooq-dr__pad"></span>';
			}

			// While only one end is picked, the range previews to wherever the
			// pointer is — without it, picking a range is two blind clicks.
			var previewEnd = draftEnd || hover;

			for ( i = 1; i <= days; i++ ) {
				var d = new Date( month.getFullYear(), month.getMonth(), i );
				var cls = [ 'aisooq-dr__day' ];
				var inRange = draftStart && previewEnd && d > draftStart && d < previewEnd;
				var isStart = sameDay( d, draftStart );
				var isEnd = sameDay( d, previewEnd );

				if ( inRange ) { cls.push( 'is-in' ); }
				if ( isStart ) { cls.push( 'is-start' ); }
				if ( isEnd ) { cls.push( 'is-end' ); }
				if ( ( isStart || isEnd ) && ! ( draftStart && previewEnd && ! sameDay( draftStart, previewEnd ) ) ) { cls.push( 'is-only' ); }
				if ( sameDay( d, today ) ) { cls.push( 'is-today' ); }
				if ( d > today ) { cls.push( 'is-future' ); }

				var iso = fmt( d );
				var sel = ( isStart || isEnd ) ? 'true' : 'false';
				cells += '<button type="button" role="gridcell" class="' + cls.join( ' ' ) + '"'
					+ ' data-d="' + iso + '" aria-selected="' + sel + '"'
					+ ' tabindex="' + ( sameDay( d, focused ) ? '0' : '-1' ) + '">'
					+ i + '</button>';
			}

			return '<div class="aisooq-dr__month">'
				+ '<div class="aisooq-dr__caption">' + cfg.months[ month.getMonth() ] + ' ' + month.getFullYear() + '</div>'
				+ '<div class="aisooq-dr__grid" role="grid">'
				+ '<div class="aisooq-dr__week" role="row">' + weekdayRow() + '</div>'
				+ '<div class="aisooq-dr__days" role="rowgroup">' + cells + '</div>'
				+ '</div></div>';
		}

		function summary() {
			if ( ! draftStart && ! draftEnd ) {
				return cfg.i18n.anyDate;
			}
			if ( draftStart && draftEnd ) {
				return sameDay( draftStart, draftEnd ) ? pretty( draftStart ) : pretty( draftStart ) + ' – ' + pretty( draftEnd );
			}
			return pretty( draftStart || draftEnd ) + ' …';
		}

		function draw() {
			if ( ! pop ) {
				return;
			}
			var on = activePreset();
			var presets = cfg.presets.map( function ( p ) {
				return '<button type="button" class="aisooq-dr__preset' + ( p.key === on ? ' is-active' : '' ) + '"'
					+ ' data-preset="' + p.key + '"' + ( p.key === on ? ' aria-current="true"' : '' ) + '>'
					+ p.label + '</button>';
			} ).join( '' );

			pop.innerHTML =
				'<div class="aisooq-dr__presets">' + presets + '</div>'
				+ '<div class="aisooq-dr__cal">'
					+ '<div class="aisooq-dr__nav">'
						+ '<button type="button" class="aisooq-iconbtn aisooq-dr__prev" aria-label="' + cfg.i18n.prev + '">&lsaquo;</button>'
						+ '<button type="button" class="aisooq-iconbtn aisooq-dr__next" aria-label="' + cfg.i18n.next + '">&rsaquo;</button>'
					+ '</div>'
					+ '<div class="aisooq-dr__months">' + monthGrid( view ) + monthGrid( addMonths( view, 1 ) ) + '</div>'
					+ '<div class="aisooq-dr__foot">'
						+ '<span class="aisooq-dr__summary">' + summary() + '</span>'
						+ '<button type="button" class="aisooq-btn aisooq-btn--secondary aisooq-dr__cancel">' + cfg.i18n.cancel + '</button>'
						+ '<button type="button" class="aisooq-btn aisooq-btn--primary aisooq-dr__apply">' + cfg.i18n.apply + '</button>'
					+ '</div>'
				+ '</div>';

			// A month can be five or six rows tall, so the popover's height
			// changes as you page through it. Re-place, or it drifts off the
			// bottom of the screen halfway down the year.
			if ( pop.style.top ) {
				place();
			}
		}

		/* ── Open / close ────────────────────────────────────────────────── */

		/*
		 * The popover is `position: fixed` and placed from here rather than
		 * being laid out by CSS, because the card it lives in sets
		 * `overflow: hidden` to round its table's corners — which clipped an
		 * absolutely-positioned popover in half. A fixed element escapes
		 * ancestor overflow clipping, and it stays a DESCENDANT of `.wrap
		 * .aisooq` rather than being portalled to <body>, because the design
		 * tokens are custom properties declared on that wrapper and only
		 * inherit down the tree. Moved to the body it would render unstyled.
		 */
		var GAP = 6;
		var EDGE = 8;

		function place() {
			var t = trigger.getBoundingClientRect();
			var w = pop.offsetWidth;
			var h = pop.offsetHeight;

			// Prefer the trigger's left edge; pull back to its right edge, then
			// to the viewport, rather than letting it run off-screen.
			var left = t.left;
			if ( left + w > window.innerWidth - EDGE ) {
				left = t.right - w;
			}
			left = Math.max( EDGE, Math.min( left, window.innerWidth - w - EDGE ) );

			// Below unless it does not fit and there is more room above.
			var top = t.bottom + GAP;
			if ( top + h > window.innerHeight - EDGE && t.top - GAP - h > EDGE ) {
				top = t.top - GAP - h;
			}
			top = Math.max( EDGE, Math.min( top, window.innerHeight - h - EDGE ) );

			pop.style.left = Math.round( left ) + 'px';
			pop.style.top = Math.round( top ) + 'px';
		}

		// `true` for capture: the popover has to follow the trigger when ANY
		// ancestor scrolls, not only the window.
		function watch( on ) {
			var fn = on ? 'addEventListener' : 'removeEventListener';
			window[ fn ]( 'scroll', place, true );
			window[ fn ]( 'resize', place );
		}

		function open() {
			if ( pop ) {
				return;
			}
			draftStart = start;
			draftEnd = end;
			hover = null;
			focused = draftStart || today;
			view = startOfMonth( draftStart || today );
			pop = document.createElement( 'div' );
			pop.className = 'aisooq-dr__pop';
			pop.setAttribute( 'role', 'dialog' );
			pop.setAttribute( 'aria-label', cfg.i18n.selected );
			root.appendChild( pop );
			draw();
			place();
			watch( true );
			trigger.setAttribute( 'aria-expanded', 'true' );
			var f = pop.querySelector( '.aisooq-dr__day[tabindex="0"]' );
			if ( f ) {
				f.focus();
			}
		}

		function close( refocus ) {
			if ( ! pop ) {
				return;
			}
			watch( false );
			pop.remove();
			pop = null;
			trigger.setAttribute( 'aria-expanded', 'false' );
			if ( refocus ) {
				trigger.focus();
			}
		}

		function apply() {
			start = draftStart;
			end = draftEnd;
			fromEl.value = start ? fmt( start ) : '';
			toEl.value = end ? fmt( end ) : '';
			syncLabel();
			close( true );
			// The event the host screen is already listening for. Fired once,
			// on `from` only: both values are read together, and dispatching on
			// both would run the query twice for one decision.
			fromEl.dispatchEvent( new Event( 'change', { bubbles: true } ) );
		}

		/* ── Interaction ─────────────────────────────────────────────────── */

		function pick( d ) {
			if ( ! draftStart || draftEnd ) {
				// Starting a new range.
				draftStart = d;
				draftEnd = null;
			} else if ( d < draftStart ) {
				// Picked backwards — treat it as the new start rather than
				// refusing, which is what the operator meant.
				draftEnd = draftStart;
				draftStart = d;
			} else {
				draftEnd = d;
			}
			focused = d;
			hover = null;
			draw();
		}

		trigger.addEventListener( 'click', function () {
			if ( pop ) {
				close( false );
			} else {
				open();
			}
		} );

		root.addEventListener( 'click', function ( e ) {
			if ( ! pop ) {
				return;
			}
			var t = e.target;

			var day = t.closest( '.aisooq-dr__day' );
			if ( day ) {
				pick( parse( day.getAttribute( 'data-d' ) ) );
				var again = pop.querySelector( '.aisooq-dr__day[tabindex="0"]' );
				if ( again ) { again.focus(); }
				return;
			}

			var preset = t.closest( '.aisooq-dr__preset' );
			if ( preset ) {
				var key = preset.getAttribute( 'data-preset' );
				for ( var i = 0; i < cfg.presets.length; i++ ) {
					if ( cfg.presets[ i ].key === key ) {
						var r = resolve( cfg.presets[ i ] );
						draftStart = r[ 0 ];
						draftEnd = r[ 1 ];
						focused = draftStart || today;
						view = startOfMonth( draftStart || today );
						draw();
						break;
					}
				}
				return;
			}

			if ( t.closest( '.aisooq-dr__prev' ) ) { view = addMonths( view, -1 ); draw(); return; }
			if ( t.closest( '.aisooq-dr__next' ) ) { view = addMonths( view, 1 ); draw(); return; }
			if ( t.closest( '.aisooq-dr__apply' ) ) { apply(); return; }
			if ( t.closest( '.aisooq-dr__cancel' ) ) { close( true ); }
		} );

		root.addEventListener( 'mouseover', function ( e ) {
			if ( ! pop || ! draftStart || draftEnd ) {
				return;
			}
			var day = e.target.closest ? e.target.closest( '.aisooq-dr__day' ) : null;
			if ( ! day ) {
				return;
			}
			var d = parse( day.getAttribute( 'data-d' ) );
			if ( d && ! sameDay( d, hover ) && d > draftStart ) {
				hover = d;
				draw();
			}
		} );

		root.addEventListener( 'keydown', function ( e ) {
			if ( ! pop ) {
				return;
			}
			if ( 'Escape' === e.key ) {
				e.preventDefault();
				close( true );
				return;
			}
			var day = e.target.closest ? e.target.closest( '.aisooq-dr__day' ) : null;
			if ( ! day ) {
				return;
			}
			var step = { ArrowLeft: -1, ArrowRight: 1, ArrowUp: -7, ArrowDown: 7, PageUp: null, PageDown: null };
			if ( ! ( e.key in step ) ) {
				return;
			}
			e.preventDefault();
			var cur = parse( day.getAttribute( 'data-d' ) );
			var next = 'PageUp' === e.key ? addMonths( cur, -1 )
				: 'PageDown' === e.key ? addMonths( cur, 1 )
				: addDays( cur, step[ e.key ] );
			focused = next;
			// Follow the focus when it walks out of the two months on show.
			if ( next < view || next >= addMonths( view, 2 ) ) {
				view = startOfMonth( next );
			}
			draw();
			var el = pop.querySelector( '.aisooq-dr__day[data-d="' + fmt( next ) + '"]' );
			if ( el ) {
				el.focus();
			}
		} );

		document.addEventListener( 'click', function ( e ) {
			if ( ! pop ) {
				return;
			}
			/*
			 * `isConnected` first, and it is load-bearing. Choosing a preset or
			 * a day redraws the popover, which detaches the very node that was
			 * clicked — and `root.contains()` on a detached node is false.
			 * Without this guard every click inside the calendar read as a
			 * click outside it and shut the popover before anything applied.
			 */
			if ( e.target.isConnected && ! root.contains( e.target ) ) {
				close( false );
			}
		} );

		/*
		 * The host screen's Clear button writes '' straight into the hidden
		 * inputs, and assigning `.value` fires no event of its own — so it
		 * dispatches `input` to say so. Without this the trigger would go on
		 * claiming a range that is no longer filtering anything.
		 *
		 * `change` is listened for as well, for any host that reaches for the
		 * more familiar event. The guard below makes the picker's own apply a
		 * no-op here rather than a loop.
		 */
		[ 'input', 'change' ].forEach( function ( evt ) {
			[ fromEl, toEl ].forEach( function ( el ) {
			el.addEventListener( evt, function () {
				var s = parse( fromEl.value );
				var t = parse( toEl.value );
				if ( sameDay( s, start ) && sameDay( t, end ) ) {
					return;
				}
				start = s;
				end = t;
				syncLabel();
			} );
			} );
		} );

		syncLabel();
	}

	function init( scope ) {
		var nodes = ( scope || document ).querySelectorAll( '[data-aisooq-daterange]' );
		Array.prototype.forEach.call( nodes, function ( n ) {
			if ( ! n.__aisooqDr ) {
				n.__aisooqDr = true;
				Picker( n );
			}
		} );
	}

	if ( 'loading' === document.readyState ) {
		document.addEventListener( 'DOMContentLoaded', function () { init(); } );
	} else {
		init();
	}
}() );
