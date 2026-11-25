<?php
/**
 * Cron real: desliga o WP-Cron das visitas sem editar o wp-config.php
 * (contrato, seção 10.4).
 *
 * Desligado por padrão. Ligado no painel, define DISABLE_WP_CRON na carga do
 * plugin (o núcleo só lê a constante depois, em `_wp_cron()`), e só enquanto
 * um cron externo der sinal de vida: a última batida precisa ter menos de
 * 60 minutos. Passou disso, a constante não é definida e o WP-Cron das
 * visitas volta sozinho. Nenhum arquivo do servidor é tocado.
 *
 * @package ConsultaLeve
 */

defined( 'ABSPATH' ) || exit;

/** Última batida do cron externo: array( em, origem ). Pequena, com autoload. */
const CONSULTA_LEVE_OPCAO_CRON_BATIDA = 'consulta_leve_cron_batida';

/** Intervalo mínimo entre duas gravações da batida (s). */
const CONSULTA_LEVE_CRON_GRAVAR_S = 300;

/** Para ligar, a última batida precisa ser mais nova que isto (s). */
const CONSULTA_LEVE_CRON_LIGAR_S = 1800;

/** Batida mais velha que isto (s): o WP-Cron das visitas volta sozinho. */
const CONSULTA_LEVE_CRON_VENCE_S = 3600;

