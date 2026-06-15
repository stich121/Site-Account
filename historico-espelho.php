<?php
require_once __DIR__ . '/seguranca.php';
iniciarSessaoSegura(true);
date_default_timezone_set('America/Sao_Paulo');

if (!isset($_SESSION['funcionario_id'])) {
    header('Location: entrada-funcionarios');
    exit;
}

require_once __DIR__ . '/config_db.php';

$funcionarioId = (int) $_SESSION['funcionario_id'];
$usuarioRaw = $_SESSION['funcionario_usuario'] ?? 'Funcionário';
$nivelAcesso = (int) ($_SESSION['funcionario_nivel_acesso'] ?? 1);
$podeAdministrar = $nivelAcesso >= 3;
$erro = '';
$funcionariosAdmin = [];
$registros = [];
$espelhos = [];

$tiposPonto = [
    'chegada' => 'Chegada ao escritório',
    'saida_almoco' => 'Saída para almoço',
    'volta_escritorio' => 'Volta do almoço',
    'saida_lanche' => 'Saída para o lanche',
    'volta_lanche' => 'Volta do lanche',
    'saida_escritorio' => 'Saída do escritório',
];

function h(string $valor): string
{
    return htmlspecialchars($valor, ENT_QUOTES, 'UTF-8');
}

function nomeExibicao(?string $usuario): string
{
    return trim(str_replace('.', ' ', $usuario ?? ''));
}

function formatarDataHora(?string $valor): string
{
    if (!$valor) {
        return '--:--';
    }

    return (new DateTimeImmutable($valor))->format('d/m/Y H:i:s');
}

function formatarHoraPonto(?string $valor): string
{
    if (!$valor) {
        return '--';
    }

    return (new DateTimeImmutable($valor))->format('H:i');
}

function segundosEntre(?string $inicio, ?string $fim): ?int
{
    if (!$inicio || !$fim) {
        return null;
    }

    $inicioDt = new DateTimeImmutable($inicio);
    $fimDt = new DateTimeImmutable($fim);
    if ($fimDt < $inicioDt) {
        return null;
    }

    return $fimDt->getTimestamp() - $inicioDt->getTimestamp();
}

function segundosTrabalhadosDia(array $porTipo): int
{
    $periodos = [
        ['chegada', 'saida_almoco'],
        ['volta_escritorio', 'saida_lanche'],
        ['volta_lanche', 'saida_escritorio'],
    ];

    $total = 0;
    foreach ($periodos as [$inicioTipo, $fimTipo]) {
        $segundos = segundosEntre($porTipo[$inicioTipo]['marcado_em'] ?? null, $porTipo[$fimTipo]['marcado_em'] ?? null);
        if ($segundos !== null) {
            $total += $segundos;
        }
    }

    return $total;
}

function segundosIntervaloDia(array $porTipo): int
{
    $intervalos = [
        ['saida_almoco', 'volta_escritorio'],
        ['saida_lanche', 'volta_lanche'],
    ];

    $total = 0;
    foreach ($intervalos as [$inicioTipo, $fimTipo]) {
        $segundos = segundosEntre($porTipo[$inicioTipo]['marcado_em'] ?? null, $porTipo[$fimTipo]['marcado_em'] ?? null);
        if ($segundos !== null) {
            $total += $segundos;
        }
    }

    return $total;
}

function formatarDuracaoEspelho(int $segundos): string
{
    if ($segundos <= 0) {
        return '';
    }

    return sprintf('%02d:%02d', intdiv($segundos, 3600), intdiv($segundos % 3600, 60));
}

function formatarSaldoEspelho(int $segundos): string
{
    if ($segundos === 0) {
        return '00:00';
    }

    return ($segundos < 0 ? '-' : '') . formatarDuracaoEspelho(abs($segundos));
}

function dataPascoa(int $ano): DateTimeImmutable
{
    return new DateTimeImmutable(date('Y-m-d', easter_date($ano)));
}

