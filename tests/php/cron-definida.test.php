<?php
/**
 * Cron real com DISABLE_WP_CRON já definida (contrato, seção 10.4): o plugin
 * nunca interfere, nem quando o valor é false.
 *
 * @package ConsultaLeve
 */

require __DIR__ . '/stubs.php';

define( 'DISABLE_WP_CRON', false ); // Como no wp-config.php.

function plugin_basename( $arquivo ) { // phpcs:ignore
	return 'consulta-leve/consulta-leve.php';
}
function number_format_i18n( $n, $d = 0 ) { // phpcs:ignore
	return number_format( (float) $n, $d, ',', '.' );
}

add_filter(
	'consulta_leve_modulos',
	static function () {
		return array( 'nucleo/config.php', 'nucleo/coexistencia.php', 'nucleo/trava.php', 'nucleo/cache.php', 'nucleo/registro.php', 'diagnostico/diagnostico.php', 'atualizacao/chaves.php', 'atualizacao/atualizador.php', 'cron/cron.php', 'admin/admin.php' );
	}
);
$GLOBALS['cl_t']['opcoes']['consulta_leve_config']      = array( 'cron_real' => true );
$GLOBALS['cl_t']['opcoes']['consulta_leve_cron_batida'] = array( 'em' => time(), 'origem' => 'http' );
$avisos_php = array();
set_error_handler( // phpcs:ignore
	static function ( $n, $m ) use ( &$avisos_php ) {
		$avisos_php[] = $m;
		return true;
	}
);
require __DIR__ . '/../../consulta-leve/consulta-leve.php';
restore_error_handler();

confere( 'sem aviso de constante repetida', $avisos_php, array() );
confere( 'valor do wp-config.php mantido', DISABLE_WP_CRON, false );
confere( 'origem: wp-config.php ou outro código', consulta_leve_cron_definido_por(), 'wp_config' );
confere( 'estado', consulta_leve_cron_estado( consulta_leve_cron_situacao() ), 'definida' );
confere( 'não deixa ligar', consulta_leve_cron_pode_ligar( consulta_leve_cron_situacao( true ) ), 'definida' );

$d = consulta_leve_diagnostico();
confere( 'diagnóstico: cron_desligado segue bool', $d['cron_desligado'], false );
confere( 'diagnóstico: origem e estado', array( $d['cron_origem'], $d['cron_estado'] ), array( 'wp_config', 'definida' ) );
confere( 'recomendação fala do wp-config.php', false !== strpos( implode( ' ', array_column( $d['recomendacoes'], 'texto' ) ), 'define DISABLE_WP_CRON como false' ), true );

confere( 'rótulo: plugin', consulta_leve_admin_cron_rotulo( true, 'plugin' ), 'sim, por este plugin' );
confere( 'rótulo: wp-config', consulta_leve_admin_cron_rotulo( true, 'wp_config' ), 'sim, pelo wp-config.php ou outro código' );
confere( 'rótulo: false no wp-config', consulta_leve_admin_cron_rotulo( false, 'wp_config' ), 'não, definido como false no wp-config.php ou em outro código' );
confere( 'rótulo: diagnóstico antigo em cache', array( consulta_leve_admin_cron_rotulo( true, '' ), consulta_leve_admin_cron_rotulo( false, '' ) ), array( 'sim', 'não' ) );

foreach ( array( 'ok' => 'cron_ligado', 'embutida' => 'cron_embutida', 'definida' => 'cron_definida', 'sem_batida' => 'cron_sem_batida' ) as $aviso ) {
	confere( "aviso {$aviso} na lista fechada", isset( consulta_leve_admin_avisos()[ $aviso ] ), true );
}

cl_teste_fim( 'cron-definida' );
