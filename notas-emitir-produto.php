<?php
$tipoNotaFixo = 'nfe';
require_once __DIR__ . '/includes/notas-emitir-motor.php';
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Emitir NF-e | ACCOUNT Contabilidade</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600&family=Montserrat:wght@500;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="stylesheet" href="assets/css/notas-fiscais.css">
</head>
<body>
    <div class="shell">
        <?php $paginaAtivaNotas = 'emitir_produto'; include __DIR__ . '/includes/notas-nav.php'; ?>

        <section class="panel">
            <h1><?php echo $notaEmEdicao ? 'Corrigir NF-e nº ' . h((string) $notaEmEdicao['numero_interno']) : 'Emitir NF-e (produto)'; ?></h1>
            <p class="muted">Olá, <?php echo $usuario; ?>. Monte notas de venda de produtos para outras empresas. Salve como rascunho, confira em <a href="notas-fiscais" style="text-decoration:underline;">Notas fiscais</a> e marque como "pronta para envio" para transmitir à SEFAZ. Para prestação de serviços, use <a href="notas-emitir-servico" style="text-decoration:underline;">Emitir NFS-e</a>.</p>
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
                <h2><i class="fa-solid <?php echo $notaEmEdicao ? 'fa-pen-to-square' : 'fa-box'; ?>"></i> <?php echo $notaEmEdicao ? 'Corrigir NF-e nº ' . h((string) $notaEmEdicao['numero_interno']) : 'Nova nota (rascunho)'; ?></h2>
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
                                $empresaEmissoraPadrao = $notaEmEdicao ? (int) $notaEmEdicao['empresa_emissora_id'] : (int) ($_SESSION['nfse_empresa_emissora_ativa_id'] ?? 0);
                                $empresaAtivaSelecionada = null;
                                foreach ($empresasAtivas as $empresa) {
                                    if ((int) $empresa['id'] === $empresaEmissoraPadrao) {
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
                            <input type="hidden" id="empresa_emissora_id" name="empresa_emissora_id" value="<?php echo h((string) $empresaEmissoraPadrao); ?>">
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
                            <label for="cliente_id">Cliente destinatário <span class="marca-obrigatoria">*</span></label>
                            <select id="cliente_id" name="cliente_id" required>
                                <option value="">Selecione</option>
                                <?php foreach ($clientes as $cliente): ?>
                                    <option value="<?php echo h((string) $cliente['id']); ?>" data-documento="<?php echo h(preg_replace('/\D/', '', (string) ($cliente['cnpj_cpf'] ?? ''))); ?>"><?php echo h($cliente['nome_razao_social'] . (($cliente['cnpj_cpf'] ?? '') !== '' ? ' - ' . $cliente['cnpj_cpf'] : '')); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="field">
                            <label for="natureza_operacao">Natureza da operação <span class="marca-obrigatoria">*</span></label>
                            <input id="natureza_operacao" name="natureza_operacao" type="text" placeholder="Ex.: Venda de mercadoria" required>
                        </div>
                        <div class="field">
                            <label for="forma_pagamento">Forma de pagamento (texto livre, opcional)</label>
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
                    </div>

                    <details class="form-section" id="secaoDestinacao" open>
                        <summary><span class="form-section-titulo"><i class="fa-solid fa-bullseye"></i> Destinação e finalidade</span><i class="fa-solid fa-chevron-down"></i></summary>
                        <div class="form-section-corpo">
                            <div class="form-grid">
                                <div class="field">
                                    <label for="nfe_finalidade_emissao">Finalidade de emissão</label>
                                    <select id="nfe_finalidade_emissao" name="nfe_finalidade_emissao">
                                        <option value="normal">Normal</option>
                                        <option value="complementar">Complementar</option>
                                        <option value="ajuste">Ajuste</option>
                                        <option value="devolucao">Devolução</option>
                                    </select>
                                </div>
                                <div class="field">
                                    <label for="nfe_indicador_presenca">Indicador de presença do comprador</label>
                                    <select id="nfe_indicador_presenca" name="nfe_indicador_presenca">
                                        <option value="0">Não se aplica</option>
                                        <option value="1">Presencial</option>
                                        <option value="2" selected>Não presencial, pela internet</option>
                                        <option value="3">Não presencial, teleatendimento</option>
                                        <option value="4">NFC-e entrega em domicílio</option>
                                        <option value="5">Presencial, fora do estabelecimento</option>
                                        <option value="9">Não presencial, outros</option>
                                    </select>
                                </div>
                                <div class="field">
                                    <label for="nfe_referenciada">NF-e referenciada (chave de 44 dígitos)</label>
                                    <input id="nfe_referenciada" name="nfe_referenciada" type="text" maxlength="44" placeholder="Obrigatório para complementar/devolução">
                                </div>
                            </div>
                        </div>
                    </details>

                    <details class="form-section" id="secaoTransporte">
                        <summary><span class="form-section-titulo"><i class="fa-solid fa-truck"></i> Transporte</span><i class="fa-solid fa-chevron-down"></i></summary>
                        <div class="form-section-corpo">
                            <div class="form-grid">
                                <div class="field">
                                    <label for="nfe_modalidade_frete">Modalidade do frete</label>
                                    <select id="nfe_modalidade_frete" name="nfe_modalidade_frete">
                                        <option value="9" selected>Sem frete</option>
                                        <option value="0">Por conta do emitente (CIF)</option>
                                        <option value="1">Por conta do destinatário (FOB)</option>
                                        <option value="2">Por conta de terceiros</option>
                                        <option value="3">Transporte próprio por conta do remetente</option>
                                        <option value="4">Transporte próprio por conta do destinatário</option>
                                    </select>
                                </div>
                                <div class="field">
                                    <label for="nfe_transportador_nome">Transportadora (nome)</label>
                                    <input id="nfe_transportador_nome" name="nfe_transportador_nome" type="text">
                                </div>
                                <div class="field">
                                    <label for="nfe_transportador_cnpj_cpf">Transportadora (CNPJ/CPF)</label>
                                    <input id="nfe_transportador_cnpj_cpf" name="nfe_transportador_cnpj_cpf" type="text">
                                </div>
                                <div class="field">
                                    <label for="nfe_transportador_ie">Transportadora (IE)</label>
                                    <input id="nfe_transportador_ie" name="nfe_transportador_ie" type="text">
                                </div>
                                <div class="field" style="grid-column: 1 / -1;">
                                    <label for="nfe_transportador_endereco">Transportadora (endereço)</label>
                                    <input id="nfe_transportador_endereco" name="nfe_transportador_endereco" type="text">
                                </div>
                                <div class="field">
                                    <label for="nfe_transportador_municipio">Transportadora (município)</label>
                                    <input id="nfe_transportador_municipio" name="nfe_transportador_municipio" type="text">
                                </div>
                                <div class="field">
                                    <label for="nfe_transportador_uf">Transportadora (UF)</label>
                                    <input id="nfe_transportador_uf" name="nfe_transportador_uf" type="text" maxlength="2" style="text-transform:uppercase;">
                                </div>
                                <div class="field">
                                    <label for="nfe_veiculo_placa">Veículo (placa)</label>
                                    <input id="nfe_veiculo_placa" name="nfe_veiculo_placa" type="text" maxlength="10">
                                </div>
                                <div class="field">
                                    <label for="nfe_veiculo_uf">Veículo (UF)</label>
                                    <input id="nfe_veiculo_uf" name="nfe_veiculo_uf" type="text" maxlength="2" style="text-transform:uppercase;">
                                </div>
                                <div class="field">
                                    <label for="nfe_veiculo_rntc">Veículo (RNTC)</label>
                                    <input id="nfe_veiculo_rntc" name="nfe_veiculo_rntc" type="text">
                                </div>
                                <div class="field">
                                    <label for="nfe_volumes_quantidade">Volumes (quantidade)</label>
                                    <input id="nfe_volumes_quantidade" name="nfe_volumes_quantidade" type="text">
                                </div>
                                <div class="field">
                                    <label for="nfe_volumes_especie">Volumes (espécie)</label>
                                    <input id="nfe_volumes_especie" name="nfe_volumes_especie" type="text">
                                </div>
                                <div class="field">
                                    <label for="nfe_volumes_marca">Volumes (marca)</label>
                                    <input id="nfe_volumes_marca" name="nfe_volumes_marca" type="text">
                                </div>
                                <div class="field">
                                    <label for="nfe_volumes_numeracao">Volumes (numeração)</label>
                                    <input id="nfe_volumes_numeracao" name="nfe_volumes_numeracao" type="text">
                                </div>
                                <div class="field">
                                    <label for="nfe_volumes_peso_liquido">Peso líquido (kg)</label>
                                    <input id="nfe_volumes_peso_liquido" name="nfe_volumes_peso_liquido" type="text">
                                </div>
                                <div class="field">
                                    <label for="nfe_volumes_peso_bruto">Peso bruto (kg)</label>
                                    <input id="nfe_volumes_peso_bruto" name="nfe_volumes_peso_bruto" type="text">
                                </div>
                            </div>
                        </div>
                    </details>

                    <details class="form-section" id="secaoPagamento">
                        <summary><span class="form-section-titulo"><i class="fa-solid fa-money-bill-wave"></i> Pagamento</span><i class="fa-solid fa-chevron-down"></i></summary>
                        <div class="form-section-corpo">
                            <div class="form-grid">
                                <div class="field">
                                    <label for="nfe_forma_pagamento_codigo">Forma de pagamento (código NF-e)</label>
                                    <select id="nfe_forma_pagamento_codigo" name="nfe_forma_pagamento_codigo">
                                        <option value="90" selected>Sem pagamento</option>
                                        <option value="01">Dinheiro</option>
                                        <option value="02">Cheque</option>
                                        <option value="03">Cartão de crédito</option>
                                        <option value="04">Cartão de débito</option>
                                        <option value="05">Crédito loja</option>
                                        <option value="10">Vale alimentação</option>
                                        <option value="11">Vale refeição</option>
                                        <option value="12">Vale presente</option>
                                        <option value="13">Vale combustível</option>
                                        <option value="15">Boleto bancário</option>
                                        <option value="16">Depósito bancário</option>
                                        <option value="17">PIX</option>
                                        <option value="18">Transferência bancária</option>
                                        <option value="99">Outros</option>
                                    </select>
                                </div>
                                <div class="field">
                                    <label for="nfe_indicador_pagamento">Indicador de pagamento</label>
                                    <select id="nfe_indicador_pagamento" name="nfe_indicador_pagamento">
                                        <option value="0" selected>À vista</option>
                                        <option value="1">A prazo</option>
                                        <option value="2">Outros</option>
                                    </select>
                                </div>
                                <div class="field">
                                    <label for="nfe_valor_pago">Valor pago (opcional, padrão = total da nota)</label>
                                    <input id="nfe_valor_pago" name="nfe_valor_pago" type="text" placeholder="0,00">
                                </div>
                                <div class="field">
                                    <label for="nfe_valor_troco">Troco</label>
                                    <input id="nfe_valor_troco" name="nfe_valor_troco" type="text" value="0,00">
                                </div>
                            </div>
                        </div>
                    </details>

                    <details class="form-section" id="secaoItens" open>
                        <summary><span class="form-section-titulo"><i class="fa-solid fa-boxes-stacked"></i> Itens (produtos)</span><i class="fa-solid fa-chevron-down"></i></summary>
                        <div class="form-section-corpo">
                            <?php if ($empresaAtivaSelecionada): ?>
                                <p class="muted" style="font-size:0.78rem;margin-bottom:0.75rem;">
                                    Regime tributário da empresa (CRT): <?php echo h((string) ($empresaAtivaSelecionada['crt'] ?? '?')); ?>
                                    — use CSOSN (101, 102, 103, 201, 202, 203, 300, 400, 500, 900) se Simples Nacional (CRT 1),
                                    ou CST (00, 10, 20, 30, 40, 41, 50, 51, 60, 61, 70, 90) nos demais regimes.
                                </p>
                            <?php endif; ?>
                            <div class="table-wrap">
                                <table class="itens-table" id="tabelaItens">
                                    <thead>
                                        <tr>
                                            <th style="min-width: 200px;">Catálogo (opcional)</th>
                                            <th style="min-width: 180px;">Descrição</th>
                                            <th style="min-width: 100px;">NCM</th>
                                            <th style="min-width: 140px;">CFOP</th>
                                            <th style="min-width: 90px;">CST/CSOSN</th>
                                            <th style="min-width: 80px;">Orig.</th>
                                            <th style="min-width: 90px;">Alíq. ICMS %</th>
                                            <th style="min-width: 110px;">cEAN</th>
                                            <th style="min-width: 70px;">Unid.</th>
                                            <th style="min-width: 70px;">Qtd.</th>
                                            <th style="min-width: 100px;">Valor unit.</th>
                                            <th style="min-width: 70px;">IPI CST</th>
                                            <th style="min-width: 90px;">Alíq. IPI %</th>
                                            <th style="min-width: 90px;">Alíq. PIS %</th>
                                            <th style="min-width: 100px;">Alíq. COFINS %</th>
                                            <th></th>
                                        </tr>
                                    </thead>
                                    <tbody id="corpoItens"></tbody>
                                </table>
                            </div>
                            <button class="btn btn-outline btn-small" type="button" id="btnAddItem"><i class="fa-solid fa-plus"></i> Adicionar item</button>

                            <datalist id="datalistCfop">
                                <?php foreach ($cfopCodigosNfe as $cfopItem): ?>
                                    <option value="<?php echo h($cfopItem['codigo']); ?>"><?php echo h($cfopItem['codigo'] . ' - ' . $cfopItem['descricao']); ?></option>
                                <?php endforeach; ?>
                            </datalist>

                            <div class="totais" id="totalNota" style="margin-top: 1rem;">Total estimado: R$ 0,00</div>
                        </div>
                    </details>

                    <details class="form-section" id="secaoComplementares">
                        <summary><span class="form-section-titulo"><i class="fa-solid fa-note-sticky"></i> Informações complementares</span><i class="fa-solid fa-chevron-down"></i></summary>
                        <div class="form-section-corpo">
                            <div class="field">
                                <label for="nfe_informacoes_complementares">Informações complementares de interesse do contribuinte</label>
                                <textarea id="nfe_informacoes_complementares" name="nfe_informacoes_complementares"></textarea>
                            </div>
                            <div class="field">
                                <label for="informacoes_frete">Observações de frete/transporte (texto livre, opcional)</label>
                                <textarea id="informacoes_frete" name="informacoes_frete" placeholder="Observações adicionais sobre o transporte..."></textarea>
                            </div>
                        </div>
                    </details>

                    <div style="margin-top: 1.5rem; display: flex; align-items: center; gap: 1rem; flex-wrap: wrap;">
                        <button class="btn" type="submit"><i class="fa-solid fa-floppy-disk"></i> <?php echo $notaEmEdicao ? 'Salvar correções' : 'Salvar rascunho'; ?></button>
                        <span class="muted" style="font-size: 0.78rem;">Campos com <span class="marca-obrigatoria">*</span> são obrigatórios.</span>
                    </div>
                </form>
            </section>
        <?php endif; ?>
    </div>

    <script>
        const dadosRestaurar = <?php echo $restaurarJson; ?>;
        const dadosEdicaoNota = <?php echo $edicaoJson; ?>;

        function restaurarCamposSimplesDoErro() {
            if (!dadosRestaurar) return;
            const form = document.getElementById('formNota');
            const nota = dadosRestaurar.nota || {};
            Object.keys(nota).forEach(function (nome) {
                const campo = form.elements.namedItem(nome);
                if (campo) campo.value = nota[nome] == null ? '' : nota[nome];
            });
            const nfe = dadosRestaurar.nfe || {};
            const mapaNfe = {
                finalidade_emissao: 'nfe_finalidade_emissao', indicador_presenca: 'nfe_indicador_presenca',
                nfe_referenciada: 'nfe_referenciada', modalidade_frete: 'nfe_modalidade_frete',
                transportador_nome: 'nfe_transportador_nome', transportador_cnpj_cpf: 'nfe_transportador_cnpj_cpf',
                transportador_ie: 'nfe_transportador_ie', transportador_endereco: 'nfe_transportador_endereco',
                transportador_municipio: 'nfe_transportador_municipio', transportador_uf: 'nfe_transportador_uf',
                veiculo_placa: 'nfe_veiculo_placa', veiculo_uf: 'nfe_veiculo_uf', veiculo_rntc: 'nfe_veiculo_rntc',
                volumes_quantidade: 'nfe_volumes_quantidade', volumes_especie: 'nfe_volumes_especie',
                volumes_marca: 'nfe_volumes_marca', volumes_numeracao: 'nfe_volumes_numeracao',
                volumes_peso_liquido: 'nfe_volumes_peso_liquido', volumes_peso_bruto: 'nfe_volumes_peso_bruto',
                forma_pagamento_codigo: 'nfe_forma_pagamento_codigo', indicador_pagamento: 'nfe_indicador_pagamento',
                valor_pago: 'nfe_valor_pago', valor_troco: 'nfe_valor_troco',
                informacoes_complementares: 'nfe_informacoes_complementares',
            };
            preencherCamposNfePorMapa(nfe, mapaNfe);
        }

        function preencherCamposNfePorMapa(nfe, mapa) {
            if (!nfe) return;
            const form = document.getElementById('formNota');
            Object.keys(mapa).forEach(function (chave) {
                const campo = form.elements.namedItem(mapa[chave]);
                if (campo && nfe[chave] != null) campo.value = nfe[chave];
            });
        }

        function preencherEdicaoNfe() {
            if (!dadosEdicaoNota || !dadosEdicaoNota.nota) return;
            const form = document.getElementById('formNota');
            const nota = dadosEdicaoNota.nota;
            const camposNota = { cliente_id: nota.cliente_id, natureza_operacao: nota.natureza_operacao, forma_pagamento: nota.forma_pagamento, data_emissao: nota.data_emissao, data_saida_entrada: nota.data_saida_entrada };
            Object.keys(camposNota).forEach(function (nome) {
                const campo = form.elements.namedItem(nome);
                if (campo && camposNota[nome] != null) campo.value = camposNota[nome];
            });

            const mapaNfe = {
                finalidade_emissao: 'nfe_finalidade_emissao', indicador_presenca: 'nfe_indicador_presenca',
                nfe_referenciada: 'nfe_referenciada', modalidade_frete: 'nfe_modalidade_frete',
                transportador_nome: 'nfe_transportador_nome', transportador_cnpj_cpf: 'nfe_transportador_cnpj_cpf',
                transportador_ie: 'nfe_transportador_ie', transportador_endereco: 'nfe_transportador_endereco',
                transportador_municipio: 'nfe_transportador_municipio', transportador_uf: 'nfe_transportador_uf',
                veiculo_placa: 'nfe_veiculo_placa', veiculo_uf: 'nfe_veiculo_uf', veiculo_rntc: 'nfe_veiculo_rntc',
                volumes_quantidade: 'nfe_volumes_quantidade', volumes_especie: 'nfe_volumes_especie',
                volumes_marca: 'nfe_volumes_marca', volumes_numeracao: 'nfe_volumes_numeracao',
                volumes_peso_liquido: 'nfe_volumes_peso_liquido', volumes_peso_bruto: 'nfe_volumes_peso_bruto',
                forma_pagamento_codigo: 'nfe_forma_pagamento_codigo', indicador_pagamento: 'nfe_indicador_pagamento',
                valor_pago: 'nfe_valor_pago', valor_troco: 'nfe_valor_troco',
                informacoes_complementares: 'nfe_informacoes_complementares',
            };
            preencherCamposNfePorMapa(dadosEdicaoNota.nfe, mapaNfe);

            const itens = Array.isArray(dadosEdicaoNota.itens) ? dadosEdicaoNota.itens : [];
            if (itens.length > 0 && corpoItens) {
                corpoItens.innerHTML = '';
                itens.forEach(function (item) {
                    adicionarLinhaItem();
                    const linha = corpoItens.lastElementChild;
                    if (!linha) return;
                    preencherLinhaItem(linha, item);
                });
                recalcularTotal();
            }
        }

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

        const catalogo = <?php echo $catalogoJson; ?>;
        const corpoItens = document.getElementById('corpoItens');
        const empresaSelect = document.getElementById('empresa_emissora_id');
        const totalNotaEl = document.getElementById('totalNota');

        function formatarMoeda(valor) {
            return 'Total estimado: R$ ' + valor.toLocaleString('pt-BR', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
        }

        function recalcularTotal() {
            let total = 0;
            corpoItens.querySelectorAll('tr').forEach(function (linha) {
                const qtd = parseFloat((linha.querySelector('.item-quantidade').value || '0').replace(',', '.')) || 0;
                const valorUnit = parseFloat((linha.querySelector('.item-valor').value || '0').replace(',', '.')) || 0;
                total += qtd * valorUnit;
            });
            totalNotaEl.textContent = formatarMoeda(total);
            const campoValorPago = document.getElementById('nfe_valor_pago');
            if (campoValorPago && campoValorPago.value.trim() === '') {
                campoValorPago.placeholder = total.toFixed(2).replace('.', ',');
            }
        }

        function montarOpcoesCatalogo(empresaId) {
            let opcoes = '<option value="">Digitar manualmente</option>';
            catalogo.filter(function (item) {
                return String(item.empresa_emissora_id) === String(empresaId);
            }).forEach(function (item) {
                opcoes += '<option value="' + item.id + '">' + item.descricao + '</option>';
            });
            return opcoes;
        }

        function preencherLinhaItem(linha, item) {
            const mapa = {
                '.item-descricao': item.descricao,
                '.item-ncm': item.ncm,
                '.item-cfop': item.cfop,
                '.item-cst': item.cst_csosn,
                '.item-icms-origem': item.icms_origem,
                '.item-icms-aliquota': item.icms_aliquota,
                '.item-cean': item.cean,
                '.item-unidade': item.unidade,
                '.item-quantidade': item.quantidade,
                '.item-valor': item.valor_unitario,
                '.item-ipi-cst': item.ipi_cst,
                '.item-ipi-aliquota': item.ipi_aliquota,
                '.item-pis-aliquota': item.pis_aliquota,
                '.item-cofins-aliquota': item.cofins_aliquota,
            };
            Object.keys(mapa).forEach(function (seletor) {
                const campoItem = linha.querySelector(seletor);
                if (campoItem && mapa[seletor] != null) campoItem.value = mapa[seletor];
            });
        }

        function adicionarLinhaItem() {
            const empresaId = empresaSelect ? empresaSelect.value : '';
            const linha = document.createElement('tr');
            linha.innerHTML =
                '<td><select class="item-catalogo">' + montarOpcoesCatalogo(empresaId) + '</select></td>' +
                '<td><input type="text" name="item_descricao[]" class="item-descricao" required></td>' +
                '<td><input type="text" name="item_ncm[]" class="item-ncm" maxlength="8" placeholder="8 dígitos"></td>' +
                '<td><input type="text" name="item_cfop[]" class="item-cfop" list="datalistCfop" autocomplete="off" placeholder="Ex.: 5102"></td>' +
                '<td><input type="text" name="item_cst[]" class="item-cst" placeholder="102 ou 00"></td>' +
                '<td><select name="item_icms_origem[]" class="item-icms-origem">' +
                    '<option value="0" selected>0 Nacional</option>' +
                    '<option value="1">1 Estrangeira - importação direta</option>' +
                    '<option value="2">2 Estrangeira - mercado interno</option>' +
                    '<option value="3">3 Nacional, imp. &gt;40%</option>' +
                    '<option value="4">4 Nacional, PPB</option>' +
                    '<option value="5">5 Nacional, imp. &lt;=40%</option>' +
                    '<option value="6">6 Estrangeira, imp. direta s/similar</option>' +
                    '<option value="7">7 Estrangeira, mercado interno s/similar</option>' +
                    '<option value="8">8 Nacional, imp. &gt;70%</option>' +
                '</select></td>' +
                '<td><input type="text" name="item_icms_aliquota[]" class="item-icms-aliquota" value="0"></td>' +
                '<td><input type="text" name="item_cean[]" class="item-cean" placeholder="Opcional"></td>' +
                '<td><input type="text" name="item_unidade[]" class="item-unidade" value="UN"></td>' +
                '<td><input type="text" name="item_quantidade[]" class="item-quantidade" value="1"></td>' +
                '<td><input type="text" name="item_valor_unitario[]" class="item-valor" value="0,00"></td>' +
                '<td><input type="text" name="item_ipi_cst[]" class="item-ipi-cst" placeholder="Opcional"></td>' +
                '<td><input type="text" name="item_ipi_aliquota[]" class="item-ipi-aliquota" value="0"></td>' +
                '<td><input type="text" name="item_pis_aliquota[]" class="item-pis-aliquota" value="0"></td>' +
                '<td><input type="text" name="item_cofins_aliquota[]" class="item-cofins-aliquota" value="0"></td>' +
                '<td><input type="hidden" name="item_produto_id[]" class="item-produto-id" value="0">' +
                '<button type="button" class="btn btn-danger btn-small btn-remover-item"><i class="fa-solid fa-trash"></i></button></td>';

            corpoItens.appendChild(linha);

            const selectCatalogo = linha.querySelector('.item-catalogo');
            selectCatalogo.addEventListener('change', function () {
                const item = catalogo.find(function (candidato) {
                    return String(candidato.id) === selectCatalogo.value;
                });
                if (item) {
                    linha.querySelector('.item-descricao').value = item.descricao || '';
                    linha.querySelector('.item-ncm').value = item.ncm || '';
                    linha.querySelector('.item-cfop').value = item.cfop || '';
                    linha.querySelector('.item-cst').value = item.cst_csosn || '';
                    linha.querySelector('.item-unidade').value = item.unidade || 'UN';
                    linha.querySelector('.item-valor').value = Number(item.valor_unitario_padrao || 0).toFixed(2).replace('.', ',');
                    linha.querySelector('.item-produto-id').value = item.id;
                    linha.querySelector('.item-cean').value = item.cean || '';
                    linha.querySelector('.item-icms-aliquota').value = item.aliquota_icms != null ? item.aliquota_icms : '0';
                    linha.querySelector('.item-ipi-cst').value = item.ipi_cst || '';
                    linha.querySelector('.item-ipi-aliquota').value = item.aliquota_ipi != null ? item.aliquota_ipi : '0';
                    linha.querySelector('.item-pis-aliquota').value = item.aliquota_pis != null ? item.aliquota_pis : '0';
                    linha.querySelector('.item-cofins-aliquota').value = item.aliquota_cofins != null ? item.aliquota_cofins : '0';
                } else {
                    linha.querySelector('.item-produto-id').value = 0;
                }
                recalcularTotal();
            });

            linha.querySelector('.item-quantidade').addEventListener('input', recalcularTotal);
            linha.querySelector('.item-valor').addEventListener('input', recalcularTotal);
            linha.querySelector('.btn-remover-item').addEventListener('click', function () {
                linha.remove();
                recalcularTotal();
            });

            recalcularTotal();
        }

        const btnAddItem = document.getElementById('btnAddItem');
        if (btnAddItem) {
            btnAddItem.addEventListener('click', adicionarLinhaItem);
            adicionarLinhaItem();
        }

        (function restaurarItensDoErro() {
            const itens = dadosRestaurar && Array.isArray(dadosRestaurar.itens) ? dadosRestaurar.itens : [];
            if (itens.length === 0 || !corpoItens) return;
            corpoItens.innerHTML = '';
            itens.forEach(function (item) {
                adicionarLinhaItem();
                const linha = corpoItens.lastElementChild;
                if (!linha) return;
                preencherLinhaItem(linha, item);
            });
            recalcularTotal();
        })();

        restaurarCamposSimplesDoErro();
        preencherEdicaoNfe();

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
