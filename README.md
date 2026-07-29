# Account Contabilidade — site, gestão interna e emissor fiscal

Projeto web da Account Contabilidade em PHP, MySQL, JavaScript e Python. O repositório reúne o site institucional, o painel de funcionários, o controle de ponto e o emissor de notas fiscais.

## Estado atual

- Aplicação publicada na Hostinger, com deploy após `push` na branch `main`.
- PHP 8.1 ou superior e dependências gerenciadas pelo Composer.
- Banco principal separado do banco do emissor fiscal.
- NFS-e integrada ao Ambiente de Dados Nacional/SEFIN Nacional.
- Certificado digital A1 configurável por empresa emissora.
- Catálogos fiscais oficiais carregados localmente.

## Módulos disponíveis

### Site institucional

- Página inicial, serviços, diferenciais, contato e orçamento.
- Conteúdo sobre Simples Nacional, reforma tributária, CFOP e CST.
- Termos de uso, política de privacidade e política de cookies.
- Sitemap e identidade visual da Account.

### Funcionários e controle de ponto

- Login e sessão segura.
- Painel individual e gerenciamento de funcionários.
- Permissões por nível de acesso.
- Registro, apuração e ajuste manual de ponto.
- Afastamentos, tipos de afastamento e banco de horas.
- Histórico de espelhos e downloads.
- Geração de folha de ponto em PDF ou HTML.
- Programas internos em página protegida.

### Emissor fiscal

- Empresas prestadoras/emissoras.
- Clientes/tomadores.
- Produtos e serviços.
- Certificados digitais A1.
- Criação de NF-e em rascunho.
- Criação, validação, fila, transmissão e acompanhamento de NFS-e.
- Status de rascunho, pendente, autorizada, rejeitada e cancelada.
- Documento de conferência, downloads protegidos, histórico e logs.
- Correção e reprocessamento de NFS-e rejeitada localmente.

## NFS-e Nacional — funcionalidades concluídas

### Empresa emissora

- Busca de dados pelo CNPJ.
- Máscaras automáticas de CNPJ e CEP.
- Endereço, inscrição municipal e código IBGE.
- Ambiente de homologação ou produção por empresa.
- Opção pelo Simples Nacional e regime de apuração.
- Tributação municipal e regime especial obtidos do cadastro da empresa emissora.
- Edição, desativação e exclusão controlada.

### Certificado digital

- Certificado A1 por empresa emissora.
- Bloqueio contra associação a outra empresa.
- Leitura e exibição da validade.
- Diagnóstico de erros de leitura.
- Conversão de PFX com criptografia legada.
- Senha armazenada cifrada.

### Cliente/tomador

- Pessoa física e jurídica.
- Consulta automática por CNPJ.
- Busca de cliente existente por CPF/CNPJ.
- Preenchimento automático da inscrição municipal.
- Tomador brasileiro ou do exterior.
- Validação de documento, código IBGE, país, NIF e motivo de ausência de NIF.

### Local da prestação

- Catálogo com todos os municípios brasileiros.
- Pesquisa pelo início do nome.
- Armazenamento do código IBGE selecionado.
- Validação no servidor contra o catálogo oficial.

### Serviço prestado

- Códigos nacionais de tributação da LC 116 pré-carregados.
- Pesquisa pelo código ou descrição.
- Preenchimento inicial da descrição do serviço.
- Códigos complementares municipais de Belo Horizonte.
- Campo complementar duplicado e comentário auxiliar removidos.

### NBS automática

- Catálogo gerado a partir do Anexo VIII oficial da NFS-e.
- 200 itens da LC 116 e 895 relações oficiais entre serviço e NBS.
- Em 83 itens com uma única NBS, preenchimento automático.
- Em 117 itens com várias NBS, exibição somente das opções oficiais compatíveis.
- O item interno `99.01` não possui NBS aplicável no Anexo VIII.
- O servidor rejeita NBS incompatível com o serviço.
- Códigos normalizados e validados com nove dígitos.

