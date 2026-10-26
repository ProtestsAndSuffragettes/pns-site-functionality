<?php
/**
 * Plugin Name: P&S Site Functionality
 * Description: Independent site editorial functionality for Protests & Suffragettes.
 * Plugin URI: https://github.com/ProtestsAndSuffragettes/pns-site-functionality
 * Author: Protests and Suffragettes
 * License: MIT
 * Update URI: https://github.com/ProtestsAndSuffragettes/pns-site-functionality
 * x-release-please-start-version
 * Version: 0.1.0
 * x-release-please-end
 * Requires at least: 7.0
 * Requires PHP: 8.4
 * Text Domain: pns-site-functionality
 *
 * @package PNS_Site_Functionality
 */

declare( strict_types = 1 );

if ( ! defined( 'ABSPATH' ) ) {
	return;
}

if ( ! file_exists( __DIR__ . '/vendor/autoload.php' ) ) {
	add_action(
		'admin_notices',
		static function (): void {
			echo '<div class="notice notice-error"><p>P&amp;S Site Functionality requires its Composer dependencies.</p></div>';
		}
	);
	return;
}

require_once __DIR__ . '/vendor/autoload.php';

register_activation_hook( __FILE__, array( \PNS\SiteFunctionality\Bootstrap::class, 'activate' ) );
\PNS\SiteFunctionality\Bootstrap::register();
