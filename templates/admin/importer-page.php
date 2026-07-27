<?php
/**
 * Importer admin page. Data is loaded over REST by assets/js/admin-importer.js;
 * this template is the static shell + the progress-monitor markup.
 *
 * @package BuddyNextImporter
 */

declare( strict_types=1 );

defined( 'ABSPATH' ) || exit;
?>
<div class="wrap bni-wrap">
	<h1><?php esc_html_e( 'Import to BuddyNext', 'buddynext-importer' ); ?></h1>

	<div class="bni-hero">
		<div class="bni-hero__mark" aria-hidden="true">
			<svg viewBox="0 0 24 24" role="img" focusable="false">
				<path d="M4 7V5a2 2 0 0 1 2-2h5l2 2h5a2 2 0 0 1 2 2v2" />
				<path d="M12 11v8" />
				<path d="m8 15 4 4 4-4" />
				<path d="M3 11h18" />
			</svg>
		</div>
		<div>
			<h2 class="bni-hero__title"><?php esc_html_e( 'Import to BuddyNext', 'buddynext-importer' ); ?></h2>
			<p class="bni-hero__sub">
				<?php esc_html_e( 'Migrate an existing BuddyPress or BuddyBoss community into BuddyNext. This is a one-time tool: review what will be imported, run the migration, then deactivate and remove this plugin.', 'buddynext-importer' ); ?>
			</p>
		</div>
	</div>

	<div id="bni-notice" class="notice" hidden></div>

	<div class="bni-card">
		<div class="bni-card__head">
			<h2 class="bni-card__title"><?php esc_html_e( 'Source community', 'buddynext-importer' ); ?></h2>
			<span class="bni-badge" id="bni-source" hidden></span>
		</div>

		<p class="bni-muted" id="bni-source-empty"><?php esc_html_e( 'Detecting source community...', 'buddynext-importer' ); ?></p>
		<div class="bni-stats" id="bni-stats-grid" hidden></div>
	</div>

	<div class="bni-card">
		<h2 class="bni-card__title"><?php esc_html_e( 'Progress', 'buddynext-importer' ); ?></h2>

		<div class="bni-progress" id="bni-progress" role="progressbar" aria-valuemin="0" aria-valuemax="100" aria-valuenow="0">
			<div class="bni-progress__bar" id="bni-progress-bar"></div>
		</div>
		<div class="bni-progress__meta">
			<p class="bni-progress__label" id="bni-progress-label"><?php esc_html_e( 'Idle.', 'buddynext-importer' ); ?></p>
			<span class="bni-progress__pct" id="bni-progress-pct"></span>
		</div>

		<p class="bni-actions">
			<button type="button" class="button button-primary" id="bni-start" disabled>
				<?php esc_html_e( 'Start import', 'buddynext-importer' ); ?>
			</button>
			<button type="button" class="button" id="bni-start-bg" disabled>
				<?php esc_html_e( 'Run in background', 'buddynext-importer' ); ?>
			</button>
			<button type="button" class="button-link bni-cancel" id="bni-cancel-bg" hidden>
				<?php esc_html_e( 'Cancel', 'buddynext-importer' ); ?>
			</button>
		</p>
		<p class="bni-hint" id="bni-bg-hint" hidden>
			<?php esc_html_e( 'The import is running on the server. You can leave this page - it will keep going, and reopening this screen will show its progress.', 'buddynext-importer' ); ?>
		</p>
	</div>
</div>
