<?php
/*
Plugin Name: WP Flat Export
Plugin URI: https://github.com/petermolnar/wp-flatexport
Description: auto-export WordPress flat, structured, readable plain text
Version: 0.5
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

namespace WP_FLATEXPORTS;

define ( 'force', true );
define ( 'basedir', 'flat' );
define ( 'basefile', 'index.txt' );
define ( 'pandocfile', 'content.asciidoc' );
define ( 'maxattachments', 100 );
define ( 'expire', 10 );
define ( 'wrap', 80 );

\register_activation_hook( __FILE__ , '\WP_FLATEXPORTS\plugin_activate' );
\add_action( 'wp', '\WP_FLATEXPORTS\export' );
\add_action ( 'init', '\WP_FLATEXPORTS\init' );


/**
 * activate hook
 */
function plugin_activate() {
	if ( version_compare( phpversion(), 5.4, '<' ) ) {
		die( 'The minimum PHP version required for this plugin is 5.3' );
	}

}

/**
 *
 */
function init () {

	$filters = array (
		'wp_flatexport_post' => array (
			'insert_title',
			'insert_excerpt',
			'insert_content',
			'insert_published',
			'insert_urls',
			'insert_author',
			'insert_tags',
			'insert_location',
			'insert_uuid',
		),
		'wp_flatexport_content' => array (
			'post_content_resized2orig',
			'post_content_clean_uploaddir',
			'post_content_insert_featured',
			'post_content_clear_imgids',
			//'post_content_pandoc',
			'post_content_fix_emstrong',
			'post_content_fix_dl',
			'post_content_url2footnote',
			'post_content_headers',
			//'post_content_urls',
		),
		'wp_flatexport_comment' => array (
			'comment_insert_type',
			'comment_insert_content',
			'comment_insert_from',
			'comment_insert_at',
		),
	);

	foreach ( $filters as $for => $subfilters ) {
		foreach ( $subfilters as $k => $filter ) {
			\add_filter (
				$for,
				"\\WP_FLATEXPORTS\\{$filter}",
				10 * ( $k + 1 ),
				2
			);
		}
	}

}


function depthmap () {

	return array (
		1 => "=", // asciidoc, restuctured text, and markdown compatible
		2 => "-", // asciidoc, restuctured text, and markdown compatible
		3 => "~", // asciidoc only
		4 => "^", // asciidoc only
		5 => "+", // asciidoc only
	);
}

/**
 *
 */
function _insert_head ( $title, $depth = 2 ) {
	$map = depthmap();
	$underline =  str_repeat( $map[ $depth ], mb_strlen( $title) );
	return "\n\n{$title}\n${underline}\n";
}

/**
 * extends the $text with
 *
 * (post title)
 * ============
 *
 */
function insert_title ( $text, $post ) {

	$title = trim( \get_the_title( $post->ID ) );

	if ( empty( $title ) )
		return $text;

	// the linebreaks are here in case the order of inserting things is changed
	$text .= _insert_head( $title, 1 );

	return $text;

}

/**
 *
 * extends the $text with
 *
 * UUID
 * ----
 * (post UUID)
 *
 * post UUID is an md5 hash of:
 *  post ID + (math add) epoch of post first publish date
 * this should not ever change!
 *
 */
function insert_uuid ( $text, $post ) {

	$uuid = hash (
		'md5',
		(int)$post->ID + (int) get_post_time('U', true, $post->ID )
	);
	$text .= _insert_head( "UUID" );
	$text .= "{$uuid}";

	return $text;

}

/**
 *
 * extends the $text with
 *
 * \n\n (post excerpt)
 */
function insert_excerpt ( $text, $post ) {

	$excerpt = trim( $post->post_excerpt );

	if( ! empty( $excerpt  ) )
		$text .= "\n\n" . $excerpt;

	return $text;

}

/**
 *
 * extends the $text with
 *
 * \n\n (post content)
 */
function insert_content ( $text, $post ) {
	$content = apply_filters(
		'wp_flatexport_content',
		trim( $post->post_content ),
		$post
	);

	if ( ! empty( $content ) )
		$text .= "\n\n" . $content;

	return $text;
}

