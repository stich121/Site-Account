# Índice de Arquivos - Gerador de Folha de Ponto

## 📋 Descrição Geral

Solução completa em Python para gerar folhas de ponto em PDF, replicando exatamente o formato do painel web da Account Contabilidade.

## 📁 Arquivos Criados

### 1. **gerar_folha_ponto_pdf.py** ⭐ (PRINCIPAL)
- Script Python principal que gera a folha de ponto
- **Tamanho:** ~13 KB
- **Dependências:** mysql-connector-python, weasyprint (opcional)
- **Uso:** `python gerar_folha_ponto_pdf.py <id> [opções]`
- **Recursos:**
  - Cálculo automático de horas, intervalos, saldos
  - Detecção de feriados brasileiros (móveis e fixos)
  - Suporte para estagiários e funcionários regulares
  - Gera HTML ou PDF

### 2. **requirements.txt**
- Lista de dependências Python
- **Uso:** `pip install -r requirements.txt`
- **Conteúdo:**
  - mysql-connector-python>=8.0.33
  - weasyprint>=60.0

### 3. **gerar_folha_ponto.bat** (WINDOWS)
- Wrapper para executar o script no Windows
- **Uso:** `gerar_folha_ponto.bat 123 --mes 2026-05`
- **Benefícios:**
  - Verifica se Python está instalado
  - Fornece mensagens de erro claras
  - Mais fácil que chamar Python direto

### 4. **gerar_folha_ponto.sh** (LINUX/macOS)
- Wrapper para executar o script em sistemas Unix
- **Uso:** `./gerar_folha_ponto.sh 123 --mes 2026-05`
- **Setup:** `chmod +x gerar_folha_ponto.sh`
- **Benefícios:** Mesmo do .bat, mas para Unix

### 5. **test_conexao.py**
- Script para validar a instalação
- **Uso:** `python test_conexao.py`
- **Verifica:**
  - Versão do Python (3.7+)
  - mysql-connector instalado
  - weasyprint instalado
  - Conexão com banco de dados
  - Quantidade de funcionários

### 6. **README_GERAR_FOLHA_PONTO.md** 📖 (DOCUMENTAÇÃO)
- Documentação completa do projeto
- **Seções:**
  - Requisitos
  - Instalação
  - Uso básico e avançado
  - Parâmetros
  - Características
  - Troubleshooting
  - Próximas melhorias

### 7. **SETUP_RAPIDO.md** ⚡ (PARA INICIANTES)
- Guia de setup em 4 passos
- **Ideal para:** Primeira execução, novos usuários
- **Conteúdo:**
  - Instalar Python
  - Instalar dependências
  - Usar o script
  - Checklist de validação

### 8. **EXEMPLOS_USO.md** 💡 (REFERÊNCIA)
- Exemplos práticos de uso
- **Exemplos inclusos:**
  - Básico (1 funcionário, mês atual)
  - Intermediário (período customizado)
  - Avançado (batch, cron, integração)
  - Casos de uso reais
  - Scripts prontos para copiar/colar
  - Troubleshooting específico

### 9. **INDICE_ARQUIVOS.md** (ESTE ARQUIVO)
- Descrição de todos os arquivos
- Fluxo de trabalho recomendado

## 🚀 Quick Start (3 minutos)

### Primeiro uso:
```bash
# 1. Instale as dependências
pip install -r requirements.txt

# 2. Teste a conexão
python test_conexao.py

# 3. Gere sua primeira folha
python gerar_folha_ponto_pdf.py 123
```

### Próximos usos:
```bash
# Windows
gerar_folha_ponto.bat 123 --mes 2026-05

# Linux/macOS
./gerar_folha_ponto.sh 123 --mes 2026-05

# Ou Python direto
python gerar_folha_ponto_pdf.py 123 --mes 2026-05
```

## 📚 Fluxo de Leitura Recomendado

Para **novatos:**
1. SETUP_RAPIDO.md (primeiro!)
2. test_conexao.py (validar)
3. EXEMPLOS_USO.md (usar)

Para **desenvolvedores:**
1. README_GERAR_FOLHA_PONTO.md
2. Explorar gerar_folha_ponto_pdf.py
3. EXEMPLOS_USO.md (avançado)

Para **troubleshooting:**
1. EXEMPLOS_USO.md (seção Troubleshooting)
2. README_GERAR_FOLHA_PONTO.md (seção Resolução de Problemas)

## 🔧 Arquitetura do Script Python

