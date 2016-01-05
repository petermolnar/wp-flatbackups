<?php
/*
Plugin Name: wp-flatbackups
Plugin URI: https://github.com/petermolnar/wp-flatbackups
Description: auto-export WordPress content to flat YAML + Markdown files
Version: 0.1
Author: Peter Molnar <hello@petermolnar.eu>
Author URI: http://petermolnar.eu/
License: GPLv3
Required minimum PHP version: 5.3
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

if (!class_exists('WP_FLATBACKUPS')):

class WP_FLATBACKUPS {

	public function __construct () {
		register_activation_hook( __FILE__ , array( &$this, 'plugin_activate' ) );
		add_action( 'wp_footer', array( &$this, 'export_yaml'));
	}

	/**
	 * activate hook
	 */
	public static function plugin_activate() {
		if ( version_compare( phpversion(), 5.3, '<' ) ) {
			die( 'The minimum PHP version required for this plugin is 5.3' );
		}

		if (!function_exists('yaml_emit')) {
			die('`yaml_emit` function missing. Please install the YAML extension; otherwise this plugin will not work');
		}
	}

	/**
	 *
	 */
	public static function export_yaml () {

		if (!function_exists('yaml_emit')) {
			static::debug('`yaml_emit` function missing. Please install the YAML extension; otherwise this plugin will not work');
			return false;
		}

		if (!is_singular())
			return false;

		$post = static::fix_post();

		if ($post === false)
			return false;

		$filename = $post->post_name;

		$flatroot = WP_CONTENT_DIR . DIRECTORY_SEPARATOR . 'flat';
		$flatdir = $flatroot . DIRECTORY_SEPARATOR . $filename;
		$flatfile = $flatdir . DIRECTORY_SEPARATOR . 'item.md';

		$post_timestamp = get_the_modified_time( 'U', $post->ID );
		$file_timestamp = 0;

		if ( @file_exists($flatfile) ) {
			$file_timestamp = @filemtime ( $flatfile );
		}

		$mkdir = array ( $flatroot, $flatdir );
		foreach ( $mkdir as $dir ) {
			if ( !is_dir($dir)) {
				if (!mkdir( $dir )) {
					static::debug_log('Failed to create ' . $dir . ', exiting YAML creation');
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
		if ( !empty($attachments) && count($attachments) < 100 ) {
			$out['attachments'] = array();
			foreach ( $attachments as $aid => $attachment ) {
				$attachment_path = get_attached_file( $aid );
				$attachment_file = basename( $attachment_path);
				$target_file = $flatdir . DIRECTORY_SEPARATOR . $attachment_file;
				//static::debug ('should ' . $post->post_name . ' have this attachment?: ' . $attachment_file );
				if ( !is_file($target_file)) {
					if (!link( $attachment_path, $target_file )) {
						static::debug("could not hardlink '$attachment_path' to '$target_file'; trying to copy");
						if (!copy($attachment_path, $target_file )) {
							static::debug("could not copy '$attachment_path' to '$target_file'; saving attachment failed!");
						}
					}
				}
			}
		}

		$comments = get_comments ( array( 'post_id' => $post->ID ) );
		if ( $comments ) {
			foreach ($comments as $comment) {
				$cf_timestamp = 0;

				$cfile = $flatdir . DIRECTORY_SEPARATOR . 'comment_' . $comment->comment_ID . '.md';

				$c_timestamp = strtotime( $comment->comment_date );
				if ( @file_exists($cfile) ) {
					$cf_timestamp = @filemtime ( $cfile );
					if ( $c_timestamp == $cf_timestamp ) {
						continue;
					}
				}

				$c = array (
					'id' =>  (int)$comment->comment_ID,
					'author' => $comment->comment_author,
					'author_email' => $comment->comment_author_email,
					'author_url' => $comment->comment_author_url,
					'date' => $comment->comment_date,
					//'content' => $comment->comment_content,
					'useragent' => $comment->comment_agent,
					'type' => $comment->comment_type,
					'user_id' => (int)$comment->user_id,
				);

				if ( $avatar = get_comment_meta ($comment->comment_ID, "avatar", true))
					$c['avatar'] = $avatar;

				$social = static::preg_value($comment->comment_agent,'/Keyring_(.*?)_Reactions/' );

				if ($social) {
					$social = strtolower($social);
					if ( $smeta = get_comment_meta ($comment->comment_ID, "keyring-${social}_reactions", true))
						$c['keyring_reactions_importer'] = json_encode($smeta);
				}

				$cout = yaml_emit($c, YAML_UTF8_ENCODING );
				$cout .= "---\n" . $comment->comment_content;

				//static::debug ('Exporting comment #' . $comment->comment_ID. ' to ' . $cfile );
				file_put_contents ($cfile, $cout);
				touch ( $cfile, $c_timestamp );
			}
		}

		if ( $file_timestamp == $post_timestamp ) {
			return true;
		}

		$out = static::yaml();

		// write log
		//static::debug ('Exporting #' . $post->ID . ', ' . $post->post_name . ' to ' . $flatfile );
		file_put_contents ($flatfile, $out);
		touch ( $flatfile, $post_timestamp );
		return true;
	}


	/**
	 * show post in YAML format (Grav friendly version)
	 *
	 */
	public static function yaml ( $postid = false ) {

		if (!function_exists('yaml_emit')) {
			static::debug('`yaml_emit` function missing. Please install the YAML extension; otherwise this plugin will not work');
			return false;
		}

		if (!$postid)
			global $post;
		else
			$post = get_post($postid);

		$post = static::fix_post($post);

		if ( $post === false )
			return false;

		$postdata = static::raw_post_data($post);

		if (empty($postdata))
			return false;

		$excerpt = false;
		if (isset($postdata['excerpt']) && empty($postdata['excerpt'])) {
			$excerpt = $postdata['excerpt'];
			unset($postdata['excerpt']);
		}

		$content = $postdata['content'];
		unset($postdata['content']);

		$out = yaml_emit($postdata,  YAML_UTF8_ENCODING );
		if($excerpt) {
			$out .= "\n" . $excerpt . "\n";
		}

		$out .= "---\n" . $content;

		return $out;
	}

	/**
	 * raw data for various representations, like JSON or YAML
	 */
	public static function raw_post_data ( &$post = null ) {
		$post = static::fix_post($post);

		if ($post === false)
			return false;

		$content = $post->post_content;

		// fix all image attachments: resized -> original
		$urlparts = parse_url(site_url());
		$domain = $urlparts ['host'];
		$wp_upload_dir = wp_upload_dir();
		$uploadurl = str_replace( '/', "\\/", trim( str_replace( site_url(), '', $wp_upload_dir['url']), '/'));

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

		// get author name
		$author_id = $post->post_author;
		$author =  get_the_author_meta ( 'display_name' , $author_id );

		// exclude hidden meta and potential garbage
		$exclude_meta = "/^_|^snap/";
		$exclude_meta = apply_filters ( __CLASS__ . '_exclude_meta', $exclude_meta);

		// get meta
		$meta = get_post_meta($post->ID);

		foreach ($meta as $key => $value ) {

			if (preg_match($exclude_meta, $key)) {
				if ($key == '_wp_old_slug')
					$meta['old_slugs'] = $value;

				unset($meta[$key]);
				continue;
			}

			if (is_array($value) && count($value) == 1) {
				$v = maybe_unserialize(array_pop($value));
				if (preg_match("/^snap.*/", $key)) {
					$v = maybe_unserialize($v);
				}
				elseif ($key == 'syndication_urls') {
					$v = explode("\n", trim($v));
				}
				$meta[$key] = $v;
			}
		}

		// read all taxonomies
		$taxonomies = get_object_taxonomies( $post );
		$post_taxonomies = array();

		foreach ($taxonomies as $taxonomy ) {
			$terms = wp_get_post_terms( $post->ID, $taxonomy );

			if ( is_wp_error($terms)) {
				static::debug($terms->get_error_message());
				continue;
			}

			foreach ($terms as $term) {
				$t_n = str_replace('post_', '', $term->taxonomy);
				$post_taxonomies[ $t_n ][] = $term->name;
			}
		}

		// additional meta
		$meta['id'] = $post->ID;
		$meta['permalink'] = get_permalink( $post );
		$meta['shortlink'] = wp_get_shortlink( $post->ID );

		// assemble the data
		$out = array (
			'title' => trim(get_the_title( $post->ID )),
			'modified_date' => get_the_modified_time('c', $post->ID),
			'date' => get_the_time('c', $post->ID),
			'slug' => $post->post_name,
			'taxonomy' => $post_taxonomies,
			'post_meta' => $meta,
			'author' => $author,
		);

		if($post->post_excerpt && !empty(trim($post->post_excerpt))) {
			$out['excerpt'] = $post->post_excerpt;
		}

		$out['content'] = $content;

		return $out;
	}


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
	 */
	static function debug( $message, $level = LOG_NOTICE ) {
		if ( @is_array( $message ) || @is_object ( $message ) )
			$message = json_encode($message);


		switch ( $level ) {
			case LOG_ERR :
				wp_die( '<h1>Error:</h1>' . '<p>' . $message . '</p>' );
				exit;
			default:
				if ( !defined( 'WP_DEBUG' ) || WP_DEBUG != true )
					return;
				break;
		}

		error_log(  __CLASS__ . " => " . $message );
	}

	/**
	 *
	 */
	public static function preg_value ( $string, $pattern, $index = 1 ) {
		preg_match( $pattern, $string, $results );
		if ( isset ( $results[ $index ] ) && !empty ( $results [ $index ] ) )
			return $results [ $index ];
		else
			return false;
	}
}

$WP_FLATBACKUPS = new WP_FLATBACKUPS();

endif;