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
$usuarioRaw = $_SESSION['funcionario_usuario'] ?? 'Operador';
$nivelAcesso = (int) ($_SESSION['funcionario_nivel_acesso'] ?? 1);
$podeAdministrar = $nivelAcesso >= 3;
$erro = '';
$sucesso = '';

$tiposPonto = [
    'chegada' => ['rotulo' => 'Chegada ao escritório'],
    'saida_almoco' => ['rotulo' => 'Saída para almoço'],
    'volta_escritorio' => ['rotulo' => 'Volta do almoço'],
    'saida_lanche' => ['rotulo' => 'Saída para o lanche'],
    'volta_lanche' => ['rotulo' => 'Volta do lanche'],
    'saida_escritorio' => ['rotulo' => 'Saída do escritório'],
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

function inicioMesAtual(): string
{
    return (new DateTimeImmutable('first day of this month'))->format('Y-m-d');
}

function fimMesAtual(): string
{
    return (new DateTimeImmutable('last day of this month'))->format('Y-m-d');
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

function montarFiltrosAdmin(array $filtros): array
{
    $where = ['1=1'];
    $bind = [];

    if (trim($filtros['empresa_nome'] ?? '') !== '') {
        $where[] = 'f.empresa_nome = :empresa_nome';
        $bind['empresa_nome'] = trim($filtros['empresa_nome']);
    }

    if ((int) ($filtros['funcionario_id'] ?? 0) > 0) {
        $where[] = 'f.id = :funcionario_id';
        $bind['funcionario_id'] = (int) $filtros['funcionario_id'];
    }

    if (trim($filtros['departamento'] ?? '') !== '') {
        $where[] = 'f.departamento = :departamento';
        $bind['departamento'] = trim($filtros['departamento']);
    }

    $dataInicio = trim($filtros['data_inicio'] ?? '') !== '' ? trim($filtros['data_inicio']) : inicioMesAtual();
    $dataFim = trim($filtros['data_fim'] ?? '') !== '' ? trim($filtros['data_fim']) : fimMesAtual();
    $where[] = 'rp.data_referencia BETWEEN :data_inicio AND :data_fim';
    $bind['data_inicio'] = $dataInicio;
    $bind['data_fim'] = $dataFim;

    return ['WHERE ' . implode(' AND ', $where), $bind, $dataInicio, $dataFim];
}

if (empty($_SESSION['csrf_ponto'])) {
    $_SESSION['csrf_ponto'] = bin2hex(random_bytes(32));
}

$funcionariosAdmin = [];
$departamentosAdmin = [];
$registrosAdmin = [];
$ajustesManuaisAdmin = [];
$paginaAtual = max(1, (int) ($_GET['pagina'] ?? 1));
$registrosPorPagina = 10;
$totalRegistrosAdmin = 0;
$totalPaginasAdmin = 1;
$dataInicioFiltro = $_GET['data_inicio'] ?? inicioMesAtual();
$dataFimFiltro = $_GET['data_fim'] ?? fimMesAtual();

try {
    $db = obterConexao();
    prepararTabelaAjustesManuaisPonto($db);

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

    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $acao = $_POST['acao'] ?? '';
        if (!hash_equals($_SESSION['csrf_ponto'] ?? '', $_POST['csrf_ponto'] ?? '')) {
            $erro = 'Sessão expirada. Atualize a página e tente novamente.';
        } elseif ($acao === 'editar_ponto') {
            $registroId = (int) ($_POST['registro_id'] ?? 0);
            $novoTipo = $_POST['tipo_ponto'] ?? '';
            $novoHorarioRaw = trim($_POST['marcado_em'] ?? '');
            $novoHorario = DateTimeImmutable::createFromFormat('Y-m-d\TH:i', $novoHorarioRaw);

            if ($registroId <= 0 || !isset($tiposPonto[$novoTipo]) || !$novoHorario) {
                $erro = 'Dados inválidos para alterar o ponto.';
            } else {
                $novoHash = hash('sha256', implode('|', [
                    'editado',
                    $registroId,
                    $novoTipo,
                    $novoHorario->format('Y-m-d H:i:s'),
                    $funcionarioId,
                    (new DateTimeImmutable('now'))->format('Y-m-d H:i:s'),
                ]));
                $stmt = $db->prepare(
                    'UPDATE registros_ponto
                     SET tipo = :tipo,
                         data_referencia = :data_referencia,
                         marcado_em = :marcado_em,
                         hash_comprovante = :hash,
                         user_agent = :user_agent
                     WHERE id = :id'
                );
                $stmt->execute([
                    'tipo' => $novoTipo,
                    'data_referencia' => $novoHorario->format('Y-m-d'),
                    'marcado_em' => $novoHorario->format('Y-m-d H:i:s'),
                    'hash' => $novoHash,
                    'user_agent' => limitarTexto('Editado na página Apuração de ponto por ' . $usuarioRaw, 255),
                    'id' => $registroId,
                ]);
                $sucesso = 'Registro de ponto atualizado com sucesso.';
            }
        } elseif ($acao === 'ajuste_manual_ponto') {
            $ajustadoId = (int) ($_POST['funcionario_id'] ?? 0);
            $tipoPontoManual = $_POST['tipo_ponto'] ?? '';
            $horarioManualRaw = trim($_POST['horario_ajustado'] ?? '');
            $motivoManual = trim($_POST['motivo'] ?? '');
            $observacoesManual = trim($_POST['observacoes'] ?? '');
            $tipoDocumentoManual = trim($_POST['tipo_documento'] ?? '');
            $horarioManual = DateTimeImmutable::createFromFormat('Y-m-d\TH:i', $horarioManualRaw);

            if ($ajustadoId <= 0 || !isset($tiposPonto[$tipoPontoManual]) || !$horarioManual || $motivoManual === '' || $observacoesManual === '') {
                $erro = 'Preencha funcionário, tipo de ponto, data/hora, motivo e justificativa.';
            } elseif (!in_array($motivoManual, $motivosAjusteManual, true)) {
                $erro = 'Selecione um motivo válido para o ajuste manual.';
            } elseif ($tipoDocumentoManual !== '' && !in_array($tipoDocumentoManual, $tiposDocumentoAjusteManual, true)) {
                $erro = 'Selecione um tipo de documento válido.';
            } else {
                $stmt = $db->prepare('SELECT id FROM funcionarios WHERE id = :id AND ativo = 1 LIMIT 1');
                $stmt->execute(['id' => $ajustadoId]);
                if (!$stmt->fetchColumn()) {
                    $erro = 'Funcionário não encontrado ou inativo.';
                } else {
                    [$documentoNome, $documentoCaminho] = salvarDocumentoAjusteManual($_FILES['documento_ajuste'] ?? []);
                    $dataReferenciaManual = $horarioManual->format('Y-m-d');
                    $marcadoEmManual = $horarioManual->format('Y-m-d H:i:s');
                    $ipManual = $_SERVER['REMOTE_ADDR'] ?? null;
                    $textoAuditoriaManual = limitarTexto(
                        'Ajuste aprovado manual nivel 3 por ' . $usuarioRaw . ': ' . $motivoManual . ' - ' . $observacoesManual,
                        255
                    );

                    $db->beginTransaction();
                    try {
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
                        $erro = 'Não foi possível salvar o ajuste manual: ' . $e->getMessage();
                    }
                }
            }
        }
    }

    $funcionariosAdmin = $db->query(
        'SELECT id, usuario, email, empresa_nome, empresa_cnpj, cpf, cargo, departamento, nivel_acesso, permite_ponto, ativo
         FROM funcionarios
         ORDER BY empresa_nome ASC, usuario ASC'
    )->fetchAll();

    $departamentosAdmin = $db->query(
        "SELECT DISTINCT departamento
         FROM funcionarios
         WHERE departamento IS NOT NULL AND departamento <> ''
         ORDER BY departamento ASC"
    )->fetchAll();

    [$whereAdmin, $bindAdmin, $dataInicioFiltro, $dataFimFiltro] = montarFiltrosAdmin($_GET);
    $stmt = $db->prepare(
        'SELECT COUNT(*)
         FROM registros_ponto rp
         INNER JOIN funcionarios f ON f.id = rp.funcionario_id
         ' . $whereAdmin
    );
    $stmt->execute($bindAdmin);
    $totalRegistrosAdmin = (int) $stmt->fetchColumn();
    $totalPaginasAdmin = max(1, (int) ceil($totalRegistrosAdmin / $registrosPorPagina));
    $paginaAtual = min($paginaAtual, $totalPaginasAdmin);
    $offsetRegistros = ($paginaAtual - 1) * $registrosPorPagina;

    $stmt = $db->prepare(
        'SELECT rp.id, rp.funcionario_id, rp.tipo, rp.data_referencia, rp.marcado_em,
                rp.latitude, rp.longitude, rp.precisao_metros,
                CASE WHEN rp.foto_comprovante IS NULL OR rp.foto_comprovante = \'\' THEN \'nao\' ELSE \'sim\' END AS foto_registrada,
                f.usuario, f.email, f.empresa_nome, f.empresa_cnpj, f.cpf, f.cargo
         FROM registros_ponto rp
         INNER JOIN funcionarios f ON f.id = rp.funcionario_id
         ' . $whereAdmin . '
         ORDER BY rp.marcado_em DESC
         LIMIT :limit OFFSET :offset'
    );
    foreach ($bindAdmin as $chave => $valor) {
        $stmt->bindValue(':' . $chave, $valor);
    }
    $stmt->bindValue(':limit', $registrosPorPagina, PDO::PARAM_INT);
    $stmt->bindValue(':offset', $offsetRegistros, PDO::PARAM_INT);
    $stmt->execute();
    $registrosAdmin = $stmt->fetchAll();

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
} catch (Throwable $e) {
    if (isset($db) && $db instanceof PDO && $db->inTransaction()) {
        $db->rollBack();
    }
    $erro = 'Erro ao preparar a apuração de ponto: ' . $e->getMessage();
}

$csrf = h($_SESSION['csrf_ponto'] ?? '');
$mesPdfFiltro = (new DateTimeImmutable($dataInicioFiltro))->format('Y-m');
$pdfQuery = [
    'export' => 'pdf',
    'scope' => 'all',
    'empresa_nome' => $_GET['empresa_nome'] ?? '',
    'funcionario_id' => $_GET['funcionario_id'] ?? '',
    'departamento' => $_GET['departamento'] ?? '',
    'data_inicio' => $dataInicioFiltro,
    'data_fim' => $dataFimFiltro,
];
$pdfTodosUrl = 'painel?' . http_build_query($pdfQuery);
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Apuração de ponto</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.2/css/all.min.css">
    <style>
        :root {
            --bg-main: #0A0A0A;
            --bg-card: #161616;
            --bg-soft: #202020;
            --primary: #74C92C;
            --primary-hover: #5EA522;
            --danger: #FF453A;
            --text-white: #FFFFFF;
            --text-light: #F5F5F7;
            --text-muted: #A1A1A6;
            --border: rgba(255, 255, 255, 0.12);
            --shadow: rgba(0, 0, 0, 0.38);
        }

        * { box-sizing: border-box; }

        body {
            min-height: 100vh;
            margin: 0;
            padding: 2rem;
            font-family: Inter, Arial, Helvetica, sans-serif;
            color: var(--text-white);
            background:
                radial-gradient(circle at 18% 6%, rgba(116, 201, 44, 0.16), transparent 26rem),
                radial-gradient(circle at 82% 0%, rgba(255, 255, 255, 0.08), transparent 22rem),
                linear-gradient(135deg, #070807 0%, #0b0d0b 48%, #050605 100%);
        }

        a { color: inherit; }

        .shell {
            width: min(1180px, 100%);
            margin: 0 auto;
        }

        .topbar,
        .section-title,
        .top-actions {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 1rem;
            flex-wrap: wrap;
        }

        .topbar {
            margin-bottom: 1.5rem;
        }

        .brand img {
            width: 160px;
            height: auto;
            display: block;
        }

        h1, h2 {
            margin: 0;
            letter-spacing: 0;
        }

        h1 {
            font-size: clamp(1.85rem, 4vw, 3rem);
        }

        h2 {
            font-size: 1.25rem;
        }

        .muted {
            color: var(--text-muted);
            font-size: 0.9rem;
        }

        .panel {
            background: linear-gradient(145deg, rgba(24, 24, 24, 0.96), rgba(17, 18, 17, 0.94));
            border: 1px solid var(--border);
            border-radius: 8px;
            padding: 1.5rem;
            box-shadow: 0 18px 45px var(--shadow);
            margin-bottom: 1.25rem;
            overflow: hidden;
        }

        .manual-adjust-card {
            border-color: rgba(116, 201, 44, 0.28);
            background:
                radial-gradient(circle at 12% 0%, rgba(116, 201, 44, 0.12), transparent 18rem),
                linear-gradient(145deg, rgba(24, 24, 24, 0.98), rgba(17, 18, 17, 0.95));
        }

        .btn {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 0.55rem;
            min-height: 2.65rem;
            border: 0;
            border-radius: 8px;
            padding: 0.7rem 1rem;
            background: var(--primary);
            color: #061006;
            font-weight: 800;
            text-decoration: none;
            cursor: pointer;
        }

        .btn:hover { background: var(--primary-hover); }

        .btn-outline {
            border: 1px solid var(--border);
            background: rgba(255, 255, 255, 0.04);
            color: var(--text-light);
        }

        .btn-outline:hover {
            border-color: rgba(116, 201, 44, 0.5);
            background: rgba(116, 201, 44, 0.1);
            color: var(--primary);
        }

        .alert {
            border-radius: 8px;
            padding: 0.9rem 1rem;
            margin-bottom: 1rem;
        }

        .alert-error {
            border: 1px solid rgba(255, 69, 58, 0.4);
            background: rgba(255, 69, 58, 0.1);
            color: var(--danger);
        }

        .alert-success {
            border: 1px solid rgba(116, 201, 44, 0.45);
            background: rgba(116, 201, 44, 0.1);
            color: var(--primary);
        }

        .admin-form-grid,
        .admin-filters {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(190px, 1fr));
            gap: 0.85rem;
            align-items: end;
        }

        .field.wide { grid-column: span 2; }
        .field.full { grid-column: 1 / -1; }

        .field label {
            display: block;
            margin-bottom: 0.35rem;
            color: var(--text-muted);
            font-weight: 800;
            font-size: 0.83rem;
        }

        .field input,
        .field select,
        .field textarea,
        .inline-edit input,
        .inline-edit select {
            width: 100%;
            border: 1px solid var(--border);
            border-radius: 4px;
            background: var(--bg-main);
            color: var(--text-white);
            padding: 0.75rem;
            font: inherit;
        }

        .field textarea {
            min-height: 92px;
            resize: vertical;
        }

        .field select option,
        .inline-edit select option {
            background: #111;
            color: var(--text-light);
        }

        .manual-adjust-layout {
            display: grid;
            grid-template-columns: minmax(0, 1.12fr) minmax(280px, 0.88fr);
            gap: 1rem;
            align-items: start;
            margin-top: 1rem;
        }

        .manual-adjust-form,
        .manual-history {
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

        .manual-history {
            max-height: 33rem;
            overflow: auto;
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

        .admin-table-wrap {
            overflow-x: auto;
            margin-top: 1rem;
        }

        .admin-table {
            width: 100%;
            min-width: 1050px;
            border-collapse: collapse;
        }

        .admin-table th,
        .admin-table td {
            border-bottom: 1px solid var(--border);
            padding: 0.85rem;
            text-align: left;
            vertical-align: top;
        }

        .admin-table th {
            color: var(--text-muted);
            font-size: 0.78rem;
            text-transform: uppercase;
            letter-spacing: 0.04em;
        }

        .inline-edit {
            display: grid;
            gap: 0.55rem;
            min-width: 220px;
        }

        .pagination {
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 0.45rem;
            flex-wrap: wrap;
            margin-top: 1rem;
            color: var(--text-muted);
            font-weight: 800;
        }

        .pagination a,
        .pagination span {
            min-width: 2.25rem;
            min-height: 2.25rem;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            border-radius: 8px;
            border: 1px solid var(--border);
            background: rgba(255, 255, 255, 0.04);
            color: var(--text-light);
            text-decoration: none;
        }

        .pagination a:hover,
        .pagination .active {
            border-color: rgba(116, 201, 44, 0.45);
            background: rgba(116, 201, 44, 0.12);
            color: var(--primary);
        }

        .pagination .dots {
            border-color: transparent;
            background: transparent;
            min-width: auto;
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
        }

        .admin-sidebar {
            position: fixed;
            top: 0;
            left: 0;
            z-index: 2100;
            width: min(330px, calc(100vw - 2rem));
            height: 100vh;
            overflow-y: auto;
            display: grid;
            align-content: start;
            gap: 0.35rem;
            padding: 2rem;
            border-radius: 0 8px 8px 0;
            transform: translateX(-110%);
            transition: transform 0.24s ease;
            background: linear-gradient(145deg, rgba(24, 24, 24, 0.98), rgba(17, 18, 17, 0.96));
            border: 1px solid var(--border);
            box-shadow: 28px 0 70px rgba(0, 0, 0, 0.34);
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

        .admin-sidebar-close {
            justify-self: end;
            width: 2.35rem;
            height: 2.35rem;
            border-radius: 8px;
            border: 1px solid var(--border);
            background: rgba(255, 255, 255, 0.04);
            color: var(--text-white);
            cursor: pointer;
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

        @media (max-width: 820px) {
            body { padding: 1rem; }
            .topbar { padding-left: 3.5rem; }
            .manual-adjust-layout,
            .manual-adjust-file {
                grid-template-columns: 1fr;
            }
            .field.wide { grid-column: 1 / -1; }
        }
    </style>
</head>
<body>
    <button class="admin-menu-toggle" type="button" id="adminMenuToggle" aria-label="Abrir menu administrativo" aria-controls="adminSidebar" aria-expanded="false">
        <i class="fa-solid fa-bars"></i>
    </button>
    <div class="admin-sidebar-backdrop" id="adminSidebarBackdrop" aria-hidden="true"></div>
    <aside class="admin-sidebar" id="adminSidebar" aria-label="Menu administrativo">
        <button class="admin-sidebar-close" type="button" id="adminSidebarClose" aria-label="Fechar menu administrativo">
            <i class="fa-solid fa-xmark"></i>
        </button>
        <a class="admin-menu-link" href="painel"><i class="fa-solid fa-chart-column"></i> Dashboard</a>
        <div class="admin-sidebar-title">Gestão de ponto</div>
        <a class="admin-menu-link" href="painel#solicitacoes-ajuste-admin"><i class="fa-solid fa-clipboard-check"></i> Solicitações</a>
        <a class="admin-menu-link active" href="apuracao-ponto"><i class="fa-regular fa-clock"></i> Apuração de ponto</a>
        <a class="admin-menu-link" href="banco-horas"><i class="fa-solid fa-scale-balanced"></i> Banco de Horas</a>
        <a class="admin-menu-link" href="afastamentos"><i class="fa-regular fa-calendar-xmark"></i> Afastamentos</a>
        <a class="admin-menu-link" href="tipos-afastamentos"><i class="fa-solid fa-sliders"></i> Tipos de afastamento</a>
        <div class="admin-sidebar-title">Relatórios</div>
        <a class="admin-menu-link" href="historico-espelho"><i class="fa-solid fa-clock-rotate-left"></i> Histórico de espelho</a>
        <a class="admin-menu-link" href="historico-download"><i class="fa-solid fa-download"></i> Histórico de download</a>
    </aside>

    <div class="shell">
        <header class="topbar">
            <a class="brand" href="/" aria-label="Voltar para o site">
                <img src="logo-branca.png" alt="ACCOUNT Contabilidade">
            </a>
            <div class="top-actions">
                <a class="btn btn-outline" href="painel"><i class="fa-solid fa-arrow-left"></i> Voltar ao painel</a>
            </div>
        </header>

        <section class="panel">
            <div class="section-title">
                <div>
                    <h1>Apuração de ponto</h1>
                    <span class="muted">Painel geral de ponto e ajuste manual em uma única página.</span>
                </div>
            </div>
        </section>

        <?php if ($erro !== ''): ?>
            <div class="alert alert-error"><?php echo h($erro); ?></div>
        <?php endif; ?>
        <?php if ($sucesso !== ''): ?>
            <div class="alert alert-success"><?php echo h($sucesso); ?></div>
        <?php endif; ?>

        <section class="panel manual-adjust-card" id="ajuste-manual-admin">
            <div class="section-title">
                <div>
                    <h2>Ajuste manual de ponto</h2>
                    <span class="muted">Crie ou altere uma batida com motivo, justificativa e documento.</span>
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
                <div>
                    <h2>Painel geral de ponto</h2>
                    <span class="muted">Filtre, consulte e ajuste registros da equipe.</span>
                </div>
                <a class="btn btn-outline" href="<?php echo h($pdfTodosUrl); ?>" target="_blank" rel="noopener" id="baixarPdfApuracao">
                    <i class="fa-solid fa-file-pdf"></i> PDF do mês
                </a>
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
                    <label for="departamento">Departamento</label>
                    <select id="departamento" name="departamento">
                        <option value="">Todos</option>
                        <?php foreach ($departamentosAdmin as $departamentoOpcao): ?>
                            <option value="<?php echo h($departamentoOpcao['departamento']); ?>" <?php echo (string) ($_GET['departamento'] ?? '') === (string) $departamentoOpcao['departamento'] ? 'selected' : ''; ?>>
                                <?php echo h($departamentoOpcao['departamento']); ?>
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

            <form class="admin-filters" method="get" action="painel" target="_blank" id="pdfMesForm">
                <input type="hidden" name="export" value="pdf">
                <input type="hidden" name="scope" value="all">
                <input type="hidden" name="data_inicio" id="pdf_data_inicio" value="<?php echo h($dataInicioFiltro); ?>">
                <input type="hidden" name="data_fim" id="pdf_data_fim" value="<?php echo h($dataFimFiltro); ?>">
                <div class="field">
                    <label for="pdf_mes">Mês do PDF</label>
                    <input id="pdf_mes" type="month" value="<?php echo h($mesPdfFiltro); ?>">
                </div>
                <div class="field">
                    <label for="pdf_departamento">Departamento</label>
                    <select id="pdf_departamento" name="departamento">
                        <option value="">Todos</option>
                        <?php foreach ($departamentosAdmin as $departamentoOpcao): ?>
                            <option value="<?php echo h($departamentoOpcao['departamento']); ?>" <?php echo (string) ($_GET['departamento'] ?? '') === (string) $departamentoOpcao['departamento'] ? 'selected' : ''; ?>>
                                <?php echo h($departamentoOpcao['departamento']); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="field">
                    <label for="pdf_funcionario_id">Funcionário</label>
                    <select id="pdf_funcionario_id" name="funcionario_id">
                        <option value="">Todos</option>
                        <?php foreach ($funcionariosAdmin as $funcionarioOpcao): ?>
                            <option value="<?php echo h((string) $funcionarioOpcao['id']); ?>" <?php echo (string) ($_GET['funcionario_id'] ?? '') === (string) $funcionarioOpcao['id'] ? 'selected' : ''; ?>>
                                <?php echo h(($funcionarioOpcao['empresa_nome'] ?? 'Sem empresa') . ' · ' . nomeExibicao($funcionarioOpcao['usuario']) . ' · ' . ($funcionarioOpcao['departamento'] ?? 'Sem departamento')); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <button class="btn" type="submit"><i class="fa-solid fa-file-pdf"></i> Baixar PDF</button>
            </form>

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

            <?php if ($totalPaginasAdmin > 1): ?>
                <?php
                    $paginasVisiveis = array_unique(array_filter([
                        1,
                        2,
                        3,
                        $paginaAtual - 1,
                        $paginaAtual,
                        $paginaAtual + 1,
                        $totalPaginasAdmin - 1,
                        $totalPaginasAdmin,
                    ], fn($pagina) => $pagina >= 1 && $pagina <= $totalPaginasAdmin));
                    sort($paginasVisiveis);
                    $paginaAnteriorRenderizada = 0;
                    $queryPaginacao = $_GET;
                ?>
                <nav class="pagination" aria-label="Paginação do painel geral de ponto">
                    <?php foreach ($paginasVisiveis as $paginaLink): ?>
                        <?php if ($paginaAnteriorRenderizada > 0 && $paginaLink > $paginaAnteriorRenderizada + 1): ?>
                            <span class="dots">...</span>
                        <?php endif; ?>
                        <?php $queryPaginacao['pagina'] = $paginaLink; ?>
                        <?php if ($paginaLink === $paginaAtual): ?>
                            <span class="active" aria-current="page"><?php echo h((string) $paginaLink); ?></span>
                        <?php else: ?>
                            <a href="apuracao-ponto?<?php echo h(http_build_query($queryPaginacao)); ?>#painel-geral"><?php echo h((string) $paginaLink); ?></a>
                        <?php endif; ?>
                        <?php $paginaAnteriorRenderizada = $paginaLink; ?>
                    <?php endforeach; ?>
                </nav>
            <?php endif; ?>
        </section>
    </div>

    <script>
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

        const pdfMesForm = document.getElementById('pdfMesForm');
        const pdfMes = document.getElementById('pdf_mes');
        const pdfDataInicio = document.getElementById('pdf_data_inicio');
        const pdfDataFim = document.getElementById('pdf_data_fim');

        function atualizarPeriodoPdf() {
            if (!pdfMes?.value || !pdfDataInicio || !pdfDataFim) return;
            const [ano, mes] = pdfMes.value.split('-').map(Number);
            if (!ano || !mes) return;
            const ultimoDia = new Date(ano, mes, 0).getDate();
            pdfDataInicio.value = `${ano}-${String(mes).padStart(2, '0')}-01`;
            pdfDataFim.value = `${ano}-${String(mes).padStart(2, '0')}-${String(ultimoDia).padStart(2, '0')}`;
        }

        pdfMes?.addEventListener('change', atualizarPeriodoPdf);
        pdfMesForm?.addEventListener('submit', atualizarPeriodoPdf);
    </script>
</body>
</html>
