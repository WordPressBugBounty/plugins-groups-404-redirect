<?php
/**
 * groups-404-redirect.php
 *
 * Copyright (c) 2013-2025 "kento" Karim Rahimpur www.itthinx.com
 *
 * This code is released under the GNU General Public License.
 * See COPYRIGHT.txt and LICENSE.txt.
 *
 * This code is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU General Public License for more details.
 *
 * This header and all notices must be kept intact.
 *
 * @author Karim Rahimpur
 * @package groups-404-redirect
 * @since groups-404-redirect 1.0.0
 *
 * Plugin Name: Groups 404 Redirect
 * Plugin URI: http://www.itthinx.com/plugins/groups
 * Description: Redirect 404's when a visitor tries to access a page protected by <a href="https://wordpress.org/plugins/groups/">Groups</a>.
 * Version: 1.9.0
 * Requires Plugins: groups
 * Author: itthinx
 * Author URI: https://www.itthinx.com
 * Donate-Link: https://www.itthinx.com
 * License: GPLv3
 */

define( 'GROUPS_404_REDIRECT_PLUGIN_DOMAIN', 'groups-404-redirect' );

/**
 * Redirection.
 */
class Groups_404_Redirect {

	/**
	 * Initialize hooks.
	 */
	public static function init() {
		// register_activation_hook(__FILE__, array( __CLASS__,'activate' ) );
		register_deactivation_hook(__FILE__,  array( __CLASS__,'deactivate' ) );
		add_action( 'wp', array( __CLASS__, 'wp' ) );
		add_action( 'parse_query', array( __CLASS__, 'parse_query' ) ); // @since 1.9.0
		add_action( 'admin_menu', array( __CLASS__, 'admin_menu' ), 11 );
		if ( is_admin() ) {
			add_filter( 'plugin_action_links_'. plugin_basename( __FILE__ ), array( __CLASS__, 'admin_settings_link' ) );
		}
	}

	/**
	 * Hooked on parse_query to get parameters early.
	 *
	 * Store values which will not be available in their current state when the wp action is triggered.
	 * We need those to check if we have to redirect on a protected term.
	 *
	 * @since 1.9.0
	 *
	 * @param WP_Query $wp_query
	 */
	public static function parse_query( $wp_query ) {
		global $groups_404_redirect;
		if ( $wp_query->is_main_query() ) {
			if ( !isset( $groups_404_redirect ) ) {
				$groups_404_redirect = array();
			}
			$groups_404_redirect['is_category'] = $wp_query->is_category;
			$groups_404_redirect['is_tag'] = $wp_query->is_tag;
			$groups_404_redirect['is_tax'] = $wp_query->is_tax;
			$groups_404_redirect['queried_object_id'] = $wp_query->get_queried_object_id();
			$groups_404_redirect['tax_query'] = $wp_query->tax_query;
		}
	}

	/**
	 * Nothing to do.
	 */
	public static function activate() {
	}

	/**
	 * Delete settings.
	 */
	public static function deactivate() {
		if ( self::groups_is_active() ) {
			Groups_Options::delete_option( 'groups-404-redirect-to' );
			Groups_Options::delete_option( 'groups-404-redirect-post-id' );
		}
	}

	/**
	 * Add the Settings > Groups 404 section.
	 */
	public static function admin_menu() {
		if ( defined( 'GROUPS_PLUGIN_DOMAIN' ) ) {
			add_submenu_page(
				'groups-admin',
				__( 'Groups 404 Redirect', GROUPS_PLUGIN_DOMAIN ),
				__( 'Groups 404', GROUPS_PLUGIN_DOMAIN ),
				GROUPS_ADMINISTER_OPTIONS,
				'groups-404-redirect',
				array( __CLASS__, 'settings' )
			);
		}
	}

	/**
	 * Adds plugin links.
	 *
	 * @param array $links
	 * @param array $links with additional links
	 */
	public static function admin_settings_link( $links ) {
		if ( defined( 'GROUPS_PLUGIN_DOMAIN' ) ) {
			$links[] = sprintf(
				'<a href="%s">%s</a>',
				esc_url( admin_url( 'admin.php?page=groups-404-redirect' ) ),
				esc_html( __( 'Settings', GROUPS_404_REDIRECT_PLUGIN_DOMAIN ) )
			);
		}
		return $links;
	}

