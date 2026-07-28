<?php
// Operações remotas complementares da NFS-e Nacional.
// Mantidas fora do fluxo de montagem da DPS para que consulta, reconciliação e
// eventos sempre reutilizem o mesmo certificado e ambiente da empresa.

require_once __DIR__ . '/nfse-nacional-integracao.php';

function servicoContribuinteNfse(array $empresa, string $ambiente): \Nfse\Service\ContribuinteService
{
    [$disponivel, $motivo] = integracaoNfseDisponivel();
    if (!$disponivel) {
        throw new RuntimeException($motivo);
    }

    [$certificadoOk, $motivoCertificado] = certificadoEmpresaDisponivel($empresa);
    if (!$certificadoOk) {
        throw new RuntimeException($motivoCertificado);
    }

    $context = new \Nfse\Http\NfseContext(
        ambiente: $ambiente === 'producao'
            ? \Nfse\Enums\TipoAmbiente::Producao
            : \Nfse\Enums\TipoAmbiente::Homologacao,
        certificatePath: __DIR__ . '/certificados-nfse/' . basename((string) $empresa['certificado_arquivo']),
        certificatePassword: descriptografarSegredo((string) $empresa['certificado_senha_cifrada'])
    );

    return (new \Nfse\Nfse($context))->contribuinte();
}

function consultarNfseRemota(array $empresa, string $ambiente, string $chave): array
{
    $chave = normalizarChaveAcessoNfse($chave) ?? '';
    if ($chave === '') {
        throw new InvalidArgumentException('A chave de acesso da NFS-e não foi informada.');
    }

    $nfse = servicoContribuinteNfse($empresa, $ambiente)->consultar($chave);
    if ($nfse === null || trim((string) ($nfse->nfseXml ?? '')) === '') {
        throw new RuntimeException('A NFS-e não foi localizada no ambiente informado.');
    }

    return [
        'chave_acesso' => normalizarChaveAcessoNfse($nfse->infNfse->id ?? null) ?? $chave,
        'xml' => (string) $nfse->nfseXml,
    ];
}

function reconciliarDpsNfse(array $empresa, string $ambiente, string $idDps): ?array
{
    if ($idDps === '') {
        return null;
    }
    if (!nfseIdDpsValido($idDps)) {
        throw new InvalidArgumentException('O identificador da DPS é inválido e não será consultado no Portal Nacional.');
    }

    $servico = servicoContribuinteNfse($empresa, $ambiente);
    if (!$servico->verificarDps($idDps)) {
        return null;
    }

    $consultaDps = $servico->consultarDps($idDps);
    $chave = trim((string) ($consultaDps->chaveAcesso ?? ''));
    $chave = normalizarChaveAcessoNfse($chave) ?? '';
    if ($chave === '') {
        throw new RuntimeException('A DPS existe no Portal Nacional, mas ainda não possui chave de acesso. Tente consultar novamente mais tarde.');
    }

    $nfse = $servico->consultar($chave);
    if ($nfse === null || trim((string) ($nfse->nfseXml ?? '')) === '') {
        throw new RuntimeException('A DPS foi localizada, mas o XML autorizado ainda não está disponível.');
    }

    return [
        'chave_acesso' => normalizarChaveAcessoNfse($nfse->infNfse->id ?? null) ?? $chave,
        'xml' => (string) $nfse->nfseXml,
    ];
}

