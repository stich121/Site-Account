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
- Criação, validação, fila, transmissão e acompanhamento de **NF-e** (produto) direto na SEFAZ estadual.
- Criação, validação, fila, transmissão e acompanhamento de **NFS-e** (serviço) na NFS-e Nacional.
- Criação, validação, transmissão, reimpressão de DANFCE, consulta e cancelamento de **NFC-e** (venda no balcão), em tela própria e independente do emissor de NF-e/NFS-e.
- Status de rascunho, pendente, autorizada, rejeitada e cancelada (todos os tipos).
- Documento de conferência (em layout de DANFE para NF-e), downloads protegidos, histórico e logs.
- Correção e reprocessamento de nota rejeitada localmente (NF-e e NFS-e).
- Filtro por empresa emissora nas telas de **notas fiscais** e de **clientes**: mostra só as notas (ou só os clientes que já têm nota) do CNPJ emitente escolhido.
- **Buscadores fiscais via SEFAZ/ADN** (NF-e, NFC-e e NFS-e): consultam documentos fiscais ligados ao CNPJ de cada empresa usando só o certificado digital A1 já cadastrado, mesmo que a nota não tenha sido emitida por este sistema — pensado para uso da contabilidade. Sincronização automática por cron, sem depender de alguém abrir a tela.

## NF-e (produto) — funcionalidades concluídas

### Cálculo de impostos por item

- ICMS: CST (regime normal) ou CSOSN (Simples Nacional), escolhido conforme o CRT cadastrado da empresa; alíquota interestadual calculada automaticamente pela regra do Senado (Resolução 22/89 + 13/2012 — 4/7/12%); ICMS-ST com base/alíquota informadas manualmente no item.
- IPI, PIS e COFINS: CST completo (tabela oficial, ~30 situações para PIS/COFINS) e alíquota, com valor calculado automaticamente (base × alíquota) e mostrado ao vivo no formulário antes mesmo de salvar.
- IBS/CBS (Reforma Tributária, LC 214/2025): CST (12 situações), cClassTrib (71 códigos oficiais, filtrados pelo CST escolhido), base de cálculo e as três alíquotas (IBS Estadual, IBS Municipal, CBS), com valor calculado ao vivo. Fica em branco por padrão — só entra no XML (`tagIBSCBS`) quando o item tem cClassTrib preenchido. A montagem local usa o schema aditivo `PL_010_V1.30` (mesmo `versao="4.00"` no XML) tanto em `nfeMontarXml()` quanto na validação do `Tools` em `montarToolsNfe()` — os dois precisam ficar em sincronia, senão a validação local usa um XSD que não conhece `IBSCBS`/`IBSCBSTot` e a SEFAZ, que já passou a exigir esse grupo em algumas operações, rejeita a nota.
- Alíquotas-teste de 2026 (LC 214/2025 art. 346, NT 2025.002 v1.20): para documentos emitidos em 2026 a SEFAZ exige valores fixos — 0,1% IBS Estadual (rejeição 1026 para qualquer outro valor), 0% IBS Municipal (rejeição 321/1036) e 0,9% CBS (rejeição 1037). Os campos ficam bloqueados para edição na tela nesse período, e `nfeCalcularImpostosItem()` (`includes/nfe-impostos.php`) força esses três valores no servidor sempre que o ano corrente for 2026, independente do que vier no formulário. Precisa ser revisto quando a fase seguinte da reforma definir novas alíquotas-teste (2027 em diante).
- O valor gravado e usado no XML é sempre recalculado no servidor a partir do que foi persistido; alíquotas do cadastro/formulário são só sugestão inicial, nunca a fonte de verdade.
- Fora do escopo por decisão explícita: DIFAL/partilha para consumidor final não contribuinte em outra UF (bloqueado com mensagem clara em vez de calcular errado) e tabela de MVA por NCM/UF para ICMS-ST.

### Geração, assinatura e transmissão

