<?php
/**
 * Perfil de consultas: medir antes de otimizar (contrato, seção 10.1).
 *
 * Porte de CRM interno `core/perfil.php` (Fase 2.50) e loja A
 * `inc/perf/perfil-consultas.php`. Desligado por padrão. Liga só quando a
 * requisição traz `X-Consulta-Leve-Perfil: 1` (resumo) ou `explain` (resumo
 * + EXPLAIN dos SELECT mais caros + ambiente) E o cookie de login é de quem
 * tem `manage_options`. Nenhuma tela envia esse cabeçalho: ele existe para
 * quem mede (console do navegador logado, curl com cookie).
 *
 * Saída: `Server-Timing` e `X-Consulta-Leve-Perfil` (JSON em base64, até
 * 3000 bytes) com as consultas agrupadas por forma (números e textos viram
 * `?`), contagem `n` (N+1 aparece aqui), tempo, quem chamou e, no modo
 * `explain`, os nomes das opções lidas uma a uma (candidatas à carga em
 * lote). SafeWidget: qualquer falha descarta só o perfil; corpo e status da
 * resposta nunca mudam. Nada é gravado no banco.
 *
 * @package ConsultaLeve
 */

defined( 'ABSPATH' ) || exit;

/** Tamanho máximo do JSON do cabeçalho antes do base64 (~4 KB depois). */
const CONSULTA_LEVE_PERFIL_MAX_BYTES = 3000;

/** Formas de consulta no resumo. */
const CONSULTA_LEVE_PERFIL_MAX_FORMAS = 15;

/** Formas SELECT com EXPLAIN no modo explain. */
const CONSULTA_LEVE_PERFIL_MAX_EXPLAIN = 6;

