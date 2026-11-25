<?php
/**
 * Testes do cron real (contrato, seção 10.4): regras puras, formulário e a
 * constante definida só quando pode.
 *
 * @package ConsultaLeve
 */

require __DIR__ . '/stubs.php';

// Começa como cópia embutida num tema: nada pode ser definido nem gravado.
$GLOBALS['t_basename'] = 'srv/site/wp-content/themes/tema/consulta-leve/consulta-leve.php';
function plugin_basename( $arquivo ) { // phpcs:ignore
	return $GLOBALS['t_basename'];
}
function site_url( $caminho = '' ) { // phpcs:ignore
	return 'https://exemplo.test/' . $caminho;
}

// WP-CLI falso: guarda os ganchos pedidos.
define( 'WP_CLI', true );
class WP_CLI { // phpcs:ignore
	public static $ganchos = array();
	public static function add_hook( $quando, $cb ) { // phpcs:ignore
		self::$ganchos[ $quando ] = $cb;
	}
}

add_filter(
	'consulta_leve_modulos',
	static function () {
		return array( 'nucleo/config.php', 'nucleo/coexistencia.php', 'nucleo/trava.php', 'nucleo/cache.php', 'atualizacao/chaves.php', 'atualizacao/atualizador.php', 'cron/cron.php' );
	}
);

// Tudo a favor (ligado e batida fresca), menos a cópia, que é embutida.
$GLOBALS['cl_t']['opcoes']['consulta_leve_config']      = array( 'cron_real' => true );
$GLOBALS['cl_t']['opcoes']['consulta_leve_cron_batida'] = array( 'em' => time() - 60, 'origem' => 'http' );
define( 'DOING_CRON', true );
require __DIR__ . '/../../consulta-leve/consulta-leve.php';

confere( 'embutida: constante não definida', defined( 'DISABLE_WP_CRON' ), false );
confere( 'embutida: sem gancho de batida', has_action( 'wp_loaded' ), false );
confere( 'embutida: sem gancho no WP-CLI', WP_CLI::$ganchos, array() );
confere( 'embutida: não aplica', consulta_leve_cron_aplicar(), false );
confere( 'embutida: não grava batida', consulta_leve_cron_bater( 'http' ), false );
confere( 'embutida: não liga', consulta_leve_cron_pode_ligar( consulta_leve_cron_situacao( true ) ), 'embutida' );

/* Origem da execução --------------------------------------------------------- */

confere( 'wp-cron.php sem parâmetro: externo', consulta_leve_cron_origem( true, 'fpm-fcgi', array() ), 'http' );
confere( 'wp-cron.php?doing_wp_cron: externo', consulta_leve_cron_origem( true, 'fpm-fcgi', array( 'doing_wp_cron' => '' ) ), 'http' );
confere( 'php wp-cron.php: externo', consulta_leve_cron_origem( true, 'cli', array() ), 'cli' );
confere( 'php-cgi wp-cron.php: externo', consulta_leve_cron_origem( true, 'cgi-fcgi', array() ), 'http' );
confere( 'spawn_cron do núcleo: não conta', consulta_leve_cron_origem( true, 'fpm-fcgi', array( 'doing_wp_cron' => '1700000000.1234567890123456789012' ) ), '' );
confere( 'parâmetro forjado: não conta', consulta_leve_cron_origem( true, 'fpm-fcgi', array( 'doing_wp_cron' => 'x' ) ), '' );
confere( 'parâmetro forjado em lista: não conta', consulta_leve_cron_origem( true, 'fpm-fcgi', array( 'doing_wp_cron' => array( '' ) ) ), '' );
confere( 'parâmetro preenchido em servidor sob cli: não conta', consulta_leve_cron_origem( true, 'cli', array( 'doing_wp_cron' => '1' ) ), '' );
confere( 'cli sem cron: não conta', consulta_leve_cron_origem( false, 'cli', array() ), '' );
confere( 'visita com parâmetro vazio: não conta', consulta_leve_cron_origem( false, 'fpm-fcgi', array( 'doing_wp_cron' => '' ) ), '' );

