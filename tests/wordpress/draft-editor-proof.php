<?php
/**
 * Disposable-installation proof for the draft-editor role.
 *
 * Run only with `wp eval-file` against the marked, disposable WordPress site.
 *
 * @package PNS_Site_Functionality
 */

declare( strict_types = 1 );

/**
 * Stop before any mutation when the disposable-installation custody checks fail.
 *
 * @param string $message Failure explanation.
 * @return never
 */
function pns_site_functionality_role_proof_abort( string $message ): never {
	fwrite( STDERR, "FAIL: {$message}\n" );
	exit( 1 );
}

/**
 * Record one proof assertion.
 *
 * @param bool   $condition Assertion result.
 * @param string $message   Assertion description.
 */
function pns_site_functionality_role_proof_assert( bool $condition, string $message ): void {
	static $passed = 0;

	if ( ! $condition ) {
		pns_site_functionality_role_proof_abort( $message );
	}

	++$passed;
	printf( "PASS %d: %s\n", absint( $passed ), esc_html( $message ) );
}

/**
 * Make a request through WordPress's actual REST server.
 *
 * @param string               $method Route HTTP method.
 * @param string               $route  REST route.
 * @param array<string, mixed> $body   Request body parameters.
 * @return WP_REST_Response
 */
function pns_site_functionality_role_proof_rest( string $method, string $route, array $body = array() ): WP_REST_Response {
	$request = new WP_REST_Request( $method, $route );
	foreach ( $body as $key => $value ) {
		$request[ $key ] = $value;
	}

	return rest_do_request( $request );
}

/**
 * Assert that a failed mutation did not change the stored post fields.
 *
 * @param int                  $post_id Post to inspect.
 * @param array<string, mixed> $before  Fields captured before the attempted mutation.
 * @param string               $label   Assertion label.
 */
function pns_site_functionality_role_proof_assert_unchanged( int $post_id, array $before, string $label ): void {
	$after = get_post( $post_id, ARRAY_A );
	pns_site_functionality_role_proof_assert( is_array( $after ), "{$label}: post still exists" );
	if ( ! is_array( $after ) ) {
		pns_site_functionality_role_proof_abort( "{$label}: post disappeared" );
	}

	foreach ( $before as $field => $value ) {
		pns_site_functionality_role_proof_assert(
			array_key_exists( $field, $after ) && $after[ $field ] === $value,
			"{$label}: {$field} is unchanged"
		);
	}
}

/**
 * Return the small set of fields whose mutation would violate the policy.
 *
 * @param int $post_id Post to snapshot.
 * @return array<string, mixed>
 */
function pns_site_functionality_role_proof_snapshot( int $post_id ): array {
	$post = get_post( $post_id, ARRAY_A );
	if ( ! is_array( $post ) ) {
		pns_site_functionality_role_proof_abort( "Could not read fixture post {$post_id}" );
	}

	return array_intersect_key(
		$post,
		array_flip( array( 'post_author', 'post_content', 'post_status', 'post_title', 'post_type', 'post_date', 'post_date_gmt' ) )
	);
}

/**
 * Get or create a named user for this isolated proof installation.
 *
 * @param string $login User login.
 * @param string $role  WordPress role.
 * @return int
 */
function pns_site_functionality_role_proof_user( string $login, string $role ): int {
	$user_id = username_exists( $login );
	if ( is_int( $user_id ) ) {
		wp_update_user(
			array(
				'ID'   => $user_id,
				'role' => $role,
			)
		);
		return $user_id;
	}

	$created_user_id = wp_create_user( $login, wp_generate_password( 32, true, true ), "{$login}@example.test" );
	if ( ! is_int( $created_user_id ) ) {
		pns_site_functionality_role_proof_abort( "Could not create {$login}." );
	}

	wp_update_user(
		array(
			'ID'   => $created_user_id,
			'role' => $role,
		)
	);

	return $created_user_id;
}

/**
 * Insert a fixture and stop if WordPress did not return a post ID.
 *
 * @param array<string, mixed> $post Post fields.
 * @return int
 */
