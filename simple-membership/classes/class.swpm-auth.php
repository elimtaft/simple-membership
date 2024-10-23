<?php

/**
 * This is a singleton class. It is responsible for handling various authentication related tasks of the plugin.
 * It checks if the user is logged in or not. It checks if the login cookie is valid or not.
 * It also handles the login form submission.
 */
class SwpmAuth {
	private static $_this; //Singleton instance of this class.
	public $protected; //Protected content object.
	public $permitted; //Permission object for the logged in user.
	private $isLoggedIn;
	private $lastStatusMsg;
	public $userData;

	private function __construct() {
		//check if we need to display custom message on the login form
		$custom_msg = filter_input( INPUT_COOKIE, 'swpm-login-form-custom-msg', FILTER_UNSAFE_RAW );
		$custom_msg = sanitize_text_field( $custom_msg );
		if ( ! empty( $custom_msg ) ) {
			$this->lastStatusMsg = $custom_msg;
			//let's 'unset' the cookie
			setcookie( 'swpm-login-form-custom-msg', '', time() - 3600, COOKIEPATH, COOKIE_DOMAIN );
		}
		$this->isLoggedIn = false;
		$this->userData = null;
		$this->protected = SwpmProtection::get_instance();
	}

	/**
	 * Get the singleton instance of this class.
	 */
	public static function get_instance() {
		if ( empty( self::$_this ) ) {
			self::$_this = new SwpmAuth();
			self::$_this->auth_init();
		}
		return self::$_this;
	}

	/**
	 * This function is called when the object is initialized.
	 * The singleton pattern is used to make sure that there is only one instance of this object per page load.
	 * So this function is only called once per page load.
	 */
	private function auth_init() {
		SwpmLog::log_simple_debug(__METHOD__, true);
		//SwpmLog::log_auth_debug("SwpmAuth::auth_init() function.", true);
		/**********************************************************
		 * Note: This function is run on every page load. 
		 * It guarantees the authenticity of the user's login session and permission on every page load.
		 * It is used to perform certain login related validation tasks when the object is initialized.
		 * It calls the validate() function to check if login cookie exists, if the cookie is valid, if the cookie is expired etc.
		 * The validate() function will call the check_constraints() function to check if the user can login.
		 * The check_constraints() function will update the last accessed date and IP address for this member's login session.
		 * The check_constraints() function will also load the membership level's permission object for this member.
		 **********************************************************/

		if (SwpmLimitActiveLogin::is_enabled()){
			if ( isset($_GET['clear_all_session_tokens']) && sanitize_text_field($_GET['clear_all_session_tokens']) == '1' ){
				if (!wp_verify_nonce($_GET['_wpnonce'], 'clear_all_session_tokens_nonce_action')){
					wp_die(__('Nonce verification failed!', 'simple-membership'));
				}

				$limit_exceeded_member_id = sanitize_text_field($_GET['member_id']);
				if (empty($limit_exceeded_member_id) || !SwpmMemberUtils::member_record_exists($limit_exceeded_member_id)){
					wp_die(__('Invalid Member ID', 'simple-membership'));
				}

				SwpmLimitActiveLogin::clear_session_token($limit_exceeded_member_id);
				$this->lastStatusMsg = '<span style="color: green">' . __( 'All session tokens cleared, try to log in in now.', 'simple-membership') . '</span>';
			}
		}

		$login_session_valid = $this->validate();
		if ( ! $login_session_valid ) {
			// The user is not logged in, or the login session is invalid due to some other validation.
			// In this case, we can check if the login form was submitted and process the login request.
			// Note: We will check if spwm_user_name or swpm_password is set inside the authenticate() function to know if the login form was submitted (keeping things backwards compatible).
			$authenticate_response = $this->authenticate();

			if ( isset( $_POST['swpm-login']) && $authenticate_response === false ) {
				//SwpmLog::log_auth_debug( 'SwpmAuth::auth_init() - SWPM Login form was submitted and the authenticate function returned false. Triggering the swpm_login_failed action hook.', true );
				$this->trigger_swpm_login_failed_hook();
			}
		}
	}

