<?php
/**
 * Stubs mínimos do WordPress para os testes da Consulta Leve, sem WordPress,
 * sem banco e sem rede. Toda função é declarada só se ainda não existir: um
 * teste pode declarar a sua antes de incluir este arquivo.
 *
 * Estado em $GLOBALS['cl_t'] (ver cl_teste_reset()). O $wpdb falso entende
 * as poucas formas de SQL que os módulos usam sobre wp_options e devolve
 * respostas programadas para o resto ($GLOBALS['cl_t']['sql']).
 *
 * @package ConsultaLeve
 */

defined( 'ABSPATH' ) || define( 'ABSPATH', __DIR__ . '/' );

foreach ( array( 'MINUTE_IN_SECONDS' => 60, 'HOUR_IN_SECONDS' => 3600, 'DAY_IN_SECONDS' => 86400, 'WEEK_IN_SECONDS' => 604800, 'ARRAY_A' => 'ARRAY_A', 'OBJECT' => 'OBJECT' ) as $cl_c => $cl_v ) {
	defined( $cl_c ) || define( $cl_c, $cl_v );
}

/**
 * Estado limpo.
 *
 * @param array $extra Valores que mudam o estado padrão.
 * @param bool  $limpar_ganchos Também apaga os ganchos registrados.
 */
function cl_teste_reset( array $extra = array(), bool $limpar_ganchos = false ) {
	$ganchos = ( ! $limpar_ganchos && isset( $GLOBALS['cl_t']['ganchos'] ) ) ? $GLOBALS['cl_t']['ganchos'] : array();
	$GLOBALS['cl_t'] = array_merge(
		array(
			'opcoes'     => array(),
			'transients' => array(),
			'site'       => array(),
			'cache'      => array(),
			'externo'    => false,
			'ganchos'    => $ganchos,
			'feitos'     => array(),
			'admin'      => false,
			'usuario'    => 0,
			'papeis'     => array(),
			'pode'       => array(),
			'suporte'    => array(),
			'sql'        => array(),
			'consultas'  => array(),
			'agora'      => null,
		),
		$extra
	);
}
cl_teste_reset();

/* Ganchos ------------------------------------------------------------------ */

{
	if ( ! function_exists( 'add_filter' ) ) {
		function add_filter( $nome, $cb, $prio = 10, $args = 1 ) { // phpcs:ignore
			$GLOBALS['cl_t']['ganchos'][ $nome ][ $prio ][] = array( $cb, (int) $args );
			return true;
		}
	}
	if ( ! function_exists( 'add_action' ) ) {
		function add_action( $nome, $cb, $prio = 10, $args = 1 ) { // phpcs:ignore
			return add_filter( $nome, $cb, $prio, $args );
		}
	}
	if ( ! function_exists( 'has_filter' ) ) {
		function has_filter( $nome, $cb = false ) { // phpcs:ignore
			if ( empty( $GLOBALS['cl_t']['ganchos'][ $nome ] ) ) {
				return false;
			}
			if ( false === $cb ) {
				return true;
			}
			foreach ( $GLOBALS['cl_t']['ganchos'][ $nome ] as $prio => $lista ) {
				foreach ( $lista as $item ) {
					if ( $item[0] === $cb ) {
						return $prio;
					}
				}
			}
			return false;
		}
	}
	if ( ! function_exists( 'has_action' ) ) {
		function has_action( $nome, $cb = false ) { // phpcs:ignore
			return has_filter( $nome, $cb );
		}
	}
	if ( ! function_exists( 'remove_filter' ) ) {
		function remove_filter( $nome, $cb, $prio = 10 ) { // phpcs:ignore
			if ( empty( $GLOBALS['cl_t']['ganchos'][ $nome ][ $prio ] ) ) {
				return false;
			}
			foreach ( $GLOBALS['cl_t']['ganchos'][ $nome ][ $prio ] as $i => $item ) {
				if ( $item[0] === $cb ) {
					unset( $GLOBALS['cl_t']['ganchos'][ $nome ][ $prio ][ $i ] );
					return true;
				}
			}
			return false;
		}
	}
	if ( ! function_exists( 'remove_action' ) ) {
		function remove_action( $nome, $cb, $prio = 10 ) { // phpcs:ignore
			return remove_filter( $nome, $cb, $prio );
		}
	}
	if ( ! function_exists( 'apply_filters' ) ) {
		function apply_filters( $nome, $valor, ...$resto ) { // phpcs:ignore
			if ( empty( $GLOBALS['cl_t']['ganchos'][ $nome ] ) ) {
				return $valor;
			}
			$lista = $GLOBALS['cl_t']['ganchos'][ $nome ];
			ksort( $lista );
			foreach ( $lista as $itens ) {
				foreach ( $itens as $item ) {
					$valor = call_user_func_array( $item[0], array_slice( array_merge( array( $valor ), $resto ), 0, $item[1] ) );
				}
			}
			return $valor;
		}
	}
	if ( ! function_exists( 'do_action' ) ) {
		function do_action( $nome, ...$args ) { // phpcs:ignore
			$GLOBALS['cl_t']['feitos'][ $nome ] = ( $GLOBALS['cl_t']['feitos'][ $nome ] ?? 0 ) + 1;
			if ( empty( $GLOBALS['cl_t']['ganchos'][ $nome ] ) ) {
				return;
			}
			$lista = $GLOBALS['cl_t']['ganchos'][ $nome ];
			ksort( $lista );
			foreach ( $lista as $itens ) {
				foreach ( $itens as $item ) {
					call_user_func_array( $item[0], array_slice( $args, 0, $item[1] ) );
				}
			}
		}
	}
	if ( ! function_exists( 'did_action' ) ) {
		function did_action( $nome ) { // phpcs:ignore
			return $GLOBALS['cl_t']['feitos'][ $nome ] ?? 0;
		}
	}
}

