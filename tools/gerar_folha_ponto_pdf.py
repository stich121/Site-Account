#!/usr/bin/env python3
# -*- coding: utf-8 -*-
"""
Gerador de Folha de Ponto em PDF
Replica a logica do painel-funcionarios.php para gerar PDFs de espelho de ponto
"""

import sys
import io
sys.stdout = io.TextIOWrapper(sys.stdout.buffer, encoding='utf-8')
import argparse
from datetime import datetime, timedelta, date
from typing import Optional, Dict, List, Tuple, Any
from decimal import Decimal
from pathlib import Path
import calendar
import mysql.connector
from mysql.connector import Error

# Configurações do banco de dados
DB_CONFIG = {
    'host': '127.0.0.1',
    'user': 'u654041352_Matheus',
    'password': 'Stich@121',
    'database': 'u654041352_Clientes',
    'charset': 'utf8mb4',
    'autocommit': True
}

# Feriados em Belo Horizonte
def calcular_feriados_belo_horizonte(ano: int) -> Dict[str, str]:
    """Calcula os feriados de Belo Horizonte para um ano específico"""
    feriados = {}

    # Feriados fixos
    feriados[f'{ano}-01-01'] = 'Confraternização Universal'
    feriados[f'{ano}-04-21'] = 'Tiradentes'
    feriados[f'{ano}-05-01'] = 'Dia do Trabalho'
    feriados[f'{ano}-09-07'] = 'Independência do Brasil'
    feriados[f'{ano}-10-12'] = 'Nossa Senhora Aparecida'
    feriados[f'{ano}-11-02'] = 'Finados'
    feriados[f'{ano}-11-15'] = 'Proclamação da República'
    feriados[f'{ano}-11-20'] = 'Consciência Negra'
    feriados[f'{ano}-12-25'] = 'Natal'

    # Minas Gerais e Belo Horizonte
    feriados[f'{ano}-08-15'] = 'Assunção de Nossa Senhora'
    feriados[f'{ano}-12-08'] = 'Imaculada Conceição'

    # Feriados móveis baseados na Páscoa
    pascoa = calcular_pascoa(ano)
    carnaval1 = (pascoa - timedelta(days=48)).strftime('%Y-%m-%d')
    carnaval2 = (pascoa - timedelta(days=47)).strftime('%Y-%m-%d')
    cinzas = (pascoa - timedelta(days=46)).strftime('%Y-%m-%d')
    sexta_santa = (pascoa - timedelta(days=2)).strftime('%Y-%m-%d')
    corpus = (pascoa + timedelta(days=60)).strftime('%Y-%m-%d')

    feriados[carnaval1] = 'Carnaval'
    feriados[carnaval2] = 'Carnaval'
    feriados[cinzas] = 'Quarta-feira de Cinzas'
    feriados[sexta_santa] = 'Sexta-feira Santa'
    feriados[corpus] = 'Corpus Christi'

    return feriados


def calcular_pascoa(ano: int) -> datetime:
    """Calcula a data da Páscoa usando o algoritmo de Computus"""
    a = ano % 19
    b = ano // 100
    c = ano % 100
    d = b // 4
    e = b % 4
    f = (b + 8) // 25
    g = (b - f + 1) // 3
    h = (19 * a + b - d - g + 15) % 30
    i = c // 4
    k = c % 4
    l = (32 + 2 * e + 2 * i - h - k) % 7
    m = (a + 11 * h + 22 * l) // 451
    mes = (h + l - 7 * m + 114) // 31
    dia = ((h + l - 7 * m + 114) % 31) + 1
    return datetime(ano, mes, dia)


def eh_feriado_belo_horizonte(data: str, feriados: Dict[str, str]) -> Optional[str]:
    """Retorna o nome do feriado se a data for feriado, None caso contrário"""
    return feriados.get(data)


def eh_estagiario(cargo: Optional[str]) -> bool:
    """Verifica se o cargo indica um estagiário"""
    if not cargo:
        return False
    return 'estagi' in cargo.lower()