	/**
	 * This function is used to process the login authentication request.
	 * It is called on every page load via the auth_init()->authenticate() function.
	 * It checks if the login form was submitted and processes the login request.
	 * It loads the userData variable with the user's data from the database.
	 * It calls the check_password() function to check if the password is correct.
	 * It calls the check_constraints() function to check if the user can login.
	 */
	private function authenticate( $user = null, $pass = null ) {
		// If $user and $pass are not provided, the function was called from auth_init().
		// In this case, we will attempt to retrieve the data from the login form's POST data.
		global $wpdb;
		$swpm_user_name = empty( $user ) ? apply_filters( 'swpm_user_name', filter_input( INPUT_POST, 'swpm_user_name' ) ) : $user;
		$swpm_password = empty( $pass ) ? filter_input( INPUT_POST, 'swpm_password' ) : $pass;

		if ( isset($_POST['swpm_user_name']) && empty ( $swpm_user_name )){
			//Login form was submitted but the username field was left empty.
			$this->isLoggedIn    = false;
			$this->userData      = null;
			$this->lastStatusMsg = '<span class="swpm-login-error-msg swpm-red-error-text">' . SwpmUtils::_( 'Username field cannot be empty.' ) . '</span>';
			$this->trigger_swpm_authenticate_failed_hook($swpm_user_name);//Trigger authenticate failed action hook.
			return false;
		}
		if ( isset($_POST['swpm_password']) && empty ( $swpm_password )){
			//Login form was submitted but the password field was left empty.
			$this->isLoggedIn    = false;
			$this->userData      = null;
			$this->lastStatusMsg = '<span class="swpm-login-error-msg swpm-red-error-text">' . SwpmUtils::_( 'Password field cannot be empty.' ) . '</span>';
			$this->trigger_swpm_authenticate_failed_hook($swpm_user_name);//Trigger authenticate failed action hook.
			return false;
		}
		
		if ( ! empty( $swpm_user_name ) && ! empty( $swpm_password ) ) {
			//SWPM member login request.
			//Trigger action hook that can be used to check stuff before the login request is processed by the plugin.
			$args = array(
				'username' => $swpm_user_name,
				'password' => $swpm_password,
			);
			do_action( 'swpm_before_login_request_is_processed', $args );

			//First, lets make sure this user is not already logged into the site as an "Admin" user. We don't want to override that admin login session.
			if ( current_user_can( 'administrator' ) ) {
				//This user is logged in as ADMIN then trying to do another login as a member. Stop the login request processing (we don't want to override your admin login session).
				$wp_profile_page = SIMPLE_WP_MEMBERSHIP_SITE_HOME_URL . '/wp-admin/profile.php';
				$error_msg = '';
				$error_msg .= '<p>' . SwpmUtils::_( 'Warning! Simple Membership plugin cannot process this login request to prevent you from getting logged out of WP Admin accidentally.' ) . '</p>';
				$error_msg .= '<p><a href="' . $wp_profile_page . '" target="_blank">' . SwpmUtils::_( 'Click here' ) . '</a>' . SwpmUtils::_( ' to see the profile you are currently logged into in this browser.' ) . '</p>';
				$error_msg .= '<p>' . SwpmUtils::_( 'You are logged into the site as an ADMIN user in this browser. First, logout from WP Admin then you will be able to log in as a normal member.' ) . '</p>';
				$error_msg .= '<p>' . SwpmUtils::_( 'Alternatively, you can use a different browser (where you are not logged-in as ADMIN) to test the membership login.' ) . '</p>';
				$error_msg .= '<p>' . SwpmUtils::_( 'Your normal visitors or members will never see this message. This message is ONLY for ADMIN user.' ) . '</p>';
				wp_die( $error_msg );
			}

			//If captcha is present and validation failed, it returns an error string. If validation succeeds, it returns an empty string.
			$captcha_validation_output = apply_filters( 'swpm_validate_login_form_submission', '' );
			if ( ! empty( $captcha_validation_output ) ) {
				$this->lastStatusMsg = '<span class="swpm-login-error-msg swpm-red-error-text">' . SwpmUtils::_( 'Captcha validation failed on the login form.' ) . '</span>';
				return;
			}

			if ( is_email( $swpm_user_name ) ) {//User is trying to log-in using an email address
				$email = sanitize_email( $swpm_user_name );
				$query = $wpdb->prepare( 'SELECT user_name FROM ' . $wpdb->prefix . 'swpm_members_tbl WHERE email = %s', $email );
				$username = $wpdb->get_var( $query );
				if ( $username ) {//Found a user record
					$swpm_user_name = $username; //Grab the usrename value so it can be used in the authentication process.
					SwpmLog::log_auth_debug( 'Authentication request using email address: ' . $email . ', Found a user record with username: ' . $swpm_user_name, true );
				}
			}

			//Lets process the request. Check username and password
			$user = sanitize_user( $swpm_user_name );
			$pass = trim( $swpm_password );
			SwpmLog::log_auth_debug( 'Authentication request - Username: ' . $swpm_user_name, true );

			$query = 'SELECT * FROM ' . $wpdb->prefix . 'swpm_members_tbl WHERE user_name = %s';
			$userData = $wpdb->get_row( $wpdb->prepare( $query, $user ) );
			$this->userData = $userData;
			if ( ! $userData ) {
				$this->isLoggedIn    = false;
				$this->userData      = null;
				$this->lastStatusMsg = '<span class="swpm-login-error-msg swpm-red-error-text">' . SwpmUtils::_( 'No user found with that username or email.' ) . '</span>';
				$this->trigger_swpm_authenticate_failed_hook($swpm_user_name);//Trigger authenticate failed action hook.
				return false;
			}
			$check = $this->check_password( $pass, $userData->password );
			if ( ! $check ) {
				$this->isLoggedIn    = false;
				$this->userData      = null;
				$this->lastStatusMsg = '<span class="swpm-login-error-msg swpm-red-error-text">' . SwpmUtils::_( 'Password empty or invalid.' ) . '</span>';
				$this->trigger_swpm_authenticate_failed_hook($swpm_user_name);//Trigger authenticate failed action hook.
				return false;
			}

			if ( ! $this->check_login_limit($userData->member_id)) {
				// TODO: Need to add a option to clear all other session tokens is login limit reached.
				$this->isLoggedIn    = false;
				$this->userData      = null;
				$this->lastStatusMsg = '<span class="swpm-login-error-msg swpm-red-error-text">' . __( 'Maximum active login limit reached.', 'simple-membership') . '</span>';

				if (SwpmLimitActiveLogin::login_logic() == 'allow'){
					$session_token_reset_query = build_query(array(
						'clear_all_session_tokens' => 1,
						'member_id' => $userData->member_id,
						'_wpnonce' => wp_create_nonce('clear_all_session_tokens_nonce_action'),
					));

					$msg = '<div>';
					$msg .= '<p>';
					$msg .= $this->lastStatusMsg;
					$msg .= '<br>';
					$msg .= __('Would you like to clear all other active session? ', 'simple-membership');
					$msg .= '<a href="?'.$session_token_reset_query.'">'.__('Clear All', 'simple-membership').'</a>';
					$msg .= '</p>';
					$msg .= '</div>';

					$this->lastStatusMsg = $msg;
				}


				$this->trigger_swpm_authenticate_failed_hook($swpm_user_name); //Trigger authenticate failed action hook.
				return false;
			}

			if ( $this->check_constraints() ) {
				$remember   = isset( $_POST['rememberme'] ) ? true : false;
				$this->set_cookie( $remember );
				$this->isLoggedIn    = true;
				$this->lastStatusMsg = 'Logged In.';
				SwpmLog::log_auth_debug( 'Authentication successful for username: ' . $user . '. Triggering swpm_after_login_authentication action hook.', true );
				do_action( 'swpm_after_login_authentication', $user, $pass, $remember );
				return true;
			}
		}
		return false;
	}

