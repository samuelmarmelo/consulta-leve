<?php
/**
 * Testes dos índices (contrato, seção 11) e do registro.
 *
 * @package ConsultaLeve
 */

require __DIR__ . '/stubs.php';

add_filter(
	'consulta_leve_modulos',
	static function () {
		return array( 'nucleo/config.php', 'nucleo/coexistencia.php', 'nucleo/trava.php', 'nucleo/cache.php', 'nucleo/registro.php', 'indices/indices.php' );
	}
);
require __DIR__ . '/../../consulta-leve/consulta-leve.php';

$c = consulta_leve_indices_candidatos();

/* Regra de estado ----------------------------------------------------------- */

$nucleo_meta = array(
	'PRIMARY'  => array( array( 'meta_id', 0 ) ),
	'post_id'  => array( array( 'post_id', 0 ) ),
	'meta_key' => array( array( 'meta_key', 191 ) ),
);
confere( 'núcleo puro: ausente', consulta_leve_indices_regra_estado( $c['meta_post_chave'], $nucleo_meta ), 'ausente' );
confere( 'só a primeira coluna não cobre', consulta_leve_indices_regra_estado( $c['meta_chave_valor'], $nucleo_meta ), 'ausente' );

// Outro plugin criou PRIMARY (post_id, meta_key, meta_id): já cobre.
$outro = $nucleo_meta + array( 'pk_alt' => array( array( 'post_id', 0 ), array( 'META_KEY', 32 ), array( 'meta_id', 0 ) ) );
confere( 'índice de outro nome cobre', consulta_leve_indices_regra_estado( $c['meta_post_chave'], $outro ), 'coberto' );
confere( 'índice próprio: criado', consulta_leve_indices_regra_estado( $c['meta_post_chave'], array( 'cl_meta_post_chave' => array() ) ), 'criado' );

/* SQL ----------------------------------------------------------------------- */

confere( 'SQL online com prefixo', consulta_leve_indices_sql_criar( $c['meta_chave_valor'], 'wp_postmeta', true ), 'ALTER TABLE `wp_postmeta` ADD INDEX `cl_meta_chave_valor` (`meta_key`(32), `meta_value`(32)), ALGORITHM=INPLACE, LOCK=NONE' );
confere( 'SQL comum', consulta_leve_indices_sql_criar( $c['posts_autor_tipo'], 'wp_posts', false ), 'ALTER TABLE `wp_posts` ADD INDEX `cl_posts_autor_tipo` (`post_author`, `post_type`, `post_status`, `post_date`)' );
foreach ( $c as $id => $cand ) {
	confere( "nome com prefixo cl_: {$id}", 0 === strpos( $cand['nome'], 'cl_' ), true );
}
confere( 'tabela fora da lista', consulta_leve_indices_tabela( 'users' ), '' );

/* Criar e remover ----------------------------------------------------------- */

$GLOBALS['cl_t_indices'] = array(
	array( 'INDEX_NAME' => 'PRIMARY', 'COLUMN_NAME' => 'meta_id', 'SUB_PART' => null ),
	array( 'INDEX_NAME' => 'post_id', 'COLUMN_NAME' => 'post_id', 'SUB_PART' => null ),
);
$GLOBALS['cl_t']['sql'] = array(
	'/INFORMATION_SCHEMA.STATISTICS/' => static function () {
		return $GLOBALS['cl_t_indices'];
	},
	'/INFORMATION_SCHEMA.TABLES/'     => static function () {
		return (string) ( $GLOBALS['cl_t_linhas'] ?? 5000 );
	},
	'/ALGORITHM=INPLACE/'             => static function () {
		$GLOBALS['wpdb']->last_error = $GLOBALS['cl_t_erro_online'] ?? 'ALGORITHM=INPLACE is not supported for this operation';
		return false; // Banco recusa o modo online.
	},
	'/ADD INDEX `cl_meta_post_chave`/' => static function () {
		$GLOBALS['cl_t_indices'][] = array( 'INDEX_NAME' => 'cl_meta_post_chave', 'COLUMN_NAME' => 'post_id', 'SUB_PART' => null );
		return 0;
	},
	'/DROP INDEX `cl_meta_post_chave`/' => 0,
);

