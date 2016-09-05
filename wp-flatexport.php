<?php
/*
Plugin Name: WP Flat Export
Plugin URI: https://github.com/petermolnar/wp-flatexport
Description: auto-export WordPress content to Spress compatible text files
Version: 0.8
Author: Peter Molnar <hello@petermolnar.net>
Author URI: http://petermolnar.net/
License: GPLv3
*/

/*  Copyright 2015-2016 Peter Molnar ( hello@petermolnar.net )

    This program is free software; you can redistribute it and/or modify
    it under the terms of the GNU General Public License, version 3, as
    published by the Free Software Foundation.

    This program is distributed in the hope that it will be useful,
    but WITHOUT ANY WARRANTY; without even the implied warranty of
    MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
    GNU General Public License for more details.

    You should have received a copy of the GNU General Public License
    along with this program; if not, write to the Free Software
    Foundation, Inc., 51 Franklin St, Fifth Floor, Boston, MA  02110-1301  USA
*/

namespace WP_FLATEXPORT;

require (__DIR__ . '/vendor/autoload.php');
//use \Yosymfony\Toml;
use \Symfony\Component\Yaml;

define ( 'WP_FLATEXPORT\FORCE', true );
define ( 'WP_FLATEXPORT\ROOT', \WP_CONTENT_DIR . DIRECTORY_SEPARATOR
	. 'flat' . DIRECTORY_SEPARATOR );
define ( 'WP_FLATEXPORT\COMMENTROOT', ROOT . 'comment' . DIRECTORY_SEPARATOR );


\register_activation_hook( __FILE__ , '\WP_FLATEXPORT\plugin_activate' );
\register_deactivation_hook( __FILE__ , '\WP_FLATEXPORT\plugin_deactivate' );

// init all the things
\add_action( 'init', '\WP_FLATEXPORT\init' );

// export on any change made to a post
\add_action( 'transition_post_status', '\WP_FLATEXPORT\export_auto' );

// cron based export for all posts
\add_action( 'wp_flatexport', '\WP_FLATEXPORT\export_all' );

// display plain text with https://site.com/path/to/post/text
\add_action( 'template_redirect', '\WP_FLATEXPORT\display' );

//
//\add_action( 'wp', '\WP_FLATEXPORT\export' );

/**
 *
 */
function post_filename ( &$post, $ext = 'md' ) {
	$path = $post->post_name . '.' . $ext;

	$kind = wp_get_post_terms( $post->ID, 'category',
			array( 'fields' => 'all' ) );

	if(is_array($kind))
		$kind = array_pop( $kind );

	if (is_object($kind) && isset($kind->slug)) {
		$dir = ROOT . $kind->slug . DIRECTORY_SEPARATOR;
		if ( ! is_dir( $dir ) )
			mkdir ( $dir );

		$path = $dir . $path;
	}
	else {
		$path = ROOT . $path;
	}

	//$path = ROOT . $path;

	//$timestamp = \get_the_time( 'U', $post->ID );
	//$date = date( 'Y-m-d', $timestamp );
	//if ( empty( $date ) )
		//die ( json_encode( $post ) );

	return $path;
}

/**
 *
 */
function check_rootdirs() {
	$dirs = [ ROOT, COMMENTROOT ];
	foreach ( $dirs as $dir ) {
		$dir = rtrim( $dir, '/' );
		if ( ! is_dir( $dir  ) ) {
			if ( ! mkdir( $dir ) ) {
				die ( "Could not create " . $dir . "directory" );
			}
		}
	}
}

/**
 * activate hook
 */
function plugin_activate() {
	if ( version_compare( phpversion(), 5.4, '<' ) ) {
		die( 'The minimum PHP version required for this plugin is 5.3' );
	}

	check_rootdirs();
}

/**
 *
 */
function plugin_deactivate() {
		wp_unschedule_event( time(), 'wp_flatexport' );
		wp_clear_scheduled_hook( 'wp_flatexport' );
}

/**
 *
 */
