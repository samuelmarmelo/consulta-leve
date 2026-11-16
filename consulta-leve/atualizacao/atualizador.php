<?php
/**
 * Atualização da Consulta Leve pelas versões publicadas no repositório público
 * (contrato docs/ATUALIZADOR.md; formato das versões em docs/RELEASE.md).
 *
 * Só age quando a Consulta Leve roda como plugin próprio (`consulta-leve/consulta-leve.php`).
 * Cópia embutida em tema ou plugin de site é atualizada por quem a embute e
 * não registra nenhum gancho daqui (as funções de baixar e conferir ficam
 * disponíveis, sem efeito).
 *
 * - `update_plugins_github.com` (cabeçalho `Update URI`): lê a última versão
 *   publicada (cache de 6 h; erro, 30 min), sem token e sem dado do site, e
 *   oferece a versão quando ela traz os três arquivos esperados.
 * - `upgrader_pre_download`: só para o pacote marcado com `#consulta-leve-assinado`.
 *   Lê a versão de novo, baixa `.sha256`, `.sig` e o zip sem seguir
 *   redirecionamento (destino conferido: https num host
 *   `*.githubusercontent.com`), confere tamanho, SHA-256 (arquivo `.sha256` e
 *   o informado pela API) e a assinatura Ed25519 com as chaves de
 *   atualizacao/chaves.php. Qualquer divergência: nada é instalado.
 * - `upgrader_source_selection`: na atualização da Consulta Leve, a pasta vira
 *   `consulta-leve/` e o cabeçalho precisa ter `Plugin Name: Consulta Leve` e a `Version`
 *   conferida. Pacote que não passou pela conferência é recusado.
 * - `auto_update_plugin`: vale a escolha do site na tela Plugins (a Consulta Leve
 *   entra ligada uma vez); automático só para versão publicada há 48 h ou
 *   mais; `CONSULTA_LEVE_ATUALIZAR_AUTO === false` desliga de vez.
 * - `plugins_api`: modal "Ver detalhes" com as notas da versão escapadas.
 *
 * SafeWidget: toda falha vira WP_Error ou a oferta some; nunca erro fatal.
 * A Consulta Leve é pública: os textos da tela citam o GitHub. PHP 7.4.
 *
 * @package ConsultaLeve
 */

defined( 'ABSPATH' ) || exit;

/** Arquivo do plugin próprio (pasta/arquivo). */
const CONSULTA_LEVE_ATUALIZADOR_PLUGIN = 'consulta-leve/consulta-leve.php';

/** Pasta do plugin e prefixo dos arquivos da versão. */
const CONSULTA_LEVE_ATUALIZADOR_SLUG = 'consulta-leve';

/** Nome exigido no cabeçalho do pacote. */
const CONSULTA_LEVE_ATUALIZADOR_NOME = 'Consulta Leve';

/** Última versão publicada (leitura pública, sem token). */
const CONSULTA_LEVE_ATUALIZADOR_ULTIMA = 'https://api.github.com/repos/samuelmarmelo/consulta-leve/releases/latest';

/** Prefixo exato do endereço público de cada arquivo da versão. */
const CONSULTA_LEVE_ATUALIZADOR_DOWNLOAD = 'https://github.com/samuelmarmelo/consulta-leve/releases/download/';

/** Página do projeto. */
const CONSULTA_LEVE_ATUALIZADOR_PAGINA = 'https://github.com/samuelmarmelo/consulta-leve';

/** Marca do pacote que este módulo baixa e confere. */
const CONSULTA_LEVE_ATUALIZADOR_MARCA = '#consulta-leve-assinado';

/** Transient de site com a última leitura. */
const CONSULTA_LEVE_ATUALIZADOR_CACHE = 'consulta_leve_atualizador_release';

/** Cache da leitura com sucesso e com erro, em segundos. */
const CONSULTA_LEVE_ATUALIZADOR_CACHE_OK   = 21600;
const CONSULTA_LEVE_ATUALIZADOR_CACHE_ERRO = 1800;

/** Leitura boa com menos que isto (s) vale também para o download. */
const CONSULTA_LEVE_ATUALIZADOR_RECENTE = 600;

/** Com a leitura falhando, a última leitura boa vale até esta idade (s), nunca mais. */
const CONSULTA_LEVE_ATUALIZADOR_TETO_ANTIGA = 3600;

/**
 * Atualização automática só de versão publicada há pelo menos isto (s):
 * janela para tirar do ar uma versão ruim antes que os sites a instalem
 * sozinhos. A atualização pelo botão não espera.
 */
const CONSULTA_LEVE_ATUALIZADOR_ESPERA_AUTO = 172800;

/** Opção de site: a Consulta Leve já entrou uma vez na lista de atualização automática. */
const CONSULTA_LEVE_ATUALIZADOR_OPCAO_AUTO = 'consulta_leve_atualizador_auto_padrao';

/** Limites em bytes: zip (igual ao de tools/release.mjs), .sha256/.sig e resposta da API. */
const CONSULTA_LEVE_ATUALIZADOR_LIMITE       = 5242880;
const CONSULTA_LEVE_ATUALIZADOR_LIMITE_TEXTO = 1024;
const CONSULTA_LEVE_ATUALIZADOR_LIMITE_API   = 1048576;

/* Regras puras -------------------------------------------------------------- */

/**
 * Esta cópia é o plugin próprio (e não uma cópia embutida)?
 *
 * @return bool
 */
