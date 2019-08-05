<?php
/**
 * Includes functions related to actions while in the admin area.
 *
 * - All AJAX related features
 * - Enqueueing of JS and CSS files
 * - Settings link on "Plugins" page
 * - Creation of local avatar image files
 * - Connecting accounts on the "Configure" tab
 * - Displaying admin notices
 * - Clearing caches
 */
if ( ! defined( 'ABSPATH' ) ) exit; // Exit if accessed directly

function sb_instagram_admin_style() {
	wp_register_style( 'sb_instagram_admin_css', SBI_PLUGIN_URL . 'css/sb-instagram-admin.css', array(), SBIVER );
	wp_enqueue_style( 'sb_instagram_font_awesome', 'https://maxcdn.bootstrapcdn.com/font-awesome/4.6.3/css/font-awesome.min.css' );
	wp_enqueue_style( 'sb_instagram_admin_css' );
	wp_enqueue_style( 'wp-color-picker' );
}
add_action( 'admin_enqueue_scripts', 'sb_instagram_admin_style' );

function sb_instagram_admin_scripts() {
	wp_enqueue_script( 'sb_instagram_admin_js', SBI_PLUGIN_URL . 'js/sb-instagram-admin.js', array(), SBIVER );
	wp_localize_script( 'sb_instagram_admin_js', 'sbiA', array(
			'ajax_url' => admin_url( 'admin-ajax.php' ),
			'sbi_nonce' => wp_create_nonce( 'sbi_nonce' )
		)
	);
	if( !wp_script_is('jquery-ui-draggable') ) {
		wp_enqueue_script(
			array(
				'jquery',
				'jquery-ui-core',
				'jquery-ui-draggable'
			)
		);
	}
	wp_enqueue_script(
		array(
			'hoverIntent',
			'wp-color-picker'
		)
	);
}
add_action( 'admin_enqueue_scripts', 'sb_instagram_admin_scripts' );

// Add a Settings link to the plugin on the Plugins page
$sbi_plugin_file = 'instagram-feed-pro/instagram-feed.php';
add_filter( "plugin_action_links_{$sbi_plugin_file}", 'sbi_add_settings_link', 10, 2 );

//modify the link by unshifting the array
function sbi_add_settings_link( $links, $file ) {
	$sbi_settings_link = '<a href="' . admin_url( 'admin.php?page=sb-instagram-feed' ) . '">' . __( 'Settings', 'instagram-feed' ) . '</a>';
	array_unshift( $links, $sbi_settings_link );

	return $links;
}


/**
 * Called via ajax to automatically save access token and access token secret
 * retrieved with the big blue button
 */
function sbi_auto_save_tokens() {
	$nonce = $_POST['sbi_nonce'];

	if ( ! wp_verify_nonce( $nonce, 'sbi_nonce' ) ) {
		die ( 'You did not do this the right way!' );
	}

    wp_cache_delete ( 'alloptions', 'options' );

    $options = sbi_get_database_settings();
    $new_access_token = isset( $_POST['access_token'] ) ? sanitize_text_field( $_POST['access_token'] ) : false;
    $split_token = $new_access_token ? explode( '.', $new_access_token ) : array();
    $new_user_id = isset( $split_token[0] ) ? $split_token[0] : '';

    $connected_accounts =  isset( $options['connected_accounts'] ) ? $options['connected_accounts'] : array();
    $test_connection_data = sbi_account_data_for_token( $new_access_token );

    $connected_accounts[ $new_user_id ] = array(
        'access_token' => sbi_get_parts( $new_access_token ),
        'user_id' => $test_connection_data['id'],
        'username' => $test_connection_data['username'],
        'is_valid' => $test_connection_data['is_valid'],
        'last_checked' => $test_connection_data['last_checked'],
        'profile_picture' => $test_connection_data['profile_picture'],
    );

    if ( !$options['sb_instagram_disable_resize'] ) {
        if ( sbi_create_local_avatar( $test_connection_data['username'], $test_connection_data['profile_picture'] ) ) {
	        $connected_accounts[ $new_user_id ]['local_avatar'] = true;
        }
    } else {
	    $connected_accounts[ $new_user_id ]['local_avatar'] = false;
    }

    $options['connected_accounts'] = $connected_accounts;

    update_option( 'sb_instagram_settings', $options );

    echo wp_json_encode( $connected_accounts[ $new_user_id ] );

	die();
}
add_action( 'wp_ajax_sbi_auto_save_tokens', 'sbi_auto_save_tokens' );

