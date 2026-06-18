#!/usr/bin/env python3
"""
Script de teste para validar a conexão com o banco de dados
e verificar se as dependências estão instaladas
"""

import sys
from pathlib import Path

print('=' * 60)
print('TESTE DE CONFIGURACAO - Gerador de Folha de Ponto')
print('=' * 60)

# 1. Verificar Python
print('\n[1/4] Verificando versao do Python...')
py_version = sys.version_info
if py_version.major >= 3 and py_version.minor >= 7:
    print(f'[OK] Python {py_version.major}.{py_version.minor}')
else:
    print(f'[ERRO] Python {py_version.major}.{py_version.minor} (requer 3.7+)')
    sys.exit(1)

# 2. Verificar mysql-connector
print('\n[2/4] Verificando mysql-connector-python...')
try:
    import mysql.connector
    print(f'[OK] mysql-connector-python instalado')
except ImportError:
    print('[ERRO] mysql-connector-python nao instalado')
    print('  Instale com: pip install mysql-connector-python')
    sys.exit(1)

# 3. Verificar weasyprint
print('\n[3/4] Verificando weasyprint...')
try:
    import weasyprint
    print(f'[OK] weasyprint instalado')
    print('  PDFs serao gerados em alta qualidade')
except Exception as e:
    print('[AVISO] weasyprint nao disponivel (OPCIONAL)')
    print('  Sem weasyprint, o script gera HTML em vez de PDF')
    print('  Para converter, abra o HTML no navegador: Ctrl+P > Salvar como PDF')
    if 'libgobject' in str(e) or 'OSError' in str(e.__class__.__name__):
        print('  [Windows] Se quiser PDF: instale GTK+ ou use alternativa')

# 4. Testar conexao com banco
print('\n[4/4] Testando conexao com banco de dados...')

DB_CONFIG = {
    'host': '127.0.0.1',
    'user': 'u654041352_Matheus',
    'password': 'Stich@121',
    'database': 'u654041352_Clientes',
    'charset': 'utf8mb4',
    'autocommit': True
}

try:
    conn = mysql.connector.connect(**DB_CONFIG)
    cursor = conn.cursor(dictionary=True)

    # Teste simples
    cursor.execute('SELECT COUNT(*) as total FROM funcionarios')
    resultado = cursor.fetchone()
    total_funcionarios = resultado['total']

    print(f'[OK] Conexao com banco bem-sucedida')
    print(f'  Total de funcionarios: {total_funcionarios}')

    # Listar alguns funcionários
    cursor.execute('SELECT id, usuario, cargo FROM funcionarios LIMIT 5')
    funcionarios = cursor.fetchall()

    if funcionarios:
        print('\n  Primeiros funcionarios:')
        for f in funcionarios:
            print(f"    - ID {f['id']}: {f['usuario']} ({f['cargo']})")

    cursor.close()
    conn.close()

except Exception as e:
    print(f'[ERRO] Erro ao conectar ao banco: {e}')
    print('\n  Verifique:')
    print('  - Se o MySQL esta rodando')
    print('  - Se as credenciais estao corretas em gerar_folha_ponto_pdf.py')
    print('  - Se o host/porta estao acessiveis (127.0.0.1:3306)')
    sys.exit(1)

# Resumo
print('\n' + '=' * 60)
print('RESULTADO: Tudo pronto!')
print('=' * 60)
print('\nVoce pode usar o gerador de folha de ponto:')
print('\n  Windows:')
print('    gerar_folha_ponto.bat 123 --mes 2026-05')
print('\n  Linux/macOS:')
print('    ./gerar_folha_ponto.sh 123 --mes 2026-05')
print('\n  Python direto:')
print('    python gerar_folha_ponto_pdf.py 123 --mes 2026-05')
print('\n')
