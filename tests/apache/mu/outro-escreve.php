<?php
/**
 * Teste em Apache real: outro processo acrescenta uma linha ao arquivo no meio da conferência.
 *
 * @package ConsultaLeve
 */

defined( 'ABSPATH' ) || exit;

add_action(
	'template_redirect',
	static function () {
		clearstatcache();
		$arquivo  = ABSPATH . '.htaccess';
		$conteudo = (string) @file_get_contents( $arquivo );
		if ( false !== strpos( $conteudo, '# BEGIN Consulta Leve' ) && false === strpos( $conteudo, '# linha de outro processo' ) ) {
			file_put_contents( $arquivo, "# linha de outro processo\n", FILE_APPEND | LOCK_EX );
		}
	},
	0
);
