<?php
require_once __DIR__ . '/nfe-sefaz-integracao.php';
// Buscador de NF-e: usa a Distribuição de DFe da SEFAZ (NfeDistribuicaoDFe/sefazDistDFe) para
// baixar, por NSU sequencial, os documentos ligados ao CNPJ da empresa - tanto as NF-e que ela
// emitiu (emit = empresa) quanto as que ela recebeu como destinatária. Mesmo padrão da NFS-e
// (nfse-nacional-integracao.php), só que aqui é por UF (a lib resolve isso sozinha) e o
// documento pode vir de duas formas diferentes:
//
// - resNFe: resumo (chave, emitente, valor, data, situação) - é o que normalmente chega para
//   quem NÃO emitiu a nota (destinatário). Não tem destinatário nem itens, então não dá pra
//   gerar DANFE a partir dele (falta o XML completo assinado).
// - procNFe: NF-e completa (protocolo + XML autorizado) - normalmente para as notas que a
//   própria empresa emitiu. A partir dela dá pra gerar o DANFE localmente (mesma função já usada
//   em notas-fiscais.php).
//
// Eventos (cancelamento etc.) chegam como resEvento, referenciando a chave de uma nota já
// sincronizada - mesmo tratamento dado aos eventos da NFS-e (não sobrescreve os dados da nota,
// só marca cancelada).

// Extrai série e número da própria chave de acesso (44 dígitos), já que o resumo (resNFe) não
// traz esses campos separados - só a chave. Posições conforme o layout oficial da chave de NF-e.
function extrairSerieNumeroDaChaveNfe(string $chave44): array
{
    $chave44 = preg_replace('/\D+/', '', $chave44);
    if (strlen($chave44) !== 44) {
        return ['serie' => null, 'numero' => null];
    }

    return [
        'serie' => (int) substr($chave44, 22, 3),
        'numero' => (int) substr($chave44, 25, 9),
    ];
}

// Extrai os campos gravados em notas_fiscais_nfe_dfe a partir do conteúdo (já descompactado) de
// um docZip cujo schema é resNFe_* ou procNFe_* (chamador decide isso olhando o atributo schema
// antes de chamar). Retorna null se o XML não puder ser interpretado como NF-e/resumo de NF-e.
function extrairCamposNfeDfe(string $xmlConteudo, bool $documentoCompleto, string $cnpjEmpresa): ?array
{
    try {
        $xml = new SimpleXMLElement($xmlConteudo);
    } catch (Throwable $e) {
        return null;
    }

    if ($documentoCompleto) {
        $infNfe = $xml->NFe->infNFe ?? null;
        if ($infNfe === null) {
            return null;
        }
        $chave = str_replace('NFe', '', (string) $infNfe->attributes()->Id);
        $cnpjEmitente = preg_replace('/\D+/', '', (string) ($infNfe->emit->CNPJ ?? ''));
        $cnpjDestinatario = preg_replace('/\D+/', '', (string) ($infNfe->dest->CNPJ ?? $infNfe->dest->CPF ?? ''));
        $qtdItens = count($infNfe->det ?? []);
        $primeiroProduto = (string) ($infNfe->det[0]->prod->xProd ?? '');
        $descricao = $qtdItens > 1 ? "{$primeiroProduto} e mais " . ($qtdItens - 1) . ' item(ns)' : $primeiroProduto;
        $cStat = (string) ($xml->protNFe->infProt->cStat ?? '');

        return [
            'chave_acesso' => $chave,
            'tipo_documento' => $cnpjEmitente === $cnpjEmpresa ? 'emitida' : 'recebida',
            'numero_nfe' => (int) ($infNfe->ide->nNF ?? 0) ?: null,
            'serie' => (int) ($infNfe->ide->serie ?? 0) ?: null,
            'situacao' => $cStat === '101' ? 'cancelada' : ($cStat === '110' || $cStat === '301' || $cStat === '302' ? 'denegada' : 'autorizada'),
            'cnpj_emitente' => $cnpjEmitente !== '' ? $cnpjEmitente : null,
            'nome_emitente' => (string) ($infNfe->emit->xNome ?? '') ?: null,
            'cnpj_destinatario' => $cnpjDestinatario !== '' ? $cnpjDestinatario : null,
            'nome_destinatario' => (string) ($infNfe->dest->xNome ?? '') ?: null,
            'natureza_operacao' => (string) ($infNfe->ide->natOp ?? '') ?: null,
            'descricao_resumida' => $descricao !== '' ? $descricao : null,
            'data_emissao' => !empty($infNfe->ide->dhEmi) ? date('Y-m-d H:i:s', strtotime((string) $infNfe->ide->dhEmi)) : null,
            'valor_nfe' => (float) ($infNfe->total->ICMSTot->vNF ?? 0),
            'protocolo_autorizacao' => (string) ($xml->protNFe->infProt->nProt ?? '') ?: null,
            'tem_documento_completo' => 1,
        ];
    }

    // resNFe: resumo, sem destinatário nem itens - número/série vêm da própria chave.
    $chave = (string) ($xml->chNFe ?? '');
    if ($chave === '') {
        return null;
    }
    $cnpjEmitente = preg_replace('/\D+/', '', (string) ($xml->CNPJ ?? ''));
    $serieNumero = extrairSerieNumeroDaChaveNfe($chave);
    $cSitNFe = (string) ($xml->cSitNFe ?? '');

    return [
        'chave_acesso' => $chave,
        'tipo_documento' => $cnpjEmitente === $cnpjEmpresa ? 'emitida' : 'recebida',
        'numero_nfe' => $serieNumero['numero'],
        'serie' => $serieNumero['serie'],
        'situacao' => $cSitNFe === '2' ? 'denegada' : 'autorizada',
        'cnpj_emitente' => $cnpjEmitente !== '' ? $cnpjEmitente : null,
        'nome_emitente' => (string) ($xml->xNome ?? '') ?: null,
        'cnpj_destinatario' => null,
        'nome_destinatario' => null,
        'natureza_operacao' => null,
        'descricao_resumida' => null,
        'data_emissao' => !empty($xml->dhEmi) ? date('Y-m-d H:i:s', strtotime((string) $xml->dhEmi)) : null,
        'valor_nfe' => (float) ($xml->vNF ?? 0),
        'protocolo_autorizacao' => (string) ($xml->nProt ?? '') ?: null,
        'tem_documento_completo' => 0,
    ];
}

