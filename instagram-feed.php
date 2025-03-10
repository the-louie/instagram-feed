<?php
/*
Plugin Name: Smash Balloon Instagram Feed
Plugin URI: https://smashballoon.com/instagram-feed
Description: Display beautifully clean, customizable, and responsive Instagram feeds.
Version: 2.1.3
Author: Smash Balloon
Author URI: https://smashballoon.com/
License: GPLv2 or later
Text Domain: instagram-feed

Copyright 2019  Smash Balloon LLC (email : hey@smashballoon.com)
This program is free software; you can redistribute it and/or modify
it under the terms of the GNU General Public License as published by
the Free Software Foundation; either version 2 of the License, or
(at your option) any later version.
This program is distributed in the hope that it will be useful,
but WITHOUT ANY WARRANTY; without even the implied warranty of
MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
GNU General Public License for more details.
You should have received a copy of the GNU General Public License
along with this program; if not, write to the Free Software
Foundation, Inc., 51 Franklin St, Fifth Floor, Boston, MA  02110-1301  USA
*/
if ( ! defined( 'SBIVER' ) ) {
	define( 'SBIVER', '2.1.3' );
}
// Db version.
if ( ! defined( 'SBI_DBVERSION' ) ) {
	define( 'SBI_DBVERSION', '1.3' );
}

// Upload folder name for local image files for posts
if ( ! defined( 'SBI_UPLOADS_NAME' ) ) {
	define( 'SBI_UPLOADS_NAME', 'sb-instagram-feed-images' );
}
// Name of the database table that contains instagram posts
if ( ! defined( 'SBI_INSTAGRAM_POSTS_TYPE' ) ) {
	define( 'SBI_INSTAGRAM_POSTS_TYPE', 'sbi_instagram_posts' );
}
// Name of the database table that contains feed ids and the ids of posts
if ( ! defined( 'SBI_INSTAGRAM_FEEDS_POSTS' ) ) {
	define( 'SBI_INSTAGRAM_FEEDS_POSTS', 'sbi_instagram_feeds_posts' );
}