/* Opções, transients e cache ------------------------------------------------ */

{
	if ( ! function_exists( 'get_option' ) ) {
		function get_option( $k, $padrao = false ) { // phpcs:ignore
			return array_key_exists( $k, $GLOBALS['cl_t']['opcoes'] ) ? $GLOBALS['cl_t']['opcoes'][ $k ] : $padrao;
		}
	}
	if ( ! function_exists( 'add_option' ) ) {
		function add_option( $k, $v = '', $x = '', $auto = null ) { // phpcs:ignore
			if ( array_key_exists( $k, $GLOBALS['cl_t']['opcoes'] ) ) {
				return false;
			}
			$GLOBALS['cl_t']['opcoes'][ $k ] = $v;
			return true;
		}
	}
	if ( ! function_exists( 'update_option' ) ) {
		function update_option( $k, $v, $auto = null ) { // phpcs:ignore
			$antes                           = get_option( $k );
			$GLOBALS['cl_t']['opcoes'][ $k ] = $v;
			do_action( 'update_option_' . $k, $antes, $v, $k );
			return true;
		}
	}
	if ( ! function_exists( 'delete_option' ) ) {
		function delete_option( $k ) { // phpcs:ignore
			unset( $GLOBALS['cl_t']['opcoes'][ $k ] );
			return true;
		}
	}
	if ( ! function_exists( 'get_transient' ) ) {
		function get_transient( $k ) { // phpcs:ignore
			return $GLOBALS['cl_t']['transients'][ $k ] ?? false;
		}
	}
	if ( ! function_exists( 'set_transient' ) ) {
		function set_transient( $k, $v, $t = 0 ) { // phpcs:ignore
			$GLOBALS['cl_t']['transients'][ $k ] = $v;
			return true;
		}
	}
	if ( ! function_exists( 'delete_transient' ) ) {
		function delete_transient( $k ) { // phpcs:ignore
			unset( $GLOBALS['cl_t']['transients'][ $k ] );
			return true;
		}
	}
	if ( ! function_exists( 'get_site_transient' ) ) {
		function get_site_transient( $k ) { // phpcs:ignore
			return $GLOBALS['cl_t']['site'][ $k ] ?? false;
		}
	}
	if ( ! function_exists( 'set_site_transient' ) ) {
		function set_site_transient( $k, $v, $t = 0 ) { // phpcs:ignore
			$GLOBALS['cl_t']['site'][ $k ] = $v;
			return true;
		}
	}
	if ( ! function_exists( 'delete_site_transient' ) ) {
		function delete_site_transient( $k ) { // phpcs:ignore
			unset( $GLOBALS['cl_t']['site'][ $k ] );
			return true;
		}
	}
	if ( ! function_exists( 'wp_using_ext_object_cache' ) ) {
		function wp_using_ext_object_cache() { // phpcs:ignore
			return (bool) $GLOBALS['cl_t']['externo'];
		}
	}
	if ( ! function_exists( 'wp_cache_get' ) ) {
		function wp_cache_get( $k, $g = '' ) { // phpcs:ignore
			return $GLOBALS['cl_t']['cache'][ $g ][ $k ] ?? false;
		}
	}
	if ( ! function_exists( 'wp_cache_set' ) ) {
		function wp_cache_set( $k, $v, $g = '', $t = 0 ) { // phpcs:ignore
			$GLOBALS['cl_t']['cache'][ $g ][ $k ] = $v;
			return true;
		}
	}
	if ( ! function_exists( 'wp_cache_add' ) ) {
		function wp_cache_add( $k, $v, $g = '', $t = 0 ) { // phpcs:ignore
			if ( isset( $GLOBALS['cl_t']['cache'][ $g ][ $k ] ) ) {
				return false;
			}
			return wp_cache_set( $k, $v, $g, $t );
		}
	}
	if ( ! function_exists( 'wp_cache_incr' ) ) {
		function wp_cache_incr( $k, $n = 1, $g = '' ) { // phpcs:ignore
			if ( ! isset( $GLOBALS['cl_t']['cache'][ $g ][ $k ] ) ) {
				return false;
			}
			$GLOBALS['cl_t']['cache'][ $g ][ $k ] += $n;
			return $GLOBALS['cl_t']['cache'][ $g ][ $k ];
		}
	}
	if ( ! function_exists( 'wp_cache_delete' ) ) {
		function wp_cache_delete( $k, $g = '' ) { // phpcs:ignore
			unset( $GLOBALS['cl_t']['cache'][ $g ][ $k ] );
			return true;
		}
	}
	if ( ! function_exists( 'wp_cache_get_last_changed' ) ) {
		function wp_cache_get_last_changed( $g ) { // phpcs:ignore
			if ( ! isset( $GLOBALS['cl_t']['cache'][ $g ]['last_changed'] ) ) {
				$GLOBALS['cl_t']['cache'][ $g ]['last_changed'] = (string) microtime( true );
			}
			return $GLOBALS['cl_t']['cache'][ $g ]['last_changed'];
		}
	}
}

