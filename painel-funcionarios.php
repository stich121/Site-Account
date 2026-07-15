<?php
require_once __DIR__ . '/seguranca.php';
iniciarSessaoSegura(true);
date_default_timezone_set('America/Sao_Paulo');

if (!isset($_SESSION['funcionario_id'])) {
    header('Location: entrada-funcionarios');
    exit;
}

require_once __DIR__ . '/config_db.php';
require_once __DIR__ . '/google_drive_service.php';

$funcionarioId = (int) $_SESSION['funcionario_id'];
$usuarioRaw = $_SESSION['funcionario_usuario'] ?? 'Funcionário';
$usuarioLoginRaw = $_SESSION['funcionario_usuario'] ?? $usuarioRaw;
$emailRaw = $_SESSION['funcionario_email'] ?? '';
$empresaNomeRaw = $_SESSION['funcionario_empresa_nome'] ?? '';
$empresaCnpjRaw = $_SESSION['funcionario_empresa_cnpj'] ?? '';
$cpfRaw = $_SESSION['funcionario_cpf'] ?? '';
$cargoRaw = $_SESSION['funcionario_cargo'] ?? '';
$nivelAcesso = (int) ($_SESSION['funcionario_nivel_acesso'] ?? 1);
$permitePonto = (int) ($_SESSION['funcionario_permite_ponto'] ?? 1) === 1;
$podeAdministrar = $nivelAcesso >= 3;
$hoje = (new DateTimeImmutable('now'))->format('Y-m-d');
$erro = '';
$sucesso = '';

$tiposPonto = [
    'chegada' => [
        'rotulo' => 'Chegada ao escritório',
        'acao' => 'Bater chegada',
        'icone' => 'fa-door-open',
    ],
    'saida_almoco' => [
        'rotulo' => 'Saída para almoço',
        'acao' => 'Sair para almoço',
        'icone' => 'fa-utensils',
    ],
    'volta_escritorio' => [
        'rotulo' => 'Volta do almoço',
        'acao' => 'Voltar do almoço',
        'icone' => 'fa-building-circle-check',
    ],
    'saida_lanche' => [
        'rotulo' => 'Saída para o lanche',
        'acao' => 'Sair para lanche',
        'icone' => 'fa-mug-hot',
    ],
    'volta_lanche' => [
        'rotulo' => 'Volta do lanche',
        'acao' => 'Voltar do lanche',
        'icone' => 'fa-person-walking-arrow-loop-left',
    ],
    'saida_escritorio' => [
        'rotulo' => 'Saída do escritório',
        'acao' => 'Bater saída',
        'icone' => 'fa-right-from-bracket',
    ],
];

$motivosAjusteManual = [
    'Batida Teste',
    'Esquecimento',
    'Motoboy em Rota',
    'Batida errada',
    'Home Office',
    'Decl. de Comparecimento',
    'Ajuste Horas',
    'Ajuste Sistema',
    'Feriado',
    'Serviço Externo',
    'Sistema Inoperante',
    'Lanche',
];

$tiposDocumentoAjusteManual = [
    'Sem documento',
    'Declaração',
    'Atestado',
    'Comprovante',
    'Documento interno',
    'Outro',
];

function h(string $valor): string
{
    return htmlspecialchars($valor, ENT_QUOTES, 'UTF-8');
}