	/**
	 * This function is used to validate the login cookie.
	 * It is called on every page load via the auth_init()->validate() function.
	 * Checks if the login cookie exists and if it is valid.
	 * It calls the check_constraints() function to check if the user can login which also updates the user's login date and IP. Also loads the permission object.
	 * @return bool
	 */
	private function validate() {
		$auth_cookie_name = is_ssl() ? SIMPLE_WP_MEMBERSHIP_SEC_AUTH : SIMPLE_WP_MEMBERSHIP_AUTH;
		if ( ! isset( $_COOKIE[ $auth_cookie_name ] ) || empty( $_COOKIE[ $auth_cookie_name ] ) ) {
			return false;
		}
		$cookie_elements = explode( '|', $_COOKIE[ $auth_cookie_name ] );
		if ( count( $cookie_elements ) != 3 ) {
			return false;
		}

		//SwpmLog::log_auth_debug("validate() - " . $_COOKIE[$auth_cookie_name], true);
		list($username, $expiration, $hmac) = $cookie_elements;
		$expired = $expiration;
		// Allow a grace period for POST and AJAX requests
		if ( defined( 'DOING_AJAX' ) || 'POST' == $_SERVER['REQUEST_METHOD'] ) {
			$expired += HOUR_IN_SECONDS;
		}
		// Quick check to see if an honest cookie has expired
		if ( $expired < time() ) {
			$this->lastStatusMsg = SwpmUtils::_( 'Session Expired.' ); //do_action('auth_cookie_expired', $cookie_elements);
			SwpmLog::log_auth_debug( 'validate() - Session Expired', true );
			return false;
		}

		global $wpdb;
		$query = ' SELECT * FROM ' . $wpdb->prefix . 'swpm_members_tbl WHERE user_name = %s';
		$user = $wpdb->get_row( $wpdb->prepare( $query, $username ) );
		if ( empty( $user ) ) {
			$this->lastStatusMsg = SwpmUtils::_( 'Invalid Username' );
			return false;
		}

		$pass_frag = substr( $user->password, 8, 4 );
		$key = self::b_hash( $username . $pass_frag . '|' . $expiration );
		$hash = hash_hmac( 'md5', $username . '|' . $expiration, $key );
		if ( $hmac != $hash ) {
			$this->lastStatusMsg = SwpmUtils::_( 'Please login again.' );
			SwpmLog::log_auth_debug( 'Validate() function - Bad hash. Going to clear the auth cookies to clear the bad hash.', true );
			SwpmLog::log_auth_debug( 'Validate() function - The user profile with the username: ' . $username . ' will be logged out.', true );
			
            do_action('swpm_validate_login_hash_mismatch');
			//Clear the auth cookies of SWPM to clear the bad hash. This will log out the user.
			$this->swpm_clear_auth_cookies();
			//Clear the wp user auth cookies and destroy session as well.
			$this->clear_wp_user_auth_cookies();
			return false;
		}

		if (SwpmLimitActiveLogin::is_enabled()){
			// Check if session_token exists.
			$verifier = $_COOKIE[ $auth_cookie_name ];
			$member_id = SwpmMemberUtils::get_user_by_user_name($username)->member_id;
			if (!SwpmLimitActiveLogin::has_members_session_token($member_id ,$verifier)){
				$this->lastStatusMsg = '<span class="swpm-login-error-msg swpm-red-error-text">' . __( 'Session Expired! Please login again.', 'simple-membership') . '</span>';
				SwpmLog::log_auth_debug( 'Validate() function - Session Token meta expired. Going to clear the auth cookies to clear the bad hash.', true );
				SwpmLog::log_auth_debug( 'Validate() function - The user profile with the username: ' . $username . ' will be logged out.', true );

				do_action('swpm_validate_login_session_token_error');
				//Clear the auth cookies of SWPM to clear the bad hash. This will log out the user.
				$this->swpm_clear_auth_cookies();
				//Clear the wp user auth cookies and destroy session as well.
				$this->clear_wp_user_auth_cookies();
				return false;
			}
		}

		if ( $expiration < time() ) {
			$GLOBALS['login_grace_period'] = 1;
		}
		$this->userData = $user;
		return $this->check_constraints();
	}

