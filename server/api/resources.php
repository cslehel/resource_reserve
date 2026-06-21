<?php

// Returns the list of active resources so the app can show them in the
// resource filter and in the add reservation form.

declare( strict_types = 1 );

require_once __DIR__ . '/config.php';

require_request_method( 'GET' );

try {

	$database_connection = open_database_connection();

	authenticate_user( $database_connection );

	$resource_statement = $database_connection->query( 'SELECT resource_id, resource_name, description, maximum_period_value, maximum_period_unit FROM resource WHERE is_active = 1 ORDER BY resource_name ASC' );

	$resource_list = [];

	foreach ( $resource_statement->fetchAll() as $resource_row ) {

		$resource_list[] = [
			'resource_id' => ( int ) $resource_row[ 'resource_id' ],
			'resource_name' => $resource_row[ 'resource_name' ],
			'description' => $resource_row[ 'description' ],
			'maximum_period_value' => ( int ) $resource_row[ 'maximum_period_value' ],
			'maximum_period_unit' => $resource_row[ 'maximum_period_unit' ]
		];
	}

	send_success( [ 'resources' => $resource_list ] );

} catch ( Throwable $error ) {

	send_error( 500, 'Could not load the resources right now.' );
}