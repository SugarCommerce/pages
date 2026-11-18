<?php
/**
 * Pages
 *
 * @package SugarCommerce\Pages
 */

declare( strict_types=1 );

namespace SugarCommerce\Pages;

use WP_Post;

/**
 * The pages a plugin needs in order to work.
 *
 * A checkout, a receipt, an account screen. Every commerce plugin creates
 * them and remembers which is which, and most get one of three things wrong:
 *
 * - **Remembering.** The id has to be stored, or the next activation makes a
 *   second checkout page and the first one becomes a mystery.
 *
 * - **Checking.** `get_post()` returns a post object for a page in the trash,
 *   so a plugin that only checks existence carries on linking to a page
 *   nobody can reach. Trashed counts as gone here.
 *
 * - **Saying so.** Without a post state on the pages list, the admin has
 *   several pages they dare not touch and nothing to tell them why.
 *
 * One set of pages per prefix, so two plugins -- or two copies of this
 * library that Strauss prefixed into two plugins -- keep their pages apart:
 *
 *     $pages = Pages::register( 'myshop', [
 *         'checkout' => [ 'title' => __( 'Checkout', 'myshop' ), 'slug' => 'checkout' ],
 *         'receipt'  => [ 'title' => __( 'Receipt', 'myshop' ), 'content' => '[myshop_receipt]' ],
 *     ] );
 *
 *     $pages->url( 'checkout' );
 *     Pages::get( 'myshop' )->id( 'receipt' );
 */
final class Pages {

	/**
	 * Every set of pages, by prefix.
	 *
	 * @var array<string, Pages>
	 */
	private static array $sets = [];

	/**
	 * The pages, by key.
	 *
	 * @var array<string, array<string, mixed>>
	 */
	private array $pages = [];

	/**
	 * Whether the post-state filter is attached.
	 *
	 * @var bool
	 */
	private bool $hooked = false;

	/**
	 * Read a stored id, by key. Null for the option `{prefix}_{key}_page`.
	 *
	 * @var callable|null
	 */
	private $read;

	/**
	 * Store an id, by key.
	 *
	 * @var callable|null
	 */
	private $write;

	/**
	 * Forget an id, by key.
	 *
	 * @var callable|null
	 */
	private $forget;

	/**
	 * Register a plugin's pages, creating any that are missing.
	 *
	 * Safe on every request: a page whose id is stored and still there is
	 * left alone, and one that was deleted or trashed is made again. Calling
	 * it again for the same prefix adds to the same set.
	 *
	 * @param string                              $prefix  The plugin's prefix. Ids are stored as `{prefix}_{key}_page`.
	 * @param array<string, array<string, mixed>> $pages   By key: title, content, slug, parent, status, label.
	 * @param array<string, mixed>                $options `create` (default true): make missing pages now. `get`, `set`, `delete`: store the ids somewhere else.
	 *
	 * @return Pages
	 */
	public static function register( string $prefix, array $pages, array $options = [] ): self {
		$set = self::get( $prefix );

		$set->store( $options['get'] ?? null, $options['set'] ?? null, $options['delete'] ?? null );

		foreach ( $pages as $key => $config ) {
			$set->add( (string) $key, (array) $config, (bool) ( $options['create'] ?? true ) );
		}

		return $set;
	}

	/**
	 * The set of pages for a prefix, made empty if there is none yet.
	 *
	 * @param string $prefix The plugin's prefix.
	 *
	 * @return Pages
	 */
	public static function get( string $prefix ): self {
		$prefix = trim( $prefix, '_' );

		if ( ! isset( self::$sets[ $prefix ] ) ) {
			self::$sets[ $prefix ] = new self( $prefix );
		}

		return self::$sets[ $prefix ];
	}

	/**
	 * Forget every set. For tests.
	 *
	 * @return void
	 */
	public static function reset(): void {
		self::$sets = [];
	}

	/**
	 * A set of pages. Pages::get() keeps one per prefix; use that.
	 *
	 * @param string $prefix The plugin's prefix.
	 */
	public function __construct( private string $prefix ) {
		$this->prefix = trim( $prefix, '_' );
	}