// Códigos de evento (tabela oficial da NF-e) que significam "esta NF-e foi cancelada".
const NFE_DFE_CODIGOS_EVENTO_CANCELAMENTO = ['110111'];

// Aplica um evento (resEvento) recebido da SEFAZ: só nos interessa marcar cancelamento na nota já
// sincronizada, identificada pela chave que o próprio evento referencia.
function aplicarEventoNfeDfe(PDO $dbNotas, string $xmlConteudo): bool
{
    try {
        $xml = new SimpleXMLElement($xmlConteudo);
    } catch (Throwable $e) {
        return false;
    }
    if ((string) ($xml->getName()) !== 'resEvento' || empty($xml->chNFe)) {
        return false;
    }

    $chave = preg_replace('/\D+/', '', (string) $xml->chNFe);
    $tipoEvento = (string) ($xml->tpEvento ?? '');
    if ($chave !== '' && in_array($tipoEvento, NFE_DFE_CODIGOS_EVENTO_CANCELAMENTO, true)) {
        $stmt = $dbNotas->prepare(
            'UPDATE notas_fiscais_nfe_dfe
             SET cancelada = 1, data_cancelamento = COALESCE(data_cancelamento, :data_evento), atualizado_em = NOW()
             WHERE chave_acesso = :chave_acesso'
        );
        $stmt->execute([
            'data_evento' => !empty($xml->dhEvento) ? date('Y-m-d H:i:s', strtotime((string) $xml->dhEvento)) : date('Y-m-d H:i:s'),
            'chave_acesso' => $chave,
        ]);
    }

    return true;
}

