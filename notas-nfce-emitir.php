<?php
require_once __DIR__ . '/includes/notas-nfce-motor.php';
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Emitir NFC-e | ACCOUNT Contabilidade</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600&family=Montserrat:wght@500;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="stylesheet" href="assets/css/notas-fiscais.css">
    <style>
        .item-card {
            border: 1px solid var(--border);
            border-radius: var(--radius-sm);
            padding: 1rem;
            margin-bottom: 1rem;
            background: rgba(255, 255, 255, 0.02);
        }
        .item-card-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            gap: 1rem;
            margin-bottom: 1rem;
        }
        .item-card-header strong {
            font-family: var(--font-titles);
            text-transform: uppercase;
            font-size: 0.85rem;
        }
        .totais-venda {
            display: flex;
            justify-content: flex-end;
            gap: 2rem;
            margin-top: 1rem;
            font-family: var(--font-titles);
            text-transform: uppercase;
        }
        .totais-venda strong { color: var(--primary); }
    </style>
</head>
<body>
    <div class="shell">
        <?php $paginaAtivaNotasNfce = 'emitir'; include __DIR__ . '/includes/notas-nfce-nav.php'; ?>

        <section class="panel">
            <h1>Emitir NFC-e</h1>
            <p class="muted">Olá, <?php echo $usuario; ?>. Monte a venda de balcão: adicione os itens do catálogo, informe o CPF do consumidor (opcional) e uma ou mais formas de pagamento. A nota é assinada e enviada à SEFAZ na hora — se a SEFAZ estiver indisponível, a venda continua valendo em contingência EPEC.</p>
        </section>

        <?php if ($erro !== ''): ?>
            <div class="notice error"><?php echo h($erro); ?></div>
        <?php endif; ?>

        <?php if ($sucesso !== ''): ?>
            <div class="notice">
                <?php echo h($sucesso); ?>
                <?php if ($notaEmitida && ($notaEmitida['sucesso'] ?? false)): ?>
                    <div style="margin-top:0.75rem;">
                        <a class="btn btn-small" href="notas-nfce-vendas?danfce=<?php echo (int) $notaEmitida['id']; ?>" target="_blank" rel="noopener"><i class="fa-solid fa-file-pdf"></i> Baixar DANFCE</a>
                    </div>
                <?php endif; ?>
            </div>
        <?php endif; ?>

        <?php if ($empresaAtiva === null): ?>
            <div class="notice error">Nenhuma empresa emissora ativa selecionada. Escolha uma em "Vendendo por" no topo da página.</div>
        <?php elseif (empty($catalogo)): ?>
            <div class="notice error">Nenhum produto cadastrado para esta empresa. Cadastre em <a href="notas-nfce-produtos" style="text-decoration:underline;">Produtos NFC-e</a>.</div>
        <?php else: ?>
            <section class="panel">
                <h2><i class="fa-solid fa-cash-register"></i> Nova venda</h2>
                <form method="post" id="formNfce">
                    <input type="hidden" name="csrf" value="<?php echo $csrf; ?>">
                    <input type="hidden" name="acao" value="emitir_nfce">

                    <h3 style="margin-bottom:0.75rem;">Itens</h3>
                    <div id="itensContainer"></div>
                    <button class="btn btn-outline btn-small" type="button" id="btnAdicionarItem"><i class="fa-solid fa-plus"></i> Adicionar item</button>

                    <div class="totais-venda">
                        <span>Total da venda: <strong id="totalVenda">R$ 0,00</strong></span>
                    </div>

                    <hr style="border-color: var(--border); margin: 1.5rem 0;">

                    <h3 style="margin-bottom:0.75rem;">Consumidor <span class="muted" style="font-weight:400; font-size:0.8rem;">(opcional)</span></h3>
                    <div class="form-grid">
                        <div class="field">
                            <label>
                                <input type="checkbox" name="consumidor_identificado" id="consumidorIdentificado" value="1" style="width:auto; display:inline-block;">
                                Identificar consumidor no CPF/CNPJ
                                <?php echo campoInfo('Quando marcado, o CPF ou CNPJ informado entra na NFC-e como destinatário. Deixe desmarcado para "consumidor não identificado" (venda de balcão comum).'); ?>
                            </label>
                        </div>
                        <div class="field">
                            <label for="consumidorCpfCnpj">CPF ou CNPJ do consumidor</label>
                            <input type="text" id="consumidorCpfCnpj" name="consumidor_cpf_cnpj" placeholder="Somente números" disabled>
                        </div>
                        <div class="field">
                            <label for="consumidorNome">Nome do consumidor</label>
                            <input type="text" id="consumidorNome" name="consumidor_nome" disabled>
                        </div>
                    </div>

                    <hr style="border-color: var(--border); margin: 1.5rem 0;">

                    <h3 style="margin-bottom:0.75rem;">Pagamento <span class="marca-obrigatoria">*</span> <?php echo campoInfo('Informe uma ou mais formas de pagamento — o total pago deve ser igual ou maior que o total da venda; o troco é calculado automaticamente.'); ?></h3>
                    <div id="pagamentosContainer"></div>
                    <button class="btn btn-outline btn-small" type="button" id="btnAdicionarPagamento"><i class="fa-solid fa-plus"></i> Adicionar forma de pagamento</button>

                    <div class="totais-venda">
                        <span>Total pago: <strong id="totalPago">R$ 0,00</strong></span>
                        <span>Troco: <strong id="totalTroco">R$ 0,00</strong></span>
                    </div>

                    <div style="margin-top: 1.5rem;">
                        <button class="btn" type="submit"><i class="fa-solid fa-check"></i> Emitir NFC-e</button>
                    </div>
                </form>
            </section>
        <?php endif; ?>
    </div>

    <script>
        var CATALOGO_NFCE = <?php echo $catalogoJson; ?>;
        var FORMAS_PAGAMENTO_NFCE = [
            ['01', 'Dinheiro'], ['02', 'Cheque'], ['03', 'Cartão de Crédito'], ['04', 'Cartão de Débito'],
            ['05', 'Crédito Loja'], ['10', 'Vale Alimentação'], ['11', 'Vale Refeição'], ['12', 'Vale Presente'],
            ['13', 'Vale Combustível'], ['15', 'Boleto Bancário'], ['16', 'Depósito Bancário'],
            ['17', 'PIX'], ['18', 'Transferência/Carteira Digital'], ['19', 'Fidelidade/Cashback'],
            ['99', 'Outros']
        ];

        function formatarMoeda(valor) {
            return 'R$ ' + (isNaN(valor) ? 0 : valor).toLocaleString('pt-BR', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
        }

        function recalcularTotais() {
            var totalVenda = 0;
            document.querySelectorAll('#itensContainer .item-card').forEach(function (card) {
                var qtd = parseFloat((card.querySelector('.item-quantidade').value || '0').replace(',', '.')) || 0;
                var valorUnit = parseFloat((card.querySelector('.item-valor-unitario').value || '0').replace(',', '.')) || 0;
                var totalItem = qtd * valorUnit;
                card.querySelector('.item-total').textContent = formatarMoeda(totalItem);
                totalVenda += totalItem;
            });
            document.getElementById('totalVenda').textContent = formatarMoeda(totalVenda);

            var totalPago = 0;
            document.querySelectorAll('#pagamentosContainer .pagamento-row').forEach(function (row) {
                var valor = parseFloat((row.querySelector('.pagamento-valor').value || '0').replace(',', '.')) || 0;
                totalPago += valor;
            });
            document.getElementById('totalPago').textContent = formatarMoeda(totalPago);
            document.getElementById('totalTroco').textContent = formatarMoeda(Math.max(0, totalPago - totalVenda));
        }

        function criarLinhaItem() {
            var container = document.getElementById('itensContainer');
            var indice = container.children.length;
            var div = document.createElement('div');
            div.className = 'item-card';

            var opcoes = '<option value="">Selecione um produto...</option>';
            CATALOGO_NFCE.forEach(function (produto) {
                opcoes += '<option value="' + produto.id + '" data-preco="' + produto.valor_unitario_padrao + '">' + produto.descricao + ' (R$ ' + parseFloat(produto.valor_unitario_padrao).toFixed(2).replace('.', ',') + ')</option>';
            });

            div.innerHTML =
                '<div class="item-card-header">' +
                    '<strong>Item ' + (indice + 1) + '</strong>' +
                    '<button type="button" class="btn btn-danger btn-small btn-remover-item"><i class="fa-solid fa-trash"></i> Remover</button>' +
                '</div>' +
                '<div class="form-grid">' +
                    '<div class="field" style="grid-column: 1 / -1;">' +
                        '<label>Produto <span class="marca-obrigatoria">*</span></label>' +
                        '<select name="item_produto_id[]" class="item-produto" required></select>' +
                    '</div>' +
                    '<div class="field">' +
                        '<label>Quantidade <span class="marca-obrigatoria">*</span></label>' +
                        '<input type="text" name="item_quantidade[]" class="item-quantidade" value="1" required>' +
                    '</div>' +
                    '<div class="field">' +
                        '<label>Valor unitário <span class="marca-obrigatoria">*</span></label>' +
                        '<input type="text" name="item_valor_unitario[]" class="item-valor-unitario" required>' +
                    '</div>' +
                    '<div class="field">' +
                        '<label>Total do item</label>' +
                        '<div class="campo-fixo item-total">R$ 0,00</div>' +
                    '</div>' +
                '</div>';

            div.querySelector('.item-produto').innerHTML = opcoes;
            container.appendChild(div);

            var selectProduto = div.querySelector('.item-produto');
            selectProduto.addEventListener('change', function () {
                var opcaoSelecionada = selectProduto.options[selectProduto.selectedIndex];
                var preco = opcaoSelecionada ? opcaoSelecionada.getAttribute('data-preco') : '';
                if (preco) {
                    div.querySelector('.item-valor-unitario').value = parseFloat(preco).toFixed(2).replace('.', ',');
                }
                recalcularTotais();
            });
            div.querySelector('.item-quantidade').addEventListener('input', recalcularTotais);
            div.querySelector('.item-valor-unitario').addEventListener('input', recalcularTotais);
            div.querySelector('.btn-remover-item').addEventListener('click', function () {
                div.remove();
                recalcularTotais();
            });
        }

        function criarLinhaPagamento() {
            var container = document.getElementById('pagamentosContainer');
            var div = document.createElement('div');
            div.className = 'item-card pagamento-row';

            var opcoes = '';
            FORMAS_PAGAMENTO_NFCE.forEach(function (forma) {
                opcoes += '<option value="' + forma[0] + '">' + forma[1] + '</option>';
            });

            div.innerHTML =
                '<div class="form-grid">' +
                    '<div class="field">' +
                        '<label>Forma de pagamento</label>' +
                        '<select name="pagamento_forma[]" class="pagamento-forma">' + opcoes + '</select>' +
                    '</div>' +
                    '<div class="field">' +
                        '<label>Valor</label>' +
                        '<input type="text" name="pagamento_valor[]" class="pagamento-valor" value="0,00">' +
                    '</div>' +
                    '<div class="field" style="align-self:end;">' +
                        '<button type="button" class="btn btn-danger btn-small btn-remover-pagamento"><i class="fa-solid fa-trash"></i> Remover</button>' +
                    '</div>' +
                '</div>';

            container.appendChild(div);
            div.querySelector('.pagamento-valor').addEventListener('input', recalcularTotais);
            div.querySelector('.btn-remover-pagamento').addEventListener('click', function () {
                div.remove();
                recalcularTotais();
            });
        }

        var btnAdicionarItem = document.getElementById('btnAdicionarItem');
        if (btnAdicionarItem) {
            btnAdicionarItem.addEventListener('click', criarLinhaItem);
        }
        var btnAdicionarPagamento = document.getElementById('btnAdicionarPagamento');
        if (btnAdicionarPagamento) {
            btnAdicionarPagamento.addEventListener('click', criarLinhaPagamento);
        }
        var checkboxConsumidor = document.getElementById('consumidorIdentificado');
        if (checkboxConsumidor) {
            checkboxConsumidor.addEventListener('change', function () {
                document.getElementById('consumidorCpfCnpj').disabled = !checkboxConsumidor.checked;
                document.getElementById('consumidorNome').disabled = !checkboxConsumidor.checked;
            });
        }

        if (document.getElementById('itensContainer')) {
            criarLinhaItem();
            criarLinhaPagamento();
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