function consulta_leve_atualizador_plugin_proprio(): bool {
	if ( ! defined( 'CONSULTA_LEVE_ARQUIVO' ) || ! function_exists( 'plugin_basename' ) ) {
		return false;
	}

	return CONSULTA_LEVE_ATUALIZADOR_PLUGIN === plugin_basename( CONSULTA_LEVE_ARQUIVO );
}

/**
 * Versão no formato X.Y.Z (sem zero à esquerda, até 4 dígitos por parte).
 *
 * @param mixed $versao Valor.
 * @return bool
 */
function consulta_leve_atualizador_versao_valida( $versao ): bool {
	return is_string( $versao ) && 1 === preg_match( '/^(?:0|[1-9]\d{0,3})\.(?:0|[1-9]\d{0,3})\.(?:0|[1-9]\d{0,3})$/D', $versao );
}

/**
 * Nome do zip de uma tag.
 *
 * @param string $tag Tag vX.Y.Z.
 * @return string
 */
function consulta_leve_atualizador_nome_zip( string $tag ): string {
	return CONSULTA_LEVE_ATUALIZADOR_SLUG . '-' . $tag . '.zip';
}

/**
 * Mensagem assinada (docs/RELEASE.md, seção 2).
 *
 * @param string $tag     Tag vX.Y.Z.
 * @param string $arquivo Nome do zip.
 * @param string $hex     SHA-256 do zip em hex minúsculo.
 * @return string
 */
function consulta_leve_atualizador_mensagem( string $tag, string $arquivo, string $hex ): string {
	return "consulta-leve-release-v1\ntag: {$tag}\narquivo: {$arquivo}\nsha256: {$hex}\n";
}

/**
 * Corta um texto em até $bytes sem partir um caractere UTF-8.
 *
 * @param string $texto Texto.
 * @param int    $bytes Limite.
 * @return string
 */
function consulta_leve_atualizador_cortar( string $texto, int $bytes ): string {
	if ( strlen( $texto ) <= $bytes ) {
		return $texto;
	}
	if ( function_exists( 'mb_strcut' ) ) {
		return mb_strcut( $texto, 0, $bytes, 'UTF-8' );
	}

	$corte = substr( $texto, 0, $bytes );
	// Tira bytes de continuação soltos no fim (10xxxxxx) e o início sem par.
	return (string) preg_replace( '/[\xC0-\xFF][\x80-\xBF]*$/', '', $corte );
}

/**
 * Regra pura: lê a resposta da última versão publicada.
 *
 * Exige versão publicada (não rascunho, não pré-versão), tag vX.Y.Z e os
 * três arquivos (zip, .sha256, .sig) enviados, cada um uma vez, no endereço
 * público exato e dentro do limite de tamanho.
 *
 * @param mixed $dados JSON decodificado.
 * @return array{ok:bool,erro:string,versao?:string,tag?:string,zip?:array,sha256?:array,sig?:array,notas?:string,data?:string}
 */
function consulta_leve_atualizador_regra_release( $dados ): array {
	$erro = static function ( string $codigo ): array {
		return array(
			'ok'   => false,
			'erro' => $codigo,
		);
	};

	if ( ! is_array( $dados ) ) {
		return $erro( 'resposta' );
	}
	if ( ! empty( $dados['draft'] ) || ! empty( $dados['prerelease'] ) ) {
		return $erro( 'rascunho' );
	}

	$tag = isset( $dados['tag_name'] ) && is_string( $dados['tag_name'] ) ? $dados['tag_name'] : '';
	if ( 0 !== strpos( $tag, 'v' ) || ! consulta_leve_atualizador_versao_valida( substr( $tag, 1 ) ) ) {
		return $erro( 'tag' );
	}

	$zip     = consulta_leve_atualizador_nome_zip( $tag );
	$nomes   = array(
		'zip'    => $zip,
		'sha256' => $zip . '.sha256',
		'sig'    => $zip . '.sig',
	);
	$limites = array(
		'zip'    => CONSULTA_LEVE_ATUALIZADOR_LIMITE,
		'sha256' => CONSULTA_LEVE_ATUALIZADOR_LIMITE_TEXTO,
		'sig'    => CONSULTA_LEVE_ATUALIZADOR_LIMITE_TEXTO,
	);
	$achados = array();
	$assets  = isset( $dados['assets'] ) && is_array( $dados['assets'] ) ? $dados['assets'] : array();

	foreach ( $assets as $asset ) {
		if ( ! is_array( $asset ) || ! isset( $asset['name'] ) || ! is_string( $asset['name'] ) ) {
			continue;
		}
		$papel = array_search( $asset['name'], $nomes, true );
		if ( false === $papel ) {
			continue;
		}
		if ( isset( $achados[ $papel ] ) ) {
			return $erro( 'duplicado' );
		}

		$url     = isset( $asset['browser_download_url'] ) && is_string( $asset['browser_download_url'] ) ? $asset['browser_download_url'] : '';
		$tamanho = isset( $asset['size'] ) && is_int( $asset['size'] ) ? $asset['size'] : -1;
		$estado  = isset( $asset['state'] ) && is_string( $asset['state'] ) ? $asset['state'] : '';

		if ( CONSULTA_LEVE_ATUALIZADOR_DOWNLOAD . $tag . '/' . $asset['name'] !== $url ) {
			return $erro( 'endereco' );
		}
		if ( $tamanho < 1 || $tamanho > $limites[ $papel ] ) {
			return $erro( 'tamanho' );
		}
		if ( 'uploaded' !== $estado ) {
			return $erro( 'estado' );
		}

		$digest = '';
		if ( isset( $asset['digest'] ) && is_string( $asset['digest'] ) && 1 === preg_match( '/^sha256:([0-9a-f]{64})$/D', $asset['digest'], $m ) ) {
			$digest = $m[1];
		}

		$achados[ $papel ] = array(
			'url'     => $url,
			'tamanho' => $tamanho,
			'digest'  => $digest,
		);
	}

	if ( 3 !== count( $achados ) ) {
		return $erro( 'arquivos' );
	}

	$data = isset( $dados['published_at'] ) && is_string( $dados['published_at'] ) && 1 === preg_match( '/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}Z$/D', $dados['published_at'] ) ? $dados['published_at'] : '';

	return array(
		'ok'     => true,
		'erro'   => '',
		'versao' => substr( $tag, 1 ),
		'tag'    => $tag,
		'zip'    => $achados['zip'],
		'sha256' => $achados['sha256'],
		'sig'    => $achados['sig'],
		'notas'  => isset( $dados['body'] ) && is_string( $dados['body'] ) ? consulta_leve_atualizador_cortar( $dados['body'], 20000 ) : '',
		'data'   => $data,
	);
}

