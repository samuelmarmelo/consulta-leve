<?php
/**
 * Teste em Apache real: código de terceiros que já manda cabeçalhos pelo PHP.
 *
 * @package ConsultaLeve
 */

defined( 'ABSPATH' ) || exit;

add_action(
	'send_headers',
	static function () {
		header( 'X-Frame-Options: DENY' );
	}
);
