<?php
/**
 * Testes da tabela de busca própria (contrato, seção 7).
 *
 * @package ConsultaLeve
 */

require __DIR__ . '/stubs.php';

function _prime_post_caches( $ids, $t = true, $m = true ) { // phpcs:ignore
}
function get_post( $id ) { // phpcs:ignore
	return $GLOBALS['cl_t_posts'][ $id ] ?? null;
}
function get_post_meta( $id, $k = '', $um = false ) { // phpcs:ignore
	$v = $GLOBALS['cl_t_meta'][ $id ][ $k ] ?? array();
	return $um ? ( $v[0] ?? '' ) : $v;
}
function dbDelta( $sql ) { // phpcs:ignore
	$GLOBALS['cl_t']['dbdelta'] = $sql;
	$GLOBALS['cl_t']['tabela_criada'] = true;
	return array();
}
function wp_is_post_revision( $id ) { // phpcs:ignore
	return false;
}
function wp_is_post_autosave( $id ) { // phpcs:ignore
	return false;
}
function get_post_types( $a = array() ) { // phpcs:ignore
	return array( 'post' => 'post', 'page' => 'page' );
}
function register_deactivation_hook( $f, $cb ) { // phpcs:ignore
}

function cl_post( $id, $tipo = 'post', $status = 'publish', $titulo = 'Título', $conteudo = '' ) {
	return (object) array(
		'ID'                => $id,
		'post_type'         => $tipo,
		'post_status'       => $status,
		'post_title'        => $titulo,
		'post_excerpt'      => '',
		'post_content'      => $conteudo,
		'post_date'         => '2026-11-0' . ( $id % 9 + 1 ) . ' 10:00:00',
		'post_modified_gmt' => '2026-11-15 10:00:00',
	);
}

add_filter(
	'consulta_leve_modulos',
	static function () {
		return array( 'nucleo/config.php', 'nucleo/coexistencia.php', 'nucleo/trava.php', 'nucleo/cache.php', 'nucleo/registro.php', 'consultas/primar.php', 'consultas/cursor.php', 'consultas/lotes.php', 'indices/indices.php', 'indices/busca.php' );
	}
);
require __DIR__ . '/../../consulta-leve/consulta-leve.php';

/* Termos e normalização ----------------------------------------------------- */

confere( 'normaliza acento, caixa e tags', consulta_leve_busca_normalizar( "<b>Coração</b>  DE\nMãe" ), 'coracao de mae' );
confere(
	'termos: longos no FULLTEXT, curtos e stopwords no LIKE',
	consulta_leve_busca_termos( 'Coração de mãe +x -y "the"' ),
	array( 'ft' => array( 'coracao', 'mae' ), 'like' => array( 'de', 'the' ) )
);
confere( 'operadores e curingas removidos', consulta_leve_busca_termos( "a*b%c_d'e" ), array( 'ft' => array(), 'like' => array() ) );
$t = consulta_leve_busca_termos( 'um dois tres quatro cinco seis sete oito nove dez' );
confere( 'no máximo 8 palavras', count( $t['ft'] ) + count( $t['like'] ), 8 );

/* Estados ------------------------------------------------------------------- */

confere( 'desligada por padrão', consulta_leve_busca_estado(), 'desligada' );
consulta_leve_config_gravar( array( 'indice_busca' => true ) );
confere( 'sem tipos continua desligada', consulta_leve_busca_estado(), 'desligada' );
consulta_leve_config_gravar( array( 'indice_tipos' => array( 'post', 'crm_demanda' ), 'indice_metas' => array( 'cliente' ) ) );
confere( 'sem tabela', consulta_leve_busca_estado(), 'sem_tabela' );

// CRM interno presente: crm_demanda já coberta.
function crmimpactei_busca_tabela() { // phpcs:ignore
	return 'wp_crm_busca';
}
confere( 'tipo coberto pelo tema pulado', consulta_leve_busca_tipos(), array( 'post' ) );

