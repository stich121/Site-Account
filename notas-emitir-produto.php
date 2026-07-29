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
            <h1>Emitir NF-e (produto)</h1>
            <p class="muted">Olá, <?php echo $usuario; ?>. Monte notas de venda de produtos para outras empresas. As notas ficam como rascunho até a integração com a SEFAZ ser habilitada — acompanhe tudo em <a href="notas-fiscais" style="text-decoration:underline;">Notas fiscais</a>. Para prestação de serviços, use <a href="notas-emitir-servico" style="text-decoration:underline;">Emitir NFS-e</a>.</p>
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
                <h2><i class="fa-solid fa-box"></i> Nova nota (rascunho)</h2>
                <form method="post" id="formNota">
                    <input type="hidden" name="csrf" value="<?php echo $csrf; ?>">
                    <input type="hidden" name="acao" value="criar_nota">
                    <div class="form-grid">
                        <div class="field">
                            <label>Empresa emissora</label>
                            <?php
                                $empresaEmissoraPadrao = (int) ($_SESSION['nfse_empresa_emissora_ativa_id'] ?? 0);
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
                            <p class="muted" style="margin-top:0.35rem;font-size:0.78rem;">Para trocar, use "Emitindo por" no topo da página. <a href="notas-empresas-emissoras" style="text-decoration:underline;">Gerenciar empresas emissoras</a>.</p>
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

                    <details class="form-section" id="secaoItens" open>
                        <summary><span class="form-section-titulo"><i class="fa-solid fa-boxes-stacked"></i> Itens (produtos)</span><i class="fa-solid fa-chevron-down"></i></summary>
                        <div class="form-section-corpo">
                            <table class="itens-table" id="tabelaItens">
                                <thead>
                                    <tr>
                                        <th style="min-width: 220px;">Catálogo (opcional)</th>
                                        <th style="min-width: 200px;">Descrição</th>
                                        <th>NCM</th>
                                        <th>CFOP</th>
                                        <th>CST/CSOSN</th>
                                        <th>Unid.</th>
                                        <th>Qtd.</th>
                                        <th>Valor unit.</th>
                                        <th></th>
                                    </tr>
                                </thead>
                                <tbody id="corpoItens"></tbody>
                            </table>
                            <button class="btn btn-outline btn-small" type="button" id="btnAddItem"><i class="fa-solid fa-plus"></i> Adicionar item</button>

                            <datalist id="datalistCfop">
                                <?php foreach ($cfopCodigosNfe as $cfopItem): ?>
                                    <option value="<?php echo h($cfopItem['codigo']); ?>"><?php echo h($cfopItem['codigo'] . ' - ' . $cfopItem['descricao']); ?></option>
                                <?php endforeach; ?>
                            </datalist>

                            <div class="totais" id="totalNota" style="margin-top: 1rem;">Total estimado: R$ 0,00</div>
                        </div>
                    </details>

                    <div style="margin-top: 1.5rem; display: flex; align-items: center; gap: 1rem; flex-wrap: wrap;">
                        <button class="btn" type="submit"><i class="fa-solid fa-floppy-disk"></i> Salvar rascunho</button>
                        <span class="muted" style="font-size: 0.78rem;">Campos com <span class="marca-obrigatoria">*</span> são obrigatórios.</span>
                    </div>
                </form>
            </section>
        <?php endif; ?>
    </div>

    <script>
        const dadosRestaurar = <?php echo $restaurarJson; ?>;

        function restaurarCamposSimplesDoErro() {
            if (!dadosRestaurar) return;
            const form = document.getElementById('formNota');
            const nota = dadosRestaurar.nota || {};
            Object.keys(nota).forEach(function (nome) {
                const campo = form.elements.namedItem(nome);
                if (campo) campo.value = nota[nome] == null ? '' : nota[nome];
            });
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

        const catalogo = JSON.parse(<?php echo json_encode($catalogoJson); ?>);
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

        function adicionarLinhaItem() {
            const empresaId = empresaSelect ? empresaSelect.value : '';
            const linha = document.createElement('tr');
            linha.innerHTML =
                '<td><select class="item-catalogo">' + montarOpcoesCatalogo(empresaId) + '</select></td>' +
                '<td><input type="text" name="item_descricao[]" class="item-descricao" required></td>' +
                '<td><input type="text" name="item_ncm[]" class="item-ncm"></td>' +
                '<td><input type="text" name="item_cfop[]" class="item-cfop" list="datalistCfop" autocomplete="off" placeholder="Ex.: 5102"></td>' +
                '<td><input type="text" name="item_cst[]" class="item-cst"></td>' +
                '<td><input type="text" name="item_unidade[]" class="item-unidade" value="UN"></td>' +
                '<td><input type="text" name="item_quantidade[]" class="item-quantidade" value="1"></td>' +
                '<td><input type="text" name="item_valor_unitario[]" class="item-valor" value="0,00"></td>' +
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
                const mapa = {
                    '.item-descricao': item.descricao,
                    '.item-ncm': item.ncm,
                    '.item-cfop': item.cfop,
                    '.item-cst': item.cst_csosn,
                    '.item-unidade': item.unidade,
                    '.item-quantidade': item.quantidade,
                    '.item-valor': item.valor_unitario
                };
                Object.keys(mapa).forEach(function (seletor) {
                    const campoItem = linha.querySelector(seletor);
                    if (campoItem && mapa[seletor] != null) campoItem.value = mapa[seletor];
                });
            });
            recalcularTotal();
        })();

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
