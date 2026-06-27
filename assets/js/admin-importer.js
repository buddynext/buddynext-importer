/**
 * Importer admin page: loads source stats over REST and drives the progress
 * monitor. Phase 1 wires the read path end to end; the batched run loop lands
 * with the domain writers in later phases.
 */
( function () {
	'use strict';

	var cfg = window.buddynextImporter || {};
	var apiFetch = window.wp && window.wp.apiFetch;

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

		// The run loop is wired in a later phase; enable only when BuddyNext is active.
		if ( start && cfg.bnActive ) {
			start.disabled = false;
		} else if ( start ) {
			showNotice( ( cfg.i18n && cfg.i18n.bnInactive ) || '', 'warning' );
		}
	}

	function loadStats() {
		if ( ! apiFetch ) {
			return;
		}
		apiFetch( {
			path: '/buddynext-importer/v1/stats',
			headers: { 'X-WP-Nonce': cfg.nonce },
		} )
			.then( renderStats )
			.catch( function () {
				showNotice( ( cfg.i18n && cfg.i18n.loadFailed ) || '', 'error' );
			} );
	}

	document.addEventListener( 'DOMContentLoaded', loadStats );
} )();
