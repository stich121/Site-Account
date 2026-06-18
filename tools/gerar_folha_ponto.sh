#!/bin/bash
# Script para gerar folha de ponto no Linux/macOS
# Uso: ./gerar_folha_ponto.sh <funcionario_id> [--mes YYYY-MM] [--saida arquivo.pdf]

set -e

# Verifica se Python está instalado
if ! command -v python3 &> /dev/null; then
    echo "Erro: Python 3 não encontrado. Instale Python 3.7 ou superior."
    exit 1
fi

# Verifica os argumentos
if [ $# -eq 0 ]; then
    echo "Uso: $0 <funcionario_id> [--mes YYYY-MM] [--saida arquivo.pdf]"
    echo ""
    echo "Exemplos:"
    echo "  $0 123"
    echo "  $0 123 --mes 2026-05"
    echo "  $0 123 --mes 2026-05 --saida espelho.pdf"
    exit 1
fi

# Obtém diretório do script
SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"

# Verifica se o script Python existe
if [ ! -f "$SCRIPT_DIR/gerar_folha_ponto_pdf.py" ]; then
    echo "Erro: gerar_folha_ponto_pdf.py não encontrado em $SCRIPT_DIR"
    exit 1
fi

# Executa o script
cd "$SCRIPT_DIR"
python3 gerar_folha_ponto_pdf.py "$@"

if [ $? -eq 0 ]; then
    echo ""
    echo "Concluído com sucesso!"
else
    echo ""
    echo "Erro ao gerar folha de ponto."
    exit 1
fi
