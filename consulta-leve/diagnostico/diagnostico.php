<?php
/**
 * Diagnóstico do banco e do ambiente (contrato, seção 10.2). Só leitura.
 *
 * Junta num lugar o que mais pesa nas consultas de um site grande: cache de
 * objetos (inclusive mal configurado, como o Redis sem servidor que custou
 * 1 s por pedido no CRM interno), opções com autoload, tamanho das tabelas,
 * revisões, transients vencidos, cron e OPcache, com recomendações em texto.
 * Nada é apagado nem alterado.
 *
 * @package ConsultaLeve
 */

defined( 'ABSPATH' ) || exit;

/** Latência média do cache de objetos acima disto (ms) vira alerta. */
const CONSULTA_LEVE_DIAG_CACHE_LENTO_MS = 5.0;

/** Autoload acima disto (KB) vira alerta (mesmo limite da Saúde do Site). */
const CONSULTA_LEVE_DIAG_AUTOLOAD_KB = 800;

if ( ! function_exists( 'consulta_leve_diagnostico' ) ) {

	/**
	 * Mede o cache de objetos: 20 idas e voltas. Só faz sentido com cache
	 * externo (sem ele, o cache é a memória da própria requisição).
	 *
	 * @return array{externo:bool,dropin:bool,ms:float}
	 */
	function consulta_leve_diag_cache(): array {
		$externo = (bool) wp_using_ext_object_cache();
		$dropin  = defined( 'WP_CONTENT_DIR' ) && is_file( WP_CONTENT_DIR . '/object-cache.php' );
		$ms      = 0.0;

		if ( $externo ) {
			$chave = 'teste_' . wp_rand( 1, 999999 );
			$t0    = microtime( true );
			for ( $i = 0; $i < 20; $i++ ) {
				wp_cache_set( $chave, $i, 'consulta_leve_teste', 30 );
				wp_cache_get( $chave, 'consulta_leve_teste', true );
			}
			wp_cache_delete( $chave, 'consulta_leve_teste' );
			$ms = round( ( microtime( true ) - $t0 ) * 1000 / 20, 2 );
		}

		return array(
			'externo' => $externo,
			'dropin'  => $dropin,
			'ms'      => $ms,
		);
	}

	/**
	 * Opções com autoload: quantidade, KB e as 10 maiores.
	 *
	 * @return array{n:int,kb:float,maiores:array}
	 */
	function consulta_leve_diag_autoload(): array {
		global $wpdb;

		$soma    = $wpdb->get_row( "SELECT COUNT(*) AS n, COALESCE(SUM(LENGTH(option_value)),0) AS bytes FROM {$wpdb->options} WHERE autoload IN ('yes','on','auto-on','auto')", ARRAY_A );
		$maiores = $wpdb->get_results( "SELECT option_name, LENGTH(option_value) AS bytes FROM {$wpdb->options} WHERE autoload IN ('yes','on','auto-on','auto') ORDER BY bytes DESC LIMIT 10", ARRAY_A );

		return array(
			'n'       => (int) ( $soma['n'] ?? 0 ),
			'kb'      => round( (int) ( $soma['bytes'] ?? 0 ) / 1024, 1 ),
			'maiores' => array_map(
				static function ( $o ) {
					return array(
						'nome' => (string) $o['option_name'],
						'kb'   => round( (int) $o['bytes'] / 1024, 1 ),
					);
				},
				is_array( $maiores ) ? $maiores : array()
			),
		);
	}

	/**
	 * Linhas e tamanho das tabelas do núcleo (estatística do banco).
	 *
	 * @return array<string,array{linhas:int,mb:float}>
	 */
	function consulta_leve_diag_tabelas(): array {
		global $wpdb;

		$nomes = array(
			'posts'    => $wpdb->posts,
			'postmeta' => $wpdb->postmeta,
			'options'  => $wpdb->options,
			'comments' => $wpdb->comments,
			'termmeta' => $wpdb->termmeta,
			'usermeta' => $wpdb->usermeta,
		);
		$saida = array();

		if ( 'sqlite' === consulta_leve_diag_motor() ) {
			return $saida;
		}

		foreach ( $nomes as $curto => $tabela ) {
			$l = $wpdb->get_row(
				$wpdb->prepare( 'SELECT TABLE_ROWS AS linhas, DATA_LENGTH + INDEX_LENGTH AS bytes FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = %s', $tabela ),
				ARRAY_A
			);
			$saida[ $curto ] = array(
				'linhas' => (int) ( $l['linhas'] ?? 0 ),
				'mb'     => round( (int) ( $l['bytes'] ?? 0 ) / 1048576, 1 ),
			);
		}

		return $saida;
	}

	/**
	 * Motor do banco.
	 *
	 * @return string mysql, mariadb ou sqlite.
	 */
	function consulta_leve_diag_motor(): string {
		if ( function_exists( 'consulta_leve_indices_motor' ) ) {
			return consulta_leve_indices_motor();
		}

		return ( defined( 'DB_ENGINE' ) && 'sqlite' === DB_ENGINE ) ? 'sqlite' : 'mysql';
	}

	/**
	 * Contagens que pesam: revisões, rascunhos automáticos, lixeira e
	 * transients vencidos.
	 *
	 * @return array<string,int>
	 */
	function consulta_leve_diag_contagens(): array {
		global $wpdb;

		$tipos = $wpdb->get_results( "SELECT post_type, post_status, COUNT(*) AS n FROM {$wpdb->posts} WHERE post_type = 'revision' OR post_status IN ('auto-draft','trash') GROUP BY post_type, post_status", ARRAY_A );
		$saida = array(
			'revisoes'   => 0,
			'rascunhos'  => 0,
			'lixeira'    => 0,
			'transients' => 0,
		);

		foreach ( is_array( $tipos ) ? $tipos : array() as $l ) {
			if ( 'revision' === $l['post_type'] ) {
				$saida['revisoes'] += (int) $l['n'];
			} elseif ( 'auto-draft' === $l['post_status'] ) {
				$saida['rascunhos'] += (int) $l['n'];
			} elseif ( 'trash' === $l['post_status'] ) {
				$saida['lixeira'] += (int) $l['n'];
			}
		}

		$saida['transients'] = (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$wpdb->options} WHERE option_name LIKE %s AND CAST(option_value AS UNSIGNED) < %d",
				$wpdb->esc_like( '_transient_timeout_' ) . '%',
				time()
			)
		);

		return $saida;
	}

	/**
	 * Recomendações a partir do diagnóstico (regra pura).
	 *
	 * @param array $d Diagnóstico sem as recomendações.
	 * @return array<int,array{nivel:string,texto:string}>
	 */
	function consulta_leve_diag_recomendacoes( array $d ): array {
		$r = array();

		$alerta = static function ( $texto ) use ( &$r ) {
			$r[] = array(
				'nivel' => 'alerta',
				'texto' => $texto,
			);
		};
		$dica   = static function ( $texto ) use ( &$r ) {
			$r[] = array(
				'nivel' => 'dica',
				'texto' => $texto,
			);
		};

		$posts    = (int) ( $d['tabelas']['posts']['linhas'] ?? 0 );
		$postmeta = (int) ( $d['tabelas']['postmeta']['linhas'] ?? 0 );

		if ( $d['cache']['externo'] && $d['cache']['ms'] > CONSULTA_LEVE_DIAG_CACHE_LENTO_MS ) {
			/* translators: %s: milissegundos por leitura. */
			$alerta( sprintf( __( 'O cache de objetos responde em %s ms por leitura. Cache saudável fica abaixo de 1 ms: confira se o servidor Redis ou Memcached está no ar; cache quebrado deixa cada página mais lenta que sem cache.', 'consulta-leve' ), number_format_i18n( $d['cache']['ms'], 2 ) ) );
		}
		if ( $d['cache']['dropin'] && ! $d['cache']['externo'] ) {
			$alerta( __( 'Existe wp-content/object-cache.php, mas o cache de objetos não está ativo. Pode ser resto de um plugin removido: confira com a hospedagem antes de apagar.', 'consulta-leve' ) );
		}
		if ( ! $d['cache']['externo'] && ( $posts > 50000 || $postmeta > 500000 ) ) {
			$dica( __( 'Com este volume, um cache de objetos persistente (Redis ou Memcached da hospedagem) evita repetir as mesmas consultas a cada página.', 'consulta-leve' ) );
		}
		if ( $d['autoload']['kb'] > CONSULTA_LEVE_DIAG_AUTOLOAD_KB ) {
			/* translators: %s: tamanho em KB. */
			$alerta( sprintf( __( 'As opções com autoload somam %s KB e são lidas em toda página. Veja as maiores abaixo: opção de plugin desativado ou log grande não deveria carregar sempre.', 'consulta-leve' ), number_format_i18n( $d['autoload']['kb'], 1 ) ) );
		}
		if ( ! $d['opcache'] ) {
			$alerta( __( 'OPcache desligado: o PHP recompila os arquivos a cada pedido. Peça à hospedagem para ligar.', 'consulta-leve' ) );
		}
		if ( $d['contagens']['revisoes'] > 5000 && ( true === $d['revisoes_limite'] || -1 === $d['revisoes_limite'] ) ) {
			$dica( __( 'Há muitas revisões e o limite está aberto. Um limite no wp-config.php (por exemplo define( \'WP_POST_REVISIONS\', 10 );) segura o crescimento de wp_posts.', 'consulta-leve' ) );
		}
		$cron = (string) ( $d['cron_estado'] ?? '' );
		if ( 'vencido' === $cron ) {
			$alerta( __( 'O cron real está ligado, mas nenhum cron externo chama este site há mais de 60 minutos. O WP-Cron das visitas voltou sozinho; confira o cron da hospedagem.', 'consulta-leve' ) );
		} elseif ( ! $d['cron_desligado'] && 'desligado' === $cron ) {
			$dica( __( 'O WP-Cron roda nas visitas. Em site com tráfego ou em conta com vários WordPress, o cron real da hospedagem alivia o servidor: crie a tarefa a cada 5 minutos e ligue Cron real na aba Configurações, sem editar o wp-config.php.', 'consulta-leve' ) );
		} elseif ( ! $d['cron_desligado'] && 'definida' === $cron ) {
			$dica( __( 'O WP-Cron roda nas visitas porque o wp-config.php (ou outro código) define DISABLE_WP_CRON como false. O plugin não interfere: se quiser o cron real da hospedagem, mude essa linha.', 'consulta-leve' ) );
		} elseif ( ! $d['cron_desligado'] ) {
			$dica( __( 'O WP-Cron roda nas visitas. Em site com tráfego ou em conta com vários WordPress, o cron real da hospedagem com DISABLE_WP_CRON alivia o servidor.', 'consulta-leve' ) );
		}
		if ( $d['contagens']['transients'] > 1000 ) {
			$dica( __( 'Muitos transients vencidos em wp_options. O WordPress apaga vencidos uma vez por dia; se o número não cai, o cron pode estar parado.', 'consulta-leve' ) );
		}
		if ( $postmeta > 500000 && isset( $d['indices']['meta_chave_valor'] ) && 'ausente' === $d['indices']['meta_chave_valor'] ) {
			$dica( __( 'wp_postmeta é grande. Se o site filtra por meta (meta_query de igualdade), avalie o índice meta_chave_valor na aba Índices, depois de ver o EXPLAIN.', 'consulta-leve' ) );
		}
		if ( version_compare( (string) $d['php'], '8.1', '<' ) ) {
			$dica( __( 'PHP abaixo de 8.1. Versões novas do PHP rodam o WordPress mais rápido e recebem correção de segurança.', 'consulta-leve' ) );
		}

		return $r;
	}

	/**
	 * Diagnóstico completo (5 min em cache; $medir força nova medida).
	 *
	 * @param bool $medir Ignora o cache.
	 * @return array
	 */
	function consulta_leve_diagnostico( bool $medir = false ): array {
		if ( $medir ) {
			consulta_leve_cache_tocar( 'diagnostico' );
		}

		$d = consulta_leve_lembrar(
			'diagnostico',
			'geral',
			static function () {
				global $wpdb, $wp_version;

				$d = array(
					'medido_em'       => time(),
					'php'             => PHP_VERSION,
					'wordpress'       => (string) $wp_version,
					'banco'           => method_exists( $wpdb, 'db_server_info' ) ? (string) $wpdb->db_server_info() : '',
					'motor'           => consulta_leve_diag_motor(),
					'cache'           => consulta_leve_diag_cache(),
					'opcache'         => function_exists( 'opcache_get_status' ) && is_array( @opcache_get_status( false ) ), // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- API restrita avisa.
					'revisoes_limite' => defined( 'WP_POST_REVISIONS' ) ? WP_POST_REVISIONS : true,
					'autoload'        => consulta_leve_diag_autoload(),
					'tabelas'         => consulta_leve_diag_tabelas(),
					'contagens'       => consulta_leve_diag_contagens(),
					'indices'         => array(),
					'busca'           => function_exists( 'consulta_leve_busca_estado' ) ? consulta_leve_busca_estado() : 'desligada',
				);

				if ( function_exists( 'consulta_leve_indices_situacao' ) ) {
					foreach ( consulta_leve_indices_situacao() as $id => $s ) {
						$d['indices'][ $id ] = $s['estado'];
					}
				}

				return $d;
			},
			array( 'ttl' => 300 )
		);

		if ( ! is_array( $d ) || ! $d ) {
			return array();
		}

		// Fora do cache: o cron real vence sozinho e o painel não pode dizer que
		// ele está ativo depois disso.
		$d['cron_desligado'] = defined( 'DISABLE_WP_CRON' ) && DISABLE_WP_CRON;
		$d['cron_origem']    = function_exists( 'consulta_leve_cron_definido_por' ) ? consulta_leve_cron_definido_por() : ( defined( 'DISABLE_WP_CRON' ) ? 'wp_config' : '' );
		$d['cron_estado']    = function_exists( 'consulta_leve_cron_estado' ) ? consulta_leve_cron_estado( consulta_leve_cron_situacao() ) : '';
		$d['recomendacoes']  = consulta_leve_diag_recomendacoes( $d );

		return $d;
	}
}
