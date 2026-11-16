<?php
/**
 * Trabalho em lotes por cursor de ID (contrato, seção 5.5).
 *
 * `ID > cursor ORDER BY ID LIMIT lote` lê só o lote, em qualquer página.
 * Cada lote entra em cache antes de ser tratado e o laço para no orçamento
 * de tempo, devolvendo onde parou para continuar depois (cron, AJAX, CLI).
 * Origem: CRM interno `busca-indice.php` e painel de catálogo
 * `jobs/fila.php`.
 *
 * @package ConsultaLeve
 */

defined( 'ABSPATH' ) || exit;

if ( ! function_exists( 'consulta_leve_em_lotes' ) ) {

	/**
	 * Lista saneada de tipos ou status para o IN do SQL.
	 *
	 * @param mixed $valor Texto ou lista.
	 * @return string[]
	 */
	function consulta_leve_lotes_lista( $valor ): array {
		$lista = array();
		foreach ( (array) $valor as $item ) {
			$item = is_string( $item ) ? sanitize_key( $item ) : '';
			if ( '' !== $item ) {
				$lista[ $item ] = true;
			}
		}

		return array_slice( array_keys( $lista ), 0, 30 );
	}

	/**
	 * Próximo lote de IDs depois do cursor.
	 *
	 * @param string[] $tipos  Tipos de post.
	 * @param string[] $status Status.
	 * @param int      $cursor Último ID tratado.
	 * @param int      $lote   Tamanho.
	 * @return int[]
	 */
	function consulta_leve_lotes_ids( array $tipos, array $status, int $cursor, int $lote ): array {
		global $wpdb;

		$tipos_in  = implode( ',', array_fill( 0, count( $tipos ), '%s' ) );
		$status_in = implode( ',', array_fill( 0, count( $status ), '%s' ) );
		$valores   = array_merge( $tipos, $status, array( $cursor, $lote ) );

		$ids = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT ID FROM {$wpdb->posts} WHERE post_type IN ({$tipos_in}) AND post_status IN ({$status_in}) AND ID > %d ORDER BY ID ASC LIMIT %d", // phpcs:ignore WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare -- marcadores montados acima, um por valor.
				$valores
			)
		);

		return array_map( 'intval', is_array( $ids ) ? $ids : array() );
	}

	/**
	 * Anda pelos posts em lotes até acabar ou estourar o orçamento.
	 *
	 * Opções: `tipos` (padrão post), `status` (padrão publish), `cursor`
	 * (padrão 0), `lote` (1 a 500, padrão 200), `orcamento` (segundos, padrão
	 * 20), `primar` (carrega posts e metas do lote, padrão true),
	 * `adiar_contagem` (wp_defer_term_counting, padrão false).
	 *
	 * @param array    $o     Opções.
	 * @param callable $fazer Recebe int[] com os IDs do lote.
	 * @return array{cursor:int,fim:bool,feitos:int,erro:string}
	 */
	function consulta_leve_em_lotes( array $o, callable $fazer ): array {
		$tipos     = consulta_leve_lotes_lista( $o['tipos'] ?? 'post' );
		$status    = consulta_leve_lotes_lista( $o['status'] ?? 'publish' );
		$cursor    = max( 0, (int) ( $o['cursor'] ?? 0 ) );
		$lote      = max( 1, min( 500, (int) ( $o['lote'] ?? 200 ) ) );
		$orcamento = max( 1, min( 300, (int) ( $o['orcamento'] ?? 20 ) ) );
		$primar    = ! isset( $o['primar'] ) || $o['primar'];
		$adiar     = ! empty( $o['adiar_contagem'] ) && function_exists( 'wp_defer_term_counting' );
		$limite    = microtime( true ) + $orcamento;
		$estado    = array(
			'cursor' => $cursor,
			'fim'    => false,
			'feitos' => 0,
			'erro'   => '',
		);

		if ( ! $tipos || ! $status ) {
			$estado['fim'] = true;
			return $estado;
		}

		if ( $adiar ) {
			wp_defer_term_counting( true );
		}

		try {
			while ( microtime( true ) < $limite ) {
				$ids = consulta_leve_lotes_ids( $tipos, $status, $estado['cursor'], $lote );
				if ( ! $ids ) {
					$estado['fim'] = true;
					break;
				}

				if ( $primar && function_exists( '_prime_post_caches' ) ) {
					_prime_post_caches( $ids, false, true );
				}

				call_user_func( $fazer, $ids );

				$estado['cursor'] = max( $ids );
				$estado['feitos'] += count( $ids );

				if ( count( $ids ) < $lote ) {
					$estado['fim'] = true;
					break;
				}
			}
		} catch ( \Throwable $erro ) {
			$estado['erro'] = substr( $erro->getMessage(), 0, 300 );
			do_action( 'consulta_leve_falha', 'lotes', $erro );
		} finally {
			if ( $adiar ) {
				wp_defer_term_counting( false );
			}
		}

		return $estado;
	}
}
