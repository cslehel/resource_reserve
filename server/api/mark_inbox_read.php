<?php

// Marks every notification of the signed in user as read. The app calls this
// when the inbox is opened so the unread badge clears.

declare( strict_types = 1 );

require_once __DIR__ . '/config.php';

require_request_method( 'POST' );

try {

	$database_connection = open_database_connection();

	$current_user = authenticate_user( $database_connection );

	$update_statement = $database_connection->prepare( 'UPDATE notification SET is_read = 1 WHERE user_id = :user_id AND is_read = 0' );
	$update_statement->execute( [ ':user_id' => $current_user[ 'user_id' ] ] );

	send_success( [ 'message' => 'Inbox marked as read.' ] );

} catch ( Throwable $error ) {

	send_error( 500, 'Could not update the inbox right now.' );
}