/**
 *
 * extends the $text with
 *
 * Published
 * ---------
 * initial - (post publish date in Y-m-d H:i:s P format)
 * [current - (post last update date in Y-m-d H:i:s P format)]
 */
function insert_published ( $text, $post ) {

	$published = \get_the_time( 'Y-m-d H:i:s P', $post->ID );
	$modified = \get_the_modified_time( 'Y-m-d H:i:s P', $post->ID );

	$text .= _insert_head ( "Published" );
	$text .= "initial - {$published}";

	if ( $published != $modified )
		$text .= "\ncurrent - {$modified}";

	return $text;
}

/**
 *
 * extends the $text with
 *
 * URLs
 * ----
 * - http://site.com/post_ID
 * - (post shortlink)
 * - (post permalink)
 * [- additional urls, one per line]
 */
function insert_urls ( $text, $post ) {

	// basic ones
	$slugs = \get_post_meta ( $post->ID, '_wp_old_slug' );
	array_push ( $slugs, $post->post_name );
	array_push ( $slugs, $post->ID );

	// eliminate revisions
	foreach ( $slugs as $k => $slug ) {
		if ( preg_match ( '/-revision-v[0-9]+/', $slug ) ) {
			unset ( $slugs[ $k ] );
			continue;
		}

		// make them real URLs
		// site_url does not allow numbers only as slugs, so we're doing it the
		// hard way
		$slugs[ $k ] = rtrim ( \site_url( ), '/' ) . '/' . $slug;
	}

	// just in case these differ
	array_push ( $slugs, \get_permalink( $post ) );
	array_push ( $slugs, \wp_get_shortlink( $post->ID ) );

	// get syndicated URLs
	$syndications = \get_post_meta ( $post->ID, 'syndication_urls', true );
	if ( ! empty( $syndications ) )
		$slugs = array_merge( $slugs, explode( "\n", trim( $syndications ) ) );

	// get rid of trailing slashes; it's either no trailing slash or slash on
	// everything, which breaks .html-like real document path URLs
	foreach ( $slugs as $k => $slug ) {
		if ( ! strstr( $slug, 'http') ) {
			unset ( $slugs[ $k ] );
			continue;
		}
		$slugs[ $k ] = rtrim( $slug, '/' );
	}

	// eliminate duplicates
	$slugs = array_unique ( $slugs );

	// make it more readable
	usort(
		$slugs,
		function ( $a, $b ) {
			return strlen( $a ) - strlen( $b );
		}
	);

	$text .= _insert_head ( "URLs" );
	$text .= "- " . join ( "\n- ", $slugs );

	return $text;
}

/**
 *
 * extends the $text with
 *
 * Author
 * ------
 * Author Display Name [<author@email>]
 * avatar URL
 * [ author URL ]
 */
function insert_author ( $text, $post ) {

	$author_id = $post->post_author;
	$author = \get_the_author_meta ( 'display_name' , $author_id );

	if ( empty( $author ) )
		return $text;

	if ( $author_email = \get_the_author_meta ( 'email' , $author_id ) )
		$author .= " <{$author_email}>";

	$thid = get_user_option ( 'metronet_image_id', $author_id );
	if ( $thid ) {
		$image = wp_get_attachment_image_src ( $thid, 'thumbnail' );
		$avatar = \site_url( $image[0]);
	}
	else {
		$avatar = gravatar ( $author_email );
	}
	$author .= "\n${avatar}";

	if ( $author_url = \get_the_author_meta ( 'url' , $author_id ) )
		$author .= " \n{$author_url}";


	$text .= _insert_head ( "Author" );
	$text .= "{$author}";

	return $text;
}

/**
 *
 * extends the $text with
 *
 * Tags
 * ----
 * \#(comma separated list of # tags)
 */
function insert_tags ( $text, $post ) {

	$raw_tags = \wp_get_post_terms( $post->ID, 'post_tag' );

	if ( empty( $raw_tags ) )
		return $text;

	$tags = array();
	foreach ( $raw_tags as $k => $tag ) {
		array_push( $tags, "#{$tag->name}" );
	}

	array_unique( $tags );
	$tags = join ( ', ', $tags );

	$text .= _insert_head ( "Tags" );
	// these are hashtags, so escape the first one to avoid converting it into
	// a header
	$text .= "\\" . $tags;

	return $text;
}