	/**
	 * Admin settings.
	 */
	public static function settings() {

		if ( !current_user_can( GROUPS_ADMINISTER_OPTIONS ) ) {
			wp_die( __( 'Access denied.', GROUPS_404_REDIRECT_PLUGIN_DOMAIN ) );
		}

		if ( !self::groups_is_active() ) {
			echo '<p>';
			echo wp_kses_post( __( 'Please install and activate <a href="https://wordpress.org/plugins/groups/">Groups</a> to use this plugin.', GROUPS_404_REDIRECT_PLUGIN_DOMAIN ) );
			echo '</p>';
			return;
		}

		$http_status_codes = array(
			'301' => __( 'Moved Permanently', GROUPS_404_REDIRECT_PLUGIN_DOMAIN ),
			'302' => __( 'Found', GROUPS_404_REDIRECT_PLUGIN_DOMAIN ),
			'303' => __( 'See Other', GROUPS_404_REDIRECT_PLUGIN_DOMAIN ),
			'307' => __( 'Temporary Redirect', GROUPS_404_REDIRECT_PLUGIN_DOMAIN )
		);

		if ( isset( $_POST['action'] ) && ( $_POST['action'] == 'save' ) && wp_verify_nonce( $_POST['groups-404-redirect'], 'admin' ) ) {

			$redirect_to = 'post';
			if ( !empty( $_POST['redirect_to'] ) ) {
				switch( $_POST['redirect_to'] ) {
					case 'post' :
					case 'login' :
						Groups_Options::update_option( 'groups-404-redirect-to', $_POST['redirect_to'] );
						break;
				}
			}

			if ( !empty( $_POST['post_id'] ) ) {
				Groups_Options::update_option( 'groups-404-redirect-post-id', intval( $_POST['post_id'] ) );
			} else {
				Groups_Options::delete_option( 'groups-404-redirect-post-id' );
			}

			$post_param = !empty( $_POST['post_param'] ) ? preg_replace( '/[^a-zA-Z0-9_-]/', '', trim( $_POST['post_param'] ) ) : null;
			if ( !empty( $post_param ) ) {
				Groups_Options::update_option( 'groups-404-redirect-post-param', $post_param );
			} else {
				Groups_Options::delete_option( 'groups-404-redirect-post-param' );
			}

			Groups_Options::update_option( 'groups-404-redirect-restricted-terms', !empty( $_POST['redirect_restricted_terms'] ) );

			if ( key_exists( $_POST['status'], $http_status_codes ) ) {
				Groups_Options::update_option( 'groups-404-redirect-status', $_POST['status'] );
			}

			echo '<div class="updated">';
			echo '<p>';
			echo esc_html__( 'The settings have been saved.', GROUPS_404_REDIRECT_PLUGIN_DOMAIN );
			echo '</p>';
			echo '</div>';
		}

		$redirect_to     = Groups_Options::get_option( 'groups-404-redirect-to', 'post' );
		$post_id         = Groups_Options::get_option( 'groups-404-redirect-post-id', '' );
		$post_param      = Groups_Options::get_option( 'groups-404-redirect-post-param', '' );
		$redirect_status = Groups_Options::get_option( 'groups-404-redirect-status', '301' );
		$redirect_restricted_terms = Groups_Options::get_option( 'groups-404-redirect-restricted-terms', false );

		echo '<h1>';
		echo esc_html__( 'Groups 404 Redirect', GROUPS_404_REDIRECT_PLUGIN_DOMAIN );
		echo '</h1>';

		echo '<p>';
		echo esc_html__( 'Redirect settings when a visitor tries to access a page protected by Groups.', GROUPS_404_REDIRECT_PLUGIN_DOMAIN );
		echo '</p>';

		echo '<div class="settings" style="padding-right: 1em;">';
		echo '<form name="settings" method="post" action="">';
		echo '<div>';

		echo '<label>';
		echo sprintf( '<input type="radio" name="redirect_to" value="post" %s />', $redirect_to == 'post' ? ' checked="checked" ' : '' );
		echo ' ';
		echo esc_html__( 'Redirect to a page or post', GROUPS_404_REDIRECT_PLUGIN_DOMAIN );
		echo '</label>';

		echo '<div style="margin: 1em 0 0 2em">';

		echo '<label>';
		echo esc_html__( 'Page or Post ID', GROUPS_404_REDIRECT_PLUGIN_DOMAIN );
		echo ' ';
		echo sprintf( '<input type="text" name="post_id" value="%s" />', esc_attr( $post_id ) );
		echo '</label>';

		if ( !empty( $post_id ) ) {
			$post_title = get_the_title( $post_id );
			echo '<p>';
			echo esc_html__( 'Title:', GROUPS_404_REDIRECT_PLUGIN_DOMAIN );
			echo ' ';
			echo '<strong>';
			echo esc_html( $post_title );
			echo '</strong>';
			echo '</p>';
		}

		echo '<p class="description">';
		echo esc_html__( 'Indicate the ID of a page or a post to redirect to, leave it empty to redirect to the home page.', GROUPS_404_REDIRECT_PLUGIN_DOMAIN );
		echo '<br/>';
		echo esc_html__( 'The title of the page will be shown if a valid ID has been given.', GROUPS_404_REDIRECT_PLUGIN_DOMAIN );
		echo '</p>';
		echo '<p class="description">';
		echo wp_kses_post( __( 'If the <strong>Redirect to the WordPress login</strong> option is chosen instead, visitors who are logged in but may not access a requested page, can be redirected to a specific page by setting the Page or Post ID here.', GROUPS_404_REDIRECT_PLUGIN_DOMAIN ) );
		echo '</p>';

		echo '<label>';
		echo esc_html__( 'Parameter name', GROUPS_404_REDIRECT_PLUGIN_DOMAIN );
		echo ' ';
		echo sprintf( '<input type="text" name="post_param" value="%s" />', esc_attr( $post_param ) );
		echo '</label>';

		echo '<p class="description">';
		echo esc_html__( 'Indicate the parameter name which holds the requested URL before redirecting to a given page or post.', GROUPS_404_REDIRECT_PLUGIN_DOMAIN );
		echo ' ';
		echo esc_html__( 'This can be useful if you need the requested URL to be passed further on.', GROUPS_404_REDIRECT_PLUGIN_DOMAIN );
		echo '</p>';

		echo '</div>';

		echo '<br/>';

		echo '<label>';
		echo sprintf( '<input type="radio" name="redirect_to" value="login" %s />', $redirect_to == 'login' ? ' checked="checked" ' : '' );
		echo ' ';
		echo esc_html__( 'Redirect to the WordPress login', GROUPS_404_REDIRECT_PLUGIN_DOMAIN );
		echo '</label>';

		echo '<div style="margin: 1em 0 0 2em">';
		echo '<p class="description">';
		echo esc_html__( 'If the visitor is logged in but is not allowed to access the requested page, the visitor will be taken to the home page, or, if a Page or Post ID is set, to the page indicated above.', GROUPS_404_REDIRECT_PLUGIN_DOMAIN );
		echo '</p>';
		echo '</div>';

		echo '<br/>';

		echo '<label>';
		echo sprintf( '<input type="checkbox" name="redirect_restricted_terms" %s />', $redirect_restricted_terms ? ' checked="checked" ' : '' );
		echo ' ';
		echo esc_html__( 'Redirect restricted categories, tags and taxonomy terms &hellip;', GROUPS_404_REDIRECT_PLUGIN_DOMAIN );
		echo '</label>';

		echo '<div style="margin: 1em 0 0 2em">';
		echo '<p class="description">';
		echo esc_html__( 'If the visitor is not allowed to access the requested taxonomy term, including restricted categories and tags, the visitor will be redirected as indicated above.', GROUPS_404_REDIRECT_PLUGIN_DOMAIN );
		echo '</p>';
		echo '<p class="description">';
		echo wp_kses_post( __( 'This option will only take effect if <a href="https://www.itthinx.com/shop/groups-restrict-categories/">Groups Restrict Categories</a> is used.', GROUPS_404_REDIRECT_PLUGIN_DOMAIN ) );
		echo '</p>';
		echo '</div>';

		echo '<br/>';

		echo '<p>';
		echo '<label>';
		echo esc_html__( 'Redirect Status Code', GROUPS_404_REDIRECT_PLUGIN_DOMAIN );
		echo ' ';
		echo '<select name="status">';
		foreach ( $http_status_codes as $code => $name ) {
			echo '<option value="' . esc_attr( $code ) . '" ' . ( $redirect_status == $code ? ' selected="selected" ' : '' ) . '>' . esc_html( $name ) . ' (' . esc_html( $code ) . ')' . '</option>';
		}
		echo '</select>';
		echo '</label>';
		echo '</p>';

		echo '<p class="description">';
		echo wp_kses_post( __( '<a href="http://www.w3.org/Protocols/rfc2616/rfc2616.html">RFC 2616</a> provides details on <a href="http://www.w3.org/Protocols/rfc2616/rfc2616-sec10.html">Status Code Definitions</a>.', GROUPS_404_REDIRECT_PLUGIN_DOMAIN ) );
		echo '</p>';

		wp_nonce_field( 'admin', 'groups-404-redirect', true, true );

		echo '<br/>';

		echo '<div class="buttons">';
		echo sprintf( '<input class="create button button-primary" type="submit" name="submit" value="%s" />', esc_attr__( 'Save', GROUPS_404_REDIRECT_PLUGIN_DOMAIN ) );
		echo '<input type="hidden" name="action" value="save" />';
		echo '</div>';

		echo '</div>';
		echo '</form>';
		echo '</div>';
	}