function init () {

	$filters = array (
		'wp_flatexport_txt' => array (
			'txt_insert_spress_frontmatter',
			'txt_insert_content',
			'normalize_line_ends',
		),
		//'wp_flatexport_content' => array (
			//'post_content_add_excerpt',
			//'post_content_resized2orig',
			//'post_content_absolute_images',
			//'post_content_clear_imgids',
		//),
		'wp_flatexport_comment' => array (
			'comment_insert_spress_frontmatter',
			'comment_insert_content',
		),
	);

	foreach ( $filters as $for => $subfilters ) {
		foreach ( $subfilters as $k => $filter ) {
			\add_filter ( $for, "\\WP_FLATEXPORT\\$filter", 5 * ( $k + 1 ), 2 );
		}
	}

	\add_rewrite_endpoint( 'text', EP_PERMALINK );

	if (!wp_get_schedule( 'wp_flatexport' ))
		wp_schedule_event ( time(), 'daily', 'wp_flatexport' );

}

/**
 * on the fly text format, mostly for debugging
 */
function display () {
	global $wp_query;
	// if this is not a request for json or a singular object then bail
	if ( ! isset( $wp_query->query_vars['text'] ) || ! is_singular() )
		return;

	// include custom template
	header("Content-Type: text/plain");
	print export();
	exit;
}

/**
 * Windows, go home
 */
function normalize_line_ends( $content, $post ) {
	//return preg_replace( "/(?<=[^\r]|^)\n/", "\n", $content );
	return preg_replace( "/\r/", "", $content );
}

/**
 *
 * extends the $text with
 *
 * \n (post content)
 */
function txt_insert_content ( $text, $post ) {

	$content = apply_filters(
		'wp_flatexport_content',
		trim( $post->post_content ),
		$post
	);

	if ( ! empty( $content ) )
		$text .= "\n" . $content;

	return $text;
}

/**
 *
 * YAML version
 */
function txt_insert_spress_frontmatter ( $content, $post ) {

	$published = \get_the_time( 'U', $post->ID );
	$modified = \get_the_modified_time( 'U', $post->ID );

	$meta = [
		'title' => trim( $post->post_title ),
		'date' => date('c', $published ),
		/*
		// the author is the same site-wise, so put it in the Spress config
		'author' => [
			'name' => \get_the_author_meta ( 'display_name' , $post->post_author ),
			'email' => \get_the_author_meta ( 'email' , $post->post_author ),
			'url' => \get_the_author_meta ( 'url' , $post->post_author ),
		],
		*/
	];

	// updated
	if ( $published != $modified && $modified > $published ) {
		$mta['updated'] = date('c', $modified );
	}

	// redirects from _wp_old_slug
	// requires https://github.com/ajgarlag/AjglRedirectorSpressPlugin
	if ( $olds = get_post_meta( $post->ID, '_wp_old_slug', false ) ) {
		$olds_f = array();
		foreach( $olds as $c => $old ) {

			// WP_SHORTSLUG add the epoch based base32 urls to the _wp_old_slug
			// field; these should be handled separately and not in this very
			// redirect method, so skip those
			if ( class_exists( '\WP_SHORTSLUG' )
				&& function_exists( '\WP_SHORTSLUG\url2epoch' ) ) {
				$epoch = \WP_SHORTSLUG\url2epoch( $old );
				if ( $epoch == $published || $epoch == $modified ) {
					continue;
				}
			}

			array_push( $olds_f, '/' . trim( $old, '/') . '/' );
		}

		if ( count( $olds_f ) > 0 ) {
			$meta['redirect_from'] = $olds_f;
		}
	}

	// category
	$categories = wp_get_post_terms( $post->ID, 'category',
		array( 'fields' => 'all' ) );

	if( is_array( $categories ) )
		$categories = array_pop( $categories );

	$meta['categories'] = array( $categories->slug );

	// add syndicated URLs, if any
	if ( $syn = get_post_meta( $post->ID, 'syndication_urls', true ) ) {
	$syn = explode( "\n", $syn );
		if ( count( $syn ) ) {
			$meta['syndications'] = $syn;
		}
	}

	// tags
	$raw_tags = \wp_get_post_terms( $post->ID, 'post_tag' );
	$tags = array();
	if ( ! empty( $raw_tags ) ) {
		foreach ( $raw_tags as $k => $tag ) {
			array_push( $tags, $tag->name );
		}
		$meta['tags'] = $tags;
	}

	// geo
	$lat = floatval ( \get_post_meta ( $post->ID, 'geo_latitude' , true ) );
	$lon = floatval ( \get_post_meta ( $post->ID, 'geo_longitude' , true ) );

	if ( ! empty( $lat ) && ! empty( $lon ) ) {
		$meta['location'] = [
			'latitude' => $lat,
			'longitude' => $lon
		];

		$alt = floatval ( \get_post_meta ( $post->ID, 'geo_altitude' , true ) );
		if ( !empty( $alt ) ) {
			$meta['location']['altitude'] = $alt;
		}
	}

	return "---\n" . trim ( \Symfony\Component\Yaml\Yaml::dump( $meta, 1 ) )
		. "\n---\n" . $content;
}

