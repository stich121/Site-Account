<?php
require_once __DIR__ . '/seguranca.php';
iniciarSessaoSegura(true);

if (!isset($_SESSION['funcionario_id'])) {
    header('Location: entrada-funcionarios');
    exit;
}

header('Location: apuracao-ponto#ajuste-manual-admin');
exit;
