<?php
/**
 * Plugin bootstrap.
 *
 * @package PNS_Site_Functionality
 */

declare( strict_types=1 );

namespace PNS\SiteFunctionality;

use PNS\SiteFunctionality\Editorial\DraftEditorPolicy;

/**
 * Registers site-functionality modules.
 */
final class Bootstrap {
	/**
	 * Registers the editorial policy at WordPress' normal integration points.
	 */
	public static function register(): void {
		add_action( 'init', array( DraftEditorPolicy::class, 'register_role' ) );
		add_filter( 'map_meta_cap', array( DraftEditorPolicy::class, 'map_meta_cap' ), 20, 4 );
		add_filter( 'user_has_cap', array( DraftEditorPolicy::class, 'grant_request_capabilities' ), 20, 4 );
		add_filter( 'rest_pre_dispatch', array( DraftEditorPolicy::class, 'guard_rest_write' ), 20, 3 );
		add_filter( 'wp_insert_post_empty_content', array( DraftEditorPolicy::class, 'reject_disallowed_insert' ), 20, 2 );
	}

	/** Ensures that activation creates the assignable, fail-closed role. */
	public static function activate(): void {
		DraftEditorPolicy::register_role();
	}
}