	/**
	 * Keep the ids somewhere other than one option per page.
	 *
	 * A plugin whose settings already hold "which page is the checkout" can
	 * point this at them, so the page the library made is the page the
	 * settings screen shows. Each callable is given the key; `set` also the id.
	 *
	 * @param callable|null $get    Read the stored id: fn( string $key ): mixed.
	 * @param callable|null $set    Store it: fn( string $key, int $id ): void.
	 * @param callable|null $delete Forget it: fn( string $key ): bool.
	 *
	 * @return Pages
	 */
	public function store( ?callable $get, ?callable $set = null, ?callable $delete = null ): self {
		$this->read   = $get ?? $this->read;
		$this->write  = $set ?? $this->write;
		$this->forget = $delete ?? $this->forget;

		return $this;
	}

	/**
	 * Add a page, creating it if it is not there.
	 *
	 * @param string               $key    How the plugin refers to it.
	 * @param array<string, mixed> $config title, content, slug, parent, status, label (the post state; default the title).
	 * @param bool                 $create Whether to make it now if it is missing.
	 *
	 * @return int|null The page's id, or null if it is missing (or could not be made).
	 */
	public function add( string $key, array $config, bool $create = true ): ?int {
		if ( '' === $key ) {
			return null;
		}

		$this->pages[ $key ] = array_merge(
			[
				'title'   => '',
				'content' => '',
				'slug'    => '',
				'parent'  => 0,
				'status'  => 'publish',
				'label'   => '',
			],
			$config
		);

		if ( ! $this->hooked ) {
			$this->hooked = true;

			add_filter( 'display_post_states', [ $this, 'post_states' ], 10, 2 );
		}

		$id = $this->id( $key );

		if ( null !== $id || ! $create ) {
			return $id;
		}

		return $this->make( $key );
	}

	/**
	 * Make every page that is missing.
	 *
	 * For activation, or a "create pages" button, when the pages were
	 * registered with `create` off.
	 *
	 * @return array<string, int> The ids that exist afterwards, by key.
	 */
	public function create(): array {
		foreach ( $this->missing() as $key ) {
			$this->make( $key );
		}

		return $this->ids();
	}

	/**
	 * The keys whose page is missing: never made, deleted, or in the trash.
	 *
	 * @return string[]
	 */
	public function missing(): array {
		return array_values( array_filter( array_keys( $this->pages ), fn( string $key ): bool => null === $this->id( $key ) ) );
	}

	/**
	 * The id of a page, if it is still there.
	 *
	 * Null covers all three ways it might not be: never made, deleted, or in
	 * the trash. A trashed page is one nobody can reach, so a plugin treating
	 * it as present links its customers at a 404.
	 *
	 * @param string $key The page's key.
	 *
	 * @return int|null
	 */
	public function id( string $key ): ?int {
		$stored = null === $this->read ? get_option( $this->option( $key ) ) : call_user_func( $this->read, $key );

		// The id used to be stored as [ 'value' => id, 'label' => title ] to
		// suit a settings screen. Read both.
		if ( is_array( $stored ) ) {
			$stored = $stored['value'] ?? 0;
		}

		$id = is_numeric( $stored ) ? (int) $stored : 0;

		if ( $id < 1 ) {
			return null;
		}

		$post = get_post( $id );

		if ( ! $post instanceof WP_Post || 'page' !== $post->post_type || 'trash' === $post->post_status ) {
			return null;
		}

		return $id;
	}

	/**
	 * The permalink of a page.
	 *
	 * @param string $key The page's key.
	 *
	 * @return string|null Null when the page is missing or has no permalink.
	 */
	public function url( string $key ): ?string {
		$id = $this->id( $key );

		if ( null === $id ) {
			return null;
		}

		// get_permalink() answers false when it cannot. Cast, that was an
		// empty string: a link to the site root rather than no link.
		$url = get_permalink( $id );

		return is_string( $url ) && '' !== $url ? $url : null;
	}

	/**
	 * Whether a post -- by default the one being shown -- is a given page.
	 *
	 *     if ( $pages->is( 'checkout' ) ) { … }
	 *
	 * @param string           $key  The page's key.
	 * @param int|WP_Post|null $post A post, or null for the queried one.
	 *
	 * @return bool
	 */
	public function is( string $key, int|WP_Post|null $post = null ): bool {
		$id = $this->id( $key );

		if ( null === $id ) {
			return false;
		}

		if ( null === $post ) {
			return function_exists( 'get_queried_object_id' ) && get_queried_object_id() === $id;
		}

		return ( $post instanceof WP_Post ? $post->ID : $post ) === $id;
	}

