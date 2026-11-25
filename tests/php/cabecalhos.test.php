<?php
/**
 * Testes dos cabeçalhos de segurança (contrato, seção 15.1).
 *
 * @package ConsultaLeve
 */

require __DIR__ . '/stubs.php';

function plugin_basename( $arquivo ) { // phpcs:ignore
	return 'consulta-leve/consulta-leve.php';
}
function is_ssl() { // phpcs:ignore
	return $GLOBALS['t_ssl'];
}
function is_embed() { // phpcs:ignore
	return $GLOBALS['t_embed'];
}
function is_customize_preview() { // phpcs:ignore
	return false;
}
$GLOBALS['t_ssl']   = true;
$GLOBALS['t_embed'] = false;

add_filter(
	'consulta_leve_modulos',
	static function () {
		return array( 'nucleo/config.php', 'nucleo/coexistencia.php', 'nucleo/trava.php', 'nucleo/cache.php', 'seguranca/cabecalhos.php', 'admin/admin.php' );
	}
);
require __DIR__ . '/../../consulta-leve/consulta-leve.php';

$tudo = array(
	'cab_nosniff'  => true,
	'cab_referrer' => true,
	'cab_frame'    => true,
	'cab_upgrade'  => true,
	'cab_hsts'     => 'ano',
);
$completo = array(
	'X-Content-Type-Options'    => 'nosniff',
	'Referrer-Policy'           => 'strict-origin-when-cross-origin',
	'X-Frame-Options'           => 'SAMEORIGIN',
	'Content-Security-Policy'   => 'upgrade-insecure-requests',
	'Strict-Transport-Security' => 'max-age=31536000',
);

/* Lista ------------------------------------------------------------------------ */

confere( 'padrão: nada', consulta_leve_cabecalhos_lista( consulta_leve_config_padrao(), true, array() ), array() );
confere( 'configuração vazia: nada', consulta_leve_cabecalhos_lista( array(), true, array() ), array() );
confere( 'tudo ligado com HTTPS', consulta_leve_cabecalhos_lista( $tudo, true, array() ), $completo );
confere( 'sem HTTPS: nem CSP nem HSTS', array_keys( consulta_leve_cabecalhos_lista( $tudo, false, array() ) ), array( 'X-Content-Type-Options', 'Referrer-Policy', 'X-Frame-Options' ) );
foreach ( array_keys( $completo ) as $nome ) {
	$sem = $completo;
	unset( $sem[ $nome ] );
	confere( "{$nome} já presente", consulta_leve_cabecalhos_lista( $tudo, true, array( "{$nome}: outro" ) ), $sem );
	confere( "{$nome} já presente em maiúsculas", consulta_leve_cabecalhos_lista( $tudo, true, array( strtoupper( $nome ) . ':outro' ) ), $sem );
	confere( "{$nome} já presente em minúsculas", consulta_leve_cabecalhos_lista( $tudo, true, array( strtolower( $nome ) . ' : outro' ) ), $sem );
}
confere( 'qualquer CSP existente segura o nosso', isset( consulta_leve_cabecalhos_lista( $tudo, true, array( "content-security-policy: default-src 'self'" ) )['Content-Security-Policy'] ), false );
confere( 'Report-Only não é CSP', isset( consulta_leve_cabecalhos_lista( $tudo, true, array( 'Content-Security-Policy-Report-Only: x' ) )['Content-Security-Policy'] ), true );
confere( 'nome citado no valor de outro não conta', consulta_leve_cabecalhos_lista( $tudo, true, array( 'X-Nota: Referrer-Policy: x' ) ), $completo );
confere( 'linhas estranhas ignoradas', consulta_leve_cabecalhos_lista( $tudo, true, array( '', null, 7, 'sem dois pontos' ) ), $completo );
confere( 'incorporação: sem X-Frame-Options', isset( consulta_leve_cabecalhos_lista( $tudo, true, array(), true )['X-Frame-Options'] ), false );
confere( 'incorporação: o resto segue', count( consulta_leve_cabecalhos_lista( $tudo, true, array(), true ) ), 4 );
confere( 'HSTS com subdomínios e preload', consulta_leve_cabecalhos_lista( array( 'cab_hsts' => 'ano_preload' ), true, array() ), array( 'Strict-Transport-Security' => 'max-age=31536000; includeSubDomains; preload' ) );
foreach ( array( 'nao', '', 'max-age=1', "ano\r\nX-Mau: 1", true, array( 'ano' ), null ) as $i => $mau ) {
	confere( "HSTS inválido {$i}: nada", consulta_leve_cabecalhos_lista( array( 'cab_hsts' => $mau ), true, array() ), array() );
}
confere( 'bool só aceita true', consulta_leve_cabecalhos_lista( array( 'cab_nosniff' => 'sim' ), true, array() ), array() );

/* Configuração ------------------------------------------------------------------- */