function sbi_delete_local_avatar( $username ) {
    var_dump( 'deleting' );
	$upload = wp_upload_dir();

	$image_files = glob( trailingslashit( $upload['basedir'] ) . trailingslashit( SBI_UPLOADS_NAME ) . $username . '.jpg'  ); // get all matching images
	foreach ( $image_files as $file ) { // iterate files
		if ( is_file( $file ) ) {
			unlink( $file );
		}
	}
}

function sbi_create_local_avatar( $username, $file_name ) {
	$image_editor = wp_get_image_editor( $file_name );

	if ( ! is_wp_error( $image_editor ) ) {
		$upload = wp_upload_dir();

		$full_file_name = trailingslashit( $upload['basedir'] ) . trailingslashit( SBI_UPLOADS_NAME ) . $username  . '.jpg';

		$saved_image = $image_editor->save( $full_file_name );

		if ( ! $saved_image ) {
			global $sb_instagram_posts_manager;

			$sb_instagram_posts_manager->add_error( 'image_editor_save', array(
				__( 'Error saving edited image.', 'instagram-feed' ),
				$full_file_name
			) );
		} else {
		    return true;
        }
	} else {
		global $sb_instagram_posts_manager;

		$message = __( 'Error editing image.', 'instagram-feed' );
		if ( isset( $image_editor ) && isset( $image_editor->errors ) ) {
			foreach ( $image_editor->errors as $key => $item ) {
				$message .= ' ' . $key . '- ' . $item[0] . ' |';
			}
		}

		$sb_instagram_posts_manager->add_error( 'image_editor', array( $file_name, $message ) );
	}
	return false;
}

function sbi_connect_business_accounts() {
	$nonce = $_POST['sbi_nonce'];

	if ( ! wp_verify_nonce( $nonce, 'sbi_nonce' ) ) {
		die ( 'You did not do this the right way!' );
	}

	$accounts = isset( $_POST['accounts'] ) ? json_decode( stripslashes( $_POST['accounts'] ), true ) : false;
	$options = sbi_get_database_settings();
	$connected_accounts =  isset( $options['connected_accounts'] ) ? $options['connected_accounts'] : array();

	foreach ( $accounts as $account ) {
		$access_token = isset( $account['access_token'] ) ? $account['access_token'] : '';
		$page_access_token = isset( $account['page_access_token'] ) ? $account['page_access_token'] : '';
		$username = isset( $account['username'] ) ? $account['username'] : '';
		$name = isset( $account['name'] ) ? $account['name'] : '';
		$profile_picture = isset( $account['profile_picture_url'] ) ? $account['profile_picture_url'] : '';
		$user_id = isset( $account['id'] ) ? $account['id'] : '';
		$type = 'business';

		$connected_accounts[ $user_id ] = array(
			'access_token' => $access_token,
			'page_access_token' => $page_access_token,
			'user_id' => $user_id,
			'username' => $username,
			'is_valid' => true,
			'last_checked' => time(),
			'profile_picture' => $profile_picture,
			'name' => $name,
			'type' => $type
		);

		if ( !$options['sb_instagram_disable_resize'] ) {
			if ( sbi_create_local_avatar( $username, $profile_picture ) ) {
				$connected_accounts[ $user_id ]['local_avatar'] = true;
			}
		} else {
			$connected_accounts[ $user_id ]['local_avatar'] = false;
		}
	}

	$options['connected_accounts'] = $connected_accounts;

	update_option( 'sb_instagram_settings', $options );

	echo wp_json_encode( $connected_accounts );

	die();
}
add_action( 'wp_ajax_sbi_connect_business_accounts', 'sbi_connect_business_accounts' );

