<?php

// Public, app-independent page where a user can request deletion of their
// account and associated data, as required by the Google Play data deletion
// policy. The user proves ownership with their email and password; on success
// the account is deleted immediately. Deleting the user row cascades to their
// reservations, messages, notifications and administrator assignments, and the
// user's own log rows are removed as well.
//
// This page renders HTML ( GET shows the form, POST performs the deletion )
// because a person opens it directly in a browser.

declare( strict_types = 1 );

require_once __DIR__ . '/config.php';


// ---------------------------------------------------------------------------
// render_deletion_page
// Emits the styled HTML shell with the given heading and inner body markup,
// then stops. The body markup is assembled by the caller from trusted strings
// and already-escaped values.
// ---------------------------------------------------------------------------
function render_deletion_page( int $status_code, string $heading, string $body_html ) : void {

	http_response_code( $status_code );
	header( 'Content-Type: text/html; charset=utf-8' );
	header( 'X-Content-Type-Options: nosniff' );
	header( 'X-Frame-Options: DENY' );
	header( "Content-Security-Policy: default-src 'none'; style-src 'unsafe-inline'" );

	echo '<!doctype html><html lang="en"><head><meta charset="utf-8">';
	echo '<meta name="viewport" content="width=device-width, initial-scale=1">';
	echo '<title>Delete your Resource Reserve account</title>';
	echo '<style>';
	echo 'body { max-width: 560px; margin: 0 auto; padding: 40px 20px 64px; font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, Helvetica, Arial, sans-serif; line-height: 1.6; color: #1a1a1a; background: #ffffff; }';
	echo 'h1 { font-size: 1.6rem; }';
	echo 'label { display: block; margin-top: 16px; font-weight: 600; }';
	echo 'input[type=email], input[type=password] { width: 100%; box-sizing: border-box; padding: 10px 12px; margin-top: 6px; font-size: 1rem; border: 1px solid #c5c5c5; border-radius: 6px; }';
	echo '.checkbox { margin-top: 20px; display: flex; gap: 10px; align-items: flex-start; }';
	echo '.checkbox input { margin-top: 6px; }';
	echo 'button { margin-top: 24px; width: 100%; padding: 12px; font-size: 1rem; font-weight: 600; color: #ffffff; background: #5a1fe0; border: none; border-radius: 6px; cursor: pointer; }';
	echo 'button:hover { background: #4a00b0; }';
	echo '.note { background: #f5f7fa; border-left: 4px solid #5a1fe0; padding: 12px 16px; border-radius: 4px; margin: 20px 0; }';
	echo '.error { background: #fdecea; border-left: 4px solid #c0392b; padding: 12px 16px; border-radius: 4px; margin: 20px 0; }';
	echo '.success { background: #eafaf0; border-left: 4px solid #1e8e4f; padding: 12px 16px; border-radius: 4px; margin: 20px 0; }';
	echo '</style></head><body>';
	echo '<h1>' . htmlspecialchars( $heading ) . '</h1>';
	echo $body_html;
	echo '</body></html>';

	exit;
}


// ---------------------------------------------------------------------------
// render_form
// Shows the deletion form. The optional message is rendered as an error notice
// above the form, and the email field is pre-filled with the supplied value.
// ---------------------------------------------------------------------------
function render_form( string $error_message = '', string $email_value = '', int $status_code = 200 ) : void {

	$safe_email = htmlspecialchars( $email_value );

	$body_html = '';

	if ( $error_message !== '' ) {
		$body_html .= '<div class="error">' . htmlspecialchars( $error_message ) . '</div>';
	}

	$body_html .= '<p>Deleting your account removes your reservations, messages, notifications and any administrator assignments, and signs you out everywhere. <strong>This cannot be undone.</strong></p>';
	$body_html .= '<div class="note">Confirm it is you by signing in with your account email and password.</div>';

	$body_html .= '<form method="post" action="delete_account.php" autocomplete="off">';
	$body_html .= '<label for="email">Account email</label>';
	$body_html .= '<input type="email" id="email" name="email" required value="' . $safe_email . '">';
	$body_html .= '<label for="password">Password</label>';
	$body_html .= '<input type="password" id="password" name="password" required>';
	$body_html .= '<div class="checkbox"><input type="checkbox" id="confirm" name="confirm" value="yes" required>';
	$body_html .= '<label for="confirm" style="margin-top:0;font-weight:400;">I understand my account and all associated data will be permanently deleted.</label></div>';
	$body_html .= '<button type="submit">Delete my account</button>';
	$body_html .= '</form>';

	render_deletion_page( $status_code, 'Delete your Resource Reserve account', $body_html );
}


