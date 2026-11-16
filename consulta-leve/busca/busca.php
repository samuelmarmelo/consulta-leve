<?php
/**
 * Busca do núcleo contida e componente de busca rápida (contrato, seção 8).
 *
 * A busca do WordPress faz `LIKE '%termo%'` em título, resumo e conteúdo de
 * todos os posts: termo enorme, de uma letra ou repetido por robô custa
 * caro. Aqui o termo é encurtado, termo curto não vai ao banco, as colunas
 * podem ser estreitadas (nunca alargadas), a página de busca recebe
 * `noindex` e cada IP tem um limite por minuto. Origem: loja C
 * `inc/cabecalho.php` e `assets/js/modules/cabecalho.js`, loja A
 * `docs/METODOLOGIAS-SCALABILITY-PRO.md` (5.3).
 *
 * @package ConsultaLeve
 */

defined( 'ABSPATH' ) || exit;

if ( ! function_exists( 'consulta_leve_busca_termo_limpo' ) ) {

	/**
	 * Termo saneado: sem tags, espaços simples, no máximo `busca_max` letras e
	 * `busca_palavras` palavras.
	 *
	 * @param string $termo Termo digitado.
	 * @return string
	 */
	function consulta_leve_busca_termo_limpo( string $termo ): string {
		$termo = trim( (string) preg_replace( '/\s+/u', ' ', wp_strip_all_tags( $termo ) ) );
		$max   = (int) consulta_leve_opcao( 'busca_max' );
		$termo = function_exists( 'mb_substr' ) ? mb_substr( $termo, 0, $max, 'UTF-8' ) : substr( $termo, 0, $max );
		$termo = implode( ' ', array_slice( explode( ' ', $termo ), 0, (int) consulta_leve_opcao( 'busca_palavras' ) ) );

		return trim( $termo );
	}

	/**
	 * Letras do termo (sem espaços).
	 *
	 * @param string $termo Termo.
	 * @return int
	 */
	function consulta_leve_busca_letras( string $termo ): int {
		$sem = str_replace( ' ', '', $termo );
		return function_exists( 'mb_strlen' ) ? mb_strlen( $sem, 'UTF-8' ) : strlen( $sem );
	}

	/**
	 * IP de quem pede (REMOTE_ADDR; proxy confiável pelo filtro).
	 *
	 * @return string
	 */
	function consulta_leve_ip(): string {
		$ip = isset( $_SERVER['REMOTE_ADDR'] ) ? (string) wp_unslash( $_SERVER['REMOTE_ADDR'] ) : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- validado como IP logo abaixo.

		/**
		 * Filtra o IP usado nos limites por minuto (ex.: proxy confiável).
		 *
		 * @param string $ip IP de REMOTE_ADDR.
		 */
		$ip = (string) apply_filters( 'consulta_leve_ip', $ip );

		return false !== filter_var( $ip, FILTER_VALIDATE_IP ) ? $ip : '0.0.0.0';
	}

	/**
	 * Conta um pedido e diz se o IP passou do limite neste minuto.
	 *
	 * @param string $balde      Nome do limite (busca, rest).
	 * @param int    $por_minuto Pedidos aceitos por minuto (0 = sem limite).
	 * @return bool Passou do limite?
	 */
	function consulta_leve_limite_bateu( string $balde, int $por_minuto ): bool {
		if ( $por_minuto <= 0 ) {
			return false;
		}

		$chave = 'consulta_leve_rl_' . md5( $balde . '|' . consulta_leve_ip() . '|' . (int) floor( time() / 60 ) );

		if ( wp_using_ext_object_cache() ) {
			wp_cache_add( $chave, 0, 'consulta_leve_limite', 70 );
			$n = (int) wp_cache_incr( $chave, 1, 'consulta_leve_limite' );
		} else {
			$n = (int) get_transient( $chave ) + 1;
			set_transient( $chave, $n, 70 );
		}

		return $n > $por_minuto;
	}

	/**
	 * A consulta é uma busca do site (não do painel, não da REST)?
	 *
	 * @param \WP_Query $q Consulta.
	 * @return bool
	 */
	function consulta_leve_busca_do_site( $q ): bool {
		if ( is_admin() || ( defined( 'REST_REQUEST' ) && REST_REQUEST ) || ! is_object( $q ) || ! method_exists( $q, 'get' ) ) {
			return false;
		}

		$s = $q->get( 's' );
		return is_string( $s ) && '' !== trim( $s );
	}

	/**
	 * Termo contido e termo curto sem ida ao banco.
	 *
	 * @param \WP_Query $q Consulta.
	 * @return void
	 */
	function consulta_leve_busca_pre_get_posts( $q ): void {
		if ( ! consulta_leve_ativo( 'busca' ) || ! consulta_leve_busca_do_site( $q ) ) {
			return;
		}

		$limpo = consulta_leve_busca_termo_limpo( (string) $q->get( 's' ) );
		$q->set( 's', $limpo );

		if ( consulta_leve_busca_letras( $limpo ) < (int) consulta_leve_opcao( 'busca_min' ) ) {
			// Nada a procurar: lista vazia sem varrer wp_posts.
			$q->set( 'post__in', array( 0 ) );
			$q->set( 'consulta_leve_busca_curta', true );
		}
	}

	/**
	 * Colunas da busca do núcleo: interseção com a escolha (nunca alarga).
	 *
	 * @param string[]  $colunas Colunas atuais (outro código pode já ter estreitado).
	 * @param string    $termo   Termo.
	 * @param \WP_Query $q       Consulta.
	 * @return string[]
	 */
	function consulta_leve_busca_colunas( $colunas, $termo = '', $q = null ) {
		if ( ! is_array( $colunas ) || ! consulta_leve_ativo( 'busca' ) || ! consulta_leve_busca_do_site( $q ) ) {
			return $colunas;
		}

		$mapa    = array(
			'titulo_resumo' => array( 'post_title', 'post_excerpt' ),
			'titulo'        => array( 'post_title' ),
		);
		$escolha = (string) consulta_leve_opcao( 'busca_colunas' );
		if ( ! isset( $mapa[ $escolha ] ) ) {
			return $colunas;
		}

		$menos = array_values( array_intersect( $colunas, $mapa[ $escolha ] ) );

		return $menos ? $menos : $colunas;
	}

	/**
	 * `noindex, follow` na página de busca.
	 *
	 * @param array $robots Diretivas.
	 * @return array
	 */
	function consulta_leve_busca_robots( $robots ) {
		if ( is_array( $robots ) && function_exists( 'is_search' ) && is_search() && consulta_leve_ativo( 'busca_noindex' ) ) {
			$robots['noindex'] = true;
			$robots['follow']  = true;
		}

		return $robots;
	}

	/**
	 * Limite por IP nas páginas de busca (visitante).
	 *
	 * @return void
	 */
	function consulta_leve_busca_limite(): void {
		if ( ! function_exists( 'is_search' ) || ! is_search() || is_user_logged_in() || ! consulta_leve_ativo( 'busca' ) || ! consulta_leve_ativo( 'busca_limite' ) ) {
			return;
		}

		if ( consulta_leve_limite_bateu( 'busca', (int) consulta_leve_opcao( 'busca_limite' ) ) ) {
			nocache_headers();
			header( 'Retry-After: 60' );
			wp_die(
				esc_html__( 'Muitas buscas seguidas. Espere um minuto e tente de novo.', 'consulta-leve' ),
				esc_html__( 'Busca em pausa', 'consulta-leve' ),
				array( 'response' => 429 )
			);
		}
	}

	/**
	 * Shortcode [consulta_leve_busca tipos="post,page" limite="8" rotulo="Buscar"].
	 * Com a REST ligada vira busca rápida; sem ela, um formulário comum
	 * (SafeWidget: funciona sem o script).
	 *
	 * @param array|string $atts Atributos.
	 * @return string
	 */
	function consulta_leve_busca_shortcode( $atts ): string {
		$atts = shortcode_atts(
			array(
				'tipos'  => '',
				'limite' => 8,
				'rotulo' => __( 'Buscar', 'consulta-leve' ),
			),
			is_array( $atts ) ? $atts : array(),
			'consulta_leve_busca'
		);

		static $n = 0;
		++$n;
		$id     = 'cl-busca-' . $n;
		$rapida = consulta_leve_ativo( 'rest' ) && function_exists( 'rest_url' );
		$tipos  = implode( ',', consulta_leve_lotes_lista_segura( explode( ',', (string) $atts['tipos'] ) ) );
		$limite = max( 1, min( 20, (int) $atts['limite'] ) );
		$dados  = '';

		if ( $rapida ) {
			wp_enqueue_script( 'consulta-leve-busca' );
			wp_enqueue_style( 'consulta-leve-busca' );
			$dados = sprintf(
				' data-cl-busca="%s" data-cl-min="%d" data-cl-tipos="%s" data-cl-limite="%d"',
				esc_url( rest_url( 'consulta-leve/v1/busca' ) ),
				(int) consulta_leve_opcao( 'busca_min' ),
				esc_attr( $tipos ),
				$limite
			);
		}

		$html  = '<form role="search" method="get" class="cl-busca" action="' . esc_url( home_url( '/' ) ) . '"' . $dados . '>';
		$html .= '<label class="cl-busca__rotulo" for="' . esc_attr( $id ) . '">' . esc_html( (string) $atts['rotulo'] ) . '</label>';
		$html .= '<input type="search" id="' . esc_attr( $id ) . '" name="s" class="cl-busca__campo" autocomplete="off" maxlength="' . (int) consulta_leve_opcao( 'busca_max' ) . '"' . ( $rapida ? ' aria-controls="' . esc_attr( $id ) . '-lista" aria-expanded="false" aria-autocomplete="list" role="combobox"' : '' ) . '>';
		$html .= '<button type="submit" class="cl-busca__botao">' . esc_html( (string) $atts['rotulo'] ) . '</button>';
		if ( $rapida ) {
			$html .= '<ul id="' . esc_attr( $id ) . '-lista" class="cl-busca__lista" role="listbox" hidden></ul>';
			$html .= '<p class="cl-busca__aviso" aria-live="polite"></p>';
		}
		$html .= '</form>';

		return $html;
	}

	/**
	 * Lista de tipos saneada, sem depender do módulo de lotes.
	 *
	 * @param array $lista Itens.
	 * @return string[]
	 */
	function consulta_leve_lotes_lista_segura( array $lista ): array {
		if ( function_exists( 'consulta_leve_lotes_lista' ) ) {
			return consulta_leve_lotes_lista( $lista );
		}

		return array_values( array_filter( array_map( 'sanitize_key', $lista ) ) );
	}

	/**
	 * Registra o script e o estilo (enfileirados só pelo shortcode).
	 *
	 * @return void
	 */
	function consulta_leve_busca_assets(): void {
		$url = plugins_url( 'assets/', CONSULTA_LEVE_ARQUIVO );
		wp_register_script( 'consulta-leve-busca', $url . 'js/busca.js', array(), CONSULTA_LEVE_VERSAO, array( 'in_footer' => true, 'strategy' => 'defer' ) );
		wp_register_style( 'consulta-leve-busca', $url . 'css/busca.css', array(), CONSULTA_LEVE_VERSAO );
	}

	add_action( 'pre_get_posts', 'consulta_leve_busca_pre_get_posts', 5 );
	add_filter( 'post_search_columns', 'consulta_leve_busca_colunas', 20, 3 );
	add_filter( 'wp_robots', 'consulta_leve_busca_robots', 20 );
	add_action( 'template_redirect', 'consulta_leve_busca_limite', 0 );
	add_action( 'wp_enqueue_scripts', 'consulta_leve_busca_assets', 5 );
	add_shortcode( 'consulta_leve_busca', 'consulta_leve_busca_shortcode' );
}