/**
 * Regra pura: o destino do redirecionamento do download é aceito? Só https,
 * sem usuário, senha ou porta diferente de 443, num dos hosts de arquivo de
 * versão (`release-assets`, `objects` ou `github-releases`
 * `.githubusercontent.com`).
 *
 * @param mixed $url Endereço do cabeçalho Location.
 * @return bool
 */
function consulta_leve_atualizador_regra_destino( $url ): bool {
	if ( ! is_string( $url ) || '' === $url || strlen( $url ) > 8192 || 1 === preg_match( '/[\s\\\\]/', $url ) ) {
		return false;
	}

	$partes = wp_parse_url( $url );
	if ( ! is_array( $partes ) || ! isset( $partes['scheme'], $partes['host'] ) || 'https' !== strtolower( (string) $partes['scheme'] ) ) {
		return false;
	}
	if ( isset( $partes['user'] ) || isset( $partes['pass'] ) || ( isset( $partes['port'] ) && 443 !== (int) $partes['port'] ) ) {
		return false;
	}

	return 1 === preg_match( '/^(?:release-assets|objects|github-releases)\.githubusercontent\.com$/D', strtolower( (string) $partes['host'] ) );
}

/**
 * Regra pura: SHA-256 publicado no arquivo `.sha256` (formato do sha256sum),
 * só quando o nome bate.
 *
 * @param mixed  $conteudo Conteúdo do arquivo.
 * @param string $arquivo  Nome esperado do zip.
 * @return string Hex minúsculo ou ''.
 */
function consulta_leve_atualizador_regra_sha256( $conteudo, string $arquivo ): string {
	if ( ! is_string( $conteudo ) || strlen( $conteudo ) > CONSULTA_LEVE_ATUALIZADOR_LIMITE_TEXTO ) {
		return '';
	}
	if ( 1 !== preg_match( '/^([0-9a-f]{64})  ([A-Za-z0-9._-]{1,200})\n?$/D', $conteudo, $m ) ) {
		return '';
	}

	return $m[2] === $arquivo ? $m[1] : '';
}

/**
 * O PHP tem a verificação Ed25519 (extensão sodium ou o sodium_compat que
 * vem com o WordPress)?
 *
 * @return bool
 */
function consulta_leve_atualizador_sodium(): bool {
	if ( function_exists( 'sodium_crypto_sign_verify_detached' ) ) {
		return true;
	}

	$compat = defined( 'WPINC' ) ? ABSPATH . WPINC . '/sodium_compat/autoload.php' : '';
	if ( '' !== $compat && is_readable( $compat ) ) {
		require_once $compat;
	}

	return function_exists( 'sodium_crypto_sign_verify_detached' );
}

/**
 * Regra pura: a assinatura (hex, 64 bytes) confere com alguma chave?
 *
 * @param string $mensagem   Mensagem assinada.
 * @param mixed  $assinatura Conteúdo do arquivo `.sig`.
 * @param array  $chaves     Chaves públicas em hex (32 bytes).
 * @return bool
 */
function consulta_leve_atualizador_assinatura_valida( string $mensagem, $assinatura, array $chaves ): bool {
	if ( ! is_string( $assinatura ) || 1 !== preg_match( '/^([0-9a-f]{128})\n?$/D', $assinatura, $m ) ) {
		return false;
	}
	if ( ! consulta_leve_atualizador_sodium() ) {
		return false;
	}

	$binaria = hex2bin( $m[1] );
	foreach ( $chaves as $chave ) {
		if ( ! is_string( $chave ) || 1 !== preg_match( '/^[0-9a-f]{64}$/D', $chave ) ) {
			continue;
		}
		try {
			if ( sodium_crypto_sign_verify_detached( $binaria, $mensagem, hex2bin( $chave ) ) ) {
				return true;
			}
		} catch ( \Throwable $erro ) {
			continue;
		}
	}

	return false;
}

