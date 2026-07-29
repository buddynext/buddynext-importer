<?php
/**
 * Fixture-only auto-login: ?autologin=1 signs in as user 1.
 *
 * This container is a disposable test fixture on localhost, never a site with
 * real users - the whole database is rebuilt by run.sh and lives in tmpfs. It
 * exists so browser verification does not spend a step on a login form.
 *
 * @package BuddyNextImporter
 */

defined( 'ABSPATH' ) || exit;

add_action(
	'init',
	static function (): void {
		if ( ! isset( $_GET['autologin'] ) || is_user_logged_in() ) {
			return;
		}

		$param = sanitize_text_field( wp_unslash( (string) $_GET['autologin'] ) );
		$user  = ( '1' === $param || 'admin' === $param )
			? get_user_by( 'ID', 1 )
			: ( get_user_by( 'login', $param ) ?: get_user_by( 'email', $param ) );

		if ( ! $user ) {
			return;
		}

		wp_set_current_user( $user->ID );
		wp_set_auth_cookie( $user->ID, true );
		wp_safe_redirect( remove_query_arg( 'autologin' ) );
		exit;
	},
	1
);
