# Setup Rápido - Gerador de Folha de Ponto

## 1. Instalar Python (se não tiver)

Baixe de https://www.python.org/downloads/ (versão 3.7+)

## 2. Instalar dependências

```bash
# Abra o terminal/prompt na pasta tools e execute:
pip install -r requirements.txt
```

Ou instale manualmente:
```bash
pip install mysql-connector-python
pip install weasyprint
```

## 3. Usar o script

### Windows
```bash
gerar_folha_ponto.bat 123 --mes 2026-05
```

### Linux/macOS
```bash
chmod +x gerar_folha_ponto.sh
./gerar_folha_ponto.sh 123 --mes 2026-05
```

### Python direto (qualquer sistema)
```bash
python gerar_folha_ponto_pdf.py 123 --mes 2026-05
```

## 4. Encontrar ID do funcionário

Consulte o banco de dados ou veja na URL do painel:
`painel?funcionario_id=123` → ID é 123

## Pronto! 🎉

Se der erro, verifique:
- [ ] Python instalado: `python --version`
- [ ] mysql-connector instalado: `pip show mysql-connector-python`
- [ ] Banco de dados acessível (localhost:3306)
- [ ] Credenciais corretas em `gerar_folha_ponto_pdf.py`
