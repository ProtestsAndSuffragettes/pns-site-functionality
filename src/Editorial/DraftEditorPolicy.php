<?php
/**
 * Draft-only editorial access policy.
 *
 * @package PNS_Site_Functionality
 */

declare( strict_types=1 );

namespace PNS\SiteFunctionality\Editorial;

use WP_Error;
use WP_Post;
use WP_REST_Request;
use WP_REST_Server;
use WP_Role;
use WP_User;

/** Provides the P&S draft-editor role and its server-side restrictions. */
final class DraftEditorPolicy {
	private const ROLE   = 'pns_draft_editor';
	private const MARKER = 'pns_edit_drafts';

	/** Creates or repairs the persistent role without writing an unchanged role. */
	public static function register_role(): void {
		$required = array(
			'read'       => true,
			self::MARKER => true,
		);
		$role     = get_role( self::ROLE );

		if ( ! $role instanceof WP_Role ) {
			add_role( self::ROLE, __( 'Draft Editor', 'pns-site-functionality' ), $required );
			return;
		}

		foreach ( array_keys( $role->capabilities ) as $capability ) {
			if ( ! isset( $required[ $capability ] ) ) {
				$role->remove_cap( $capability );
			}
		}
		foreach ( array_keys( $required ) as $capability ) {
			if ( empty( $role->capabilities[ $capability ] ) ) {
				$role->add_cap( $capability, true );
			}
		}
	}

	/**
	 * Restricts object-level capabilities for a draft-editor, including mixed-role users.
	 *
	 * @param string[] $caps Required primitive capabilities.
	 * @param string   $cap Requested capability.
	 * @param int      $user_id User being checked.
	 * @param mixed[]  $args Capability context.
	 * @return string[] Required primitive capabilities.
	 */
	public static function map_meta_cap( array $caps, string $cap, int $user_id, array $args ): array {
		if ( ! self::is_restricted_user( $user_id ) ) {
			return $caps;
		}

		if ( in_array( $cap, array( 'delete_post', 'publish_post' ), true ) ) {
			return array( 'do_not_allow' );
		}
		if ( ! in_array( $cap, array( 'edit_post', 'read_post' ), true ) ) {
			return $caps;
		}
		if ( empty( $args[0] ) || ! self::is_editable_draft( (int) $args[0] ) ) {
			return array( 'do_not_allow' );
		}

		return array( self::MARKER );
	}

	/**
	 * Supplies only the list/create primitives needed during this capability check.
	 *
	 * @param array<string, bool> $allcaps User's current capabilities.
	 * @param string[]            $caps Required primitive capabilities.
	 * @param mixed[]             $args Capability context.
	 * @param WP_User             $user User being checked.
	 * @return array<string, bool> Filtered capabilities.
	 */
	public static function grant_request_capabilities( array $allcaps, array $caps, array $args, WP_User $user ): array {
		if ( ! self::has_draft_editor_role( $user ) ) {
			return $allcaps;
		}

		$denied = array(
			'edit_posts',
			'edit_others_posts',
			'publish_posts',
			'edit_published_posts',
			'edit_private_posts',
			'delete_posts',
			'delete_others_posts',
			'delete_published_posts',
			'delete_private_posts',
			'read_private_posts',
			'upload_files',
			'edit_pages',
			'publish_pages',
			'delete_pages',
			'manage_categories',
		);
		foreach ( $denied as $capability ) {
			$allcaps[ $capability ] = false;
		}

		$requested = $args[0] ?? '';
		if ( 'edit_posts' === $requested ) {
			$allcaps['edit_posts'] = true;
		}
		if ( 'edit_others_posts' === $requested ) {
			$allcaps['edit_others_posts'] = true;
		}

		return $allcaps;
	}

	/**
	 * Returns a REST error before a restricted principal can write an invalid post.
	 *
	 * @param mixed           $result Existing REST result.
	 * @param WP_REST_Server  $server REST server.
	 * @param WP_REST_Request $request Request being dispatched.
	 * @return mixed Existing result or an authorization error.
	 */
	public static function guard_rest_write( mixed $result, WP_REST_Server $server, WP_REST_Request $request ): mixed {
		unset( $server );
		if ( null !== $result || ! self::is_restricted_user( get_current_user_id() ) ) {
			return $result;
		}

		$route = $request->get_route();
		if ( ! in_array( $request->get_method(), array( 'POST', 'PUT', 'PATCH', 'DELETE' ), true ) ) {
			return $result;
		}
		if ( 1 !== preg_match( '#^/wp/v2/posts(?:/(\\d+))?(?:/autosaves)?$#', $route, $matches ) ) {
			return $result;
		}
		if ( 'DELETE' === $request->get_method() ) {
			return self::forbidden();
		}
		if ( ! empty( $matches[1] ) && ! self::is_editable_draft( (int) $matches[1] ) ) {
			return self::forbidden();
		}
		if ( $request->has_param( 'status' ) && ! self::is_draft_status( (string) $request->get_param( 'status' ) ) ) {
			return self::forbidden();
		}
		if ( $request->has_param( 'author' ) && ! self::is_permitted_rest_author( $request, $matches[1] ?? '' ) ) {
			return self::forbidden();
		}
		if ( $request->has_param( 'sticky' ) && (bool) $request->get_param( 'sticky' ) ) {
			return self::forbidden();
		}

		return $result;
	}

