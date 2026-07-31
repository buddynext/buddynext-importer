/**
 * Importer admin page: loads source stats over REST and runs the migration as a
 * sequence of batched /step calls, driving the progress monitor so a large site
 * imports without a request timeout.
 *
 * The phase list is NOT defined here - it is handed over in cfg.steps from the
 * PHP StepRegistry, already filtered to the domains this site can import. A
 * hardcoded copy in this file is what let "Start import" migrate 5 of 16 domains
 * and still report success.
 */
( function () {
	'use strict';

	var cfg = window.buddynextImporter || {};
	var apiFetch = window.wp && window.wp.apiFetch;

	// Dependency-ordered phases, from the server registry (see file header).
	var PHASES = Array.isArray( cfg.steps ) ? cfg.steps : [];

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

	// Every /step response carries a uniform `count` of rows written, so this does
	// not need to know each phase's own key. Summing a per-phase list here is how
	// the bar used to count six domains out of sixteen.
	function stepCount( res ) {
		return ( res && res.count ) || 0;
	}

	// The denominator is everything the source holds. /stats returns one entry per
	// domain, so summing its values keeps the total honest as domains are added -
	// no second list to update.
	function computeTotal( stats ) {
		return Object.keys( stats || {} ).reduce( function ( sum, domain ) {
			var value = stats[ domain ];
			return sum + ( typeof value === 'number' ? value : 0 );
		}, 0 );
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

		renderCommentRoots( data.comment_roots );

		toggleButtons( true );
		if ( ! cfg.bnActive ) {
			showNotice( ( cfg.i18n && cfg.i18n.bnInactive ) || '', 'warning' );
		}
	}

	// Comments whose root activity is not imported have no post to attach to, so
	// they cannot migrate. Saying so BEFORE the run is the whole point - after
	// it, the same fact is just an unexplained shortfall.
	function renderCommentRoots( roots ) {
		var note = el( 'bni-comment-roots' );
		if ( ! note || ! Array.isArray( roots ) ) {
			return;
		}

		var blocked = roots.filter( function ( r ) { return ! r.importable; } );
		if ( ! blocked.length ) {
			return;
		}

		var total = blocked.reduce( function ( sum, r ) { return sum + ( r.comments || 0 ); }, 0 );
		var types = blocked.map( function ( r ) { return r.type + ' (' + r.comments + ')'; } ).join( ', ' );

		note.textContent = ( ( cfg.i18n && cfg.i18n.commentRoots ) || '' )
			.replace( '%1$d', String( total ) )
			.replace( '%2$s', types );
		note.hidden = false;
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
		// No registry means no source was detected, so there is nothing to run.
		// Without this the empty chain would resolve straight to "Import complete."
		if ( ! PHASES.length ) {
			showNotice( ( cfg.i18n && cfg.i18n.noSource ) || '', 'warning' );
			return;
		}

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
			loadSummary();
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
			loadSummary();
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

	/**
	 * Show or hide the teardown confirmation, and move focus onto it when opening
	 * so a keyboard user is not left on a button whose panel appeared elsewhere.
	 */
	function toggleCleanupConfirm( open ) {
		var box = el( 'bni-cleanup-confirm' );
		var opener = el( 'bni-cleanup' );
		if ( ! box ) {
			return;
		}
		box.hidden = ! open;
		if ( opener ) {
			opener.hidden = !! open;
		}
		if ( open ) {
			var yes = el( 'bni-cleanup-yes' );
			if ( yes ) {
				yes.focus();
			}
		} else if ( opener ) {
			opener.focus();
		}
	}

	/**
	 * Drop the importer's working tables. The endpoint requires confirm:true, so
	 * this cannot fire from a stray POST, and it refuses while a job is running.
	 */
	function runCleanup() {
		var yes = el( 'bni-cleanup-yes' );
		if ( yes ) {
			yes.disabled = true;
		}
		apiFetch( {
			path: '/buddynext-importer/v1/cleanup',
			method: 'POST',
			headers: { 'X-WP-Nonce': cfg.nonce },
			data: { confirm: true }
		} ).then( function ( res ) {
			toggleCleanupConfirm( false );
			var teardown = document.querySelector( '.bni-teardown' );
			if ( teardown ) {
				teardown.hidden = true;
			}
			showNotice( ( res && res.message ) || '', 'success' );
		} ).catch( function ( err ) {
			if ( yes ) {
				yes.disabled = false;
			}
			// Surface the server's reason - "an import is still running" is the one
			// an owner needs to see, and a generic failure line would hide it.
			showNotice( ( err && err.message ) || ( cfg.i18n && cfg.i18n.runFailed ) || '', 'error' );
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

	// --- What was imported -------------------------------------------------
	// Rendered from /summary rather than accumulated during the run, so it is
	// still correct after a reload and identical whichever way the import ran.

	function renderSummary( data ) {
		var card = el( 'bni-summary-card' );
		var body = el( 'bni-summary-body' );
		if ( ! card || ! body || ! data || ! data.has_run || ! data.rows ) {
			return;
		}

		while ( body.firstChild ) {
			body.removeChild( body.firstChild );
		}

		data.rows.forEach( function ( row ) {
			// A domain this site cannot import at all is noise unless it moved
			// something, so leave it out rather than print a row of zeroes.
			if ( ! row.available && ! row.imported ) {
				return;
			}

			var tr = document.createElement( 'tr' );

			var name = document.createElement( 'td' );
			name.textContent = row.label;
			tr.appendChild( name );

			var src = document.createElement( 'td' );
			src.className = 'bni-summary__num';
			src.textContent = ( null === row.source || undefined === row.source ) ? '\u2014' : String( row.source );
			tr.appendChild( src );

			var got = document.createElement( 'td' );
			got.className = 'bni-summary__num';

			if ( row.skipped ) {
				// Chosen to be left behind. Not a number, and emphatically not a
				// shortfall: showing "0" against a source count of 4,812 would read
				// as a failed migration of the messages the owner declined.
				got.className += ' is-skipped';
				got.textContent = ( cfg.i18n && cfg.i18n.skippedByChoice ) || 'skipped by choice';
			} else {
				got.textContent = String( row.imported );
				// Flag only a real shortfall against a comparable source count.
				if ( 'number' === typeof row.source && row.imported < row.source ) {
					got.className += ' is-short';
					got.title = ( cfg.i18n && cfg.i18n.shortfall ) || '';
				}
			}
			tr.appendChild( got );

			body.appendChild( tr );
		} );

		card.hidden = false;
	}

	function loadSummary() {
		if ( ! apiFetch ) {
			return;
		}
		return apiFetch( {
			path: '/buddynext-importer/v1/summary',
			headers: { 'X-WP-Nonce': cfg.nonce }
		} ).then( renderSummary ).catch( function () {} );
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

	/*
	 * Domain selection.
	 *
	 * The phases, their contents and their dependencies all come from
	 * /domains, which derives them from StepRegistry - nothing about the
	 * pipeline is described twice in this file. That is the same rule that keeps
	 * PHASES above server-owned, and for the same reason.
	 */
	var DOMAINS = [];

	function selectedDomains() {
		return DOMAINS.filter( function ( phase ) {
			var box = el( 'bni-domain-' + phase.key );
			return box && box.checked;
		} ).map( function ( phase ) {
			return phase.key;
		} );
	}

	/**
	 * Tick a phase and everything it needs, transitively.
	 *
	 * Doing this in the UI rather than only on save is the point: the owner sees
	 * that choosing reactions also brought activity, instead of discovering it
	 * in the report afterwards. The server closes the same graph again on save,
	 * so a stale tab can never store a selection that strands content.
	 */
	function pullInDependencies( key, seen ) {
		seen = seen || {};
		if ( seen[ key ] ) {
			return;
		}
		seen[ key ] = true;

		var phase = DOMAINS.filter( function ( item ) {
			return item.key === key;
		} )[ 0 ];
		if ( ! phase ) {
			return;
		}

		var box = el( 'bni-domain-' + key );
		if ( box && ! box.disabled ) {
			box.checked = true;
		}

		( phase.depends || [] ).forEach( function ( needs ) {
			pullInDependencies( needs, seen );
		} );
	}

	/**
	 * Untick everything that depends on a phase being turned off.
	 *
	 * The alternative - leaving the children ticked - is the trap the whole
	 * dependency graph exists to close: the run would import comments whose
	 * posts were never brought across and report success.
	 */
	function dropDependents( key ) {
		DOMAINS.forEach( function ( phase ) {
			if ( ( phase.depends || [] ).indexOf( key ) === -1 ) {
				return;
			}
			var box = el( 'bni-domain-' + phase.key );
			if ( box && box.checked ) {
				box.checked = false;
				dropDependents( phase.key );
			}
		} );
	}

	function saveDomains() {
		if ( ! apiFetch ) {
			return Promise.resolve();
		}

		return apiFetch( {
			path: '/buddynext-importer/v1/domains',
			method: 'POST',
			headers: { 'X-WP-Nonce': cfg.nonce },
			data: { domains: selectedDomains() }
		} ).then( function ( res ) {
			// Re-tick from the RESOLVED list, so anything the server pulled in is
			// visible rather than silently applied.
			( res && res.selected ? res.selected : [] ).forEach( function ( key ) {
				var box = el( 'bni-domain-' + key );
				if ( box ) {
					box.checked = true;
				}
			} );
			// And re-point the run loop at the new order. PHASES was localised at
			// page load; without this, changing the selection and pressing Start
			// would run the domains chosen BEFORE the change.
			if ( res && Array.isArray( res.steps ) ) {
				PHASES = res.steps;
			}
		} ).catch( function () {} );
	}

	function renderDomains( phases, selected ) {
		var list = el( 'bni-domains-list' );
		var card = el( 'bni-domains-card' );
		if ( ! list || ! card || ! phases.length ) {
			return;
		}

		DOMAINS = phases;
		list.textContent = '';

		phases.forEach( function ( phase ) {
			var row = document.createElement( 'label' );
			row.className = 'bni-domain';

			var box = document.createElement( 'input' );
			box.type = 'checkbox';
			box.id = 'bni-domain-' + phase.key;
			box.checked = selected.indexOf( phase.key ) !== -1;
			// A domain this site cannot import stays off and unclickable, as it
			// already did in the run loop - the reason is spelled out beside it.
			box.disabled = ! phase.available;

			var name = document.createElement( 'span' );
			name.className = 'bni-domain__name';
			name.textContent = phase.label;

			// The contents line earns its place only when it says something the
			// name does not. "Follows / follows" is noise, and twelve rows of it
			// buries the two that matter - "Activity / posts, comments" is the
			// whole reason an owner can decide about this phase at all.
			var contents = ( phase.steps || [] );
			var detailText = phase.available
				? ( contents.length > 1 ? contents.join( ', ' ) : '' )
				: ( ( cfg.i18n && cfg.i18n.domainUnavailable ) || 'not available on this site' );

			var detail = document.createElement( 'span' );
			detail.className = 'bni-domain__detail';
			detail.textContent = detailText;

			box.addEventListener( 'change', function () {
				if ( box.checked ) {
					pullInDependencies( phase.key );
				} else {
					dropDependents( phase.key );
				}
				saveDomains();
			} );

			row.appendChild( box );
			row.appendChild( name );
			row.appendChild( detail );
			list.appendChild( row );
		} );

		card.hidden = false;
	}

	function loadDomains() {
		if ( ! apiFetch ) {
			return;
		}

		apiFetch( { path: '/buddynext-importer/v1/domains' } ).then( function ( res ) {
			if ( res && Array.isArray( res.phases ) ) {
				renderDomains( res.phases, Array.isArray( res.selected ) ? res.selected : [] );
			}
			if ( res && Array.isArray( res.steps ) ) {
				PHASES = res.steps;
			}
		} ).catch( function () {} );
	}

	function selectAllDomains() {
		DOMAINS.forEach( function ( phase ) {
			var box = el( 'bni-domain-' + phase.key );
			if ( box && ! box.disabled ) {
				box.checked = true;
			}
		} );
		saveDomains();
	}

	document.addEventListener( 'DOMContentLoaded', function () {
		loadStats();
		loadDomains();
		loadSummary();
		resumeIfRunning();
		var selectAll = el( 'bni-domains-all' );
		if ( selectAll ) {
			selectAll.addEventListener( 'click', selectAllDomains );
		}
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
		var cleanup = el( 'bni-cleanup' );
		if ( cleanup ) {
			cleanup.addEventListener( 'click', function () {
				toggleCleanupConfirm( true );
			} );
		}
		var cleanupNo = el( 'bni-cleanup-no' );
		if ( cleanupNo ) {
			cleanupNo.addEventListener( 'click', function () {
				toggleCleanupConfirm( false );
			} );
		}
		var cleanupYes = el( 'bni-cleanup-yes' );
		if ( cleanupYes ) {
			cleanupYes.addEventListener( 'click', runCleanup );
		}
	} );
} )();
