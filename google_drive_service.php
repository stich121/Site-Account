<?php

function googleDriveConfig(): array
{
    $configPath = __DIR__ . '/google_drive_config.php';
    if (!is_file($configPath)) {
        throw new RuntimeException('Configuração do Google Drive não encontrada.');
    }

    $config = require $configPath;
    foreach (['client_id', 'client_secret', 'refresh_token', 'folder_id'] as $campo) {
        if (empty($config[$campo])) {
            throw new RuntimeException('Configuração do Google Drive incompleta: ' . $campo);
        }
    }

    return $config;
}

function googleDriveAccessToken(): string
{
    $config = googleDriveConfig();
    $ch = curl_init('https://oauth2.googleapis.com/token');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => http_build_query([
            'client_id' => $config['client_id'],
            'client_secret' => $config['client_secret'],
            'refresh_token' => $config['refresh_token'],
            'grant_type' => 'refresh_token',
        ]),
        CURLOPT_HTTPHEADER => ['Content-Type: application/x-www-form-urlencoded'],
        CURLOPT_TIMEOUT => 30,
    ]);

    $response = curl_exec($ch);
    $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $erro = curl_error($ch);
    curl_close($ch);

    if ($response === false || $status < 200 || $status >= 300) {
        throw new RuntimeException('Falha ao renovar token do Google Drive. ' . ($erro ?: $response));
    }

    $dados = json_decode($response, true);
    if (empty($dados['access_token'])) {
        throw new RuntimeException('Resposta do Google sem access_token.');
    }

    return $dados['access_token'];
}

function salvarCopiaDownloadLocal(string $conteudo, string $nomeArquivo): string
{
    $diretorio = __DIR__ . '/downloads';
    if (!is_dir($diretorio) && !mkdir($diretorio, 0755, true)) {
        throw new RuntimeException('Não foi possível criar a pasta local de downloads.');
    }

    $nomeSeguro = preg_replace('/[^a-zA-Z0-9._-]+/', '-', $nomeArquivo);
    $caminho = $diretorio . '/' . date('YmdHis') . '-' . bin2hex(random_bytes(4)) . '-' . $nomeSeguro;
    if (file_put_contents($caminho, $conteudo) === false) {
        throw new RuntimeException('Não foi possível salvar a cópia local do download.');
    }

    return $caminho;
}

function enviarArquivoGoogleDrive(string $caminhoArquivo, string $nomeArquivo, string $mimeType): array
{
    $config = googleDriveConfig();
    $accessToken = googleDriveAccessToken();
    $metadata = [
        'name' => $nomeArquivo,
        'parents' => [$config['folder_id']],
    ];

    $boundary = 'account_drive_' . bin2hex(random_bytes(12));
    $body = "--{$boundary}\r\n"
        . "Content-Type: application/json; charset=UTF-8\r\n\r\n"
        . json_encode($metadata, JSON_UNESCAPED_UNICODE)
        . "\r\n--{$boundary}\r\n"
        . "Content-Type: {$mimeType}\r\n\r\n"
        . file_get_contents($caminhoArquivo)
        . "\r\n--{$boundary}--";

    $ch = curl_init('https://www.googleapis.com/upload/drive/v3/files?uploadType=multipart&fields=id,webViewLink');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => $body,
        CURLOPT_HTTPHEADER => [
            'Authorization: Bearer ' . $accessToken,
            'Content-Type: multipart/related; boundary=' . $boundary,
            'Content-Length: ' . strlen($body),
        ],
        CURLOPT_TIMEOUT => 60,
    ]);

    $response = curl_exec($ch);
    $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $erro = curl_error($ch);
    curl_close($ch);

    if ($response === false || $status < 200 || $status >= 300) {
        throw new RuntimeException('Falha ao enviar arquivo ao Google Drive. ' . ($erro ?: $response));
    }

    $dados = json_decode($response, true);
    if (empty($dados['id'])) {
        throw new RuntimeException('Upload concluído sem ID de arquivo do Google Drive.');
    }

    return $dados;
}