- XML montado via `NFePHP\NFe\Make` (`nfephp-org/sped-nfe`), assinado e transmitido à SEFAZ do estado da empresa emissora (cada UF tem seu próprio webservice; a biblioteca resolve isso sozinha).
- Chave de acesso, protocolo de autorização e XML autorizado persistidos na nota.
- Fila de envio com trava por nota (mesmo padrão da NFS-e), rodável pelo painel ou por cron.
- Consulta por chave e cancelamento (evento 110111, com justificativa de 15 a 255 caracteres).
- DANFE em PDF gerado localmente (`nfephp-org/sped-da`), sem depender de serviço externo — tanto para a nota autorizada quanto uma **prévia** (rascunho/pendente, sem assinar/enviar, marcada automaticamente como "SEM VALOR FISCAL") acessível pelo botão "Conferência".

### Catálogos de apoio ao preenchimento

- CFOP: 357 códigos (grupos 5xxx/6xxx/7xxx de saída) com descrição oficial, autocompletar ao digitar.
- NCM: tabela oficial completa (10.515 códigos de 8 dígitos, com descrição hierárquica completa), baixada da API pública do Portal Único de Comércio Exterior (Siscomex/Receita Federal). Busca por código ou por nome do produto, carregada uma vez pelo navegador (cache) e filtrada no cliente. Máscara automática `0000.00.00` enquanto digita um código puro; a máscara não interfere na busca por nome.
- Descrição do item: autocompletar pelos produtos já cadastrados no catálogo da mesma empresa, preenchendo NCM/CFOP/CST/alíquotas/cEAN automaticamente ao selecionar.
- IBS/CBS: mesmos catálogos oficiais já usados na NFS-e (cClassTrib/CST), reaproveitados no item da NF-e.

### Cadastro de produtos/serviços

- Tela reorganizada em seções (Identificação, Fiscal produto, Fiscal serviço, Preço e impostos).
- Campos adicionais: Origem da mercadoria, CEST, CNPJ do fabricante, indicador de escala relevante e código de benefício fiscal na UF (usados quando o produto está sujeito a ICMS-ST ou é importado).
- Edição de item já cadastrado (antes só dava para desativar/reativar).

### Empresa emissora e numeração

- Série da NF-e configurável por empresa; numeração sequencial independente por empresa + tipo de nota (NF-e/NFS-e) + série — nunca mistura entre empresas.
- Ajuste manual da numeração (`nfe_numero_base`): campo no cadastro da empresa para registrar a última NF-e emitida fora do sistema (ou corrigir a sequência). A próxima nota emitida usa sempre o maior valor entre esse ajuste e o que já foi lançado pelo sistema, mais 1 — depois disso a numeração segue sozinha, sem precisar mexer no campo de novo. O cadastro mostra ao vivo o último número já lançado e qual será o próximo (calculado a partir de `notas_fiscais`, não é um contador solto que possa dessincronizar).
- CRT da empresa passou a ser efetivamente usado (antes só a NFS-e lia esse campo) para decidir CSOSN × CST em todo o formulário.

### Diagnóstico e operação

- `nfe-diagnostico.php`: confere no servidor real se as extensões PHP (`soap`, `curl` etc.) e as bibliotecas (`sped-nfe`, `sped-da`) estão disponíveis antes de confiar no envio.
- `processar-fila-nfe.php`: mesmo padrão de fila/log da NFS-e, rodável por cron.

## NFS-e Nacional — funcionalidades concluídas

### Empresa emissora

- Busca de dados pelo CNPJ.
- Máscaras automáticas de CNPJ e CEP.
- Endereço, inscrição municipal e código IBGE.
- Ambiente de homologação ou produção por empresa.
- Opção pelo Simples Nacional e regime de apuração.
- Tributação municipal e regime especial obtidos do cadastro da empresa emissora.
- Edição, desativação e exclusão controlada.
- Textos de ajuda de campo (ex.: opção pelo Simples, regime de apuração, ajuste manual da numeração) exibidos por um botão "i" que abre uma caixa de diálogo ao lado do campo, em vez de texto sempre visível — componente compartilhado em `assets/css/notas-fiscais.css` (`.info-tooltip-wrap`/`.info-btn`/`.info-tooltip-box`), reaproveitável nas outras telas do emissor.

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

## Buscadores fiscais (consulta direta na SEFAZ/ADN)

Telas independentes do emissor de notas: em vez de mostrar só o que foi emitido por este sistema, consultam a **Distribuição de DFe** da SEFAZ (NF-e/NFC-e) ou o **ADN/SEFIN Nacional** (NFS-e) usando apenas o certificado digital A1 já cadastrado da empresa. Pensadas para a contabilidade acompanhar as notas de empresas-cliente mesmo quando a emissão acontece em outro sistema (POS de terceiros, outro emissor etc.).

