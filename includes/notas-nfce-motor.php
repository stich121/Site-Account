<?php
/**
 * Motor de emissão da NFC-e (venda no balcão) — enxuto e isolado do motor de NF-e/NFS-e
 * (includes/notas-emitir-motor.php, não alterado e não incluído aqui).
 *
 * Grava notas_fiscais + notas_fiscais_itens + notas_fiscais_nfce +
 * notas_fiscais_nfce_pagamentos e, na MESMA requisição, monta/assina/transmite a NFC-e
 * (fluxo síncrono — sem fila), com fallback para contingência EPEC quando a SEFAZ está
 * indisponível.
 *
 * Ao final da inclusão, ficam disponíveis para a página: $erro, $sucesso, $notaEmitida
 * (array com dados da nota recém emitida/rejeitada, ou null), $empresaAtiva, $empresasAtivas,
 * $catalogo, $csrf, $usuario, $catalogoJson, $db, $dbNotas, $funcionarioId, $podeAdministrar.
 */

require_once __DIR__ . '/../seguranca.php';
iniciarSessaoSegura(true);
date_default_timezone_set('America/Sao_Paulo');

if (!isset($_SESSION['funcionario_id'])) {
    header('Location: entrada-funcionarios');
    exit;
}

require_once __DIR__ . '/../config_db.php';
require_once __DIR__ . '/../config_db_notas.php';
require_once __DIR__ . '/notas-nfce-schema.php';
require_once __DIR__ . '/nfe-impostos.php';
require_once __DIR__ . '/../config_app_key.php';

$funcionarioId = (int) $_SESSION['funcionario_id'];
$usuarioRaw = $_SESSION['funcionario_usuario'] ?? 'Funcionário';
$nivelAcesso = (int) ($_SESSION['funcionario_nivel_acesso'] ?? 1);
$podeAdministrar = $nivelAcesso >= 3;

$erro = '';
$sucesso = '';
$notaEmitida = null;

if (!function_exists('h')) {
    function h(string $valor): string
    {
        return htmlspecialchars($valor, ENT_QUOTES, 'UTF-8');
    }
}

if (!function_exists('campoInfo')) {
    function campoInfo(string $textoHtml): string
    {
        return '<span class="info-tooltip-wrap">'
            . '<button type="button" class="info-btn" aria-expanded="false" aria-label="Mais informações sobre este campo"><i class="fa-solid fa-info"></i></button>'
            . '<span class="info-tooltip-box" role="tooltip">' . $textoHtml . '</span>'
            . '</span>';
    }
}

function registrarLogNotaNfce(PDO $db, int $notaId, int $funcionarioId, string $acao, string $detalhe = ''): void
{
    $stmt = $db->prepare(
        'INSERT INTO notas_fiscais_log (nota_id, funcionario_id, acao, detalhe) VALUES (:nota_id, :funcionario_id, :acao, :detalhe)'
    );
    $stmt->execute([
        'nota_id' => $notaId,
        'funcionario_id' => $funcionarioId,
        'acao' => $acao,
        'detalhe' => $detalhe !== '' ? $detalhe : null,
    ]);
}

/**
 * Busca (ou cria, na primeira venda da empresa) o cliente-placeholder "CONSUMIDOR NÃO
 * IDENTIFICADO" usado em toda NFC-e sem CPF informado pelo comprador. Como notas_clientes
 * não tem coluna de empresa emissora, o placeholder é identificado por um marcador
 * determinístico no campo cnpj_cpf (NFCE + id da empresa com zero-padding), único por
 * empresa e nunca confundido com um CPF/CNPJ real (que nfeBloqueioDifal/CFOP tratam à parte).
 */