$limpa = consulta_leve_config_sanitizar( array( 'cab_hsts' => 'para_sempre', 'cab_frame' => array( 1 ), 'cab_nosniff' => '1' ) );
confere( 'saneada no servidor', array( $limpa['cab_hsts'], $limpa['cab_frame'], $limpa['cab_nosniff'] ), array( 'nao', false, true ) );
$geral = consulta_leve_config_do_formulario( array( 'cab_nosniff' => '1', 'cab_hsts' => 'ano_preload' ) );
confere( 'formulário geral não mexe nos cabeçalhos', array_filter( array_keys( $geral ), static function ( $k ) { return 0 === strpos( $k, 'cab_' ); } ), array() ); // phpcs:ignore

/* Formulário próprio --------------------------------------------------------------- */

$r = consulta_leve_cabecalhos_do_formulario( array( 'cab_nosniff' => '1', 'cab_hsts' => 'ano' ), array(), false );
confere( 'salva sem confirmação até um ano', $r, array( 'valores' => array( 'cab_nosniff' => true, 'cab_referrer' => false, 'cab_frame' => false, 'cab_upgrade' => false, 'cab_hsts' => 'ano' ), 'aviso' => 'salvo' ) );
$r = consulta_leve_cabecalhos_do_formulario( array( 'cab_frame' => '1', 'cab_hsts' => 'ano_preload' ), array( 'cab_hsts' => 'ano' ), false );
confere( 'preload sem confirmação: HSTS fica, resto salva', array( $r['valores']['cab_hsts'], $r['valores']['cab_frame'], $r['aviso'] ), array( 'ano', true, 'hsts_confirmar' ) );
$r = consulta_leve_cabecalhos_do_formulario( array( 'cab_hsts' => 'ano_preload' ), array( 'cab_hsts' => 'nao' ), true );
confere( 'preload confirmado', array( $r['valores']['cab_hsts'], $r['aviso'] ), array( 'ano_preload', 'salvo' ) );
$r = consulta_leve_cabecalhos_do_formulario( array( 'cab_hsts' => 'ano_preload' ), array( 'cab_hsts' => 'ano_preload' ), false );
confere( 'preload já gravado: não pede de novo', array( $r['valores']['cab_hsts'], $r['aviso'] ), array( 'ano_preload', 'salvo' ) );
$r = consulta_leve_cabecalhos_do_formulario( array( 'cab_hsts' => 'nao' ), array( 'cab_hsts' => 'ano_preload' ), false );
confere( 'desligar não pede confirmação', $r['valores']['cab_hsts'], 'nao' );
$r = consulta_leve_cabecalhos_do_formulario( array( 'cab_hsts' => array( 'ano_preload' ) ), array( 'cab_hsts' => 'ano' ), true );
confere( 'valor forjado: HSTS fica como estava', $r['valores']['cab_hsts'], 'ano' );
confere( 'avisos na lista fechada', isset( consulta_leve_admin_avisos()['salvo'], consulta_leve_admin_avisos()['hsts_confirmar'] ), true );

/* Envio: gancho, painel, dispensa ---------------------------------------------------- */

confere( 'gancho tardio', isset( $GLOBALS['cl_t']['ganchos']['send_headers'][999] ), true );
consulta_leve_config_gravar( $tudo );
confere( 'site: envia', consulta_leve_cabecalhos_enviar(), $completo );
$GLOBALS['t_ssl'] = false;
confere( 'site sem HTTPS: só os três', count( consulta_leve_cabecalhos_enviar() ), 3 );
$GLOBALS['t_ssl']   = true;
$GLOBALS['t_embed'] = true;
confere( 'incorporação: sem X-Frame-Options no envio', isset( consulta_leve_cabecalhos_enviar()['X-Frame-Options'] ), false );
$GLOBALS['t_embed']       = false;
$GLOBALS['cl_t']['admin'] = true;
confere( 'painel: nada', consulta_leve_cabecalhos_enviar(), array() );
$GLOBALS['cl_t']['admin'] = false;

confere( 'método dispensável', in_array( 'cabecalhos', consulta_leve_metodos_dispensaveis(), true ), true );
$GLOBALS['cl_t']['suporte']['consulta-leve'] = array( array( 'dispensar' => array( 'cabecalhos' ) ) );
confere( 'tema dispensou: nada', consulta_leve_cabecalhos_enviar(), array() );
$GLOBALS['cl_t']['suporte'] = array();
confere( 'sem dispensa: volta a enviar', count( consulta_leve_cabecalhos_enviar() ), 5 );
add_filter(
	'consulta_leve_dispensar',
	static function ( $d, $m ) {
		return 'cabecalhos' === $m ? true : $d;
	},
	10,
	2
);
confere( 'filtro dispensou: nada', consulta_leve_cabecalhos_enviar(), array() );

cl_teste_fim( 'cabecalhos' );
