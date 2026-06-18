# Setup Python - Integração com Gerador de Folha de Ponto

## 🚀 Setup Rápido (Recomendado)

Execute este comando UMA VEZ para instalar todas as dependências:

```bash
pip install -r tools/requirements.txt
```

## 📋 O que será instalado

- **mysql-connector-python** - Conexão com MySQL/MariaDB
- **weasyprint** - Geração de PDF (qualidade profissional)

## ✅ Validar Instalação

Execute para validar:

```bash
python tools/test_conexao.py
```

Deve mostrar:
```
✓ Python 3.7+ (OK)
✓ mysql-connector-python instalado (OK)
✓ weasyprint instalado (OK)
✓ Conexão com banco bem-sucedida (OK)
```

## 🎯 Como Funciona Agora

### Fluxo Antigo ❌
1. Clica em "PDF do mês" → PHP gera HTML no navegador → Salva como PDF manualmente

### Fluxo Novo ✅
1. Clica em "PDF do mês" → PHP chama script Python
2. Python gera PDF automaticamente
3. Download automático do PDF

## 📝 Arquivo de Integração

**`gerar-pdf-ponto.php`** - Arquivo intermediário que:
- Recebe requisição do painel
- Valida autenticação
- Chama o script Python
- Retorna o PDF gerado
- Faz download automático

## 🔧 Configuração

### Se Python está em local customizado

Edite `gerar-pdf-ponto.php`:

```php
// Linha ~32
$pythonCmd = strpos(PHP_OS, 'WIN') === 0 ? 'python' : 'python3';

// Mude para:
$pythonCmd = '/caminho/completo/para/python3';
```

### Se as credenciais do banco mudarem

Edite `tools/gerar_folha_ponto_pdf.py`:

```python
# Linha ~20
DB_CONFIG = {
    'host': 'seu_host',
    'user': 'seu_usuario',
    'password': 'sua_senha',
    'database': 'seu_banco',
    ...
}
```

## 🐛 Troubleshooting

### "Python não está disponível"
```bash
# Verifique se Python está instalado
python --version

# Ou use python3
python3 --version

# Se não tiver, instale de https://www.python.org
```

### "ModuleNotFoundError: No module named 'mysql.connector'"
```bash
# Reinstale as dependências
pip install --upgrade -r tools/requirements.txt
```

### "ModuleNotFoundError: No module named 'weasyprint'"
```bash
# Instale weasyprint
pip install weasyprint

# Se falhar, use a alternativa HTML (sem weasyprint)
# O arquivo será salvo como HTML e você pode imprimir como PDF
```

### PDF vazio ou quebrado
```bash
# Verifique os logs
python tools/test_conexao.py

# Se a conexão passou, teste o script diretamente
python tools/gerar_folha_ponto_pdf.py 123 --mes 2026-05
```

### "Permissão negada" (Linux/Mac)
```bash
# Dê permissão ao script shell
chmod +x tools/gerar_folha_ponto.sh
```

## 📊 Performance

- **Tempo para gerar PDF:** ~1-2 segundos por funcionário
- **Tamanho do PDF:** ~50-100 KB
- **Limite:** Praticamente ilimitado

## 🔐 Segurança

✅ **Verificações implementadas:**
- Autenticação via sessão PHP
- Validação de datas
- Escape de parâmetros
- Limpeza de arquivos temporários
- Restrição por permissão (admin ou próprio usuário)

## 📱 Compatibilidade

| Sistema | Python | Status |
|---------|--------|--------|
| Windows | 3.7+ | ✅ Totalmente suportado |
| Linux | 3.7+ | ✅ Totalmente suportado |
| macOS | 3.7+ | ✅ Totalmente suportado |
| Hospedagem compartilhada | 3.7+ | ⚠️ Pode precisar de suporte técnico |

## 🆘 Suporte na Hospedagem

Se usar hospedagem compartilhada (tipo Hostinger, HostGator):

1. **Verificar se Python está disponível:**
   - Contate o suporte
   - Peça para verificar se Python 3.7+ está instalado
   - Peça para instalar `mysql-connector-python` e `weasyprint`

2. **Alternativa (sem Python):**
   - Continue usando o fluxo HTML/PDF anterior
   - Abra no navegador, pressione Ctrl+P, salve como PDF

## 🎉 Depois de Configurado

Agora quando alguém clica em "PDF do mês" ou "Meu PDF":
- ✅ PDF é gerado automaticamente
- ✅ Download começa imediatamente
- ✅ Ninguém precisa clicar em "Salvar como PDF"

## 💡 Dicas

**Para criar um atalho rápido de testes:**

```bash
# Windows - crie um arquivo test.bat
python tools/test_conexao.py

# Linux/Mac - crie um arquivo test.sh
#!/bin/bash
python3 tools/test_conexao.py
```

**Para gerar múltiplas folhas:**

```bash
# Crie um arquivo ids.txt com os IDs
123
124
125

# Depois execute
for id in $(cat ids.txt); do
    python tools/gerar_folha_ponto_pdf.py $id --mes 2026-05
done
```

---

**Versão:** 1.0  
**Data:** Junho 2026  
**Compatibilidade:** Python 3.7+
