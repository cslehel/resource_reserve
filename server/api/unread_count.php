<?php

// Returns how many notifications the signed in user has not read yet, so the
// app can show an unread badge.

declare( strict_types = 1 );

require_once __DIR__ . '/config.php';

require_request_method( 'GET' );

try {

	$database_connection = open_database_connection();

	$current_user = authenticate_user( $database_connection );

	$count_statement = $database_connection->prepare( 'SELECT COUNT( * ) AS unread_count FROM notification WHERE user_id = :user_id AND is_read = 0' );
	$count_statement->execute( [ ':user_id' => $current_user[ 'user_id' ] ] );

	$count_row = $count_statement->fetch();

	send_success( [ 'unread_count' => ( int ) $count_row[ 'unread_count' ] ] );

} catch ( Throwable $error ) {

	send_error( 500, 'Could not load the unread count right now.' );
}