/**
 *
 * extends the $text with
 *
 * Location
 * --------
 * latitude,longitude[@altitude]
 */
function insert_location ( $text, $post ) {

	// geo
	$lat = \get_post_meta ( $post->ID, 'geo_latitude' , true );
	$lon = \get_post_meta ( $post->ID, 'geo_longitude' , true );

	if ( empty( $lat ) || empty( $lon ) )
		return $text;

	$geo = "{$lat},{$lon}";

	$alt = \get_post_meta ( $post->ID, 'geo_altitude' , true );
	if ( !empty( $alt ) )
		$geo .= "@{$alt}";


	$text .= _insert_head ( "Location" );
	$text .= "{$geo}";

	return $text;
}

/**
 *
 * extends the $c with
 *
 * From
 * ------
 * Author Display Name [<author@email>]
 * avatar URL
 * [ author URL ]
 */
function comment_insert_from ( $c, $comment ) {
	$c .= _insert_head( "From" );

	$c .= "{$comment->comment_author}";

	if ( ! empty( $comment->comment_author_email ) )
		$c .= " <{$comment->comment_author_email}>";

	if ( $avatar = \get_comment_meta ($comment->comment_ID, "avatar", true))
		$c .= "\n{$avatar}";
	elseif ( ! empty( $comment->comment_author_email ) )
		$c .= "\n". gravatar ( $comment->comment_author_email );

	if ( ! empty( $comment->comment_author_url ))
		$c .= "\n{$comment->comment_author_url}";

	return $c;
}

/**
 *
 * extends the $c with
 *
 * (Type)
 * ======
 */
function comment_insert_type ( $c, $comment ) {
	if ( empty ( $comment->comment_type ) )
		$type = "Reply";
	else
		$type = ucfirst( $comment->comment_type );

	$c .= _insert_head( $type, 1 );

	return $c;
}

/**
 *
 * extends the $text with
 *
* At
 * --
 * (comment publish date in Y-m-d H:i:s P format)
 */
function comment_insert_at ( $c, $comment ) {
	$c .= _insert_head( "At" );
	$c .= date( 'Y-m-d H:i:s P', strtotime( $comment->comment_date ) );

	return $c;
}

/**
 *
 * extends the $c with
 *
 * \n\n (comment content) \n
 */
function comment_insert_content ( $c, $comment ) {
	if ( ! empty( $comment->comment_content ) )
		$c .= "\n\n" . $comment->comment_content . "\n";

	return $c;
}


/**
 * fix all image attachments: resized -> original
 *
 */
function post_content_resized2orig ( $content, $post ) {

	$urlparts = parse_url( \site_url() );
	$domain = $urlparts ['host'];
	$wp_upload_dir = \wp_upload_dir();
	$uploadurl = str_replace(
		'/',
		"\\/",
		trim( str_replace(
			\site_url(),
			'',
			$wp_upload_dir['url']
		), '/')
	);

	$pregstr = "/((https?:\/\/". $domain .")?"
	. "\/". $uploadurl
	. "\/.*\/[0-9]{4}\/[0-9]{2}\/)(.*)-([0-9]{1,4})×([0-9]{1,4})"
	. "\.([a-zA-Z]{2,4})/";

	preg_match_all( $pregstr, $content, $resized_images );

	if ( !empty ( $resized_images[0]  )) {
		foreach ( $resized_images[0] as $cntr => $imgstr ) {
			$done_images[ $resized_images[2][$cntr] ] = 1;
			$fname = $resized_images[2][$cntr] . '.' . $resized_images[5][$cntr];
			$width = $resized_images[3][$cntr];
			$height = $resized_images[4][$cntr];
			$r = $fname . '?resize=' . $width . ',' . $height;
			$content = str_replace ( $imgstr, $r, $content );
		}
	}

	$pregstr = "/(https?:\/\/". $domain .")?"
	. "\/".$uploadurl
	."\/.*\/[0-9]{4}\/[0-9]{2}\/(.*?)\.([a-zA-Z]{2,4})/";

	preg_match_all( $pregstr, $content, $images );
	if ( !empty ( $images[0]  )) {

		foreach ( $images[0] as $cntr=>$imgstr ) {
			if ( !isset($done_images[ $images[1][$cntr] ]) ){
				if ( !strstr($images[1][$cntr], 'http'))
					$fname = $images[2][$cntr] . '.' . $images[3][$cntr];
				else
					$fname = $images[1][$cntr] . '.' . $images[2][$cntr];

				$content = str_replace ( $imgstr, $fname, $content );
			}
		}
	}

	return $content;
}