### Buscador de NF-e e Buscador de NFC-e

- `notas-fiscais-nfe-dfe.php` e `notas-fiscais-nfce-dfe.php` usam a **mesma** chamada `sefazDistDFe()` (webservice de Distribuição de DFe) por empresa/CNPJ — a SEFAZ não filtra por modelo nessa distribuição, então em vez de duplicar a consulta (o que dobraria o consumo de NSU e aumentaria o risco de bloqueio "Consumo Indevido"), o modelo do documento (55 = NF-e, 65 = NFC-e) é lido direto da chave de acesso e gravado na tabela correspondente (`notas_fiscais_nfe_dfe` ou `notas_fiscais_nfce_dfe`). As duas telas compartilham o mesmo ponteiro de posição (NSU) por empresa.
- Documento completo (`procNFe`) chega pronto, com XML autorizado (baixável) e DANFE/DANFCE gerados localmente. Documento recebido de terceiros chega primeiro como resumo (`resNFe`); a sincronização já envia sozinha a **Ciência da Operação** (evento 210210) para liberar o XML completo numa sincronização seguinte — aplicável só a NF-e, já que NFC-e recebida de terceiros é um cenário raro.
- Eventos de cancelamento (`resEvento`) são aplicados por chave de acesso nas duas tabelas.
- Busca por nome, CPF/CNPJ, número ou chave de acesso; filtros de data, empresa e tipo (emitida/recebida); exportação em lote em ZIP (XML + DANFE/DANFCE de um período); paginação.
- Sincronização automática ao abrir qualquer uma das duas páginas (uma empresa por visita, a mais atrasada) e por cron via `processar-nfe-dfe-automatico.php --cli` — o mesmo cron mantém os dois buscadores em dia, não é preciso configurar um separado para NFC-e.

### Buscador de NFS-e

- `notas-fiscais-nfse-adn.php` usa o ADN/SEFIN Nacional (`sincronizarNfseAdn()`) da mesma forma, com sincronização automática e cron próprio (`processar-nfse-adn-automatico.php`).

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
- `notas_fiscais_itens` (inclui os campos de ICMS/ICMS-ST/IPI/PIS/COFINS/IBS/CBS por item)
- `notas_fiscais_nfse`
- `notas_fiscais_nfe` (finalidade, transporte, pagamento, informações complementares)
- `notas_fiscais_log`

O SQL inclui atualizações idempotentes. A alteração da tabela `funcionarios` deve ser executada somente no banco principal, conforme os comentários do próprio arquivo.

### Backup automático

`backup-banco-dados.php` gera um dump completo (estrutura + dados) de cada um dos dois bancos, comprime em `.sql.gz` e envia pro Google Drive (mesma configuração de `google_drive_config.php` usada pelos downloads). Não depende de `mysqldump`/`shell_exec` — o dump é montado via PDO, o que funciona em hospedagem compartilhada. Mantém só os 14 backups mais recentes de cada banco no Drive.

- Botão manual: acesse a página logado como administrador (nível de acesso 3+).
- Automático: configure no hPanel da Hostinger (Avançado > Cron Jobs) uma tarefa diária executando `php /caminho/do/site/backup-banco-dados.php --cli`.

## Arquivos importantes

