<?php
$tipoNotaFixo = 'nfse';
require_once __DIR__ . '/includes/notas-emitir-motor.php';
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Emitir NFS-e | ACCOUNT Contabilidade</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600&family=Montserrat:wght@500;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="stylesheet" href="assets/css/notas-fiscais.css">
</head>
<body>
    <div class="shell">
        <?php $paginaAtivaNotas = 'emitir_servico'; include __DIR__ . '/includes/notas-nav.php'; ?>

        <section class="panel">
            <h1><?php echo $notaEmEdicao ? 'Corrigir NFS-e nº ' . h((string) $notaEmEdicao['numero_interno']) : 'Emitir NFS-e (serviço)'; ?></h1>
            <p class="muted">Olá, <?php echo $usuario; ?>. Monte notas de prestação de serviço para outras empresas com a integração ao Ambiente de Dados Nacional/SEFIN Nacional — acompanhe tudo em <a href="notas-fiscais" style="text-decoration:underline;">Notas fiscais</a>. Para venda de produtos, use <a href="notas-emitir-produto" style="text-decoration:underline;">Emitir NF-e</a>.</p>
        </section>

        <?php if ($erro !== ''): ?>
            <div class="notice error"><?php echo h($erro); ?></div>
        <?php endif; ?>

        <?php if ($sucesso !== ''): ?>
            <div class="notice"><?php echo h($sucesso); ?></div>
        <?php endif; ?>

        <?php if (empty($empresasAtivas)): ?>
            <div class="notice error">Nenhuma empresa emissora ativa. <?php echo $podeAdministrar ? 'Cadastre uma em <a href="notas-empresas-emissoras" style="text-decoration:underline;">Empresas emissoras</a>.' : 'Peça para um administrador cadastrar em Empresas emissoras.'; ?></div>
        <?php else: ?>

            <div class="notice">
                <i class="fa-solid fa-circle-info"></i>
                Não achou o cliente na lista? <a href="notas-clientes" style="text-decoration:underline;">Cadastre um novo cliente</a> na aba Clientes e volte para escolhê-lo aqui.
            </div>

            <section class="panel">
                <h2><?php echo $notaEmEdicao ? 'Corrigir NFS-e nº ' . h((string) $notaEmEdicao['numero_interno']) : 'Nova nota (rascunho)'; ?></h2>
                <form method="post" id="formNota">
                    <input type="hidden" name="csrf" value="<?php echo $csrf; ?>">
                    <input type="hidden" name="acao" value="<?php echo $notaEmEdicao ? 'salvar_edicao' : 'criar_nota'; ?>">
                    <?php if ($notaEmEdicao): ?>
                        <input type="hidden" name="nota_id_edicao" value="<?php echo h((string) $notaEmEdicao['id']); ?>">
                        <input type="hidden" name="nota_atualizada_em" value="<?php echo h((string) $notaEmEdicao['atualizado_em']); ?>">
                    <?php endif; ?>
                    <div class="form-grid">
                        <div class="field">
                            <label>Empresa emissora</label>
                            <?php
                                $empresaEmissoraPadrao = (int) ($_SESSION['nfse_empresa_emissora_ativa_id'] ?? 0);
                                $empresaExibicaoId = $notaEmEdicao ? (int) $notaEmEdicao['empresa_emissora_id'] : $empresaEmissoraPadrao;
                                $empresaAtivaSelecionada = null;
                                foreach ($empresasAtivas as $empresa) {
                                    if ((int) $empresa['id'] === $empresaExibicaoId) {
                                        $empresaAtivaSelecionada = $empresa;
                                        break;
                                    }
                                }
                            ?>
                            <div class="campo-fixo">
                                <?php if ($empresaAtivaSelecionada): ?>
                                    <?php echo h($empresaAtivaSelecionada['razao_social']); ?> (<?php echo h(($empresaAtivaSelecionada['ambiente_emissao'] ?? 'homologacao') === 'producao' ? 'Produção' : 'Homologação'); ?>)
                                <?php else: ?>
                                    Nenhuma empresa selecionada
                                <?php endif; ?>
                            </div>
                            <input type="hidden" id="empresa_emissora_id" name="empresa_emissora_id" value="<?php echo h((string) $empresaExibicaoId); ?>" data-ibge="<?php echo h($empresaAtivaSelecionada['codigo_ibge_municipio'] ?? ''); ?>">
                            <p class="muted" style="margin-top:0.35rem;font-size:0.78rem;"><?php echo $notaEmEdicao ? 'A empresa emissora não pode ser alterada nesta correção.' : 'Para trocar, use "Emitindo por" no topo da página.'; ?> <a href="notas-empresas-emissoras" style="text-decoration:underline;">Gerenciar empresas emissoras</a>.</p>
                        </div>
                        <div class="field" style="grid-column: 1 / -1;">
                            <label for="busca_cliente_documento">Buscar cliente por CNPJ/CPF</label>
                            <div class="row-actions">
                                <input id="busca_cliente_documento" type="text" style="flex: 1;" placeholder="Digite o CNPJ ou CPF do cliente já cadastrado">
                                <button class="btn btn-outline btn-small" type="button" id="btnBuscarClienteDocumento"><i class="fa-solid fa-magnifying-glass"></i> Buscar</button>
                            </div>
                            <span class="muted" id="statusBuscaClienteDocumento" style="font-size: 0.78rem;"></span>
                        </div>
                        <div class="field">
                            <label for="cliente_id">Cliente destinatário (tomador)</label>
                            <select id="cliente_id" name="cliente_id" required>
                                <option value="">Selecione</option>
                                <?php foreach ($clientes as $cliente): ?>
                                    <option value="<?php echo h((string) $cliente['id']); ?>" data-documento="<?php echo h(preg_replace('/\D/', '', (string) ($cliente['cnpj_cpf'] ?? ''))); ?>" data-inscricao-municipal="<?php echo h((string) ($cliente['inscricao_municipal'] ?? '')); ?>"><?php echo h($cliente['nome_razao_social'] . (($cliente['cnpj_cpf'] ?? '') !== '' ? ' - ' . $cliente['cnpj_cpf'] : '')); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="field">
                            <label for="natureza_operacao">Natureza da operação</label>
                            <input id="natureza_operacao" name="natureza_operacao" type="text" placeholder="Ex.: Prestação de serviço" required>
                        </div>
                        <div class="field">
                            <label for="forma_pagamento">Forma de pagamento</label>
                            <input id="forma_pagamento" name="forma_pagamento" type="text" placeholder="Pix, boleto, cartão...">
                        </div>
                        <div class="field">
                            <label for="data_emissao">Data de emissão</label>
                            <input id="data_emissao" name="data_emissao" type="date" value="<?php echo h(date('Y-m-d')); ?>">
                        </div>
                        <div class="field">
                            <label for="data_saida_entrada">Data de saída/entrada</label>
                            <input id="data_saida_entrada" name="data_saida_entrada" type="date">
                        </div>
                        <div class="field" style="grid-column: 1 / -1;">
                            <label for="informacoes_frete">Frete/transporte (opcional)</label>
                            <textarea id="informacoes_frete" name="informacoes_frete" placeholder="Transportadora, placa, volume, peso..."></textarea>
                        </div>
                    </div>

                    <div class="form-jump" id="atalhosNfse">
                        <button type="button" data-form-jump="secaoCompetencia"><i class="fa-solid fa-calendar-days"></i> Competência</button>
                        <button type="button" data-form-jump="secaoTomador"><i class="fa-solid fa-user"></i> Tomador</button>
                        <button type="button" data-form-jump="secaoIntermediario"><i class="fa-solid fa-people-arrows"></i> Intermediário</button>
                        <button type="button" data-form-jump="secaoLocal"><i class="fa-solid fa-location-dot"></i> Local</button>
                        <button type="button" data-form-jump="secaoServico"><i class="fa-solid fa-briefcase"></i> Serviço</button>
                        <button type="button" data-form-jump="secaoIbscbs"><i class="fa-solid fa-scale-balanced"></i> IBS/CBS</button>
                        <button type="button" data-form-jump="secaoComplementares"><i class="fa-solid fa-circle-info"></i> Complementares</button>
                        <button type="button" data-form-jump="secaoValores"><i class="fa-solid fa-sack-dollar"></i> Valores</button>
                        <button type="button" data-form-jump="secaoTributacaoMunicipal"><i class="fa-solid fa-city"></i> Trib. municipal</button>
                        <button type="button" data-form-jump="secaoTributacaoFederal"><i class="fa-solid fa-landmark"></i> Trib. federal</button>
                        <button type="button" data-form-jump="secaoTributosAproximados"><i class="fa-solid fa-percent"></i> Tributos aprox.</button>
                    </div>

                    <details class="form-section" id="secaoCompetencia" open>
                        <summary><span class="form-section-titulo"><i class="fa-solid fa-calendar-days"></i> Competência e DPS</span><i class="fa-solid fa-chevron-down"></i></summary>
                        <div class="form-section-corpo">
                    <div class="form-grid">
                        <div class="field">
                            <label for="nfse_data_competencia">Data de competência</label>
                            <input id="nfse_data_competencia" name="nfse_data_competencia" type="date" value="<?php echo h(date('Y-m-d')); ?>">
                        </div>
                        <label class="check-row">
                            <input type="checkbox" id="nfse_informar_dps" name="nfse_informar_dps">
                            Informar série e número da DPS
                        </label>
                    </div>
                    <div class="form-grid" id="camposDpsManual" style="display:none;">
                        <div class="field">
                            <label for="nfse_serie_dps">Série da DPS</label>
                            <input id="nfse_serie_dps" name="nfse_serie_dps" type="text" maxlength="5">
                        </div>
                        <div class="field">
                            <label for="nfse_numero_dps">Número da DPS</label>
                            <input id="nfse_numero_dps" name="nfse_numero_dps" type="text" maxlength="15">
                        </div>
                    </div>
                        </div>
                    </details>

                    <details class="form-section" id="secaoTomador" open>
                        <summary><span class="form-section-titulo"><i class="fa-solid fa-user"></i> Tomador do serviço</span><i class="fa-solid fa-chevron-down"></i></summary>
                        <div class="form-section-corpo">
                    <p class="muted" style="margin-bottom: 0.75rem;">Documento, nome e e-mail do tomador vêm do cliente destinatário selecionado acima.</p>
                    <div class="form-grid">
                        <div class="field">
                            <label for="nfse_tomador_local">Onde está localizado o estabelecimento/domicílio?</label>
                            <select id="nfse_tomador_local" name="nfse_tomador_local">
                                <option value="nao_informado">Tomador não informado</option>
                                <option value="brasil" selected>Brasil</option>
                                <option value="exterior">Exterior</option>
                            </select>
                        </div>
                        <div class="field" id="campoTomadorInscricaoMunicipal">
                            <label for="nfse_tomador_inscricao_municipal">Inscrição Municipal do tomador</label>
                            <input id="nfse_tomador_inscricao_municipal" name="nfse_tomador_inscricao_municipal" type="text">
                        </div>
                        <div class="field">
                            <label for="nfse_tomador_telefone">Telefone (opcional)</label>
                            <input id="nfse_tomador_telefone" name="nfse_tomador_telefone" type="text">
                        </div>
                    </div>
                        </div>
                    </details>

                    <details class="form-section" id="secaoIntermediario">
                        <summary><span class="form-section-titulo"><i class="fa-solid fa-people-arrows"></i> Intermediário do serviço</span><i class="fa-solid fa-chevron-down"></i></summary>
                        <div class="form-section-corpo">
                    <label class="check-row" style="margin-top: 0;">
                        <input type="checkbox" id="nfse_intermediario_incluido" name="nfse_intermediario_incluido">
                        Esta NFS-e tem intermediário
                    </label>
                    <div class="form-grid" id="camposIntermediario" style="display:none; margin-top: 1rem;">
                        <div class="field">
                            <label for="nfse_intermediario_local">Onde está localizado o estabelecimento/domicílio?</label>
                            <select id="nfse_intermediario_local" name="nfse_intermediario_local">
                                <option value="nao_informado">Intermediário não informado</option>
                                <option value="brasil">Brasil</option>
                            </select>
                        </div>
                        <div class="field">
                            <label for="nfse_intermediario_cpf_cnpj">CPF/CNPJ do intermediário</label>
                            <input id="nfse_intermediario_cpf_cnpj" name="nfse_intermediario_cpf_cnpj" type="text">
                        </div>
                        <div class="field">
                            <label for="nfse_intermediario_nome">Nome/Razão social do intermediário</label>
                            <input id="nfse_intermediario_nome" name="nfse_intermediario_nome" type="text">
                        </div>
                    </div>
                        </div>
                    </details>

                    <details class="form-section" id="secaoLocal" open>
                        <summary><span class="form-section-titulo"><i class="fa-solid fa-location-dot"></i> Local da prestação do serviço</span><i class="fa-solid fa-chevron-down"></i></summary>
                        <div class="form-section-corpo">
                    <div class="form-grid">
                        <div class="field">
                            <label for="nfse_pais_prestacao">País</label>
                            <input id="nfse_pais_prestacao" name="nfse_pais_prestacao" type="text" value="Brasil">
                        </div>
                        <div class="field municipio-autocomplete">
                            <label for="nfse_municipio_prestacao_busca">Município</label>
                            <input id="nfse_municipio_prestacao_busca" type="search" required placeholder="Digite o início do município" autocomplete="off" role="combobox" aria-autocomplete="list" aria-controls="nfse_municipio_prestacao_opcoes" aria-expanded="false">
                            <input id="nfse_municipio_prestacao" name="nfse_municipio_prestacao" type="hidden">
                            <div id="nfse_municipio_prestacao_opcoes" class="municipio-sugestoes" role="listbox"></div>
                            <span class="muted" id="nfse_municipio_prestacao_status" style="font-size: 0.78rem;">Pesquise pelo início do nome e selecione o município.</span>
                        </div>
                    </div>
                        </div>
                    </details>

                    <details class="form-section" id="secaoServico" open>
                        <summary><span class="form-section-titulo"><i class="fa-solid fa-briefcase"></i> Serviço prestado</span><i class="fa-solid fa-chevron-down"></i></summary>
                        <div class="form-section-corpo">
                    <div class="form-grid">
                        <div class="field">
                            <label for="nfse_codigo_tributacao_nacional">Código de Tributação Nacional (LC 116)</label>
                            <input id="nfse_codigo_tributacao_nacional" name="nfse_codigo_tributacao_nacional" type="text" required placeholder="Digite para buscar. Ex.: 17.19.01" list="datalistCodigosNacionais" autocomplete="off">
                            <datalist id="datalistCodigosNacionais">
                                <?php foreach ($codigosTributacaoNacionalNfse as $codigoNacional): ?>
                                    <option value="<?php echo h($codigoNacional['codigo']); ?>"><?php echo h($codigoNacional['codigo'] . ' - ' . $codigoNacional['descricao']); ?></option>
                                <?php endforeach; ?>
                            </datalist>
                        </div>
                        <div class="field">
                            <label for="nfse_codigo_tributacao_municipal">Código Complementar Municipal</label>
                            <select id="nfse_codigo_tributacao_municipal" name="nfse_codigo_tributacao_municipal">
                                <option value="">Escolha primeiro o código de tributação nacional</option>
                            </select>
                            <span class="muted" id="nfse_codigo_tributacao_municipal_status" style="font-size: 0.78rem;"></span>
                        </div>

                        <div class="field">
                            <label for="nfse_item_nbs">NBS do serviço (cNBS)</label>
                            <select id="nfse_item_nbs" name="nfse_item_nbs" disabled>
                                <option value="">Escolha primeiro o serviço prestado</option>
                            </select>
                            <span class="muted" id="nfse_item_nbs_status" style="font-size: 0.78rem;">A NBS será definida conforme a correlação oficial da NFS-e.</span>
                        </div>
                        <div class="field">
                            <label for="nfse_codigo_interno_contribuinte">Código interno do contribuinte</label>
                            <input id="nfse_codigo_interno_contribuinte" name="nfse_codigo_interno_contribuinte" type="text" required placeholder="Seu código de controle interno para este serviço">
                        </div>
                        <div class="field" style="grid-column: 1 / -1;">
                            <label for="nfse_descricao_servico">Descrição do serviço</label>
                            <textarea id="nfse_descricao_servico" name="nfse_descricao_servico" maxlength="2000" required placeholder="Descreva o serviço prestado"></textarea>
                        </div>
                    </div>
                        </div>
                    </details>

                    <details class="form-section" id="secaoIbscbs">
                        <summary><span class="form-section-titulo"><i class="fa-solid fa-scale-balanced"></i> IBS/CBS — Reforma Tributária (NT 004)</span><i class="fa-solid fa-chevron-down"></i></summary>
                        <div class="form-section-corpo">
                    <p class="muted">Pesquise e selecione os códigos das tabelas oficiais. Ao escolher a classificação tributária, o CST correspondente é preenchido automaticamente.</p>
                    <div class="form-grid">
                        <div class="field"><label for="nfse_ibscbs_finalidade">Finalidade</label><select id="nfse_ibscbs_finalidade" name="nfse_ibscbs_finalidade"><option value="0" selected>0 - NFS-e regular</option><option value="1">1 - Crédito</option><option value="2">2 - Débito</option></select></div>
                        <div class="field"><label for="nfse_ibscbs_ind_final">Operação com consumidor final?</label><select id="nfse_ibscbs_ind_final" name="nfse_ibscbs_ind_final"><option value="">Selecione</option><option value="0">0 - Não</option><option value="1">1 - Sim</option></select></div>
                        <div class="field catalogo-autocomplete">
                            <label for="nfse_ibscbs_codigo_indicador_operacao_busca">Código do indicador da operação (cIndOp)</label>
                            <input id="nfse_ibscbs_codigo_indicador_operacao_busca" type="search" placeholder="Pesquise pelo código ou descrição" autocomplete="off" role="combobox" aria-autocomplete="list" aria-controls="nfse_ibscbs_codigo_indicador_operacao_opcoes" aria-expanded="false">
                            <input id="nfse_ibscbs_codigo_indicador_operacao" name="nfse_ibscbs_codigo_indicador_operacao" type="hidden">
                            <div id="nfse_ibscbs_codigo_indicador_operacao_opcoes" class="catalogo-sugestoes" role="listbox"></div>
                            <span class="muted" id="nfse_ibscbs_codigo_indicador_operacao_status" style="font-size: 0.78rem;">Selecione uma opção da tabela oficial.</span>
                        </div>
                        <div class="field"><label for="nfse_ibscbs_ind_destinatario">Indicador do destinatário</label><select id="nfse_ibscbs_ind_destinatario" name="nfse_ibscbs_ind_destinatario"><option value="">Selecione</option><option value="0">0 - Destinatário é o tomador</option><option value="1">1 - Destinatário diferente do tomador</option></select></div>
                        <div class="field"><label for="nfse_ibscbs_cst">CST IBS/CBS</label><input id="nfse_ibscbs_cst" name="nfse_ibscbs_cst" type="text" inputmode="numeric" pattern="\d{3}" maxlength="3" placeholder="Preenchido pelo cClassTrib" readonly aria-readonly="true"><span class="muted" id="nfse_ibscbs_cst_status" style="font-size: 0.78rem;">Será definido automaticamente.</span></div>
                        <div class="field catalogo-autocomplete">
                            <label for="nfse_ibscbs_classificacao_tributaria_busca">Classificação tributária (cClassTrib)</label>
                            <input id="nfse_ibscbs_classificacao_tributaria_busca" type="search" placeholder="Pesquise pelo código ou descrição" autocomplete="off" role="combobox" aria-autocomplete="list" aria-controls="nfse_ibscbs_classificacao_tributaria_opcoes" aria-expanded="false">
                            <input id="nfse_ibscbs_classificacao_tributaria" name="nfse_ibscbs_classificacao_tributaria" type="hidden">
                            <div id="nfse_ibscbs_classificacao_tributaria_opcoes" class="catalogo-sugestoes" role="listbox"></div>
                            <span class="muted" id="nfse_ibscbs_classificacao_tributaria_status" style="font-size: 0.78rem;">Selecione uma opção vigente e permitida para NFS-e.</span>
                        </div>
                    </div>
                        </div>
                    </details>

                    <details class="form-section" id="secaoComplementares">
                        <summary><span class="form-section-titulo"><i class="fa-solid fa-circle-info"></i> Informações complementares</span><i class="fa-solid fa-chevron-down"></i></summary>
                        <div class="form-section-corpo">
                    <div class="form-grid">
                        <div class="field">
                            <label for="nfse_documento_responsabilidade_tecnica">Nº do documento de responsabilidade técnica</label>
                            <input id="nfse_documento_responsabilidade_tecnica" name="nfse_documento_responsabilidade_tecnica" type="text">
                        </div>
                        <div class="field">
                            <label for="nfse_numero_pedido_b2b">Nº do Pedido/OC/OS/Projeto (B2B)</label>
                            <input id="nfse_numero_pedido_b2b" name="nfse_numero_pedido_b2b" type="text">
                        </div>
                        <div class="field" style="grid-column: 1 / -1;">
                            <label for="nfse_documento_referencia">Documento de referência</label>
                            <textarea id="nfse_documento_referencia" name="nfse_documento_referencia"></textarea>
                        </div>
                        <div class="field" style="grid-column: 1 / -1;">
                            <label for="nfse_informacoes_complementares">Informações complementares</label>
                            <textarea id="nfse_informacoes_complementares" name="nfse_informacoes_complementares" maxlength="2000"></textarea>
                        </div>
                    </div>
                        </div>
                    </details>

                    <details class="form-section" id="secaoValores" open>
                        <summary><span class="form-section-titulo"><i class="fa-solid fa-sack-dollar"></i> Valores do serviço prestado</span><i class="fa-solid fa-chevron-down"></i></summary>
                        <div class="form-section-corpo">
                    <div class="form-grid">
                        <div class="field">
                            <label for="nfse_valor_servico">Valor do serviço prestado</label>
                            <input id="nfse_valor_servico" name="nfse_valor_servico" type="text" required value="0,00">
                        </div>
                        <div class="field">
                            <label for="nfse_valor_recebido_intermediario">Valor recebido pelo intermediário</label>
                            <input id="nfse_valor_recebido_intermediario" name="nfse_valor_recebido_intermediario" type="text">
                        </div>
                        <div class="field">
                            <label for="nfse_desconto_incondicionado">Desconto incondicionado</label>
                            <input id="nfse_desconto_incondicionado" name="nfse_desconto_incondicionado" type="text">
                        </div>
                        <div class="field">
                            <label for="nfse_desconto_condicionado">Desconto condicionado</label>
                            <input id="nfse_desconto_condicionado" name="nfse_desconto_condicionado" type="text">
                        </div>
                    </div>
                        </div>
                    </details>

                    <details class="form-section" id="secaoTributacaoMunicipal">
                        <summary><span class="form-section-titulo"><i class="fa-solid fa-city"></i> Tributação municipal</span><i class="fa-solid fa-chevron-down"></i></summary>
                        <div class="form-section-corpo">
                    <div class="form-grid">
                        <div class="field">
                            <label for="nfse_exigibilidade_suspensa">A exigibilidade do ISSQN está suspensa?</label>
                            <select id="nfse_exigibilidade_suspensa" name="nfse_exigibilidade_suspensa">
                                <option value="nao" selected>Não</option>
                                <option value="sim">Sim</option>
                            </select>
                        </div>
                        <div class="field"><label for="nfse_tipo_suspensao_issqn">Tipo de suspensão (se suspensa)</label><input id="nfse_tipo_suspensao_issqn" name="nfse_tipo_suspensao_issqn" type="text" maxlength="2" placeholder="Código oficial"></div>
                        <div class="field"><label for="nfse_numero_processo_suspensao">Número do processo de suspensão</label><input id="nfse_numero_processo_suspensao" name="nfse_numero_processo_suspensao" type="text" maxlength="60"></div>
                        <div class="field">
                            <label for="nfse_issqn_retido">Há retenção do ISSQN pelo Tomador ou Intermediário?</label>
                            <select id="nfse_issqn_retido" name="nfse_issqn_retido">
                                <option value="nao" selected>Não</option>
                                <option value="sim">Sim</option>
                            </select>
                        </div>
                        <div class="field" id="campoIssqnRetidoPor" style="display:none;">
                            <label for="nfse_issqn_retido_por">Retido por</label>
                            <select id="nfse_issqn_retido_por" name="nfse_issqn_retido_por">
                                <option value="tomador">Tomador</option>
                                <option value="intermediario">Intermediário</option>
                            </select>
                        </div>
                        <div class="field">
                            <label for="nfse_beneficio_municipal">Este serviço está amparado por algum benefício municipal?</label>
                            <select id="nfse_beneficio_municipal" name="nfse_beneficio_municipal">
                                <option value="nao" selected>Não</option>
                                <option value="sim">Sim</option>
                            </select>
                        </div>
                        <div class="field"><label for="nfse_codigo_beneficio_municipal">Código do benefício municipal (se amparado)</label><input id="nfse_codigo_beneficio_municipal" name="nfse_codigo_beneficio_municipal" type="text" maxlength="30"></div>
                        <div class="field">
                            <label for="nfse_deducao_reducao_base">Dedução/redução da base de cálculo do ISSQN (opcional)</label>
                            <input id="nfse_deducao_reducao_base" name="nfse_deducao_reducao_base" type="text">
                        </div>
                    </div>
                        </div>
                    </details>

                    <details class="form-section" id="secaoTributacaoFederal">
                        <summary><span class="form-section-titulo"><i class="fa-solid fa-landmark"></i> Tributação federal</span><i class="fa-solid fa-chevron-down"></i></summary>
                        <div class="form-section-corpo">
                    <div class="form-grid">
                        <div class="field">
                            <label for="nfse_situacao_pis_cofins">Situação Tributária do PIS/COFINS</label>
                            <select id="nfse_situacao_pis_cofins" name="nfse_situacao_pis_cofins">
                                <option value="">Selecione...</option>
                                <option value="01">01 - Tributável (alíquota básica)</option>
                                <option value="04">04 - Tributável (alíquota zero)</option>
                                <option value="06">06 - Não tributável</option>
                                <option value="07">07 - Isenta</option>
                                <option value="08">08 - Sem incidência</option>
                                <option value="09">09 - Com suspensão</option>
                            </select>
                        </div>
                        <div class="field">
                            <label for="nfse_tipo_retencao_pis_cofins_csll">Tipo de retenção do PIS/COFINS/CSLL</label>
                            <select id="nfse_tipo_retencao_pis_cofins_csll" name="nfse_tipo_retencao_pis_cofins_csll">
                                <option value="">Selecione...</option>
                                <option value="nenhuma">Sem retenção</option>
                                <option value="lei_10833">Retenção conforme Lei 10.833/2003</option>
                                <option value="outras">Outras retenções</option>
                            </select>
                        </div>
                        <div class="field">
                            <label for="nfse_irrf">IRRF (opcional)</label>
                            <input id="nfse_irrf" name="nfse_irrf" type="text">
                        </div>
                        <div class="field">
                            <label for="nfse_contribuicoes_sociais_retidas">Contribuições Sociais - Retidas (opcional)</label>
                            <input id="nfse_contribuicoes_sociais_retidas" name="nfse_contribuicoes_sociais_retidas" type="text">
                        </div>
                        <div class="field">
                            <label for="nfse_contribuicao_previdenciaria_retida">Contribuição Previdenciária - Retida (opcional)</label>
                            <input id="nfse_contribuicao_previdenciaria_retida" name="nfse_contribuicao_previdenciaria_retida" type="text">
                        </div>
                    </div>
                        </div>
                    </details>

                    <details class="form-section" id="secaoTributosAproximados">
                        <summary><span class="form-section-titulo"><i class="fa-solid fa-percent"></i> Valor aproximado dos tributos</span><i class="fa-solid fa-chevron-down"></i></summary>
                        <div class="form-section-corpo">
                    <div class="form-grid">
                        <div class="field">
                            <label for="nfse_tributos_modo">Como informar</label>
                            <select id="nfse_tributos_modo" name="nfse_tributos_modo">
                                <option value="percentuais" selected>Configurar os valores percentuais</option>
                                <option value="valores">Preencher os valores monetários</option>
                                <option value="simples">Simples Nacional (alíquota única)</option>
                            </select>
                        </div>
                    </div>
                    <div class="form-grid" id="tributosPercentuais">
                        <div class="field">
                            <label for="nfse_tributos_federal_percentual" id="labelTributosFederalPercentual">Federal (%)</label>
                            <input id="nfse_tributos_federal_percentual" name="nfse_tributos_federal_percentual" type="text">
                            <span class="muted" id="statusTributosSimples" style="font-size: 0.78rem; display: none;">Informe a alíquota total do DAS (conforme o Anexo e a faixa de receita da empresa); será enviada como tributo federal aproximado.</span>
                        </div>
                        <div class="field" id="campoTributosEstadualPercentual">
                            <label for="nfse_tributos_estadual_percentual">Estadual (%)</label>
                            <input id="nfse_tributos_estadual_percentual" name="nfse_tributos_estadual_percentual" type="text">
                        </div>
                        <div class="field" id="campoTributosMunicipalPercentual">
                            <label for="nfse_tributos_municipal_percentual">Municipal (%)</label>
                            <input id="nfse_tributos_municipal_percentual" name="nfse_tributos_municipal_percentual" type="text">
                        </div>
                    </div>
                    <div class="form-grid" id="tributosValores" style="display:none;">
                        <div class="field">
                            <label for="nfse_tributos_federal_valor">Federal (R$)</label>
                            <input id="nfse_tributos_federal_valor" name="nfse_tributos_federal_valor" type="text">
                        </div>
                        <div class="field">
                            <label for="nfse_tributos_estadual_valor">Estadual (R$)</label>
                            <input id="nfse_tributos_estadual_valor" name="nfse_tributos_estadual_valor" type="text">
                        </div>
                        <div class="field">
                            <label for="nfse_tributos_municipal_valor">Municipal (R$)</label>
                            <input id="nfse_tributos_municipal_valor" name="nfse_tributos_municipal_valor" type="text">
                        </div>
                    </div>
                        </div>
                    </details>

                    <div style="margin-top: 1.5rem;">
                        <button class="btn" type="submit"><i class="fa-solid fa-floppy-disk"></i> <?php echo $notaEmEdicao ? 'Salvar correções' : 'Salvar rascunho'; ?></button>
                    </div>
                </form>
            </section>
        <?php endif; ?>
    </div>

    <script>
        const dadosEdicaoNota = <?php echo $edicaoJson; ?>;
        const dadosRestaurar = <?php echo $restaurarJson; ?>;
        function aplicarDadosEdicao() {
            if (!dadosEdicaoNota || !dadosEdicaoNota.nota) return;
            const form = document.getElementById('formNota');
            const nota = dadosEdicaoNota.nota;
            const nfse = dadosEdicaoNota.nfse || {};
            const itens = Array.isArray(dadosEdicaoNota.itens) ? dadosEdicaoNota.itens : [];
            const notaCampos = {
                empresa_emissora_id: nota.empresa_emissora_id,
                cliente_id: nota.cliente_id,
                natureza_operacao: nota.natureza_operacao,
                forma_pagamento: nota.forma_pagamento,
                data_emissao: nota.data_emissao,
                data_saida_entrada: nota.data_saida_entrada,
                informacoes_frete: nota.informacoes_frete
            };
            Object.keys(notaCampos).forEach(function (nome) {
                const campo = form.elements.namedItem(nome);
                if (campo) campo.value = notaCampos[nome] == null ? '' : notaCampos[nome];
            });
            const especiais = {
                deducao_reducao_base_calculo: 'nfse_deducao_reducao_base',
                exigibilidade_issqn_suspensa: 'nfse_exigibilidade_suspensa',
                situacao_tributaria_pis_cofins: 'nfse_situacao_pis_cofins'
            };
            Object.keys(nfse).forEach(function (chave) {
                const nome = especiais[chave] || ('nfse_' + chave);
                const campo = form.elements.namedItem(nome);
                if (!campo) return;
                if (campo.type === 'checkbox') campo.checked = Number(nfse[chave]) === 1;
                else campo.value = nfse[chave] == null ? '' : nfse[chave];
            });
            const informarDps = form.elements.namedItem('nfse_informar_dps');
            if (informarDps) informarDps.checked = Boolean(nfse.serie_dps || nfse.numero_dps);
            const itemServico = itens[0] || null;
            if (itemServico && form.elements.namedItem('nfse_valor_servico')) form.elements.namedItem('nfse_valor_servico').value = itemServico.valor_total;
            const cindopBusca = document.getElementById('nfse_ibscbs_codigo_indicador_operacao_busca');
            if (cindopBusca && nfse.ibscbs_codigo_indicador_operacao) cindopBusca.value = nfse.ibscbs_codigo_indicador_operacao;
            const cclassBusca = document.getElementById('nfse_ibscbs_classificacao_tributaria_busca');
            if (cclassBusca && nfse.ibscbs_classificacao_tributaria) cclassBusca.value = nfse.ibscbs_classificacao_tributaria;
            CAMPOS_MOEDA_NFSE.forEach(function (id) { formatarCampoMoeda(document.getElementById(id)); });
        }

        const CAMPOS_MOEDA_NFSE = [
            'nfse_valor_servico',
            'nfse_valor_recebido_intermediario',
            'nfse_desconto_incondicionado',
            'nfse_desconto_condicionado',
            'nfse_deducao_reducao_base',
            'nfse_irrf',
            'nfse_contribuicoes_sociais_retidas',
            'nfse_contribuicao_previdenciaria_retida',
            'nfse_tributos_federal_valor',
            'nfse_tributos_estadual_valor',
            'nfse_tributos_municipal_valor'
        ];

        function formatarCampoMoeda(campo) {
            if (!campo) return;
            const bruto = campo.value.trim();
            if (bruto === '') return;
            const normalizado = bruto.includes(',') ? bruto.replace(/\./g, '').replace(',', '.') : bruto;
            const numero = parseFloat(normalizado);
            if (!isFinite(numero)) return;
            campo.value = numero.toLocaleString('pt-BR', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
        }

        CAMPOS_MOEDA_NFSE.forEach(function (id) {
            const campo = document.getElementById(id);
            if (!campo) return;
            campo.setAttribute('inputmode', 'decimal');
            campo.addEventListener('blur', function () { formatarCampoMoeda(campo); });
            formatarCampoMoeda(campo);
        });

        aplicarDadosEdicao();

        function restaurarCamposSimplesDoErro() {
            if (!dadosRestaurar) return;
            const form = document.getElementById('formNota');
            const nota = dadosRestaurar.nota || {};
            const nfse = dadosRestaurar.nfse || {};

            Object.keys(nota).forEach(function (nome) {
                const campo = form.elements.namedItem(nome);
                if (campo) campo.value = nota[nome] == null ? '' : nota[nome];
            });

            const especiais = {
                deducao_reducao_base_calculo: 'nfse_deducao_reducao_base',
                exigibilidade_issqn_suspensa: 'nfse_exigibilidade_suspensa',
                situacao_tributaria_pis_cofins: 'nfse_situacao_pis_cofins'
            };
            Object.keys(nfse).forEach(function (chave) {
                const nome = especiais[chave] || ('nfse_' + chave);
                const campo = form.elements.namedItem(nome);
                if (!campo) return;
                if (campo.type === 'checkbox') campo.checked = Number(nfse[chave]) === 1;
                else campo.value = nfse[chave] == null ? '' : nfse[chave];
            });
            const informarDps = form.elements.namedItem('nfse_informar_dps');
            if (informarDps) informarDps.checked = Boolean(nfse.serie_dps || nfse.numero_dps);

            const cindopBusca = document.getElementById('nfse_ibscbs_codigo_indicador_operacao_busca');
            if (cindopBusca && nfse.ibscbs_codigo_indicador_operacao) cindopBusca.value = nfse.ibscbs_codigo_indicador_operacao;
            const cclassBusca = document.getElementById('nfse_ibscbs_classificacao_tributaria_busca');
            if (cclassBusca && nfse.ibscbs_classificacao_tributaria) cclassBusca.value = nfse.ibscbs_classificacao_tributaria;

            // O valor do serviço fica no item, não na tabela nfse — restaura separadamente.
            const itensRestaurar = Array.isArray(dadosRestaurar.itens) ? dadosRestaurar.itens : [];
            if (itensRestaurar[0] && form.elements.namedItem('nfse_valor_servico')) {
                form.elements.namedItem('nfse_valor_servico').value = itensRestaurar[0].valor_total;
            }

            CAMPOS_MOEDA_NFSE.forEach(function (id) { formatarCampoMoeda(document.getElementById(id)); });
        }
        restaurarCamposSimplesDoErro();

        function formatarCnpjOuCpf(valor, tipoPessoa) {
            const digitos = (valor || '').replace(/\D/g, '');
            if (tipoPessoa === 'PF') {
                const d = digitos.slice(0, 11);
                if (d.length > 9) return d.replace(/^(\d{3})(\d{3})(\d{3})(\d{0,2})$/, '$1.$2.$3-$4').replace(/-$/, '');
                if (d.length > 6) return d.replace(/^(\d{3})(\d{3})(\d{0,3})$/, '$1.$2.$3');
                if (d.length > 3) return d.replace(/^(\d{3})(\d{0,3})$/, '$1.$2');
                return d;
            }
            const d = digitos.slice(0, 14);
            if (d.length > 12) return d.replace(/^(\d{2})(\d{3})(\d{3})(\d{4})(\d{0,2})$/, '$1.$2.$3/$4-$5').replace(/-$/, '');
            if (d.length > 8) return d.replace(/^(\d{2})(\d{3})(\d{3})(\d{0,4})$/, '$1.$2.$3/$4');
            if (d.length > 5) return d.replace(/^(\d{2})(\d{3})(\d{0,3})$/, '$1.$2.$3');
            if (d.length > 2) return d.replace(/^(\d{2})(\d{0,3})$/, '$1.$2');
            return d;
        }

        function formatarDocumentoBusca(valor) {
            const digitos = (valor || '').replace(/\D/g, '');
            return formatarCnpjOuCpf(digitos, digitos.length <= 11 ? 'PF' : 'PJ');
        }

        const campoBuscaClienteDocumento = document.getElementById('busca_cliente_documento');
        const btnBuscarClienteDocumento = document.getElementById('btnBuscarClienteDocumento');
        const selectClienteId = document.getElementById('cliente_id');

        if (campoBuscaClienteDocumento) {
            campoBuscaClienteDocumento.addEventListener('input', function () {
                campoBuscaClienteDocumento.value = formatarDocumentoBusca(campoBuscaClienteDocumento.value);
            });
        }

        function buscarClientePorDocumento() {
            const statusEl = document.getElementById('statusBuscaClienteDocumento');
            const digitos = (campoBuscaClienteDocumento.value || '').replace(/\D/g, '');

            if (digitos.length < 11) {
                statusEl.textContent = 'Informe um CPF (11 dígitos) ou CNPJ (14 dígitos) para buscar.';
                statusEl.style.color = '#FFD1CE';
                return;
            }

            const opcao = Array.from(selectClienteId.options).find(function (candidata) {
                return candidata.dataset.documento && candidata.dataset.documento === digitos;
            });

            if (opcao) {
                selectClienteId.value = opcao.value;
                selectClienteId.dispatchEvent(new Event('change'));
                statusEl.style.color = 'var(--primary)';
                statusEl.textContent = 'Cliente encontrado e selecionado: ' + opcao.textContent;
            } else {
                statusEl.style.color = '#FFD1CE';
                statusEl.innerHTML = 'Nenhum cliente cadastrado com esse documento. <a href="notas-clientes" style="text-decoration:underline; color:#FFD1CE;">Cadastre um novo cliente</a>.';
            }
        }

        if (btnBuscarClienteDocumento) {
            btnBuscarClienteDocumento.addEventListener('click', buscarClientePorDocumento);
        }

        if (campoBuscaClienteDocumento) {
            campoBuscaClienteDocumento.addEventListener('keydown', function (evento) {
                if (evento.key === 'Enter') {
                    evento.preventDefault();
                    buscarClientePorDocumento();
                }
            });
        }

        if (selectClienteId) {
            selectClienteId.addEventListener('change', function () {
                const opcaoSelecionada = selectClienteId.options[selectClienteId.selectedIndex];
                const campoInscricaoMunicipal = document.getElementById('nfse_tomador_inscricao_municipal');
                if (campoInscricaoMunicipal && opcaoSelecionada) {
                    campoInscricaoMunicipal.value = opcaoSelecionada.dataset.inscricaoMunicipal || '';
                }
            });
        }

        const btnMenuHamburguer = document.getElementById('btnMenuHamburguer');
        const menuDropdown = document.getElementById('menuDropdown');
        if (btnMenuHamburguer && menuDropdown) {
            btnMenuHamburguer.addEventListener('click', function (evento) {
                evento.stopPropagation();
                const aberto = menuDropdown.classList.toggle('aberto');
                btnMenuHamburguer.setAttribute('aria-expanded', aberto ? 'true' : 'false');
            });
            document.addEventListener('click', function (evento) {
                if (!menuDropdown.contains(evento.target) && evento.target !== btnMenuHamburguer) {
                    menuDropdown.classList.remove('aberto');
                    btnMenuHamburguer.setAttribute('aria-expanded', 'false');
                }
            });
        }

        const codigosTributacaoNacional = <?php echo json_encode($codigosTributacaoNacionalNfse, JSON_UNESCAPED_UNICODE | JSON_HEX_APOS | JSON_HEX_QUOT); ?>;
        const mapaCodigosTributacaoNacional = {};
        codigosTributacaoNacional.forEach(function (item) {
            mapaCodigosTributacaoNacional[item.codigo] = item.descricao;
        });
        const correlacaoNbsPorItemLc116 = <?php echo json_encode($correlacaoNbsNfse['itens'] ?? [], JSON_UNESCAPED_UNICODE | JSON_HEX_APOS | JSON_HEX_QUOT); ?>;

        const campoCodigoTributacaoNacional = document.getElementById('nfse_codigo_tributacao_nacional');
        const campoDescricaoServico = document.getElementById('nfse_descricao_servico');
        const campoCodigoTributacaoMunicipal = document.getElementById('nfse_codigo_tributacao_municipal');
        const statusCodigoTributacaoMunicipal = document.getElementById('nfse_codigo_tributacao_municipal_status');
        const codigoMunicipalSalvoEdicao = String(
            (dadosEdicaoNota && dadosEdicaoNota.nfse && dadosEdicaoNota.nfse.codigo_tributacao_municipal)
            || (dadosRestaurar && dadosRestaurar.nfse && dadosRestaurar.nfse.codigo_tributacao_municipal)
            || ''
        ).replace(/\D/g, '');
        const campoNbs = document.getElementById('nfse_item_nbs');
        const statusNbs = document.getElementById('nfse_item_nbs_status');
        const nbsSalvaEdicao = String(
            (dadosEdicaoNota && dadosEdicaoNota.nfse && dadosEdicaoNota.nfse.item_nbs)
            || (dadosRestaurar && dadosRestaurar.nfse && dadosRestaurar.nfse.item_nbs)
            || ''
        ).replace(/\D/g, '');
        function atualizarNbsPorServico() {
            if (!campoNbs || !campoCodigoTributacaoNacional) return;
            const partes = campoCodigoTributacaoNacional.value.trim().split('.');
            const itemLc116 = partes.length >= 2 ? partes[0].padStart(2, '0') + '.' + partes[1].padStart(2, '0') : '';
            const correlacao = correlacaoNbsPorItemLc116[itemLc116] || null;
            const opcoes = correlacao && Array.isArray(correlacao.nbs) ? correlacao.nbs : [];
            const valorAnterior = String(campoNbs.value || nbsSalvaEdicao).replace(/\D/g, '');

            campoNbs.innerHTML = '';
            campoNbs.disabled = opcoes.length === 0;
            campoNbs.required = opcoes.length > 0;

            const inicial = document.createElement('option');
            inicial.value = '';
            inicial.textContent = opcoes.length === 0
                ? 'Sem NBS aplicável na correlação oficial'
                : (opcoes.length === 1 ? 'NBS definida automaticamente' : 'Selecione a NBS específica do serviço');
            campoNbs.appendChild(inicial);

            opcoes.forEach(function (item) {
                const opcao = document.createElement('option');
                opcao.value = item.codigo;
                opcao.textContent = item.codigo_formatado + ' - ' + item.descricao;
                campoNbs.appendChild(opcao);
            });

            const valorPermitido = opcoes.some(function (item) { return item.codigo === valorAnterior; });
            if (opcoes.length === 1) {
                campoNbs.value = opcoes[0].codigo;
                if (statusNbs) statusNbs.textContent = 'Preenchida automaticamente pela correlação oficial para ' + itemLc116 + '.';
            } else if (valorPermitido) {
                campoNbs.value = valorAnterior;
                if (statusNbs) statusNbs.textContent = 'NBS salva e compatível com o serviço selecionado.';
            } else if (opcoes.length > 1) {
                campoNbs.value = '';
                if (statusNbs) statusNbs.textContent = opcoes.length + ' NBS oficiais são possíveis. Escolha a descrição exata do serviço.';
            } else if (statusNbs) {
                statusNbs.textContent = 'Este item não possui NBS aplicável no Anexo VIII oficial.';
            }
        }
        function atualizarCodigoComplementarMunicipal() {
            if (!campoCodigoTributacaoMunicipal || !campoCodigoTributacaoNacional) return;
            const codigo = campoCodigoTributacaoNacional.value.trim();
            const descricaoPadrao = mapaCodigosTributacaoNacional[codigo];
            const valorAtual = campoCodigoTributacaoMunicipal.value || codigoMunicipalSalvoEdicao;

            campoCodigoTributacaoMunicipal.innerHTML = '';
            if (!codigo || !descricaoPadrao) {
                const vazio = document.createElement('option');
                vazio.value = '';
                vazio.textContent = 'Escolha primeiro o código de tributação nacional';
                campoCodigoTributacaoMunicipal.appendChild(vazio);
                if (statusCodigoTributacaoMunicipal) statusCodigoTributacaoMunicipal.textContent = '';
                return;
            }

            const opcaoPadrao = document.createElement('option');
            opcaoPadrao.value = '001';
            opcaoPadrao.textContent = codigo + '.001 - ' + descricaoPadrao;
            campoCodigoTributacaoMunicipal.appendChild(opcaoPadrao);

            if (valorAtual && valorAtual !== '001') {
                const opcaoSalva = document.createElement('option');
                opcaoSalva.value = valorAtual;
                opcaoSalva.textContent = codigo + '.' + valorAtual + ' - ' + descricaoPadrao + ' (valor salvo)';
                campoCodigoTributacaoMunicipal.appendChild(opcaoSalva);
            }

            campoCodigoTributacaoMunicipal.value = valorAtual && valorAtual !== '001' ? valorAtual : '001';
            if (statusCodigoTributacaoMunicipal) {
                statusCodigoTributacaoMunicipal.textContent = 'Preenchido automaticamente com o padrão nacional (001). Se sua prefeitura exigir um código próprio, consulte o site da prefeitura e ajuste aqui.';
            }
        }

        if (campoCodigoTributacaoNacional && campoDescricaoServico) {
            campoCodigoTributacaoNacional.addEventListener('input', function () {
                const codigo = campoCodigoTributacaoNacional.value.trim();
                const descricaoPadrao = mapaCodigosTributacaoNacional[codigo];

                if (descricaoPadrao && campoDescricaoServico.value.trim() === '') {
                    campoDescricaoServico.value = descricaoPadrao;
                }

                atualizarCodigoComplementarMunicipal();
                atualizarNbsPorServico();
            });
            atualizarCodigoComplementarMunicipal();
            atualizarNbsPorServico();
        }

        const empresaSelect = document.getElementById('empresa_emissora_id');

        if (empresaSelect && empresaSelect.dataset.ibge && !(dadosEdicaoNota && dadosEdicaoNota.nota)) {
            selecionarMunicipioPorCodigo(empresaSelect.dataset.ibge);
        }

        document.querySelectorAll('[data-form-jump]').forEach(function (botao) {
            botao.addEventListener('click', function () {
                const alvo = document.getElementById(botao.dataset.formJump);
                if (!alvo) return;
                alvo.open = true;
                alvo.scrollIntoView({ behavior: 'smooth', block: 'start' });
            });
        });

        const selectTomadorLocal = document.getElementById('nfse_tomador_local');
        const campoTomadorInscricaoMunicipal = document.getElementById('nfse_tomador_inscricao_municipal');

        function atualizarObrigatoriedadeTomador() {
            if (!campoTomadorInscricaoMunicipal || !selectTomadorLocal) return;
            campoTomadorInscricaoMunicipal.required = selectTomadorLocal.value === 'brasil';
        }

        if (selectTomadorLocal) {
            selectTomadorLocal.addEventListener('change', atualizarObrigatoriedadeTomador);
            atualizarObrigatoriedadeTomador();
        }

        const municipioCodigo = document.getElementById('nfse_municipio_prestacao');
        const municipioBusca = document.getElementById('nfse_municipio_prestacao_busca');
        const municipioOpcoes = document.getElementById('nfse_municipio_prestacao_opcoes');
        const municipioStatus = document.getElementById('nfse_municipio_prestacao_status');
        let municipiosIbge = [];

        function normalizarMunicipio(valor) {
            return String(valor || '').normalize('NFD').replace(/[\u0300-\u036f]/g, '').toLocaleLowerCase('pt-BR').trim();
        }

        function rotuloMunicipio(municipio) {
            return municipio.nome + '/' + municipio.uf;
        }

        function fecharMunicipios() {
            if (!municipioOpcoes || !municipioBusca) return;
            municipioOpcoes.classList.remove('aberto');
            municipioBusca.setAttribute('aria-expanded', 'false');
        }

        function escolherMunicipio(municipio) {
            if (!municipioCodigo || !municipioBusca || !municipioStatus) return;
            municipioCodigo.value = municipio.codigo;
            municipioBusca.value = rotuloMunicipio(municipio);
            municipioStatus.textContent = 'Código IBGE: ' + municipio.codigo;
            municipioBusca.setCustomValidity('');
            fecharMunicipios();
        }

        function selecionarMunicipioPorCodigo(codigo) {
            if (!municipioCodigo) return;
            municipioCodigo.value = String(codigo || '');
            const municipio = municipiosIbge.find(function (item) {
                return String(item.codigo) === String(codigo);
            });
            if (municipio) {
                escolherMunicipio(municipio);
            } else if (municipioBusca && municipioStatus) {
                municipioBusca.value = '';
                municipioBusca.setCustomValidity('');
                municipioStatus.textContent = 'Pesquise pelo início do nome e selecione o município.';
            }
        }

        function renderizarMunicipios() {
            if (!municipioBusca || !municipioOpcoes || !municipioCodigo || !municipioStatus) return;
            const termo = normalizarMunicipio(municipioBusca.value);
            municipioCodigo.value = '';
            municipioStatus.textContent = 'Selecione um município da lista.';
            municipioBusca.setCustomValidity('Selecione um município da lista.');
            municipioOpcoes.innerHTML = '';
            if (termo === '') {
                fecharMunicipios();
                return;
            }

            const encontrados = municipiosIbge.filter(function (municipio) {
                return normalizarMunicipio(municipio.nome).startsWith(termo);
            }).slice(0, 40);

            encontrados.forEach(function (municipio) {
                const botao = document.createElement('button');
                botao.type = 'button';
                botao.className = 'municipio-opcao';
                botao.setAttribute('role', 'option');
                botao.textContent = rotuloMunicipio(municipio);
                botao.addEventListener('click', function () {
                    escolherMunicipio(municipio);
                });
                municipioOpcoes.appendChild(botao);
            });

            if (encontrados.length === 0) {
                const vazio = document.createElement('span');
                vazio.className = 'muted';
                vazio.style.padding = '0.65rem 0.75rem';
                vazio.textContent = 'Nenhum município encontrado.';
                municipioOpcoes.appendChild(vazio);
            }
            municipioOpcoes.classList.add('aberto');
            municipioBusca.setAttribute('aria-expanded', 'true');
        }

        if (municipioBusca && municipioCodigo && municipioOpcoes) {
            fetch('ibge-municipios.json', { cache: 'force-cache' })
                .then(function (resposta) {
                    if (!resposta.ok) throw new Error('Catálogo de municípios indisponível.');
                    return resposta.json();
                })
                .then(function (municipios) {
                    municipiosIbge = Array.isArray(municipios) ? municipios : [];
                    const municipioSalvo = (dadosEdicaoNota && dadosEdicaoNota.nfse && dadosEdicaoNota.nfse.municipio_prestacao)
                        || (dadosRestaurar && dadosRestaurar.nfse && dadosRestaurar.nfse.municipio_prestacao)
                        || '';
                    if (municipioSalvo) {
                        selecionarMunicipioPorCodigo(municipioSalvo);
                    } else if (empresaSelect && empresaSelect.dataset.ibge) {
                        selecionarMunicipioPorCodigo(empresaSelect.dataset.ibge);
                    }
                })
                .catch(function () {
                    municipioStatus.textContent = 'Não foi possível carregar o catálogo de municípios.';
                });

            municipioBusca.addEventListener('input', renderizarMunicipios);
            municipioBusca.addEventListener('focus', function () {
                if (!municipioCodigo.value) renderizarMunicipios();
            });
            municipioBusca.addEventListener('keydown', function (evento) {
                if (evento.key === 'Escape') fecharMunicipios();
                if (evento.key === 'Enter') {
                    const primeiraOpcao = municipioOpcoes.querySelector('.municipio-opcao');
                    if (primeiraOpcao) {
                        evento.preventDefault();
                        primeiraOpcao.click();
                    }
                }
            });
            document.addEventListener('click', function (evento) {
                if (!evento.target.closest('.municipio-autocomplete')) fecharMunicipios();
            });
        }
        function criarAutocompleteCatalogo(configuracao) {
            const busca = document.getElementById(configuracao.buscaId);
            const codigo = document.getElementById(configuracao.codigoId);
            const opcoes = document.getElementById(configuracao.opcoesId);
            const status = document.getElementById(configuracao.statusId);
            let itens = [];

            function fechar() {
                if (!busca || !opcoes) return;
                opcoes.classList.remove('aberto');
                busca.setAttribute('aria-expanded', 'false');
            }

            function limpar() {
                if (codigo) codigo.value = '';
                if (status) status.textContent = configuracao.textoInicial;
                if (configuracao.aoLimpar) configuracao.aoLimpar();
            }

            function escolher(item) {
                if (!busca || !codigo || !status) return;
                codigo.value = item.codigo;
                busca.value = configuracao.rotulo(item);
                status.textContent = configuracao.detalhe(item);
                busca.setCustomValidity('');
                if (configuracao.aoEscolher) configuracao.aoEscolher(item);
                fechar();
            }

            function renderizar() {
                if (!busca || !codigo || !opcoes || !status) return;
                const termo = normalizarMunicipio(busca.value);
                limpar();
                busca.setCustomValidity(termo === '' ? '' : 'Selecione uma opção da lista oficial.');
                opcoes.innerHTML = '';
                const encontrados = itens.filter(function (item) {
                    return termo === '' || normalizarMunicipio(configuracao.rotulo(item)).includes(termo);
                }).slice(0, 40);

                encontrados.forEach(function (item) {
                    const botao = document.createElement('button');
                    botao.type = 'button';
                    botao.className = 'catalogo-opcao';
                    botao.setAttribute('role', 'option');
                    const linhaPrincipal = document.createElement('span');
                    linhaPrincipal.textContent = configuracao.rotulo(item);
                    botao.appendChild(linhaPrincipal);
                    const exemplo = configuracao.exemplo ? configuracao.exemplo(item) : '';
                    if (exemplo) {
                        const linhaExemplo = document.createElement('span');
                        linhaExemplo.className = 'catalogo-opcao-exemplo';
                        linhaExemplo.textContent = exemplo;
                        botao.appendChild(linhaExemplo);
                    }
                    botao.addEventListener('click', function () { escolher(item); });
                    opcoes.appendChild(botao);
                });

                if (encontrados.length === 0) {
                    const vazio = document.createElement('span');
                    vazio.className = 'muted';
                    vazio.style.padding = '0.65rem 0.75rem';
                    vazio.textContent = 'Nenhum código encontrado.';
                    opcoes.appendChild(vazio);
                }
                opcoes.classList.add('aberto');
                busca.setAttribute('aria-expanded', 'true');
            }

            if (busca && codigo && opcoes) {
                busca.addEventListener('input', renderizar);
                busca.addEventListener('focus', renderizar);
                busca.addEventListener('keydown', function (evento) {
                    if (evento.key === 'Escape') fechar();
                    if (evento.key === 'Enter') {
                        const primeira = opcoes.querySelector('.catalogo-opcao');
                        if (primeira) {
                            evento.preventDefault();
                            primeira.click();
                        }
                    }
                });
            }

            return {
                definirItens: function (novosItens) { itens = Array.isArray(novosItens) ? novosItens : []; },
                fechar: fechar
            };
        }

        const campoCstIbsCbs = document.getElementById('nfse_ibscbs_cst');
        const statusCstIbsCbs = document.getElementById('nfse_ibscbs_cst_status');
        let descricoesCstIbsCbs = {};
        const autocompleteCindop = criarAutocompleteCatalogo({
            buscaId: 'nfse_ibscbs_codigo_indicador_operacao_busca',
            codigoId: 'nfse_ibscbs_codigo_indicador_operacao',
            opcoesId: 'nfse_ibscbs_codigo_indicador_operacao_opcoes',
            statusId: 'nfse_ibscbs_codigo_indicador_operacao_status',
            textoInicial: 'Selecione uma opção da tabela oficial.',
            rotulo: function (item) { return item.codigo + ' - ' + item.tipo_operacao + ' — ' + item.local_fornecimento; },
            detalhe: function (item) { return 'cIndOp ' + item.codigo + ': ' + item.caracteristica; },
            exemplo: function (item) { return item.exemplos || ''; }
        });
        const autocompleteCclass = criarAutocompleteCatalogo({
            buscaId: 'nfse_ibscbs_classificacao_tributaria_busca',
            codigoId: 'nfse_ibscbs_classificacao_tributaria',
            opcoesId: 'nfse_ibscbs_classificacao_tributaria_opcoes',
            statusId: 'nfse_ibscbs_classificacao_tributaria_status',
            textoInicial: 'Selecione uma opção vigente e permitida para NFS-e.',
            rotulo: function (item) { return item.codigo + ' - ' + item.nome; },
            detalhe: function (item) { return item.tipo_aliquota + (item.reducao_ibs || item.reducao_cbs ? ' • Redução IBS/CBS: ' + item.reducao_ibs + '%/' + item.reducao_cbs + '%' : ''); },
            aoEscolher: function (item) {
                if (campoCstIbsCbs) campoCstIbsCbs.value = item.cst;
                if (statusCstIbsCbs) statusCstIbsCbs.textContent = item.cst + ' - ' + (descricoesCstIbsCbs[item.cst] || 'CST vinculado à classificação');
            },
            aoLimpar: function () {
                if (campoCstIbsCbs) campoCstIbsCbs.value = '';
                if (statusCstIbsCbs) statusCstIbsCbs.textContent = 'Será definido automaticamente.';
            }
        });

        fetch('nfse-ibs-catalogos.json?v=<?php echo (int) @filemtime(__DIR__ . '/nfse-ibs-catalogos.json'); ?>', { cache: 'force-cache' })
            .then(function (resposta) {
                if (!resposta.ok) throw new Error('Catálogo fiscal indisponível.');
                return resposta.json();
            })
            .then(function (catalogoFiscal) {
                (catalogoFiscal.cst || []).forEach(function (item) { descricoesCstIbsCbs[item.codigo] = item.descricao; });
                autocompleteCindop.definirItens(catalogoFiscal.cindop || []);
                autocompleteCclass.definirItens(catalogoFiscal.cclass || []);
            })
            .catch(function () {
                const statusCindop = document.getElementById('nfse_ibscbs_codigo_indicador_operacao_status');
                const statusCclass = document.getElementById('nfse_ibscbs_classificacao_tributaria_status');
                if (statusCindop) statusCindop.textContent = 'Não foi possível carregar a tabela oficial de cIndOp.';
                if (statusCclass) statusCclass.textContent = 'Não foi possível carregar a tabela oficial de cClassTrib.';
            });

        document.addEventListener('click', function (evento) {
            if (!evento.target.closest('.catalogo-autocomplete')) {
                autocompleteCindop.fechar();
                autocompleteCclass.fechar();
            }
        });
        const checkboxInformarDps = document.getElementById('nfse_informar_dps');
        const camposDpsManual = document.getElementById('camposDpsManual');
        if (checkboxInformarDps && camposDpsManual) {
            checkboxInformarDps.addEventListener('change', function () {
                camposDpsManual.style.display = checkboxInformarDps.checked ? '' : 'none';
            });
            camposDpsManual.style.display = checkboxInformarDps.checked ? '' : 'none';
        }

        const checkboxIntermediario = document.getElementById('nfse_intermediario_incluido');
        const camposIntermediario = document.getElementById('camposIntermediario');
        if (checkboxIntermediario && camposIntermediario) {
            checkboxIntermediario.addEventListener('change', function () {
                camposIntermediario.style.display = checkboxIntermediario.checked ? '' : 'none';
            });
            camposIntermediario.style.display = checkboxIntermediario.checked ? '' : 'none';
        }

        const selectIssqnRetido = document.getElementById('nfse_issqn_retido');
        const campoIssqnRetidoPor = document.getElementById('campoIssqnRetidoPor');
        if (selectIssqnRetido && campoIssqnRetidoPor) {
            selectIssqnRetido.addEventListener('change', function () {
                campoIssqnRetidoPor.style.display = selectIssqnRetido.value === 'sim' ? '' : 'none';
            });
            campoIssqnRetidoPor.style.display = selectIssqnRetido.value === 'sim' ? '' : 'none';
        }

        const selectTributosModo = document.getElementById('nfse_tributos_modo');
        const blocoTributosPercentuais = document.getElementById('tributosPercentuais');
        const blocoTributosValores = document.getElementById('tributosValores');
        const labelTributosFederalPercentual = document.getElementById('labelTributosFederalPercentual');
        const statusTributosSimples = document.getElementById('statusTributosSimples');
        const campoTributosEstadualPercentual = document.getElementById('campoTributosEstadualPercentual');
        const campoTributosMunicipalPercentual = document.getElementById('campoTributosMunicipalPercentual');
        if (selectTributosModo && blocoTributosPercentuais && blocoTributosValores) {
            function aplicarModoTributos() {
                const ehValores = selectTributosModo.value === 'valores';
                const ehSimples = selectTributosModo.value === 'simples';
                blocoTributosPercentuais.style.display = ehValores ? 'none' : '';
                blocoTributosValores.style.display = ehValores ? '' : 'none';
                if (labelTributosFederalPercentual) {
                    labelTributosFederalPercentual.textContent = ehSimples ? 'Alíquota do Simples Nacional (%)' : 'Federal (%)';
                }
                if (statusTributosSimples) statusTributosSimples.style.display = ehSimples ? '' : 'none';
                if (campoTributosEstadualPercentual) campoTributosEstadualPercentual.style.display = ehSimples ? 'none' : '';
                if (campoTributosMunicipalPercentual) campoTributosMunicipalPercentual.style.display = ehSimples ? 'none' : '';
                if (ehSimples) {
                    const campoEstadual = document.getElementById('nfse_tributos_estadual_percentual');
                    const campoMunicipal = document.getElementById('nfse_tributos_municipal_percentual');
                    if (campoEstadual) campoEstadual.value = '';
                    if (campoMunicipal) campoMunicipal.value = '';
                }
            }
            selectTributosModo.addEventListener('change', aplicarModoTributos);
            aplicarModoTributos();
        }

        if (sessionStorage.getItem('accountFuncionarioSessao') !== 'ativa') {
            fetch('login?logout=1', { keepalive: true })
                .finally(() => {
                    window.location.href = '/';
                });
        }

        function sair() {
            sessionStorage.removeItem('accountFuncionarioSessao');
            fetch('login?logout=1')
                .then(() => {
                    window.location.href = '/';
                });
        }
    </script>
</body>
</html>
