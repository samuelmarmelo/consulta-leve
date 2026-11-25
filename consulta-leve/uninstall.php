<?php
/**
 * Desinstalação (contrato, seção 15.2): tira só o bloco da Consulta Leve do
 * `.htaccess`, se ele ainda estiver lá. Opções e tabelas ficam (soft delete).
 *
 * @package ConsultaLeve
 */

defined( 'ABSPATH' ) || exit;
defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

require_once __DIR__ . '/consulta-leve.php';

if ( function_exists( 'consulta_leve_htaccess_sair' ) ) {
	consulta_leve_htaccess_sair( 'desinstalar' );
}