// Manifestação do Destinatário - Ciência da Operação (evento 210210). A SEFAZ só libera o XML
// completo (procNFe) de uma NF-e recebida de terceiros DEPOIS desse evento; até lá só manda o
// resumo (resNFe). É o mesmo mecanismo que sistemas como o SIEG usam para conseguir "tudo".
// Não confirma nem valida a operação - só declara que a empresa está ciente de que ela existe.
function manifestarCienciaNfe(array $empresa, string $chave): array
{
    [$ok, $erro, $tools] = montarToolsNfe($empresa);
    if (!$ok) {
        return ['sucesso' => false, 'mensagem' => $erro];
    }

    try {
        $resposta = $tools->sefazManifesta($chave, \NFePHP\NFe\Tools::EVT_CIENCIA);
        $std = new SimpleXMLElement($resposta);
        $retEvento = $std->xpath('//*[local-name()="retEvento"]');
        $infEvento = !empty($retEvento)
            ? ($retEvento[0]->children('http://www.portalfiscal.inf.br/nfe')->infEvento ?? $retEvento[0]->infEvento)
            : null;
        $cStat = $infEvento !== null ? (string) $infEvento->cStat : '';
        $xMotivo = $infEvento !== null ? (string) $infEvento->xMotivo : 'Retorno inesperado da SEFAZ.';

        // 135/136: evento registrado. 573: já tinha sido manifestada antes (duplicidade) - também
        // conta como sucesso, só não faz sentido tentar de novo depois.
        if (in_array($cStat, ['135', '136', '573'], true)) {
            return ['sucesso' => true, 'mensagem' => "[{$cStat}] {$xMotivo}"];
        }

        return ['sucesso' => false, 'mensagem' => "[{$cStat}] {$xMotivo}"];
    } catch (Throwable $e) {
        return ['sucesso' => false, 'mensagem' => 'Falha ao manifestar ciência: ' . trim(strip_tags($e->getMessage()))];
    }
}

