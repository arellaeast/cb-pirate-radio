<?php
defined( 'ABSPATH' ) || exit;

/**
 * cb_artist CPT — public artist profiles that "Currently Playing" links to.
 */
class CB_Radio_Artists {

	public static function init() {
		add_action( 'init', [ __CLASS__, 'register_cpt' ] );
	}

	public static function register_cpt() {
		register_post_type( 'cb_artist', [
			'labels' => [
				'name'          => __( 'Radio Artists', 'cb-radio' ),
				'singular_name' => __( 'Radio Artist', 'cb-radio' ),
				'add_new_item'  => __( 'Add Artist', 'cb-radio' ),
			],
			'public'       => true,
			'has_archive'  => true,
			'rewrite'      => [ 'slug' => 'radio/artists' ],
			'menu_icon'    => 'dashicons-microphone',
			'supports'     => [ 'title', 'editor', 'thumbnail' ],
			'show_in_rest' => true,
		] );
	}

	/** The artist post owned by a given user, or null. */
	public static function for_user( $user_id ) {
		$q = get_posts( [
			'post_type'      => 'cb_artist',
			'posts_per_page' => 1,
			'meta_key'       => 'cb_user_id',
			'meta_value'     => (int) $user_id,
			'post_status'    => [ 'publish', 'pending', 'draft' ],
		] );
		return $q ? $q[0] : null;
	}

	/** Create a minimal artist profile from the submission form. */
	public static function create( $user_id, $name, $bio, $links = [], $hood = '' ) {
		$post_id = wp_insert_post( [
			'post_type'    => 'cb_artist',
			'post_title'   => sanitize_text_field( $name ),
			'post_content' => wp_kses_post( $bio ),
			'post_status'  => 'publish', // TODO: consider 'pending' if profiles need review too
		] );
		if ( is_wp_error( $post_id ) ) {
			return $post_id;
		}
		update_post_meta( $post_id, 'cb_user_id', (int) $user_id );
		update_post_meta( $post_id, 'cb_links', wp_json_encode( array_map( 'esc_url_raw', $links ) ) );
		update_post_meta( $post_id, 'cb_hood', sanitize_text_field( $hood ) );
		return $post_id;
	}

	/** Compact payload for the now-playing enrichment. */
	public static function payload( $post_id ) {
		$post = get_post( $post_id );
		if ( ! $post || 'cb_artist' !== $post->post_type ) {
			return null;
		}
		return [
			'name'  => $post->post_title,
			'url'   => get_permalink( $post ),
			'photo' => get_the_post_thumbnail_url( $post, 'medium' ),
			'hood'  => get_post_meta( $post->ID, 'cb_hood', true ),
			'links' => json_decode( get_post_meta( $post->ID, 'cb_links', true ) ?: '[]', true ),
		];
	}
}