/**
 * Regra pura: confere o zip baixado contra a versão lida.
 *
 * @param string $hex     SHA-256 do arquivo baixado.
 * @param int    $tamanho Tamanho do arquivo baixado.
 * @param array  $release Resultado de consulta_leve_atualizador_regra_release().
 * @param mixed  $sha256  Conteúdo do `.sha256`.
 * @param mixed  $sig     Conteúdo do `.sig`.
 * @param array  $chaves  Chaves públicas confiáveis.
 * @return string '' quando tudo confere; senão o código do problema.
 */
function consulta_leve_atualizador_regra_pacote( string $hex, int $tamanho, array $release, $sha256, $sig, array $chaves ): string {
	if ( empty( $release['ok'] ) || ! isset( $release['tag'], $release['zip']['tamanho'] ) ) {
		return 'versao';
	}

	$nome = consulta_leve_atualizador_nome_zip( (string) $release['tag'] );
	if ( $tamanho !== (int) $release['zip']['tamanho'] ) {
		return 'tamanho';
	}

	$publicado = consulta_leve_atualizador_regra_sha256( $sha256, $nome );
	if ( '' === $publicado || 1 !== preg_match( '/^[0-9a-f]{64}$/D', $hex ) || ! hash_equals( $publicado, $hex ) ) {
		return 'checksum';
	}
	$digest = isset( $release['zip']['digest'] ) ? (string) $release['zip']['digest'] : '';
	if ( '' !== $digest && ! hash_equals( $digest, $hex ) ) {
		return 'checksum';
	}
	if ( ! consulta_leve_atualizador_assinatura_valida( consulta_leve_atualizador_mensagem( (string) $release['tag'], $nome, $hex ), $sig, $chaves ) ) {
		return 'assinatura';
	}

	return '';
}

/**
 * Texto de cada código de erro (tela do WordPress).
 *
 * @param string $codigo Código.
 * @return string
 */
function consulta_leve_atualizador_texto_erro( string $codigo ): string {
	$textos = array(
		'versao'     => __( 'A versão oferecida mudou ou não pôde ser conferida no GitHub da Consulta Leve. Nada foi instalado; tente de novo mais tarde.', 'consulta-leve' ),
		'endereco'   => __( 'O endereço do pacote não é o das versões publicadas da Consulta Leve no GitHub. Nada foi instalado.', 'consulta-leve' ),
		'download'   => __( 'Não foi possível baixar o pacote da Consulta Leve. Nada foi instalado.', 'consulta-leve' ),
		'tamanho'    => __( 'O pacote baixado não tem o tamanho publicado. Nada foi instalado.', 'consulta-leve' ),
		'checksum'   => __( 'O pacote baixado não confere com o checksum publicado. Nada foi instalado.', 'consulta-leve' ),
		'assinatura' => __( 'A assinatura do pacote não confere com as chaves da Consulta Leve. Nada foi instalado.', 'consulta-leve' ),
		'pacote'     => __( 'O pacote não é a Consulta Leve da versão conferida. Nada foi instalado.', 'consulta-leve' ),
		'sodium'     => __( 'Este servidor não tem como conferir a assinatura do pacote (extensão sodium do PHP ausente). Nada foi instalado; peça à hospedagem para ativar a extensão sodium.', 'consulta-leve' ),
	);

	return isset( $textos[ $codigo ] ) ? $textos[ $codigo ] : $textos['download'];
}

/**
 * Erro do atualizador (e aviso `consulta_leve_falha`).
 *
 * @param string $codigo Código.
 * @return \WP_Error
 */
function consulta_leve_atualizador_erro( string $codigo ) {
	do_action( 'consulta_leve_falha', 'atualizacao-' . sanitize_key( $codigo ), new \RuntimeException( 'atualizacao: ' . $codigo ) );

	return new \WP_Error( 'consulta_leve_atualizador_' . sanitize_key( $codigo ), consulta_leve_atualizador_texto_erro( $codigo ) );
}

/* Rede ------------------------------------------------------------------------ */

/**
 * User-Agent sem o endereço do site (o padrão do WordPress leva a URL).
 *
 * @return string
 */
function consulta_leve_atualizador_agente(): string {
	return 'ConsultaLeve/' . ( defined( 'CONSULTA_LEVE_VERSAO' ) ? CONSULTA_LEVE_VERSAO : '0' ) . '; WordPress';
}

/**
 * Lê a última versão publicada (sem cache).
 *
 * @return array Ver consulta_leve_atualizador_regra_release().
 */
function consulta_leve_atualizador_buscar_release(): array {
	$resposta = wp_safe_remote_get(
		CONSULTA_LEVE_ATUALIZADOR_ULTIMA,
		array(
			'timeout'             => 10,
			'redirection'         => 0,
			'user-agent'          => consulta_leve_atualizador_agente(),
			'headers'             => array( 'Accept' => 'application/vnd.github+json' ),
			'limit_response_size' => CONSULTA_LEVE_ATUALIZADOR_LIMITE_API,
		)
	);
	if ( is_wp_error( $resposta ) ) {
		return array(
			'ok'   => false,
			'erro' => 'rede',
		);
	}
	if ( 200 !== (int) wp_remote_retrieve_response_code( $resposta ) ) {
		return array(
			'ok'   => false,
			'erro' => 'http',
		);
	}

	return consulta_leve_atualizador_regra_release( json_decode( (string) wp_remote_retrieve_body( $resposta ), true ) );
}

