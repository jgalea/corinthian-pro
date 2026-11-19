<?php
// Seeds posts, pages, an Event post type, comments and a filterable column set on each screen the
// polish-states e2e checks. Safe to run again. Prints JSON with the set ids.
$mu = WP_CONTENT_DIR . '/mu-plugins';
wp_mkdir_p( $mu );
file_put_contents( $mu . '/states-event-cpt.php', "<?php\nadd_action( 'init', function () { register_post_type( 'event', array( 'label' => 'Events', 'public' => true, 'show_ui' => true, 'supports' => array( 'title', 'author' ), 'taxonomies' => array( 'category' ) ) ); } );\nadd_action( 'init', function () { register_post_type( 'note', array( 'label' => 'Notes', 'public' => true, 'show_ui' => true, 'taxonomies' => array( 'category' ) ) ); } );\n" );
register_post_type( 'note', array( 'label' => 'Notes', 'public' => true, 'show_ui' => true, 'taxonomies' => array( 'category' ) ) );
register_post_type( 'event', array( 'label' => 'Events', 'public' => true, 'show_ui' => true, 'supports' => array( 'title', 'author' ), 'taxonomies' => array( 'category' ) ) );

$ensure = static function ( string $type, string $title, string $date ): int {
	$found = get_posts( array( 'title' => $title, 'post_type' => $type, 'post_status' => 'any', 'fields' => 'ids', 'posts_per_page' => 1 ) );
	return $found ? (int) $found[0] : (int) wp_insert_post( array( 'post_title' => $title, 'post_type' => $type, 'post_status' => 'publish', 'post_author' => 1, 'post_date' => $date ) );
};
$cat = term_exists( 'States fixture', 'category' );
$cat = (int) ( $cat ? $cat['term_id'] : wp_insert_term( 'States fixture', 'category' )['term_id'] );
for ( $i = 1; $i <= 24; $i++ ) {
	$id = $ensure( 'post', sprintf( 'States post %02d', $i ), sprintf( '2026-0%d-1%d 10:00:00', 1 + $i % 9, $i % 9 ) );
	wp_set_post_terms( $id, array( $cat ), 'category' );
}
for ( $i = 1; $i <= 4; $i++ ) {
	$ensure( 'page', "States page $i", '2026-02-01 10:00:00' );
	$ensure( 'event', "States event $i", '2026-03-01 10:00:00' );
	$ensure( 'note', "States note $i", '2026-04-01 10:00:00' );
}
$first = $ensure( 'post', 'States post 01', '2026-02-11 10:00:00' );
if ( ! get_comments( array( 'post_id' => $first, 'count' => true ) ) ) {
	wp_insert_comment( array( 'comment_post_ID' => $first, 'comment_author' => 'Reader One', 'comment_author_email' => 'one@example.com', 'comment_content' => 'First comment', 'comment_approved' => 1 ) );
	wp_insert_comment( array( 'comment_post_ID' => $first, 'comment_author' => 'Reader Two', 'comment_author_email' => 'two@example.com', 'comment_content' => 'Second comment', 'comment_approved' => 1 ) );
}

$plugin = \Corinthian\Plugin::instance();
$sets   = array();
$make   = static function ( string $screen, array $columns ) use ( $plugin, &$sets ): void {
	$have = null;
	foreach ( $plugin->sets->for_screen( $screen ) as $candidate ) {
		if ( 'States fixture' === $candidate->title ) {
			$have = $candidate;
		}
	}
	$set = $have ?: $plugin->sets->save( array( 'screen' => $screen, 'title' => 'States fixture', 'columns' => $columns ) );
	update_user_meta( 1, \Corinthian\Sets\SetRepository::active_meta_key( $screen ), $set->id );
	$sets[ $screen ] = $set->id;
};
$post_columns = array(
	array( 'id' => 'title', 'type' => 'native', 'label' => '', 'settings' => array( 'key' => 'title' ) ),
	array( 'id' => 'author', 'type' => 'post.author', 'label' => 'Author' ),
	array( 'id' => 'slug', 'type' => 'post.slug', 'label' => 'Slug' ),
	array( 'id' => 'status', 'type' => 'post.status', 'label' => 'Status' ),
);
$make( 'post:post', $post_columns );
$make( 'post:page', $post_columns );
$make( 'post:event', $post_columns );
$make( 'users', array(
	array( 'id' => 'username', 'type' => 'native', 'label' => '', 'settings' => array( 'key' => 'username' ) ),
	array( 'id' => 'roles', 'type' => 'user.roles', 'label' => 'Roles' ),
	array( 'id' => 'registered', 'type' => 'user.registered', 'label' => 'Registered' ),
) );
$make( 'comments', array(
	array( 'id' => 'author', 'type' => 'native', 'label' => '', 'settings' => array( 'key' => 'author' ) ),
	array( 'id' => 'cauthor', 'type' => 'comment.author', 'label' => 'Commenter' ),
	array( 'id' => 'cdate', 'type' => 'comment.date', 'label' => 'Date' ),
) );
echo wp_json_encode( $sets );
