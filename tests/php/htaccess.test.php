<?php
/**
 * Testes dos cabeçalhos no .htaccess (contrato, seção 15.2).
 *
 * O arquivo é de verdade, numa pasta temporária; o servidor é um Apache
 * simulado em wp_remote_get(), com as duas tabelas do mod_headers.
 *
 * @package ConsultaLeve
 */

$cl_pasta = sys_get_temp_dir() . '/cl-htaccess-' . bin2hex( random_bytes( 6 ) );
mkdir( $cl_pasta );
mkdir( $cl_pasta . '/wp-content' );
mkdir( $cl_pasta . '/wp-content/uploads' );
define( 'ABSPATH', $cl_pasta . '/' );

/**
 * Esvazia e remove uma pasta do próprio teste.
 *
 * @param string $pasta Pasta.
 */
function remover_pasta( string $pasta ) { // phpcs:ignore
	foreach ( array_diff( (array) @scandir( $pasta ), array( '.', '..', false ) ) as $n ) {
		$c = $pasta . '/' . $n;
		is_dir( $c ) && ! is_link( $c ) ? remover_pasta( $c ) : unlink( $c );
	}
	@rmdir( $pasta );
}
register_shutdown_function( 'remover_pasta', $cl_pasta );

require __DIR__ . '/stubs.php';

$GLOBALS['t'] = array();

function consulta_leve_atualizador_plugin_proprio() { return $GLOBALS['t']['proprio']; } // phpcs:ignore
function plugin_basename( $a ) { return 'consulta-leve/consulta-leve.php'; } // phpcs:ignore
function register_deactivation_hook( $a, $cb ) { $GLOBALS['t_desativar'] = $cb; } // phpcs:ignore
function is_multisite() { return $GLOBALS['t']['multisite']; } // phpcs:ignore
function wp_is_file_mod_allowed( $c ) { return $GLOBALS['t']['mods']; } // phpcs:ignore
function get_home_path() { return ABSPATH; } // phpcs:ignore
function home_url( $p = '' ) { return $GLOBALS['t']['esquema'] . '://exemplo.test' . $p; } // phpcs:ignore
function site_url( $p = '' ) { return home_url( $p ); } // phpcs:ignore
function add_query_arg( $k, $v, $u ) { return $u . '?' . $k . '=' . $v; } // phpcs:ignore
function nocache_headers() { $GLOBALS['t']['nocache'] = true; } // phpcs:ignore
function is_ssl() { return 'https' === $GLOBALS['t']['esquema']; } // phpcs:ignore
function is_embed() { return false; } // phpcs:ignore
function is_customize_preview() { return false; } // phpcs:ignore
function is_wp_error( $v ) { return null === $v; } // phpcs:ignore
function wp_remote_retrieve_headers( $r ) { return $r['headers']; } // phpcs:ignore
function wp_remote_retrieve_body( $r ) { return $r['body'] ?? ''; } // phpcs:ignore
function set_url_scheme( $u, $s ) { return preg_replace( '#^https?://#', $s . '://', $u ); } // phpcs:ignore
function admin_url( $p = '' ) { return 'https://exemplo.test/wp-admin/' . $p; } // phpcs:ignore
function wp_die( $m ) { throw new \RuntimeException( $m ); } // phpcs:ignore
function wp_get_upload_dir() { return $GLOBALS['t']['uploads'] + array( 'basedir' => ABSPATH . 'wp-content/uploads', 'baseurl' => 'http://exemplo.test/wp-content/uploads', 'error' => false ); } // phpcs:ignore
function wp_remote_retrieve_response_code( $r ) { return $r['status']; } // phpcs:ignore

/**
 * Apache simulado: lê o .htaccess da pasta e aplica o bloco.
 *
 * @param string $url  Endereço.
 * @param array  $args Argumentos.
 * @return array|null
 */
function wp_remote_get( $url, $args ) { // phpcs:ignore
	$t = &$GLOBALS['t'];
	++$t['pedidos'];
	$t['args'][] = $args;
	if ( $t['antes_de'] ) {
		call_user_func( $t['antes_de'], $t['pedidos'] );
	}
	$caminho = (string) parse_url( $url, PHP_URL_PATH );
	$ensaio  = false !== strpos( $caminho, '/uploads/' );
	// Todo .htaccess da raiz até a pasta do arquivo, na ordem.
	$linhas = array();
	$pasta  = rtrim( ABSPATH, '/' );
	foreach ( array_merge( array( '' ), array_filter( explode( '/', dirname( $caminho ) ) ) ) as $parte ) {
		$pasta .= '' === $parte ? '' : '/' . $parte;
		$h      = $pasta . '/.htaccess';
		clearstatcache();
		if ( is_file( $h ) ) {
			$linhas = array_merge( $linhas, preg_split( '/\r?\n/', file_get_contents( $h ) ) );
		}
		if ( '' === $parte ) {
			$t['raiz_com_bloco'] = $t['raiz_com_bloco'] || false !== strpos( implode( "\n", $linhas ), 'Header set' );
		}
	}
	$regras   = preg_grep( '/^Header set /', $linhas );
	$servidor = $t['so_raiz'] && $ensaio ? 'apache' : $t['servidor'];
	$proxy    = $t['proxy'] && ! ( $t['so_raiz'] && $ensaio );
	if ( ! empty( $t['sem_rede'] ) ) {
		return null;
	}
	if ( $regras && 'erro' === $servidor ) {
		return array( 'status' => 500, 'headers' => array() );
	}
	if ( $ensaio && ( $t['uploads_fechado'] || ! is_file( ABSPATH . ltrim( $caminho, '/' ) ) ) ) {
		return array( 'status' => $t['uploads_fechado'] ? 403 : 404, 'headers' => array() );
	}
	$estatico = $ensaio || false !== strpos( $url, 'blank.gif' );
	$always   = array();
	$sucesso  = array();
	if ( ! $estatico ) {
		unset( $_SERVER['HTTP_X_CONSULTA_LEVE_CONFERENCIA'] );
		if ( isset( $args['headers']['X-Consulta-Leve-Conferencia'] ) && empty( $t['cache_na_frente'] ) ) {
			$_SERVER['HTTP_X_CONSULTA_LEVE_CONFERENCIA'] = $args['headers']['X-Consulta-Leve-Conferencia'];
		}
		header_remove();
		$php = array_merge( array_diff_key( consulta_leve_cabecalhos_enviar(), $t['tema'] ), $t['tema'] );
		header_remove();
		unset( $_SERVER['HTTP_X_CONSULTA_LEVE_CONFERENCIA'] );
		// CGI e FPM põem os cabeçalhos do PHP em always; o mod_php, em onsuccess.
		foreach ( $php as $n => $v ) {
			if ( 'modphp' === $t['sapi'] ) {
				$sucesso[ $n ][] = $v;
			} else {
				$always[ $n ][] = $v;
			}
		}
	}
	foreach ( $t['vhost'] as $n => $v ) {
		$sucesso[ $n ] = array( $v );
	}
	if ( 'nao_le' !== $servidor ) {
		foreach ( preg_grep( '/^Header always set /', $linhas ) as $l ) {
			confere( 'linha da sonda no formato fechado', preg_match( '/^Header always set ([A-Za-z-]+) "consulta-leve-sonda"$/', $l, $m ), 1 );
			$always[ $m[1] ] = array( CONSULTA_LEVE_HTACCESS_SONDA );
		}
		foreach ( $regras as $regra ) {
			confere( 'linha do bloco no formato fechado', preg_match( '/^Header set ([A-Za-z-]+) "([^"]+)" "expr=([^"]+)"$/', $regra, $m ), 1 );
			$vale = true;
			foreach ( explode( ' && ', $m[3] ) as $parte ) {
				if ( "%{HTTPS} == 'on'" === $parte ) {
					$vale = $vale && 'https' === $t['esquema'] && ! $proxy;
				} else {
					confere( 'condição conhecida', preg_match( "/^-z resp\('([A-Za-z-]+)'\)$/", $parte, $r ), 1 );
					$vale = $vale && empty( $always[ $r[1] ] ) && empty( $sucesso[ $r[1] ] );
				}
			}
			if ( $vale || 'ignora_condicao' === $servidor ) {
				$sucesso[ $m[1] ] = array( $m[2] );
			}
		}
	}
	$saida = array();
	foreach ( array( $always, $sucesso ) as $tabela ) {
		foreach ( $tabela as $n => $vs ) {
			foreach ( $vs as $v ) {
				$saida[ strtolower( $n ) ][] = $v;
			}
		}
	}
	foreach ( $saida as $n => $vs ) {
		$saida[ $n ] = 1 === count( $vs ) ? $vs[0] : $vs;
	}

	return array( 'status' => $ensaio ? 200 : $t['status'], 'headers' => $saida, 'body' => $ensaio ? $t['corpo'] ?? file_get_contents( ABSPATH . ltrim( $caminho, '/' ) ) : '' );
}

