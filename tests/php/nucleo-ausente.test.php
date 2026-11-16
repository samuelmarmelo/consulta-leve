<?php
/**
 * SafeWidget: sem o núcleo, os demais módulos não carregam (nada de fatal
 * em toda página). Contrato, seção 2.4.
 *
 * @package ConsultaLeve
 */

require __DIR__ . '/stubs.php';

$GLOBALS['cl_falhas_vistas'] = array();
add_action(
	'consulta_leve_falha',
	static function ( $modulo ) {
		$GLOBALS['cl_falhas_vistas'][] = $modulo;
	}
);
add_filter(
	'consulta_leve_modulos',
	static function () {
		return array( 'nucleo/config.php', 'consultas/argumentos.php', 'busca/busca.php', 'indices/busca.php' );
	}
);
require __DIR__ . '/../../consulta-leve/consulta-leve.php';

confere( 'núcleo incompleto detectado', consulta_leve_nucleo_pronto(), false );
confere( 'argumentos parados', function_exists( 'consulta_leve_args' ), false );
confere( 'busca parada', function_exists( 'consulta_leve_busca_termo_limpo' ), false );
confere( 'tabela de busca parada', function_exists( 'consulta_leve_busca_tabela' ), false );
confere( 'falha avisada uma vez', $GLOBALS['cl_falhas_vistas'], array( 'nucleo' ) );
confere( 'carga terminou mesmo assim', did_action( 'consulta_leve_carregado' ), 1 );

// Ganchos do WordPress seguem sem erro (nenhum módulo dependente registrou nada).
do_action( 'pre_get_posts', new stdClass() );
do_action( 'save_post', 1, null, false );
confere( 'ganchos sem fatal', true, true );

cl_teste_fim( 'nucleo-ausente' );