function limitarTexto(string $valor, int $limite): string
{
    if (function_exists('mb_substr')) {
        return mb_substr($valor, 0, $limite, 'UTF-8');
    }

    return substr($valor, 0, $limite);
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

function formatarData(?string $valor): string
{
    if (!$valor) {
        return '';
    }

    return (new DateTimeImmutable($valor))->format('d/m/Y');
}

function formatarDuracao(?string $inicio, ?string $fim): string
{
    $segundos = segundosEntre($inicio, $fim);
    if ($segundos === null) {
        return '--';
    }

    return formatarSegundos($segundos);
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

function formatarSegundos(int $segundos): string
{
    $segundos = max(0, $segundos);
    $horas = intdiv($segundos, 3600);
    $minutos = intdiv($segundos % 3600, 60);

    return sprintf('%02dh%02d', $horas, $minutos);
}

function formatarSaldoSegundos(int $segundos): string
{
    $prefixo = $segundos < 0 ? '-' : '+';
    return $prefixo . formatarSegundos(abs($segundos));
}

function classeSaldo(int $segundos): string
{
    if ($segundos > 0) {
        return 'positive';
    }

    if ($segundos < 0) {
        return 'negative';
    }

    return 'neutral';
}

function ehEstagiario(?string $cargo): bool
{
    $cargo = function_exists('mb_strtolower') ? mb_strtolower($cargo ?? '', 'UTF-8') : strtolower($cargo ?? '');
    return strpos($cargo, 'estagi') !== false;
}

function tiposPontoParaCargo(array $tiposPonto, ?string $cargo): array
{
    if (!ehEstagiario($cargo)) {
        return $tiposPonto;
    }

    return array_intersect_key($tiposPonto, array_flip([
        'chegada',
        'saida_lanche',
        'volta_lanche',
        'saida_escritorio',
    ]));
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

function segundosAtrasoChegadaDia(array $porTipo, ?string $cargo = null): int
{
    $chegada = $porTipo['chegada']['marcado_em'] ?? null;
    if (!$chegada) {
        return 0;
    }

    $chegadaDt = new DateTimeImmutable($chegada);
    $limiteEntrada = ehEstagiario($cargo) ? '12:55:00' : '08:10:00';
    $limite = (new DateTimeImmutable($chegadaDt->format('Y-m-d') . ' ' . $limiteEntrada));

    return max(0, $chegadaDt->getTimestamp() - $limite->getTimestamp());
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

function textoMetaJornada(string $data, ?string $cargo = null): string
{
    $segundos = segundosEsperadosParaData($data, $cargo);
    return $segundos > 0 ? formatarSegundos($segundos) : '00h00';
}

function inicioMesAtual(): string
{
    return (new DateTimeImmutable('first day of this month'))->format('Y-m-d');
}

function fimMesAtual(): string
{
    return (new DateTimeImmutable('last day of this month'))->format('Y-m-d');
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

function buscarAfastamentosPeriodo(PDO $db, string $dataInicio, string $dataFim, ?int $funcionarioId = null, string $empresaNome = '', string $departamento = ''): array
{
    $where = [
        'a.ativo = 1',
        'a.data_inicio <= :data_fim',
        'a.data_fim >= :data_inicio',
    ];
    $bind = [
        'data_inicio' => $dataInicio,
        'data_fim' => $dataFim,
    ];

    if ($funcionarioId !== null) {
        $where[] = 'a.funcionario_id = :funcionario_id';
        $bind['funcionario_id'] = $funcionarioId;
    }

    if ($empresaNome !== '') {
        $where[] = 'f.empresa_nome = :empresa_nome';
        $bind['empresa_nome'] = $empresaNome;
    }

    if ($departamento !== '') {
        $where[] = 'f.departamento = :departamento';
        $bind['departamento'] = $departamento;
    }

    $stmt = $db->prepare(
        'SELECT a.id, a.funcionario_id, a.tipo_afastamento, a.motivo, a.data_inicio, a.data_fim,
                a.tipo_documento, a.documento_nome, a.documento_caminho, a.bloquear_usuario,
                f.usuario, f.email, f.empresa_nome, f.empresa_cnpj, f.cpf, f.cargo, f.nivel_acesso
         FROM afastamentos a
         INNER JOIN funcionarios f ON f.id = a.funcionario_id
         WHERE ' . implode(' AND ', $where) . '
         ORDER BY a.data_inicio ASC, a.id ASC'
    );
    $stmt->execute($bind);

    return $stmt->fetchAll();
}

function saldosPorFuncionario(array $registros, array $afastamentos = [], array $saldosIniciais = []): array
{
    $porFuncionarioDia = [];
    $afastamentosDia = afastamentosPorFuncionarioDia($afastamentos);
    foreach ($registros as $registro) {
        $funcionarioId = (int) $registro['funcionario_id'];
        $data = $registro['data_referencia'];

        if (!isset($porFuncionarioDia[$funcionarioId][$data])) {
            $porFuncionarioDia[$funcionarioId][$data] = [
                'usuario' => $registro['usuario'],
                'empresa_nome' => $registro['empresa_nome'] ?? '',
                'empresa_cnpj' => $registro['empresa_cnpj'] ?? '',
                'cpf' => $registro['cpf'] ?? '',
                'cargo' => $registro['cargo'] ?? '',
                'tipos' => [],
            ];
        }

        $porFuncionarioDia[$funcionarioId][$data]['tipos'][$registro['tipo']] = $registro;
    }

    $resumo = [];
    foreach ($porFuncionarioDia as $funcionarioId => $dias) {
        foreach ($dias as $data => $dia) {
            if (!isset($resumo[$funcionarioId])) {
                $resumo[$funcionarioId] = [
                    'usuario' => $dia['usuario'],
                    'empresa_nome' => $dia['empresa_nome'],
                    'empresa_cnpj' => $dia['empresa_cnpj'],
                    'cpf' => $dia['cpf'],
                    'cargo' => $dia['cargo'],
                    'dias' => 0,
                    'trabalhado' => 0,
                    'saldo' => $saldosIniciais[$funcionarioId] ?? 0,
                ];
            }

            $trabalhado = segundosTrabalhadosDia($dia['tipos'], $dia['cargo'] ?? '');
            $abonado = isset($afastamentosDia[$funcionarioId][$data]);
            $resumo[$funcionarioId]['dias']++;
            $resumo[$funcionarioId]['trabalhado'] += $trabalhado;
            $resumo[$funcionarioId]['saldo'] += $trabalhado - ($abonado ? 0 : segundosEsperadosParaData((string) $data, $dia['cargo'] ?? ''));
        }
    }

    uasort($resumo, fn($a, $b) => strcmp($a['usuario'], $b['usuario']));
    return $resumo;
}

function formatarHoraPonto(?string $valor): string
{
    if (!$valor) {
        return '--';
    }

    return (new DateTimeImmutable($valor))->format('H:i');
}

function formatarDuracaoEspelho(int $segundos): string
{
    if ($segundos <= 0) {
        return '';
    }

    $horas = intdiv($segundos, 3600);
    $minutos = intdiv($segundos % 3600, 60);

    return sprintf('%02d:%02d', $horas, $minutos);
}

function formatarSaldoEspelho(int $segundos): string
{
    if ($segundos === 0) {
        return '00:00';
    }

    $prefixo = $segundos < 0 ? '-' : '';
    return $prefixo . formatarDuracaoEspelho(abs($segundos));
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
                    'pis_pasep' => $registro['pis_pasep'] ?? '',
                    'cargo' => $registro['cargo'] ?? '',
                    'data_admissao' => $registro['data_admissao'] ?? '',
                    'departamento' => $registro['departamento'] ?? '',
                    'numero_folha' => $registro['numero_folha'] ?? '',
                    'centro_custo' => $registro['centro_custo'] ?? '',
                    'nivel_acesso' => $registro['nivel_acesso'] ?? '',
                ],
                'dias' => [],
            ];
        }

        $data = (string) $registro['data_referencia'];
        $tipo = (string) $registro['tipo'];
        $porFuncionario[$funcionarioId]['dias'][$data][$tipo] = $registro;
    }

    uasort($porFuncionario, function ($a, $b) {
        $empresa = strcmp((string) $a['dados']['empresa_nome'], (string) $b['dados']['empresa_nome']);
        if ($empresa !== 0) {
            return $empresa;
        }

        return strcmp((string) $a['dados']['usuario'], (string) $b['dados']['usuario']);
    });

    return $porFuncionario;
}

function montarLinhasEspelho(DateTimeImmutable $inicio, DateTimeImmutable $fim, array $diasRegistros, array $afastamentosDia, array $ajustesDia = [], ?string $cargo = null, int $saldoInicial = 0): array
{
    $linhas = [];
    $saldoAcumulado = $saldoInicial;
    $totais = [
        'trabalhado' => 0,
        'intervalo' => 0,
        'credito' => 0,
        'debito' => 0,
    ];

    for ($dia = $inicio; $dia <= $fim; $dia = $dia->modify('+1 day')) {
        $data = $dia->format('Y-m-d');
        $diaSemana = (int) $dia->format('N');
        $registrosDia = $diasRegistros[$data] ?? [];
        $temRegistroDia = !empty($registrosDia);
        $afastamento = $afastamentosDia[$data] ?? null;
        $nomeFeriado = nomeFeriadoBeloHorizonte($data);
        $diaSemExpediente = $diaSemana >= 6 || $nomeFeriado !== null;
        $esperado = $diaSemExpediente ? 0 : segundosEsperadosParaData($data, $cargo);
        $trabalhado = segundosTrabalhadosDia($registrosDia, $cargo);
        $intervaloDia = segundosIntervaloDia($registrosDia);
        $intervaloExcedido = segundosExcessoIntervaloDia($registrosDia, $cargo);
        $atrasoChegada = segundosAtrasoChegadaDia($registrosDia, $cargo);
        $saldoDia = $trabalhado - ($afastamento ? 0 : $esperado);
        $credito = max(0, $saldoDia);
        $debito = max(0, -$saldoDia);
        $saldoAcumulado += $saldoDia;

        $observacao = '';
        if ($data === $inicio->format('Y-m-d') && $saldoInicial !== 0) {
            $observacao = 'Ajuste saldo inicial banco: ' . formatarSaldoEspelho($saldoInicial);
        }

        if ($afastamento) {
            $observacao = trim($observacao . ($observacao !== '' ? ' | ' : '') . ($afastamento['tipo_afastamento'] ?? 'Afastamento') . ' ' . ($afastamento['motivo'] ?? ''));
        } elseif ($nomeFeriado !== null && !$temRegistroDia) {
            $observacao = trim($observacao . ($observacao !== '' ? ' | ' : '') . 'Feriado - ' . $nomeFeriado);
        } elseif ($nomeFeriado !== null && $temRegistroDia) {
            $observacao = trim($observacao . ($observacao !== '' ? ' | ' : '') . 'Feriado trabalhado - ' . $nomeFeriado);
        } elseif ($diaSemana >= 6) {
            $observacao = trim($observacao . ($observacao !== '' ? ' | ' : '') . ($temRegistroDia ? 'Folga trabalhada' : 'Folga'));
        } elseif (!$temRegistroDia) {
            $observacao = trim($observacao . ($observacao !== '' ? ' | ' : '') . 'Sem registro');
        }

        $avisos = [];
        if ($atrasoChegada > 0) {
            $avisos[] = 'Está atrasado: ' . formatarDuracaoEspelho($atrasoChegada);
        }

        if ($intervaloExcedido > 0) {
            $avisos[] = 'Atraso intervalo: ' . formatarDuracaoEspelho($intervaloExcedido);
        }

        $temAjusteDia = !empty($ajustesDia[$data]);
        if (!$temAjusteDia) {
            foreach ($registrosDia as $registroDia) {
                if (stripos((string) ($registroDia['user_agent'] ?? ''), 'Ajuste aprovado') !== false) {
                    $temAjusteDia = true;
                    break;
                }
            }
        }

        if ($temAjusteDia) {
            $avisos[] = 'Ajuste de ponto aprovado';
        }

        if (!empty($avisos)) {
            $observacao = trim($observacao . ($observacao !== '' ? ' | ' : '') . implode(' | ', $avisos));
        }

        $linhas[] = [
            'data' => $dia->format('d/m') . ' ' . ['Seg', 'Ter', 'Qua', 'Qui', 'Sex', 'Sab', 'Dom'][$diaSemana - 1],
            'e1' => $diaSemExpediente && !$temRegistroDia ? ($nomeFeriado !== null ? 'Feriado' : 'Folga') : formatarHoraPonto($registrosDia['chegada']['marcado_em'] ?? null),
            's1' => $diaSemExpediente && !$temRegistroDia ? ($nomeFeriado !== null ? 'Feriado' : 'Folga') : formatarHoraPonto($registrosDia['saida_almoco']['marcado_em'] ?? null),
            'e2' => $diaSemExpediente && !$temRegistroDia ? ($nomeFeriado !== null ? 'Feriado' : 'Folga') : formatarHoraPonto($registrosDia['volta_escritorio']['marcado_em'] ?? null),
            's2' => $diaSemExpediente && !$temRegistroDia ? ($nomeFeriado !== null ? 'Feriado' : 'Folga') : formatarHoraPonto($registrosDia['saida_lanche']['marcado_em'] ?? null),
            'e3' => $diaSemExpediente && !$temRegistroDia ? ($nomeFeriado !== null ? 'Feriado' : 'Folga') : formatarHoraPonto($registrosDia['volta_lanche']['marcado_em'] ?? null),
            's3' => $diaSemExpediente && !$temRegistroDia ? ($nomeFeriado !== null ? 'Feriado' : 'Folga') : formatarHoraPonto($registrosDia['saida_escritorio']['marcado_em'] ?? null),
            'hnor' => formatarDuracaoEspelho($trabalhado),
            'intervalo' => formatarDuracaoEspelho($intervaloDia),
            'credito' => formatarDuracaoEspelho($credito),
            'debito' => formatarDuracaoEspelho($debito),
            'saldo' => formatarSaldoEspelho($saldoAcumulado),
            'observacao' => $observacao,
        ];

        $totais['trabalhado'] += $trabalhado;
        $totais['intervalo'] += $intervaloDia;
        $totais['credito'] += $credito;
        $totais['debito'] += $debito;
    }

    return [$linhas, $totais, $saldoAcumulado];
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

function prepararFotoPonto(PDO $db): void
{
    if (!colunaExiste($db, 'registros_ponto', 'foto_comprovante')) {
        $db->exec('ALTER TABLE registros_ponto ADD COLUMN foto_comprovante MEDIUMTEXT NULL AFTER hash_comprovante');
    }

    if (!colunaExiste($db, 'registros_ponto', 'foto_mime')) {
        $db->exec("ALTER TABLE registros_ponto ADD COLUMN foto_mime VARCHAR(40) NULL AFTER foto_comprovante");
    }

    if (!colunaExiste($db, 'registros_ponto', 'latitude')) {
        $db->exec('ALTER TABLE registros_ponto ADD COLUMN latitude DECIMAL(10,7) NULL AFTER foto_mime');
    }

    if (!colunaExiste($db, 'registros_ponto', 'longitude')) {
        $db->exec('ALTER TABLE registros_ponto ADD COLUMN longitude DECIMAL(10,7) NULL AFTER latitude');
    }

    if (!colunaExiste($db, 'registros_ponto', 'precisao_metros')) {
        $db->exec('ALTER TABLE registros_ponto ADD COLUMN precisao_metros DECIMAL(10,2) NULL AFTER longitude');
    }
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

function prepararTabelaAfastamentos(PDO $db): void
{
    $db->exec(
        "CREATE TABLE IF NOT EXISTS afastamentos (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            funcionario_id INT UNSIGNED NOT NULL,
            criado_por INT UNSIGNED NULL,
            tipo_afastamento VARCHAR(80) NOT NULL,
            motivo VARCHAR(255) NOT NULL,
            data_inicio DATE NOT NULL,
            data_fim DATE NOT NULL,
            tipo_documento VARCHAR(80) NULL,
            documento_nome VARCHAR(180) NULL,
            documento_caminho VARCHAR(255) NULL,
            bloquear_usuario TINYINT(1) NOT NULL DEFAULT 0,
            ativo TINYINT(1) NOT NULL DEFAULT 1,
            criado_em TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY idx_afastamentos_funcionario_periodo (funcionario_id, data_inicio, data_fim),
            CONSTRAINT fk_afastamentos_funcionario
                FOREIGN KEY (funcionario_id) REFERENCES funcionarios(id)
                ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
    );
}

function prepararTabelaSolicitacoesAjuste(PDO $db): void
{
    $db->exec(
        "CREATE TABLE IF NOT EXISTS solicitacoes_ajuste_ponto (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            funcionario_id INT UNSIGNED NOT NULL,
            tipo_solicitado VARCHAR(40) NOT NULL,
            data_referencia DATE NOT NULL,
            horario_solicitado DATETIME NOT NULL,
            justificativa TEXT NOT NULL,
            status ENUM('pendente','aprovada','recusada') NOT NULL DEFAULT 'pendente',
            registro_ponto_id BIGINT UNSIGNED NULL,
            avaliado_por INT UNSIGNED NULL,
            parecer_admin TEXT NULL,
            criado_em TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            avaliado_em DATETIME NULL,
            PRIMARY KEY (id),
            KEY idx_solicitacoes_funcionario (funcionario_id, criado_em),
            KEY idx_solicitacoes_status (status, criado_em),
            CONSTRAINT fk_solicitacoes_funcionario
                FOREIGN KEY (funcionario_id) REFERENCES funcionarios(id)
                ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
    );
}

function prepararTabelaAjustesManuaisPonto(PDO $db): void
{
    $db->exec(
        "CREATE TABLE IF NOT EXISTS ajustes_manuais_ponto (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            registro_ponto_id BIGINT UNSIGNED NOT NULL,
            funcionario_id INT UNSIGNED NOT NULL,
            operador_id INT UNSIGNED NOT NULL,
            tipo_ponto VARCHAR(40) NOT NULL,
            data_referencia DATE NOT NULL,
            horario_ajustado DATETIME NOT NULL,
            motivo VARCHAR(80) NOT NULL,
            observacoes TEXT NOT NULL,
            tipo_documento VARCHAR(80) NULL,
            documento_nome VARCHAR(180) NULL,
            documento_caminho VARCHAR(255) NULL,
            acao ENUM('criado','alterado') NOT NULL,
            criado_em TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY idx_ajustes_manuais_funcionario_data (funcionario_id, data_referencia),
            KEY idx_ajustes_manuais_operador (operador_id, criado_em),
            CONSTRAINT fk_ajustes_manuais_registro
                FOREIGN KEY (registro_ponto_id) REFERENCES registros_ponto(id)
                ON DELETE CASCADE,
            CONSTRAINT fk_ajustes_manuais_funcionario
                FOREIGN KEY (funcionario_id) REFERENCES funcionarios(id)
                ON DELETE CASCADE,
            CONSTRAINT fk_ajustes_manuais_operador
                FOREIGN KEY (operador_id) REFERENCES funcionarios(id)
                ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
    );
}

function salvarDocumentoAjusteManual(array $arquivo): array
{
    if (($arquivo['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
        return [null, null];
    }

    if (($arquivo['error'] ?? UPLOAD_ERR_OK) !== UPLOAD_ERR_OK) {
        throw new RuntimeException('Não foi possível carregar o documento do ajuste manual.');
    }

    if (($arquivo['size'] ?? 0) > 8 * 1024 * 1024) {
        throw new RuntimeException('O documento do ajuste manual deve ter no máximo 8 MB.');
    }

    $nomeOriginal = basename((string) ($arquivo['name'] ?? 'documento'));
    $extensao = strtolower(pathinfo($nomeOriginal, PATHINFO_EXTENSION));
    if (!in_array($extensao, ['pdf', 'jpg', 'jpeg', 'png', 'webp', 'doc', 'docx'], true)) {
        throw new RuntimeException('Use documento em PDF, JPG, PNG, WEBP, DOC ou DOCX.');
    }

    $diretorio = __DIR__ . '/uploads/ajustes-ponto';
    if (!is_dir($diretorio) && !mkdir($diretorio, 0755, true)) {
        throw new RuntimeException('Não foi possível criar a pasta de documentos de ajuste manual.');
    }

    $nomeSeguro = preg_replace('/[^a-zA-Z0-9._-]+/', '-', pathinfo($nomeOriginal, PATHINFO_FILENAME));
    $nomeArquivo = date('YmdHis') . '-' . bin2hex(random_bytes(4)) . '-' . trim($nomeSeguro, '-') . '.' . $extensao;
    $destino = $diretorio . '/' . $nomeArquivo;

    if (!move_uploaded_file($arquivo['tmp_name'], $destino)) {
        throw new RuntimeException('Não foi possível salvar o documento do ajuste manual no servidor.');
    }

    return [$nomeOriginal, 'uploads/ajustes-ponto/' . $nomeArquivo];
}

function prepararTabelaHistoricoDownloads(PDO $db): void
{
    $db->exec(
        "CREATE TABLE IF NOT EXISTS historico_downloads (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            solicitado_por INT UNSIGNED NULL,
            funcionario_id INT UNSIGNED NULL,
            empresa_nome VARCHAR(120) NULL,
            tipo_arquivo VARCHAR(20) NOT NULL,
            item_baixado VARCHAR(120) NOT NULL,
            escopo VARCHAR(30) NOT NULL,
            filtros TEXT NULL,
            arquivo_nome VARCHAR(180) NULL,
            caminho_local VARCHAR(255) NULL,
            drive_status VARCHAR(30) NOT NULL DEFAULT 'pendente',
            drive_file_id VARCHAR(120) NULL,
            drive_link VARCHAR(255) NULL,
            drive_erro TEXT NULL,
            total_registros INT UNSIGNED NOT NULL DEFAULT 0,
            ip VARCHAR(45) NULL,
            user_agent VARCHAR(255) NULL,
            criado_em TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY idx_historico_downloads_criado (criado_em),
            KEY idx_historico_downloads_solicitado_por (solicitado_por)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
    );

    $campos = [
        'caminho_local' => "ALTER TABLE historico_downloads ADD COLUMN caminho_local VARCHAR(255) NULL AFTER arquivo_nome",
        'drive_status' => "ALTER TABLE historico_downloads ADD COLUMN drive_status VARCHAR(30) NOT NULL DEFAULT 'pendente' AFTER caminho_local",
        'drive_file_id' => "ALTER TABLE historico_downloads ADD COLUMN drive_file_id VARCHAR(120) NULL AFTER drive_status",
        'drive_link' => "ALTER TABLE historico_downloads ADD COLUMN drive_link VARCHAR(255) NULL AFTER drive_file_id",
        'drive_erro' => "ALTER TABLE historico_downloads ADD COLUMN drive_erro TEXT NULL AFTER drive_link",
    ];

    foreach ($campos as $campo => $sql) {
        if (!colunaExiste($db, 'historico_downloads', $campo)) {
            $db->exec($sql);
        }
    }

    $db->exec("DELETE FROM historico_downloads WHERE criado_em < DATE_SUB(NOW(), INTERVAL 1 MONTH)");
}

function registrarHistoricoDownload(PDO $db, array $dados): int
{
    $stmt = $db->prepare(
        'INSERT INTO historico_downloads (
            solicitado_por, funcionario_id, empresa_nome, tipo_arquivo, item_baixado, escopo,
            filtros, arquivo_nome, caminho_local, drive_status, total_registros, ip, user_agent
         ) VALUES (
            :solicitado_por, :funcionario_id, :empresa_nome, :tipo_arquivo, :item_baixado, :escopo,
            :filtros, :arquivo_nome, :caminho_local, :drive_status, :total_registros, :ip, :user_agent
         )'
    );
    $stmt->execute([
        'solicitado_por' => $dados['solicitado_por'] ?? null,
        'funcionario_id' => $dados['funcionario_id'] ?? null,
        'empresa_nome' => $dados['empresa_nome'] ?? null,
        'tipo_arquivo' => $dados['tipo_arquivo'],
        'item_baixado' => $dados['item_baixado'],
        'escopo' => $dados['escopo'],
        'filtros' => $dados['filtros'] ?? null,
        'arquivo_nome' => $dados['arquivo_nome'] ?? null,
        'caminho_local' => $dados['caminho_local'] ?? null,
        'drive_status' => $dados['drive_status'] ?? 'pendente',
        'total_registros' => (int) ($dados['total_registros'] ?? 0),
        'ip' => $_SERVER['REMOTE_ADDR'] ?? null,
        'user_agent' => limitarTexto($_SERVER['HTTP_USER_AGENT'] ?? '', 255),
    ]);

    return (int) $db->lastInsertId();
}

function atualizarHistoricoDownloadDrive(PDO $db, int $historicoId, array $dados): void
{
    $stmt = $db->prepare(
        'UPDATE historico_downloads
         SET caminho_local = :caminho_local,
             drive_status = :drive_status,
             drive_file_id = :drive_file_id,
             drive_link = :drive_link,
             drive_erro = :drive_erro
         WHERE id = :id'
    );
    $stmt->execute([
        'caminho_local' => $dados['caminho_local'] ?? null,
        'drive_status' => $dados['drive_status'] ?? 'pendente',
        'drive_file_id' => $dados['drive_file_id'] ?? null,
        'drive_link' => $dados['drive_link'] ?? null,
        'drive_erro' => $dados['drive_erro'] ?? null,
        'id' => $historicoId,
    ]);
}

function salvarDownloadNoDrive(PDO $db, int $historicoId, string $conteudo, string $nomeArquivo, string $mimeType): void
{
    try {
        $caminhoLocal = salvarCopiaDownloadLocal($conteudo, $nomeArquivo);
        $drive = enviarArquivoGoogleDrive($caminhoLocal, $nomeArquivo, $mimeType);
        atualizarHistoricoDownloadDrive($db, $historicoId, [
            'caminho_local' => str_replace(__DIR__ . '/', '', $caminhoLocal),
            'drive_status' => 'enviado',
            'drive_file_id' => $drive['id'] ?? null,
            'drive_link' => $drive['webViewLink'] ?? null,
            'drive_erro' => null,
        ]);
    } catch (Throwable $e) {
        atualizarHistoricoDownloadDrive($db, $historicoId, [
            'caminho_local' => isset($caminhoLocal) ? str_replace(__DIR__ . '/', '', $caminhoLocal) : null,
            'drive_status' => 'erro',
            'drive_file_id' => null,
            'drive_link' => null,
            'drive_erro' => limitarTexto($e->getMessage(), 1000),
        ]);
    }
}

function rotuloStatusSolicitacao(string $status): string
{
    return [
        'pendente' => 'Pendente',
        'aprovada' => 'Aprovada',
        'recusada' => 'Recusada',
    ][$status] ?? $status;
}

function classeStatusSolicitacao(string $status): string
{
    return [
        'pendente' => 'warning',
        'aprovada' => 'positive',
        'recusada' => 'negative',
    ][$status] ?? '';
}

function buscarAfastamentoBloqueio(PDO $db, int $funcionarioId): ?array
{
    $stmt = $db->prepare(
        'SELECT tipo_afastamento, motivo, data_inicio, data_fim
         FROM afastamentos
         WHERE funcionario_id = :funcionario_id
           AND bloquear_usuario = 1
           AND ativo = 1
           AND CURDATE() BETWEEN data_inicio AND data_fim
         ORDER BY data_inicio DESC, id DESC
         LIMIT 1'
    );
    $stmt->execute(['funcionario_id' => $funcionarioId]);
    $afastamento = $stmt->fetch();

    return $afastamento ?: null;
}

function mensagemBloqueioAfastamento(array $afastamento): string
{
    $inicio = (new DateTimeImmutable($afastamento['data_inicio']))->format('d/m/Y');
    $fim = (new DateTimeImmutable($afastamento['data_fim']))->format('d/m/Y');

    return 'Usuário bloqueado por afastamento (' . $afastamento['tipo_afastamento'] . ') no período de ' . $inicio . ' a ' . $fim . '.';
}

function normalizarFotoPonto(string $foto): array
{
    if (!preg_match('/^data:(image\/(?:jpeg|png|webp));base64,([A-Za-z0-9+\/=]+)$/', $foto, $matches)) {
        throw new InvalidArgumentException('Capture uma foto pela câmera antes de bater o ponto.');
    }

    if (strlen($foto) > 1800000) {
        throw new InvalidArgumentException('A foto ficou muito grande. Capture novamente.');
    }

    $binario = base64_decode($matches[2], true);
    if ($binario === false || strlen($binario) < 5000) {
        throw new InvalidArgumentException('A foto capturada não pôde ser validada. Tente novamente.');
    }

    return [$foto, $matches[1]];
}

function normalizarCoordenada(?string $valor, float $minimo, float $maximo): ?float
{
    if ($valor === null || $valor === '') {
        return null;
    }

    $numero = filter_var($valor, FILTER_VALIDATE_FLOAT);
    if ($numero === false || $numero < $minimo || $numero > $maximo) {
        return null;
    }

    return $numero;
}

function montarFiltrosAdmin(array $params): array
{
    $where = [];
    $bind = [];

    $funcionarioFiltro = (int) ($params['funcionario_id'] ?? 0);
    if ($funcionarioFiltro > 0) {
        $where[] = 'rp.funcionario_id = :admin_funcionario_id';
        $bind['admin_funcionario_id'] = $funcionarioFiltro;
    }

    $empresaFiltro = trim($params['empresa_nome'] ?? '');
    if ($empresaFiltro !== '') {
        $where[] = 'f.empresa_nome = :admin_empresa_nome';
        $bind['admin_empresa_nome'] = $empresaFiltro;
    }

    $departamentoFiltro = trim($params['departamento'] ?? '');
    if ($departamentoFiltro !== '') {
        $where[] = 'f.departamento = :admin_departamento';
        $bind['admin_departamento'] = $departamentoFiltro;
    }

    $dataInicio = trim($params['data_inicio'] ?? '');
    $dataFim = trim($params['data_fim'] ?? '');
    if ($dataInicio === '' && $dataFim === '') {
        $dataInicio = inicioMesAtual();
        $dataFim = fimMesAtual();
    }

    if ($dataInicio !== '') {
        $where[] = 'rp.data_referencia >= :admin_data_inicio';
        $bind['admin_data_inicio'] = $dataInicio;
    }

    if ($dataFim !== '') {
        $where[] = 'rp.data_referencia <= :admin_data_fim';
        $bind['admin_data_fim'] = $dataFim;
    }

    return [$where ? 'WHERE ' . implode(' AND ', $where) : '', $bind];
}

function exportarPontoDireto(PDO $db, int $funcionarioId, bool $podeAdministrar): void
{
    try {
        $export = $_GET['export'] ?? '';
        if (!in_array($export, ['csv', 'pdf'], true)) {
            http_response_code(400);
            echo 'Tipo de exportação inválido.';
            exit;
        }

        $scopeAll = ($_GET['scope'] ?? '') === 'all' && $podeAdministrar;
        if ($scopeAll) {
            [$sqlWhere, $bindExport] = montarFiltrosAdmin($_GET);
            $filenameSuffix = 'todos';
            $dataInicioExport = trim($_GET['data_inicio'] ?? '') !== '' ? trim($_GET['data_inicio']) : inicioMesAtual();
            $dataFimExport = trim($_GET['data_fim'] ?? '') !== '' ? trim($_GET['data_fim']) : fimMesAtual();
            $funcionarioExport = (int) ($_GET['funcionario_id'] ?? 0);
            $empresaExport = trim($_GET['empresa_nome'] ?? '');
            $departamentoExport = trim($_GET['departamento'] ?? '');
        } else {
            $dataInicioExport = inicioMesAtual();
            $dataFimExport = fimMesAtual();
            $sqlWhere = 'WHERE rp.funcionario_id = :funcionario_id
                         AND rp.data_referencia >= :data_inicio
                         AND rp.data_referencia <= :data_fim';
            $bindExport = [
                'funcionario_id' => $funcionarioId,
                'data_inicio' => $dataInicioExport,
                'data_fim' => $dataFimExport,
            ];
            $filenameSuffix = (string) $funcionarioId;
            $funcionarioExport = $funcionarioId;
            $empresaExport = '';
            $departamentoExport = '';
        }

        $stmt = $db->prepare(
            'SELECT rp.id, rp.data_referencia, rp.tipo, rp.marcado_em, rp.timezone, rp.hash_comprovante,
                    CASE WHEN rp.foto_comprovante IS NULL OR rp.foto_comprovante = \'\' THEN \'nao\' ELSE \'sim\' END AS foto_registrada,
                    rp.latitude, rp.longitude, rp.precisao_metros,
                    f.id AS funcionario_id, f.usuario, f.email, f.empresa_nome, f.empresa_cnpj, f.cpf,
                    f.cargo, f.nivel_acesso
             FROM registros_ponto rp
             INNER JOIN funcionarios f ON f.id = rp.funcionario_id
             ' . $sqlWhere . '
             ORDER BY f.empresa_nome ASC, f.usuario ASC, rp.data_referencia ASC, rp.marcado_em ASC
             LIMIT 5000'
        );
        $stmt->execute($bindExport);
        $linhas = $stmt->fetchAll();

        $arquivoExtensao = $export === 'pdf' ? 'pdf' : 'csv';
        $arquivoNome = ($export === 'pdf' ? 'espelho-ponto-' : 'registros-ponto-') . $filenameSuffix . '.' . $arquivoExtensao;
        $historicoDownloadId = registrarHistoricoDownload($db, [
            'solicitado_por' => $funcionarioId,
            'funcionario_id' => $funcionarioExport > 0 ? $funcionarioExport : null,
            'empresa_nome' => $empresaExport !== '' ? $empresaExport : null,
            'tipo_arquivo' => $export,
            'item_baixado' => $export === 'pdf' ? 'Espelho de ponto' : 'Registros de ponto',
            'escopo' => $scopeAll ? 'todos' : 'proprio',
            'filtros' => json_encode([
                'data_inicio' => $dataInicioExport,
                'data_fim' => $dataFimExport,
                'funcionario_id' => $funcionarioExport > 0 ? $funcionarioExport : null,
                'empresa_nome' => $empresaExport !== '' ? $empresaExport : null,
                'departamento' => $departamentoExport !== '' ? $departamentoExport : null,
            ], JSON_UNESCAPED_UNICODE),
            'arquivo_nome' => $arquivoNome,
            'total_registros' => count($linhas),
        ]);

        if ($export === 'pdf') {
            $linhasPdf = [
                'Espelho de ponto',
                'Periodo: ' . formatarData($dataInicioExport) . ' a ' . formatarData($dataFimExport),
                'Gerado em: ' . (new DateTimeImmutable('now'))->format('d/m/Y H:i:s'),
                'Total de registros: ' . count($linhas),
                str_repeat('-', 105),
                'ID | Data | Funcionario | CPF | Empresa | Tipo | Horario | Foto | Localizacao',
                str_repeat('-', 105),
            ];

            if (empty($linhas)) {
                $linhasPdf[] = 'Nenhum registro encontrado.';
            }

            foreach ($linhas as $linha) {
                $localizacao = trim((string) ($linha['latitude'] ?? '') . ', ' . (string) ($linha['longitude'] ?? ''), ' ,');
                $linhasPdf[] = implode(' | ', [
                    (string) $linha['id'],
                    formatarData((string) $linha['data_referencia']),
                    nomeExibicao((string) ($linha['usuario'] ?? '')),
                    (string) ($linha['cpf'] ?? ''),
                    (string) ($linha['empresa_nome'] ?? ''),
                    (string) ($linha['tipo'] ?? ''),
                    formatarDataHora((string) $linha['marcado_em']),
                    (string) ($linha['foto_registrada'] ?? ''),
                    $localizacao,
                ]);
            }

            $conteudo = gerarPdfTexto($linhasPdf);
            salvarDownloadNoDrive($db, $historicoDownloadId, $conteudo, $arquivoNome, 'application/pdf');
            header('Content-Type: application/pdf');
            header('Content-Disposition: attachment; filename="' . $arquivoNome . '"');
            echo $conteudo;
            exit;
        }

        $saida = fopen('php://temp', 'w+');
        fputcsv($saida, [
            'id', 'empresa', 'cnpj', 'funcionario', 'cpf', 'email', 'cargo', 'nivel_acesso',
            'data', 'tipo', 'marcado_em', 'timezone', 'hash_comprovante', 'foto_registrada',
            'latitude', 'longitude', 'precisao_metros',
        ], ';');

        foreach ($linhas as $linha) {
            fputcsv($saida, [
                $linha['id'],
                $linha['empresa_nome'] ?? '',
                $linha['empresa_cnpj'] ?? '',
                nomeExibicao((string) ($linha['usuario'] ?? '')),
                $linha['cpf'] ?? '',
                $linha['email'] ?? '',
                $linha['cargo'] ?? '',
                $linha['nivel_acesso'] ?? '',
                $linha['data_referencia'] ?? '',
                $linha['tipo'] ?? '',
                $linha['marcado_em'] ?? '',
                $linha['timezone'] ?? '',
                $linha['hash_comprovante'] ?? '',
                $linha['foto_registrada'] ?? '',
                $linha['latitude'] ?? '',
                $linha['longitude'] ?? '',
                $linha['precisao_metros'] ?? '',
            ], ';');
        }

        rewind($saida);
        $conteudo = stream_get_contents($saida);
        fclose($saida);

        salvarDownloadNoDrive($db, $historicoDownloadId, $conteudo, $arquivoNome, 'text/csv; charset=UTF-8');
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="' . $arquivoNome . '"');
        echo $conteudo;
        exit;
    } catch (Throwable $e) {
        error_log($e->getMessage());
        if (!headers_sent()) {
            header('Content-Type: text/plain; charset=utf-8');
            header('Content-Disposition: attachment; filename="erro-exportacao.txt"');
        }
        echo "Não foi possível gerar a exportação agora.\n\nDetalhe técnico registrado no log do servidor.";
        exit;
    }
}

try {
    $db = obterConexao();
    prepararCamposFuncionarios($db);
    prepararFotoPonto($db);
    prepararTabelaAfastamentos($db);
    prepararTabelaSolicitacoesAjuste($db);
    prepararTabelaAjustesManuaisPonto($db);
    prepararTabelaHistoricoDownloads($db);
    if (function_exists('prepararTabelaSaldosIniciaisBancoHoras')) {
        prepararTabelaSaldosIniciaisBancoHoras($db);
    }

    $stmt = $db->prepare('SELECT empresa_nome, empresa_cnpj, cpf, cargo, nivel_acesso, permite_ponto FROM funcionarios WHERE id = :id LIMIT 1');
    $stmt->execute(['id' => $funcionarioId]);
    $dadosAcesso = $stmt->fetch();
    if ($dadosAcesso) {
        $empresaNomeRaw = $dadosAcesso['empresa_nome'] ?? $empresaNomeRaw;
        $empresaCnpjRaw = $dadosAcesso['empresa_cnpj'] ?? $empresaCnpjRaw;
        $cpfRaw = $dadosAcesso['cpf'] ?? $cpfRaw;
        $cargoRaw = $dadosAcesso['cargo'] ?? $cargoRaw;
        $nivelAcesso = (int) ($dadosAcesso['nivel_acesso'] ?? $nivelAcesso);
        $permitePonto = (int) ($dadosAcesso['permite_ponto'] ?? ($permitePonto ? 1 : 0)) === 1;
        $podeAdministrar = $nivelAcesso >= 3;
        $_SESSION['funcionario_empresa_nome'] = $empresaNomeRaw;
        $_SESSION['funcionario_empresa_cnpj'] = $empresaCnpjRaw;
        $_SESSION['funcionario_cpf'] = $cpfRaw;
        $_SESSION['funcionario_cargo'] = $cargoRaw;
        $_SESSION['funcionario_nivel_acesso'] = $nivelAcesso;
        $_SESSION['funcionario_permite_ponto'] = $permitePonto ? 1 : 0;
    }

    $afastamentoBloqueioAtual = buscarAfastamentoBloqueio($db, $funcionarioId);

    if ($_SERVER['REQUEST_METHOD'] === 'GET' && in_array($_GET['export'] ?? '', ['csv', 'pdf'], true)) {
        $export = $_GET['export'];
        $scopeAll = ($_GET['scope'] ?? '') === 'all' && $podeAdministrar;
        $mostrarAssinaturaPdf = $scopeAll;

        if ($scopeAll) {
            [$whereAdmin, $bindAdmin] = montarFiltrosAdmin($_GET);
            $sqlWhere = $whereAdmin;
            $bindExport = $bindAdmin;
            $filenameSuffix = 'todos';
            $dataInicioExport = trim($_GET['data_inicio'] ?? '') !== '' ? trim($_GET['data_inicio']) : inicioMesAtual();
            $dataFimExport = trim($_GET['data_fim'] ?? '') !== '' ? trim($_GET['data_fim']) : fimMesAtual();
            $funcionarioExport = (int) ($_GET['funcionario_id'] ?? 0);
            $empresaExport = trim($_GET['empresa_nome'] ?? '');
            $departamentoExport = trim($_GET['departamento'] ?? '');
        } else {
            $sqlWhere = 'WHERE rp.funcionario_id = :funcionario_id
                         AND rp.data_referencia >= :data_inicio
                         AND rp.data_referencia <= :data_fim';
            $dataInicioExport = inicioMesAtual();
            $dataFimExport = fimMesAtual();
            $bindExport = [
                'funcionario_id' => $funcionarioId,
                'data_inicio' => $dataInicioExport,
                'data_fim' => $dataFimExport,
            ];
            $funcionarioExport = $funcionarioId;
            $empresaExport = '';
            $departamentoExport = '';
            $filenameSuffix = (string) $funcionarioId;
        }

        $stmt = $db->prepare(
            'SELECT rp.id, rp.data_referencia, rp.tipo, rp.marcado_em, rp.timezone, rp.hash_comprovante, rp.user_agent,
                    CASE WHEN rp.foto_comprovante IS NULL OR rp.foto_comprovante = \'\' THEN \'nao\' ELSE \'sim\' END AS foto_registrada,
                    rp.latitude, rp.longitude, rp.precisao_metros,
                    f.id AS funcionario_id, f.usuario, f.email, f.empresa_nome, f.empresa_cnpj, f.cpf,
                    f.pis_pasep, f.cargo, f.data_admissao, f.departamento, f.numero_folha, f.centro_custo, f.nivel_acesso
             FROM registros_ponto rp
             INNER JOIN funcionarios f ON f.id = rp.funcionario_id
             ' . $sqlWhere . '
             ORDER BY f.empresa_nome ASC, f.usuario ASC, rp.data_referencia ASC, rp.marcado_em ASC
             LIMIT 2000'
        );
        $stmt->execute($bindExport);
        $linhas = $stmt->fetchAll();
        $afastamentosExport = buscarAfastamentosPeriodo(
            $db,
            $dataInicioExport,
            $dataFimExport,
            $funcionarioExport > 0 ? $funcionarioExport : null,
            $empresaExport,
            $departamentoExport
        );
        $saldosExport = saldosPorFuncionario($linhas, $afastamentosExport);
        $arquivoNome = ($export === 'pdf' ? 'espelho-ponto-' : 'registros-ponto-') . $filenameSuffix . '.' . $export;
        $historicoDownloadId = registrarHistoricoDownload($db, [
            'solicitado_por' => $funcionarioId,
            'funcionario_id' => $funcionarioExport > 0 ? $funcionarioExport : null,
            'empresa_nome' => $empresaExport !== '' ? $empresaExport : null,
            'tipo_arquivo' => $export,
            'item_baixado' => $export === 'pdf' ? 'Espelho de ponto' : 'Registros de ponto',
            'escopo' => $scopeAll ? 'todos' : 'proprio',
            'filtros' => json_encode([
                'data_inicio' => $dataInicioExport,
                'data_fim' => $dataFimExport,
                'funcionario_id' => $funcionarioExport > 0 ? $funcionarioExport : null,
                'empresa_nome' => $empresaExport !== '' ? $empresaExport : null,
                'departamento' => $departamentoExport !== '' ? $departamentoExport : null,
            ], JSON_UNESCAPED_UNICODE),
            'arquivo_nome' => $arquivoNome,
            'total_registros' => count($linhas),
        ]);

        if ($export === 'pdf') {
            $inicioPdf = new DateTimeImmutable($dataInicioExport);
            $fimPdf = new DateTimeImmutable($dataFimExport);
            $registrosEspelho = registrosPorFuncionarioDia($linhas);
            $whereFuncionariosPdf = ['permite_ponto = 1'];
            $bindFuncionariosPdf = [];
            if ($scopeAll) {
                if ($funcionarioExport > 0) {
                    $whereFuncionariosPdf[] = 'id = :funcionario_id';
                    $bindFuncionariosPdf['funcionario_id'] = $funcionarioExport;
                }

                if ($empresaExport !== '') {
                    $whereFuncionariosPdf[] = 'empresa_nome = :empresa_nome';
                    $bindFuncionariosPdf['empresa_nome'] = $empresaExport;
                }

                if ($departamentoExport !== '') {
                    $whereFuncionariosPdf[] = 'departamento = :departamento';
                    $bindFuncionariosPdf['departamento'] = $departamentoExport;
                }
            } else {
                $whereFuncionariosPdf[] = 'id = :funcionario_id';
                $bindFuncionariosPdf['funcionario_id'] = $funcionarioId;
            }

            $stmtFuncionariosPdf = $db->prepare(
                'SELECT id AS funcionario_id, usuario, email, empresa_nome, empresa_cnpj, cpf,
                        pis_pasep, cargo, data_admissao, departamento, numero_folha, centro_custo, nivel_acesso
                 FROM funcionarios
                 WHERE ' . implode(' AND ', $whereFuncionariosPdf) . '
                 ORDER BY empresa_nome ASC, usuario ASC'
            );
            $stmtFuncionariosPdf->execute($bindFuncionariosPdf);
            foreach ($stmtFuncionariosPdf->fetchAll() as $funcionarioPdf) {
                $fidPdf = (int) $funcionarioPdf['funcionario_id'];
                if (!isset($registrosEspelho[$fidPdf])) {
                    $registrosEspelho[$fidPdf] = [
                        'dados' => $funcionarioPdf,
                        'dias' => [],
                    ];
                } else {
                    $registrosEspelho[$fidPdf]['dados'] = array_merge(
                        $funcionarioPdf,
                        $registrosEspelho[$fidPdf]['dados']
                    );
                }
            }

            uasort($registrosEspelho, function ($a, $b) {
                $empresa = strcmp((string) $a['dados']['empresa_nome'], (string) $b['dados']['empresa_nome']);
                if ($empresa !== 0) {
                    return $empresa;
                }

                return strcmp((string) $a['dados']['usuario'], (string) $b['dados']['usuario']);
            });
            $afastamentosDiaPdf = afastamentosPorFuncionarioDia($afastamentosExport);
            $whereAjustesPdf = [
                's.status = \'aprovada\'',
                's.data_referencia >= :data_inicio',
                's.data_referencia <= :data_fim',
            ];
            $bindAjustesPdf = [
                'data_inicio' => $dataInicioExport,
                'data_fim' => $dataFimExport,
            ];
            if ($funcionarioExport > 0) {
                $whereAjustesPdf[] = 's.funcionario_id = :funcionario_id';
                $bindAjustesPdf['funcionario_id'] = $funcionarioExport;
            }
            if ($empresaExport !== '') {
                $whereAjustesPdf[] = 'f.empresa_nome = :empresa_nome';
                $bindAjustesPdf['empresa_nome'] = $empresaExport;
            }
            if ($departamentoExport !== '') {
                $whereAjustesPdf[] = 'f.departamento = :departamento';
                $bindAjustesPdf['departamento'] = $departamentoExport;
            }
            if (!$scopeAll) {
                $whereAjustesPdf[] = 's.funcionario_id = :funcionario_logado';
                $bindAjustesPdf['funcionario_logado'] = $funcionarioId;
            }

            $stmtAjustesPdf = $db->prepare(
                'SELECT s.funcionario_id, s.data_referencia
                 FROM solicitacoes_ajuste_ponto s
                 INNER JOIN funcionarios f ON f.id = s.funcionario_id
                 WHERE ' . implode(' AND ', $whereAjustesPdf)
            );
            $stmtAjustesPdf->execute($bindAjustesPdf);
            $ajustesDiaPdf = [];
            foreach ($stmtAjustesPdf->fetchAll() as $ajustePdf) {
                $ajustesDiaPdf[(int) $ajustePdf['funcionario_id']][(string) $ajustePdf['data_referencia']] = true;
            }
            header('Content-Type: text/html; charset=utf-8');
            ob_start();
            ?>
            <!DOCTYPE html>
            <html lang="pt-BR">
            <head>
                <meta charset="UTF-8">
                <title>Espelho de Ponto</title>
                <style>
                    * { box-sizing: border-box; }
                    body { font-family: Arial, sans-serif; color: #111; margin: 10px; font-size: 9px; }
                    .print-actions { margin-bottom: 10px; }
                    button { border: 1px solid #111; background: #111; color: #fff; border-radius: 4px; padding: 8px 12px; cursor: pointer; }
                    .employee-page { page-break-after: always; break-after: page; }
                    .employee-page:last-child { page-break-after: auto; }
                    .topbar { display: flex; justify-content: space-between; align-items: flex-start; border-bottom: 1px solid #111; padding-bottom: 3px; margin-bottom: 4px; }
                    .title { font-size: 13px; font-weight: 700; text-transform: uppercase; }
                    .subtitle { font-size: 10px; font-weight: 700; margin-top: 1px; }
                    .issued { text-align: right; color: #333; line-height: 1.1; font-size: 7.8px; }
                    .info-grid { display: none; }
                    .info-item strong { display: block; font-size: 7.5px; text-transform: uppercase; color: #555; line-height: 1.05; }
                    .info-item span { display: block; font-size: 9px; font-weight: 700; line-height: 1.1; overflow-wrap: anywhere; }
                    .employee-info { background: #e3e3e3; border-radius: 2px; padding: 5px 7px; margin: 4px 0 5px; font-size: 8.8px; line-height: 1.25; }
                    .employee-info-title { font-weight: 700; border-bottom: 1px solid #fff; padding-bottom: 3px; margin-bottom: 4px; }
                    .employee-info-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 1px 12px; }
                    .employee-info-grid div { white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
                    .schedule { width: 100%; border-collapse: collapse; margin: 4px 0 5px; font-size: 8.2px; table-layout: fixed; }
                    .schedule th, .schedule td { border: 1px solid #999; padding: 1px 2px; text-align: center; line-height: 1.05; }
                    .schedule th { background: #e9e9e9; }
                    .mirror { width: 100%; border-collapse: collapse; font-size: 7.8px; table-layout: fixed; }
                    .mirror th, .mirror td { border: 1px solid #999; padding: 1px; text-align: center; vertical-align: middle; line-height: 1.05; white-space: nowrap; }
                    .mirror th { background: #e9e9e9; }
                    .mirror th { font-size: 6.7px; white-space: normal; }
                    .mirror th:nth-child(2), .mirror th:nth-child(3), .mirror th:nth-child(4), .mirror th:nth-child(5), .mirror th:nth-child(6), .mirror th:nth-child(7),
                    .mirror td:nth-child(2), .mirror td:nth-child(3), .mirror td:nth-child(4), .mirror td:nth-child(5), .mirror td:nth-child(6), .mirror td:nth-child(7) { width: 4.7%; }
                    .mirror th:nth-child(8), .mirror th:nth-child(9), .mirror th:nth-child(10), .mirror th:nth-child(11), .mirror th:nth-child(12),
                    .mirror td:nth-child(8), .mirror td:nth-child(9), .mirror td:nth-child(10), .mirror td:nth-child(11), .mirror td:nth-child(12) { width: 5.1%; }
                    .mirror .date { text-align: left; width: 6.5%; }
                    .mirror .obs { text-align: left; width: 28%; white-space: normal; overflow-wrap: anywhere; font-size: 7.2px; }
                    .mirror tfoot td { font-weight: 700; background: #f4f4f4; }
                    .legend { display: grid; grid-template-columns: repeat(3, 1fr); gap: 1px 6px; margin-top: 3px; color: #333; font-size: 7.5px; }
                    .signature { margin-top: 5px; font-size: 7.8px; line-height: 1.1; }
                    .signature-lines { display: flex; justify-content: space-between; gap: 18px; margin-top: 12px; }
                    .signature-line { border-top: 1px solid #111; width: 46%; text-align: center; padding-top: 2px; font-size: 7.9px; }
                    .empty { border: 1px solid #999; padding: 12px; font-size: 11px; }
                    @media print {
                        @page { size: A4 portrait; margin: 7mm; }
                        body { margin: 0; }
                        .print-actions { display: none; }
                        .employee-page { min-height: 0; overflow: visible; }
                    }
                </style>
            </head>
            <body>
                <div class="print-actions">
                    <button onclick="window.print()">Salvar/Imprimir PDF</button>
                </div>
                <?php if (empty($registrosEspelho)): ?>
                    <div class="empty">Nenhum registro encontrado para o período selecionado.</div>
                <?php endif; ?>
                <?php foreach ($registrosEspelho as $fid => $espelho): ?>
                    <?php
                    $dadosFuncionario = $espelho['dados'];
                    $temAjusteMes = !empty($ajustesDiaPdf[(int) $fid]);
                    [$linhasEspelho, $totaisEspelho, $saldoFinalEspelho] = montarLinhasEspelho(
                        $inicioPdf,
                        $fimPdf,
                        $espelho['dias'],
                        $afastamentosDiaPdf[(int) $fid] ?? [],
                        $ajustesDiaPdf[(int) $fid] ?? [],
                        $dadosFuncionario['cargo'] ?? ''
                    );
                    ?>
                    <section class="employee-page">
                        <div class="topbar">
                            <div>
                                <div class="title"><?php echo h(nomeExibicao($dadosFuncionario['usuario'] ?? 'Colaborador')); ?></div>
                                <div class="subtitle">Espelho de Ponto</div>
                            </div>
                            <div class="issued">
                                Emitido em <?php echo h((new DateTimeImmutable('now'))->format('d/m/Y H:i:s')); ?><br>
                                Período: <?php echo h($inicioPdf->format('d/m/Y')); ?> a <?php echo h($fimPdf->format('d/m/Y')); ?>
                            </div>
                        </div>

                        <div class="info-grid">
                            <div class="info-item"><strong>Empresa</strong><span><?php echo h($dadosFuncionario['empresa_nome'] ?? ''); ?></span></div>
                            <div class="info-item"><strong>CNPJ</strong><span><?php echo h($dadosFuncionario['empresa_cnpj'] ?? ''); ?></span></div>
                            <div class="info-item"><strong>CPF</strong><span><?php echo h($dadosFuncionario['cpf'] ?? ''); ?></span></div>
                            <div class="info-item"><strong>Colaborador</strong><span><?php echo h(nomeExibicao($dadosFuncionario['usuario'] ?? '')); ?></span></div>
                            <div class="info-item"><strong>Função</strong><span><?php echo h($dadosFuncionario['cargo'] ?? ''); ?></span></div>
                            <div class="info-item"><strong>E-mail</strong><span><?php echo h($dadosFuncionario['email'] ?? ''); ?></span></div>
                        </div>

                        <div class="employee-info">
                            <div class="employee-info-title">
                                <?php echo h(nomeExibicao($dadosFuncionario['usuario'] ?? 'Colaborador')); ?> -
                                Período: <?php echo h($inicioPdf->format('d/m/y')); ?> à <?php echo h($fimPdf->format('d/m/y')); ?>
                            </div>
                            <div class="employee-info-grid">
                                <div><strong>PIS/PASEP:</strong> <?php echo h($dadosFuncionario['pis_pasep'] ?? ''); ?></div>
                                <div><strong>CPF:</strong> <?php echo h($dadosFuncionario['cpf'] ?? ''); ?></div>
                                <div><strong>Nº de Folha:</strong> <?php echo h($dadosFuncionario['numero_folha'] ?? ''); ?></div>
                                <div><strong>Função:</strong> <?php echo h($dadosFuncionario['cargo'] ?? ''); ?></div>
                                <div><strong>Admissão:</strong> <?php echo h(formatarData($dadosFuncionario['data_admissao'] ?? '')); ?></div>
                                <div><strong>Departamento:</strong> <?php echo h($dadosFuncionario['departamento'] ?? ''); ?></div>
                                <div><strong>Centro de Custo:</strong> <?php echo h($dadosFuncionario['centro_custo'] ?? ''); ?></div>
                                <?php if ($temAjusteMes): ?>
                                    <div><strong>Ajuste:</strong> Houve ajuste de ponto aprovado no mês</div>
                                <?php endif; ?>
                            </div>
                        </div>

                        <table class="mirror">
                        <thead>
                            <tr>
                                <th>Data</th>
                                <th>Chegada</th>
                                <th>Saída almoço</th>
                                <th>Volta almoço</th>
                                <th>Saída lanche</th>
                                <th>Volta lanche</th>
                                <th>Saída escritório</th>
                                <th>H.NOR</th>
                                <th>I.DIÁ</th>
                                <th>B.CRÉ</th>
                                <th>B.DÉB</th>
                                <th>S.BAN</th>
                                <th>OBSER</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($linhasEspelho as $linhaEspelho): ?>
                                <tr>
                                    <td class="date"><?php echo h($linhaEspelho['data']); ?></td>
                                    <td><?php echo h($linhaEspelho['e1']); ?></td>
                                    <td><?php echo h($linhaEspelho['s1']); ?></td>
                                    <td><?php echo h($linhaEspelho['e2']); ?></td>
                                    <td><?php echo h($linhaEspelho['s2']); ?></td>
                                    <td><?php echo h($linhaEspelho['e3']); ?></td>
                                    <td><?php echo h($linhaEspelho['s3']); ?></td>
                                    <td><?php echo h($linhaEspelho['hnor']); ?></td>
                                    <td><?php echo h($linhaEspelho['intervalo']); ?></td>
                                    <td><?php echo h($linhaEspelho['credito']); ?></td>
                                    <td><?php echo h($linhaEspelho['debito']); ?></td>
                                    <td><?php echo h($linhaEspelho['saldo']); ?></td>
                                    <td class="obs"><?php echo h($linhaEspelho['observacao']); ?></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                        <tfoot>
                            <tr>
                                <td class="date">Totais</td>
                                <td colspan="6"></td>
                                <td><?php echo h(formatarDuracaoEspelho($totaisEspelho['trabalhado'])); ?></td>
                                <td><?php echo h(formatarDuracaoEspelho($totaisEspelho['intervalo'])); ?></td>
                                <td><?php echo h(formatarDuracaoEspelho($totaisEspelho['credito'])); ?></td>
                                <td><?php echo h(formatarDuracaoEspelho($totaisEspelho['debito'])); ?></td>
                                <td><?php echo h(formatarSaldoEspelho($saldoFinalEspelho)); ?></td>
                                <td></td>
                            </tr>
                        </tfoot>
                    </table>

                    <div class="legend">
                        <span><strong>H.NOR:</strong> Horas normais</span>
                        <span><strong>I.DIÁ:</strong> Intervalo diário</span>
                        <span><strong>B.CRÉ:</strong> Banco crédito</span>
                        <span><strong>B.DÉB:</strong> Banco débito</span>
                        <span><strong>S.BAN:</strong> Saldo do banco</span>
                        <span><strong>OBSER:</strong> Observação</span>
                    </div>

                    <?php if ($mostrarAssinaturaPdf): ?>
                        <div class="signature">
                            Reconheço a exatidão das horas constantes de acordo com minha frequência neste intervalo.
                            <div class="signature-lines">
                                <div class="signature-line"><?php echo h(nomeExibicao($dadosFuncionario['usuario'] ?? 'Colaborador')); ?><br>Colaborador</div>
                                <div class="signature-line">Cleber Anderson<br>Dono do escritório</div>
                            </div>
                        </div>
                    <?php endif; ?>
                </section>
                <?php endforeach; ?>
                <script>window.print();</script>
            </body>
            </html>
            <?php
            $conteudoPdf = ob_get_clean();
            salvarDownloadNoDrive($db, $historicoDownloadId, $conteudoPdf, $arquivoNome, 'text/html; charset=UTF-8');
            echo $conteudoPdf;
            exit;
        }

        $saida = fopen('php://temp', 'w+');
        $cabecalhoCsv = [
            'tipo_linha', 'id', 'empresa', 'cnpj', 'funcionario', 'cpf', 'email', 'cargo', 'nivel_acesso',
            'data', 'tipo', 'marcado_em', 'timezone', 'hash_comprovante', 'foto_registrada',
            'latitude', 'longitude', 'precisao_metros', 'dias', 'horas_trabalhadas',
            'horas_credoras', 'horas_devedoras', 'saldo_horas',
            'tipo_afastamento', 'tipo_documento', 'nome_documento', 'arquivo_documento', 'motivo_afastamento', 'abonado',
        ];
        fputcsv($saida, $cabecalhoCsv, ';');
        foreach ($saldosExport as $saldo) {
            $linhaResumo = [
                'resumo',
                '',
                $saldo['empresa_nome'] ?? '',
                $saldo['empresa_cnpj'] ?? '',
                nomeExibicao($saldo['usuario']),
                $saldo['cpf'] ?? '',
                '',
                $saldo['cargo'] ?? '',
                '',
                '',
                '',
                '',
                '',
                '',
                '',
                '',
                '',
                '',
                '',
                '',
                '',
                $saldo['dias'],
                formatarSegundos($saldo['trabalhado']),
                '+' . formatarSegundos(max(0, (int) $saldo['saldo'])),
                '-' . formatarSegundos(max(0, -(int) $saldo['saldo'])),
                formatarSaldoSegundos($saldo['saldo']),
            ];
            fputcsv($saida, array_slice(array_pad($linhaResumo, count($cabecalhoCsv), ''), 0, count($cabecalhoCsv)), ';');
        }
        foreach ($afastamentosExport as $afastamento) {
            $linhaAfastamento = [
                'afastamento',
                $afastamento['id'],
                $afastamento['empresa_nome'] ?? '',
                $afastamento['empresa_cnpj'] ?? '',
                nomeExibicao($afastamento['usuario']),
                $afastamento['cpf'] ?? '',
                $afastamento['email'] ?? '',
                $afastamento['cargo'] ?? '',
                $afastamento['nivel_acesso'] ?? '',
                $afastamento['data_inicio'] . ' a ' . $afastamento['data_fim'],
                '',
                '',
                '',
                '',
                '',
                '',
                '',
                '',
                '',
                '',
                '',
                '',
                '',
                $afastamento['tipo_afastamento'] ?? '',
                $afastamento['tipo_documento'] ?? '',
                $afastamento['documento_nome'] ?? '',
                $afastamento['documento_caminho'] ?? '',
                $afastamento['motivo'] ?? '',
                'sim',
            ];
            fputcsv($saida, array_slice(array_pad($linhaAfastamento, count($cabecalhoCsv), ''), 0, count($cabecalhoCsv)), ';');
        }
        foreach ($linhas as $linha) {
            $linhaRegistro = [
                'registro',
                $linha['id'],
                $linha['empresa_nome'] ?? '',
                $linha['empresa_cnpj'] ?? '',
                nomeExibicao($linha['usuario']),
                $linha['cpf'] ?? '',
                $linha['email'],
                $linha['cargo'] ?? '',
                $linha['nivel_acesso'],
                $linha['data_referencia'],
                $linha['tipo'],
                $linha['marcado_em'],
                $linha['timezone'],
                $linha['hash_comprovante'],
                $linha['foto_registrada'],
                $linha['latitude'],
                $linha['longitude'],
                $linha['precisao_metros'],
                '',
                '',
                '',
                '',
                '',
            ];
            fputcsv($saida, array_slice(array_pad($linhaRegistro, count($cabecalhoCsv), ''), 0, count($cabecalhoCsv)), ';');
        }
        rewind($saida);
        $conteudoCsv = stream_get_contents($saida);
        fclose($saida);
        salvarDownloadNoDrive($db, $historicoDownloadId, $conteudoCsv, $arquivoNome, 'text/csv; charset=UTF-8');
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="' . $arquivoNome . '"');
        echo $conteudoCsv;
        exit;
    }

    if (empty($_SESSION['csrf_ponto'])) {
        $_SESSION['csrf_ponto'] = bin2hex(random_bytes(32));
    }

    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        if (($_POST['acao'] ?? '') === 'editar_ponto') {
            if (!$podeAdministrar) {
                $erro = 'Você não tem permissão para alterar registros de ponto.';
            } elseif (!hash_equals($_SESSION['csrf_ponto'] ?? '', $_POST['csrf_ponto'] ?? '')) {
                $erro = 'Sessão expirada. Atualize a página e tente novamente.';
            } else {
                $registroId = (int) ($_POST['registro_id'] ?? 0);
                $novoTipo = $_POST['tipo_ponto'] ?? '';
                $novoHorarioRaw = trim($_POST['marcado_em'] ?? '');

                if ($registroId <= 0 || !isset($tiposPonto[$novoTipo]) || $novoHorarioRaw === '') {
                    $erro = 'Dados inválidos para alterar o ponto.';
                } else {
                    $novoHorario = DateTimeImmutable::createFromFormat('Y-m-d\TH:i', $novoHorarioRaw);
                    if (!$novoHorario) {
                        $erro = 'Informe data e hora válidas para alterar o ponto.';
                    } else {
                        $stmt = $db->prepare(
                            'UPDATE registros_ponto
                             SET tipo = :tipo,
                                 data_referencia = :data_referencia,
                                 marcado_em = :marcado_em,
                                 hash_comprovante = :hash
                             WHERE id = :id'
                        );
                        $novoHash = hash('sha256', implode('|', [
                            'editado',
                            $registroId,
                            $novoTipo,
                            $novoHorario->format('Y-m-d H:i:s'),
                            $funcionarioId,
                            (new DateTimeImmutable('now'))->format('Y-m-d H:i:s'),
                        ]));
                        $stmt->execute([
                            'tipo' => $novoTipo,
                            'data_referencia' => $novoHorario->format('Y-m-d'),
                            'marcado_em' => $novoHorario->format('Y-m-d H:i:s'),
                            'hash' => $novoHash,
                            'id' => $registroId,
                        ]);
                        $sucesso = 'Registro de ponto atualizado com sucesso.';
                    }
                }
            }
        } elseif (($_POST['acao'] ?? '') === 'solicitar_ajuste') {
            if (!hash_equals($_SESSION['csrf_ponto'] ?? '', $_POST['csrf_ponto'] ?? '')) {
                $erro = 'Sessão expirada. Atualize a página e tente novamente.';
            } else {
                $tipoSolicitado = $_POST['tipo_ponto'] ?? '';
                $horarioRaw = trim($_POST['horario_solicitado'] ?? '');
                $justificativa = trim($_POST['justificativa'] ?? '');

                if (!isset($tiposPonto[$tipoSolicitado]) || $horarioRaw === '' || $justificativa === '') {
                    $erro = 'Informe o tipo de ponto, horário sugerido e justificativa.';
                } else {
                    $horarioSolicitado = DateTimeImmutable::createFromFormat('Y-m-d\TH:i', $horarioRaw);
                    if (!$horarioSolicitado) {
                        $erro = 'Informe uma data e hora válidas para a solicitação.';
                    } elseif (strlen($justificativa) < 8) {
                        $erro = 'Explique melhor o motivo do ajuste solicitado.';
                    } else {
                        $stmt = $db->prepare(
                            'SELECT id
                             FROM solicitacoes_ajuste_ponto
                             WHERE funcionario_id = :funcionario_id
                               AND tipo_solicitado = :tipo
                               AND data_referencia = :data_referencia
                               AND status = \'pendente\'
                             LIMIT 1'
                        );
                        $stmt->execute([
                            'funcionario_id' => $funcionarioId,
                            'tipo' => $tipoSolicitado,
                            'data_referencia' => $horarioSolicitado->format('Y-m-d'),
                        ]);

                        if ($stmt->fetch()) {
                            $erro = 'Já existe uma solicitação pendente para este tipo de ponto nesta data.';
                        } else {
                            $stmt = $db->prepare(
                                'INSERT INTO solicitacoes_ajuste_ponto
                                    (funcionario_id, tipo_solicitado, data_referencia, horario_solicitado, justificativa)
                                 VALUES
                                    (:funcionario_id, :tipo_solicitado, :data_referencia, :horario_solicitado, :justificativa)'
                            );
                            $stmt->execute([
                                'funcionario_id' => $funcionarioId,
                                'tipo_solicitado' => $tipoSolicitado,
                                'data_referencia' => $horarioSolicitado->format('Y-m-d'),
                                'horario_solicitado' => $horarioSolicitado->format('Y-m-d H:i:s'),
                                'justificativa' => limitarTexto($justificativa, 1000),
                            ]);
                            $sucesso = 'Solicitação enviada para análise dos operadores nível 3.';
                        }
                    }
                }
            }
        } elseif (($_POST['acao'] ?? '') === 'ajuste_manual_ponto') {
            if (!$podeAdministrar) {
                $erro = 'Você não tem permissão para fazer ajuste manual de ponto.';
            } elseif (!hash_equals($_SESSION['csrf_ponto'] ?? '', $_POST['csrf_ponto'] ?? '')) {
                $erro = 'Sessão expirada. Atualize a página e tente novamente.';
            } else {
                $ajustadoId = (int) ($_POST['funcionario_id'] ?? 0);
                $tipoPontoManual = $_POST['tipo_ponto'] ?? '';
                $horarioManualRaw = trim($_POST['horario_ajustado'] ?? '');
                $motivoManual = trim($_POST['motivo'] ?? '');
                $observacoesManual = trim($_POST['observacoes'] ?? '');
                $tipoDocumentoManual = trim($_POST['tipo_documento'] ?? '');

                if ($ajustadoId <= 0 || !isset($tiposPonto[$tipoPontoManual]) || $horarioManualRaw === '' || $motivoManual === '' || $observacoesManual === '') {
                    $erro = 'Preencha funcionário, tipo de ponto, data/hora, motivo e justificativa.';
                } elseif (!in_array($motivoManual, $motivosAjusteManual, true)) {
                    $erro = 'Selecione um motivo válido para o ajuste manual.';
                } elseif ($tipoDocumentoManual !== '' && !in_array($tipoDocumentoManual, $tiposDocumentoAjusteManual, true)) {
                    $erro = 'Selecione um tipo de documento válido.';
                } else {
                    $horarioManual = DateTimeImmutable::createFromFormat('Y-m-d\TH:i', $horarioManualRaw);
                    if (!$horarioManual) {
                        $erro = 'Informe data e hora válidas para o ajuste manual.';
                    } elseif (strlen($observacoesManual) < 8) {
                        $erro = 'Informe uma justificativa mais detalhada para o ajuste manual.';
                    } else {
                        $stmt = $db->prepare('SELECT id FROM funcionarios WHERE id = :id AND ativo = 1 LIMIT 1');
                        $stmt->execute(['id' => $ajustadoId]);

                        if (!$stmt->fetchColumn()) {
                            $erro = 'Funcionário não encontrado ou inativo.';
                        } else {
                            try {
                                [$documentoNome, $documentoCaminho] = salvarDocumentoAjusteManual($_FILES['documento_ajuste'] ?? []);
                                $dataReferenciaManual = $horarioManual->format('Y-m-d');
                                $marcadoEmManual = $horarioManual->format('Y-m-d H:i:s');
                                $ipManual = $_SERVER['REMOTE_ADDR'] ?? null;
                                $textoAuditoriaManual = limitarTexto(
                                    'Ajuste aprovado manual nivel 3 por ' . $usuarioRaw . ': ' . $motivoManual . ' - ' . $observacoesManual,
                                    255
                                );

                                $db->beginTransaction();

                                $stmt = $db->prepare(
                                    'SELECT id
                                     FROM registros_ponto
                                     WHERE funcionario_id = :funcionario_id
                                       AND data_referencia = :data_referencia
                                       AND tipo = :tipo
                                     LIMIT 1'
                                );
                                $stmt->execute([
                                    'funcionario_id' => $ajustadoId,
                                    'data_referencia' => $dataReferenciaManual,
                                    'tipo' => $tipoPontoManual,
                                ]);
                                $registroManualId = (int) ($stmt->fetchColumn() ?: 0);
                                $acaoManual = $registroManualId > 0 ? 'alterado' : 'criado';

                                if ($registroManualId > 0) {
                                    $stmt = $db->prepare(
                                        'UPDATE registros_ponto
                                         SET marcado_em = :marcado_em,
                                             data_referencia = :data_referencia,
                                             timezone = :timezone,
                                             ip = :ip,
                                             user_agent = :user_agent
                                         WHERE id = :id'
                                    );
                                    $stmt->execute([
                                        'marcado_em' => $marcadoEmManual,
                                        'data_referencia' => $dataReferenciaManual,
                                        'timezone' => 'America/Sao_Paulo',
                                        'ip' => $ipManual,
                                        'user_agent' => $textoAuditoriaManual,
                                        'id' => $registroManualId,
                                    ]);
                                } else {
                                    $stmt = $db->prepare(
                                        'INSERT INTO registros_ponto (
                                            funcionario_id, tipo, data_referencia, marcado_em, timezone, ip, user_agent
                                         ) VALUES (
                                            :funcionario_id, :tipo, :data_referencia, :marcado_em, :timezone, :ip, :user_agent
                                         )'
                                    );
                                    $stmt->execute([
                                        'funcionario_id' => $ajustadoId,
                                        'tipo' => $tipoPontoManual,
                                        'data_referencia' => $dataReferenciaManual,
                                        'marcado_em' => $marcadoEmManual,
                                        'timezone' => 'America/Sao_Paulo',
                                        'ip' => $ipManual,
                                        'user_agent' => $textoAuditoriaManual,
                                    ]);
                                    $registroManualId = (int) $db->lastInsertId();
                                }

                                $hashManual = hash('sha256', implode('|', [
                                    $registroManualId,
                                    $ajustadoId,
                                    $tipoPontoManual,
                                    $marcadoEmManual,
                                    'America/Sao_Paulo',
                                    $ipManual,
                                    $motivoManual,
                                    $observacoesManual,
                                    $funcionarioId,
                                ]));
                                $stmt = $db->prepare('UPDATE registros_ponto SET hash_comprovante = :hash WHERE id = :id');
                                $stmt->execute(['hash' => $hashManual, 'id' => $registroManualId]);

                                $stmt = $db->prepare(
                                    'INSERT INTO ajustes_manuais_ponto (
                                        registro_ponto_id, funcionario_id, operador_id, tipo_ponto, data_referencia,
                                        horario_ajustado, motivo, observacoes, tipo_documento, documento_nome,
                                        documento_caminho, acao
                                     ) VALUES (
                                        :registro_ponto_id, :funcionario_id, :operador_id, :tipo_ponto, :data_referencia,
                                        :horario_ajustado, :motivo, :observacoes, :tipo_documento, :documento_nome,
                                        :documento_caminho, :acao
                                     )'
                                );
                                $stmt->execute([
                                    'registro_ponto_id' => $registroManualId,
                                    'funcionario_id' => $ajustadoId,
                                    'operador_id' => $funcionarioId,
                                    'tipo_ponto' => $tipoPontoManual,
                                    'data_referencia' => $dataReferenciaManual,
                                    'horario_ajustado' => $marcadoEmManual,
                                    'motivo' => $motivoManual,
                                    'observacoes' => limitarTexto($observacoesManual, 1000),
                                    'tipo_documento' => $tipoDocumentoManual ?: null,
                                    'documento_nome' => $documentoNome,
                                    'documento_caminho' => $documentoCaminho,
                                    'acao' => $acaoManual,
                                ]);

                                $db->commit();
                                $sucesso = $acaoManual === 'criado'
                                    ? 'Ponto manual criado com justificativa registrada.'
                                    : 'Ponto manual alterado com justificativa registrada.';
                            } catch (Throwable $e) {
                                if ($db->inTransaction()) {
                                    $db->rollBack();
                                }
                                $erro = $e->getMessage();
                            }
                        }
                    }
                }
            }
        } elseif (($_POST['acao'] ?? '') === 'avaliar_ajuste') {
            if (!$podeAdministrar) {
                $erro = 'Você não tem permissão para avaliar solicitações de ajuste.';
            } elseif (!hash_equals($_SESSION['csrf_ponto'] ?? '', $_POST['csrf_ponto'] ?? '')) {
                $erro = 'Sessão expirada. Atualize a página e tente novamente.';
            } else {
                $solicitacaoId = (int) ($_POST['solicitacao_id'] ?? 0);
                $decisao = $_POST['decisao'] ?? '';
                $parecer = trim($_POST['parecer_admin'] ?? '');

                if ($solicitacaoId <= 0 || !in_array($decisao, ['aprovar', 'recusar'], true)) {
                    $erro = 'Solicitação inválida.';
                } else {
                    $stmt = $db->prepare(
                        'SELECT *
                         FROM solicitacoes_ajuste_ponto
                         WHERE id = :id AND status = \'pendente\'
                         LIMIT 1'
                    );
                    $stmt->execute(['id' => $solicitacaoId]);
                    $solicitacao = $stmt->fetch();

                    if (!$solicitacao) {
                        $erro = 'Solicitação não encontrada ou já avaliada.';
                    } elseif ($decisao === 'recusar') {
                        $stmt = $db->prepare(
                            'UPDATE solicitacoes_ajuste_ponto
                             SET status = \'recusada\',
                                 avaliado_por = :avaliado_por,
                                 parecer_admin = :parecer_admin,
                                 avaliado_em = :avaliado_em
                             WHERE id = :id'
                        );
                        $stmt->execute([
                            'avaliado_por' => $funcionarioId,
                            'parecer_admin' => $parecer !== '' ? limitarTexto($parecer, 1000) : null,
                            'avaliado_em' => (new DateTimeImmutable('now'))->format('Y-m-d H:i:s'),
                            'id' => $solicitacaoId,
                        ]);
                        $sucesso = 'Solicitação recusada.';
                    } else {
                        $db->beginTransaction();
                        try {
                            $hash = hash('sha256', implode('|', [
                                'ajuste-aprovado',
                                $solicitacao['id'],
                                $solicitacao['funcionario_id'],
                                $solicitacao['tipo_solicitado'],
                                $solicitacao['horario_solicitado'],
                                $funcionarioId,
                                (new DateTimeImmutable('now'))->format('Y-m-d H:i:s'),
                            ]));

                            $stmt = $db->prepare(
                                'SELECT id
                                 FROM registros_ponto
                                 WHERE funcionario_id = :funcionario_id
                                   AND tipo = :tipo
                                   AND data_referencia = :data_referencia
                                 LIMIT 1'
                            );
                            $stmt->execute([
                                'funcionario_id' => (int) $solicitacao['funcionario_id'],
                                'tipo' => $solicitacao['tipo_solicitado'],
                                'data_referencia' => $solicitacao['data_referencia'],
                            ]);
                            $registroExistenteId = (int) ($stmt->fetchColumn() ?: 0);

                            if ($registroExistenteId > 0) {
                                $stmt = $db->prepare(
                                    'UPDATE registros_ponto
                                     SET marcado_em = :marcado_em,
                                         data_referencia = :data_referencia,
                                         timezone = :timezone,
                                         user_agent = :user_agent,
                                         hash_comprovante = :hash
                                     WHERE id = :id'
                                );
                                $stmt->execute([
                                    'marcado_em' => $solicitacao['horario_solicitado'],
                                    'data_referencia' => $solicitacao['data_referencia'],
                                    'timezone' => 'America/Sao_Paulo',
                                    'user_agent' => 'Ajuste aprovado por operador nivel 3',
                                    'hash' => $hash,
                                    'id' => $registroExistenteId,
                                ]);
                                $registroPontoId = $registroExistenteId;
                            } else {
                                $stmt = $db->prepare(
                                    'INSERT INTO registros_ponto
                                        (funcionario_id, tipo, data_referencia, marcado_em, timezone, ip, user_agent, hash_comprovante)
                                     VALUES
                                        (:funcionario_id, :tipo, :data_referencia, :marcado_em, :timezone, :ip, :user_agent, :hash)'
                                );
                                $stmt->execute([
                                    'funcionario_id' => (int) $solicitacao['funcionario_id'],
                                    'tipo' => $solicitacao['tipo_solicitado'],
                                    'data_referencia' => $solicitacao['data_referencia'],
                                    'marcado_em' => $solicitacao['horario_solicitado'],
                                    'timezone' => 'America/Sao_Paulo',
                                    'ip' => $_SERVER['REMOTE_ADDR'] ?? null,
                                    'user_agent' => 'Ajuste aprovado por operador nivel 3',
                                    'hash' => $hash,
                                ]);
                                $registroPontoId = (int) $db->lastInsertId();
                            }

                            $stmt = $db->prepare(
                                'UPDATE solicitacoes_ajuste_ponto
                                 SET status = \'aprovada\',
                                     registro_ponto_id = :registro_ponto_id,
                                     avaliado_por = :avaliado_por,
                                     parecer_admin = :parecer_admin,
                                     avaliado_em = :avaliado_em
                                 WHERE id = :id'
                            );
                            $stmt->execute([
                                'registro_ponto_id' => $registroPontoId,
                                'avaliado_por' => $funcionarioId,
                                'parecer_admin' => $parecer !== '' ? limitarTexto($parecer, 1000) : null,
                                'avaliado_em' => (new DateTimeImmutable('now'))->format('Y-m-d H:i:s'),
                                'id' => $solicitacaoId,
                            ]);

                            $db->commit();
                            $sucesso = 'Solicitação aprovada e ponto ajustado.';
                        } catch (Throwable $e) {
                            if ($db->inTransaction()) {
                                $db->rollBack();
                            }
                            $erro = 'Não foi possível aprovar a solicitação. Tente novamente.';
                        }
                    }
                }
            }
        } else {
        $tipo = $_POST['tipo_ponto'] ?? '';
        $csrf = $_POST['csrf_ponto'] ?? '';

        if (!$permitePonto) {
            $erro = 'Seu perfil não registra ponto. Use a área administrativa para consulta.';
        } elseif ($afastamentoBloqueioAtual) {
            $erro = mensagemBloqueioAfastamento($afastamentoBloqueioAtual);
        } elseif (!hash_equals($_SESSION['csrf_ponto'], $csrf)) {
            $erro = 'Sessão expirada. Atualize a página e tente novamente.';
        } elseif (!isset($tiposPonto[$tipo])) {
            $erro = 'Tipo de marcação inválido.';
        } else {
            try {
                [$fotoPonto, $fotoMime] = normalizarFotoPonto($_POST['foto_ponto'] ?? '');
            } catch (InvalidArgumentException $e) {
                $erro = $e->getMessage();
                $fotoPonto = null;
                $fotoMime = null;
            }

            $stmt = $db->prepare(
                'SELECT tipo
                 FROM registros_ponto
                 WHERE funcionario_id = :funcionario_id AND data_referencia = :data_referencia
                 ORDER BY marcado_em ASC'
            );
            $stmt->execute([
                'funcionario_id' => $funcionarioId,
                'data_referencia' => $hoje,
            ]);
            $tiposRegistrados = array_column($stmt->fetchAll(), 'tipo');
            $tiposPontoPerfil = tiposPontoParaCargo($tiposPonto, $cargoRaw);
            $tiposRegistradosPerfil = array_values(array_filter($tiposRegistrados, fn($tipoRegistrado) => isset($tiposPontoPerfil[$tipoRegistrado])));
            $proximoTipo = array_keys($tiposPontoPerfil)[count($tiposRegistradosPerfil)] ?? null;

            if ($erro !== '') {
                // A mensagem de erro ja foi definida na validacao da foto.
            } elseif (!isset($tiposPontoPerfil[$tipo])) {
                $erro = 'Este tipo de marcação não faz parte da sua jornada.';
            } elseif (in_array($tipo, $tiposRegistrados, true)) {
                $erro = 'Esta marcação já foi registrada hoje.';
            } elseif ($tipo !== $proximoTipo) {
                $erro = 'Bata primeiro o ponto anterior para manter a sequência correta.';
            } else {
                $agora = new DateTimeImmutable('now');
                $ip = $_SERVER['REMOTE_ADDR'] ?? null;
                $userAgent = substr($_SERVER['HTTP_USER_AGENT'] ?? '', 0, 255);
                $latitude = normalizarCoordenada($_POST['latitude'] ?? null, -90, 90);
                $longitude = normalizarCoordenada($_POST['longitude'] ?? null, -180, 180);
                $precisaoMetros = normalizarCoordenada($_POST['precisao_metros'] ?? null, 0, 100000);

                if ($latitude === null || $longitude === null) {
                    $erro = 'Não foi possível capturar a localização. Permita a localização no navegador e tente novamente.';
                } else {

                    $insert = $db->prepare(
                        'INSERT INTO registros_ponto (funcionario_id, tipo, data_referencia, marcado_em, timezone, ip, user_agent, foto_comprovante, foto_mime, latitude, longitude, precisao_metros)
                         VALUES (:funcionario_id, :tipo, :data_referencia, :marcado_em, :timezone, :ip, :user_agent, :foto_comprovante, :foto_mime, :latitude, :longitude, :precisao_metros)'
                    );
                    $insert->execute([
                        'funcionario_id' => $funcionarioId,
                        'tipo' => $tipo,
                        'data_referencia' => $hoje,
                        'marcado_em' => $agora->format('Y-m-d H:i:s'),
                        'timezone' => 'America/Sao_Paulo',
                        'ip' => $ip,
                        'user_agent' => $userAgent,
                        'foto_comprovante' => $fotoPonto,
                        'foto_mime' => $fotoMime,
                        'latitude' => $latitude,
                        'longitude' => $longitude,
                        'precisao_metros' => $precisaoMetros,
                    ]);

                    $registroId = (int) $db->lastInsertId();
                    $hash = hash('sha256', implode('|', [
                        $registroId,
                        $funcionarioId,
                        $usuarioRaw,
                        $usuarioLoginRaw,
                        $emailRaw,
                        $tipo,
                        $agora->format('Y-m-d H:i:s'),
                        'America/Sao_Paulo',
                        $ip,
                        $latitude,
                        $longitude,
                        $precisaoMetros,
                    ]));

                    $update = $db->prepare('UPDATE registros_ponto SET hash_comprovante = :hash WHERE id = :id');
                    $update->execute([
                        'hash' => $hash,
                        'id' => $registroId,
                    ]);

                    header('Location: painel?comprovante=' . $registroId);
                    exit;
                }
            }
        }
        }
    }

    $stmt = $db->prepare(
        'SELECT id, tipo, marcado_em, timezone, hash_comprovante
         FROM registros_ponto
         WHERE funcionario_id = :funcionario_id AND data_referencia = :data_referencia
         ORDER BY marcado_em ASC'
    );
    $stmt->execute([
        'funcionario_id' => $funcionarioId,
        'data_referencia' => $hoje,
    ]);
    $registrosHoje = $stmt->fetchAll();

    $porTipo = [];
    foreach ($registrosHoje as $registro) {
        $porTipo[$registro['tipo']] = $registro;
    }

    $tiposPontoPerfil = tiposPontoParaCargo($tiposPonto, $cargoRaw);
    $tiposRegistradosPerfil = array_values(array_filter(array_keys($porTipo), fn($tipoRegistrado) => isset($tiposPontoPerfil[$tipoRegistrado])));
    $proximoTipo = array_keys($tiposPontoPerfil)[count($tiposRegistradosPerfil)] ?? null;

    $stmt = $db->prepare(
        'SELECT id, data_referencia, tipo, marcado_em, hash_comprovante
         FROM registros_ponto
         WHERE funcionario_id = :funcionario_id
         ORDER BY marcado_em DESC
         LIMIT 30'
    );
    $stmt->execute(['funcionario_id' => $funcionarioId]);
    $historico = $stmt->fetchAll();

    $stmt = $db->prepare(
        'SELECT s.id, s.tipo_solicitado, s.data_referencia, s.horario_solicitado, s.justificativa,
                s.status, s.parecer_admin, s.criado_em, s.avaliado_em, a.usuario AS avaliador_usuario
         FROM solicitacoes_ajuste_ponto s
         LEFT JOIN funcionarios a ON a.id = s.avaliado_por
         WHERE s.funcionario_id = :funcionario_id
         ORDER BY s.criado_em DESC
         LIMIT 20'
    );
    $stmt->execute(['funcionario_id' => $funcionarioId]);
    $solicitacoesUsuario = $stmt->fetchAll();

    $stmt = $db->prepare(
        'SELECT rp.id, rp.funcionario_id, rp.tipo, rp.data_referencia, rp.marcado_em,
                f.usuario, f.empresa_nome, f.empresa_cnpj, f.cpf, f.cargo
         FROM registros_ponto rp
         INNER JOIN funcionarios f ON f.id = rp.funcionario_id
         WHERE rp.funcionario_id = :funcionario_id
           AND rp.data_referencia >= :data_inicio
           AND rp.data_referencia <= :data_fim
         ORDER BY rp.marcado_em ASC'
    );
    $stmt->execute([
        'funcionario_id' => $funcionarioId,
        'data_inicio' => inicioMesAtual(),
        'data_fim' => fimMesAtual(),
    ]);
    $afastamentosMensalFuncionario = buscarAfastamentosPeriodo($db, inicioMesAtual(), fimMesAtual(), $funcionarioId);
    $saldoMensalFuncionario = saldosPorFuncionario($stmt->fetchAll(), $afastamentosMensalFuncionario)[$funcionarioId] ?? [
        'usuario' => $usuarioRaw,
        'empresa_nome' => $empresaNomeRaw,
        'empresa_cnpj' => $empresaCnpjRaw,
        'cpf' => $cpfRaw,
        'cargo' => $cargoRaw,
        'dias' => 0,
        'trabalhado' => 0,
        'saldo' => 0,
    ];

    $comprovante = null;
    if (isset($_GET['comprovante'])) {
        $stmt = $db->prepare(
            'SELECT id, tipo, data_referencia, marcado_em, timezone, hash_comprovante, foto_comprovante, latitude, longitude, precisao_metros
             FROM registros_ponto
             WHERE id = :id AND funcionario_id = :funcionario_id
             LIMIT 1'
        );
        $stmt->execute([
            'id' => (int) $_GET['comprovante'],
            'funcionario_id' => $funcionarioId,
        ]);
        $comprovante = $stmt->fetch() ?: null;
    }

    $funcionariosAdmin = [];
    $registrosAdmin = [];
    $saldosAdmin = [];
    $solicitacoesAjusteAdmin = [];
    $ajustesManuaisAdmin = [];
    if ($podeAdministrar) {
        $stmt = $db->query(
            'SELECT id, usuario, email, empresa_nome, empresa_cnpj, cpf, cargo, nivel_acesso, permite_ponto, ativo
             FROM funcionarios
             ORDER BY empresa_nome ASC, usuario ASC'
        );
        $funcionariosAdmin = $stmt->fetchAll();

        [$whereAdmin, $bindAdmin] = montarFiltrosAdmin($_GET);
        $stmt = $db->prepare(
            'SELECT rp.id, rp.funcionario_id, rp.tipo, rp.data_referencia, rp.marcado_em,
                    rp.latitude, rp.longitude, rp.precisao_metros,
                    CASE WHEN rp.foto_comprovante IS NULL OR rp.foto_comprovante = \'\' THEN \'nao\' ELSE \'sim\' END AS foto_registrada,
                    f.usuario, f.email, f.empresa_nome, f.empresa_cnpj, f.cpf, f.cargo
             FROM registros_ponto rp
             INNER JOIN funcionarios f ON f.id = rp.funcionario_id
             ' . $whereAdmin . '
             ORDER BY rp.marcado_em DESC
             LIMIT 2000'
        );
        $stmt->execute($bindAdmin);
        $registrosAdmin = $stmt->fetchAll();

        $stmt = $db->query(
            'SELECT s.id, s.funcionario_id, s.tipo_solicitado, s.data_referencia, s.horario_solicitado,
                    s.justificativa, s.status, s.parecer_admin, s.criado_em, s.avaliado_em,
                    f.usuario, f.email, f.empresa_nome, f.empresa_cnpj, f.cpf, f.cargo,
                    a.usuario AS avaliador_usuario
             FROM solicitacoes_ajuste_ponto s
             INNER JOIN funcionarios f ON f.id = s.funcionario_id
             LEFT JOIN funcionarios a ON a.id = s.avaliado_por
             ORDER BY CASE s.status
                WHEN \'pendente\' THEN 1
                WHEN \'aprovada\' THEN 2
                WHEN \'recusada\' THEN 3
                ELSE 4
             END, s.criado_em DESC
             LIMIT 200'
        );
        $solicitacoesAjusteAdmin = $stmt->fetchAll();

        $stmt = $db->query(
            'SELECT amp.*, f.usuario AS funcionario_nome, f.empresa_nome, f.empresa_cnpj,
                    o.usuario AS operador_nome
             FROM ajustes_manuais_ponto amp
             INNER JOIN funcionarios f ON f.id = amp.funcionario_id
             INNER JOIN funcionarios o ON o.id = amp.operador_id
             ORDER BY amp.criado_em DESC
             LIMIT 30'
        );
        $ajustesManuaisAdmin = $stmt->fetchAll();

        $dataInicioAdmin = trim($_GET['data_inicio'] ?? '') !== '' ? trim($_GET['data_inicio']) : inicioMesAtual();
        $dataFimAdmin = trim($_GET['data_fim'] ?? '') !== '' ? trim($_GET['data_fim']) : fimMesAtual();
        $funcionarioAdminFiltro = (int) ($_GET['funcionario_id'] ?? 0);
        $empresaAdminFiltro = trim($_GET['empresa_nome'] ?? '');
        $afastamentosAdminSaldo = buscarAfastamentosPeriodo(
            $db,
            $dataInicioAdmin,
            $dataFimAdmin,
            $funcionarioAdminFiltro > 0 ? $funcionarioAdminFiltro : null,
            $empresaAdminFiltro
        );
        $saldosAdmin = saldosPorFuncionario($registrosAdmin, $afastamentosAdminSaldo);
    }
} catch (PDOException $e) {
    $erro = 'Erro ao preparar o painel de ponto. Confira se o arquivo funcionarios.sql foi importado no phpMyAdmin e se o config_db.php está com os dados corretos do banco.';
    $registrosHoje = [];
    $porTipo = [];
    $historico = [];
    $solicitacoesUsuario = [];
    $saldoMensalFuncionario = [
        'usuario' => $usuarioRaw,
        'empresa_nome' => $empresaNomeRaw,
        'empresa_cnpj' => $empresaCnpjRaw,
        'cpf' => $cpfRaw,
        'cargo' => $cargoRaw,
        'dias' => 0,
        'trabalhado' => 0,
        'saldo' => 0,
    ];
    $funcionariosAdmin = [];
    $registrosAdmin = [];
    $saldosAdmin = [];
    $solicitacoesAjusteAdmin = [];
    $ajustesManuaisAdmin = [];
    $afastamentoBloqueioAtual = null;
    $proximoTipo = null;
    $tiposPontoPerfil = tiposPontoParaCargo($tiposPonto, $cargoRaw);
    $comprovante = null;
}

$usuario = h(nomeExibicao($usuarioRaw));
$email = h($emailRaw);
$empresaNome = h($empresaNomeRaw);
$empresaCnpj = h($empresaCnpjRaw);
$cpf = h($cpfRaw);
$cargo = h($cargoRaw);
$csrf = h($_SESSION['csrf_ponto'] ?? '');
$entrada = $porTipo['chegada']['marcado_em'] ?? null;
$saidaAlmoco = $porTipo['saida_almoco']['marcado_em'] ?? null;
$voltaAlmoco = $porTipo['volta_escritorio']['marcado_em'] ?? null;
$saidaLanche = $porTipo['saida_lanche']['marcado_em'] ?? null;
$voltaLanche = $porTipo['volta_lanche']['marcado_em'] ?? null;
$saida = $porTipo['saida_escritorio']['marcado_em'] ?? null;
$tempoManha = formatarDuracao($entrada, $saidaAlmoco);
$tempoTarde1 = formatarDuracao($voltaAlmoco, $saidaLanche);
$tempoTarde2 = formatarDuracao($voltaLanche, $saida);
$segundosHoje = segundosTrabalhadosDia($porTipo, $cargoRaw);
$segundosEsperadosDia = segundosEsperadosParaData($hoje, $cargoRaw);
$saldoHoje = $segundosHoje - $segundosEsperadosDia;
$atrasoChegadaHoje = segundosAtrasoChegadaDia($porTipo, $cargoRaw);
$atrasoIntervaloHoje = segundosExcessoIntervaloDia($porTipo, $cargoRaw);
$ehEstagiarioLogado = ehEstagiario($cargoRaw);
$avisosPontoHoje = [];
if ($atrasoChegadaHoje > 0) {
    $avisosPontoHoje[] = 'Está atrasado na entrada: ' . formatarDuracaoEspelho($atrasoChegadaHoje) . ' após a tolerância de ' . ($ehEstagiarioLogado ? '12:55' : '08:10') . '.';
}
if ($atrasoIntervaloHoje > 0) {
    $avisosPontoHoje[] = 'Atraso no intervalo: ' . formatarDuracaoEspelho($atrasoIntervaloHoje) . ' acima de ' . ($ehEstagiarioLogado ? '15min de lanche.' : '1h15 de almoço + lanche.');
}
$saldoMensalSegundos = (int) ($saldoMensalFuncionario['saldo'] ?? 0);
$creditoMensalSegundos = max(0, $saldoMensalSegundos);
$debitoMensalSegundos = max(0, -$saldoMensalSegundos);
$rotuloMesAtual = (new DateTimeImmutable('first day of this month'))->format('m/Y');
$dataInicioFiltro = $_GET['data_inicio'] ?? inicioMesAtual();
$dataFimFiltro = $_GET['data_fim'] ?? fimMesAtual();
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Ponto do Funcionário | ACCOUNT Contabilidade</title>
    <link rel="icon" type="image/svg+xml" href="favicon.svg">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600&family=Montserrat:wght@500;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        :root {
            --bg-main: #0A0A0A;
            --bg-card: #161616;
            --bg-soft: #202020;
            --primary: #74C92C;
            --primary-hover: #5EA522;
            --danger: #FF453A;
            --warning: #FFD60A;
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
                radial-gradient(circle at 18% 6%, rgba(116, 201, 44, 0.16), transparent 26rem),
                radial-gradient(circle at 82% 0%, rgba(255, 255, 255, 0.08), transparent 22rem),
                linear-gradient(135deg, #070807 0%, #0b0d0b 48%, #050605 100%);
            color: var(--text-light);
            padding: 2rem;
            position: relative;
            overflow-x: hidden;
        }

        body::before {
            content: "";
            position: fixed;
            inset: -20%;
            z-index: -1;
            pointer-events: none;
            background:
                radial-gradient(circle at 24% 24%, rgba(116, 201, 44, 0.12), transparent 20rem),
                radial-gradient(circle at 76% 18%, rgba(116, 201, 44, 0.08), transparent 18rem);
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

        @keyframes softPulse {
            0%, 100% { box-shadow: 0 0 0 rgba(116, 201, 44, 0); }
            50% { box-shadow: 0 0 0 4px rgba(116, 201, 44, 0.08); }
        }

        @keyframes modalIn {
            from { opacity: 0; transform: translateY(18px) scale(0.98); }
            to { opacity: 1; transform: translateY(0) scale(1); }
        }

        a { color: inherit; text-decoration: none; }

        .shell {
            width: min(1180px, 100%);
            margin: 0 auto;
            overflow: hidden;
            animation: pageIn 0.55s ease both;
        }

        .topbar {
            display: flex;
            justify-content: space-between;
            align-items: center;
            gap: 1rem;
            margin-bottom: 2rem;
        }

        .brand img {
            height: 34px;
            width: auto;
            display: block;
        }

        .top-actions {
            display: flex;
            gap: 0.75rem;
            flex-wrap: wrap;
            min-width: 0;
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
            transition: 0.25s ease;
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
            background: rgba(255,255,255,0.04);
            color: var(--primary);
            border-color: rgba(116, 201, 44, 0.4);
        }

        .btn-danger {
            background: rgba(255, 69, 58, 0.12);
            color: #FFD1CE;
            border: 1px solid rgba(255, 69, 58, 0.34);
        }

        .btn-danger:hover {
            background: rgba(255, 69, 58, 0.2);
            color: var(--text-white);
            border-color: rgba(255, 69, 58, 0.58);
        }

        .btn:disabled {
            background: #3a3a3a;
            color: var(--text-muted);
            cursor: not-allowed;
            transform: none;
        }

        .hero {
            display: grid;
            grid-template-columns: minmax(0, 1.3fr) minmax(320px, 0.7fr);
            gap: 1.5rem;
            align-items: stretch;
            margin-bottom: 1.5rem;
        }

        .panel {
            background: linear-gradient(145deg, rgba(24, 24, 24, 0.96), rgba(17, 18, 17, 0.94));
            border: 1px solid var(--border);
            border-radius: 8px;
            padding: 2rem;
            min-width: 0;
            box-shadow: 0 18px 45px rgba(0, 0, 0, 0.22);
            transition: transform 0.24s ease, border-color 0.24s ease, box-shadow 0.24s ease;
        }

        .panel:hover {
            transform: translateY(-2px);
            border-color: rgba(116, 201, 44, 0.24);
            box-shadow: 0 22px 58px rgba(0, 0, 0, 0.3);
        }

        .welcome h1 {
            font-family: var(--font-titles);
            color: var(--text-white);
            text-transform: uppercase;
            font-size: clamp(2rem, 5vw, 3.6rem);
            line-height: 1;
            margin-bottom: 1rem;
        }

        .welcome p,
        .muted {
            color: var(--text-muted);
            line-height: 1.7;
        }

        .badge {
            display: inline-flex;
            align-items: center;
            gap: 0.5rem;
            margin-bottom: 1.4rem;
            padding: 0.45rem 0.8rem;
            border-radius: 999px;
            color: var(--primary);
            background: rgba(116, 201, 44, 0.1);
            border: 1px solid rgba(116, 201, 44, 0.2);
            font-size: 0.82rem;
            font-weight: 700;
            text-transform: uppercase;
        }

        .clock {
            display: grid;
            align-content: center;
            text-align: center;
        }

        .clock small {
            color: var(--text-muted);
            text-transform: uppercase;
            font-weight: 700;
            letter-spacing: 0.05em;
        }

        .clock strong {
            display: block;
            font-family: var(--font-titles);
            font-size: clamp(2.5rem, 8vw, 4.8rem);
            color: var(--primary);
            margin: 0.7rem 0;
        }

        .grid {
            display: grid;
            grid-template-columns: minmax(0, 1fr) minmax(320px, 420px);
            gap: 1.5rem;
            align-items: start;
        }

        .section-title {
            display: flex;
            justify-content: space-between;
            align-items: center;
            gap: 1rem;
            margin-bottom: 1.4rem;
            min-width: 0;
            flex-wrap: wrap;
        }

        .section-title h2 {
            min-width: 0;
        }

        h2 {
            font-family: var(--font-titles);
            color: var(--text-white);
            text-transform: uppercase;
            font-size: 1.35rem;
        }

        .steps {
            display: grid;
            gap: 0.9rem;
        }

        .step {
            display: grid;
            grid-template-columns: auto minmax(0, 1fr) auto;
            gap: 1rem;
            align-items: center;
            padding: 1rem;
            border-radius: 8px;
            background: rgba(255,255,255,0.03);
            border: 1px solid var(--border);
            transition: transform 0.22s ease, border-color 0.22s ease, background 0.22s ease;
        }

        .step.current {
            border-color: rgba(116, 201, 44, 0.5);
            background: rgba(116, 201, 44, 0.06);
            animation: softPulse 2.4s ease-in-out infinite;
        }

        .step:hover {
            transform: translateX(3px);
            border-color: rgba(116, 201, 44, 0.28);
            background: rgba(255, 255, 255, 0.045);
        }

        .step.done .icon {
            background: rgba(116, 201, 44, 0.16);
            color: var(--primary);
        }

        .icon {
            width: 44px;
            height: 44px;
            display: grid;
            place-items: center;
            border-radius: 8px;
            background: var(--bg-soft);
            color: var(--text-muted);
        }

        .step h3 {
            font-family: var(--font-titles);
            color: var(--text-white);
            text-transform: uppercase;
            font-size: 0.95rem;
            margin-bottom: 0.25rem;
        }

        .step p {
            color: var(--text-muted);
            font-size: 0.9rem;
        }

        .status {
            color: var(--primary);
            font-weight: 700;
            white-space: nowrap;
        }

        .cards {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(220px, 1fr));
            gap: 1rem;
            margin-top: 1.2rem;
            min-width: 0;
        }

        .cards[hidden] {
            display: none !important;
        }

        .metric {
            background: rgba(255,255,255,0.03);
            border: 1px solid var(--border);
            border-radius: 8px;
            padding: 1rem;
            min-width: 0;
        }

        .metric span {
            color: var(--text-muted);
            font-size: 0.78rem;
            text-transform: uppercase;
            font-weight: 700;
        }

        .metric strong {
            display: block;
            color: var(--text-white);
            font-family: var(--font-titles);
            font-size: 1.25rem;
            margin-top: 0.5rem;
            overflow-wrap: anywhere;
        }

        .metric strong.positive,
        .positive {
            color: var(--primary);
        }

        .metric strong.negative,
        .negative {
            color: var(--danger);
        }

        .neutral {
            color: var(--text-white);
        }

        .warning {
            color: var(--warning);
        }

        .notice {
            margin-bottom: 1rem;
            padding: 1rem;
            border-radius: 8px;
            border: 1px solid rgba(116, 201, 44, 0.3);
            background: rgba(116, 201, 44, 0.08);
            color: var(--text-light);
        }

        .notice.error {
            border-color: rgba(255, 69, 58, 0.35);
            background: rgba(255, 69, 58, 0.08);
            color: #FFD1CE;
        }

        .receipt {
            border-color: rgba(116, 201, 44, 0.5);
            margin-bottom: 1.5rem;
        }

        .receipt-code {
            margin-top: 0.8rem;
            padding: 0.85rem;
            border-radius: 6px;
            background: #0d0d0d;
            color: var(--primary);
            word-break: break-all;
            font-family: Consolas, monospace;
            font-size: 0.86rem;
        }

        .history {
            display: grid;
            gap: 0.75rem;
        }

        .history-row {
            display: grid;
            grid-template-columns: minmax(0, 1fr) auto;
            gap: 1rem;
            padding: 0.9rem 0;
            border-bottom: 1px solid var(--border);
        }

        .history-row:last-child { border-bottom: 0; }

        .history-row strong {
            color: var(--text-white);
            font-size: 0.92rem;
        }

        .history-row span,
        .history-row small {
            color: var(--text-muted);
        }

        .compliance-list {
            display: grid;
            gap: 0.8rem;
            margin-top: 1rem;
        }

        .compliance-list li {
            list-style: none;
            display: flex;
            gap: 0.7rem;
            color: var(--text-muted);
            line-height: 1.55;
        }

        .compliance-list i {
            color: var(--primary);
            margin-top: 0.2rem;
        }

        .camera-modal {
            position: fixed;
            inset: 0;
            z-index: 2000;
            display: none;
            align-items: center;
            justify-content: center;
            padding: 1rem;
            background: rgba(0, 0, 0, 0.78);
        }

        .camera-modal.show {
            display: flex;
        }

        .camera-box {
            width: min(560px, 100%);
            background: var(--bg-card);
            border: 1px solid var(--border);
            border-radius: 8px;
            padding: 1.25rem;
        }

        .camera-box video,
        .camera-box img {
            width: 100%;
            aspect-ratio: 4 / 3;
            object-fit: cover;
            border-radius: 8px;
            background: #050505;
            border: 1px solid var(--border);
            display: block;
            margin: 1rem 0;
        }

        .camera-actions {
            display: grid;
            grid-template-columns: repeat(3, 1fr);
            gap: 0.75rem;
        }

        .camera-help {
            color: var(--text-muted);
            font-size: 0.9rem;
            line-height: 1.55;
        }

        .location-status {
            margin-top: 0.8rem;
            padding: 0.75rem;
            border-radius: 6px;
            background: rgba(255,255,255,0.04);
            border: 1px solid var(--border);
            color: var(--text-muted);
            font-size: 0.88rem;
        }

        .location-status.ready {
            color: var(--primary);
            border-color: rgba(116, 201, 44, 0.35);
            background: rgba(116, 201, 44, 0.08);
        }

        .location-status.error {
            color: #FFD1CE;
            border-color: rgba(255, 69, 58, 0.35);
            background: rgba(255, 69, 58, 0.08);
        }

        .photo-proof {
            margin-top: 1rem;
            width: min(220px, 100%);
            border-radius: 8px;
            border: 1px solid var(--border);
            display: block;
        }

        .admin-layout {
            display: block;
            align-items: start;
            margin-top: 1.5rem;
            min-width: 0;
        }

        .admin-sidebar {
            position: fixed;
            top: 0;
            left: 0;
            z-index: 2100;
            width: min(330px, calc(100vw - 2rem));
            height: 100vh;
            margin: 0;
            overflow-y: auto;
            display: grid;
            align-content: start;
            gap: 0.35rem;
            min-width: 0;
            border-radius: 0 8px 8px 0;
            transform: translateX(-110%);
            transition: transform 0.24s ease, box-shadow 0.24s ease;
            box-shadow: 28px 0 70px rgba(0, 0, 0, 0.34);
        }

        body.admin-sidebar-open {
            overflow: hidden;
        }

        body.admin-sidebar-open .admin-sidebar {
            transform: translateX(0);
        }

        .admin-sidebar-backdrop {
            position: fixed;
            inset: 0;
            z-index: 2050;
            background: rgba(0, 0, 0, 0.58);
            opacity: 0;
            pointer-events: none;
            transition: opacity 0.22s ease;
        }

        body.admin-sidebar-open .admin-sidebar-backdrop {
            opacity: 1;
            pointer-events: auto;
        }

        .admin-menu-toggle {
            position: fixed;
            top: 1rem;
            left: 1rem;
            z-index: 2060;
            width: 3rem;
            height: 3rem;
            border-radius: 8px;
            border: 1px solid rgba(116, 201, 44, 0.34);
            background: rgba(10, 10, 10, 0.9);
            color: var(--primary);
            display: inline-flex;
            align-items: center;
            justify-content: center;
            cursor: pointer;
            box-shadow: 0 16px 40px rgba(0, 0, 0, 0.28);
            backdrop-filter: blur(14px);
        }

        .admin-menu-toggle:hover,
        .admin-menu-toggle:focus {
            background: rgba(116, 201, 44, 0.12);
            outline: none;
        }

        .admin-menu-toggle i {
            font-size: 1.25rem;
        }

        .admin-sidebar-close {
            justify-self: end;
            width: 2.35rem;
            height: 2.35rem;
            border-radius: 8px;
            border: 1px solid var(--border);
            background: rgba(255, 255, 255, 0.04);
            color: var(--text-white);
            cursor: pointer;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            margin-bottom: 0.35rem;
        }

        .admin-sidebar-close:hover,
        .admin-sidebar-close:focus {
            color: var(--primary);
            border-color: rgba(116, 201, 44, 0.4);
            outline: none;
        }

        .admin-sidebar-title {
            margin: 0.75rem 0 0.35rem;
            color: var(--text-muted);
            font-size: 0.74rem;
            font-weight: 800;
            letter-spacing: 0.08em;
            text-transform: uppercase;
        }

        .admin-menu-link {
            display: flex;
            align-items: center;
            gap: 0.75rem;
            padding: 0.75rem 0.85rem;
            border-radius: 6px;
            color: var(--text-light);
            text-decoration: none;
            border: 1px solid transparent;
        }

        .admin-menu-link:hover,
        .admin-menu-link.active {
            border-color: rgba(116, 201, 44, 0.28);
            background: rgba(116, 201, 44, 0.08);
            color: var(--primary);
        }

        .admin-content {
            display: grid;
            gap: 1.25rem;
            min-width: 0;
            overflow: hidden;
        }

        .admin-combined-tab {
            display: none;
            gap: 1.25rem;
            scroll-margin-top: 1.5rem;
        }

        .collapsible-panel.is-hidden .collapsible-content {
            display: none;
        }

        .collapsible-panel.is-hidden {
            padding-bottom: 1.25rem;
        }

        .admin-form-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(180px, 1fr));
            gap: 0.85rem;
            align-items: end;
            min-width: 0;
        }

        .field.wide {
            grid-column: span 2;
        }

        .field.full {
            grid-column: 1 / -1;
        }

        .manual-adjust-card {
            border-color: rgba(116, 201, 44, 0.28);
            background:
                radial-gradient(circle at 12% 0%, rgba(116, 201, 44, 0.12), transparent 18rem),
                linear-gradient(145deg, rgba(24, 24, 24, 0.98), rgba(17, 18, 17, 0.95));
        }

        .manual-adjust-layout {
            display: grid;
            grid-template-columns: minmax(0, 1.12fr) minmax(280px, 0.88fr);
            gap: 1rem;
            align-items: start;
        }

        .manual-adjust-form {
            display: grid;
            gap: 0.9rem;
        }

        .manual-adjust-file {
            display: grid;
            grid-template-columns: minmax(0, 1fr) auto;
            gap: 0.75rem;
            align-items: center;
            padding: 0.75rem;
            border: 1px dashed rgba(116, 201, 44, 0.34);
            border-radius: 8px;
            background: rgba(116, 201, 44, 0.06);
        }

        .manual-adjust-file input {
            min-width: 0;
            color: var(--text-light);
        }

        .manual-history {
            display: grid;
            gap: 0.75rem;
            max-height: 33rem;
            overflow: auto;
            padding-right: 0.25rem;
        }

        .manual-history-item {
            border: 1px solid var(--border);
            border-radius: 8px;
            padding: 0.85rem;
            background: rgba(255, 255, 255, 0.04);
        }

        .manual-history-item strong {
            display: block;
            margin-bottom: 0.35rem;
        }

        .manual-history-item a {
            color: var(--primary);
            text-decoration: none;
            font-weight: 800;
        }

        .field textarea {
            width: 100%;
            min-height: 90px;
            resize: vertical;
            padding: 0.8rem;
            border-radius: 4px;
            border: 1px solid var(--border);
            background: var(--bg-main);
            color: var(--text-white);
            font-family: var(--font-body);
        }

        .checkbox-row {
            display: flex;
            align-items: center;
            gap: 0.65rem;
            min-height: 44px;
            color: var(--text-light);
        }

        .checkbox-row input {
            width: 18px;
            height: 18px;
            accent-color: var(--primary);
        }

        .status-pill {
            display: inline-flex;
            align-items: center;
            gap: 0.35rem;
            padding: 0.3rem 0.5rem;
            border-radius: 999px;
            background: rgba(255, 255, 255, 0.06);
            color: var(--text-muted);
            font-size: 0.75rem;
            font-weight: 700;
        }

        .status-pill.locked {
            background: rgba(255, 69, 58, 0.12);
            color: #FFD1CE;
        }

        .admin-filters {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(150px, 1fr));
            gap: 0.75rem;
            align-items: end;
            margin-bottom: 1.25rem;
            min-width: 0;
        }

        .field label {
            display: block;
            margin-bottom: 0.35rem;
            color: var(--text-muted);
            font-size: 0.78rem;
            font-weight: 700;
            text-transform: uppercase;
        }

        .field {
            min-width: 0;
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

        .admin-table-wrap {
            width: 100%;
            max-width: 100%;
            overflow-x: auto;
            overflow-y: hidden;
        }

        .admin-table {
            width: 100%;
            min-width: 920px;
            border-collapse: collapse;
            font-size: 0.88rem;
        }

        .admin-table th,
        .admin-table td {
            padding: 0.75rem;
            border-bottom: 1px solid var(--border);
            text-align: left;
            vertical-align: top;
        }

        .admin-table th {
            color: var(--text-white);
            font-family: var(--font-titles);
            font-size: 0.75rem;
            text-transform: uppercase;
        }

        .inline-edit {
            display: grid;
            grid-template-columns: minmax(120px, 140px) minmax(155px, 180px) auto;
            gap: 0.5rem;
            min-width: 0;
        }

        .inline-review {
            display: grid;
            gap: 0.55rem;
            min-width: 220px;
        }

        .inline-review textarea {
            width: 100%;
            min-height: 74px;
            resize: vertical;
            padding: 0.75rem;
            border-radius: 4px;
            border: 1px solid var(--border);
            background: var(--bg-main);
            color: var(--text-white);
            font-family: var(--font-body);
        }

        .review-actions {
            display: flex;
            gap: 0.5rem;
            flex-wrap: wrap;
        }

        input,
        select,
        textarea {
            transition: border-color 0.2s ease, box-shadow 0.2s ease, background 0.2s ease;
        }

        input:focus,
        select:focus,
        textarea:focus {
            border-color: rgba(116, 201, 44, 0.58);
            box-shadow: 0 0 0 3px rgba(116, 201, 44, 0.12);
            outline: none;
        }

        .metric,
        .notice {
            transition: transform 0.22s ease, border-color 0.22s ease, background 0.22s ease;
        }

        .metric:hover,
        .notice:hover {
            transform: translateY(-2px);
            border-color: rgba(116, 201, 44, 0.22);
        }

        .admin-table tbody tr {
            transition: background 0.18s ease, transform 0.18s ease;
        }

        .admin-table tbody tr:hover {
            background: rgba(116, 201, 44, 0.045);
        }

        .camera-modal.show .camera-box {
            animation: modalIn 0.28s ease both;
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

        @media (max-width: 900px) {
            body { padding: 1rem; }
            .hero,
            .grid {
                grid-template-columns: 1fr;
            }
            .topbar,
            .section-title {
                align-items: flex-start;
                flex-direction: column;
            }
            .cards {
                grid-template-columns: 1fr;
            }
        }

        @media (max-width: 560px) {
            .panel { padding: 1.2rem; }
            .manual-adjust-layout,
            .manual-adjust-file {
                grid-template-columns: 1fr;
            }
            .step {
                grid-template-columns: auto minmax(0, 1fr);
            }
            .step form,
            .step .status {
                grid-column: 1 / -1;
            }
            .btn {
                width: 100%;
            }
            .camera-actions {
                grid-template-columns: 1fr;
            }
            .admin-filters,
            .admin-form-grid,
            .inline-edit,
            .review-actions {
                grid-template-columns: 1fr;
            }
            .field.wide {
                grid-column: auto;
            }
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

        .camera-modal {
            z-index: 2000;
        }

        .panel,
        .step,
        .metric,
        .notice {
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
    <button class="admin-menu-toggle" type="button" id="adminMenuToggle" aria-label="Abrir menu" aria-controls="adminSidebar" aria-expanded="false">
        <i class="fa-solid fa-bars"></i>
    </button>
    <div class="admin-sidebar-backdrop" id="adminSidebarBackdrop" aria-hidden="true"></div>
    <?php if (!$podeAdministrar): ?>
        <aside class="panel admin-sidebar" id="adminSidebar" aria-label="Menu do funcionário">
            <button class="admin-sidebar-close" type="button" id="adminSidebarClose" aria-label="Fechar menu">
                <i class="fa-solid fa-xmark"></i>
            </button>
            <div class="admin-sidebar-title">Programas internos</div>
            <a class="admin-menu-link active" href="programas-funcionarios"><i class="fa-solid fa-laptop-code"></i> Fiscal e Contábil</a>
        </aside>
    <?php endif; ?>
    <div class="shell">
        <header class="topbar">
            <a class="brand" href="/" aria-label="Voltar para o site">
                <img src="logo-branca.png" alt="ACCOUNT Contabilidade">
            </a>
            <div class="top-actions">
                <a class="btn btn-outline" href="painel?export=csv"><i class="fa-solid fa-file-arrow-down"></i> Meu CSV</a>
                <a class="btn btn-outline" href="gerar-pdf-ponto.php?export=pdf&mes=<?php echo h(date('Y-m')); ?>" target="_blank" rel="noopener"><i class="fa-solid fa-file-pdf"></i> Meu PDF</a>
                <?php if ($podeAdministrar): ?>
                    <a class="btn btn-outline" href="gerenciar-funcionarios"><i class="fa-solid fa-users-gear"></i> Funcionários</a>
                    <a class="btn btn-outline" href="painel?export=csv&scope=all"><i class="fa-solid fa-file-csv"></i> CSV geral</a>
                <?php endif; ?>
                <a class="btn btn-outline" href="/"><i class="fa-solid fa-house"></i> Site</a>
                <button class="btn btn-outline" type="button" onclick="sair()"><i class="fa-solid fa-arrow-right-from-bracket"></i> Sair</button>
            </div>
        </header>

        <section class="hero">
            <div class="panel welcome">
                <span class="badge"><i class="fa-solid fa-shield-halved"></i> Registro eletrônico de ponto</span>
                <h1>Olá, <?php echo $usuario; ?></h1>
                <p>Use esta página para registrar sua jornada com horário oficial do servidor, comprovante por marcação e histórico auditável. As marcações seguem a ordem da jornada para reduzir erros e preservar o espelho de ponto.</p>
                <?php if ($email !== ''): ?>
                    <p class="muted">E-mail cadastrado: <?php echo $email; ?></p>
                <?php endif; ?>
                <?php if ($empresaNome !== '' || $empresaCnpj !== '' || $cpf !== ''): ?>
                    <p class="muted">
                        Empresa: <?php echo $empresaNome !== '' ? $empresaNome : 'Não informada'; ?>
                        <?php if ($empresaCnpj !== ''): ?> · CNPJ: <?php echo $empresaCnpj; ?><?php endif; ?>
                        <?php if ($cpf !== ''): ?> · CPF: <?php echo $cpf; ?><?php endif; ?>
                    </p>
                <?php endif; ?>
                <?php if ($cargo !== ''): ?>
                    <p class="muted">Cargo: <?php echo $cargo; ?> · Nível de acesso: <?php echo h((string) $nivelAcesso); ?></p>
                <?php endif; ?>
            </div>

            <aside class="panel clock" aria-label="Relógio do servidor">
                <small>Horário de Brasília</small>
                <strong id="clockNow"><?php echo h((new DateTimeImmutable('now'))->format('H:i:s')); ?></strong>
                <span class="muted"><?php echo h((new DateTimeImmutable('now'))->format('d/m/Y')); ?> · America/Sao_Paulo</span>
            </aside>
        </section>

        <?php if ($erro !== ''): ?>
            <div class="notice error"><?php echo h($erro); ?></div>
        <?php endif; ?>

        <?php if ($sucesso !== ''): ?>
            <div class="notice"><?php echo h($sucesso); ?></div>
        <?php endif; ?>

        <?php if (!empty($afastamentoBloqueioAtual)): ?>
            <div class="notice error"><?php echo h(mensagemBloqueioAfastamento($afastamentoBloqueioAtual)); ?></div>
        <?php endif; ?>


        <?php if ($comprovante): ?>
            <section class="panel receipt">
                <div class="section-title">
                    <h2>Comprovante gerado</h2>
                    <span class="status">Registro #<?php echo h((string) $comprovante['id']); ?></span>
                </div>
                <p class="muted">
                    <?php echo h($tiposPonto[$comprovante['tipo']]['rotulo']); ?> em
                    <strong><?php echo h(formatarDataHora($comprovante['marcado_em'])); ?></strong>
                    (<?php echo h($comprovante['timezone']); ?>).
                </p>
                <div class="receipt-code"><?php echo h($comprovante['hash_comprovante']); ?></div>
                <?php if (!empty($comprovante['foto_comprovante'])): ?>
                    <img class="photo-proof" src="<?php echo h($comprovante['foto_comprovante']); ?>" alt="Foto capturada no registro de ponto">
                <?php endif; ?>
                <?php if ($comprovante['latitude'] !== null && $comprovante['longitude'] !== null): ?>
                    <p class="muted" style="margin-top: 0.8rem;">
                        Localização informada pelo navegador:
                        <?php echo h((string) $comprovante['latitude']); ?>,
                        <?php echo h((string) $comprovante['longitude']); ?>
                        <?php if ($comprovante['precisao_metros'] !== null): ?>
                            · precisão aprox. <?php echo h((string) $comprovante['precisao_metros']); ?> m
                        <?php endif; ?>
                    </p>
                <?php endif; ?>
            </section>
        <?php endif; ?>

        <?php if ($permitePonto): ?>
        <main class="grid">
            <section class="panel">
                <div class="section-title">
                    <h2>Jornada de hoje</h2>
                    <span class="muted"><?php echo h((new DateTimeImmutable($hoje))->format('d/m/Y')); ?></span>
                </div>
                <p class="muted" style="margin-bottom: 1rem;">
                    <?php if ($ehEstagiarioLogado): ?>
                        Regra da empresa para estagiário: segunda a sexta de 12:45 às 18:00, com 15min de lanche.
                    <?php else: ?>
                        Regra da empresa: segunda a quinta de 08:00 às 18:00 e sexta de 08:00 às 17:00, com 1h de almoço e 15min de lanche.
                    <?php endif; ?>
                </p>
                <?php if (!empty($avisosPontoHoje)): ?>
                    <div class="notice error" style="margin-bottom: 1rem;">
                        <?php echo h(implode(' ', $avisosPontoHoje)); ?>
                    </div>
                <?php endif; ?>

                <div class="steps">
                    <?php foreach ($tiposPontoPerfil as $tipo => $dados): ?>
                        <?php
                            $registro = $porTipo[$tipo] ?? null;
                            $feito = $registro !== null;
                            $atual = $tipo === $proximoTipo;
                            $classe = trim(($feito ? 'done ' : '') . ($atual ? 'current' : ''));
                        ?>
                        <article class="step <?php echo h($classe); ?>">
                            <div class="icon"><i class="fa-solid <?php echo h($dados['icone']); ?>"></i></div>
                            <div>
                                <h3><?php echo h($dados['rotulo']); ?></h3>
                                <p><?php echo $feito ? h(formatarDataHora($registro['marcado_em'])) : 'Aguardando marcação'; ?></p>
                            </div>
                            <?php if ($feito): ?>
                                <span class="status"><i class="fa-solid fa-check"></i> Registrado</span>
                            <?php else: ?>
                                <form method="post" class="point-form">
                                    <input type="hidden" name="csrf_ponto" value="<?php echo $csrf; ?>">
                                    <input type="hidden" name="tipo_ponto" value="<?php echo h($tipo); ?>">
                                    <input type="hidden" name="foto_ponto" value="">
                                    <input type="hidden" name="latitude" value="">
                                    <input type="hidden" name="longitude" value="">
                                    <input type="hidden" name="precisao_metros" value="">
                                    <button class="btn" type="submit" <?php echo $atual && empty($afastamentoBloqueioAtual) ? '' : 'disabled'; ?>>
                                        <i class="fa-solid fa-fingerprint"></i> <?php echo h($dados['acao']); ?>
                                    </button>
                                </form>
                            <?php endif; ?>
                        </article>
                    <?php endforeach; ?>
                </div>

                <div class="cards">
                    <div class="metric">
                        <span>Trabalhado hoje</span>
                        <strong><?php echo h(formatarSegundos($segundosHoje)); ?></strong>
                    </div>
                    <div class="metric">
                        <span>Meta diária</span>
                        <strong><?php echo h(textoMetaJornada($hoje, $cargoRaw)); ?></strong>
                    </div>
                    <div class="metric">
                        <span>Saldo hoje</span>
                        <strong class="<?php echo h(classeSaldo($saldoHoje)); ?>"><?php echo h(formatarSaldoSegundos($saldoHoje)); ?></strong>
                    </div>
                    <div class="metric">
                        <span>Período manhã</span>
                        <strong><?php echo h($tempoManha); ?></strong>
                    </div>
                    <div class="metric">
                        <span>Período tarde</span>
                        <strong><?php echo h($tempoTarde1); ?></strong>
                    </div>
                    <div class="metric">
                        <span>Pós-lanche</span>
                        <strong><?php echo h($tempoTarde2); ?></strong>
                    </div>
                    <div class="metric">
                        <span>Crédito mensal <?php echo h($rotuloMesAtual); ?></span>
                        <strong class="positive">+<?php echo h(formatarSegundos($creditoMensalSegundos)); ?></strong>
                    </div>
                    <div class="metric">
                        <span>Débito mensal <?php echo h($rotuloMesAtual); ?></span>
                        <strong class="negative">-<?php echo h(formatarSegundos($debitoMensalSegundos)); ?></strong>
                    </div>
                    <div class="metric">
                        <span>Saldo mensal <?php echo h($rotuloMesAtual); ?></span>
                        <strong class="<?php echo h(classeSaldo($saldoMensalSegundos)); ?>"><?php echo h(formatarSaldoSegundos($saldoMensalSegundos)); ?></strong>
                    </div>
                </div>
            </section>

            <aside>
                <section class="panel">
                    <div class="section-title">
                        <h2>Conformidade</h2>
                    </div>
                    <p class="muted">Recursos incluídos para apoiar as exigências brasileiras de controle eletrônico de jornada.</p>
                    <ul class="compliance-list">
                        <li><i class="fa-solid fa-check"></i> Marcação feita pelo funcionário autenticado, sem pré-preenchimento automático.</li>
                        <li><i class="fa-solid fa-check"></i> Horário gravado no servidor com fuso America/Sao_Paulo.</li>
                        <li><i class="fa-solid fa-check"></i> Comprovante imediato com identificador e hash SHA-256.</li>
                        <li><i class="fa-solid fa-check"></i> Histórico exportável para conferência e guarda fiscal por pelo menos 5 anos.</li>
                        <li><i class="fa-solid fa-check"></i> Registros preservados sem edição pela tela do usuário.</li>
                    </ul>
                </section>

                <section class="panel" style="margin-top: 1.5rem;">
                    <div class="section-title">
                        <h2>Últimos registros</h2>
                    </div>
                    <div class="history">
                        <?php if (empty($historico)): ?>
                            <p class="muted">Nenhum ponto registrado ainda.</p>
                        <?php endif; ?>
                        <?php foreach ($historico as $linha): ?>
                            <div class="history-row">
                                <div>
                                    <strong><?php echo h($tiposPonto[$linha['tipo']]['rotulo'] ?? $linha['tipo']); ?></strong><br>
                                    <span><?php echo h(formatarDataHora($linha['marcado_em'])); ?></span>
                                </div>
                                <small>#<?php echo h((string) $linha['id']); ?></small>
                            </div>
                        <?php endforeach; ?>
                    </div>
                </section>

                <section class="panel" style="margin-top: 1.5rem;" id="solicitar-ajuste">
                    <div class="section-title">
                        <h2>Solicitar ajuste</h2>
                    </div>
                    <p class="muted">Use quando esquecer uma marcação. A solicitação será analisada por um operador nível 3 antes de alterar o ponto.</p>
                    <form class="admin-form-grid" method="post">
                        <input type="hidden" name="acao" value="solicitar_ajuste">
                        <input type="hidden" name="csrf_ponto" value="<?php echo $csrf; ?>">
                        <div class="field">
                            <label for="tipo_ajuste">Tipo de ponto</label>
                            <select id="tipo_ajuste" name="tipo_ponto" required>
                                <?php foreach ($tiposPonto as $tipoAjuste => $dadosAjuste): ?>
                                    <option value="<?php echo h($tipoAjuste); ?>"><?php echo h($dadosAjuste['rotulo']); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="field">
                            <label for="horario_solicitado">Data e hora</label>
                            <input id="horario_solicitado" name="horario_solicitado" type="datetime-local" value="<?php echo h((new DateTimeImmutable('now'))->format('Y-m-d\TH:i')); ?>" required>
                        </div>
                        <div class="field full">
                            <label for="justificativa">Justificativa</label>
                            <textarea id="justificativa" name="justificativa" rows="4" maxlength="1000" placeholder="Ex.: Cheguei às 08:02, mas esqueci de bater o ponto de chegada." required></textarea>
                        </div>
                        <button class="btn" type="submit"><i class="fa-solid fa-paper-plane"></i> Enviar solicitação</button>
                    </form>

                    <div class="history" style="margin-top: 1rem;">
                        <?php if (empty($solicitacoesUsuario)): ?>
                            <p class="muted">Nenhuma solicitação enviada ainda.</p>
                        <?php endif; ?>
                        <?php foreach ($solicitacoesUsuario as $solicitacaoUsuario): ?>
                            <?php $statusSolicitacao = (string) $solicitacaoUsuario['status']; ?>
                            <div class="history-row">
                                <div>
                                    <strong><?php echo h($tiposPonto[$solicitacaoUsuario['tipo_solicitado']]['rotulo'] ?? $solicitacaoUsuario['tipo_solicitado']); ?></strong><br>
                                    <span><?php echo h(formatarDataHora($solicitacaoUsuario['horario_solicitado'])); ?></span><br>
                                    <span class="muted"><?php echo h($solicitacaoUsuario['justificativa']); ?></span>
                                    <?php if (!empty($solicitacaoUsuario['parecer_admin'])): ?>
                                        <br><span class="muted">Parecer: <?php echo h($solicitacaoUsuario['parecer_admin']); ?></span>
                                    <?php endif; ?>
                                </div>
                                <small class="<?php echo h(classeStatusSolicitacao($statusSolicitacao)); ?>">
                                    <?php echo h(rotuloStatusSolicitacao($statusSolicitacao)); ?>
                                </small>
                            </div>
                        <?php endforeach; ?>
                    </div>
                </section>
            </aside>
        </main>
        <?php else: ?>
            <section class="panel">
                <div class="section-title">
                    <h2>Perfil administrativo</h2>
                </div>
                <p class="muted">Seu perfil não registra ponto. Use a área administrativa abaixo para consultar, filtrar, alterar e exportar os registros da equipe.</p>
            </section>
        <?php endif; ?>

        <?php if ($podeAdministrar): ?>
            <?php
                $queryBase = [
                    'scope' => 'all',
                    'empresa_nome' => $_GET['empresa_nome'] ?? '',
                    'funcionario_id' => $_GET['funcionario_id'] ?? '',
                    'data_inicio' => $dataInicioFiltro,
                    'data_fim' => $dataFimFiltro,
                ];
                $csvAdminUrl = 'painel?export=csv&' . http_build_query($queryBase);
                $pdfQueryBase = [
                    'export' => 'pdf',
                    'scope' => 'all',
                    'funcionario_id' => $_GET['funcionario_id'] ?? '',
                    'data_inicio' => $dataInicioFiltro,
                    'data_fim' => $dataFimFiltro,
                ];
                $pdfAdminUrl = 'gerar-pdf-ponto.php?' . http_build_query($pdfQueryBase);
            ?>
            <div class="admin-layout">
                <aside class="panel admin-sidebar" id="adminSidebar" aria-label="Menu administrativo">
                    <button class="admin-sidebar-close" type="button" id="adminSidebarClose" aria-label="Fechar menu administrativo">
                        <i class="fa-solid fa-xmark"></i>
                    </button>
                    <a class="admin-menu-link active" href="#painel-geral"><i class="fa-solid fa-chart-column"></i> Dashboard</a>
                    <div class="admin-sidebar-title">Gestão de ponto</div>
                    <a class="admin-menu-link" href="#solicitacoes-ajuste-admin"><i class="fa-solid fa-clipboard-check"></i> Solicitações</a>
                    <a class="admin-menu-link" href="apuracao-ponto"><i class="fa-regular fa-clock"></i> Apuração de ponto</a>
                    <a class="admin-menu-link" href="banco-horas"><i class="fa-solid fa-scale-balanced"></i> Banco de Horas</a>
                    <a class="admin-menu-link" href="afastamentos"><i class="fa-regular fa-calendar-xmark"></i> Afastamentos</a>
                    <a class="admin-menu-link" href="tipos-afastamentos"><i class="fa-solid fa-sliders"></i> Tipos de afastamento</a>
                    <div class="admin-sidebar-title">Programas internos</div>
                    <a class="admin-menu-link" href="programas-funcionarios"><i class="fa-solid fa-laptop-code"></i> Fiscal e Contábil</a>
                    <div class="admin-sidebar-title">Relatórios</div>
                    <a class="admin-menu-link" href="historico-espelho"><i class="fa-solid fa-clock-rotate-left"></i> Histórico de espelho</a>
                    <a class="admin-menu-link" href="historico-download"><i class="fa-solid fa-download"></i> Histórico de download</a>
                    <a class="admin-menu-link" href="<?php echo h($csvAdminUrl); ?>"><i class="fa-solid fa-file-csv"></i> Exportar CSV</a>
                    <a class="admin-menu-link" href="<?php echo h($pdfAdminUrl); ?>" target="_blank" rel="noopener"><i class="fa-solid fa-file-pdf"></i> Exportar PDF</a>
                </aside>

                <div class="admin-content">
            <section class="panel collapsible-panel" id="solicitacoes-ajuste-admin">
                <div class="section-title">
                    <div>
                        <h2>Solicitações de ajuste</h2>
                        <span class="muted">Pedidos enviados pelos funcionários para revisão do ponto.</span>
                    </div>
                    <button class="btn btn-outline" type="button" id="toggleSolicitacoesAjuste" aria-controls="solicitacoesAjusteConteudo" aria-expanded="true">
                        <i class="fa-solid fa-eye-slash"></i> Ocultar solicitações
                    </button>
                </div>

                <div class="admin-table-wrap collapsible-content" id="solicitacoesAjusteConteudo">
                    <table class="admin-table">
                        <thead>
                            <tr>
                                <th>Status</th>
                                <th>Funcionário</th>
                                <th>Empresa</th>
                                <th>Ponto solicitado</th>
                                <th>Justificativa</th>
                                <th>Avaliação</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (empty($solicitacoesAjusteAdmin)): ?>
                                <tr>
                                    <td colspan="6">Nenhuma solicitação de ajuste encontrada.</td>
                                </tr>
                            <?php endif; ?>
                            <?php foreach ($solicitacoesAjusteAdmin as $solicitacaoAdmin): ?>
                                <?php
                                    $statusAdmin = (string) $solicitacaoAdmin['status'];
                                    $pendente = $statusAdmin === 'pendente';
                                ?>
                                <tr>
                                    <td>
                                        <span class="<?php echo h(classeStatusSolicitacao($statusAdmin)); ?>">
                                            <?php echo h(rotuloStatusSolicitacao($statusAdmin)); ?>
                                        </span><br>
                                        <span class="muted">#<?php echo h((string) $solicitacaoAdmin['id']); ?></span>
                                    </td>
                                    <td>
                                        <?php echo h(nomeExibicao($solicitacaoAdmin['usuario'])); ?><br>
                                        <span class="muted"><?php echo h($solicitacaoAdmin['cpf'] ?? ''); ?></span>
                                    </td>
                                    <td>
                                        <?php echo h($solicitacaoAdmin['empresa_nome'] ?? ''); ?><br>
                                        <span class="muted"><?php echo h($solicitacaoAdmin['empresa_cnpj'] ?? ''); ?></span>
                                    </td>
                                    <td>
                                        <?php echo h($tiposPonto[$solicitacaoAdmin['tipo_solicitado']]['rotulo'] ?? $solicitacaoAdmin['tipo_solicitado']); ?><br>
                                        <span class="muted"><?php echo h(formatarDataHora($solicitacaoAdmin['horario_solicitado'])); ?></span>
                                    </td>
                                    <td>
                                        <?php echo h($solicitacaoAdmin['justificativa']); ?><br>
                                        <span class="muted">Enviado em <?php echo h(formatarDataHora($solicitacaoAdmin['criado_em'])); ?></span>
                                        <?php if (!$pendente && !empty($solicitacaoAdmin['parecer_admin'])): ?>
                                            <br><span class="muted">Parecer: <?php echo h($solicitacaoAdmin['parecer_admin']); ?></span>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <?php if ($pendente): ?>
                                            <form method="post" class="inline-review">
                                                <input type="hidden" name="acao" value="avaliar_ajuste">
                                                <input type="hidden" name="csrf_ponto" value="<?php echo $csrf; ?>">
                                                <input type="hidden" name="solicitacao_id" value="<?php echo h((string) $solicitacaoAdmin['id']); ?>">
                                                <textarea name="parecer_admin" rows="2" maxlength="1000" placeholder="Parecer opcional"></textarea>
                                                <div class="review-actions">
                                                    <button class="btn btn-outline" type="submit" name="decisao" value="aprovar"><i class="fa-solid fa-check"></i> Aprovar</button>
                                                    <button class="btn btn-danger" type="submit" name="decisao" value="recusar"><i class="fa-solid fa-xmark"></i> Recusar</button>
                                                </div>
                                            </form>
                                        <?php else: ?>
                                            <span class="muted">
                                                Avaliado por <?php echo h(nomeExibicao($solicitacaoAdmin['avaliador_usuario'] ?? '')); ?><br>
                                                <?php echo h(formatarDataHora($solicitacaoAdmin['avaliado_em'])); ?>
                                            </span>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </section>

            <div class="admin-combined-tab" id="apuracao-ajuste-admin">
            <section class="panel manual-adjust-card" id="ajuste-manual-admin">
                <div class="section-title">
                    <div>
                        <h2>Ajuste manual de ponto</h2>
                        <span class="muted">Operadores nível 3 podem criar ou alterar uma batida com motivo, justificativa e documento.</span>
                    </div>
                    <a class="btn btn-outline" href="#painel-geral"><i class="fa-regular fa-clock"></i> Ver apuração</a>
                </div>

                <div class="manual-adjust-layout">
                    <form class="manual-adjust-form" method="post" enctype="multipart/form-data">
                        <input type="hidden" name="acao" value="ajuste_manual_ponto">
                        <input type="hidden" name="csrf_ponto" value="<?php echo $csrf; ?>">

                        <div class="admin-form-grid">
                            <div class="field wide">
                                <label for="ajuste_funcionario_id">Funcionário</label>
                                <select id="ajuste_funcionario_id" name="funcionario_id" required>
                                    <option value="">Selecione uma opção</option>
                                    <?php foreach ($funcionariosAdmin as $funcionarioOpcao): ?>
                                        <?php if ((int) ($funcionarioOpcao['ativo'] ?? 1) === 1): ?>
                                            <option value="<?php echo h((string) $funcionarioOpcao['id']); ?>">
                                                <?php echo h(($funcionarioOpcao['empresa_nome'] ?? 'Sem empresa') . ' · ' . nomeExibicao($funcionarioOpcao['usuario']) . ' · ' . ($funcionarioOpcao['cargo'] ?? 'Sem cargo')); ?>
                                            </option>
                                        <?php endif; ?>
                                    <?php endforeach; ?>
                                </select>
                            </div>

                            <div class="field">
                                <label for="ajuste_tipo_ponto">Tipo de ponto</label>
                                <select id="ajuste_tipo_ponto" name="tipo_ponto" required>
                                    <option value="">Selecione uma opção</option>
                                    <?php foreach ($tiposPonto as $tipoOpcao => $dadosOpcao): ?>
                                        <option value="<?php echo h($tipoOpcao); ?>"><?php echo h($dadosOpcao['rotulo']); ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>

                            <div class="field">
                                <label for="ajuste_horario">Data e hora</label>
                                <input id="ajuste_horario" name="horario_ajustado" type="datetime-local" value="<?php echo h((new DateTimeImmutable('now'))->format('Y-m-d\TH:i')); ?>" required>
                            </div>

                            <div class="field">
                                <label for="ajuste_motivo">Motivo</label>
                                <select id="ajuste_motivo" name="motivo" required>
                                    <option value="">Selecione uma opção</option>
                                    <?php foreach ($motivosAjusteManual as $motivoAjuste): ?>
                                        <option value="<?php echo h($motivoAjuste); ?>"><?php echo h($motivoAjuste); ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>

                            <div class="field">
                                <label for="ajuste_tipo_documento">Tipo do documento</label>
                                <select id="ajuste_tipo_documento" name="tipo_documento">
                                    <option value="">Selecione uma opção</option>
                                    <?php foreach ($tiposDocumentoAjusteManual as $tipoDocumentoAjuste): ?>
                                        <option value="<?php echo h($tipoDocumentoAjuste); ?>"><?php echo h($tipoDocumentoAjuste); ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>

                            <div class="field full">
                                <label for="ajuste_observacoes">Justificativa / observações</label>
                                <textarea id="ajuste_observacoes" name="observacoes" rows="4" maxlength="1000" placeholder="Descreva o motivo do ajuste manual." required></textarea>
                            </div>
                        </div>

                        <div class="manual-adjust-file">
                            <div>
                                <strong>Documento do ajuste</strong><br>
                                <span class="muted">PDF, JPG, PNG, WEBP, DOC ou DOCX até 8 MB.</span>
                            </div>
                            <input type="file" name="documento_ajuste" accept=".pdf,.jpg,.jpeg,.png,.webp,.doc,.docx">
                        </div>

                        <div class="top-actions">
                            <button class="btn" type="submit"><i class="fa-solid fa-floppy-disk"></i> Salvar ajuste manual</button>
                            <a class="btn btn-outline" href="#painel-geral"><i class="fa-solid fa-table-list"></i> Conferir registros</a>
                        </div>
                    </form>

                    <div>
                        <div class="section-title" style="margin-bottom: 0.75rem;">
                            <h2>Histórico manual</h2>
                            <span class="muted">Últimos 30 ajustes</span>
                        </div>
                        <div class="manual-history">
                            <?php if (empty($ajustesManuaisAdmin)): ?>
                                <div class="manual-history-item">
                                    <strong>Nenhum ajuste manual registrado.</strong>
                                    <span class="muted">Os ajustes feitos por operadores nível 3 aparecerão aqui.</span>
                                </div>
                            <?php endif; ?>

                            <?php foreach ($ajustesManuaisAdmin as $ajusteManual): ?>
                                <article class="manual-history-item">
                                    <strong><?php echo h(nomeExibicao($ajusteManual['funcionario_nome'] ?? '')); ?></strong>
                                    <span class="muted">
                                        <?php echo h($ajusteManual['empresa_nome'] ?? ''); ?><br>
                                        <?php echo h($tiposPonto[$ajusteManual['tipo_ponto']]['rotulo'] ?? $ajusteManual['tipo_ponto']); ?>
                                        · <?php echo h(formatarDataHora($ajusteManual['horario_ajustado'])); ?>
                                        · <?php echo h($ajusteManual['acao'] === 'criado' ? 'Criado' : 'Alterado'); ?>
                                    </span>
                                    <p class="muted" style="margin: 0.45rem 0 0;">
                                        Motivo: <?php echo h($ajusteManual['motivo']); ?><br>
                                        Justificativa: <?php echo h($ajusteManual['observacoes']); ?><br>
                                        Operador: <?php echo h(nomeExibicao($ajusteManual['operador_nome'] ?? '')); ?>
                                        <?php if (!empty($ajusteManual['documento_caminho'])): ?>
                                            <br>Documento: <a href="<?php echo h($ajusteManual['documento_caminho']); ?>" target="_blank" rel="noopener"><?php echo h($ajusteManual['documento_nome'] ?? 'Abrir arquivo'); ?></a>
                                        <?php endif; ?>
                                    </p>
                                </article>
                            <?php endforeach; ?>
                        </div>
                    </div>
                </div>
            </section>

            <section class="panel" id="painel-geral">
                <div class="section-title">
                    <h2>Painel geral de ponto</h2>
                    <div class="top-actions">
                        <a class="btn btn-outline" href="<?php echo h($csvAdminUrl); ?>"><i class="fa-solid fa-file-csv"></i> Exportar CSV</a>
                        <a class="btn btn-outline" href="<?php echo h($pdfAdminUrl); ?>" target="_blank" rel="noopener"><i class="fa-solid fa-file-pdf"></i> Exportar PDF</a>
                    </div>
                </div>

                <form class="admin-filters" method="get">
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
                        <label for="funcionario_id">Funcionário</label>
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
                        <input id="data_inicio" name="data_inicio" type="date" value="<?php echo h($dataInicioFiltro); ?>">
                    </div>
                    <div class="field">
                        <label for="data_fim">Até</label>
                        <input id="data_fim" name="data_fim" type="date" value="<?php echo h($dataFimFiltro); ?>">
                    </div>
                    <button class="btn" type="submit"><i class="fa-solid fa-filter"></i> Filtrar</button>
                </form>

                <?php if (!empty($saldosAdmin)): ?>
                    <div class="section-title" style="margin: 1rem 0;">
                        <h2>Banco de horas da equipe</h2>
                        <button class="btn btn-outline" type="button" id="toggleBancoHoras" aria-controls="banco-horas" aria-expanded="false">
                            <i class="fa-solid fa-eye"></i> Mostrar saldos
                        </button>
                    </div>
                    <div class="cards" id="banco-horas" style="margin-bottom: 1.25rem;" hidden>
                        <?php foreach ($saldosAdmin as $saldoAdmin): ?>
                            <div class="metric">
                                <span><?php echo h(($saldoAdmin['empresa_nome'] ?? 'Sem empresa') . ' · ' . nomeExibicao($saldoAdmin['usuario'])); ?></span>
                                <strong class="<?php echo h(classeSaldo($saldoAdmin['saldo'])); ?>"><?php echo h(formatarSaldoSegundos($saldoAdmin['saldo'])); ?></strong>
                                <p class="muted">
                                    CNPJ: <?php echo h($saldoAdmin['empresa_cnpj'] ?? ''); ?> · CPF: <?php echo h($saldoAdmin['cpf'] ?? ''); ?><br>
                                    Credor: +<?php echo h(formatarSegundos(max(0, (int) $saldoAdmin['saldo']))); ?> ·
                                    Devedor: -<?php echo h(formatarSegundos(max(0, -(int) $saldoAdmin['saldo']))); ?><br>
                                    <?php echo h(formatarSegundos($saldoAdmin['trabalhado'])); ?> trabalhadas em <?php echo h((string) $saldoAdmin['dias']); ?> dia(s)
                                </p>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>

                <div class="admin-table-wrap">
                    <table class="admin-table">
                        <thead>
                            <tr>
                                <th>ID</th>
                                <th>Empresa</th>
                                <th>Funcionário</th>
                                <th>CPF</th>
                                <th>Cargo</th>
                                <th>Ponto</th>
                                <th>Localização</th>
                                <th>Foto</th>
                                <th>Alterar</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (empty($registrosAdmin)): ?>
                                <tr>
                                    <td colspan="9">Nenhum registro encontrado para os filtros selecionados.</td>
                                </tr>
                            <?php endif; ?>
                            <?php foreach ($registrosAdmin as $registroAdmin): ?>
                                <tr>
                                    <td>#<?php echo h((string) $registroAdmin['id']); ?></td>
                                    <td><?php echo h($registroAdmin['empresa_nome'] ?? ''); ?><br><span class="muted"><?php echo h($registroAdmin['empresa_cnpj'] ?? ''); ?></span></td>
                                    <td><?php echo h(nomeExibicao($registroAdmin['usuario'])); ?><br><span class="muted"><?php echo h($registroAdmin['email']); ?></span></td>
                                    <td><?php echo h($registroAdmin['cpf'] ?? ''); ?></td>
                                    <td><?php echo h($registroAdmin['cargo'] ?? ''); ?></td>
                                    <td>
                                        <?php echo h($tiposPonto[$registroAdmin['tipo']]['rotulo'] ?? $registroAdmin['tipo']); ?><br>
                                        <span class="muted"><?php echo h(formatarDataHora($registroAdmin['marcado_em'])); ?></span>
                                    </td>
                                    <td>
                                        <?php if ($registroAdmin['latitude'] !== null && $registroAdmin['longitude'] !== null): ?>
                                            <?php echo h((string) $registroAdmin['latitude']); ?>,<br><?php echo h((string) $registroAdmin['longitude']); ?>
                                        <?php else: ?>
                                            <span class="muted">Sem localização</span>
                                        <?php endif; ?>
                                    </td>
                                    <td><?php echo h($registroAdmin['foto_registrada']); ?></td>
                                    <td>
                                        <form method="post" class="inline-edit">
                                            <input type="hidden" name="acao" value="editar_ponto">
                                            <input type="hidden" name="csrf_ponto" value="<?php echo $csrf; ?>">
                                            <input type="hidden" name="registro_id" value="<?php echo h((string) $registroAdmin['id']); ?>">
                                            <select name="tipo_ponto">
                                                <?php foreach ($tiposPonto as $tipoOpcao => $dadosOpcao): ?>
                                                    <option value="<?php echo h($tipoOpcao); ?>" <?php echo $registroAdmin['tipo'] === $tipoOpcao ? 'selected' : ''; ?>>
                                                        <?php echo h($dadosOpcao['rotulo']); ?>
                                                    </option>
                                                <?php endforeach; ?>
                                            </select>
                                            <input name="marcado_em" type="datetime-local" value="<?php echo h((new DateTimeImmutable($registroAdmin['marcado_em']))->format('Y-m-d\TH:i')); ?>">
                                            <button class="btn btn-outline" type="submit"><i class="fa-solid fa-pen"></i> Salvar</button>
                                        </form>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </section>
            </div>
                </div>
            </div>
        <?php endif; ?>
    </div>

    <div class="camera-modal" id="cameraModal" aria-hidden="true">
        <div class="camera-box" role="dialog" aria-modal="true" aria-labelledby="cameraTitle">
            <div class="section-title">
                <h2 id="cameraTitle">Foto de comprovação</h2>
                <button class="btn btn-outline" type="button" id="cameraClose"><i class="fa-solid fa-xmark"></i> Fechar</button>
            </div>
            <p class="camera-help">A foto é obrigatória para registrar o ponto e ficará vinculada ao comprovante da marcação.</p>
            <div class="location-status" id="locationStatus">Buscando localização do navegador...</div>
            <video id="cameraVideo" autoplay playsinline muted></video>
            <img id="cameraPreview" alt="Prévia da foto capturada" style="display: none;">
            <canvas id="cameraCanvas" width="640" height="480" style="display: none;"></canvas>
            <div class="camera-actions">
                <button class="btn" type="button" id="capturePhoto"><i class="fa-solid fa-camera"></i> Tirar foto</button>
                <button class="btn btn-outline" type="button" id="retakePhoto" disabled><i class="fa-solid fa-rotate-left"></i> Refazer</button>
                <button class="btn" type="button" id="confirmPoint" disabled><i class="fa-solid fa-check"></i> Confirmar ponto</button>
            </div>
        </div>
    </div>

    <script>
        if (sessionStorage.getItem('accountFuncionarioSessao') !== 'ativa') {
            fetch('login?logout=1', { keepalive: true })
                .finally(() => {
                    window.location.href = '/';
                });
        }

        const initialServerTime = new Date('<?php echo h((new DateTimeImmutable('now'))->format(DateTimeInterface::ATOM)); ?>');
        const loadedAt = Date.now();
        let activePointForm = null;
        let cameraStream = null;
        let capturedPhoto = '';
        let capturedPosition = null;

        const cameraModal = document.getElementById('cameraModal');
        const cameraVideo = document.getElementById('cameraVideo');
        const cameraPreview = document.getElementById('cameraPreview');
        const cameraCanvas = document.getElementById('cameraCanvas');
        const capturePhotoBtn = document.getElementById('capturePhoto');
        const retakePhotoBtn = document.getElementById('retakePhoto');
        const confirmPointBtn = document.getElementById('confirmPoint');
        const cameraCloseBtn = document.getElementById('cameraClose');
        const locationStatus = document.getElementById('locationStatus');

        function updateClock() {
            const elapsed = Date.now() - loadedAt;
            const current = new Date(initialServerTime.getTime() + elapsed);
            document.getElementById('clockNow').textContent = current.toLocaleTimeString('pt-BR', {
                hour: '2-digit',
                minute: '2-digit',
                second: '2-digit'
            });
        }

        function sair() {
            sessionStorage.removeItem('accountFuncionarioSessao');
            fetch('login?logout=1')
                .then(() => {
                    window.location.href = '/';
                });
        }

        const adminMenuToggle = document.getElementById('adminMenuToggle');
        const adminSidebar = document.getElementById('adminSidebar');
        const adminSidebarBackdrop = document.getElementById('adminSidebarBackdrop');
        const adminSidebarClose = document.getElementById('adminSidebarClose');

        function setAdminSidebar(open) {
            if (!adminMenuToggle || !adminSidebar) return;
            document.body.classList.toggle('admin-sidebar-open', open);
            adminMenuToggle.setAttribute('aria-expanded', open ? 'true' : 'false');
            if (open) {
                adminSidebar.querySelector('.admin-menu-link')?.focus();
            } else {
                adminMenuToggle.focus();
            }
        }

        adminMenuToggle?.addEventListener('click', () => {
            setAdminSidebar(!document.body.classList.contains('admin-sidebar-open'));
        });

        adminSidebarBackdrop?.addEventListener('click', () => setAdminSidebar(false));
        adminSidebarClose?.addEventListener('click', () => setAdminSidebar(false));

        document.addEventListener('keydown', event => {
            if (event.key === 'Escape' && document.body.classList.contains('admin-sidebar-open')) {
                setAdminSidebar(false);
            }
        });

        adminSidebar?.querySelectorAll('a[href^="#"]').forEach(link => {
            link.addEventListener('click', () => setAdminSidebar(false));
        });

        const toggleSolicitacoesAjuste = document.getElementById('toggleSolicitacoesAjuste');
        const solicitacoesAjustePanel = document.getElementById('solicitacoes-ajuste-admin');
        toggleSolicitacoesAjuste?.addEventListener('click', () => {
            if (!solicitacoesAjustePanel) return;
            const ocultar = !solicitacoesAjustePanel.classList.contains('is-hidden');
            solicitacoesAjustePanel.classList.toggle('is-hidden', ocultar);
            toggleSolicitacoesAjuste.setAttribute('aria-expanded', ocultar ? 'false' : 'true');
            toggleSolicitacoesAjuste.innerHTML = ocultar
                ? '<i class="fa-solid fa-eye"></i> Mostrar solicitações'
                : '<i class="fa-solid fa-eye-slash"></i> Ocultar solicitações';
        });

        const toggleBancoHoras = document.getElementById('toggleBancoHoras');
        const bancoHorasCards = document.getElementById('banco-horas');
        toggleBancoHoras?.addEventListener('click', () => {
            if (!bancoHorasCards) return;
            const mostrar = bancoHorasCards.hidden;
            bancoHorasCards.hidden = !mostrar;
            toggleBancoHoras.setAttribute('aria-expanded', mostrar ? 'true' : 'false');
            toggleBancoHoras.innerHTML = mostrar
                ? '<i class="fa-solid fa-eye-slash"></i> Ocultar saldos'
                : '<i class="fa-solid fa-eye"></i> Mostrar saldos';
        });

        async function openCamera(form) {
            activePointForm = form;
            capturedPhoto = '';
            capturedPosition = null;
            updateLocationStatus('Buscando localização do navegador...', '');
            cameraPreview.style.display = 'none';
            cameraVideo.style.display = 'block';
            retakePhotoBtn.disabled = true;
            confirmPointBtn.disabled = true;
            cameraModal.classList.add('show');
            cameraModal.setAttribute('aria-hidden', 'false');
            captureLocation();

            try {
                cameraStream = await navigator.mediaDevices.getUserMedia({
                    video: {
                        facingMode: 'user',
                        width: { ideal: 640 },
                        height: { ideal: 480 }
                    },
                    audio: false
                });
                cameraVideo.srcObject = cameraStream;
            } catch (error) {
                closeCamera();
                alert('Não foi possível acessar a câmera. Permita o uso da câmera no navegador para bater o ponto.');
            }
        }

        function stopCameraStream() {
            if (cameraStream) {
                cameraStream.getTracks().forEach(track => track.stop());
                cameraStream = null;
            }
        }

        function closeCamera() {
            stopCameraStream();
            cameraModal.classList.remove('show');
            cameraModal.setAttribute('aria-hidden', 'true');
            activePointForm = null;
            capturedPhoto = '';
            capturedPosition = null;
        }

        function updateLocationStatus(message, state) {
            locationStatus.textContent = message;
            locationStatus.className = 'location-status';
            if (state) {
                locationStatus.classList.add(state);
            }
            updateConfirmButton();
        }

        function updateConfirmButton() {
            confirmPointBtn.disabled = !(capturedPhoto && capturedPosition);
        }

        function captureLocation() {
            if (!navigator.geolocation) {
                updateLocationStatus('Este navegador não oferece geolocalização. Use um navegador atualizado para bater ponto.', 'error');
                return;
            }

            navigator.geolocation.getCurrentPosition(
                position => {
                    capturedPosition = {
                        latitude: position.coords.latitude,
                        longitude: position.coords.longitude,
                        accuracy: position.coords.accuracy
                    };
                    updateLocationStatus(
                        `Localização capturada: ${capturedPosition.latitude.toFixed(7)}, ${capturedPosition.longitude.toFixed(7)} · precisão aprox. ${Math.round(capturedPosition.accuracy)} m`,
                        'ready'
                    );
                },
                error => {
                    capturedPosition = null;
                    const detail = error && error.code === 1
                        ? 'Permita o acesso à localização no navegador.'
                        : 'Não foi possível obter a localização agora.';
                    updateLocationStatus(`${detail} Sem localização, o ponto não será gravado.`, 'error');
                },
                {
                    enableHighAccuracy: true,
                    timeout: 15000,
                    maximumAge: 0
                }
            );
        }

        function capturePhoto() {
            const context = cameraCanvas.getContext('2d');
            context.drawImage(cameraVideo, 0, 0, cameraCanvas.width, cameraCanvas.height);
            capturedPhoto = cameraCanvas.toDataURL('image/jpeg', 0.78);
            cameraPreview.src = capturedPhoto;
            cameraPreview.style.display = 'block';
            cameraVideo.style.display = 'none';
            retakePhotoBtn.disabled = false;
            updateConfirmButton();
            stopCameraStream();
        }

        function retakePhoto() {
            if (activePointForm) {
                openCamera(activePointForm);
            }
        }

        function confirmPoint() {
            if (!activePointForm || !capturedPhoto) {
                alert('Tire uma foto antes de confirmar o ponto.');
                return;
            }

            if (!capturedPosition) {
                alert('Aguarde a localização ser capturada ou permita a localização no navegador.');
                return;
            }

            activePointForm.querySelector('input[name="foto_ponto"]').value = capturedPhoto;
            activePointForm.querySelector('input[name="latitude"]').value = capturedPosition.latitude;
            activePointForm.querySelector('input[name="longitude"]').value = capturedPosition.longitude;
            activePointForm.querySelector('input[name="precisao_metros"]').value = capturedPosition.accuracy;
            activePointForm.submit();
        }

        document.querySelectorAll('.point-form').forEach(form => {
            form.addEventListener('submit', event => {
                const button = form.querySelector('button[type="submit"]');
                if (button && button.disabled) {
                    return;
                }

                if (!form.querySelector('input[name="foto_ponto"]').value) {
                    event.preventDefault();
                    openCamera(form);
                }
            });
        });

        capturePhotoBtn.addEventListener('click', capturePhoto);
        retakePhotoBtn.addEventListener('click', retakePhoto);
        confirmPointBtn.addEventListener('click', confirmPoint);
        cameraCloseBtn.addEventListener('click', closeCamera);

        updateClock();
        setInterval(updateClock, 1000);
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
