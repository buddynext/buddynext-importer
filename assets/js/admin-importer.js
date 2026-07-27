/**
 * Importer admin page: loads source stats over REST and runs the migration as a
 * sequence of batched /step calls (profiles -> spaces -> activity -> friends),
 * driving the progress monitor so a large site imports without a request timeout.
 */
( function () {
	'use strict';

	var cfg = window.buddynextImporter || {};
	var apiFetch = window.wp && window.wp.apiFetch;

	// Dependency-ordered phases. Activity runs posts then comments so a comment
	// can resolve its root post.
	var PHASES = [
		{ phase: 'profiles', stage: null, label: 'profile fields' },
		{ phase: 'spaces', stage: null, label: 'spaces and members' },
		{ phase: 'activity', stage: 'posts', label: 'posts' },
		{ phase: 'activity', stage: 'comments', label: 'comments' },
		{ phase: 'friends', stage: null, label: 'connections' }
	];

	var total = 0;

	function el( id ) {
		return document.getElementById( id );
	}

	function showNotice( message, type ) {
		var notice = el( 'bni-notice' );
		if ( ! notice ) {
			return;
		}
		notice.textContent = message;
		notice.className = 'notice notice-' + ( type || 'info' );
		notice.hidden = false;
	}

	function setBar( percent ) {
		var bar = el( 'bni-progress-bar' );
		if ( bar ) {
			bar.style.width = percent + '%';
		}
		var wrap = el( 'bni-progress' );
		if ( wrap ) {
			wrap.setAttribute( 'aria-valuenow', String( percent ) );
		}
		var pct = el( 'bni-progress-pct' );
		if ( pct ) {
			pct.textContent = percent > 0 ? percent + '%' : '';
		}
	}

	function setRunning( on ) {
		var wrap = el( 'bni-progress' );
		if ( wrap ) {
			wrap.classList.toggle( 'is-running', !! on );
		}
	}

	function setLabel( text ) {
		var label = el( 'bni-progress-label' );
		if ( label ) {
			label.textContent = text;
		}
	}

	function stepCount( res ) {
		return ( res.values || 0 ) + ( res.members || 0 ) + ( res.groups || 0 ) +
			( res.posts || 0 ) + ( res.comments || 0 ) + ( res.connections || 0 );
	}

	function computeTotal( stats ) {
		return ( stats.profile_values || 0 ) + ( stats.groups || 0 ) + ( stats.group_members || 0 ) +
			( stats.activities || 0 ) + ( stats.activity_comments || 0 ) + ( stats.friendships || 0 );
	}

	function renderStats( data ) {
		var badge = el( 'bni-source' );
		var empty = el( 'bni-source-empty' );
		var grid = el( 'bni-stats-grid' );

		if ( ! data || ! data.available ) {
			if ( empty ) {
				empty.textContent = ( cfg.i18n && cfg.i18n.noSource ) || '';
			}
			return;
		}

		if ( badge ) {
			badge.textContent = data.label;
			badge.hidden = false;
		}
		if ( empty ) {
			empty.hidden = true;
		}

		total = computeTotal( data.stats );

		if ( grid ) {
			while ( grid.firstChild ) {
				grid.removeChild( grid.firstChild );
			}
			Object.keys( data.stats ).forEach( function ( domain ) {
				var tile = document.createElement( 'div' );
				tile.className = 'bni-stat';
				var num = document.createElement( 'span' );
				num.className = 'bni-stat__num';
				num.textContent = String( data.stats[ domain ] );
				var label = document.createElement( 'span' );
				label.className = 'bni-stat__label';
				label.textContent = domain.replace( /_/g, ' ' );
				tile.appendChild( num );
				tile.appendChild( label );
				grid.appendChild( tile );
			} );
			grid.hidden = false;
		}

		toggleButtons( true );
		if ( ! cfg.bnActive ) {
			showNotice( ( cfg.i18n && cfg.i18n.bnInactive ) || '', 'warning' );
		}
	}

	function step( spec, after ) {
		var data = { phase: spec.phase, after: after, batch: 50 };
		if ( spec.stage ) {
			data.stage = spec.stage;
		}
		return apiFetch( {
			path: '/buddynext-importer/v1/step',
			method: 'POST',
			headers: { 'X-WP-Nonce': cfg.nonce },
			data: data
		} );
	}

	function runPhase( spec, doneSoFar ) {
		var after = 0;
		setLabel( ( ( cfg.i18n && cfg.i18n.importing ) || 'Importing' ) + ' ' + spec.label + '...' );

		function next( accumulated ) {
			return step( spec, after ).then( function ( res ) {
				accumulated += stepCount( res );
				after = res.last;
				if ( total > 0 ) {
					setBar( Math.min( 100, Math.round( accumulated / total * 100 ) ) );
				}
				if ( res.done ) {
					return accumulated;
				}
				return next( accumulated );
			} );
		}

		return next( doneSoFar );
	}

	function runImport() {
		toggleButtons( false );
		setRunning( true );
		var notice = el( 'bni-notice' );
		if ( notice ) {
			notice.hidden = true;
		}

		var chain = Promise.resolve( 0 );
		PHASES.forEach( function ( spec ) {
			chain = chain.then( function ( done ) {
				return runPhase( spec, done );
			} );
		} );

		chain.then( function () {
			setBar( 100 );
			setRunning( false );
			setLabel( ( cfg.i18n && cfg.i18n.complete ) || 'Import complete.' );
			showNotice( ( cfg.i18n && cfg.i18n.complete ) || 'Import complete.', 'success' );
		} ).catch( function () {
			setRunning( false );
			toggleButtons( true );
			showNotice( ( cfg.i18n && cfg.i18n.runFailed ) || 'The import stopped on an error.', 'error' );
		} );
	}

	// --- Background (server-side, Action Scheduler) import -------------------
	// Unlike the browser /step loop above, this runs on the server, so the owner
	// can close the tab. The page polls /status and resumes the display if the
	// import is still running when the screen is reopened.

	var pollTimer = null;

	function toggleButtons( enabled ) {
		var startBtn = el( 'bni-start' );
		var startBg = el( 'bni-start-bg' );
		if ( startBtn ) {
			startBtn.disabled = ! ( enabled && cfg.bnActive );
		}
		if ( startBg ) {
			startBg.disabled = ! ( enabled && cfg.bnActive );
		}
	}

	function stopPolling() {
		if ( pollTimer ) {
			window.clearInterval( pollTimer );
			pollTimer = null;
		}
		var hint = el( 'bni-bg-hint' );
		var cancel = el( 'bni-cancel-bg' );
		if ( hint ) {
			hint.hidden = true;
		}
		if ( cancel ) {
			cancel.hidden = true;
		}
		setRunning( false );
	}

	// Reflect a /status envelope in the UI. Returns true while still running.
	function renderBgStatus( res ) {
		if ( ! res || res.state === 'idle' ) {
			return false;
		}
		setBar( res.percent || 0 );
		if ( res.state === 'running' ) {
			setLabel(
				( ( cfg.i18n && cfg.i18n.importing ) || 'Importing' ) +
				( res.phase ? ' ' + res.phase : '' ) +
				'... (' + res.done + '/' + res.total + ')'
			);
			return true;
		}
		if ( res.state === 'complete' ) {
			setBar( 100 );
			setLabel( ( cfg.i18n && cfg.i18n.complete ) || 'Import complete.' );
			showNotice( ( cfg.i18n && cfg.i18n.complete ) || 'Import complete.', 'success' );
			return false;
		}
		if ( res.state === 'error' ) {
			showNotice( ( cfg.i18n && cfg.i18n.runFailed ) || '', 'error' );
			return false;
		}
		return false;
	}

	function pollStatus() {
		return apiFetch( {
			path: '/buddynext-importer/v1/status',
			headers: { 'X-WP-Nonce': cfg.nonce }
		} ).then( function ( res ) {
			if ( ! renderBgStatus( res ) ) {
				stopPolling();
				toggleButtons( true );
			}
			return res;
		} );
	}

	function beginPolling() {
		var hint = el( 'bni-bg-hint' );
		var cancel = el( 'bni-cancel-bg' );
		if ( hint ) {
			hint.hidden = false;
		}
		if ( cancel ) {
			cancel.hidden = false;
		}
		toggleButtons( false );
		setRunning( true );
		if ( ! pollTimer ) {
			pollTimer = window.setInterval( pollStatus, 3000 );
		}
	}

	function runBackground() {
		var notice = el( 'bni-notice' );
		if ( notice ) {
			notice.hidden = true;
		}
		apiFetch( {
			path: '/buddynext-importer/v1/background',
			method: 'POST',
			headers: { 'X-WP-Nonce': cfg.nonce }
		} ).then( function ( res ) {
			beginPolling();
			renderBgStatus( res );
		} ).catch( function () {
			toggleButtons( true );
			showNotice( ( cfg.i18n && cfg.i18n.runFailed ) || '', 'error' );
		} );
	}

	function cancelBackground() {
		apiFetch( {
			path: '/buddynext-importer/v1/background/cancel',
			method: 'POST',
			headers: { 'X-WP-Nonce': cfg.nonce }
		} ).then( function () {
			stopPolling();
			setLabel( 'Idle.' );
			toggleButtons( true );
		} );
	}

	// On load, resume the live display only if an import is actually running.
	function resumeIfRunning() {
		if ( ! apiFetch ) {
			return;
		}
		apiFetch( {
			path: '/buddynext-importer/v1/status',
			headers: { 'X-WP-Nonce': cfg.nonce }
		} ).then( function ( res ) {
			if ( res && res.state === 'running' ) {
				renderBgStatus( res );
				beginPolling();
			}
		} ).catch( function () {} );
	}

	function loadStats() {
		if ( ! apiFetch ) {
			return;
		}
		apiFetch( {
			path: '/buddynext-importer/v1/stats',
			headers: { 'X-WP-Nonce': cfg.nonce }
		} )
			.then( renderStats )
			.catch( function () {
				showNotice( ( cfg.i18n && cfg.i18n.loadFailed ) || '', 'error' );
			} );
	}

	document.addEventListener( 'DOMContentLoaded', function () {
		loadStats();
		resumeIfRunning();
		var start = el( 'bni-start' );
		if ( start ) {
			start.addEventListener( 'click', runImport );
		}
		var startBg = el( 'bni-start-bg' );
		if ( startBg ) {
			startBg.addEventListener( 'click', runBackground );
		}
		var cancel = el( 'bni-cancel-bg' );
		if ( cancel ) {
			cancel.addEventListener( 'click', cancelBackground );
		}
	} );
} )();
