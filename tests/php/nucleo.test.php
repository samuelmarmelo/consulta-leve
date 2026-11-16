<?php
/**
 * Testes do núcleo: carregador, configuração, coexistência, trava e cache.
 * Contrato: docs/CONTRATO.md, seções 3, 4 e 6.
 *
 * @package ConsultaLeve
 */

require __DIR__ . '/stubs.php';

// Só o núcleo: os outros módulos têm testes próprios.
add_filter(
	'consulta_leve_modulos',
	static function () {
		return array( 'nucleo/config.php', 'nucleo/coexistencia.php', 'nucleo/trava.php', 'nucleo/cache.php', '../fora.php', 'nao/existe.php' );
	}
);
require __DIR__ . '/../../consulta-leve/consulta-leve.php';

/* Carregador ---------------------------------------------------------------- */

confere( 'versão definida', defined( 'CONSULTA_LEVE_VERSAO' ), true );
confere( 'carregado avisado', did_action( 'consulta_leve_carregado' ), 1 );
confere( 'caminho com .. recusado', consulta_leve_carregar_arquivo( '../fora.php' ), false );
confere( 'arquivo ausente pulado', consulta_leve_carregar_arquivo( 'nao/existe.php' ), false );

// Segunda cópia: a guarda devolve antes de redeclarar.
$retorno = include __DIR__ . '/../../consulta-leve/consulta-leve.php';
confere( 'segunda cópia não carrega', $retorno, null );
confere( 'carregado uma vez só', did_action( 'consulta_leve_carregado' ), 1 );

/* Configuração -------------------------------------------------------------- */

$c = consulta_leve_config();
confere( 'padrão seguro: rest desligada', $c['rest'], false );
confere( 'padrão seguro: índice desligado', $c['indice_busca'], false );
confere( 'padrão: argumentos ligados', $c['argumentos'], true );

$limpa = consulta_leve_config_sanitizar(
	array(
		'teto'          => 999999,
		'busca_min'     => 'x',
		'busca_colunas' => 'tudo',
		'indice_tipos'  => 'post, page, <script>, post',
		'rest'          => '1',
		'estranha'      => 'valor',
	)
);
confere( 'teto preso ao máximo', $limpa['teto'], 5000 );
confere( 'número inválido volta ao padrão', $limpa['busca_min'], 2 );
confere( 'escolha fora da lista volta ao padrão', $limpa['busca_colunas'], 'nucleo' );
confere( 'lista saneada e sem repetição', $limpa['indice_tipos'], array( 'post', 'page' ) );
confere( 'bool de texto', $limpa['rest'], true );
confere( 'chave desconhecida descartada', array_key_exists( 'estranha', $limpa ), false );

$gravada = consulta_leve_config_gravar( array( 'teto' => 300 ) );
confere( 'gravar mantém o resto', $gravada['busca_min'], 2 );
confere( 'memo refeito ao gravar', consulta_leve_opcao( 'teto' ), 300 );
confere( 'chave desconhecida é null', consulta_leve_opcao( 'nada' ), null );

/* Coexistência -------------------------------------------------------------- */

$GLOBALS['cl_t']['suporte']['consulta-leve'] = array( array( 'dispensar' => array( 'busca', 'inventado' ) ) );
confere( 'tema dispensa busca', consulta_leve_dispensado( 'busca' ), true );
confere( 'método fora da lista ignorado', in_array( 'inventado', consulta_leve_dispensa_do_tema(), true ), false );
confere( 'busca dispensada não fica ativa', consulta_leve_ativo( 'busca' ), false );
confere( 'argumentos seguem ativos', consulta_leve_ativo( 'argumentos' ), true );
$GLOBALS['cl_t']['suporte'] = array();

define( 'QM_VERSION', '3.16' );
confere( 'Query Monitor dispensa o monitor', consulta_leve_dispensado( 'monitor' ), true );

add_filter(
	'consulta_leve_dispensar',
	static function ( $d, $m ) {
		return 'argumentos' === $m ? true : $d;
	},
	10,
	2
);
confere( 'filtro dispensa', consulta_leve_ativo( 'argumentos' ), false );
confere( 'sem CRM interno, nada coberto', consulta_leve_indice_tipos_cobertos(), array() );

/* Trava --------------------------------------------------------------------- */

$dono = consulta_leve_travar( 'teste', 60 );
confere( 'trava pega', 24 === strlen( $dono ), true );
confere( 'segunda tentativa recusada', consulta_leve_travar( 'teste', 60 ), '' );
confere( 'liberar com dono errado falha', consulta_leve_liberar( 'teste', 'outro' ), false );
confere( 'liberar com dono certo', consulta_leve_liberar( 'teste', $dono ), true );
confere( 'trava livre de novo', '' !== consulta_leve_travar( 'teste', 60 ), true );

