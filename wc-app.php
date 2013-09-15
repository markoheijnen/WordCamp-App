<?php

/**
 * Plugin Name: WordCamp.org App
 * Plugin Description: Add endpoints to json-rest-api
 */

class WordCamp_App {

	public function __construct() {
		add_filter( 'json_endpoints', array( $this, 'json_endpoints' ), 100 );
	}

	public function json_endpoints( $endpoints ) {

		$endpoints = array(); // Others aren't needed

		$endpoints['/'] = array(
			array( array( 'WP_JSON_Server', 'getIndex' ), WP_JSON_Server::READABLE ),
		);

		$endpoints['/wordcamps'] = array(
			array( array( $this, 'get_wordcamps' ), WP_JSON_Server::READABLE ),
		);

		$endpoints['/wordcamps/(?P<id>\d+)'] = array(
			array( array( $this, 'get_wordcamp' ), WP_JSON_Server::READABLE ),
		);

		return $endpoints;
	}

	public function get_wordcamps() {
		global $wpdb;

		if( ! is_multisite() )
			return array( 'blog_id' => 1, 'url' => home_url() );

		// Not using wp_get_sites due limited functionality
		$query     = "SELECT blog_id, domain as url FROM $wpdb->blogs WHERE site_id = 1 AND blog_id != 1 AND public = 1 AND archived = '0' AND mature = 0 AND spam = 0 AND deleted = 0";
		$wordcamps = $wpdb->get_results( $query, ARRAY_A );

		foreach( $wordcamps as &$wordcamp ) {
			$wordcamp['title'] = get_blog_option( $wordcamp['blog_id'], 'blogname' );
		}

		return $wordcamps;
	}

	public function get_wordcamp( $id ) {
		global $wpdb;

		if( 1 == $id )
			$id = false;

		if( $id ) {
			$query  = "SELECT blog_id FROM $wpdb->blogs WHERE site_id = 1 AND blog_id = %d AND public = 1 AND archived = '0' AND mature = 0 AND spam = 0 AND deleted = 0";
			$id     = $wpdb->get_var( $wpdb->prepare( $query, $id ) );
		}

		if ( empty( $id ) )
			return new WP_Error( 'json_wordcamp_invalid_id', __( 'Invalid WordCamp ID.' ), array( 'status' => 404 ) );

		switch_to_blog( $id );

		return array(
			'sessions'   => $this->get_posts( 'wcb_session' ),
			'sponsors'   => $this->get_posts( 'wcb_sponsor' ),
			'speakers'   => $this->get_posts( 'wcb_speaker' ),
			'organizers' => $this->get_posts( 'wcb_organizer' ),
			'tracks'     => $this->get_terms( 'wcb_track' )
		);
	}


	private function get_posts( $post_type ) {
		$posts  = array();

		$args = array(
			'post_type'      => $post_type,
			'posts_per_page' => -1
		);
		$_posts = get_posts( $args );

		foreach( $_posts as $_post ) {
			$post = array(
				'post_id'   => $_post->ID,
				'title'     => $_post->post_title,
				'content'   => $_post->post_content,
				'thumbnail' => wp_get_attachment_url( get_post_thumbnail_id( $_post->ID ) )
			);

			if( 'wcb_speaker' == $post_type ) {
				$post['thumbnail'] = $this->get_avatar_url( get_post_meta( $_post->ID, '_wcb_speaker_email', true ), 150 );
			}
			else if( 'wcb_session' == $post_type ) {
				$post['datetime'] = get_post_meta( $_post->ID, '_wcpt_session_time', true );
				$post['type']     = get_post_meta( $_post->ID, '_wcpt_session_type', true );
				$post['tracks']   = wp_get_post_terms( $_post->ID, 'wcb_track', array( 'fields' => 'ids' ) );
				$post['speakers'] = get_post_meta( $_post->ID, '_wcpt_speaker_id' );
			}
			else if( 'wcb_sponsor' == $post_type ) {
				$post['level']   = wp_get_post_terms( $_post->ID, 'wcb_sponsor_level', array( 'fields' => 'names' ) );
			}

			if( 'wcb_speaker' == $post_type || 'wcb_organizer' == $post_type  ) {
				$user_id    = get_post_meta( $_post->ID, '_wcpt_user_id', true );
				$wporg_user = get_user_by( 'id', $user_id );

				if ( $wporg_user )
					$post['wporg_user'] = $wporg_user->user_nicename;
			}

			$posts[] = $post;
		}

		return $posts;
	}

	private function get_terms( $taxonomy ) {
		$terms  = array();
		$_terms = get_terms( $taxonomy );

		foreach( $_terms as $_term ) {
			$terms[] = array(
				'term_id' => $_term->term_id,
				'name'    => $_term->name
			);
		}

		return $terms;
	}



	private function get_avatar_url( $email, $size = '96' ) {
        if ( empty($default) ) {
			$avatar_default = get_option('avatar_default');

			if ( empty($avatar_default) )
				$default = 'mystery';
			else
				$default = $avatar_default;
			}

		$email_hash = md5( strtolower( trim( $email ) ) );

		if ( is_ssl() ) {
			$host = 'https://secure.gravatar.com';
		}
		else {
			if ( !empty($email) )
				$host = sprintf( "http://%d.gravatar.com", ( hexdec( $email_hash[0] ) % 2 ) );
			else
				$host = 'http://0.gravatar.com';
		}

		if ( 'mystery' == $default )
			$default = "$host/avatar/ad516503a11cd5ca435acc9bb6523536?s={$size}"; // ad516503a11cd5ca435acc9bb6523536 == md5('unknown@gravatar.com')
		elseif ( 'blank' == $default )
			$default = $email ? 'blank' : includes_url( 'images/blank.gif' );
		elseif ( !empty($email) && 'gravatar_default' == $default )
			$default = '';
		elseif ( 'gravatar_default' == $default )
			$default = "$host/avatar/?s={$size}";
		elseif ( empty($email) )
			$default = "$host/avatar/?d=$default&amp;s={$size}";
		elseif ( strpos($default, 'http://') === 0 )
			$default = add_query_arg( 's', $size, $default );

		$out = "$host/avatar/";
		$out .= $email_hash;
		$out .= '?s='.$size;
		$out .= '&amp;d=' . urlencode( $default );

		return $out;
	}

}

$GLOBALS['wc_app'] = new WordCamp_App;