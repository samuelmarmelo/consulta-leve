<?php
/**
 * Cabeçalhos de segurança opcionais (contrato, seção 15.1).
 *
 * Tudo desligado por padrão e enviado pelo PHP, nunca por `.htaccess` nem por
 * configuração do servidor. Complementar: cabeçalho que já está na resposta
 * nunca é trocado, e o tema pode dispensar o módulo inteiro.
 *
 * @package ConsultaLeve
 */

defined( 'ABSPATH' ) || exit;

if ( ! function_exists( 'consulta_leve_cabecalhos_lista' ) ) {

	/**
	 * Valores do Strict-Transport-Security por escolha.
	 *
	 * @return array<string,string>
	 */
	function consulta_leve_cabecalhos_hsts(): array {
		return array(
			'ano'         => 'max-age=31536000',
			'ano_preload' => 'max-age=31536000; includeSubDomains; preload',
		);
	}

	/**
	 * Cabeçalhos a enviar (regra pura).
	 *
	 * @param array    $config     Configuração (chaves `cab_*`).
	 * @param bool     $ssl        A requisição chegou por HTTPS?
	 * @param string[] $existentes Cabeçalhos já na resposta, como headers_list().
	 * @param bool     $em_quadro  Página feita para abrir em quadro de outro
	 *                             endereço (incorporação, prévia do Personalizar)?
	 * @return array<string,string> Nome => valor.
	 */
	function consulta_leve_cabecalhos_lista( array $config, bool $ssl, array $existentes, bool $em_quadro = false ): array {
		$hsts   = consulta_leve_cabecalhos_hsts();
		$opcao  = is_string( $config['cab_hsts'] ?? null ) ? $config['cab_hsts'] : '';
		$ligado = static function ( string $chave ) use ( $config ): bool {
			return true === ( $config[ $chave ] ?? false );
		};

		$lista = array();
		if ( $ligado( 'cab_nosniff' ) ) {
			$lista['X-Content-Type-Options'] = 'nosniff';
		}
		if ( $ligado( 'cab_referrer' ) ) {
			$lista['Referrer-Policy'] = 'strict-origin-when-cross-origin';
		}
		if ( $ligado( 'cab_frame' ) && ! $em_quadro ) {
			$lista['X-Frame-Options'] = 'SAMEORIGIN';
		}
		if ( $ligado( 'cab_upgrade' ) && $ssl ) {
			$lista['Content-Security-Policy'] = 'upgrade-insecure-requests';
		}
		if ( $ssl && isset( $hsts[ $opcao ] ) ) {
			$lista['Strict-Transport-Security'] = $hsts[ $opcao ];
		}

		$presentes = array();
		foreach ( $existentes as $linha ) {
			if ( is_string( $linha ) ) {
				$presentes[ strtolower( trim( explode( ':', $linha, 2 )[0] ) ) ] = true;
			}
		}

		foreach ( array_keys( $lista ) as $nome ) {
			if ( isset( $presentes[ strtolower( $nome ) ] ) ) {
				unset( $lista[ $nome ] );
			}
		}

		return $lista;
	}

	/**
	 * Valores novos a partir do formulário dos cabeçalhos (regra pura).
	 *
	 * Passar para a opção com subdomínios e preload exige a confirmação; sem
	 * ela, o HSTS fica como estava e o resto é salvo.
	 *
	 * @param array $recebido   Campos recebidos, sem barras.
	 * @param array $atual      Configuração gravada.
	 * @param bool  $confirmado Caixa de confirmação marcada?
	 * @return array{valores:array<string,mixed>,aviso:string} aviso: salvo ou hsts_confirmar.
	 */
	function consulta_leve_cabecalhos_do_formulario( array $recebido, array $atual, bool $confirmado ): array {
		$valores = array();
		foreach ( array( 'cab_nosniff', 'cab_referrer', 'cab_frame', 'cab_upgrade' ) as $chave ) {
			$valores[ $chave ] = ! empty( $recebido[ $chave ] );
		}

		$esquema = consulta_leve_config_esquema();
		$antes   = consulta_leve_config_valor( $esquema['cab_hsts'], $atual['cab_hsts'] ?? 'nao', 'nao' );
		$hsts    = consulta_leve_config_valor( $esquema['cab_hsts'], $recebido['cab_hsts'] ?? $antes, $antes );
		$aviso   = 'salvo';
		if ( 'ano_preload' === $hsts && 'ano_preload' !== $antes && ! $confirmado ) {
			$hsts  = $antes;
			$aviso = 'hsts_confirmar';
		}
		$valores['cab_hsts'] = $hsts;

		return array(
			'valores' => $valores,
			'aviso'   => $aviso,
		);
	}

	/**
	 * Envia os cabeçalhos (gancho `send_headers`, só no site).
	 *
	 * @return array<string,string> O que foi enviado.
	 */
	function consulta_leve_cabecalhos_enviar(): array {
		if ( is_admin() || headers_sent() || consulta_leve_dispensado( 'cabecalhos' ) ) {
			return array();
		}

		// Incorporações (oEmbed) e a prévia do Personalizar abrem em quadro.
		$em_quadro = is_embed() || is_customize_preview();
		$lista     = consulta_leve_cabecalhos_lista( consulta_leve_config(), is_ssl(), headers_list(), $em_quadro );
		foreach ( $lista as $nome => $valor ) {
			header( $nome . ': ' . $valor, false );
		}

		return $lista;
	}

	// Depois do tema e dos outros plugins; erro aqui nunca derruba a página.
	add_action(
		'send_headers',
		static function () {
			try {
				consulta_leve_cabecalhos_enviar();
			} catch ( \Throwable $erro ) {
				do_action( 'consulta_leve_falha', 'cabecalhos', $erro );
			}
		},
		999
	);
}
