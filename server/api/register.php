<?php

// Creates a new account from an email and password, then sends a verification
// link to the given email address. The account cannot sign in until the link
// has been opened.

declare( strict_types = 1 );

require_once __DIR__ . '/config.php';

require_request_method( 'POST' );

$request_body = read_json_request_body();

$email = trim( ( string ) ( $request_body[ 'email' ] ?? '' ) );
$password = ( string ) ( $request_body[ 'password' ] ?? '' );

if ( !filter_var( $email, FILTER_VALIDATE_EMAIL ) || strlen( $email ) > 254 ) {
	send_error( 422, 'Please provide a valid email address.' );
}

if ( strlen( $password ) < 8 ) {
	send_error( 422, 'The password must be at least 8 characters long.' );
}

if ( strlen( $password ) > 200 ) {
	send_error( 422, 'The password is too long.' );
}

try {

	$database_connection = open_database_connection();

	// Cap how many accounts a single network address can create, to stop
	// automated mass registration and the verification emails it would send.
	enforce_rate_limit( $database_connection, 'register_ip', get_client_ip_address(), 5, 3600 );
	enforce_rate_limit( $database_connection, 'register_email', strtolower( $email ), 3, 3600 );

	$existing_statement = $database_connection->prepare( 'SELECT user_id FROM user WHERE email = :email LIMIT 1' );
	$existing_statement->execute( [ ':email' => $email ] );

	if ( $existing_statement->fetch() !== false ) {
		send_error( 409, 'An account with this email already exists.' );
	}

	$password_hash = password_hash( $password, PASSWORD_DEFAULT );
	$verification_token = bin2hex( random_bytes( 32 ) );

	$insert_statement = $database_connection->prepare( 'INSERT INTO user ( email, password_hash, verification_token ) VALUES ( :email, :password_hash, :verification_token )' );
	$insert_statement->execute( [
		':email' => $email,
		':password_hash' => $password_hash,
		':verification_token' => $verification_token
	] );

	$new_user_id = ( int ) $database_connection->lastInsertId();

	write_log( $database_connection, $new_user_id, 'registration', 'Account registered: ' . $email );

	$verification_link = API_PUBLIC_BASE_URL . '/verify_email.php?token=' . urlencode( $verification_token );

	$email_subject = 'Confirm your Resource Reserve account';
	$email_message = "Welcome to Resource Reserve.\n\nPlease confirm your account by opening this link:\n" . $verification_link . "\n\nIf you did not create this account you can ignore this message.";
	$email_headers = 'From: ' . VERIFICATION_EMAIL_SENDER;

	// The return value is ignored on purpose: on many development machines no
	// mail transport is configured.
	@mail( $email, $email_subject, $email_message, $email_headers );

	$response_payload = [
		'message' => 'Account created. Please check your email to verify your account.'
	];

	// Only expose the link directly during development. In production it must be
	// delivered by email alone, never returned in the response.
	if ( DEVELOPMENT_MODE ) {
		$response_payload[ 'verification_link' ] = $verification_link;
	}

	send_success( $response_payload );

} catch ( Throwable $error ) {

	send_error( 500, 'Could not create the account right now.' );
}