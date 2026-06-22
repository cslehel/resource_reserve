<?php

// Validation helpers shared by the create and update reservation endpoints.

declare( strict_types = 1 );

require_once __DIR__ . '/config.php';

const RESERVATION_DATETIME_FORMAT = 'Y-m-d H:i';


// ---------------------------------------------------------------------------
// read_reservation_lead_minutes
// How many minutes from now a reservation must begin, taken from the setting
// table so it can be changed without touching the code. The literal is only a
// safety net for a database that has not been seeded.
// ---------------------------------------------------------------------------
function read_reservation_lead_minutes( PDO $database_connection ) : int {

	return ( int ) read_setting_value( $database_connection, 'reservation_minimum_lead_minutes', '60' );
}


// ---------------------------------------------------------------------------
// read_reservation_horizon_months
// How many months from now a reservation may begin at the latest, taken from
// the setting table.
// ---------------------------------------------------------------------------
function read_reservation_horizon_months( PDO $database_connection ) : int {

	return ( int ) read_setting_value( $database_connection, 'reservation_maximum_horizon_months', '3' );
}


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

	$resource_statement = $database_connection->prepare( 'SELECT resource_id, resource_name, maximum_period_value, maximum_period_unit, availability_mode, active_start_time, active_end_time, working_days FROM resource WHERE resource_id = :resource_id AND is_active = 1 LIMIT 1' );
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
function validate_reservation_timeframe( PDO $database_connection, array $resource_row, string $begin_datetime_text, string $end_datetime_text ) : void {

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

	$now = new DateTimeImmutable( 'now' );

	$minimum_lead_minutes = read_reservation_lead_minutes( $database_connection );
	$maximum_horizon_months = read_reservation_horizon_months( $database_connection );

	if ( $begin_datetime < $now->modify( '+' . $minimum_lead_minutes . ' minutes' ) ) {
		send_error( 422, 'A reservation must begin at least ' . $minimum_lead_minutes . ' minutes from now.' );
	}

	if ( $begin_datetime > $now->modify( '+' . $maximum_horizon_months . ' months' ) ) {
		send_error( 422, 'A reservation may not begin more than ' . $maximum_horizon_months . ' months from now.' );
	}

	if ( ( $resource_row[ 'availability_mode' ] ?? 'always' ) === 'scheduled' ) {
		validate_resource_schedule( $resource_row, $begin_datetime, $end_datetime );
	}
}


// ---------------------------------------------------------------------------
// time_text_to_minutes
// Turns a 'HH:MM:SS' ( or 'HH:MM' ) time into minutes since midnight.
// ---------------------------------------------------------------------------
function time_text_to_minutes( string $time_text ) : int {

	$time_parts = explode( ':', $time_text );

	$hours = ( int ) ( $time_parts[ 0 ] ?? 0 );
	$minutes = ( int ) ( $time_parts[ 1 ] ?? 0 );

	return $hours * 60 + $minutes;
}


// ---------------------------------------------------------------------------
// describe_minutes_of_day
// Formats minutes since midnight back into 'HH:MM', showing the end of the day
// ( 1440 ) as '24:00'.
// ---------------------------------------------------------------------------
function describe_minutes_of_day( int $minutes_of_day ) : string {

	if ( $minutes_of_day >= 1440 ) {
		return '24:00';
	}

	return sprintf( '%02d:%02d', intdiv( $minutes_of_day, 60 ), $minutes_of_day % 60 );
}


// ---------------------------------------------------------------------------
// validate_resource_schedule
// For a scheduled resource, walks the reservation one calendar day at a time
// and stops with an error when any day it touches is not a working day or the
// portion used on that day falls outside the active hours window. This handles
// single day and multi day reservations alike.
// ---------------------------------------------------------------------------
function validate_resource_schedule( array $resource_row, DateTimeImmutable $begin_datetime, DateTimeImmutable $end_datetime ) : void {

	$working_days = array_filter( array_map( 'trim', explode( ',', strtolower( ( string ) $resource_row[ 'working_days' ] ) ) ) );

	$active_start_minutes = time_text_to_minutes( ( string ) $resource_row[ 'active_start_time' ] );

	// An active end of midnight ( 00:00 ) means the window runs to the end of
	// the day, which we represent as 1440 minutes.
	$active_end_minutes = time_text_to_minutes( ( string ) $resource_row[ 'active_end_time' ] );

	if ( $active_end_minutes === 0 ) {
		$active_end_minutes = 1440;
	}

	$active_window_text = describe_minutes_of_day( $active_start_minutes ) . ' - ' . describe_minutes_of_day( $active_end_minutes );

	$day_start = $begin_datetime;

	while ( $day_start < $end_datetime ) {

		$next_midnight = $day_start->modify( 'tomorrow midnight' );

		$segment_end = $next_midnight < $end_datetime ? $next_midnight : $end_datetime;

		$weekday = strtolower( $day_start->format( 'l' ) );

		if ( !in_array( $weekday, $working_days, true ) ) {
			send_error( 422, 'This resource is not available on ' . $day_start->format( 'Y-m-d' ) . ' ( ' . ucfirst( $weekday ) . ' ).' );
		}

		$segment_start_minutes = ( int ) $day_start->format( 'H' ) * 60 + ( int ) $day_start->format( 'i' );

		// When the used part of this day runs into the next midnight, the end is
		// the end of the day ( 1440 ), not 00:00 of the following day.
		$segment_end_minutes = $segment_end->format( 'Y-m-d' ) === $day_start->format( 'Y-m-d' )
			? ( int ) $segment_end->format( 'H' ) * 60 + ( int ) $segment_end->format( 'i' )
			: 1440;

		if ( $segment_start_minutes < $active_start_minutes || $segment_end_minutes > $active_end_minutes ) {
			send_error( 422, 'This resource can only be reserved during its active hours ( ' . $active_window_text . ' ).' );
		}

		$day_start = $next_midnight;
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


// ---------------------------------------------------------------------------
// assert_no_overlapping_reservation
// Stops with a 409 error when another active ( pending or confirmed )
// reservation for the same resource overlaps the requested timeframe. Rejected
// reservations and ones that have already ended are ignored because they no
// longer hold the resource. The
// reservation identified by $ignore_reservation_id is skipped so a user can
// edit their own booking without it colliding with itself.
//
// Two timeframes overlap when one begins before the other ends and ends after
// the other begins. The comparison is strict ( < and > ) so two reservations
// that merely touch ( one ends exactly when the next begins ) are allowed.
// ---------------------------------------------------------------------------
function assert_no_overlapping_reservation( PDO $database_connection, int $resource_id, string $begin_datetime_text, string $end_datetime_text, int $ignore_reservation_id = 0 ) : void {

	$overlap_statement = $database_connection->prepare(
		"SELECT begin_datetime, end_datetime FROM reservation WHERE resource_id = :resource_id AND status IN ( 'pending', 'confirmed' ) AND end_datetime >= NOW() AND reservation_id <> :ignore_reservation_id AND begin_datetime < :end_datetime AND end_datetime > :begin_datetime ORDER BY begin_datetime ASC LIMIT 1"
	);

	$overlap_statement->execute( [
		':resource_id' => $resource_id,
		':ignore_reservation_id' => $ignore_reservation_id,
		':end_datetime' => $end_datetime_text,
		':begin_datetime' => $begin_datetime_text
	] );

	$conflicting_row = $overlap_statement->fetch();

	if ( $conflicting_row !== false ) {
		send_error( 409, 'This resource is already reserved between ' . $conflicting_row[ 'begin_datetime' ] . ' and ' . $conflicting_row[ 'end_datetime' ] . '.' );
	}
}