/* Utilidades ---------------------------------------------------------------- */

{
	if ( ! function_exists( 'wp_json_encode' ) ) {
		function wp_json_encode( $v, $f = 0 ) { // phpcs:ignore
			return json_encode( $v, $f );
		}
	}
	if ( ! function_exists( '__' ) ) {
		function __( $t, $d = '' ) { // phpcs:ignore
			return $t;
		}
	}
	if ( ! function_exists( 'esc_html__' ) ) {
		function esc_html__( $t, $d = '' ) { // phpcs:ignore
			return htmlspecialchars( $t, ENT_QUOTES );
		}
	}
	if ( ! function_exists( 'esc_html' ) ) {
		function esc_html( $t ) { // phpcs:ignore
			return htmlspecialchars( (string) $t, ENT_QUOTES );
		}
	}
	if ( ! function_exists( 'esc_attr' ) ) {
		function esc_attr( $t ) { // phpcs:ignore
			return htmlspecialchars( (string) $t, ENT_QUOTES );
		}
	}
	if ( ! function_exists( 'esc_url' ) ) {
		function esc_url( $t ) { // phpcs:ignore
			return (string) $t;
		}
	}
	if ( ! function_exists( 'sanitize_key' ) ) {
		function sanitize_key( $k ) { // phpcs:ignore
			return preg_replace( '/[^a-z0-9_\-]/', '', strtolower( (string) $k ) );
		}
	}
	if ( ! function_exists( 'sanitize_text_field' ) ) {
		function sanitize_text_field( $t ) { // phpcs:ignore
			return trim( preg_replace( '/[\r\n\t ]+/', ' ', strip_tags( (string) $t ) ) );
		}
	}
	if ( ! function_exists( 'wp_unslash' ) ) {
		function wp_unslash( $v ) { // phpcs:ignore
			return is_string( $v ) ? stripslashes( $v ) : $v;
		}
	}
	if ( ! function_exists( 'absint' ) ) {
		function absint( $v ) { // phpcs:ignore
			return abs( (int) $v );
		}
	}
	if ( ! function_exists( 'wp_salt' ) ) {
		function wp_salt( $s = 'auth' ) { // phpcs:ignore
			return 'sal-de-teste-' . $s;
		}
	}
	if ( ! function_exists( 'wp_hash' ) ) {
		function wp_hash( $dado, $s = 'auth', $algo = 'md5' ) { // phpcs:ignore
			return hash_hmac( $algo, $dado, wp_salt( $s ) );
		}
	}
	if ( ! function_exists( 'remove_accents' ) ) {
		function remove_accents( $t ) { // phpcs:ignore
			$de   = array( 'á', 'à', 'â', 'ã', 'ä', 'é', 'è', 'ê', 'ë', 'í', 'ì', 'î', 'ï', 'ó', 'ò', 'ô', 'õ', 'ö', 'ú', 'ù', 'û', 'ü', 'ç', 'ñ', 'Á', 'À', 'Â', 'Ã', 'É', 'Ê', 'Í', 'Ó', 'Ô', 'Õ', 'Ú', 'Ç' );
			$para = array( 'a', 'a', 'a', 'a', 'a', 'e', 'e', 'e', 'e', 'i', 'i', 'i', 'i', 'o', 'o', 'o', 'o', 'o', 'u', 'u', 'u', 'u', 'c', 'n', 'A', 'A', 'A', 'A', 'E', 'E', 'I', 'O', 'O', 'O', 'U', 'C' );
			return str_replace( $de, $para, (string) $t );
		}
	}
	if ( ! function_exists( 'is_admin' ) ) {
		function is_admin() { // phpcs:ignore
			return (bool) $GLOBALS['cl_t']['admin'];
		}
	}
	if ( ! function_exists( 'get_current_user_id' ) ) {
		function get_current_user_id() { // phpcs:ignore
			return (int) $GLOBALS['cl_t']['usuario'];
		}
	}
	if ( ! function_exists( 'wp_get_current_user' ) ) {
		function wp_get_current_user() { // phpcs:ignore
			return (object) array(
				'ID'    => (int) $GLOBALS['cl_t']['usuario'],
				'roles' => $GLOBALS['cl_t']['papeis'],
			);
		}
	}
	if ( ! function_exists( 'current_user_can' ) ) {
		function current_user_can( $cap ) { // phpcs:ignore
			return ! empty( $GLOBALS['cl_t']['pode'][ $cap ] );
		}
	}
	if ( ! function_exists( 'get_theme_support' ) ) {
		function get_theme_support( $f ) { // phpcs:ignore
			return $GLOBALS['cl_t']['suporte'][ $f ] ?? false;
		}
	}
	if ( ! function_exists( 'wp_strip_all_tags' ) ) {
		function wp_strip_all_tags( $t ) { // phpcs:ignore
			return trim( strip_tags( (string) $t ) );
		}
	}
	if ( ! function_exists( 'strip_shortcodes' ) ) {
		function strip_shortcodes( $t ) { // phpcs:ignore
			return preg_replace( '/\[[^\]]*\]/', '', (string) $t );
		}
	}
	if ( ! function_exists( 'is_user_logged_in' ) ) {
		function is_user_logged_in() { // phpcs:ignore
			return (int) $GLOBALS['cl_t']['usuario'] > 0;
		}
	}
	if ( ! function_exists( 'wp_schedule_single_event' ) ) {
		function wp_schedule_single_event( $quando, $gancho, $args = array() ) { // phpcs:ignore
			$GLOBALS['cl_t']['agendados'][] = array( $gancho, $args );
			return true;
		}
	}
	if ( ! function_exists( 'wp_schedule_event' ) ) {
		function wp_schedule_event( $quando, $rec, $gancho, $args = array() ) { // phpcs:ignore
			$GLOBALS['cl_t']['agendados'][] = array( $gancho, $rec );
			return true;
		}
	}
	if ( ! function_exists( 'wp_next_scheduled' ) ) {
		function wp_next_scheduled( $gancho, $args = array() ) { // phpcs:ignore
			foreach ( $GLOBALS['cl_t']['agendados'] ?? array() as $a ) {
				if ( $a[0] === $gancho ) {
					return time();
				}
			}
			return false;
		}
	}
	if ( ! function_exists( 'wp_clear_scheduled_hook' ) ) {
		function wp_clear_scheduled_hook( $g ) { // phpcs:ignore
			return 0;
		}
	}
	if ( ! function_exists( 'wp_unschedule_hook' ) ) {
		function wp_unschedule_hook( $g ) { // phpcs:ignore
			return 0;
		}
	}
	if ( ! function_exists( 'wp_doing_ajax' ) ) {
		function wp_doing_ajax() { // phpcs:ignore
			return false;
		}
	}
	if ( ! function_exists( 'wp_doing_cron' ) ) {
		function wp_doing_cron() { // phpcs:ignore
			return false;
		}
	}
}

