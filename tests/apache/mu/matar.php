<?php
/**
 * Teste em Apache real: SIGKILL no processo que escreveu o bloco, antes do primeiro pedido da conferência (nenhum shutdown roda).
 *
 * @package ConsultaLeve
 */

defined( 'ABSPATH' ) || exit;

add_filter(
	'pre_http_request',
	static function ( $resposta ) {
		clearstatcache();
		if ( 'cli' !== PHP_SAPI && false !== strpos( (string) @file_get_contents( ABSPATH . '.htaccess' ), '# BEGIN Consulta Leve' ) ) {
			file_put_contents( '/tmp/cl-morto', (string) getmypid() );
			posix_kill( getmypid(), 9 );
		}
		return $resposta;
	},
	0
);