$GLOBALS['cl_t']['sql'] = array(
	"/SHOW TABLES LIKE 'wp\\\\\\\\_consulta\\\\\\\\_leve\\\\\\\\_busca'/" => 'wp_consulta_leve_busca',
	'/SHOW TABLES LIKE/'          => 'wp_consulta_leve_busca',
	'/SHOW INDEX FROM/'           => null,
	'/ADD FULLTEXT KEY cl_ft/'    => 0,
);
confere( 'instala', consulta_leve_busca_instalar(), true );
confere( 'dbDelta com dois espaços no PRIMARY KEY', false !== strpos( $GLOBALS['cl_t']['dbdelta'], 'PRIMARY KEY  (post_id)' ), true );
confere( 'FULLTEXT ligado', get_option( 'consulta_leve_busca_fulltext' ), '1' );
confere( 'construindo antes da primeira carga', consulta_leve_busca_estado(), 'construindo' );

/* Gravação ------------------------------------------------------------------ */

$GLOBALS['cl_t_posts'] = array(
	1 => cl_post( 1, 'post', 'publish', 'Coração de mãe', '[galeria] Texto <i>longo</i>' ),
	2 => cl_post( 2, 'page' ),
	3 => cl_post( 3, 'post', 'auto-draft' ),
	4 => cl_post( 4, 'post', 'draft', 'Rascunho' ),
);
$GLOBALS['cl_t_meta'] = array( 1 => array( 'cliente' => array( 'Loja Azul', array( 'array', 'ignorado' ) ) ) );

$gravou = consulta_leve_busca_gravar( array( 1, 2, 3, 4, 99 ) );
$linhas = $GLOBALS['cl_t']['linhas']['wp_consulta_leve_busca'] ?? array();
confere( 'grava só tipo e status certos', $gravou, 2 );
confere( 'linha sem shortcode, com meta escalar', $linhas[1]['texto'], 'Texto longo Loja Azul' );
confere( 'texto normalizado inclui o título', $linhas[1]['texto_norm'], 'coracao de mae texto longo loja azul' );
confere( 'modificado em GMT', $linhas[1]['modificado'], '2026-11-15 10:00:00' );
$sql = implode( "\n", $GLOBALS['cl_t']['consultas'] );
confere( 'fora dos tipos sai da tabela', false !== strpos( $sql, 'DELETE wp_consulta_leve_busca {"post_id":2}' ), true );
confere( 'post apagado sai da tabela', false !== strpos( $sql, 'DELETE wp_consulta_leve_busca {"post_id":99}' ), true );
confere( 'gravar invalida o cache da busca', isset( get_option( 'consulta_leve_cache_versoes' )['busca'] ), true );

/* Fila ---------------------------------------------------------------------- */

$GLOBALS['cl_t']['linhas'] = array();
do_action( 'save_post', 4, $GLOBALS['cl_t_posts'][4], true );
do_action( 'updated_post_meta', 7, 1, 'cliente', 'x' );
do_action( 'updated_post_meta', 8, 2, 'outra', 'x' );
confere( 'fila com o post salvo e a meta indexada', array_keys( $GLOBALS['consulta_leve_busca_fila'] ), array( 4, 1 ) );
do_action( 'shutdown' );
$gravadas = array_keys( $GLOBALS['cl_t']['linhas']['wp_consulta_leve_busca'] );
sort( $gravadas );
confere( 'fila gravada no shutdown', $gravadas, array( 1, 4 ) );

/* Reconstrução -------------------------------------------------------------- */

$GLOBALS['cl_t']['sql']['/SELECT ID FROM wp_posts WHERE post_type IN/'] = static function ( $sql ) {
	preg_match( '/ID > (\d+)/', $sql, $m );
	return (int) $m[1] < 4 ? array( 1, 3, 4 ) : array();
};
$geracao = consulta_leve_busca_reconstruir_iniciar();
confere( 'lote agendado', end( $GLOBALS['cl_t']['agendados'] ), array( CONSULTA_LEVE_BUSCA_GANCHO_LOTE, array( $geracao, 0 ) ) );
confere( 'geração velha ignorada', consulta_leve_busca_lote( $geracao - 1, 0 )['cursor'], 0 );
$estado = consulta_leve_busca_lote( $geracao, 0 );
confere( 'reconstrução termina', $estado['geracao'], 0 );
confere( 'pronta depois da carga', consulta_leve_busca_estado(), 'pronta' );
$sql = implode( "\n", $GLOBALS['cl_t']['consultas'] );
confere( 'órfãs removidas da tabela', false !== strpos( $sql, 'DELETE FROM wp_consulta_leve_busca WHERE post_id NOT IN (SELECT ID FROM wp_posts)' ), true );
confere( 'tipos fora removidos', false !== strpos( $sql, "DELETE FROM wp_consulta_leve_busca WHERE post_type NOT IN ('post')" ), true );