function feriadosBeloHorizonte(int $ano): array
{
    $pascoa = dataPascoa($ano);
    return [
        // Feriados nacionais brasileiros.
        sprintf('%04d-01-01', $ano) => 'Confraternização Universal',
        $pascoa->modify('-48 days')->format('Y-m-d') => 'Carnaval',
        $pascoa->modify('-47 days')->format('Y-m-d') => 'Carnaval',
        $pascoa->modify('-46 days')->format('Y-m-d') => 'Quarta-feira de Cinzas',
        $pascoa->modify('-2 days')->format('Y-m-d') => 'Sexta-feira Santa',
        sprintf('%04d-04-21', $ano) => 'Tiradentes',
        sprintf('%04d-05-01', $ano) => 'Dia do Trabalho',
        $pascoa->modify('+60 days')->format('Y-m-d') => 'Corpus Christi',
        sprintf('%04d-09-07', $ano) => 'Independência do Brasil',
        sprintf('%04d-10-12', $ano) => 'Nossa Senhora Aparecida',
        sprintf('%04d-11-02', $ano) => 'Finados',
        sprintf('%04d-11-15', $ano) => 'Proclamação da República',
        sprintf('%04d-11-20', $ano) => 'Consciência Negra',
        sprintf('%04d-12-25', $ano) => 'Natal',

        // Minas Gerais e Belo Horizonte.
        sprintf('%04d-08-15', $ano) => 'Assunção de Nossa Senhora',
        sprintf('%04d-12-08', $ano) => 'Imaculada Conceição',
    ];
}

function nomeFeriadoBeloHorizonte(string $data): ?string
{
    $ano = (int) (new DateTimeImmutable($data))->format('Y');
    $feriados = feriadosBeloHorizonte($ano);

    return $feriados[$data] ?? null;
}

function ehFeriadoBeloHorizonte(string $data): bool
{
    return nomeFeriadoBeloHorizonte($data) !== null;
}

function segundosEsperadosParaData(string $data): int
{
    if (ehFeriadoBeloHorizonte($data)) {
        return 0;
    }

    $diaSemana = (int) (new DateTimeImmutable($data))->format('N');
    if ($diaSemana >= 1 && $diaSemana <= 4) {
        return 31500;
    }

    if ($diaSemana === 5) {
        return 27900;
    }

    return 0;
}

function registrosPorFuncionarioDia(array $registros): array
{
    $porFuncionario = [];
    foreach ($registros as $registro) {
        $funcionarioId = (int) $registro['funcionario_id'];
        if (!isset($porFuncionario[$funcionarioId])) {
            $porFuncionario[$funcionarioId] = [
                'dados' => [
                    'funcionario_id' => $funcionarioId,
                    'usuario' => $registro['usuario'] ?? '',
                    'email' => $registro['email'] ?? '',
                    'empresa_nome' => $registro['empresa_nome'] ?? '',
                    'empresa_cnpj' => $registro['empresa_cnpj'] ?? '',
                    'cpf' => $registro['cpf'] ?? '',
                    'cargo' => $registro['cargo'] ?? '',
                ],
                'dias' => [],
            ];
        }

        $porFuncionario[$funcionarioId]['dias'][(string) $registro['data_referencia']][(string) $registro['tipo']] = $registro;
    }

    return $porFuncionario;
}