if ( ! defined( 'ABSPATH' ) ) exit; // Exit if accessed directly
if ( function_exists( 'sb_instagram_feed_init' ) ) {
	wp_die( "Please deactivate Custom Feeds for Instagram Pro before activating this version.<br /><br />Back to the WordPress <a href='".get_admin_url(null, 'plugins.php')."'>Plugins page</a>." );
} else {
	/**
	 * Define constants and load plugin files
	 *
	 * @since  2.0
	 */
	function sb_instagram_feed_init() {
		// Plugin Folder Path.
		if ( ! defined( 'SBI_PLUGIN_DIR' ) ) {
			define( 'SBI_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
		}
		// Plugin Folder URL.
		if ( ! defined( 'SBI_PLUGIN_URL' ) ) {
			define( 'SBI_PLUGIN_URL', plugin_dir_url( __FILE__ ) );
		}
		// Plugin Base Name
		if ( ! defined( 'SBI_PLUGIN_BASENAME' ) ) {
			define( 'SBI_PLUGIN_BASENAME', plugin_basename( __FILE__ ) );
		}
		// Plugin Base Name
		if ( ! defined( 'SBI_BACKUP_PREFIX' ) ) {
			define( 'SBI_BACKUP_PREFIX', '!' );
		}
		// Plugin Base Name
		if ( ! defined( 'SBI_FPL_PREFIX' ) ) {
			define( 'SBI_FPL_PREFIX', '$' );
		}
		// Plugin Base Name
		if ( ! defined( 'SBI_USE_BACKUP_PREFIX' ) ) {
			define( 'SBI_USE_BACKUP_PREFIX', '&' );
		}
		// Cron Updating Cache Time 60 days
		if ( ! defined( 'SBI_CRON_UPDATE_CACHE_TIME' ) ) {
			define( 'SBI_CRON_UPDATE_CACHE_TIME', 60 * 60 * 24 * 60 );
		}
		// Max Records in Database for Image Resizing
		if ( ! defined( 'SBI_MAX_RECORDS' ) ) {
			define( 'SBI_MAX_RECORDS', 100 );
		}

		require_once trailingslashit( SBI_PLUGIN_DIR ) . 'inc/if-functions.php';
		require_once trailingslashit( SBI_PLUGIN_DIR ) . 'inc/class-sb-instagram-api-connect.php';
		require_once trailingslashit( SBI_PLUGIN_DIR ) . 'inc/class-sb-instagram-cron-updater.php';
		require_once trailingslashit( SBI_PLUGIN_DIR ) . 'inc/class-sb-instagram-display-elements.php';
		require_once trailingslashit( SBI_PLUGIN_DIR ) . 'inc/class-sb-instagram-feed.php';
		require_once trailingslashit( SBI_PLUGIN_DIR ) . 'inc/class-sb-instagram-parse.php';
		require_once trailingslashit( SBI_PLUGIN_DIR ) . 'inc/class-sb-instagram-post.php';
		require_once trailingslashit( SBI_PLUGIN_DIR ) . 'inc/class-sb-instagram-post-set.php';
		require_once trailingslashit( SBI_PLUGIN_DIR ) . 'inc/class-sb-instagram-posts-manager.php';
		require_once trailingslashit( SBI_PLUGIN_DIR ) . 'inc/class-sb-instagram-settings.php';

		if ( is_admin() ) {
			require_once trailingslashit( SBI_PLUGIN_DIR ) . 'inc/admin/actions.php';
			require_once trailingslashit( SBI_PLUGIN_DIR ) . 'inc/admin/main.php';
		}

		global $sb_instagram_posts_manager;
		$sb_instagram_posts_manager = new SB_Instagram_Posts_Manager();
	}

	add_action( 'plugins_loaded', 'sb_instagram_feed_init' );

	/**
	 * Add the custom interval of 30 minutes for cron caching
	 *
	 * @param  array $schedules current list of cron intervals
	 *
	 * @return array
	 *
	 * @since  2.0
	 */
	function sbi_cron_custom_interval( $schedules ) {
		$schedules['sbi30mins'] = array(
			'interval' => 30 * 60,
			'display'  => __( 'Every 30 minutes' )
		);

		return $schedules;
	}

	add_filter( 'cron_schedules', 'sbi_cron_custom_interval' );

	/**
	 * Create database tables, schedule cron events, initiate capabilities
	 *
	 * @param  bool $network_wide is a multisite network activation
	 *
	 * @since  2.0 database tables and capabilties created
	 * @since  1.0
	 */
	function sb_instagram_activate( $network_wide ) {
		//Clear page caching plugins and autoptomize
		if ( is_callable( 'sb_instagram_clear_page_caches' ) ) {
			sb_instagram_clear_page_caches();
		}

		//Run cron twice daily when plugin is first activated for new users
		if ( ! wp_next_scheduled( 'sb_instagram_cron_job' ) ) {
			wp_schedule_event( time(), 'twicedaily', 'sb_instagram_cron_job' );
		}

		$sbi_settings = get_option( 'sb_instagram_settings', array() );
		if ( isset( $sbi_settings['sbi_caching_type'] ) && $sbi_settings['sbi_caching_type'] === 'background' ) {
			require_once trailingslashit( plugin_dir_path( __FILE__ ) ) . 'inc/if-functions.php';
			require_once trailingslashit( plugin_dir_path( __FILE__ ) ) . 'inc/class-sb-instagram-cron-updater.php';
			SB_Instagram_Cron_Updater::start_cron_job( $sbi_settings['sbi_cache_cron_interval'], $sbi_settings['sbi_cache_cron_time'], $sbi_settings['sbi_cache_cron_am_pm'] );
		}

		require_once( trailingslashit( dirname( __FILE__ ) ) . 'inc/class-sb-instagram-posts-manager.php' );
		$sb_instagram_posts_manager = new SB_Instagram_Posts_Manager();

		if ( is_multisite() && $network_wide && function_exists( 'get_sites' ) && class_exists( 'WP_Site_Query' ) ) {

			// Get all blogs in the network and activate plugin on each one
			$sites = get_sites();
			foreach ( $sites as $site ) {
				switch_to_blog( $site->blog_id );

				$upload     = wp_upload_dir();
				$upload_dir = $upload['basedir'];
				$upload_dir = trailingslashit( $upload_dir ) . SBI_UPLOADS_NAME;
				if ( ! file_exists( $upload_dir ) ) {
					$created = wp_mkdir_p( $upload_dir );
					if ( $created ) {
						$sb_instagram_posts_manager->remove_error( 'upload_dir' );
					} else {
						$sb_instagram_posts_manager->add_error( 'upload_dir', array(
							__( 'There was an error creating the folder for storing resized images.', 'instagram-feed' ),
							$upload_dir
						) );
					}
				} else {
					$sb_instagram_posts_manager->remove_error( 'upload_dir' );
				}

				sbi_create_database_table();
				restore_current_blog();
			}

		} else {
			$upload     = wp_upload_dir();
			$upload_dir = $upload['basedir'];
			$upload_dir = trailingslashit( $upload_dir ) . SBI_UPLOADS_NAME;
			if ( ! file_exists( $upload_dir ) ) {
				$created = wp_mkdir_p( $upload_dir );
				if ( $created ) {
					$sb_instagram_posts_manager->remove_error( 'upload_dir' );
				} else {
					$sb_instagram_posts_manager->add_error( 'upload_dir', array(
						__( 'There was an error creating the folder for storing resized images.', 'instagram-feed' ),
						$upload_dir
					) );
				}
			} else {
				$sb_instagram_posts_manager->remove_error( 'upload_dir' );
			}

			sbi_create_database_table();
		}

		global $wp_roles;
		$wp_roles->add_cap( 'administrator', 'manage_instagram_feed_options' );
	}

	register_activation_hook( __FILE__, 'sb_instagram_activate' );

	/**
	 * Stop cron events when deactivated
	 *
	 * @since  1.0
	 */
	function sb_instagram_deactivate() {
		wp_clear_scheduled_hook( 'sb_instagram_cron_job' );
		wp_clear_scheduled_hook( 'sbi_feed_update' );
	}

	register_deactivation_hook( __FILE__, 'sb_instagram_deactivate' );

	/**
	 * Creates custom database tables and directory for storing custom
	 * images
	 *
	 * @since  2.0
	 */
	function sbi_create_database_table() {
		global $wpdb;
		global $sb_instagram_posts_manager;

		if ( ! isset( $sb_instagram_posts_manager ) ) {
			require_once( trailingslashit( dirname( __FILE__ ) ) . 'inc/class-sb-instagram-posts-manager.php' );
			$sb_instagram_posts_manager = new SB_Instagram_Posts_Manager();
		}

		global $wp_version;

		if ( version_compare( $wp_version, '3.5', '<' ) ) {
			$table_name = esc_sql( $wpdb->prefix . SBI_INSTAGRAM_POSTS_TYPE );

			if ( $wpdb->get_var( "show tables like '$table_name'" ) != $table_name ) {
				$sql = "CREATE TABLE " . $table_name . " (
                id INT(11) UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
                created_on DATETIME,
                instagram_id VARCHAR(1000) DEFAULT '' NOT NULL,
                time_stamp DATETIME,
                top_time_stamp DATETIME,
                json_data LONGTEXT DEFAULT '' NOT NULL,
                media_id VARCHAR(1000) DEFAULT '' NOT NULL,
                sizes VARCHAR(1000) DEFAULT '' NOT NULL,
                aspect_ratio DECIMAL (4,2) DEFAULT 0 NOT NULL,
                images_done TINYINT(1) DEFAULT 0 NOT NULL,
                last_requested DATE
            );";
				$wpdb->query( $sql );
			}

			$feeds_posts_table_name = esc_sql( $wpdb->prefix . SBI_INSTAGRAM_FEEDS_POSTS );

			if ( $wpdb->get_var( "show tables like '$feeds_posts_table_name'" ) != $feeds_posts_table_name ) {
				$sql = "CREATE TABLE " . $feeds_posts_table_name . " (
				record_id INT(11) UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
                id INT(11) UNSIGNED NOT NULL,
                instagram_id VARCHAR(1000) DEFAULT '' NOT NULL,
                feed_id VARCHAR(1000) DEFAULT '' NOT NULL,
                INDEX feed_id (feed_id(100))
            );";
				$wpdb->query( $sql );
			}

			return;
		} else {
			$charset_collate = $wpdb->get_charset_collate();
			$table_name      = esc_sql( $wpdb->prefix . SBI_INSTAGRAM_POSTS_TYPE );

			if ( $wpdb->get_var( "show tables like '$table_name'" ) != $table_name ) {
				$sql = "CREATE TABLE " . $table_name . " (
                id INT(11) UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
                created_on DATETIME,
                instagram_id VARCHAR(1000) DEFAULT '' NOT NULL,
                time_stamp DATETIME,
                top_time_stamp DATETIME,
                json_data LONGTEXT DEFAULT '' NOT NULL,
                media_id VARCHAR(1000) DEFAULT '' NOT NULL,
                sizes VARCHAR(1000) DEFAULT '' NOT NULL,
                aspect_ratio DECIMAL (4,2) DEFAULT 0 NOT NULL,
                images_done TINYINT(1) DEFAULT 0 NOT NULL,
                last_requested DATE
            ) $charset_collate;";
				$wpdb->query( $sql );
			}
			$error = $wpdb->last_error;
			$query = $wpdb->last_query;

			if ( $wpdb->get_var( "show tables like '$table_name'" ) != $table_name ) {
				$sb_instagram_posts_manager->add_error( 'database_create_posts', array(
					__( 'There was an error when trying to create the database tables used for resizing images.', 'instagram-feed' ),
					$error . '<br><code>' . $query . '</code>'
				) );
			} else {
				$sb_instagram_posts_manager->remove_error( 'database_create_posts' );
			}

			$feeds_posts_table_name = esc_sql( $wpdb->prefix . SBI_INSTAGRAM_FEEDS_POSTS );

			if ( $wpdb->get_var( "show tables like '$feeds_posts_table_name'" ) != $feeds_posts_table_name ) {
				$sql = "CREATE TABLE " . $feeds_posts_table_name . " (
				record_id INT(11) UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
                id INT(11) UNSIGNED NOT NULL,
                instagram_id VARCHAR(1000) DEFAULT '' NOT NULL,
                feed_id VARCHAR(1000) DEFAULT '' NOT NULL,
                INDEX feed_id (feed_id(100))
            ) $charset_collate;";
				$wpdb->query( $sql );
			}
			$error = $wpdb->last_error;
			$query = $wpdb->last_query;

			if ( $wpdb->get_var( "show tables like '$feeds_posts_table_name'" ) != $feeds_posts_table_name ) {
				$sb_instagram_posts_manager->add_error( 'database_create_posts_feeds', array(
					__( 'There was an error when trying to create the database tables used for resizing images.', 'instagram-feed' ),
					$error . '<br><code>' . $query . '</code>'
				) );
			} else {
				$sb_instagram_posts_manager->remove_error( 'database_create_posts_feeds' );
			}
		}
	}

	/**
	 * Compares previous plugin version and updates database related
	 * items as needed
	 *
	 * @since  2.0
	 */
	function sbi_check_for_db_updates() {

		$db_ver = get_option( 'sbi_db_version', 0 );

		if ( (float) $db_ver < 1.2 ) {

			$upload     = wp_upload_dir();
			$upload_dir = $upload['basedir'];
			$upload_dir = trailingslashit( $upload_dir ) . SBI_UPLOADS_NAME;
			if ( ! file_exists( $upload_dir ) ) {
				$created = wp_mkdir_p( $upload_dir );
			}

			sbi_create_database_table();

			global $wp_roles;
			$wp_roles->add_cap( 'administrator', 'manage_instagram_feed_options' );

			//Delete all transients
			global $wpdb;
			$table_name = $wpdb->prefix . "options";
			$wpdb->query( "
		        DELETE
		        FROM $table_name
		        WHERE `option_name` LIKE ('%\_transient\_sbi\_%')
		        " );
					$wpdb->query( "
		        DELETE
		        FROM $table_name
		        WHERE `option_name` LIKE ('%\_transient\_timeout\_sbi\_%')
		        " );
					$wpdb->query( "
		        DELETE
		        FROM $table_name
		        WHERE `option_name` LIKE ('%\_transient\_&sbi\_%')
		        " );
					$wpdb->query( "
		        DELETE
		        FROM $table_name
		        WHERE `option_name` LIKE ('%\_transient\_timeout\_&sbi\_%')
		        " );
					$wpdb->query( "
		        DELETE
		        FROM $table_name
		        WHERE `option_name` LIKE ('%\_transient\_\$sbi\_%')
		        " );
					$wpdb->query( "
		        DELETE
		        FROM $table_name
		        WHERE `option_name` LIKE ('%\_transient\_timeout\_\$sbi\_%')
            " );

			$sbi_statuses_option = get_option( 'sbi_statuses', array() );

			if ( ! isset( $sbi_statuses_option['first_install'] ) ) {

				$options_set = get_option( 'sb_instagram_settings', false );

				if ( $options_set ) {
					$sbi_statuses_option['first_install'] = 'from_update';
				} else {
					$sbi_statuses_option['first_install'] = time();
				}

				$sbi_rating_notice_option = get_option( 'sbi_rating_notice', false );

				if ( $sbi_rating_notice_option === 'dismissed' ) {
					$sbi_statuses_option['rating_notice_dismissed'] = time();
				}

				$sbi_rating_notice_waiting = get_transient( 'instagram_feed_rating_notice_waiting' );

				if ( $sbi_rating_notice_waiting === false
				     && $sbi_rating_notice_option === false ) {
					$time = 2 * WEEK_IN_SECONDS;
					set_transient( 'instagram_feed_rating_notice_waiting', 'waiting', $time );
					update_option( 'sbi_rating_notice', 'pending', false );
				}

				update_option( 'sbi_statuses', $sbi_statuses_option, false );

				if ( is_callable( 'sb_instagram_clear_page_caches' ) ) {
					sb_instagram_clear_page_caches();
				}

			}

			update_option( 'sbi_db_version', SBI_DBVERSION );
		}

		if ( (float) $db_ver < 1.3 ) {
			// removed code that was giving a one week waiting period before notice appeared

			update_option( 'sbi_db_version', SBI_DBVERSION );
		}

	}

	add_action( 'wp_loaded', 'sbi_check_for_db_updates' );

	/**
	 * Deletes saved data for the plugin unless setting to preserve
	 * settings is enabled
	 *
	 * @since  2.0 custom tables, custom images, and image directory deleted
	 * @since  1.0
	 */
	function sb_instagram_uninstall() {
		if ( ! current_user_can( 'activate_plugins' ) ) {
			return;
		}

		//If the user is preserving the settings then don't delete them
		$options                        = get_option( 'sb_instagram_settings' );
		$sb_instagram_preserve_settings = $options['sb_instagram_preserve_settings'];
		if ( $sb_instagram_preserve_settings ) {
			return;
		}

		//Settings
		delete_option( 'sb_instagram_settings' );
		delete_option( 'sbi_ver' );
		delete_option( 'sbi_db_version' );
		delete_option( 'sb_expired_tokens' );
		delete_option( 'sbi_cron_report' );
		delete_option( 'sb_instagram_errors' );
		delete_option( 'sb_instagram_ajax_status' );
		delete_option( 'sbi_statuses' );

		// Clear backup caches
		global $wpdb;
		$table_name = $wpdb->prefix . "options";
		$wpdb->query( "
        DELETE
        FROM $table_name
        WHERE `option_name` LIKE ('%!sbi\_%')
        " );
		$wpdb->query( "
        DELETE
        FROM $table_name
        WHERE `option_name` LIKE ('%\_transient\_&sbi\_%')
        " );
		$wpdb->query( "
        DELETE
        FROM $table_name
        WHERE `option_name` LIKE ('%\_transient\_timeout\_&sbi\_%')
        " );
		$wpdb->query( "
    DELETE
    FROM $table_name
    WHERE `option_name` LIKE ('%sb_wlupdated_%')
    " );

		//image resizing
		$upload                 = wp_upload_dir();
		$posts_table_name       = $wpdb->prefix . 'sbi_instagram_posts';
		$feeds_posts_table_name = esc_sql( $wpdb->prefix . 'sbi_instagram_feeds_posts' );

		$image_files = glob( trailingslashit( $upload['basedir'] ) . trailingslashit( 'sb-instagram-feed-images' ) . '*' ); // get all file names
		foreach ( $image_files as $file ) { // iterate files
			if ( is_file( $file ) ) {
				unlink( $file );
			} // delete file
		}

		global $wp_filesystem;

		$wp_filesystem->delete( trailingslashit( $upload['basedir'] ) . trailingslashit( 'sb-instagram-feed-images' ) , true );
		//Delete tables
		$wpdb->query( "DROP TABLE IF EXISTS $posts_table_name" );
		$wpdb->query( "DROP TABLE IF EXISTS $feeds_posts_table_name" );

		$table_name = $wpdb->prefix . "options";
		$wpdb->query( "
			        DELETE
			        FROM $table_name
			        WHERE `option_name` LIKE ('%\_transient\_\$sbi\_%')
			        " );
		$wpdb->query( "
			        DELETE
			        FROM $table_name
			        WHERE `option_name` LIKE ('%\_transient\_timeout\_\$sbi\_%')
			        " );
		delete_option( 'sbi_hashtag_ids' );
		delete_option( 'sb_instagram_errors' );

		global $wp_roles;
		$wp_roles->remove_cap( 'administrator', 'manage_instagram_feed_options' );

	}

	register_uninstall_hook( __FILE__, 'sb_instagram_uninstall' );

	/**
	 * Create database tables for sub-site if multisite
	 *
	 * @param  int $blog_id
	 * @param  int $user_id
	 * @param  string $domain
	 * @param  string $path
	 * @param  string $site_id
	 * @param  array $meta
	 *
	 * @since  2.0
	 */
	function sbi_on_create_blog( $blog_id, $user_id, $domain, $path, $site_id, $meta ) {
		if ( is_plugin_active_for_network( 'instagram-feed/instagram-feed.php' ) ) {
			switch_to_blog( $blog_id );
			sbi_create_database_table();
			restore_current_blog();
		}
	}

	add_action( 'wpmu_new_blog', 'sbi_on_create_blog', 10, 6 );

	/**
	 * Delete custom tables if not preserving settings
	 *
	 * @param  array $tables tables to drop
	 *
	 * @return array
	 *
	 * @since  2.0
	 */
	function sbi_on_delete_blog( $tables ) {
		$options                        = get_option( 'sb_instagram_settings' );
		$sb_instagram_preserve_settings = $options['sb_instagram_preserve_settings'];
		if ( $sb_instagram_preserve_settings ) {
			return;
		}

		global $wpdb;
		$tables[] = $wpdb->prefix . SBI_INSTAGRAM_POSTS_TYPE;
		$tables[] = $wpdb->prefix . SBI_INSTAGRAM_FEEDS_POSTS;

		return $tables;
	}

	add_filter( 'wpmu_drop_tables', 'sbi_on_delete_blog' );
}