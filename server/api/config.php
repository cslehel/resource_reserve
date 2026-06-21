<?php

// Shared configuration and helper functions for the Resource Reserve API.
// Every endpoint includes this file first.

declare( strict_types = 1 );

// ---------------------------------------------------------------------------
// Database connection settings. Adjust these to match the hosting environment.
// ---------------------------------------------------------------------------
const DATABASE_HOST = '127.0.0.1';
const DATABASE_NAME = 'database';
const DATABASE_USER = 'user';
const DATABASE_PASSWORD = 'password';

// Public base address of this API. It is used to build the email
// verification link, so it must be reachable from the user's mail client.
const API_PUBLIC_BASE_URL = 'https://example.com/resource_reserve/api';

// Address shown as the sender of verification emails.
const VERIFICATION_EMAIL_SENDER = 'resource-reserve@example.com';


// ---------------------------------------------------------------------------
// open_database_connection
// Returns a configured PDO connection that throws exceptions on error.
// ---------------------------------------------------------------------------
function open_database_connection() : PDO {

	$data_source_name = 'mysql:host=' . DATABASE_HOST . ';dbname=' . DATABASE_NAME . ';charset=utf8mb4';

	$connection_options = [
		PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
		PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
		PDO::ATTR_EMULATE_PREPARES => false
	];

	return new PDO( $data_source_name, DATABASE_USER, DATABASE_PASSWORD, $connection_options );
}


// ---------------------------------------------------------------------------
// read_json_request_body
// Decodes the incoming JSON request body into an associative array.
// ---------------------------------------------------------------------------
function read_json_request_body() : array {

	$raw_body = file_get_contents( 'php://input' );

	if ( $raw_body === false || $raw_body === '' ) {
		return [];
	}

	$decoded_body = json_decode( $raw_body, true );

	if ( !is_array( $decoded_body ) ) {
		return [];
	}

	return $decoded_body;
}


// ---------------------------------------------------------------------------
// send_json_response
// Emits a JSON response with the given HTTP status code and stops execution.
// ---------------------------------------------------------------------------
function send_json_response( int $status_code, array $payload ) : void {

	http_response_code( $status_code );
	header( 'Content-Type: application/json; charset=utf-8' );

	echo json_encode( $payload );

	exit;
}


// ---------------------------------------------------------------------------
// send_success
// Convenience wrapper that returns a successful JSON response.
// ---------------------------------------------------------------------------
function send_success( array $data = [] ) : void {

	send_json_response( 200, [ 'success' => true, 'data' => $data ] );
}


// ---------------------------------------------------------------------------
// send_error
// Convenience wrapper that returns a failed JSON response.
// ---------------------------------------------------------------------------
function send_error( int $status_code, string $message ) : void {

	send_json_response( $status_code, [ 'success' => false, 'message' => $message ] );
}


// ---------------------------------------------------------------------------
// require_request_method
// Stops with a 405 error unless the request uses the expected HTTP method.
// ---------------------------------------------------------------------------
function require_request_method( string $expected_method ) : void {

	$actual_method = $_SERVER[ 'REQUEST_METHOD' ] ?? 'GET';

	if ( strtoupper( $actual_method ) !== strtoupper( $expected_method ) ) {
		send_error( 405, 'This endpoint only accepts ' . $expected_method . ' requests.' );
	}
}


// ---------------------------------------------------------------------------
// authenticate_user
// Reads the access token from the Authorization header and returns the
// matching user row, or stops with a 401 error when the token is invalid.
// ---------------------------------------------------------------------------
function authenticate_user( PDO $database_connection ) : array {

	$authorization_header = $_SERVER[ 'HTTP_AUTHORIZATION' ] ?? '';

	$access_token = '';

	if ( stripos( $authorization_header, 'Bearer ' ) === 0 ) {
		$access_token = trim( substr( $authorization_header, 7 ) );
	}

	if ( $access_token === '' ) {
		send_error( 401, 'Missing access token.' );
	}

	$statement = $database_connection->prepare( 'SELECT user_id, email, is_email_verified FROM user WHERE access_token = :access_token LIMIT 1' );
	$statement->execute( [ ':access_token' => $access_token ] );

	$user_row = $statement->fetch();

	if ( $user_row === false ) {
		send_error( 401, 'Invalid access token.' );
	}

	return $user_row;
}