/**
 *
 */
function comment_insert_spress_frontmatter ( $c, $comment ) {

	if ( empty ( $comment->comment_type ) )
		$type = "Reply";
	else
		$type = ucfirst( $comment->comment_type );

	$meta = [
		'from' => [
			'name' => $comment->comment_author,
			'email' => $comment->comment_author_email,
			'url' => $comment->comment_author_url,
		],
		'type' => $type,
		'for' => \get_permalink( $comment->comment_post_ID ),
		'date' => date( 'c', strtotime( $comment->comment_date ) ),
	];

	return "---\n" . trim ( \Symfony\Component\Yaml\Yaml::dump( $meta, 1 ) )
		. "\n---\n" . $c;
}

/**
 *
 * extends the $c with
 *
 * \n\n (comment content) \n
 */
function comment_insert_content ( $c, $comment ) {
	if ( ! empty( $comment->comment_content ) )
		$c .= "\n" . trim( $comment->comment_content ) . "\n";

	return $c;
}


/**
 *
 */
function post_content ( &$post ) {
	return trim (
		apply_filters (
			'wp_flatexport_content',
			trim( $post->post_content ),
			$post
		)
	);
}

/**
 *
 */
function export_all () {

	$types = get_post_types();
	$exclude = [ 'attachment', 'revision', 'nav_menu_item' ];
	foreach ( $exclude as $ex )
		unset ( $types[ $ex ] );

	$args = [
		'posts_per_page' => -1,
		'post_types' => array_keys( $types ),
	];

	$posts = get_posts( $args );
	foreach ( $posts as $post ) {
		export ( $post );
	}

	$args = [
		'hierarchical' => 0,
		'post_type' => 'page',
		'post_status' => 'publish'
	];

	$posts = get_pages( $args );
	foreach ( $posts as $post ) {
		export ( $post, 'raw' );
	}

}

/**
 *
 */
function export_auto ( $new_status = null , $old_status = null,
	$post = null ) {
	if (  null === $new_status || null === $old_status || null === $post )
		return;

	export ( $post );
}

/**
 *
 */
function export ( $post = null, $mode = 'normal' ) {

	check_rootdirs();

	if ( null === $post ) {
		 if ( ! \is_singular() )
			return false;
	}

	$post = fix_post( $post );
	if ( $post === false )
		return false;

	// create directory structure
	$flatfile = post_filename( $post );

	$post_timestamp = \get_the_time( 'U', $post->ID );
	$file_timestamp = 0;

	if ( @file_exists($flatfile) ) {
		$file_timestamp = @filemtime ( $flatfile );
	}

	//// deal with comments
	$comments = get_comments ( array( 'post_id' => $post->ID ) );
	if ( $comments ) {
		foreach ($comments as $comment) {
			export_comment ( $post, $comment );
		}
	}

	// in case our export is fresh or we're not forcing updates on each and
	// every time, walk away from this post
	if ( $file_timestamp == $post_timestamp && FORCE == false ) {
		return true;
	}

	if ( $mode == 'raw' )
		$txt = apply_filters (
			'wp_flatexport_content',
			trim( $post->post_content ),
			$post
		);
	else
		$txt = trim ( apply_filters ( 'wp_flatexport_txt', "", $post ) ) . "\n\n";

	// write log
	debug ( "Exporting #{$post->ID}, {$post->post_name} to {$flatfile}", 6 );
	file_put_contents ($flatfile, $txt);
	touch ( $flatfile, $post_timestamp );

	touch ( dirname( $flatfile), $post_timestamp );

	return $txt;
}

