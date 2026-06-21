<?php

// Returns the full notification history for the signed in user, newest first.
// Unlike notifications.php this never changes the delivered flag: it is only a
// read only inbox for the app to display.

declare( strict_types = 1 );

require_once __DIR__ . '/config.php';

require_request_method( 'GET' );

try {

	$database_connection = open_database_connection();

	$current_user = authenticate_user( $database_connection );

	$select_statement = $database_connection->prepare( 'SELECT notification_id, notification_type, title, body, is_delivered, created_at FROM notification WHERE user_id = :user_id ORDER BY created_at DESC, notification_id DESC LIMIT 200' );
	$select_statement->execute( [ ':user_id' => $current_user[ 'user_id' ] ] );

	$notification_list = [];

	foreach ( $select_statement->fetchAll() as $notification_row ) {

		$notification_list[] = [
			'notification_id' => ( int ) $notification_row[ 'notification_id' ],
			'notification_type' => $notification_row[ 'notification_type' ],
			'title' => $notification_row[ 'title' ],
			'body' => $notification_row[ 'body' ],
			'is_delivered' => ( int ) $notification_row[ 'is_delivered' ] === 1,
			'created_at' => $notification_row[ 'created_at' ]
		];
	}

	send_success( [ 'notifications' => $notification_list ] );

} catch ( Throwable $error ) {

	send_error( 500, 'Could not load the inbox right now.' );
}
