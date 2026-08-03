<?php
/**
 * Gerador do arquivo texto de importação da DES (Declaração Eletrônica de Serviços)
 * da Prefeitura de Belo Horizonte - leiaute "Manual do Usuário DES - Regras de
 * Importação", versão 2.05 (BHISS Digital / SMF-SMAAR-GETM).
 *
 * Cobre apenas os registros "H" (identificação), "E" (notas emitidas) e "R" (notas
 * recebidas) - os únicos necessários para declarar as notas de saída e de entrada de
 * um período. Os registros opcionais "D" (dedução de materiais), "L" (profissionais
 * liberais) e "T" (AIDF) não são gerados.
 *
 * A extração dos dados de contraparte (endereço, IM, alíquota, tipo de retenção) é
 * "melhor esforço" a partir do xml_completo já sincronizado do Portal Nacional, no
 * mesmo espírito do includes/impostos-xml.php: nem toda nota traz endereço completo
 * do tomador (o schema nacional só exige nome/documento), então os campos que não
 * existirem na nota ficam em branco/zerados conforme a regra de preenchimento do
 * próprio leiaute - o resultado deve ser conferido antes do envio à Prefeitura.
 */

const DES_TIPOS_LOGRADOURO = [
    'RUA', 'ACESSO', 'AEROPORTO', 'ALAMEDA', 'ATALHO', 'AVENIDA', 'BECO', 'BOULEVAR',
    'CAMINHO', 'CHACARA', 'CONJUNTO', 'CAMPO', 'CORREDOR', 'ENTRONCAMENTO', 'ESPLANADA',
    'ESTIVA', 'ESTACAO', 'ESTRADA', 'FAZENDA', 'FERROVIA', 'GALERIA', 'JARDIM', 'LADEIRA',
    'LAGO', 'LAGOA', 'LARGE', 'MORRO', 'PARQUE', 'PASSAGEM', 'PRACA', 'PRAIA', 'PORTO',
    'PASSEIO', 'RODOVIA', 'RUELA', 'RIO', 'SITIO', 'SUP QUADRA', 'TRAVESSA', 'VALE',
    'VIADUTO', 'VIELA', 'VIA', 'VILA', 'VARGEM',
];

/**
 * Troca por tabela (em vez de iconv//TRANSLIT, que insere lixo tipo apóstrofos e
 * circunflexos para caracteres que não sabe transliterar direito - ruim num arquivo
 * de largura fixa, onde cada caractere extra desalinha o resto do registro).
 */
function desTxtRemoverAcentos(string $valor): string
{
    static $de = ['á','à','â','ã','ä','é','è','ê','ë','í','ì','î','ï','ó','ò','ô','õ','ö','ú','ù','û','ü','ç','ñ','ý','ÿ',
        'Á','À','Â','Ã','Ä','É','È','Ê','Ë','Í','Ì','Î','Ï','Ó','Ò','Ô','Õ','Ö','Ú','Ù','Û','Ü','Ç','Ñ','Ý'];
    static $para = ['a','a','a','a','a','e','e','e','e','i','i','i','i','o','o','o','o','o','u','u','u','u','c','n','y','y',
        'A','A','A','A','A','E','E','E','E','I','I','I','I','O','O','O','O','O','U','U','U','U','C','N','Y'];

    return str_replace($de, $para, $valor);
}

function desTxtTexto(?string $valor, int $tamanho): string
{
    $valor = desTxtRemoverAcentos(trim((string) ($valor ?? '')));
    $valor = strtoupper($valor);

    return str_pad(substr($valor, 0, $tamanho), $tamanho, ' ', STR_PAD_RIGHT);
}

function desTxtNumero(?string $valor, int $tamanho): string
{
    $digitos = preg_replace('/\D+/', '', (string) ($valor ?? ''));
    $digitos = $digitos === '' ? '0' : $digitos;

    return str_pad(substr($digitos, -$tamanho), $tamanho, '0', STR_PAD_LEFT);
}

function desTxtValor(?float $valor, int $tamanho): string
{
    $formatado = number_format($valor ?? 0.0, 2, '.', '');

    return str_pad($formatado, $tamanho, '0', STR_PAD_LEFT);
}

