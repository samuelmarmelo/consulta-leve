<?php
/**
 * Configuração da Consulta Leve (contrato, seção 3.2).
 *
 * Uma opção pequena (`consulta_leve_config`, autoload ligado) mesclada com os
 * padrões e com o filtro `consulta_leve_config`. Toda chave passa por uma
 * lista fechada de tipos e limites: valor estranho volta ao padrão.
 *
 * @package ConsultaLeve
 */

defined( 'ABSPATH' ) || exit;

/** Nome da opção da configuração. */
const CONSULTA_LEVE_OPCAO_CONFIG = 'consulta_leve_config';

if ( ! function_exists( 'consulta_leve_config_padrao' ) ) {

	/**
	 * Valores padrão. Seguro por padrão: o que muda o banco ou abre
	 * superfície pública começa desligado.
	 *
	 * @return array<string,mixed>
	 */
	function consulta_leve_config_padrao(): array {
		return array(
			'argumentos'      => true,
			'teto_global'     => false,
			'teto'            => 500,
			'primar_opcoes'   => true,
			'opcoes_lote'     => array(),
			'busca'           => true,
			'busca_min'       => 2,
			'busca_max'       => 80,
			'busca_palavras'  => 6,
			'busca_colunas'   => 'nucleo',
			'busca_noindex'   => true,
			'busca_limite'    => 30,
			'indice_busca'    => false,
			'indice_tipos'    => array(),
			'indice_metas'    => array(),
			'indice_na_busca' => false,
			'rest'            => false,
			'rest_por_minuto' => 60,
			'monitor'         => true,
			'cache_ttl'       => 600,
			'cron_real'       => false,
			'cab_nosniff'     => false,
			'cab_referrer'    => false,
			'cab_frame'       => false,
			'cab_upgrade'     => false,
			'cab_hsts'        => 'nao',
			'cab_htaccess'    => false,
		);
	}

	/**
	 * Esquema de cada chave: tipo e limites.
	 *
	 * @return array<string,array>
	 */
	function consulta_leve_config_esquema(): array {
		return array(
			'argumentos'      => array( 'bool' ),
			'teto_global'     => array( 'bool' ),
			'teto'            => array( 'int', 50, 5000 ),
			'primar_opcoes'   => array( 'bool' ),
			'opcoes_lote'     => array( 'lista', '/^[A-Za-z0-9_.:-]{1,191}$/', 100 ),
			'busca'           => array( 'bool' ),
			'busca_min'       => array( 'int', 1, 5 ),
			'busca_max'       => array( 'int', 20, 200 ),
			'busca_palavras'  => array( 'int', 1, 12 ),
			'busca_colunas'   => array( 'escolha', array( 'nucleo', 'titulo_resumo', 'titulo' ) ),
			'busca_noindex'   => array( 'bool' ),
			'busca_limite'    => array( 'int', 0, 600 ),
			'indice_busca'    => array( 'bool' ),
			'indice_tipos'    => array( 'lista', '/^[a-z0-9_-]{1,20}$/', 30 ),
			'indice_metas'    => array( 'lista', '/^[A-Za-z0-9_-]{1,191}$/', 30 ),
			'indice_na_busca' => array( 'bool' ),
			'rest'            => array( 'bool' ),
			'rest_por_minuto' => array( 'int', 10, 600 ),
			'monitor'         => array( 'bool' ),
			'cache_ttl'       => array( 'int', 30, 86400 ),
			'cron_real'       => array( 'bool' ),
			'cab_nosniff'     => array( 'bool' ),
			'cab_referrer'    => array( 'bool' ),
			'cab_frame'       => array( 'bool' ),
			'cab_upgrade'     => array( 'bool' ),
			'cab_hsts'        => array( 'escolha', array( 'nao', 'ano', 'ano_preload' ) ),
			'cab_htaccess'    => array( 'bool' ),
		);
	}

	/**
	 * Saneia um valor conforme o esquema. Valor inválido devolve o padrão.
	 *
	 * @param array $regra  Regra do esquema.
	 * @param mixed $valor  Valor recebido.
	 * @param mixed $padrao Valor padrão.
	 * @return mixed
	 */
	function consulta_leve_config_valor( array $regra, $valor, $padrao ) {
		switch ( $regra[0] ) {
			case 'bool':
				if ( is_bool( $valor ) ) {
					return $valor;
				}
				if ( in_array( $valor, array( 1, '1', 'sim', 'on', 'true' ), true ) ) {
					return true;
				}
				if ( in_array( $valor, array( 0, '0', '', 'nao', 'off', 'false' ), true ) ) {
					return false;
				}
				return $padrao;

			case 'int':
				if ( ! is_numeric( $valor ) ) {
					return $padrao;
				}
				return max( (int) $regra[1], min( (int) $regra[2], (int) $valor ) );

			case 'escolha':
				return is_string( $valor ) && in_array( $valor, $regra[1], true ) ? $valor : $padrao;

			case 'lista':
				if ( is_string( $valor ) ) {
					$valor = preg_split( '/[\s,]+/', $valor );
				}
				if ( ! is_array( $valor ) ) {
					return $padrao;
				}
				$limpa = array();
				foreach ( $valor as $item ) {
					$item = is_string( $item ) ? trim( $item ) : '';
					if ( '' !== $item && 1 === preg_match( $regra[1], $item ) ) {
						$limpa[ $item ] = true;
					}
				}
				return array_slice( array_keys( $limpa ), 0, (int) $regra[2] );
		}

		return $padrao;
	}

	/**
	 * Saneia a configuração inteira: só chaves conhecidas, cada uma no tipo e
	 * limite certos. Chave ausente recebe o padrão.
	 *
	 * @param mixed $dados Configuração recebida.
	 * @return array<string,mixed>
	 */
	function consulta_leve_config_sanitizar( $dados ): array {
		$dados   = is_array( $dados ) ? $dados : array();
		$padrao  = consulta_leve_config_padrao();
		$esquema = consulta_leve_config_esquema();
		$limpa   = array();

		foreach ( $padrao as $chave => $valor_padrao ) {
			$limpa[ $chave ] = array_key_exists( $chave, $dados )
				? consulta_leve_config_valor( $esquema[ $chave ], $dados[ $chave ], $valor_padrao )
				: $valor_padrao;
		}

		// O máximo de letras nunca fica abaixo do mínimo.
		if ( $limpa['busca_max'] < $limpa['busca_min'] ) {
			$limpa['busca_max'] = $padrao['busca_max'];
		}

		return $limpa;
	}

	/**
	 * Chaves com formulário e conferência próprios: o formulário geral do
	 * painel nunca as muda (nem por campo forjado, nem por caixa ausente).
	 *
	 * @return string[]
	 */
	function consulta_leve_config_chaves_proprias(): array {
		return array( 'cron_real', 'cab_nosniff', 'cab_referrer', 'cab_frame', 'cab_upgrade', 'cab_hsts', 'cab_htaccess' );
	}

	/**
	 * Valores novos a partir do formulário geral do painel.
	 *
	 * @param array $recebido Campos recebidos, sem barras.
	 * @return array<string,mixed> Para consulta_leve_config_gravar(), que saneia.
	 */
	function consulta_leve_config_do_formulario( array $recebido ): array {
		$novos = array();

		foreach ( consulta_leve_config_esquema() as $chave => $regra ) {
			if ( in_array( $chave, consulta_leve_config_chaves_proprias(), true ) ) {
				continue;
			}
			if ( 'bool' === $regra[0] ) {
				$novos[ $chave ] = ! empty( $recebido[ $chave ] );
			} elseif ( array_key_exists( $chave, $recebido ) ) {
				$valor           = $recebido[ $chave ];
				$novos[ $chave ] = is_array( $valor ) ? array_map( 'sanitize_text_field', $valor ) : sanitize_text_field( (string) $valor );
			} elseif ( 'lista' === $regra[0] ) {
				$novos[ $chave ] = array(); // Nenhuma caixa marcada.
			}
		}

		return $novos;
	}

	/**
	 * Configuração em vigor (memo por requisição, refeito quando a opção muda).
	 *
	 * @param bool $limpar Descarta o memo.
	 * @return array<string,mixed>
	 */
	function consulta_leve_config( bool $limpar = false ): array {
		static $memo = null;

		if ( $limpar ) {
			$memo = null;
		}
		if ( null !== $memo ) {
			return $memo;
		}

		$config = consulta_leve_config_sanitizar( get_option( CONSULTA_LEVE_OPCAO_CONFIG, array() ) );

		/**
		 * Filtra a configuração da Consulta Leve (o resultado é saneado de novo).
		 *
		 * @param array $config Configuração saneada.
		 */
		$memo = consulta_leve_config_sanitizar( apply_filters( 'consulta_leve_config', $config ) );

		return $memo;
	}

	/**
	 * Uma chave da configuração.
	 *
	 * @param string $chave Chave do contrato, seção 3.2.
	 * @return mixed Valor, ou null para chave desconhecida.
	 */
	function consulta_leve_opcao( string $chave ) {
		$config = consulta_leve_config();
		return array_key_exists( $chave, $config ) ? $config[ $chave ] : null;
	}

	/**
	 * Grava a configuração saneada (só chaves conhecidas).
	 *
	 * @param array $novos Valores novos (os ausentes ficam como estão).
	 * @return array<string,mixed> Configuração gravada.
	 */
	function consulta_leve_config_gravar( array $novos ): array {
		$atual = consulta_leve_config_sanitizar( get_option( CONSULTA_LEVE_OPCAO_CONFIG, array() ) );
		$limpa = consulta_leve_config_sanitizar( array_merge( $atual, $novos ) );
		update_option( CONSULTA_LEVE_OPCAO_CONFIG, $limpa, true );
		consulta_leve_config( true );

		return $limpa;
	}

	add_action(
		'update_option_' . CONSULTA_LEVE_OPCAO_CONFIG,
		static function () {
			consulta_leve_config( true );
		}
	);
}
