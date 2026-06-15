<?php
// Copie este arquivo para config_db.php e preencha com os dados reais do servidor.
const DB_HOST = '127.0.0.1';
const DB_NAME = 'nome_do_banco';
const DB_USER = 'usuario_do_banco';
const DB_PASS = 'senha_do_banco';
const DB_CHARSET = 'utf8mb4';

function obterConexao(): PDO
{
    $dsn = 'mysql:host=' . DB_HOST . ';dbname=' . DB_NAME . ';charset=' . DB_CHARSET;

    return new PDO($dsn, DB_USER, DB_PASS, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
    ]);
}
?>
