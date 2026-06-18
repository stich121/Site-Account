# Gerador de Folha de Ponto em PDF

Script Python que gera folhas de ponto em PDF do sistema de pontos, replicando exatamente o formato do painel web.

## Requisitos

- Python 3.7+
- MySQL/MariaDB acessível
- Bibliotecas Python:
  - `mysql-connector-python` (obrigatório)
  - `weasyprint` (opcional, para gerar PDF; sem ela gera HTML)

## Instalação

### 1. Instalar dependências obrigatórias

```bash
pip install mysql-connector-python
```

### 2. Instalar weasyprint (opcional, recomendado para PDF)

```bash
pip install weasyprint
```

**Nota:** O `weasyprint` tem dependências adicionais:
- **Windows:** Instale o GTK+ para Windows (http://ftp.gnome.org/pub/GNOME/binaries/win64/) ou use `pip install pypiwin32`
- **Ubuntu/Debian:** `sudo apt-get install libffi-dev libjpeg-dev zlib1g-dev`
- **macOS:** `brew install python3 libffi libjpeg openjpeg`

Se o `weasyprint` não estiver disponível, o script gera um arquivo HTML que pode ser convertido em PDF pelo navegador (Arquivo > Salvar como PDF).

## Uso

### Comando básico - mês atual

```bash
python gerar_folha_ponto_pdf.py 123
```

Gera `espelho-ponto-2026-06.pdf` para o funcionário ID 123

### Especificar mês

```bash
python gerar_folha_ponto_pdf.py 123 --mes 2026-05
```

Gera folha de ponto para maio/2026

### Especificar período customizado

```bash
python gerar_folha_ponto_pdf.py 123 --inicio 2026-05-01 --fim 2026-05-31
```

### Especificar arquivo de saída

```bash
python gerar_folha_ponto_pdf.py 123 --mes 2026-05 --saida espelho-maio.pdf
```

## Parâmetros

| Parâmetro | Descrição | Exemplo |
|-----------|-----------|---------|
| `funcionario_id` | ID do funcionário (obrigatório) | `123` |
| `--mes` | Mês no formato YYYY-MM | `2026-05` |
| `--inicio` | Data inicial (YYYY-MM-DD) | `2026-05-01` |
| `--fim` | Data final (YYYY-MM-DD) | `2026-05-31` |
| `--saida` | Caminho do arquivo de saída | `relatorio.pdf` |

## Características

✓ Calcula automaticamente:
- Horas trabalhadas por dia
- Intervalos (almoço, lanche)
- Excesso de intervalo
- Atraso na chegada
- Saldo acumulado (crédito/débito)
- Banco de horas

✓ Identifica automaticamente:
- Feriados em Belo Horizonte (incluindo móveis)
- Domingos e sábados
- Afastamentos
- Ajustes de ponto aprovados
- Estagiários vs. funcionários regulares

✓ Exibe informações do funcionário:
- Nome, CPF, PIS
- Cargo, departamento, centro de custo
- Número de folha
- Data de admissão

## Configuração do Banco de Dados

O script usa as credenciais em `config_db.php`. Para alterar, edite o dicionário `DB_CONFIG` no arquivo:

```python
DB_CONFIG = {
    'host': '127.0.0.1',
    'user': 'seu_usuario',
    'password': 'sua_senha',
    'database': 'nome_banco',
    'charset': 'utf8mb4',
    'autocommit': True
}
```

## Exemplos de Uso

### Gerar folha de ponto do mês atual
```bash
python gerar_folha_ponto_pdf.py 42
```

### Gerar para todos os funcionários de um mês
```bash
for id in 1 2 3 4 5; do
    python gerar_folha_ponto_pdf.py $id --mes 2026-05 --saida espelho-$id.pdf
done
```

### Gerar com período customizado
```bash
python gerar_folha_ponto_pdf.py 123 \
    --inicio 2026-04-15 \
    --fim 2026-05-14 \
    --saida folha-quinzenal.pdf
```

## Saída

### Se weasyprint estiver instalado:
- Gera arquivo PDF pronto para imprimir

### Se weasyprint NÃO estiver instalado:
- Gera arquivo HTML
- Abra no navegador e use Ctrl+P ou Arquivo > Salvar como PDF

## Estrutura da Folha de Ponto

A folha exibe:

| Coluna | Significado |
|--------|-------------|
| Data | Dia da semana e data |
| Chegada | Hora de chegada ao escritório |
| Saída almoço | Hora de saída para almoço |
| Volta almoço | Hora de volta do almoço |
| Saída lanche | Hora de saída para lanche |
| Volta lanche | Hora de volta do lanche |
| Saída escritório | Hora de saída final |
| H.NOR | Horas normais (tempo trabalhado) |
| I.DIÁ | Intervalo diário |
| B.CRÉ | Banco de horas a crédito (excesso trabalhado) |
| B.DÉB | Banco de horas a débito (falta) |
| S.BAN | Saldo do banco (acumulado) |
| OBSER | Observações (feriados, ajustes, etc) |

## Resolução de Problemas

### Erro: "ModuleNotFoundError: No module named 'mysql.connector'"
```bash
pip install mysql-connector-python
```

### Erro: "Can't connect to MySQL server"
- Verifique se o MySQL está rodando
- Verifique credenciais em `DB_CONFIG`
- Verifique host/porta

### O PDF fica em branco
- Instale weasyprint: `pip install weasyprint`
- Use o arquivo HTML em vez disso

### Funcionário não encontrado
- Verifique se o ID do funcionário existe
- Verifique se o funcionário tem `permite_ponto = 1`

## Logs e Debugging

Para ver mais detalhes de execução, adicione ao script:
```python
import logging
logging.basicConfig(level=logging.DEBUG)
```

## Próximas melhorias

- [ ] Suporte a múltiplos funcionários em um único arquivo
- [ ] Assinatura digital
- [ ] Envio por email automático
- [ ] Agendamento (cron)
- [ ] Geração em batch para todos os funcionários
- [ ] Suporte a outros locais (cálculo de feriados customizável)

## Licença

Uso interno - Account Contabilidade

## Suporte

Para dúvidas ou problemas, revise a saída do console e verifique a conexão com o banco de dados.