function sbi_auto_save_id() {
	$nonce = $_POST['sbi_nonce'];

	if ( ! wp_verify_nonce( $nonce, 'sbi_nonce' ) ) {
		die ( 'You did not do this the right way!' );
	}
	if ( current_user_can( 'edit_posts' ) && isset( $_POST['id'] ) ) {
		$options = get_option( 'sb_instagram_settings', array() );

		$options['sb_instagram_user_id'] = array( sanitize_text_field( $_POST['id'] ) );

		update_option( 'sb_instagram_settings', $options );
	}
	die();
}
add_action( 'wp_ajax_sbi_auto_save_id', 'sbi_auto_save_id' );

function sbi_test_token() {
	$access_token = isset( $_POST['access_token'] ) ? sanitize_text_field( $_POST['access_token'] ) : false;
	$account_id = isset( $_POST['account_id'] ) ? sanitize_text_field( $_POST['account_id'] ) : false;
	$options = sbi_get_database_settings();
	$connected_accounts =  isset( $options['connected_accounts'] ) ? $options['connected_accounts'] : array();

	if ( $access_token ) {
		wp_cache_delete ( 'alloptions', 'options' );

		$number_dots = substr_count ( $access_token , '.' );
		$test_connection_data = array( 'error_message' => 'A successful connection could not be made. Please make sure your Access Token is valid.');

		if ( $number_dots > 1 ) {
			$split_token = explode( '.', $access_token );
			$new_user_id = isset( $split_token[0] ) ? $split_token[0] : '';

			$test_connection_data = sbi_account_data_for_token( $access_token );
		} else if (! empty( $account_id ) ) {
			$url = 'https://graph.facebook.com/'.$account_id.'?fields=biography,id,username,website,followers_count,media_count,profile_picture_url,name&access_token='.sbi_maybe_clean( $access_token );
			$json = json_decode( sbi_business_account_request( $url, array( 'access_token' => $access_token ) ), true );
			if ( isset( $json['id'] ) ) {
				$new_user_id = $json['id'];
				$test_connection_data = array(
					'access_token' => $access_token,
					'id' => $json['id'],
					'username' => $json['username'],
					'type' => 'business',
					'is_valid' => true,
					'last_checked' => time(),
					'profile_picture' => $json['profile_picture_url']
				);
			}

		}

		if ( isset( $test_connection_data['error_message'] ) ) {
			echo $test_connection_data['error_message'];
		} elseif ( $test_connection_data !== false ) {
			$username = $test_connection_data['username'] ? $test_connection_data['username'] : $connected_accounts[ $new_user_id ]['username'];
			$user_id = $test_connection_data['id'] ? $test_connection_data['id'] : $connected_accounts[ $new_user_id ]['user_id'];
			$profile_picture = $test_connection_data['profile_picture'] ? $test_connection_data['profile_picture'] : $connected_accounts[ $new_user_id ]['profile_picture'];
			$type = isset( $test_connection_data['type'] ) ? $test_connection_data['type'] : 'personal';
			$connected_accounts[ $new_user_id ] = array(
				'access_token' => sbi_get_parts( $access_token ),
				'user_id' => $user_id,
				'username' => $username,
				'type' => $type,
				'is_valid' => $test_connection_data['is_valid'],
				'last_checked' => $test_connection_data['last_checked'],
				'profile_picture' => $profile_picture
			);

			if ( !$options['sb_instagram_disable_resize'] ) {
				if ( sbi_create_local_avatar( $username, $profile_picture ) ) {
					$connected_accounts[ $new_user_id ]['local_avatar'] = true;
				}
			} else {
				$connected_accounts[ $new_user_id ]['local_avatar'] = false;
			}

			$options['connected_accounts'] = $connected_accounts;

			update_option( 'sb_instagram_settings', $options );

			echo wp_json_encode( $connected_accounts[ $new_user_id ] );
		} else {
			echo 'A successful connection could not be made. Please make sure your Access Token is valid.';
		}

	}

	die();
}
add_action( 'wp_ajax_sbi_test_token', 'sbi_test_token' );