function montarLinhasEspelho(DateTimeImmutable $inicio, DateTimeImmutable $fim, array $diasRegistros): array
{
    $linhas = [];
    $saldoAcumulado = 0;
    $totais = ['trabalhado' => 0, 'intervalo' => 0, 'credito' => 0, 'debito' => 0];

    for ($dia = $inicio; $dia <= $fim; $dia = $dia->modify('+1 day')) {
        $data = $dia->format('Y-m-d');
        $diaSemana = (int) $dia->format('N');
        $nomeFeriado = nomeFeriadoBeloHorizonte($data);
        $registrosDia = $diasRegistros[$data] ?? [];
        $trabalhado = segundosTrabalhadosDia($registrosDia);
        $intervalo = segundosIntervaloDia($registrosDia);
        $esperado = segundosEsperadosParaData($data);
        $saldoDia = $trabalhado - $esperado;
        $credito = max(0, $saldoDia);
        $debito = max(0, -$saldoDia);
        $saldoAcumulado += $saldoDia;
        if ($nomeFeriado !== null && empty($registrosDia)) {
            $observacao = 'Feriado - ' . $nomeFeriado;
        } elseif ($nomeFeriado !== null) {
            $observacao = 'Feriado trabalhado - ' . $nomeFeriado;
        } else {
            $observacao = empty($registrosDia) ? ($diaSemana >= 6 ? 'Folga' : 'Sem registro') : '';
        }

        $linhas[] = [
            'data' => $dia->format('d/m/Y'),
            'e1' => formatarHoraPonto($registrosDia['chegada']['marcado_em'] ?? null),
            's1' => formatarHoraPonto($registrosDia['saida_almoco']['marcado_em'] ?? null),
            'e2' => formatarHoraPonto($registrosDia['volta_escritorio']['marcado_em'] ?? null),
            's2' => formatarHoraPonto($registrosDia['saida_lanche']['marcado_em'] ?? null),
            'e3' => formatarHoraPonto($registrosDia['volta_lanche']['marcado_em'] ?? null),
            's3' => formatarHoraPonto($registrosDia['saida_escritorio']['marcado_em'] ?? null),
            'hnor' => formatarDuracaoEspelho($trabalhado),
            'intervalo' => formatarDuracaoEspelho($intervalo),
            'credito' => formatarDuracaoEspelho($credito),
            'debito' => formatarDuracaoEspelho($debito),
            'saldo' => formatarSaldoEspelho($saldoAcumulado),
            'observacao' => $observacao,
        ];

        $totais['trabalhado'] += $trabalhado;
        $totais['intervalo'] += $intervalo;
        $totais['credito'] += $credito;
        $totais['debito'] += $debito;
    }

    return [$linhas, $totais, $saldoAcumulado];
}

try {
    $db = obterConexao();
    $stmt = $db->prepare('SELECT nivel_acesso FROM funcionarios WHERE id = :id LIMIT 1');
    $stmt->execute(['id' => $funcionarioId]);
    $dadosAcesso = $stmt->fetch();
    if ($dadosAcesso) {
        $nivelAcesso = (int) ($dadosAcesso['nivel_acesso'] ?? $nivelAcesso);
        $podeAdministrar = $nivelAcesso >= 3;
        $_SESSION['funcionario_nivel_acesso'] = $nivelAcesso;
    }

    if (!$podeAdministrar) {
        header('Location: painel');
        exit;
    }

    $funcionariosAdmin = $db->query(
        'SELECT id, usuario, email, empresa_nome, empresa_cnpj, cpf, cargo, permite_ponto, ativo
         FROM funcionarios
         ORDER BY empresa_nome ASC, usuario ASC'
    )->fetchAll();

    $dataInicio = trim($_GET['data_inicio'] ?? '') !== '' ? trim($_GET['data_inicio']) : (new DateTimeImmutable('first day of this month'))->format('Y-m-d');
    $dataFim = trim($_GET['data_fim'] ?? '') !== '' ? trim($_GET['data_fim']) : (new DateTimeImmutable('last day of this month'))->format('Y-m-d');
    $dataInicioValida = DateTimeImmutable::createFromFormat('Y-m-d', $dataInicio);
    $dataFimValida = DateTimeImmutable::createFromFormat('Y-m-d', $dataFim);
    if (!$dataInicioValida) {
        $dataInicio = (new DateTimeImmutable('first day of this month'))->format('Y-m-d');
        $dataInicioValida = new DateTimeImmutable($dataInicio);
    }
    if (!$dataFimValida) {
        $dataFim = (new DateTimeImmutable('last day of this month'))->format('Y-m-d');
        $dataFimValida = new DateTimeImmutable($dataFim);
    }
    if ($dataFimValida < $dataInicioValida) {
        [$dataInicio, $dataFim] = [$dataFim, $dataInicio];
    }
    $funcionarioFiltro = (int) ($_GET['funcionario_id'] ?? 0);
    $empresaFiltro = trim($_GET['empresa_nome'] ?? '');

    $where = ['rp.data_referencia >= :data_inicio', 'rp.data_referencia <= :data_fim'];
    $bind = ['data_inicio' => $dataInicio, 'data_fim' => $dataFim];

    if ($funcionarioFiltro > 0) {
        $where[] = 'rp.funcionario_id = :funcionario_id';
        $bind['funcionario_id'] = $funcionarioFiltro;
    }

    if ($empresaFiltro !== '') {
        $where[] = 'f.empresa_nome = :empresa_nome';
        $bind['empresa_nome'] = $empresaFiltro;
    }

    $stmt = $db->prepare(
        'SELECT rp.id, rp.funcionario_id, rp.tipo, rp.data_referencia, rp.marcado_em, rp.timezone,
                rp.hash_comprovante, rp.latitude, rp.longitude, rp.precisao_metros,
                CASE WHEN rp.foto_comprovante IS NULL OR rp.foto_comprovante = \'\' THEN \'nao\' ELSE \'sim\' END AS foto_registrada,
                f.usuario, f.email, f.empresa_nome, f.empresa_cnpj, f.cpf, f.cargo
         FROM registros_ponto rp
         INNER JOIN funcionarios f ON f.id = rp.funcionario_id
         WHERE ' . implode(' AND ', $where) . '
         ORDER BY f.empresa_nome ASC, f.usuario ASC, rp.data_referencia ASC, rp.marcado_em ASC
         LIMIT 5000'
    );
    $stmt->execute($bind);
    $registros = $stmt->fetchAll();
    $espelhos = registrosPorFuncionarioDia($registros);
} catch (PDOException $e) {
    $erro = 'Erro ao preparar o histórico de espelho. Confira o banco de dados e o config_db.php.';
    $dataInicio = $_GET['data_inicio'] ?? '';
    $dataFim = $_GET['data_fim'] ?? '';
}

