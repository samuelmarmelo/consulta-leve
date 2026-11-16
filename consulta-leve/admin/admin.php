<?php
/**
 * Painel: Ferramentas, Consulta Leve (contrato, seção 12).
 *
 * Abas Diagnóstico, Índices, Busca e Configurações. Toda ação vai por
 * admin-post com nonce próprio e `manage_options`; a volta traz só um código
 * curto, traduzido por uma lista fechada (nada da URL vira texto). Estilo
 * do próprio admin do WordPress, sem enfeite.
 *
 * @package ConsultaLeve
 */

defined( 'ABSPATH' ) || exit;

/** Slug da página. */
const CONSULTA_LEVE_PAGINA = 'consulta-leve';

if ( ! function_exists( 'consulta_leve_admin_menu' ) ) {

	/**
	 * Registra a página em Ferramentas.
	 *
	 * @return void
	 */
	function consulta_leve_admin_menu(): void {
		add_management_page(
			__( 'Consulta Leve', 'consulta-leve' ),
			__( 'Consulta Leve', 'consulta-leve' ),
			'manage_options',
			CONSULTA_LEVE_PAGINA,
			'consulta_leve_admin_pagina'
		);
	}

	/**
	 * Endereço de uma aba.
	 *
	 * @param string $aba   Aba.
	 * @param string $aviso Código do aviso.
	 * @return string
	 */
	function consulta_leve_admin_url( string $aba = 'diagnostico', string $aviso = '' ): string {
		$args = array(
			'page' => CONSULTA_LEVE_PAGINA,
			'aba'  => $aba,
		);
		if ( '' !== $aviso ) {
			$args['cl_aviso'] = $aviso;
		}

		return add_query_arg( $args, admin_url( 'tools.php' ) );
	}

	/**
	 * Textos dos avisos (lista fechada).
	 *
	 * @return array<string,array{0:string,1:string}> código => (tipo, texto).
	 */
	function consulta_leve_admin_avisos(): array {
		return array(
			'salvo'           => array( 'success', __( 'Configurações salvas.', 'consulta-leve' ) ),
			'medido'          => array( 'success', __( 'Diagnóstico medido de novo.', 'consulta-leve' ) ),
			'indice_ok'       => array( 'success', __( 'Pronto. O índice foi alterado e o registro anotou a ação.', 'consulta-leve' ) ),
			'indice_confirmar' => array( 'warning', __( 'Tabela com mais de 1 milhão de linhas: marque a confirmação e prefira um horário de pouco movimento.', 'consulta-leve' ) ),
			'indice_coberto'  => array( 'info', __( 'Outro índice já cobre estas colunas. Nada foi criado.', 'consulta-leve' ) ),
			'indice_criado'   => array( 'info', __( 'O índice já existe.', 'consulta-leve' ) ),
			'indice_ausente'  => array( 'info', __( 'O índice não existe. Nada foi removido.', 'consulta-leve' ) ),
			'indice_ocupado'  => array( 'warning', __( 'Outra alteração de índice está rodando. Tente em alguns minutos.', 'consulta-leve' ) ),
			'indice_sem_suporte' => array( 'warning', __( 'Este banco (SQLite) não recebe índices por aqui. Meça no MySQL do servidor.', 'consulta-leve' ) ),
			'indice_banco'    => array( 'error', __( 'O banco recusou a alteração. Nada mudou; veja o log de erros do servidor.', 'consulta-leve' ) ),
			'indice_desconhecido' => array( 'error', __( 'Índice fora da lista. Nada foi feito.', 'consulta-leve' ) ),
			'busca_iniciada'  => array( 'success', __( 'Reconstrução da tabela de busca iniciada. Ela continua sozinha em segundo plano.', 'consulta-leve' ) ),
			'busca_desligada' => array( 'warning', __( 'Ligue a tabela de busca e escolha os tipos de conteúdo nas Configurações.', 'consulta-leve' ) ),
			'busca_falhou'    => array( 'error', __( 'Não foi possível criar a tabela de busca. Veja o log de erros do servidor.', 'consulta-leve' ) ),
		);
	}

	/**
	 * Confere quem pede e o nonce da ação. Falhou, para tudo.
	 *
	 * @param string $acao Ação.
	 * @return void
	 */
	function consulta_leve_admin_conferir( string $acao ): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Você não tem permissão para isto.', 'consulta-leve' ), '', array( 'response' => 403 ) );
		}
		check_admin_referer( 'consulta_leve_' . $acao );
	}

	/**
	 * Volta para a aba com o aviso.
	 *
	 * @param string $aba   Aba.
	 * @param string $aviso Código.
	 * @return void
	 */
	function consulta_leve_admin_voltar( string $aba, string $aviso ): void {
		wp_safe_redirect( consulta_leve_admin_url( $aba, $aviso ) );
		exit;
	}

	/**
	 * Salvar configurações.
	 *
	 * @return void
	 */
	function consulta_leve_admin_salvar(): void {
		consulta_leve_admin_conferir( 'salvar' );

		$recebido = isset( $_POST['cl'] ) && is_array( $_POST['cl'] ) ? wp_unslash( $_POST['cl'] ) : array(); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- saneado chave a chave por consulta_leve_config_sanitizar().
		$novos    = array();

		foreach ( consulta_leve_config_esquema() as $chave => $regra ) {
			if ( 'bool' === $regra[0] ) {
				$novos[ $chave ] = ! empty( $recebido[ $chave ] );
			} elseif ( array_key_exists( $chave, $recebido ) ) {
				$valor           = $recebido[ $chave ];
				$novos[ $chave ] = is_array( $valor ) ? array_map( 'sanitize_text_field', $valor ) : sanitize_text_field( (string) $valor );
			} elseif ( 'lista' === $regra[0] ) {
				$novos[ $chave ] = array(); // Nenhuma caixa marcada.
			}
		}

		consulta_leve_config_gravar( $novos );
		consulta_leve_registrar( 'config_salvar', 'config', 'ok' );
		consulta_leve_admin_voltar( 'config', 'salvo' );
	}

	/**
	 * Medir o diagnóstico de novo.
	 *
	 * @return void
	 */
	function consulta_leve_admin_medir(): void {
		consulta_leve_admin_conferir( 'medir' );
		consulta_leve_diagnostico( true );
		consulta_leve_admin_voltar( 'diagnostico', 'medido' );
	}

	/**
	 * Criar ou remover um índice.
	 *
	 * @return void
	 */
	function consulta_leve_admin_indice(): void {
		consulta_leve_admin_conferir( 'indice' );

		$id        = isset( $_POST['indice'] ) ? sanitize_key( wp_unslash( $_POST['indice'] ) ) : '';
		$operacao  = isset( $_POST['operacao'] ) && 'remover' === $_POST['operacao'] ? 'remover' : 'criar';
		$confirmar = ! empty( $_POST['confirmar'] );

		$resultado = 'remover' === $operacao ? consulta_leve_indices_remover( $id ) : consulta_leve_indices_criar( $id, $confirmar );
		consulta_leve_cache_tocar( 'diagnostico' );

		consulta_leve_admin_voltar( 'indices', 'ok' === $resultado ? 'indice_ok' : 'indice_' . $resultado );
	}

	/**
	 * Reconstruir a tabela de busca (primeiro lote já nesta requisição).
	 *
	 * @return void
	 */
	function consulta_leve_admin_busca(): void {
		consulta_leve_admin_conferir( 'busca' );

		if ( ! function_exists( 'consulta_leve_busca_ligada' ) || ! consulta_leve_busca_ligada() ) {
			consulta_leve_admin_voltar( 'busca', 'busca_desligada' );
		}
		if ( (int) get_option( 'consulta_leve_busca_esquema', 0 ) !== CONSULTA_LEVE_BUSCA_ESQUEMA && ! consulta_leve_busca_instalar() ) {
			consulta_leve_admin_voltar( 'busca', 'busca_falhou' );
		}

		$geracao = consulta_leve_busca_reconstruir_iniciar();
		consulta_leve_busca_lote( $geracao, 0, 10 );
		consulta_leve_admin_voltar( 'busca', 'busca_iniciada' );
	}

	/**
	 * Formulário de uma ação (botão com nonce).
	 *
	 * @param string $acao   Ação.
	 * @param string $rotulo Texto do botão.
	 * @param array  $campos Campos escondidos.
	 * @param string $classe Classe do botão.
	 * @param string $extra  HTML extra já escapado (ex.: caixa de confirmação).
	 * @return string
	 */
	function consulta_leve_admin_botao( string $acao, string $rotulo, array $campos = array(), string $classe = 'button', string $extra = '' ): string {
		$html  = '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" class="cl-acao" style="display:inline-block;margin:0 .5em .5em 0">';
		$html .= '<input type="hidden" name="action" value="consulta_leve_' . esc_attr( $acao ) . '">';
		$html .= wp_nonce_field( 'consulta_leve_' . $acao, '_wpnonce', true, false );
		foreach ( $campos as $nome => $valor ) {
			$html .= '<input type="hidden" name="' . esc_attr( $nome ) . '" value="' . esc_attr( $valor ) . '">';
		}
		$html .= $extra . '<button type="submit" class="' . esc_attr( $classe ) . '">' . esc_html( $rotulo ) . '</button></form>';

		return $html;
	}

	/**
	 * Página.
	 *
	 * @return void
	 */
	function consulta_leve_admin_pagina(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		$abas = array(
			'diagnostico' => __( 'Diagnóstico', 'consulta-leve' ),
			'indices'     => __( 'Índices', 'consulta-leve' ),
			'busca'       => __( 'Busca', 'consulta-leve' ),
			'config'      => __( 'Configurações', 'consulta-leve' ),
		);
		$aba  = isset( $_GET['aba'] ) ? sanitize_key( wp_unslash( $_GET['aba'] ) ) : 'diagnostico'; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- só escolhe a aba.
		$aba  = isset( $abas[ $aba ] ) ? $aba : 'diagnostico';

		echo '<div class="wrap"><h1>' . esc_html__( 'Consulta Leve', 'consulta-leve' ) . '</h1>';

		$aviso  = isset( $_GET['cl_aviso'] ) ? sanitize_key( wp_unslash( $_GET['cl_aviso'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- código de uma lista fechada.
		$avisos = consulta_leve_admin_avisos();
		if ( isset( $avisos[ $aviso ] ) ) {
			echo '<div class="notice notice-' . esc_attr( $avisos[ $aviso ][0] ) . ' is-dismissible"><p>' . esc_html( $avisos[ $aviso ][1] ) . '</p></div>';
		}

		echo '<nav class="nav-tab-wrapper">';
		foreach ( $abas as $id => $rotulo ) {
			echo '<a href="' . esc_url( consulta_leve_admin_url( $id ) ) . '" class="nav-tab' . ( $id === $aba ? ' nav-tab-active' : '' ) . '">' . esc_html( $rotulo ) . '</a>';
		}
		echo '</nav>';

		try {
			call_user_func( 'consulta_leve_admin_aba_' . $aba );
		} catch ( \Throwable $erro ) {
			do_action( 'consulta_leve_falha', 'admin-' . $aba, $erro );
			echo '<div class="notice notice-error"><p>' . esc_html__( 'Esta aba falhou ao carregar. O resto do site segue normal; veja o log de erros do servidor.', 'consulta-leve' ) . '</p></div>';
		}

		echo '</div>';
	}

	/**
	 * Linha de tabela com rótulo e valor (ambos escapados aqui).
	 *
	 * @param string $rotulo Rótulo.
	 * @param string $valor  Valor.
	 * @return string
	 */
	function consulta_leve_admin_linha( string $rotulo, string $valor ): string {
		return '<tr><th scope="row">' . esc_html( $rotulo ) . '</th><td>' . esc_html( $valor ) . '</td></tr>';
	}

	/**
	 * Aba Diagnóstico.
	 *
	 * @return void
	 */
	function consulta_leve_admin_aba_diagnostico(): void {
		$d   = consulta_leve_diagnostico();
		$sim = __( 'sim', 'consulta-leve' );
		$nao = __( 'não', 'consulta-leve' );

		echo '<h2>' . esc_html__( 'Recomendações', 'consulta-leve' ) . '</h2>';
		if ( empty( $d['recomendacoes'] ) ) {
			echo '<p>' . esc_html__( 'Nada a apontar com os números atuais.', 'consulta-leve' ) . '</p>';
		}
		foreach ( (array) ( $d['recomendacoes'] ?? array() ) as $r ) {
			echo '<div class="notice inline notice-' . ( 'alerta' === $r['nivel'] ? 'warning' : 'info' ) . '"><p>' . esc_html( $r['texto'] ) . '</p></div>';
		}

		echo '<h2>' . esc_html__( 'Ambiente', 'consulta-leve' ) . '</h2><table class="widefat striped" style="max-width:56rem"><tbody>';
		echo consulta_leve_admin_linha( __( 'PHP', 'consulta-leve' ), (string) ( $d['php'] ?? '' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escapado na função.
		echo consulta_leve_admin_linha( __( 'WordPress', 'consulta-leve' ), (string) ( $d['wordpress'] ?? '' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		echo consulta_leve_admin_linha( __( 'Banco', 'consulta-leve' ), ( $d['motor'] ?? '' ) . ' ' . ( $d['banco'] ?? '' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		/* translators: %s: milissegundos. */
		$cache = ! empty( $d['cache']['externo'] ) ? sprintf( __( 'persistente, %s ms por leitura', 'consulta-leve' ), number_format_i18n( (float) $d['cache']['ms'], 2 ) ) : __( 'só da requisição (sem Redis ou Memcached)', 'consulta-leve' );
		echo consulta_leve_admin_linha( __( 'Cache de objetos', 'consulta-leve' ), $cache ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		echo consulta_leve_admin_linha( __( 'OPcache', 'consulta-leve' ), ! empty( $d['opcache'] ) ? $sim : $nao ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		echo consulta_leve_admin_linha( __( 'Cron real (DISABLE_WP_CRON)', 'consulta-leve' ), ! empty( $d['cron_desligado'] ) ? $sim : $nao ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		echo consulta_leve_admin_linha( __( 'Limite de revisões', 'consulta-leve' ), var_export( $d['revisoes_limite'] ?? true, true ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped,WordPress.PHP.DevelopmentFunctions.error_log_var_export
		/* translators: 1: quantidade, 2: KB. */
		echo consulta_leve_admin_linha( __( 'Opções com autoload', 'consulta-leve' ), sprintf( __( '%1$d opções, %2$s KB', 'consulta-leve' ), (int) ( $d['autoload']['n'] ?? 0 ), number_format_i18n( (float) ( $d['autoload']['kb'] ?? 0 ), 1 ) ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		foreach ( array( 'revisoes' => __( 'Revisões', 'consulta-leve' ), 'rascunhos' => __( 'Rascunhos automáticos', 'consulta-leve' ), 'lixeira' => __( 'Na lixeira', 'consulta-leve' ), 'transients' => __( 'Transients vencidos', 'consulta-leve' ) ) as $k => $rotulo ) {
			echo consulta_leve_admin_linha( $rotulo, number_format_i18n( (int) ( $d['contagens'][ $k ] ?? 0 ) ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		}
		echo '</tbody></table>';

		if ( ! empty( $d['tabelas'] ) ) {
			echo '<h2>' . esc_html__( 'Tabelas', 'consulta-leve' ) . '</h2><table class="widefat striped" style="max-width:56rem"><thead><tr><th>' . esc_html__( 'Tabela', 'consulta-leve' ) . '</th><th>' . esc_html__( 'Linhas (aprox.)', 'consulta-leve' ) . '</th><th>' . esc_html__( 'Tamanho', 'consulta-leve' ) . '</th></tr></thead><tbody>';
			foreach ( $d['tabelas'] as $nome => $t ) {
				echo '<tr><td><code>' . esc_html( $nome ) . '</code></td><td>' . esc_html( number_format_i18n( (int) $t['linhas'] ) ) . '</td><td>' . esc_html( number_format_i18n( (float) $t['mb'], 1 ) ) . ' MB</td></tr>';
			}
			echo '</tbody></table>';
		}

		if ( ! empty( $d['autoload']['maiores'] ) ) {
			echo '<h2>' . esc_html__( 'Maiores opções com autoload', 'consulta-leve' ) . '</h2><table class="widefat striped" style="max-width:56rem"><tbody>';
			foreach ( $d['autoload']['maiores'] as $o ) {
				echo '<tr><td><code>' . esc_html( $o['nome'] ) . '</code></td><td>' . esc_html( number_format_i18n( (float) $o['kb'], 1 ) ) . ' KB</td></tr>';
			}
			echo '</tbody></table>';
		}

		echo '<h2>' . esc_html__( 'Medir uma página', 'consulta-leve' ) . '</h2>';
		echo '<p>' . esc_html__( 'Logado como administrador, abra a página no navegador, abra o console e rode:', 'consulta-leve' ) . '</p>';
		echo '<pre style="white-space:pre-wrap;max-width:56rem;background:#fff;border:1px solid #c3c4c7;padding:.75em">' . esc_html( "fetch(location.href, { headers: { 'X-Consulta-Leve-Perfil': 'explain' } })\n  .then((r) => console.log(JSON.parse(atob(r.headers.get('X-Consulta-Leve-Perfil')))));" ) . '</pre>';
		echo '<p>' . esc_html__( 'A coluna n mostra quantas vezes a mesma consulta se repetiu: número alto é consulta dentro de laço. opcoes_avulsas lista opções lidas uma a uma, candidatas à carga em lote.', 'consulta-leve' ) . '</p>';

		/* translators: %s: data e hora. */
		echo '<p>' . esc_html( sprintf( __( 'Medido em %s.', 'consulta-leve' ), wp_date( 'd/m/Y H:i', (int) ( $d['medido_em'] ?? time() ) ) ) ) . '</p>';
		echo consulta_leve_admin_botao( 'medir', __( 'Medir de novo', 'consulta-leve' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- montado com escape.

		$registro = consulta_leve_registro();
		if ( $registro ) {
			echo '<h2>' . esc_html__( 'Registro', 'consulta-leve' ) . '</h2><table class="widefat striped" style="max-width:56rem"><thead><tr><th>' . esc_html__( 'Quando', 'consulta-leve' ) . '</th><th>' . esc_html__( 'Quem', 'consulta-leve' ) . '</th><th>' . esc_html__( 'Ação', 'consulta-leve' ) . '</th><th>' . esc_html__( 'Alvo', 'consulta-leve' ) . '</th><th>' . esc_html__( 'Resultado', 'consulta-leve' ) . '</th></tr></thead><tbody>';
			foreach ( array_slice( $registro, 0, 20 ) as $r ) {
				$quem = get_userdata( (int) $r['quem'] );
				echo '<tr><td>' . esc_html( wp_date( 'd/m/Y H:i', (int) $r['em'] ) ) . '</td><td>' . esc_html( $quem ? $quem->display_name : '-' ) . '</td><td><code>' . esc_html( $r['acao'] ) . '</code></td><td><code>' . esc_html( $r['alvo'] ) . '</code></td><td>' . esc_html( $r['resultado'] ) . '</td></tr>';
			}
			echo '</tbody></table>';
		}
	}

	/**
	 * Aba Índices.
	 *
	 * @return void
	 */
	function consulta_leve_admin_aba_indices(): void {
		echo '<p style="max-width:56rem">' . esc_html__( 'Índice novo em tabela do WordPress só depois de ver o EXPLAIN. Nada é criado sozinho. Se outro plugin ou o tema já tem um índice com as mesmas colunas, a linha aparece como coberta e não é criada de novo.', 'consulta-leve' ) . '</p>';

		$estados = array(
			'criado'      => __( 'criado por este plugin', 'consulta-leve' ),
			'coberto'     => __( 'coberto por outro índice', 'consulta-leve' ),
			'ausente'     => __( 'ausente', 'consulta-leve' ),
			'sem_suporte' => __( 'sem suporte neste banco', 'consulta-leve' ),
		);

		echo '<table class="widefat striped"><thead><tr><th>' . esc_html__( 'Índice', 'consulta-leve' ) . '</th><th>' . esc_html__( 'Para que serve', 'consulta-leve' ) . '</th><th>' . esc_html__( 'Linhas', 'consulta-leve' ) . '</th><th>' . esc_html__( 'EXPLAIN da consulta típica', 'consulta-leve' ) . '</th><th>' . esc_html__( 'Estado', 'consulta-leve' ) . '</th><th></th></tr></thead><tbody>';
		foreach ( consulta_leve_indices_situacao() as $id => $s ) {
			$colunas = implode(
				', ',
				array_map(
					static function ( $c ) {
						return $c[0] . ( $c[1] ? '(' . $c[1] . ')' : '' );
					},
					$s['colunas']
				)
			);
			$plano   = 'ausente' === $s['estado'] || 'criado' === $s['estado'] ? consulta_leve_indices_explain( $id ) : array();
			$explica = array();
			foreach ( $plano as $p ) {
				$explica[] = sprintf( '%s: %s, %s, %d linhas', $p['tabela'], $p['tipo'], '' !== $p['chave'] ? $p['chave'] : 'sem índice', $p['linhas'] );
			}

			echo '<tr><td><code>' . esc_html( $s['nome'] ) . '</code><br><small>' . esc_html( $s['tabela'] . ' (' . $colunas . ')' ) . '</small></td>';
			echo '<td>' . esc_html( $s['motivo'] ) . '</td>';
			echo '<td>' . esc_html( number_format_i18n( (int) $s['linhas'] ) ) . '</td>';
			echo '<td><small>' . esc_html( implode( ' | ', $explica ) ) . '</small></td>';
			echo '<td>' . esc_html( $estados[ $s['estado'] ] ?? $s['estado'] ) . '</td><td>';
			if ( 'ausente' === $s['estado'] ) {
				$extra = (int) $s['linhas'] > CONSULTA_LEVE_INDICES_LINHAS_ALERTA ? '<label style="display:block"><input type="checkbox" name="confirmar" value="1"> ' . esc_html__( 'Confirmo: tabela grande', 'consulta-leve' ) . '</label>' : '';
				echo consulta_leve_admin_botao( 'indice', __( 'Criar', 'consulta-leve' ), array( 'indice' => $id, 'operacao' => 'criar' ), 'button button-primary', $extra ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- montado com escape.
			} elseif ( 'criado' === $s['estado'] ) {
				echo consulta_leve_admin_botao( 'indice', __( 'Remover', 'consulta-leve' ), array( 'indice' => $id, 'operacao' => 'remover' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- montado com escape.
			}
			echo '</td></tr>';
		}
		echo '</tbody></table>';
	}

	/**
	 * Aba Busca.
	 *
	 * @return void
	 */
	function consulta_leve_admin_aba_busca(): void {
		global $wpdb;

		$estado  = function_exists( 'consulta_leve_busca_estado' ) ? consulta_leve_busca_estado() : 'desligada';
		$rotulos = array(
			'desligada'   => __( 'desligada', 'consulta-leve' ),
			'sem_tabela'  => __( 'ligada, tabela ainda não criada', 'consulta-leve' ),
			'construindo' => __( 'construindo em segundo plano', 'consulta-leve' ),
			'pronta'      => __( 'pronta', 'consulta-leve' ),
		);

		echo '<p style="max-width:56rem">' . esc_html__( 'A tabela de busca guarda uma cópia enxuta e indexada dos tipos de conteúdo escolhidos. A busca rápida e, se você ligar, a busca do site leem dela em vez de varrer o conteúdo inteiro. Enquanto ela não fica pronta, tudo segue pelo caminho normal do WordPress.', 'consulta-leve' ) . '</p>';
		echo '<table class="widefat striped" style="max-width:56rem"><tbody>';
		echo consulta_leve_admin_linha( __( 'Estado', 'consulta-leve' ), $rotulos[ $estado ] ?? $estado ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escapado na função.
		echo consulta_leve_admin_linha( __( 'Tipos indexados', 'consulta-leve' ), function_exists( 'consulta_leve_busca_tipos' ) ? implode( ', ', consulta_leve_busca_tipos() ) : '' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		if ( in_array( $estado, array( 'construindo', 'pronta' ), true ) ) {
			$linhas = (int) $wpdb->get_var( 'SELECT COUNT(*) FROM ' . consulta_leve_busca_tabela() ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- nome da tabela fixo.
			echo consulta_leve_admin_linha( __( 'Linhas na tabela', 'consulta-leve' ), number_format_i18n( $linhas ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
			echo consulta_leve_admin_linha( __( 'Modo de busca', 'consulta-leve' ), '1' === (string) get_option( 'consulta_leve_busca_fulltext', '0' ) ? __( 'FULLTEXT com reserva LIKE', 'consulta-leve' ) : __( 'LIKE no texto normalizado', 'consulta-leve' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
			$reconstrucao = get_option( 'consulta_leve_busca_reconstrucao', array() );
			if ( ! empty( $reconstrucao['geracao'] ) ) {
				echo consulta_leve_admin_linha( __( 'Reconstrução', 'consulta-leve' ), sprintf( /* translators: %d: posts. */ __( '%d posts gravados até agora', 'consulta-leve' ), (int) $reconstrucao['feitos'] ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
			}
			$construida = (int) get_option( 'consulta_leve_busca_construida', 0 );
			if ( $construida ) {
				echo consulta_leve_admin_linha( __( 'Última construção completa', 'consulta-leve' ), wp_date( 'd/m/Y H:i', $construida ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
			}
		}
		echo '</tbody></table><p></p>';

		if ( 'desligada' !== $estado ) {
			echo consulta_leve_admin_botao( 'busca', __( 'Reconstruir agora', 'consulta-leve' ), array(), 'button button-primary' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- montado com escape.
		}
		echo '<h2>' . esc_html__( 'Busca rápida no site', 'consulta-leve' ) . '</h2><p>' . esc_html__( 'Com a rota pública ligada nas Configurações, use o shortcode em qualquer página ou bloco de shortcode:', 'consulta-leve' ) . '</p><pre style="background:#fff;border:1px solid #c3c4c7;padding:.75em;max-width:56rem">[consulta_leve_busca tipos="post,page" limite="8"]</pre>';
	}

	/**
	 * Campo de configuração.
	 *
	 * @param string $chave  Chave.
	 * @param string $rotulo Rótulo.
	 * @param string $ajuda  Texto de ajuda.
	 * @return void
	 */
	function consulta_leve_admin_campo( string $chave, string $rotulo, string $ajuda = '' ): void {
		$regra = consulta_leve_config_esquema()[ $chave ];
		$valor = consulta_leve_opcao( $chave );
		$nome  = 'cl[' . $chave . ']';
		$id    = 'cl-' . $chave;

		echo '<tr><th scope="row"><label for="' . esc_attr( $id ) . '">' . esc_html( $rotulo ) . '</label></th><td>';
		if ( 'bool' === $regra[0] ) {
			echo '<input type="checkbox" id="' . esc_attr( $id ) . '" name="' . esc_attr( $nome ) . '" value="1"' . checked( (bool) $valor, true, false ) . '>';
		} elseif ( 'int' === $regra[0] ) {
			echo '<input type="number" class="small-text" id="' . esc_attr( $id ) . '" name="' . esc_attr( $nome ) . '" value="' . esc_attr( (string) $valor ) . '" min="' . (int) $regra[1] . '" max="' . (int) $regra[2] . '">';
		} elseif ( 'escolha' === $regra[0] ) {
			$textos = array(
				'nucleo'        => __( 'título, resumo e conteúdo (padrão do WordPress)', 'consulta-leve' ),
				'titulo_resumo' => __( 'título e resumo', 'consulta-leve' ),
				'titulo'        => __( 'só título', 'consulta-leve' ),
			);
			echo '<select id="' . esc_attr( $id ) . '" name="' . esc_attr( $nome ) . '">';
			foreach ( $regra[1] as $opcao ) {
				echo '<option value="' . esc_attr( $opcao ) . '"' . selected( $valor, $opcao, false ) . '>' . esc_html( $textos[ $opcao ] ?? $opcao ) . '</option>';
			}
			echo '</select>';
		} elseif ( 'indice_tipos' === $chave ) {
			$cobertos = consulta_leve_indice_tipos_cobertos();
			foreach ( get_post_types( array( 'show_ui' => true ), 'objects' ) as $tipo ) {
				// Tipos internos do WordPress (anexos, blocos, navegação, modelos) ficam de fora.
				if ( 'attachment' === $tipo->name || 0 === strpos( $tipo->name, 'wp_' ) ) {
					continue;
				}
				$coberto = in_array( $tipo->name, $cobertos, true );
				echo '<label style="display:inline-block;margin-right:1em"><input type="checkbox" name="' . esc_attr( $nome ) . '[]" value="' . esc_attr( $tipo->name ) . '"' . checked( in_array( $tipo->name, (array) $valor, true ), true, false ) . disabled( $coberto, true, false ) . '> ' . esc_html( $tipo->labels->name ) . ( $coberto ? ' (' . esc_html__( 'já indexado pelo tema', 'consulta-leve' ) . ')' : '' ) . '</label>';
			}
		} else {
			echo '<input type="text" class="regular-text" id="' . esc_attr( $id ) . '" name="' . esc_attr( $nome ) . '" value="' . esc_attr( implode( ', ', (array) $valor ) ) . '">';
		}
		if ( '' !== $ajuda ) {
			echo '<p class="description">' . esc_html( $ajuda ) . '</p>';
		}
		echo '</td></tr>';
	}

	/**
	 * Aba Configurações.
	 *
	 * @return void
	 */
	function consulta_leve_admin_aba_config(): void {
		$dispensados = consulta_leve_dispensa_do_tema();
		if ( $dispensados ) {
			/* translators: %s: lista de métodos. */
			echo '<div class="notice inline notice-info"><p>' . esc_html( sprintf( __( 'O tema já faz e dispensou: %s. Esses itens ficam parados mesmo marcados.', 'consulta-leve' ), implode( ', ', $dispensados ) ) ) . '</p></div>';
		}

		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '"><input type="hidden" name="action" value="consulta_leve_salvar">';
		wp_nonce_field( 'consulta_leve_salvar' );

		echo '<h2>' . esc_html__( 'Consultas', 'consulta-leve' ) . '</h2><table class="form-table" role="presentation"><tbody>';
		consulta_leve_admin_campo( 'argumentos', __( 'Argumentos enxutos', 'consulta-leve' ), __( 'Completa consultas marcadas com consulta_leve sem mudar o que o tema já definiu.', 'consulta-leve' ) );
		consulta_leve_admin_campo( 'teto_global', __( 'Teto global', 'consulta-leve' ), __( 'Corta consultas secundárias do site que pedem todos os posts completos. Deixe desligado se o tema lista tudo de propósito.', 'consulta-leve' ) );
		consulta_leve_admin_campo( 'teto', __( 'Valor do teto', 'consulta-leve' ) );
		consulta_leve_admin_campo( 'primar_opcoes', __( 'Opções em lote', 'consulta-leve' ), __( 'Lê de uma vez as opções da lista abaixo (só sem cache de objetos persistente).', 'consulta-leve' ) );
		consulta_leve_admin_campo( 'opcoes_lote', __( 'Lista de opções', 'consulta-leve' ), __( 'Nomes separados por vírgula. O perfil (aba Diagnóstico) mostra as opções lidas uma a uma.', 'consulta-leve' ) );
		consulta_leve_admin_campo( 'cache_ttl', __( 'Validade do cache (s)', 'consulta-leve' ) );
		echo '</tbody></table>';

		echo '<h2>' . esc_html__( 'Busca do site', 'consulta-leve' ) . '</h2><table class="form-table" role="presentation"><tbody>';
		consulta_leve_admin_campo( 'busca', __( 'Busca contida', 'consulta-leve' ), __( 'Encurta o termo e não consulta o banco para termo curto demais.', 'consulta-leve' ) );
		consulta_leve_admin_campo( 'busca_min', __( 'Letras mínimas', 'consulta-leve' ) );
		consulta_leve_admin_campo( 'busca_max', __( 'Letras máximas', 'consulta-leve' ) );
		consulta_leve_admin_campo( 'busca_palavras', __( 'Palavras máximas', 'consulta-leve' ) );
		consulta_leve_admin_campo( 'busca_colunas', __( 'Onde procurar', 'consulta-leve' ), __( 'Menos colunas, menos texto lido. Nunca alarga o que o tema já estreitou.', 'consulta-leve' ) );
		consulta_leve_admin_campo( 'busca_noindex', __( 'noindex na busca', 'consulta-leve' ), __( 'Páginas de resultado ficam fora do Google (evita páginas fracas indexadas).', 'consulta-leve' ) );
		consulta_leve_admin_campo( 'busca_limite', __( 'Buscas por minuto por IP', 'consulta-leve' ), __( '0 desliga. Só para visitantes.', 'consulta-leve' ) );
		echo '</tbody></table>';

		echo '<h2>' . esc_html__( 'Tabela de busca', 'consulta-leve' ) . '</h2><table class="form-table" role="presentation"><tbody>';
		consulta_leve_admin_campo( 'indice_busca', __( 'Ligar tabela de busca', 'consulta-leve' ), __( 'Cria uma tabela própria e a mantém em dia sozinha. Os posts não mudam.', 'consulta-leve' ) );
		consulta_leve_admin_campo( 'indice_tipos', __( 'Tipos indexados', 'consulta-leve' ) );
		consulta_leve_admin_campo( 'indice_metas', __( 'Metas no texto', 'consulta-leve' ), __( 'Chaves de meta cujo valor entra na busca, separadas por vírgula.', 'consulta-leve' ) );
		consulta_leve_admin_campo( 'indice_na_busca', __( 'Busca do site pela tabela', 'consulta-leve' ), __( 'Para visitantes, quando todos os tipos pesquisados estão indexados.', 'consulta-leve' ) );
		echo '</tbody></table>';

		echo '<h2>' . esc_html__( 'Rota pública e painel', 'consulta-leve' ) . '</h2><table class="form-table" role="presentation"><tbody>';
		consulta_leve_admin_campo( 'rest', __( 'Busca rápida (REST)', 'consulta-leve' ), __( 'Liga /wp-json/consulta-leve/v1/busca e o shortcode de busca rápida. Só lê conteúdo publicado.', 'consulta-leve' ) );
		consulta_leve_admin_campo( 'rest_por_minuto', __( 'Pedidos por minuto por IP', 'consulta-leve' ) );
		consulta_leve_admin_campo( 'monitor', __( 'Contador na barra do admin', 'consulta-leve' ) );
		echo '</tbody></table>';

		submit_button( __( 'Salvar', 'consulta-leve' ) );
		echo '</form>';
	}

	/**
	 * Link "Configurações" na tela Plugins.
	 *
	 * @param array $links Links.
	 * @return array
	 */
	function consulta_leve_admin_links( $links ) {
		if ( is_array( $links ) && current_user_can( 'manage_options' ) ) {
			array_unshift( $links, '<a href="' . esc_url( consulta_leve_admin_url( 'config' ) ) . '">' . esc_html__( 'Configurações', 'consulta-leve' ) . '</a>' );
		}

		return $links;
	}

	add_action( 'admin_menu', 'consulta_leve_admin_menu' );
	add_action( 'admin_post_consulta_leve_salvar', 'consulta_leve_admin_salvar' );
	add_action( 'admin_post_consulta_leve_medir', 'consulta_leve_admin_medir' );
	add_action( 'admin_post_consulta_leve_indice', 'consulta_leve_admin_indice' );
	add_action( 'admin_post_consulta_leve_busca', 'consulta_leve_admin_busca' );
	add_filter( 'plugin_action_links_' . plugin_basename( CONSULTA_LEVE_ARQUIVO ), 'consulta_leve_admin_links' );
}
