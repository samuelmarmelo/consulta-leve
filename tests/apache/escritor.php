<?php
/**
 * Teste em Apache real: outro processo no mesmo .htaccess, como o núcleo do
 * WordPress faz ao gravar marcadores (abre, trava, lê e escreve no lugar).
 *
 * Uso: php escritor.php <arquivo> concorrer|segurar
 *
 * @package ConsultaLeve
 */

if ( 'cli' !== PHP_SAPI ) {
	exit;
}

list( , $arquivo, $modo ) = $argv;
$h = fopen( $arquivo, 'r+b' );

if ( 'segurar' === $modo ) {
	flock( $h, LOCK_EX );
	echo "pronto\n";
	sleep( 8 );
	exit;
}

// Aberto antes da troca; tenta a trava assim que o temporário dela aparece.
$ino = fstat( $h )['ino'];
echo "pronto\n";
for ( $fim = microtime( true ) + 20; microtime( true ) < $fim; usleep( 200 ) ) {
	clearstatcache();
	if ( glob( dirname( $arquivo ) . '/.ht*.tmp' ) || @fileinode( $arquivo ) !== $ino ) {
		break;
	}
}
flock( $h, LOCK_EX );
clearstatcache();
$trocado = @fileinode( $arquivo ) !== $ino ? 'depois da troca' : 'antes da troca';
$bytes   = stream_get_contents( $h );
rewind( $h );
fwrite( $h, $bytes . "# linha de outro processo\n" );
fflush( $h );
flock( $h, LOCK_UN );
echo "escreveu $trocado\n";
