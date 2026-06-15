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

function h(string $valor): string
{
    return htmlspecialchars($valor, ENT_QUOTES, 'UTF-8');
}

function nomeExibicao(?string $usuario): string
{
    return trim(str_replace('.', ' ', $usuario ?? ''));
}

function colunaExiste(PDO $db, string $tabela, string $coluna): bool
{
    $stmt = $db->prepare(
        'SELECT COUNT(*)
         FROM INFORMATION_SCHEMA.COLUMNS
         WHERE TABLE_SCHEMA = DATABASE()
           AND TABLE_NAME = :tabela
           AND COLUMN_NAME = :coluna'
    );
    $stmt->execute([
        'tabela' => $tabela,
        'coluna' => $coluna,
    ]);

    return (int) $stmt->fetchColumn() > 0;
}

function prepararCamposFuncionarios(PDO $db): void
{
    $campos = [
        'empresa_nome' => "ALTER TABLE funcionarios ADD COLUMN empresa_nome VARCHAR(120) NULL AFTER usuario",
        'empresa_cnpj' => "ALTER TABLE funcionarios ADD COLUMN empresa_cnpj VARCHAR(20) NULL AFTER empresa_nome",
        'cpf' => "ALTER TABLE funcionarios ADD COLUMN cpf VARCHAR(14) NULL AFTER empresa_cnpj",
        'pis_pasep' => "ALTER TABLE funcionarios ADD COLUMN pis_pasep VARCHAR(20) NULL AFTER cpf",
        'cargo' => "ALTER TABLE funcionarios ADD COLUMN cargo VARCHAR(120) NULL AFTER email",
        'data_admissao' => "ALTER TABLE funcionarios ADD COLUMN data_admissao DATE NULL AFTER cargo",
        'departamento' => "ALTER TABLE funcionarios ADD COLUMN departamento VARCHAR(120) NULL AFTER data_admissao",
        'numero_folha' => "ALTER TABLE funcionarios ADD COLUMN numero_folha VARCHAR(30) NULL AFTER departamento",
        'centro_custo' => "ALTER TABLE funcionarios ADD COLUMN centro_custo VARCHAR(120) NULL AFTER numero_folha",
        'nivel_acesso' => "ALTER TABLE funcionarios ADD COLUMN nivel_acesso TINYINT UNSIGNED NOT NULL DEFAULT 1 AFTER cargo",
        'permite_ponto' => "ALTER TABLE funcionarios ADD COLUMN permite_ponto TINYINT(1) NOT NULL DEFAULT 1 AFTER nivel_acesso",
    ];

    foreach ($campos as $campo => $sql) {
        if (!colunaExiste($db, 'funcionarios', $campo)) {
            $db->exec($sql);
        }
    }
}