if ( ! function_exists( 'consulta_leve_cron_estado' ) ) {

	/**
	 * Origem de uma execução de cron externo (regra pura).
	 *
	 * O `spawn_cron()` do núcleo sempre chama o wp-cron.php com
	 * `doing_wp_cron` preenchido; o próprio wp-cron.php trata o parâmetro vazio
	 * ou ausente como chamada externa. Parâmetro preenchido, portanto, nunca
	 * conta, seja qual for o valor.
	 *
	 * @param bool   $doing_cron A requisição é do wp-cron.php (DOING_CRON)?
	 * @param string $sapi       PHP_SAPI.
	 * @param array  $get        Parâmetros da URL ($_GET).
	 * @return string cli, http ou '' (não é cron externo).
	 */
	function consulta_leve_cron_origem( bool $doing_cron, string $sapi, array $get ): string {
		if ( ! $doing_cron || ! empty( $get['doing_wp_cron'] ) ) {
			return '';
		}

		return 'cli' === $sapi ? 'cli' : 'http';
	}

	/**
	 * A batida está dentro do prazo (regra pura)? Batida no futuro além de 5
	 * minutos (relógio mexido) não vale.
	 *
	 * @param int $em    Instante da batida (0 = nunca).
	 * @param int $agora Agora.
	 * @param int $prazo Prazo em segundos.
	 * @return bool
	 */
	function consulta_leve_cron_recente( int $em, int $agora, int $prazo ): bool {
		$idade = $agora - $em;

		return $em > 0 && $idade <= $prazo && $idade >= -CONSULTA_LEVE_CRON_GRAVAR_S;
	}

	/**
	 * É hora de gravar a batida (regra pura)? No máximo uma vez a cada 5 minutos.
	 *
	 * @param int $em    Última batida gravada.
	 * @param int $agora Agora.
	 * @return bool
	 */
	function consulta_leve_cron_deve_gravar( int $em, int $agora ): bool {
		return $em <= 0 || $em > $agora || $agora - $em >= CONSULTA_LEVE_CRON_GRAVAR_S;
	}

	/**
	 * Estado do cron real (regra pura).
	 *
	 * @param array $s Situação: proprio, definida_fora, ligado (bool), batida, agora (int).
	 * @return string embutida, definida, desligado, vencido ou ativo. Só `ativo` define a constante.
	 */
	function consulta_leve_cron_estado( array $s ): string {
		if ( empty( $s['proprio'] ) ) {
			return 'embutida';
		}
		if ( ! empty( $s['definida_fora'] ) ) {
			return 'definida';
		}
		if ( empty( $s['ligado'] ) ) {
			return 'desligado';
		}

		return consulta_leve_cron_recente( (int) ( $s['batida'] ?? 0 ), (int) ( $s['agora'] ?? 0 ), CONSULTA_LEVE_CRON_VENCE_S ) ? 'ativo' : 'vencido';
	}

	/**
	 * Pode ligar (regra pura)?
	 *
	 * @param array $s Situação (ver consulta_leve_cron_estado()).
	 * @return string ok, embutida, definida ou sem_batida.
	 */
	function consulta_leve_cron_pode_ligar( array $s ): string {
		$estado = consulta_leve_cron_estado( $s );
		if ( 'embutida' === $estado || 'definida' === $estado ) {
			return $estado;
		}

		return consulta_leve_cron_recente( (int) ( $s['batida'] ?? 0 ), (int) ( $s['agora'] ?? 0 ), CONSULTA_LEVE_CRON_LIGAR_S ) ? 'ok' : 'sem_batida';
	}

	/**
	 * Esta cópia é o plugin próprio? Cópia embutida em tema carrega tarde, e
	 * quem a embute decide sobre o cron. Mesma conferência do atualizador.
	 *
	 * @return bool
	 */
	function consulta_leve_cron_proprio(): bool {
		return function_exists( 'consulta_leve_atualizador_plugin_proprio' ) && consulta_leve_atualizador_plugin_proprio();
	}

	/**
	 * Última batida gravada.
	 *
	 * @return array{em:int,origem:string}
	 */
	function consulta_leve_cron_batida(): array {
		$b      = get_option( CONSULTA_LEVE_OPCAO_CRON_BATIDA, array() );
		$b      = is_array( $b ) ? $b : array();
		$origem = $b['origem'] ?? '';

		return array(
			'em'     => isset( $b['em'] ) && is_numeric( $b['em'] ) ? (int) $b['em'] : 0,
			'origem' => in_array( $origem, array( 'cli', 'http', 'wp-cli' ), true ) ? $origem : '',
		);
	}

	/**
	 * Situação atual.
	 *
	 * Lê a opção gravada, e não `consulta_leve_opcao()`: a decisão é tomada na
	 * carga do plugin, antes de o tema e os outros plugins registrarem o filtro
	 * `consulta_leve_config`, e o memo da configuração não pode nascer sem eles.
	 * Com a chave desligada a batida não é lida (nenhuma consulta a mais).
	 *
	 * @param bool $completa Lê a batida mesmo com a chave desligada (painel).
	 * @return array{proprio:bool,definida_fora:bool,ligado:bool,batida:int,agora:int}
	 */
	function consulta_leve_cron_situacao( bool $completa = false ): array {
		$config = get_option( CONSULTA_LEVE_OPCAO_CONFIG, array() );
		$ligado = is_array( $config ) && isset( $config['cron_real'] ) && true === consulta_leve_config_valor( array( 'bool' ), $config['cron_real'], false );

		return array(
			'proprio'       => consulta_leve_cron_proprio(),
			'definida_fora' => 'wp_config' === consulta_leve_cron_definido_por(),
			'ligado'        => $ligado,
			'batida'        => $ligado || $completa ? consulta_leve_cron_batida()['em'] : 0,
			'agora'         => time(),
		);
	}

	/**
	 * Quem decidiu DISABLE_WP_CRON nesta requisição.
	 *
	 * @return string '' (ninguém), plugin (este) ou wp_config (wp-config.php ou outro código).
	 */
	function consulta_leve_cron_definido_por(): string {
		if ( ! defined( 'DISABLE_WP_CRON' ) ) {
			return '';
		}

		return defined( 'CONSULTA_LEVE_CRON_PELO_PLUGIN' ) ? 'plugin' : 'wp_config';
	}

	/**
	 * Grava a batida, se for hora.
	 *
	 * @param string $origem cli, http ou wp-cli ('' não grava).
	 * @return bool Gravou?
	 */
	function consulta_leve_cron_bater( string $origem ): bool {
		$agora = time();
		if ( '' === $origem || ! consulta_leve_cron_proprio() || ! consulta_leve_cron_deve_gravar( consulta_leve_cron_batida()['em'], $agora ) ) {
			return false;
		}

		update_option(
			CONSULTA_LEVE_OPCAO_CRON_BATIDA,
			array(
				'em'     => $agora,
				'origem' => $origem,
			),
			true
		);

		return true;
	}

	/**
	 * Linha de exemplo para o cron da hospedagem (a cada 5 minutos).
	 *
	 * @return string
	 */
	function consulta_leve_cron_linha(): string {
		return '*/5 * * * * curl -fsS -o /dev/null "' . site_url( 'wp-cron.php' ) . '"';
	}

	/**
	 * Decide e, se for o caso, define DISABLE_WP_CRON.
	 *
	 * @return bool Definiu?
	 */
	function consulta_leve_cron_aplicar(): bool {
		if ( defined( 'DISABLE_WP_CRON' ) || 'ativo' !== consulta_leve_cron_estado( consulta_leve_cron_situacao() ) ) {
			return false;
		}

		define( 'CONSULTA_LEVE_CRON_PELO_PLUGIN', true );

		return define( 'DISABLE_WP_CRON', true );
	}

	/**
	 * Começa: decide a constante e, numa execução externa, agenda a batida.
	 *
	 * @param string $origem Origem da execução ('' em requisição comum).
	 * @return void
	 */
	function consulta_leve_cron_iniciar( string $origem ): void {
		if ( ! consulta_leve_cron_proprio() ) {
			return;
		}

		consulta_leve_cron_aplicar();

		// A batida só é gravada com o WordPress inteiro de pé: site que quebra
		// antes do wp_loaded não conta como cron vivo.
		if ( '' !== $origem ) {
			add_action(
				'wp_loaded',
				static function () use ( $origem ) {
					consulta_leve_cron_bater( $origem );
				},
				999
			);
		}

		// `wp cron event run` só define DOING_CRON ao rodar cada evento, depois da
		// carga dos plugins: conta pelo gancho do próprio WP-CLI.
		if ( defined( 'WP_CLI' ) && WP_CLI && class_exists( 'WP_CLI' ) && is_callable( array( 'WP_CLI', 'add_hook' ) ) ) {
			\WP_CLI::add_hook(
				'before_invoke:cron event run',
				static function () {
					consulta_leve_cron_bater( 'wp-cli' );
				}
			);
		}
	}

	// O wp-cron.php define DOING_CRON antes de carregar o WordPress.
	consulta_leve_cron_iniciar( consulta_leve_cron_origem( defined( 'DOING_CRON' ) && DOING_CRON, PHP_SAPI, $_GET ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- só confere se o parâmetro está vazio.
}