/* Prazos --------------------------------------------------------------------- */

$agora = 1700000000;
confere( 'recente no limite', consulta_leve_cron_recente( $agora - 3600, $agora, 3600 ), true );
confere( 'vencida um segundo depois', consulta_leve_cron_recente( $agora - 3601, $agora, 3600 ), false );
confere( 'sem batida', consulta_leve_cron_recente( 0, $agora, 3600 ), false );
confere( 'batida no futuro não vale', consulta_leve_cron_recente( $agora + 301, $agora, 3600 ), false );
confere( 'grava a primeira', consulta_leve_cron_deve_gravar( 0, $agora ), true );
confere( 'não grava antes de 5 minutos', consulta_leve_cron_deve_gravar( $agora - 299, $agora ), false );
confere( 'grava aos 5 minutos', consulta_leve_cron_deve_gravar( $agora - 300, $agora ), true );
confere( 'relógio voltou: grava', consulta_leve_cron_deve_gravar( $agora + 9, $agora ), true );

/* Estado e permissão de ligar ------------------------------------------------- */

$s = array(
	'proprio'       => true,
	'definida_fora' => false,
	'ligado'        => true,
	'batida'        => $agora - 60,
	'agora'         => $agora,
);
confere( 'ativo', consulta_leve_cron_estado( $s ), 'ativo' );
confere( 'batida vencida', consulta_leve_cron_estado( array( 'batida' => $agora - 3601 ) + $s ), 'vencido' );
confere( 'nunca bateu', consulta_leve_cron_estado( array( 'batida' => 0 ) + $s ), 'vencido' );
confere( 'desligado', consulta_leve_cron_estado( array( 'ligado' => false ) + $s ), 'desligado' );
confere( 'já definida: não interfere', consulta_leve_cron_estado( array( 'definida_fora' => true ) + $s ), 'definida' );
confere( 'cópia embutida', consulta_leve_cron_estado( array( 'proprio' => false ) + $s ), 'embutida' );
confere( 'situação vazia', consulta_leve_cron_estado( array() ), 'embutida' );

$d = array( 'ligado' => false ) + $s;
confere( 'liga com batida recente', consulta_leve_cron_pode_ligar( $d ), 'ok' );
confere( 'liga aos 30 minutos', consulta_leve_cron_pode_ligar( array( 'batida' => $agora - 1800 ) + $d ), 'ok' );
confere( 'recusa depois de 30 minutos', consulta_leve_cron_pode_ligar( array( 'batida' => $agora - 1801 ) + $d ), 'sem_batida' );
confere( 'recusa sem batida', consulta_leve_cron_pode_ligar( array( 'batida' => 0 ) + $d ), 'sem_batida' );
confere( 'recusa já definida', consulta_leve_cron_pode_ligar( array( 'definida_fora' => true ) + $d ), 'definida' );

/* Configuração e formulário geral ---------------------------------------------- */

confere( 'padrão desligado', consulta_leve_config_padrao()['cron_real'], false );
confere( 'valor estranho volta ao padrão', consulta_leve_config_sanitizar( array( 'cron_real' => array( 1 ) ) )['cron_real'], false );
$form = consulta_leve_config_do_formulario( array( 'cron_real' => '1', 'teto' => '700', 'monitor' => '1' ) );
confere( 'formulário geral não liga o cron', array_key_exists( 'cron_real', $form ), false );
confere( 'formulário geral segue igual', array( $form['teto'], $form['monitor'], $form['busca'], $form['indice_tipos'] ), array( '700', true, false, array() ) );
consulta_leve_config_gravar( $form );
confere( 'salvar o resto não desliga o cron', consulta_leve_config_sanitizar( get_option( 'consulta_leve_config' ) )['cron_real'], true );

