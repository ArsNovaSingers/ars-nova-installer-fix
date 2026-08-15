<?php
/**
 * Plugin Name: Ars Nova Installer Fix
 * Plugin URI:  https://arsnovasingers.org
 * Description: Renames a plugin's extracted folder during install to match the plugin's own main file. Makes GitHub source zips (which always extract to repo-branch/) install and UPDATE correctly instead of silently creating a duplicate plugin.
 * Version:     1.1.0
 * Author:      Ars Nova Singers
 * License:     GPL-2.0-or-later
 * Requires at least: 5.8
 * Requires PHP: 7.4
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'ANS_IFIX_VERSION', '1.1.0' );

/**
 * Why this exists.
 *
 * GitHub's source archives ALWAYS extract to <repo>-<ref>: ars-nova-core-main/,
 * ars-nova-core-e11b366/, and so on. WordPress names the installed plugin
 * folder after whatever directory it finds inside the zip. So installing
 * ars-nova-core from a plain GitHub URL produced a SECOND plugin at
 * ars-nova-core-main/ and left the real, active ars-nova-core/ untouched.
 * overwrite_package never matched, because as far as WordPress was concerned
 * that was a different plugin entirely.
 *
 * Observed on staging 2026-08-13: HTTP 500 from the installer, two copies of
 * Ars Nova Core installed, the 1.5.0 one still active and the new 1.8.6 one
 * inactive under the wrong folder name.
 *
 * WordPress convention is that a plugin's folder matches its main file
 * (ars-nova-core/ars-nova-core.php), which makes the main file an unambiguous
 * source of truth for what the folder should be called.
 *
 * The rule, deliberately conservative:
 *   if the extracted directory contains EXACTLY ONE top-level .php file
 *   carrying a `Plugin Name:` header, AND the extracted folder is that name
 *   plus a "-suffix" (i.e. it really does look like a GitHub archive), rename
 *   the directory to match that file.
 *
 * Zero candidates, or more than one, means we do not guess - the source is
 * handed back untouched and WordPress behaves exactly as it always has. The
 * same applies if the name already matches, or if the move fails.
 *
 * ---------------------------------------------------------------------------
 * 1.1.0 - the folder-match test, and why it had to be added
 *
 * 1.0.0 renamed on the main file alone, on the premise that a plugin's folder
 * always matches its main file. That premise is a convention, not a rule, and
 * Tickera breaks it deliberately: EVERY Tickera add-on ships as index.php.
 *
 * Observed on staging 2026-08-15 installing Tickera CSV Export: csv-export/
 * was renamed to index/, and the install then failed at activation. The real
 * danger was the next install, not that one. Live already runs three Tickera
 * add-ons (checkinera-premium, bulk-discount-codes, check-in-notifications),
 * all with index.php as their main file - so a second vendor install would
 * have resolved to the same index/ target and, because this filter DELETES a
 * stale target before moving, silently destroyed the first.
 *
 * Two guards now:
 *   1. A generically-named main file (index.php, main.php, plugin.php...)
 *      tells us nothing about the folder name, so never rename on one.
 *   2. Only rename when the extracted folder is the desired name plus a
 *      "-suffix" - exactly GitHub's <repo>-<ref> shape. A vendor who names
 *      their folder differently from their main file has made a choice; that
 *      is not a defect for us to correct.
 *
 * This runs for plugin installs and updates from ANY source, including manual
 * wp-admin zip uploads. That is intentional: a hand-uploaded GitHub zip hits
 * precisely the same trap.
 */
function ans_ifix_rename_source_to_main_file( $source, $remote_source = '', $upgrader = null, $hook_extra = array() ) {
	global $wp_filesystem;

	if ( is_wp_error( $source ) || empty( $wp_filesystem ) ) {
		return $source;
	}

	// Plugins only. Themes and core have their own naming rules.
	if ( ! ( $upgrader instanceof Plugin_Upgrader ) ) {
		return $source;
	}

	$source  = trailingslashit( $source );
	$current = basename( untrailingslashit( $source ) );

	$list = $wp_filesystem->dirlist( $source );
	if ( ! is_array( $list ) ) {
		return $source;
	}

	if ( ! function_exists( 'get_plugin_data' ) ) {
		require_once ABSPATH . 'wp-admin/includes/plugin.php';
	}

	$candidates = array();
	foreach ( $list as $name => $meta ) {
		if ( isset( $meta['type'] ) && 'f' !== $meta['type'] ) {
			continue;
		}
		if ( '.php' !== strtolower( substr( $name, -4 ) ) ) {
			continue;
		}
		$data = get_plugin_data( $source . $name, false, false );
		if ( ! empty( $data['Name'] ) ) {
			$candidates[] = $name;
		}
	}

	// Ambiguous or none -> do not guess.
	if ( 1 !== count( $candidates ) ) {
		return $source;
	}

	$desired = basename( $candidates[0], '.php' );
	if ( '' === $desired || $desired === $current ) {
		return $source;
	}

	// Guard 1: a generic main-file name carries no information about the folder.
	// Tickera ships every add-on as index.php; renaming on that would funnel
	// csv-export/, checkinera/ and the rest into one index/ folder.
	$generic = array( 'index', 'main', 'plugin', 'init', 'class', 'loader', 'bootstrap', 'functions' );
	if ( in_array( strtolower( $desired ), $generic, true ) ) {
		return $source;
	}

	// Guard 2: only correct the shape this plugin exists for. GitHub archives
	// extract to <repo>-<ref>, so the folder is the desired name plus a
	// "-suffix". Anything else is a vendor naming their folder their own way,
	// which is not ours to override.
	$suffix = substr( $current, strlen( $desired ) );
	if ( 0 !== strpos( $current, $desired ) || '' === $suffix || '-' !== $suffix[0] ) {
		return $source;
	}

	$parent = trailingslashit( dirname( untrailingslashit( $source ) ) );
	$target = $parent . $desired;

	// Clear a stale target left by an earlier failed run, inside the temp
	// working directory only - never the live plugins directory.
	if ( $wp_filesystem->exists( $target ) ) {
		$wp_filesystem->delete( $target, true );
	}

	if ( ! $wp_filesystem->move( untrailingslashit( $source ), $target ) ) {
		return $source; // Move failed - carry on unchanged rather than break the install.
	}

	return trailingslashit( $target );
}
add_filter( 'upgrader_source_selection', 'ans_ifix_rename_source_to_main_file', 10, 4 );