function sbi_delete_account() {
	$nonce = $_POST['sbi_nonce'];

	if ( ! wp_verify_nonce( $nonce, 'sbi_nonce' ) ) {
		die ( 'You did not do this the right way!' );
	}
	$account_id = isset( $_POST['account_id'] ) ? sanitize_text_field( $_POST['account_id'] ) : false;
	$options = get_option( 'sb_instagram_settings', array() );
	$connected_accounts =  isset( $options['connected_accounts'] ) ? $options['connected_accounts'] : array();

	if ( $account_id ) {
		wp_cache_delete ( 'alloptions', 'options' );
		$username = $connected_accounts[ $account_id ]['username'];

		$num_times_used = 0;
		foreach ( $connected_accounts as $connected_account ) {

		    if ( $connected_account['username'] === $username ) {
		        $num_times_used++;
            }
        }

		if ( $num_times_used < 2 ) {
			sbi_delete_local_avatar( $username );
        }

		unset( $connected_accounts[ $account_id ] );

		$options['connected_accounts'] = $connected_accounts;

		update_option( 'sb_instagram_settings', $options );

	}

	die();
}
add_action( 'wp_ajax_sbi_delete_account', 'sbi_delete_account' );

function sbi_account_data_for_token( $access_token ) {
	$return = array(
		'id' => false,
		'username' => false,
		'is_valid' => false,
		'last_checked' => time()
	);
	$url = 'https://api.instagram.com/v1/users/self/?access_token=' . sbi_maybe_clean( $access_token );
	$args = array(
		'timeout' => 60,
		'sslverify' => false
	);
	$result = wp_remote_get( $url, $args );

	if ( ! is_wp_error( $result ) ) {
		$data = json_decode( $result['body'] );
	} else {
		$data = array();
	}

	if ( isset( $data->data->id ) ) {
		$return['id'] = $data->data->id;
		$return['username'] = $data->data->username;
		$return['is_valid'] = true;
		$return['profile_picture'] = $data->data->profile_picture;

	} elseif ( isset( $data->error_type ) && $data->error_type === 'OAuthRateLimitException' ) {
		$return['error_message'] = 'This account\'s access token is currently over the rate limit. Try removing this access token from all feeds and wait an hour before reconnecting.';
	} else {
		$return = false;

	}

	return $return;
}

function sbi_get_connected_accounts_data( $sb_instagram_at ) {
	$sbi_options = get_option( 'sb_instagram_settings' );
	$return = array();
	$return['connected_accounts'] = isset( $sbi_options['connected_accounts'] ) ? $sbi_options['connected_accounts'] : array();

	if ( empty( $connected_accounts ) && ! empty( $sb_instagram_at ) ) {
		$tokens = explode(',', $sb_instagram_at );
		$user_ids = array();

		foreach ( $tokens as $token ) {
			$account = sbi_account_data_for_token( $token );
			if ( isset( $account['is_valid'] ) ) {
				$split = explode( '.', $token );
				$return['connected_accounts'][ $split[0] ] = array(
					'access_token' => sbi_get_parts( $token ),
					'user_id' => $split[0],
					'username' => '',
					'is_valid' => true,
					'last_checked' => time(),
					'profile_picture' => ''
				);
				$user_ids[] = $split[0];
			}

		}

		$sbi_options['connected_accounts'] = $return['connected_accounts'];
		$sbi_options['sb_instagram_at'] = '';
		$sbi_options['sb_instagram_user_id'] = $user_ids;

		$return['user_ids'] = $user_ids;

		update_option( 'sb_instagram_settings', $sbi_options );
	}

	return $return;
}

function sbi_business_account_request( $url, $account, $remove_access_token = true ) {
	$args = array(
		'timeout' => 60,
		'sslverify' => false
	);
	$result = wp_remote_get( $url, $args );

	if ( ! is_wp_error( $result ) ) {
		$response_no_at = $remove_access_token ? str_replace( sbi_maybe_clean( $account['access_token'] ), '{accesstoken}', $result['body'] ) : $result['body'];
		return $response_no_at;
	} else {
		return wp_json_encode( $result );
	}
}

