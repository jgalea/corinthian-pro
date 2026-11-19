<?php
// Seeds a post column set with four filterable columns and a few prioritised posts. Safe to run again. Prints JSON.
$priorities = array( 'low', 'medium', 'high', 'urgent' );
for ( $i = 1; $i <= 12; $i++ ) {
	$title = sprintf( 'Chips task %02d', $i );
	$found = get_posts( array( 'title' => $title, 'post_type' => 'post', 'post_status' => 'any', 'fields' => 'ids', 'posts_per_page' => 1 ) );
	$id    = $found ? (int) $found[0] : (int) wp_insert_post( array( 'post_title' => $title, 'post_status' => 'publish', 'post_author' => 1 ) );
	update_post_meta( $id, 'priority', $priorities[ $i % 4 ] );
}
$set = null;
foreach ( \Corinthian\Plugin::instance()->sets->for_screen( 'post:post' ) as $candidate ) {
	if ( 'Chips fixture' === $candidate->title ) {
		$set = $candidate;
	}
}
if ( ! $set ) {
	$set = \Corinthian\Plugin::instance()->sets->save(
		array(
			'screen'  => 'post:post',
			'title'   => 'Chips fixture',
			'columns' => array(
				array( 'id' => 'title', 'type' => 'native', 'label' => '', 'settings' => array( 'key' => 'title' ) ),
				array( 'id' => 'author', 'type' => 'post.author', 'label' => 'Author' ),
				array( 'id' => 'tags', 'type' => 'post.taxonomy', 'label' => 'Tags', 'settings' => array( 'taxonomy' => 'post_tag' ) ),
				array( 'id' => 'priority', 'type' => 'post.meta', 'label' => 'Priority', 'settings' => array( 'meta_key' => 'priority', 'display' => 'text' ) ),
			),
		)
	);
}
$key      = \Corinthian\Sets\SetRepository::active_meta_key( 'post:post' );
$previous = (int) get_user_meta( 1, $key, true );
update_user_meta( 1, $key, $set->id );
echo wp_json_encode( array( 'set' => $set->id, 'previous' => $previous ) );
