# Exemplos de Uso - Gerador de Folha de Ponto

## Básico

### Gerar folha do mês atual
```bash
python gerar_folha_ponto_pdf.py 123
```

### Gerar para mês específico
```bash
python gerar_folha_ponto_pdf.py 123 --mes 2026-05
```

## Intermediário

### Com arquivo de saída customizado
```bash
python gerar_folha_ponto_pdf.py 123 --mes 2026-05 --saida my-folder/maio-2026.pdf
```

### Período customizado (não alinhado ao mês)
```bash
python gerar_folha_ponto_pdf.py 123 --inicio 2026-04-15 --fim 2026-05-14
```

### Folha recente (últimos 30 dias)
```bash
python gerar_folha_ponto_pdf.py 123 --inicio 2026-05-19 --fim 2026-06-18
```

## Avançado

### Batch - Gerar para múltiplos funcionários (Windows)
```batch
@echo off
for /f "tokens=1" %%a in (funcionarios.txt) do (
    python gerar_folha_ponto_pdf.py %%a --mes 2026-05 --saida folhas\folha-%%a.pdf
)
```

### Batch - Gerar para múltiplos funcionários (Linux/macOS)
```bash
#!/bin/bash
while IFS= read -r id; do
    python gerar_folha_ponto_pdf.py "$id" --mes 2026-05 --saida "folhas/folha-$id.pdf"
done < funcionarios.txt
```

### Cron - Agendamento automático (Linux/macOS)
```cron
# Gera folha do mês anterior, todo 1º do mês às 08:00
0 8 1 * * cd /path/to/tools && python gerar_folha_ponto_pdf.py 123 --mes $(date -d 'last month' +%Y-%m) --saida ~/folhas/$(date -d 'last month' +%Y-%m).pdf
```

### Python - Integração com outro script
```python
import subprocess
import sys

funcionarios = [1, 2, 3, 4, 5]
mes = "2026-05"

for fid in funcionarios:
    resultado = subprocess.run([
        sys.executable,
        "gerar_folha_ponto_pdf.py",
        str(fid),
        "--mes", mes,
        "--saida", f"folhas/folha-{fid}.pdf"
    ])
    
    if resultado.returncode == 0:
        print(f"✓ Folha do funcionário {fid} gerada")
    else:
        print(f"✗ Erro ao gerar folha do funcionário {fid}")
```

## Casos de Uso

### Relatório mensal de todos os funcionários
```bash
# 1. Crie um arquivo com os IDs
echo 1 > ids.txt
echo 2 >> ids.txt
echo 3 >> ids.txt

# 2. Gere as folhas
for id in $(cat ids.txt); do
    python gerar_folha_ponto_pdf.py $id --mes 2026-05 --saida folhas/folha-$id.pdf
done

# 3. Abra a pasta com as folhas
open folhas/  # macOS
xdg-open folhas/  # Linux
start folhas/  # Windows
```

### Folha de ponto para entrega ao contador
```bash
# Gera folha do mês anterior (útil para executar no 1º dia do mês)
LAST_MONTH=$(date -d 'last month' +%Y-%m)
python gerar_folha_ponto_pdf.py 123 --mes $LAST_MONTH --saida contador-$LAST_MONTH.pdf
```

### Validar registros antes de gerar
```python
#!/usr/bin/env python3
"""Script para validar dados antes de gerar folhas"""

import mysql.connector

conn = mysql.connector.connect(
    host='127.0.0.1',
    user='u654041352_Matheus',
    password='Stich@121',
    database='u654041352_Clientes'
)

cursor = conn.cursor(dictionary=True)

# Verificar funcionários sem registros
cursor.execute("""
    SELECT f.id, f.usuario
    FROM funcionarios f
    WHERE f.permite_ponto = 1
    AND NOT EXISTS (
        SELECT 1 FROM registros_ponto WHERE funcionario_id = f.id
    )
""")

sem_registros = cursor.fetchall()
if sem_registros:
    print("Funcionários SEM registros de ponto:")
    for f in sem_registros:
        print(f"  - ID {f['id']}: {f['usuario']}")

# Verificar registros órfãos
cursor.execute("""
    SELECT COUNT(*) as total FROM registros_ponto
    WHERE funcionario_id NOT IN (SELECT id FROM funcionarios)
""")

orfaos = cursor.fetchone()['total']
print(f"\nRegistros órfãos: {orfaos}")

cursor.close()
conn.close()
```

### Enviar folhas por email (integração)
```python
#!/usr/bin/env python3
"""Gera e envia folhas por email"""

import subprocess
import smtplib
from pathlib import Path
from email.mime.multipart import MIMEMultipart
from email.mime.base import MIMEBase
from email.mime.text import MIMEText
from email import encoders

def gerar_e_enviar(funcionario_id, email_destino, mes):
    # Gera PDF
    pdf_path = f"folha-{mes}.pdf"
    subprocess.run([
        "python", "gerar_folha_ponto_pdf.py",
        str(funcionario_id),
        "--mes", mes,
        "--saida", pdf_path
    ])
    
    # Envia email
    msg = MIMEMultipart()
    msg['From'] = 'seu_email@gmail.com'
    msg['To'] = email_destino
    msg['Subject'] = f'Folha de Ponto - {mes}'
    
    corpo = f"Segue anexa sua folha de ponto de {mes}."
    msg.attach(MIMEText(corpo, 'plain'))
    
    with open(pdf_path, 'rb') as anexo:
        parte = MIMEBase('application', 'octet-stream')
        parte.set_payload(anexo.read())
        encoders.encode_base64(parte)
        parte.add_header('Content-Disposition', f'attachment; filename= {pdf_path}')
        msg.attach(parte)
    
    # SMTP (exemplo com Gmail)
    # servidor = smtplib.SMTP('smtp.gmail.com', 587)
    # servidor.starttls()
    # servidor.login('seu_email@gmail.com', 'sua_senha_app')
    # servidor.send_message(msg)
    # servidor.quit()

# Uso
# gerar_e_enviar(123, 'funcionario@email.com', '2026-05')
```

## Troubleshooting

### Script não encontra mysql
```bash
pip install --upgrade mysql-connector-python
```

### PDF vazio
```bash
# Use HTML em vez de PDF
python gerar_folha_ponto_pdf.py 123 --saida folha.html
# Depois abra no navegador: File > Print > Save as PDF
```

### Permissão negada no Linux
```bash
chmod +x gerar_folha_ponto.sh
```

### Erro de encoding
```bash
# Force UTF-8
export PYTHONIOENCODING=utf-8
python gerar_folha_ponto_pdf.py 123
```

## Dicas

💡 **Salve um template de funcionários em arquivo:**
```bash
# Salve os IDs
echo "1,2,3,4,5" > IDs.txt

# Use em um script
for id in $(cat IDs.txt | tr ',' '\n'); do
    echo "Gerando folha para $id..."
    python gerar_folha_ponto_pdf.py $id --mes 2026-05
done
```

💡 **Adicione a ferramenta ao PATH (Windows):**
- Copie `gerar_folha_ponto.bat` para uma pasta no PATH
- Ou adicione o diretório ao PATH nas variáveis de ambiente
- Depois pode usar de qualquer pasta: `gerar_folha_ponto.bat 123`

💡 **Crie um atalho no Windows:**
- Clique direito em `gerar_folha_ponto.bat`
- Criar atalho
- Coloque na área de trabalho ou menu Iniciar