/* Consulta ------------------------------------------------------------------ */

$GLOBALS['cl_t']['sql']['/SELECT post_id, post_date FROM wp_consulta_leve_busca/'] = static function ( $sql ) {
	$GLOBALS['cl_t']['ultima_busca'] = $sql;
	return array(
		array( 'post_id' => '9', 'post_date' => '2026-11-09 10:00:00' ),
		array( 'post_id' => '8', 'post_date' => '2026-11-08 10:00:00' ),
		array( 'post_id' => '7', 'post_date' => '2026-11-07 10:00:00' ),
	);
};
$r = consulta_leve_busca_ids( 'Coração de mãe', array( 'por_pagina' => 2 ) );
confere( 'ids da página', $r['ids'], array( 9, 8 ) );
confere( 'via FULLTEXT', $r['via'], 'fulltext' );
confere( 'próximo cursor', consulta_leve_cursor_decodificar( $r['proximo'], 'data' ), array( 'o' => 'data', 'i' => 8, 'd' => '2026-11-08 10:00:00' ) );
$sql = $GLOBALS['cl_t']['ultima_busca'];
confere( 'MATCH em modo booleano', false !== strpos( $sql, "MATCH(titulo,texto) AGAINST ('+coracao* +mae*' IN BOOLEAN MODE)" ), true );
confere( 'LIKE nas palavras curtas', false !== strpos( $sql, "texto_norm LIKE '%de%'" ), true );
confere( 'só publicados por padrão', false !== strpos( $sql, "post_status IN ('publish')" ), true );
confere( 'pede um a mais', false !== strpos( $sql, 'LIMIT 3' ), true );

unset( $GLOBALS['cl_t']['ultima_busca'] );
consulta_leve_busca_ids( 'Coração de mãe', array( 'por_pagina' => 2 ) );
confere( 'repetida vem do cache', isset( $GLOBALS['cl_t']['ultima_busca'] ), false );

update_option( 'consulta_leve_busca_fulltext', '0' );
$r = consulta_leve_busca_ids( 'Coração', array( 'status' => array( 'publish', 'trash' ) ) );
confere( 'sem FULLTEXT vai para o LIKE', $r['via'], 'like' );
confere( 'status fora da lista ignorado', false !== strpos( $GLOBALS['cl_t']['ultima_busca'], "post_status IN ('publish')" ), true );
confere( 'termo vazio não consulta', consulta_leve_busca_ids( '+ - *' )['via'], 'nenhuma' );
confere( 'tipo não indexado não consulta', consulta_leve_busca_ids( 'coração', array( 'tipos' => array( 'page' ) ) )['via'], 'nenhuma' );

/* Busca do núcleo ----------------------------------------------------------- */

class CL_Q { // phpcs:ignore
	public $v;
	public function __construct( $v ) {
		$this->v = $v;
	}
	public function get( $k ) {
		return $this->v[ $k ] ?? '';
	}
}
$q = new CL_Q( array( 's' => 'coração', 'post_type' => 'post' ) );
confere( 'desligada: SQL do núcleo intocado', consulta_leve_busca_no_nucleo( ' AND (x LIKE y)', $q ), ' AND (x LIKE y)' );
consulta_leve_config_gravar( array( 'indice_na_busca' => true ) );
confere( 'ligada: lê a tabela', consulta_leve_busca_no_nucleo( ' AND (x LIKE y)', $q ), ' AND wp_posts.ID IN (9,8,7) ' );
confere( 'tipos não indexados: núcleo', consulta_leve_busca_no_nucleo( ' X', new CL_Q( array( 's' => 'a', 'post_type' => 'any' ) ) ), ' X' );
$GLOBALS['cl_t']['usuario'] = 3;
confere( 'logado: núcleo', consulta_leve_busca_no_nucleo( ' X', $q ), ' X' );

cl_teste_fim( 'busca-indice' );
