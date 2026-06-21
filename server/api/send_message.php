<?php

// Sends a message about a resource. Every administrator of that resource and
// every other user who already took part in the resource's conversation gets a
// queued notification.

declare( strict_types = 1 );

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/reservation_helpers.php';

require_request_method( 'POST' );

$request_body = read_json_request_body();

$resource_id = ( int ) ( $request_body[ 'resource_id' ] ?? 0 );
$body = trim( ( string ) ( $request_body[ 'body' ] ?? '' ) );

if ( $body === '' ) {
	send_error( 422, 'The message cannot be empty.' );
}

try {

	$database_connection = open_database_connection();

	$current_user = authenticate_user( $database_connection );

	$resource_row = load_active_resource( $database_connection, $resource_id );

	$insert_statement = $database_connection->prepare( 'INSERT INTO message ( resource_id, sender_user_id, body ) VALUES ( :resource_id, :sender_user_id, :body )' );
	$insert_statement->execute( [
		':resource_id' => $resource_id,
		':sender_user_id' => $current_user[ 'user_id' ],
		':body' => $body
	] );

	// Recipients are the resource administrators plus the users who already
	// posted on this resource, minus the sender.
	$recipient_user_ids = load_resource_administrator_user_ids( $database_connection, $resource_id );

	$participant_statement = $database_connection->prepare( 'SELECT DISTINCT sender_user_id FROM message WHERE resource_id = :resource_id' );
	$participant_statement->execute( [ ':resource_id' => $resource_id ] );

	foreach ( $participant_statement->fetchAll() as $participant_row ) {
		$recipient_user_ids[] = ( int ) $participant_row[ 'sender_user_id' ];
	}

	$recipient_user_ids = array_unique( $recipient_user_ids );

	$body_preview = mb_substr( $body, 0, 80 );

	foreach ( $recipient_user_ids as $recipient_user_id ) {

		if ( $recipient_user_id === ( int ) $current_user[ 'user_id' ] ) {
			continue;
		}

		enqueue_notification( $database_connection, $recipient_user_id, 'message', 'New message', $current_user[ 'email' ] . ' ( ' . $resource_row[ 'resource_name' ] . ' ): ' . $body_preview );
	}

	send_success( [ 'message' => 'Message sent.' ] );

} catch ( Throwable $error ) {

	send_error( 500, 'Could not send the message right now.' );
}
