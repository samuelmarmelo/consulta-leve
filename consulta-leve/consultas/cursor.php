<?php
/**
 * Paginação por cursor, sem OFFSET (contrato, seção 5.3).
 *
 * OFFSET alto obriga o banco a ler e descartar todas as linhas anteriores.
 * Com a chave composta (post_date, ID) a página N custa o mesmo que a
 * primeira, apoiada no índice type_status_date do núcleo. O cursor é
 * assinado: alterado ou de outra ordem, volta ao começo. Origem:
 * CRM interno `busca-indice.php` (cursor por ID) e painel de catálogo
 * `data/historico.php`.
 *
 * @package ConsultaLeve
 */

defined( 'ABSPATH' ) || exit;

if ( ! function_exists( 'consulta_leve_cursor_codificar' ) ) {

	/**
	 * Assinatura de um cursor (32 hex).
	 *
	 * @param string $hex Corpo do cursor em hex.
	 * @return string
	 */
	function consulta_leve_cursor_assinatura( string $hex ): string {
		return substr( hash_hmac( 'sha256', 'consulta_leve_cursor|' . $hex, wp_salt( 'nonce' ) ), 0, 32 );
	}

	/**
	 * Codifica e assina a posição.
	 *
	 * @param array $pos `o` (ordem), `i` (ID) e `d` (post_date, na ordem data).
	 * @return string
	 */
	function consulta_leve_cursor_codificar( array $pos ): string {
		$hex = bin2hex( (string) wp_json_encode( $pos ) );
		return $hex . '.' . consulta_leve_cursor_assinatura( $hex );
	}

	/**
	 * Confere e decodifica um cursor.
	 *
	 * @param string $cursor Cursor recebido.
	 * @param string $ordem  Ordem esperada (data ou id).
	 * @return array|null Posição, ou null se inválido.
	 */
	function consulta_leve_cursor_decodificar( string $cursor, string $ordem ): ?array {
		if ( 1 !== preg_match( '/^([0-9a-f]{2,400})\.([0-9a-f]{32})$/D', $cursor, $m ) || 0 !== strlen( $m[1] ) % 2 ) {
			return null;
		}
		if ( ! hash_equals( consulta_leve_cursor_assinatura( $m[1] ), $m[2] ) ) {
			return null;
		}

		$pos = json_decode( (string) hex2bin( $m[1] ), true );
		if ( ! is_array( $pos ) || ( $pos['o'] ?? '' ) !== $ordem || ! isset( $pos['i'] ) || ! is_int( $pos['i'] ) || $pos['i'] < 1 ) {
			return null;
		}
		if ( 'data' === $ordem && ( ! isset( $pos['d'] ) || ! is_string( $pos['d'] ) || 1 !== preg_match( '/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/D', $pos['d'] ) ) ) {
			return null;
		}

		return $pos;
	}

	/**
	 * Argumentos para a página do cursor.
	 *
	 * @param array  $args   Argumentos de WP_Query (posts_per_page de 1 a 100).
	 * @param string $cursor Cursor da página anterior ('' na primeira).
	 * @param string $ordem  data (post_date DESC, ID DESC) ou id (ID DESC).
	 * @return array
	 */
	function consulta_leve_cursor_args( array $args, string $cursor = '', string $ordem = 'data' ): array {
		$ordem = 'id' === $ordem ? 'id' : 'data';
		$por   = isset( $args['posts_per_page'] ) ? (int) $args['posts_per_page'] : 20;
		$por   = $por < 1 ? 20 : min( 100, $por );

		unset( $args['offset'], $args['paged'], $args['page'], $args['nopaging'], $args['numberposts'] );

		$args['posts_per_page']       = $por + 1;
		$args['no_found_rows']        = true;
		$args['ignore_sticky_posts']  = true;
		$args['orderby']              = 'id' === $ordem ? array( 'ID' => 'DESC' ) : array(
			'date' => 'DESC',
			'ID'   => 'DESC',
		);
		$args['consulta_leve_sem_teto'] = true;
		// get_posts() desliga filtros por padrão; o cursor depende do posts_where.
		$args['suppress_filters'] = false;
		$args['consulta_leve_cursor']   = array(
			'ordem' => $ordem,
			'por'   => $por,
			'pos'   => '' === $cursor ? null : consulta_leve_cursor_decodificar( $cursor, $ordem ),
		);

		return $args;
	}

	/**
	 * Condição de posição no WHERE, só na consulta marcada.
	 *
	 * @param string    $where WHERE montado pelo WP_Query.
	 * @param \WP_Query $q     Consulta.
	 * @return string
	 */
	function consulta_leve_cursor_where( $where, $q ) {
		$info = is_object( $q ) && method_exists( $q, 'get' ) ? $q->get( 'consulta_leve_cursor' ) : null;
		if ( ! is_array( $info ) || empty( $info['pos'] ) ) {
			return $where;
		}

		global $wpdb;
		$pos = $info['pos'];

		if ( 'id' === $info['ordem'] ) {
			return $where . $wpdb->prepare( " AND {$wpdb->posts}.ID < %d", $pos['i'] );
		}

		return $where . $wpdb->prepare(
			" AND ( {$wpdb->posts}.post_date < %s OR ( {$wpdb->posts}.post_date = %s AND {$wpdb->posts}.ID < %d ) )",
			$pos['d'],
			$pos['d'],
			$pos['i']
		);
	}

	/**
	 * Resultado da página: posts (sem o item extra) e o próximo cursor.
	 *
	 * @param \WP_Query $q Consulta feita com consulta_leve_cursor_args().
	 * @return array{posts:array,proximo:string}
	 */
	function consulta_leve_cursor_resultado( $q ): array {
		$info  = $q->get( 'consulta_leve_cursor' );
		$por   = is_array( $info ) ? (int) $info['por'] : 20;
		$ordem = is_array( $info ) ? (string) $info['ordem'] : 'data';
		$posts = is_array( $q->posts ) ? $q->posts : array();
		$mais  = count( $posts ) > $por;
		$posts = array_slice( $posts, 0, $por );

		if ( ! $mais || ! $posts ) {
			return array(
				'posts'   => $posts,
				'proximo' => '',
			);
		}

		$ultimo = end( $posts );
		$id     = is_object( $ultimo ) ? (int) $ultimo->ID : (int) $ultimo;
		$pos    = array(
			'o' => $ordem,
			'i' => $id,
		);
		if ( 'data' === $ordem ) {
			$pos['d'] = is_object( $ultimo ) ? (string) $ultimo->post_date : (string) get_post_field( 'post_date', $id );
		}

		return array(
			'posts'   => $posts,
			'proximo' => consulta_leve_cursor_codificar( $pos ),
		);
	}

	add_filter( 'posts_where', 'consulta_leve_cursor_where', 10, 2 );
}
