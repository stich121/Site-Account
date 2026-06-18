# ⚠️ PROBLEMA IDENTIFICADO: config_db.php Não Existe!

## O Problema

O arquivo `config_db.php` **NÃO EXISTE NO SERVIDOR**.

Isso causa:
- ✗ Erro ao fazer login
- ✗ Erro ao acessar painel
- ✗ Erro ao acessar qualquer função que use banco de dados

## Por Que?

O arquivo `config_db.php` contém **credenciais do banco de dados** (senha), então está no `.gitignore` para não ser enviado ao GitHub por segurança.

Isso significa:
- ✓ Local (seu computador): arquivo existe
- ✗ GitHub: arquivo não foi enviado
- ✗ Servidor: arquivo não existe

## Solução

Você precisa **criar** o arquivo `config_db.php` no servidor!

### Opção 1: Via File Manager (Painel Hosting)

**Se você tem acesso ao File Manager do hosting:**

1. Acesse: **cPanel > File Manager** (ou similar)
2. Navegue para a raiz do site
3. Você verá um arquivo chamado `config_db.example.php`
4. **Clique com botão direito > Copy**
5. Renomeie para `config_db.php`
6. **Abra o arquivo para editar**
7. Preencha com seus dados reais:

```php
<?php
const DB_HOST = '127.0.0.1';                    // ← Seu host MySQL
const DB_NAME = 'u654041352_Clientes';          // ← Seu banco
const DB_USER = 'u654041352_Matheus';           // ← Seu usuário
const DB_PASS = 'Stich@121';                    // ← Sua senha
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

8. **Salve o arquivo**
9. Teste fazer login novamente

### Opção 2: Via FTP

**Se você usa FTP:**

1. Acesse seu FTP com Filezilla ou similar
2. Navegue para raiz do site
3. **Faça upload** de `config_db.php` com os dados corretos:

```php
<?php
const DB_HOST = 'seu_host';
const DB_NAME = 'seu_banco';
const DB_USER = 'seu_usuario';
const DB_PASS = 'sua_senha';
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

### Opção 3: Via SSH (Terminal)

**Se você tem acesso SSH:**

```bash
# Acesse o servidor
ssh seu_usuario@seu_servidor.com

# Navegue para o site
cd /home/seu_usuario/public_html

# Crie o arquivo
cat > config_db.php << 'EOF'
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
EOF

# Defina permissões
chmod 644 config_db.php
```

## Credenciais Necessárias

Você precisa ter as seguintes informações:

| Campo | Valor | Onde encontrar |
|-------|-------|-----------------|
| **DB_HOST** | Host MySQL | cPanel > MySQL Databases |
| **DB_NAME** | Nome do banco | cPanel > MySQL Databases |
| **DB_USER** | Usuário MySQL | cPanel > MySQL Databases |
| **DB_PASS** | Senha MySQL | cPanel > MySQL Databases ou phpMyAdmin |
| **DB_CHARSET** | Deixe `utf8mb4` | Não mude |

### Como Obter no Hostinger/cPanel

1. Faça login no cPanel
2. Vá em **MySQL Databases** (ou **MySQL®**)
3. Procure pela seção de "Databases" ou "Users"
4. Você verá as credenciais

### Como Obter no phpMyAdmin

1. Abra phpMyAdmin
2. Vá em **Users** ou **Contas de Usuário**
3. Procure pelo usuário MySQL
4. As credenciais estão lá

## Depois de Criar o Arquivo

1. ✓ Arquivo `config_db.php` criado no servidor
2. ✓ Credenciais preenchidas corretamente
3. ✓ Permissões do arquivo são 644 (leitura pública)
4. ✓ Tente fazer login novamente

## Testando

Após criar o arquivo, teste acessando:

```
http://seu-site.com/debug-login.php
```

Todos os testes devem passar com ✓ verde!

## Problemas Comuns

| Problema | Solução |
|----------|---------|
| Erro 1045 (Acesso negado) | Verifique usuário/senha |
| Erro 1049 (Banco não existe) | Verifique nome do banco |
| Erro 2002/2003 (Conexão recusada) | Verifique host, pode ser `localhost` em vez de `127.0.0.1` |
| Arquivo não salva | Verifique permissões de escrita |

## Nota de Segurança

⚠️ **IMPORTANTE:**
- Nunca commite este arquivo no GitHub (está no .gitignore)
- Nunca compartilhe suas credenciais
- Este arquivo contém a senha do banco de dados
- Mantenha-o protegido no servidor

---

**Depois de criar o arquivo, tente fazer login novamente!** 🔑
