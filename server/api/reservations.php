<?php

// Returns reservations for the reservation list screen. Supports three
// filters that match the app:
//   resource_id   - keep only reservations for one resource ( optional )
//   only_own      - 1 to keep only the signed in user's reservations
//   month_range   - 1, 2 or 3 months ahead from today ( defaults to 1 )

declare( strict_types = 1 );

require_once __DIR__ . '/config.php';

require_request_method( 'GET' );

try {

	$database_connection = open_database_connection();

	$current_user = authenticate_user( $database_connection );

	$resource_id = isset( $_GET[ 'resource_id' ] ) ? ( int ) $_GET[ 'resource_id' ] : 0;
	$only_own = ( ( int ) ( $_GET[ 'only_own' ] ?? 0 ) ) === 1;
	$month_range = ( int ) ( $_GET[ 'month_range' ] ?? 1 );

	if ( !in_array( $month_range, [ 1, 2, 3 ], true ) ) {
		$month_range = 1;
	}

	$where_clauses = [ 'reservation.end_datetime >= NOW()' ];
	$query_parameters = [];

	// $month_range is already restricted to the whitelist 1, 2 or 3 above, so it
	// is safe to place directly into the interval expression. A bound parameter
	// inside INTERVAL is rejected by some MySQL versions.
	$where_clauses[] = 'reservation.begin_datetime < DATE_ADD( NOW(), INTERVAL ' . $month_range . ' MONTH )';

	if ( $resource_id > 0 ) {
		$where_clauses[] = 'reservation.resource_id = :resource_id';
		$query_parameters[ ':resource_id' ] = $resource_id;
	}

	if ( $only_own ) {
		$where_clauses[] = 'reservation.user_id = :user_id';
		$query_parameters[ ':user_id' ] = $current_user[ 'user_id' ];
	}

	$query_text = 'SELECT reservation.reservation_id, reservation.user_id, reservation.resource_id, resource.resource_name, reservation.begin_datetime, reservation.end_datetime, reservation.description, reservation.status, user.email AS owner_email FROM reservation INNER JOIN resource ON resource.resource_id = reservation.resource_id INNER JOIN user ON user.user_id = reservation.user_id WHERE ' . implode( ' AND ', $where_clauses ) . ' ORDER BY reservation.begin_datetime ASC';

	$reservation_statement = $database_connection->prepare( $query_text );
	$reservation_statement->execute( $query_parameters );

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
			'owner_email' => $reservation_row[ 'owner_email' ],
			'is_own' => ( int ) $reservation_row[ 'user_id' ] === ( int ) $current_user[ 'user_id' ]
		];
	}

	send_success( [ 'reservations' => $reservation_list ] );

} catch ( Throwable $error ) {

	send_error( 500, 'Could not load the reservations right now.' );
}