	/**
	 * Stops direct wp_insert_post() writes which bypass capability checks.
	 *
	 * @param bool                $maybe_empty Whether Core would reject empty content.
	 * @param array<string,mixed> $postarr Normalized post data.
	 * @return bool Whether Core must reject the write.
	 */
	public static function reject_disallowed_insert( bool $maybe_empty, array $postarr ): bool {
		if ( $maybe_empty || ! self::is_restricted_user( get_current_user_id() ) ) {
			return $maybe_empty;
		}
		if ( self::is_permitted_post_write( $postarr ) ) {
			return false;
		}

		return true;
	}

	/**
	 * Determines whether an insert payload stays inside the allowed draft boundary.
	 *
	 * @param array<string,mixed> $postarr Normalized post data.
	 */
	private static function is_permitted_post_write( array $postarr ): bool {
		$type = (string) ( $postarr['post_type'] ?? 'post' );
		if ( 'revision' === $type ) {
			if ( 'inherit' !== (string) ( $postarr['post_status'] ?? '' ) || empty( $postarr['post_parent'] ) || ! self::is_editable_draft( (int) $postarr['post_parent'] ) ) {
				return false;
			}
			if ( empty( $postarr['ID'] ) ) {
				return true;
			}
			$existing = get_post( (int) $postarr['ID'] );
			return $existing instanceof WP_Post
				&& 'revision' === $existing->post_type
				&& (int) $existing->post_parent === (int) $postarr['post_parent'];
		}
		if ( 'post' !== $type || ! self::is_draft_status( (string) ( $postarr['post_status'] ?? 'draft' ) ) ) {
			return false;
		}

		if ( empty( $postarr['ID'] ) ) {
			$author = (int) ( $postarr['post_author'] ?? 0 );
			return 0 === $author || get_current_user_id() === $author;
		}
		$post = get_post( (int) $postarr['ID'] );
		return $post instanceof WP_Post
			&& self::is_editable_draft( $post->ID )
			&& (int) ( $postarr['post_author'] ?? $post->post_author ) === (int) $post->post_author;
	}

	/**
	 * Checks an ordinary draft or an autosave/revision whose parent remains an ordinary draft.
	 *
	 * @param int $post_id Post or revision ID.
	 */
	private static function is_editable_draft( int $post_id ): bool {
		$post = get_post( $post_id );
		if ( ! $post instanceof WP_Post ) {
			return false;
		}
		if ( 'revision' === $post->post_type ) {
			$post = get_post( $post->post_parent );
		}

		return $post instanceof WP_Post && 'post' === $post->post_type && self::is_draft_status( $post->post_status );
	}

	/**
	 * Tests the only statuses that the role may create or edit.
	 *
	 * @param string $status Post status.
	 */
	private static function is_draft_status( string $status ): bool {
		return in_array( $status, array( 'draft', 'auto-draft' ), true );
	}

	/**
	 * Tests whether a user is assigned the constrained role.
	 *
	 * @param int $user_id User ID.
	 */
	private static function is_restricted_user( int $user_id ): bool {
		$user = get_userdata( $user_id );
		return $user instanceof WP_User && self::has_draft_editor_role( $user );
	}

	/**
	 * Tests role membership rather than a potentially copied marker capability.
	 *
	 * @param WP_User $user User being checked.
	 */
	private static function has_draft_editor_role( WP_User $user ): bool {
		return in_array( self::ROLE, $user->roles, true );
	}

	/**
	 * Checks a REST author field without allowing reassignment.
	 *
	 * @param WP_REST_Request $request REST request.
	 * @param string          $post_id Target post ID, if any.
	 */
	private static function is_permitted_rest_author( WP_REST_Request $request, string $post_id ): bool {
		$author = (int) $request->get_param( 'author' );
		if ( '' === $post_id ) {
			return get_current_user_id() === $author;
		}
		$post = get_post( (int) $post_id );
		return $post instanceof WP_Post && (int) $post->post_author === $author;
	}

	/** Builds a consistent REST authorization response. */
	private static function forbidden(): WP_Error {
		return new WP_Error( 'pns_draft_editor_forbidden', __( 'Draft Editors may only create and edit ordinary drafts.', 'pns-site-functionality' ), array( 'status' => 403 ) );
	}
}