/**
 * Última versão publicada, com cache.
 *
 * A leitura anônima tem limite por IP (hospedagem compartilhada divide o
 * IP): `$forcar` só lê de novo quando a última leitura boa tem mais de
 * 10 min, e uma falha da leitura mantém a última leitura boa (com até 1 h
 * de idade) em vez de sumir com a oferta. O pacote é conferido por
 * assinatura de qualquer jeito.
 *
 * @param bool $forcar Lê de novo (salvo leitura boa recente).
 * @return array
 */
function consulta_leve_atualizador_release( bool $forcar = false ): array {
	$guardada = get_site_transient( CONSULTA_LEVE_ATUALIZADOR_CACHE );
	$valida   = is_array( $guardada ) && isset( $guardada['ok'] );
	$recente  = $valida && ! empty( $guardada['ok'] ) && isset( $guardada['lido_em'] ) && time() - (int) $guardada['lido_em'] < CONSULTA_LEVE_ATUALIZADOR_RECENTE;
	if ( $valida && ( ! $forcar || $recente ) ) {
		return $guardada;
	}

	try {
		$release = consulta_leve_atualizador_buscar_release();
	} catch ( \Throwable $erro ) {
		do_action( 'consulta_leve_falha', 'atualizacao', $erro );
		$release = array(
			'ok'   => false,
			'erro' => 'rede',
		);
	}
	$release['lido_em'] = time();

	$antiga_vale = $valida && ! empty( $guardada['ok'] ) && isset( $guardada['lido_em'] ) && time() - (int) $guardada['lido_em'] < CONSULTA_LEVE_ATUALIZADOR_TETO_ANTIGA;
	if ( empty( $release['ok'] ) && $antiga_vale ) {
		set_site_transient( CONSULTA_LEVE_ATUALIZADOR_CACHE, $guardada, CONSULTA_LEVE_ATUALIZADOR_CACHE_ERRO );
		return $guardada;
	}
	set_site_transient( CONSULTA_LEVE_ATUALIZADOR_CACHE, $release, $release['ok'] ? CONSULTA_LEVE_ATUALIZADOR_CACHE_OK : CONSULTA_LEVE_ATUALIZADOR_CACHE_ERRO );

	return $release;
}

/**
 * Baixa um arquivo da versão para um arquivo temporário: pede o endereço
 * público sem seguir redirecionamento, confere o destino e o baixa, também
 * sem seguir.
 *
 * @param string $url    Endereço público do arquivo.
 * @param int    $limite Tamanho máximo em bytes.
 * @return string|\WP_Error Caminho do arquivo temporário.
 */
function consulta_leve_atualizador_baixar( string $url, int $limite ) {
	if ( 0 !== strpos( $url, CONSULTA_LEVE_ATUALIZADOR_DOWNLOAD ) ) {
		return consulta_leve_atualizador_erro( 'endereco' );
	}

	$primeira = wp_safe_remote_get(
		$url,
		array(
			'timeout'             => 15,
			'redirection'         => 0,
			'user-agent'          => consulta_leve_atualizador_agente(),
			'limit_response_size' => 65536,
		)
	);
	if ( is_wp_error( $primeira ) || ! in_array( (int) wp_remote_retrieve_response_code( $primeira ), array( 301, 302, 303, 307, 308 ), true ) ) {
		return consulta_leve_atualizador_erro( 'download' );
	}
	$destino = wp_remote_retrieve_header( $primeira, 'location' );
	if ( is_array( $destino ) ) {
		$destino = 1 === count( $destino ) ? reset( $destino ) : '';
	}
	if ( ! consulta_leve_atualizador_regra_destino( $destino ) ) {
		return consulta_leve_atualizador_erro( 'endereco' );
	}

	if ( ! function_exists( 'wp_tempnam' ) ) {
		require_once ABSPATH . 'wp-admin/includes/file.php';
	}
	$arquivo = wp_tempnam( 'consulta-leve-atualizacao' );
	if ( ! is_string( $arquivo ) || '' === $arquivo ) {
		return consulta_leve_atualizador_erro( 'download' );
	}

	$resposta = wp_safe_remote_get(
		(string) $destino,
		array(
			'timeout'             => 60,
			'redirection'         => 0,
			'user-agent'          => consulta_leve_atualizador_agente(),
			'stream'              => true,
			'filename'            => $arquivo,
			'limit_response_size' => $limite + 1,
		)
	);
	clearstatcache( true, $arquivo );
	$tamanho = is_readable( $arquivo ) ? filesize( $arquivo ) : false;
	if ( is_wp_error( $resposta ) || 200 !== (int) wp_remote_retrieve_response_code( $resposta ) || false === $tamanho || $tamanho > $limite ) {
		wp_delete_file( $arquivo );
		return consulta_leve_atualizador_erro( false !== $tamanho && $tamanho > $limite ? 'tamanho' : 'download' );
	}

	return $arquivo;
}

/**
 * Baixa um arquivo pequeno da versão (`.sha256`, `.sig`) e devolve o texto.
 *
 * @param string $url Endereço público.
 * @return string|\WP_Error
 */
