<?php
/**
 * Registro das ações que mudam o banco ou a configuração (contrato, seção 11).
 *
 * Uma opção sem autoload com as últimas 50 entradas: quem, quando, ação e
 * resultado. Nada de dado pessoal além do ID de quem agiu.
 *
 * @package ConsultaLeve
 */

defined( 'ABSPATH' ) || exit;

/** Opção do registro (autoload desligado). */
const CONSULTA_LEVE_OPCAO_REGISTRO = 'consulta_leve_registro';

if ( ! function_exists( 'consulta_leve_registrar' ) ) {

	/**
	 * Acrescenta uma entrada ao registro.
	 *
	 * @param string $acao      Ação curta (ex.: indice_criado).
	 * @param string $alvo      Alvo (ex.: ID do índice).
	 * @param string $resultado ok ou código do erro.
	 * @return void
	 */
	function consulta_leve_registrar( string $acao, string $alvo, string $resultado ): void {
		$lista = get_option( CONSULTA_LEVE_OPCAO_REGISTRO, array() );
		$lista = is_array( $lista ) ? $lista : array();

		array_unshift(
			$lista,
			array(
				'em'        => time(),
				'quem'      => function_exists( 'get_current_user_id' ) ? (int) get_current_user_id() : 0,
				'acao'      => substr( sanitize_key( $acao ), 0, 40 ),
				'alvo'      => substr( preg_replace( '/[^A-Za-z0-9_.:-]/', '', $alvo ), 0, 80 ),
				'resultado' => substr( preg_replace( '/[^A-Za-z0-9_.:-]/', '', $resultado ), 0, 60 ),
			)
		);

		update_option( CONSULTA_LEVE_OPCAO_REGISTRO, array_slice( $lista, 0, 50 ), false );
	}

	/**
	 * Últimas entradas do registro.
	 *
	 * @return array<int,array>
	 */
	function consulta_leve_registro(): array {
		$lista = get_option( CONSULTA_LEVE_OPCAO_REGISTRO, array() );
		return is_array( $lista ) ? $lista : array();
	}
}
