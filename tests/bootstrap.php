<?php
/**
 * PHPUnit bootstrap.
 *
 * Posts and options live in globals, so a test can make a page, trash it,
 * and ask what the library makes of that -- which is where the interesting
 * behaviour is. Hooks really run their callbacks.
 *
 * @package SugarCommerce\Pages
 */

declare( strict_types=1 );

define( 'ABSPATH', __DIR__ . '/' );

require_once dirname( __DIR__ ) . '/vendor/autoload.php';

/**
 * Enough of a post.
 */
class WP_Post {
	public int $ID             = 0;
	public string $post_type   = 'page';
	public string $post_status = 'publish';
	public string $post_title  = '';
	public string $post_name   = '';
	public int $post_parent    = 0;
	public string $comment_status = 'open';
}

/**
 * Enough of an error.
 */
class WP_Error {
	public function __construct( public string $code = '', public string $message = '' ) {}
}

/**
 * Forget everything a previous test set up.
 *
 * @return void
 */
function pg_reset(): void {
	$GLOBALS['pg'] = [
		'hooks'   => [],
		'options' => [],
		'posts'   => [],
		'queried' => 0,
		'next'    => 100,
	];

	SugarCommerce\Pages\Pages::reset();
}

function esc_html( $text ) {
	return htmlspecialchars( (string) $text, ENT_QUOTES, 'UTF-8' );
}

function is_wp_error( $thing ) {
	return $thing instanceof WP_Error;
}

function add_filter( $hook, $callback, $priority = 10, $accepted_args = 1 ) {
	$GLOBALS['pg']['hooks'][ $hook ][] = [ $callback, (int) $accepted_args ];

	return true;
}

function add_action( $hook, $callback, $priority = 10, $accepted_args = 1 ) {
	return add_filter( $hook, $callback, $priority, $accepted_args );
}

function apply_filters( $hook, $value, ...$args ) {
	foreach ( $GLOBALS['pg']['hooks'][ $hook ] ?? [] as [ $callback, $accepted ] ) {
		$value = $callback( ...array_slice( array_merge( [ $value ], $args ), 0, $accepted ) );
	}

	return $value;
}

function do_action( $hook, ...$args ) {
	foreach ( $GLOBALS['pg']['hooks'][ $hook ] ?? [] as [ $callback, $accepted ] ) {
		$callback( ...array_slice( $args, 0, $accepted ) );
	}
}

function get_option( $key, $default = false ) {
	return $GLOBALS['pg']['options'][ $key ] ?? $default;
}

function update_option( $key, $value, $autoload = null ) {
	$GLOBALS['pg']['options'][ $key ] = $value;

	return true;
}

function delete_option( $key ) {
	$existed = isset( $GLOBALS['pg']['options'][ $key ] );

	unset( $GLOBALS['pg']['options'][ $key ] );

	return $existed;
}

function wp_insert_post( $args, $wp_error = false ) {
	if ( '' === ( $args['post_title'] ?? '' ) && '' === ( $args['post_content'] ?? '' ) ) {
		return $wp_error ? new WP_Error( 'empty_content', 'Content, title, and excerpt are empty.' ) : 0;
	}

	$id = $GLOBALS['pg']['next']++;

	$post                 = new WP_Post();
	$post->ID             = $id;
	$post->post_type      = $args['post_type'] ?? 'post';
	$post->post_status    = $args['post_status'] ?? 'publish';
	$post->post_title     = $args['post_title'] ?? '';
	$post->post_name      = $args['post_name'] ?? sanitize_title( $post->post_title );
	$post->post_parent    = (int) ( $args['post_parent'] ?? 0 );
	$post->comment_status = $args['comment_status'] ?? 'open';

	$GLOBALS['pg']['posts'][ $id ] = $post;

	return $id;
}

function get_post( $id = null ) {
	return $GLOBALS['pg']['posts'][ (int) $id ] ?? null;
}

function wp_delete_post( $id, $force = false ) {
	if ( $force ) {
		unset( $GLOBALS['pg']['posts'][ (int) $id ] );
	} elseif ( isset( $GLOBALS['pg']['posts'][ (int) $id ] ) ) {
		$GLOBALS['pg']['posts'][ (int) $id ]->post_status = 'trash';
	}

	return true;
}

function get_permalink( $id = 0 ) {
	$post = get_post( $id );

	if ( in_array( (int) $id, $GLOBALS['pg']['no_permalink'] ?? [], true ) ) {
		return false;
	}

	return $post ? 'https://example.test/' . $post->post_name . '/' : false;
}

function get_queried_object_id() {
	return $GLOBALS['pg']['queried'];
}

function sanitize_title( $title ) {
	return trim( (string) preg_replace( '/[^a-z0-9]+/', '-', strtolower( (string) $title ) ), '-' );
}

pg_reset();
