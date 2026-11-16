<?php
/**
 * Tabela de busca própria (contrato, seção 7).
 *
 * Busca e filtro em wp_posts/wp_postmeta ficam lentos com milhares de posts:
 * `LIKE '%termo%'` varre o conteúdo inteiro e meta serializada não tem
 * índice útil. A tabela guarda uma cópia enxuta e indexada de cada post dos
 * tipos escolhidos, com FULLTEXT quando o banco aceita e `LIKE` num texto
 * normalizado como reserva. É cache: o post continua a fonte da verdade, e
 * tudo que usa a tabela volta ao caminho do núcleo enquanto ela não está
 * pronta. Origem: CRM interno `data/busca-indice.php` e `api/busca.php`.
 *
 * @package ConsultaLeve
 */

defined( 'ABSPATH' ) || exit;

/** Versão do esquema da tabela (subiu, reinstala). */
const CONSULTA_LEVE_BUSCA_ESQUEMA = 1;

/** Posts gravados na própria requisição; o resto vai para o cron. */
const CONSULTA_LEVE_BUSCA_FILA_AGORA = 50;

/** Pendentes acima disto viram reconstrução. */
const CONSULTA_LEVE_BUSCA_FILA_MAX = 5000;

/** Gancho dos pendentes. */
const CONSULTA_LEVE_BUSCA_GANCHO_PENDENTES = 'consulta_leve_busca_pendentes';

/** Caracteres de texto guardados por post. */
const CONSULTA_LEVE_BUSCA_TEXTO_MAX = 200000;

/** Gancho do lote de reconstrução. */
const CONSULTA_LEVE_BUSCA_GANCHO_LOTE = 'consulta_leve_busca_lote';

/** Gancho da conferência diária. */
const CONSULTA_LEVE_BUSCA_GANCHO_DIARIO = 'consulta_leve_busca_diaria';

