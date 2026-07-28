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

function tribIssqnAPartirDaTributacao(?string $tributacao): int
{
    // tribISSQN (padrão NFS-e Nacional): 1 = Operação tributável, 2 = Isenção, 3 = Imune,
    // 4 = Exportação de serviço, 5 = Não incidência, 6 = Imunidade/isenção parcial.
    return match ($tributacao) {
        'isenta' => 2,
        'imune' => 3,
        'exportacao' => 4,
        'nao_incidencia' => 5,
        default => 1,
    };
}

function dpsAPartirDaNota(array $nota, array $empresa, array $cliente, array $itens, ?array $nfse = null): array
{
    $cnpjPrestador = preg_replace('/\D/', '', (string) ($empresa['cnpj'] ?? ''));
    $codigoMunicipio = (string) ($empresa['codigo_ibge_municipio'] ?? '');
    $serie = ($nfse['serie_dps'] ?? '') !== '' ? (string) $nfse['serie_dps'] : '1';
    $numero = ($nfse['numero_dps'] ?? '') !== '' ? (string) $nfse['numero_dps'] : (string) $nota['numero_interno'];
    $dataCompetencia = $nfse['data_competencia'] ?? $nota['data_emissao'];

    $idDps = \Nfse\Support\IdGenerator::generateDpsId(
        cpfCnpj: $cnpjPrestador,
        codIbge: $codigoMunicipio,
        serieDps: $serie,
        numDps: $numero
    );

    $servicoDescricao = $nfse['descricao_servico'] ?? implode('; ', array_map(
        static fn (array $item): string => $item['descricao'],
        $itens
    ));
    $codigoServicoNacional = $nfse['codigo_tributacao_nacional'] ?? ($itens[0]['codigo_servico_municipal'] ?? null);
    $codigoServicoMunicipal = $nfse['codigo_tributacao_municipal'] ?? null;

    $chaveDocumentoTomador = $cliente['tipo_pessoa'] === 'PF' ? 'CPF' : 'CNPJ';
    $documentoTomador = preg_replace('/\D/', '', (string) ($cliente['cnpj_cpf'] ?? ''));

    $issqnRetido = ($nfse['issqn_retido'] ?? 'nao') === 'sim';
    // tpRetISSQN (padrão NFS-e Nacional): 1 = Não retido, 2 = Retido pelo tomador, 3 = Retido pelo intermediário.
    $tpRetIssqn = 1;
    if ($issqnRetido) {
        $tpRetIssqn = ($nfse['issqn_retido_por'] ?? 'tomador') === 'intermediario' ? 3 : 2;
    }

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
                'dCompet' => $dataCompetencia,
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
                    'IM' => ($nfse['tomador_local'] ?? null) === 'brasil' ? ($nfse['tomador_inscricao_municipal'] ?? null) : null,
                    'xNome' => $cliente['nome_razao_social'],
                    'email' => $cliente['email'] ?? null,
                    'fone' => $nfse['tomador_telefone'] ?? null,
                ],
                'serv' => [
                    'locPrest' => [
                        'cLocPrestacao' => $nfse['municipio_prestacao'] ?? $codigoMunicipio,
                    ],
                    'cServ' => [
                        'cTribNac' => $codigoServicoNacional,
                        'cTribMun' => $codigoServicoMunicipal,
                        'xDescServ' => $servicoDescricao,
                        'cIntContrib' => $nfse['codigo_interno_contribuinte'] ?? null,
                    ],
                ],
                'valores' => [
                    'vServPrest' => [
                        'vServ' => round((float) $nota['valor_total'], 2),
                    ],
                    'vDescCondIncond' => [
                        'vDescIncond' => $nfse['desconto_incondicionado'] ?? null,
                        'vDescCond' => $nfse['desconto_condicionado'] ?? null,
                    ],
                    // Confirmar com o contador antes de produção: CST do PIS/COFINS e indTotTrib
                    // ainda usam um valor de partida quando o campo não é preenchido no formulário.
                    'trib' => [
                        'tribMun' => [
                            'tribISSQN' => tribIssqnAPartirDaTributacao($nfse['tributacao_issqn'] ?? null),
                            'tpRetISSQN' => $tpRetIssqn,
                        ],
                        'tribFed' => [
                            'piscofins' => [
                                'CST' => ($nfse['situacao_tributaria_pis_cofins'] ?? '') !== '' ? $nfse['situacao_tributaria_pis_cofins'] : '08',
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
