<?php
/**
 * Cabeçalhos de segurança também no `.htaccess` (contrato, seção 15.2).
 *
 * Opcional, desligado por padrão e só por clique. Escreve um bloco próprio,
 * gerado de lista fechada, confere o resultado pedindo o próprio site e volta
 * tudo se algo sair diferente. Nenhum outro arquivo do servidor é tocado.
 *
 * @package ConsultaLeve
 */

defined( 'ABSPATH' ) || exit;

/** Estado (autoload ligado, pequeno). */
const CONSULTA_LEVE_OPCAO_HTACCESS = 'consulta_leve_htaccess';

/** Cópia do arquivo de antes da última escrita (sem autoload). */
const CONSULTA_LEVE_OPCAO_HTACCESS_COPIA = 'consulta_leve_htaccess_copia';

/** Marcadores do bloco próprio. */
const CONSULTA_LEVE_HTACCESS_INICIO = '# BEGIN Consulta Leve';
const CONSULTA_LEVE_HTACCESS_FIM    = '# END Consulta Leve';

/** Tamanho máximo do arquivo, em bytes. */
const CONSULTA_LEVE_HTACCESS_MAX = 131072;

/** Trava (s), limite de cada pedido da conferência (s) e intervalo da conferência no painel (s). */
const CONSULTA_LEVE_HTACCESS_TRAVA_S   = 120;
const CONSULTA_LEVE_HTACCESS_PEDIDO_S  = 5;
const CONSULTA_LEVE_HTACCESS_REVISAO_S = 3600;

/** Valor enviado pelo PHP no modo sonda da conferência. */
const CONSULTA_LEVE_HTACCESS_SONDA = 'consulta-leve-sonda';

