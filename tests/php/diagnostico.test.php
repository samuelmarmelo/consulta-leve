<?php
/**
 * Testes do perfil e do diagnóstico (contrato, seção 10).
 *
 * @package ConsultaLeve
 */

require __DIR__ . '/stubs.php';

function number_format_i18n( $n, $d = 0 ) { // phpcs:ignore
	return number_format( (float) $n, $d, ',', '.' );
}
function get_stylesheet() { // phpcs:ignore
	return 'meu-crm';
}

add_filter(
	'consulta_leve_modulos',
	static function () {
		return array( 'nucleo/config.php', 'nucleo/coexistencia.php', 'nucleo/trava.php', 'nucleo/cache.php', 'diagnostico/perfil.php', 'diagnostico/diagnostico.php', 'diagnostico/monitor.php' );
	}
);
require __DIR__ . '/../../consulta-leve/consulta-leve.php';

/* Perfil -------------------------------------------------------------------- */

confere( 'sem cabeçalho: desligado', consulta_leve_perfil_modo_pedido(), '' );
$_SERVER['HTTP_X_CONSULTA_LEVE_PERFIL'] = 'EXPLAIN';
confere( 'modo explain', consulta_leve_perfil_modo_pedido(), 'explain' );
$_SERVER['HTTP_X_CONSULTA_LEVE_PERFIL'] = 'outro';
confere( 'valor fora da lista: desligado', consulta_leve_perfil_modo_pedido(), '' );
$_SERVER['HTTP_X_CONSULTA_LEVE_PERFIL'] = '1';
confere( 'sem pluggable: não decide ainda', consulta_leve_perfil_ativo(), false );

confere(
	'forma agrupa números, textos e IN',
	consulta_leve_perfil_forma( "SELECT * FROM wp_posts WHERE ID IN (1, 2, 3) AND post_title = 'Olá \'x\'' AND menu_order = 4.5" ),
	'SELECT * FROM wp_posts WHERE ID IN (?) AND post_title = ? AND menu_order = ?'
);
confere( 'prefixo do tema reconhecido', consulta_leve_perfil_prefixos(), array( 'consulta_leve_', 'meu_crm_' ) );
confere( 'quem chamou: função do tema', consulta_leve_perfil_quem( 'require, do_action, meu_crm_board, WP_Query->get_posts', array( 'meu_crm_' ) ), 'meu_crm_board' );
confere( 'quem chamou: pula o driver do SQLite e o wpdb', consulta_leve_perfil_quem( 'require, do_action, WP_Hook->apply_filters, minha_lista, WP_Query->get_posts, wpdb->query, WP_SQLite_DB->_do_query', array( 'x_' ) ), 'WP_Query->get_posts' );
confere( 'quem chamou: a mais interna sem prefixo', consulta_leve_perfil_quem( 'a, b, get_option', array( 'x_' ) ), 'get_option' );

$lista = array(
	array( 'SELECT option_value FROM wp_options WHERE option_name = \'woo_x\' LIMIT 1', 0.001, 'get_option' ),
	array( 'SELECT option_value FROM wp_options WHERE option_name = \'woo_x\' LIMIT 1', 0.001, 'get_option' ),
	array( 'SELECT * FROM wp_postmeta WHERE post_id = 1', 0.004, 'get_post_meta' ),
	array( 'SELECT * FROM wp_postmeta WHERE post_id = 2', 0.004, 'get_post_meta' ),
	array( 'SELECT * FROM wp_postmeta WHERE post_id = 3', 0.004, 'get_post_meta' ),
);
$g = consulta_leve_perfil_agrupar( $lista );
confere( 'total de consultas', $g['total'], 5 );
confere( 'N+1 aparece agrupado e primeiro', array( $g['formas'][0]['n'], $g['formas'][0]['forma'] ), array( 3, 'SELECT * FROM wp_postmeta WHERE post_id = ?' ) );
confere( 'opções avulsas listadas', $g['opcoes'], array( 'woo_x' ) );
confere( 'tempo do banco', $g['ms'], 14.0 );

