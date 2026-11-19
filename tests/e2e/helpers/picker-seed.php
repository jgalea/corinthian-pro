<?php
// Seeds a site past the old picker caps: 620 users, 1,050 tags, a Priority field with four
// values, and a column set on post:post that the admin sees first. Safe to run again. Prints JSON.
global $wpdb;

$first = array( 'Maria', 'Joseph', 'Anna', 'Paul', 'Claire', 'Mark', 'Rita', 'Karl', 'Nadia', 'Simon', 'Elena', 'Luke', 'Sara', 'Daniel', 'Ivy', 'Owen', 'Greta', 'Hugo', 'Lina', 'Theo' );
$last  = array( 'Borg', 'Camilleri', 'Debono', 'Ellul', 'Farrugia', 'Grech', 'Mifsud', 'Pace', 'Vella', 'Zammit', 'Galea', 'Abela', 'Azzopardi', 'Bonello', 'Cassar', 'Spiteri', 'Attard', 'Micallef', 'Sammut', 'Fenech', 'Scicluna', 'Tabone', 'Xuereb', 'Agius', 'Buttigieg', 'Caruana', 'Gauci', 'Muscat', 'Portelli', 'Zahra', 'Bugeja' );

$have = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->users} WHERE user_login LIKE 'picker-user-%'" );
for ( $i = $have + 1; $i <= 620; $i++ ) {
	$login = sprintf( 'picker-user-%04d', $i );
	// Direct inserts: hashing a password per user would take minutes. These accounts cannot log in.
	$wpdb->insert(
		$wpdb->users,
		array(
			'user_login'      => $login,
			'user_pass'       => '*',
			'user_nicename'   => $login,
			'user_email'      => $login . '@example.com',
			'user_registered' => '2026-01-01 00:00:00',
			'display_name'    => $first[ $i % count( $first ) ] . ' ' . $last[ intdiv( $i, count( $first ) ) % count( $last ) ] . ' ' . $i,
		)
	);
	update_user_meta( (int) $wpdb->insert_id, $wpdb->prefix . 'capabilities', array( 'author' => true ) );
}
$zoe = get_user_by( 'login', 'picker-zoe' );
$zoe = $zoe ? $zoe->ID : wp_insert_user(
	array(
		'user_login'   => 'picker-zoe',
		'user_pass'    => wp_generate_password(),
		'user_email'   => 'picker-zoe@example.com',
		'display_name' => 'Zoe Zammit',
		'role'         => 'author',
	)
);

$adjectives = array( 'Ancient', 'Bright', 'Coastal', 'Daily', 'Early', 'Festive', 'Golden', 'Hidden', 'Inland', 'Joyful', 'Kind', 'Local', 'Modern', 'Northern', 'Open', 'Public', 'Quiet', 'Rural', 'Southern', 'Tidal', 'Urban', 'Vintage', 'Western', 'Young', 'Annual', 'Busy', 'Civic', 'Dry', 'Eastern', 'Fresh' );
$nouns      = array( 'harbours', 'walks', 'markets', 'festivals', 'gardens', 'churches', 'beaches', 'recipes', 'villages', 'museums', 'buses', 'ferries', 'schools', 'farms', 'bakeries', 'bridges', 'caves', 'forts', 'galleries', 'libraries', 'parks', 'quays', 'roads', 'squares', 'towers', 'valleys', 'wells', 'yards', 'docks', 'cliffs', 'bays', 'lanes', 'shops', 'cafes', 'clubs' );
$have       = (int) wp_count_terms( array( 'taxonomy' => 'post_tag', 'hide_empty' => false ) );
for ( $i = 0; $have < 1050 && $i < count( $adjectives ) * count( $nouns ); $i++ ) {
	$name = $adjectives[ $i % count( $adjectives ) ] . ' ' . $nouns[ intdiv( $i, count( $adjectives ) ) ] . ' ' . ( $i + 1 );
	if ( ! term_exists( $name, 'post_tag' ) && ! is_wp_error( wp_insert_term( $name, 'post_tag' ) ) ) {
		++$have;
	}
}
$zebra = term_exists( 'Zebra crossings', 'post_tag' );
$zebra = (int) ( $zebra ? $zebra['term_id'] : wp_insert_term( 'Zebra crossings', 'post_tag' )['term_id'] );

$post_by_title = static function ( string $title, array $extra = array() ): int {
	$found = get_posts(
		array(
			'title'          => $title,
			'post_type'      => 'post',
			'post_status'    => 'any',
			'fields'         => 'ids',
			'posts_per_page' => 1,
		)
	);
	return $found ? (int) $found[0] : (int) wp_insert_post( array_merge( array( 'post_title' => $title, 'post_status' => 'publish', 'post_author' => 1 ), $extra ) );
};
$tagged = $post_by_title( 'Picker: tagged with Zebra crossings' );
wp_set_post_terms( $tagged, array( $zebra ), 'post_tag' );
$authored = $post_by_title( 'Picker: written by Zoe Zammit', array( 'post_author' => $zoe ) );

$priorities = array( 'low', 'medium', 'high', 'urgent' );
for ( $i = 1; $i <= 40; $i++ ) {
	update_post_meta( $post_by_title( sprintf( 'Picker task %02d', $i ) ), 'priority', $priorities[ $i % 4 ] );
}

$set = null;
foreach ( \Corinthian\Plugin::instance()->sets->for_screen( 'post:post' ) as $candidate ) {
	if ( 'Picker fixture' === $candidate->title ) {
		$set = $candidate;
	}
}
if ( ! $set ) {
	$set = \Corinthian\Plugin::instance()->sets->save(
		array(
			'screen'  => 'post:post',
			'title'   => 'Picker fixture',
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

echo wp_json_encode(
	array(
		'set'      => $set->id,
		'previous' => $previous,
		'zebra'    => $zebra,
		'zoe'      => (int) $zoe,
		'tagged'   => $tagged,
		'authored' => $authored,
		'users'    => (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->users}" ),
		'tags'     => (int) wp_count_terms( array( 'taxonomy' => 'post_tag', 'hide_empty' => false ) ),
	)
);
