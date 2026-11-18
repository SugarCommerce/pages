<?php
/**
 * Pages tests.
 *
 * @package SugarCommerce\Pages
 */

declare( strict_types=1 );

namespace SugarCommerce\Pages\Tests;

use PHPUnit\Framework\TestCase;
use SugarCommerce\Pages\Pages;
use WP_Post;

/**
 * Making a plugin's pages, finding them again, and saying whose they are.
 */
final class PagesTest extends TestCase {

	/**
	 * A clean site.
	 */
	protected function setUp(): void {
		pg_reset();
	}

	/**
	 * The states the pages list would show for a post.
	 *
	 * @param int      $id     The post.
	 * @param string[] $states States already there.
	 *
	 * @return string[]
	 */
	private function states( int $id, array $states = [] ): array {
		return apply_filters( 'display_post_states', $states, get_post( $id ) );
	}

	// -- Making and remembering --------------------------------------------

	public function test_a_page_is_made_and_remembered(): void {
		$pages = Pages::register( 'shop', [ 'checkout' => [ 'title' => 'Checkout' ] ] );
		$id    = $pages->id( 'checkout' );

		$this->assertNotNull( $id );
		$this->assertSame( $id, $GLOBALS['pg']['options']['shop_checkout_page'] );
		$this->assertSame( 'publish', get_post( $id )->post_status );
		$this->assertSame( 'closed', get_post( $id )->comment_status );
	}

	public function test_registering_twice_makes_one_page(): void {
		$first  = Pages::register( 'shop', [ 'checkout' => [ 'title' => 'Checkout' ] ] )->id( 'checkout' );
		$second = Pages::register( 'shop', [ 'checkout' => [ 'title' => 'Checkout' ] ] )->id( 'checkout' );

		$this->assertSame( $first, $second );
		$this->assertCount( 1, $GLOBALS['pg']['posts'] );
	}

	/**
	 * get_post() hands back a trashed page, which nobody can reach.
	 */
	public function test_a_trashed_page_is_missing_and_made_again(): void {
		$pages = Pages::register( 'shop', [ 'checkout' => [ 'title' => 'Checkout' ] ] );
		$id    = $pages->id( 'checkout' );

		wp_delete_post( $id );

		$this->assertNull( $pages->id( 'checkout' ) );
		$this->assertNull( $pages->url( 'checkout' ) );
		$this->assertSame( [ 'checkout' ], $pages->missing() );

		$again = Pages::register( 'shop', [ 'checkout' => [ 'title' => 'Checkout' ] ] )->id( 'checkout' );

		$this->assertNotSame( $id, $again );
		$this->assertNotNull( $again );
	}

	public function test_a_deleted_page_is_missing(): void {
		$pages = Pages::register( 'shop', [ 'checkout' => [ 'title' => 'Checkout' ] ] );

		wp_delete_post( $pages->id( 'checkout' ), true );

		$this->assertNull( $pages->id( 'checkout' ) );
	}

	public function test_an_id_that_is_not_a_page_is_missing(): void {
		$post = wp_insert_post( [ 'post_title' => 'A post', 'post_type' => 'post' ] );

		$GLOBALS['pg']['options']['shop_checkout_page'] = $post;

		$this->assertNull( Pages::get( 'shop' )->id( 'checkout' ) );
	}

	public function test_the_page_is_made_as_configured(): void {
		$pages = Pages::register(
			'shop',
			[
				'parent'  => [ 'title' => 'Shop' ],
				'receipt' => [
					'title'   => 'Order receipt',
					'slug'    => 'Thank You!',
					'content' => '[shop_receipt]',
					'status'  => 'draft',
				],
			]
		);

		$post = get_post( $pages->id( 'receipt' ) );

		$this->assertSame( 'thank-you', $post->post_name );
		$this->assertSame( 'draft', $post->post_status );
		$this->assertSame( 'https://example.test/thank-you/', $pages->url( 'receipt' ) );
	}

	/**
	 * get_permalink() answers false when it cannot, and cast that was an
	 * empty string: a link to the site root rather than no link.
	 */
	public function test_a_page_without_a_permalink_has_no_url(): void {
		$pages = Pages::register( 'shop', [ 'checkout' => [ 'title' => 'Checkout' ] ] );

		$GLOBALS['pg']['no_permalink'] = [ $pages->id( 'checkout' ) ];

		$this->assertNull( $pages->url( 'checkout' ) );
	}