def segundos_entre(inicio: Optional[str], fim: Optional[str]) -> Optional[int]:
    """Calcula segundos entre dois horários"""
    if not inicio or not fim:
        return None

    try:
        inicio_dt = datetime.fromisoformat(inicio)
        fim_dt = datetime.fromisoformat(fim)

        if fim_dt < inicio_dt:
            return None

        return int((fim_dt - inicio_dt).total_seconds())
    except (ValueError, TypeError):
        return None


def formatar_segundos(segundos: int) -> str:
    """Formata segundos como HHhMM"""
    segundos = max(0, int(segundos))
    horas = segundos // 3600
    minutos = (segundos % 3600) // 60
    return f'{horas:02d}h{minutos:02d}'


def formatar_saldo_espelho(segundos: int) -> str:
    """Formata saldo para espelho de ponto"""
    if segundos == 0:
        return '0h00'

    prefixo = '+' if segundos > 0 else '-'
    return prefixo + formatar_segundos(abs(segundos))


def formatar_hora_ponto(marcado_em: Optional[str]) -> str:
    """Formata horário para exibição no espelho"""
    if not marcado_em:
        return ''

    try:
        dt = datetime.fromisoformat(marcado_em)
        return dt.strftime('%H:%M')
    except (ValueError, TypeError):
        return ''


def segundos_trabalhados_dia(por_tipo: Dict[str, Any], cargo: Optional[str] = None) -> int:
    """Calcula segundos trabalhados em um dia"""
    if eh_estagiario(cargo):
        saida_lanche = por_tipo.get('saida_lanche', {}).get('marcado_em')
        volta_lanche = por_tipo.get('volta_lanche', {}).get('marcado_em')
        saida_escritorio = por_tipo.get('saida_escritorio', {}).get('marcado_em')

        if not saida_lanche and not volta_lanche:
            return segundos_entre(por_tipo.get('chegada', {}).get('marcado_em'), saida_escritorio) or 0

        return (segundos_entre(por_tipo.get('chegada', {}).get('marcado_em'), saida_lanche) or 0) + \
               (segundos_entre(volta_lanche, saida_escritorio) or 0)

    periodos = [
        ['chegada', 'saida_almoco'],
        ['volta_escritorio', 'saida_lanche'],
        ['volta_lanche', 'saida_escritorio'],
    ]

    total = 0
    for inicio_tipo, fim_tipo in periodos:
        segundos = segundos_entre(
            por_tipo.get(inicio_tipo, {}).get('marcado_em'),
            por_tipo.get(fim_tipo, {}).get('marcado_em')
        )
        if segundos is not None:
            total += segundos

    return total


def segundos_intervalo_dia(por_tipo: Dict[str, Any]) -> int:
    """Calcula segundos de intervalo em um dia"""
    intervalos = [
        ['saida_almoco', 'volta_escritorio'],
        ['saida_lanche', 'volta_lanche'],
    ]

    total = 0
    for inicio_tipo, fim_tipo in intervalos:
        segundos = segundos_entre(
            por_tipo.get(inicio_tipo, {}).get('marcado_em'),
            por_tipo.get(fim_tipo, {}).get('marcado_em')
        )
        if segundos is not None:
            total += segundos

    return total


def segundos_excesso_intervalo_dia(por_tipo: Dict[str, Any], cargo: Optional[str] = None) -> int:
    """Calcula excesso de intervalo em um dia"""
    intervalo = segundos_intervalo_dia(por_tipo)
    if intervalo <= 0:
        return 0

    limite = 900 if eh_estagiario(cargo) else 4500  # 15 min para estagi, 75 min para regular
    return max(0, intervalo - limite)


def segundos_atraso_chegada_dia(por_tipo: Dict[str, Any], cargo: Optional[str] = None) -> int:
    """Calcula atraso na chegada"""
    chegada = por_tipo.get('chegada', {}).get('marcado_em')
    if not chegada:
        return 0

    try:
        chegada_dt = datetime.fromisoformat(chegada)
        limite_entrada = '12:55:00' if eh_estagiario(cargo) else '08:10:00'
        limite = datetime.fromisoformat(f'{chegada_dt.strftime("%Y-%m-%d")} {limite_entrada}')

        return max(0, int((chegada_dt - limite).total_seconds()))
    except (ValueError, TypeError):
        return 0


