<?php
/**
 * Teste em Apache real: a página inicial quebra assim que o bloco aparece no arquivo.
 *
 * @package ConsultaLeve
 */

defined( 'ABSPATH' ) || exit;

add_action(
	'template_redirect',
	static function () {
		clearstatcache();
		if ( false !== strpos( (string) @file_get_contents( ABSPATH . '.htaccess' ), '# BEGIN Consulta Leve' ) ) {
			status_header( 500 );
			echo 'erro simulado';
			exit;
		}
	},
	0
);