function pns_site_functionality_role_proof_insert_fixture( array $post ): int {
	$post_id = wp_insert_post( $post, true );
	if ( ! is_int( $post_id ) || 0 >= $post_id ) {
		pns_site_functionality_role_proof_abort( 'Could not create a proof fixture.' );
	}

	return $post_id;
}

/** Run the selected active or inactive integration-proof phase. */
function pns_site_functionality_role_proof_run(): void {
	$phase = getenv( 'PNS_ROLE_TEST_PHASE' );
	if ( 'active' !== $phase && 'inactive' !== $phase ) {
		pns_site_functionality_role_proof_abort( 'PNS_ROLE_TEST_PHASE must be active or inactive.' );
	}

	$site_url    = get_option( 'siteurl' );
	$marker_path = ABSPATH . '.pns-role-proof.json';
	$marker      = is_readable( $marker_path ) ? json_decode( (string) file_get_contents( $marker_path ), true ) : null;
	$db_name     = defined( 'DB_NAME' ) ? constant( 'DB_NAME' ) : null;

	if ( ABSPATH !== getenv( 'PNS_ROLE_TEST_ROOT' ) || getenv( 'PNS_ROLE_TEST_URL' ) !== $site_url ) {
		pns_site_functionality_role_proof_abort( 'Environment root or URL does not exactly match this WordPress installation.' );
	}

	if ( ! is_array( $marker )
	|| ! isset( $marker['root'], $marker['site_url'], $marker['db_name'] )
	|| ABSPATH !== $marker['root']
	|| $site_url !== $marker['site_url']
		|| ! is_string( $db_name )
		|| $db_name !== $marker['db_name']
		|| ( 'wordpress_test' !== $db_name && 1 !== preg_match( '/^pns_role_proof_/', $db_name ) )
	) {
		pns_site_functionality_role_proof_abort( 'Disposable marker is missing or does not match root, URL, and database custody.' );
	}

	if ( 'inactive' === $phase ) {
		$role = get_role( 'pns_draft_editor' );
		pns_site_functionality_role_proof_assert( $role instanceof WP_Role, 'The deactivated plugin leaves the editorial role intact.' );
		if ( ! $role instanceof WP_Role ) {
			pns_site_functionality_role_proof_abort( 'Editorial role is missing after deactivation.' );
		}
		pns_site_functionality_role_proof_assert( empty( $role->capabilities['edit_posts'] ), 'Persisted role grants do not include edit_posts.' );
		pns_site_functionality_role_proof_assert( empty( $role->capabilities['edit_others_posts'] ), 'Persisted role grants do not include edit_others_posts.' );

		$editor_id = username_exists( 'pns_role_proof_editor' );
		pns_site_functionality_role_proof_assert( is_int( $editor_id ), 'The active-phase editor fixture remains after deactivation.' );
		if ( ! is_int( $editor_id ) ) {
			pns_site_functionality_role_proof_abort( 'Editorial fixture user is missing after deactivation.' );
		}
		wp_set_current_user( $editor_id );
		pns_site_functionality_role_proof_assert( ! current_user_can( 'edit_posts' ), 'Inactive plugin denies edit_posts.' );
		pns_site_functionality_role_proof_assert( ! current_user_can( 'edit_post', 1 ), 'Inactive plugin denies edit_post.' );

		printf( "SUCCESS: inactive proof completed.\n" );
		return;
	}

	$role = get_role( 'pns_draft_editor' );
	pns_site_functionality_role_proof_assert( $role instanceof WP_Role, 'Plugin activation registered pns_draft_editor.' );
	if ( ! $role instanceof WP_Role ) {
		pns_site_functionality_role_proof_abort( 'Plugin activation did not create the editorial role.' );
	}
	pns_site_functionality_role_proof_assert( ! empty( $role->capabilities['read'] ), 'Role can read the administration surface.' );
	pns_site_functionality_role_proof_assert( ! empty( $role->capabilities['pns_edit_drafts'] ), 'Role carries only the custom editorial grant.' );
	pns_site_functionality_role_proof_assert( empty( $role->capabilities['edit_posts'] ), 'Role has no broad edit_posts grant.' );
	pns_site_functionality_role_proof_assert( empty( $role->capabilities['edit_others_posts'] ), 'Role has no broad edit_others_posts grant.' );

	$admin_id        = pns_site_functionality_role_proof_user( 'pns_role_proof_admin', 'administrator' );
	$editor_id       = pns_site_functionality_role_proof_user( 'pns_role_proof_editor', 'pns_draft_editor' );
	$author_id       = pns_site_functionality_role_proof_user( 'pns_role_proof_author', 'author' );
	$other_author_id = pns_site_functionality_role_proof_user( 'pns_role_proof_other_author', 'author' );

	register_post_type(
		'pns_role_proof_cpt',
		array(
			'capability_type' => 'post',
			'map_meta_cap'    => true,
			'public'          => false,
			'show_ui'         => false,
		)
	);

	$fixtures = array(
		'own_draft'   => pns_site_functionality_role_proof_insert_fixture(
			array(
				'post_author'  => $editor_id,
				'post_content' => 'Own draft',
				'post_status'  => 'draft',
				'post_title'   => 'Own draft',
				'post_type'    => 'post',
			)
		),
		'other_draft' => pns_site_functionality_role_proof_insert_fixture(
			array(
				'post_author'  => $author_id,
				'post_content' => 'Other draft',
				'post_status'  => 'draft',
				'post_title'   => 'Other draft',
				'post_type'    => 'post',
			)
		),
		'published'   => pns_site_functionality_role_proof_insert_fixture(
			array(
				'post_author'  => $author_id,
				'post_content' => 'Published',
				'post_status'  => 'publish',
				'post_title'   => 'Published',
				'post_type'    => 'post',
			)
		),
		'future'      => pns_site_functionality_role_proof_insert_fixture(
			array(
				'post_author'  => $author_id,
				'post_content' => 'Future',
				'post_date'    => gmdate( 'Y-m-d H:i:s', time() + DAY_IN_SECONDS ),
				'post_status'  => 'future',
				'post_title'   => 'Future',
				'post_type'    => 'post',
			)
		),
		'pending'     => pns_site_functionality_role_proof_insert_fixture(
			array(
				'post_author'  => $author_id,
				'post_content' => 'Pending',
				'post_status'  => 'pending',
				'post_title'   => 'Pending',
				'post_type'    => 'post',
			)
		),
		'private'     => pns_site_functionality_role_proof_insert_fixture(
			array(
				'post_author'  => $author_id,
				'post_content' => 'Private',
				'post_status'  => 'private',
				'post_title'   => 'Private',
				'post_type'    => 'post',
			)
		),
		'trash'       => pns_site_functionality_role_proof_insert_fixture(
			array(
				'post_author'  => $author_id,
				'post_content' => 'Trash',
				'post_status'  => 'trash',
				'post_title'   => 'Trash',
				'post_type'    => 'post',
			)
		),
		'page'        => pns_site_functionality_role_proof_insert_fixture(
			array(
				'post_author'  => $author_id,
				'post_content' => 'Page',
				'post_status'  => 'draft',
				'post_title'   => 'Page',
				'post_type'    => 'page',
			)
		),
		'cpt'         => pns_site_functionality_role_proof_insert_fixture(
			array(
				'post_author'  => $author_id,
				'post_content' => 'CPT',
				'post_status'  => 'draft',
				'post_title'   => 'CPT',
				'post_type'    => 'pns_role_proof_cpt',
			)
		),
	);

	foreach ( $fixtures as $name => $post_id ) {
		pns_site_functionality_role_proof_assert( $post_id > 0, "Created {$name} fixture." );
	}

	update_option(
		'pns_role_proof_fixture_ids',
		array(
			'admin_id'        => $admin_id,
			'author_id'       => $author_id,
			'editor_id'       => $editor_id,
			'other_author_id' => $other_author_id,
			'posts'           => $fixtures,
		),
		false
	);

	wp_set_current_user( $editor_id );
	pns_site_functionality_role_proof_assert( current_user_can( 'edit_post', $fixtures['own_draft'] ), 'Editor may edit their ordinary post draft.' );
	pns_site_functionality_role_proof_assert( current_user_can( 'edit_post', $fixtures['other_draft'] ), 'Editor may edit another author ordinary post draft.' );

	$response = pns_site_functionality_role_proof_rest( 'POST', "/wp/v2/posts/{$fixtures['own_draft']}", array( 'content' => 'Own draft via REST' ) );
	pns_site_functionality_role_proof_assert( $response->get_status() < 300, 'REST updates an own draft.' );

	$direct_result = wp_update_post(
		array(
			'ID'           => $fixtures['other_draft'],
			'post_content' => 'Other draft via native API',
		),
		true
	);
	pns_site_functionality_role_proof_assert( is_int( $direct_result ) && $direct_result === $fixtures['other_draft'], 'Native API updates another author draft.' );
	$other_response = pns_site_functionality_role_proof_rest( 'POST', "/wp/v2/posts/{$fixtures['other_draft']}", array( 'content' => 'Other draft via REST' ) );
	pns_site_functionality_role_proof_assert( $other_response->get_status() < 300, 'REST updates another author draft.' );

	foreach ( array( 'published', 'future', 'pending', 'private', 'trash' ) as $status ) {
		$before = pns_site_functionality_role_proof_snapshot( $fixtures[ $status ] );
		pns_site_functionality_role_proof_assert( ! current_user_can( 'edit_post', $fixtures[ $status ] ), "Editor cannot edit {$status} posts." );
		$result = pns_site_functionality_role_proof_rest( 'POST', "/wp/v2/posts/{$fixtures[ $status ]}", array( 'title' => "Blocked {$status}" ) );
		pns_site_functionality_role_proof_assert( $result->get_status() === 403, "REST returns 403 for {$status} post edits." );
		pns_site_functionality_role_proof_assert_unchanged( $fixtures[ $status ], $before, "{$status} edit" );
	}

	pns_site_functionality_role_proof_assert( ! current_user_can( 'delete_post', $fixtures['own_draft'] ), 'Editor cannot delete drafts.' );
	pns_site_functionality_role_proof_assert( ! current_user_can( 'publish_posts' ), 'Editor cannot publish posts.' );
	$post_type = get_post_type_object( 'post' );
	pns_site_functionality_role_proof_assert( $post_type instanceof WP_Post_Type && current_user_can( $post_type->cap->create_posts ), 'Editor can create ordinary drafts.' );
	pns_site_functionality_role_proof_assert( ! current_user_can( 'edit_post', $fixtures['page'] ), 'Editor cannot reuse post permissions for pages.' );
	pns_site_functionality_role_proof_assert( ! current_user_can( 'edit_post', $fixtures['cpt'] ), 'Editor cannot reuse post permissions for custom post types.' );

	$blocked_transitions = array( 'pending', 'private', 'publish', 'future', 'trash' );
	foreach ( $blocked_transitions as $target_status ) {
		$before = pns_site_functionality_role_proof_snapshot( $fixtures['own_draft'] );
		$result = pns_site_functionality_role_proof_rest( 'POST', "/wp/v2/posts/{$fixtures['own_draft']}", array( 'status' => $target_status ) );
		pns_site_functionality_role_proof_assert( $result->get_status() === 403, "REST denies draft to {$target_status}." );
		pns_site_functionality_role_proof_assert_unchanged( $fixtures['own_draft'], $before, "draft to {$target_status}" );
	}

	$before = pns_site_functionality_role_proof_snapshot( $fixtures['own_draft'] );
	$result = pns_site_functionality_role_proof_rest( 'POST', "/wp/v2/posts/{$fixtures['own_draft']}", array( 'author' => $other_author_id ) );
	pns_site_functionality_role_proof_assert( $result->get_status() === 403, 'REST denies author changes.' );
	pns_site_functionality_role_proof_assert_unchanged( $fixtures['own_draft'], $before, 'author change' );

	foreach (
		array(
			'author'  => array( 'post_author' => $other_author_id ),
			'publish' => array( 'post_status' => 'publish' ),
			'retype'  => array( 'post_type' => 'page' ),
		) as $direct_label => $direct_change
	) {
		$before        = pns_site_functionality_role_proof_snapshot( $fixtures['own_draft'] );
		$native_denial = wp_update_post( array_merge( array( 'ID' => $fixtures['own_draft'] ), $direct_change ), true );
		pns_site_functionality_role_proof_assert( ! is_int( $native_denial ) || $native_denial !== $fixtures['own_draft'], "Native API denies {$direct_label} changes." );
		pns_site_functionality_role_proof_assert_unchanged( $fixtures['own_draft'], $before, "native {$direct_label} change" );
	}

	$created = pns_site_functionality_role_proof_rest(
		'POST',
		'/wp/v2/posts',
		array(
			'content' => 'Blocked create',
			'status'  => 'draft',
			'title'   => 'Blocked create',
		)
	);
	pns_site_functionality_role_proof_assert( $created->get_status() === 201, 'REST creates an ordinary draft.' );
	$created_post = get_post( (int) $created->get_data()['id'] );
	pns_site_functionality_role_proof_assert( $created_post instanceof WP_Post && (int) $created_post->post_author === $editor_id && 'draft' === $created_post->post_status, 'New REST draft belongs to the editor and remains a draft.' );
	$native_created = wp_insert_post(
		array(
			'post_content' => 'Native create',
			'post_status'  => 'draft',
			'post_title'   => 'Native create',
			'post_type'    => 'post',
		),
		true
	);
	pns_site_functionality_role_proof_assert( is_int( $native_created ) && $native_created > 0, 'Native API creates an ordinary draft.' );
	$native_created_post = is_int( $native_created ) ? get_post( $native_created ) : null;
	pns_site_functionality_role_proof_assert( $native_created_post instanceof WP_Post && (int) $native_created_post->post_author === $editor_id && 'draft' === $native_created_post->post_status, 'New native draft belongs to the editor and remains a draft.' );
	$page_create = pns_site_functionality_role_proof_rest(
		'POST',
		'/wp/v2/pages',
		array(
			'content' => 'Blocked page',
			'status'  => 'draft',
			'title'   => 'Blocked page',
		)
	);
	pns_site_functionality_role_proof_assert( $page_create->get_status() === 403, 'REST denies page creation.' );

	$revisions = wp_get_post_revisions( $fixtures['other_draft'] );
	$revision  = reset( $revisions );
	pns_site_functionality_role_proof_assert( $revision instanceof WP_Post && (int) $revision->post_parent === $fixtures['other_draft'], 'Native draft edit creates a revision attached to its parent.' );
	if ( $revision instanceof WP_Post ) {
		$revision_before = pns_site_functionality_role_proof_snapshot( $revision->ID );
		$revision_denial = wp_update_post(
			array(
				'ID'        => $revision->ID,
				'post_type' => 'post',
			),
			true
		);
		pns_site_functionality_role_proof_assert( ! is_int( $revision_denial ) || $revision_denial !== $revision->ID, 'Native API denies revision retyping.' );
		pns_site_functionality_role_proof_assert_unchanged( $revision->ID, $revision_before, 'native revision retype' );
	}

	$autosave = pns_site_functionality_role_proof_rest( 'POST', "/wp/v2/posts/{$fixtures['own_draft']}/autosaves", array( 'content' => 'Draft autosave' ) );
	pns_site_functionality_role_proof_assert( $autosave->get_status() < 300, 'REST creates an autosave for a draft parent.' );
	$other_autosave = pns_site_functionality_role_proof_rest( 'POST', "/wp/v2/posts/{$fixtures['other_draft']}/autosaves", array( 'content' => 'Other draft autosave' ) );
	pns_site_functionality_role_proof_assert( $other_autosave->get_status() < 300, 'REST creates an autosave for another author draft parent.' );
	$parent = get_post( $fixtures['own_draft'] );
	pns_site_functionality_role_proof_assert( $parent instanceof WP_Post && (int) $parent->post_author === $editor_id, 'Draft parent author remains unchanged after revision and autosave.' );

	wp_set_current_user( $admin_id );
	pns_site_functionality_role_proof_assert( current_user_can( 'edit_post', $fixtures['published'] ), 'Administrator editing remains unaffected.' );
	$admin_result = wp_update_post(
		array(
			'ID'         => $fixtures['published'],
			'post_title' => 'Published by administrator',
		),
		true
	);
	pns_site_functionality_role_proof_assert( is_int( $admin_result ) && $admin_result === $fixtures['published'], 'Administrator can still update published posts.' );

	printf( "SUCCESS: active proof completed.\n" );
}

pns_site_functionality_role_proof_run();
