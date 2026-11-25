<?php
/**
 * Teste em Apache real: redirecionamento 302 e 403 feitos pelo PHP.
 *
 * @package ConsultaLeve
 */

defined( 'ABSPATH' ) || exit;

add_action(
	'template_redirect',
	static function () {
		if ( isset( $_GET['cl_redir'] ) ) {
			wp_safe_redirect( home_url( '/' ), 302 );
			exit;
		}
		if ( isset( $_GET['cl_morre'] ) ) {
			wp_die( 'negado', '', array( 'response' => 403 ) );
		}
	},
	0
);