$GLOBALS['t']['proprio'] = true;
add_filter(
	'consulta_leve_modulos',
	static function () {
		return array( 'nucleo/config.php', 'nucleo/coexistencia.php', 'nucleo/trava.php', 'nucleo/cache.php', 'nucleo/registro.php', 'seguranca/cabecalhos.php', 'seguranca/ensaio.php', 'seguranca/htaccess.php', 'admin/admin.php' );
	}
);
require __DIR__ . '/../../consulta-leve/consulta-leve.php';

const ARQUIVO = ABSPATH . '.htaccess';

$tudo = array(
	'cab_nosniff'  => true,
	'cab_referrer' => true,
	'cab_frame'    => true,
	'cab_upgrade'  => true,
	'cab_hsts'     => 'ano',
);
$wp   = "# regra do dono\nOptions -Indexes\n\n# BEGIN WordPress\n<IfModule mod_rewrite.c>\nRewriteEngine On\n</IfModule>\n# END WordPress\n";

/**
 * Cenário limpo.
 *
 * @param string|null $conteudo Conteúdo do .htaccess (null: não existe).
 * @param array       $extra    Mudanças no servidor simulado.
 * @param array       $config   Configuração gravada.
 */
function cenario( ?string $conteudo, array $extra = array(), ?array $config = null ) { // phpcs:ignore
	global $tudo;
	cl_teste_reset( array( 'pode' => array( 'manage_options' => true ) ) );
	$GLOBALS['t'] = array_merge(
		array(
			'proprio'         => true,
			'multisite'       => false,
			'mods'            => true,
			'esquema'         => 'https',
			'servidor'        => 'apache',
			'sapi'            => 'fpm',
			'status'          => 200,
			'tema'            => array(),
			'vhost'           => array(),
			'pedidos'         => 0,
			'args'            => array(),
			'antes_de'        => null,
			'sem_rede'        => false,
			'cache_na_frente' => false,
			'proxy'           => false,
			'nocache'         => false,
			'uploads'         => array(),
			'uploads_fechado' => false,
			'so_raiz'         => false,
			'raiz_com_bloco'  => false,
		),
		$extra
	);
	$_SERVER['SERVER_SOFTWARE'] = 'Apache/2.4.58 (Unix)';
	if ( is_file( ARQUIVO ) ) {
		unlink( ARQUIVO );
	}
	if ( null !== $conteudo ) {
		file_put_contents( ARQUIVO, $conteudo );
	}
	consulta_leve_config_gravar( null === $config ? $tudo : $config );
}

/** Bytes atuais (null sem arquivo). */
function bytes() { // phpcs:ignore
	clearstatcache();
	return is_file( ARQUIVO ) ? file_get_contents( ARQUIVO ) : null;
}

/** Estado e motivo. */
function situacao() { // phpcs:ignore
	$e = consulta_leve_htaccess_estado();
	return array( $e['estado'], $e['motivo'], consulta_leve_opcao( 'cab_htaccess' ) );
}

/** Sobrou temporário na pasta? */
function sobras() { // phpcs:ignore
	return array_merge( (array) glob( ABSPATH . '.htaccess-*' ), (array) glob( ABSPATH . 'wp-content/uploads/*' ) );
}

/* Bloco ----------------------------------------------------------------------- */

confere( 'padrão desligado', consulta_leve_config_padrao()['cab_htaccess'], false );
confere( 'nada marcado: sem bloco', consulta_leve_htaccess_bloco( consulta_leve_config_padrao() ), '' );
$esperado = <<<'TXT'
# BEGIN Consulta Leve
# Gerado pelo plugin; mude as escolhas no painel.
<IfModule mod_headers.c>
Header set X-Content-Type-Options "nosniff" "expr=-z resp('X-Content-Type-Options')"
Header set Referrer-Policy "strict-origin-when-cross-origin" "expr=-z resp('Referrer-Policy')"
Header set X-Frame-Options "SAMEORIGIN" "expr=-z resp('X-Frame-Options') && -z resp('X-Consulta-Leve-Quadro') && -z resp('X-WP-embed')"
Header set Content-Security-Policy "upgrade-insecure-requests" "expr=%{HTTPS} == 'on' && -z resp('Content-Security-Policy')"
Header set Strict-Transport-Security "max-age=31536000" "expr=%{HTTPS} == 'on' && -z resp('Strict-Transport-Security')"
</IfModule>
# END Consulta Leve

TXT;
$esperado = str_replace( "\r\n", "\n", $esperado );
confere( 'bloco completo, igual ao contrato', consulta_leve_htaccess_bloco( $tudo ), $esperado );
confere( 'bloco em CRLF', consulta_leve_htaccess_bloco( $tudo, "\r\n" ), str_replace( "\n", "\r\n", $esperado ) );
confere( 'quebra estranha vira LF', consulta_leve_htaccess_bloco( $tudo, "\nSetEnv X 1\n" ), $esperado );
confere( 'contrato traz o bloco', false !== strpos( str_replace( "\r\n", "\n", file_get_contents( __DIR__ . '/../../docs/CONTRATO.md' ) ), $esperado ), true );

// Injeção: nenhum valor estranho chega ao arquivo.
foreach ( array( "nosniff\"\nSetHandler x", "ano\nRequire all denied", '"><', array( "\n" ), 1, null, '%{env:X}s' ) as $i => $mau ) {
	$config = array_fill_keys( array( 'cab_nosniff', 'cab_referrer', 'cab_frame', 'cab_upgrade', 'cab_hsts', "X\nMau" ), $mau );
	confere( "injeção {$i}: nada gerado", consulta_leve_htaccess_bloco( $config ), '' );
	confere( "injeção {$i}: saneada antes", consulta_leve_htaccess_bloco( consulta_leve_config_sanitizar( array( 'cab_hsts' => $mau ) ) ), '' );
}
confere( 'sem cabeçalho obsoleto', preg_match( '/X-XSS-Protection|interest-cohort/', consulta_leve_htaccess_bloco( $tudo ) ), 0 );

/* Conteúdo ----------------------------------------------------------------------- */

$com = static function ( string $c, ?array $config = null ) use ( $tudo ) { // phpcs:ignore
	return consulta_leve_htaccess_com_bloco( $c, null === $config ? $tudo : $config );
};
$sem = 'consulta_leve_htaccess_sem_bloco';