if ( ! function_exists( 'consulta_leve_perfil_modo_pedido' ) ) {

	/**
	 * Modo pedido no cabeçalho: '' (desligado), resumo ou explain.
	 *
	 * @return string
	 */
	function consulta_leve_perfil_modo_pedido(): string {
		$valor = isset( $_SERVER['HTTP_X_CONSULTA_LEVE_PERFIL'] ) ? strtolower( trim( (string) wp_unslash( $_SERVER['HTTP_X_CONSULTA_LEVE_PERFIL'] ) ) ) : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- comparado a uma lista fechada.

		if ( '1' === $valor ) {
			return 'resumo';
		}

		return 'explain' === $valor ? 'explain' : '';
	}

	/**
	 * O perfil vale para esta requisição? Cabeçalho + cookie de administrador.
	 *
	 * @return bool
	 */
	function consulta_leve_perfil_ativo(): bool {
		static $ativo = null;

		if ( null !== $ativo ) {
			return $ativo;
		}

		// Antes do pluggable.php não dá para conferir o cookie: decide depois.
		if ( '' === consulta_leve_perfil_modo_pedido() ) {
			$ativo = false;
			return $ativo;
		}
		if ( ! function_exists( 'wp_validate_auth_cookie' ) ) {
			return false;
		}

		$usuario = (int) wp_validate_auth_cookie( '', 'logged_in' );
		$ativo   = $usuario > 0 && user_can( $usuario, 'manage_options' ) && consulta_leve_ativo( 'perfil' );

		return $ativo;
	}

	/**
	 * Liga o registro de consultas, marca o início e abre o buffer.
	 *
	 * @return void
	 */
	function consulta_leve_perfil_iniciar(): void {
		if ( isset( $GLOBALS['consulta_leve_perfil'] ) || ! consulta_leve_perfil_ativo() ) {
			return;
		}

		global $wpdb;

		if ( ! defined( 'SAVEQUERIES' ) ) {
			define( 'SAVEQUERIES', true );
		}

		$GLOBALS['consulta_leve_perfil'] = array(
			'inicio_em'      => microtime( true ),
			'boot_consultas' => isset( $wpdb->num_queries ) ? (int) $wpdb->num_queries : 0,
			'ja_salvas'      => is_array( $wpdb->queries ?? null ) ? count( $wpdb->queries ) : 0,
			'buffer'         => ob_start(),
			'tetos'          => 0,
		);

		add_action(
			'consulta_leve_teto_aplicado',
			static function () {
				++$GLOBALS['consulta_leve_perfil']['tetos'];
			}
		);
	}

	/**
	 * A versão medida nunca vai para cache de página.
	 *
	 * @return void
	 */
	function consulta_leve_perfil_sem_cache(): void {
		if ( isset( $GLOBALS['consulta_leve_perfil'] ) && ! headers_sent() ) {
			nocache_headers();
		}
	}

	/**
	 * Forma da consulta: números, textos e listas IN viram `?`.
	 *
	 * @param string $sql SQL.
	 * @return string
	 */
	function consulta_leve_perfil_forma( string $sql ): string {
		$forma = preg_replace( "/'(?:[^'\\\\]|\\\\.)*'/s", '?', $sql );
		$forma = preg_replace( '/\b\d+(?:\.\d+)?\b/', '?', (string) $forma );
		$forma = preg_replace( '/\(\s*\?(?:\s*,\s*\?)*\s*\)/', '(?)', (string) $forma );
		$forma = preg_replace( '/\s+/', ' ', (string) $forma );

		return trim( (string) $forma );
	}

	/**
	 * Prefixos de função reconhecidos como "quem chamou".
	 *
	 * @return string[]
	 */
	function consulta_leve_perfil_prefixos(): array {
		$prefixos = array( 'consulta_leve_' );
		if ( function_exists( 'get_stylesheet' ) ) {
			$tema = strtolower( (string) preg_replace( '/[^a-z0-9]+/i', '_', (string) get_stylesheet() ) );
			if ( '' !== trim( $tema, '_' ) ) {
				$prefixos[] = trim( $tema, '_' ) . '_';
			}
		}

		/**
		 * Filtra os prefixos de função que o perfil mostra como "quem chamou".
		 *
		 * @param string[] $prefixos Prefixos.
		 */
		$filtrados = apply_filters( 'consulta_leve_perfil_prefixos', $prefixos );

		return is_array( $filtrados ) ? array_values( array_filter( $filtrados, 'is_string' ) ) : $prefixos;
	}

	/**
	 * Primeira função com prefixo conhecido na pilha (ou a mais interna).
	 *
	 * @param string   $pilha    Texto do wpdb ("require, do_action, fn1, fn2").
	 * @param string[] $prefixos Prefixos.
	 * @return string
	 */
	function consulta_leve_perfil_quem( string $pilha, array $prefixos ): string {
		$partes = array_reverse( array_map( 'trim', explode( ',', $pilha ) ) );

		foreach ( $partes as $parte ) {
			foreach ( $prefixos as $prefixo ) {
				if ( 0 === strpos( $parte, $prefixo ) ) {
					return substr( $parte, 0, 80 );
				}
			}
		}

		// Sem prefixo conhecido: a mais interna que não seja o próprio banco
		// (wpdb, driver do SQLite) nem a maquinaria de ganchos e includes.
		foreach ( $partes as $parte ) {
			if ( '' !== $parte && 1 !== preg_match( '/^(?:wpdb->|WP_SQLite_|WP_Hook->|require|include|do_action|apply_filters|call_user_func)/', $parte ) ) {
				return substr( $parte, 0, 80 );
			}
		}

		return substr( (string) ( $partes[0] ?? '' ), 0, 80 );
	}

	/**
	 * Consultas guardadas desde o início do perfil, agrupadas por forma.
	 *
	 * @param array $lista Itens de $wpdb->queries (sql, tempo, pilha).
	 * @return array{total:int,ms:float,formas:array,opcoes:array}
	 */
	function consulta_leve_perfil_agrupar( array $lista ): array {
		$prefixos = consulta_leve_perfil_prefixos();
		$grupos   = array();
		$opcoes   = array();
		$ms       = 0.0;

		foreach ( $lista as $q ) {
			$sql   = (string) ( $q[0] ?? '' );
			$tempo = (float) ( $q[1] ?? 0 ) * 1000;
			$forma = consulta_leve_perfil_forma( $sql );
			$chave = md5( $forma );
			$ms   += $tempo;

			// Opção lida sozinha: candidata à carga em lote (opcoes_lote).
			if ( preg_match( "/^SELECT option_value FROM \S+ WHERE option_name = '([A-Za-z0-9_.:-]{1,191})' LIMIT 1$/", trim( $sql ), $m ) ) {
				$opcoes[ $m[1] ] = ( $opcoes[ $m[1] ] ?? 0 ) + 1;
			}

			if ( ! isset( $grupos[ $chave ] ) ) {
				$grupos[ $chave ] = array(
					'forma'   => $forma,
					'n'       => 0,
					'ms'      => 0.0,
					'quem'    => consulta_leve_perfil_quem( (string) ( $q[2] ?? '' ), $prefixos ),
					'exemplo' => $sql,
				);
			}

			++$grupos[ $chave ]['n'];
			$grupos[ $chave ]['ms'] += $tempo;
		}

		usort(
			$grupos,
			static function ( $a, $b ) {
				return $b['ms'] <=> $a['ms'];
			}
		);
		arsort( $opcoes );

		return array(
			'total'  => count( $lista ),
			'ms'     => round( $ms, 1 ),
			'formas' => $grupos,
			'opcoes' => array_slice( array_keys( $opcoes ), 0, 15 ),
		);
	}

	/**
	 * EXPLAIN de um SELECT que o próprio WordPress rodou (nunca SQL de fora).
	 *
	 * @param string $sql SQL original.
	 * @return array|null
	 */
	function consulta_leve_perfil_explain( string $sql ): ?array {
		global $wpdb;

		if ( 1 !== preg_match( '/^\s*SELECT\b/i', $sql ) || false !== strpos( $sql, ';' ) ) {
			return null;
		}

		$linhas = $wpdb->get_results( 'EXPLAIN ' . $sql, ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- SQL já executado nesta requisição; só SELECT.
		if ( ! is_array( $linhas ) ) {
			return null;
		}

		return array_map(
			static function ( $l ) {
				return array(
					'tabela' => (string) ( $l['table'] ?? '' ),
					'tipo'   => (string) ( $l['type'] ?? '' ),
					'chave'  => (string) ( $l['key'] ?? '' ),
					'linhas' => (int) ( $l['rows'] ?? 0 ),
					'extra'  => substr( (string) ( $l['Extra'] ?? '' ), 0, 80 ),
				);
			},
			array_slice( $linhas, 0, 8 )
		);
	}

	/**
	 * Ambiente do banco e do PHP (modo explain).
	 *
	 * @return array
	 */
	function consulta_leve_perfil_ambiente(): array {
		global $wpdb;

		$autoload = $wpdb->get_row( "SELECT COUNT(*) AS n, COALESCE(SUM(LENGTH(option_value)),0) AS bytes FROM {$wpdb->options} WHERE autoload IN ('yes','on','auto-on','auto')", ARRAY_A );

		$t0 = microtime( true );
		$wpdb->get_var( 'SELECT 1' );
		$ida_volta = round( ( microtime( true ) - $t0 ) * 1000, 2 );

		return array(
			'banco_ida_volta_ms' => $ida_volta,
			'php'                => PHP_VERSION,
			'banco'              => method_exists( $wpdb, 'db_server_info' ) ? (string) $wpdb->db_server_info() : '',
			'cache_objetos'      => (bool) wp_using_ext_object_cache(),
			'opcache'            => function_exists( 'opcache_get_status' ) && is_array( @opcache_get_status( false ) ), // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- API restrita avisa; aqui só importa sim/não.
			'autoload_n'         => (int) ( $autoload['n'] ?? 0 ),
			'autoload_kb'        => round( (int) ( $autoload['bytes'] ?? 0 ) / 1024, 1 ),
		);
	}

	/**
	 * Resumo do perfil desta requisição.
	 *
	 * @return array
	 */
	function consulta_leve_perfil_resumo(): array {
		global $wpdb;

		$p      = $GLOBALS['consulta_leve_perfil'];
		$agora  = microtime( true );
		$inicio = isset( $_SERVER['REQUEST_TIME_FLOAT'] ) ? (float) $_SERVER['REQUEST_TIME_FLOAT'] : $p['inicio_em'];
		$lista  = is_array( $wpdb->queries ?? null ) ? array_slice( $wpdb->queries, (int) $p['ja_salvas'] ) : array();
		$grupos = consulta_leve_perfil_agrupar( $lista );
		$modo   = consulta_leve_perfil_modo_pedido();

		$resumo = array(
			'v'              => 1,
			'modo'           => $modo,
			'total_ms'       => round( ( $agora - $inicio ) * 1000, 1 ),
			'boot_ms'        => round( ( $p['inicio_em'] - $inicio ) * 1000, 1 ),
			'boot_consultas' => (int) $p['boot_consultas'],
			'consultas'      => (int) $wpdb->num_queries,
			'banco_ms'       => $grupos['ms'],
			'memoria_mb'     => round( memory_get_peak_usage( true ) / 1048576, 1 ),
			'tetos'          => (int) $p['tetos'],
			'formas'         => array(),
		);

		$explicar = 'explain' === $modo;
		$feitos   = 0;

		foreach ( array_slice( $grupos['formas'], 0, CONSULTA_LEVE_PERFIL_MAX_FORMAS ) as $g ) {
			$linha = array(
				'n'     => $g['n'],
				'ms'    => round( $g['ms'], 1 ),
				'quem'  => $g['quem'],
				'forma' => substr( $g['forma'], 0, 220 ),
			);

			if ( $explicar && $feitos < CONSULTA_LEVE_PERFIL_MAX_EXPLAIN ) {
				$plano = consulta_leve_perfil_explain( $g['exemplo'] );
				if ( null !== $plano ) {
					$linha['plano'] = $plano;
					++$feitos;
				}
			}

			$resumo['formas'][] = $linha;
		}

		if ( $explicar ) {
			$resumo['ambiente']       = consulta_leve_perfil_ambiente();
			$resumo['opcoes_avulsas'] = $grupos['opcoes'];
		}

		return $resumo;
	}

	/**
	 * JSON do resumo dentro do limite, tirando formas do fim (as mais baratas).
	 *
	 * @param array $resumo Resumo.
	 * @return string
	 */
	function consulta_leve_perfil_json( array $resumo ): string {
		$json = (string) wp_json_encode( $resumo );
		while ( strlen( $json ) > CONSULTA_LEVE_PERFIL_MAX_BYTES && $resumo['formas'] ) {
			array_pop( $resumo['formas'] );
			$json = (string) wp_json_encode( $resumo );
		}
		if ( strlen( $json ) > CONSULTA_LEVE_PERFIL_MAX_BYTES ) {
			unset( $resumo['opcoes_avulsas'], $resumo['ambiente'] );
			$json = (string) wp_json_encode( $resumo );
		}

		return $json;
	}

	/**
	 * Cabeçalhos do perfil. Prioridade 0 do shutdown: antes do
	 * wp_ob_end_flush_all() do núcleo, com os cabeçalhos ainda abertos.
	 *
	 * @return void
	 */
	function consulta_leve_perfil_enviar(): void {
		if ( ! isset( $GLOBALS['consulta_leve_perfil'] ) ) {
			return;
		}

		try {
			if ( ! headers_sent() && current_user_can( 'manage_options' ) ) {
				$resumo = consulta_leve_perfil_resumo();
				header( sprintf( 'Server-Timing: total;dur=%.1f, banco;dur=%.1f;desc="%d consultas"', $resumo['total_ms'], $resumo['banco_ms'], $resumo['consultas'] ) );
				header( 'X-Consulta-Leve-Perfil: ' . base64_encode( consulta_leve_perfil_json( $resumo ) ) ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- transporte de JSON num cabeçalho HTTP.
				header( 'Cache-Control: no-store, private' );
			}
		} catch ( \Throwable $erro ) {
			do_action( 'consulta_leve_falha', 'perfil', $erro );
		}
	}

	// O pluggable.php (cookie de login) só existe depois dos plugins: o perfil
	// começa no primeiro gancho em que dá para conferir quem pede.
	if ( did_action( 'plugins_loaded' ) ) {
		consulta_leve_perfil_iniciar();
	} else {
		add_action( 'plugins_loaded', 'consulta_leve_perfil_iniciar', -9999 );
	}
	add_action( 'send_headers', 'consulta_leve_perfil_sem_cache' );
	add_action( 'shutdown', 'consulta_leve_perfil_enviar', 0 );
}
