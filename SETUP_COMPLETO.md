# 🚀 SETUP COMPLETO - Colocar Tudo Funcionando

## Status Atual

✅ Arquivo SQL preparado (u654041352_Clientes.sql)  
✅ config_db.php criado  
✅ Script de importação criado  
⏳ Banco precisa ser importado  

## Instruções Passo a Passo

### PASSO 1: Colocar os Arquivos no Servidor

Você precisa colocar 2 arquivos no servidor (raiz do site):

#### Arquivo 1: `config_db.php`

Conteúdo (copie e cole):

```php
<?php
const DB_HOST = '127.0.0.1';
const DB_NAME = 'u654041352_Clientes';
const DB_USER = 'u654041352_Matheus';
const DB_PASS = 'Stich@121';
const DB_CHARSET = 'utf8mb4';

function obterConexao(): PDO
{
    $dsn = 'mysql:host=' . DB_HOST . ';dbname=' . DB_NAME . ';charset=' . DB_CHARSET;
    return new PDO($dsn, DB_USER, DB_PASS, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
    ]);
}
?>
```

**Como adicionar (escolha um):**
- **File Manager:** Crie novo arquivo, cole o conteúdo, salve como `config_db.php`
- **FTP:** Crie arquivo localmente, faça upload
- **SSH:** `cat > config_db.php << 'EOF'` ... `EOF`

#### Arquivo 2: `importar-sql.php`

**Está no GitHub!** Clique em `importar-sql.php` e copie o código.

Ou faça download direto do GitHub:
```
https://github.com/stich121/Site-Account/blob/main/importar-sql.php
```

**Como adicionar:**
- Mesmo processo do config_db.php
- Coloque na raiz do site

#### Arquivo 3: `u654041352_Clientes.sql`

**Este é o mais importante!** É o dump do banco de dados.

O arquivo está em: `C:\Users\mathe\Downloads敀41352_Clientes.sql`

**Como adicionar:**
- **File Manager:** Faça upload do arquivo .sql para raiz do site
- **FTP:** Faça upload direto
- **Terminal:** `scp arquivo.sql usuario@servidor:/caminho/`

### PASSO 2: Importar o Banco de Dados

#### Via Script (Recomendado)

1. Acesse: `http://seu-site.com/importar-sql.php`
2. Clique em **"Importar Banco de Dados"**
3. Aguarde completar (pode levar 5-10 minutos)
4. Você verá mensagem de sucesso com:
   - ✓ Tabelas criadas
   - ✓ Funcionários importados
   - ✓ Pronto para usar

#### Via phpMyAdmin

1. Abra phpMyAdmin
2. Clique em "Importar"
3. Selecione arquivo `u654041352_Clientes.sql`
4. Clique "Executar"
5. Aguarde conclusão

#### Via Terminal (SSH)

```bash
ssh usuario@servidor.com
cd /caminho/do/site
mysql -u u654041352_Matheus -p u654041352_Clientes < u654041352_Clientes.sql
# Digite a senha quando pedir
```

### PASSO 3: Testar

1. Acesse: `http://seu-site.com/entrada-funcionarios.html`
2. Faça login com uma conta (ex: `Matheus@accountassessoria.com.br`)
3. Se funcionar → ✅ SUCESSO!

### PASSO 4: Limpeza

Se tudo funcionou, delete estes arquivos (não são mais necessários):

```
- importar-sql.php (script de importação)
- debug-login.php (debug)
- teste-conexao-db.php (teste)
- u654041352_Clientes.sql (banco SQL grande)
```

## Checklist de Verificação

- [ ] Arquivo `config_db.php` está na raiz do site
- [ ] Arquivo `importar-sql.php` está na raiz do site
- [ ] Arquivo `u654041352_Clientes.sql` está na raiz do site
- [ ] MySQL está rodando
- [ ] Credenciais em `config_db.php` estão corretas
- [ ] Banco foi importado (acessou importar-sql.php e clicou em "Importar")
- [ ] Teste de login funciona
- [ ] Painel carrega sem erros

## Credenciais (Confirme)

Verifique se estas são as credenciais corretas:

| Campo | Valor |
|-------|-------|
| Host | `127.0.0.1` |
| Banco | `u654041352_Clientes` |
| Usuário | `u654041352_Matheus` |
| Senha | `Stich@121` |
| Charset | `utf8mb4` |

**Se forem diferentes, atualize em `config_db.php`**

## Erros Comuns

### Erro: "Arquivo SQL não encontrado"
**Solução:** Verifique se `u654041352_Clientes.sql` está na raiz do site (mesmo nível de config_db.php)

### Erro: "Conexão recusada"
**Solução:** 
- MySQL não está rodando
- Credenciais estão erradas
- Host está incorreto

### Erro: "Acesso negado (1045)"
**Solução:** Verifique usuário e senha em `config_db.php`

### Importação muito lenta
**Normal!** Arquivo tem ~18 MB, pode levar 5-10 minutos dependendo da velocidade do servidor

### "Já existe banco com este nome"
**Solução:** Delete o banco velho e tente novamente
```sql
DROP DATABASE u654041352_Clientes;
```

## Arquivos Criados Localmente

Estes arquivos já existem no seu computador:

```
H:\Meu Drive\00001 site\Site pelo CODEX\
├── config_db.php (criado)
├── importar-sql.php (criado)
└── u654041352_Clientes.sql (copiado de Downloads)
```

## Próximos Passos

1. ✅ Copie os 3 arquivos para o servidor
2. ✅ Acesse importar-sql.php
3. ✅ Clique "Importar Banco de Dados"
4. ✅ Aguarde completar
5. ✅ Teste fazer login
6. ✅ Delete os scripts temporários
7. ✅ Sistema pronto!

## Suporte

Se tiver problemas:

1. Acesse `http://seu-site.com/debug-login.php` para diagnosticar
2. Leia `CORRIGIR_ERRO_LOGIN.md` para soluções
3. Verifique `CRIAR_CONFIG_DB.md` para config

---

**Você está a 3 passos de ter tudo funcionando! 🚀**

Commit: `82eb9a7`  
GitHub: ✅ Online
