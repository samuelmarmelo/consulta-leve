<?php
/**
 * Cache versionado (contrato, seção 6).
 *
 * A chave leva a versão do grupo e as versões `last_changed` do núcleo das
 * dependências (posts, terms, comments, users). Mudar a versão invalida tudo
 * do grupo sem varrer wp_options: as chaves velhas param de ser lidas e
 * expiram sozinhas. Dado de um usuário nunca é servido a outro (escopo
 * `usuario`). Origem: loja C `inc/core/cache.php`, CRM interno
 * `api/busca.php` e `data/metricas.php`.
 *
 * @package ConsultaLeve
 */

defined( 'ABSPATH' ) || exit;

/** Opção com as versões dos grupos (autoload desligado). */
const CONSULTA_LEVE_OPCAO_VERSOES = 'consulta_leve_cache_versoes';

/** Grupo do cache de objetos. */
const CONSULTA_LEVE_GRUPO_CACHE = 'consulta_leve';

/** Maior valor serializado guardado em transient (sem cache de objetos externo). */
const CONSULTA_LEVE_CACHE_MAX_BYTES = 65536;

/** Quanto tempo a última cópia boa vale enquanto outro pedido recalcula. */
const CONSULTA_LEVE_CACHE_ULTIMO_TTL = 600;