/**
 * get rid of wp_upload_dir in self urls
 *
 */
function post_content_clean_uploaddir ( $content, $post ) {

	$urlparts = parse_url( \site_url() );
	$domain = $urlparts ['host'];
	$wp_upload_dir = \wp_upload_dir();
	$uploadurl = str_replace(
		'/',
		"\\/",
		trim( str_replace( \site_url(), '', $wp_upload_dir['url'] ), '/' )
	);

	$pattern = "/\({$wp_upload_dir['baseurl']}\/(.*?)\)/";
	$search = str_replace( '/', '\/', $wp_upload_dir['baseurl'] );
	$content = preg_replace( "/\({$search}\/(.*?)\)/", '(${1})', $content );


	return $content;
}

/**
 * insert featured image
 *
 */
function post_content_insert_featured ( $content, $post ) {

	$thid = \get_post_thumbnail_id( $post->ID );
	if ( ! empty( $thid ) ) {
		$src = \wp_get_attachment_image_src( $thid, 'full' );
		if ( isset($src[0]) ) {
			$meta = \wp_get_attachment_metadata($thid);

			if ( empty( $meta['image_meta']['title'] ) )
				$title = $post->post_title;
			else
				$title = $meta['image_meta']['title'];

			$featured = "\n\n![{$title}]({$src[0]}){#img-{$thid}}";
			$content .= apply_filters (
				'wp_flatexport_featured_image',
				$featured,
				$post
			);
		}
	}

	return $content;
}

/**
 * get rid of markdown extra {#img-ID} -s
 *
 */
function post_content_clear_imgids ( $content, $post ) {

	$content = preg_replace( "/\{\#img-[0-9]+.*?\}/", "", $content );

	return $content;
}

/**
 * find markdown links and replace them with footnote versions
 *
 */
function post_content_url2footnote ( $content, $post ) {

	//
	$pattern = "/[\s*_\/]+(\[([^\s].*?)\]\((.*?)(\s?+[\\\"\'].*?[\\\"\'])?\))/";
	preg_match_all( $pattern, $content, $m );
	// [1] -> array of []()
	// [2] -> array of []
	// [3] -> array of ()
	// [4] -> (maybe) "" titles
	if ( ! empty( $m ) && isset( $m[0] ) && ! empty( $m[0] ) ) {
		foreach ( $m[1] as $cntr => $match ) {
			$name = trim( $m[2][$cntr] );
			$url = trim( $m[3][$cntr] );
			if ( ! strstr( $url, 'http') )
				$url = \site_url( $url );

			$title = "";

			if ( isset( $m[4][$cntr] ) && !empty( $m[4][$cntr] ) )
				$title = " {$m[4][$cntr]}";

			$refid = $cntr+1;

			$footnotes[] = "[{$refid}]: {$url}{$title}";
			$content = str_replace (
				$match,
				"[" . trim( $m[2][$cntr] ) . "][". $refid ."]" ,
				$content
			);
		}

		$content = $content . "\n\n" . join( "\n", $footnotes );
	}

	return $content;
}

/**
 * export with pandoc
 *
 */
function post_content_pandoc ( $content, $post ) {
	$flatroot = \WP_CONTENT_DIR . DIRECTORY_SEPARATOR . basedir;
	$flatdir = $flatroot . DIRECTORY_SEPARATOR . $post->post_name;
	$pandoc = $flatdir . DIRECTORY_SEPARATOR . pandocfile;

	$tmp = tempnam ( sys_get_temp_dir() , __NAMESPACE__ );
	file_put_contents( $tmp, $post->post_content );

	$cmd =
		"/usr/bin/pandoc -p -f markdown_phpextra -t asciidoc -o {$pandoc} {$tmp}";
	//exec( $cmd, $exif, $retval);
	passthru ( $cmd );

	unlink ( $tmp );

	return $content;
}