def segundos_esperados_para_data(data: str, cargo: Optional[str] = None, feriados: Optional[Dict] = None) -> int:
    """Calcula segundos esperados para uma data"""
    if feriados and eh_feriado_belo_horizonte(data, feriados):
        return 0

    data_dt = datetime.fromisoformat(data)
    dia_semana = data_dt.weekday()  # 0 = seg, 6 = dom

    if eh_estagiario(cargo):
        return 18000 if 0 <= dia_semana <= 4 else 0  # 5h por dia de semana

    if 0 <= dia_semana <= 3:  # seg a qui
        return 31500  # 8h45m

    if dia_semana == 4:  # sexta
        return 27900  # 7h45m

    return 0


def obter_conexao_banco():
    """Obtém conexão com o banco de dados"""
    try:
        conexao = mysql.connector.connect(**DB_CONFIG)
        return conexao
    except Error as e:
        print(f'Erro ao conectar ao banco de dados: {e}', file=sys.stderr)
        sys.exit(1)


def buscar_funcionario(conn, funcionario_id: int) -> Optional[Dict]:
    """Busca dados do funcionário"""
    cursor = conn.cursor(dictionary=True)
    try:
        cursor.execute(
            'SELECT id, usuario, email, empresa_nome, empresa_cnpj, cpf, '
            'pis_pasep, cargo, data_admissao, departamento, numero_folha, centro_custo '
            'FROM funcionarios WHERE id = %s',
            (funcionario_id,)
        )
        return cursor.fetchone()
    finally:
        cursor.close()


def buscar_registros_ponto(conn, funcionario_id: int, data_inicio: str, data_fim: str) -> List[Dict]:
    """Busca registros de ponto de um funcionário em um período"""
    cursor = conn.cursor(dictionary=True)
    try:
        cursor.execute(
            'SELECT id, funcionario_id, tipo, data_referencia, marcado_em, timezone '
            'FROM registros_ponto '
            'WHERE funcionario_id = %s AND data_referencia >= %s AND data_referencia <= %s '
            'ORDER BY marcado_em ASC',
            (funcionario_id, data_inicio, data_fim)
        )
        return cursor.fetchall()
    finally:
        cursor.close()


def buscar_afastamentos(conn, funcionario_id: int, data_inicio: str, data_fim: str) -> List[Dict]:
    """Busca afastamentos de um funcionário em um período"""
    cursor = conn.cursor(dictionary=True)
    try:
        cursor.execute(
            'SELECT id, funcionario_id, tipo_afastamento, motivo, data_inicio, data_fim '
            'FROM afastamentos '
            'WHERE funcionario_id = %s AND data_inicio <= %s AND data_fim >= %s AND ativo = 1 '
            'ORDER BY data_inicio ASC',
            (funcionario_id, data_fim, data_inicio)
        )
        return cursor.fetchall()
    finally:
        cursor.close()


def buscar_ajustes_ponto(conn, funcionario_id: int, data_inicio: str, data_fim: str) -> Dict[str, bool]:
    """Busca ajustes de ponto aprovados"""
    cursor = conn.cursor(dictionary=True)
    try:
        cursor.execute(
            'SELECT funcionario_id, data_referencia '
            'FROM solicitacoes_ajuste_ponto '
            'WHERE funcionario_id = %s AND status = "aprovada" AND data_referencia >= %s AND data_referencia <= %s',
            (funcionario_id, data_inicio, data_fim)
        )

        ajustes = {}
        for row in cursor.fetchall():
            data = str(row['data_referencia'])
            ajustes[data] = True
        return ajustes
    finally:
        cursor.close()


def agrupar_registros_por_dia(registros: List[Dict]) -> Dict[str, Dict[str, Dict]]:
    """Agrupa registros por funcionário e dia"""
    agrupados = {}

    for registro in registros:
        fid = int(registro['funcionario_id'])
        data = str(registro['data_referencia'])
        tipo = str(registro['tipo'])

        if fid not in agrupados:
            agrupados[fid] = {}

        if data not in agrupados[fid]:
            agrupados[fid][data] = {}

        agrupados[fid][data][tipo] = {
            'marcado_em': str(record['marcado_em']) if record.get('marcado_em') else None
        }

    return agrupados