$request_method = strtoupper( $_SERVER[ 'REQUEST_METHOD' ] ?? 'GET' );

if ( $request_method !== 'POST' ) {
	render_form();
}

// From here on the request is a POST: validate it and perform the deletion.

$email = trim( ( string ) ( $_POST[ 'email' ] ?? '' ) );
$password = ( string ) ( $_POST[ 'password' ] ?? '' );
$is_confirmed = ( $_POST[ 'confirm' ] ?? '' ) === 'yes';

if ( !$is_confirmed ) {
	render_form( 'Please tick the confirmation box to continue.', $email, 422 );
}

if ( !filter_var( $email, FILTER_VALIDATE_EMAIL ) || $password === '' ) {
	render_form( 'Enter the email and password for the account you want to delete.', $email, 422 );
}

try {

	$database_connection = open_database_connection();

	// Throttle by network address and by email so this form cannot be used to
	// guess passwords or to probe which emails are registered.
	enforce_rate_limit( $database_connection, 'delete_account_ip', get_client_ip_address(), 5, 900 );
	enforce_rate_limit( $database_connection, 'delete_account_email', strtolower( $email ), 5, 900 );

	$user_statement = $database_connection->prepare( 'SELECT user_id, password_hash FROM user WHERE email = :email LIMIT 1' );
	$user_statement->execute( [ ':email' => $email ] );

	$user_row = $user_statement->fetch();

	// Always run a hash comparison so the response time does not reveal whether
	// the email exists, and report failures with one generic message.
	$password_hash_to_check = $user_row === false
		? '$2y$10$usesomesillystringforsalt0123456789abcdefghijklmnopqrstuv'
		: $user_row[ 'password_hash' ];

	$is_password_correct = password_verify( $password, $password_hash_to_check );

	if ( $user_row === false || !$is_password_correct ) {
		render_form( 'The email or password is incorrect.', $email, 401 );
	}

	$user_id = ( int ) $user_row[ 'user_id' ];

	// Remove the user's own log rows first ( they would otherwise be kept with a
	// null user_id but still hold the email in their text ), then delete the
	// account. The foreign keys cascade the deletion to reservations, messages,
	// notifications and administrator assignments.
	$database_connection->beginTransaction();

	$delete_logs_statement = $database_connection->prepare( 'DELETE FROM log WHERE user_id = :user_id' );
	$delete_logs_statement->execute( [ ':user_id' => $user_id ] );

	$delete_user_statement = $database_connection->prepare( 'DELETE FROM user WHERE user_id = :user_id' );
	$delete_user_statement->execute( [ ':user_id' => $user_id ] );

	$database_connection->commit();

	// Record that a deletion happened, without storing anything that identifies
	// the person, so an operator can still see the action took place.
	write_log( $database_connection, null, 'account_deleted', 'An account was deleted through the web form.' );

	$success_html = '<div class="success">Your account and all associated data have been permanently deleted. You can close this page.</div>';

	render_deletion_page( 200, 'Account deleted', $success_html );

} catch ( Throwable $error ) {

	if ( isset( $database_connection ) && $database_connection->inTransaction() ) {
		$database_connection->rollBack();
	}

	render_form( 'We could not complete the deletion right now. Please try again later.', $email, 500 );
}