function sincronizarNfeDfe(PDO $dbNotas, array $empresa): array
{
    [$ok, $erro, $tools] = montarToolsNfe($empresa);
    if (!$ok) {
        return ['sucesso' => false, 'mensagem' => $erro, 'total' => 0];
    }

    $cnpjEmpresa = preg_replace('/\D+/', '', (string) ($empresa['cnpj'] ?? ''));
    if ($cnpjEmpresa === '') {
        return ['sucesso' => false, 'mensagem' => 'Empresa sem CNPJ cadastrado; cadastre o CNPJ em Empresas emissoras.', 'total' => 0];
    }

    $totalProcessado = 0;

    try {
        $stmtUpsert = $dbNotas->prepare(
            'INSERT INTO notas_fiscais_nfe_dfe
                (empresa_emissora_id, chave_acesso, nsu, tipo_documento, numero_nfe, serie, situacao,
                 cnpj_emitente, nome_emitente, cnpj_destinatario, nome_destinatario, natureza_operacao,
                 descricao_resumida, data_emissao, valor_nfe, protocolo_autorizacao, tem_documento_completo,
                 xml_completo, atualizado_em)
             VALUES
                (:empresa_emissora_id, :chave_acesso, :nsu, :tipo_documento, :numero_nfe, :serie, :situacao,
                 :cnpj_emitente, :nome_emitente, :cnpj_destinatario, :nome_destinatario, :natureza_operacao,
                 :descricao_resumida, :data_emissao, :valor_nfe, :protocolo_autorizacao, :tem_documento_completo,
                 :xml_completo, NOW())
             ON DUPLICATE KEY UPDATE
                nsu = VALUES(nsu), tipo_documento = VALUES(tipo_documento), numero_nfe = VALUES(numero_nfe),
                serie = VALUES(serie), situacao = VALUES(situacao), cnpj_emitente = VALUES(cnpj_emitente),
                nome_emitente = VALUES(nome_emitente),
                cnpj_destinatario = COALESCE(VALUES(cnpj_destinatario), cnpj_destinatario),
                nome_destinatario = COALESCE(VALUES(nome_destinatario), nome_destinatario),
                natureza_operacao = COALESCE(VALUES(natureza_operacao), natureza_operacao),
                descricao_resumida = COALESCE(VALUES(descricao_resumida), descricao_resumida),
                data_emissao = VALUES(data_emissao), valor_nfe = VALUES(valor_nfe),
                protocolo_autorizacao = VALUES(protocolo_autorizacao),
                tem_documento_completo = GREATEST(tem_documento_completo, VALUES(tem_documento_completo)),
                xml_completo = IF(VALUES(tem_documento_completo) = 1, VALUES(xml_completo), xml_completo),
                atualizado_em = NOW()'
        );

        $ultimoNsu = (int) ($empresa['nfe_dfe_ultimo_nsu'] ?? 0);
        // A SEFAZ também aplica rate limit por CNPJ nesse serviço; mesma cautela usada na NFS-e.
        $maximoLotes = 5;

        $stmtEmpresa = $dbNotas->prepare('UPDATE empresas_emissoras SET nfe_dfe_ultimo_nsu = :nsu, nfe_dfe_sincronizado_em = NOW() WHERE id = :id');

        for ($lote = 0; $lote < $maximoLotes; $lote++) {
            $respostaXml = $tools->sefazDistDFe($ultimoNsu);
            $resposta = new SimpleXMLElement($respostaXml);
            // A resposta pode vir com um wrapper da operação SOAP por cima de retDistDFeInt (varia
            // conforme a UF/versão), então acessar campos direto na raiz (ex.: $resposta->cStat)
            // falha silenciosamente quando há esse wrapper. Usa xpath com local-name() - acha o nó em
            // qualquer profundidade, sem depender de prefixo/namespace - mesmo padrão já usado em
            // nfe-operacoes.php para as respostas de consulta/cancelamento.
            $noCStat = $resposta->xpath('//*[local-name()="cStat"]');
            $cStat = !empty($noCStat) ? (string) $noCStat[0] : '';
            $noXMotivo = $resposta->xpath('//*[local-name()="xMotivo"]');
            $xMotivo = !empty($noXMotivo) ? (string) $noXMotivo[0] : 'Resposta inesperada da SEFAZ.';
            $noUltNsu = $resposta->xpath('//*[local-name()="ultNSU"]');
            $docZips = $resposta->xpath('//*[local-name()="docZip"]') ?: [];

            if ($cStat !== '137' && $cStat !== '138' && empty($docZips)) {
                // Código diferente de "documentos localizados"/"nenhum documento localizado" e sem
                // lote: trata como aviso, não erro - encerra o laço sem mais tentativas.
                if ($totalProcessado === 0) {
                    return ['sucesso' => true, 'mensagem' => "Portal da SEFAZ: [{$cStat}] {$xMotivo}", 'total' => 0];
                }
                break;
            }

            foreach ($docZips as $docZip) {
                $schema = (string) $docZip->attributes()->schema;
                $nsuDoc = (string) $docZip->attributes()->NSU;
                $xmlDoc = @gzdecode(base64_decode((string) $docZip));
                if ($xmlDoc === false || $xmlDoc === '') {
                    continue;
                }

                if (str_starts_with($schema, 'resEvento') || str_starts_with($schema, 'procEventoNFe')) {
                    aplicarEventoNfeDfe($dbNotas, $xmlDoc);
                    continue;
                }

                $documentoCompleto = str_starts_with($schema, 'procNFe');
                if (!$documentoCompleto && !str_starts_with($schema, 'resNFe')) {
                    continue;
                }

                $campos = extrairCamposNfeDfe($xmlDoc, $documentoCompleto, $cnpjEmpresa);
                if ($campos === null) {
                    continue;
                }

                $campos['empresa_emissora_id'] = (int) $empresa['id'];
                $campos['nsu'] = $nsuDoc !== '' ? (int) $nsuDoc : null;
                $campos['xml_completo'] = $documentoCompleto ? $xmlDoc : null;
                $stmtUpsert->execute($campos);
                $totalProcessado++;
            }

            $novoUltimoNsu = !empty($noUltNsu) ? (int) $noUltNsu[0] : $ultimoNsu;
            if ($novoUltimoNsu <= $ultimoNsu) {
                $ultimoNsu = $novoUltimoNsu;
                $stmtEmpresa->execute(['nsu' => $ultimoNsu, 'id' => (int) $empresa['id']]);
                break;
            }
            $ultimoNsu = $novoUltimoNsu;
            $stmtEmpresa->execute(['nsu' => $ultimoNsu, 'id' => (int) $empresa['id']]);
            if (empty($docZips)) {
                break;
            }
        }

        // Manifesta Ciência da Operação nas notas recebidas que só têm resumo (sem XML completo)
        // e ainda não foram manifestadas - isso é o que faz a SEFAZ liberar o documento completo
        // numa sincronização futura. Lote pequeno pra não estourar limite de eventos por minuto.
        $totalManifestado = 0;
        $stmtPendentesManifestacao = $dbNotas->prepare(
            "SELECT id, chave_acesso FROM notas_fiscais_nfe_dfe
             WHERE empresa_emissora_id = :empresa_emissora_id AND tipo_documento = 'recebida'
               AND tem_documento_completo = 0 AND manifestada = 0
             LIMIT 5"
        );
        $stmtPendentesManifestacao->execute(['empresa_emissora_id' => (int) $empresa['id']]);
        $stmtMarcarManifestada = $dbNotas->prepare('UPDATE notas_fiscais_nfe_dfe SET manifestada = 1, data_manifestacao = NOW() WHERE id = :id');
        foreach ($stmtPendentesManifestacao->fetchAll() as $pendente) {
            $resultadoManifesto = manifestarCienciaNfe($empresa, (string) $pendente['chave_acesso']);
            if ($resultadoManifesto['sucesso']) {
                $stmtMarcarManifestada->execute(['id' => (int) $pendente['id']]);
                $totalManifestado++;
            }
        }

        $mensagem = "Sincronização concluída: {$totalProcessado} documento(s) novo(s)/atualizado(s).";
        if ($totalManifestado > 0) {
            $mensagem .= " Ciência da Operação enviada para {$totalManifestado} nota(s) recebida(s); o XML completo delas deve aparecer numa próxima sincronização.";
        }
        if ($totalProcessado > 0) {
            $mensagem .= ' Se ainda houver documentos mais antigos pendentes, clique em "Sincronizar agora" de novo para continuar.';
        }

        return ['sucesso' => true, 'mensagem' => $mensagem, 'total' => $totalProcessado];
    } catch (Throwable $e) {
        return ['sucesso' => false, 'mensagem' => 'Falha ao consultar a SEFAZ: ' . trim(strip_tags($e->getMessage())), 'total' => $totalProcessado];
    }
}

