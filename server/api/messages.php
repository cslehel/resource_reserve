<?php

// Returns the messages the signed in user is allowed to see: every message on a
// resource they administer, and every message on a resource where they have
// already posted. Each message is marked as sent ( by this user ) or received.

declare( strict_types = 1 );

require_once __DIR__ . '/config.php';

require_request_method( 'GET' );

try {

	$database_connection = open_database_connection();

	$current_user = authenticate_user( $database_connection );

	$query_text = 'SELECT message.message_id, message.resource_id, resource.resource_name, message.sender_user_id, message.body, message.created_at, sender.email AS sender_email FROM message INNER JOIN resource ON resource.resource_id = message.resource_id INNER JOIN user AS sender ON sender.user_id = message.sender_user_id WHERE message.resource_id IN ( SELECT resource_id FROM administrator_resource WHERE user_id = :administrator_user_id UNION SELECT DISTINCT resource_id FROM message WHERE sender_user_id = :participant_user_id ) ORDER BY message.created_at ASC';

	$message_statement = $database_connection->prepare( $query_text );
	$message_statement->execute( [
		':administrator_user_id' => $current_user[ 'user_id' ],
		':participant_user_id' => $current_user[ 'user_id' ]
	] );

	$message_list = [];

	foreach ( $message_statement->fetchAll() as $message_row ) {

		$message_list[] = [
			'message_id' => ( int ) $message_row[ 'message_id' ],
			'resource_id' => ( int ) $message_row[ 'resource_id' ],
			'resource_name' => $message_row[ 'resource_name' ],
			'sender_email' => $message_row[ 'sender_email' ],
			'body' => $message_row[ 'body' ],
			'created_at' => $message_row[ 'created_at' ],
			'is_sent' => ( int ) $message_row[ 'sender_user_id' ] === ( int ) $current_user[ 'user_id' ]
		];
	}

	send_success( [ 'messages' => $message_list ] );

} catch ( Throwable $error ) {

	send_error( 500, 'Could not load the messages right now.' );
}
