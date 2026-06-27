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
		var wrap = bar ? bar.parentNode : null;
		if ( wrap ) {
			wrap.setAttribute( 'aria-valuenow', String( percent ) );
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
		var source = el( 'bni-source' );
		var table = el( 'bni-stats' );
		var body = el( 'bni-stats-body' );
		var start = el( 'bni-start' );

		if ( ! data || ! data.available ) {
			source.textContent = ( cfg.i18n && cfg.i18n.noSource ) || '';
			return;
		}

		source.textContent = data.label;
		total = computeTotal( data.stats );

		while ( body.firstChild ) {
			body.removeChild( body.firstChild );
		}
		Object.keys( data.stats ).forEach( function ( domain ) {
			var row = document.createElement( 'tr' );
			var th = document.createElement( 'td' );
			var td = document.createElement( 'td' );
			th.textContent = domain.replace( /_/g, ' ' );
			td.className = 'bni-stats__count';
			td.textContent = String( data.stats[ domain ] );
			row.appendChild( th );
			row.appendChild( td );
			body.appendChild( row );
		} );
		table.hidden = false;

		if ( start && cfg.bnActive ) {
			start.disabled = false;
		} else if ( start ) {
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
		var start = el( 'bni-start' );
		if ( start ) {
			start.disabled = true;
		}
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
			setLabel( ( cfg.i18n && cfg.i18n.complete ) || 'Import complete.' );
			showNotice( ( cfg.i18n && cfg.i18n.complete ) || 'Import complete.', 'success' );
		} ).catch( function () {
			if ( start ) {
				start.disabled = false;
			}
			showNotice( ( cfg.i18n && cfg.i18n.runFailed ) || 'The import stopped on an error.', 'error' );
		} );
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
		var start = el( 'bni-start' );
		if ( start ) {
			start.addEventListener( 'click', runImport );
		}
	} );
} )();