function desTxtAliquota(?float $valor): string
{
    $formatado = number_format($valor ?? 0.0, 2, '.', '');

    return str_pad($formatado, 5, '0', STR_PAD_LEFT);
}

function desTxtData(?string $dataMysqlOuBr): string
{
    $valor = trim((string) ($dataMysqlOuBr ?? ''));
    if ($valor === '') {
        return str_repeat(' ', 10);
    }
    $timestamp = strtotime($valor);
    if ($timestamp === false) {
        return str_repeat(' ', 10);
    }

    return date('d/m/Y', $timestamp);
}

/**
 * Separa "RUA DAS FLORES" em tipo ("RUA") e nome ("DAS FLORES"), reconhecendo a
 * primeira palavra contra a lista oficial de tipos de logradouro do leiaute (item 5
 * do manual). Se não reconhecer, assume "RUA" (o mais comum) e mantém o texto todo
 * como nome - é só uma aproximação quando o endereço vem como string única.
 *
 * @return array{0: string, 1: string}
 */
function desTxtSepararLogradouro(?string $enderecoCompleto): array
{
    $endereco = trim((string) ($enderecoCompleto ?? ''));
    if ($endereco === '') {
        return ['', ''];
    }

    $semAcento = strtoupper(desTxtRemoverAcentos($endereco));
    foreach (DES_TIPOS_LOGRADOURO as $tipo) {
        if ($semAcento === $tipo || str_starts_with($semAcento, $tipo . ' ')) {
            $resto = trim(substr($endereco, strlen($tipo)));

            return [$tipo, $resto];
        }
    }

    return ['RUA', $endereco];
}

/**
 * Lê CNPJ/CPF/IM/nome/endereço de um bloco <emit> ou <toma> da NFS-e Nacional
 * (xmlns sem prefixo - getElementsByTagName ignora o namespace, então não precisa
 * de XPath com registro de namespace).
 *
 * @return array{cnpj: string, cpf: string, im: string, nome: string, tipo_logradouro: string,
 *     nome_logradouro: string, numero: string, bairro: string, municipio: string, uf: string, cep: string}
 */
function desTxtLerParteXml(?DOMElement $bloco): array
{
    $vazio = [
        'cnpj' => '', 'cpf' => '', 'im' => '', 'nome' => '',
        'tipo_logradouro' => '', 'nome_logradouro' => '', 'numero' => '',
        'bairro' => '', 'municipio' => '', 'uf' => '', 'cep' => '',
    ];
    if (!$bloco instanceof DOMElement) {
        return $vazio;
    }

    $ler = static function (DOMElement $base, string $tag): string {
        $lista = $base->getElementsByTagName($tag);

        return $lista->length > 0 ? trim((string) $lista->item(0)->nodeValue) : '';
    };

    $enderNac = $bloco->getElementsByTagName('enderNac')->item(0);
    [$tipoLogradouro, $nomeLogradouro] = $enderNac instanceof DOMElement
        ? desTxtSepararLogradouro($ler($enderNac, 'xLgr'))
        : ['', ''];

    return [
        'cnpj' => $ler($bloco, 'CNPJ'),
        'cpf' => $ler($bloco, 'CPF'),
        'im' => $ler($bloco, 'IM'),
        'nome' => $ler($bloco, 'xNome'),
        'tipo_logradouro' => $tipoLogradouro,
        'nome_logradouro' => $nomeLogradouro,
        'numero' => $enderNac instanceof DOMElement ? $ler($enderNac, 'nro') : '',
        'bairro' => $enderNac instanceof DOMElement ? $ler($enderNac, 'xBairro') : '',
        'municipio' => '',
        'uf' => $enderNac instanceof DOMElement ? $ler($enderNac, 'UF') : '',
        'cep' => $enderNac instanceof DOMElement ? $ler($enderNac, 'CEP') : '',
    ];
}

