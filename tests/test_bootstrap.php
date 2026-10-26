<?php
/**
 * PHPUnit bootstrap for behavioral scaffold contracts.
 *
 * @package PNS_Site_Functionality
 */

declare( strict_types = 1 );

require_once dirname( __DIR__ ) . '/vendor/autoload.php';

// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals, Squiz.Commenting, Generic.Commenting.DocComment, Generic.Files.OneObjectStructurePerFile -- Minimal Core doubles are required because WP_Mock does not ship WordPress objects.
if ( ! class_exists( 'WP_User' ) ) {
	class WP_User {
		/** @var string[] */
		public array $roles = array();
	}
}

if ( ! class_exists( 'WP_Post' ) ) {
	class WP_Post {
		public int $ID;
		public string $post_type;
		public string $post_status;
		public int $post_parent;
		public int $post_author;

		/** @param array<string, int|string> $post */
		public function __construct( array $post ) {
			$this->ID          = (int) $post['ID'];
			$this->post_type   = (string) $post['post_type'];
			$this->post_status = (string) $post['post_status'];
			$this->post_parent = (int) $post['post_parent'];
			$this->post_author = (int) $post['post_author'];
		}
	}
}

if ( ! class_exists( 'WP_Role' ) ) {
	class WP_Role {
		/** @var array<string, bool> */
		public array $capabilities = array();
		public function remove_cap( string $capability ): void {}
		public function add_cap( string $capability, bool $grant = true ): void {}
	}
}

if ( ! class_exists( 'WP_Error' ) ) {
	class WP_Error {
		/** @param array<string, int> $data */
		public function __construct( public string $code = '', public string $message = '', public array $data = array() ) {}
	}
}

if ( ! class_exists( 'WP_REST_Server' ) ) {
	class WP_REST_Server {}
}

if ( ! class_exists( 'WP_REST_Request' ) ) {
	class WP_REST_Request {
		/** @param array<string, mixed> $params */
		public function __construct( private string $route, private string $method, private array $params = array() ) {}
		public function get_route(): string {
			return $this->route; }
		public function get_method(): string {
			return $this->method; }
		public function has_param( string $key ): bool {
			return array_key_exists( $key, $this->params ); }
		public function get_param( string $key ): mixed {
			return $this->params[ $key ] ?? null; }
	}
}

// phpcs:enable
WP_Mock::bootstrap();
