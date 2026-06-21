<?php

// Administrator only. Returns every reservation that is still waiting for a
// decision, across all users, so an administrator can review them.

declare( strict_types = 1 );

require_once __DIR__ . '/config.php';

require_request_method( 'GET' );

try {

	$database_connection = open_database_connection();

	$current_user = authenticate_user( $database_connection );

	$allowed_resource_ids = require_administrator( $database_connection, $current_user );

	// The ids come from our own table and are cast to integers, so they are safe
	// to place straight into the IN list.
	$resource_id_list = implode( ', ', $allowed_resource_ids );

	$query_text = "SELECT reservation.reservation_id, reservation.resource_id, resource.resource_name, reservation.begin_datetime, reservation.end_datetime, reservation.description, reservation.status, user.email AS owner_email FROM reservation INNER JOIN resource ON resource.resource_id = reservation.resource_id INNER JOIN user ON user.user_id = reservation.user_id WHERE reservation.status = 'pending' AND reservation.resource_id IN ( " . $resource_id_list . " ) ORDER BY reservation.created_at ASC";

	$reservation_statement = $database_connection->query( $query_text );

	$reservation_list = [];

	foreach ( $reservation_statement->fetchAll() as $reservation_row ) {

		$reservation_list[] = [
			'reservation_id' => ( int ) $reservation_row[ 'reservation_id' ],
			'resource_id' => ( int ) $reservation_row[ 'resource_id' ],
			'resource_name' => $reservation_row[ 'resource_name' ],
			'begin_datetime' => $reservation_row[ 'begin_datetime' ],
			'end_datetime' => $reservation_row[ 'end_datetime' ],
			'description' => $reservation_row[ 'description' ],
			'status' => $reservation_row[ 'status' ],
			'owner_email' => $reservation_row[ 'owner_email' ]
		];
	}

	send_success( [ 'reservations' => $reservation_list ] );

} catch ( Throwable $error ) {

	send_error( 500, 'Could not load the pending reservations right now.' );
}