# Solução: Erro ao fazer Login - "Erro interno ao processar o login"

## O Problema

Quando tenta fazer login na "ENTRADA DOS FUNCIONÁRIOS", aparece:

```
Erro interno ao processar o login. Verifique a conexão 
com o banco de dados e tente novamente.
```

## Causas Possíveis (em ordem de probabilidade)

### 1. **MySQL não está rodando** ⚠️ (MAIS COMUM)

**Como verificar:**
- Abra um terminal
- Execute: `mysql -u root`
- Se não conectar, MySQL está desligado

**Como resolver:**
- **Windows (XAMPP/WAMP):**
  - Abra o painel de controle do XAMPP/WAMP
  - Clique em "Start" para MySQL
  - Aguarde ficar verde

- **Windows (Serviço):**
  - Abra "Serviços" (services.msc)
  - Procure por "MySQL" ou "MariaDB"
  - Clique com botão direito > Iniciar

- **Linux:**
  - Execute: `sudo systemctl start mysql` ou `sudo service mysql start`

- **macOS:**
  - Execute: `brew services start mysql`

### 2. **Credenciais erradas em config_db.php**

**Como verificar:**
1. Abra o arquivo: `config_db.php`
2. Verifique as credenciais:

```php
const DB_HOST = '127.0.0.1';     // Host do MySQL
const DB_NAME = 'u654041352_Clientes';     // Nome do banco
const DB_USER = 'u654041352_Matheus';      // Usuário MySQL
const DB_PASS = 'Stich@121';     // Senha MySQL
```

**Como resolver:**
- Verifique as credenciais em phpMyAdmin
- Se mudou a senha, atualize aqui
- Se mudou o host (ex: hospedagem), atualize aqui

### 3. **Banco de dados não existe**

**Como verificar:**
1. Acesse phpMyAdmin
2. Veja a lista de bancos de dados
3. Procure por: `u654041352_Clientes`

**Como resolver:**
- Se não existir, crie o banco com esse nome
- Ou atualize `config_db.php` com o nome correto do banco

### 4. **Usuário MySQL não tem permissões**

**Como verificar:**
- Tente conectar direto via MySQL:
  ```bash
  mysql -u u654041352_Matheus -p'Stich@121' u654041352_Clientes
  ```

**Como resolver:**
- Abra phpMyAdmin
- Vá em "Contas de Usuário"
- Clique no usuário `u654041352_Matheus`
- Garanta que ele tem acesso ao banco `u654041352_Clientes`
- Clique em "Verificar Privilégios"

## Passo a Passo para Diagnosticar

### Passo 1: Testar conexão com o script

1. Acesse: `http://seu-site.com/teste-conexao-db.php`
2. Veja a mensagem de erro detalhada
3. Siga as dicas mostradas

### Passo 2: Verificar credenciais

```bash
# Terminal/Prompt de comando
mysql -u u654041352_Matheus -p'Stich@121' u654041352_Clientes

# Se conectar com sucesso:
mysql> SELECT COUNT(*) FROM funcionarios;
```

### Passo 3: Verificar arquivo config_db.php

```php
<?php
// config_db.php
const DB_HOST = '127.0.0.1';
const DB_NAME = 'u654041352_Clientes';
const DB_USER = 'u654041352_Matheus';
const DB_PASS = 'Stich@121';
```

Certifique-se que as credenciais estão exatamente certas (maiúsculas/minúsculas importam).

## Soluções Rápidas

### ✅ Se MySQL está rodando em XAMPP

1. Abra XAMPP Control Panel
2. Clique em "Admin" para MySQL (phpMyAdmin abre)
3. Verifique as credenciais
4. Tente fazer login novamente

### ✅ Se está em uma hospedagem

1. Abra phpMyAdmin (geralmente em painel.seu-dominio.com/phpmyadmin)
2. Veja as credenciais corretas
3. Atualize `config_db.php` com os dados da hospedagem
4. Tente fazer login novamente

### ✅ Se mudou de servidor/hospedagem

1. Obtenha as novas credenciais do seu provedor
2. Edite `config_db.php` com as novas credenciais
3. Tente fazer login novamente

## Verificação Completa (Checklist)

- [ ] MySQL está rodando/online
- [ ] Host está correto em `config_db.php` (127.0.0.1 para local)
- [ ] Nome do banco está correto em `config_db.php`
- [ ] Usuário está correto em `config_db.php`
- [ ] Senha está correta em `config_db.php`
- [ ] Banco de dados existe em MySQL
- [ ] Usuário tem permissões no banco
- [ ] Tabela `funcionarios` existe e tem dados

## Se Nada Funcionar

1. Abra: `teste-conexao-db.php` no navegador
2. Copie exatamente a mensagem de erro mostrada
3. Verifique os logs do PHP/Apache/Nginx para mais detalhes
4. Reinicie o MySQL completamente
5. Tente fazer login novamente

## Dúvidas?

Se aparecer um erro específico, procure por ele aqui:

| Erro | Causa | Solução |
|------|-------|---------|
| "Can't connect to MySQL server" | MySQL não está rodando | Inicie o MySQL |
| "Access denied for user" | Credenciais erradas | Corrija em config_db.php |
| "Unknown database" | Banco não existe | Crie o banco ou corrija nome |
| "Lost connection to MySQL server" | Conexão caiu | Reinicie MySQL |

---

**Depois de corrigir, teste clicando em "Entrar" novamente!**
