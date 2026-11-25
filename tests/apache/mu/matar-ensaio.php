<?php
/**
 * Teste em Apache real: SIGKILL no meio do ensaio, com o bloco já escrito na pasta de uploads.
 *
 * @package ConsultaLeve
 */

defined( 'ABSPATH' ) || exit;

add_filter(
	'pre_http_request',
	static function ( $resposta ) {
		if ( 'cli' !== PHP_SAPI && glob( WP_CONTENT_DIR . '/uploads/*/.htaccess' ) ) {
			file_put_contents( '/tmp/cl-morto', (string) getmypid() );
			posix_kill( getmypid(), 9 );
		}
		return $resposta;
	},
	0
);
