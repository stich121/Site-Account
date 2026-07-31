<?php
/**
 * Cache de arquivos JSON grandes (NCM, IBGE, CFOP, catálogos NFS-e) que hoje são
 * decodificados a cada requisição. O JSON decodificado é gravado como um arquivo
 * PHP (var_export) na pasta cache/; o PHP inclui esse arquivo em vez de rodar
 * json_decode de novo, e o OPcache mantém o bytecode em memória entre requisições.
 * Invalida sozinho quando o arquivo de origem é atualizado (compara mtime).
 */

function cacheJsonArquivo(string $caminhoAbsoluto): array
{
    static $memoria = [];

    if (isset($memoria[$caminhoAbsoluto])) {
        return $memoria[$caminhoAbsoluto];
    }

    if (!is_file($caminhoAbsoluto)) {
        return $memoria[$caminhoAbsoluto] = [];
    }

    $mtimeOrigem = filemtime($caminhoAbsoluto);
    $dirCache = __DIR__ . '/../cache';
    $arquivoCache = $dirCache . '/' . md5($caminhoAbsoluto) . '.php';

    if (is_file($arquivoCache) && filemtime($arquivoCache) >= $mtimeOrigem) {
        $dados = include $arquivoCache;
        if (is_array($dados)) {
            return $memoria[$caminhoAbsoluto] = $dados;
        }
    }

    $dados = json_decode((string) file_get_contents($caminhoAbsoluto), true);
    $dados = is_array($dados) ? $dados : [];

    if (!is_dir($dirCache)) {
        @mkdir($dirCache, 0755, true);
    }
    if (is_dir($dirCache)) {
        @file_put_contents($arquivoCache, "<?php\nreturn " . var_export($dados, true) . ";\n", LOCK_EX);
    }

    return $memoria[$caminhoAbsoluto] = $dados;
}