```
gerar_folha_ponto_pdf.py
├── Configuração do banco (DB_CONFIG)
├── Funções de cálculo
│   ├── Horas trabalhadas
│   ├── Intervalos
│   ├── Saldos
│   └── Feriados
├── Funções de formatação
│   ├── Segundos → HHhMM
│   ├── Datas
│   └── Saldos
├── Funções de banco de dados
│   ├── Buscar funcionário
│   ├── Buscar registros de ponto
│   ├── Buscar afastamentos
│   └── Buscar ajustes
├── Funções de processamento
│   ├── Agrupar registros
│   ├── Montar linhas do espelho
│   └── Gerar HTML
├── Geração de PDF
│   ├── HTML → PDF (weasyprint)
│   └── Fallback para HTML
└── main() - Orquestração
```

## 🔐 Segurança

⚠️ **IMPORTANTE:**
- As credenciais do banco estão hardcoded no arquivo
- Para produção, considere:
  - Variáveis de ambiente
  - Arquivo de configuração separado
  - Cifrar a senha
  - Usar arquivo de config não versionado

**Para alterar credenciais:**
```python
# Edite DB_CONFIG no início do arquivo
DB_CONFIG = {
    'host': '127.0.0.1',  # ← seu host
    'user': 'usuario',     # ← seu usuário
    'password': 'senha',   # ← sua senha
    'database': 'banco',   # ← seu banco
    'charset': 'utf8mb4',
    'autocommit': True
}
```

## 📊 Dados Processados

O script acessa as seguintes tabelas:
- `funcionarios` (dados pessoais, cargo, etc)
- `registros_ponto` (batidas de entrada/saída)
- `afastamentos` (licenças, férias, etc)
- `solicitacoes_ajuste_ponto` (ajustes aprovados)

## 📋 Saída do Script

### Se weasyprint instalado:
- ✅ Arquivo `.pdf` pronto para imprimir

### Se weasyprint NÃO instalado:
- ⚠️ Arquivo `.html`
- Abra no navegador: Ctrl+P ou Cmd+P
- Salve como PDF

## 🎯 Casos de Uso

| Caso | Comando |
|------|---------|
| Folha do mês atual | `gerar_folha_ponto_pdf.py 123` |
| Folha de maio | `gerar_folha_ponto_pdf.py 123 --mes 2026-05` |
| Período customizado | `gerar_folha_ponto_pdf.py 123 --inicio 2026-04-15 --fim 2026-05-14` |
| Salvar com nome custom | `gerar_folha_ponto_pdf.py 123 --saida meu-arquivo.pdf` |
| Múltiplos funcionários | Ver EXEMPLOS_USO.md |
| Agendado (cron) | Ver EXEMPLOS_USO.md |
| Integrado em outro script | Ver EXEMPLOS_USO.md |

## ✨ Recursos Principais

✅ **Cálculos Automáticos:**
- Horas normais trabalhadas
- Intervalo diário
- Banco de horas (crédito/débito)
- Saldo acumulado

✅ **Detecção Automática:**
- Feriados brasileiros (fixos + móveis)
- Domingos e sábados
- Estagiários vs. funcionários
- Ajustes de ponto aprovados
- Afastamentos

✅ **Formatação:**
- Exatamente igual ao painel web
- Suporta uma página por funcionário
- Pronto para imprimir

## 🐛 Conhecidos Limitações

- Apenas para Belo Horizonte (feriados hardcoded)
- Uma página por funcionário
- Sem assinatura digital
- Sem envio automático de email (mas pode integrar)

## 🚦 Versão Atual

- **Versão:** 1.0
- **Data:** Junho 2026
- **Compatibilidade:** Python 3.7+
- **Bancos suportados:** MySQL/MariaDB

## 📞 Suporte

Para dúvidas:
1. Leia SETUP_RAPIDO.md
2. Execute test_conexao.py
3. Verifique EXEMPLOS_USO.md
4. Revise README_GERAR_FOLHA_PONTO.md

## 📝 Changelog

### v1.0 (2026-06-18)
- ✨ Release inicial
- 🎯 Funcionalidade completa
- 📖 Documentação extensa
- 🧪 Script de teste
- 🔧 Wrappers para Windows/Linux

## 🎉 Próximas Features (Roadmap)

- [ ] Suporte a múltiplos locais (cálculo customizável de feriados)
- [ ] Geração em batch para todos os funcionários
- [ ] Envio automático por email
- [ ] Agendamento automático (scheduler)
- [ ] Assinatura digital
- [ ] Suporte a PostgreSQL
- [ ] Interface gráfica (GUI)
- [ ] API REST para integração

---

**Criado para:** Account Contabilidade  
**Funcionalidade:** Gerar folhas de ponto em PDF  
**Repositório:** Site-Account