if ( ! function_exists( 'consulta_leve_htaccess_bloco' ) ) {

	/* Regras puras -------------------------------------------------------------- */

	/**
	 * O servidor lê `.htaccess`?
	 *
	 * @param string $software SERVER_SOFTWARE.
	 * @return bool
	 */
	function consulta_leve_htaccess_servidor_ok( string $software ): bool {
		return 1 === preg_match( '#^(?:apache(?:[/\s]|$)|(?:open)?litespeed(?:[/\s]|$))#i', $software );
	}

	/**
	 * Bloco completo, com a quebra de linha final ('' sem nenhum cabeçalho).
	 * Nomes e valores vêm só da lista fechada de consulta_leve_cabecalhos_lista().
	 *
	 * @param array  $config Configuração (chaves `cab_*`).
	 * @param string $eol    Quebra de linha do arquivo.
	 * @return string
	 */
	function consulta_leve_htaccess_bloco( array $config, string $eol = "\n" ): string {
		$eol    = "\r\n" === $eol ? "\r\n" : "\n";
		$linhas = array();
		foreach ( consulta_leve_cabecalhos_lista( $config, true, array() ) as $nome => $valor ) {
			$condicao = "-z resp('" . $nome . "')";
			if ( 'X-Frame-Options' === $nome ) {
				$condicao .= " && -z resp('X-Consulta-Leve-Quadro') && -z resp('X-WP-embed')";
			}
			if ( 'Content-Security-Policy' === $nome || 'Strict-Transport-Security' === $nome ) {
				$condicao = "%{HTTPS} == 'on' && " . $condicao;
			}
			$linhas[] = 'Header set ' . $nome . ' "' . $valor . '" "expr=' . $condicao . '"';
		}
		if ( ! $linhas ) {
			return '';
		}

		return implode(
			$eol,
			array_merge(
				array( CONSULTA_LEVE_HTACCESS_INICIO, '# Gerado pelo plugin; mude as escolhas no painel.', '<IfModule mod_headers.c>' ),
				$linhas,
				array( '</IfModule>', CONSULTA_LEVE_HTACCESS_FIM, '' )
			)
		);
	}

	/**
	 * Lê a estrutura do arquivo.
	 *
	 * @param string $conteudo Bytes do arquivo.
	 * @return array{motivo:string,inicio:int,fim:int,wp:int,eol:string} Posições em bytes; -1 sem bloco ou sem marcador.
	 */
	function consulta_leve_htaccess_analisar( string $conteudo ): array {
		$r = array(
			'motivo' => '',
			'inicio' => -1,
			'fim'    => -1,
			'wp'     => -1,
			'eol'    => 1 === preg_match( '/^[^\n]*\r\n/', $conteudo ) ? "\r\n" : "\n",
		);
		if ( false !== strpos( $conteudo, "\0" ) ) {
			$r['motivo'] = 'binario';
			return $r;
		}

		$total       = strlen( $conteudo );
		$pos         = 0 === strpos( $conteudo, "\xEF\xBB\xBF" ) ? 3 : 0;
		$dentro      = false;
		$continuacao = false;
		$nivel       = 0;
		while ( $pos < $total ) {
			$nl    = strpos( $conteudo, "\n", $pos );
			$fim   = false === $nl ? $total : $nl + 1;
			$linha = trim( substr( $conteudo, $pos, $fim - $pos ) );

			if ( 1 === preg_match( '/^#+\s*(BEGIN|END)\s+Consulta\s+Leve$/i', $linha ) ) {
				if ( $continuacao ) {
					$r['motivo'] = 'continuacao';
					return $r;
				}
				if ( CONSULTA_LEVE_HTACCESS_INICIO === $linha && -1 === $r['inicio'] ) {
					$r['inicio'] = $pos;
					$dentro      = true;
				} elseif ( CONSULTA_LEVE_HTACCESS_FIM === $linha && $dentro ) {
					$r['fim'] = $fim;
					$dentro   = false;
				} else {
					$r['motivo'] = 'marcadores';
					return $r;
				}
			} elseif ( $dentro && 1 === preg_match( '/^#+\s*(?:BEGIN|END)\b/i', $linha ) ) {
				$r['motivo'] = 'marcadores';
				return $r;
			} elseif ( '# BEGIN WordPress' === $linha ) {
				if ( -1 !== $r['wp'] ) {
					$r['motivo'] = 'marcador_wp';
					return $r;
				}
				if ( $continuacao || 0 !== $nivel ) {
					$r['motivo'] = $continuacao ? 'continuacao' : 'secao';
					return $r;
				}
				$r['wp'] = $pos;
			} elseif ( ! $continuacao && 1 === preg_match( '#^<(/?)[a-z]#i', $linha, $m ) ) {
				$nivel += '/' === $m[1] ? -1 : 1;
			}

			$continuacao = '\\' === substr( $linha, -1 );
			$pos         = $fim;
		}
		if ( $dentro ) {
			$r['motivo'] = 'marcadores';
		}

		return $r;
	}

	/**
	 * Arquivo sem o bloco próprio. O resto fica igual byte a byte.
	 *
	 * @param string $conteudo Bytes do arquivo.
	 * @return array{motivo:string,conteudo:string} Com motivo, o conteúdo volta como veio.
	 */
	function consulta_leve_htaccess_sem_bloco( string $conteudo ): array {
		$a = consulta_leve_htaccess_analisar( $conteudo );
		if ( '' === $a['motivo'] && -1 !== $a['inicio'] ) {
			$conteudo = substr( $conteudo, 0, $a['inicio'] ) . substr( $conteudo, $a['fim'] );
		}

		return array(
			'motivo'   => $a['motivo'],
			'conteudo' => $conteudo,
		);
	}

	/**
	 * Arquivo com o bloco próprio, antes de `# BEGIN WordPress` ou no topo.
	 *
	 * @param string $conteudo Bytes do arquivo.
	 * @param array  $config   Configuração (chaves `cab_*`).
	 * @return array{motivo:string,conteudo:string}
	 */
	function consulta_leve_htaccess_com_bloco( string $conteudo, array $config ): array {
		$limpo = consulta_leve_htaccess_sem_bloco( $conteudo );
		if ( '' !== $limpo['motivo'] ) {
			return $limpo;
		}
		$a = consulta_leve_htaccess_analisar( $limpo['conteudo'] );
		if ( '' !== $a['motivo'] ) {
			return array(
				'motivo'   => $a['motivo'],
				'conteudo' => $conteudo,
			);
		}
		$em = -1 !== $a['wp'] ? $a['wp'] : ( 0 === strpos( $limpo['conteudo'], "\xEF\xBB\xBF" ) ? 3 : 0 );

		return array(
			'motivo'   => '',
			'conteudo' => substr( $limpo['conteudo'], 0, $em ) . consulta_leve_htaccess_bloco( $config, $a['eol'] ) . substr( $limpo['conteudo'], $em ),
		);
	}

	/**
	 * Motivo de recusa, antes de ler ou tocar o arquivo ('' = pode).
	 *
	 * @param array  $f     Fatos (ver consulta_leve_htaccess_fatos()).
	 * @param string $nivel ligar, remover (só arquivo e trava de mudanças) ou desfazer (só arquivo).
	 * @return string
	 */
	function consulta_leve_htaccess_recusa( array $f, string $nivel = 'ligar' ): string {
		if ( 'ligar' === $nivel ) {
			foreach ( array( 'proprio' => 'embutida', 'pode' => 'permissao' ) as $fato => $motivo ) {
				if ( empty( $f[ $fato ] ) ) {
					return $motivo;
				}
			}
			if ( ! empty( $f['multisite'] ) ) {
				return 'multisite';
			}
			if ( empty( $f['servidor'] ) ) {
				return 'servidor';
			}
		}
		if ( 'desfazer' !== $nivel && ! empty( $f['bloqueado'] ) ) {
			return 'bloqueado';
		}
		if ( 'ligar' === $nivel ) {
			if ( ! empty( $f['dispensado'] ) ) {
				return 'dispensado';
			}
			if ( empty( $f['escolha'] ) ) {
				return 'sem_escolha';
			}
		}
		if ( empty( $f['caminho'] ) ) {
			return 'caminho';
		}
		if ( ! empty( $f['link'] ) ) {
			return 'link';
		}
		if ( ! empty( $f['existe'] ) ) {
			if ( empty( $f['arquivo'] ) ) {
				return 'nao_arquivo';
			}
			if ( (int) ( $f['tamanho'] ?? 0 ) > CONSULTA_LEVE_HTACCESS_MAX ) {
				return 'tamanho';
			}
			if ( empty( $f['leitura'] ) ) {
				return 'sem_leitura';
			}
		}

		return empty( $f['escrita'] ) ? 'sem_escrita' : '';
	}

	/**
	 * O que o PHP envia, conforme o estado do bloco.
	 *
	 * @param array<string,string> $lista     Cabeçalhos escolhidos e ainda ausentes.
	 * @param array                $estado    Estado do bloco.
	 * @param string               $modo      Modo da conferência ('' em requisição comum).
	 * @param bool                 $em_quadro Página feita para abrir em quadro?
	 * @param bool                 $servidor  Apache ou LiteSpeed?
	 * @return array<string,string>
	 */
	function consulta_leve_htaccess_envio( array $lista, array $estado, string $modo, bool $em_quadro, bool $servidor ): array {
		$estado_atual = (string) ( $estado['estado'] ?? '' );
		$cobertos     = (array) ( $estado['cobertos'] ?? array() );
		$marca        = array( 'X-Consulta-Leve-Quadro' => '1' );

		if ( '' !== $modo ) {
			$eco = array( 'X-Consulta-Leve-Conferencia' => $modo );
			if ( 'sonda' === $modo ) {
				return array_fill_keys( array_keys( $lista ), CONSULTA_LEVE_HTACCESS_SONDA ) + $eco;
			}
			return 'quadro' === $modo ? $marca + $eco : $eco;
		}

		if ( ! $servidor || ! in_array( $estado_atual, array( 'ativo', 'pendente' ), true ) ) {
			return $lista;
		}
		if ( 'ativo' === $estado_atual ) {
			$lista = array_diff_key( $lista, array_flip( $cobertos ) );
		}

		return $em_quadro && in_array( 'X-Frame-Options', $cobertos, true ) ? $lista + $marca : $lista;
	}

	/**
	 * Modo pedido pela conferência, só com o token da escrita em andamento.
	 *
	 * @param mixed $pedido Cabeçalho recebido (`modo:token`).
	 * @param array $estado Estado do bloco.
	 * @return string silencio, sonda, quadro ou ''.
	 */
	function consulta_leve_htaccess_modo( $pedido, array $estado ): string {
		$token = (string) ( $estado['token'] ?? '' );
		if ( 'pendente' !== ( $estado['estado'] ?? '' ) || 32 !== strlen( $token ) || ! is_string( $pedido ) || 1 !== preg_match( '/^(silencio|sonda|quadro):([0-9a-f]{32})$/D', $pedido, $m ) ) {
			return '';
		}

		return hash_equals( $token, $m[2] ) ? $m[1] : '';
	}

	/**
	 * Confere as respostas de antes da escrita.
	 *
	 * @param array $antes Respostas `estatico` e `home` (ver consulta_leve_htaccess_pedir()).
	 * @return string '' ou motivo.
	 */
	function consulta_leve_htaccess_conferir_base( array $antes ): string {
		foreach ( array( 'estatico', 'home' ) as $tipo ) {
			$status = (int) ( $antes[ $tipo ]['status'] ?? 0 );
			if ( 200 !== $status ) {
				return 0 === $status ? 'base_sem_resposta' : 'base_status';
			}
		}

		return array( 'silencio' ) === ( $antes['home']['cab']['x-consulta-leve-conferencia'] ?? array() ) ? '' : 'sem_eco';
	}

	/**
	 * Confere uma resposta de depois da escrita.
	 *
	 * @param string               $tipo      estatico, silencio, sonda ou quadro.
	 * @param array                $antes     Resposta do mesmo endereço antes da escrita.
	 * @param array                $depois    Resposta de agora.
	 * @param array<string,string> $esperados Cabeçalhos que valem neste endereço.
	 * @param string[]             $nomes     Todos os nomes do bloco.
	 * @return string '' ou motivo.
	 */
	function consulta_leve_htaccess_conferir_resposta( string $tipo, array $antes, array $depois, array $esperados, array $nomes ): string {
		$status = (int) ( $depois['status'] ?? 0 );
		if ( 0 === $status ) {
			return 'sem_resposta';
		}
		if ( $status >= 500 ) {
			return 'erro_5xx';
		}
		if ( (int) ( $antes['status'] ?? 0 ) !== $status ) {
			return 'status_mudou';
		}
		if ( 'estatico' !== $tipo && array( $tipo ) !== ( $depois['cab']['x-consulta-leve-conferencia'] ?? array() ) ) {
			return 'sem_eco';
		}

		foreach ( $nomes as $nome ) {
			$ja    = array_values( (array) ( $antes['cab'][ strtolower( $nome ) ] ?? array() ) );
			$agora = array_values( (array) ( $depois['cab'][ strtolower( $nome ) ] ?? array() ) );
			$nosso = $esperados[ $nome ] ?? null;

			if ( $ja ) {
				// De terceiros: mesma quantidade (o valor pode variar por pedido) e nunca o nosso.
				if ( 'sonda' === $tipo ) {
					continue; // O PHP não enxerga o que o servidor já punha: a sonda não diz nada aqui.
				}
				if ( count( $agora ) !== count( $ja ) ) {
					return count( $agora ) > count( $ja ) ? 'duplicado' : 'ausente';
				}
				if ( null !== $nosso && in_array( $nosso, $agora, true ) && ! in_array( $nosso, $ja, true ) ) {
					return 'trocado';
				}
				continue;
			}
			if ( null === $nosso || ( 'quadro' === $tipo && 'X-Frame-Options' === $nome ) ) {
				if ( $agora ) {
					return 'condicao';
				}
				continue;
			}
			if ( ! $agora ) {
				return 'ausente';
			}
			if ( count( $agora ) > 1 ) {
				return 'duplicado';
			}
			if ( ( 'sonda' === $tipo ? CONSULTA_LEVE_HTACCESS_SONDA : $nosso ) !== $agora[0] ) {
				return 'trocado';
			}
		}

		return '';
	}

	/* Estado -------------------------------------------------------------------- */

	/**
	 * Estado gravado, normalizado.
	 *
	 * @return array{estado:string,motivo:string,em:int,visto:int,cobertos:string[],arquivo:string,existia:bool,token:string,sha_novo:string,sha_bloco:string}
	 */
	function consulta_leve_htaccess_estado(): array {
		$e      = get_option( CONSULTA_LEVE_OPCAO_HTACCESS, array() );
		$e      = is_array( $e ) ? $e : array();
		$texto  = static function ( string $chave ) use ( $e ): string {
			return is_string( $e[ $chave ] ?? null ) ? $e[ $chave ] : '';
		};
		$estado = $texto( 'estado' );

		return array(
			'estado'    => in_array( $estado, array( 'desligado', 'pendente', 'ativo', 'recusado' ), true ) ? $estado : 'desligado',
			'motivo'    => $texto( 'motivo' ),
			'em'        => (int) ( $e['em'] ?? 0 ),
			'visto'     => (int) ( $e['visto'] ?? 0 ),
			'cobertos'  => is_array( $e['cobertos'] ?? null ) ? array_values( array_filter( $e['cobertos'], 'is_string' ) ) : array(),
			'arquivo'   => $texto( 'arquivo' ),
			'existia'   => ! isset( $e['existia'] ) || true === $e['existia'],
			'token'     => $texto( 'token' ),
			'sha_novo'  => $texto( 'sha_novo' ),
			'sha_bloco' => $texto( 'sha_bloco' ),
		);
	}

	/**
	 * Grava o estado e a chave `cab_htaccess` (ligada só em pendente e ativo).
	 *
	 * @param array $novos Campos novos; os ausentes ficam como estão.
	 * @return array Estado gravado.
	 */
	function consulta_leve_htaccess_gravar_estado( array $novos ): array {
		$e = array_merge( consulta_leve_htaccess_estado(), array( 'em' => time() ), $novos );
		if ( 'pendente' !== $e['estado'] ) {
			$e['token']    = '';
			$e['sha_novo'] = '';
		}
		if ( ! in_array( $e['estado'], array( 'pendente', 'ativo' ), true ) ) {
			$e['cobertos'] = array();
		}
		update_option( CONSULTA_LEVE_OPCAO_HTACCESS, $e, true );
		consulta_leve_config_gravar( array( 'cab_htaccess' => in_array( $e['estado'], array( 'pendente', 'ativo' ), true ) ) );

		return $e;
	}

	/**
	 * Estado em texto curto: estado e, se houver, o motivo.
	 *
	 * @return string
	 */
	function consulta_leve_htaccess_rotulo(): string {
		$e       = consulta_leve_htaccess_estado();
		$rotulos = array(
			'desligado' => __( 'desligado', 'consulta-leve' ),
			'pendente'  => __( 'conferência em andamento ou interrompida', 'consulta-leve' ),
			'ativo'     => __( 'ativo e conferido', 'consulta-leve' ),
			'recusado'  => __( 'recusado', 'consulta-leve' ),
		);
		$motivos = consulta_leve_htaccess_motivos();
		$texto   = $rotulos[ $e['estado'] ];

		return isset( $motivos[ $e['motivo'] ] ) ? $texto . ': ' . $motivos[ $e['motivo'] ] : $texto;
	}

	/**
	 * Textos dos motivos (lista fechada).
	 *
	 * @return array<string,string>
	 */
	function consulta_leve_htaccess_motivos(): array {
		return array(
			'embutida'          => __( 'esta cópia está embutida em um tema ou plugin; só funciona como plugin próprio', 'consulta-leve' ),
			'permissao'         => __( 'só quem administra o site pode mudar o .htaccess', 'consulta-leve' ),
			'multisite'         => __( 'em multisite o .htaccess é de toda a rede; o plugin não mexe', 'consulta-leve' ),
			'servidor'          => __( 'o servidor não é Apache nem LiteSpeed; o .htaccess não teria efeito', 'consulta-leve' ),
			'bloqueado'         => __( 'mudança de arquivos bloqueada neste site (DISALLOW_FILE_MODS); se o bloco existe, tire as linhas entre # BEGIN Consulta Leve e # END Consulta Leve à mão', 'consulta-leve' ),
			'dispensado'        => __( 'o tema ou outro código já cuida dos cabeçalhos e dispensou este módulo', 'consulta-leve' ),
			'sem_escolha'       => __( 'nenhum cabeçalho marcado; salve as escolhas antes', 'consulta-leve' ),
			'caminho'           => __( 'a pasta do .htaccess não é a do WordPress nem uma acima dela', 'consulta-leve' ),
			'link'              => __( 'o .htaccess é um link simbólico', 'consulta-leve' ),
			'nao_arquivo'       => __( 'o .htaccess não é um arquivo comum', 'consulta-leve' ),
			'tamanho'           => __( 'o .htaccess passa de 128 KB', 'consulta-leve' ),
			'sem_leitura'       => __( 'o WordPress não consegue ler o .htaccess', 'consulta-leve' ),
			'sem_escrita'       => __( 'o WordPress não consegue escrever o .htaccess', 'consulta-leve' ),
			'marcadores'        => __( 'os marcadores da Consulta Leve no .htaccess estão repetidos, sem par ou alterados; arrume à mão', 'consulta-leve' ),
			'marcador_wp'       => __( 'o marcador # BEGIN WordPress aparece mais de uma vez', 'consulta-leve' ),
			'secao'             => __( 'o marcador # BEGIN WordPress está dentro de outra seção do arquivo', 'consulta-leve' ),
			'continuacao'       => __( 'a linha antes do marcador termina em barra invertida', 'consulta-leve' ),
			'binario'           => __( 'o arquivo tem bytes que não são texto', 'consulta-leve' ),
			'ocupado'           => __( 'outra mudança do .htaccess está rodando; tente em alguns minutos', 'consulta-leve' ),
			'copia'             => __( 'a cópia de segurança não pôde ser gravada no banco', 'consulta-leve' ),
			'mudou'             => __( 'outro processo mudou o .htaccess no meio; nada foi escrito por cima', 'consulta-leve' ),
			'base_sem_resposta' => __( 'o site não conseguiu pedir as próprias páginas, então não dá para conferir', 'consulta-leve' ),
			'base_status'       => __( 'a página inicial ou um arquivo do WordPress não respondeu 200 antes da mudança', 'consulta-leve' ),
			'sem_resposta'      => __( 'o site não respondeu depois da mudança', 'consulta-leve' ),
			'erro_5xx'          => __( 'o servidor respondeu com erro depois da mudança (o arquivo não aceita estas linhas)', 'consulta-leve' ),
			'status_mudou'      => __( 'uma página respondeu com outro código depois da mudança', 'consulta-leve' ),
			'sem_eco'           => __( 'a página inicial veio de um cache ou perdeu cabeçalhos no caminho, então não dá para conferir', 'consulta-leve' ),
			'ausente'           => __( 'o servidor não enviou os cabeçalhos (sem mod_headers, sem permissão no .htaccess, arquivo servido por outro servidor ou HTTPS que termina num proxy)', 'consulta-leve' ),
			'duplicado'         => __( 'um cabeçalho sairia repetido', 'consulta-leve' ),
			'trocado'           => __( 'o servidor trocou um cabeçalho que o site já envia', 'consulta-leve' ),
			'condicao'          => __( 'o servidor ignorou a condição de uma linha', 'consulta-leve' ),
			'interrompido'      => __( 'a última mudança foi interrompida antes da conferência e desfeita', 'consulta-leve' ),
			'volta_falhou'      => __( 'não foi possível desfazer a mudança: tire à mão as linhas entre # BEGIN Consulta Leve e # END Consulta Leve', 'consulta-leve' ),
			'volta_nao_conferida' => __( 'a mudança foi desfeita, mas o site ainda não respondeu como antes; confira o site', 'consulta-leve' ),
			'bloco_sumiu'       => __( 'o bloco foi mudado ou tirado do arquivo por outro meio; o PHP voltou a enviar', 'consulta-leve' ),
			'erro'              => __( 'erro inesperado; a mudança foi desfeita', 'consulta-leve' ),
		);
	}

	/* Arquivo ------------------------------------------------------------------- */

	/**
	 * Caminho do `.htaccess` do site ('' se não der para saber).
	 *
	 * @return string
	 */
	function consulta_leve_htaccess_arquivo(): string {
		if ( ! function_exists( 'get_home_path' ) && is_readable( ABSPATH . 'wp-admin/includes/file.php' ) ) {
			require_once ABSPATH . 'wp-admin/includes/file.php';
		}
		$pasta = function_exists( 'get_home_path' ) ? (string) get_home_path() : '';

		return '' === $pasta ? '' : rtrim( $pasta, '/\\' ) . '/.htaccess';
	}

	/**
	 * Caminho permitido: `.htaccess` na pasta do WordPress ou acima dela.
	 *
	 * @param string $arquivo Caminho.
	 * @return bool
	 */
	function consulta_leve_htaccess_caminho_ok( string $arquivo ): bool {
		$pasta = '' !== $arquivo && '.htaccess' === basename( $arquivo ) ? realpath( dirname( $arquivo ) ) : false;
		$base  = realpath( ABSPATH );
		if ( false === $pasta || false === $base ) {
			return false;
		}
		$pasta = rtrim( str_replace( '\\', '/', $pasta ), '/' ) . '/';

		// Nunca a raiz do disco.
		return 0 === strpos( rtrim( str_replace( '\\', '/', $base ), '/' ) . '/', $pasta ) && 1 !== preg_match( '#^(?:[a-z]:)?/$#i', $pasta );
	}

	/**
	 * Fatos para consulta_leve_htaccess_recusa().
	 *
	 * @param string $arquivo Caminho.
	 * @param array  $config  Configuração gravada.
	 * @return array<string,mixed>
	 */
	function consulta_leve_htaccess_fatos( string $arquivo, array $config ): array {
		clearstatcache();
		$existe = '' !== $arquivo && file_exists( $arquivo );

		return array(
			'proprio'    => function_exists( 'consulta_leve_atualizador_plugin_proprio' ) && consulta_leve_atualizador_plugin_proprio(),
			'pode'       => current_user_can( 'manage_options' ),
			'multisite'  => is_multisite(),
			'servidor'   => consulta_leve_htaccess_servidor_ok( consulta_leve_htaccess_software() ),
			'bloqueado'  => ( defined( 'DISALLOW_FILE_MODS' ) && DISALLOW_FILE_MODS ) || ! wp_is_file_mod_allowed( 'consulta_leve_htaccess' ),
			'dispensado' => consulta_leve_dispensado( 'cabecalhos' ),
			'escolha'    => array() !== consulta_leve_cabecalhos_lista( $config, true, array() ),
			'caminho'    => consulta_leve_htaccess_caminho_ok( $arquivo ),
			'link'       => '' !== $arquivo && is_link( $arquivo ),
			'existe'     => $existe,
			'arquivo'    => $existe && is_file( $arquivo ),
			'tamanho'    => $existe && is_file( $arquivo ) ? (int) filesize( $arquivo ) : 0,
			'leitura'    => $existe && is_readable( $arquivo ),
			'escrita'    => '' !== $arquivo && ( $existe ? is_writable( $arquivo ) : is_writable( dirname( $arquivo ) ) ), // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_is_writable
		);
	}

	/**
	 * SERVER_SOFTWARE da requisição.
	 *
	 * @return string
	 */
	function consulta_leve_htaccess_software(): string {
		return isset( $_SERVER['SERVER_SOFTWARE'] ) && is_string( $_SERVER['SERVER_SOFTWARE'] ) ? $_SERVER['SERVER_SOFTWARE'] : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput -- só comparado com lista fechada.
	}

	/**
	 * Bytes do arquivo.
	 *
	 * @param string $arquivo Caminho.
	 * @return string|null|false null sem arquivo; false se não deu para ler.
	 */
	function consulta_leve_htaccess_ler( string $arquivo ) {
		clearstatcache();
		if ( is_link( $arquivo ) ) {
			return false;
		}
		if ( ! file_exists( $arquivo ) ) {
			return null;
		}
		if ( ! is_file( $arquivo ) || filesize( $arquivo ) > CONSULTA_LEVE_HTACCESS_MAX ) {
			return false;
		}

		return @file_get_contents( $arquivo ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents,WordPress.PHP.NoSilencedErrors.Discouraged -- arquivo local; a falha é tratada.
	}

	/**
	 * Escreve tudo num arquivo já aberto.
	 *
	 * @param resource $h     Arquivo.
	 * @param string   $bytes Conteúdo.
	 * @return bool
	 */
	function consulta_leve_htaccess_despejar( $h, string $bytes ): bool {
		$total = strlen( $bytes );
		for ( $feito = 0; $feito < $total; $feito += $n ) {
			$n = fwrite( $h, substr( $bytes, $feito ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions
			if ( false === $n || 0 === $n ) {
				return false;
			}
		}

		return fflush( $h );
	}

	/**
	 * Troca o conteúdo do arquivo, só se ele ainda é o esperado.
	 *
	 * @param string      $arquivo  Caminho já conferido.
	 * @param string|null $esperado Bytes lidos antes (null: não existia).
	 * @param string      $novo     Bytes novos.
	 * @return string '' ou motivo (mudou, sem_escrita).
	 */
	function consulta_leve_htaccess_escrever( string $arquivo, ?string $esperado, string $novo ): string {
		// phpcs:disable WordPress.WP.AlternativeFunctions, WordPress.PHP.NoSilencedErrors.Discouraged -- escrita atômica de um arquivo local; toda falha é tratada.
		if ( consulta_leve_htaccess_ler( $arquivo ) !== $esperado ) {
			return 'mudou';
		}

		// Arquivo novo: criação exclusiva.
		if ( null === $esperado ) {
			$h = @fopen( $arquivo, 'xb' );
			if ( false === $h ) {
				return file_exists( $arquivo ) ? 'mudou' : 'sem_escrita';
			}
			$ok = consulta_leve_htaccess_despejar( $h, $novo );
			fclose( $h );
			if ( ! $ok ) {
				@unlink( $arquivo );
				return 'sem_escrita';
			}
			return consulta_leve_htaccess_ler( $arquivo ) === $novo ? '' : 'sem_escrita';
		}

		// Temporário na mesma pasta (nome .ht*, que o Apache não serve) e rename.
		$temp = dirname( $arquivo ) . '/.htaccess-consulta-leve-' . bin2hex( random_bytes( 8 ) ) . '.tmp';
		$h    = is_writable( dirname( $arquivo ) ) ? @fopen( $temp, 'xb' ) : false;
		if ( false !== $h ) {
			$ok = consulta_leve_htaccess_despejar( $h, $novo );
			fclose( $h );
			$ok = $ok && fileowner( $temp ) === @fileowner( $arquivo ) && filegroup( $temp ) === @filegroup( $arquivo ) && @chmod( $temp, @fileperms( $arquivo ) & 0777 );
			if ( $ok && consulta_leve_htaccess_ler( $arquivo ) !== $esperado ) {
				@unlink( $temp );
				return 'mudou';
			}
			if ( $ok && @rename( $temp, $arquivo ) ) {
				return consulta_leve_htaccess_ler( $arquivo ) === $novo ? '' : 'sem_escrita';
			}
			@unlink( $temp );
		}

		// No próprio arquivo, com trava: dono diferente ou pasta sem escrita.
		$h = @fopen( $arquivo, 'r+b' );
		if ( false === $h ) {
			return 'sem_escrita';
		}
		$travado = false;
		for ( $i = 0; $i < 10 && ! $travado; $i++ ) {
			$travado = flock( $h, LOCK_EX | LOCK_NB );
			if ( ! $travado ) {
				usleep( 100000 );
			}
		}
		if ( ! $travado ) {
			fclose( $h );
			return 'mudou';
		}
		$motivo = '';
		if ( stream_get_contents( $h ) !== $esperado ) {
			$motivo = 'mudou';
		} elseif ( ! rewind( $h ) || ! consulta_leve_htaccess_despejar( $h, $novo ) || ! ftruncate( $h, strlen( $novo ) ) ) {
			// Disco cheio ou parecido: põe de volta o que estava lá.
			rewind( $h );
			consulta_leve_htaccess_despejar( $h, $esperado );
			ftruncate( $h, strlen( $esperado ) );
			$motivo = 'sem_escrita';
		}
		fflush( $h );
		flock( $h, LOCK_UN );
		fclose( $h );
		// phpcs:enable

		if ( '' !== $motivo ) {
			return $motivo;
		}

		return consulta_leve_htaccess_ler( $arquivo ) === $novo ? '' : 'sem_escrita';
	}

	/**
	 * Guarda a cópia e confere relendo.
	 *
	 * @param string $arquivo Caminho.
	 * @param string $bytes   Conteúdo.
	 * @return bool
	 */
	function consulta_leve_htaccess_guardar_copia( string $arquivo, string $bytes ): bool {
		update_option(
			CONSULTA_LEVE_OPCAO_HTACCESS_COPIA,
			array(
				'em'      => time(),
				'arquivo' => $arquivo,
				'sha256'  => hash( 'sha256', $bytes ),
				'hex'     => bin2hex( $bytes ),
			),
			false
		);

		return consulta_leve_htaccess_copia( $arquivo ) === $bytes;
	}

	/**
	 * Cópia guardada.
	 *
	 * @param string $arquivo Caminho a que ela pertence.
	 * @return string|null null sem cópia íntegra.
	 */
	function consulta_leve_htaccess_copia( string $arquivo ): ?string {
		$c = get_option( CONSULTA_LEVE_OPCAO_HTACCESS_COPIA, array() );
		if ( ! is_array( $c ) || ( $c['arquivo'] ?? '' ) !== $arquivo || ! is_string( $c['hex'] ?? null ) || 1 !== preg_match( '/^(?:[0-9a-f]{2})*$/D', $c['hex'] ) ) {
			return null;
		}
		$bytes = (string) hex2bin( $c['hex'] );

		return hash_equals( hash( 'sha256', $bytes ), (string) ( $c['sha256'] ?? '' ) ) ? $bytes : null;
	}

	/**
	 * Desfaz a escrita: bytes da cópia (sem bloco nosso) se o arquivo ainda é o
	 * que escrevemos; senão, só o nosso bloco sai.
	 *
	 * @param array $estado Estado do bloco.
	 * @return string '' ou motivo.
	 */
	function consulta_leve_htaccess_desfazer( array $estado ): string {
		$arquivo = (string) $estado['arquivo'];
		if ( '' === $arquivo ) {
			return '';
		}
		$atual = consulta_leve_htaccess_caminho_ok( $arquivo ) ? consulta_leve_htaccess_ler( $arquivo ) : false;
		if ( null === $atual ) {
			return '';
		}
		if ( false === $atual ) {
			return 'sem_leitura';
		}

		if ( '' !== $estado['sha_novo'] && hash_equals( $estado['sha_novo'], hash( 'sha256', $atual ) ) ) {
			if ( ! $estado['existia'] ) {
				return @unlink( $arquivo ) ? '' : 'sem_escrita'; // phpcs:ignore WordPress.WP.AlternativeFunctions,WordPress.PHP.NoSilencedErrors.Discouraged -- arquivo criado por esta escrita.
			}
			$copia = consulta_leve_htaccess_copia( $arquivo );
			$alvo  = consulta_leve_htaccess_sem_bloco( null !== $copia ? $copia : $atual );
		} else {
			$alvo = consulta_leve_htaccess_sem_bloco( $atual );
		}
		if ( '' !== $alvo['motivo'] ) {
			return $alvo['motivo'];
		}

		return $alvo['conteudo'] === $atual ? '' : consulta_leve_htaccess_escrever( $arquivo, $atual, $alvo['conteudo'] );
	}

	/* Conferência --------------------------------------------------------------- */

	/**
	 * Pede um endereço do próprio site.
	 *
	 * @param string $url   Endereço.
	 * @param string $modo  Modo da conferência.
	 * @param string $token Token da escrita em andamento.
	 * @return array{status:int,cab:array<string,string[]>} status 0 sem resposta.
	 */
	function consulta_leve_htaccess_pedir( string $url, string $modo = '', string $token = '' ): array {
		$cabecalhos = array( 'Cache-Control' => 'no-cache' );
		if ( '' !== $modo ) {
			$cabecalhos['X-Consulta-Leve-Conferencia'] = $modo . ':' . $token;
		}
		$resposta = wp_remote_get(
			add_query_arg( 'consulta_leve_conferencia', bin2hex( random_bytes( 6 ) ), $url ),
			array(
				'timeout'     => CONSULTA_LEVE_HTACCESS_PEDIDO_S,
				'redirection' => 0,
				/** Filtro do próprio WordPress para pedidos a si mesmo. */
				'sslverify'   => apply_filters( 'https_local_ssl_verify', false ),
				'headers'     => $cabecalhos,
			)
		);
		if ( is_wp_error( $resposta ) ) {
			return array(
				'status' => 0,
				'cab'    => array(),
			);
		}

		$lidos = wp_remote_retrieve_headers( $resposta );
		$lidos = is_object( $lidos ) && method_exists( $lidos, 'getAll' ) ? $lidos->getAll() : (array) $lidos;
		$cab   = array();
		foreach ( $lidos as $nome => $valor ) {
			$cab[ strtolower( (string) $nome ) ] = array_map( 'trim', array_map( 'strval', array_values( (array) $valor ) ) );
		}

		return array(
			'status' => (int) wp_remote_retrieve_response_code( $resposta ),
			'cab'    => $cab,
		);
	}

	/**
	 * Endereços da conferência.
	 *
	 * @return array{home:string,estatico:string}
	 */
	function consulta_leve_htaccess_enderecos(): array {
		return array(
			'home'     => home_url( '/' ),
			'estatico' => site_url( '/wp-includes/images/blank.gif' ),
		);
	}

	/**
	 * Respostas com o PHP calado.
	 *
	 * @param string $token Token da escrita em andamento.
	 * @return array{estatico:array,home:array}
	 */
	function consulta_leve_htaccess_base( string $token ): array {
		$url = consulta_leve_htaccess_enderecos();

		return array(
			'estatico' => consulta_leve_htaccess_pedir( $url['estatico'] ),
			'home'     => consulta_leve_htaccess_pedir( $url['home'], 'silencio', $token ),
		);
	}

	/**
	 * Confere o bloco recém-escrito, parando no primeiro problema.
	 *
	 * @param array  $antes  Respostas de antes.
	 * @param array  $config Configuração gravada.
	 * @param string $token  Token da escrita em andamento.
	 * @return string '' ou motivo.
	 */
	function consulta_leve_htaccess_conferir( array $antes, array $config, string $token ): string {
		$url   = consulta_leve_htaccess_enderecos();
		$nomes = array_keys( consulta_leve_cabecalhos_lista( $config, true, array() ) );

		foreach ( array( 'estatico', 'silencio', 'sonda', 'quadro' ) as $tipo ) {
			if ( 'quadro' === $tipo && ! in_array( 'X-Frame-Options', $nomes, true ) ) {
				continue;
			}
			$onde      = 'estatico' === $tipo ? 'estatico' : 'home';
			$https     = 'https' === wp_parse_url( $url[ $onde ], PHP_URL_SCHEME );
			$depois    = consulta_leve_htaccess_pedir( $url[ $onde ], 'estatico' === $tipo ? '' : $tipo, $token );
			$esperados = consulta_leve_cabecalhos_lista( $config, $https, array() );
			$motivo    = consulta_leve_htaccess_conferir_resposta( $tipo, $antes[ $onde ], $depois, $esperados, $nomes );
			if ( '' !== $motivo ) {
				return $motivo;
			}
		}

		return '';
	}

	/* Operações ----------------------------------------------------------------- */

	/**
	 * Escreve o bloco, confere e, se algo falhar, desfaz.
	 *
	 * @param string $operacao ligar ou salvar (vai para o registro).
	 * @return string ok ou motivo.
	 */
	function consulta_leve_htaccess_aplicar( string $operacao ): string {
		$config  = consulta_leve_config_sanitizar( get_option( CONSULTA_LEVE_OPCAO_CONFIG, array() ) );
		$arquivo = consulta_leve_htaccess_arquivo();
		$motivo  = consulta_leve_htaccess_recusa( consulta_leve_htaccess_fatos( $arquivo, $config ) );
		if ( '' !== $motivo ) {
			if ( 'ativo' === consulta_leve_htaccess_estado()['estado'] ) {
				// Não dá mais para manter: o bloco antigo sai, se puder.
				consulta_leve_htaccess_remover( $operacao, 'sem_escolha' === $motivo ? '' : $motivo );
			} elseif ( 'pendente' !== consulta_leve_htaccess_estado()['estado'] ) {
				consulta_leve_htaccess_gravar_estado( array( 'estado' => 'recusado', 'motivo' => $motivo ) );
				consulta_leve_registrar( 'htaccess', $operacao, $motivo );
			}
			return $motivo;
		}

		$dono = consulta_leve_travar( 'htaccess', CONSULTA_LEVE_HTACCESS_TRAVA_S );
		if ( '' === $dono ) {
			return 'ocupado';
		}

		// Aba fechada ou fatal no meio: termina ou desfaz.
		ignore_user_abort( true );
		if ( function_exists( 'set_time_limit' ) ) {
			@set_time_limit( CONSULTA_LEVE_HTACCESS_TRAVA_S ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged,Squiz.PHP.DiscouragedFunctions.Discouraged
		}
		$aberto = true;
		register_shutdown_function(
			static function () use ( &$aberto ) {
				try {
					if ( $aberto ) {
						consulta_leve_htaccess_encerrar( 'interrompido' );
					}
				} catch ( \Throwable $erro ) {
					do_action( 'consulta_leve_falha', 'htaccess', $erro );
				}
			}
		);

		try {
			$motivo = consulta_leve_htaccess_executar( $arquivo, $config );
			if ( '' !== $motivo ) {
				$motivo = consulta_leve_htaccess_encerrar( $motivo );
			}
		} catch ( \Throwable $erro ) {
			$motivo = consulta_leve_htaccess_encerrar( 'erro' );
			do_action( 'consulta_leve_falha', 'htaccess', $erro );
		} finally {
			$aberto = false;
			consulta_leve_liberar( 'htaccess', $dono );
		}

		consulta_leve_registrar( 'htaccess', $operacao, '' === $motivo ? 'ok' : $motivo );

		return '' === $motivo ? 'ok' : $motivo;
	}

	/**
	 * Miolo da escrita, com a trava na mão. Quem chama desfaz se voltar motivo.
	 *
	 * @param string $arquivo Caminho conferido.
	 * @param array  $config  Configuração gravada.
	 * @return string '' ou motivo.
	 */
	function consulta_leve_htaccess_executar( string $arquivo, array $config ): string {
		$original = consulta_leve_htaccess_ler( $arquivo );
		if ( false === $original ) {
			return 'sem_leitura';
		}
		$limpo = consulta_leve_htaccess_sem_bloco( (string) $original );
		$novo  = consulta_leve_htaccess_com_bloco( (string) $original, $config );
		if ( '' !== $novo['motivo'] ) {
			return $novo['motivo'];
		}

		// Arquivo que só existe por nossa causa continua contando como inexistente.
		$nosso = '' === $limpo['conteudo'] && '' !== (string) $original && ! consulta_leve_htaccess_estado()['existia'];

		// A partir daqui o PHP volta a mandar tudo, até a conferência passar.
		$token  = bin2hex( random_bytes( 16 ) );
		$estado = consulta_leve_htaccess_gravar_estado(
			array(
				'estado'   => 'pendente',
				'motivo'   => '',
				'arquivo'  => $arquivo,
				'existia'  => null !== $original && ! $nosso,
				'cobertos' => array_keys( consulta_leve_cabecalhos_lista( $config, true, array() ) ),
				'token'    => $token,
				'sha_novo' => '',
			)
		);

		// Cópia antes de qualquer byte mudar.
		if ( ! consulta_leve_htaccess_guardar_copia( $arquivo, (string) $original ) ) {
			return 'copia';
		}

		// Bloco antigo sai primeiro: a base tem de ser a resposta sem nós.
		if ( null !== $original && $limpo['conteudo'] !== $original ) {
			$motivo = consulta_leve_htaccess_escrever( $arquivo, $original, $limpo['conteudo'] );
			if ( '' !== $motivo ) {
				return $motivo;
			}
			$original = $limpo['conteudo'];
		}

		$antes  = consulta_leve_htaccess_base( $token );
		$motivo = consulta_leve_htaccess_conferir_base( $antes );
		if ( '' !== $motivo ) {
			return $motivo;
		}

		consulta_leve_htaccess_gravar_estado( array( 'sha_novo' => hash( 'sha256', $novo['conteudo'] ) ) );
		$motivo = consulta_leve_htaccess_escrever( $arquivo, $original, $novo['conteudo'] );
		if ( '' !== $motivo ) {
			return $motivo;
		}

		$motivo = consulta_leve_htaccess_conferir( $antes, $config, $token );
		if ( '' !== $motivo ) {
			$volta = consulta_leve_htaccess_desfazer( consulta_leve_htaccess_estado() );
			if ( '' === $volta ) {
				// Só informa: o arquivo já é o de antes.
				$agora = consulta_leve_htaccess_base( $token );
				foreach ( array( 'estatico', 'home' ) as $tipo ) {
					if ( $agora[ $tipo ]['status'] !== $antes[ $tipo ]['status'] ) {
						return 'volta_nao_conferida';
					}
				}
			}
			return $motivo;
		}

		// O arquivo ainda é o que foi conferido?
		if ( consulta_leve_htaccess_ler( $arquivo ) !== $novo['conteudo'] ) {
			return 'mudou';
		}

		consulta_leve_htaccess_gravar_estado(
			array(
				'estado'    => 'ativo',
				'motivo'    => '',
				'visto'     => time(),
				'cobertos'  => $estado['cobertos'],
				'sha_bloco' => hash( 'sha256', consulta_leve_htaccess_bloco( $config ) ),
			)
		);

		return '';
	}

	/**
	 * Desfaz e grava a recusa. Se nem desfazer deu, fica pendente para tentar
	 * de novo na próxima carga do painel.
	 *
	 * @param string $motivo Motivo.
	 * @return string Motivo gravado.
	 */
	function consulta_leve_htaccess_encerrar( string $motivo ): string {
		$estado = consulta_leve_htaccess_estado();
		if ( 'pendente' !== $estado['estado'] ) {
			// Recusou antes de escrever. Bloco antigo que já estava conferido fica como está.
			if ( 'ativo' !== $estado['estado'] ) {
				consulta_leve_htaccess_gravar_estado( array( 'estado' => 'recusado', 'motivo' => $motivo ) );
			} else {
				consulta_leve_htaccess_gravar_estado( array( 'motivo' => $motivo ) );
			}
			return $motivo;
		}

		if ( '' !== consulta_leve_htaccess_desfazer( $estado ) ) {
			consulta_leve_htaccess_gravar_estado( array( 'motivo' => 'volta_falhou' ) );
			return 'volta_falhou';
		}
		consulta_leve_htaccess_gravar_estado( array( 'estado' => 'recusado', 'motivo' => $motivo ) );

		return $motivo;
	}

	/**
	 * Tira só o bloco próprio.
	 *
	 * @param string $operacao desligar, salvar ou desativar (vai para o registro).
	 * @param string $final    Motivo a mostrar ('' = desligado por escolha).
	 * @return string ok ou motivo (o bloco ficou).
	 */
	function consulta_leve_htaccess_remover( string $operacao, string $final = '' ): string {
		$estado = consulta_leve_htaccess_estado();
		if ( '' === $estado['arquivo'] || ! in_array( $estado['estado'], array( 'ativo', 'pendente', 'recusado' ), true ) ) {
			consulta_leve_htaccess_gravar_estado( array( 'estado' => 'desligado', 'motivo' => '' ) );
			return 'ok';
		}

		$dono = consulta_leve_travar( 'htaccess', CONSULTA_LEVE_HTACCESS_TRAVA_S );
		if ( '' === $dono ) {
			return 'ocupado';
		}
		try {
			$motivo = consulta_leve_htaccess_remover_bloco( $estado );
		} catch ( \Throwable $erro ) {
			$motivo = 'erro';
			do_action( 'consulta_leve_falha', 'htaccess', $erro );
		} finally {
			consulta_leve_liberar( 'htaccess', $dono );
		}

		if ( '' === $motivo ) {
			consulta_leve_htaccess_gravar_estado(
				array(
					'estado' => '' === $final ? 'desligado' : 'recusado',
					'motivo' => $final,
				)
			);
		} else {
			// O bloco continua no arquivo: o estado não finge que saiu.
			consulta_leve_htaccess_gravar_estado( array( 'motivo' => $motivo ) );
		}
		consulta_leve_registrar( 'htaccess', $operacao, '' === $motivo ? 'ok' : $motivo );

		return '' === $motivo ? 'ok' : $motivo;
	}

	/**
	 * Miolo da remoção, com a trava na mão.
	 *
	 * @param array $estado Estado do bloco.
	 * @return string '' ou motivo.
	 */
	function consulta_leve_htaccess_remover_bloco( array $estado ): string {
		$arquivo = $estado['arquivo'];
		$atual   = consulta_leve_htaccess_caminho_ok( $arquivo ) ? consulta_leve_htaccess_ler( $arquivo ) : false;
		if ( null === $atual ) {
			return '';
		}
		// Só lê: sem bloco nosso, nada a fazer, mesmo sem permissão de escrita.
		if ( is_string( $atual ) && false === stripos( $atual, 'Consulta Leve' ) ) {
			return '';
		}
		$motivo = consulta_leve_htaccess_recusa( consulta_leve_htaccess_fatos( $arquivo, array() ), 'remover' );
		if ( '' !== $motivo ) {
			return $motivo;
		}
		if ( false === $atual ) {
			return 'sem_leitura';
		}
		$limpo = consulta_leve_htaccess_sem_bloco( $atual );
		if ( '' !== $limpo['motivo'] || $limpo['conteudo'] === $atual ) {
			return $limpo['motivo'];
		}
		if ( '' === $limpo['conteudo'] && ! $estado['existia'] ) {
			return @unlink( $arquivo ) ? '' : 'sem_escrita'; // phpcs:ignore WordPress.WP.AlternativeFunctions,WordPress.PHP.NoSilencedErrors.Discouraged -- arquivo criado por este plugin e vazio sem o bloco.
		}

		return consulta_leve_htaccess_escrever( $arquivo, $atual, $limpo['conteudo'] );
	}

	/**
	 * Depois de salvar as escolhas: com o bloco ativo, reescreve e confere.
	 *
	 * @return string '' (nada a fazer), ok ou motivo.
	 */
	function consulta_leve_htaccess_sincronizar(): string {
		$estado = consulta_leve_htaccess_estado();
		if ( 'ativo' !== $estado['estado'] ) {
			return '';
		}
		$config = consulta_leve_config_sanitizar( get_option( CONSULTA_LEVE_OPCAO_CONFIG, array() ) );
		$bloco  = consulta_leve_htaccess_bloco( $config );
		if ( '' !== $bloco && hash_equals( $estado['sha_bloco'], hash( 'sha256', $bloco ) ) ) {
			return '';
		}
		if ( '' === $bloco ) {
			return consulta_leve_htaccess_remover( 'salvar' );
		}

		$motivo = consulta_leve_htaccess_aplicar( 'salvar' );
		if ( 'ok' !== $motivo && 'ocupado' !== $motivo && 'ativo' === consulta_leve_htaccess_estado()['estado'] ) {
			// O bloco com as escolhas antigas não pode ficar.
			consulta_leve_htaccess_remover( 'salvar', $motivo );
		}

		return $motivo;
	}

	/**
	 * Escrita interrompida: desfaz quando a trava estiver livre.
	 *
	 * @return bool Desfez?
	 */
	function consulta_leve_htaccess_recuperar(): bool {
		$config = consulta_leve_config_sanitizar( get_option( CONSULTA_LEVE_OPCAO_CONFIG, array() ) );
		if ( ! $config['cab_htaccess'] || 'pendente' !== consulta_leve_htaccess_estado()['estado'] ) {
			return false;
		}
		$dono = consulta_leve_travar( 'htaccess', CONSULTA_LEVE_HTACCESS_TRAVA_S );
		if ( '' === $dono ) {
			return false; // Outra requisição está no meio da conferência.
		}
		try {
			// A escrita pode ter terminado entre a leitura e a trava.
			wp_cache_delete( 'alloptions', 'options' );
			wp_cache_delete( CONSULTA_LEVE_OPCAO_HTACCESS, 'options' );
			if ( 'pendente' !== consulta_leve_htaccess_estado()['estado'] ) {
				return false;
			}
			$motivo = consulta_leve_htaccess_encerrar( 'interrompido' );
		} finally {
			consulta_leve_liberar( 'htaccess', $dono );
		}
		if ( 'volta_falhou' !== $motivo ) {
			consulta_leve_registrar( 'htaccess', 'desfazer', $motivo );
		}

		return 'volta_falhou' !== $motivo;
	}

	/**
	 * Com o bloco ativo, confere de tempos em tempos se ele continua no arquivo
	 * e se o servidor ainda lê `.htaccess`. Se não, o PHP volta a enviar.
	 *
	 * @param bool $forcar Ignora o intervalo.
	 * @return void
	 */
	function consulta_leve_htaccess_revisar( bool $forcar = false ): void {
		$config = consulta_leve_config_sanitizar( get_option( CONSULTA_LEVE_OPCAO_CONFIG, array() ) );
		if ( ! $config['cab_htaccess'] ) {
			return;
		}
		$estado = consulta_leve_htaccess_estado();
		if ( 'ativo' !== $estado['estado'] || ( ! $forcar && time() - $estado['visto'] < CONSULTA_LEVE_HTACCESS_REVISAO_S ) ) {
			return;
		}

		$motivo = '';
		if ( ! consulta_leve_htaccess_servidor_ok( consulta_leve_htaccess_software() ) ) {
			$motivo = 'servidor';
		} else {
			$atual = consulta_leve_htaccess_caminho_ok( $estado['arquivo'] ) ? consulta_leve_htaccess_ler( $estado['arquivo'] ) : false;
			$a     = is_string( $atual ) ? consulta_leve_htaccess_analisar( $atual ) : array( 'motivo' => 'sem_leitura' );
			$bloco = '' === $a['motivo'] && -1 !== $a['inicio'] ? str_replace( "\r\n", "\n", substr( $atual, $a['inicio'], $a['fim'] - $a['inicio'] ) ) : '';
			if ( ! hash_equals( $estado['sha_bloco'], hash( 'sha256', $bloco ) ) ) {
				$motivo = 'bloco_sumiu';
			}
		}

		if ( '' === $motivo ) {
			consulta_leve_htaccess_gravar_estado( array( 'em' => $estado['em'], 'visto' => time() ) );
		} else {
			consulta_leve_htaccess_gravar_estado( array( 'estado' => 'recusado', 'motivo' => $motivo ) );
			consulta_leve_registrar( 'htaccess', 'revisar', $motivo );
		}
	}

	/**
	 * Ajusta o que o PHP envia (chamada por consulta_leve_cabecalhos_enviar()).
	 *
	 * @param array<string,string> $lista     Cabeçalhos a enviar.
	 * @param bool                 $em_quadro Página feita para abrir em quadro?
	 * @return array<string,string>
	 */
	function consulta_leve_htaccess_ajustar( array $lista, bool $em_quadro ): array {
		if ( true !== consulta_leve_opcao( 'cab_htaccess' ) ) {
			return $lista;
		}
		$estado = consulta_leve_htaccess_estado();
		$modo   = consulta_leve_htaccess_modo( $_SERVER['HTTP_X_CONSULTA_LEVE_CONFERENCIA'] ?? '', $estado ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput -- formato fechado e token conferido.
		if ( '' !== $modo ) {
			defined( 'DONOTCACHEPAGE' ) || define( 'DONOTCACHEPAGE', true );
			do_action( 'litespeed_control_set_nocache', 'consulta-leve' );
			nocache_headers();
		}

		return consulta_leve_htaccess_envio( $lista, $estado, $modo, $em_quadro, consulta_leve_htaccess_servidor_ok( consulta_leve_htaccess_software() ) );
	}

	/* Ganchos ------------------------------------------------------------------- */

	// Só o plugin próprio escreve, então só ele desfaz.
	if ( function_exists( 'consulta_leve_atualizador_plugin_proprio' ) && consulta_leve_atualizador_plugin_proprio() ) {
		add_action(
			'admin_init',
			static function () {
				try {
					if ( ! wp_doing_ajax() ) {
						consulta_leve_htaccess_recuperar();
						consulta_leve_htaccess_revisar();
					}
				} catch ( \Throwable $erro ) {
					do_action( 'consulta_leve_falha', 'htaccess', $erro );
				}
			}
		);

		// Com o site em erro o painel não abre: o WP-CLI e o cron por linha de comando também desfazem.
		if ( ( defined( 'WP_CLI' ) && WP_CLI ) || ( defined( 'DOING_CRON' ) && DOING_CRON ) ) {
			add_action(
				'wp_loaded',
				static function () {
					try {
						consulta_leve_htaccess_recuperar();
					} catch ( \Throwable $erro ) {
						do_action( 'consulta_leve_falha', 'htaccess', $erro );
					}
				}
			);
		}

		if ( function_exists( 'register_deactivation_hook' ) ) {
			register_deactivation_hook(
				CONSULTA_LEVE_ARQUIVO,
				static function () {
					try {
						if ( 'desligado' !== consulta_leve_htaccess_estado()['estado'] ) {
							consulta_leve_htaccess_remover( 'desativar' );
						}
					} catch ( \Throwable $erro ) {
						do_action( 'consulta_leve_falha', 'htaccess', $erro );
					}
				}
			);
		}
	}
}
