<?php

// Validation helpers shared by the create and update reservation endpoints.

declare( strict_types = 1 );

require_once __DIR__ . '/config.php';

const RESERVATION_DATETIME_FORMAT = 'Y-m-d H:i';


// ---------------------------------------------------------------------------
// parse_reservation_datetime
// Returns a DateTimeImmutable for a strict 'year-month-day hour:minute' string,
// or null when the value is not a real moment in time.
// ---------------------------------------------------------------------------
function parse_reservation_datetime( string $value ) : ?DateTimeImmutable {

	$parsed_datetime = DateTimeImmutable::createFromFormat( '!' . RESERVATION_DATETIME_FORMAT, $value );

	if ( $parsed_datetime === false ) {
		return null;
	}

	if ( $parsed_datetime->format( RESERVATION_DATETIME_FORMAT ) !== $value ) {
		return null;
	}

	return $parsed_datetime;
}


// ---------------------------------------------------------------------------
// load_active_resource
// Returns the resource row, or stops with an error when it is missing.
// ---------------------------------------------------------------------------
function load_active_resource( PDO $database_connection, int $resource_id ) : array {

	$resource_statement = $database_connection->prepare( 'SELECT resource_id, resource_name, maximum_period_value, maximum_period_unit FROM resource WHERE resource_id = :resource_id AND is_active = 1 LIMIT 1' );
	$resource_statement->execute( [ ':resource_id' => $resource_id ] );

	$resource_row = $resource_statement->fetch();

	if ( $resource_row === false ) {
		send_error( 422, 'The selected resource does not exist.' );
	}

	return $resource_row;
}


// ---------------------------------------------------------------------------
// resolve_maximum_period_in_minutes
// Translates the resource's maximum period ( a value plus a day or hour unit )
// into a whole number of minutes.
// ---------------------------------------------------------------------------
function resolve_maximum_period_in_minutes( array $resource_row ) : int {

	$maximum_period_value = ( int ) $resource_row[ 'maximum_period_value' ];

	if ( $resource_row[ 'maximum_period_unit' ] === 'hour' ) {
		return max( 1, $maximum_period_value ) * 60;
	}

	return max( 1, $maximum_period_value ) * 24 * 60;
}


// ---------------------------------------------------------------------------
// describe_maximum_period
// Builds a human readable limit such as "8 hour(s)" or "3 day(s)" for messages.
// ---------------------------------------------------------------------------
function describe_maximum_period( array $resource_row ) : string {

	$maximum_period_value = max( 1, ( int ) $resource_row[ 'maximum_period_value' ] );

	return $maximum_period_value . ' ' . $resource_row[ 'maximum_period_unit' ] . '(s)';
}


// ---------------------------------------------------------------------------
// validate_reservation_timeframe
// Checks the begin / end datetime pair against the resource rules. Stops with
// an error when the timeframe is not allowed.
// ---------------------------------------------------------------------------
function validate_reservation_timeframe( array $resource_row, string $begin_datetime_text, string $end_datetime_text ) : void {

	$begin_datetime = parse_reservation_datetime( $begin_datetime_text );
	$end_datetime = parse_reservation_datetime( $end_datetime_text );

	if ( $begin_datetime === null || $end_datetime === null ) {
		send_error( 422, 'Both moments must use the year-month-day hour:minute format.' );
	}

	if ( $end_datetime <= $begin_datetime ) {
		send_error( 422, 'The end must be after the begin.' );
	}

	$length_in_minutes = ( int ) round( ( $end_datetime->getTimestamp() - $begin_datetime->getTimestamp() ) / 60 );

	$maximum_period_in_minutes = resolve_maximum_period_in_minutes( $resource_row );

	if ( $length_in_minutes > $maximum_period_in_minutes ) {
		send_error( 422, 'This resource may be reserved for at most ' . describe_maximum_period( $resource_row ) . '.' );
	}
}


// ---------------------------------------------------------------------------
// count_active_reservations
// Returns how many upcoming pending or confirmed reservations a user holds.
// ---------------------------------------------------------------------------
function count_active_reservations( PDO $database_connection, int $user_id ) : int {

	$count_statement = $database_connection->prepare( "SELECT COUNT( * ) AS active_count FROM reservation WHERE user_id = :user_id AND status IN ( 'pending', 'confirmed' ) AND end_datetime >= NOW()" );
	$count_statement->execute( [ ':user_id' => $user_id ] );

	$count_row = $count_statement->fetch();

	return ( int ) $count_row[ 'active_count' ];
}