/**
 * Dados "melhor esforço" para preencher os registros E/R a partir do xml_completo:
 * endereço/documento de emitente e tomador (bloco <emit>/<toma> da NFS-e), alíquota
 * aplicada, indicador de retenção do ISSQN, local da prestação e opção pelo Simples
 * Nacional do prestador (relevante só no registro "R", onde o prestador é terceiro).
 *
 * @return array{emit: array, toma: array, aliquota: float|null, tipo_recolhimento: string,
 *     cidade_prestacao: string, simples_nacional: string, vServ: float|null}
 */
function extrairDadosDesNfseXml(?string $xml): array
{
    $vazio = [
        'emit' => desTxtLerParteXml(null),
        'toma' => desTxtLerParteXml(null),
        'aliquota' => null,
        'tipo_recolhimento' => 'A',
        'cidade_prestacao' => '',
        'simples_nacional' => 'N',
        'vServ' => null,
    ];

    $xml = trim((string) $xml);
    if ($xml === '') {
        return $vazio;
    }
    $doc = new DOMDocument();
    $anterior = libxml_use_internal_errors(true);
    $ok = $doc->loadXML($xml);
    libxml_clear_errors();
    libxml_use_internal_errors($anterior);
    if (!$ok) {
        return $vazio;
    }

    $emit = $doc->getElementsByTagName('emit')->item(0);
    $toma = $doc->getElementsByTagName('toma')->item(0);

    $lerNum = static function (string $tag) use ($doc): ?float {
        $lista = $doc->getElementsByTagName($tag);
        if ($lista->length === 0) {
            return null;
        }
        $valor = trim((string) $lista->item(0)->nodeValue);

        return $valor !== '' ? (float) $valor : null;
    };
    $lerTexto = static function (string $tag) use ($doc): string {
        $lista = $doc->getElementsByTagName($tag);

        return $lista->length > 0 ? trim((string) $lista->item(0)->nodeValue) : '';
    };

    $tpRetIssqn = $lerTexto('tpRetISSQN');
    $opSimpNac = $lerTexto('opSimpNac');
    // Domínio oficial do campo opSimpNac da DPS Nacional: 1-Não optante, 2-Optante MEI,
    // 3-Optante (demais casos). O leiaute da DES só distingue "S"/"N"/"M" (MEI).
    $simplesNacional = match ($opSimpNac) {
        '2' => 'M',
        '3' => 'S',
        default => 'N',
    };

    return [
        'emit' => desTxtLerParteXml($emit instanceof DOMElement ? $emit : null),
        'toma' => desTxtLerParteXml($toma instanceof DOMElement ? $toma : null),
        'aliquota' => $lerNum('pAliqAplic'),
        // tpRetISSQN: 1-Não retido, 2-Retido pelo tomador, 3-Retido pelo intermediário.
        'tipo_recolhimento' => $tpRetIssqn !== '' && $tpRetIssqn !== '1' ? 'R' : 'A',
        'cidade_prestacao' => $lerTexto('xLocPrestacao'),
        'simples_nacional' => $simplesNacional,
        'vServ' => $lerNum('vServ'),
    ];
}

function gerarRegistroDesH(string $inscricaoMunicipalEmpresa): string
{
    return 'H'
        . desTxtNumero($inscricaoMunicipalEmpresa, 11)
        . '100';
}

/**
 * Endereço/documento da própria empresa emissora (usada como tomador no registro "R"
 * e, por omissão, como base do "preposto" - deixado em branco - no registro "E").
 *
 * @param array<string, mixed> $empresa Linha de empresas_emissoras
 * @return array{cnpj: string, cpf: string, im: string, nome: string, tipo_logradouro: string,
 *     nome_logradouro: string, numero: string, bairro: string, municipio: string, uf: string, cep: string}
 */
function desTxtDadosProprios(array $empresa): array
{
    [$tipoLogradouro, $nomeLogradouro] = desTxtSepararLogradouro($empresa['logradouro'] ?? '');

    return [
        'cnpj' => (string) ($empresa['cnpj'] ?? ''),
        'cpf' => '',
        'im' => (string) ($empresa['inscricao_municipal'] ?? ''),
        'nome' => (string) ($empresa['razao_social'] ?? ''),
        'tipo_logradouro' => $tipoLogradouro,
        'nome_logradouro' => $nomeLogradouro,
        'numero' => (string) ($empresa['numero'] ?? ''),
        'bairro' => (string) ($empresa['bairro'] ?? ''),
        'municipio' => (string) ($empresa['municipio'] ?? ''),
        'uf' => (string) ($empresa['uf'] ?? ''),
        'cep' => (string) ($empresa['cep'] ?? ''),
    ];
}

