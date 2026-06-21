<?php

// Lets a user change one of their own reservations, but only while it is still
// 'pending'. Once an administrator confirms ( or rejects ) it, the reservation
// can no longer be edited.

declare( strict_types = 1 );

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/reservation_helpers.php';

require_request_method( 'POST' );

$request_body = read_json_request_body();

$reservation_id = ( int ) ( $request_body[ 'reservation_id' ] ?? 0 );
$resource_id = ( int ) ( $request_body[ 'resource_id' ] ?? 0 );
$begin_datetime_text = trim( ( string ) ( $request_body[ 'begin_datetime' ] ?? '' ) );
$end_datetime_text = trim( ( string ) ( $request_body[ 'end_datetime' ] ?? '' ) );
$description = trim( ( string ) ( $request_body[ 'description' ] ?? '' ) );

if ( mb_strlen( $description ) > 500 ) {
	send_error( 422, 'The description is too long.' );
}

try {

	$database_connection = open_database_connection();

	$current_user = authenticate_user( $database_connection );

	$reservation_statement = $database_connection->prepare( 'SELECT reservation_id, user_id, status FROM reservation WHERE reservation_id = :reservation_id LIMIT 1' );
	$reservation_statement->execute( [ ':reservation_id' => $reservation_id ] );

	$reservation_row = $reservation_statement->fetch();

	if ( $reservation_row === false ) {
		send_error( 404, 'The reservation was not found.' );
	}

	if ( ( int ) $reservation_row[ 'user_id' ] !== ( int ) $current_user[ 'user_id' ] ) {
		send_error( 403, 'You can only change your own reservations.' );
	}

	if ( $reservation_row[ 'status' ] !== 'pending' ) {
		send_error( 409, 'This reservation was already reviewed and can no longer be changed.' );
	}

	$resource_row = load_active_resource( $database_connection, $resource_id );

	validate_reservation_timeframe( $resource_row, $begin_datetime_text, $end_datetime_text );

	$update_statement = $database_connection->prepare( 'UPDATE reservation SET resource_id = :resource_id, begin_datetime = :begin_datetime, end_datetime = :end_datetime, description = :description WHERE reservation_id = :reservation_id' );
	$update_statement->execute( [
		':resource_id' => $resource_id,
		':begin_datetime' => $begin_datetime_text,
		':end_datetime' => $end_datetime_text,
		':description' => $description,
		':reservation_id' => $reservation_id
	] );

	write_log( $database_connection, ( int ) $current_user[ 'user_id' ], 'reservation_updated', 'Reservation #' . $reservation_id . ' updated' );

	send_success( [ 'message' => 'Reservation updated.' ] );

} catch ( Throwable $error ) {

	send_error( 500, 'Could not update the reservation right now.' );
}