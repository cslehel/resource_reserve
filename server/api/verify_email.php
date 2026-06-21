<?php

// Handles the link that is sent in the verification email. Opening it in a
// browser marks the matching account as verified. This endpoint replies with
// a small HTML page instead of JSON because a person opens it directly.

declare( strict_types = 1 );

require_once __DIR__ . '/config.php';

require_request_method( 'GET' );

$verification_token = trim( ( string ) ( $_GET[ 'token' ] ?? '' ) );

function render_verification_page( string $title, string $body_text ) : void {

	http_response_code( 200 );
	header( 'Content-Type: text/html; charset=utf-8' );

	echo '<!doctype html><html lang="en"><head><meta charset="utf-8">';
	echo '<meta name="viewport" content="width=device-width, initial-scale=1">';
	echo '<title>' . htmlspecialchars( $title ) . '</title></head>';
	echo '<body style="font-family: sans-serif; text-align: center; padding: 48px;">';
	echo '<h1>' . htmlspecialchars( $title ) . '</h1>';
	echo '<p>' . htmlspecialchars( $body_text ) . '</p>';
	echo '</body></html>';

	exit;
}

if ( $verification_token === '' ) {
	render_verification_page( 'Invalid link', 'This verification link is missing its token.' );
}

try {

	$database_connection = open_database_connection();

	$user_statement = $database_connection->prepare( 'SELECT user_id, is_email_verified FROM user WHERE verification_token = :verification_token LIMIT 1' );
	$user_statement->execute( [ ':verification_token' => $verification_token ] );

	$user_row = $user_statement->fetch();

	if ( $user_row === false ) {
		render_verification_page( 'Invalid link', 'This verification link is no longer valid.' );
	}

	if ( ( int ) $user_row[ 'is_email_verified' ] === 1 ) {
		render_verification_page( 'Already verified', 'Your account was already verified. You can sign in from the app.' );
	}

	$update_statement = $database_connection->prepare( 'UPDATE user SET is_email_verified = 1, verification_token = NULL WHERE user_id = :user_id' );
	$update_statement->execute( [ ':user_id' => $user_row[ 'user_id' ] ] );

	render_verification_page( 'Account verified', 'Thank you. Your account is now active. You can sign in from the app.' );

} catch ( Throwable $error ) {

	render_verification_page( 'Something went wrong', 'We could not verify your account right now. Please try again later.' );
}