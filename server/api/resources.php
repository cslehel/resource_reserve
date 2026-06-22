<?php

// Returns the list of active resources so the app can show them in the
// resource filter and in the add reservation form.

declare( strict_types = 1 );

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/reservation_helpers.php';

require_request_method( 'GET' );

try {

	$database_connection = open_database_connection();

	authenticate_user( $database_connection );

	$resource_statement = $database_connection->query( 'SELECT resource_id, resource_name, description, maximum_period_value, maximum_period_unit, availability_mode, active_start_time, active_end_time, working_days FROM resource WHERE is_active = 1 ORDER BY resource_name ASC' );

	$resource_list = [];

	foreach ( $resource_statement->fetchAll() as $resource_row ) {

		$resource_list[] = [
			'resource_id' => ( int ) $resource_row[ 'resource_id' ],
			'resource_name' => $resource_row[ 'resource_name' ],
			'description' => $resource_row[ 'description' ],
			'maximum_period_value' => ( int ) $resource_row[ 'maximum_period_value' ],
			'maximum_period_unit' => $resource_row[ 'maximum_period_unit' ],
			'availability_mode' => $resource_row[ 'availability_mode' ],
			// Times are sent as 'HH:MM'. An active_end_time of '00:00' means the
			// window runs to the end of the day.
			'active_start_time' => substr( ( string ) $resource_row[ 'active_start_time' ], 0, 5 ),
			'active_end_time' => substr( ( string ) $resource_row[ 'active_end_time' ], 0, 5 ),
			'working_days' => $resource_row[ 'working_days' ]
		];
	}

	// The booking window rules travel with the resource list so the app never
	// has to hardcode them and always matches what the server enforces.
	send_success( [
		'resources' => $resource_list,
		'minimum_lead_minutes' => read_reservation_lead_minutes( $database_connection ),
		'maximum_horizon_months' => read_reservation_horizon_months( $database_connection )
	] );

} catch ( Throwable $error ) {

	send_error( 500, 'Could not load the resources right now.' );
}