def agrupar_afastamentos(afastamentos: List[Dict]) -> Dict[str, Dict[str, Dict]]:
    """Agrupa afastamentos por funcionário e data"""
    agrupados = {}

    for afastamento in afastamentos:
        fid = int(afastamento['funcionario_id'])
        data_inicio = datetime.fromisoformat(str(afastamento['data_inicio']))
        data_fim = datetime.fromisoformat(str(afastamento['data_fim']))

        if fid not in agrupados:
            agrupados[fid] = {}

        # Preencher cada dia do afastamento
        data_atual = data_inicio
        while data_atual <= data_fim:
            data_str = data_atual.strftime('%Y-%m-%d')
            agrupados[fid][data_str] = {
                'tipo_afastamento': afastamento['tipo_afastamento'],
                'motivo': afastamento['motivo']
            }
            data_atual += timedelta(days=1)

    return agrupados


def montar_linhas_espelho(
    inicio: datetime,
    fim: datetime,
    dias_registros: Dict[str, Dict],
    afastamentos_dia: Dict[str, Dict],
    ajustes_dia: Dict[str, bool],
    cargo: Optional[str] = None,
    saldo_inicial: int = 0,
    feriados: Optional[Dict] = None
) -> Tuple[List[Dict], Dict[str, int], int]:
    """Monta as linhas do espelho de ponto"""
    if feriados is None:
        feriados = {}

    linhas = []
    saldo_acumulado = saldo_inicial
    totais = {
        'trabalhado': 0,
        'intervalo': 0,
        'credito': 0,
        'debito': 0,
    }

    dia_atual = inicio
    dias_semana = ['Seg', 'Ter', 'Qua', 'Qui', 'Sex', 'Sab', 'Dom']

    while dia_atual <= fim:
        data = dia_atual.strftime('%Y-%m-%d')
        dia_semana_num = dia_atual.weekday() + 1  # 1-7 (seg-dom)

        registros_dia = dias_registros.get(data, {})
        tem_registro_dia = bool(registros_dia)
        afastamento = afastamentos_dia.get(data)
        nome_feriado = eh_feriado_belo_horizonte(data, feriados)
        dia_sem_expediente = dia_semana_num >= 6 or nome_feriado is not None

        esperado = 0 if dia_sem_expediente else segundos_esperados_para_data(data, cargo, feriados)
        trabalhado = segundos_trabalhados_dia(registros_dia, cargo)
        intervalo_dia = segundos_intervalo_dia(registros_dia)
        saldo_dia = trabalhado - (0 if afastamento else esperado)
        credito = max(0, saldo_dia)
        debito = max(0, -saldo_dia)
        saldo_acumulado += saldo_dia

        observacao = ''

        if data == inicio.strftime('%Y-%m-%d') and saldo_inicial != 0:
            observacao = f'Ajuste saldo inicial banco: {formatar_saldo_espelho(saldo_inicial)}'

        if afastamento:
            observacao = f"{observacao}{'|' if observacao else ''} {afastamento.get('tipo_afastamento', 'Afastamento')} {afastamento.get('motivo', '')}".strip()
        elif nome_feriado and not tem_registro_dia:
            observacao = f"{observacao}{'|' if observacao else ''} Feriado - {nome_feriado}".strip()
        elif nome_feriado and tem_registro_dia:
            observacao = f"{observacao}{'|' if observacao else ''} Feriado trabalhado - {nome_feriado}".strip()
        elif dia_semana_num >= 6:
            observacao = f"{observacao}{'|' if observacao else ''} {'Folga trabalhada' if tem_registro_dia else 'Folga'}".strip()
        elif not tem_registro_dia:
            observacao = f"{observacao}{'|' if observacao else ''} Sem registro".strip()

        avisos = []
        atraso = segundos_atraso_chegada_dia(registros_dia, cargo)
        if atraso > 0:
            avisos.append(f'Está atrasado: {formatar_segundos(atraso)}')

        excesso = segundos_excesso_intervalo_dia(registros_dia, cargo)
        if excesso > 0:
            avisos.append(f'Atraso intervalo: {formatar_segundos(excesso)}')

        if data in ajustes_dia:
            avisos.append('Ajuste de ponto aprovado')

        if avisos:
            observacao = f"{observacao}{'|' if observacao else ''} {' | '.join(avisos)}".strip()

        linhas.append({
            'data': f"{dia_atual.strftime('%d/%m')} {dias_semana[dia_semana_num - 1]}",
            'e1': '' if dia_sem_expediente and not tem_registro_dia else formatar_hora_ponto(registros_dia.get('chegada', {}).get('marcado_em')),
            's1': '' if dia_sem_expediente and not tem_registro_dia else formatar_hora_ponto(registros_dia.get('saida_almoco', {}).get('marcado_em')),
            'e2': '' if dia_sem_expediente and not tem_registro_dia else formatar_hora_ponto(registros_dia.get('volta_escritorio', {}).get('marcado_em')),
            's2': '' if dia_sem_expediente and not tem_registro_dia else formatar_hora_ponto(registros_dia.get('saida_lanche', {}).get('marcado_em')),
            'e3': '' if dia_sem_expediente and not tem_registro_dia else formatar_hora_ponto(registros_dia.get('volta_lanche', {}).get('marcado_em')),
            's3': '' if dia_sem_expediente and not tem_registro_dia else formatar_hora_ponto(registros_dia.get('saida_escritorio', {}).get('marcado_em')),
            'hnor': formatar_segundos(trabalhado),
            'intervalo': formatar_segundos(intervalo_dia),
            'credito': formatar_segundos(credito),
            'debito': formatar_segundos(debito),
            'saldo': formatar_saldo_espelho(saldo_acumulado),
            'observacao': observacao,
        })

        totais['trabalhado'] += trabalhado
        totais['intervalo'] += intervalo_dia
        totais['credito'] += credito
        totais['debito'] += debito

        dia_atual += timedelta(days=1)

    return linhas, totais, saldo_acumulado