function consulta_leve_atualizador_ler_texto( string $url ) {
	$arquivo = consulta_leve_atualizador_baixar( $url, CONSULTA_LEVE_ATUALIZADOR_LIMITE_TEXTO );
	if ( is_wp_error( $arquivo ) ) {
		return $arquivo;
	}

	$texto = file_get_contents( $arquivo ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- arquivo temporário local.
	wp_delete_file( $arquivo );

	return is_string( $texto ) ? $texto : consulta_leve_atualizador_erro( 'download' );
}

/**
 * Baixa o zip da versão e confere tudo (tamanho, SHA-256, assinatura).
 *
 * @param array $release Resultado de consulta_leve_atualizador_release().
 * @return string|\WP_Error Caminho do zip conferido (temporário).
 */
function consulta_leve_atualizador_baixar_conferido( array $release ) {
	if ( empty( $release['ok'] ) ) {
		return consulta_leve_atualizador_erro( 'versao' );
	}

	$sha256 = consulta_leve_atualizador_ler_texto( (string) $release['sha256']['url'] );
	if ( is_wp_error( $sha256 ) ) {
		return $sha256;
	}
	$sig = consulta_leve_atualizador_ler_texto( (string) $release['sig']['url'] );
	if ( is_wp_error( $sig ) ) {
		return $sig;
	}
	$zip = consulta_leve_atualizador_baixar( (string) $release['zip']['url'], CONSULTA_LEVE_ATUALIZADOR_LIMITE );
	if ( is_wp_error( $zip ) ) {
		return $zip;
	}

	$hex     = hash_file( 'sha256', $zip );
	$tamanho = filesize( $zip );
	$codigo  = consulta_leve_atualizador_regra_pacote( is_string( $hex ) ? $hex : '', false === $tamanho ? -1 : (int) $tamanho, $release, $sha256, $sig, consulta_leve_atualizador_chaves() );
	if ( '' !== $codigo ) {
		wp_delete_file( $zip );
		return consulta_leve_atualizador_erro( $codigo );
	}

	return $zip;
}

/**
 * Versão conferida no download em curso. Vale uma vez: a seleção da pasta
 * lê e apaga (consulta_leve_atualizador_consumir_esperada), e todo download novo
 * começa apagando.
 *
 * @param string|null $versao Grava quando informada ('' apaga).
 * @return string
 */
function consulta_leve_atualizador_esperada( $versao = null ): string {
	static $esperada = '';
	if ( is_string( $versao ) ) {
		$esperada = $versao;
	}

	return $esperada;
}

/**
 * Lê e apaga a versão conferida.
 *
 * @return string
 */
function consulta_leve_atualizador_consumir_esperada(): string {
	$esperada = consulta_leve_atualizador_esperada();
	consulta_leve_atualizador_esperada( '' );

	return $esperada;
}

/**
 * Regra pura: a versão foi publicada há pelo menos $espera segundos?
 *
 * @param mixed $data   published_at (AAAA-MM-DDTHH:MM:SSZ).
 * @param int   $agora  Agora (timestamp).
 * @param int   $espera Segundos.
 * @return bool
 */
function consulta_leve_atualizador_regra_espera( $data, int $agora, int $espera ): bool {
	if ( ! is_string( $data ) || 1 !== preg_match( '/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}Z$/D', $data ) ) {
		return false;
	}
	$publicada = strtotime( $data );

	return false !== $publicada && $agora - $publicada >= $espera;
}

/**
 * Prepara e confere a pasta extraída: vira `consulta-leve/`, cabeçalho com
 * `Plugin Name: Consulta Leve` e a versão esperada.
 *
 * @param mixed  $source        Pasta extraída.
 * @param mixed  $remote_source Pasta-mãe da extração.
 * @param string $esperada      Versão conferida.
 * @return mixed Pasta ou WP_Error.
 */
function consulta_leve_atualizador_preparar_pasta( $source, $remote_source, string $esperada ) {
	if ( is_wp_error( $source ) ) {
		return $source;
	}
	if ( ! consulta_leve_atualizador_versao_valida( $esperada ) ) {
		return consulta_leve_atualizador_erro( 'pacote' );
	}

	global $wp_filesystem;

	$source  = trailingslashit( (string) $source );
	$remoto  = trailingslashit( (string) $remote_source );
	$destino = $remoto . CONSULTA_LEVE_ATUALIZADOR_SLUG . '/';
	if ( '' === (string) $remote_source || 0 !== strpos( $source, $remoto ) ) {
		return consulta_leve_atualizador_erro( 'pacote' );
	}
	if ( $source !== $destino ) {
		if ( ! $wp_filesystem || ! $wp_filesystem->move( $source, $destino, true ) ) {
			return consulta_leve_atualizador_erro( 'pacote' );
		}
		$source = $destino;
	}

	$principal = $source . 'consulta-leve.php';
	$dados     = is_readable( $principal ) ? get_file_data(
		$principal,
		array(
			'nome'   => 'Plugin Name',
			'versao' => 'Version',
		)
	) : array();
	$nome      = isset( $dados['nome'] ) ? (string) $dados['nome'] : '';
	$versao    = isset( $dados['versao'] ) ? (string) $dados['versao'] : '';
	if ( CONSULTA_LEVE_ATUALIZADOR_NOME !== $nome || $esperada !== $versao ) {
		return consulta_leve_atualizador_erro( 'pacote' );
	}

	return $source;
}

/* Ganchos do WordPress ------------------------------------------------------------ */

/**
 * update_plugins_github.com: oferece a última versão ao WordPress (ele
 * compara com a instalada e decide entre atualização e "em dia").
 *
 * @param mixed  $update       Resposta anterior.
 * @param mixed  $dados_plugin Cabeçalho do plugin.
 * @param string $arquivo      Arquivo do plugin.
 * @return mixed
 */
function consulta_leve_atualizador_oferta( $update, $dados_plugin = array(), $arquivo = '' ) {
	if ( CONSULTA_LEVE_ATUALIZADOR_PLUGIN !== $arquivo || ! consulta_leve_atualizador_plugin_proprio() ) {
		return $update;
	}

	// Sem como conferir a assinatura, não há o que oferecer.
	if ( ! consulta_leve_atualizador_sodium() ) {
		return $update;
	}

	$release = consulta_leve_atualizador_release();
	if ( empty( $release['ok'] ) ) {
		return $update;
	}

	return array(
		'slug'         => CONSULTA_LEVE_ATUALIZADOR_SLUG,
		'version'      => $release['versao'],
		'url'          => CONSULTA_LEVE_ATUALIZADOR_PAGINA,
		'package'      => $release['zip']['url'] . CONSULTA_LEVE_ATUALIZADOR_MARCA,
		'requires'     => '6.3',
		'requires_php' => '7.4',
		'tested'       => '',
		'icons'        => array(),
		'banners'      => array(),
		'banners_rtl'  => array(),
		'translations' => array(),
	);
}

/**
 * upgrader_pre_download: baixa e confere o pacote marcado.
 *
 * @param mixed $reply      Resposta anterior (false = ninguém baixou).
 * @param mixed $package    Pacote.
 * @param mixed $upgrader   Upgrader.
 * @param mixed $hook_extra Contexto.
 * @return mixed Caminho do zip conferido, WP_Error ou $reply.
 */
function consulta_leve_atualizador_pre_download( $reply, $package, $upgrader = null, $hook_extra = array() ) {
	if ( false !== $reply || ! is_string( $package ) || CONSULTA_LEVE_ATUALIZADOR_MARCA !== substr( $package, -strlen( CONSULTA_LEVE_ATUALIZADOR_MARCA ) ) ) {
		return $reply;
	}

	// Download novo: nenhuma conferência anterior vale para ele.
	consulta_leve_atualizador_esperada( '' );

	try {
		if ( ! consulta_leve_atualizador_sodium() ) {
			return consulta_leve_atualizador_erro( 'sodium' );
		}
		$release = consulta_leve_atualizador_release( true );
		if ( empty( $release['ok'] ) || $release['zip']['url'] . CONSULTA_LEVE_ATUALIZADOR_MARCA !== $package ) {
			return consulta_leve_atualizador_erro( 'versao' );
		}
		if ( defined( 'CONSULTA_LEVE_VERSAO' ) && ! version_compare( $release['versao'], CONSULTA_LEVE_VERSAO, '>' ) ) {
			return consulta_leve_atualizador_erro( 'versao' );
		}

		$zip = consulta_leve_atualizador_baixar_conferido( $release );
		if ( ! is_wp_error( $zip ) ) {
			consulta_leve_atualizador_esperada( $release['versao'] );
		}

		return $zip;
	} catch ( \Throwable $erro ) {
		do_action( 'consulta_leve_falha', 'atualizacao', $erro );
		return consulta_leve_atualizador_erro( 'download' );
	}
}

/**
 * upgrader_source_selection: na atualização da Consulta Leve, só o pacote conferido.
 *
 * @param mixed $source        Pasta extraída.
 * @param mixed $remote_source Pasta-mãe.
 * @param mixed $upgrader      Upgrader.
 * @param mixed $hook_extra    Contexto.
 * @return mixed
 */
function consulta_leve_atualizador_selecionar_pasta( $source, $remote_source, $upgrader = null, $hook_extra = array() ) {
	$plugin = is_array( $hook_extra ) && isset( $hook_extra['plugin'] ) && is_string( $hook_extra['plugin'] ) ? $hook_extra['plugin'] : '';
	if ( CONSULTA_LEVE_ATUALIZADOR_PLUGIN !== $plugin ) {
		return $source;
	}

	$esperada = consulta_leve_atualizador_consumir_esperada();
	if ( '' === $esperada ) {
		return consulta_leve_atualizador_erro( 'pacote' );
	}

	return consulta_leve_atualizador_preparar_pasta( $source, $remote_source, $esperada );
}

/**
 * auto_update_plugin: vale a escolha do site (link "Ativar/Desativar
 * atualizações automáticas" na tela Plugins; a Consulta Leve entra ligada uma vez,
 * consulta_leve_atualizador_auto_padrao). Mesmo ligada, a atualização automática
 * só instala versão publicada há 48 h ou mais. Com
 * `define( 'CONSULTA_LEVE_ATUALIZAR_AUTO', false );` fica sempre desligada.
 *
 * @param mixed $atualizar Decisão do WordPress (null quando só pergunta se é forçada).
 * @param mixed $item      Item da atualização.
 * @return mixed
 */
function consulta_leve_atualizador_auto( $atualizar, $item = null ) {
	$plugin = is_object( $item ) && isset( $item->plugin ) ? $item->plugin : '';
	if ( CONSULTA_LEVE_ATUALIZADOR_PLUGIN !== $plugin ) {
		return $atualizar;
	}
	if ( defined( 'CONSULTA_LEVE_ATUALIZAR_AUTO' ) && false === CONSULTA_LEVE_ATUALIZAR_AUTO ) {
		return false;
	}
	if ( true !== $atualizar ) {
		return $atualizar;
	}

	$release = consulta_leve_atualizador_release();
	if ( empty( $release['ok'] ) || ! consulta_leve_atualizador_regra_espera( isset( $release['data'] ) ? $release['data'] : '', time(), CONSULTA_LEVE_ATUALIZADOR_ESPERA_AUTO ) ) {
		return false;
	}

	return true;
}

/**
 * admin_init: na primeira vez, põe a Consulta Leve na lista de atualização
 * automática do site (opção do WordPress `auto_update_plugins`). Depois, a
 * escolha é de quem administra o site e nunca é desfeita aqui.
 *
 * @return void
 */
function consulta_leve_atualizador_auto_padrao() {
	try {
		if ( '' !== (string) get_site_option( CONSULTA_LEVE_ATUALIZADOR_OPCAO_AUTO, '' ) || ! current_user_can( 'update_plugins' ) ) {
			return;
		}
		if ( defined( 'CONSULTA_LEVE_ATUALIZAR_AUTO' ) && false === CONSULTA_LEVE_ATUALIZAR_AUTO ) {
			return;
		}
		$lista = get_site_option( 'auto_update_plugins', array() );
		$lista = is_array( $lista ) ? $lista : array();
		if ( ! in_array( CONSULTA_LEVE_ATUALIZADOR_PLUGIN, $lista, true ) ) {
			$lista[] = CONSULTA_LEVE_ATUALIZADOR_PLUGIN;
			update_site_option( 'auto_update_plugins', array_values( array_unique( $lista ) ) );
		}
		update_site_option( CONSULTA_LEVE_ATUALIZADOR_OPCAO_AUTO, gmdate( 'c' ) );
	} catch ( \Throwable $erro ) {
		do_action( 'consulta_leve_falha', 'atualizacao-auto', $erro );
	}
}

/**
 * plugins_api: dados do modal "Ver detalhes". Nunca deixa outro diretório
 * responder por este slug.
 *
 * @param mixed  $resultado Resposta anterior.
 * @param string $acao      Ação.
 * @param mixed  $args      Argumentos.
 * @return mixed
 */
function consulta_leve_atualizador_detalhes( $resultado, $acao = '', $args = null ) {
	if ( 'plugin_information' !== $acao || ! is_object( $args ) || ! isset( $args->slug ) || CONSULTA_LEVE_ATUALIZADOR_SLUG !== $args->slug ) {
		return $resultado;
	}

	$release = consulta_leve_atualizador_release();
	$versao  = ! empty( $release['ok'] ) ? $release['versao'] : ( defined( 'CONSULTA_LEVE_VERSAO' ) ? CONSULTA_LEVE_VERSAO : '' );
	$notas   = ! empty( $release['ok'] ) ? $release['notas'] : '';

	$info               = new \stdClass();
	$info->name         = CONSULTA_LEVE_ATUALIZADOR_NOME;
	$info->slug         = CONSULTA_LEVE_ATUALIZADOR_SLUG;
	$info->version      = $versao;
	$info->author       = 'Samuel Neves';
	$info->homepage     = CONSULTA_LEVE_ATUALIZADOR_PAGINA;
	$info->requires     = '6.3';
	$info->requires_php = '7.4';
	$info->last_updated = ! empty( $release['ok'] ) ? $release['data'] : '';
	$info->sections     = array(
		'description' => '<p>' . esc_html__( 'Consultas ao banco do WordPress rápidas e leves: argumentos enxutos, carga em lote, cache versionado, paginação por cursor, tabela de busca própria e diagnóstico, sem conflitar com o tema.', 'consulta-leve' ) . '</p><p>' . esc_html__( 'Código aberto, versões assinadas:', 'consulta-leve' ) . ' <a href="' . esc_url( CONSULTA_LEVE_ATUALIZADOR_PAGINA ) . '">github.com/samuelmarmelo/consulta-leve</a></p>',
		'changelog'   => '' !== $notas ? '<pre>' . esc_html( $notas ) . '</pre>' : '<p>' . esc_html__( 'Sem notas desta versão.', 'consulta-leve' ) . '</p>',
	);

	return $info;
}

/**
 * Painel, Atualizações, "Verificar novamente": lê a versão de novo.
 *
 * @return void
 */
function consulta_leve_atualizador_verificar_agora() {
	// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- só limpa o cache da leitura.
	if ( isset( $_GET['force-check'] ) && current_user_can( 'update_plugins' ) ) {
		delete_site_transient( CONSULTA_LEVE_ATUALIZADOR_CACHE );
	}
}

if ( consulta_leve_atualizador_plugin_proprio() ) {
	add_filter( 'update_plugins_github.com', 'consulta_leve_atualizador_oferta', 10, 3 );
	add_filter( 'upgrader_pre_download', 'consulta_leve_atualizador_pre_download', 10, 4 );
	add_filter( 'upgrader_source_selection', 'consulta_leve_atualizador_selecionar_pasta', 10, 4 );
	add_filter( 'auto_update_plugin', 'consulta_leve_atualizador_auto', 10, 2 );
	add_filter( 'plugins_api', 'consulta_leve_atualizador_detalhes', 20, 3 );
	add_action( 'load-update-core.php', 'consulta_leve_atualizador_verificar_agora' );
	add_action( 'admin_init', 'consulta_leve_atualizador_auto_padrao' );
}
