<?php

// Lightweight health check used by the app's "Test connection" button. It
// proves that PHP actually executed this API ( not merely that the host
// answered ) by returning this service's identifier, and it reports whether the
// database behind the API is reachable.

declare( strict_types = 1 );

require_once __DIR__ . '/config.php';

require_request_method( 'GET' );

$is_database_reachable = false;

try {

	open_database_connection();

	$is_database_reachable = true;

} catch ( Throwable $ignored_error ) {
}

send_success( [
	'service' => 'resource_reserve',
	'is_database_reachable' => $is_database_reachable
] );