Fonte: [Anexo VIII — Correlação Item LC 116, NBS, cIndOp e cClassTrib](https://www.gov.br/nfse/pt-br/biblioteca/documentacao-tecnica/rtc/anexoviii-correlacaoitemnbsindopcclasstrib_ibscbs_v1-00-00.xlsx/view).

### IBS/CBS — Reforma Tributária

- Catálogos oficiais locais para indicador da operação e classificação tributária.
- Pesquisa pelo código ou descrição.
- CST preenchido pela classificação tributária.
- Validação do conjunto IBS/CBS e da correspondência CST/cClassTrib.
- NBS obrigatória com nove dígitos quando aplicável.

### DPS e transmissão

- Geração do JSON da DPS.
- Assinatura com certificado A1.
- Geração e validação do identificador da DPS.
- Endpoint definido pelo ambiente da empresa.
- Tratamento de respostas, rejeições e falhas de comunicação.
- Fila com trava para reduzir transmissão duplicada.
- Persistência de protocolo, chave, documento fiscal e motivo de rejeição.

### Correção de nota

- Rascunhos e rejeições locais podem ser corrigidos.
- Empresa emissora e tipo da nota ficam bloqueados durante a correção.
- Cabeçalho, serviço e NFS-e são salvos em transação.
- Controle de atualização evita sobrescrita concorrente.
- A nota corrigida volta a rascunho e pode ser reprocessada.
- Notas autorizadas e canceladas permanecem somente para leitura.

## Bancos de dados

O sistema utiliza dois bancos:

1. Principal: funcionários, permissões, ponto e afastamentos.
2. Fiscal: empresas emissoras, clientes, produtos/serviços, notas, itens, NFS-e e logs.

O schema fiscal consolidado está em `notas-fiscais-schema.sql`.

Tabelas principais:

- `empresas_emissoras`
- `notas_clientes`
- `notas_produtos_servicos`
- `notas_fiscais`
- `notas_fiscais_itens`
- `notas_fiscais_nfse`
- `notas_fiscais_log`

O SQL inclui atualizações idempotentes. A alteração da tabela `funcionarios` deve ser executada somente no banco principal, conforme os comentários do próprio arquivo.

## Arquivos importantes

| Arquivo | Finalidade |
|---|---|
| `notas-fiscais.php` | Painel e listagem de notas |
| `notas-emitir.php` | Criação e correção de notas |
| `notas-empresas-emissoras.php` | Empresas prestadoras |
| `notas-certificados.php` | Certificados A1 |
| `notas-produtos-servicos.php` | Produtos e serviços |
| `nfse-dps-fiscal.php` | Montagem e validação da DPS |
| `nfse-nacional-integracao.php` | Integração com a NFS-e Nacional |
| `nfse-operacoes.php` | Operações e persistência |
| `processar-fila-nfse.php` | Processamento da fila |
| `nfse-codigos-tributacao-nacional.php` | Códigos da LC 116 |
| `nfse-codigos-complementares-bh.php` | Códigos de Belo Horizonte |
| `nfse-ibs-catalogos.json` | Catálogos IBS/CBS |
| `nfse-nbs-correlacao.json` | Correlação serviço–NBS |
| `ibge-municipios.json` | Municípios e códigos IBGE |
| `notas-fiscais-schema.sql` | Schema fiscal |
| `seguranca.php` | Sessão, CSRF e segurança |

## Instalação

### Requisitos

- PHP 8.1 ou superior.
- MySQL/MariaDB.
- Composer.
- Extensões DOM, GD, libxml, mbstring, OpenSSL e zlib.
- Certificado A1 válido para transmissão.
- SSH ou terminal da hospedagem.

### Composer

Na raiz pública:

```bash
composer install --no-dev --optimize-autoloader
php -r "require 'vendor/autoload.php'; echo 'Composer OK'.PHP_EOL;"
```

### Bancos e configurações

Crie os arquivos locais a partir dos exemplos:

```text
config_db.example.php        -> config_db.php
config_db_notas.example.php  -> config_db_notas.php
config_app_key.example.php   -> config_app_key.php
```

Preencha-os somente no servidor ou ambiente local. Nunca coloque senhas, certificados ou chaves no Git.

Importe `notas-fiscais-schema.sql` no banco fiscal. A permissão `permite_notas_fiscais` pertence ao banco principal e pode ser aplicada por `notas-fiscais-permissao-principal.sql`.

`config_app_key.php` deve conter uma chave forte e exclusiva. Trocar essa chave depois de cadastrar certificados exige recadastrar ou migrar os dados cifrados.

### Diretórios privados

Mantenha fora do acesso público direto:

- certificados;
- documentos fiscais;
- temporários;
- configurações com credenciais.

O projeto possui regras `.htaccess` nos diretórios fiscais. Downloads devem usar endpoints autenticados.

### Fila da NFS-e

Pode ser executada pelo painel ou por tarefa agendada:

```bash
php processar-fila-nfse.php
```

O cron deve usar a mesma versão do PHP do site.

## Deploy

Fluxo atual:

1. Alterar e testar localmente.
2. Adicionar somente arquivos não sensíveis.
3. Criar o commit.
4. Enviar para `main`.
5. A Hostinger sincroniza e publica.
6. Validar a alteração no domínio de produção.

Verificações recomendadas:

```bash
git status --short
git diff --check
php -l arquivo-alterado.php
```

O arquivo `pacote-atualizacao-nfse-2026-07-28.zip` é local e não faz parte da aplicação versionada.

## Segurança

- Sessão segura e autenticação nas áreas internas.
- CSRF em formulários sensíveis.
- Consultas preparadas com PDO.
- Controle de acesso por funcionário.
- Downloads fiscais protegidos.
- Certificado vinculado à empresa.
- Senha do certificado cifrada.
- Validação no servidor, além do JavaScript.
- Travas e transações contra concorrência e duplicidade.

Não versionar:

- `config_db.php`
- `config_db_notas.php`
- `config_app_key.php`
- `google_drive_config.php`
- certificados `.pfx`/`.p12`
- dumps reais
- documentos de clientes
- logs com dados fiscais

## Checklist antes de emitir em produção

- [ ] Empresa com CNPJ, IM, endereço e código IBGE completos.
- [ ] Simples, regime, tributação municipal e regime especial corretos.
- [ ] Certificado A1 válido e pertencente à empresa.
- [ ] Ambiente da empresa correto.
- [ ] Tomador com documento e endereço válidos.
- [ ] Município escolhido pelo catálogo IBGE.
- [ ] Código nacional e municipal do serviço conferidos.
- [ ] NBS automática ou escolhida entre opções oficiais.
- [ ] cIndOp, CST e cClassTrib conferidos.
- [ ] Valores, retenções, descontos e competência revisados.
- [ ] Primeiro teste realizado em homologação.
- [ ] Fila, cron e logs verificados.

## Histórico das principais entregas

- Correções de login, sessão e conexão MySQL.
- Folha de ponto em PDF/HTML.
- Programas internos e documentos protegidos.
- Termos, privacidade e cookies.
- Emissor fiscal, permissões, rascunhos e schema próprio.
- Integração NFS-e Nacional e certificado A1.
- Empresa e cliente com consulta por CNPJ.
- Separação dos campos de NF-e e NFS-e.
- Municípios pesquisáveis pelo catálogo IBGE.
- Códigos LC 116 e complemento municipal de Belo Horizonte.
- Tributação municipal e regime vindos da prestadora.
- Catálogos automáticos IBS/CBS.
- Correção do identificador da DPS.
- Edição e reprocessamento de NFS-e rejeitada.
- NBS automática ou filtrada pelo serviço.
- Correção do grupo `pTotTrib`/`vTotTrib`: quando só parte dos tributos aproximados (federal/estadual/municipal) era informada, o campo faltante era omitido do XML em vez de enviado como zero, quebrando o schema (erro E1235).
- Inclusão da Inscrição Municipal (`IM`) do prestador na DPS, exigida pelo Sefin Nacional quando o CNC NFS-e do município emissor está vinculado a ela (erro E0116); validação no servidor passou a exigir a IM cadastrada na empresa emissora antes de montar a DPS.
- Diagnóstico do erro E0312 (código de tributação não administrado pelo município na data de competência): a causa mais provável não é o código LC 116 em si nem a adesão do município ao Sistema Nacional, e sim o campo "Município da prestação" (`cLocPrestacao`) estar apontando para uma cidade diferente da do prestador em serviços cujo ISSQN é devido no local do estabelecimento prestador (como contabilidade, fora das exceções do art. 3º da LC 116).

## Limites e cuidados

- Uma atividade da LC 116 pode ter várias NBS; nesses casos a escolha depende do serviço real.
- Endpoints nacionais e municipais são serviços externos.
- Novas notas técnicas exigem atualização dos catálogos.
- Após falha de rede, reconcilie a DPS antes de reenviar.
- A transmissão descrita aqui é da NFS-e Nacional; NF-e possui fluxo distinto.

## Referências

- [Documentação técnica atual da NFS-e](https://www.gov.br/nfse/pt-br/biblioteca/documentacao-tecnica/documentacao-atual)
- [Anexo VIII — correlação fiscal](https://www.gov.br/nfse/pt-br/biblioteca/documentacao-tecnica/rtc/anexoviii-correlacaoitemnbsindopcclasstrib_ibscbs_v1-00-00.xlsx/view)
- [Nomenclatura Brasileira de Serviços — MDIC](https://www.gov.br/mdic/pt-br/assuntos/sdic/comercio-e-servicos/nbs-nomenclatura-brasileira-de-servicos)

---

Última consolidação: 28 de julho de 2026 (correções de emissão NFS-e: `pTotTrib`/`vTotTrib` incompletos e IM do prestador ausente).