function sbi_after_connection() {

	if ( isset( $_POST['access_token'] ) ) {
		$access_token = sanitize_text_field( $_POST['access_token'] );
		$account_info = 	sbi_account_data_for_token( $access_token );
		echo wp_json_encode( $account_info );
	}

	die();
}
add_action( 'wp_ajax_sbi_after_connection', 'sbi_after_connection' );

function sbi_clear_backups() {
	$nonce = isset( $_POST['sbi_nonce'] ) ? sanitize_text_field( $_POST['sbi_nonce'] ) : '';

	if ( ! wp_verify_nonce( $nonce, 'sbi_nonce' ) ) {
		die ( 'You did not do this the right way!' );
	}

	//Delete all transients
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

	die();
}
add_action( 'wp_ajax_sbi_clear_backups', 'sbi_clear_backups' );

function sbi_reset_resized() {

	global $sb_instagram_posts_manager;
	$sb_instagram_posts_manager->delete_all_sbi_instagram_posts();

	echo "1";

	die();
}
add_action( 'wp_ajax_sbi_reset_resized', 'sbi_reset_resized' );

function sbi_reset_log() {

	delete_option( 'sb_instagram_errors' );

	echo "1";

	die();
}
add_action( 'wp_ajax_sbi_reset_log', 'sbi_reset_log' );

//REVIEW REQUEST NOTICE

// checks $_GET to see if the nag variable is set and what it's value is
function sbi_check_nag_get( $get, $nag, $option, $transient ) {
	if ( isset( $_GET[$nag] ) && $get[$nag] == 1 ) {
		update_option( $option, 'dismissed' );
	} elseif ( isset( $_GET[$nag] ) && $get[$nag] == 'later' ) {
		$time = 2 * WEEK_IN_SECONDS;
		set_transient( $transient, 'waiting', $time );
		update_option( $option, 'pending' );
	}
}

// will set a transient if the notice hasn't been dismissed or hasn't been set yet
function sbi_maybe_set_transient( $transient, $option ) {
	$sbi_rating_notice_waiting = get_transient( $transient );
	$notice_status = get_option( $option, false );

	if ( ! $sbi_rating_notice_waiting && !( $notice_status === 'dismissed' || $notice_status === 'pending' ) ) {
		$time = 2 * WEEK_IN_SECONDS;
		set_transient( $transient, 'waiting', $time );
		update_option( $option, 'pending' );
	}
}