if ( ! function_exists( 'consulta_leve_lembrar' ) ) {

	/**
	 * Nome de grupo saneado.
	 *
	 * @param string $grupo Grupo.
	 * @return string
	 */
	function consulta_leve_cache_grupo( string $grupo ): string {
		$limpo = substr( preg_replace( '/[^a-z0-9_]/', '', strtolower( $grupo ) ), 0, 32 );
		return '' === $limpo ? 'geral' : $limpo;
	}

	/**
	 * Versões de todos os grupos.
	 *
	 * @return array<string,string>
	 */
	function consulta_leve_cache_versoes(): array {
		$versoes = get_option( CONSULTA_LEVE_OPCAO_VERSOES, array() );
		return is_array( $versoes ) ? $versoes : array();
	}

	/**
	 * Versão de um grupo ('0' enquanto ninguém tocou).
	 *
	 * @param string $grupo Grupo.
	 * @return string
	 */
	function consulta_leve_cache_versao( string $grupo ): string {
		$versoes = consulta_leve_cache_versoes();
		$grupo   = consulta_leve_cache_grupo( $grupo );

		return isset( $versoes[ $grupo ] ) ? (string) $versoes[ $grupo ] : '0';
	}

	/**
	 * Invalida um grupo: muda a versão. Nada é apagado.
	 *
	 * @param string $grupo Grupo.
	 * @return string Versão nova.
	 */
	function consulta_leve_cache_tocar( string $grupo ): string {
		$versoes = consulta_leve_cache_versoes();
		$grupo   = consulta_leve_cache_grupo( $grupo );
		$nova    = str_replace( '.', '', sprintf( '%.4F', microtime( true ) ) );

		$versoes[ $grupo ] = $nova;
		update_option( CONSULTA_LEVE_OPCAO_VERSOES, $versoes, false );

		/**
		 * Um grupo do cache da Consulta Leve foi invalidado.
		 *
		 * @param string $grupo Grupo.
		 */
		do_action( 'consulta_leve_cache_tocado', $grupo );

		return $nova;
	}

	/**
	 * Parte da chave que muda quando uma dependência do núcleo muda.
	 *
	 * @param string[] $deps Entre posts, terms, comments e users.
	 * @return string
	 */
	function consulta_leve_cache_deps( array $deps ): string {
		$partes = array();
		// Dependência => grupo de cache onde o núcleo guarda o last_changed.
		$grupos = array(
			'posts'    => 'posts',
			'terms'    => 'terms',
			'comments' => 'comment',
			'users'    => 'users',
		);

		// Sem cache de objetos externo, o last_changed do núcleo nasce de novo
		// a cada requisição: vale a versão própria, gravada no banco.
		$externo = wp_using_ext_object_cache() && function_exists( 'wp_cache_get_last_changed' );

		foreach ( $grupos as $dep => $grupo ) {
			if ( in_array( $dep, $deps, true ) ) {
				$partes[] = $externo ? (string) wp_cache_get_last_changed( $grupo ) : consulta_leve_cache_versao( 'dep_' . $dep );
			}
		}

		return implode( ':', $partes );
	}

	/**
	 * Marca uma dependência como alterada nesta requisição. A versão é
	 * gravada uma vez só, no shutdown (importação em massa não vira mil
	 * gravações).
	 *
	 * @param string $dep posts, terms, comments ou users.
	 * @return void
	 */
	function consulta_leve_cache_dep_mudou( string $dep ): void {
		static $registrado = false;

		if ( wp_using_ext_object_cache() ) {
			return; // O núcleo já muda o last_changed persistente.
		}

		$GLOBALS['consulta_leve_deps_mudadas'][ $dep ] = true;

		if ( ! $registrado ) {
			$registrado = true;
			add_action( 'shutdown', 'consulta_leve_cache_gravar_deps', 5 );
		}
	}

	/**
	 * Grava as versões das dependências alteradas na requisição.
	 *
	 * @return void
	 */
	function consulta_leve_cache_gravar_deps(): void {
		$mudadas = isset( $GLOBALS['consulta_leve_deps_mudadas'] ) ? array_keys( (array) $GLOBALS['consulta_leve_deps_mudadas'] ) : array();
		if ( ! $mudadas ) {
			return;
		}

		$versoes = consulta_leve_cache_versoes();
		$nova    = str_replace( '.', '', sprintf( '%.4F', microtime( true ) ) );
		foreach ( $mudadas as $dep ) {
			$versoes[ 'dep_' . consulta_leve_cache_grupo( $dep ) ] = $nova;
		}
		update_option( CONSULTA_LEVE_OPCAO_VERSOES, $versoes, false );
		$GLOBALS['consulta_leve_deps_mudadas'] = array();
	}

	/**
	 * Ganchos do núcleo que mudam cada dependência. Metas de edição
	 * (`_edit_lock`, `_edit_last`) não contam: mudam a cada batida do editor.
	 *
	 * @return void
	 */
	function consulta_leve_cache_ganchos(): void {
		foreach ( array( 'clean_post_cache', 'transition_post_status' ) as $gancho ) {
			add_action(
				$gancho,
				static function () {
					consulta_leve_cache_dep_mudou( 'posts' );
				}
			);
		}

		foreach ( array( 'added_post_meta', 'updated_post_meta', 'deleted_post_meta' ) as $gancho ) {
			add_action(
				$gancho,
				static function ( $meta_id, $post_id = 0, $meta_key = '' ) {
					if ( ! in_array( (string) $meta_key, array( '_edit_lock', '_edit_last' ), true ) ) {
						consulta_leve_cache_dep_mudou( 'posts' );
					}
				},
				10,
				3
			);
		}

		foreach ( array( 'clean_term_cache', 'set_object_terms', 'clean_object_term_cache' ) as $gancho ) {
			add_action(
				$gancho,
				static function () {
					consulta_leve_cache_dep_mudou( 'terms' );
				}
			);
		}

		add_action(
			'clean_comment_cache',
			static function () {
				consulta_leve_cache_dep_mudou( 'comments' );
			}
		);

		add_action(
			'clean_user_cache',
			static function () {
				consulta_leve_cache_dep_mudou( 'users' );
			}
		);
	}

	consulta_leve_cache_ganchos();

	/**
	 * Parte da chave que separa as pessoas (escopo `usuario`).
	 *
	 * @return string
	 */
	function consulta_leve_cache_escopo_usuario(): string {
		$id = function_exists( 'get_current_user_id' ) ? (int) get_current_user_id() : 0;
		if ( $id <= 0 ) {
			return 'u0';
		}

		$usuario = function_exists( 'wp_get_current_user' ) ? wp_get_current_user() : null;
		$papeis  = ( $usuario && isset( $usuario->roles ) && is_array( $usuario->roles ) ) ? $usuario->roles : array();
		sort( $papeis );

		return 'u' . $id . '_' . implode( '.', $papeis );
	}

	/**
	 * Nome final da chave. Cabe nos 172 caracteres de um transient.
	 *
	 * @param string $grupo  Grupo saneado.
	 * @param mixed  $chave  Chave (qualquer valor serializável em JSON).
	 * @param array  $deps   Dependências do núcleo.
	 * @param string $escopo `publico` ou `usuario`.
	 * @return string
	 */
	function consulta_leve_cache_nome( string $grupo, $chave, array $deps, string $escopo ): string {
		$base = is_string( $chave ) ? $chave : (string) wp_json_encode( $chave );
		$sal  = consulta_leve_cache_versao( $grupo ) . '|' . consulta_leve_cache_deps( $deps ) . '|' . ( 'usuario' === $escopo ? consulta_leve_cache_escopo_usuario() : 'p' );

		return 'consulta_leve_' . $grupo . '_' . md5( $sal . '|' . $base );
	}

	/**
	 * Lê do armazenamento (cache de objetos externo ou transient).
	 *
	 * @param string $nome Nome da chave.
	 * @return mixed false se não houver.
	 */
	function consulta_leve_cache_ler( string $nome ) {
		if ( wp_using_ext_object_cache() ) {
			return wp_cache_get( $nome, CONSULTA_LEVE_GRUPO_CACHE );
		}

		return get_transient( $nome );
	}

	/**
	 * Grava no armazenamento. Sem cache externo, valor grande não vai para
	 * wp_options (fica só no memo da requisição).
	 *
	 * @param string $nome  Nome da chave.
	 * @param mixed  $valor Valor.
	 * @param int    $ttl   Segundos.
	 * @return bool
	 */
	function consulta_leve_cache_gravar( string $nome, $valor, int $ttl ): bool {
		if ( wp_using_ext_object_cache() ) {
			return (bool) wp_cache_set( $nome, $valor, CONSULTA_LEVE_GRUPO_CACHE, $ttl );
		}

		if ( strlen( serialize( $valor ) ) > CONSULTA_LEVE_CACHE_MAX_BYTES ) { // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.serialize_serialize -- só mede o tamanho.
			return false;
		}

		return (bool) set_transient( $nome, $valor, $ttl );
	}

	/**
	 * Memo da requisição (compartilhado por referência).
	 *
	 * @return array<string,mixed>
	 */
	function &consulta_leve_cache_memo(): array {
		static $memo = array();
		return $memo;
	}

	/**
	 * Devolve o valor guardado ou calcula, guarda e devolve.
	 *
	 * Opções:
	 * - `ttl` (int): segundos (padrão: configuração `cache_ttl`);
	 * - `deps` (string[]): posts, terms, comments, users;
	 * - `escopo` (string): `publico` ou `usuario`;
	 * - `travar` (bool): um pedido calcula, os outros recebem a última cópia boa.
	 *
	 * `false` e `null` calculados não são guardados.
	 *
	 * @param string   $grupo    Grupo (invalidado por consulta_leve_cache_tocar()).
	 * @param mixed    $chave    O que distingue um resultado do outro.
	 * @param callable $calcular Função que calcula o valor.
	 * @param array    $o        Opções.
	 * @return mixed
	 */
	function consulta_leve_lembrar( string $grupo, $chave, callable $calcular, array $o = array() ) {
		$grupo  = consulta_leve_cache_grupo( $grupo );
		$ttl    = isset( $o['ttl'] ) ? max( 1, (int) $o['ttl'] ) : (int) ( function_exists( 'consulta_leve_opcao' ) ? consulta_leve_opcao( 'cache_ttl' ) : 600 );
		$deps   = isset( $o['deps'] ) && is_array( $o['deps'] ) ? $o['deps'] : array();
		$escopo = isset( $o['escopo'] ) && 'usuario' === $o['escopo'] ? 'usuario' : 'publico';
		$nome   = consulta_leve_cache_nome( $grupo, $chave, $deps, $escopo );
		$memo   = &consulta_leve_cache_memo();

		if ( array_key_exists( $nome, $memo ) ) {
			return $memo[ $nome ];
		}

		$guardado = consulta_leve_cache_ler( $nome );
		if ( false !== $guardado ) {
			$memo[ $nome ] = $guardado;
			return $guardado;
		}

		// Nome da última cópia boa: sem as versões, para sobreviver à troca.
		$ultimo = 'consulta_leve_ultimo_' . md5( $grupo . '|' . $escopo . '|' . ( 'usuario' === $escopo ? consulta_leve_cache_escopo_usuario() : '' ) . '|' . ( is_string( $chave ) ? $chave : wp_json_encode( $chave ) ) );
		$dono   = '';

		if ( ! empty( $o['travar'] ) && function_exists( 'consulta_leve_travar' ) ) {
			$dono = consulta_leve_travar( 'cache_' . md5( $nome ), 30 );
			if ( '' === $dono ) {
				$copia = consulta_leve_cache_ler( $ultimo );
				if ( false !== $copia ) {
					$memo[ $nome ] = $copia;
					return $copia;
				}
			}
		}

		try {
			$valor = call_user_func( $calcular );
		} finally {
			if ( '' !== $dono ) {
				consulta_leve_liberar( 'cache_' . md5( $nome ), $dono );
			}
		}

		if ( false !== $valor && null !== $valor ) {
			$memo[ $nome ] = $valor;
			consulta_leve_cache_gravar( $nome, $valor, $ttl );
			if ( ! empty( $o['travar'] ) ) {
				consulta_leve_cache_gravar( $ultimo, $valor, max( $ttl, CONSULTA_LEVE_CACHE_ULTIMO_TTL ) );
			}
		}

		return $valor;
	}
}
