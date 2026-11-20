<?php
/**
 * The label each page wears in the Pages list ("Checkout Page"), kept for pages registered on a later request.
 *
 * @package   SugarCommerce\Pages
 * @copyright Copyright (c) 2026, ArrayPress Limited
 * @license   GPL-2.0-or-later
 * @since     1.0.0
 */

declare( strict_types=1 );

namespace SugarCommerce\Pages\Traits\Pages;

use WP_Post;

/**
 * Trait States
 *
 * The label each page wears in the Pages list ("Checkout Page"), kept for pages registered on a later request.
 *
 * Core's display_post_states filter, hooked once. The labels are
 * remembered in one option, so a page still says what it is on a request
 * where its registration has not run.
 *
 * @since 1.0.0
 */
trait States {

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

		// What is registered on this request, then what was registered on an
		// earlier one and not since.
		$labels = array_map( [ self::class, 'label' ], $this->pages ) + $this->remembered();

		foreach ( $labels as $key => $label ) {
			if ( $this->id( (string) $key ) === $post->ID ) {
				// Core prints post states as they are given.
				$states[ $this->prefix . '_' . $key ] = esc_html( (string) $label );
			}
		}

		return $states;
	}

	/**
	 * The option the labels of added pages are kept in.
	 *
	 * @return string
	 */
	public function labels_option(): string {
		return ( '' === $this->prefix ? '' : $this->prefix . '_' ) . 'page_labels';
	}

	/**
	 * Attach the post-state filter, once.
	 *
	 * @return void
	 */
	private function hook(): void {
		if ( ! $this->hooked ) {
			$this->hooked = true;

			add_filter( 'display_post_states', [ $this, 'post_states' ], 10, 2 );
		}
	}

	/**
	 * Keep a page's label for requests on which it is not registered.
	 *
	 * Written only the first time a key is seen, so a site whose admins use
	 * different languages does not rewrite the option on every request; when
	 * the page is registered, its label is taken from the registration.
	 *
	 * Not with ids kept in the plugin's own settings: those can only be read
	 * through the `get` given at registration, so such a plugin registers on
	 * every request anyway, and nothing lands in the options table.
	 *
	 * @param string $key The page's key.
	 *
	 * @return void
	 */
	private function remember( string $key ): void {
		if ( null !== $this->read ) {
			return;
		}

		$labels = $this->remembered();

		if ( ! isset( $labels[ $key ] ) ) {
			$labels[ $key ] = self::label( $this->pages[ $key ] );
			$this->save_labels( $labels );
		}
	}

	/**
	 * The labels kept from earlier requests, by key.
	 *
	 * @return array<string, string>
	 */
	private function remembered(): array {
		$labels = get_option( $this->labels_option(), [] );

		return is_array( $labels ) ? array_map( 'strval', $labels ) : [];
	}

	/**
	 * Store the labels, or drop the option when there are none.
	 *
	 * @param array<string, string> $labels By key.
	 *
	 * @return void
	 */
	private function save_labels( array $labels ): void {
		if ( [] === $labels ) {
			delete_option( $this->labels_option() );

			return;
		}

		update_option( $this->labels_option(), $labels, false );
	}

	/**
	 * A page's post state: its label, or its title.
	 *
	 * @param array<string, mixed> $config The page's configuration.
	 *
	 * @return string
	 */
	private static function label( array $config ): string {
		return '' !== (string) $config['label'] ? (string) $config['label'] : (string) $config['title'];
	}
}