$r = $com( $wp );
confere( 'logo antes do WordPress', $r, array( 'motivo' => '', 'conteudo' => str_replace( '# BEGIN WordPress', $esperado . '# BEGIN WordPress', $wp ) ) );
confere( 'ida e volta: byte a byte', $sem( $r['conteudo'] ), array( 'motivo' => '', 'conteudo' => $wp ) );
confere( 'idempotente', $com( $r['conteudo'] ), $r );
confere( 'troca só o próprio bloco', $com( $r['conteudo'], array( 'cab_nosniff' => true ) )['conteudo'], $com( $wp, array( 'cab_nosniff' => true ) )['conteudo'] );
confere( 'sem marcador do WordPress: no topo', $com( "Options -Indexes\n" )['conteudo'], $esperado . "Options -Indexes\n" );
confere( 'arquivo vazio', $com( '' )['conteudo'], $esperado );
confere( 'sem quebra no fim', $sem( $com( 'Options -Indexes' )['conteudo'] )['conteudo'], 'Options -Indexes' );
$crlf = str_replace( "\n", "\r\n", $wp );
confere( 'CRLF: bloco em CRLF', $com( $crlf )['conteudo'], str_replace( "\n", "\r\n", $r['conteudo'] ) );
confere( 'CRLF: volta igual', $sem( $com( $crlf )['conteudo'] )['conteudo'], $crlf );
$misto = "a\r\nb\nc\r\n\tx \r# BEGIN WordPress\n\n  # BEGIN WordPress  \r\n# END WordPress";
confere( 'quebras mistas e espaços preservados', $sem( $com( $misto )['conteudo'] )['conteudo'], $misto );
$bom = "\xEF\xBB\xBFOptions -Indexes\n";
confere( 'BOM continua no começo', substr( $com( $bom )['conteudo'], 0, 25 ), "\xEF\xBB\xBF# BEGIN Consulta Leve\n" );
confere( 'BOM: volta igual', $sem( $com( $bom )['conteudo'] )['conteudo'], $bom );
$latin = "# a\xE7\xE3o \xFF\xFE\n" . $wp;
confere( 'bytes fora de UTF-8 preservados', $sem( $com( $latin )['conteudo'] )['conteudo'], $latin );
$outros = "# BEGIN LSCACHE\n# END LSCACHE\n# BEGIN W3TC Page Cache core\n# END W3TC Page Cache core\n# BEGIN Consulta Leve Extra\nx\n# END Consulta Leve Extra\n" . $wp;
confere( 'outros marcadores intactos', $sem( $com( $outros )['conteudo'] )['conteudo'], $outros );
confere( 'sem bloco: nada sai', $sem( $outros ), array( 'motivo' => '', 'conteudo' => $outros ) );
confere( 'marcador só na própria linha', $sem( "# veja # BEGIN Consulta Leve\n" . $wp )['motivo'], '' );

$ini = "# BEGIN Consulta Leve\n";
$fim = "# END Consulta Leve\n";
foreach (
	array(
		'começo repetido'         => array( $ini . $ini . $fim . $wp, 'marcadores' ),
		'bloco repetido'          => array( $ini . $fim . $ini . $fim . $wp, 'marcadores' ),
		'fim repetido'            => array( $ini . $fim . $fim . $wp, 'marcadores' ),
		'sem fim'                 => array( $ini . "Header x\n" . $wp, 'marcadores' ),
		'sem fim, no fim'         => array( $wp . $ini . "Header x\n", 'marcadores' ),
		'sem começo'              => array( $fim . $wp, 'marcadores' ),
		'fim antes do começo'     => array( $fim . $ini . $wp, 'marcadores' ),
		'minúsculas'              => array( "# begin consulta leve\n" . $fim . $wp, 'marcadores' ),
		'sem espaço'              => array( "#BEGIN Consulta Leve\n" . $fim . $wp, 'marcadores' ),
		'outro marcador dentro'   => array( $ini . "# BEGIN Outro\n# END Outro\n" . $fim . $wp, 'marcadores' ),
		'WordPress dentro'        => array( $ini . $wp . $fim, 'marcadores' ),
		'WordPress repetido'      => array( $wp . $wp, 'marcador_wp' ),
		'dentro de seção'         => array( "<IfModule a.c>\n" . $wp . "</IfModule>\n", 'secao' ),
		'seção fechada a mais'    => array( "</IfModule>\n" . $wp, 'secao' ),
		'linha continuada'        => array( "# nota \\\n# BEGIN WordPress\n# END WordPress\n", 'continuacao' ),
		'nosso marcador continua' => array( "SetEnv A \\\n" . $ini . $fim, 'continuacao' ),
		'byte nulo'               => array( "a\0b\n" . $wp, 'binario' ),
	) as $nome => $caso
) {
	confere( "recusa: {$nome}", $com( $caso[0] ), array( 'motivo' => $caso[1], 'conteudo' => $caso[0] ) );
}
confere( 'seção fechada antes: aceita', $com( "<IfModule a.c>\n</IfModule>\n" . $wp )['motivo'], '' );

/* Recusas ------------------------------------------------------------------------- */

foreach ( array( 'Apache' => true, 'Apache/2.4.58 (Unix) OpenSSL/3' => true, 'LiteSpeed' => true, 'openlitespeed/1.7.19' => true, 'nginx/1.25' => false, 'Apache-Coyote/1.1' => false, 'Microsoft-IIS/10.0' => false, 'PHP.wasm' => false, '' => false, 'proxy Apache' => false ) as $software => $ok ) {
	confere( "servidor: {$software}", consulta_leve_htaccess_servidor_ok( $software ), $ok );
}
$bons = array( 'proprio' => true, 'pode' => true, 'multisite' => false, 'servidor' => true, 'bloqueado' => false, 'dispensado' => false, 'escolha' => true, 'caminho' => true, 'link' => false, 'existe' => true, 'arquivo' => true, 'tamanho' => 10, 'leitura' => true, 'escrita' => true, 'ensaio' => true );
confere( 'tudo certo', consulta_leve_htaccess_recusa( $bons ), '' );
confere( 'sem fatos: recusa', consulta_leve_htaccess_recusa( array() ), 'embutida' );
foreach (
	array(
		'embutida'    => array( 'proprio' => false ),
		'permissao'   => array( 'pode' => false ),
		'multisite'   => array( 'multisite' => true ),
		'servidor'    => array( 'servidor' => false ),
		'bloqueado'   => array( 'bloqueado' => true ),
		'dispensado'  => array( 'dispensado' => true ),
		'sem_escolha' => array( 'escolha' => false ),
		'caminho'     => array( 'caminho' => false ),
		'link'        => array( 'link' => true ),
		'nao_arquivo' => array( 'arquivo' => false ),
		'tamanho'     => array( 'tamanho' => CONSULTA_LEVE_HTACCESS_MAX + 1 ),
		'sem_leitura' => array( 'leitura' => false ),
		'sem_escrita' => array( 'escrita' => false ),
		'ensaio_local' => array( 'ensaio' => false ),
	) as $motivo => $ruim
) {
	confere( "recusa {$motivo}", consulta_leve_htaccess_recusa( array_merge( $bons, $ruim ) ), $motivo );
	confere( "texto do motivo {$motivo}", isset( consulta_leve_htaccess_motivos()[ $motivo ] ), true );
}
confere( 'link quebrado também recusa', consulta_leve_htaccess_recusa( array_merge( $bons, array( 'link' => true, 'existe' => false ) ) ), 'link' );
confere( 'tirar não exige Apache', consulta_leve_htaccess_recusa( array_merge( $bons, array( 'servidor' => false, 'escolha' => false, 'ensaio' => false ) ), false ), '' );
confere( 'tirar respeita DISALLOW_FILE_MODS', consulta_leve_htaccess_recusa( array_merge( $bons, array( 'bloqueado' => true ) ), false ), 'bloqueado' );
confere( 'caminho: pasta do WordPress', consulta_leve_htaccess_caminho_ok( ARQUIVO ), true );
confere( 'caminho: pasta acima', consulta_leve_htaccess_caminho_ok( dirname( ABSPATH ) . '/.htaccess' ), true );
confere( 'caminho: outra pasta', consulta_leve_htaccess_caminho_ok( __DIR__ . '/.htaccess' ), false );
confere( 'caminho: outro arquivo', consulta_leve_htaccess_caminho_ok( ABSPATH . 'wp-config.php' ), false );
confere( 'caminho: raiz do disco', consulta_leve_htaccess_caminho_ok( '/.htaccess' ), false );
confere( 'caminho: vazio', consulta_leve_htaccess_caminho_ok( '' ), false );

/* Recusas de ponta a ponta: nada muda ------------------------------------------------ */

