<?php

// Verifies an email and password, then issues a fresh access token that the
// Android app stores and sends with every following request.

declare( strict_types = 1 );

require_once __DIR__ . '/config.php';

require_request_method( 'POST' );

$request_body = read_json_request_body();

$email = trim( ( string ) ( $request_body[ 'email' ] ?? '' ) );
$password = ( string ) ( $request_body[ 'password' ] ?? '' );

if ( $email === '' || $password === '' ) {
	send_error( 422, 'Email and password are required.' );
}

try {

	$database_connection = open_database_connection();

	// Throttle sign in attempts by network address and by email so an attacker
	// cannot brute force passwords or probe which emails are registered.
	enforce_rate_limit( $database_connection, 'login_ip', get_client_ip_address(), 10, 900 );
	enforce_rate_limit( $database_connection, 'login_email', strtolower( $email ), 10, 900 );

	$user_statement = $database_connection->prepare( 'SELECT user_id, email, password_hash, is_email_verified FROM user WHERE email = :email LIMIT 1' );
	$user_statement->execute( [ ':email' => $email ] );

	$user_row = $user_statement->fetch();

	// Always run a hash comparison, even when no account matched, so the response
	// takes the same time whether or not the email exists. This denies the timing
	// signal an attacker would otherwise use to enumerate registered accounts.
	$password_hash_to_check = $user_row === false
		? '$2y$10$usesomesillystringforsalt0123456789abcdefghijklmnopqrstuv'
		: $user_row[ 'password_hash' ];

	$is_password_correct = password_verify( $password, $password_hash_to_check );

	if ( $user_row === false || !$is_password_correct ) {
		send_error( 401, 'The email or password is incorrect.' );
	}

	if ( ( int ) $user_row[ 'is_email_verified' ] !== 1 ) {
		send_error( 403, 'Please verify your email address before signing in.' );
	}

	$access_token = bin2hex( random_bytes( 32 ) );

	$token_statement = $database_connection->prepare( 'UPDATE user SET access_token = :access_token WHERE user_id = :user_id' );
	$token_statement->execute( [
		':access_token' => $access_token,
		':user_id' => $user_row[ 'user_id' ]
	] );

	write_log( $database_connection, ( int ) $user_row[ 'user_id' ], 'login', 'User signed in: ' . $user_row[ 'email' ] );

	// A user is an administrator when they have at least one assigned resource.
	$administrator_resource_ids = load_administrator_resource_ids( $database_connection, ( int ) $user_row[ 'user_id' ] );

	send_success( [
		'access_token' => $access_token,
		'user_id' => ( int ) $user_row[ 'user_id' ],
		'email' => $user_row[ 'email' ],
		'is_administrator' => count( $administrator_resource_ids ) > 0
	] );

} catch ( Throwable $error ) {

	send_error( 500, 'Could not sign you in right now.' );
}