// Coloca em dia TODAS as empresas com certificado válido para NF-e (mesma checagem de
// certificado usada na NFS-e - certificadoEmpresaDisponivel() não é específica de nenhuma delas).
//
// $maxTentativasPorEmpresa alto (cada tentativa já busca até 5 lotes) e $tempoLimiteSegundos
// existem pra dar conta de um catch-up retroativo grande (ex.: 3 meses de histórico numa empresa
// que nunca sincronizou) num único cron, sem depender de dezenas de execuções - mas sem correr o
// risco de estourar o tempo de execução do PHP. Não aumenta o número de chamadas feitas à SEFAZ
// além do necessário: cada empresa já para sozinha assim que não há mais documento novo ou dá erro
// (ex.: rate limit) - o limite alto só permite ir mais fundo quando o backlog é grande de verdade.
function sincronizarTodasEmpresasNfeDfe(PDO $dbNotas, int $maxTentativasPorEmpresa = 40, int $tempoLimiteSegundos = 90): array
{
    $resultados = [];
    $inicio = microtime(true);

    foreach (empresasComCertificadoValidoAdn($dbNotas) as $empresa) {
        if ((microtime(true) - $inicio) > $tempoLimiteSegundos) {
            break; // orçamento de tempo estourado - as empresas restantes ficam pra próxima execução
        }

        $totalEmpresa = 0;
        $ultimaMensagem = '';
        $ultimoSucesso = true;

        for ($tentativa = 0; $tentativa < $maxTentativasPorEmpresa; $tentativa++) {
            if ((microtime(true) - $inicio) > $tempoLimiteSegundos) {
                $ultimaMensagem .= ' (parou por orçamento de tempo; continua na próxima execução)';
                break;
            }

            $resultado = sincronizarNfeDfe($dbNotas, $empresa);
            $ultimaMensagem = $resultado['mensagem'];
            $ultimoSucesso = $resultado['sucesso'];
            $totalEmpresa += $resultado['total'];

            if (!$resultado['sucesso'] || $resultado['total'] === 0) {
                break;
            }

            $stmtRecarregar = $dbNotas->prepare('SELECT * FROM empresas_emissoras WHERE id = :id LIMIT 1');
            $stmtRecarregar->execute(['id' => (int) $empresa['id']]);
            $empresa = $stmtRecarregar->fetch();
        }

        $resultados[] = [
            'empresa' => $empresa['razao_social'],
            'sucesso' => $ultimoSucesso,
            'total' => $totalEmpresa,
            'mensagem' => $ultimaMensagem,
        ];
    }

    return $resultados;
}
?>
