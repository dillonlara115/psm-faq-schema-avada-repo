<?php
/**
 * Plugin Name:       PSM FAQ Schema (Avada)
 * Plugin URI:        https://pointsourcemarketing.com/tools/faq-schema
 * Description:       Auto-injects FAQPage JSON-LD by parsing Avada accordion shortcodes marked with the "faq-accordion" CSS class. Self-updates from the PSM update endpoint.
 * Version:           1.0.0
 * Author:            Point Source Marketing
 * Author URI:        https://pointsourcemarketing.com
 * Requires PHP:      7.4
 * Requires at least: 5.8
 * License:           Proprietary
 * Update URI:        https://github.com/point-source-marketing/psm-faq-schema-avada
 *
 * Workflow:
 *   1. Content team marks Avada accordion with CSS Class "faq-accordion".
 *   2. Authors Q&A as fusion_toggle items inside it.
 *   3. Plugin renders FAQPage JSON-LD in <head> on next pageview.
 *
 * Updates:
 *   This plugin checks UPDATE_ENDPOINT (constant below) for new versions.
 *   See README-host.md for what to deploy to that endpoint.
 *
 * Filters:
 *   psm_faq_schema_enabled       (bool, WP_Post)   - false to skip injection
 *   psm_faq_schema_class_marker  (string)          - default 'faq-accordion'
 *   psm_faq_schema_min_count     (int)             - default 2
 *   psm_faq_schema_data          (array, WP_Post)  - mutate before encoding
 *   psm_faq_schema_debug         (bool)            - emit detection HTML comment
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( class_exists( 'PSM_FAQ_Schema_Avada_Plugin' ) ) {
	return;
}

final class PSM_FAQ_Schema_Avada_Plugin {

	const VERSION     = '1.0.0';
	const SLUG        = 'psm-faq-schema-avada';
	const SCHEMA_TAG  = 'psm-faq-schema';
	const GITHUB_REPO = 'point-source-marketing/psm-faq-schema-avada';   // <-- EDIT to match your repo
	const UPDATE_TTL  = 12 * HOUR_IN_SECONDS;

	public static function boot() {
		add_action( 'wp_head', [ __CLASS__, 'inject' ], 99 );

		// Self-updater hooks
		add_filter( 'pre_set_site_transient_update_plugins', [ __CLASS__, 'check_for_update' ] );
		add_filter( 'plugins_api', [ __CLASS__, 'plugin_info' ], 10, 3 );
		add_action( 'upgrader_process_complete', [ __CLASS__, 'clear_update_cache' ], 10, 2 );
	}

	/* ============================================================
	 *  FAQ schema generation
	 * ============================================================ */

	public static function inject() {
		if ( ! is_singular() ) {
			return;
		}

		$post = get_post();
		if ( ! $post || empty( $post->post_content ) ) {
			return;
		}

		if ( ! apply_filters( 'psm_faq_schema_enabled', true, $post ) ) {
			return;
		}

		$marker = (string) apply_filters( 'psm_faq_schema_class_marker', 'faq-accordion' );
		$min    = (int) apply_filters( 'psm_faq_schema_min_count', 2 );
		$debug  = (bool) apply_filters( 'psm_faq_schema_debug', false );

		$faqs = self::extract_faqs( $post->post_content, $marker );
		$faqs = apply_filters( 'psm_faq_schema_data', $faqs, $post );

		if ( $debug ) {
			printf(
				"\n<!-- %s v%s | marker=%s | found=%d -->\n",
				esc_html( self::SCHEMA_TAG ),
				esc_html( self::VERSION ),
				esc_html( $marker ),
				count( $faqs )
			);
		}

		if ( count( $faqs ) < $min ) {
			return;
		}

		$schema = [
			'@context'   => 'https://schema.org',
			'@type'      => 'FAQPage',
			'mainEntity' => array_map(
				static function ( $pair ) {
					return [
						'@type'          => 'Question',
						'name'           => $pair['q'],
						'acceptedAnswer' => [
							'@type' => 'Answer',
							'text'  => $pair['a'],
						],
					];
				},
				$faqs
			),
		];

		$json = wp_json_encode( $schema, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
		if ( false === $json ) {
			return;
		}

		printf(
			"\n<script type=\"application/ld+json\" data-source=\"%s\">%s</script>\n",
			esc_attr( self::SCHEMA_TAG ),
			$json
		);
	}

	private static function extract_faqs( $content, $marker ) {
		$faqs           = [];
		$marker_escaped = preg_quote( $marker, '/' );
		$accordion_re   = '/\[fusion_accordion\b[^\]]*\bclass\s*=\s*["\'][^"\']*\b' . $marker_escaped . '\b[^"\']*["\'][^\]]*\](.*?)\[\/fusion_accordion\]/is';

		if ( ! preg_match_all( $accordion_re, $content, $accordion_matches ) ) {
			return $faqs;
		}

		foreach ( $accordion_matches[1] as $accordion_inner ) {
			$toggle_re = '/\[fusion_toggle\s+([^\]]+)\](.*?)\[\/fusion_toggle\]/is';
			if ( ! preg_match_all( $toggle_re, $accordion_inner, $toggle_matches ) ) {
				continue;
			}

			$count = count( $toggle_matches[0] );
			for ( $i = 0; $i < $count; $i++ ) {
				$attrs = shortcode_parse_atts( $toggle_matches[1][ $i ] );
				$title = isset( $attrs['title'] ) ? (string) $attrs['title'] : '';
				$body  = $toggle_matches[2][ $i ];

				$body  = do_shortcode( $body );
				$body  = wp_strip_all_tags( $body );
				$body  = preg_replace( '/\s+/', ' ', $body );
				$body  = trim( html_entity_decode( $body, ENT_QUOTES | ENT_HTML5, 'UTF-8' ) );
				$title = trim( html_entity_decode( $title, ENT_QUOTES | ENT_HTML5, 'UTF-8' ) );

				if ( $title !== '' && $body !== '' ) {
					$faqs[] = [ 'q' => $title, 'a' => $body ];
				}
			}
		}

		return $faqs;
	}

	/* ============================================================
	 *  Self-updater (GitHub Releases)
	 *  Queries https://api.github.com/repos/<owner>/<repo>/releases/latest
	 *  Expects a release whose tag is a semver string (with or without 'v'
	 *  prefix) and that has the plugin zip attached as a release asset.
	 *  Release notes (the body) become the changelog shown in WP's
	 *  "View details" popup.
	 * ============================================================ */

	private static function plugin_basename() {
		return self::SLUG . '/' . self::SLUG . '.php';
	}

	private static function github_api_url() {
		return 'https://api.github.com/repos/' . self::GITHUB_REPO . '/releases/latest';
	}

	/**
	 * Find the plugin zip among a release's assets. Prefers an asset named
	 * exactly {slug}.zip, then any zip whose name starts with {slug},
	 * then the first zip available.
	 */
	private static function pick_zip_asset( $assets ) {
		if ( empty( $assets ) || ! is_array( $assets ) ) {
			return '';
		}
		$exact   = '';
		$prefix  = '';
		$any_zip = '';
		foreach ( $assets as $a ) {
			$name = $a['name'] ?? '';
			$url  = $a['browser_download_url'] ?? '';
			if ( ! $url || substr( $name, -4 ) !== '.zip' ) {
				continue;
			}
			if ( $name === self::SLUG . '.zip' ) {
				$exact = $url;
			} elseif ( ! $prefix && strpos( $name, self::SLUG ) === 0 ) {
				$prefix = $url;
			} elseif ( ! $any_zip ) {
				$any_zip = $url;
			}
		}
		return $exact ?: ( $prefix ?: $any_zip );
	}

	private static function get_remote_info() {
		$cached = get_site_transient( 'psm_faq_update_info' );
		if ( false !== $cached ) {
			return $cached;
		}

		$response = wp_remote_get(
			self::github_api_url(),
			[
				'timeout' => 10,
				'headers' => [
					'Accept'     => 'application/vnd.github+json',
					'User-Agent' => 'PSM-FAQ-Schema-Avada/' . self::VERSION,
				],
			]
		);

		if ( is_wp_error( $response ) || 200 !== wp_remote_retrieve_response_code( $response ) ) {
			set_site_transient( 'psm_faq_update_info', [], HOUR_IN_SECONDS );
			return [];
		}

		$release = json_decode( wp_remote_retrieve_body( $response ), true );
		if ( ! is_array( $release ) || empty( $release['tag_name'] ) ) {
			set_site_transient( 'psm_faq_update_info', [], HOUR_IN_SECONDS );
			return [];
		}

		$version  = ltrim( $release['tag_name'], 'vV' );
		$zip_url  = self::pick_zip_asset( $release['assets'] ?? [] );

		// If a release has no attached zip asset, we can't update.
		if ( empty( $zip_url ) ) {
			set_site_transient( 'psm_faq_update_info', [], HOUR_IN_SECONDS );
			return [];
		}

		$info = [
			'version'      => $version,
			'download_url' => $zip_url,
			'homepage'     => $release['html_url'] ?? ( 'https://github.com/' . self::GITHUB_REPO ),
			'tested'       => '',
			'requires'     => '5.8',
			'requires_php' => '7.4',
			'sections'     => [
				'description' => 'Auto-injects FAQPage JSON-LD for Avada accordions tagged with the "faq-accordion" CSS class.',
				'changelog'   => ! empty( $release['body'] ) ? wp_kses_post( $release['body'] ) : '',
			],
		];

		set_site_transient( 'psm_faq_update_info', $info, self::UPDATE_TTL );
		return $info;
	}

	public static function check_for_update( $transient ) {
		if ( empty( $transient->checked ) ) {
			return $transient;
		}

		$info = self::get_remote_info();
		if ( empty( $info ) ) {
			return $transient;
		}

		if ( version_compare( $info['version'], self::VERSION, '>' ) ) {
			$transient->response[ self::plugin_basename() ] = (object) [
				'id'           => self::plugin_basename(),
				'slug'         => self::SLUG,
				'plugin'       => self::plugin_basename(),
				'new_version'  => $info['version'],
				'url'          => $info['homepage'] ?? '',
				'package'      => $info['download_url'],
				'tested'       => $info['tested'] ?? '',
				'requires_php' => $info['requires_php'] ?? '7.4',
				'icons'        => [],
				'banners'      => [],
			];
		} else {
			$transient->no_update[ self::plugin_basename() ] = (object) [
				'id'           => self::plugin_basename(),
				'slug'         => self::SLUG,
				'plugin'       => self::plugin_basename(),
				'new_version'  => self::VERSION,
				'url'          => $info['homepage'] ?? '',
				'package'      => '',
				'requires_php' => $info['requires_php'] ?? '7.4',
			];
		}

		return $transient;
	}

	public static function plugin_info( $result, $action, $args ) {
		if ( 'plugin_information' !== $action || empty( $args->slug ) || self::SLUG !== $args->slug ) {
			return $result;
		}

		$info = self::get_remote_info();
		if ( empty( $info ) ) {
			return $result;
		}

		return (object) [
			'name'          => 'PSM FAQ Schema (Avada)',
			'slug'          => self::SLUG,
			'version'       => $info['version'],
			'author'        => '<a href="https://pointsourcemarketing.com">Point Source Marketing</a>',
			'homepage'      => $info['homepage'] ?? '',
			'requires'      => $info['requires'] ?? '5.8',
			'tested'        => $info['tested'] ?? '',
			'requires_php'  => $info['requires_php'] ?? '7.4',
			'download_link' => $info['download_url'],
			'sections'      => $info['sections'] ?? [
				'description' => 'Auto-injects FAQPage JSON-LD for Avada accordions tagged with the "faq-accordion" CSS class.',
			],
		];
	}

	public static function clear_update_cache( $upgrader, $options ) {
		if ( empty( $options['type'] ) || 'plugin' !== $options['type'] ) {
			return;
		}
		if ( empty( $options['action'] ) || 'update' !== $options['action'] ) {
			return;
		}
		delete_site_transient( 'psm_faq_update_info' );
	}
}

PSM_FAQ_Schema_Avada_Plugin::boot();
