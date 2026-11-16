<?php
/**
 * Contador de consultas na barra do admin (contrato, seção 10.3).
 *
 * Só para quem tem `manage_options`; some quando o Query Monitor está ativo
 * (ele já mostra isso e muito mais).
 *
 * @package ConsultaLeve
 */

defined( 'ABSPATH' ) || exit;

if ( ! function_exists( 'consulta_leve_monitor_barra' ) ) {

	/**
	 * Acrescenta "N consultas, X ms" à barra do admin.
	 *
	 * @param \WP_Admin_Bar $barra Barra.
	 * @return void
	 */
	function consulta_leve_monitor_barra( $barra ): void {
		if ( ! is_object( $barra ) || ! current_user_can( 'manage_options' ) || ! consulta_leve_ativo( 'monitor' ) ) {
			return;
		}

		global $wpdb;

		$ms = function_exists( 'timer_float' ) ? timer_float() * 1000 : 0;

		$barra->add_node(
			array(
				'id'    => 'consulta-leve',
				/* translators: 1: número de consultas, 2: milissegundos. */
				'title' => esc_html( sprintf( __( '%1$d consultas, %2$s ms', 'consulta-leve' ), (int) $wpdb->num_queries, number_format_i18n( $ms, 0 ) ) ),
				'href'  => esc_url( admin_url( 'tools.php?page=consulta-leve' ) ),
				'meta'  => array( 'title' => __( 'Consulta Leve: consultas ao banco nesta página até aqui', 'consulta-leve' ) ),
			)
		);
	}

	add_action( 'admin_bar_menu', 'consulta_leve_monitor_barra', 999 );
}
