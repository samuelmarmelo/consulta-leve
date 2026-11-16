<?php
/**
 * Contagens em cache versionado (contrato, seção 5.4).
 *
 * Contar com COUNT separado (perfil `contagem`), guardado com dependência
 * `posts`: qualquer post alterado invalida sozinho. Origem: CRM interno
 * `api/busca.php` (COUNT separado, sem SQL_CALC_FOUND_ROWS na lista).
 *
 * @package ConsultaLeve
 */

defined( 'ABSPATH' ) || exit;

if ( ! function_exists( 'consulta_leve_contar' ) ) {

	/**
	 * Quantos posts a consulta acharia.
	 *
	 * @param array  $args   Argumentos de WP_Query (paginação ignorada).
	 * @param string $escopo publico ou usuario (consulta que depende de quem vê).
	 * @return int
	 */
	function consulta_leve_contar( array $args, string $escopo = 'publico' ): int {
		unset( $args['paged'], $args['offset'], $args['posts_per_page'], $args['nopaging'], $args['numberposts'], $args['orderby'], $args['order'] );
		ksort( $args );

		$total = consulta_leve_lembrar(
			'contagem',
			$args,
			static function () use ( $args ) {
				$q = new \WP_Query( consulta_leve_args( $args, 'contagem' ) );
				return (int) $q->found_posts;
			},
			array(
				'deps'   => array( 'posts', 'terms' ),
				'escopo' => 'usuario' === $escopo ? 'usuario' : 'publico',
			)
		);

		return (int) $total;
	}

	/**
	 * Contagem por status de um tipo de post (wp_count_posts guardado).
	 *
	 * @param string $tipo Tipo de post.
	 * @return array<string,int>
	 */
	function consulta_leve_contar_status( string $tipo ): array {
		$tipo = sanitize_key( $tipo );

		$contagem = consulta_leve_lembrar(
			'contagem_status',
			$tipo,
			static function () use ( $tipo ) {
				$bruto = wp_count_posts( $tipo );
				$lista = array();
				foreach ( (array) $bruto as $status => $n ) {
					$lista[ (string) $status ] = (int) $n;
				}
				return $lista;
			},
			array( 'deps' => array( 'posts' ) )
		);

		return is_array( $contagem ) ? $contagem : array();
	}
}