def gerar_html_espelho(
    funcionario: Dict,
    linhas: List[Dict],
    totais: Dict,
    saldo_final: int,
    inicio: datetime,
    fim: datetime
) -> str:
    """Gera HTML do espelho de ponto"""
    linhas_html = '\n'.join([
        f'''                                <tr>
                                    <td class="date">{linha['data']}</td>
                                    <td>{linha['e1']}</td>
                                    <td>{linha['s1']}</td>
                                    <td>{linha['e2']}</td>
                                    <td>{linha['s2']}</td>
                                    <td>{linha['e3']}</td>
                                    <td>{linha['s3']}</td>
                                    <td>{linha['hnor']}</td>
                                    <td>{linha['intervalo']}</td>
                                    <td>{linha['credito']}</td>
                                    <td>{linha['debito']}</td>
                                    <td>{linha['saldo']}</td>
                                    <td class="obs">{linha['observacao']}</td>
                                </tr>'''
        for linha in linhas
    ])

    usuario = funcionario.get('usuario', 'Colaborador').replace('.', ' ').strip()

    html = f'''<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Espelho de Ponto</title>
    <style>
        * {{ box-sizing: border-box; }}
        body {{ font-family: Arial, sans-serif; color: #111; margin: 10px; font-size: 9px; }}
        .print-actions {{ margin-bottom: 10px; }}
        button {{ border: 1px solid #111; background: #111; color: #fff; border-radius: 4px; padding: 8px 12px; cursor: pointer; }}
        button:hover {{ background: #333; }}
        .employee-page {{ page-break-after: always; break-after: page; }}
        .employee-page:last-child {{ page-break-after: auto; }}
        .topbar {{ display: flex; justify-content: space-between; align-items: flex-start; border-bottom: 1px solid #111; padding-bottom: 3px; margin-bottom: 4px; }}
        .title {{ font-size: 13px; font-weight: 700; text-transform: uppercase; }}
        .subtitle {{ font-size: 10px; font-weight: 700; margin-top: 1px; }}
        .issued {{ text-align: right; color: #333; line-height: 1.1; font-size: 7.8px; }}
        .employee-info {{ background: #e3e3e3; border-radius: 2px; padding: 5px 7px; margin: 4px 0 5px; font-size: 8.8px; line-height: 1.25; }}
        .employee-info-title {{ font-weight: 700; border-bottom: 1px solid #fff; padding-bottom: 3px; margin-bottom: 4px; }}
        .employee-info-grid {{ display: grid; grid-template-columns: 1fr 1fr; gap: 1px 12px; }}
        .employee-info-grid div {{ white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }}
        .mirror {{ width: 100%; border-collapse: collapse; font-size: 7.8px; table-layout: fixed; margin: 4px 0 5px; }}
        .mirror th, .mirror td {{ border: 1px solid #999; padding: 1px; text-align: center; vertical-align: middle; line-height: 1.05; white-space: nowrap; }}
        .mirror th {{ background: #e9e9e9; font-size: 6.7px; white-space: normal; }}
        .mirror th:nth-child(2), .mirror th:nth-child(3), .mirror th:nth-child(4), .mirror th:nth-child(5), .mirror th:nth-child(6), .mirror th:nth-child(7),
        .mirror td:nth-child(2), .mirror td:nth-child(3), .mirror td:nth-child(4), .mirror td:nth-child(5), .mirror td:nth-child(6), .mirror td:nth-child(7) {{ width: 4.7%; }}
        .mirror th:nth-child(8), .mirror th:nth-child(9), .mirror th:nth-child(10), .mirror th:nth-child(11), .mirror th:nth-child(12),
        .mirror td:nth-child(8), .mirror td:nth-child(9), .mirror td:nth-child(10), .mirror td:nth-child(11), .mirror td:nth-child(12) {{ width: 5.1%; }}
        .mirror .date {{ text-align: left; width: 6.5%; }}
        .mirror .obs {{ text-align: left; width: 28%; white-space: normal; overflow-wrap: anywhere; font-size: 7.2px; }}
        .mirror tfoot td {{ font-weight: 700; background: #f4f4f4; }}
        .legend {{ display: grid; grid-template-columns: repeat(3, 1fr); gap: 1px 6px; margin-top: 3px; color: #333; font-size: 7.5px; }}
        @media print {{
            @page {{ size: A4 portrait; margin: 7mm; }}
            body {{ margin: 0; }}
            .print-actions {{ display: none; }}
        }}
    </style>
</head>
<body>
    <div class="print-actions">
        <button onclick="window.print()">Salvar/Imprimir PDF</button>
    </div>

    <section class="employee-page">
        <div class="topbar">
            <div>
                <div class="title">{usuario}</div>
                <div class="subtitle">Espelho de Ponto</div>
            </div>
            <div class="issued">
                Emitido em {datetime.now().strftime('%d/%m/%Y %H:%M:%S')}<br>
                Período: {inicio.strftime('%d/%m/%Y')} a {fim.strftime('%d/%m/%Y')}
            </div>
        </div>

        <div class="employee-info">
            <div class="employee-info-title">
                {usuario} -
                Período: {inicio.strftime('%d/%m/%y')} à {fim.strftime('%d/%m/%y')}
            </div>
            <div class="employee-info-grid">
                <div><strong>PIS/PASEP:</strong> {funcionario.get('pis_pasep', '')}</div>
                <div><strong>CPF:</strong> {funcionario.get('cpf', '')}</div>
                <div><strong>Nº de Folha:</strong> {funcionario.get('numero_folha', '')}</div>
                <div><strong>Função:</strong> {funcionario.get('cargo', '')}</div>
                <div><strong>Admissão:</strong> {funcionario.get('data_admissao', '')}</div>
                <div><strong>Departamento:</strong> {funcionario.get('departamento', '')}</div>
                <div><strong>Centro de Custo:</strong> {funcionario.get('centro_custo', '')}</div>
                <div><strong>Empresa:</strong> {funcionario.get('empresa_nome', '')}</div>
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
                {linhas_html}
            </tbody>
            <tfoot>
                <tr>
                    <td class="date">Totais</td>
                    <td colspan="6"></td>
                    <td>{formatar_segundos(totais['trabalhado'])}</td>
                    <td>{formatar_segundos(totais['intervalo'])}</td>
                    <td>{formatar_segundos(totais['credito'])}</td>
                    <td>{formatar_segundos(totais['debito'])}</td>
                    <td>{formatar_saldo_espelho(saldo_final)}</td>
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
    </section>
</body>
</html>'''

    return html