/**
 * Registro "E" - nota emitida pela empresa (ela é a prestadora; tomador é a contraparte).
 *
 * @param array<string, mixed> $nota Linha de notas_fiscais_nfse_adn (tipo_documento = 'emitida')
 * @param array<string, mixed> $empresa Linha de empresas_emissoras
 */
function gerarRegistroDesE(array $nota, array $empresa, string $naturezaOperacao): string
{
    $dados = extrairDadosDesNfseXml($nota['xml_completo'] ?? null);
    $toma = $dados['toma'];
    $valorServico = $dados['vServ'] ?? (isset($nota['valor_servico']) ? (float) $nota['valor_servico'] : null);
    $cancelada = !empty($nota['cancelada']);
    $documentoTomadorEhCpf = $toma['cpf'] !== '' && $toma['cnpj'] === '';

    return 'E'
        . desTxtData($nota['data_emissao'] ?? null)
        . desTxtTexto('S', 2)
        . desTxtTexto('U', 1)
        . desTxtTexto($naturezaOperacao, 1)
        . desTxtNumero($nota['numero_nfse'] ?? '', 9)
        . desTxtValor($cancelada ? null : $valorServico, 15)
        . desTxtValor($cancelada ? null : $valorServico, 15)
        . desTxtTexto($dados['tipo_recolhimento'], 1)
        . desTxtAliquota($cancelada ? null : $dados['aliquota'])
        . desTxtNumero($toma['im'], 11)
        . desTxtNumero(!$documentoTomadorEhCpf ? ($nota['cnpj_tomador'] ?? $toma['cnpj']) : '', 14)
        . desTxtNumero($documentoTomadorEhCpf ? $toma['cpf'] : '', 11)
        . desTxtTexto(($nota['nome_tomador'] ?? '') !== '' ? $nota['nome_tomador'] : ($toma['nome'] !== '' ? $toma['nome'] : 'DIVERSOS'), 40)
        . desTxtTexto($toma['tipo_logradouro'] !== '' ? $toma['tipo_logradouro'] : 'RUA', 10)
        . desTxtTexto($toma['nome_logradouro'], 50)
        . desTxtNumero($toma['numero'], 6)
        . desTxtTexto('', 20)
        . desTxtTexto('BAIRRO', 10)
        . desTxtTexto($toma['bairro'], 50)
        . desTxtTexto($toma['municipio'], 30)
        . desTxtTexto($toma['uf'], 2)
        . desTxtNumero($toma['cep'], 8)
        . desTxtNumero('', 9)
        . desTxtTexto('', 2)
        . desTxtNumero($nota['numero_nfse'] ?? '', 9)
        . desTxtTexto($dados['cidade_prestacao'], 30)
        . desTxtTexto($empresa['uf'] ?? '', 2)
        . desTxtNumero('', 11)
        . desTxtNumero('', 14)
        . desTxtNumero('', 11)
        . desTxtTexto('', 40)
        . desTxtTexto('', 10)
        . desTxtTexto('', 50)
        . desTxtNumero('', 6)
        . desTxtTexto('', 20)
        . desTxtTexto('', 10)
        . desTxtTexto('', 50)
        . desTxtTexto('', 30)
        . desTxtTexto('', 2)
        . desTxtNumero('', 8)
        . desTxtTexto($cancelada ? 'C' : 'N', 1)
        . desTxtTexto('', 20)
        . desTxtData(null);
}

/**
 * Registro "R" - nota recebida pela empresa (ela é a tomadora; prestador é a contraparte).
 *
 * @param array<string, mixed> $nota Linha de notas_fiscais_nfse_adn (tipo_documento = 'recebida')
 * @param array<string, mixed> $empresa Linha de empresas_emissoras
 */
