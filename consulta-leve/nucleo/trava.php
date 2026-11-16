<?php
/**
 * Trava entre requisições (contrato, seção 3.4).
 *
 * Uma linha em wp_options criada com add_option (atômico pela chave única),
 * autoload desligado, valor `expira|dono`. Funciona em MySQL e no SQLite do
 * Playground, que não tem GET_LOCK. Origem: CRM interno `core/trava.php`.
 *
 * @package ConsultaLeve
 */

defined( 'ABSPATH' ) || exit;

if ( ! function_exists( 'consulta_leve_travar' ) ) {

	/**
	 * Nome da opção da trava.
	 *
	 * @param string $nome Nome curto (letras, números, _ e -).
	 * @return string
	 */
	function consulta_leve_trava_opcao( string $nome ): string {
		return 'consulta_leve_trava_' . substr( preg_replace( '/[^a-z0-9_-]/', '', strtolower( $nome ) ), 0, 120 );
	}

	/**
	 * Valor atual da trava, lido direto do banco (sem cache de opções).
	 *
	 * @param string $opcao Nome da opção.
	 * @return string
	 */
	function consulta_leve_trava_ler( string $opcao ): string {
		global $wpdb;

		$valor = $wpdb->get_var( $wpdb->prepare( "SELECT option_value FROM {$wpdb->options} WHERE option_name = %s LIMIT 1", $opcao ) );

		return is_string( $valor ) ? $valor : '';
	}

	/**
	 * Tenta pegar a trava.
	 *
	 * @param string $nome Nome curto.
	 * @param int    $ttl  Segundos até a trava vencer (5 a 3600).
	 * @return string Dono (guarde para liberar) ou '' se outra requisição tem a trava.
	 */
	function consulta_leve_travar( string $nome, int $ttl = 60 ): string {
		$opcao = consulta_leve_trava_opcao( $nome );
		$ttl   = max( 5, min( 3600, $ttl ) );

		for ( $tentativa = 0; $tentativa < 2; $tentativa++ ) {
			$dono  = bin2hex( random_bytes( 12 ) );
			$valor = ( time() + $ttl ) . '|' . $dono;

			if ( add_option( $opcao, $valor, '', false ) ) {
				wp_cache_delete( $opcao, 'options' );
				if ( consulta_leve_trava_ler( $opcao ) === $valor ) {
					return $dono;
				}
				return '';
			}

			// Já existe: só tira a trava vencida e tenta mais uma vez.
			$atual  = consulta_leve_trava_ler( $opcao );
			$expira = (int) strtok( $atual, '|' );
			if ( '' !== $atual && $expira > time() ) {
				return '';
			}

			global $wpdb;
			$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->options} WHERE option_name = %s AND option_value = %s", $opcao, $atual ) );
			wp_cache_delete( $opcao, 'options' );
			wp_cache_delete( 'notoptions', 'options' );
		}

		return '';
	}

	/**
	 * Libera a trava, só se ainda for do mesmo dono.
	 *
	 * @param string $nome Nome curto.
	 * @param string $dono Dono devolvido por consulta_leve_travar().
	 * @return bool Liberou?
	 */
	function consulta_leve_liberar( string $nome, string $dono ): bool {
		if ( '' === $dono ) {
			return false;
		}

		$opcao = consulta_leve_trava_opcao( $nome );
		$atual = consulta_leve_trava_ler( $opcao );
		$partes = explode( '|', $atual, 2 );

		if ( 2 !== count( $partes ) || ! hash_equals( $partes[1], $dono ) ) {
			return false;
		}

		global $wpdb;
		$feito = (bool) $wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->options} WHERE option_name = %s AND option_value = %s", $opcao, $atual ) );
		wp_cache_delete( $opcao, 'options' );
		wp_cache_delete( 'notoptions', 'options' );

		return $feito;
	}
}
