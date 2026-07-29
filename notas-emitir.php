<?php
/**
 * Compatibilidade: a emissão de nota foi separada em duas telas —
 * notas-emitir-produto.php (NF-e) e notas-emitir-servico.php (NFS-e).
 * Mantém links e favoritos antigos funcionando via redirecionamento.
 * Como somente NFS-e pode ser corrigida, ?editar= sempre aponta para a tela de serviço.
 */

$destino = isset($_GET['tipo']) && $_GET['tipo'] === 'nfe' ? 'notas-emitir-produto' : 'notas-emitir-servico';

$query = $_GET;
unset($query['tipo']);
$querystring = http_build_query($query);

header('Location: ' . $destino . ($querystring !== '' ? '?' . $querystring : ''));
exit;