/**
 * find markdown links and replace them with footnote versions
 *
 */
function post_content_fix_emstrong ( $content, $post ) {

	// these regexes are borrowed from https://github.com/erusev/parsedown

	$regexes = array (
		'strong' => array(
			'**' => '/[*]{2}((?:\\\\\*|[^*]|[*][^*]*[*])+?)[*]{2}(?![*])/s',
			'__' => '/__((?:\\\\_|[^_]|_[^_]*_)+?)__(?!_)/us',
		),
		'em' => array (
			'*' => '/[*]((?:\\\\\*|[^*]|[*][*][^*]+?[*][*])+?)[*](?![*])/s',
			'_' => '/_((?:\\\\_|[^_]|__[^_]*__)+?)_(?!_)\b/us',
		)
	);

	$replace_map = array (
		'*' => '/',
		'_' => '/',
		'**' => '*',
		'__' => '*',
	);

	foreach ( $regexes as $what => $subregexes ) {
		$m = array();
		foreach ( $subregexes as $key => $regex ) {
			preg_match_all( $regex, $content, $m );
			if ( empty( $m ) || ! isset( $m[0] ) || empty( $m[0] ) )
				continue;

			foreach ( array_keys ( $m[1] ) as $cntr ) {
				$content = str_replace (
					$m[0][$cntr],
					$replace_map[ $key ] . $m[1][$cntr] . $replace_map[ $key ],
					$content
				);
			}

		}
	}

	return $content;
}

/**
 *
 *
 */
function post_content_fix_dl ( $content, $post ) {
	preg_match_all( '/^.*\n(:\s+).*$/mi', $content, $m );

	if ( empty( $m ) || ! isset( $m[0] ) || empty( $m[0] ) )
		return $content;

	foreach ( $m[0] as $i => $match ) {
		$match = str_replace( $m[1][$i], ':    ', $match );
		$content = str_replace( $m[0][$i], $match, $content );
	}

	return $content;
}




/**
 * find all second level markdown headers and replace them with
 * underlined version
 *
 */