// generates the html for the admin notice
function sbi_rating_notice_html() {

	//Only show to admins
	if ( current_user_can( 'manage_options' ) ){

		global $current_user;
		$user_id = $current_user->ID;

		/* Check that the user hasn't already clicked to ignore the message */
		if ( ! get_user_meta( $user_id, 'sbi_ignore_rating_notice') ) {

			_e("
            <div class='sbi_notice sbi_review_notice'>
                <img src='". SBI_PLUGIN_URL . 'img/sbi-icon.png' ."' alt='Custom Feeds for Instagram'>
                <div class='ctf-notice-text'>
                    <p>It's great to see that you've been using the <strong>Custom Feeds for Instagram</strong> plugin for a while now. Hopefully you're happy with it!&nbsp; If so, would you consider leaving a positive review? It really helps to support the plugin and helps others to discover it too!</p>
                    <p class='links'>
                        <a class='sbi_notice_dismiss' href='https://wordpress.org/support/plugin/instagram-feed/reviews/' target='_blank'>Sure, I'd love to!</a>
                        &middot;
                        <a class='sbi_notice_dismiss' href='" .esc_url( add_query_arg( 'sbi_ignore_rating_notice_nag', '1' ) ). "'>No thanks</a>
                        &middot;
                        <a class='sbi_notice_dismiss' href='" .esc_url( add_query_arg( 'sbi_ignore_rating_notice_nag', '1' ) ). "'>I've already given a review</a>
                        &middot;
                        <a class='sbi_notice_dismiss' href='" .esc_url( add_query_arg( 'sbi_ignore_rating_notice_nag', 'later' ) ). "'>Ask Me Later</a>
                    </p>
                </div>
                <a class='sbi_notice_close' href='" .esc_url( add_query_arg( 'sbi_ignore_rating_notice_nag', '1' ) ). "'><i class='fa fa-close'></i></a>
            </div>
            ");

		}

	}
}

function sbi_process_admin_notices() {
	// variables to define certain terms
	$transient = 'instagram_feed_rating_notice_waiting';
	$option = 'sbi_rating_notice';
	$nag = 'sbi_ignore_rating_notice_nag';

	sbi_check_nag_get( $_GET, $nag, $option, $transient );
	sbi_maybe_set_transient( $transient, $option );
	$notice_status = get_option( $option, false );

// only display the notice if the time offset has passed and the user hasn't already dismissed it
	if ( get_transient( $transient ) !== 'waiting' && $notice_status !== 'dismissed' ) {
		add_action( 'admin_notices', 'sbi_rating_notice_html' );
	}
}
add_action( 'admin_init', 'sbi_process_admin_notices' );

add_action('admin_notices', 'sbi_admin_error_notices');
function sbi_admin_error_notices() {
	//Only display notice to admins
	if( !current_user_can( 'manage_options' ) ) return;

	global $sb_instagram_posts_manager;

	if ( isset( $_GET['page'] ) && in_array( $_GET['page'], array( 'sb-instagram-feed' )) ) {
		$errors = $sb_instagram_posts_manager->get_errors();
		if ( ! empty( $errors ) && ( isset( $errors['database_create_posts'] ) || isset( $errors['database_create_posts_feeds'] ) || isset( $errors['upload_dir'] ) || isset( $errors['ajax'] )  ) ) : ?>
			<div class="notice notice-warning is-dismissible">

				<?php foreach ( $sb_instagram_posts_manager->get_errors() as $type => $error ) : ?>
					<?php if ( (in_array( $type, array( 'database_create_posts', 'database_create_posts_feeds', 'upload_dir' ) ) && !$sb_instagram_posts_manager->image_resizing_disabled() ) ) : ?>
						<p><strong><?php echo $error[0]; ?></strong></p>
						<p><?php _e( 'Note for support', 'instagram-feed' ); ?>: <?php echo $error[1]; ?></p>
					<?php endif; ?>
				<?php endforeach; ?>
				<?php if ( ( isset( $errors['database_create_posts'] ) || isset( $errors['database_create_posts_feeds'] ) || isset( $errors['upload_dir'] ) ) && !$sb_instagram_posts_manager->image_resizing_disabled() ) : ?>
					<p><?php _e( sprintf( 'Visit our %s page for help', '<a href="https://smashballoon.com/instagram-feed/support/faq/" target="_blank">FAQ</a>' ), 'instagram-feed' ); ?></p>
				<?php endif; ?>

			<?php foreach ( $sb_instagram_posts_manager->get_errors() as $type => $error ) : ?>
				<?php if (in_array( $type, array( 'ajax' ) )) : ?>
					<p><strong><?php echo $error[0]; ?></strong></p>
					<p><?php echo $error[1]; ?></p>
				<?php endif; ?>
			<?php endforeach; ?>

			</div>

		<?php endif;
	}

}

function sbi_maybe_add_ajax_test_error() {
	if ( isset( $_GET['page'] ) && $_GET['page'] === 'sb-instagram-feed' ) {
		global $sb_instagram_posts_manager;

		if ( $sb_instagram_posts_manager->should_add_ajax_test_notice() ) {
			$sb_instagram_posts_manager->add_error( 'ajax', array( __( 'Unable to use admin-ajax.php when displaying feeds. Some features of the plugin will be unavailable.', 'instagram-feed' ), __( sprintf( 'Please visit %s to troubleshoot.', '<a href="https://smashballoon.com/admin-ajax-requests-are-not-working/">'.__( 'this page', 'instagram-feed' ).'</a>' ), 'instagram-feed' ) ) );
		} else {
			$sb_instagram_posts_manager->remove_error( 'ajax' );
		}
	}
}
add_action( 'admin_init', 'sbi_maybe_add_ajax_test_error' );