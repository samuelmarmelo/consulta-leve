<?php
/**
 * Coexistência com o tema e outros plugins (contrato, seção 4).
 *
 * Um método é dispensado quando o tema declara que já o faz
 * (`add_theme_support( 'consulta-leve', array( 'dispensar' => array( ... ) ) )`),
 * quando o filtro `consulta_leve_dispensar` diz que sim ou quando um detector
 * automático acha o mesmo método já aplicado.
 *
 * @package ConsultaLeve
 */

defined( 'ABSPATH' ) || exit;

if ( ! function_exists( 'consulta_leve_metodos_dispensaveis' ) ) {

	/**
	 * Métodos que aceitam dispensa.
	 *
	 * @return string[]
	 */
	function consulta_leve_metodos_dispensaveis(): array {
		return array( 'argumentos', 'teto_global', 'primar_opcoes', 'busca', 'busca_noindex', 'busca_limite', 'indice_busca', 'rest', 'monitor', 'perfil' );
	}

	/**
	 * Métodos que o tema dispensou por `add_theme_support`.
	 *
	 * @return string[]
	 */
	function consulta_leve_dispensa_do_tema(): array {
		if ( ! function_exists( 'get_theme_support' ) ) {
			return array();
		}

		$suporte = get_theme_support( 'consulta-leve' );
		if ( ! is_array( $suporte ) || ! isset( $suporte[0] ) || ! is_array( $suporte[0] ) ) {
			return array();
		}

		$lista = isset( $suporte[0]['dispensar'] ) ? (array) $suporte[0]['dispensar'] : array();

		return array_values( array_intersect( consulta_leve_metodos_dispensaveis(), array_filter( $lista, 'is_string' ) ) );
	}

	/**
	 * Detectores automáticos: método => callable que diz se outro código já
	 * aplica o mesmo método.
	 *
	 * @return array<string,callable>
	 */
	function consulta_leve_detectores(): array {
		$detectores = array(
			// O Query Monitor já mostra as consultas na barra do admin.
			'monitor' => static function (): bool {
				return defined( 'QM_VERSION' ) || class_exists( 'QM_Activation', false );
			},
		);

		/**
		 * Filtra os detectores automáticos de coexistência.
		 *
		 * @param array<string,callable> $detectores Método => callable(): bool.
		 */
		$filtrados = apply_filters( 'consulta_leve_detectores', $detectores );

		return is_array( $filtrados ) ? $filtrados : $detectores;
	}

	/**
	 * O método foi dispensado (pelo tema, por filtro ou por detector)?
	 *
	 * @param string $metodo Método da lista de consulta_leve_metodos_dispensaveis().
	 * @return bool
	 */
	function consulta_leve_dispensado( string $metodo ): bool {
		$dispensado = in_array( $metodo, consulta_leve_dispensa_do_tema(), true );

		if ( ! $dispensado ) {
			$detectores = consulta_leve_detectores();
			if ( isset( $detectores[ $metodo ] ) && is_callable( $detectores[ $metodo ] ) ) {
				try {
					$dispensado = (bool) call_user_func( $detectores[ $metodo ] );
				} catch ( \Throwable $erro ) {
					do_action( 'consulta_leve_falha', 'coexistencia-' . $metodo, $erro );
				}
			}
		}

		/**
		 * Filtra se um método da Consulta Leve fica dispensado.
		 *
		 * @param bool   $dispensado Dispensado até aqui.
		 * @param string $metodo     Método.
		 */
		return (bool) apply_filters( 'consulta_leve_dispensar', $dispensado, $metodo );
	}

	/**
	 * O método está ligado na configuração e não foi dispensado?
	 *
	 * @param string $metodo Chave booleana da configuração (contrato, seção 3.2).
	 * @return bool
	 */
	function consulta_leve_ativo( string $metodo ): bool {
		$ligado = 'perfil' === $metodo ? true : (bool) consulta_leve_opcao( $metodo );

		return $ligado && ! consulta_leve_dispensado( $metodo );
	}

	/**
	 * Tipos de post que outro código já indexa numa tabela de busca própria:
	 * a tabela da Consulta Leve pula esses tipos.
	 *
	 * @return string[]
	 */
	function consulta_leve_indice_tipos_cobertos(): array {
		$cobertos = array();

		// CRM interno: tabela crm_busca cobre as demandas.
		if ( function_exists( 'crmimpactei_busca_tabela' ) ) {
			$cobertos[] = 'crm_demanda';
		}

		/**
		 * Filtra os tipos de post já cobertos por um índice de busca de outro código.
		 *
		 * @param string[] $cobertos Tipos de post.
		 */
		$filtrados = apply_filters( 'consulta_leve_indice_tipos_cobertos', $cobertos );

		return is_array( $filtrados ) ? array_values( array_filter( $filtrados, 'is_string' ) ) : $cobertos;
	}
}
