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
	<p class="bni-intro">
		<?php esc_html_e( 'Migrate an existing BuddyPress or BuddyBoss community into BuddyNext. This is a one-time tool: review what will be imported, run the migration, then deactivate and remove this plugin.', 'buddynext-importer' ); ?>
	</p>

	<div id="bni-notice" class="notice" hidden></div>

	<div class="bni-card">
		<h2 class="bni-card__title"><?php esc_html_e( 'Source community', 'buddynext-importer' ); ?></h2>
		<p id="bni-source" class="bni-source"><?php esc_html_e( 'Detecting source...', 'buddynext-importer' ); ?></p>

		<table class="widefat striped bni-stats" id="bni-stats" hidden>
			<thead>
				<tr>
					<th scope="col"><?php esc_html_e( 'Domain', 'buddynext-importer' ); ?></th>
					<th scope="col" class="bni-stats__count"><?php esc_html_e( 'Records', 'buddynext-importer' ); ?></th>
				</tr>
			</thead>
			<tbody id="bni-stats-body"></tbody>
		</table>
	</div>

	<div class="bni-card">
		<h2 class="bni-card__title"><?php esc_html_e( 'Progress', 'buddynext-importer' ); ?></h2>
		<div class="bni-progress" role="progressbar" aria-valuemin="0" aria-valuemax="100" aria-valuenow="0">
			<div class="bni-progress__bar" id="bni-progress-bar"></div>
		</div>
		<p class="bni-progress__label" id="bni-progress-label"><?php esc_html_e( 'Idle.', 'buddynext-importer' ); ?></p>

		<p class="bni-actions">
			<button type="button" class="button button-primary" id="bni-start" disabled>
				<?php esc_html_e( 'Start import', 'buddynext-importer' ); ?>
			</button>
		</p>
	</div>
</div>
