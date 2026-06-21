<?php

// Creates a new reservation for the signed in user. The new reservation always
// starts in the 'pending' status so an administrator can review it. The number
// of upcoming reservations a user may hold is limited by a value in the
// setting table.

declare( strict_types = 1 );

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/reservation_helpers.php';

require_request_method( 'POST' );

$request_body = read_json_request_body();

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

	// Limit how many reservations one account can fire off in a short time, on
	// top of the cap on simultaneously active reservations checked below.
	enforce_rate_limit( $database_connection, 'create_reservation', ( string ) $current_user[ 'user_id' ], 30, 600 );

	$resource_row = load_active_resource( $database_connection, $resource_id );

	validate_reservation_timeframe( $resource_row, $begin_datetime_text, $end_datetime_text );

	$maximum_active_reservations = ( int ) read_setting_value( $database_connection, 'maximum_active_reservations_per_user', '5' );

	$current_active_reservations = count_active_reservations( $database_connection, ( int ) $current_user[ 'user_id' ] );

	if ( $current_active_reservations >= $maximum_active_reservations ) {
		send_error( 409, 'You have reached your limit of ' . $maximum_active_reservations . ' active reservations.' );
	}

	$insert_statement = $database_connection->prepare( 'INSERT INTO reservation ( user_id, resource_id, begin_datetime, end_datetime, description ) VALUES ( :user_id, :resource_id, :begin_datetime, :end_datetime, :description )' );
	$insert_statement->execute( [
		':user_id' => $current_user[ 'user_id' ],
		':resource_id' => $resource_id,
		':begin_datetime' => $begin_datetime_text,
		':end_datetime' => $end_datetime_text,
		':description' => $description
	] );

	$new_reservation_id = ( int ) $database_connection->lastInsertId();

	write_log( $database_connection, ( int ) $current_user[ 'user_id' ], 'reservation_created', 'Reservation #' . $new_reservation_id . ' created for resource #' . $resource_id );

	// Notify every administrator of this resource ( except the creator ).
	$administrator_user_ids = load_resource_administrator_user_ids( $database_connection, $resource_id );

	foreach ( $administrator_user_ids as $administrator_user_id ) {

		if ( $administrator_user_id === ( int ) $current_user[ 'user_id' ] ) {
			continue;
		}

		enqueue_notification( $database_connection, $administrator_user_id, 'reservation', 'New reservation', $current_user[ 'email' ] . ' reserved ' . $resource_row[ 'resource_name' ] . ' ( ' . $begin_datetime_text . ' )' );
	}

	send_success( [
		'message' => 'Reservation created and waiting for confirmation.',
		'reservation_id' => $new_reservation_id
	] );

} catch ( Throwable $error ) {

	send_error( 500, 'Could not create the reservation right now.' );
}