function prepararTabelaSaldosIniciaisBanco(PDO $db): void
{
    $db->exec(
        "CREATE TABLE IF NOT EXISTS saldos_iniciais_banco_horas (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            funcionario_id INT UNSIGNED NOT NULL,
            data_referencia DATE NOT NULL,
            saldo_segundos INT NOT NULL,
            observacoes VARCHAR(255) NULL,
            criado_em TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            atualizado_em TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY uq_saldos_iniciais_funcionario_data (funcionario_id, data_referencia),
            KEY idx_saldos_iniciais_data (data_referencia),
            CONSTRAINT fk_saldos_iniciais_funcionario
                FOREIGN KEY (funcionario_id) REFERENCES funcionarios(id)
                ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
    );
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

function ehEstagiario(?string $cargo): bool
{
    $cargo = function_exists('mb_strtolower') ? mb_strtolower($cargo ?? '', 'UTF-8') : strtolower($cargo ?? '');
    return strpos($cargo, 'estagi') !== false;
}

function segundosTrabalhadosDia(array $porTipo, ?string $cargo = null): int
{
    if (ehEstagiario($cargo)) {
        $saidaLanche = $porTipo['saida_lanche']['marcado_em'] ?? null;
        $voltaLanche = $porTipo['volta_lanche']['marcado_em'] ?? null;
        $saidaEscritorio = $porTipo['saida_escritorio']['marcado_em'] ?? null;

        if (!$saidaLanche && !$voltaLanche) {
            return segundosEntre($porTipo['chegada']['marcado_em'] ?? null, $saidaEscritorio) ?? 0;
        }

        return (segundosEntre($porTipo['chegada']['marcado_em'] ?? null, $saidaLanche) ?? 0)
            + (segundosEntre($voltaLanche, $saidaEscritorio) ?? 0);
    }

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

function segundosExcessoIntervaloDia(array $porTipo, ?string $cargo = null): int
{
    $intervalo = segundosIntervaloDia($porTipo);
    if ($intervalo <= 0) {
        return 0;
    }

    return max(0, $intervalo - (ehEstagiario($cargo) ? 900 : 4500));
}

function segundosEsperadosParaData(string $data, ?string $cargo = null): int
{
    if (ehFeriadoBeloHorizonte($data)) {
        return 0;
    }

    $diaSemana = (int) (new DateTimeImmutable($data))->format('N');
    if (ehEstagiario($cargo)) {
        return $diaSemana >= 1 && $diaSemana <= 5 ? 18000 : 0;
    }

    if ($diaSemana >= 1 && $diaSemana <= 4) {
        return 31500;
    }

    if ($diaSemana === 5) {
        return 27900;
    }

    return 0;
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

function formatarHorasBanco(int $segundos): string
{
    $prefixo = $segundos < 0 ? '-' : '';
    $segundos = abs($segundos);
    $horas = intdiv($segundos, 3600);
    $minutos = intdiv($segundos % 3600, 60);

    return sprintf('%s%02d:%02d:00', $prefixo, $horas, $minutos);
}

function formatarSaldoBanco(int $segundos): string
{
    if ($segundos === 0) {
        return '00:00:00';
    }

    return ($segundos > 0 ? '+' : '-') . formatarHorasBanco(abs($segundos));
}

function inicioMesAtual(): string
{
    return (new DateTimeImmutable('first day of this month'))->format('Y-m-d');
}

function fimMesAtual(): string
{
    return (new DateTimeImmutable('last day of this month'))->format('Y-m-d');
}

function rotuloMesBanco(string $dataInicio, string $dataFim): string
{
    $inicio = new DateTimeImmutable($dataInicio);
    $fim = new DateTimeImmutable($dataFim);
    $meses = [
        1 => 'Janeiro',
        2 => 'Fevereiro',
        3 => 'Março',
        4 => 'Abril',
        5 => 'Maio',
        6 => 'Junho',
        7 => 'Julho',
        8 => 'Agosto',
        9 => 'Setembro',
        10 => 'Outubro',
        11 => 'Novembro',
        12 => 'Dezembro',
    ];

    if ($inicio->format('Y-m') === $fim->format('Y-m')) {
        return $meses[(int) $inicio->format('n')] . '/' . $inicio->format('Y');
    }

    return $inicio->format('d/m/Y') . ' a ' . $fim->format('d/m/Y');
}

function datasDoPeriodo(string $inicio, string $fim): array
{
    $datas = [];
    $cursor = new DateTimeImmutable($inicio);
    $fimDt = new DateTimeImmutable($fim);

    while ($cursor <= $fimDt) {
        $datas[] = $cursor->format('Y-m-d');
        $cursor = $cursor->modify('+1 day');
    }

    return $datas;
}

function diasParaVencimento(?string $ultimaData): ?int
{
    if (!$ultimaData) {
        return null;
    }

    $vencimento = (new DateTimeImmutable($ultimaData))->modify('+180 days');
    $hoje = new DateTimeImmutable('today');

    return (int) $hoje->diff($vencimento)->format('%r%a');
}

function afastamentosPorFuncionarioDia(array $afastamentos): array
{
    $porDia = [];
    foreach ($afastamentos as $afastamento) {
        $funcionarioId = (int) $afastamento['funcionario_id'];
        $inicio = new DateTimeImmutable($afastamento['data_inicio']);
        $fim = new DateTimeImmutable($afastamento['data_fim']);

        while ($inicio <= $fim) {
            $porDia[$funcionarioId][$inicio->format('Y-m-d')] = $afastamento;
            $inicio = $inicio->modify('+1 day');
        }
    }

    return $porDia;
}

$dataInicio = $_GET['data_inicio'] ?? inicioMesAtual();
$dataFim = $_GET['data_fim'] ?? fimMesAtual();
$empresaFiltro = trim($_GET['empresa_nome'] ?? '');
$busca = trim($_GET['busca'] ?? '');
$linhasBanco = [];
$empresas = [];
$totais = [
    'funcionarios' => 0,
    'credor' => 0,
    'devedor' => 0,
    'ajuste_inicial' => 0,
    'movimento_mensal' => 0,
    'saldo' => 0,
];

try {
    $db = obterConexao();
    prepararCamposFuncionarios($db);
    prepararTabelaSaldosIniciaisBanco($db);

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

    $empresas = $db->query(
        "SELECT DISTINCT empresa_nome
         FROM funcionarios
         WHERE empresa_nome IS NOT NULL AND empresa_nome <> ''
         ORDER BY empresa_nome ASC"
    )->fetchAll();

    $whereFuncionarios = ['ativo = 1'];
    $bindFuncionarios = [];

    if ($empresaFiltro !== '') {
        $whereFuncionarios[] = 'empresa_nome = :empresa_nome';
        $bindFuncionarios['empresa_nome'] = $empresaFiltro;
    }

    if ($busca !== '') {
        $whereFuncionarios[] = '(usuario LIKE :busca OR email LIKE :busca OR cpf LIKE :busca OR cargo LIKE :busca)';
        $bindFuncionarios['busca'] = '%' . $busca . '%';
    }

    $stmt = $db->prepare(
        'SELECT id, usuario, email, empresa_nome, empresa_cnpj, cpf, cargo, permite_ponto
         FROM funcionarios
         WHERE ' . implode(' AND ', $whereFuncionarios) . '
         ORDER BY empresa_nome ASC, usuario ASC'
    );
    $stmt->execute($bindFuncionarios);
    $funcionarios = $stmt->fetchAll();

    $registrosPorFuncionarioDia = [];
    $ultimaMarcacaoPorFuncionario = [];
    $primeiraMarcacaoPorFuncionario = [];
    $afastamentosPorDia = [];
    $documentosPorFuncionario = [];
    $saldosIniciaisPorFuncionario = [];

    if (!empty($funcionarios)) {
        $ids = array_map(fn($funcionario) => (int) $funcionario['id'], $funcionarios);
        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        $params = array_merge($ids, [$dataInicio, $dataFim]);

        $stmt = $db->prepare(
            'SELECT funcionario_id, MIN(data_referencia) AS primeira_data
             FROM registros_ponto
             WHERE funcionario_id IN (' . $placeholders . ')
             GROUP BY funcionario_id'
        );
        $stmt->execute($ids);
        foreach ($stmt->fetchAll() as $primeiraMarcacao) {
            $primeiraMarcacaoPorFuncionario[(int) $primeiraMarcacao['funcionario_id']] = $primeiraMarcacao['primeira_data'];
        }

        $stmt = $db->prepare(
            'SELECT funcionario_id, tipo, data_referencia, marcado_em
             FROM registros_ponto
             WHERE funcionario_id IN (' . $placeholders . ')
               AND data_referencia >= ?
               AND data_referencia <= ?
             ORDER BY marcado_em ASC'
        );
        $stmt->execute($params);

        foreach ($stmt->fetchAll() as $registro) {
            $fid = (int) $registro['funcionario_id'];
            $data = $registro['data_referencia'];
            $registrosPorFuncionarioDia[$fid][$data][$registro['tipo']] = $registro;
            $ultimaMarcacaoPorFuncionario[$fid] = $data;
        }

        $stmt = $db->prepare(
            'SELECT a.id, a.funcionario_id, a.tipo_afastamento, a.motivo, a.data_inicio, a.data_fim,
                    a.tipo_documento, a.documento_nome, a.documento_caminho
             FROM afastamentos a
             WHERE a.ativo = 1
               AND a.funcionario_id IN (' . $placeholders . ')
               AND a.data_inicio <= ?
               AND a.data_fim >= ?
             ORDER BY a.data_inicio ASC, a.id ASC'
        );
        $stmt->execute(array_merge($ids, [$dataFim, $dataInicio]));
        $afastamentosPeriodo = $stmt->fetchAll();
        $afastamentosPorDia = afastamentosPorFuncionarioDia($afastamentosPeriodo);

        foreach ($afastamentosPeriodo as $afastamento) {
            $fid = (int) $afastamento['funcionario_id'];
            $documentosPorFuncionario[$fid][] = trim(implode(' - ', array_filter([
                $afastamento['tipo_afastamento'] ?? '',
                $afastamento['tipo_documento'] ?? '',
                $afastamento['documento_nome'] ?? '',
            ])));
        }

        $stmt = $db->prepare(
            'SELECT funcionario_id, COALESCE(SUM(saldo_segundos), 0) AS saldo_inicial
             FROM saldos_iniciais_banco_horas
             WHERE funcionario_id IN (' . $placeholders . ')
               AND data_referencia >= ?
               AND data_referencia <= ?
             GROUP BY funcionario_id'
        );
        $stmt->execute(array_merge($ids, [$dataInicio, $dataFim]));
        foreach ($stmt->fetchAll() as $saldoInicial) {
            $saldosIniciaisPorFuncionario[(int) $saldoInicial['funcionario_id']] = (int) $saldoInicial['saldo_inicial'];
        }
    }

    $datasPeriodo = datasDoPeriodo($dataInicio, $dataFim);
    foreach ($funcionarios as $funcionario) {
        $fid = (int) $funcionario['id'];
        $permitePonto = (int) ($funcionario['permite_ponto'] ?? 1) === 1;
        $esperado = 0;
        $trabalhado = 0;
        $intervaloExcedido = 0;
        $primeiraMarcacao = $primeiraMarcacaoPorFuncionario[$fid] ?? null;

        foreach ($datasPeriodo as $data) {
            $temRegistroNoDia = isset($registrosPorFuncionarioDia[$fid][$data]);
            $abonado = isset($afastamentosPorDia[$fid][$data]);
            $jaIniciouBanco = $primeiraMarcacao !== null && $data >= $primeiraMarcacao;
            if ($permitePonto && $jaIniciouBanco && $temRegistroNoDia && !$abonado) {
                $esperado += segundosEsperadosParaData($data, $funcionario['cargo'] ?? '');
            }
            $registrosDiaBanco = $registrosPorFuncionarioDia[$fid][$data] ?? [];
            $trabalhado += segundosTrabalhadosDia($registrosDiaBanco, $funcionario['cargo'] ?? '');
            $intervaloExcedido += segundosExcessoIntervaloDia($registrosDiaBanco, $funcionario['cargo'] ?? '');
        }

        $saldoInicialBanco = $saldosIniciaisPorFuncionario[$fid] ?? 0;
        $saldoMensal = $trabalhado - $esperado;
        $saldo = $saldoInicialBanco + $saldoMensal;
        $ultimaMarcacao = $ultimaMarcacaoPorFuncionario[$fid] ?? null;
        $diasVencimento = diasParaVencimento($ultimaMarcacao ?: $dataFim);

        $linhasBanco[] = [
            'id' => $fid,
            'nome' => nomeExibicao($funcionario['usuario']),
            'email' => $funcionario['email'] ?? '',
            'empresa_nome' => $funcionario['empresa_nome'] ?? '',
            'empresa_cnpj' => $funcionario['empresa_cnpj'] ?? '',
            'cpf' => $funcionario['cpf'] ?? '',
            'cargo' => $funcionario['cargo'] ?? '',
            'trabalhado' => $trabalhado,
            'esperado' => $esperado,
            'intervalo_excedido' => $intervaloExcedido,
            'saldo_inicial' => $saldoInicialBanco,
            'saldo_mensal' => $saldoMensal,
            'saldo' => $saldo,
            'dias_vencimento' => $diasVencimento,
            'ultima_marcacao' => $ultimaMarcacao,
            'primeira_marcacao' => $primeiraMarcacao,
            'documentos_abono' => array_values(array_unique(array_filter($documentosPorFuncionario[$fid] ?? []))),
        ];

        $totais['funcionarios']++;
        $totais['credor'] += max(0, $saldo);
        $totais['devedor'] += max(0, -$saldo);
        $totais['ajuste_inicial'] += $saldoInicialBanco;
        $totais['movimento_mensal'] += $saldoMensal;
        $totais['saldo'] += $saldo;
    }
} catch (PDOException $e) {
    $erro = 'Erro ao preparar o banco de horas. Confira o banco de dados e o config_db.php.';
}

$usuario = h(nomeExibicao($usuarioRaw));
$rotuloMesBanco = rotuloMesBanco($dataInicio, $dataFim);
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Banco de Horas | ACCOUNT Contabilidade</title>
    <link rel="icon" type="image/svg+xml" href="favicon.svg">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600&family=Montserrat:wght@500;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        :root {
            --bg-main: #0A0A0A;
            --bg-card: #161616;
            --primary: #74C92C;
            --primary-hover: #5EA522;
            --danger: #FF453A;
            --text-white: #FFFFFF;
            --text-light: #F5F5F7;
            --text-muted: #A1A1A6;
            --border: rgba(255, 255, 255, 0.09);
            --font-titles: 'Montserrat', sans-serif;
            --font-body: 'Inter', sans-serif;
        }

        * { box-sizing: border-box; margin: 0; padding: 0; }

        html {
            scrollbar-color: var(--primary) transparent;
            scrollbar-width: thin;
        }

        *::-webkit-scrollbar {
            width: 10px;
            height: 10px;
        }

        *::-webkit-scrollbar-button {
            display: none;
            width: 0;
            height: 0;
        }

        *::-webkit-scrollbar-track {
            background: transparent;
            border-radius: 999px;
        }

        *::-webkit-scrollbar-thumb {
            background: linear-gradient(135deg, var(--primary), var(--primary-hover));
            border: 2px solid var(--bg-main);
            border-radius: 999px;
        }

        *::-webkit-scrollbar-thumb:hover {
            background: var(--primary);
        }

        *::-webkit-scrollbar-corner {
            background: var(--bg-main);
        }

        body {
            min-height: 100vh;
            font-family: var(--font-body);
            background:
                radial-gradient(circle at 18% 6%, rgba(116, 201, 44, 0.15), transparent 26rem),
                radial-gradient(circle at 82% 0%, rgba(255, 255, 255, 0.07), transparent 22rem),
                linear-gradient(135deg, #070807 0%, #0b0d0b 48%, #050605 100%);
            color: var(--text-light);
            padding: 2rem;
            overflow-x: hidden;
        }

        body::before {
            content: "";
            position: fixed;
            inset: -20%;
            z-index: -1;
            pointer-events: none;
            background:
                radial-gradient(circle at 24% 24%, rgba(116, 201, 44, 0.11), transparent 20rem),
                radial-gradient(circle at 76% 18%, rgba(116, 201, 44, 0.07), transparent 18rem);
            filter: blur(18px);
            animation: ambientShift 14s ease-in-out infinite alternate;
        }

        @keyframes ambientShift {
            from { transform: translate3d(-1.5%, -1%, 0) scale(1); opacity: 0.78; }
            to { transform: translate3d(1.5%, 1%, 0) scale(1.04); opacity: 1; }
        }

        @keyframes pageIn {
            from { opacity: 0; transform: translateY(16px); }
            to { opacity: 1; transform: translateY(0); }
        }

        a { color: inherit; text-decoration: none; }

        .shell {
            width: min(1280px, 100%);
            margin: 0 auto;
            overflow: hidden;
            animation: pageIn 0.55s ease both;
        }

        .topbar,
        .section-title {
            display: flex;
            justify-content: space-between;
            align-items: center;
            gap: 1rem;
            flex-wrap: wrap;
        }

        .topbar { margin-bottom: 2rem; }

        .brand img {
            height: 34px;
            width: auto;
            display: block;
        }

        .top-actions {
            display: flex;
            gap: 0.75rem;
            flex-wrap: wrap;
        }

        .btn {
            border: 0;
            border-radius: 4px;
            padding: 0.9rem 1.2rem;
            color: var(--bg-main);
            background: var(--primary);
            font-family: var(--font-titles);
            font-size: 0.82rem;
            font-weight: 700;
            text-transform: uppercase;
            cursor: pointer;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 0.55rem;
            position: relative;
            overflow: hidden;
            box-shadow: 0 10px 28px rgba(116, 201, 44, 0.12);
        }

        .btn::after {
            content: "";
            position: absolute;
            inset: 0;
            pointer-events: none;
            background: linear-gradient(110deg, transparent 0%, rgba(255,255,255,0.24) 45%, transparent 70%);
            transform: translateX(-120%);
            transition: transform 0.55s ease;
        }

        .btn:hover { background: var(--primary-hover); transform: translateY(-2px); }
        .btn:hover::after { transform: translateX(120%); }
        .btn:active { transform: translateY(0); }

        .btn-outline {
            background: transparent;
            color: var(--text-white);
            border: 1px solid var(--border);
        }

        .btn-outline:hover {
            color: var(--primary);
            border-color: rgba(116, 201, 44, 0.4);
            background: rgba(255,255,255,0.04);
        }

        .panel {
            min-width: 0;
            margin-bottom: 1.25rem;
            padding: 2rem;
            border-radius: 8px;
            border: 1px solid var(--border);
            background: linear-gradient(145deg, rgba(24, 24, 24, 0.96), rgba(17, 18, 17, 0.94));
            box-shadow: 0 18px 45px rgba(0, 0, 0, 0.22);
            transition: transform 0.24s ease, border-color 0.24s ease, box-shadow 0.24s ease;
        }

        .panel:hover {
            transform: translateY(-2px);
            border-color: rgba(116, 201, 44, 0.24);
            box-shadow: 0 22px 58px rgba(0, 0, 0, 0.3);
        }

        h1,
        h2 {
            font-family: var(--font-titles);
            color: var(--text-white);
            text-transform: uppercase;
        }

        h1 {
            font-size: clamp(2rem, 5vw, 3.2rem);
            line-height: 1;
            margin-bottom: 0.75rem;
        }

        h2 { font-size: 1.35rem; }

        .muted {
            color: var(--text-muted);
            line-height: 1.6;
        }

        .notice {
            margin-bottom: 1rem;
            padding: 1rem;
            border-radius: 8px;
            border: 1px solid rgba(255, 69, 58, 0.35);
            background: rgba(255, 69, 58, 0.08);
            color: #FFD1CE;
        }

        .filters,
        .cards {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(180px, 1fr));
            gap: 0.85rem;
            align-items: end;
            margin-top: 1.25rem;
        }

        .field { min-width: 0; }

        .field label {
            display: block;
            margin-bottom: 0.35rem;
            color: var(--text-muted);
            font-size: 0.78rem;
            font-weight: 700;
            text-transform: uppercase;
        }

        .field select,
        .field input {
            width: 100%;
            min-width: 0;
            padding: 0.8rem;
            border-radius: 4px;
            border: 1px solid var(--border);
            background: var(--bg-main);
            color: var(--text-white);
            font-family: var(--font-body);
        }

        .metric {
            padding: 1rem;
            border-radius: 8px;
            border: 1px solid var(--border);
            background: rgba(255, 255, 255, 0.03);
        }

        .metric span {
            color: var(--text-muted);
            font-size: 0.78rem;
            font-weight: 700;
            text-transform: uppercase;
        }

        .metric strong {
            display: block;
            margin-top: 0.45rem;
            font-family: var(--font-titles);
            font-size: 1.25rem;
        }

        .positive { color: var(--primary); }
        .negative { color: var(--danger); }

        .table-wrap {
            width: 100%;
            max-width: 100%;
            overflow-x: auto;
        }

        table {
            width: 100%;
            min-width: 1080px;
            border-collapse: collapse;
            font-size: 0.9rem;
        }

        th,
        td {
            padding: 0.85rem;
            border-bottom: 1px solid var(--border);
            text-align: left;
            vertical-align: middle;
        }

        th {
            color: var(--text-white);
            font-family: var(--font-titles);
            font-size: 0.75rem;
            text-transform: uppercase;
        }

        .pill {
            display: inline-flex;
            justify-content: center;
            min-width: 120px;
            padding: 0.35rem 0.7rem;
            border-radius: 999px;
            border: 1px solid rgba(116, 201, 44, 0.35);
            background: rgba(116, 201, 44, 0.1);
            color: var(--primary);
            font-size: 0.82rem;
            font-weight: 700;
        }

        .pill.warning {
            border-color: rgba(255, 69, 58, 0.35);
            background: rgba(255, 69, 58, 0.08);
            color: #FFD1CE;
        }

        input,
        select {
            transition: border-color 0.2s ease, box-shadow 0.2s ease, background 0.2s ease;
        }

        input:focus,
        select:focus {
            border-color: rgba(116, 201, 44, 0.58);
            box-shadow: 0 0 0 3px rgba(116, 201, 44, 0.12);
            outline: none;
        }

        table tbody tr {
            transition: background 0.18s ease;
        }

        table tbody tr:hover {
            background: rgba(116, 201, 44, 0.045);
        }

        .pill {
            transition: transform 0.2s ease, border-color 0.2s ease;
        }

        tr:hover .pill {
            transform: translateY(-1px);
        }

        @media (prefers-reduced-motion: reduce) {
            *,
            *::before,
            *::after {
                animation-duration: 0.01ms !important;
                animation-iteration-count: 1 !important;
                scroll-behavior: auto !important;
                transition-duration: 0.01ms !important;
            }
        }

        @media (max-width: 700px) {
            body { padding: 1rem; }
            .panel { padding: 1.2rem; }
            .btn { width: 100%; }
            .top-actions { width: 100%; }
        }
        /* === FUNDO INTERATIVO COM O MOUSE === */
        :root {
            --cursor-x: 50vw;
            --cursor-y: 28vh;
            --cursor-glow: 0;
        }

        body::after {
            content: "";
            position: fixed;
            inset: 0;
            z-index: 0;
            pointer-events: none;
            opacity: var(--cursor-glow);
            background:
                radial-gradient(circle 360px at var(--cursor-x) var(--cursor-y), rgba(116, 201, 44, 0.22), transparent 68%),
                radial-gradient(circle 220px at calc(var(--cursor-x) + 9rem) calc(var(--cursor-y) + 5rem), rgba(255, 255, 255, 0.11), transparent 72%);
            mix-blend-mode: screen;
            transition: opacity 0.25s ease;
        }

        .shell {
            position: relative;
            z-index: 1;
        }

        .panel,
        .pill {
            will-change: transform;
        }

        @media (hover: none), (prefers-reduced-motion: reduce) {
            body::after {
                display: none;
            }
        }
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
                <button class="btn btn-outline" type="button" onclick="sair()"><i class="fa-solid fa-arrow-right-from-bracket"></i> Sair</button>
            </div>
        </header>

        <section class="panel">
            <div class="section-title">
                <div>
                    <h1>Banco de horas</h1>
                    <p class="muted">Olá, <?php echo $usuario; ?>. Consulte o saldo de todos os funcionários e filtre por empresa.</p>
                </div>
                <span class="pill"><?php echo h((string) $totais['funcionarios']); ?> colaborador(es)</span>
            </div>

            <form class="filters" method="get">
                <div class="field">
                    <label for="empresa_nome">Empresa</label>
                    <select id="empresa_nome" name="empresa_nome">
                        <option value="">Todas</option>
                        <?php foreach ($empresas as $empresa): ?>
                            <option value="<?php echo h($empresa['empresa_nome']); ?>" <?php echo $empresaFiltro === $empresa['empresa_nome'] ? 'selected' : ''; ?>>
                                <?php echo h($empresa['empresa_nome']); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="field">
                    <label for="busca">Buscar funcionário</label>
                    <input id="busca" name="busca" type="search" value="<?php echo h($busca); ?>" placeholder="Nome, CPF, e-mail ou cargo">
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

            <div class="cards">
                <div class="metric">
                    <span>Total credor</span>
                    <strong class="positive"><?php echo h(formatarHorasBanco($totais['credor'])); ?></strong>
                </div>
                <div class="metric">
                    <span>Total devedor</span>
                    <strong class="negative"><?php echo h(formatarHorasBanco(-$totais['devedor'])); ?></strong>
                </div>
                <div class="metric">
                    <span>Saldo geral</span>
                    <strong class="<?php echo $totais['saldo'] < 0 ? 'negative' : 'positive'; ?>"><?php echo h(formatarSaldoBanco($totais['saldo'])); ?></strong>
                </div>
                <div class="metric">
                    <span>Ajuste inicial</span>
                    <strong class="<?php echo $totais['ajuste_inicial'] < 0 ? 'negative' : 'positive'; ?>"><?php echo h(formatarSaldoBanco($totais['ajuste_inicial'])); ?></strong>
                </div>
                <div class="metric">
                    <span>Movimento mensal <?php echo h($rotuloMesBanco); ?></span>
                    <strong class="<?php echo $totais['movimento_mensal'] < 0 ? 'negative' : 'positive'; ?>"><?php echo h(formatarSaldoBanco($totais['movimento_mensal'])); ?></strong>
                </div>
            </div>
        </section>

        <?php if ($erro !== ''): ?>
            <div class="notice"><?php echo h($erro); ?></div>
        <?php endif; ?>

        <section class="panel">
            <div class="section-title">
                <h2>Colaboradores</h2>
                <span class="muted">Período: <?php echo h((new DateTimeImmutable($dataInicio))->format('d/m/Y')); ?> a <?php echo h((new DateTimeImmutable($dataFim))->format('d/m/Y')); ?></span>
            </div>

            <div class="table-wrap">
                <table>
                    <thead>
                        <tr>
                            <th>Funcionário</th>
                            <th>Empresa</th>
                            <th>CPF</th>
                            <th>Função</th>
                            <th>Horas previstas</th>
                            <th>Horas trabalhadas</th>
                            <th>I.DIÁ</th>
                            <th>Ajuste inicial</th>
                            <th>Movimento mensal</th>
                            <th>Abonos / documentos</th>
                            <th>Início do banco</th>
                            <th>Vencimento do banco</th>
                            <th>Saldo do banco</th>
                            <th>Última compensação</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($linhasBanco)): ?>
                            <tr>
                                <td colspan="14">Nenhum funcionário encontrado para os filtros selecionados.</td>
                            </tr>
                        <?php endif; ?>
                        <?php foreach ($linhasBanco as $linha): ?>
                            <?php
                                $saldo = (int) $linha['saldo'];
                                $diasVencimento = $linha['dias_vencimento'];
                                $classeSaldo = $saldo < 0 ? 'negative' : 'positive';
                                $classeVencimento = $diasVencimento !== null && $diasVencimento <= 0 ? 'warning' : '';
                                $textoVencimento = 'Sem marcação';
                                if ($diasVencimento !== null) {
                                    $textoVencimento = $diasVencimento <= 0 ? 'Vence hoje' : 'Em ' . $diasVencimento . ' dias';
                                }
                            ?>
                            <tr>
                                <td>
                                    <strong><?php echo h($linha['nome']); ?></strong><br>
                                    <span class="muted"><?php echo h($linha['email']); ?></span>
                                </td>
                                <td><?php echo h($linha['empresa_nome']); ?><br><span class="muted"><?php echo h($linha['empresa_cnpj']); ?></span></td>
                                <td><?php echo h($linha['cpf']); ?></td>
                                <td><?php echo h($linha['cargo']); ?></td>
                                <td><?php echo h(formatarHorasBanco((int) $linha['esperado'])); ?></td>
                                <td><?php echo h(formatarHorasBanco((int) $linha['trabalhado'])); ?></td>
                                <td><?php echo h(formatarHorasBanco((int) $linha['intervalo_excedido'])); ?></td>
                                <td><?php echo h(formatarSaldoBanco((int) $linha['saldo_inicial'])); ?></td>
                                <td><strong class="<?php echo h($classeSaldo); ?>"><?php echo h(formatarSaldoBanco((int) $linha['saldo_mensal'])); ?></strong></td>
                                <td>
                                    <?php if (!empty($linha['documentos_abono'])): ?>
                                        <?php echo h(implode(' | ', $linha['documentos_abono'])); ?>
                                    <?php else: ?>
                                        <span class="muted">Sem abono</span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <?php echo $linha['primeira_marcacao'] ? h((new DateTimeImmutable($linha['primeira_marcacao']))->format('d/m/Y')) : '<span class="muted">Aguardando primeiro ponto</span>'; ?>
                                </td>
                                <td><span class="pill <?php echo h($classeVencimento); ?>"><?php echo h($textoVencimento); ?></span></td>
                                <td><strong class="<?php echo h($classeSaldo); ?>"><?php echo h(formatarSaldoBanco($saldo)); ?></strong></td>
                                <td>
                                    <?php echo $linha['ultima_marcacao'] ? h((new DateTimeImmutable($linha['ultima_marcacao']))->format('d/m/Y')) : '<span class="muted">Sem registro</span>'; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </section>
    </div>

    <script>
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
<script>
    (() => {
        const root = document.documentElement;
        if (window.matchMedia('(hover: none), (prefers-reduced-motion: reduce)').matches) return;

        let targetX = window.innerWidth / 2;
        let targetY = window.innerHeight * 0.28;
        let currentX = targetX;
        let currentY = targetY;
        let active = false;

        const animateGlow = () => {
            currentX += (targetX - currentX) * 0.16;
            currentY += (targetY - currentY) * 0.16;
            root.style.setProperty('--cursor-x', `${currentX}px`);
            root.style.setProperty('--cursor-y', `${currentY}px`);
            root.style.setProperty('--cursor-glow', active ? '1' : '0.42');
            requestAnimationFrame(animateGlow);
        };

        window.addEventListener('pointermove', (event) => {
            targetX = event.clientX;
            targetY = event.clientY;
            active = true;
        }, { passive: true });

        window.addEventListener('pointerleave', () => {
            active = false;
        });

        animateGlow();
    })();
</script>
</body>
</html>