	/**
	 * Checks if all the constraints are met for the user to be able to login.
	 * Called on every page load via the auth_init()->validate()->check_constraints() function.
	 * It updates the last accessed date and IP address for this member's login session.
	 * It checks the account status to see if the user can login.
	 * It also checks the member's account expiry and sets expiry status accordingly (as a failsafe to expiry cronjob).
	 * It loads the membership level's permission object for this member.
	 * @return bool
	 */
	private function check_constraints() {
		//SwpmLog::log_auth_debug("SwpmAuth::check_constraints() function.", true);
		if ( empty( $this->userData ) ) {
			//No user data found. Can't proceed.
			return false;
		}

		global $wpdb;
		$enable_expired_login = SwpmSettings::get_instance()->get_value( 'enable-expired-account-login', '' );

		//Update the last accessed date and IP address for this login attempt. $wpdb->update(table, data, where, format, where format)
		$last_accessed_date = current_time( 'mysql' );
		$last_accessed_ip = SwpmUtils::get_user_ip_address();
		$wpdb->update(
			$wpdb->prefix . 'swpm_members_tbl',
			array(
				'last_accessed' => $last_accessed_date,
				'last_accessed_from_ip' => $last_accessed_ip,
			),
			array( 'member_id' => $this->userData->member_id ),
			array( '%s', '%s' ),
			array( '%d' )
		);

		//Check the member's account status.
		$can_login = true;
		if ( $this->userData->account_state == 'inactive' && empty( $enable_expired_login ) ) {
			$this->lastStatusMsg = SwpmUtils::_( 'Account is inactive.' );
			$can_login = false;
		} elseif ( ( $this->userData->account_state == 'expired' ) && empty( $enable_expired_login ) ) {
			$this->lastStatusMsg = SwpmUtils::_( 'Account has expired.' );
			$can_login = false;
		} elseif ( $this->userData->account_state == 'pending' ) {
			$this->lastStatusMsg = SwpmUtils::_( 'Account is pending.' );
			$can_login = false;
		} elseif ( $this->userData->account_state == 'activation_required' ) {
			$resend_email_url = add_query_arg(
				array(
					'swpm_resend_activation_email' => '1',
					'swpm_member_id' => $this->userData->member_id,
				),
				get_home_url()
			);
			$msg = sprintf( SwpmUtils::_( 'You need to activate your account. If you didn\'t receive an email then %s to resend the activation email.' ), '<a href="' . $resend_email_url . '">' . SwpmUtils::_( 'click here' ) . '</a>' );
			$this->lastStatusMsg = '<div class="swpm_login_error_activation_required">' . $msg . '</div>';
			$can_login = false;
		}

		if ( ! $can_login ) {
			$this->isLoggedIn = false;
			$this->userData = null;
			return false;
		}

		//Check if the user's account has expired. 
		if ( SwpmUtils::is_subscription_expired( $this->userData ) ) {
			//The user's account has expired.
			if ( $this->userData->account_state == 'active' ) {
				//This is an additional check at login validation time to ensure the user account gets set to expired state even if the cronjob fails to do it.
				$wpdb->update( $wpdb->prefix . 'swpm_members_tbl', array( 'account_state' => 'expired' ), array( 'member_id' => $this->userData->member_id ), array( '%s' ), array( '%d' ) );
			}
			if ( empty( $enable_expired_login ) ) {
				//Account has expired and expired login is not enabled.
				$this->lastStatusMsg = SwpmUtils::_( 'Account has expired.' );
				$this->isLoggedIn = false;
				$this->userData = null;
				return false;
			}
		}

		//Load the membership level's permission object.
		$this->permitted = SwpmPermission::get_instance( $this->userData->membership_level );

		$this->lastStatusMsg = SwpmUtils::_( 'You are logged in as:' ) . $this->userData->user_name;
		$this->isLoggedIn  = true;
		return true;
	}