function cancelarNfseRemota(array $empresa, string $ambiente, string $chave, string $motivo, int $codigoMotivo = 1): array
{
    $chave = normalizarChaveAcessoNfse($chave) ?? '';
    if ($chave === '') throw new InvalidArgumentException('A chave de acesso da NFS-e não foi informada.');
    $documentoAutor = preg_replace('/\D/', '', (string) ($empresa['cnpj'] ?? ''));
    if (strlen($documentoAutor) !== 14) {
        throw new RuntimeException('O CNPJ da empresa emissora é inválido para assinar o evento.');
    }
    $motivo = trim($motivo);
    if (mb_strlen($motivo) < 15 || mb_strlen($motivo) > 255) {
        throw new InvalidArgumentException('Informe um motivo de cancelamento entre 15 e 255 caracteres.');
    }

    $evento = new \Nfse\Dto\Nfse\PedRegEventoData([
        'versao' => '1.01',
        'infPedReg' => [
            'tpAmb' => $ambiente === 'producao' ? 1 : 2,
            'verAplic' => 'AccountContabilidade-1.0',
            'dhEvento' => (new DateTimeImmutable('now'))->format('c'),
            'chNFSe' => $chave,
            'CNPJAutor' => $documentoAutor,
            'nPedRegEvento' => 1,
            'tipoEvento' => '101101',
            'e101101' => [
                'xDesc' => 'Cancelamento de NFS-e',
                'cMotivo' => (string) $codigoMotivo,
                'xMotivo' => $motivo,
            ],
        ],
    ]);

    $resposta = servicoContribuinteNfse($empresa, $ambiente)->cancelar($evento);
    $conteudoB64 = trim((string) ($resposta->eventoXmlGZipB64 ?? ''));
    if ($conteudoB64 === '') {
        throw new RuntimeException('O Portal Nacional não devolveu o XML do evento; o cancelamento local não foi realizado.');
    }
    $comprimido = base64_decode($conteudoB64, true);
    $xmlEvento = $comprimido === false ? false : gzdecode($comprimido);
    if ($xmlEvento === false || trim($xmlEvento) === '') {
        throw new RuntimeException('Não foi possível validar o XML de retorno do cancelamento.');
    }

    return [
        'xml_evento' => $xmlEvento,
        'processado_em' => (string) ($resposta->dataHoraProcessamento ?? ''),
    ];
}

function baixarDanfseRemoto(array $empresa, string $ambiente, string $chave, ?string $xmlAutorizado = null): string
{
    $autoload = __DIR__ . '/vendor/autoload.php';
    if (!is_file($autoload)) throw new RuntimeException('Dependências do Composer não instaladas; rode composer install.');
    require_once $autoload;
    if (!class_exists(\PhpNfseNacional\Services\DanfseService::class)) {
        throw new RuntimeException('Gerador local de DANFSe não instalado no vendor/.');
    }

    // Desde 01/07/2026 o endpoint de download do ADN foi descontinuado.
    // Consulta-se o XML autorizado e o DANFSe é renderizado localmente no
    // leiaute NT 008/2026, sem depender do antigo endpoint de PDF.
    $xmlAutorizado = trim((string) $xmlAutorizado);
    if ($xmlAutorizado === '') {
        $documento = consultarNfseRemota($empresa, $ambiente, $chave);
        $xmlAutorizado = (string) $documento['xml'];
    }
    $gerador = new \PhpNfseNacional\Services\DanfseService();
    $pdf = $gerador->gerarDoXml($xmlAutorizado);
    if (!str_starts_with($pdf, '%PDF-')) {
        throw new RuntimeException('Não foi possível gerar o DANFSe local a partir do XML autorizado.');
    }

    return $pdf;
}

function salvarDocumentoFiscalPrivado(int $notaId, string $tipo, string $conteudo, string $extensao): string
{
    $tipoSeguro = preg_replace('/[^a-z0-9_-]/i', '', $tipo) ?: 'documento';
    $extensaoSegura = preg_replace('/[^a-z0-9]/i', '', $extensao) ?: 'bin';
    $diretorio = __DIR__ . '/documentos-fiscais-privados';
    if (!is_dir($diretorio) && !mkdir($diretorio, 0700, true) && !is_dir($diretorio)) {
        throw new RuntimeException('Não foi possível criar o repositório privado de documentos fiscais.');
    }
    if (!function_exists('criptografarSegredo')) {
        throw new RuntimeException('Criptografia da aplicação indisponível para proteger o documento fiscal.');
    }
    $arquivo = sprintf('nota-%d-%s-%s.%s.enc', $notaId, $tipoSeguro, gmdate('YmdHis'), $extensaoSegura);
    $caminho = $diretorio . '/' . $arquivo;
    $conteudoProtegido = criptografarSegredo($conteudo);
    if (file_put_contents($caminho, $conteudoProtegido, LOCK_EX) === false) {
        throw new RuntimeException('Não foi possível persistir o documento fiscal.');
    }

    return $arquivo;
}