// ---------------------------------------------------------------------------
// require_administrator
// A user is an administrator when they have at least one assigned resource in
// the administrator_resource table. Stops with a 403 error otherwise, and
// returns the list of resource ids the administrator may review on success.
// ---------------------------------------------------------------------------
function require_administrator( PDO $database_connection, array $user_row ) : array {

	$allowed_resource_ids = load_administrator_resource_ids( $database_connection, ( int ) $user_row[ 'user_id' ] );

	if ( count( $allowed_resource_ids ) === 0 ) {
		send_error( 403, 'This action requires an administrator account.' );
	}

	return $allowed_resource_ids;
}


// ---------------------------------------------------------------------------
// load_administrator_resource_ids
// Returns the list of resource ids an administrator is allowed to approve. An
// administrator with no assignments gets an empty list and can review nothing.
// ---------------------------------------------------------------------------
function load_administrator_resource_ids( PDO $database_connection, int $user_id ) : array {

	$statement = $database_connection->prepare( 'SELECT resource_id FROM administrator_resource WHERE user_id = :user_id' );
	$statement->execute( [ ':user_id' => $user_id ] );

	$resource_ids = [];

	foreach ( $statement->fetchAll() as $assignment_row ) {
		$resource_ids[] = ( int ) $assignment_row[ 'resource_id' ];
	}

	return $resource_ids;
}


// ---------------------------------------------------------------------------
// load_resource_administrator_user_ids
// Returns the user ids of every administrator assigned to a given resource.
// ---------------------------------------------------------------------------
function load_resource_administrator_user_ids( PDO $database_connection, int $resource_id ) : array {

	$statement = $database_connection->prepare( 'SELECT user_id FROM administrator_resource WHERE resource_id = :resource_id' );
	$statement->execute( [ ':resource_id' => $resource_id ] );

	$user_ids = [];

	foreach ( $statement->fetchAll() as $assignment_row ) {
		$user_ids[] = ( int ) $assignment_row[ 'user_id' ];
	}

	return $user_ids;
}


// ---------------------------------------------------------------------------
// enqueue_notification
// Queues one notification for a recipient. Like logging, a failure here must
// never break the main flow.
// ---------------------------------------------------------------------------
function enqueue_notification( PDO $database_connection, int $user_id, string $notification_type, string $title, string $body ) : void {

	try {

		$statement = $database_connection->prepare( 'INSERT INTO notification ( user_id, notification_type, title, body ) VALUES ( :user_id, :notification_type, :title, :body )' );
		$statement->execute( [
			':user_id' => $user_id,
			':notification_type' => $notification_type,
			':title' => $title,
			':body' => $body
		] );

	} catch ( Throwable $ignored_error ) {
	}
}


// ---------------------------------------------------------------------------
// write_log
// Appends one row to the log table. Logging must never break the main flow, so
// any failure here is swallowed.
// ---------------------------------------------------------------------------
function write_log( PDO $database_connection, ?int $user_id, string $event_type, string $message ) : void {

	try {

		$statement = $database_connection->prepare( 'INSERT INTO log ( user_id, event_type, message ) VALUES ( :user_id, :event_type, :message )' );
		$statement->execute( [
			':user_id' => $user_id,
			':event_type' => $event_type,
			':message' => $message
		] );

	} catch ( Throwable $ignored_error ) {
	}
}


// ---------------------------------------------------------------------------
// read_setting_value
// Returns the value of a setting, or the given fallback when it is missing.
// ---------------------------------------------------------------------------
function read_setting_value( PDO $database_connection, string $setting_key, string $fallback_value ) : string {

	$statement = $database_connection->prepare( 'SELECT setting_value FROM setting WHERE setting_key = :setting_key LIMIT 1' );
	$statement->execute( [ ':setting_key' => $setting_key ] );

	$setting_row = $statement->fetch();

	if ( $setting_row === false ) {
		return $fallback_value;
	}

	return ( string ) $setting_row[ 'setting_value' ];
}