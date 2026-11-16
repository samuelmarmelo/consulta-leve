<?php
/**
 * Chaves públicas que assinam as versões publicadas da Consulta Leve (Ed25519, 32
 * bytes em hex minúsculo). Só versão assinada por uma destas chaves é
 * instalada pelo atualizador (atualizacao/atualizador.php).
 *
 * Troca de chave (docs/RELEASE.md, seção 3): uma versão assinada pela chave
 * antiga traz a nova nesta lista; a seguinte já sai assinada pela nova e
 * tira a antiga. Sem filtro de propósito: nenhum código de fora acrescenta
 * chave.
 *
 * @package ConsultaLeve
 */

defined( 'ABSPATH' ) || exit;

if ( ! function_exists( 'consulta_leve_atualizador_chaves' ) ) {

	/**
	 * Chaves públicas confiáveis.
	 *
	 * @return string[] Hex minúsculo, 64 caracteres cada.
	 */
	function consulta_leve_atualizador_chaves(): array {
		return array(
			// Chave 1, criada em 2026-11-16.
			'eb752056fef94ab7ab7af06891737d25799471c866aaa2f0efee57d8ba8adb87',
		);
	}
}