function gerarRegistroDesR(array $nota, array $empresa, string $naturezaOperacao): string
{
    $dados = extrairDadosDesNfseXml($nota['xml_completo'] ?? null);
    $prest = $dados['emit'];
    $tomadorProprio = desTxtDadosProprios($empresa);
    $valorServico = $dados['vServ'] ?? (isset($nota['valor_servico']) ? (float) $nota['valor_servico'] : null);
    $documentoPrestadorEhCpf = $prest['cpf'] !== '' && $prest['cnpj'] === '';
    $reteveIssqn = $dados['tipo_recolhimento'] === 'R';

    return 'R'
        . desTxtData($nota['data_emissao'] ?? null)
        . desTxtData($nota['data_emissao'] ?? null)
        . desTxtTexto('S', 2)
        . desTxtTexto('U', 1)
        . desTxtTexto($naturezaOperacao, 1)
        . desTxtNumero($nota['numero_nfse'] ?? '', 9)
        . desTxtValor($valorServico, 15)
        . desTxtValor($valorServico, 15)
        . desTxtAliquota($reteveIssqn ? $dados['aliquota'] : null)
        . desTxtNumero('', 6)
        . desTxtNumero('', 6)
        . desTxtTexto('', 30)
        . desTxtNumero($prest['im'], 11)
        . desTxtNumero(!$documentoPrestadorEhCpf ? ($nota['cnpj_prestador'] ?? $prest['cnpj']) : '', 14)
        . desTxtNumero($documentoPrestadorEhCpf ? $prest['cpf'] : '', 11)
        . desTxtTexto($nota['nome_prestador'] ?? $prest['nome'], 40)
        . desTxtTexto($prest['tipo_logradouro'] !== '' ? $prest['tipo_logradouro'] : 'RUA', 10)
        . desTxtTexto($prest['nome_logradouro'], 50)
        . desTxtNumero($prest['numero'], 6)
        . desTxtTexto('', 20)
        . desTxtTexto('BAIRRO', 10)
        . desTxtTexto($prest['bairro'], 50)
        . desTxtTexto($prest['municipio'], 30)
        . desTxtTexto($prest['uf'], 2)
        . desTxtNumero($prest['cep'], 8)
        . desTxtTexto('', 2)
        . desTxtTexto($dados['cidade_prestacao'], 30)
        . desTxtTexto($prest['uf'], 2)
        . desTxtNumero($tomadorProprio['im'], 11)
        . desTxtNumero($tomadorProprio['cnpj'], 14)
        . desTxtNumero('', 11)
        . desTxtTexto($tomadorProprio['nome'], 40)
        . desTxtTexto($tomadorProprio['tipo_logradouro'] !== '' ? $tomadorProprio['tipo_logradouro'] : 'RUA', 10)
        . desTxtTexto($tomadorProprio['nome_logradouro'], 50)
        . desTxtNumero($tomadorProprio['numero'], 6)
        . desTxtTexto('', 20)
        . desTxtTexto('BAIRRO', 10)
        . desTxtTexto($tomadorProprio['bairro'], 50)
        . desTxtTexto($tomadorProprio['municipio'], 30)
        . desTxtTexto($tomadorProprio['uf'], 2)
        . desTxtNumero($tomadorProprio['cep'], 8)
        . desTxtTexto($dados['simples_nacional'], 1)
        . desTxtTexto('', 30)
        . desTxtData(null);
}

/**
 * Monta o conteúdo final do arquivo (CRLF após cada registro + EOF asc(26), conforme
 * as observações 2 e 3 do item 3 do manual).
 *
 * @param string[] $registros
 */
function gerarArquivoDesTxt(array $registros): string
{
    $conteudo = '';
    foreach ($registros as $registro) {
        $conteudo .= $registro . "\r\n";
    }

    return $conteudo . chr(26);
}

function gerarDesTxtDownload(string $nomeArquivo, array $registros): void
{
    $conteudo = gerarArquivoDesTxt($registros);
    header('Content-Type: text/plain; charset=ISO-8859-1');
    header('Content-Disposition: attachment; filename="' . $nomeArquivo . '"');
    header('Content-Length: ' . strlen($conteudo));
    echo $conteudo;
    exit;
}
