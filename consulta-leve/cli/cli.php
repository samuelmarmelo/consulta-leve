<?php
/**
 * Comandos WP-CLI (contrato, seção 13).
 *
 *   wp consulta-leve diagnostico
 *   wp consulta-leve indices listar
 *   wp consulta-leve indices criar <id> [--confirmar]
 *   wp consulta-leve indices remover <id>
 *   wp consulta-leve busca status
 *   wp consulta-leve busca reconstruir
 *
 * @package ConsultaLeve
 */

defined( 'ABSPATH' ) || exit;

if ( defined( 'WP_CLI' ) && WP_CLI && class_exists( 'WP_CLI' ) && ! function_exists( 'consulta_leve_cli_diagnostico' ) ) {

	/**
	 * Diagnóstico com recomendações.
	 *
	 * @return void
	 */
	function consulta_leve_cli_diagnostico(): void {
		$d = consulta_leve_diagnostico( true );

		\WP_CLI::log( sprintf( 'PHP %s, WordPress %s, %s %s', $d['php'], $d['wordpress'], $d['motor'], $d['banco'] ) );
		\WP_CLI::log( sprintf( 'Cache de objetos: %s', $d['cache']['externo'] ? 'persistente, ' . $d['cache']['ms'] . ' ms por leitura' : 'só da requisição' ) );
		\WP_CLI::log( sprintf( 'Autoload: %d opções, %s KB', $d['autoload']['n'], $d['autoload']['kb'] ) );
		foreach ( $d['tabelas'] as $nome => $t ) {
			\WP_CLI::log( sprintf( '  %-10s %10d linhas %8.1f MB', $nome, $t['linhas'], $t['mb'] ) );
		}
		foreach ( $d['recomendacoes'] as $r ) {
			\WP_CLI::log( ( 'alerta' === $r['nivel'] ? 'ALERTA: ' : 'Dica: ' ) . $r['texto'] );
		}
		\WP_CLI::success( 'Diagnóstico medido.' );
	}

	/**
	 * Índices: listar, criar ou remover.
	 *
	 * @param array $args  Argumentos (operação e ID).
	 * @param array $assoc Opções (--confirmar).
	 * @return void
	 */
	function consulta_leve_cli_indices( array $args, array $assoc ): void {
		$operacao = $args[0] ?? 'listar';
		$id       = isset( $args[1] ) ? sanitize_key( $args[1] ) : '';

		if ( 'listar' === $operacao ) {
			$linhas = array();
			foreach ( consulta_leve_indices_situacao() as $cid => $s ) {
				$linhas[] = array(
					'id'     => $cid,
					'nome'   => $s['nome'],
					'tabela' => $s['tabela'],
					'linhas' => $s['linhas'],
					'estado' => $s['estado'],
				);
			}
			\WP_CLI\Utils\format_items( 'table', $linhas, array( 'id', 'nome', 'tabela', 'linhas', 'estado' ) );
			return;
		}

		if ( ! in_array( $operacao, array( 'criar', 'remover' ), true ) || '' === $id ) {
			\WP_CLI::error( 'Uso: wp consulta-leve indices listar | criar <id> [--confirmar] | remover <id>' );
		}

		$resultado = 'criar' === $operacao ? consulta_leve_indices_criar( $id, ! empty( $assoc['confirmar'] ) ) : consulta_leve_indices_remover( $id );
		if ( 'ok' === $resultado ) {
			\WP_CLI::success( sprintf( 'Índice %s: %s.', $id, 'criar' === $operacao ? 'criado' : 'removido' ) );
		} else {
			\WP_CLI::error( sprintf( 'Índice %s: %s. Nada mudou.', $id, $resultado ) );
		}
	}

	/**
	 * Tabela de busca: status ou reconstruir (aqui mesmo, até o fim).
	 *
	 * @param array $args Argumentos (operação).
	 * @return void
	 */
	function consulta_leve_cli_busca( array $args ): void {
		$operacao = $args[0] ?? 'status';

		if ( 'status' === $operacao ) {
			\WP_CLI::log( 'Estado: ' . consulta_leve_busca_estado() );
			\WP_CLI::log( 'Tipos: ' . implode( ', ', consulta_leve_busca_tipos() ) );
			\WP_CLI::log( 'FULLTEXT: ' . ( '1' === (string) get_option( 'consulta_leve_busca_fulltext', '0' ) ? 'sim' : 'não' ) );
			return;
		}

		if ( 'reconstruir' !== $operacao ) {
			\WP_CLI::error( 'Uso: wp consulta-leve busca status | reconstruir' );
		}
		if ( ! consulta_leve_busca_ligada() ) {
			\WP_CLI::error( 'Ligue a tabela de busca e escolha os tipos nas configurações.' );
		}
		if ( (int) get_option( 'consulta_leve_busca_esquema', 0 ) !== CONSULTA_LEVE_BUSCA_ESQUEMA && ! consulta_leve_busca_instalar() ) {
			\WP_CLI::error( 'Não foi possível criar a tabela de busca.' );
		}

		$geracao = consulta_leve_busca_reconstruir_iniciar();
		$cursor  = 0;
		$parado  = 0;
		do {
			$estado = consulta_leve_busca_lote( $geracao, $cursor, 60 );
			$novo   = (int) ( $estado['cursor'] ?? 0 );
			$parado = $novo === $cursor ? $parado + 1 : 0;
			$cursor = $novo;
			\WP_CLI::log( sprintf( '%d posts gravados', (int) ( $estado['feitos'] ?? 0 ) ) );
			if ( $parado >= 3 && ! empty( $estado['geracao'] ) ) {
				\WP_CLI::error( 'A reconstrução não avança (outro lote rodando ou erro). Ela segue pelo cron; confira com: wp consulta-leve busca status' );
			}
		} while ( ! empty( $estado['geracao'] ) );

		\WP_CLI::success( 'Tabela de busca pronta.' );
	}

	\WP_CLI::add_command( 'consulta-leve diagnostico', 'consulta_leve_cli_diagnostico' );
	\WP_CLI::add_command( 'consulta-leve indices', 'consulta_leve_cli_indices' );
	\WP_CLI::add_command( 'consulta-leve busca', 'consulta_leve_cli_busca' );
}