| Arquivo | Finalidade |
|---|---|
| `notas-fiscais.php` | Painel e listagem de notas |
| `notas-emitir-produto.php` | Emissão de NF-e (produto) |
| `notas-emitir-servico.php` | Emissão e correção de NFS-e (serviço) |
| `includes/notas-emitir-motor.php` | Validação e persistência compartilhadas por NF-e e NFS-e |
| `notas-emitir.php` | Redireciona links antigos para a tela correta (NF-e ou NFS-e) |
| `notas-empresas-emissoras.php` | Empresas prestadoras |
| `notas-certificados.php` | Certificados A1 |
| `notas-produtos-servicos.php` | Produtos e serviços |
| `nfse-dps-fiscal.php` | Montagem e validação da DPS |
| `nfse-nacional-integracao.php` | Integração com a NFS-e Nacional |
| `nfse-operacoes.php` | Operações e persistência |
| `processar-fila-nfse.php` | Processamento da fila da NFS-e |
| `nfse-codigos-tributacao-nacional.php` | Códigos da LC 116 |
| `nfse-codigos-complementares-bh.php` | Códigos de Belo Horizonte |
| `nfse-ibs-catalogos.json` | Catálogos IBS/CBS |
| `nfse-nbs-correlacao.json` | Correlação serviço–NBS |
| `ibge-municipios.json` | Municípios e códigos IBGE |
| `cfop-codigos.json` | Catálogo de CFOPs (venda) para autocompletar |
| `ncm-codigos.json` | Tabela NCM oficial completa (Siscomex), para autocompletar |
| `ibscbs-cst-codigos.json` | CST do IBS/CBS (reforma tributária), para o item da NF-e |
| `ibscbs-cclass-codigos.json` | cClassTrib do IBS/CBS (reforma tributária), para o item da NF-e |
| `includes/nfe-impostos.php` | Cálculo de ICMS/ICMS-ST/IPI/PIS/COFINS por item da NF-e |
| `nfe-xml-fiscal.php` | Montagem do XML da NF-e (NFePHP\NFe\Make) |
| `nfe-sefaz-integracao.php` | Assinatura e transmissão à SEFAZ (nfephp-org/sped-nfe) |
| `nfe-operacoes.php` | Consulta, cancelamento e DANFE da NF-e |
| `processar-fila-nfe.php` | Processamento da fila da NF-e |
| `nfe-diagnostico.php` | Diagnóstico do ambiente (extensões PHP e libs) para NF-e |
| `notas-nfce-vendas.php` | Listagem, reimpressão de DANFCE, consulta e cancelamento de NFC-e (venda no balcão) |
| `nfce-operacoes.php` | Consulta, cancelamento e geração de DANFCE da NFC-e |
| `nfce-sefaz-integracao.php` | Assinatura e transmissão da NFC-e à SEFAZ |
| `notas-fiscais-nfe-dfe.php` | Buscador de NF-e via Distribuição de DFe da SEFAZ (usa só o certificado A1) |
| `notas-fiscais-nfce-dfe.php` | Buscador de NFC-e via Distribuição de DFe da SEFAZ (mesma sincronização do buscador de NF-e) |
| `nfe-distribuicao-integracao.php` | Sincronização compartilhada da Distribuição de DFe (alimenta os buscadores de NF-e e NFC-e) |
| `processar-nfe-dfe-automatico.php` | Cron/admin da sincronização automática dos buscadores de NF-e e NFC-e |
| `notas-fiscais-nfse-adn.php` | Buscador de NFS-e via ADN/SEFIN Nacional |
| `processar-nfse-adn-automatico.php` | Cron/admin da sincronização automática do buscador de NFS-e |
| `notas-fiscais-schema.sql` | Schema fiscal |
| `seguranca.php` | Sessão, CSRF e segurança |
| `backup-banco-dados.php` | Backup diário dos dois bancos (dump via PDO) para o Google Drive |
| `google_drive_service.php` | Autenticação OAuth e upload/listagem/exclusão de arquivos no Google Drive |
| `assets/css/notas-fiscais.css` | Design system e componentes de UI (inclui o botão "i" de ajuda) compartilhados pelas telas do emissor |

## Instalação

### Requisitos

- PHP 8.1 ou superior.
- MySQL/MariaDB.
- Composer.
- Extensões DOM, GD, libxml, mbstring, OpenSSL e zlib (NFS-e) e também cURL e SOAP (NF-e, comunicação com a SEFAZ estadual).
- Certificado A1 válido para transmissão.
- SSH ou terminal da hospedagem.

Rode `nfe-diagnostico.php` (menu > "Diagnóstico NF-e", nível de acesso administrador) para confirmar no
servidor real que as extensões cURL/SOAP e as bibliotecas `nfephp-org/sped-nfe`/`sped-da` estão disponíveis
antes de confiar na emissão de NF-e em produção.

### Composer

Na raiz pública:

```bash
composer install --no-dev --optimize-autoloader
php -r "require 'vendor/autoload.php'; echo 'Composer OK'.PHP_EOL;"
php corrigir-vendor-danfse.php
```