	public function test_the_old_storage_format_is_still_read(): void {
		$id = wp_insert_post( [ 'post_title' => 'Checkout', 'post_type' => 'page' ] );

		$GLOBALS['pg']['options']['shop_checkout_page'] = [ 'value' => $id, 'label' => 'Checkout' ];

		$this->assertSame( $id, Pages::register( 'shop', [ 'checkout' => [ 'title' => 'Checkout' ] ] )->id( 'checkout' ) );
		$this->assertCount( 1, $GLOBALS['pg']['posts'], 'A second page was made.' );
	}

	public function test_a_page_that_cannot_be_made_is_null(): void {
		$this->assertNull( Pages::get( 'shop' )->add( 'empty', [] ) );
		$this->assertNull( Pages::get( 'shop' )->add( '', [ 'title' => 'No key' ] ) );
	}

	/**
	 * Registered without making, a missing page waits for create(): the
	 * "Create pages" button, or activation.
	 */
	public function test_pages_can_wait_to_be_made(): void {
		$pages = Pages::register( 'shop', [ 'checkout' => [ 'title' => 'Checkout' ], 'account' => [ 'title' => 'Account' ] ], [ 'create' => false ] );

		$this->assertSame( [], $GLOBALS['pg']['posts'] );
		$this->assertSame( [ 'checkout', 'account' ], $pages->missing() );

		$ids = $pages->create();

		$this->assertSame( [ 'checkout', 'account' ], array_keys( $ids ) );
		$this->assertSame( [], $pages->missing() );
		$this->assertSame( $ids, $pages->create(), 'A second create() made more pages.' );
	}

	// -- Two plugins -------------------------------------------------------

	/**
	 * One set per prefix. The old class had one prefix for the whole
	 * process, so a second plugin calling it moved the first one's pages
	 * under the second's options.
	 */
	public function test_two_prefixes_keep_their_pages_apart(): void {
		$shop    = Pages::register( 'shop', [ 'account' => [ 'title' => 'Shop account' ] ] );
		$members = Pages::register( 'members', [ 'account' => [ 'title' => 'Members account' ] ] );

		$this->assertNotSame( $shop->id( 'account' ), $members->id( 'account' ) );
		$this->assertSame( $shop, Pages::get( 'shop' ) );
		$this->assertSame( $shop, Pages::get( '_shop_' ), 'The prefix was not trimmed.' );
		$this->assertArrayHasKey( 'members_account_page', $GLOBALS['pg']['options'] );
		$this->assertSame( [ 'shop_account' => 'Shop account' ], $this->states( $shop->id( 'account' ) ) );
	}

	// -- Storing the ids elsewhere -----------------------------------------

	/**
	 * A plugin whose settings already say which page is which keeps the ids
	 * there, so the page made is the page its settings screen shows.
	 */
	public function test_the_ids_can_be_kept_in_a_plugins_own_settings(): void {
		$settings = [];
		$pages    = Pages::register(
			'shop',
			[ 'success' => [ 'title' => 'Thank you' ] ],
			[
				'get'    => static function ( string $key ) use ( &$settings ) {
					return $settings[ $key . '_page' ] ?? 0;
				},
				'set'    => static function ( string $key, int $id ) use ( &$settings ) {
					$settings[ $key . '_page' ] = $id;
				},
				'delete' => static function ( string $key ) use ( &$settings ) {
					unset( $settings[ $key . '_page' ] );

					return true;
				},
			]
		);

		$this->assertSame( [ 'success_page' => $pages->id( 'success' ) ], $settings );
		$this->assertSame( [], $GLOBALS['pg']['options'] );

		$this->assertTrue( $pages->remove( 'success' ) );
		$this->assertSame( [], $settings );
	}

	// -- Labels ------------------------------------------------------------

	public function test_a_page_is_labelled_in_the_admin(): void {
		$pages = Pages::register( 'shop', [ 'checkout' => [ 'title' => 'Checkout' ] ] );

		$this->assertSame( [ 'shop_checkout' => 'Checkout' ], $this->states( $pages->id( 'checkout' ) ) );
	}

