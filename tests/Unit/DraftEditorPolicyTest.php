<?php
/**
 * Behavioral contracts for the draft-editor role.
 *
 * @package PNS_Site_Functionality
 */

declare( strict_types=1 );

namespace PNS\SiteFunctionality\Tests\Unit;

use PNS\SiteFunctionality\Editorial\DraftEditorPolicy;
use WP_Error;
use WP_Mock;
use WP_Post;
use WP_REST_Request;
use WP_REST_Server;
use WP_User;

/** Verifies the role is narrow across capability and write boundaries. */
final class DraftEditorPolicyTest extends WP_Mock\Tools\TestCase {
	/** Sets up WordPress function mocks. */
	public function setUp(): void {
		WP_Mock::setUp();
	}

	/** Tears down WordPress function mocks. */
	public function tearDown(): void {
		WP_Mock::tearDown();
	}

	/** Creates the assignable role with only its read and policy-marker capabilities. */
	public function test_register_role_creates_only_the_fail_closed_capabilities(): void {
		WP_Mock::userFunction( 'get_role' )->once()->with( 'pns_draft_editor' )->andReturn( null );
		WP_Mock::userFunction( '__' )->once()->with( 'Draft Editor', 'pns-site-functionality' )->andReturn( 'Draft Editor' );
		WP_Mock::userFunction( 'add_role' )->once()->with(
			'pns_draft_editor',
			'Draft Editor',
			array(
				'read'            => true,
				'pns_edit_drafts' => true,
			)
		);

		DraftEditorPolicy::register_role();
		$this->assertConditionsMet();
	}

	/** Allows edits to another author's ordinary draft through the marker only. */
	public function test_map_meta_cap_allows_another_users_draft(): void {
		WP_Mock::userFunction( 'get_userdata' )->once()->with( 2 )->andReturn( self::draft_editor() );
		WP_Mock::userFunction( 'get_post' )->once()->with( 17 )->andReturn( self::post( 17, 'post', 'draft', 9 ) );

		self::assertSame( array( 'pns_edit_drafts' ), DraftEditorPolicy::map_meta_cap( array( 'edit_others_posts' ), 'edit_post', 2, array( 17 ) ) );
	}

	/** Rejects a published target, deletion, and a page for the restricted role. */
	public function test_map_meta_cap_rejects_forbidden_targets(): void {
		WP_Mock::userFunction( 'get_userdata' )->times( 3 )->with( 2 )->andReturn( self::draft_editor() );
		WP_Mock::userFunction( 'get_post' )->times( 2 )->andReturnUsing(
			static fn ( int $id ): WP_Post => 18 === $id ? self::post( 18, 'post', 'publish', 9 ) : self::post( 19, 'page', 'draft', 9 )
		);

		self::assertSame( array( 'do_not_allow' ), DraftEditorPolicy::map_meta_cap( array(), 'edit_post', 2, array( 18 ) ) );
		self::assertSame( array( 'do_not_allow' ), DraftEditorPolicy::map_meta_cap( array(), 'delete_post', 2, array( 17 ) ) );
		self::assertSame( array( 'do_not_allow' ), DraftEditorPolicy::map_meta_cap( array(), 'edit_post', 2, array( 19 ) ) );
	}

	/** Limits temporary primitives and removes stronger caps inherited from another role. */
	public function test_request_capabilities_only_grant_draft_list_primitives(): void {
		$user = self::draft_editor();
		$caps = DraftEditorPolicy::grant_request_capabilities(
			array(
				'edit_others_posts' => true,
				'publish_posts'     => true,
				'upload_files'      => true,
			),
			array( 'edit_posts' ),
			array( 'edit_posts', 2 ),
			$user
		);

		self::assertTrue( $caps['edit_posts'] );
		self::assertFalse( $caps['publish_posts'] );
		self::assertFalse( $caps['edit_others_posts'] );
		self::assertFalse( $caps['upload_files'] );
		self::assertFalse( $caps['delete_posts'] );
	}

	/** Rejects forged status, author, and type writes, but retains a valid other-author draft update. */
	public function test_direct_write_guard_is_fail_closed_without_breaking_drafts(): void {
		WP_Mock::userFunction( 'get_current_user_id' )->times( 4 )->andReturn( 2 );
		WP_Mock::userFunction( 'get_userdata' )->times( 4 )->with( 2 )->andReturn( self::draft_editor() );
		WP_Mock::userFunction( 'get_post' )->times( 6 )->andReturnUsing(
			static fn ( int $id ): WP_Post => 18 === $id ? self::post( 18, 'post', 'publish', 9 ) : self::post( 17, 'post', 'draft', 9 )
		);

		self::assertTrue(
			DraftEditorPolicy::reject_disallowed_insert(
				false,
				array(
					'post_type'   => 'post',
					'post_status' => 'publish',
				)
			)
		);
		self::assertTrue(
			DraftEditorPolicy::reject_disallowed_insert(
				false,
				array(
					'ID'          => 17,
					'post_type'   => 'post',
					'post_status' => 'draft',
					'post_author' => 2,
				)
			)
		);
		self::assertFalse(
			DraftEditorPolicy::reject_disallowed_insert(
				false,
				array(
					'ID'          => 17,
					'post_type'   => 'post',
					'post_status' => 'draft',
					'post_author' => 9,
				)
			)
		);
		self::assertTrue(
			DraftEditorPolicy::reject_disallowed_insert(
				false,
				array(
					'ID'          => 18,
					'post_type'   => 'revision',
					'post_status' => 'inherit',
					'post_parent' => 17,
				)
			)
		);
	}

	/** Returns a REST 403 for a publish transition, before WordPress attempts the write. */
	public function test_rest_guard_rejects_publish_transition(): void {
		WP_Mock::userFunction( 'get_current_user_id' )->once()->andReturn( 2 );
		WP_Mock::userFunction( 'get_userdata' )->once()->with( 2 )->andReturn( self::draft_editor() );
		WP_Mock::userFunction( 'get_post' )->once()->with( 17 )->andReturn( self::post( 17, 'post', 'draft', 9 ) );

		$result = DraftEditorPolicy::guard_rest_write( null, new WP_REST_Server(), new WP_REST_Request( '/wp/v2/posts/17', 'POST', array( 'status' => 'publish' ) ) );
		self::assertInstanceOf( WP_Error::class, $result );
		self::assertSame( 'pns_draft_editor_forbidden', $result->code );
	}

	/** Builds a restricted user fixture. */
	private static function draft_editor(): WP_User {
		$user        = new WP_User();
		$user->roles = array( 'pns_draft_editor', 'editor' );
		return $user;
	}

	/**
	 * Builds a post fixture.
	 *
	 * @param int    $id Post ID.
	 * @param string $type Post type.
	 * @param string $status Post status.
	 * @param int    $author Post author.
	 * @param int    $parent Parent post ID.
	 */
	private static function post( int $id, string $type, string $status, int $author, int $parent = 0 ): WP_Post {
		return new WP_Post(
			array(
				'ID'          => $id,
				'post_type'   => $type,
				'post_status' => $status,
				'post_parent' => $parent,
				'post_author' => $author,
			)
		);
	}
}