	private function check_password( $plain_password, $hashed_pw ) {
		global $wp_hasher;
		if ( empty( $plain_password ) ) {
			return false;
		}
		if ( empty( $wp_hasher ) ) {
			require_once ABSPATH . 'wp-includes/class-phpass.php';
			$wp_hasher = new PasswordHash( 8, true );
		}
		return $wp_hasher->CheckPassword( $plain_password, $hashed_pw );
	}

	/**
	 * Check if login limit is enabled and login limit has not reached.
	 *
	 * @param $member_id
	 *
	 * @return bool
	 */
	private function check_login_limit($member_id){
		if (SwpmLimitActiveLogin::is_enabled() && SwpmLimitActiveLogin::reached_active_login_limit($member_id)){
			return false;
		}

		// Active login limit not enabled, no restrictions will be applied.
		return true;
	}

	public function match_password( $password ) {
		if ( ! $this->is_logged_in() ) {
			return false;
		}
		return $this->check_password( $password, $this->get( 'password' ) );
	}

	public function login_to_swpm_using_wp_user( $user ) {
		if ( $this->isLoggedIn ) {
			return false;
		}
		$email  = $user->user_email;
		$member = SwpmMemberUtils::get_user_by_email( $email );
		if ( empty( $member ) ) {
			//There is no swpm profile with this email.
			return false;
		}
		$this->userData   = $member;
		$this->isLoggedIn = true;
		$this->set_cookie();
		SwpmLog::log_auth_debug( 'Member has been logged in using WP User object.', true );
		$this->check_constraints();
		return true;
	}

	/**
	 * Note: This function is NOT called for our plugin's standard login form submission.
	 * This is called for other methods of login authentication (for example, wp_authenticate_handler, auto_login, API addon etc).
	 * Our standard login form submission uses the auth_init() to process the login request.
	 */
	public function login( $user, $pass, $remember = '', $secure = '' ) {
		SwpmLog::log_auth_debug( 'SwpmAuth::login()', true );
		if ( $this->isLoggedIn ) {
			//Already logged in. Nothing to do here.
			return;
		}
		if ( $this->authenticate( $user, $pass ) && $this->validate() ) {
			//Authentication successful.
			$this->set_cookie( $remember, $secure );
		} else {
			//Authentication failed.
			$this->isLoggedIn = false;
			$this->userData = null;
		}
		return $this->lastStatusMsg;
	}

	public function logout( $trigger_hook = true) {
		if ( ! $this->isLoggedIn ) {
			return;
		}

		if ( SwpmUtils::is_multisite_install() ) {
			//Defines cookie-related WordPress constants on a multi-site setup (if not defined already).
			wp_cookie_constants();
		}
        
		//Clear the auth cookies.
		$this->swpm_clear_auth_cookies();

		$this->userData      = null;
		$this->isLoggedIn    = false;
		$this->lastStatusMsg = SwpmUtils::_( 'Logged Out Successfully.' );
		if ( $trigger_hook ) {
			//Trigger action hook unless it is a silent logout.
			do_action( 'swpm_logout' );
		}
	}