// Trava vencida é retomada.
$GLOBALS['cl_t']['opcoes']['consulta_leve_trava_velha'] = ( time() - 5 ) . '|antigo';
confere( 'trava vencida retomada', '' !== consulta_leve_travar( 'velha', 60 ), true );

/* Cache --------------------------------------------------------------------- */

$contas = 0;
$calc   = static function () use ( &$contas ) {
	++$contas;
	return array( 1, 2, 3 );
};

confere( 'calcula na primeira', consulta_leve_lembrar( 'lista', 'a', $calc ), array( 1, 2, 3 ) );
consulta_leve_lembrar( 'lista', 'a', $calc );
confere( 'memo evita recalcular', $contas, 1 );

// Requisição nova (memo zerado): vem do transient.
$memo = &consulta_leve_cache_memo();
$memo = array();
consulta_leve_lembrar( 'lista', 'a', $calc );
confere( 'transient evita recalcular', $contas, 1 );

consulta_leve_cache_tocar( 'lista' );
consulta_leve_lembrar( 'lista', 'a', $calc );
confere( 'tocar o grupo invalida', $contas, 2 );

// Escopo usuário: uma pessoa não lê o cache da outra.
$memo                       = array();
$GLOBALS['cl_t']['usuario'] = 7;
$GLOBALS['cl_t']['papeis']  = array( 'editor' );
consulta_leve_lembrar(
	'privado',
	'x',
	static function () {
		return 'da pessoa 7';
	},
	array( 'escopo' => 'usuario' )
);
$GLOBALS['cl_t']['usuario'] = 8;
$valor                      = consulta_leve_lembrar(
	'privado',
	'x',
	static function () {
		return 'da pessoa 8';
	},
	array( 'escopo' => 'usuario' )
);
confere( 'escopo separa usuários', $valor, 'da pessoa 8' );

// Dependência posts sem cache externo: versão própria muda no shutdown.
$memo   = array();
$contas = 0;
consulta_leve_lembrar( 'dep', 'k', $calc, array( 'deps' => array( 'posts' ) ) );
do_action( 'clean_post_cache', 10 );
confere( 'versão só grava no shutdown', isset( $GLOBALS['cl_t']['opcoes']['consulta_leve_cv_dep_posts'] ), false );
do_action( 'shutdown' );
$memo = array();
consulta_leve_lembrar( 'dep', 'k', $calc, array( 'deps' => array( 'posts' ) ) );
confere( 'post alterado invalida dependentes', $contas, 2 );

// _edit_lock não invalida.
do_action( 'updated_post_meta', 1, 10, '_edit_lock', '1' );
confere( 'edit_lock não marca mudança', empty( $GLOBALS['consulta_leve_deps_mudadas'] ), true );

// Valor grande não vai para o transient sem cache externo.
$memo   = array();
$grande = str_repeat( 'x', 70000 );
consulta_leve_lembrar(
	'grande',
	'g',
	static function () use ( $grande ) {
		return $grande;
	}
);
$guardou = false;
foreach ( array_keys( $GLOBALS['cl_t']['transients'] ) as $k ) {
	if ( 0 === strpos( $k, 'consulta_leve_grande_' ) ) {
		$guardou = true;
	}
}
confere( 'valor grande fica fora do wp_options', $guardou, false );

// false não é guardado.
$memo  = array();
$falsos = 0;
for ( $i = 0; $i < 2; $i++ ) {
	$memo = array();
	consulta_leve_lembrar(
		'falso',
		'f',
		static function () use ( &$falsos ) {
			++$falsos;
			return false;
		}
	);
}
confere( 'false recalculado', $falsos, 2 );

// Com cache externo: vai para wp_cache, não para transient.
$GLOBALS['cl_t']['externo'] = true;
$memo                       = array();
consulta_leve_lembrar(
	'externo',
	'e',
	static function () {
		return 'ok';
	}
);
confere( 'cache externo usado', ! empty( $GLOBALS['cl_t']['cache']['consulta_leve'] ), true );
$GLOBALS['cl_t']['externo'] = false;

// Travar: sem a trava, serve a última cópia boa.
$memo = array();
consulta_leve_lembrar(
	'painel',
	'p',
	static function () {
		return 'cópia boa';
	},
	array( 'travar' => true )
);
consulta_leve_cache_tocar( 'painel' );
$memo  = array();
$nome  = consulta_leve_cache_nome( 'painel', 'p', array(), 'publico' );
$outro = consulta_leve_travar( 'cache_' . md5( $nome ), 30 );
$valor = consulta_leve_lembrar(
	'painel',
	'p',
	static function () {
		return 'recalculado';
	},
	array( 'travar' => true )
);
confere( 'correria serve última cópia', $valor, 'cópia boa' );
consulta_leve_liberar( 'cache_' . md5( $nome ), $outro );

cl_teste_fim( 'nucleo' );