	/**
	 * Which page a post is, if it is one of these.
	 *
	 * @param int|WP_Post $post The post.
	 *
	 * @return string|null The key, or null.
	 */
	public function key( int|WP_Post $post ): ?string {
		$id = $post instanceof WP_Post ? $post->ID : $post;

		foreach ( array_keys( $this->pages ) as $key ) {
			if ( $this->id( $key ) === $id ) {
				return $key;
			}
		}

		return null;
	}

	/**
	 * Every page that is there, by key.
	 *
	 * @return array<string, int>
	 */
	public function ids(): array {
		$ids = [];

		foreach ( array_keys( $this->pages ) as $key ) {
			$id = $this->id( $key );

			if ( null !== $id ) {
				$ids[ $key ] = $id;
			}
		}

		return $ids;
	}

	/**
	 * Forget a page, and optionally bin it.
	 *
	 * For uninstall. Not the default, because a page holds content somebody
	 * may have edited, and a plugin deleting it on the way out destroys work
	 * it did not do.
	 *
	 * @param string $key    The page's key.
	 * @param bool   $delete Whether to move the page to the trash as well.
	 *
	 * @return bool Whether a stored id was forgotten.
	 */
	public function remove( string $key, bool $delete = false ): bool {
		$id = $this->id( $key );

		if ( $delete && null !== $id ) {
			wp_delete_post( $id );
		}

		unset( $this->pages[ $key ] );

		return null === $this->forget ? delete_option( $this->option( $key ) ) : (bool) call_user_func( $this->forget, $key );
	}

	/**
	 * Every page's configuration, by key.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	public function all(): array {
		return $this->pages;
	}

	/**
	 * The prefix.
	 *
	 * @return string
	 */
	public function prefix(): string {
		return $this->prefix;
	}

	/**
	 * The option a page's id is kept in, when no store was given.
	 *
	 * @param string $key The page's key.
	 *
	 * @return string
	 */
	public function option( string $key ): string {
		return ( '' === $this->prefix ? '' : $this->prefix . '_' ) . $key . '_page';
	}

	/**
	 * Label these pages on the pages list.
	 *
	 * @param string[] $states The states so far.
	 * @param WP_Post  $post   The page being listed.
	 *
	 * @return string[]
	 */
	public function post_states( $states, $post ): array {
		$states = (array) $states;

		if ( ! $post instanceof WP_Post || 'page' !== $post->post_type ) {
			return $states;
		}

		foreach ( $this->pages as $key => $config ) {
			if ( $this->id( $key ) === $post->ID ) {
				$label = '' !== (string) $config['label'] ? $config['label'] : $config['title'];

				// Core prints post states as they are given.
				$states[ $this->prefix . '_' . $key ] = esc_html( (string) $label );
			}
		}

		return $states;
	}

	/**
	 * Make a page and remember it.
	 *
	 * @param string $key Its key.
	 *
	 * @return int|null
	 */
	private function make( string $key ): ?int {
		$config = $this->pages[ $key ];
		$args   = [
			'post_title'     => (string) $config['title'],
			'post_content'   => (string) $config['content'],
			'post_status'    => (string) $config['status'],
			'post_type'      => 'page',
			'post_parent'    => (int) $config['parent'],
			'comment_status' => 'closed',
			'ping_status'    => 'closed',
		];

		// A slug given outright, because the URL of a checkout page is not
		// something to leave to whatever the title happened to be.
		if ( '' !== (string) $config['slug'] ) {
			$args['post_name'] = sanitize_title( (string) $config['slug'] );
		}

		/**
		 * Filter the post about to be made for a page.
		 *
		 * @param array<string, mixed> $args   wp_insert_post() arguments.
		 * @param string               $key    The page's key.
		 * @param array<string, mixed> $config Its configuration.
		 */
		$args = (array) apply_filters( "{$this->prefix}_page_args", $args, $key, $config );

		$id = wp_insert_post( $args, true );

		if ( is_wp_error( $id ) || ! $id ) {
			return null;
		}

		$id = (int) $id;

		if ( null === $this->write ) {
			update_option( $this->option( $key ), $id, false );
		} else {
			call_user_func( $this->write, $key, $id );
		}

		/**
		 * A page was made.
		 *
		 * @param string $key The page's key.
		 * @param int    $id  Its id.
		 */
		do_action( "{$this->prefix}_page_created", $key, $id );

		return $id;
	}
}
