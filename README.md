# Pages

Create the pages a plugin needs, remember which ones they are, and label them
in the admin list so nobody deletes one by accident.

## What it does

A plugin that needs a checkout page usually creates it on activation, stores
the id in an option, and then has to cope with every way that can come apart:
the page trashed, the option missing, the page created twice because
activation ran again.

This does the creating and the remembering, gives the id or URL back by name,
and labels each page in Pages so it is obvious what it belongs to.

## Features

* Create the pages a plugin needs, without duplicating them on reactivation
* Get a page's id or URL by name, rather than reading an option
* Set the slug, title, status and starting content, including a shortcode or block
* Show a label in the admin list, so nobody deletes one wondering what it is
* Make a trashed page again, without touching the ones that are fine
* Wait for a "Create pages" button instead of making them straight away
* Keep the ids in your plugin's own settings, if that is where they live
* Forget a page, or bin it, at uninstall

## Installation

```bash
composer require sugarcommerce/pages
```

## Quick start

```php
use SugarCommerce\Pages\Pages;

add_action( 'init', static function () {
	Pages::register( 'myshop', [
		'checkout' => [
			'title' => __( 'Checkout', 'myshop' ),
			'slug'  => 'checkout',
		],
		'receipt'  => [
			'title'   => __( 'Order receipt', 'myshop' ),
			'content' => '[myshop_receipt]',
			'label'   => __( 'My Shop receipt', 'myshop' ),
		],
	] );
} );
```

Then, wherever you need to link to one:

```php
$url = Pages::get( 'myshop' )->url( 'checkout' );
```

Safe on every request: a page whose id is stored and still there is left
alone, so the second call does nothing and a page somebody binned comes back.

## A page's configuration

| Key | Default | |
|-----|---------|---|
| `title` | `''` | the page's title |
| `slug` | from the title | the page's address, given outright: a checkout's URL is not something to leave to its title |
| `content` | `''` | what it starts with: a shortcode, a block, markup |
| `status` | `publish` | |
| `parent` | `0` | a parent page's id |
| `label` | the title | the post state shown beside it in Pages |

A page with neither title nor content cannot be made, and comes back null.

## Finding them again

```php
$pages = Pages::get( 'myshop' );

$pages->id( 'checkout' );        // int, or null: never made, deleted, or in the trash
$pages->url( 'checkout' );       // the permalink, or null
$pages->is( 'checkout' );        // is the page being shown the checkout? (or pass a post)
$pages->key( $post );            // 'checkout', or null: which of these a post is
$pages->ids();                   // [ 'checkout' => 12, … ] -- only those that are there
$pages->missing();               // [ 'receipt' ] -- those that are not
$pages->all();                   // every page's configuration
```

A page in the trash is missing. `get_post()` still returns it, so a plugin
that only checks the post exists carries on linking customers to a page
nobody can reach.

Each prefix is its own set, so two plugins keep their pages apart, and a
page's id is stored in the option `{prefix}_{key}_page`.

## Making them later

Registered with `create` off, missing pages wait. Labels and lookups work
straight away; `create()` makes whatever is missing, for activation or a
setup screen's button:

```php
$pages = Pages::register( 'myshop', $definitions, [ 'create' => false ] );

register_activation_hook( __FILE__, static fn() => Pages::get( 'myshop' )->create() );

if ( [] !== $pages->missing() ) {
	// Show the "Create pages" button, which calls $pages->create().
}
```

## Keeping the ids in your own settings

A plugin whose settings already say "this is the checkout page" can keep the
ids there, so the page the library makes is the page the settings screen
shows, and a page the user picks there is the one the library labels:

```php
Pages::register( 'myshop', $definitions, [
	'get'    => static fn( string $key ) => myshop_get_option( $key . '_page' ),
	'set'    => static fn( string $key, int $id ) => myshop_update_option( $key . '_page', $id ),
	'delete' => static fn( string $key ) => myshop_delete_option( $key . '_page' ),
] );
```

## Uninstalling

```php
$pages->remove( 'checkout' );        // forget it; the page stays
$pages->remove( 'checkout', true );  // forget it and move it to the trash
```

Forgetting is the default, because a page holds content somebody may have
edited, and a plugin deleting it on the way out destroys work it did not do.

## Hooks

Named from the prefix, so two plugins never hear each other's:

| Hook | Kind | For |
|------|------|-----|
| `{prefix}_page_args` | filter | the `wp_insert_post()` arguments for a page about to be made, given its key and configuration |
| `{prefix}_page_created` | action | a page was made, given its key and id |

## Moving from arraypress/wp-*

From `arraypress/wp-register-pages` (`ArrayPress\RegisterPages`). The global
functions are gone -- a library bundled into several plugins must not declare
any -- and the one process-wide prefix is now one set of pages per prefix.
Stored ids keep their option names.

| Old | New |
|-----|-----|
| `register_pages( 'myshop', $pages )` | `Pages::register( 'myshop', $pages )` (returns the set, not the ids: `->ids()`) |
| `get_registered_page_id( $key )` | `Pages::get( 'myshop' )->id( $key )` |
| `get_registered_page_url( $key )` | `Pages::get( 'myshop' )->url( $key )` |
| `unregister_page( $key, $delete )` | `Pages::get( 'myshop' )->remove( $key, $delete )` |
| `Pages::set_prefix( 'myshop' )` then `Pages::add( $key, $config )` | `Pages::get( 'myshop' )->add( $key, $config )` |
| `Pages::id()`, `::url()`, `::ids()`, `::remove()`, `::all()`, `::post_states()` | the same names, on the set |
| option `{prefix}_{key}_page` | unchanged |
| post state key `{prefix}_{key}` | unchanged |
| — | `create` off and `create()`, `missing()`, `is()`, `key()`, `get`/`set`/`delete` stores, `{prefix}_page_args`, `{prefix}_page_created` |

## Requirements

* PHP 8.3 or later
* WordPress 7.1 or later

## License

GPL-2.0-or-later