	/*
	 * This function is used to logout without triggering the action hook. Then redirect to a specific URL (to prevent any logout redirect loop).
	 */
	public function logout_silent_and_redirect() {
		$this->logout( false );//Logout without triggering the action hook.
		$this->swpm_clear_auth_cookies();//Force clear the auth cookies.
		$silent_logout_redirect_url = add_query_arg(
			array(
				'swpm_logged_out' => '1',
			),
			SIMPLE_WP_MEMBERSHIP_SITE_HOME_URL
		);		
		$redirect_url = apply_filters( 'swpm_logout_silent_and_redirect_url', $silent_logout_redirect_url );
		SwpmLog::log_auth_debug( 'Silent logout completed. Redirecting to: ' . $redirect_url, true );
		wp_redirect( trailingslashit( $redirect_url ) );
		exit( 0 );
	}
	
	public function swpm_clear_auth_cookies() {
		do_action( 'swpm_clear_auth_cookies' );
		if ( SwpmUtils::is_multisite_install() ) {
			//Defines cookie-related WordPress constants on a multi-site setup (if not defined already).
			wp_cookie_constants();
		}

		$logged_in_member_id = SwpmMemberUtils::get_logged_in_members_id();

		setcookie( SIMPLE_WP_MEMBERSHIP_AUTH, ' ', time() - YEAR_IN_SECONDS, COOKIEPATH, COOKIE_DOMAIN );
		setcookie( SIMPLE_WP_MEMBERSHIP_SEC_AUTH, ' ', time() - YEAR_IN_SECONDS, COOKIEPATH, COOKIE_DOMAIN );

		if (SwpmLimitActiveLogin::is_enabled()){
			// Save Session Token to members meta as well
			$verifier = isset($_COOKIE[SIMPLE_WP_MEMBERSHIP_SEC_AUTH]) ? $_COOKIE[SIMPLE_WP_MEMBERSHIP_SEC_AUTH] : $_COOKIE[SIMPLE_WP_MEMBERSHIP_AUTH];
			SwpmLimitActiveLogin::clear_session_token($logged_in_member_id, $verifier);
		}
	}

	public function clear_wp_user_auth_cookies(){
		//This is useful in certain circumstances (instead of using the wp_logout).
		if( function_exists('wp_destroy_current_session') ){
			wp_destroy_current_session();
		}
		if( function_exists('wp_clear_auth_cookie') ){
			wp_clear_auth_cookie();
		}
	}
	
	private function set_cookie( $remember = '', $secure = '' ) {
		if ( $remember ) {
			$expiration = time() + 1209600; //14 days
			$expire = $expiration + 43200; //12 hours grace period
		} else {
			$expiration = time() + 259200; //3 days.
			$expire = $expiration; //The minimum cookie expiration should be at least a few days.
			$force_wp_user_sync = SwpmSettings::get_instance()->get_value( 'force-wp-user-sync' );
			if ( !empty( $force_wp_user_sync ) ) {
				//Set the expire to 0 to match with WP's cookie expiration (when "remember me" is not checked).
				SwpmLog::log_auth_debug( 'The force_wp_user_sync option is enabled. Setting the cookie expiration to 0 to match with WP\'s cookie expiration (when "remember me" is not checked).', true );
				$expire = 0;
			}
		}

		$expire = apply_filters( 'swpm_auth_cookie_expiry_value', $expire );

		if ( SwpmUtils::is_multisite_install() ) {
			//Defines cookie-related WordPress constants on a multi-site setup (if not defined already).
			wp_cookie_constants();
		}
                
		setcookie( 'swpm_in_use', 'swpm_in_use', $expire, COOKIEPATH, COOKIE_DOMAIN );//Switch this to the following one.
		setcookie( 'wp_swpm_in_use', 'wp_swpm_in_use', $expire, COOKIEPATH, COOKIE_DOMAIN );//Prefix the cookie with 'wp' to exclude Batcache caching.
		if ( function_exists( 'wp_cache_serve_cache_file' ) ) {//WP Super cache workaround
			$author_value = isset( $this->userData->user_name ) ? $this->userData->user_name : 'wp_swpm';
			$author_value = apply_filters( 'swpm_comment_author_cookie_value', $author_value );
			setcookie( "comment_author_", $author_value, $expire, COOKIEPATH, COOKIE_DOMAIN );
		}
                
		$expiration_timestamp = SwpmUtils::get_expiration_timestamp( $this->userData );
		$enable_expired_login = SwpmSettings::get_instance()->get_value( 'enable-expired-account-login', '' );
		// make sure cookie doesn't live beyond account expiration date.
		// but if expired account login is enabled then ignore if account is expired
		$expiration = empty( $enable_expired_login ) ? min( $expiration, $expiration_timestamp ) : $expiration;
		$pass_frag  = substr( $this->userData->password, 8, 4 );
		$scheme     = 'auth';
		if ( ! $secure ) {
			$secure = is_ssl();
		}
		$key              = self::b_hash( $this->userData->user_name . $pass_frag . '|' . $expiration, $scheme );
		$hash             = hash_hmac( 'md5', $this->userData->user_name . '|' . $expiration, $key );
		$auth_cookie      = $this->userData->user_name . '|' . $expiration . '|' . $hash;
		$auth_cookie_name = $secure ? SIMPLE_WP_MEMBERSHIP_SEC_AUTH : SIMPLE_WP_MEMBERSHIP_AUTH;
		setcookie( $auth_cookie_name, $auth_cookie, $expire, COOKIEPATH, COOKIE_DOMAIN, $secure, true );

		if (SwpmLimitActiveLogin::is_enabled()){
			// Save Session Token to members meta as well
			$verifier = $auth_cookie;
			$new_session_token = SwpmLimitActiveLogin::prepare_new_session_token(!empty($remember));
			SwpmLimitActiveLogin::purge_member_session_tokens($this->userData->member_id, $verifier, $new_session_token);
		}
	}