/**
 *
 */
function export_comment ( $post, $comment ) {
	$flatdir = dirname( post_filename( $post ) );

	$c_timestamp = strtotime( $comment->comment_date );
	$cfile = date( 'Y-m-d-H-i-s', $c_timestamp ) . '.md';
	$cfile = COMMENTROOT . $cfile;

	$cf_timestamp = 0;
	if ( @file_exists($cfile) ) {
		$cf_timestamp = @filemtime ( $cfile );
	}

	// non force mode means skip existing
	if ( $c_timestamp == $cf_timestamp && FORCE == false ) {
		return;
	}

	$c = trim ( apply_filters ( 'wp_flatexport_comment', "", $comment ) );

	debug ( "Exporting comment # {$comment->comment_ID} to {$cfile}", 6 );
	file_put_contents ($cfile, $c);
	touch ( $cfile, $c_timestamp );
}


/**
 * do everything to get the Post object
 */
function fix_post ( &$post = null ) {
	if ($post === null || !is_post($post))
		global $post;

	if (is_post($post))
		return $post;

	return false;
}

/**
 * test if an object is actually a post
 */
function is_post ( &$post ) {
	if ( ! empty( $post ) &&
			 is_object( $post ) &&
			 isset( $post->ID ) &&
			 ! empty( $post->ID ) )
		return true;

	return false;
}

/**
 *
 * debug messages; will only work if WP_DEBUG is on
 * or if the level is LOG_ERR, but that will kill the process
 *
 * @param string $message
 * @param int $level
 *
 * @output log to syslog | wp_die on high level
 * @return false on not taking action, true on log sent
 */
function debug( $message, $level = LOG_NOTICE ) {
	if ( empty( $message ) )
		return false;

	if ( @is_array( $message ) || @is_object ( $message ) )
		$message = json_encode($message);

	$levels = array (
		LOG_EMERG => 0, // system is unusable
		LOG_ALERT => 1, // Alert 	action must be taken immediately
		LOG_CRIT => 2, // Critical 	critical conditions
		LOG_ERR => 3, // Error 	error conditions
		LOG_WARNING => 4, // Warning 	warning conditions
		LOG_NOTICE => 5, // Notice 	normal but significant condition
		LOG_INFO => 6, // Informational 	informational messages
		LOG_DEBUG => 7, // Debug 	debug-level messages
	);

	// number for number based comparison
	// should work with the defines only, this is just a make-it-sure step
	$level_ = $levels [ $level ];

	// in case WordPress debug log has a minimum level
	if ( defined ( '\WP_DEBUG_LEVEL' ) ) {
		$wp_level = $levels [ \WP_DEBUG_LEVEL ];
		if ( $level_ > $wp_level ) {
			return false;
		}
	}

	// ERR, CRIT, ALERT and EMERG
	if ( 3 >= $level_ ) {
		\wp_die( '<h1>Error:</h1>' . '<p>' . $message . '</p>' );
		exit;
	}

	$trace = debug_backtrace();
	$caller = $trace[1];
	$parent = $caller['function'];

	if (isset($caller['class']))
		$parent = $caller['class'] . '::' . $parent;

	if (isset($caller['namespace']))
		$parent = $caller['namespace'] . '::' . $parent;

	return error_log( "{$parent}: {$message}" );
}