if ( ! function_exists( 'consulta_leve_busca_tabela' ) ) {

	/**
	 * Nome da tabela.
	 *
	 * @return string
	 */
	function consulta_leve_busca_tabela(): string {
		global $wpdb;
		return $wpdb->prefix . 'consulta_leve_busca';
	}

	/**
	 * Tipos de post indexados: os escolhidos, que existem e que nenhum outro
	 * código já indexa.
	 *
	 * @return string[]
	 */
	function consulta_leve_busca_tipos(): array {
		$tipos = array_diff( (array) consulta_leve_opcao( 'indice_tipos' ), consulta_leve_indice_tipos_cobertos() );
		if ( function_exists( 'post_type_exists' ) && did_action( 'init' ) ) {
			$tipos = array_filter( $tipos, 'post_type_exists' );
		}

		return array_values( $tipos );
	}

	/**
	 * Status guardados na tabela (as consultas filtram por status).
	 *
	 * @return string[]
	 */
	function consulta_leve_busca_status_guardados(): array {
		return array( 'publish', 'future', 'draft', 'pending', 'private' );
	}

	/**
	 * A tabela está ligada (configuração, tipos e sem dispensa)?
	 *
	 * @return bool
	 */
	function consulta_leve_busca_ligada(): bool {
		// Depende dos módulos de consultas (lotes, cursor e carga em lote).
		foreach ( array( 'consulta_leve_em_lotes', 'consulta_leve_cursor_codificar', 'consulta_leve_ids' ) as $funcao ) {
			if ( ! function_exists( $funcao ) ) {
				return false;
			}
		}

		return consulta_leve_ativo( 'indice_busca' ) && (bool) consulta_leve_busca_tipos();
	}

	/**
	 * Estado: desligada, sem_tabela, construindo ou pronta.
	 *
	 * @return string
	 */
	function consulta_leve_busca_estado(): string {
		if ( ! consulta_leve_busca_ligada() ) {
			return 'desligada';
		}
		if ( (int) get_option( 'consulta_leve_busca_esquema', 0 ) !== CONSULTA_LEVE_BUSCA_ESQUEMA ) {
			return 'sem_tabela';
		}
		$reconstrucao = get_option( 'consulta_leve_busca_reconstrucao', array() );
		if ( ! empty( $reconstrucao['geracao'] ) || ! get_option( 'consulta_leve_busca_construida', false ) ) {
			return 'construindo';
		}

		return 'pronta';
	}

	/**
	 * Texto normalizado: sem tags, minúsculas, sem acento, espaços simples.
	 *
	 * @param string $texto Texto.
	 * @return string
	 */
	function consulta_leve_busca_normalizar( string $texto ): string {
		$texto = remove_accents( wp_strip_all_tags( $texto ) );
		$texto = function_exists( 'mb_strtolower' ) ? mb_strtolower( $texto, 'UTF-8' ) : strtolower( $texto );

		return trim( (string) preg_replace( '/\s+/u', ' ', $texto ) );
	}

	/**
	 * Cria ou atualiza a tabela (dbDelta) e tenta o FULLTEXT.
	 *
	 * @return bool A tabela existe?
	 */
	function consulta_leve_busca_instalar(): bool {
		global $wpdb;

		$tabela  = consulta_leve_busca_tabela();
		$charset = method_exists( $wpdb, 'get_charset_collate' ) ? $wpdb->get_charset_collate() : '';

		// Formato do dbDelta: uma coluna por linha, dois espaços depois de PRIMARY KEY.
		$sql = "CREATE TABLE {$tabela} (
post_id bigint(20) unsigned NOT NULL,
post_type varchar(20) NOT NULL DEFAULT '',
post_status varchar(20) NOT NULL DEFAULT '',
post_date datetime NOT NULL DEFAULT '0000-00-00 00:00:00',
modificado datetime NOT NULL DEFAULT '0000-00-00 00:00:00',
titulo text NOT NULL,
texto longtext NOT NULL,
texto_norm longtext NOT NULL,
PRIMARY KEY  (post_id),
KEY tipo_status_data (post_type,post_status,post_date),
KEY modificado (modificado)
) {$charset};";

		if ( ! function_exists( 'dbDelta' ) ) {
			require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		}
		dbDelta( $sql );

		$existe = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $tabela ) ) );
		if ( $existe !== $tabela ) {
			consulta_leve_registrar( 'busca_instalar', 'tabela', 'falhou' );
			return false;
		}

		// FULLTEXT fora do dbDelta; banco que recusa fica com o LIKE.
		$ft = '0';
		if ( 'sqlite' !== consulta_leve_indices_motor_seguro() ) {
			$tem = $wpdb->get_var( $wpdb->prepare( "SHOW INDEX FROM {$tabela} WHERE Key_name = %s", 'cl_ft' ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- nome da tabela fixo.
			if ( $tem ) {
				$ft = '1';
			} else {
				$antes = $wpdb->suppress_errors( true );
				$ft    = false === $wpdb->query( "ALTER TABLE {$tabela} ADD FULLTEXT KEY cl_ft (titulo,texto)" ) ? '0' : '1'; // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- nome da tabela fixo.
				$wpdb->suppress_errors( $antes );
			}
		}

		update_option( 'consulta_leve_busca_fulltext', $ft, false );
		update_option( 'consulta_leve_busca_esquema', CONSULTA_LEVE_BUSCA_ESQUEMA, false );
		consulta_leve_registrar( 'busca_instalar', 'tabela', '1' === $ft ? 'ok_fulltext' : 'ok_like' );

		return true;
	}

	/**
	 * Motor do banco, sem depender do módulo de índices estar carregado.
	 *
	 * @return string
	 */
	function consulta_leve_indices_motor_seguro(): string {
		if ( function_exists( 'consulta_leve_indices_motor' ) ) {
			return consulta_leve_indices_motor();
		}

		return ( defined( 'DB_ENGINE' ) && 'sqlite' === DB_ENGINE ) ? 'sqlite' : 'mysql';
	}

	/**
	 * Linha da tabela para um post.
	 *
	 * @param \WP_Post $post Post.
	 * @return array<string,mixed>
	 */
	function consulta_leve_busca_linha( $post ): array {
		$partes = array( (string) $post->post_excerpt, strip_shortcodes( (string) $post->post_content ) );

		foreach ( (array) consulta_leve_opcao( 'indice_metas' ) as $chave ) {
			foreach ( (array) get_post_meta( $post->ID, $chave, false ) as $valor ) {
				if ( is_scalar( $valor ) ) {
					$partes[] = (string) $valor;
				}
			}
		}

		$titulo = wp_strip_all_tags( (string) $post->post_title );
		$texto  = wp_strip_all_tags( implode( ' ', $partes ) );
		$texto  = function_exists( 'mb_substr' ) ? mb_substr( $texto, 0, CONSULTA_LEVE_BUSCA_TEXTO_MAX, 'UTF-8' ) : substr( $texto, 0, CONSULTA_LEVE_BUSCA_TEXTO_MAX );

		return array(
			'post_id'     => (int) $post->ID,
			'post_type'   => (string) $post->post_type,
			'post_status' => (string) $post->post_status,
			'post_date'   => (string) $post->post_date,
			'modificado'  => (string) $post->post_modified_gmt,
			'titulo'      => $titulo,
			'texto'       => $texto,
			'texto_norm'  => consulta_leve_busca_normalizar( $titulo . ' ' . $texto ),
		);
	}

	/**
	 * Grava (ou tira) cada post na tabela. Post apagado, fora dos tipos ou
	 * fora dos status guardados sai da tabela; o post em si nunca muda.
	 *
	 * @param int[] $ids   IDs.
	 * @param bool  $tocar Invalida o cache da busca no fim.
	 * @return int Linhas gravadas.
	 */
	function consulta_leve_busca_gravar( array $ids, bool $tocar = true ): int {
		global $wpdb;

		$tabela  = consulta_leve_busca_tabela();
		$tipos   = consulta_leve_busca_tipos();
		$status  = consulta_leve_busca_status_guardados();
		$gravou  = 0;

		foreach ( consulta_leve_ids( $ids ) as $id ) {
			$post = get_post( $id );
			if ( ! $post || ! in_array( $post->post_type, $tipos, true ) || ! in_array( $post->post_status, $status, true ) ) {
				$wpdb->delete( $tabela, array( 'post_id' => $id ), array( '%d' ) );
				continue;
			}

			if ( false !== $wpdb->replace( $tabela, consulta_leve_busca_linha( $post ), array( '%d', '%s', '%s', '%s', '%s', '%s', '%s', '%s' ) ) ) {
				++$gravou;
			}
		}

		if ( $tocar ) {
			consulta_leve_cache_tocar( 'busca' );
		}

		return $gravou;
	}

	/**
	 * Põe um post na fila da requisição (gravada no shutdown).
	 *
	 * @param int $id Post.
	 * @return void
	 */
	function consulta_leve_busca_enfileirar( $id ): void {
		$id = (int) $id;
		if ( $id <= 0 || ! in_array( consulta_leve_busca_estado(), array( 'pronta', 'construindo' ), true ) ) {
			return;
		}

		if ( empty( $GLOBALS['consulta_leve_busca_fila'] ) ) {
			$GLOBALS['consulta_leve_busca_fila'] = array();
			add_action( 'shutdown', 'consulta_leve_busca_processar_fila', 20 );
		}
		$GLOBALS['consulta_leve_busca_fila'][ $id ] = true;
	}

	/**
	 * Grava a fila: até 50 posts na própria requisição (o salvar do editor
	 * não espera mais que isso); o resto entra nos pendentes, gravados pelo
	 * cron em lotes. Pendentes demais viram reconstrução.
	 *
	 * @return void
	 */
	function consulta_leve_busca_processar_fila(): void {
		$fila = array_keys( (array) ( $GLOBALS['consulta_leve_busca_fila'] ?? array() ) );
		$GLOBALS['consulta_leve_busca_fila'] = array();
		if ( ! $fila ) {
			return;
		}

		try {
			$agora = array_slice( $fila, 0, CONSULTA_LEVE_BUSCA_FILA_AGORA );
			$resto = array_slice( $fila, CONSULTA_LEVE_BUSCA_FILA_AGORA );
			consulta_leve_busca_gravar( $agora );
			if ( $resto ) {
				consulta_leve_busca_adiar( $resto );
			}
		} catch ( \Throwable $erro ) {
			do_action( 'consulta_leve_falha', 'busca-fila', $erro );
		}
	}

	/**
	 * Guarda posts para o cron gravar depois.
	 *
	 * @param int[] $ids Posts.
	 * @return void
	 */
	function consulta_leve_busca_adiar( array $ids ): void {
		$pendentes = get_option( 'consulta_leve_busca_pendentes', array() );
		$pendentes = is_array( $pendentes ) ? $pendentes : array();
		foreach ( $ids as $id ) {
			$pendentes[ (int) $id ] = true;
		}

		if ( count( $pendentes ) > CONSULTA_LEVE_BUSCA_FILA_MAX ) {
			delete_option( 'consulta_leve_busca_pendentes' );
			consulta_leve_busca_reconstruir_iniciar();
			return;
		}

		update_option( 'consulta_leve_busca_pendentes', $pendentes, false );
		if ( ! wp_next_scheduled( CONSULTA_LEVE_BUSCA_GANCHO_PENDENTES ) ) {
			wp_schedule_single_event( time() + 5, CONSULTA_LEVE_BUSCA_GANCHO_PENDENTES );
		}
	}

	/**
	 * Cron: grava até 200 pendentes por vez e se reagenda enquanto houver.
	 *
	 * @return void
	 */
	function consulta_leve_busca_gravar_pendentes(): void {
		$dono = consulta_leve_travar( 'busca_pendentes', 300 );
		if ( '' === $dono ) {
			return;
		}

		try {
			$pendentes = get_option( 'consulta_leve_busca_pendentes', array() );
			$pendentes = is_array( $pendentes ) ? array_keys( $pendentes ) : array();
			$lote      = array_slice( $pendentes, 0, 200 );
			if ( $lote ) {
				consulta_leve_busca_gravar( $lote );
			}
			$resto = array_slice( $pendentes, 200 );
			update_option( 'consulta_leve_busca_pendentes', array_fill_keys( $resto, true ), false );
			if ( $resto ) {
				wp_schedule_single_event( time() + 5, CONSULTA_LEVE_BUSCA_GANCHO_PENDENTES );
			}
		} finally {
			consulta_leve_liberar( 'busca_pendentes', $dono );
		}
	}

	/**
	 * Começa (ou recomeça) a reconstrução por cron, em lotes.
	 *
	 * @return int Geração nova.
	 */
	function consulta_leve_busca_reconstruir_iniciar(): int {
		$geracao = time();
		update_option(
			'consulta_leve_busca_reconstrucao',
			array(
				'geracao' => $geracao,
				'cursor'  => 0,
				'feitos'  => 0,
				'inicio'  => $geracao,
				'agora'   => $geracao,
			),
			false
		);
		wp_schedule_single_event( time(), CONSULTA_LEVE_BUSCA_GANCHO_LOTE, array( $geracao, 0 ) );
		consulta_leve_registrar( 'busca_reconstruir', (string) $geracao, 'iniciada' );

		return $geracao;
	}

	/**
	 * Um lote da reconstrução. Cadeia de geração velha para sozinha.
	 *
	 * @param int $geracao Geração do pedido.
	 * @param int $cursor  Cursor esperado (deixa cada evento único no cron).
	 * @param int $orcamento Segundos para este lote.
	 * @return array Estado depois do lote.
	 */
	function consulta_leve_busca_lote( $geracao = 0, $cursor = 0, $orcamento = 20 ): array {
		$estado = get_option( 'consulta_leve_busca_reconstrucao', array() );
		if ( empty( $estado['geracao'] ) || (int) $estado['geracao'] !== (int) $geracao || ! consulta_leve_busca_ligada() ) {
			return is_array( $estado ) ? $estado : array();
		}

		$dono = consulta_leve_travar( 'busca_lote', 600 );
		if ( '' === $dono ) {
			// Outro lote rodando: tenta de novo em 1 minuto.
			wp_schedule_single_event( time() + 60, CONSULTA_LEVE_BUSCA_GANCHO_LOTE, array( (int) $geracao, (int) $estado['cursor'] ) );
			return $estado;
		}

		try {
			if ( (int) get_option( 'consulta_leve_busca_esquema', 0 ) !== CONSULTA_LEVE_BUSCA_ESQUEMA && ! consulta_leve_busca_instalar() ) {
				return $estado;
			}

			$feito = consulta_leve_em_lotes(
				array(
					'tipos'     => consulta_leve_busca_tipos(),
					'status'    => consulta_leve_busca_status_guardados(),
					'cursor'    => (int) $estado['cursor'],
					'lote'      => 200,
					'orcamento' => max( 1, (int) $orcamento ),
				),
				static function ( $ids ) {
					consulta_leve_busca_gravar( $ids, false );
				}
			);

			$estado['cursor']  = $feito['cursor'];
			$estado['feitos'] += $feito['feitos'];

			if ( '' !== $feito['erro'] ) {
				$estado['agora'] = time();
				update_option( 'consulta_leve_busca_reconstrucao', $estado, false );
				wp_schedule_single_event( time() + 60, CONSULTA_LEVE_BUSCA_GANCHO_LOTE, array( (int) $geracao, (int) $estado['cursor'] ) );
				return $estado;
			}

			if ( $feito['fim'] ) {
				consulta_leve_busca_limpar_orfas();
				update_option( 'consulta_leve_busca_construida', time(), false );
				delete_option( 'consulta_leve_busca_reconstrucao' );
				consulta_leve_cache_tocar( 'busca' );
				consulta_leve_registrar( 'busca_reconstruir', (string) $geracao, 'ok_' . (int) $estado['feitos'] );
				$estado['geracao'] = 0;
				return $estado;
			}

			$estado['agora'] = time();
			update_option( 'consulta_leve_busca_reconstrucao', $estado, false );
			wp_schedule_single_event( time() + 5, CONSULTA_LEVE_BUSCA_GANCHO_LOTE, array( (int) $geracao, (int) $estado['cursor'] ) );
		} finally {
			consulta_leve_liberar( 'busca_lote', $dono );
		}

		return $estado;
	}

	/**
	 * Tira da tabela linhas de posts que não existem mais ou saíram dos tipos.
	 * Só a tabela de busca (cache) muda.
	 *
	 * @return void
	 */
	function consulta_leve_busca_limpar_orfas(): void {
		global $wpdb;

		$tabela = consulta_leve_busca_tabela();
		$tipos  = consulta_leve_busca_tipos();

		$wpdb->query( "DELETE FROM {$tabela} WHERE post_id NOT IN (SELECT ID FROM {$wpdb->posts})" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- nomes de tabela fixos, sem valor externo.

		if ( $tipos ) {
			$marcas = implode( ',', array_fill( 0, count( $tipos ), '%s' ) );
			$wpdb->query( $wpdb->prepare( "DELETE FROM {$tabela} WHERE post_type NOT IN ({$marcas})", $tipos ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare -- marcadores montados acima.
		}
	}

	/**
	 * Conferência diária barata: contagem e última alteração. Diferente,
	 * reconstrói.
	 *
	 * @return bool Pediu reconstrução?
	 */
	function consulta_leve_busca_conferir(): bool {
		global $wpdb;

		if ( 'pronta' !== consulta_leve_busca_estado() ) {
			return false;
		}

		$tipos  = consulta_leve_busca_tipos();
		$status = consulta_leve_busca_status_guardados();
		$t_in   = implode( ',', array_fill( 0, count( $tipos ), '%s' ) );
		$s_in   = implode( ',', array_fill( 0, count( $status ), '%s' ) );
		$tabela = consulta_leve_busca_tabela();

		$posts  = $wpdb->get_row( $wpdb->prepare( "SELECT COUNT(*) AS n, MAX(post_modified_gmt) AS m FROM {$wpdb->posts} WHERE post_type IN ({$t_in}) AND post_status IN ({$s_in})", array_merge( $tipos, $status ) ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare -- marcadores montados acima.
		$indice = $wpdb->get_row( "SELECT COUNT(*) AS n, MAX(modificado) AS m FROM {$tabela}", ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- nome da tabela fixo.

		$igual = is_array( $posts ) && is_array( $indice ) && (int) $posts['n'] === (int) $indice['n'] && (string) $posts['m'] === (string) $indice['m'];
		if ( ! $igual ) {
			consulta_leve_busca_reconstruir_iniciar();
		}

		return ! $igual;
	}

	/**
	 * Termos da busca: palavras para o FULLTEXT (3+ letras, sem stopword do
	 * InnoDB) e palavras para o LIKE (as demais), sem operadores do usuário.
	 *
	 * @param string $termo Texto digitado.
	 * @return array{ft:string[],like:string[]}
	 */
	function consulta_leve_busca_termos( string $termo ): array {
		$stop  = array( 'a', 'about', 'an', 'are', 'as', 'at', 'be', 'by', 'com', 'de', 'en', 'for', 'from', 'how', 'i', 'in', 'is', 'it', 'la', 'of', 'on', 'or', 'that', 'the', 'this', 'to', 'was', 'what', 'when', 'where', 'who', 'will', 'with', 'und', 'www' );
		$norm  = consulta_leve_busca_normalizar( $termo );
		$norm  = (string) preg_replace( '/[+\-<>()~*"@\'\\\\%_]+/', ' ', $norm );
		$saida = array(
			'ft'   => array(),
			'like' => array(),
		);

		foreach ( array_slice( array_unique( array_filter( explode( ' ', $norm ) ) ), 0, 8 ) as $palavra ) {
			$tamanho = function_exists( 'mb_strlen' ) ? mb_strlen( $palavra, 'UTF-8' ) : strlen( $palavra );
			if ( $tamanho < 2 ) {
				continue;
			}
			if ( $tamanho >= 3 && ! in_array( $palavra, $stop, true ) ) {
				$saida['ft'][] = $palavra;
			} else {
				$saida['like'][] = $palavra;
			}
		}

		return $saida;
	}

	/**
	 * IDs que casam com o termo, direto da tabela.
	 *
	 * Opções: `tipos` (subconjunto dos indexados; padrão todos), `status`
	 * (padrão publish), `por_pagina` (1 a 500, padrão 20), `cursor`
	 * (ordem data), `ordem` (data ou relevancia; relevância sem cursor).
	 *
	 * @param string $termo Texto digitado.
	 * @param array  $o     Opções.
	 * @return array{ids:int[],proximo:string,via:string}
	 */
	function consulta_leve_busca_ids( string $termo, array $o = array() ): array {
		$vazio = array(
			'ids'     => array(),
			'proximo' => '',
			'via'     => 'nenhuma',
		);
		if ( 'pronta' !== consulta_leve_busca_estado() ) {
			return $vazio;
		}

		$indexados = consulta_leve_busca_tipos();
		$tipos     = isset( $o['tipos'] ) ? array_values( array_intersect( consulta_leve_lotes_lista( $o['tipos'] ), $indexados ) ) : $indexados;
		$status    = array_values( array_intersect( consulta_leve_lotes_lista( $o['status'] ?? 'publish' ), consulta_leve_busca_status_guardados() ) );
		// Rascunho, privado e agendado só para quem edita posts dos outros:
		// tema que repassar entrada do visitante não vaza conteúdo não publicado.
		if ( array_diff( $status, array( 'publish' ) ) && ! current_user_can( 'edit_others_posts' ) ) {
			$status = array_values( array_intersect( $status, array( 'publish' ) ) );
		}
		$por       = max( 1, min( 500, (int) ( $o['por_pagina'] ?? 20 ) ) );
		$ordem     = ( $o['ordem'] ?? 'data' ) === 'relevancia' ? 'relevancia' : 'data';
		$cursor    = 'data' === $ordem && isset( $o['cursor'] ) ? (string) $o['cursor'] : '';
		$termos    = consulta_leve_busca_termos( $termo );

		if ( ! $tipos || ! $status || ( ! $termos['ft'] && ! $termos['like'] ) ) {
			return $vazio;
		}

		$chave = array( $termos, $tipos, $status, $por, $ordem, $cursor );

		return consulta_leve_lembrar(
			'busca',
			$chave,
			static function () use ( $termos, $tipos, $status, $por, $ordem, $cursor ) {
				return consulta_leve_busca_consultar( $termos, $tipos, $status, $por, $ordem, $cursor );
			},
			array( 'ttl' => 60 )
		);
	}

	/**
	 * Monta e roda o SQL da busca (sem cache).
	 *
	 * @param array    $termos Saída de consulta_leve_busca_termos().
	 * @param string[] $tipos  Tipos.
	 * @param string[] $status Status.
	 * @param int      $por    Itens por página.
	 * @param string   $ordem  data ou relevancia.
	 * @param string   $cursor Cursor (ordem data).
	 * @return array{ids:int[],proximo:string,via:string}
	 */
	function consulta_leve_busca_consultar( array $termos, array $tipos, array $status, int $por, string $ordem, string $cursor ): array {
		global $wpdb;

		$tabela  = consulta_leve_busca_tabela();
		$valores = array_merge( $tipos, $status );
		$where   = 'post_type IN (' . implode( ',', array_fill( 0, count( $tipos ), '%s' ) ) . ') AND post_status IN (' . implode( ',', array_fill( 0, count( $status ), '%s' ) ) . ')';
		$ft      = '1' === (string) get_option( 'consulta_leve_busca_fulltext', '0' ) && $termos['ft'];
		$like    = $ft ? $termos['like'] : array_merge( $termos['ft'], $termos['like'] );
		$pontos  = '';

		if ( $ft ) {
			$booleano = implode( ' ', array_map( static function ( $p ) {
				return '+' . $p . '*';
			}, $termos['ft'] ) );
			$where   .= ' AND MATCH(titulo,texto) AGAINST (%s IN BOOLEAN MODE)';
			$valores[] = $booleano;
			if ( 'relevancia' === $ordem ) {
				$pontos = $booleano;
			}
		}

		// LIKE só nas linhas já filtradas por tipo, status e FULLTEXT.
		foreach ( $like as $palavra ) {
			$where    .= ' AND texto_norm LIKE %s';
			$valores[] = '%' . $wpdb->esc_like( $palavra ) . '%';
		}

		$pos = '' !== $cursor ? consulta_leve_cursor_decodificar( $cursor, 'data' ) : null;
		if ( $pos ) {
			$where    .= ' AND ( post_date < %s OR ( post_date = %s AND post_id < %d ) )';
			$valores[] = $pos['d'];
			$valores[] = $pos['d'];
			$valores[] = $pos['i'];
		}

		if ( '' !== $pontos ) {
			$sql       = "SELECT post_id, post_date FROM {$tabela} WHERE {$where} ORDER BY MATCH(titulo,texto) AGAINST (%s IN BOOLEAN MODE) DESC, post_id DESC LIMIT %d";
			$valores[] = $pontos;
		} else {
			$sql = "SELECT post_id, post_date FROM {$tabela} WHERE {$where} ORDER BY post_date DESC, post_id DESC LIMIT %d";
		}
		$valores[] = $por + 1;

		$linhas = $wpdb->get_results( $wpdb->prepare( $sql, $valores ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- SQL montado só com marcadores; valores passam pelo prepare.
		$linhas = is_array( $linhas ) ? $linhas : array();
		$mais   = count( $linhas ) > $por;
		$linhas = array_slice( $linhas, 0, $por );

		$proximo = '';
		if ( $mais && 'data' === $ordem && $linhas ) {
			$ultima  = end( $linhas );
			$proximo = consulta_leve_cursor_codificar(
				array(
					'o' => 'data',
					'i' => (int) $ultima['post_id'],
					'd' => (string) $ultima['post_date'],
				)
			);
		}

		return array(
			'ids'     => array_map( 'intval', array_column( $linhas, 'post_id' ) ),
			'proximo' => $proximo,
			'via'     => $ft ? 'fulltext' : 'like',
		);
	}

	/**
	 * Busca do núcleo lendo a tabela (indice_na_busca, desligado por padrão).
	 * Só visitante, só quando todos os tipos pesquisados estão indexados e a
	 * tabela está pronta; senão a busca do núcleo segue intocada.
	 *
	 * @param string    $sql SQL de busca montado pelo WP_Query.
	 * @param \WP_Query $q   Consulta.
	 * @return string
	 */
	function consulta_leve_busca_no_nucleo( $sql, $q ) {
		if ( '' === (string) $sql || is_admin() || ! consulta_leve_opcao( 'indice_na_busca' ) || is_user_logged_in() || 'pronta' !== consulta_leve_busca_estado() ) {
			return $sql;
		}

		$termo = (string) $q->get( 's' );
		$tipos = $q->get( 'post_type' );
		if ( '' === $termo ) {
			return $sql;
		}
		if ( empty( $tipos ) || 'any' === $tipos ) {
			$tipos = get_post_types( array( 'exclude_from_search' => false ) );
		}
		// Anexos não entram na tabela; com ela ligada, a busca do site não os lista.
		$tipos = array_values( array_diff( (array) $tipos, array( 'attachment' ) ) );
		if ( array_diff( $tipos, consulta_leve_busca_tipos() ) ) {
			return $sql;
		}

		$achado = consulta_leve_busca_ids(
			$termo,
			array(
				'tipos'      => $tipos,
				'status'     => 'publish',
				'por_pagina' => 500,
				'ordem'      => 'relevancia',
			)
		);

		global $wpdb;
		if ( ! $achado['ids'] ) {
			return ' AND 1=0 ';
		}

		return " AND {$wpdb->posts}.ID IN (" . implode( ',', array_map( 'intval', $achado['ids'] ) ) . ') ';
	}

	/**
	 * Ganchos de sincronia, cron e instalação.
	 *
	 * @return void
	 */
	function consulta_leve_busca_ganchos(): void {
		add_action(
			'save_post',
			static function ( $id, $post = null ) {
				if ( $post && ! wp_is_post_revision( $id ) && ! wp_is_post_autosave( $id ) ) {
					consulta_leve_busca_enfileirar( $id );
				}
			},
			20,
			2
		);
		foreach ( array( 'deleted_post', 'trashed_post', 'untrashed_post' ) as $gancho ) {
			add_action( $gancho, 'consulta_leve_busca_enfileirar' );
		}
		foreach ( array( 'added_post_meta', 'updated_post_meta', 'deleted_post_meta' ) as $gancho ) {
			add_action(
				$gancho,
				static function ( $meta_id, $post_id = 0, $chave = '' ) {
					if ( in_array( (string) $chave, (array) consulta_leve_opcao( 'indice_metas' ), true ) ) {
						consulta_leve_busca_enfileirar( $post_id );
					}
				},
				10,
				3
			);
		}

		add_action( CONSULTA_LEVE_BUSCA_GANCHO_LOTE, 'consulta_leve_busca_lote', 10, 2 );
		add_action( CONSULTA_LEVE_BUSCA_GANCHO_DIARIO, 'consulta_leve_busca_conferir' );
		add_action( CONSULTA_LEVE_BUSCA_GANCHO_PENDENTES, 'consulta_leve_busca_gravar_pendentes' );
		add_filter( 'posts_search', 'consulta_leve_busca_no_nucleo', 20, 2 );

		// Painel: instala, começa a primeira construção e garante a conferência diária.
		add_action(
			'admin_init',
			static function () {
				if ( ! consulta_leve_busca_ligada() ) {
					return;
				}
				$estado = consulta_leve_busca_estado();
				if ( 'sem_tabela' === $estado ) {
					$dono = consulta_leve_travar( 'busca_instalar', 120 );
					if ( '' !== $dono ) {
						if ( consulta_leve_busca_instalar() && ! get_option( 'consulta_leve_busca_construida', false ) ) {
							consulta_leve_busca_reconstruir_iniciar();
						}
						consulta_leve_liberar( 'busca_instalar', $dono );
					}
				} elseif ( 'construindo' === $estado ) {
					$reconstrucao = get_option( 'consulta_leve_busca_reconstrucao', array() );
					if ( empty( $reconstrucao['geracao'] ) ) {
						consulta_leve_busca_reconstruir_iniciar();
					} elseif ( time() - (int) ( $reconstrucao['agora'] ?? 0 ) > 600 ) {
						// Cadeia parada há 10 min (cron perdido, tabela desligada no meio): retoma do cursor.
						$reconstrucao['agora'] = time();
						update_option( 'consulta_leve_busca_reconstrucao', $reconstrucao, false );
						wp_schedule_single_event( time(), CONSULTA_LEVE_BUSCA_GANCHO_LOTE, array( (int) $reconstrucao['geracao'], (int) $reconstrucao['cursor'] ) );
					}
				}
				if ( ! wp_next_scheduled( CONSULTA_LEVE_BUSCA_GANCHO_DIARIO ) ) {
					wp_schedule_event( time() + HOUR_IN_SECONDS, 'daily', CONSULTA_LEVE_BUSCA_GANCHO_DIARIO );
				}
			}
		);

		if ( function_exists( 'register_deactivation_hook' ) && defined( 'CONSULTA_LEVE_ARQUIVO' ) ) {
			register_deactivation_hook(
				CONSULTA_LEVE_ARQUIVO,
				static function () {
					wp_clear_scheduled_hook( CONSULTA_LEVE_BUSCA_GANCHO_DIARIO );
					wp_unschedule_hook( CONSULTA_LEVE_BUSCA_GANCHO_LOTE );
					wp_unschedule_hook( CONSULTA_LEVE_BUSCA_GANCHO_PENDENTES );
				}
			);
		}
	}

	consulta_leve_busca_ganchos();
}