	/*
	 * Important: This function should only be used after the users have changed their password. Otherwise it can have unintended consequences.
	 * This function is used to reset the auth cookies after the user changes their password (from our plugin's profile page).
	 */
	public function reset_auth_cookies_after_pass_change($user_info, $remember='', $secure=''){
		// Clear the old auth cookies for WP user and SWPM. Then set new auth cookies.

		//Reset the auth cookies for SWPM user only.
		$this->reset_swpm_auth_cookies_only($user_info, $remember, $secure);

		//Clear the WP user auth cookies and destroy session. New auth cookies will be generate below.
		$this->clear_wp_user_auth_cookies(); 
		
		// Set new auth cookies for WP user
		$swpm_id = $user_info['member_id'];
		$wp_user = SwpmMemberUtils::get_wp_user_from_swpm_user_id( $swpm_id );
		$wp_user_id = $wp_user->ID;
		wp_set_auth_cookie( $wp_user_id, true ); // Set new auth cookies (second parameter true means "remember me")
		wp_set_current_user( $wp_user_id ); // Set the current user object
		SwpmLog::log_auth_debug( 'Authentication cookies have been reset after the password update.', true );
	}

	/*
	 * This function is used to reset the auth cookies of SWPM user only.
	 * This is typically used after the user's password is updated in the members DB table (for example, after WP profile update hook is triggered).
	 */
	public function reset_swpm_auth_cookies_only($user_info, $remember='', $secure=''){
		// First clear the old auth cookies for the SWPM user.
		$this->swpm_clear_auth_cookies(); //Clear the swpm auth cookies. New auth cookies will generate below.

		// Next, assign new cookies, so the user doesn't have to login again.
		// Set new auth cookies for SWPM user
        if ( $remember ) {
            $expiration = time() + 1209600; //14 days
            $expire = $expiration + 43200; //12 hours grace period
        } else {
            $expiration = time() + 259200; //3 days.
            $expire = $expiration; //The minimum cookie expiration should be at least a few days.
            $force_wp_user_sync = SwpmSettings::get_instance()->get_value( 'force-wp-user-sync' );
            if ( !empty( $force_wp_user_sync ) ) {
                //Set the expire to 0 to match with WP's cookie expiration (when "remember me" is not checked).
                SwpmLog::log_auth_debug( 'The force_wp_user_sync option is enabled. Setting the cookie expiration to 0 to match with WP\'s cookie expiration (when "remember me" is not checked).', true );
                $expire = 0;
            }
        }
        $expire = apply_filters( 'swpm_auth_cookie_expiry_value', $expire );

        if ( SwpmUtils::is_multisite_install() ) {
            //Defines cookie-related WordPress constants on a multi-site setup (if not defined already).
            wp_cookie_constants();
        }

        $expiration_timestamp = SwpmUtils::get_expiration_timestamp( $this->userData );
        $enable_expired_login = SwpmSettings::get_instance()->get_value( 'enable-expired-account-login', '' );
        // Make sure cookie doesn't live beyond account expiration date.
        // However, if expired account login is enabled then ignore if account is expired.
        $expiration = empty( $enable_expired_login ) ? min( $expiration, $expiration_timestamp ) : $expiration;
        $pass_frag = substr( $user_info['new_enc_password'], 8, 4 );
        $scheme = 'auth';
        if ( !$secure ) {
            $secure = is_ssl();
        }

		$swpm_username = $user_info['user_name'];
        $key = self::b_hash( $swpm_username . $pass_frag . '|' . $expiration, $scheme );
        $hash = hash_hmac( 'md5', $swpm_username . '|' . $expiration, $key );
        $auth_cookie = $swpm_username . '|' . $expiration . '|' . $hash;
        $auth_cookie_name = $secure ? SIMPLE_WP_MEMBERSHIP_SEC_AUTH : SIMPLE_WP_MEMBERSHIP_AUTH;
        setcookie( $auth_cookie_name, $auth_cookie, $expire, COOKIEPATH, COOKIE_DOMAIN, $secure, true );
	}

