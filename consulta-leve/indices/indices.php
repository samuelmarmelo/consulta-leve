<?php
/**
 * Índices em tabelas do núcleo (contrato, seção 11).
 *
 * Nunca automáticos: lista fechada de candidatos, criados só por quem
 * administra (painel com nonce ou WP-CLI), depois de ver as linhas da tabela
 * e o EXPLAIN. Antes de criar, procura índice de qualquer nome que já comece
 * pelas mesmas colunas (tema ou outro plugin): achou, marca "coberto" e não
 * cria. Só remove índice com o prefixo `cl_` desta lista. Origem:
 * loja A `docs/METODOLOGIAS-SCALABILITY-PRO.md` (7.6) e
 * CRM interno `docs/FASE-2-50` (5.6).
 *
 * @package ConsultaLeve
 */

defined( 'ABSPATH' ) || exit;

/** Tabela com mais linhas que isto pede confirmação extra para criar índice. */
const CONSULTA_LEVE_INDICES_LINHAS_ALERTA = 1000000;

if ( ! function_exists( 'consulta_leve_indices_candidatos' ) ) {

	/**
	 * Candidatos. Coluna = array( nome, prefixo ) (prefixo 0 = coluna inteira).
	 *
	 * @return array<string,array>
	 */
	function consulta_leve_indices_candidatos(): array {
		return array(
			'meta_chave_valor'        => array(
				'tabela'  => 'postmeta',
				'nome'    => 'cl_meta_chave_valor',
				'colunas' => array( array( 'meta_key', 32 ), array( 'meta_value', 32 ) ),
				'motivo'  => __( 'meta_query de igualdade (chave = valor) em lista grande lê só as linhas daquele valor, sem varrer todas as metas da chave.', 'consulta-leve' ),
				'exemplo' => "SELECT post_id FROM %postmeta% WHERE meta_key = '_thumbnail_id' AND meta_value = '1' LIMIT 10",
			),
			'meta_post_chave'         => array(
				'tabela'  => 'postmeta',
				'nome'    => 'cl_meta_post_chave',
				'colunas' => array( array( 'post_id', 0 ), array( 'meta_key', 32 ) ),
				'motivo'  => __( 'O JOIN de cada meta_query e a leitura de uma meta de um post vão direto à linha, sem ler todas as metas do post.', 'consulta-leve' ),
				'exemplo' => "SELECT meta_value FROM %postmeta% WHERE post_id = 1 AND meta_key = '_thumbnail_id'",
			),
			'posts_tipo_status_ordem' => array(
				'tabela'  => 'posts',
				'nome'    => 'cl_posts_tipo_status_ordem',
				'colunas' => array( array( 'post_type', 0 ), array( 'post_status', 0 ), array( 'menu_order', 0 ), array( 'ID', 0 ) ),
				'motivo'  => __( 'Listas ordenadas pela ordem manual (quadros, catálogos, páginas) sem ordenar em arquivo temporário.', 'consulta-leve' ),
				'exemplo' => "SELECT ID FROM %posts% WHERE post_type = 'page' AND post_status = 'publish' ORDER BY menu_order ASC LIMIT 20",
			),
			'posts_autor_tipo'        => array(
				'tabela'  => 'posts',
				'nome'    => 'cl_posts_autor_tipo',
				'colunas' => array( array( 'post_author', 0 ), array( 'post_type', 0 ), array( 'post_status', 0 ), array( 'post_date', 0 ) ),
				'motivo'  => __( 'Listas por autor ("minhas demandas", painel do colaborador) já ordenadas por data.', 'consulta-leve' ),
				'exemplo' => "SELECT ID FROM %posts% WHERE post_author = 1 AND post_type = 'post' AND post_status = 'publish' ORDER BY post_date DESC LIMIT 20",
			),
		);
	}

	/**
	 * Nome real de uma tabela da lista fechada.
	 *
	 * @param string $curta posts ou postmeta.
	 * @return string '' fora da lista.
	 */
	function consulta_leve_indices_tabela( string $curta ): string {
		global $wpdb;

		$mapa = array(
			'posts'    => $wpdb->posts,
			'postmeta' => $wpdb->postmeta,
		);

		return isset( $mapa[ $curta ] ) ? (string) $mapa[ $curta ] : '';
	}

	/**
	 * Motor do banco: mysql, mariadb ou sqlite.
	 *
	 * @return string
	 */
	function consulta_leve_indices_motor(): string {
		global $wpdb;

		if ( ( defined( 'DB_ENGINE' ) && 'sqlite' === DB_ENGINE ) || ( class_exists( 'WP_SQLite_DB', false ) && $wpdb instanceof \WP_SQLite_DB ) ) {
			return 'sqlite';
		}

		$info = method_exists( $wpdb, 'db_server_info' ) ? (string) $wpdb->db_server_info() : '';

		return false !== stripos( $info, 'mariadb' ) ? 'mariadb' : 'mysql';
	}

	/**
	 * Índices de uma tabela: nome => colunas (nome e prefixo), em ordem.
	 *
	 * @param string $tabela Nome real da tabela (da lista fechada).
	 * @return array<string,array>
	 */
	function consulta_leve_indices_existentes( string $tabela ): array {
		global $wpdb;

		$linhas = $wpdb->get_results(
			$wpdb->prepare(
				'SELECT INDEX_NAME, COLUMN_NAME, SUB_PART FROM INFORMATION_SCHEMA.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = %s ORDER BY INDEX_NAME, SEQ_IN_INDEX',
				$tabela
			),
			ARRAY_A
		);

		$indices = array();
		foreach ( is_array( $linhas ) ? $linhas : array() as $l ) {
			$indices[ (string) $l['INDEX_NAME'] ][] = array( (string) $l['COLUMN_NAME'], (int) $l['SUB_PART'] );
		}

		return $indices;
	}

	/**
	 * Estado de um candidato diante dos índices existentes (regra pura).
	 *
	 * @param array $candidato  Candidato.
	 * @param array $existentes Saída de consulta_leve_indices_existentes().
	 * @return string criado, coberto ou ausente.
	 */
	function consulta_leve_indices_regra_estado( array $candidato, array $existentes ): string {
		if ( isset( $existentes[ $candidato['nome'] ] ) ) {
			return 'criado';
		}

		$quer = array_map( 'strtolower', array_column( $candidato['colunas'], 0 ) );
		foreach ( $existentes as $colunas ) {
			$tem = array_map( 'strtolower', array_column( $colunas, 0 ) );
			if ( count( $tem ) >= count( $quer ) && array_slice( $tem, 0, count( $quer ) ) === $quer ) {
				return 'coberto';
			}
		}

		return 'ausente';
	}

	/**
	 * Linhas aproximadas de uma tabela (estatística do banco, sem COUNT).
	 *
	 * @param string $tabela Nome real.
	 * @return int
	 */
	function consulta_leve_indices_linhas( string $tabela ): int {
		global $wpdb;

		return (int) $wpdb->get_var(
			$wpdb->prepare( 'SELECT TABLE_ROWS FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = %s', $tabela )
		);
	}

	/**
	 * Situação de todos os candidatos.
	 *
	 * @return array<string,array> ID => candidato + estado + linhas + quem cobre.
	 */
	function consulta_leve_indices_situacao(): array {
		$motor      = consulta_leve_indices_motor();
		$situacao   = array();
		$existentes = array();

		foreach ( consulta_leve_indices_candidatos() as $id => $c ) {
			$tabela = consulta_leve_indices_tabela( $c['tabela'] );
			if ( 'sqlite' === $motor ) {
				$situacao[ $id ] = $c + array(
					'estado' => 'sem_suporte',
					'linhas' => 0,
				);
				continue;
			}
			if ( ! isset( $existentes[ $tabela ] ) ) {
				$existentes[ $tabela ] = consulta_leve_indices_existentes( $tabela );
			}

			$situacao[ $id ] = $c + array(
				'estado' => consulta_leve_indices_regra_estado( $c, $existentes[ $tabela ] ),
				'linhas' => consulta_leve_indices_linhas( $tabela ),
			);
		}

		return $situacao;
	}

	/**
	 * SQL de criação (nomes da lista fechada, entre crases).
	 *
	 * @param array  $c      Candidato.
	 * @param string $tabela Nome real.
	 * @param bool   $online Pede ALGORITHM=INPLACE, LOCK=NONE.
	 * @return string
	 */
	function consulta_leve_indices_sql_criar( array $c, string $tabela, bool $online ): string {
		$colunas = array();
		foreach ( $c['colunas'] as $col ) {
			$colunas[] = '`' . $col[0] . '`' . ( $col[1] > 0 ? '(' . (int) $col[1] . ')' : '' );
		}

		return 'ALTER TABLE `' . $tabela . '` ADD INDEX `' . $c['nome'] . '` (' . implode( ', ', $colunas ) . ')' . ( $online ? ', ALGORITHM=INPLACE, LOCK=NONE' : '' );
	}

	/**
	 * Cria um índice candidato.
	 *
	 * @param string $id        ID do candidato.
	 * @param bool   $confirmar Confirmação extra para tabela grande.
	 * @return string ok ou código do erro (desconhecido, sem_suporte, coberto,
	 *                criado, confirmar, ocupado, banco).
	 */
	function consulta_leve_indices_criar( string $id, bool $confirmar = false ): string {
		$candidatos = consulta_leve_indices_candidatos();
		if ( ! isset( $candidatos[ $id ] ) ) {
			return 'desconhecido';
		}

		$situacao = consulta_leve_indices_situacao();
		$estado   = $situacao[ $id ]['estado'];
		if ( 'ausente' !== $estado ) {
			return $estado;
		}
		if ( $situacao[ $id ]['linhas'] > CONSULTA_LEVE_INDICES_LINHAS_ALERTA && ! $confirmar ) {
			return 'confirmar';
		}

		$dono = consulta_leve_travar( 'indices', 900 );
		if ( '' === $dono ) {
			return 'ocupado';
		}

		global $wpdb;
		try {
			$tabela = consulta_leve_indices_tabela( $candidatos[ $id ]['tabela'] );
			$antes  = $wpdb->suppress_errors( true );
			$feito  = $wpdb->query( consulta_leve_indices_sql_criar( $candidatos[ $id ], $tabela, true ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- nomes de tabela, índice e colunas da lista fechada.
			if ( false === $feito ) {
				// Banco que não aceita criação online: tenta do jeito comum.
				$feito = $wpdb->query( consulta_leve_indices_sql_criar( $candidatos[ $id ], $tabela, false ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- idem.
			}
			$wpdb->suppress_errors( $antes );
			$resultado = false === $feito ? 'banco' : 'ok';
		} finally {
			consulta_leve_liberar( 'indices', $dono );
		}

		consulta_leve_registrar( 'indice_criar', $id, $resultado );
		do_action( 'consulta_leve_indice_criado', $id, $resultado );

		return $resultado;
	}

	/**
	 * Remove um índice criado por este plugin (prefixo cl_ e da lista).
	 *
	 * @param string $id ID do candidato.
	 * @return string ok ou código do erro (desconhecido, ausente, banco, ocupado).
	 */
	function consulta_leve_indices_remover( string $id ): string {
		$candidatos = consulta_leve_indices_candidatos();
		if ( ! isset( $candidatos[ $id ] ) || 0 !== strpos( $candidatos[ $id ]['nome'], 'cl_' ) ) {
			return 'desconhecido';
		}

		$situacao = consulta_leve_indices_situacao();
		if ( 'criado' !== $situacao[ $id ]['estado'] ) {
			return 'ausente';
		}

		$dono = consulta_leve_travar( 'indices', 900 );
		if ( '' === $dono ) {
			return 'ocupado';
		}

		global $wpdb;
		try {
			$tabela    = consulta_leve_indices_tabela( $candidatos[ $id ]['tabela'] );
			$feito     = $wpdb->query( 'ALTER TABLE `' . $tabela . '` DROP INDEX `' . $candidatos[ $id ]['nome'] . '`' ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- nomes da lista fechada.
			$resultado = false === $feito ? 'banco' : 'ok';
		} finally {
			consulta_leve_liberar( 'indices', $dono );
		}

		consulta_leve_registrar( 'indice_remover', $id, $resultado );

		return $resultado;
	}

	/**
	 * EXPLAIN da consulta típica de um candidato (só leitura, SQL fixo).
	 *
	 * @param string $id ID do candidato.
	 * @return array<int,array> Linhas do plano (tabela, tipo, chave, linhas, extra).
	 */
	function consulta_leve_indices_explain( string $id ): array {
		global $wpdb;

		$candidatos = consulta_leve_indices_candidatos();
		if ( ! isset( $candidatos[ $id ] ) || 'sqlite' === consulta_leve_indices_motor() ) {
			return array();
		}

		$sql    = str_replace( array( '%postmeta%', '%posts%' ), array( $wpdb->postmeta, $wpdb->posts ), $candidatos[ $id ]['exemplo'] );
		$linhas = $wpdb->get_results( 'EXPLAIN ' . $sql, ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- SQL fixo do candidato.

		$plano = array();
		foreach ( is_array( $linhas ) ? array_slice( $linhas, 0, 4 ) : array() as $l ) {
			$plano[] = array(
				'tabela' => (string) ( $l['table'] ?? '' ),
				'tipo'   => (string) ( $l['type'] ?? '' ),
				'chave'  => (string) ( $l['key'] ?? '' ),
				'linhas' => (int) ( $l['rows'] ?? 0 ),
				'extra'  => substr( (string) ( $l['Extra'] ?? '' ), 0, 80 ),
			);
		}

		return $plano;
	}
}
