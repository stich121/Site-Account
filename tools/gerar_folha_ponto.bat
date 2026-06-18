@echo off
REM Script para gerar folha de ponto no Windows
REM Uso: gerar_folha_ponto.bat <funcionario_id> [--mes YYYY-MM] [--saida arquivo.pdf]

setlocal enabledelayedexpansion

if "%1"=="" (
    echo Uso: gerar_folha_ponto.bat <funcionario_id> [--mes YYYY-MM] [--saida arquivo.pdf]
    echo.
    echo Exemplos:
    echo   gerar_folha_ponto.bat 123
    echo   gerar_folha_ponto.bat 123 --mes 2026-05
    echo   gerar_folha_ponto.bat 123 --mes 2026-05 --saida espelho.pdf
    exit /b 1
)

REM Verifica se Python está instalado
python --version >nul 2>&1
if %errorlevel% neq 0 (
    echo Erro: Python nao encontrado. Instale Python 3.7 ou superior.
    exit /b 1
)

REM Verifica se o script existe
if not exist "%~dp0gerar_folha_ponto_pdf.py" (
    echo Erro: gerar_folha_ponto_pdf.py nao encontrado em %~dp0
    exit /b 1
)

REM Executa o script
cd /d "%~dp0"
python gerar_folha_ponto_pdf.py %*

if %errorlevel% equ 0 (
    echo.
    echo Concluido com sucesso!
) else (
    echo.
    echo Erro ao gerar folha de ponto.
)

exit /b %errorlevel%