	/**
	 * Handles redirection.
	 */
	public static function wp() {

		global $wp_query, $groups_404_redirect;

		$is_category = $groups_404_redirect['is_category'] ?? false;
		$is_tag = $groups_404_redirect['is_tag'] ?? false;
		$is_tax = $groups_404_redirect['is_tax'] ?? false;

		$term_ids = array();

		if ( !class_exists( 'Groups_Options' ) ) {
			return;
		}

		$redirect_restricted_terms = Groups_Options::get_option( 'groups-404-redirect-restricted-terms', false );

		$is_restricted_term = false;
		if ( $redirect_restricted_terms ) {
			if ( class_exists( 'Groups_Restrict_Categories' ) ) {
				$is_term = $wp_query->is_category || $wp_query->is_tag || $wp_query->is_tax || $is_category || $is_tag || $is_tax;
				if ( $is_term ) {
					$restricted_term_ids = Groups_Restrict_Categories::get_user_restricted_term_ids( get_current_user_id() );
					$term_id = $wp_query->get_queried_object_id();
					if ( $term_id ) {
						if ( in_array( $term_id, $restricted_term_ids ) ) {
							$is_restricted_term = true;
						}
					} else {
						// @since 1.9.0
						// Check if this is for a term which is not accessible and for which $wp_query->is_category || $wp_query->is_tag || $wp_query->is_tax would yield false
						// and for which $term_id = $wp_query->get_queried_object_id() would yield 0.
						if ( $wp_query->is_main_query() ) {
							if ( $is_category || $is_tag || $is_tax ) {
								$term_id = $wp_query->get_queried_object_id();
								if ( !$term_id ) {
									/**
									 * @var WP_Tax_Query $tax_query
									 */
									$tax_query = $groups_404_redirect['tax_query'];
									if ( $tax_query instanceof WP_Tax_Query ) {
										$priority = has_filter( 'list_terms_exclusions', array( 'Groups_Restrict_Categories', 'list_terms_exclusions' ) );
										if ( is_numeric( $priority ) ) {
											remove_filter( 'list_terms_exclusions', array( 'Groups_Restrict_Categories', 'list_terms_exclusions' ), $priority );
										}
										foreach ( $tax_query->queried_terms as $taxonomy => $items ) {
											if ( !empty( $items['terms'] ) ) {
												foreach ( $items['terms'] as $item_term ) {
													$term = get_term_by( $items['field'], $item_term, $taxonomy );
													if ( $term instanceof WP_Term ) {
														$term_ids[] = $term->term_id;
													}
												}
											}
										}
										if ( is_numeric( $priority ) ) {
											add_filter( 'list_terms_exclusions', array( 'Groups_Restrict_Categories', 'list_terms_exclusions' ), $priority, 3 );
										}
									}
								}
							}
						}
						if ( count( $term_ids ) > 0 ) {
							foreach ( $term_ids as $term_id ) {
								if ( in_array( $term_id, $restricted_term_ids ) ) {
									$is_restricted_term = true;
									break;
								}
							}
						}
					}
				}
			}
		}

		if ( $wp_query->is_404 || $is_restricted_term ) {
			if ( self::groups_is_active() ) {
				$redirect_to     = Groups_Options::get_option( 'groups-404-redirect-to', 'post' );
				$post_id         = Groups_Options::get_option( 'groups-404-redirect-post-id', '' );
				$post_param      = Groups_Options::get_option( 'groups-404-redirect-post-param', '' );
				$redirect_status = intval( Groups_Options::get_option( 'groups-404-redirect-status', '301' ) );

				$current_url = ( is_ssl() ? 'https://' : 'http://' ) . $_SERVER['HTTP_HOST'] . $_SERVER['REQUEST_URI'];

				$current_post_id = url_to_postid( $current_url );
				if ( !$current_post_id ) {
					$current_post_id = $wp_query->get_queried_object_id();
				}
				if ( !$current_post_id ) {
					require_once 'groups-404-url-to-postid.php';
					$current_post_id = groups_404_url_to_postid( $current_url );
				}

				$redirect_to = apply_filters( 'groups_404_redirect_redirect_to', $redirect_to, $current_post_id, $current_url );
				$post_param  = apply_filters( 'groups_404_redirect_post_param', $post_param, $current_post_id, $current_url );
				$redirect_status = apply_filters( 'groups_404_redirect_redirect_status', $redirect_status, $current_post_id, $current_url );

				if (
					$current_post_id ||
					$is_restricted_term // @since 1.9.0
				) {

					$is_restricted_by_term = false;
					if ( class_exists( 'Groups_Restrict_Categories' ) && method_exists( 'Groups_Restrict_Categories', 'user_can_read' ) ) {
						$is_restricted_by_term = !Groups_Restrict_Categories::user_can_read( $current_post_id );
					}

					$user_can_read_post_legacy = true;
					$legacy_enable = !defined( 'GROUPS_LEGACY_ENABLE' ) || Groups_Options::get_option( GROUPS_LEGACY_ENABLE, GROUPS_LEGACY_ENABLE_DEFAULT );
					if ( $legacy_enable ) {
						if ( defined( 'GROUPS_LEGACY_LIB' ) ) {
							require_once GROUPS_LEGACY_LIB . '/access/class-groups-post-access-legacy.php';
							if ( !Groups_Post_Access_Legacy::user_can_read_post( $current_post_id, get_current_user_id() ) ) {
								$user_can_read_post_legacy = false;
							}
						}
					}

					if ( !$user_can_read_post_legacy || !Groups_Post_Access::user_can_read_post( $current_post_id, get_current_user_id() ) || $is_restricted_by_term || $is_restricted_term ) {

						switch( $redirect_to ) {
							case 'login' :
								if ( !is_user_logged_in() ) {
									wp_redirect( wp_login_url( $current_url ), $redirect_status );
									exit;
								} else {
									// If the user is already logged in, we can't
									// redirect to the WordPress login again,
									// we either send them to the home page, or
									// to the page indicated in the settings.
									if ( empty( $post_id ) ) {
										wp_redirect( get_home_url(), $redirect_status );
									} else {
										$post_id = apply_filters( 'groups_404_redirect_post_id', $post_id, $current_post_id, $current_url );
										if ( $post_id != $current_post_id ) {
											wp_redirect( get_permalink( $post_id ), $redirect_status );
										} else {
											return;
										}
									}
									exit;
								}

							default: // 'post'
								if ( empty( $post_id ) ) {
									$redirect_url = get_home_url();
								} else {
									$post_id = apply_filters( 'groups_404_redirect_post_id', $post_id, $current_post_id, $current_url );
									if ( $post_id != $current_post_id ) {
										$redirect_url = get_permalink( $post_id );
									} else {
										return;
									}
								}
								if ( !empty( $post_param ) ) {
									$redirect_url = add_query_arg( $post_param, urlencode( $current_url ), $redirect_url );
								}
								wp_redirect( $redirect_url, $redirect_status );
								exit;

						}
					}
				}
			}
		}
	}

	/**
	 * Returns true if the Groups plugin is active.
	 *
	 * @return boolean true if Groups is active
	 */
	private static function groups_is_active() {
		$active_plugins = get_option( 'active_plugins', array() );
		if ( is_multisite() ) {
			$active_sitewide_plugins = get_site_option( 'active_sitewide_plugins', array() );
			$active_sitewide_plugins = array_keys( $active_sitewide_plugins );
			$active_plugins = array_merge( $active_plugins, $active_sitewide_plugins );
		}
		return in_array( 'groups/groups.php', $active_plugins );
	}
}
Groups_404_Redirect::init();