function obterClientePlaceholderNfce(PDO $dbNotas, int $empresaId, int $funcionarioId): int
{
    $marcador = 'NFCE' . str_pad((string) $empresaId, 6, '0', STR_PAD_LEFT);

    $stmt = $dbNotas->prepare('SELECT id FROM notas_clientes WHERE cnpj_cpf = :marcador LIMIT 1');
    $stmt->execute(['marcador' => $marcador]);
    $id = $stmt->fetchColumn();
    if ($id) {
        return (int) $id;
    }

    $stmt = $dbNotas->prepare(
        'INSERT INTO notas_clientes (tipo_pessoa, nome_razao_social, cnpj_cpf, indicador_consumidor_final, criado_por)
         VALUES (\'PF\', \'CONSUMIDOR NÃO IDENTIFICADO\', :marcador, 1, :criado_por)'
    );
    $stmt->execute(['marcador' => $marcador, 'criado_por' => $funcionarioId]);

    return (int) $dbNotas->lastInsertId();
}

try {
    $db = obterConexao();
    $dbNotas = obterConexaoNotas();
    prepararSchemaNfce($dbNotas);

    $stmt = $db->prepare('SELECT permite_notas_fiscais, usuario FROM funcionarios WHERE id = :id LIMIT 1');
    $stmt->execute(['id' => $funcionarioId]);
    $dadosFuncionario = $stmt->fetch();
    $permiteNotas = (int) ($dadosFuncionario['permite_notas_fiscais'] ?? 0) === 1;

    if (!$permiteNotas) {
        header('Location: painel');
        exit;
    }

    if (empty($_SESSION['csrf_notas_nfce'])) {
        $_SESSION['csrf_notas_nfce'] = bin2hex(random_bytes(32));
    }

    $stmt = $dbNotas->query('SELECT * FROM empresas_emissoras WHERE ativo = 1 ORDER BY razao_social ASC');
    $empresasAtivas = $stmt->fetchAll();

    $empresaAtivaId = (int) ($_SESSION['nfse_empresa_emissora_ativa_id'] ?? 0);
    $empresaAtiva = null;
    foreach ($empresasAtivas as $empresaCandidata) {
        if ((int) $empresaCandidata['id'] === $empresaAtivaId) {
            $empresaAtiva = $empresaCandidata;
            break;
        }
    }

    $stmt = $dbNotas->query(
        'SELECT id, empresa_emissora_id, tipo, descricao, ncm, cfop, cst_csosn, unidade, valor_unitario_padrao,
                aliquota_icms, aliquota_pis, aliquota_cofins, aliquota_ipi, ipi_cst, cean,
                icms_origem, cest, cnpj_fabricante, indicador_escala_relevante, codigo_beneficio_fiscal
         FROM notas_produtos_servicos WHERE ativo = 1 AND tipo = \'nfce\' ORDER BY descricao ASC'
    );
    $catalogoCompleto = $stmt->fetchAll();
    // O catálogo é global na tabela (sem filtro por empresa na consulta, mesmo padrão de
    // includes/notas-emitir-motor.php); a página filtra no PHP e no JS pela empresa ativa.
    $catalogo = $empresaAtiva
        ? array_values(array_filter($catalogoCompleto, static fn(array $p): bool => (int) $p['empresa_emissora_id'] === (int) $empresaAtiva['id']))
        : [];
    $catalogoPorId = [];
    foreach ($catalogo as $produtoCatalogo) {
        $catalogoPorId[(int) $produtoCatalogo['id']] = $produtoCatalogo;
    }

    if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['acao'] ?? '') === 'emitir_nfce') {
        $csrf = $_POST['csrf'] ?? '';
        if (!hash_equals($_SESSION['csrf_notas_nfce'], $csrf)) {
            $erro = 'Sessão expirada. Atualize a página e tente novamente.';
        } elseif ($empresaAtiva === null) {
            $erro = 'Selecione uma empresa emissora ativa antes de vender.';
        } elseif (empty($empresaAtiva['cnpj']) || empty($empresaAtiva['uf']) || !preg_match('/^\d{7}$/', (string) ($empresaAtiva['codigo_ibge_municipio'] ?? '')) || empty($empresaAtiva['inscricao_estadual']) || empty($empresaAtiva['logradouro']) || empty($empresaAtiva['numero']) || empty($empresaAtiva['bairro']) || empty($empresaAtiva['cep'])) {
            $erro = 'Complete CNPJ, Inscrição Estadual, endereço, UF e código IBGE da empresa emissora em Empresas emissoras antes de vender.';
        } elseif (!in_array((int) ($empresaAtiva['crt'] ?? 0), [1, 2, 3], true)) {
            $erro = 'Configure o regime tributário (CRT) da empresa emissora antes de vender.';
        } elseif (empty($empresaAtiva['nfce_csc_id']) || empty($empresaAtiva['nfce_csc_cifrado'])) {
            $erro = 'Configure a Série, o CSC e o CSCid da empresa em "Configuração NFC-e" antes de vender.';
        } else {
            $numericoNfce = static function (string $valorBruto): float {
                $valorBruto = trim($valorBruto);
                if (str_contains($valorBruto, ',')) {
                    $valorBruto = str_replace('.', '', $valorBruto);
                    $valorBruto = str_replace(',', '.', $valorBruto);
                }
                return (float) $valorBruto;
            };

            $produtoIds = $_POST['item_produto_id'] ?? [];
            $quantidades = $_POST['item_quantidade'] ?? [];
            $valoresUnitarios = $_POST['item_valor_unitario'] ?? [];

            $itensValidos = [];
            $valorTotalNota = 0.0;
            foreach ($produtoIds as $indice => $produtoIdBruto) {
                $produtoId = (int) $produtoIdBruto;
                $quantidade = round($numericoNfce((string) ($quantidades[$indice] ?? '0')), 3);
                $valorUnitarioInformado = trim((string) ($valoresUnitarios[$indice] ?? ''));
                if ($produtoId <= 0 || $quantidade <= 0 || !isset($catalogoPorId[$produtoId])) {
                    continue;
                }
                $produtoCatalogo = $catalogoPorId[$produtoId];
                $valorUnitario = $valorUnitarioInformado !== '' ? round($numericoNfce($valorUnitarioInformado), 2) : round((float) $produtoCatalogo['valor_unitario_padrao'], 2);
                if ($valorUnitario <= 0) {
                    continue;
                }
                $valorTotalItem = round($quantidade * $valorUnitario, 2);
                $itensValidos[] = [
                    'produto_servico_id' => $produtoId,
                    'descricao' => (string) $produtoCatalogo['descricao'],
                    'ncm' => $produtoCatalogo['ncm'],
                    'cfop' => $produtoCatalogo['cfop'],
                    'cst_csosn' => $produtoCatalogo['cst_csosn'],
                    'codigo_servico_municipal' => null,
                    'unidade' => $produtoCatalogo['unidade'],
                    'quantidade' => $quantidade,
                    'valor_unitario' => $valorUnitario,
                    'valor_total' => $valorTotalItem,
                    'cean' => $produtoCatalogo['cean'],
                    'cest' => $produtoCatalogo['cest'],
                    'cnpj_fabricante' => $produtoCatalogo['cnpj_fabricante'],
                    'indicador_escala_relevante' => $produtoCatalogo['indicador_escala_relevante'],
                    'codigo_beneficio_fiscal' => $produtoCatalogo['codigo_beneficio_fiscal'],
                    'icms_origem' => $produtoCatalogo['icms_origem'],
                    'icms_aliquota' => $produtoCatalogo['aliquota_icms'],
                    'icms_st_aliquota' => null,
                    'icms_st_base_calculo' => null,
                    'ipi_cst' => $produtoCatalogo['ipi_cst'],
                    'ipi_aliquota' => $produtoCatalogo['aliquota_ipi'],
                    'pis_aliquota' => $produtoCatalogo['aliquota_pis'],
                    'cofins_aliquota' => $produtoCatalogo['aliquota_cofins'],
                    'ibscbs_cclasstrib' => null,
                ];
                $valorTotalNota += $valorTotalItem;
            }
            $valorTotalNota = round($valorTotalNota, 2);

            $consumidorIdentificado = !empty($_POST['consumidor_identificado']) ? 1 : 0;
            $consumidorCpfCnpj = $consumidorIdentificado ? preg_replace('/\D+/', '', (string) ($_POST['consumidor_cpf_cnpj'] ?? '')) : '';
            $consumidorNome = $consumidorIdentificado ? trim((string) ($_POST['consumidor_nome'] ?? '')) : '';

            $formasPagamento = $_POST['pagamento_forma'] ?? [];
            $valoresPagamento = $_POST['pagamento_valor'] ?? [];
            $pagamentosValidos = [];
            $totalPago = 0.0;
            foreach ($formasPagamento as $indice => $formaBruta) {
                $forma = preg_replace('/\D+/', '', (string) $formaBruta);
                $valorPagamento = round($numericoNfce((string) ($valoresPagamento[$indice] ?? '0')), 2);
                if ($forma === '' || $valorPagamento <= 0) {
                    continue;
                }
                $pagamentosValidos[] = [
                    'forma_pagamento_codigo' => str_pad($forma, 2, '0', STR_PAD_LEFT),
                    'valor' => $valorPagamento,
                    'indicador_pagamento' => 0,
                    'ordem' => count($pagamentosValidos),
                ];
                $totalPago += $valorPagamento;
            }
            $totalPago = round($totalPago, 2);
            $valorTroco = max(0, round($totalPago - $valorTotalNota, 2));

            if (empty($itensValidos)) {
                $erro = 'Adicione ao menos um item com produto, quantidade e valor válidos.';
            } elseif ($consumidorIdentificado && !preg_match('/^\d{11}$|^\d{14}$/', $consumidorCpfCnpj)) {
                $erro = 'Informe um CPF (11 dígitos) ou CNPJ (14 dígitos) válido para identificar o consumidor, ou desmarque a identificação.';
            } elseif (empty($pagamentosValidos)) {
                $erro = 'Informe ao menos uma forma de pagamento com valor maior que zero.';
            } elseif ($totalPago + 0.009 < $valorTotalNota) {
                $erro = 'O total pago (R$ ' . number_format($totalPago, 2, ',', '.') . ') é menor que o total da venda (R$ ' . number_format($valorTotalNota, 2, ',', '.') . ').';
            } else {
                try {
                    foreach ($itensValidos as &$itemNfce) {
                        $impostosItem = nfeCalcularImpostosItem(
                            $itemNfce,
                            $empresaAtiva,
                            (string) ($itemNfce['cfop'] ?? ''),
                            (string) $empresaAtiva['uf']
                        );
                        $itemNfce = array_merge($itemNfce, $impostosItem);
                    }
                    unset($itemNfce);

                    $clienteId = obterClientePlaceholderNfce($dbNotas, (int) $empresaAtiva['id'], $funcionarioId);
                    $ambienteNota = ($empresaAtiva['ambiente_emissao'] ?? 'homologacao') === 'producao' ? 'producao' : 'homologacao';

                    $dbNotas->beginTransaction();

                    $serieNfce = (string) ($empresaAtiva['nfce_serie'] ?? '1');
                    $stmt = $dbNotas->prepare(
                        'SELECT COALESCE(MAX(numero_interno), 0) FROM notas_fiscais
                         WHERE empresa_emissora_id = :empresa_id AND tipo_nota = \'nfce\' AND serie = :serie FOR UPDATE'
                    );
                    $stmt->execute(['empresa_id' => (int) $empresaAtiva['id'], 'serie' => $serieNfce]);
                    $maxNumeroInternoAtual = (int) $stmt->fetchColumn();
                    $numeroBaseManual = (int) ($empresaAtiva['nfce_numero_base'] ?? 0);
                    $numeroInterno = max($maxNumeroInternoAtual, $numeroBaseManual) + 1;

                    $stmt = $dbNotas->prepare(
                        'INSERT INTO notas_fiscais (
                            empresa_emissora_id, cliente_id, funcionario_id, tipo_nota, natureza_operacao,
                            numero_interno, serie, status, ambiente, forma_pagamento, data_emissao, valor_total
                         ) VALUES (
                            :empresa_id, :cliente_id, :funcionario_id, \'nfce\', \'Venda ao consumidor\',
                            :numero_interno, :serie, \'rascunho\', :ambiente, \'Venda no balcão\', :data_emissao, :valor_total
                         )'
                    );
                    $stmt->execute([
                        'empresa_id' => (int) $empresaAtiva['id'],
                        'cliente_id' => $clienteId,
                        'funcionario_id' => $funcionarioId,
                        'numero_interno' => $numeroInterno,
                        'serie' => $serieNfce,
                        'ambiente' => $ambienteNota,
                        'data_emissao' => date('Y-m-d'),
                        'valor_total' => $valorTotalNota,
                    ]);
                    $notaId = (int) $dbNotas->lastInsertId();

                    $stmtItem = $dbNotas->prepare(
                        'INSERT INTO notas_fiscais_itens (
                            nota_id, produto_servico_id, descricao, ncm, cfop, cst_csosn, unidade,
                            quantidade, valor_unitario, valor_total, cean, cest, cnpj_fabricante, indicador_escala_relevante,
                            codigo_beneficio_fiscal, icms_origem, icms_modalidade_bc, icms_base_calculo,
                            icms_aliquota, icms_valor, icms_st_modalidade_bc, icms_st_aliquota, icms_st_base_calculo, icms_st_valor,
                            ipi_cst, ipi_base_calculo, ipi_aliquota, ipi_valor, pis_cst, pis_base_calculo, pis_aliquota, pis_valor,
                            cofins_cst, cofins_base_calculo, cofins_aliquota, cofins_valor
                         ) VALUES (
                            :nota_id, :produto_servico_id, :descricao, :ncm, :cfop, :cst_csosn, :unidade,
                            :quantidade, :valor_unitario, :valor_total, :cean, :cest, :cnpj_fabricante, :indicador_escala_relevante,
                            :codigo_beneficio_fiscal, :icms_origem, :icms_modalidade_bc, :icms_base_calculo,
                            :icms_aliquota, :icms_valor, :icms_st_modalidade_bc, :icms_st_aliquota, :icms_st_base_calculo, :icms_st_valor,
                            :ipi_cst, :ipi_base_calculo, :ipi_aliquota, :ipi_valor, :pis_cst, :pis_base_calculo, :pis_aliquota, :pis_valor,
                            :cofins_cst, :cofins_base_calculo, :cofins_aliquota, :cofins_valor
                         )'
                    );
                    foreach ($itensValidos as $item) {
                        $stmtItem->execute([
                            'nota_id' => $notaId,
                            'produto_servico_id' => $item['produto_servico_id'],
                            'descricao' => $item['descricao'],
                            'ncm' => $item['ncm'],
                            'cfop' => $item['cfop'],
                            'cst_csosn' => $item['cst_csosn'],
                            'unidade' => $item['unidade'],
                            'quantidade' => $item['quantidade'],
                            'valor_unitario' => $item['valor_unitario'],
                            'valor_total' => $item['valor_total'],
                            'cean' => $item['cean'] ?? null,
                            'cest' => $item['cest'] ?? null,
                            'cnpj_fabricante' => $item['cnpj_fabricante'] ?? null,
                            'indicador_escala_relevante' => $item['indicador_escala_relevante'] ?? null,
                            'codigo_beneficio_fiscal' => $item['codigo_beneficio_fiscal'] ?? null,
                            'icms_origem' => $item['icms_origem'] ?? null,
                            'icms_modalidade_bc' => $item['icms_modalidade_bc'] ?? null,
                            'icms_base_calculo' => $item['icms_base_calculo'] ?? null,
                            'icms_aliquota' => $item['icms_aliquota'] ?? null,
                            'icms_valor' => $item['icms_valor'] ?? null,
                            'icms_st_modalidade_bc' => $item['icms_st_modalidade_bc'] ?? null,
                            'icms_st_aliquota' => $item['icms_st_aliquota'] ?? null,
                            'icms_st_base_calculo' => $item['icms_st_base_calculo'] ?? null,
                            'icms_st_valor' => $item['icms_st_valor'] ?? null,
                            'ipi_cst' => $item['ipi_cst'] ?? null,
                            'ipi_base_calculo' => $item['ipi_base_calculo'] ?? null,
                            'ipi_aliquota' => $item['ipi_aliquota'] ?? null,
                            'ipi_valor' => $item['ipi_valor'] ?? null,
                            'pis_cst' => $item['pis_cst'] ?? null,
                            'pis_base_calculo' => $item['pis_base_calculo'] ?? null,
                            'pis_aliquota' => $item['pis_aliquota'] ?? null,
                            'pis_valor' => $item['pis_valor'] ?? null,
                            'cofins_cst' => $item['cofins_cst'] ?? null,
                            'cofins_base_calculo' => $item['cofins_base_calculo'] ?? null,
                            'cofins_aliquota' => $item['cofins_aliquota'] ?? null,
                            'cofins_valor' => $item['cofins_valor'] ?? null,
                        ]);
                    }

                    $informacoesComplementares = $valorTroco > 0 ? ('Troco: R$ ' . number_format($valorTroco, 2, ',', '.')) : null;
                    $stmt = $dbNotas->prepare(
                        'INSERT INTO notas_fiscais_nfce (
                            nota_id, indicador_presenca, consumidor_identificado, consumidor_cpf_cnpj, consumidor_nome,
                            informacoes_complementares, modo_emissao, epec_status
                         ) VALUES (
                            :nota_id, 4, :consumidor_identificado, :consumidor_cpf_cnpj, :consumidor_nome,
                            :informacoes_complementares, \'normal\', \'nenhum\'
                         )'
                    );
                    $stmt->execute([
                        'nota_id' => $notaId,
                        'consumidor_identificado' => $consumidorIdentificado,
                        'consumidor_cpf_cnpj' => $consumidorIdentificado ? $consumidorCpfCnpj : null,
                        'consumidor_nome' => $consumidorIdentificado && $consumidorNome !== '' ? $consumidorNome : null,
                        'informacoes_complementares' => $informacoesComplementares,
                    ]);

                    $stmtPagamento = $dbNotas->prepare(
                        'INSERT INTO notas_fiscais_nfce_pagamentos (nota_id, forma_pagamento_codigo, valor, indicador_pagamento, ordem)
                         VALUES (:nota_id, :forma_pagamento_codigo, :valor, :indicador_pagamento, :ordem)'
                    );
                    foreach ($pagamentosValidos as $pagamento) {
                        $stmtPagamento->execute([
                            'nota_id' => $notaId,
                            'forma_pagamento_codigo' => $pagamento['forma_pagamento_codigo'],
                            'valor' => $pagamento['valor'],
                            'indicador_pagamento' => $pagamento['indicador_pagamento'],
                            'ordem' => $pagamento['ordem'],
                        ]);
                    }

                    $dbNotas->commit();
                    registrarLogNotaNfce($dbNotas, $notaId, $funcionarioId, 'rascunho_criado', 'Venda NFC-e nº ' . $numeroInterno);

                    // Fluxo síncrono: monta/assina/transmite (ou cai em contingência EPEC) na
                    // mesma requisição — ver nfce-sefaz-integracao.php.
                    require_once __DIR__ . '/../nfce-sefaz-integracao.php';

                    $stmt = $dbNotas->prepare('SELECT * FROM notas_clientes WHERE id = :id LIMIT 1');
                    $stmt->execute(['id' => $clienteId]);
                    $clienteConsumidor = $stmt->fetch() ?: [];
                    $stmt = $dbNotas->prepare('SELECT * FROM notas_fiscais_itens WHERE nota_id = :nota_id ORDER BY id ASC');
                    $stmt->execute(['nota_id' => $notaId]);
                    $itensGravados = $stmt->fetchAll();
                    $stmt = $dbNotas->prepare('SELECT * FROM notas_fiscais WHERE id = :id LIMIT 1');
                    $stmt->execute(['id' => $notaId]);
                    $notaGravada = $stmt->fetch();
                    $stmt = $dbNotas->prepare('SELECT * FROM notas_fiscais_nfce WHERE nota_id = :nota_id LIMIT 1');
                    $stmt->execute(['nota_id' => $notaId]);
                    $nfceExtra = $stmt->fetch();
                    $stmt = $dbNotas->prepare('SELECT * FROM notas_fiscais_nfce_pagamentos WHERE nota_id = :nota_id ORDER BY ordem ASC');
                    $stmt->execute(['nota_id' => $notaId]);
                    $pagamentosGravados = $stmt->fetchAll();

                    $montagem = nfceMontarXml($notaGravada, $empresaAtiva, $clienteConsumidor, $nfceExtra, $itensGravados, $pagamentosGravados);
                    if (!empty($montagem['erros'])) {
                        $motivo = 'XML não gerado: ' . implode(' ', array_map('strval', $montagem['erros']));
                        $dbNotas->prepare("UPDATE notas_fiscais SET status = 'rejeitada', motivo_rejeicao = :motivo WHERE id = :id")->execute(['motivo' => mb_substr($motivo, 0, 60000), 'id' => $notaId]);
                        registrarLogNotaNfce($dbNotas, $notaId, $funcionarioId, 'rejeitada', mb_substr($motivo, 0, 255));
                        $erro = $motivo;
                    } else {
                        $resultado = nfceAssinarEEnviar($montagem['xml'], $empresaAtiva);

                        if (($resultado['status'] ?? 'pendente_envio') === 'pendente_envio') {
                            // SEFAZ indisponível/timeout: cai para contingência EPEC — a venda
                            // não para, o DANFCE de contingência é impresso na hora.
                            registrarLogNotaNfce($dbNotas, $notaId, $funcionarioId, 'envio_adiado', mb_substr((string) ($resultado['motivo_rejeicao'] ?? ''), 0, 255));
                            $resultado = nfceEmitirEmContingenciaEpec($notaGravada, $empresaAtiva, $clienteConsumidor, $nfceExtra, $itensGravados, $pagamentosGravados);
                        }

                        $stmt = $dbNotas->prepare('UPDATE notas_fiscais SET status = :status, chave_acesso = :chave_acesso, protocolo_autorizacao = :protocolo_autorizacao, xml_gerado = :xml_gerado, motivo_rejeicao = :motivo_rejeicao WHERE id = :id');
                        $stmt->execute([
                            'status' => $resultado['status'],
                            'chave_acesso' => $resultado['chave_acesso'],
                            'protocolo_autorizacao' => $resultado['protocolo_autorizacao'],
                            'xml_gerado' => $resultado['xml_gerado'],
                            'motivo_rejeicao' => $resultado['motivo_rejeicao'],
                            'id' => $notaId,
                        ]);

                        if (isset($resultado['modo_emissao'])) {
                            $dbNotas->prepare(
                                'UPDATE notas_fiscais_nfce SET modo_emissao = :modo_emissao, epec_status = :epec_status,
                                    epec_protocolo = :epec_protocolo, epec_enviado_em = :epec_enviado_em,
                                    qrcode_url = COALESCE(:qrcode_url, qrcode_url)
                                 WHERE nota_id = :nota_id'
                            )->execute([
                                'modo_emissao' => $resultado['modo_emissao'],
                                'epec_status' => $resultado['epec_status'] ?? 'nenhum',
                                'epec_protocolo' => $resultado['epec_protocolo'] ?? null,
                                'epec_enviado_em' => ($resultado['epec_status'] ?? 'nenhum') !== 'nenhum' ? date('Y-m-d H:i:s') : null,
                                'qrcode_url' => $resultado['qrcode_url'] ?? null,
                                'nota_id' => $notaId,
                            ]);
                        } elseif (!empty($resultado['qrcode_url'])) {
                            $dbNotas->prepare('UPDATE notas_fiscais_nfce SET qrcode_url = :qrcode_url WHERE nota_id = :nota_id')
                                ->execute(['qrcode_url' => $resultado['qrcode_url'], 'nota_id' => $notaId]);
                        }

                        registrarLogNotaNfce($dbNotas, $notaId, $funcionarioId, $resultado['sucesso'] ? 'autorizada' : 'rejeitada', $resultado['sucesso'] ? ('Chave de acesso: ' . $resultado['chave_acesso']) : ('Rejeitada: ' . $resultado['motivo_rejeicao']));

                        if ($resultado['sucesso']) {
                            $sucesso = 'NFC-e nº ' . $numeroInterno . ' emitida com sucesso.' . (($resultado['modo_emissao'] ?? 'normal') === 'contingencia_epec' ? ' (emitida em contingência EPEC — será vinculada quando a conexão com a SEFAZ voltar.)' : '');
                            $notaEmitida = ['id' => $notaId, 'numero_interno' => $numeroInterno] + $resultado;
                        } else {
                            $erro = 'Venda registrada, mas a NFC-e foi rejeitada pela SEFAZ: ' . $resultado['motivo_rejeicao'];
                            $notaEmitida = ['id' => $notaId, 'numero_interno' => $numeroInterno] + $resultado;
                        }
                    }
                } catch (Throwable $e) {
                    if ($dbNotas->inTransaction()) {
                        $dbNotas->rollBack();
                    }
                    $erro = 'Não foi possível registrar a venda: ' . $e->getMessage();
                }
            }
        }
    }
} catch (PDOException $e) {
    $erro = 'Erro ao carregar dados da NFC-e: ' . $e->getMessage();
    $empresasAtivas = $empresasAtivas ?? [];
    $catalogo = $catalogo ?? [];
}

$csrf = h($_SESSION['csrf_notas_nfce'] ?? '');
$usuario = h(trim(str_replace('.', ' ', $usuarioRaw ?? '')));
$catalogoJson = json_encode($catalogo ?? [], JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?: '[]';
