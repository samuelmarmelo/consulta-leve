<?php
/**
 * Semente do WordPress local: 6 mil posts com metas, em lotes de 500 por
 * INSERT (rápido no SQLite do Playground). Roda uma vez (opção marcadora).
 * Dados fictícios. A página /semente-consulta-leve/ é criada por último e
 * marca o fim para tools/local/central.mjs.
 *
 * @package ConsultaLeve
 */

defined( 'ABSPATH' ) || exit;

if ( ! get_option( 'consulta_leve_semente' ) ) {
	global $wpdb;

	$palavras = array( 'coração', 'mãe', 'campanha', 'reunião', 'orçamento', 'cliente', 'relatório', 'vídeo', 'carrossel', 'aprovação', 'logística', 'catálogo', 'quadro', 'moldura', 'evento', 'promoção', 'lançamento', 'contrato', 'agenda', 'publicação' );
	$clientes = array( 'Loja Azul', 'Casa Norte', 'Ateliê Sol', 'Oficina Mar', 'Café Lua', 'Estúdio Rio', 'Marcenaria Vale', 'Clínica Serra' );
	$autor    = 1;
	$total    = 6000;
	$lote     = 500;
	$base     = strtotime( '2024-01-01 08:00:00' );

	for ( $inicio = 0; $inicio < $total; $inicio += $lote ) {
		$linhas = array();
		for ( $i = $inicio; $i < min( $total, $inicio + $lote ); $i++ ) {
			$titulo   = ucfirst( $palavras[ $i % 20 ] ) . ' ' . $palavras[ ( $i * 7 ) % 20 ] . ' ' . $clientes[ $i % 8 ] . ' ' . ( $i + 1 );
			$data     = gmdate( 'Y-m-d H:i:s', $base + $i * 9000 );
			$conteudo = str_repeat( 'Texto de demonstração sobre ' . $palavras[ ( $i * 3 ) % 20 ] . ' e ' . $palavras[ ( $i * 11 ) % 20 ] . '. ', 12 );
			$tipo     = 0 === $i % 10 ? 'page' : 'post';
			$status   = 0 === $i % 25 ? 'draft' : 'publish';
			$slug     = sanitize_title( $titulo );
			$linhas[] = $wpdb->prepare( '(%d,%s,%s,%s,%s,%s,%s,%s,%s,%s,%s,%s,%s,%s,%s)', $autor, $data, $data, $conteudo, $titulo, '', $status, 'open', 'closed', $slug, $data, $data, '', $tipo, '' );
		}
		$wpdb->query( "INSERT INTO {$wpdb->posts} (post_author,post_date,post_date_gmt,post_content,post_title,post_excerpt,post_status,comment_status,ping_status,post_name,post_modified,post_modified_gmt,to_ping,post_type,pinged) VALUES " . implode( ',', $linhas ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- linhas preparadas acima.
	}

	// Metas: cliente e status de cada post.
	$ids = $wpdb->get_col( "SELECT ID FROM {$wpdb->posts} WHERE post_type IN ('post','page') ORDER BY ID" );
	foreach ( array_chunk( $ids, $lote ) as $pedaco ) {
		$metas = array();
		foreach ( $pedaco as $n => $id ) {
			$metas[] = $wpdb->prepare( '(%d,%s,%s)', $id, 'cliente', $clientes[ $id % 8 ] );
			$metas[] = $wpdb->prepare( '(%d,%s,%s)', $id, '_status', array( 'briefing', 'design', 'aprovacao', 'publicado' )[ $id % 4 ] );
		}
		$wpdb->query( "INSERT INTO {$wpdb->postmeta} (post_id,meta_key,meta_value) VALUES " . implode( ',', $metas ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- linhas preparadas acima.
	}

	update_option( 'permalink_structure', '/%postname%/' );
	wp_insert_post(
		array(
			'post_type'    => 'page',
			'post_status'  => 'publish',
			'post_title'   => 'Semente Consulta Leve',
			'post_name'    => 'semente-consulta-leve',
			'post_content' => 'Página da busca rápida: [consulta_leve_busca tipos="post,page" limite="8"]',
		)
	);
	flush_rewrite_rules();
	update_option( 'consulta_leve_semente', time(), false );
}