$grande = array( 'v' => 1, 'formas' => array_fill( 0, 50, array( 'forma' => str_repeat( 'x', 200 ) ) ), 'ambiente' => array( 'a' => 1 ) );
confere( 'JSON cabe no limite', strlen( consulta_leve_perfil_json( $grande ) ) <= CONSULTA_LEVE_PERFIL_MAX_BYTES, true );
confere( 'explain só de SELECT simples', consulta_leve_perfil_explain( 'DELETE FROM wp_posts' ), null );
confere( 'explain recusa várias instruções', consulta_leve_perfil_explain( 'SELECT 1; DROP TABLE x' ), null );

/* Recomendações ------------------------------------------------------------- */

$base = array(
	'php'             => '8.3.0',
	'cache'           => array( 'externo' => false, 'dropin' => false, 'ms' => 0.0 ),
	'opcache'         => true,
	'cron_desligado'  => true,
	'revisoes_limite' => 10,
	'autoload'        => array( 'kb' => 300.0 ),
	'tabelas'         => array( 'posts' => array( 'linhas' => 6000 ), 'postmeta' => array( 'linhas' => 40000 ) ),
	'contagens'       => array( 'revisoes' => 10, 'transients' => 0 ),
	'indices'         => array( 'meta_chave_valor' => 'ausente' ),
);
confere( 'site saudável: nada a apontar', consulta_leve_diag_recomendacoes( $base ), array() );

$ruim = array_replace_recursive(
	$base,
	array(
		'php'             => '7.4.33',
		'cache'           => array( 'externo' => true, 'dropin' => true, 'ms' => 48.5 ),
		'opcache'         => false,
		'cron_desligado'  => false,
		'revisoes_limite' => true,
		'autoload'        => array( 'kb' => 2400.0 ),
		'tabelas'         => array( 'postmeta' => array( 'linhas' => 900000 ) ),
		'contagens'       => array( 'revisoes' => 9000, 'transients' => 5000 ),
	)
);
$r      = consulta_leve_diag_recomendacoes( $ruim );
$textos = implode( "\n", array_column( $r, 'texto' ) );
confere( 'cache lento vira alerta', false !== strpos( $textos, '48,50 ms' ), true );
confere( 'autoload grande vira alerta', false !== strpos( $textos, '2.400,0 KB' ), true );
confere( 'OPcache desligado', false !== strpos( $textos, 'OPcache desligado' ), true );
confere( 'revisões sem limite', false !== strpos( $textos, 'WP_POST_REVISIONS' ), true );
confere( 'índice sugerido, não criado', false !== strpos( $textos, 'avalie o índice meta_chave_valor' ), true );
confere( 'PHP antigo', false !== strpos( $textos, 'PHP abaixo de 8.1' ), true );
confere( 'drop-in ativo não é órfão', false === strpos( $textos, 'resto de um plugin' ), true );
$orfao = array_replace_recursive( $base, array( 'cache' => array( 'dropin' => true ) ) );
confere( 'drop-in órfão', false !== strpos( implode( ' ', array_column( consulta_leve_diag_recomendacoes( $orfao ), 'texto' ) ), 'resto de um plugin' ), true );

$cron = static function ( array $campos ) use ( $base ) {
	return consulta_leve_diag_recomendacoes( array_merge( $base, $campos ) );
};
$r_cron = $cron( array( 'cron_desligado' => false, 'cron_estado' => 'vencido' ) );
confere( 'cron real vencido vira alerta, uma vez só', array( count( $r_cron ), $r_cron[0]['nivel'], false !== strpos( $r_cron[0]['texto'], 'mais de 60 minutos' ) ), array( 1, 'alerta', true ) );
confere( 'cron desligado: dica aponta a chave', false !== strpos( $cron( array( 'cron_desligado' => false, 'cron_estado' => 'desligado' ) )[0]['texto'], 'ligue Cron real' ), true );
confere( 'cópia embutida: dica antiga', false !== strpos( $cron( array( 'cron_desligado' => false, 'cron_estado' => 'embutida' ) )[0]['texto'], 'com DISABLE_WP_CRON' ), true );
confere( 'cron real ativo: nada a apontar', $cron( array( 'cron_desligado' => true, 'cron_estado' => 'ativo' ) ), array() );
confere( 'diagnóstico antigo, sem cron_estado: dica antiga', false !== strpos( $cron( array( 'cron_desligado' => false ) )[0]['texto'], 'com DISABLE_WP_CRON' ), true );
confere( 'níveis só alerta e dica', array_values( array_unique( array_column( $r, 'nivel' ) ) ), array( 'alerta', 'dica' ) );

cl_teste_fim( 'diagnostico' );
