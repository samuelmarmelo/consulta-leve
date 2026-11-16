<?php
/**
 * Carga em lote, antes do laço (contrato, seção 5.2).
 *
 * Nada de consulta por item (N+1): posts, metas, termos, miniaturas, autores
 * e pais de uma lista entram no cache numa consulta por tipo. As funções do
 * núcleo pulam o que já está em cache, então chamar de novo é inofensivo.
 * Origem: CRM interno `data/demandas.php`, loja A
 * `inc/woocommerce/query-cache.php`, loja B `prime-opcoes.php`.
 *
 * @package ConsultaLeve
 */

defined( 'ABSPATH' ) || exit;

/** Maior lista aceita por chamada. */
const CONSULTA_LEVE_PRIMAR_MAX = 1000;

/** Tamanho de cada pedaço enviado ao banco. */
const CONSULTA_LEVE_PRIMAR_PEDACO = 200;

if ( ! function_exists( 'consulta_leve_primar' ) ) {

	/**
	 * IDs inteiros, positivos e únicos de uma lista de IDs ou objetos.
	 *
	 * @param array $itens IDs, WP_Post ou objetos com ID.
	 * @return int[]
	 */
	function consulta_leve_ids( array $itens ): array {
		$ids = array();
		foreach ( $itens as $item ) {
			$id = is_object( $item ) && isset( $item->ID ) ? (int) $item->ID : ( is_numeric( $item ) ? (int) $item : 0 );
			if ( $id > 0 ) {
				$ids[ $id ] = true;
			}
		}

		return array_slice( array_keys( $ids ), 0, CONSULTA_LEVE_PRIMAR_MAX );
	}

	/**
	 * Carrega em cache tudo que a lista vai usar.
	 *
	 * Opções (bool): `meta` (padrão true), `termos` (true), `miniaturas`
	 * (false), `autores` (false), `pais` (false).
	 *
	 * @param array $itens IDs ou posts.
	 * @param array $o     Opções.
	 * @return int[] IDs carregados.
	 */
	function consulta_leve_primar( array $itens, array $o = array() ): array {
		$o   = $o + array(
			'meta'       => true,
			'termos'     => true,
			'miniaturas' => false,
			'autores'    => false,
			'pais'       => false,
		);
		$ids = consulta_leve_ids( $itens );
		if ( ! $ids || ! function_exists( '_prime_post_caches' ) ) {
			return $ids;
		}

		try {
			foreach ( array_chunk( $ids, CONSULTA_LEVE_PRIMAR_PEDACO ) as $pedaco ) {
				_prime_post_caches( $pedaco, (bool) $o['termos'], (bool) $o['meta'] );
			}

			$extras  = array();
			$autores = array();
			$laco    = $o['pais'] || $o['autores'] || $o['miniaturas'];
			foreach ( $laco ? $ids : array() as $id ) {
				$post = get_post( $id );
				if ( ! $post ) {
					continue;
				}
				if ( $o['pais'] && (int) $post->post_parent > 0 ) {
					$extras[] = (int) $post->post_parent;
				}
				if ( $o['autores'] && (int) $post->post_author > 0 ) {
					$autores[] = (int) $post->post_author;
				}
				if ( $o['miniaturas'] ) {
					$capa = (int) get_post_meta( $id, '_thumbnail_id', true );
					if ( $capa > 0 ) {
						$extras[] = $capa;
					}
				}
			}

			// Pais e capas: post e metas (a capa precisa do _wp_attachment_metadata), sem termos.
			$extras = consulta_leve_ids( $extras );
			foreach ( array_chunk( $extras, CONSULTA_LEVE_PRIMAR_PEDACO ) as $pedaco ) {
				_prime_post_caches( $pedaco, false, true );
			}

			$autores = consulta_leve_ids( $autores );
			if ( $autores && function_exists( 'cache_users' ) ) {
				cache_users( $autores );
			}
		} catch ( \Throwable $erro ) {
			do_action( 'consulta_leve_falha', 'primar', $erro );
		}

		return $ids;
	}

	/**
	 * Opções sem autoload numa consulta só (WordPress 6.4+). Com cache de
	 * objetos externo, nada: lá cada leitura já é barata.
	 *
	 * @param string[] $nomes Nomes das opções.
	 * @return int Quantas foram pedidas.
	 */
	function consulta_leve_primar_opcoes( array $nomes ): int {
		if ( ! function_exists( 'wp_prime_option_caches' ) || wp_using_ext_object_cache() ) {
			return 0;
		}

		$limpos = array();
		foreach ( $nomes as $nome ) {
			if ( is_string( $nome ) && 1 === preg_match( '/^[A-Za-z0-9_.:-]{1,191}$/', $nome ) ) {
				$limpos[ $nome ] = true;
			}
		}
		$limpos = array_slice( array_keys( $limpos ), 0, 200 );

		if ( $limpos ) {
			wp_prime_option_caches( $limpos );
		}

		return count( $limpos );
	}

	/**
	 * Carga automática da lista configurada (`opcoes_lote` + filtro).
	 *
	 * @return void
	 */
	function consulta_leve_primar_opcoes_auto(): void {
		if ( ! consulta_leve_ativo( 'primar_opcoes' ) ) {
			return;
		}

		$nomes = (array) consulta_leve_opcao( 'opcoes_lote' );

		/**
		 * Filtra as opções carregadas em lote a cada requisição.
		 *
		 * @param string[] $nomes Nomes das opções.
		 */
		$nomes = apply_filters( 'consulta_leve_opcoes_lote', $nomes );

		consulta_leve_primar_opcoes( is_array( $nomes ) ? $nomes : array() );
	}

	add_action( 'after_setup_theme', 'consulta_leve_primar_opcoes_auto', 1 );
}
