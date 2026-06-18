<?php
// Configure estes dados conforme o banco criado na hospedagem/phpMyAdmin.
const DB_HOST = '127.0.0.1';
const DB_NAME = 'u654041352_Clientes';
const DB_USER = 'u654041352_Matheus';
const DB_PASS = 'Stich@121';
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