$usuario = h(nomeExibicao($usuarioRaw));
$queryDownload = [
    'scope' => 'all',
    'empresa_nome' => $_GET['empresa_nome'] ?? '',
    'funcionario_id' => $_GET['funcionario_id'] ?? '',
    'data_inicio' => $dataInicio,
    'data_fim' => $dataFim,
];
$pdfUrl = 'painel?export=pdf&' . http_build_query($queryDownload);
$csvUrl = 'painel?export=csv&' . http_build_query($queryDownload);
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Histórico de Espelho | ACCOUNT Contabilidade</title>
    <link rel="icon" type="image/svg+xml" href="favicon.svg">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600&family=Montserrat:wght@500;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        :root { --bg-main:#0A0A0A; --primary:#74C92C; --primary-hover:#5EA522; --danger:#FF453A; --text-white:#FFFFFF; --text-light:#F5F5F7; --text-muted:#A1A1A6; --border:rgba(255,255,255,0.09); --font-titles:'Montserrat',sans-serif; --font-body:'Inter',sans-serif; }
        * { box-sizing:border-box; margin:0; padding:0; }
        body { min-height:100vh; font-family:var(--font-body); background:linear-gradient(135deg,#070807 0%,#0b0d0b 48%,#050605 100%); color:var(--text-light); padding:2rem; }
        a { color:inherit; text-decoration:none; }
        .shell { width:min(1280px,100%); margin:0 auto; }
        .topbar,.section-title,.top-actions { display:flex; align-items:center; justify-content:space-between; gap:1rem; flex-wrap:wrap; }
        .topbar { margin-bottom:2rem; }
        .brand img { height:34px; display:block; }
        .top-actions { justify-content:flex-start; }
        .btn { border:0; border-radius:4px; padding:0.9rem 1.2rem; color:var(--bg-main); background:var(--primary); font-family:var(--font-titles); font-size:0.82rem; font-weight:700; text-transform:uppercase; cursor:pointer; display:inline-flex; align-items:center; justify-content:center; gap:0.55rem; }
        .btn:hover { background:var(--primary-hover); transform:translateY(-2px); }
        .btn-outline { background:transparent; color:var(--text-white); border:1px solid var(--border); }
        .btn-outline:hover { color:var(--primary); border-color:rgba(116,201,44,0.4); background:rgba(255,255,255,0.04); }
        .panel { min-width:0; margin-bottom:1.25rem; padding:2rem; border-radius:8px; border:1px solid var(--border); background:linear-gradient(145deg,rgba(24,24,24,0.96),rgba(17,18,17,0.94)); box-shadow:0 18px 45px rgba(0,0,0,0.22); }
        h1,h2,h3 { font-family:var(--font-titles); color:var(--text-white); text-transform:uppercase; }
        h1 { font-size:clamp(2rem,5vw,3.2rem); line-height:1; margin-bottom:0.75rem; }
        h2 { font-size:1.35rem; }
        h3 { font-size:1rem; margin-bottom:0.5rem; }
        .muted { color:var(--text-muted); line-height:1.6; }
        .notice { margin-bottom:1rem; padding:1rem; border-radius:8px; border:1px solid rgba(255,69,58,0.35); background:rgba(255,69,58,0.08); color:#FFD1CE; }
        .filters { display:grid; grid-template-columns:repeat(auto-fit,minmax(180px,1fr)); gap:0.85rem; align-items:end; margin-top:1.25rem; }
        .field label { display:block; margin-bottom:0.35rem; color:var(--text-muted); font-size:0.78rem; font-weight:700; text-transform:uppercase; }
        .field input,.field select { width:100%; padding:0.8rem; border-radius:4px; border:1px solid var(--border); background:var(--bg-main); color:var(--text-white); font-family:var(--font-body); }
        .table-wrap { width:100%; overflow-x:auto; margin-top:1rem; }
        table { width:100%; min-width:1020px; border-collapse:collapse; font-size:0.86rem; }
        th,td { padding:0.7rem; border-bottom:1px solid var(--border); text-align:left; vertical-align:top; }
        th { color:var(--text-white); font-family:var(--font-titles); font-size:0.72rem; text-transform:uppercase; }
        tbody tr:hover { background:rgba(116,201,44,0.045); }
        .employee-block { margin-top:1.25rem; padding-top:1.25rem; border-top:1px solid var(--border); }
        .summary { display:grid; grid-template-columns:repeat(auto-fit,minmax(170px,1fr)); gap:0.85rem; margin-top:1rem; }
        .metric { padding:1rem; border-radius:8px; border:1px solid var(--border); background:rgba(255,255,255,0.03); }
        .metric span { color:var(--text-muted); font-size:0.78rem; text-transform:uppercase; font-weight:800; }
        .metric strong { display:block; margin-top:0.35rem; font-size:1.2rem; color:var(--text-white); }
        @media (max-width:700px) { body{padding:1rem;} .panel{padding:1.2rem;} .btn{width:100%;} .top-actions{width:100%;} }
    </style>
</head>
<body>
    <div class="shell">
        <header class="topbar">
            <a class="brand" href="/" aria-label="Voltar para o site">
                <img src="logo-branca.png" alt="ACCOUNT Contabilidade">
            </a>
            <div class="top-actions">
                <a class="btn btn-outline" href="painel"><i class="fa-solid fa-arrow-left"></i> Voltar ao painel</a>
                <a class="btn btn-outline" href="historico-download"><i class="fa-solid fa-download"></i> Histórico de download</a>
                <button class="btn btn-outline" type="button" onclick="sair()"><i class="fa-solid fa-arrow-right-from-bracket"></i> Sair</button>
            </div>
        </header>

        <section class="panel">
            <h1>Histórico de Espelho</h1>
            <p class="muted">Olá, <?php echo $usuario; ?>. Operadores nível 3 podem buscar informações de cada ponto e consultar o espelho de todos os colaboradores. Os registros de ponto devem ser preservados por pelo menos 5 anos.</p>
        </section>

        <?php if ($erro !== ''): ?>
            <div class="notice"><?php echo h($erro); ?></div>
        <?php endif; ?>

        <section class="panel">
            <div class="section-title">
                <h2>Buscar espelho</h2>
                <div class="top-actions">
                    <a class="btn btn-outline" href="<?php echo h($csvUrl); ?>"><i class="fa-solid fa-file-csv"></i> Baixar CSV</a>
                    <a class="btn btn-outline" href="<?php echo h($pdfUrl); ?>" target="_blank" rel="noopener"><i class="fa-solid fa-file-pdf"></i> Baixar PDF</a>
                </div>
            </div>
            <form class="filters" method="get">
                <div class="field">
                    <label for="empresa_nome">Empresa</label>
                    <select id="empresa_nome" name="empresa_nome">
                        <option value="">Todas</option>
                        <?php foreach (['Account', 'Bookkeep'] as $empresaOpcao): ?>
                            <option value="<?php echo h($empresaOpcao); ?>" <?php echo (string) ($_GET['empresa_nome'] ?? '') === $empresaOpcao ? 'selected' : ''; ?>>
                                <?php echo h($empresaOpcao); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="field">
                    <label for="funcionario_id">Colaborador</label>
                    <select id="funcionario_id" name="funcionario_id">
                        <option value="">Todos</option>
                        <?php foreach ($funcionariosAdmin as $funcionarioOpcao): ?>
                            <option value="<?php echo h((string) $funcionarioOpcao['id']); ?>" <?php echo (string) ($_GET['funcionario_id'] ?? '') === (string) $funcionarioOpcao['id'] ? 'selected' : ''; ?>>
                                <?php echo h(($funcionarioOpcao['empresa_nome'] ?? 'Sem empresa') . ' · ' . nomeExibicao($funcionarioOpcao['usuario']) . ' · ' . ($funcionarioOpcao['cargo'] ?? 'Sem cargo')); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="field">
                    <label for="data_inicio">De</label>
                    <input id="data_inicio" name="data_inicio" type="date" value="<?php echo h($dataInicio); ?>">
                </div>
                <div class="field">
                    <label for="data_fim">Até</label>
                    <input id="data_fim" name="data_fim" type="date" value="<?php echo h($dataFim); ?>">
                </div>
                <button class="btn" type="submit"><i class="fa-solid fa-filter"></i> Filtrar</button>
            </form>
        </section>

        <section class="panel">
            <div class="section-title">
                <h2>Itens de ponto</h2>
                <span class="muted"><?php echo h((string) count($registros)); ?> marcação(ões)</span>
            </div>
            <div class="table-wrap">
                <table>
                    <thead>
                        <tr>
                            <th>ID</th>
                            <th>Empresa</th>
                            <th>Colaborador</th>
                            <th>Data</th>
                            <th>Item</th>
                            <th>Localização</th>
                            <th>Foto</th>
                            <th>Hash</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($registros)): ?>
                            <tr><td colspan="8">Nenhum ponto encontrado para os filtros selecionados.</td></tr>
                        <?php endif; ?>
                        <?php foreach ($registros as $registro): ?>
                            <tr>
                                <td>#<?php echo h((string) $registro['id']); ?></td>
                                <td><?php echo h($registro['empresa_nome'] ?? ''); ?><br><span class="muted"><?php echo h($registro['empresa_cnpj'] ?? ''); ?></span></td>
                                <td><?php echo h(nomeExibicao($registro['usuario'])); ?><br><span class="muted"><?php echo h($registro['cpf'] ?? ''); ?></span></td>
                                <td><?php echo h(formatarDataHora($registro['marcado_em'])); ?></td>
                                <td><?php echo h($tiposPonto[$registro['tipo']] ?? $registro['tipo']); ?></td>
                                <td>
                                    <?php if ($registro['latitude'] !== null && $registro['longitude'] !== null): ?>
                                        <?php echo h((string) $registro['latitude']); ?>, <?php echo h((string) $registro['longitude']); ?><br>
                                        <span class="muted">Precisão: <?php echo h((string) ($registro['precisao_metros'] ?? '')); ?> m</span>
                                    <?php else: ?>
                                        <span class="muted">Sem localização</span>
                                    <?php endif; ?>
                                </td>
                                <td><?php echo h($registro['foto_registrada']); ?></td>
                                <td><span class="muted"><?php echo h($registro['hash_comprovante'] ?? ''); ?></span></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </section>

        <section class="panel">
            <div class="section-title">
                <h2>Espelho de ponto</h2>
                <span class="muted"><?php echo h((string) count($espelhos)); ?> colaborador(es)</span>
            </div>
            <?php if (empty($espelhos)): ?>
                <p class="muted">Nenhum espelho disponível para os filtros selecionados.</p>
            <?php endif; ?>
            <?php foreach ($espelhos as $espelho): ?>
                <?php
                    [$linhasEspelho, $totaisEspelho, $saldoFinal] = montarLinhasEspelho(
                        new DateTimeImmutable($dataInicio),
                        new DateTimeImmutable($dataFim),
                        $espelho['dias']
                    );
                    $dados = $espelho['dados'];
                ?>
                <div class="employee-block">
                    <h3><?php echo h(($dados['empresa_nome'] ?? 'Sem empresa') . ' · ' . nomeExibicao($dados['usuario'] ?? 'Colaborador')); ?></h3>
                    <p class="muted">CPF: <?php echo h($dados['cpf'] ?? ''); ?> · Cargo: <?php echo h($dados['cargo'] ?? ''); ?></p>
                    <div class="summary">
                        <div class="metric"><span>Trabalhado</span><strong><?php echo h(formatarDuracaoEspelho($totaisEspelho['trabalhado'])); ?></strong></div>
                        <div class="metric"><span>Intervalos</span><strong><?php echo h(formatarDuracaoEspelho($totaisEspelho['intervalo'])); ?></strong></div>
                        <div class="metric"><span>Crédito</span><strong><?php echo h(formatarDuracaoEspelho($totaisEspelho['credito'])); ?></strong></div>
                        <div class="metric"><span>Débito</span><strong><?php echo h(formatarDuracaoEspelho($totaisEspelho['debito'])); ?></strong></div>
                        <div class="metric"><span>Saldo</span><strong><?php echo h(formatarSaldoEspelho($saldoFinal)); ?></strong></div>
                    </div>
                    <div class="table-wrap">
                        <table>
                            <thead>
                                <tr>
                                    <th>Data</th><th>E1</th><th>S1</th><th>E2</th><th>S2</th><th>E3</th><th>S3</th>
                                    <th>H.NOR</th><th>I.DIÁ</th><th>B.CRÉ</th><th>B.DÉB</th><th>S.BAN</th><th>Observação</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($linhasEspelho as $linha): ?>
                                    <tr>
                                        <td><?php echo h($linha['data']); ?></td>
                                        <td><?php echo h($linha['e1']); ?></td>
                                        <td><?php echo h($linha['s1']); ?></td>
                                        <td><?php echo h($linha['e2']); ?></td>
                                        <td><?php echo h($linha['s2']); ?></td>
                                        <td><?php echo h($linha['e3']); ?></td>
                                        <td><?php echo h($linha['s3']); ?></td>
                                        <td><?php echo h($linha['hnor']); ?></td>
                                        <td><?php echo h($linha['intervalo']); ?></td>
                                        <td><?php echo h($linha['credito']); ?></td>
                                        <td><?php echo h($linha['debito']); ?></td>
                                        <td><?php echo h($linha['saldo']); ?></td>
                                        <td><?php echo h($linha['observacao']); ?></td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            <?php endforeach; ?>
        </section>
    </div>

    <script>
        if (sessionStorage.getItem('accountFuncionarioSessao') !== 'ativa') {
            fetch('login?logout=1', { keepalive: true }).finally(() => { window.location.href = '/'; });
        }

        function sair() {
            sessionStorage.removeItem('accountFuncionarioSessao');
            fetch('login?logout=1').then(() => { window.location.href = '/'; });
        }
    </script>
</body>
</html>
