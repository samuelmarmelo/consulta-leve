<?php
/**
 * Ensaio do bloco do `.htaccess` numa pasta própria de uploads (contrato,
 * seção 15.2): servidor que não aceita as linhas erra ali, não no site.
 *
 * @package ConsultaLeve
 */

defined( 'ABSPATH' ) || exit;

/** Arquivos da pasta de ensaio, na ordem em que saem. */
const CONSULTA_LEVE_HTACCESS_ENSAIO = array( 'sonda/.htaccess', 'sonda/ensaio.txt', '.htaccess', 'ensaio.txt' );

if ( ! function_exists( 'consulta_leve_htaccess_ensaiar' ) ) {

	/**
	 * Pasta de uploads, só se estiver dentro do WordPress e no mesmo endereço.
	 *
	 * @return array{pasta:string,url:string} Vazios se não serve.
	 */
	function consulta_leve_htaccess_uploads(): array {
		$u     = wp_get_upload_dir();
		$pasta = empty( $u['error'] ) ? realpath( (string) ( $u['basedir'] ?? '' ) ) : false;
		$base  = realpath( ABSPATH );
		$url   = (string) ( $u['baseurl'] ?? '' );
		$casa  = home_url( '/' );
		$ok    = false !== $pasta && false !== $base && is_dir( $pasta ) && is_writable( $pasta ) // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_is_writable
			&& 0 === strpos( str_replace( '\\', '/', $pasta ) . '/', rtrim( str_replace( '\\', '/', $base ), '/' ) . '/' )
			&& wp_parse_url( $url, PHP_URL_HOST ) === wp_parse_url( $casa, PHP_URL_HOST )
			&& wp_parse_url( $url, PHP_URL_PORT ) === wp_parse_url( $casa, PHP_URL_PORT );

		return array(
			'pasta' => $ok ? rtrim( str_replace( '\\', '/', $pasta ), '/' ) : '',
			'url'   => $ok ? set_url_scheme( rtrim( $url, '/' ), (string) wp_parse_url( $casa, PHP_URL_SCHEME ) ) : '',
		);
	}

	/**
	 * Caminho da pasta de ensaio ('' com nome fora do formato).
	 *
	 * @param string $nome Nome da pasta.
	 * @return string
	 */
	function consulta_leve_htaccess_ensaio_pasta( string $nome ): string {
		$u = consulta_leve_htaccess_uploads();

		return '' !== $u['pasta'] && 1 === preg_match( '/^consulta-leve-ensaio-[0-9a-f]{32}$/D', $nome ) ? $u['pasta'] . '/' . $nome : '';
	}

	/**
	 * Remove a pasta de ensaio: só os arquivos que o plugin criou nela.
	 *
	 * @param string $nome Nome da pasta ('' nada a fazer).
	 * @return bool Não existe mais?
	 */
	function consulta_leve_htaccess_ensaio_limpar( string $nome ): bool {
		// phpcs:disable WordPress.WP.AlternativeFunctions, WordPress.PHP.NoSilencedErrors.Discouraged -- arquivos próprios, de lista fechada.
		if ( '' === $nome ) {
			return true;
		}
		$pasta = consulta_leve_htaccess_ensaio_pasta( $nome );
		clearstatcache();
		if ( '' === $pasta || is_link( $pasta ) || is_link( $pasta . '/sonda' ) ) {
			return false;
		}
		if ( is_dir( $pasta ) ) {
			foreach ( CONSULTA_LEVE_HTACCESS_ENSAIO as $arquivo ) {
				@unlink( $pasta . '/' . $arquivo );
			}
			@rmdir( $pasta . '/sonda' );
			@rmdir( $pasta );
			clearstatcache();
		}
		// phpcs:enable

		return ! is_dir( $pasta );
	}

	/**
	 * Ensaia o bloco numa pasta própria, sem tocar o arquivo do site: um arquivo
	 * só com o bloco e outro com a sonda na tabela always e o bloco depois.
	 *
	 * @param string $nome   Nome da pasta, já gravado no estado.
	 * @param string $bloco  Bloco, igual ao que vai para o site.
	 * @param array  $config Configuração gravada.
	 * @return string '' ou motivo.
	 */
	function consulta_leve_htaccess_ensaiar( string $nome, string $bloco, array $config ): string {
		$pasta = consulta_leve_htaccess_ensaio_pasta( $nome );
		$url   = consulta_leve_htaccess_uploads()['url'] . '/' . $nome;
		$nomes = array_keys( consulta_leve_cabecalhos_lista( $config, true, array() ) );
		$sonda = '<IfModule mod_headers.c>' . "\n";
		foreach ( $nomes as $n ) {
			$sonda .= 'Header always set ' . $n . ' "' . CONSULTA_LEVE_HTACCESS_SONDA . '"' . "\n";
		}
		$sonda .= '</IfModule>' . "\n";

		// Permissões da pasta de uploads, para o servidor conseguir ler.
		$modo = '' !== $pasta ? fileperms( dirname( $pasta ) ) & 0777 : 0;
		foreach ( array( '', '/sonda' ) as $sub ) {
			// phpcs:ignore WordPress.WP.AlternativeFunctions, WordPress.PHP.NoSilencedErrors.Discouraged -- pasta própria; a falha é tratada.
			if ( '' === $pasta || ! @mkdir( $pasta . $sub ) || ! @chmod( $pasta . $sub, $modo ) || ! consulta_leve_htaccess_criar( $pasta . $sub . '/ensaio.txt', 'consulta-leve' ) || ! @chmod( $pasta . $sub . '/ensaio.txt', $modo & 0666 ) ) {
				return 'ensaio_local';
			}
		}

		$alvos = array( 'estatico' => '', 'ensaio' => '/sonda' );
		$antes = array();
		foreach ( $alvos as $tipo => $sub ) {
			$antes[ $tipo ] = consulta_leve_htaccess_pedir( $url . $sub . '/ensaio.txt' );
			if ( 200 !== $antes[ $tipo ]['status'] || 'consulta-leve' !== $antes[ $tipo ]['corpo'] ) {
				return 'ensaio_local';
			}
		}

		$esperados = consulta_leve_cabecalhos_lista( $config, 'https' === wp_parse_url( $url, PHP_URL_SCHEME ), array() );
		foreach ( $alvos as $tipo => $sub ) {
			$arquivo = $pasta . $sub . '/.htaccess';
			// phpcs:ignore WordPress.WP.AlternativeFunctions, WordPress.PHP.NoSilencedErrors.Discouraged
			if ( ! consulta_leve_htaccess_criar( $arquivo, ( '' === $sub ? '' : $sonda ) . $bloco ) || ! @chmod( $arquivo, $modo & 0666 ) ) {
				return 'ensaio_local';
			}
			$motivo = consulta_leve_htaccess_conferir_resposta( $tipo, $antes[ $tipo ], consulta_leve_htaccess_pedir( $url . $sub . '/ensaio.txt' ), $esperados, $nomes );
			if ( '' !== $motivo ) {
				return $motivo;
			}
		}

		return '';
	}
}