`corrigir-vendor-danfse.php` corrige um bug de layout do pacote `mendesalexandre/php-nfse-nacional` (bloco "Descrição do Serviço" da DANFSe sobrepondo texto). Como `vendor/` não vai pro git, esse patch precisa rodar de novo sempre que `composer install` reinstalar o pacote do zero — o script é idempotente (não faz nada se já estiver corrigido).

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

### Filas de envio (NFS-e e NF-e)

Podem ser executadas pelo painel ou por tarefa agendada:

```bash
php processar-fila-nfse.php
php processar-fila-nfe.php
```

O cron deve usar a mesma versão do PHP do site (com as extensões cURL/SOAP habilitadas para a fila de NF-e).

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

### NFS-e

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

### NF-e

- [ ] `composer install --no-dev` rodado no servidor real (a pasta `vendor/` não vai no `git push`, está no `.gitignore`) e `php corrigir-vendor-danfse.php` rodado em seguida.
- [ ] `nfe-diagnostico.php` sem itens vermelhos (extensões `soap`/`curl` e bibliotecas `sped-nfe`/`sped-da`).
- [ ] Empresa com CNPJ, IE, endereço, UF e código IBGE completos; CRT configurado; série da NF-e definida.
- [ ] Certificado A1 válido e pertencente à empresa.
- [ ] Ambiente da empresa em Homologação para o primeiro teste.
- [ ] Cliente destinatário com endereço completo (obrigatório para a tag `enderDest`).
- [ ] Item com NCM (8 dígitos), CFOP e CST/CSOSN preenchidos; alíquotas de ICMS/IPI/PIS/COFINS conferidas.
- [ ] Se a venda for interestadual para consumidor final sem IE, a nota é bloqueada (DIFAL fora do escopo) — não é bug.
- [ ] Prévia em DANFE (botão "Conferência") conferida antes de mandar pra fila.
- [ ] Primeiro teste completo realizado e autorizado em homologação antes de trocar a empresa para Produção.
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
- **Emissão real de NF-e (produto), do zero**: até então a tela de NF-e só salvava rascunho local, sem gerar XML nem falar com a SEFAZ. Ficou pronto: cálculo de ICMS/ICMS-ST/IPI/PIS/COFINS/IBS-CBS por item; geração, assinatura e transmissão do XML via `nfephp-org/sped-nfe`; fila de envio, consulta e cancelamento; DANFE em PDF (autorizada e em prévia) via `nfephp-org/sped-da`.
- Autocompletar de CFOP (357 códigos oficiais de saída) e de NCM (tabela oficial completa, 10.515 códigos, baixada do Siscomex) com busca por código ou por nome do produto.
- Autocompletar de descrição do item pelo catálogo de produtos da empresa, com preenchimento automático de NCM/CFOP/CST/alíquotas.
- Situação tributária completa nos itens da NF-e: CSOSN × CST do ICMS conforme o regime da empresa, e a tabela oficial de ~30 códigos para PIS/COFINS — com o valor de cada imposto calculado e mostrado ao vivo no formulário.
- Suporte ao grupo IBS/CBS por item na NF-e (Reforma Tributária, LC 214/2025), reaproveitando os catálogos oficiais já usados na NFS-e.
- Cadastro de produtos/serviços reorganizado em seções, com campos de CEST/origem/fabricante/benefício fiscal, e passou a permitir edição de item (antes só desativar/reativar).
- Correção de dois bugs de dado, não de código novo: `catalogoJson` sendo escapado e serializado em dobro (quebrava a lista de itens do catálogo dentro do `<script>` assim que havia produtos cadastrados) e a coluna "Detalhe" da fila de NF-e mostrando a chave de acesso em vez do motivo real de rejeição.
- Correção de migração ausente: colunas novas do catálogo de produtos só tinham sido adicionadas em uma das três cópias da função de schema que o projeto já mantinha por entry-point (padrão pré-existente, não introduzido agora).
- Filtro por empresa emissora nas telas de notas fiscais e de clientes (o filtro de clientes usa `EXISTS` em `notas_fiscais`, já que o cadastro de cliente é compartilhado entre empresas e não tem coluna própria de empresa emissora).
- Ajuste manual da numeração de NF-e por empresa (`nfe_numero_base`): permite "avançar" a sequência quando já existe nota emitida fora do sistema, sem nunca reduzir o próximo número. Cadastro da empresa passou a mostrar ao vivo o último número lançado e o próximo, calculado direto de `notas_fiscais` (não é contador solto).
- Botão de informação ("i") com caixa de diálogo para os textos de ajuda de campo do cadastro da empresa emissora, substituindo texto sempre visível — componente novo e reaproveitável em `assets/css/notas-fiscais.css`.
- Relatório em Excel com os impostos (ICMS, ICMS-ST, IPI, PIS, COFINS) lidos do XML de cada NF-e sincronizada, exportável por período direto do buscador de NF-e.
- **Buscador de NFC-e via Distribuição de DFe da SEFAZ**: nova tela (`notas-fiscais-nfce-dfe.php`) que consulta as NFC-e ligadas ao CNPJ de cada empresa usando só o certificado A1 já cadastrado — pensada para a contabilidade acompanhar vendas de empresas-cliente mesmo quando a emissão não passa por este sistema. Reaproveita a mesma chamada `sefazDistDFe()` já usada pelo buscador de NF-e (mesmo NSU por empresa): o modelo do documento (55/65) é identificado pela própria chave de acesso, sem duplicar a consulta à SEFAZ. O cron existente (`processar-nfe-dfe-automatico.php`) passou a manter os dois buscadores em dia automaticamente, sem precisar de uma tarefa separada.