	public static function b_hash( $data, $scheme = 'auth' ) {
		$salt = wp_salt( $scheme ) . 'j4H!B3TA,J4nIn4.';
		return hash_hmac( 'md5', $data, $salt );
	}

	public function is_logged_in() {
		return $this->isLoggedIn;
	}

	public function get( $key, $default = '' ) {
		if ( isset( $this->userData->$key ) ) {
			return $this->userData->$key;
		}
		if ( isset( $this->permitted->$key ) ) {
			return $this->permitted->$key;
		}
		if ( ! empty( $this->permitted ) ) {
			return $this->permitted->get( $key, $default );
		}
		return $default;
	}

	public function get_message() {
		return $this->lastStatusMsg;
	}

	public function get_expire_date() {
		if ( $this->isLoggedIn ) {
			return SwpmUtils::get_formatted_expiry_date( $this->get( 'subscription_starts' ), $this->get( 'subscription_period' ), $this->get( 'subscription_duration_type' ) );
		}
		return '';
	}

	public function delete() {
		if ( ! $this->is_logged_in() ) {
			return;
		}
		$user_name = $this->get( 'user_name' );
		$user_id   = $this->get( 'member_id' );
		//$subscr_id = $this->get( 'subscr_id' );
		//$email     = $this->get( 'email' );

		SwpmLog::log_simple_debug( 'Deleting member profile with username: ' . $user_name . ' (ID: ' . $user_id . ')', true );
		$this->swpm_clear_auth_cookies();
		wp_clear_auth_cookie();
		SwpmMembers::delete_swpm_user_by_id( $user_id );
		SwpmMembers::delete_wp_user( $user_name );
		$this->isLoggedIn = false;
		SwpmLog::log_simple_debug( 'User profile deleted.', true );
	}

	public function reload_user_data() {
		if ( ! $this->is_logged_in() ) {
			return;
		}
		global $wpdb;
		$member_id = isset( $this->userData->member_id ) ? $this->userData->member_id : '';
		if( empty( $member_id ) ) {
			return;
		}
		$query = 'SELECT * FROM ' . $wpdb->prefix . 'swpm_members_tbl WHERE member_id = %d';
		$this->userData = $wpdb->get_row( $wpdb->prepare( $query, $member_id ) );
	}

	public function is_expired_account() {
		if ( ! $this->is_logged_in() ) {
			return null;
		}
		$account_status = $this->get( 'account_state' );
		if ( $account_status == 'expired' || $account_status == 'inactive' ) {
			//Expired or Inactive accounts are both considered to be expired.
			return true;
		}
		return false;
	}

	public function trigger_swpm_login_failed_hook($username = '') {
		//Trigger login failed action hook.
		if( empty( $username ) ){
			$username = isset( $_POST['swpm_user_name'] ) ? sanitize_text_field( $_POST['swpm_user_name'] ) : '';
		}
		$error_msg = $this->lastStatusMsg;
		$wp_error_obj = new WP_Error( 'swpm-login-failed', $error_msg );
		do_action( 'swpm_login_failed', $username, $wp_error_obj );
	}

	public function trigger_swpm_authenticate_failed_hook($username) {
		//Trigger authenticate failed action hook.
		$error_msg = $this->lastStatusMsg;
		$wp_error_obj = new WP_Error( 'swpm-authenticate-failed', $error_msg );
		do_action( 'swpm_authenticate_failed', $username, $wp_error_obj );
	}
}