function post_content_headers ( $content, $post ) {

	$map = depthmap();
	preg_match_all( "/^([#]+)\s?+(.*)$/m", $content, $m );

	if ( ! empty( $m ) && isset( $m[0] ) && ! empty( $m[0] ) ) {
		foreach ( $m[0] as $cntr => $match ) {
			$depth = strlen( trim( $m[1][$cntr] ) );
			$title = trim( $m[2][$cntr] );
			$u = str_repeat( $map[ $depth ], mb_strlen( $title ) );
			$content = str_replace ( $match, "{$title}\n{$u}", $content );
		}
	}

	return $content;
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
function export () {

	if ( ! \is_singular() )
		return false;

	$post = fix_post();

	if ( $post === false )
		return false;

	// create directory structure
	$filename = $post->post_name;

	$flatroot = \WP_CONTENT_DIR . DIRECTORY_SEPARATOR . basedir;
	$flatdir = $flatroot . DIRECTORY_SEPARATOR . $filename;
	$flatfile = $flatdir . DIRECTORY_SEPARATOR . basefile;

	$post_timestamp = \get_the_modified_time( 'U', $post->ID );
	$file_timestamp = 0;

	if ( @file_exists($flatfile) ) {
		$file_timestamp = @filemtime ( $flatfile );
	}

	$mkdir = array ( $flatroot, $flatdir );
	foreach ( $mkdir as $dir ) {
		if ( !is_dir($dir)) {
			if (!mkdir( $dir )) {
				debug_log('Failed to create ' . $dir . ', exiting export', 4);
				return false;
			}
		}
	}

	touch($flatdir, $post_timestamp);

	// get all the attachments
	$attachments = \get_children( array (
		'post_parent'=>$post->ID,
		'post_type'=>'attachment',
		'orderby'=>'menu_order',
		'order'=>'asc'
	));

	// 100 is there for sanity
	// hardlink all the attachments; no need for copy
	// unless you're on a filesystem that does not support hardlinks
	if ( !empty($attachments) && count($attachments) < maxattachments ) {
		$out['attachments'] = array();
		foreach ( $attachments as $aid => $attachment ) {
			$attachment_path = \get_attached_file( $aid );
			$attachment_file = basename( $attachment_path);
			$target_file = $flatdir . DIRECTORY_SEPARATOR . $attachment_file;
			debug ( "exporting {$attachment_file} for {$post->post_name}", 7 );

			if ( is_file( $target_file ) )
				continue;

			if ( link( $attachment_path, $target_file ) )
				continue;
			else
				debug( "could not hardlink '{$attachment_path}'"
					. " to '{$target_file}'; trying to copy", 5);

			if ( copy( $attachment_path, $target_file ) )
				continue;
			else
				debug( "could not copy '{$attachment_path}'"
					. " to '{$target_file}'; saving attachment failed!", 4);

		}
	}

	// deal with comments
	$comments = get_comments ( array( 'post_id' => $post->ID ) );
	if ( $comments ) {
		foreach ($comments as $comment) {
			export_comment ( $post, $comment );
		}
	}

	// in case our export is fresh or we're not forcing updates on each and
	// every time, walk away from this post
	if ( $file_timestamp == $post_timestamp && force == false ) {
		return true;
	}

	$out = trim ( apply_filters ( 'wp_flatexport_post', "", $post ) );

	// write log
	debug ( "Exporting #{$post->ID}, {$post->post_name} to {$flatfile}", 6 );
	file_put_contents ($flatfile, $out);
	touch ( $flatfile, $post_timestamp );
	return true;
}

/**
 *
 */
function export_comment ( $post, $comment ) {
	$filename = $post->post_name;
	$flatroot = \WP_CONTENT_DIR . DIRECTORY_SEPARATOR . basedir;
	$flatdir = $flatroot . DIRECTORY_SEPARATOR . $filename;

	$cfile = "comment_{$comment->comment_ID}.txt";
	$cfile = $flatdir . DIRECTORY_SEPARATOR . $cfile;

	$cf_timestamp = 0;
	$c_timestamp = strtotime( $comment->comment_date );

	if ( @file_exists($cfile) ) {
		$cf_timestamp = @filemtime ( $cfile );
	}

	// non force mode means skip existing
	if ( $c_timestamp == $cf_timestamp && force == false ) {
		return;
	}

	$c = trim ( apply_filters ( 'wp_flatexport_comment', "", $comment ) );

	debug ( "Exporting comment # {$comment->comment_ID} to {$cfile}", 6 );
	file_put_contents ($cfile, $c);
	touch ( $cfile, $c_timestamp );
}


/**
 *
 *
function yaml_header ( &$post ) {
	if ( ! extension_loaded( 'yaml') || ! function_exists( 'yaml_emit') )
		return false;

	$tags = \wp_get_post_terms( $post->ID, 'post_tag' );
	foreach ( $tags as $k => $tag ) {
		$tags[ $k ] = "{$tag->name}";
	}

	$urls = get_insert_urls ( $post );
	$permalink = \get_permalink( $post );
	foreach ( $urls as $k => $url ) {

		if ( ! strstr( $url, site_url() ) )
			unset ( $urls[ $k ] );

		if ( strstr ( $permalink, $url ) )
			unset ( $urls[ $k ] );

		$urls[ $k ] = str_replace ( rtrim( site_url() . '/' ), '/', $url );
	}

	$yaml = array (
		'title' => $post->post_title,
		'description' => $post->post_excerpt,
		'date' => \get_the_time( 'Y-m-d H:i:s P', $post->ID );
		'tags' => $tags,
		'aliases' => $urls,
		'slug' => $post->post_name,
		'url' => $permalink,

	//$modified = \get_the_modified_time( 'Y-m-d H:i:s P', $post->ID );
	);


}
*/

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

/**
 * generate gravatar img link
 */
function gravatar ( $email ) {
	return sprintf(
		'https://s.gravatar.com/avatar/%s?=64',
		md5( strtolower( trim( $email ) ) )
	);
}