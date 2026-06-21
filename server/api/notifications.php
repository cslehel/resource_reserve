<?php

// Returns the notifications that have been queued for the signed in user and
// not yet delivered, then marks exactly those rows as delivered so each one is
// shown only once by the app.

declare( strict_types = 1 );

require_once __DIR__ . '/config.php';

require_request_method( 'GET' );

try {

	$database_connection = open_database_connection();

	$current_user = authenticate_user( $database_connection );

	$select_statement = $database_connection->prepare( 'SELECT notification_id, notification_type, title, body, created_at FROM notification WHERE user_id = :user_id AND is_delivered = 0 ORDER BY created_at ASC' );
	$select_statement->execute( [ ':user_id' => $current_user[ 'user_id' ] ] );

	$notification_list = [];
	$delivered_ids = [];

	foreach ( $select_statement->fetchAll() as $notification_row ) {

		$delivered_ids[] = ( int ) $notification_row[ 'notification_id' ];

		$notification_list[] = [
			'notification_id' => ( int ) $notification_row[ 'notification_id' ],
			'notification_type' => $notification_row[ 'notification_type' ],
			'title' => $notification_row[ 'title' ],
			'body' => $notification_row[ 'body' ],
			'created_at' => $notification_row[ 'created_at' ]
		];
	}

	// Mark only the rows that were returned, by their own ids, to avoid hiding
	// notifications that arrived between the select and this update.
	if ( count( $delivered_ids ) > 0 ) {

		$id_list = implode( ', ', $delivered_ids );
		$database_connection->query( 'UPDATE notification SET is_delivered = 1 WHERE notification_id IN ( ' . $id_list . ' )' );
	}

	send_success( [ 'notifications' => $notification_list ] );

} catch ( Throwable $error ) {

	send_error( 500, 'Could not load the notifications right now.' );
}
