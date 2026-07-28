<?php
// Copie este arquivo para config_app_key.php.
// Chave usada para cifrar segredos guardados no banco (hoje: a senha do certificado
// digital A1 de cada empresa emissora, em empresas_emissoras.certificado_senha_cifrada).
//
// IMPORTANTE:
// - Gere uma chave nova com: php -r "echo base64_encode(random_bytes(32));"
// - NUNCA troque essa chave depois que já existir certificado cadastrado com ela —
//   trocar a chave torna toda senha já cifrada impossível de recuperar (a nota fiscal
//   continua existindo, mas o certificado precisaria ser recadastrado do zero).
// - Nunca commite o valor real desta chave no Git.
const APP_ENCRYPTION_KEY = 'GERE_UMA_CHAVE_NOVA_E_COLOQUE_AQUI';

function criptografarSegredo(string $texto): string
{
    $chave = base64_decode(APP_ENCRYPTION_KEY, true);
    $iv = random_bytes(openssl_cipher_iv_length('aes-256-cbc'));
    $cifrado = openssl_encrypt($texto, 'aes-256-cbc', $chave, OPENSSL_RAW_DATA, $iv);

    if ($cifrado === false) {
        throw new RuntimeException('Não foi possível cifrar o segredo.');
    }

    return base64_encode($iv . $cifrado);
}

function descriptografarSegredo(string $valorCifrado): string
{
    $chave = base64_decode(APP_ENCRYPTION_KEY, true);
    $bin = base64_decode($valorCifrado, true);
    if ($bin === false || strlen($bin) <= 16) {
        throw new RuntimeException('Segredo cifrado inválido.');
    }

    $ivLen = openssl_cipher_iv_length('aes-256-cbc');
    $iv = substr($bin, 0, $ivLen);
    $cifrado = substr($bin, $ivLen);
    $texto = openssl_decrypt($cifrado, 'aes-256-cbc', $chave, OPENSSL_RAW_DATA, $iv);

    if ($texto === false) {
        throw new RuntimeException('Não foi possível decifrar o segredo (chave errada ou dado corrompido).');
    }

    return $texto;
}
?>
