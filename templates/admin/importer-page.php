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

	<?php
	/*
	 * Standing advisory, above everything and never dismissible.
	 *
	 * A migration writes a whole community through BuddyNext's live services.
	 * Every write is idempotent and a re-run adds nothing twice, but there is no
	 * "undo": the rows are real, members can see them, and taking them back out
	 * again is a restore-from-backup job rather than a button. Someone who runs
	 * this against production to find out what it does has already done it.
	 *
	 * Deliberately not dismissible and not stored per user. The audience is a
	 * site owner who opens this screen once, so a "don't show again" would only
	 * ever hide it from the person who most needs it.
	 */
	?>
	<div class="bni-standing" role="note">
		<span class="bni-standing__mark" aria-hidden="true">
			<?php
			/*
			 * Size and paint declared as ATTRIBUTES, not left to CSS alone.
			 * An inline <svg> with only a viewBox has no intrinsic size, so if the
			 * stylesheet is missing, stale in cache, or blocked, it expands to fill
			 * its container and paints solid black - a full-page triangle instead of
			 * a 20px marker. Seen for real. CSS still owns the final look and may
			 * override these; the attributes only guarantee it degrades to a small
			 * outlined icon rather than swallowing the screen.
			 */
			?>
			<svg viewBox="0 0 24 24" width="20" height="20" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" role="img" focusable="false">
				<path d="M10.29 3.86 1.82 18a2 2 0 0 0 1.71 3h16.94a2 2 0 0 0 1.71-3L13.71 3.86a2 2 0 0 0-3.42 0z" />
				<path d="M12 9v4" />
				<path d="M12 17h.01" />
			</svg>
		</span>
		<div class="bni-standing__body">
			<p class="bni-standing__title"><?php esc_html_e( 'Run this on a staging copy first, not on your live community.', 'buddynext-importer' ); ?></p>
			<p>
				<?php
				esc_html_e(
					'An import writes real members, spaces, posts and messages into BuddyNext. It never duplicates on a re-run, but it cannot be undone from here - reversing it means restoring a backup. Copy your site to staging, migrate there, check the result with the migration checks below, and only then repeat it on production.',
					'buddynext-importer'
				);
				?>
			</p>
		</div>
	</div>

	<div class="bni-hero">
		<div class="bni-hero__mark" aria-hidden="true">
			<?php /* Same reason as the advisory marker above: never unsized. */ ?>
			<svg viewBox="0 0 24 24" width="26" height="26" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" role="img" focusable="false">
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
		<?php /* Content the migration cannot carry, shown before it is started. */ ?>
		<p class="bni-warn" id="bni-comment-roots" hidden></p>
		<?php /* Content this importer does not read at all - named before a run, because it cannot surface as a shortfall after one. */ ?>
		<p class="bni-warn" id="bni-unsupported" hidden></p>
	</div>

	<?php
	/*
	 * What to bring across. Everything is selected by default, so an owner who
	 * ignores this panel gets exactly the all-or-nothing migration they got
	 * before it existed.
	 *
	 * It sits directly under the source counts on purpose: the only way to
	 * decide whether to carry five years of private messages is to see how many
	 * there are. The list is built in JS from /domains, which derives it from
	 * StepRegistry, so a domain added to the pipeline appears here on its own.
	 */
	?>
	<div class="bni-card" id="bni-domains-card" hidden>
		<div class="bni-card__head">
			<h2 class="bni-card__title"><?php esc_html_e( 'What to import', 'buddynext-importer' ); ?></h2>
			<button type="button" class="button-link" id="bni-domains-all">
				<?php esc_html_e( 'Select all', 'buddynext-importer' ); ?>
			</button>
		</div>

		<p class="bni-muted">
			<?php esc_html_e( 'Everything is selected. Clear anything you would rather not bring across - your old community is not changed either way.', 'buddynext-importer' ); ?>
		</p>

		<div class="bni-domains" id="bni-domains-list"></div>

		<?php
		/*
		 * The half of the story that makes deselecting safe. Skipping is
		 * reversible - every write is keyed in bni_id_map, so a later run with
		 * more selected tops up instead of duplicating - but ONLY until "Remove
		 * import data" drops that map. Saying just the first half would invite an
		 * owner to skip messages, tidy up, and then duplicate their whole
		 * community trying to add them back.
		 */
		?>
		<p class="bni-hint" id="bni-domains-hint">
			<span>
				<?php esc_html_e( 'You can run the import again later with more selected - anything already imported is recognised and never duplicated. That protection lives in the importer\'s own mapping tables, so add whatever you skipped BEFORE you use "Remove import data" below.', 'buddynext-importer' ); ?>
			</span>
		</p>
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

		<?php
		/*
		 * Finishing the migration. The id-map and checkpoint are scaffolding, and
		 * this is the step that clears them away - previously only reachable as
		 * `wp buddynext-import cleanup`, which an owner who ran everything from this
		 * page could not use.
		 *
		 * Two-step rather than a native confirm(): a browser dialog cannot say what
		 * is lost, is unstyleable, and is banned by the BuddyNext UX standard. The
		 * consequence is spelled out in the page, where the owner is already reading.
		 */
		?>
		<div class="bni-teardown">
			<p class="bni-actions">
				<button type="button" class="button-link bni-teardown__open" id="bni-cleanup">
					<?php esc_html_e( 'Remove import data', 'buddynext-importer' ); ?>
				</button>
			</p>
			<div class="bni-teardown__confirm" id="bni-cleanup-confirm" hidden>
				<?php
				/*
				 * The whole warning is ONE child of .bni-hint. That class is
				 * display:flex with a marker ::before, so a bare <strong> + text
				 * become two flex items - the heading collapsed into a ~110px
				 * column and wrapped over four lines, and the <br> did nothing.
				 * Wrapping keeps it a single item, which is what the component
				 * expects.
				 */
				?>
				<p class="bni-hint bni-hint--danger">
					<span>
						<strong><?php esc_html_e( 'Remove the importer working tables?', 'buddynext-importer' ); ?></strong><br />
						<?php esc_html_e( 'This drops the id-map and resume checkpoint. Your migrated community is untouched, but the record of what was already imported is gone - so running the import again afterwards would re-create everything from scratch, with no duplicate protection. Do this once the migration is complete and checked.', 'buddynext-importer' ); ?>
					</span>
				</p>
				<p class="bni-actions">
					<button type="button" class="button" id="bni-cleanup-yes">
						<?php esc_html_e( 'Remove import data', 'buddynext-importer' ); ?>
					</button>
					<button type="button" class="button-link" id="bni-cleanup-no">
						<?php esc_html_e( 'Keep it', 'buddynext-importer' ); ?>
					</button>
				</p>
			</div>
		</div>
	</div>

	<?php
	/*
	 * What the migration actually moved. "Import complete" alone cannot answer
	 * "did my hundred message threads arrive?", and a migration is exactly the
	 * moment an owner needs that answered. Populated from /summary, so it is
	 * still here after a reload and covers the background run too.
	 */
	?>
	<div class="bni-card" id="bni-summary-card" hidden>
		<h2 class="bni-card__title"><?php esc_html_e( 'What was imported', 'buddynext-importer' ); ?></h2>
		<table class="bni-summary" id="bni-summary">
			<thead>
				<tr>
					<th scope="col"><?php esc_html_e( 'Domain', 'buddynext-importer' ); ?></th>
					<th scope="col" class="bni-summary__num"><?php esc_html_e( 'In source', 'buddynext-importer' ); ?></th>
					<th scope="col" class="bni-summary__num"><?php esc_html_e( 'Imported', 'buddynext-importer' ); ?></th>
				</tr>
			</thead>
			<tbody id="bni-summary-body"></tbody>
		</table>
		<p class="bni-hint">
			<?php esc_html_e( 'A domain can be legitimately short - private content its author cannot post into, or content whose parent was not migrated. A domain marked unavailable needs the plugin that owns it (Jetonomy for forums, WPMediaVerse for media and messages).', 'buddynext-importer' ); ?>
		</p>
	</div>

	<?php
	/*
	 * The deeper check, and the last thing an owner should read before deleting
	 * their old community.
	 *
	 * Totals cannot see placement or privacy: a migration can reconcile
	 * perfectly on every domain while a post sits in the wrong space, in front
	 * of the wrong people. All of this already existed in VerifyService and was
	 * reachable only through WP-CLI, which the owner running this screen does
	 * not have - so the one person who needs the privacy check could not see it.
	 *
	 * On demand, because it counts the source independently and walks sampled
	 * objects one by one.
	 */
	?>
	<div class="bni-card" id="bni-verify-card" hidden>
		<div class="bni-card__head">
			<h2 class="bni-card__title"><?php esc_html_e( 'Check the migration', 'buddynext-importer' ); ?></h2>
			<button type="button" class="button" id="bni-verify-run">
				<?php esc_html_e( 'Run checks', 'buddynext-importer' ); ?>
			</button>
		</div>

		<p class="bni-muted" id="bni-verify-intro">
			<?php esc_html_e( 'Counts your source community again, independently, and walks a random sample of migrated spaces and posts end to end - checking that each one landed in the right place and is visible to the right people. Worth running before you delete anything.', 'buddynext-importer' ); ?>
		</p>

		<div id="bni-verify-out" hidden></div>
	</div>
</div>
