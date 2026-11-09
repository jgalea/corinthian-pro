<?php
// Creates a private admin post and an author post, both with meta fmt_flag=yes, and a
// non-default set on post:post with a formatting rule on that meta. Prints JSON.
$admin_private = wp_insert_post( array( 'post_title' => 'Private admin post', 'post_status' => 'private', 'post_author' => 1 ) );
$author        = get_user_by( 'login', 'author2' );
$author_post   = wp_insert_post( array( 'post_title' => 'Author draft', 'post_status' => 'draft', 'post_author' => $author->ID ) );
update_post_meta( $admin_private, 'fmt_flag', 'yes' );
update_post_meta( $author_post, 'fmt_flag', 'yes' );
$set = \Corinthian\Plugin::instance()->sets->save(
	array(
		'screen'   => 'post:post',
		'title'    => 'Formatting fixture',
		'position' => 99,
		'columns'  => array(
			array( 'id' => 'title', 'type' => 'native', 'label' => '', 'settings' => array( 'key' => 'title' ) ),
			array( 'id' => 'flag', 'type' => 'post.meta', 'label' => 'Flag', 'settings' => array( 'meta_key' => 'fmt_flag', 'display' => 'text' ) ),
		),
		'settings' => array( 'formatting' => array( array( 'id' => 'f1', 'column' => 'flag', 'op' => 'eq', 'value' => 'yes', 'tone' => 'red', 'scope' => 'row' ) ) ),
	)
);
echo wp_json_encode( array( 'set' => $set->id, 'private' => $admin_private, 'own' => $author_post ) );