$nada = static function ( string $nome, string $motivo, array $extra = array(), string $software = 'Apache', ?array $config = null ) use ( $wp, $tudo ) { // phpcs:ignore
	cenario( $wp, $extra, null === $config ? $tudo : $config );
	$_SERVER['SERVER_SOFTWARE'] = $software;
	confere( "{$nome}: recusa", consulta_leve_htaccess_aplicar( 'ligar' ), $motivo );
	confere( "{$nome}: estado", situacao(), array( 'recusado', $motivo, false ) );
	confere( "{$nome}: arquivo intacto e nunca escrito", array( bytes(), $GLOBALS['t']['raiz_com_bloco'] ), array( $wp, false ) );
	confere( "{$nome}: sem sobras", sobras(), array() );
	confere( "{$nome}: PHP segue", count( consulta_leve_cabecalhos_enviar() ) >= ( null === $config ? 5 : 0 ), true );
	confere( "{$nome}: registrado", consulta_leve_registro()[0]['resultado'], $motivo );
};
$nada( 'nginx', 'servidor', array(), 'nginx/1.25' );
$nada( 'multisite', 'multisite', array( 'multisite' => true ) );
$nada( 'embutida', 'embutida', array( 'proprio' => false ) );
$nada( 'DISALLOW_FILE_MODS', 'bloqueado', array( 'mods' => false ) );
$nada( 'nada marcado', 'sem_escolha', array(), 'Apache', array() );
$nada( 'sem rede', 'ensaio_local', array( 'sem_rede' => true ) );
$nada( 'rede cai antes da base', 'base_sem_resposta', array( 'antes_de' => static function ( $n ) { $GLOBALS['t']['sem_rede'] = $n >= 5; } ) ); // phpcs:ignore
$nada( 'uploads fora do WordPress', 'ensaio_local', array( 'uploads' => array( 'basedir' => __DIR__ ) ) );
$nada( 'uploads inexistente', 'ensaio_local', array( 'uploads' => array( 'basedir' => ABSPATH . 'nao-existe' ) ) );
$nada( 'uploads com erro', 'ensaio_local', array( 'uploads' => array( 'error' => 'x' ) ) );
$nada( 'uploads em outro endereço', 'ensaio_local', array( 'uploads' => array( 'baseurl' => 'https://cdn.exemplo.test/u' ) ) );
$nada( 'uploads em outra porta', 'ensaio_local', array( 'uploads' => array( 'baseurl' => 'https://exemplo.test:8443/wp-content/uploads' ) ) );
$nada( 'uploads sem acesso pela web', 'ensaio_local', array( 'uploads_fechado' => true ) );
$nada( 'uploads respondido por outra coisa', 'ensaio_local', array( 'corpo' => 'página de erro com 200' ) );
$nada( 'manutenção', 'base_status', array( 'status' => 503 ) );
$nada( 'redireciona', 'base_status', array( 'status' => 301 ) );
$nada( 'cache na frente', 'sem_eco', array( 'cache_na_frente' => true ) );
cenario( $wp );
$GLOBALS['cl_t']['pode'] = array();
confere( 'sem permissão', consulta_leve_htaccess_aplicar( 'ligar' ), 'permissao' );
cenario( $wp );
$GLOBALS['cl_t']['suporte']['consulta-leve'] = array( array( 'dispensar' => array( 'cabecalhos' ) ) );
confere( 'tema dispensou', consulta_leve_htaccess_aplicar( 'ligar' ), 'dispensado' );
confere( 'tema dispensou: intacto', bytes(), $wp );
cenario( $ini . $wp );
confere( 'marcador sem par: recusa', consulta_leve_htaccess_aplicar( 'ligar' ), 'marcadores' );
confere( 'marcador sem par: intacto, sem pedido', array( bytes(), $GLOBALS['t']['pedidos'] ), array( $ini . $wp, 0 ) );
cenario( str_repeat( "# linha\n", 20000 ) );
confere( 'arquivo grande', consulta_leve_htaccess_aplicar( 'ligar' ), 'tamanho' );
cenario( null );
mkdir( ARQUIVO );
confere( 'pasta no lugar do arquivo', consulta_leve_htaccess_aplicar( 'ligar' ), 'nao_arquivo' );
rmdir( ARQUIVO );
cenario( null );
file_put_contents( ABSPATH . 'alvo.txt', $wp );
if ( @symlink( ABSPATH . 'alvo.txt', ARQUIVO ) ) {
	confere( 'link simbólico', consulta_leve_htaccess_aplicar( 'ligar' ), 'link' );
	confere( 'link: alvo intacto', file_get_contents( ABSPATH . 'alvo.txt' ), $wp );
	confere( 'link: escrita direta recusa', consulta_leve_htaccess_escrever( ARQUIVO, $wp, 'x' ), 'mudou' );
	unlink( ARQUIVO );
}
// Sem permissão para criar link nesta máquina, vale a regra pura conferida acima.

/* Ligar com sucesso -------------------------------------------------------------------- */

foreach ( array( 'fpm', 'modphp' ) as $sapi ) {
	cenario( $wp, array( 'sapi' => $sapi ) );
	confere( "{$sapi}: liga", consulta_leve_htaccess_aplicar( 'ligar' ), 'ok' );
	confere( "{$sapi}: estado", situacao(), array( 'ativo', '', true ) );
	confere( "{$sapi}: arquivo", bytes(), $com( $wp )['conteudo'] );
	confere( "{$sapi}: 4 do ensaio, 2 antes e 4 depois", $GLOBALS['t']['pedidos'], 10 );
	confere( "{$sapi}: PHP calado", consulta_leve_cabecalhos_enviar(), array() );
	confere( "{$sapi}: página com cada cabeçalho uma vez", array_filter( wp_remote_get( home_url( '/' ), array() )['headers'], 'is_array' ), array() );
	confere( "{$sapi}: página com os cinco", count( wp_remote_get( home_url( '/' ), array() )['headers'] ), 5 );
	confere( "{$sapi}: token apagado", consulta_leve_htaccess_estado()['token'], '' );
	confere( "{$sapi}: conferido, a cópia sai do banco", array_key_exists( CONSULTA_LEVE_OPCAO_HTACCESS_COPIA, $GLOBALS['cl_t']['opcoes'] ), false );
	confere( "{$sapi}: nome do ensaio apagado", consulta_leve_htaccess_estado()['ensaio'], '' );
	confere( "{$sapi}: sem sobras", sobras(), array() );
	confere( "{$sapi}: trava livre", consulta_leve_trava_ler( consulta_leve_trava_opcao( 'htaccess' ) ), '' );
	confere( "{$sapi}: de novo não muda nada", array( consulta_leve_htaccess_aplicar( 'ligar' ), bytes() ), array( 'ok', $com( $wp )['conteudo'] ) );
	confere( "{$sapi}: desliga", consulta_leve_htaccess_remover( 'desligar' ), 'ok' );
	confere( "{$sapi}: volta igual", bytes(), $wp );
	confere( "{$sapi}: desligado", situacao(), array( 'desligado', '', false ) );
	confere( "{$sapi}: PHP volta", count( consulta_leve_cabecalhos_enviar() ), 5 );
}
confere( 'pedidos: sem redirecionar e com tempo curto', array( $GLOBALS['t']['args'][0]['redirection'], $GLOBALS['t']['args'][0]['timeout'] <= 5 ), array( 0, true ) );
confere( 'resposta da conferência sem cache', $GLOBALS['t']['nocache'], true );

cenario( $crlf );
confere( 'CRLF: liga', consulta_leve_htaccess_aplicar( 'ligar' ), 'ok' );
consulta_leve_htaccess_revisar( true );
confere( 'CRLF: revisão aceita', situacao()[0], 'ativo' );
consulta_leve_htaccess_remover( 'desligar' );
confere( 'CRLF: volta igual', bytes(), $crlf );

cenario( null );
confere( 'sem arquivo: cria', array( consulta_leve_htaccess_aplicar( 'ligar' ), bytes() ), array( 'ok', $esperado ) );
confere( 'sem arquivo: muda escolha', array( consulta_leve_config_gravar( array( 'cab_hsts' => 'nao' ) ) ? consulta_leve_htaccess_sincronizar() : '', consulta_leve_htaccess_estado()['existia'] ), array( 'ok', false ) );
consulta_leve_htaccess_remover( 'desligar' );
confere( 'sem arquivo: some ao desligar', bytes(), null );