/* Opção da batida --------------------------------------------------------------- */

$GLOBALS['cl_t']['opcoes']['consulta_leve_cron_batida'] = 'lixo';
confere( 'batida corrompida', consulta_leve_cron_batida(), array( 'em' => 0, 'origem' => '' ) );
$GLOBALS['cl_t']['opcoes']['consulta_leve_cron_batida'] = array( 'em' => 'x', 'origem' => '<b>' );
confere( 'campos fora do formato', consulta_leve_cron_batida(), array( 'em' => 0, 'origem' => '' ) );

/* Plugin próprio: da batida à constante ------------------------------------------- */

$GLOBALS['t_basename'] = 'consulta-leve/consulta-leve.php';

confere( 'ligado sem batida: constante não definida', consulta_leve_cron_aplicar(), false );
$GLOBALS['cl_t']['opcoes']['consulta_leve_cron_batida'] = array( 'em' => time() - 4000, 'origem' => 'http' );
consulta_leve_cron_iniciar( '' );
confere( 'batida vencida: WP-Cron das visitas fica', defined( 'DISABLE_WP_CRON' ), false );
confere( 'batida vencida: diagnóstico avisa', consulta_leve_cron_estado( consulta_leve_cron_situacao() ), 'vencido' );
confere( 'requisição comum: sem gancho de batida', has_action( 'wp_loaded' ), false );

consulta_leve_cron_iniciar( 'http' );
confere( 'ainda vencida na própria execução externa', defined( 'DISABLE_WP_CRON' ), false );
confere( 'antes do wp_loaded nada é gravado', consulta_leve_cron_batida()['em'] < time() - 3000, true );
do_action( 'wp_loaded' );
$b = consulta_leve_cron_batida();
confere( 'wp_loaded grava a batida', array( time() - $b['em'] < 5, $b['origem'] ), array( true, 'http' ) );
confere( 'segunda batida em menos de 5 minutos não grava', consulta_leve_cron_bater( 'cli' ), false );
confere( 'origem vazia não grava', consulta_leve_cron_bater( '' ), false );

confere( 'WP-CLI: gancho do wp cron event run', array_keys( WP_CLI::$ganchos ), array( 'before_invoke:cron event run' ) );
$GLOBALS['cl_t']['opcoes']['consulta_leve_cron_batida'] = array();
call_user_func( WP_CLI::$ganchos['before_invoke:cron event run'] );
confere( 'WP-CLI: batida gravada', consulta_leve_cron_batida()['origem'], 'wp-cli' );

consulta_leve_config_gravar( array( 'cron_real' => false ) );
confere( 'desligado com batida fresca: não define', consulta_leve_cron_aplicar(), false );
confere( 'desligado: batida nem é lida', consulta_leve_cron_situacao()['batida'], 0 );
add_filter(
	'consulta_leve_config',
	static function ( $c ) {
		return array( 'cron_real' => true ) + $c;
	}
);
consulta_leve_config( true );
confere( 'filtro da configuração não liga o cron', consulta_leve_cron_aplicar(), false );

confere( 'ninguém definiu ainda', consulta_leve_cron_definido_por(), '' );
consulta_leve_config_gravar( array( 'cron_real' => true ) );
confere( 'ligado com batida fresca: define', consulta_leve_cron_aplicar(), true );
confere( 'constante verdadeira', DISABLE_WP_CRON, true );
confere( 'origem: este plugin', consulta_leve_cron_definido_por(), 'plugin' );
confere( 'a própria constante não conta como de fora', consulta_leve_cron_estado( consulta_leve_cron_situacao() ), 'ativo' );
confere( 'não define duas vezes', consulta_leve_cron_aplicar(), false );
confere( 'linha de exemplo', consulta_leve_cron_linha(), '*/5 * * * * curl -fsS -o /dev/null "https://exemplo.test/wp-cron.php"' );

cl_teste_fim( 'cron' );
