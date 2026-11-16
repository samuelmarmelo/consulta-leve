<?php
/**
 * Argumentos enxutos para WP_Query (contrato, seção 5.1).
 *
 * Pedir só o que a tela usa: sem contagem quando não há paginação, sem
 * carregar metas e termos que a lista não mostra. Nunca muda chave que o
 * chamador definiu ($args + padrões): o tema que já otimiza continua no
 * comando. Origem: CRM interno Fase 2.50 e loja A
 * `docs/OTIMIZACAO-CONSULTAS.md`, regra 3.
 *
 * @package ConsultaLeve
 */

defined( 'ABSPATH' ) || exit;

if ( ! function_exists( 'consulta_leve_perfis' ) ) {

	/**
	 * Padrões de cada perfil.
	 *
	 * @return array<string,array>
	 */
	function consulta_leve_perfis(): array {
		$sem_caches = array(
			'update_post_meta_cache' => false,
			'update_post_term_cache' => false,
		);

		return array(
			'ids'      => array(
				'fields'              => 'ids',
				'no_found_rows'       => true,
				'ignore_sticky_posts' => true,
			) + $sem_caches,
			'lista'    => array(
				'no_found_rows'       => true,
				'ignore_sticky_posts' => true,
			),
			'leve'     => array(
				'no_found_rows'       => true,
				'ignore_sticky_posts' => true,
			) + $sem_caches,
			'paginada' => array(
				'ignore_sticky_posts' => true,
			),
			'contagem' => array(
				'fields'              => 'ids',
				'posts_per_page'      => 1,
				'no_found_rows'       => false,
				'ignore_sticky_posts' => true,
			) + $sem_caches,
		);
	}

	/**
	 * Teto para `posts_per_page => -1` (nunca abaixo de 1).
	 *
	 * @return int
	 */
	function consulta_leve_teto(): int {
		$teto = function_exists( 'consulta_leve_opcao' ) ? (int) consulta_leve_opcao( 'teto' ) : 500;
		return max( 1, $teto );
	}

	/**
	 * Aplica o teto a um array de argumentos, quando cabe.
	 *
	 * @param array $args Argumentos.
	 * @return array
	 */
	function consulta_leve_aplicar_teto( array $args ): array {
		if ( ! empty( $args['consulta_leve_sem_teto'] ) ) {
			return $args;
		}

		$sem_limite = ( isset( $args['posts_per_page'] ) && -1 === (int) $args['posts_per_page'] )
			|| ( isset( $args['numberposts'] ) && -1 === (int) $args['numberposts'] )
			|| ! empty( $args['nopaging'] );

		if ( ! $sem_limite ) {
			return $args;
		}

		$teto                   = consulta_leve_teto();
		$args['posts_per_page'] = $teto;
		$args['nopaging']       = false;
		unset( $args['numberposts'] );

		/**
		 * O teto da Consulta Leve cortou uma consulta sem limite.
		 *
		 * @param int   $teto Valor aplicado.
		 * @param array $args Argumentos já com o teto.
		 */
		do_action( 'consulta_leve_teto_aplicado', $teto, $args );

		return $args;
	}

	/**
	 * Argumentos enxutos: $args + padrões do perfil, com teto em `-1`.
	 *
	 * @param array  $args   Argumentos de WP_Query.
	 * @param string $perfil ids, lista, leve, paginada ou contagem.
	 * @return array
	 */
	function consulta_leve_args( array $args, string $perfil = 'lista' ): array {
		$perfis = consulta_leve_perfis();
		$perfil = isset( $perfis[ $perfil ] ) ? $perfil : 'lista';

		$args += $perfis[ $perfil ];
		$args += array( 'consulta_leve' => $perfil );

		return 'contagem' === $perfil ? $args : consulta_leve_aplicar_teto( $args );
	}

	/**
	 * Consulta marcada com `consulta_leve => <perfil>`: completa os
	 * argumentos que o chamador não passou.
	 *
	 * @param \WP_Query $q Consulta.
	 * @return void
	 */
	function consulta_leve_pre_get_posts_marcada( $q ): void {
		$perfil = $q->get( 'consulta_leve' );
		if ( ! is_string( $perfil ) || '' === $perfil || ! consulta_leve_ativo( 'argumentos' ) ) {
			return;
		}

		$perfis = consulta_leve_perfis();
		if ( ! isset( $perfis[ $perfil ] ) ) {
			return;
		}

		// $q->query guarda só o que o chamador passou (antes dos padrões do WP).
		$passados = is_array( $q->query ) ? $q->query : array();
		foreach ( $perfis[ $perfil ] as $chave => $valor ) {
			if ( ! array_key_exists( $chave, $passados ) ) {
				$q->set( $chave, $valor );
			}
		}

		if ( 'contagem' !== $perfil ) {
			$teto = consulta_leve_aplicar_teto(
				array(
					'posts_per_page'         => $q->get( 'posts_per_page' ),
					'nopaging'               => $q->get( 'nopaging' ),
					'consulta_leve_sem_teto' => $q->get( 'consulta_leve_sem_teto' ),
				)
			);
			$q->set( 'posts_per_page', $teto['posts_per_page'] );
			$q->set( 'nopaging', $teto['nopaging'] );
		}
	}

	/**
	 * Teto global (desligado por padrão): consultas secundárias do site que
	 * pedem todos os posts completos. Fora do painel, da REST, do cron e do
	 * WP-CLI; consulta só de IDs fica de fora (costuma ser regra de negócio
	 * que precisa da lista inteira).
	 *
	 * @param \WP_Query $q Consulta.
	 * @return void
	 */
	function consulta_leve_pre_get_posts_teto_global( $q ): void {
		if ( ! consulta_leve_ativo( 'teto_global' ) || $q->is_main_query() || is_admin() ) {
			return;
		}
		if ( ( defined( 'REST_REQUEST' ) && REST_REQUEST ) || wp_doing_cron() || ( defined( 'WP_CLI' ) && WP_CLI ) ) {
			return;
		}
		if ( 'ids' === $q->get( 'fields' ) || 'id=>parent' === $q->get( 'fields' ) || '' !== (string) $q->get( 'consulta_leve' ) ) {
			return;
		}

		$teto = consulta_leve_aplicar_teto(
			array(
				'posts_per_page'         => $q->get( 'posts_per_page' ),
				'nopaging'               => $q->get( 'nopaging' ),
				'consulta_leve_sem_teto' => $q->get( 'consulta_leve_sem_teto' ),
			)
		);
		$q->set( 'posts_per_page', $teto['posts_per_page'] );
		$q->set( 'nopaging', $teto['nopaging'] );
	}

	add_action( 'pre_get_posts', 'consulta_leve_pre_get_posts_marcada', 1 );
	add_action( 'pre_get_posts', 'consulta_leve_pre_get_posts_teto_global', 1 );
}