	public function test_another_page_is_not_labelled_and_states_are_kept(): void {
		Pages::register( 'shop', [ 'checkout' => [ 'title' => 'Checkout' ] ] );

		$other = wp_insert_post( [ 'post_title' => 'About', 'post_type' => 'page' ] );

		$this->assertSame( [ 'draft' => 'Draft' ], $this->states( $other, [ 'draft' => 'Draft' ] ) );
		$this->assertSame( [], Pages::get( 'shop' )->post_states( [], null ) );
	}

	public function test_a_label_is_preferred_and_escaped(): void {
		$pages = Pages::register( 'shop', [ 'checkout' => [ 'title' => 'Checkout', 'label' => 'Shop <b>checkout</b>' ] ] );

		$this->assertSame( [ 'shop_checkout' => 'Shop &lt;b&gt;checkout&lt;/b&gt;' ], $this->states( $pages->id( 'checkout' ) ) );
	}

	// -- Asking ------------------------------------------------------------

	public function test_which_page_a_post_is(): void {
		$pages = Pages::register( 'shop', [ 'checkout' => [ 'title' => 'Checkout' ], 'account' => [ 'title' => 'Account' ] ] );
		$id    = $pages->id( 'checkout' );

		$this->assertTrue( $pages->is( 'checkout', $id ) );
		$this->assertTrue( $pages->is( 'checkout', get_post( $id ) ) );
		$this->assertFalse( $pages->is( 'account', $id ) );
		$this->assertFalse( $pages->is( 'checkout' ), 'Nothing is being shown.' );

		$GLOBALS['pg']['queried'] = $id;

		$this->assertTrue( $pages->is( 'checkout' ) );
		$this->assertSame( 'checkout', $pages->key( $id ) );
		$this->assertNull( $pages->key( 9999 ) );
	}

	public function test_the_pages_can_be_listed(): void {
		$pages = Pages::register( 'shop', [ 'checkout' => [ 'title' => 'Checkout' ], 'nothing' => [] ] );

		$this->assertSame( [ 'checkout' ], array_keys( $pages->ids() ) );
		$this->assertSame( [ 'checkout', 'nothing' ], array_keys( $pages->all() ) );
		$this->assertSame( 'shop', $pages->prefix() );
		$this->assertSame( 'shop_checkout_page', $pages->option( 'checkout' ) );
	}

	// -- Forgetting --------------------------------------------------------

	public function test_forgetting_a_page_leaves_it(): void {
		$pages = Pages::register( 'shop', [ 'checkout' => [ 'title' => 'Checkout' ] ] );
		$id    = $pages->id( 'checkout' );

		$this->assertTrue( $pages->remove( 'checkout' ) );
		$this->assertSame( 'publish', get_post( $id )->post_status );
		$this->assertArrayNotHasKey( 'shop_checkout_page', $GLOBALS['pg']['options'] );
		$this->assertSame( [], $pages->all() );
	}

	public function test_a_page_can_be_binned_on_the_way_out(): void {
		$pages = Pages::register( 'shop', [ 'checkout' => [ 'title' => 'Checkout' ] ] );
		$id    = $pages->id( 'checkout' );

		$pages->remove( 'checkout', true );

		$this->assertSame( 'trash', get_post( $id )->post_status );
	}

	// -- Hooks -------------------------------------------------------------

	public function test_the_post_can_be_changed_and_its_making_heard(): void {
		$made = [];

		add_filter(
			'shop_page_args',
			static function ( array $args, string $key ): array {
				$args['post_title'] .= ' (' . $key . ')';

				return $args;
			},
			10,
			2
		);
		add_action(
			'shop_page_created',
			static function ( string $key, int $id ) use ( &$made ) {
				$made[ $key ] = $id;
			},
			10,
			2
		);

		$pages = Pages::register( 'shop', [ 'checkout' => [ 'title' => 'Checkout' ] ] );

		$this->assertSame( 'Checkout (checkout)', get_post( $pages->id( 'checkout' ) )->post_title );
		$this->assertSame( [ 'checkout' => $pages->id( 'checkout' ) ], $made );
	}

	public function test_the_label_filter_is_attached_once(): void {
		Pages::register( 'shop', [ 'a' => [ 'title' => 'A' ], 'b' => [ 'title' => 'B' ] ] );
		Pages::register( 'shop', [ 'c' => [ 'title' => 'C' ] ] );

		$this->assertCount( 1, $GLOBALS['pg']['hooks']['display_post_states'] );
		$this->assertInstanceOf( WP_Post::class, get_post( Pages::get( 'shop' )->id( 'c' ) ) );
	}
}
