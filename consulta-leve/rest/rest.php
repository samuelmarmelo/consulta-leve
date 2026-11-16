<?php
/**
 * Rota pública de busca rápida (contrato, seção 9). Desligada por padrão.
 *
 * Só lê post publicado, sem senha, de tipo público e pesquisável; resposta
 * enxuta (id, título, endereço, tipo, data), cursor em vez de página, cache
 * de 60 s, ETag com 304 e limite por IP. Origem: CRM interno
 * `api/busca.php` (limites e COUNT separado) e `api/dashboard.php` (ETag).
 *
 * @package ConsultaLeve
 */

defined( 'ABSPATH' ) || exit;

if ( ! function_exists( 'consulta_leve_rest_etag_bate' ) ) {

	/**
	 * O If-None-Match recebido casa com a ETag? Aceita lista, `*` e `W/`.
	 *
	 * @param string $recebido Cabeçalho If-None-Match.
	 * @param string $etag     ETag com aspas.
	 * @return bool
	 */
	function consulta_leve_rest_etag_bate( string $recebido, string $etag ): bool {
		foreach ( explode( ',', $recebido ) as $item ) {
			$item = trim( $item );
			if ( '*' === $item ) {
				return true;
			}
			if ( 0 === strpos( $item, 'W/' ) ) {
				$item = substr( $item, 2 );
			}
			if ( '' !== $item && hash_equals( $etag, $item ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * ETag e Cache-Control numa resposta; 304 sem corpo quando o cliente já
	 * tem a mesma versão. Serve também para rotas do tema.
	 *
	 * @param \WP_REST_Response $resposta      Resposta.
	 * @param \WP_REST_Request  $pedido        Pedido.
	 * @param string            $cache_control Valor do Cache-Control.
	 * @return \WP_REST_Response
	 */
	function consulta_leve_rest_etag( $resposta, $pedido, string $cache_control = 'private, no-cache' ) {
		$etag     = '"' . hash( 'sha256', (string) wp_json_encode( $resposta->get_data() ) ) . '"';
		$recebido = (string) $pedido->get_header( 'if_none_match' );

		if ( '' !== $recebido && consulta_leve_rest_etag_bate( $recebido, $etag ) ) {
			$resposta = new \WP_REST_Response( null, 304 );
		}

		$resposta->header( 'ETag', $etag );
		$resposta->header( 'Cache-Control', $cache_control );

		return $resposta;
	}

	/**
	 * 304 sai sem corpo (sem o "null" da REST).
	 *
	 * @param bool              $servido  Já servido.
	 * @param \WP_HTTP_Response $resultado Resultado.
	 * @return bool
	 */
	function consulta_leve_rest_sem_corpo( $servido, $resultado ) {
		if ( ! $servido && is_object( $resultado ) && method_exists( $resultado, 'get_status' ) && 304 === (int) $resultado->get_status() ) {
			return true;
		}

		return $servido;
	}

	/**
	 * Tipos que a rota aceita: públicos e pesquisáveis.
	 *
	 * @return string[]
	 */
	function consulta_leve_rest_tipos_aceitos(): array {
		return array_values( get_post_types( array( 'public' => true, 'exclude_from_search' => false ) ) );
	}

	/**
	 * Registra a rota (só com a REST ligada).
	 *
	 * @return void
	 */
	function consulta_leve_rest_registrar(): void {
		if ( ! consulta_leve_ativo( 'rest' ) ) {
			return;
		}
		// Depende da busca contida, dos argumentos enxutos e do cursor.
		foreach ( array( 'consulta_leve_busca_termo_limpo', 'consulta_leve_args', 'consulta_leve_cursor_args' ) as $funcao ) {
			if ( ! function_exists( $funcao ) ) {
				return;
			}
		}

		register_rest_route(
			'consulta-leve/v1',
			'/busca',
			array(
				'methods'             => 'GET',
				'callback'            => 'consulta_leve_rest_busca',
				// Pública de propósito: só lê post publicado de tipo público.
				'permission_callback' => '__return_true',
				'args'                => array(
					'termo'      => array(
						'type'     => 'string',
						'required' => true,
						'minLength' => 1,
						'maxLength' => 200,
					),
					'tipos'      => array(
						'type'      => 'string',
						'default'   => '',
						'maxLength' => 300,
						'pattern'   => '^[a-z0-9_,-]*$',
					),
					'por_pagina' => array(
						'type'    => 'integer',
						'default' => 8,
						'minimum' => 1,
						'maximum' => 20,
					),
					'cursor'     => array(
						'type'      => 'string',
						'default'   => '',
						'maxLength' => 500,
						'pattern'   => '^[0-9a-f.]*$',
					),
				),
			)
		);
	}

	/**
	 * GET /consulta-leve/v1/busca.
	 *
	 * @param \WP_REST_Request $pedido Pedido.
	 * @return \WP_REST_Response|\WP_Error
	 */
	function consulta_leve_rest_busca( $pedido ) {
		if ( consulta_leve_limite_bateu( 'rest', (int) consulta_leve_opcao( 'rest_por_minuto' ) ) ) {
			$erro = new \WP_REST_Response(
				array(
					'code'    => 'consulta_leve_limite',
					'message' => __( 'Muitas buscas seguidas. Espere um minuto e tente de novo.', 'consulta-leve' ),
					'data'    => array( 'status' => 429 ),
				),
				429
			);
			$erro->header( 'Retry-After', '60' );
			$erro->header( 'Cache-Control', 'no-store' );
			return $erro;
		}

		$termo = consulta_leve_busca_termo_limpo( (string) $pedido->get_param( 'termo' ) );
		if ( consulta_leve_busca_letras( $termo ) < max( 2, (int) consulta_leve_opcao( 'busca_min' ) ) ) {
			return new \WP_Error( 'consulta_leve_termo_curto', __( 'Digite pelo menos duas letras.', 'consulta-leve' ), array( 'status' => 400 ) );
		}

		$aceitos = consulta_leve_rest_tipos_aceitos();
		$pedidos = array_filter( explode( ',', (string) $pedido->get_param( 'tipos' ) ) );
		$tipos   = $pedidos ? array_values( array_intersect( $aceitos, $pedidos ) ) : $aceitos;
		if ( ! $tipos ) {
			return new \WP_Error( 'consulta_leve_tipo', __( 'Tipo de conteúdo não disponível para busca.', 'consulta-leve' ), array( 'status' => 400 ) );
		}

		$por    = max( 1, min( 20, (int) $pedido->get_param( 'por_pagina' ) ) );
		$cursor = (string) $pedido->get_param( 'cursor' );
		$achado = consulta_leve_rest_achar( $termo, $tipos, $por, $cursor );

		$itens = array();
		if ( function_exists( 'consulta_leve_primar' ) ) {
			consulta_leve_primar( $achado['ids'], array( 'meta' => false, 'termos' => false ) );
		}
		foreach ( $achado['ids'] as $id ) {
			$post = get_post( $id );
			// A tabela de busca não guarda senha: confere de novo no post.
			if ( ! $post || 'publish' !== $post->post_status || '' !== (string) $post->post_password || ! in_array( $post->post_type, $tipos, true ) ) {
				continue;
			}
			$itens[] = array(
				'id'     => (int) $post->ID,
				'titulo' => html_entity_decode( wp_strip_all_tags( get_the_title( $post ) ), ENT_QUOTES, 'UTF-8' ),
				'url'    => (string) get_permalink( $post ),
				'tipo'   => (string) $post->post_type,
				'data'   => (string) get_post_time( 'c', true, $post ),
			);
		}

		$resposta = new \WP_REST_Response(
			array(
				'itens'   => $itens,
				'proximo' => $achado['proximo'],
			),
			200
		);

		return consulta_leve_rest_etag( $resposta, $pedido, 'public, max-age=60' );
	}

	/**
	 * IDs do termo: tabela de busca quando pronta e cobre os tipos; senão
	 * WP_Query enxuta só no título, com cursor.
	 *
	 * @param string   $termo  Termo limpo.
	 * @param string[] $tipos  Tipos aceitos.
	 * @param int      $por    Itens por página.
	 * @param string   $cursor Cursor.
	 * @return array{ids:int[],proximo:string}
	 */
	function consulta_leve_rest_achar( string $termo, array $tipos, int $por, string $cursor ): array {
		if ( function_exists( 'consulta_leve_busca_estado' ) && 'pronta' === consulta_leve_busca_estado() && ! array_diff( $tipos, consulta_leve_busca_tipos() ) ) {
			$r = consulta_leve_busca_ids(
				$termo,
				array(
					'tipos'      => $tipos,
					'status'     => 'publish',
					'por_pagina' => $por,
					'cursor'     => $cursor,
					'ordem'      => 'data',
				)
			);
			return array(
				'ids'     => $r['ids'],
				'proximo' => $r['proximo'],
			);
		}

		$achado = consulta_leve_lembrar(
			'rest_busca',
			array( $termo, $tipos, $por, $cursor ),
			static function () use ( $termo, $tipos, $por, $cursor ) {
				$args = consulta_leve_args(
					array(
						'post_type'      => $tipos,
						'post_status'    => 'publish',
						'has_password'   => false,
						's'              => $termo,
						'search_columns' => array( 'post_title' ),
						'posts_per_page' => $por,
					),
					'ids'
				);
				$q    = new \WP_Query( consulta_leve_cursor_args( $args, $cursor, 'data' ) );
				$r    = consulta_leve_cursor_resultado( $q );

				return array(
					'ids'     => array_map( 'intval', $r['posts'] ),
					'proximo' => $r['proximo'],
				);
			},
			array(
				'deps' => array( 'posts' ),
				'ttl'  => 60,
			)
		);

		return is_array( $achado ) ? $achado : array(
			'ids'     => array(),
			'proximo' => '',
		);
	}

	add_action( 'rest_api_init', 'consulta_leve_rest_registrar' );
	add_filter( 'rest_pre_serve_request', 'consulta_leve_rest_sem_corpo', 10, 2 );
}
