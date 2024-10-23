<?php

/**
 * This class handles all active login limit related operations.
 */

class SwpmLimitActiveLogin {
	/**
	 * Check if active login limit enabled or not.
	 *
	 * @return bool
	 */
	public static function is_enabled() {
		return ! empty( SwpmSettings::get_instance()->get_value( 'enable-login-limiter' ) );
	}

	/**
	 * Get the max allowed active logins.
	 *
	 * @return int
	 */
	public static function max_active_logins() {
		return intval( SwpmSettings::get_instance()->get_value( 'maximum-active-logins', 3 ) );
	}

	/**
	 * Get the logic settings for restricting login limits.
	 *
	 * TODO: The settings has not finalized yet, assume it 'allow' for now.
	 *
	 * Options:
	 * 'allow': Allow new login by terminating all other old sessions when the limit is reached.
	 * 'block': Do not allow new login if the limit is reached. Users need to wait for the old login sessions to expire.
	 *
	 * @return string
	 */
	public static function login_logic() {
		// return SwpmSettings::get_instance()->get_value( 'login-logic', 'allow' );
		return 'allow';
	}

	/**
	 * Prepare a session token array data.
	 *
	 * @param $remember_me bool If 'remember me' input checked or not.
	 *
	 * @return array
	 */
	public static function prepare_new_session_token( $remember_me ) {
		if ( $remember_me ) {
			$expiration = time() + 14 * DAY_IN_SECONDS;
		} else {
			$expiration = time() + 2 * DAY_IN_SECONDS;
		}

		return array(
			'expiration' => $expiration,
			'ip'         => SwpmUtils::get_user_ip_address(),
			'ua'         => $_SERVER['HTTP_USER_AGENT'],
			'login'      => time(),
		);
	}

	/**
	 * Clear expired session token data and append new one for a member.
	 *
	 * @param $member_id         int
	 * @param $verifier          string Session Token data array key.
	 * @param $new_session_token array
	 *
	 * @return void
	 */
	public static function purge_member_session_tokens( $member_id, $verifier, $new_session_token ) {
		// Get valid session tokens.
		$session_tokens = self::get_valid_session_tokens( $member_id );

		$session_tokens[ hash( 'sha256', $verifier ) ] = $new_session_token;

		SwpmMembersMeta::update( $member_id, 'session_tokens', $session_tokens );
	}

	/**
	 * Set a new session token data for a member.
	 *
	 * @param $member_id     int
	 * @param $verifier      string Session Token data array key.
	 * @param $session_token array
	 *
	 * @return void
	 */
	public static function set_session_token( $member_id, $verifier, $session_token ) {
		$session_tokens = SwpmMembersMeta::get( $member_id, 'session_tokens', true );
		if ( empty( $session_tokens ) || ! is_array( $session_tokens ) ) {
			$session_tokens = array();
		}

		$session_tokens[ hash( 'sha256', $verifier ) ] = $session_token;

		SwpmMembersMeta::update( $member_id, 'session_tokens', $session_tokens );
	}


	/**
	 * Check if a member has a session token with specific verifier.
	 *
	 * @param $member_id int
	 * @param $verifier  string Session Token data array key.
	 *
	 * @return bool
	 */
	public static function has_members_session_token( $member_id, $verifier ) {
		$valid_tokens = self::get_valid_session_tokens( $member_id );

		return array_key_exists( hash( 'sha256', $verifier ), $valid_tokens );
	}

	/**
	 * Get only the valid session tokens.
	 *
	 * @param $member_id int
	 *
	 * @return array
	 */
	public static function get_valid_session_tokens( $member_id ) {
		$session_tokens = SwpmMembersMeta::get( $member_id, 'session_tokens', true );
		if ( ! is_array( $session_tokens ) ) {
			return array();
		}

		return array_filter( $session_tokens, 'SwpmLimitActiveLogin::is_token_still_valid' );
	}

	/**
	 * Check if the 'expiration' field exceeds current time.
	 *
	 * @param $session_token array Session token data.
	 *
	 * @return bool
	 */
	public static function is_token_still_valid( $session_token ) {
		if ( ! is_array( $session_token ) ) {
			return false;
		}

		return $session_token['expiration'] >= time();
	}

	/**
	 * Clear session token of a member.
	 * If a session_token verifier provided, only delete that, else clear all.
	 *
	 * @param $member_id int
	 *
	 * @param $verifier  string Session Token data array key.
	 *
	 * @return void
	 */
	public static function clear_session_token( $member_id, $verifier = '' ) {
		if ( empty( $member_id ) ) {
			return;
		}

		// Clear all tokens if specific session token provided.
		if ( empty( $verifier ) ) {
			SwpmMembersMeta::delete( $member_id, 'session_tokens' );

			return;
		}

		// CHeck is 'session_token' meta is empty.
		$session_tokens = SwpmMembersMeta::get( $member_id, 'session_tokens', true );
		if ( empty( $session_tokens ) || ! is_array( $session_tokens ) ) {
			return;
		}

		$verifier = hash( 'sha256', $verifier ); // The session_token key was saved as sha256 hash.

		// Check and remove target session token.
		if ( array_key_exists( $verifier, $session_tokens ) ) {
			unset( $session_tokens[ $verifier ] );
		}

		// Update member's session tokens.
		SwpmMembersMeta::update( $member_id, 'session_tokens', $session_tokens );
	}

	/**
	 * Deletes all session tokens meta of a member that has been expired.
	 *
	 * @param $member_id int
	 *
	 * @return void
	 */
	public static function delete_expired_session_tokens( $member_id ) {
		// Get valid session tokens.
		$session_tokens = self::get_valid_session_tokens( $member_id );
		// Update member's session tokens.
		SwpmMembersMeta::update( $member_id, 'session_tokens', $session_tokens );
	}

	/**
	 * Check if login limit has reached for a member.
	 *
	 * @param $member_id int
	 *
	 * @return bool
	 */
	public static function reached_active_login_limit( $member_id ) {
		$valid_tokens       = self::get_valid_session_tokens( $member_id );
		$valid_tokens_count = count( $valid_tokens );
		if ( $valid_tokens_count >= self::max_active_logins() ) {
			return true;
		}

		return false;
	}
}