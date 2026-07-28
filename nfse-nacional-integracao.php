<?php
// Integração com o Portal Nacional da NFS-e (SEFIN Nacional / ADN).
//
// Este arquivo não gera HTML — só funções, para ser usado por notas-fiscais.php e
// processar-fila-nfse.php. Ele funciona em dois modos:
//
// 1) Composer/certificado ainda não configurados neste servidor (situação padrão até
//    alguém rodar `composer install` e criar config_certificado_nfse.php): as funções
//    retornam um erro claro e recuperável, sem derrubar a página com fatal error.
// 2) Depois de configurado: monta a DPS a partir das nossas tabelas, assina e transmite
//    via SDK `nfse-nacional/nfse-php`.
//
// IMPORTANTE: o mapeamento de campos da DPS abaixo segue a estrutura geral descrita na
// documentação pública do Portal Nacional da NFS-e (bloco infDPS > prest/toma/serv/valores).
// Antes de emitir a primeira nota em homologação de verdade, confira os nomes exatos dos
// campos contra os exemplos que vêm junto com a lib instalada (vendor/nfse-nacional/nfse-php)
// e contra o manual técnico oficial em https://www.gov.br/nfse — schemas fiscais mudam com
// frequência (inclusive por causa da Reforma Tributária).

function integracaoNfseDisponivel(): array
{
    $autoload = __DIR__ . '/vendor/autoload.php';
    if (!is_file($autoload)) {
        return [false, 'Dependências do Composer não instaladas neste servidor (rode "composer install").'];
    }

    require_once $autoload;

    $configCertificado = __DIR__ . '/config_certificado_nfse.php';
    if (!is_file($configCertificado)) {
        return [false, 'Certificado digital ainda não configurado (falta config_certificado_nfse.php).'];
    }

    require_once $configCertificado;

    if (!certificadoNfseConfigurado()) {
        return [false, 'Certificado digital configurado, mas o arquivo .pfx não foi encontrado no caminho informado.'];
    }

    if (!class_exists('NfseNacional\\Sdk\\Nfse') && !class_exists('NFSeNacional\\Nfse')) {
        return [false, 'Biblioteca nfse-nacional/nfse-php não encontrada no vendor/ (confira o composer.json).'];
    }

    return [true, ''];
}

function dpsAPartirDaNota(array $nota, array $empresa, array $cliente, array $itens): array
{
    $servicoDescricao = implode('; ', array_map(
        static fn (array $item): string => $item['descricao'] . ' (qtd ' . $item['quantidade'] . ')',
        $itens
    ));

    return [
        'infDPS' => [
            'tpAmb' => $nota['ambiente'] === 'producao' ? 1 : 2,
            'dhEmi' => (new DateTimeImmutable($nota['data_emissao']))->format(DateTimeImmutable::ATOM),
            'serie' => '1',
            'nDPS' => (string) $nota['numero_interno'],
            'dCompet' => $nota['data_emissao'],
            'prest' => [
                'CNPJ' => preg_replace('/\D/', '', (string) ($empresa['cnpj'] ?? '')),
                'IM' => $empresa['inscricao_municipal'] ?? null,
                'xNome' => $empresa['razao_social'],
            ],
            'toma' => [
                'tipoDocumento' => $cliente['tipo_pessoa'] === 'PF' ? 'CPF' : 'CNPJ',
                'documento' => preg_replace('/\D/', '', (string) ($cliente['cnpj_cpf'] ?? '')),
                'xNome' => $cliente['nome_razao_social'],
                'email' => $cliente['email'] ?? null,
            ],
            'serv' => [
                'discriminacao' => $servicoDescricao,
                'codigoServicoMunicipal' => $itens[0]['codigo_servico_municipal'] ?? null,
            ],
            'valores' => [
                'vServPrest' => number_format((float) $nota['valor_total'], 2, '.', ''),
            ],
        ],
    ];
}

function enviarNfseNacional(array $dps, string $ambiente): array
{
    [$disponivel, $motivo] = integracaoNfseDisponivel();
    if (!$disponivel) {
        return [
            'sucesso' => false,
            'status' => 'pendente_envio',
            'motivo_rejeicao' => $motivo,
            'chave_acesso' => null,
            'protocolo_autorizacao' => null,
            'xml_gerado' => null,
        ];
    }

    try {
        // TODO(fase 2 - homologação): confirmar contra a lib instalada o construtor exato
        // do cliente (certificado, senha, ambiente) e o método de emissão. O esqueleto abaixo
        // segue o padrão documentado publicamente para nfse-nacional/nfse-php.
        $nfse = new \NfseNacional\Sdk\Nfse([
            'certificado' => CERTIFICADO_NFSE_CAMINHO,
            'senha' => CERTIFICADO_NFSE_SENHA,
            'ambiente' => $ambiente === 'producao' ? 'producao' : 'homologacao',
        ]);

        $resultado = $nfse->contribuinte()->emitir($dps);

        return [
            'sucesso' => true,
            'status' => 'autorizada',
            'motivo_rejeicao' => null,
            'chave_acesso' => $resultado['chaveAcesso'] ?? null,
            'protocolo_autorizacao' => $resultado['protocolo'] ?? null,
            'xml_gerado' => $resultado['xml'] ?? null,
        ];
    } catch (Throwable $e) {
        return [
            'sucesso' => false,
            'status' => 'rejeitada',
            'motivo_rejeicao' => $e->getMessage(),
            'chave_acesso' => null,
            'protocolo_autorizacao' => null,
            'xml_gerado' => null,
        ];
    }
}
?>
