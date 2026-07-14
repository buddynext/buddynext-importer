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
	var currentSource = '';
	var mappingNew = '__new__';

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

	// ---- Profile field mapping ------------------------------------------

	function buildTargetSelect( field, targets ) {
		var sel = document.createElement( 'select' );
		sel.className = 'bni-mapping__select';
		sel.setAttribute( 'data-source-id', String( field.source_id ) );

		var optNew = document.createElement( 'option' );
		optNew.value = mappingNew;
		optNew.textContent = ( cfg.i18n && cfg.i18n.mapCreateNew ) || 'Create new field';
		sel.appendChild( optNew );

		// Group the BuddyNext targets by their group for a readable dropdown.
		var groups = {};
		var order = [];
		targets.forEach( function ( t ) {
			if ( ! groups[ t.group ] ) {
				groups[ t.group ] = [];
				order.push( t.group );
			}
			groups[ t.group ].push( t );
		} );
		order.forEach( function ( g ) {
			var og = document.createElement( 'optgroup' );
			og.label = g || '';
			groups[ g ].forEach( function ( t ) {
				var o = document.createElement( 'option' );
				o.value = t.key;
				o.textContent = t.label;
				og.appendChild( o );
			} );
			sel.appendChild( og );
		} );

		sel.value = field.target || mappingNew;
		return sel;
	}

	function renderMapping( data ) {
		if ( ! data || ! data.fields ) {
			return;
		}
		currentSource = data.source || '';
		mappingNew = data.new || '__new__';

		var body = el( 'bni-mapping-body' );
		var card = el( 'bni-mapping-card' );
		if ( ! body ) {
			return;
		}
		while ( body.firstChild ) {
			body.removeChild( body.firstChild );
		}

		data.fields.forEach( function ( field ) {
			var row = document.createElement( 'tr' );
			var tdName = document.createElement( 'td' );
			tdName.textContent = field.label;
			var tdType = document.createElement( 'td' );
			tdType.textContent = ( field.type || '' ).replace( /_/g, ' ' );
			var tdTarget = document.createElement( 'td' );
			tdTarget.appendChild( buildTargetSelect( field, data.targets || [] ) );
			row.appendChild( tdName );
			row.appendChild( tdType );
			row.appendChild( tdTarget );
			body.appendChild( row );
		} );

		if ( card ) {
			card.hidden = false;
		}
	}

	function loadMapping() {
		if ( ! apiFetch ) {
			return;
		}
		apiFetch( {
			path: '/buddynext-importer/v1/mapping',
			headers: { 'X-WP-Nonce': cfg.nonce }
		} )
			.then( renderMapping )
			.catch( function () {
				/* No source / not applicable - the mapping card simply stays hidden. */
			} );
	}

	function saveMapping() {
		var body = el( 'bni-mapping-body' );
		var status = el( 'bni-mapping-status' );
		if ( ! body ) {
			return;
		}
		var fields = {};
		Array.prototype.forEach.call(
			body.querySelectorAll( 'select.bni-mapping__select' ),
			function ( sel ) {
				fields[ sel.getAttribute( 'data-source-id' ) ] = sel.value;
			}
		);
		if ( status ) {
			status.textContent = '';
		}
		apiFetch( {
			path: '/buddynext-importer/v1/mapping',
			method: 'POST',
			headers: { 'X-WP-Nonce': cfg.nonce },
			data: { source: currentSource, fields: fields }
		} )
			.then( function () {
				if ( status ) {
					status.textContent = ( cfg.i18n && cfg.i18n.mapSaved ) || 'Saved.';
				}
			} )
			.catch( function () {
				if ( status ) {
					status.textContent = ( cfg.i18n && cfg.i18n.mapSaveFailed ) || '';
				}
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
		loadMapping();
		var start = el( 'bni-start' );
		if ( start ) {
			start.addEventListener( 'click', runImport );
		}
		var save = el( 'bni-mapping-save' );
		if ( save ) {
			save.addEventListener( 'click', saveMapping );
		}
	} );
} )();