def gerar_pdf_do_html(html: str, arquivo_saida: str) -> bool:
    """Converte HTML para PDF usando weasyprint"""
    try:
        from weasyprint import HTML, CSS
        HTML(string=html).write_pdf(arquivo_saida)
        return True
    except Exception as e:
        print(f'Nota: Salvando como HTML em vez de PDF (weasyprint nao disponivel)', file=sys.stderr)
        html_file = arquivo_saida.replace('.pdf', '.html')
        with open(html_file, 'w', encoding='utf-8') as f:
            f.write(html)
        return False


def main():
    parser = argparse.ArgumentParser(description='Gera folha de ponto em PDF')
    parser.add_argument('funcionario_id', type=int, help='ID do funcionário')
    parser.add_argument('--mes', type=str, default=None, help='Mês (YYYY-MM), padrão: mês atual')
    parser.add_argument('--inicio', type=str, default=None, help='Data inicial (YYYY-MM-DD)')
    parser.add_argument('--fim', type=str, default=None, help='Data final (YYYY-MM-DD)')
    parser.add_argument('--saida', type=str, default=None, help='Arquivo de saída (padrão: espelho-ponto-{mes}.pdf)')

    args = parser.parse_args()

    # Determinar período
    if args.mes:
        ano, mes = map(int, args.mes.split('-'))
        inicio = datetime(ano, mes, 1)
        ultimo_dia = calendar.monthrange(ano, mes)[1]
        fim = datetime(ano, mes, ultimo_dia)
    elif args.inicio and args.fim:
        inicio = datetime.fromisoformat(args.inicio)
        fim = datetime.fromisoformat(args.fim)
    else:
        hoje = datetime.now()
        inicio = datetime(hoje.year, hoje.month, 1)
        ultimo_dia = calendar.monthrange(hoje.year, hoje.month)[1]
        fim = datetime(hoje.year, hoje.month, ultimo_dia)

    # Arquivo de saída
    if not args.saida:
        args.saida = f"espelho-ponto-{inicio.strftime('%Y-%m')}.pdf"

    print(f'Gerando folha de ponto para funcionário {args.funcionario_id}...')
    print(f'Período: {inicio.strftime("%d/%m/%Y")} a {fim.strftime("%d/%m/%Y")}')

    # Conectar ao banco
    conn = obter_conexao_banco()

    try:
        # Buscar dados
        funcionario = buscar_funcionario(conn, args.funcionario_id)
        if not funcionario:
            print(f'Funcionário {args.funcionario_id} não encontrado!', file=sys.stderr)
            sys.exit(1)

        data_inicio_str = inicio.strftime('%Y-%m-%d')
        data_fim_str = fim.strftime('%Y-%m-%d')

        registros = buscar_registros_ponto(conn, args.funcionario_id, data_inicio_str, data_fim_str)
        afastamentos = buscar_afastamentos(conn, args.funcionario_id, data_inicio_str, data_fim_str)
        ajustes = buscar_ajustes_ponto(conn, args.funcionario_id, data_inicio_str, data_fim_str)

        # Agrupar registros
        registros_agrupados = agrupar_registros_por_dia(registros)
        afastamentos_agrupados = agrupar_afastamentos(afastamentos)

        dias_registros = registros_agrupados.get(args.funcionario_id, {})
        afastamentos_dia = afastamentos_agrupados.get(args.funcionario_id, {})

        # Calcular feriados
        feriados = {}
        for ano in range(inicio.year, fim.year + 1):
            feriados.update(calcular_feriados_belo_horizonte(ano))

        # Montar espelho
        linhas, totais, saldo_final = montar_linhas_espelho(
            inicio,
            fim,
            dias_registros,
            afastamentos_dia,
            ajustes,
            funcionario.get('cargo'),
            feriados=feriados
        )

        # Gerar HTML
        html = gerar_html_espelho(funcionario, linhas, totais, saldo_final, inicio, fim)

        # Gerar PDF
        sucesso = gerar_pdf_do_html(html, args.saida)

        if sucesso:
            print(f'✓ PDF gerado com sucesso: {args.saida}')
        else:
            html_file = args.saida.replace('.pdf', '.html')
            print(f'✓ HTML gerado: {html_file}')

    finally:
        conn.close()


if __name__ == '__main__':
    main()
