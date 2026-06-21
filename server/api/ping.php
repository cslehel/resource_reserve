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

	$database_connection = open_database_connection();

	$is_database_reachable = true;

} catch ( Throwable $ignored_error ) {
}

// Throttle by network address so this unauthenticated endpoint cannot be used to
// open database connections in a tight loop. Only runs when the database is up,
// since the limiter itself needs it; when it is down there is nothing to exhaust.
if ( $is_database_reachable ) {
	enforce_rate_limit( $database_connection, 'ping', get_client_ip_address(), 30, 60 );
}

send_success( [
	'service' => 'resource_reserve',
	'is_database_reachable' => $is_database_reachable
] );
