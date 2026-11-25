<?php
/**
 * Plugin Name:       Consulta Leve
 * Plugin URI:        https://github.com/samuelmarmelo/consulta-leve
 * Description:       Consultas leves ao banco do WordPress para sites com milhares de posts: cache versionado, paginação por cursor, índices conferidos, busca enxuta e diagnóstico, sem conflitar com o que o tema já faz.
 * Version:           0.2.0
 * Requires at least: 6.3
 * Requires PHP:      7.4
 * Author:            Samuel Neves
 * Author URI:        https://samuelneves.com.br/
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       consulta-leve
 * Update URI:        https://github.com/samuelmarmelo/consulta-leve
 *
 * Base do plugin (contrato docs/CONTRATO.md, seção 3): constantes e
 * carregador dos módulos.
 *
 * A mesma pasta funciona como plugin próprio ou embutida em outro plugin ou
 * tema, que inclui este arquivo. A primeira cópia que carregar vence (guarda
 * CONSULTA_LEVE_VERSAO); a outra fica parada, sem erro.
 *
 * As funções deste arquivo ficam dentro de um `if`: declaradas no topo, o
 * PHP as registraria antes da guarda e a segunda cópia daria erro fatal.
 *
 * @package ConsultaLeve
 */

defined( 'ABSPATH' ) || exit;

// Outra cópia já carregou: esta não carrega.
if ( defined( 'CONSULTA_LEVE_VERSAO' ) ) {
	return;
}

define( 'CONSULTA_LEVE_VERSAO', '0.2.0' );
define( 'CONSULTA_LEVE_ARQUIVO', __FILE__ );
define( 'CONSULTA_LEVE_DIR', __DIR__ . '/' );

if ( ! function_exists( 'consulta_leve_modulos' ) ) {

	/**
	 * Módulos, na ordem de carga (caminhos a partir desta pasta). O que
	 * ainda não existe é pulado.
	 *
	 * @return string[]
	 */
	function consulta_leve_modulos(): array {
		$modulos = array(
			'nucleo/config.php',
			'nucleo/coexistencia.php',
			'nucleo/trava.php',
			'nucleo/cache.php',
			'nucleo/registro.php',
			'diagnostico/perfil.php',
			'consultas/argumentos.php',
			'consultas/primar.php',
			'consultas/cursor.php',
			'consultas/contagem.php',
			'consultas/lotes.php',
			'indices/indices.php',
			'indices/busca.php',
			'busca/busca.php',
			'rest/rest.php',
			'diagnostico/diagnostico.php',
			'diagnostico/monitor.php',
			'atualizacao/chaves.php',
			'atualizacao/atualizador.php',
			'cron/cron.php',
			'seguranca/cabecalhos.php',
			'seguranca/ensaio.php',
			'seguranca/htaccess.php',
		);
		if ( is_admin() ) {
			$modulos[] = 'admin/admin.php';
		}
		if ( defined( 'WP_CLI' ) && WP_CLI ) {
			$modulos[] = 'cli/cli.php';
		}

		/**
		 * Filtra a lista de módulos da Consulta Leve.
		 *
		 * @param string[] $modulos Caminhos a partir da pasta do plugin.
		 */
		$filtrados = apply_filters( 'consulta_leve_modulos', $modulos );

		return is_array( $filtrados ) ? array_values( array_filter( $filtrados, 'is_string' ) ) : $modulos;
	}

	/**
	 * Carrega um módulo. SafeWidget: arquivo ausente é pulado e erro no
	 * módulo vira a ação `consulta_leve_falha`, sem derrubar o site.
	 *
	 * @param string $modulo Caminho a partir da pasta (ex.: nucleo/config.php).
	 * @param string $pasta  Pasta de origem, com barra final.
	 * @return bool Carregou?
	 */
	function consulta_leve_carregar_arquivo( string $modulo, string $pasta = CONSULTA_LEVE_DIR ): bool {
		// Só caminhos simples dentro da pasta (sem "..", sem barra inicial).
		if ( 1 !== preg_match( '#^[a-z0-9_-]+(?:/[a-z0-9_-]+)*\.php$#D', $modulo ) ) {
			return false;
		}

		$caminho = $pasta . $modulo;
		if ( ! is_readable( $caminho ) ) {
			return false;
		}

		try {
			require_once $caminho;
		} catch ( \Throwable $erro ) {
			/**
			 * Um módulo falhou ao carregar ou ao rodar.
			 *
			 * @param string     $modulo Módulo ou ponto da falha.
			 * @param \Throwable $erro   Erro capturado.
			 */
			do_action( 'consulta_leve_falha', $modulo, $erro );
			return false;
		}

		return true;
	}

	/**
	 * O núcleo carregou (funções de que todos os módulos dependem)?
	 *
	 * @return bool
	 */
	function consulta_leve_nucleo_pronto(): bool {
		foreach ( array( 'consulta_leve_opcao', 'consulta_leve_ativo', 'consulta_leve_travar', 'consulta_leve_lembrar' ) as $funcao ) {
			if ( ! function_exists( $funcao ) ) {
				return false;
			}
		}

		return true;
	}

	/**
	 * Carrega os módulos e avisa (`consulta_leve_carregado`).
	 *
	 * @return void
	 */
	function consulta_leve_carregar(): void {
		$avisado = false;

		foreach ( consulta_leve_modulos() as $modulo ) {
			// Sem o núcleo (configuração, coexistência, trava e cache), os demais
			// módulos não carregam: melhor parado que fatal em toda página.
			if ( 0 !== strpos( $modulo, 'nucleo/' ) && ! consulta_leve_nucleo_pronto() ) {
				if ( ! $avisado ) {
					$avisado = true;
					do_action( 'consulta_leve_falha', 'nucleo', new \RuntimeException( 'núcleo incompleto: módulos parados' ) );
				}
				continue;
			}
			consulta_leve_carregar_arquivo( $modulo );
		}

		/** A Consulta Leve terminou de carregar os módulos. */
		do_action( 'consulta_leve_carregado' );
	}
}

consulta_leve_carregar();