cenario( $wp, array( 'esquema' => 'http' ) );
confere( 'site em http: liga sem exigir HSTS', consulta_leve_htaccess_aplicar( 'ligar' ), 'ok' );
cenario( $wp, array( 'tema' => array( 'X-Frame-Options' => 'DENY', 'Content-Security-Policy' => "default-src 'self'" ) ) );
confere( 'tema já envia: liga', consulta_leve_htaccess_aplicar( 'ligar' ), 'ok' );
$h = wp_remote_get( home_url( '/' ), array() )['headers'];
confere( 'tema já envia: fica o do tema', array( $h['x-frame-options'], $h['content-security-policy'] ), array( 'DENY', "default-src 'self'" ) );
cenario( $wp, array( 'vhost' => array( 'X-Content-Type-Options' => 'nosniff' ), 'sapi' => 'modphp' ) );
confere( 'servidor já envia: liga sem repetir', consulta_leve_htaccess_aplicar( 'ligar' ), 'ok' );

/* Conferência que falha: volta tudo --------------------------------------------------------- */

foreach (
	array(
		'erro 500'          => array( 'erro_5xx', 'erro_5xx', array( 'servidor' => 'erro' ) ),
		'sem mod_headers'   => array( 'ausente', 'ausente', array( 'servidor' => 'nao_le' ) ),
		'HTTPS no proxy'    => array( 'ausente', 'ausente', array( 'proxy' => true ) ),
		'condição ignorada' => array( 'condicao', 'condicao', array( 'servidor' => 'ignora_condicao', 'esquema' => 'http' ) ),
		'repete no FPM'     => array( 'duplicado', 'duplicado', array( 'servidor' => 'ignora_condicao' ) ),
		'troca no mod_php'  => array( 'duplicado', 'trocado', array( 'servidor' => 'ignora_condicao', 'sapi' => 'modphp' ) ),
		'troca o do tema'   => array( 'duplicado', 'trocado', array( 'servidor' => 'ignora_condicao', 'sapi' => 'modphp', 'tema' => array( 'Referrer-Policy' => 'no-referrer' ) ) ),
	) as $nome => $caso
) {
	foreach ( array( false, true ) as $so_raiz ) {
		foreach ( array( $wp, $crlf, null ) as $i => $antes ) {
			$onde = $so_raiz ? 'raiz' : 'ensaio';
			cenario( $antes, $caso[2] + array( 'so_raiz' => $so_raiz ) );
			confere( "{$nome} {$i} ({$onde}): motivo", consulta_leve_htaccess_aplicar( 'ligar' ), $caso[ $so_raiz ? 1 : 0 ] );
			confere( "{$nome} {$i} ({$onde}): bytes de volta", bytes(), $antes );
			confere( "{$nome} {$i} ({$onde}): raiz escrita só depois do ensaio", $GLOBALS['t']['raiz_com_bloco'], $so_raiz );
			confere( "{$nome} {$i} ({$onde}): estado", situacao(), array( 'recusado', $caso[ $so_raiz ? 1 : 0 ], false ) );
			confere( "{$nome} {$i} ({$onde}): sem sobras", sobras(), array() );
			confere( "{$nome} {$i} ({$onde}): desfeito, sem cópia", consulta_leve_htaccess_copia( ARQUIVO ), null );
		}
	}
	confere( "{$nome}: PHP segue", count( consulta_leve_cabecalhos_enviar() ), 'http' === $GLOBALS['t']['esquema'] ? 3 : 5 );
}

// Rede cai depois da escrita.
cenario( $wp, array( 'antes_de' => static function ( $n ) { $GLOBALS['t']['sem_rede'] = $n >= 8; } ) ); // phpcs:ignore
confere( 'rede cai: volta, sem conseguir conferir', consulta_leve_htaccess_aplicar( 'ligar' ), 'volta_nao_conferida' );
confere( 'rede cai: bytes de volta e cópia guardada', array( bytes(), consulta_leve_htaccess_copia( ARQUIVO ) ), array( $wp, $wp ) );
consulta_leve_htaccess_remover( 'desligar' );
confere( 'rede cai, depois desligado: cópia sai', consulta_leve_htaccess_copia( ARQUIVO ), null );
// Uma página muda de código.
cenario( $wp, array( 'antes_de' => static function ( $n ) { $GLOBALS['t']['status'] = 8 === $n ? 403 : 200; } ) ); // phpcs:ignore
confere( 'código muda', array( consulta_leve_htaccess_aplicar( 'ligar' ), bytes() ), array( 'status_mudou', $wp ) );
// Erro de código no meio.
$falha = 0;
add_action( 'consulta_leve_falha', static function () use ( &$falha ) { ++$falha; } ); // phpcs:ignore
foreach ( array( 1, 2, 4, 5, 7, 9, 10 ) as $i => $quando ) {
	cenario( $wp, array( 'antes_de' => static function ( $n ) use ( $quando ) { if ( $quando === $n ) { throw new \RuntimeException( 'x' ); } } ) ); // phpcs:ignore
	confere( "exceção no pedido {$quando}: desfaz", array( consulta_leve_htaccess_aplicar( 'ligar' ), bytes(), situacao()[0], $falha, sobras() ), array( 'erro', $wp, 'recusado', $i + 1, array() ) );
}
confere( 'exceção: trava livre', consulta_leve_trava_ler( consulta_leve_trava_opcao( 'htaccess' ) ), '' );

/* Arquivo mudado por outro ------------------------------------------------------------------- */

$alheio = "# outro plugin\n";
cenario( $wp, array( 'antes_de' => static function ( $n ) use ( $alheio ) { 6 === $n && file_put_contents( ARQUIVO, $alheio, FILE_APPEND ); } ) ); // phpcs:ignore
confere( 'mudou entre ler e escrever: recusa', consulta_leve_htaccess_aplicar( 'ligar' ), 'mudou' );
confere( 'mudou entre ler e escrever: fica o do outro', bytes(), $wp . $alheio );
cenario( $wp, array( 'servidor' => 'nao_le', 'so_raiz' => true, 'antes_de' => static function ( $n ) use ( $alheio ) { 7 === $n && file_put_contents( ARQUIVO, $alheio, FILE_APPEND ); } ) ); // phpcs:ignore
confere( 'mudou durante a conferência que falha', consulta_leve_htaccess_aplicar( 'ligar' ), 'ausente' );
confere( 'sai só o nosso bloco', bytes(), $wp . $alheio );
cenario( $wp, array( 'antes_de' => static function ( $n ) use ( $alheio ) { 10 === $n && file_put_contents( ARQUIVO, $alheio, FILE_APPEND ); } ) ); // phpcs:ignore
confere( 'mudou no fim da conferência: não vira ativo', array( consulta_leve_htaccess_aplicar( 'ligar' ), situacao()[0], bytes() ), array( 'mudou', 'recusado', $wp . $alheio ) );
cenario( $wp );
confere( 'escrita direta com esperado errado', array( consulta_leve_htaccess_escrever( ARQUIVO, 'outro', 'x' ), bytes() ), array( 'mudou', $wp ) );
confere( 'escrita direta: esperava não existir', array( consulta_leve_htaccess_escrever( ARQUIVO, null, 'x' ), bytes() ), array( 'mudou', $wp ) );
confere( 'escrita direta: sumiu', consulta_leve_htaccess_escrever( ABSPATH . 'sumiu', 'x', 'y' ), 'mudou' );
chmod( ARQUIVO, 0640 );
$modo = fileperms( ARQUIVO ) & 0777;
confere( 'escrita direta: certa', array( consulta_leve_htaccess_escrever( ARQUIVO, $wp, 'novo' ), bytes() ), array( '', 'novo' ) );
clearstatcache();
confere( 'escrita direta: mesmas permissões', fileperms( ARQUIVO ) & 0777, $modo );
consulta_leve_travar( 'htaccess', 60 );
cenario( $wp );
consulta_leve_travar( 'htaccess', 60 );
confere( 'outra requisição com a trava', array( consulta_leve_htaccess_aplicar( 'ligar' ), bytes(), situacao()[0] ), array( 'ocupado', $wp, 'desligado' ) );