## Limites e cuidados

- Uma atividade da LC 116 pode ter várias NBS; nesses casos a escolha depende do serviço real.
- Endpoints nacionais e municipais são serviços externos.
- Novas notas técnicas exigem atualização dos catálogos.
- Após falha de rede, reconcilie a DPS antes de reenviar.
- NF-e não calcula DIFAL/partilha (venda interestadual para consumidor final não contribuinte) — a emissão é bloqueada nesse cenário em vez de calcular errado.
- NF-e não tem Carta de Correção Eletrônica (CC-e) nem contingência offline (SVC-AN/RS, EPEC) implementadas — só o fluxo normal de emissão, consulta e cancelamento.
- ICMS-ST na NF-e usa base/alíquota informadas manualmente no item; não há tabela de MVA por NCM/UF embutida.
- IBS/CBS na NF-e usa o schema local `PL_010_V1.30` (bundled na `nfephp-org/sped-nfe`) tanto para montar quanto para validar o XML antes de enviar — `nfeMontarXml()` (`new Make('PL_010_V1.30')`) e `montarToolsNfe()` (`'schemes' => 'PL_010_V1.30'`) precisam sempre apontar para o mesmo schema. Usar `PL_009_V4` em qualquer um dos dois faz a validação local falhar ("Element IBSCBS: this element is not expected") ou a nota ser enviada sem o grupo e a SEFAZ rejeitar por IBS/CBS não informado.

## Referências

- [Documentação técnica atual da NFS-e](https://www.gov.br/nfse/pt-br/biblioteca/documentacao-tecnica/documentacao-atual)
- [Anexo VIII — correlação fiscal](https://www.gov.br/nfse/pt-br/biblioteca/documentacao-tecnica/rtc/anexoviii-correlacaoitemnbsindopcclasstrib_ibscbs_v1-00-00.xlsx/view)
- [Nomenclatura Brasileira de Serviços — MDIC](https://www.gov.br/mdic/pt-br/assuntos/sdic/comercio-e-servicos/nbs-nomenclatura-brasileira-de-servicos)

---

Última consolidação: 3 de agosto de 2026 (relatório Excel de impostos no buscador de NF-e; e novo Buscador de NFC-e via Distribuição de DFe da SEFAZ, reaproveitando a sincronização e o cron já existentes do buscador de NF-e). Consolidação anterior: 29 de julho de 2026 (emissão real de NF-e do zero: cálculo de impostos por item, XML/assinatura/transmissão à SEFAZ, fila, DANFE, catálogos de CFOP/NCM/IBS-CBS e reorganização do cadastro de produtos; e, na sequência, filtro por empresa emissora em notas/clientes, ajuste manual da numeração de NF-e com exibição ao vivo do próximo número, e botão de informação para os textos de ajuda do cadastro da empresa).
