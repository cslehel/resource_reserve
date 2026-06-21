<?php

// Administrator only. Confirms or rejects a pending reservation. The decision
// is recorded in the log table.

declare( strict_types = 1 );

require_once __DIR__ . '/config.php';

require_request_method( 'POST' );

$request_body = read_json_request_body();

$reservation_id = ( int ) ( $request_body[ 'reservation_id' ] ?? 0 );
$decision = trim( ( string ) ( $request_body[ 'decision' ] ?? '' ) );

if ( !in_array( $decision, [ 'confirmed', 'rejected' ], true ) ) {
	send_error( 422, 'The decision must be either confirmed or rejected.' );
}

try {

	$database_connection = open_database_connection();

	$current_user = authenticate_user( $database_connection );

	$allowed_resource_ids = require_administrator( $database_connection, $current_user );

	$reservation_statement = $database_connection->prepare( 'SELECT reservation_id, resource_id, status FROM reservation WHERE reservation_id = :reservation_id LIMIT 1' );
	$reservation_statement->execute( [ ':reservation_id' => $reservation_id ] );

	$reservation_row = $reservation_statement->fetch();

	if ( $reservation_row === false ) {
		send_error( 404, 'The reservation was not found.' );
	}

	if ( !in_array( ( int ) $reservation_row[ 'resource_id' ], $allowed_resource_ids, true ) ) {
		send_error( 403, 'You are not allowed to review reservations for this resource.' );
	}

	if ( $reservation_row[ 'status' ] !== 'pending' ) {
		send_error( 409, 'This reservation was already reviewed.' );
	}

	$update_statement = $database_connection->prepare( 'UPDATE reservation SET status = :status WHERE reservation_id = :reservation_id' );
	$update_statement->execute( [
		':status' => $decision,
		':reservation_id' => $reservation_id
	] );

	$event_type = $decision === 'confirmed' ? 'reservation_approved' : 'reservation_rejected';

	write_log( $database_connection, ( int ) $current_user[ 'user_id' ], $event_type, 'Reservation #' . $reservation_id . ' ' . $decision . ' by ' . $current_user[ 'email' ] );

	send_success( [ 'message' => 'Reservation ' . $decision . '.' ] );

} catch ( Throwable $error ) {

	send_error( 500, 'Could not update the reservation right now.' );
}