// Outro processo segurando a trava do sistema: não escreve.
cenario( $wp );
$preso = fopen( ARQUIVO, 'r+b' );
flock( $preso, LOCK_EX );
$tomou = microtime( true );
confere( 'flock alheio: ocupado, sem escrever', consulta_leve_htaccess_trocar( ARQUIVO, $wp, 'novo' ), 'ocupado' );
confere( 'flock alheio: espera limitada', microtime( true ) - $tomou < 6, true );
flock( $preso, LOCK_UN );
fclose( $preso );
confere( 'flock alheio: intacto, sem sobras', array( bytes(), sobras() ), array( $wp, array() ) );

// Como o insert_with_markers: abre, espera a trava, acrescenta. A mudança nunca se perde.
file_put_contents( ABSPATH . 'outro.php', '<?php $h = fopen( $argv[1], "r+b" ); touch( $argv[2] ); flock( $h, LOCK_EX ); usleep( (int) $argv[3] ); fseek( $h, 0, SEEK_END ); fwrite( $h, "# outro plugin\n" ); fflush( $h ); flock( $h, LOCK_UN );' );
foreach ( array( 0, 30000, 120000 ) as $i => $espera ) {
	cenario( $wp );
	$sinal = ABSPATH . 'pronto' . $i;
	$p     = proc_open( array( PHP_BINARY, ABSPATH . 'outro.php', ARQUIVO, $sinal, (string) $espera ), array(), $canos );
	for ( $k = 0; $k < 500 && ! file_exists( $sinal ); $k++ ) {
		usleep( 1000 );
		clearstatcache();
	}
	$r = consulta_leve_htaccess_escrever( ARQUIVO, $wp, $com( $wp )['conteudo'] );
	proc_close( $p );
	confere( "concorrência {$i}: mudança do outro não se perde", false !== strpos( bytes(), trim( $alheio ) ), true );
	confere( "concorrência {$i}: sem sobras", sobras(), array() );
}

/* Pasta de ensaio ------------------------------------------------------------------------------- */

$ens  = 'consulta-leve-ensaio-' . str_repeat( 'a', 32 );
$onde = ABSPATH . 'wp-content/uploads/' . $ens;
$por  = static function ( string $extra = '' ) use ( $onde ) {
	mkdir( $onde );
	mkdir( $onde . '/sonda' );
	foreach ( CONSULTA_LEVE_HTACCESS_ENSAIO as $n ) {
		file_put_contents( $onde . '/' . $n, 'x' );
	}
	if ( '' !== $extra ) {
		file_put_contents( $onde . '/' . $extra, 'de outro' );
	}
};
cenario( $wp );
$por();
confere( 'ensaio: some inteiro', array( consulta_leve_htaccess_ensaio_limpar( $ens ), sobras() ), array( true, array() ) );
$por( 'foto.jpg' );
confere( 'ensaio: arquivo de outro fica', array( consulta_leve_htaccess_ensaio_limpar( $ens ), array_values( array_diff( scandir( $onde ), array( '.', '..' ) ) ) ), array( false, array( 'foto.jpg' ) ) );
remover_pasta( $onde );
foreach ( array( '..', '../uploads', 'consulta-leve-ensaio-', $ens . '/..', strtoupper( $ens ), $ens . "\n" ) as $i => $mau ) {
	confere( "ensaio: nome forjado {$i}", array( consulta_leve_htaccess_ensaio_pasta( $mau ), consulta_leve_htaccess_ensaio_limpar( $mau ) ), array( '', false ) );
}
confere( 'ensaio: nada a limpar', consulta_leve_htaccess_ensaio_limpar( '' ), true );
// Morto no ensaio: a carga seguinte limpa e a raiz nem mudou.
$por();
consulta_leve_htaccess_gravar_estado( array( 'estado' => 'pendente', 'arquivo' => ARQUIVO, 'ensaio' => $ens ) );
file_put_contents( ABSPATH . '.htaccess-consulta-leve-' . str_repeat( 'b', 16 ) . '.tmp', 'resto' );
confere( 'morto no ensaio: limpa na carga seguinte', array( consulta_leve_htaccess_recuperar(), sobras(), bytes(), consulta_leve_htaccess_estado()['ensaio'] ), array( true, array(), $wp, '' ) );
// Sobra que não sai: recusa, guarda o nome e tenta de novo na aba.
cenario( $wp, array( 'antes_de' => static function ( $n ) { 4 === $n && file_put_contents( glob( ABSPATH . 'wp-content/uploads/consulta-leve-ensaio-*' )[0] . '/foto.jpg', 'de outro' ); } ) ); // phpcs:ignore
confere( 'sobra no ensaio: recusa sem tocar a raiz', array( consulta_leve_htaccess_aplicar( 'ligar' ), bytes(), $GLOBALS['t']['raiz_com_bloco'] ), array( 'ensaio_sobra', $wp, false ) );
confere( 'sobra no ensaio: nome guardado', '' !== consulta_leve_htaccess_estado()['ensaio'], true );
unlink( ABSPATH . 'wp-content/uploads/' . consulta_leve_htaccess_estado()['ensaio'] . '/foto.jpg' );
consulta_leve_htaccess_revisar( true );
confere( 'sobra no ensaio: sai depois', array( consulta_leve_htaccess_estado()['ensaio'], sobras(), situacao()[0] ), array( '', array(), 'recusado' ) );

/* Processo morto no meio ------------------------------------------------------------------------ */

$morto = static function ( ?string $original, string $novo, bool $existia = true ) {
	cenario( $novo );
	consulta_leve_htaccess_guardar_copia( ARQUIVO, (string) $original );
	consulta_leve_htaccess_gravar_estado( array( 'estado' => 'pendente', 'arquivo' => ARQUIVO, 'existia' => $existia, 'cobertos' => array( 'X-Frame-Options' ), 'token' => str_repeat( 'a', 32 ), 'sha_novo' => hash( 'sha256', $novo ) ) );
};
$morto( $wp, $com( $wp )['conteudo'] );
confere( 'pendente: PHP manda tudo', count( consulta_leve_cabecalhos_enviar() ), 5 );
consulta_leve_travar( 'htaccess', 60 );
confere( 'pendente com trava viva: espera', array( consulta_leve_htaccess_recuperar(), situacao()[0] ), array( false, 'pendente' ) );
unset( $GLOBALS['cl_t']['opcoes'][ consulta_leve_trava_opcao( 'htaccess' ) ] );
confere( 'pendente: desfaz na carga seguinte', array( consulta_leve_htaccess_recuperar(), bytes(), situacao() ), array( true, $wp, array( 'recusado', 'interrompido', false ) ) );
confere( 'pendente: só uma vez', consulta_leve_htaccess_recuperar(), false );
$morto( null, $esperado, false );
confere( 'pendente que criou o arquivo: remove', array( consulta_leve_htaccess_recuperar(), bytes() ), array( true, null ) );
$morto( $wp, $com( $wp )['conteudo'] );
file_put_contents( ARQUIVO, $alheio, FILE_APPEND );
confere( 'pendente e mudado por outro: só o nosso bloco', array( consulta_leve_htaccess_recuperar(), bytes() ), array( true, $wp . $alheio ) );
$morto( $wp, $com( $wp )['conteudo'] );
$GLOBALS['cl_t']['opcoes'][ CONSULTA_LEVE_OPCAO_HTACCESS_COPIA ]['hex'] = bin2hex( 'estragada' );
confere( 'cópia estragada nunca é usada', array( consulta_leve_htaccess_copia( ARQUIVO ), consulta_leve_htaccess_recuperar(), bytes() ), array( null, true, $wp ) );
$morto( $wp, $com( $wp )['conteudo'] );
file_put_contents( ARQUIVO, $ini . bytes() );
confere( 'não dá para desfazer: continua pendente', array( consulta_leve_htaccess_recuperar(), situacao() ), array( false, array( 'pendente', 'volta_falhou', true ) ) );
$GLOBALS['t']['mods'] = false;
file_put_contents( ARQUIVO, $com( $wp )['conteudo'] );
confere( 'tenta de novo, mesmo com DISALLOW_FILE_MODS', array( consulta_leve_htaccess_recuperar(), bytes() ), array( true, $wp ) );
$morto( $wp, $com( $wp )['conteudo'] );
$GLOBALS['cl_t']['opcoes'][ CONSULTA_LEVE_OPCAO_HTACCESS ]['arquivo'] = __DIR__ . '/.htaccess';
confere( 'caminho forjado na opção: não toca', array( consulta_leve_htaccess_recuperar(), file_exists( __DIR__ . '/.htaccess' ) ), array( false, false ) );
$morto( $wp, $com( $wp )['conteudo'] );
foreach ( $GLOBALS['cl_t']['ganchos']['admin_init'][10] as $g ) {
	call_user_func( $g[0] );
}
confere( 'gancho admin_init desfaz', bytes(), $wp );