if ( ! function_exists( 'wp_parse_url' ) ) {
	function wp_parse_url( $url, $componente = -1 ) { return parse_url( (string) $url, $componente ); } // phpcs:ignore
}
if ( ! function_exists( 'trailingslashit' ) ) {
	function trailingslashit( $v ) { return rtrim( (string) $v, '/\\' ) . '/'; } // phpcs:ignore
}
if ( ! function_exists( 'untrailingslashit' ) ) {
	function untrailingslashit( $v ) { return rtrim( (string) $v, '/\\' ); } // phpcs:ignore
}

/* $wpdb falso ---------------------------------------------------------------- */

if ( ! class_exists( 'CL_Teste_Wpdb' ) ) {
	class CL_Teste_Wpdb { // phpcs:ignore
		public $prefix      = 'wp_';
		public $options     = 'wp_options';
		public $posts       = 'wp_posts';
		public $postmeta    = 'wp_postmeta';
		public $comments    = 'wp_comments';
		public $termmeta    = 'wp_termmeta';
		public $usermeta    = 'wp_usermeta';
		public $users       = 'wp_users';
		public $term_relationships = 'wp_term_relationships';
		public $num_queries = 0;
		public $last_error  = '';
		public $queries     = array();

		public function prepare( $sql, ...$args ) {
			if ( 1 === count( $args ) && is_array( $args[0] ) ) {
				$args = $args[0];
			}
			$i = 0;
			return preg_replace_callback(
				'/%[sdfi]/',
				function ( $m ) use ( &$i, $args ) {
					$v = $args[ $i++ ] ?? '';
					if ( '%d' === $m[0] ) {
						return (string) (int) $v;
					}
					if ( '%f' === $m[0] ) {
						return (string) (float) $v;
					}
					if ( '%i' === $m[0] ) {
						return '`' . str_replace( '`', '', (string) $v ) . '`';
					}
					return "'" . addslashes( (string) $v ) . "'";
				},
				$sql
			);
		}
		public function esc_like( $t ) {
			return addcslashes( (string) $t, '_%\\' );
		}
		private function registrar( $sql ) {
			++$this->num_queries;
			$GLOBALS['cl_t']['consultas'][] = $sql;
		}
		private function programada( $sql ) {
			foreach ( $GLOBALS['cl_t']['sql'] as $padrao => $resposta ) {
				if ( preg_match( $padrao, $sql ) ) {
					return is_callable( $resposta ) ? $resposta( $sql ) : $resposta;
				}
			}
			return null;
		}
		public function get_var( $sql ) {
			$this->registrar( $sql );
			if ( preg_match( "/^SELECT option_value FROM wp_options WHERE option_name = '([^']+)'/", $sql, $m ) ) {
				$v = $GLOBALS['cl_t']['opcoes'][ stripslashes( $m[1] ) ] ?? null;
				return null === $v ? null : (string) $v;
			}
			return $this->programada( $sql );
		}
		public function get_row( $sql, $f = OBJECT ) {
			$this->registrar( $sql );
			return $this->programada( $sql );
		}
		public function get_results( $sql, $f = OBJECT ) {
			$this->registrar( $sql );
			$r = $this->programada( $sql );
			return null === $r ? array() : $r;
		}
		public function get_col( $sql ) {
			$this->registrar( $sql );
			$r = $this->programada( $sql );
			return null === $r ? array() : $r;
		}
		public function query( $sql ) {
			$this->registrar( $sql );
			if ( preg_match( "/^DELETE FROM wp_options WHERE option_name = '([^']+)' AND option_value = '([^']*)'/", $sql, $m ) ) {
				$k = stripslashes( $m[1] );
				if ( isset( $GLOBALS['cl_t']['opcoes'][ $k ] ) && (string) $GLOBALS['cl_t']['opcoes'][ $k ] === stripslashes( $m[2] ) ) {
					unset( $GLOBALS['cl_t']['opcoes'][ $k ] );
					return 1;
				}
				return 0;
			}
			$r = $this->programada( $sql );
			return null === $r ? 0 : $r;
		}
		public function replace( $tabela, $dados, $formato = null ) {
			$this->registrar( 'REPLACE ' . $tabela . ' ' . json_encode( $dados ) );
			$GLOBALS['cl_t']['linhas'][ $tabela ][ $dados['post_id'] ?? count( $GLOBALS['cl_t']['linhas'][ $tabela ] ?? array() ) ] = $dados;
			return 1;
		}
		public function delete( $tabela, $onde, $formato = null ) {
			$this->registrar( 'DELETE ' . $tabela . ' ' . json_encode( $onde ) );
			unset( $GLOBALS['cl_t']['linhas'][ $tabela ][ $onde['post_id'] ?? -1 ] );
			return 1;
		}
		public function get_charset_collate() {
			return 'DEFAULT CHARSET=utf8mb4';
		}
		public function suppress_errors( $s = true ) {
			return false;
		}
		public function db_server_info() {
			return '8.0.36';
		}
	}
}
$GLOBALS['wpdb'] = new CL_Teste_Wpdb();

/* Conferência ---------------------------------------------------------------- */

$GLOBALS['cl_falhas'] = 0;
$GLOBALS['cl_total']  = 0;

if ( ! function_exists( 'confere' ) ) {
/**
 * Compara obtido e esperado (===).
 *
 * @param string $nome     Nome do caso.
 * @param mixed  $obtido   Obtido.
 * @param mixed  $esperado Esperado.
 */
function confere( $nome, $obtido, $esperado ) {
	++$GLOBALS['cl_total'];
	if ( $obtido !== $esperado ) {
		++$GLOBALS['cl_falhas'];
		fwrite( STDERR, "FALHOU: {$nome}\n  esperado: " . var_export( $esperado, true ) . "\n  obtido:   " . var_export( $obtido, true ) . "\n" );
	}
}

/**
 * Fecha o teste: imprime o resumo e sai com 1 se algo falhou.
 *
 * @param string $nome Nome do arquivo de teste.
 */
function cl_teste_fim( $nome ) {
	echo "{$nome}: " . ( $GLOBALS['cl_total'] - $GLOBALS['cl_falhas'] ) . '/' . $GLOBALS['cl_total'] . " casos\n";
	exit( $GLOBALS['cl_falhas'] ? 1 : 0 );
}
}
