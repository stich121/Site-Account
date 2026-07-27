<?php
// Copie este arquivo para config_db_notas.php e preencha com os dados reais do banco de Notas Fiscais.
// Este banco é separado do banco principal (config_db.php) — por isso as tabelas de notas fiscais
// não usam FOREIGN KEY para a tabela funcionarios (que vive no outro banco).
const DB_NOTAS_HOST = '127.0.0.1';
const DB_NOTAS_NAME = 'nome_do_banco_notas';
const DB_NOTAS_USER = 'usuario_do_banco_notas';
const DB_NOTAS_PASS = 'senha_do_banco_notas';
const DB_NOTAS_CHARSET = 'utf8mb4';

function obterConexaoNotas(): PDO
{
    $dsn = 'mysql:host=' . DB_NOTAS_HOST . ';dbname=' . DB_NOTAS_NAME . ';charset=' . DB_NOTAS_CHARSET;

    return new PDO($dsn, DB_NOTAS_USER, DB_NOTAS_PASS, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
    ]);
}
?>