/* Mudar escolhas, desativar, revisar ------------------------------------------------------------- */

cenario( $wp );
confere( 'sem bloco ativo: nada a sincronizar', array( consulta_leve_htaccess_sincronizar(), $GLOBALS['t']['pedidos'] ), array( '', 0 ) );
consulta_leve_htaccess_aplicar( 'ligar' );
confere( 'mesmas escolhas: nada a fazer', consulta_leve_htaccess_sincronizar(), '' );
consulta_leve_config_gravar( array( 'cab_hsts' => 'nao', 'cab_frame' => false ) );
confere( 'muda escolhas: reescreve e confere', array( consulta_leve_htaccess_sincronizar(), bytes(), situacao()[0] ), array( 'ok', $com( $wp, consulta_leve_config() )['conteudo'], 'ativo' ) );
confere( 'HSTS saiu do arquivo', strpos( bytes(), 'Strict-Transport' ), false );
confere( 'sem XFO: sem pedido de quadro', $GLOBALS['t']['pedidos'], 10 + 9 );
$GLOBALS['t']['servidor'] = 'erro';
consulta_leve_config_gravar( array( 'cab_hsts' => 'ano' ) );
confere( 'muda e falha: bloco antigo também sai', array( consulta_leve_htaccess_sincronizar(), bytes(), situacao()[0] ), array( 'erro_5xx', $wp, 'recusado' ) );
confere( 'muda e falha: PHP manda', isset( consulta_leve_cabecalhos_enviar()['Strict-Transport-Security'] ), true );
cenario( $wp );
consulta_leve_htaccess_aplicar( 'ligar' );
consulta_leve_config_gravar( array( 'cab_hsts' => 'nao', 'cab_frame' => false, 'cab_nosniff' => false, 'cab_referrer' => false, 'cab_upgrade' => false ) );
confere( 'tudo desmarcado: bloco sai', array( consulta_leve_htaccess_sincronizar(), bytes(), situacao() ), array( 'ok', $wp, array( 'desligado', '', false ) ) );

cenario( $outros );
consulta_leve_htaccess_aplicar( 'ligar' );
call_user_func( $GLOBALS['t_desativar'] );
confere( 'desativar tira só o nosso', array( bytes(), situacao()[0], consulta_leve_registro()[0]['alvo'] ), array( $outros, 'desligado', 'desativar' ) );
cenario( $wp );
call_user_func( $GLOBALS['t_desativar'] );
confere( 'desativar sem bloco: nada', bytes(), $wp );
// Desativar sem conseguir tirar: registra e avisa.
cenario( $wp );
consulta_leve_htaccess_aplicar( 'ligar' );
consulta_leve_travar( 'htaccess', 60 );
confere( 'desativar com trava alheia: insiste, desiste e registra', array( consulta_leve_htaccess_sair( 'desativar', 1 ), consulta_leve_registro()[0]['resultado'], bytes() ), array( 'ocupado', 'ocupado', $com( $wp )['conteudo'] ) );
unset( $GLOBALS['cl_t']['opcoes'][ consulta_leve_trava_opcao( 'htaccess' ) ] );
confere( 'desinstalar depois: tira', array( consulta_leve_htaccess_sair( 'desinstalar', 1 ), bytes(), consulta_leve_htaccess_sair( 'desinstalar', 1 ) ), array( 'ok', $wp, 'ok' ) );
cenario( $wp );
consulta_leve_htaccess_aplicar( 'ligar' );
$GLOBALS['t']['mods']     = false;
$GLOBALS['cl_t']['admin'] = true;
call_user_func( $GLOBALS['t_desativar'] );
confere( 'aviso só na volta à tela Plugins', apply_filters( 'wp_redirect', 'https://exemplo.test/' ), 'https://exemplo.test/' );
$aviso = '';
try {
	apply_filters( 'wp_redirect', 'plugins.php?deactivate=true' );
} catch ( \RuntimeException $e ) {
	$aviso = $e->getMessage();
}
confere( 'aviso diz o motivo e como tirar', array( false !== strpos( $aviso, 'DISALLOW_FILE_MODS' ), false !== strpos( $aviso, '# BEGIN Consulta Leve até # END Consulta Leve' ), consulta_leve_registro()[0]['resultado'] ), array( true, true, 'bloqueado' ) );
unset( $GLOBALS['cl_t']['ganchos']['wp_redirect'] );
cenario( $outros );
consulta_leve_htaccess_aplicar( 'ligar' );
define( 'WP_UNINSTALL_PLUGIN', 'consulta-leve/consulta-leve.php' );
require __DIR__ . '/../../consulta-leve/uninstall.php';
confere( 'uninstall.php: só o bloco sai, opções ficam', array( bytes(), isset( $GLOBALS['cl_t']['opcoes'][ CONSULTA_LEVE_OPCAO_CONFIG ] ) ), array( $outros, true ) );
cenario( $wp );
consulta_leve_htaccess_aplicar( 'ligar' );
$GLOBALS['t']['mods'] = false;
confere( 'DISALLOW_FILE_MODS depois: não tira e diz', array( consulta_leve_htaccess_remover( 'desligar' ), bytes(), situacao() ), array( 'bloqueado', $com( $wp )['conteudo'], array( 'ativo', 'bloqueado', true ) ) );
cenario( $wp );
consulta_leve_htaccess_aplicar( 'ligar' );
file_put_contents( ARQUIVO, str_replace( $fim, $fim . $fim, bytes() ) );
$agora = bytes();
confere( 'marcador quebrado à mão: não tira e diz', array( consulta_leve_htaccess_remover( 'desligar' ), bytes(), situacao()[0] ), array( 'marcadores', $agora, 'ativo' ) );

cenario( $wp );
consulta_leve_htaccess_aplicar( 'ligar' );
consulta_leve_htaccess_revisar( true );
confere( 'revisão: continua ativo', situacao(), array( 'ativo', '', true ) );
file_put_contents( ARQUIVO, $wp );
consulta_leve_htaccess_revisar();
confere( 'revisão espera o intervalo', situacao()[0], 'ativo' );
consulta_leve_htaccess_revisar( true );
confere( 'bloco sumiu: PHP volta', array( situacao(), count( consulta_leve_cabecalhos_enviar() ) ), array( array( 'recusado', 'bloco_sumiu', false ), 5 ) );
cenario( $wp );
consulta_leve_htaccess_aplicar( 'ligar' );
$_SERVER['SERVER_SOFTWARE'] = 'nginx/1.25';
confere( 'mudou para nginx: PHP volta na hora', count( consulta_leve_cabecalhos_enviar() ), 5 );
consulta_leve_htaccess_revisar( true );
confere( 'mudou para nginx: estado', situacao(), array( 'recusado', 'servidor', false ) );

/* Envio e token --------------------------------------------------------------------------------- */

