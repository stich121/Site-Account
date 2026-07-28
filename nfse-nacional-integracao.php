<?php
// Integração com o Portal Nacional da NFS-e (SEFIN Nacional / ADN), via
// nfse-nacional/nfse-php (https://github.com/nfse-nacional/nfse-php).
//
// Este arquivo não gera HTML — só funções, para ser usado por notas-fiscais.php e
// processar-fila-nfse.php. O certificado A1 é por empresa emissora (cadastrado em
// notas-certificados.php, colunas certificado_arquivo/certificado_senha_cifrada em
// empresas_emissoras) — não existe mais um certificado único global.
//
// Duas checagens diferentes precisam passar antes de enviar de verdade:
// 1) integracaoNfseDisponivel(): coisa de servidor (Composer instalado, lib presente).
// 2) certificadoEmpresaDisponivel($empresa): a empresa específica tem certificado configurado.
// Se qualquer uma falhar, as funções retornam um erro claro e recuperável, sem fatal error.
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

    if (!class_exists(\Nfse\Nfse::class)) {
        return [false, 'Biblioteca nfse-nacional/nfse-php não encontrada no vendor/ (confira o composer.json).'];
    }

    $configAppKey = __DIR__ . '/config_app_key.php';
    if (!is_file($configAppKey)) {
        return [false, 'Chave de criptografia da aplicação ainda não configurada (falta config_app_key.php).'];
    }

    require_once $configAppKey;

    return [true, ''];
}

function certificadoEmpresaDisponivel(array $empresa): array
{
    if (empty($empresa['certificado_arquivo']) || empty($empresa['certificado_senha_cifrada'])) {
        return [false, 'Empresa "' . ($empresa['razao_social'] ?? '') . '" ainda não tem certificado digital cadastrado (cadastre em Certificado digital).'];
    }

    $caminho = __DIR__ . '/certificados-nfse/' . basename((string) $empresa['certificado_arquivo']);
    if (!is_file($caminho)) {
        return [false, 'Certificado cadastrado para "' . ($empresa['razao_social'] ?? '') . '" não foi encontrado no servidor (recadastre em Certificado digital).'];
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

function enviarNfseNacional(array $dpsMontada, string $ambiente, array $empresa): array
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

    [$certificadoOk, $motivoCertificado] = certificadoEmpresaDisponivel($empresa);
    if (!$certificadoOk) {
        return [
            'sucesso' => false,
            'status' => 'pendente_envio',
            'motivo_rejeicao' => $motivoCertificado,
            'chave_acesso' => null,
            'protocolo_autorizacao' => null,
            'xml_gerado' => null,
        ];
    }

    try {
        $context = new \Nfse\Http\NfseContext(
            ambiente: $ambiente === 'producao' ? \Nfse\Enums\TipoAmbiente::Producao : \Nfse\Enums\TipoAmbiente::Homologacao,
            certificatePath: __DIR__ . '/certificados-nfse/' . basename((string) $empresa['certificado_arquivo']),
            certificatePassword: descriptografarSegredo($empresa['certificado_senha_cifrada'])
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