confere( 'desconhecido recusado', consulta_leve_indices_criar( 'nada' ), 'desconhecido' );
confere( 'cria com reserva sem modo online', consulta_leve_indices_criar( 'meta_post_chave' ), 'ok' );
confere( 'criar de novo: já criado', consulta_leve_indices_criar( 'meta_post_chave' ), 'criado' );
$reg = consulta_leve_registro();
confere( 'registro guarda a ação', array( $reg[0]['acao'], $reg[0]['alvo'], $reg[0]['resultado'] ), array( 'indice_criar', 'meta_post_chave', 'ok' ) );

// Erro que não é das cláusulas online (tempo de trava): para, sem DDL bloqueante.
$GLOBALS['cl_t_erro_online'] = 'Lock wait timeout exceeded';
$antes                       = count( $GLOBALS['cl_t']['consultas'] );
confere( 'tempo de trava não repete sem modo online', consulta_leve_indices_criar( 'posts_autor_tipo' ), 'banco' );
$depois = array_slice( $GLOBALS['cl_t']['consultas'], $antes );
confere( 'nenhum ALTER sem ALGORITHM depois do erro', count( preg_grep( '/ADD INDEX `cl_posts_autor_tipo` \([^)]*\)$/', $depois ) ), 0 );
unset( $GLOBALS['cl_t_erro_online'] );

$GLOBALS['cl_t_linhas'] = 2000000;
confere( 'tabela grande pede confirmação', consulta_leve_indices_criar( 'meta_chave_valor' ), 'confirmar' );
$GLOBALS['cl_t_linhas'] = 5000;

// Trava ocupada.
$dono = consulta_leve_travar( 'indices', 60 );
confere( 'trava ocupada', consulta_leve_indices_remover( 'meta_post_chave' ), 'ocupado' );
consulta_leve_liberar( 'indices', $dono );
confere( 'remove o próprio', consulta_leve_indices_remover( 'meta_post_chave' ), 'ok' );
confere( 'remover ausente recusado', consulta_leve_indices_remover( 'posts_autor_tipo' ), 'ausente' );

$sql = implode( "\n", $GLOBALS['cl_t']['consultas'] );
confere( 'nunca remove índice alheio', 1 === preg_match( '/DROP INDEX `(?!cl_)/', $sql ), false );
confere( 'STATISTICS com prepare e igualdade', false !== strpos( $sql, "TABLE_NAME = 'wp_postmeta'" ), true );

/* SQLite -------------------------------------------------------------------- */

define( 'DB_ENGINE', 'sqlite' );
confere( 'SQLite sem suporte', consulta_leve_indices_situacao()['meta_post_chave']['estado'], 'sem_suporte' );
confere( 'SQLite não cria', consulta_leve_indices_criar( 'meta_post_chave' ), 'sem_suporte' );
confere( 'SQLite sem EXPLAIN', consulta_leve_indices_explain( 'meta_post_chave' ), array() );

/* Registro ------------------------------------------------------------------ */

for ( $i = 0; $i < 60; $i++ ) {
	consulta_leve_registrar( 'teste', 'x' . $i, 'ok' );
}
confere( 'registro guarda 50', count( consulta_leve_registro() ), 50 );
consulta_leve_registrar( 'a<b>', "alvo'; DROP", 'ok<script>' );
$r = consulta_leve_registro()[0];
confere( 'registro saneado', array( $r['acao'], $r['alvo'], $r['resultado'] ), array( 'ab', 'alvoDROP', 'okscript' ) );

cl_teste_fim( 'indices' );