$lista  = consulta_leve_cabecalhos_lista( $tudo, true, array() );
$nomes  = array_keys( $lista );
$ativo  = array( 'estado' => 'ativo', 'cobertos' => $nomes );
$parte  = array( 'estado' => 'ativo', 'cobertos' => array( 'Referrer-Policy' ) );
confere( 'ativo: servidor manda', consulta_leve_htaccess_envio( $lista, $ativo, '', false, true ), array() );
confere( 'ativo em parte: PHP manda o resto', count( consulta_leve_htaccess_envio( $lista, $parte, '', false, true ) ), 4 );
confere( 'ativo em outro servidor: PHP manda', consulta_leve_htaccess_envio( $lista, $ativo, '', false, false ), $lista );
confere( 'recusado: PHP manda', consulta_leve_htaccess_envio( $lista, array( 'estado' => 'recusado', 'cobertos' => $nomes ), '', false, true ), $lista );
confere( 'em quadro: marca para o servidor', consulta_leve_htaccess_envio( array(), $ativo, '', true, true ), array( 'X-Consulta-Leve-Quadro' => '1' ) );
confere( 'em quadro sem XFO no bloco: sem marca', consulta_leve_htaccess_envio( array(), $parte, '', true, true ), array() );
$token = str_repeat( 'b', 32 );
$pend  = array( 'estado' => 'pendente', 'token' => $token );
confere( 'token certo', consulta_leve_htaccess_modo( 'sonda:' . $token, $pend ), 'sonda' );
foreach ( array( 'sonda:' . str_repeat( 'c', 32 ), 'sonda:', 'outro:' . $token, "sonda:{$token}\n", $token, array( 'sonda' ), null ) as $i => $mau ) {
	confere( "token errado {$i}", consulta_leve_htaccess_modo( $mau, $pend ), '' );
}
confere( 'token fora da conferência', consulta_leve_htaccess_modo( 'sonda:' . $token, array( 'estado' => 'ativo', 'token' => $token ) ), '' );
confere( 'token vazio', consulta_leve_htaccess_modo( 'sonda:', array( 'estado' => 'pendente', 'token' => '' ) ), '' );

/* Conferência, regra a regra ------------------------------------------------------------------------ */

$resp = static function ( array $cab, int $status = 200 ) {
	return array( 'status' => $status, 'cab' => $cab );
};
$xfo  = array( 'X-Frame-Options' => 'SAMEORIGIN' );
$conf = static function ( string $tipo, array $antes, array $depois, ?array $esp = null ) use ( $xfo ) { // phpcs:ignore
	return consulta_leve_htaccess_conferir_resposta( $tipo, $antes, $depois, null === $esp ? $xfo : $esp, array( 'X-Frame-Options' ) );
};
$eco = static function ( string $m, array $cab = array() ) {
	return array( 'x-consulta-leve-conferencia' => array( $m ) ) + $cab;
};
confere( 'estático certo', $conf( 'estatico', $resp( array() ), $resp( array( 'x-frame-options' => array( 'SAMEORIGIN' ) ) ) ), '' );
confere( 'estático ausente', $conf( 'estatico', $resp( array() ), $resp( array() ) ), 'ausente' );
confere( 'estático repetido', $conf( 'estatico', $resp( array() ), $resp( array( 'x-frame-options' => array( 'SAMEORIGIN', 'SAMEORIGIN' ) ) ) ), 'duplicado' );
confere( 'repetido na mesma linha', $conf( 'estatico', $resp( array() ), $resp( array( 'x-frame-options' => array( 'SAMEORIGIN, SAMEORIGIN' ) ) ) ), 'trocado' );
confere( 'sem resposta', $conf( 'estatico', $resp( array() ), $resp( array(), 0 ) ), 'sem_resposta' );
confere( '502', $conf( 'estatico', $resp( array(), 502 ), $resp( array(), 502 ) ), 'erro_5xx' );
confere( 'sem eco', $conf( 'silencio', $resp( array() ), $resp( array( 'x-frame-options' => array( 'SAMEORIGIN' ) ) ) ), 'sem_eco' );
confere( 'eco de outro modo', $conf( 'sonda', $resp( array() ), $resp( $eco( 'silencio' ) ) ), 'sem_eco' );
confere( 'sonda certa', $conf( 'sonda', $resp( array() ), $resp( $eco( 'sonda', array( 'x-frame-options' => array( CONSULTA_LEVE_HTACCESS_SONDA ) ) ) ) ), '' );
confere( 'sonda trocada', $conf( 'sonda', $resp( array() ), $resp( $eco( 'sonda', array( 'x-frame-options' => array( 'SAMEORIGIN' ) ) ) ) ), 'trocado' );
confere( 'sonda repetida', $conf( 'sonda', $resp( array() ), $resp( $eco( 'sonda', array( 'x-frame-options' => array( CONSULTA_LEVE_HTACCESS_SONDA, 'SAMEORIGIN' ) ) ) ) ), 'duplicado' );
confere( 'quadro certo', $conf( 'quadro', $resp( array() ), $resp( $eco( 'quadro' ) ) ), '' );
confere( 'quadro com o nosso', $conf( 'quadro', $resp( array() ), $resp( $eco( 'quadro', array( 'x-frame-options' => array( 'SAMEORIGIN' ) ) ) ) ), 'condicao' );
$dele = array( 'x-frame-options' => array( 'DENY' ) );
confere( 'de terceiros continua', $conf( 'silencio', $resp( $dele ), $resp( $eco( 'silencio', $dele ) ) ), '' );
confere( 'de terceiros com valor novo a cada pedido', $conf( 'silencio', $resp( array( 'x-frame-options' => array( 'a1' ) ) ), $resp( $eco( 'silencio', array( 'x-frame-options' => array( 'b2' ) ) ) ) ), '' );
confere( 'de terceiros virou o nosso', $conf( 'silencio', $resp( $dele ), $resp( $eco( 'silencio', array( 'x-frame-options' => array( 'SAMEORIGIN' ) ) ) ) ), 'trocado' );
confere( 'de terceiros e o nosso', $conf( 'silencio', $resp( $dele ), $resp( $eco( 'silencio', array( 'x-frame-options' => array( 'DENY', 'SAMEORIGIN' ) ) ) ) ), 'duplicado' );
confere( 'de terceiros sumiu', $conf( 'silencio', $resp( $dele ), $resp( $eco( 'silencio' ) ) ), 'ausente' );
confere( 'fora da condição', $conf( 'estatico', $resp( array() ), $resp( array( 'x-frame-options' => array( 'SAMEORIGIN' ) ) ), array() ), 'condicao' );

/* Painel ------------------------------------------------------------------------------------------ */

foreach ( array( 'htaccess_ligado', 'htaccess_desligado', 'htaccess_recusado' ) as $aviso ) {
	confere( "aviso {$aviso}", isset( consulta_leve_admin_avisos()[ $aviso ] ), true );
}
cenario( $wp );
confere( 'rótulo desligado', consulta_leve_htaccess_rotulo(), 'desligado' );
consulta_leve_htaccess_aplicar( 'ligar' );
confere( 'rótulo ativo', consulta_leve_htaccess_rotulo(), 'ativo e conferido' );
consulta_leve_htaccess_gravar_estado( array( 'estado' => 'recusado', 'motivo' => 'multisite' ) );
confere( 'rótulo com motivo', 0 === strpos( consulta_leve_htaccess_rotulo(), 'recusado: em multisite' ), true );
consulta_leve_htaccess_gravar_estado( array( 'motivo' => '<b>fora da lista</b>' ) );
confere( 'motivo fora da lista não vira texto', consulta_leve_htaccess_rotulo(), 'recusado' );
$GLOBALS['cl_t']['opcoes'][ CONSULTA_LEVE_OPCAO_HTACCESS ] = 'lixo';
confere( 'opção estragada: desligado', consulta_leve_htaccess_estado()['estado'], 'desligado' );
$geral = consulta_leve_config_do_formulario( array( 'cab_htaccess' => '1' ) );
confere( 'formulário geral não liga', isset( $geral['cab_htaccess'] ), false );
$proprio = consulta_leve_cabecalhos_do_formulario( array( 'cab_htaccess' => '1' ), array(), true );
confere( 'formulário dos cabeçalhos não liga', isset( $proprio['valores']['cab_htaccess'] ), false );

cl_teste_fim( 'htaccess' );
