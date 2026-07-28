<?php
// Integração com o Portal Nacional da NFS-e (SEFIN Nacional / ADN), via
// nfse-nacional/nfse-php (https://github.com/nfse-nacional/nfse-php).
//
// Este arquivo não gera HTML — só funções, para ser usado por notas-fiscais.php e
// processar-fila-nfse.php. Ele funciona em dois modos:
//
// 1) Composer/certificado ainda não configurados neste servidor (situação padrão até
//    alguém rodar `composer install` e criar config_certificado_nfse.php): as funções
//    retornam um erro claro e recuperável, sem derrubar a página com fatal error.
// 2) Depois de configurado: monta a DPS a partir das nossas tabelas, assina e transmite.
//
// O mapeamento de campos da DPS abaixo foi conferido contra o exemplo oficial da lib
// (examples/contribuinte/emitir.php no repositório). Um ponto que PRECISA de revisão
// de um contador antes de produção: o campo regTrib.opSimpNac (enquadramento no Simples
// Nacional) está sendo inferido a partir do CRT que cadastramos em empresas_emissoras,
// mas são tabelas de código diferentes (CRT é da SEFAZ/NF-e; opSimpNac é do padrão
// nacional de NFS-e) — a inferência abaixo é uma aproximação razoável, não uma certeza.

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

    if (!class_exists(\Nfse\Nfse::class)) {
        return [false, 'Biblioteca nfse-nacional/nfse-php não encontrada no vendor/ (confira o composer.json).'];
    }

    return [true, ''];
}

function opSimpNacAPartirDoCrt(?int $crt): int
{
    // opSimpNac (padrão NFS-e Nacional): 1 = Não optante, 2 = Optante MEI, 3 = Optante ME/EPP.
    // crt (nosso cadastro, herdado do padrão NF-e/SEFAZ): 1/2 = Simples Nacional, 3 = Regime Normal.
    return match ($crt) {
        1, 2 => 3,
        default => 1,
    };
}

function dpsAPartirDaNota(array $nota, array $empresa, array $cliente, array $itens): array
{
    $cnpjPrestador = preg_replace('/\D/', '', (string) ($empresa['cnpj'] ?? ''));
    $codigoMunicipio = (string) ($empresa['codigo_ibge_municipio'] ?? '');
    $serie = '1';
    $numero = (string) $nota['numero_interno'];

    $idDps = \Nfse\Support\IdGenerator::generateDpsId(
        cpfCnpj: $cnpjPrestador,
        codIbge: $codigoMunicipio,
        serieDps: $serie,
        numDps: $numero
    );

    $servicoDescricao = implode('; ', array_map(
        static fn (array $item): string => $item['descricao'],
        $itens
    ));
    $codigoServico = $itens[0]['codigo_servico_municipal'] ?? null;

    $chaveDocumentoTomador = $cliente['tipo_pessoa'] === 'PF' ? 'CPF' : 'CNPJ';
    $documentoTomador = preg_replace('/\D/', '', (string) ($cliente['cnpj_cpf'] ?? ''));

    return [
        'idDps' => $idDps,
        'dados' => [
            '@attributes' => [
                'versao' => '1.01',
            ],
            'infDPS' => [
                '@attributes' => [
                    'Id' => $idDps,
                ],
                'tpAmb' => $nota['ambiente'] === 'producao' ? 1 : 2,
                'dhEmi' => (new DateTimeImmutable($nota['data_emissao']))->format('c'),
                'verAplic' => 'AccountContabilidade-1.0',
                'serie' => $serie,
                'nDPS' => $numero,
                'dCompet' => $nota['data_emissao'],
                'tpEmit' => 1, // 1 = Prestador emite a própria DPS.
                'cLocEmi' => $codigoMunicipio,
                'prest' => [
                    'CNPJ' => $cnpjPrestador,
                    'xNome' => $empresa['razao_social'],
                    'end' => [
                        'endNac' => [
                            'cMun' => $codigoMunicipio,
                            'CEP' => preg_replace('/\D/', '', (string) ($empresa['cep'] ?? '')),
                        ],
                        'xLgr' => $empresa['logradouro'] ?? '',
                        'nro' => $empresa['numero'] ?? '',
                        'xCpl' => $empresa['complemento'] ?? '',
                        'xBairro' => $empresa['bairro'] ?? '',
                    ],
                    'email' => null,
                    'regTrib' => [
                        'opSimpNac' => opSimpNacAPartirDoCrt($empresa['crt'] !== null ? (int) $empresa['crt'] : null),
                        'regApTribSN' => null,
                        'regEspTrib' => 0, // 0 = Nenhum regime especial de tributação.
                    ],
                ],
                'toma' => [
                    $chaveDocumentoTomador => $documentoTomador,
                    'xNome' => $cliente['nome_razao_social'],
                ],
                'serv' => [
                    'locPrest' => [
                        'cLocPrestacao' => $codigoMunicipio,
                    ],
                    'cServ' => [
                        'cTribNac' => $codigoServico,
                        'xDescServ' => $servicoDescricao,
                    ],
                ],
                'valores' => [
                    'vServPrest' => [
                        'vServ' => round((float) $nota['valor_total'], 2),
                    ],
                    // TODO(fase 2 - homologação): confirmar com o contador os códigos de
                    // tributação (tribISSQN, tpRetISSQN, CST do PIS/COFINS, indTotTrib) antes
                    // de emitir qualquer nota em produção — valores abaixo são só um ponto
                    // de partida (mesmo usado no exemplo oficial da lib).
                    'trib' => [
                        'tribMun' => [
                            'tribISSQN' => 1,
                            'tpRetISSQN' => 1,
                        ],
                        'tribFed' => [
                            'piscofins' => [
                                'CST' => '08',
                            ],
                        ],
                        'totTrib' => [
                            'indTotTrib' => 0,
                        ],
                    ],
                ],
            ],
        ],
    ];
}

function enviarNfseNacional(array $dpsMontada, string $ambiente): array
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
        $context = new \Nfse\Http\NfseContext(
            ambiente: $ambiente === 'producao' ? \Nfse\Enums\TipoAmbiente::Producao : \Nfse\Enums\TipoAmbiente::Homologacao,
            certificatePath: CERTIFICADO_NFSE_CAMINHO,
            certificatePassword: CERTIFICADO_NFSE_SENHA
        );

        $nfse = new \Nfse\Nfse($context);
        $dps = new \Nfse\Dto\Nfse\DpsData($dpsMontada['dados']);
        $nfseData = $nfse->contribuinte()->emitir($dps);

        return [
            'sucesso' => true,
            'status' => 'autorizada',
            'motivo_rejeicao' => null,
            'chave_acesso' => $nfseData->infNfse->id ?? null,
            'protocolo_autorizacao' => null,
            'xml_gerado' => null,
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
