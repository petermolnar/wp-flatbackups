<?php
/*
Plugin Name: WP Flat Exports
Plugin URI: https://github.com/petermolnar/wp-flatexport
Description: auto-export WordPress contents to folders and plain text + markdown files for longetivity and portability
Version: 0.2
Author: Peter Molnar <hello@petermolnar.eu>
Author URI: http://petermolnar.eu/
License: GPLv3
Required minimum PHP version: 5.4
*/

/*  Copyright 2015 Peter Molnar ( hello@petermolnar.eu )

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

if (!class_exists('WP_FLATEXPORTS')):

class WP_FLATEXPORTS {

	const force = true;
	const basedir = 'flat';
	const basefile = 'item.md';
	const maxattachments = 100;

	public function __construct () {
		register_activation_hook( __FILE__ , array( &$this, 'plugin_activate' ) );
		add_action( 'wp_footer', array( 'WP_FLATEXPORTS', 'export'));
	}

	/**
	 * activate hook
	 */
	public static function plugin_activate() {
		if ( version_compare( phpversion(), 5.4, '<' ) ) {
			die( 'The minimum PHP version required for this plugin is 5.3' );
		}

	}

	/**
	 *
	 */
	public static function export () {

		if ( ! is_singular() )
			return false;

		$post = static::fix_post();

		if ( $post === false )
			return false;

		// create directory structure
		$filename = $post->post_name;

		$flatroot = WP_CONTENT_DIR . DIRECTORY_SEPARATOR . static::basedir;
		$flatdir = $flatroot . DIRECTORY_SEPARATOR . $filename;
		$flatfile = $flatdir . DIRECTORY_SEPARATOR . static::basefile;

		$post_timestamp = get_the_modified_time( 'U', $post->ID );
		$file_timestamp = 0;

		if ( @file_exists($flatfile) ) {
			$file_timestamp = @filemtime ( $flatfile );
		}

		$mkdir = array ( $flatroot, $flatdir );
		foreach ( $mkdir as $dir ) {
			if ( !is_dir($dir)) {
				if (!mkdir( $dir )) {
					static::debug_log('Failed to create ' . $dir . ', exiting export', 4);
					return false;
				}
			}
		}

		touch($flatdir, $post_timestamp);

		// get all the attachments
		$attachments = get_children( array (
			'post_parent'=>$post->ID,
			'post_type'=>'attachment',
			'orderby'=>'menu_order',
			'order'=>'asc'
		));

		// 100 is there for sanity
		// hardlink all the attachments; no need for copy
		// unless you're on a filesystem that does not support hardlinks
		if ( !empty($attachments) && count($attachments) < static::maxattachments ) {
			$out['attachments'] = array();
			foreach ( $attachments as $aid => $attachment ) {
				$attachment_path = get_attached_file( $aid );
				$attachment_file = basename( $attachment_path);
				$target_file = $flatdir . DIRECTORY_SEPARATOR . $attachment_file;
				static::debug ( "exporting {$attachment_file} for {$post->post_name}", 7 );
				if ( !is_file( $target_file ) ) {
					if ( ! link( $attachment_path, $target_file ) ) {
						static::debug("could not hardlink '{$attachment_path}' to '{$target_file}'; trying to copy", 5);
						if ( ! copy( $attachment_path, $target_file ) ) {
							static::debug("could not copy '{$attachment_path}' to '{$target_file}'; saving attachment failed!", 4);
						}
					}
				}
			}
		}

		// deal with comments
		/*
		 * [TYPE] - reply, like, etc.
		 * name <email> - url
		 * date
		 *
		 * ![avatar markdown]()
		 * text
		 *
		 */

		$comments = get_comments ( array( 'post_id' => $post->ID ) );
		if ( $comments ) {
			foreach ($comments as $comment) {

				$cfile = $flatdir . DIRECTORY_SEPARATOR . 'comment_' . $comment->comment_ID . '.md';
				$cf_timestamp = 0;
				$c_timestamp = strtotime( $comment->comment_date );

				if ( @file_exists($cfile) ) {
					$cf_timestamp = @filemtime ( $cfile );
				}

				if ( $c_timestamp == $cf_timestamp && static::force == false ) {
					continue;
				}

				$c = "{$comment->comment_type}\n";
				$c .= "{$comment->comment_author} <{$comment->comment_author_email}> - {$comment->comment_author_url}\n";
				$c .= date( 'Y-m-d H:i:s P', $c_timestamp) . "\n\n";

				if ( $avatar = get_comment_meta ($comment->comment_ID, "avatar", true))
					$c .= "![$comment->comment_author]({$avatar})\n";

				$c .= $comment->comment_content;

				static::debug ( "Exporting comment # {$comment->comment_ID} to {$cfile}", 6 );
				file_put_contents ($cfile, $c);
				touch ( $cfile, $c_timestamp );
			}
		}

		// in case our export is fresh or we're not forcing updates on each and
		// every time, walk away from this post
		if ( $file_timestamp == $post_timestamp && static::force == false ) {
			return true;
		}

		$out = static::plain_text_post();

		// write log
		static::debug ( "Exporting #{$post->ID}, {$post->post_name} to {$flatfile}", 6 );
		file_put_contents ($flatfile, $out);
		touch ( $flatfile, $post_timestamp );
		return true;
	}


	/**
	 *
	 *
	 */
	public static function plain_text_post ( $postid = false ) {

		if ( ! $postid )
			global $post;
		else
			$post = get_post( $postid );

		$post = static::fix_post( $post );

		if ( false === $post )
			return false;


		$postdata = static::raw_post_data( $post );

		if ( empty( $postdata ) )
			return false;

		$out = "";

		if ( isset( $postdata['title'] ) && ! empty( $postdata['title'] ) ) {
			$out .= "{$postdata['title']}\n";
			// first level header
			$out .= str_repeat( "=", strlen( $postdata['title'] ) ) . "\n\n";
		}

		//if ( !empty ( $postdata['reactions'] ) )
			//$out .= $postdata['reactions'] . "\n\n";

		if (isset($postdata['excerpt']) && !empty($postdata['excerpt']))
			$out .= $postdata['excerpt'] . "\n\n";

		$out .= $postdata['content'] . "\n\n";

		$out .= "Published\n";
		$out .= "---------\n";
		$out .= "{$postdata['published']}\n\n";

		if ( $postdata['published'] != $postdata['modified'] ) {
			$out .= "Updated\n";
			$out .= "-------\n";
			$out .= "{$postdata['modified']}\n\n";
		}

		$out .= "URLs\n";
		$out .= "----\n";
		$out .= "- <" . join ( ">\n- <", $postdata['urls'] ) . ">\n\n";

		if ( isset( $postdata['author'] ) && ! empty( $postdata['author'] ) ) {
			$out .= "Author\n";
			$out .= "------\n";
			$out .= "{$postdata['author']}\n\n";
		}

		if ( ! empty( $postdata['tags'] ) ) {
			$tags = array();
			foreach ( $postdata['tags'] as $k => $tag ) {
				array_push( $tags, "#{$tag->name}" );
			}
			$tags = join (', ', $tags);
			$out .= "Tags\n";
			$out .= "----\n";
			// these are hashtags, so escape the first one to avoid converting it into
			// a header
			$out .= "\\" . $tags . "\n\n";
		}

		return $out;
	}

	/**
	 *
	 */
	public static function post_content ( &$post ) {

		$content = trim( $post->post_content );

		$urlparts = parse_url(site_url());
		$domain = $urlparts ['host'];
		$wp_upload_dir = wp_upload_dir();
		$uploadurl = str_replace( '/', "\\/", trim( str_replace( site_url(), '', $wp_upload_dir['url']), '/'));

		// get trailing hashtags, if any
		//$hashtags = static::find_hashtag_line( $content );
		//$content = static::maybe_remove_hashtag_line( $content, $hashtags );

		// fix all image attachments: resized -> original
		$pregstr = "/((https?:\/\/". $domain .")?\/". $uploadurl ."\/.*\/[0-9]{4}\/[0-9]{2}\/)(.*)-([0-9]{1,4})×([0-9]{1,4})\.([a-zA-Z]{2,4})/";

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

		$pregstr = "/(https?:\/\/". $domain .")?\/". $uploadurl ."\/.*\/[0-9]{4}\/[0-9]{2}\/(.*?)\.([a-zA-Z]{2,4})/";

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

		// insert featured image
		$thid = get_post_thumbnail_id( $post->ID );
		if ( ! empty( $thid ) ) {
			$src = wp_get_attachment_image_src( $thid, 'full' );
			if ( isset($src[0]) ) {
				$meta = wp_get_attachment_metadata($thid);

				if ( empty( $meta['image_meta']['title'] ) )
					$title = $post->post_title;
				else
					$title = $meta['image_meta']['title'];

				$content .= "\n\n![{$title}]({$src[0]}){#img-{$thid}}";
			}
		}

		// get rid of wp_upload_dir in self urls
		$pattern = "/\({$wp_upload_dir['baseurl']}\/(.*?)\)/";
		$search = str_replace( '/', '\/', $wp_upload_dir['baseurl'] );
		$content = preg_replace( "/\({$search}\/(.*?)\)/", '(${1})', $content );

		// get rid of {#img-ID} -s
		$content = preg_replace( "/\{\#img-[0-9]+.*?\}/", "", $content );

		// convert standalone urls to <url>
		$content = preg_replace("/\b((?:http|https)\:\/\/?[a-zA-Z0-9\.\/\?\:@\-_=#]+\.[a-zA-Z0-9\.\/\?\:@\-_=#&]*)(?:\s|\n|\r|$)/i", '<${1}>', $content);

		// find all second level headers and replace them with underlined version
		$pattern = "/^##\s?+(.*)$/m";
		$matches = array();
		preg_match_all( $pattern, $content, $matches );

		if ( ! empty( $matches ) && isset( $matches[0] ) && ! empty( $matches[0] ) ) {
			foreach ( $matches[0] as $cntr => $match ) {
				$title = trim( $matches[1][$cntr] );
				$content = str_replace ( $match, $title ."\n" . str_repeat( "-", strlen( $title ) ), $content );
			}
		}

		// find links and replace them with footnote versions
		$pattern = "/\s+(\[([^\s].*?)\]\((.*?)(\s?+[\\\"\'].*?[\\\"\'])?\))/";
		$matches = array();
		preg_match_all( $pattern, $content, $matches );
		// [1] -> array of []()
		// [2] -> array of []
		// [3] -> array of ()
		// [4] -> (maybe) "" titles
		if ( ! empty( $matches ) && isset( $matches[0] ) && ! empty( $matches[0] ) ) {
			foreach ( $matches[1] as $cntr => $match ) {
				$name = trim( $matches[2][$cntr] );
				$url = trim( $matches[3][$cntr] );
				$title = "";

				if ( isset( $matches[4][$cntr] ) && !empty( $matches[4][$cntr] ) )
					$title = " {$matches[4][$cntr]}";

				$footnotes[] = "[{$name}]: {$url}{$title}";
				$content = str_replace ( $match, "[" . trim( $matches[2][$cntr] ) . "]" , $content );
			}

			$content = $content . "\n\n" . join( "\n", $footnotes );
		}

		// find images and replace them with footnote versions ?

		return $content;
	}

	/**
	 * raw data for various representations, like JSON or YAML
	 */
	public static function raw_post_data ( &$post = null ) {

		$post = static::fix_post( $post );

		if ($post === false)
			return false;

		$content = static::post_content ( $post );

		// excerpt
		$excerpt = "";
		if( $post->post_excerpt && !empty( trim( $post->post_excerpt ) ) ) {
			$excerpt = trim( $post->post_excerpt );
		}

		// get author name
		$author_id = $post->post_author;
		$author_name =  get_the_author_meta ( 'display_name' , $author_id );
		$author_email =  get_the_author_meta ( 'email' , $author_id );
		$author_url =  get_the_author_meta ( 'url' , $author_id );
		$author = "{$author_name} <{$author_email}>\n<{$author_url}>";

		// get a list of all possible URLs to this post, including syndications
		$post_urls = array();

		$slugs = get_post_meta ( $post->ID, '_wp_old_slug' );
		array_push ( $slugs, $post->post_name );
		array_push ( $slugs, $post->ID );
		$slugs = array_unique ( $slugs );

		foreach ( $slugs as $k => $slug ) {
			if ( preg_match ( '/-revision-v[0-9]+/', $slug ) ) {
				unset ( $slugs[ $k ] );
				continue;
			}

			$slugs[ $k ] = rtrim( site_url(), '/') . '/' . $slug;
		}

		$syndications = get_post_meta ( $post->ID, 'syndication_urls', true );
		if ( ! empty( $syndications ) ) {
			$syndications = explode( "\n", trim( $syndications ) );
			array_merge( $slugs, $syndications );
		}

		array_push ( $slugs, get_permalink( $post ) );
		array_push ( $slugs, wp_get_shortlink( $post->ID ) );

		foreach ( $slugs as $k => $slug ) {
			$slugs[ $k ] = rtrim( $slug, '/' );
		}

		$slugs = array_unique ( $slugs );
		usort( $slugs, function ( $a, $b ) { return strlen( $a ) - strlen( $b ); } );

		// read tags
		$tags = wp_get_post_terms( $post->ID, 'post_tag' );

		// assemble the data
		$out = array (
			'title' => trim( get_the_title( $post->ID ) ),
			'modified' => get_the_modified_time( 'Y-m-d H:i:s P', $post->ID ),
			'published' => get_the_time( 'Y-m-d H:i:s P', $post->ID ),
			'urls' => $slugs,
			'tags' => $tags,
			'author' => $author,
			'content' => $content,
			'excerpt' => trim( $excerpt ),
			//'reactions' => static::meta_reaction( $post ),
		);

		return $out;
	}


	/**
	 *
	 *
	public static function meta_reaction ( &$post ) {

		$url = trim ( get_post_meta( $post->ID, 'webmention_url', true ) );
		$type = trim ( get_post_meta( $post->ID, 'webmention_type', true ) );
		$rsvp = trim ( get_post_meta( $post->ID, 'webmention_data', true ) );

		if ( empty( $url ) )
			return false;

		switch ($type) {
			case 'from':
			case 'repost':
				$prefix = '**FROM:** ';
				break;
			case 're':
			case 'reply':
			case 'rsvp':
				$prefix = '**RE:** ';
				break;
			default:
				$prefix = '**URL:** ';
				break;
		}

		$r = "{$prefix}${url}";
		if ( !empty ($rsvp) )
			$r .= "\n{$rsvp}";

		return $r;
	}
	*/

	/**
	 * do everything to get the Post object
	 */
	public static function fix_post ( &$post = null ) {
		if ($post === null || !static::is_post($post))
			global $post;

		if (static::is_post($post))
			return $post;

		return false;
	}

	/**
	 * test if an object is actually a post
	 */
	public static function is_post ( &$post ) {
		if ( !empty($post) && is_object($post) && isset($post->ID) && !empty($post->ID) )
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
	public static function debug( $message, $level = LOG_NOTICE ) {
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
		if ( defined ( 'WP_DEBUG_LEVEL' ) ) {
			$wp_level = $levels [ WP_DEBUG_LEVEL ];
			if ( $level_ > $wp_level ) {
				return false;
			}
		}

		// ERR, CRIT, ALERT and EMERG
		if ( 3 >= $level_ ) {
			wp_die( '<h1>Error:</h1>' . '<p>' . $message . '</p>' );
			exit;
		}

		$trace = debug_backtrace();
		$caller = $trace[1];
		$parent = $caller['function'];

		if (isset($caller['class']))
			$parent = $caller['class'] . '::' . $parent;

		return error_log( "{$parent}: {$message}" );
	}

}

$WP_FLATEXPORTS = new WP_FLATEXPORTS();

endif;
