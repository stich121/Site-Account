<?php
/**
 * Gerador mínimo de arquivos .xlsx (OOXML/SpreadsheetML) sem dependências externas,
 * no mesmo espírito do gerarPdfSimples()/ZipArchive já usados no projeto.
 */

function xlsxColunaLetra(int $indiceZeroBased): string
{
    $letra = '';
    $n = $indiceZeroBased + 1;
    while ($n > 0) {
        $resto = ($n - 1) % 26;
        $letra = chr(65 + $resto) . $letra;
        $n = intdiv($n - 1, 26);
    }
    return $letra;
}

function xlsxTextoXml(string $valor): string
{
    return htmlspecialchars($valor, ENT_QUOTES | ENT_XML1, 'UTF-8');
}

/**
 * @param string[] $cabecalhos Nomes das colunas.
 * @param array<int, array<int, string|int|float|null>> $linhas Cada linha é um array de valores na mesma ordem dos cabeçalhos.
 */
function gerarXlsxBytes(string $nomeAba, array $cabecalhos, array $linhas): string
{
    if (!class_exists('ZipArchive')) {
        throw new RuntimeException('Geração de Excel indisponível: a extensão PHP "zip" não está habilitada neste servidor.');
    }

    $nomeAba = xlsxTextoXml(mb_substr($nomeAba, 0, 31));

    $sheetXml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>' . "\n";
    $sheetXml .= '<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">';
    $sheetXml .= '<sheetData>';

    $sheetXml .= '<row r="1">';
    foreach ($cabecalhos as $indice => $cabecalho) {
        $ref = xlsxColunaLetra($indice) . '1';
        $sheetXml .= '<c r="' . $ref . '" t="inlineStr" s="1"><is><t>' . xlsxTextoXml((string) $cabecalho) . '</t></is></c>';
    }
    $sheetXml .= '</row>';

    $numeroLinha = 2;
    foreach ($linhas as $linha) {
        $sheetXml .= '<row r="' . $numeroLinha . '">';
        foreach (array_values($linha) as $indice => $valor) {
            $ref = xlsxColunaLetra($indice) . $numeroLinha;
            if ($valor === null || $valor === '') {
                $sheetXml .= '<c r="' . $ref . '" s="0"/>';
            } elseif (is_int($valor) || is_float($valor)) {
                $sheetXml .= '<c r="' . $ref . '" s="0"><v>' . (string) $valor . '</v></c>';
            } else {
                $sheetXml .= '<c r="' . $ref . '" t="inlineStr" s="0"><is><t>' . xlsxTextoXml((string) $valor) . '</t></is></c>';
            }
        }
        $sheetXml .= '</row>';
        $numeroLinha++;
    }

    $sheetXml .= '</sheetData></worksheet>';

    $contentTypes = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>' . "\n"
        . '<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">'
        . '<Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>'
        . '<Default Extension="xml" ContentType="application/xml"/>'
        . '<Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/>'
        . '<Override PartName="/xl/worksheets/sheet1.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>'
        . '<Override PartName="/xl/styles.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.styles+xml"/>'
        . '</Types>';

    $rootRels = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>' . "\n"
        . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
        . '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/>'
        . '</Relationships>';

    $workbookXml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>' . "\n"
        . '<workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">'
        . '<sheets><sheet name="' . $nomeAba . '" sheetId="1" r:id="rId1"/></sheets>'
        . '</workbook>';

    $workbookRels = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>' . "\n"
        . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
        . '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet1.xml"/>'
        . '<Relationship Id="rId2" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/styles" Target="styles.xml"/>'
        . '</Relationships>';

    $stylesXml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>' . "\n"
        . '<styleSheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">'
        . '<fonts count="2"><font><sz val="11"/><name val="Calibri"/></font><font><b/><sz val="11"/><name val="Calibri"/></font></fonts>'
        . '<fills count="1"><fill><patternFill patternType="none"/></fill></fills>'
        . '<borders count="1"><border/></borders>'
        . '<cellStyleXfs count="1"><xf numFmtId="0" fontId="0"/></cellStyleXfs>'
        . '<cellXfs count="2"><xf numFmtId="0" fontId="0" xfId="0"/><xf numFmtId="0" fontId="1" xfId="0" applyFont="1"/></cellXfs>'
        . '</styleSheet>';

    $arquivoTemp = tempnam(sys_get_temp_dir(), 'xlsx_');
    $zip = new ZipArchive();
    if ($zip->open($arquivoTemp, ZipArchive::OVERWRITE) !== true) {
        throw new RuntimeException('Não foi possível gerar o arquivo Excel.');
    }
    $zip->addFromString('[Content_Types].xml', $contentTypes);
    $zip->addFromString('_rels/.rels', $rootRels);
    $zip->addFromString('xl/workbook.xml', $workbookXml);
    $zip->addFromString('xl/_rels/workbook.xml.rels', $workbookRels);
    $zip->addFromString('xl/styles.xml', $stylesXml);
    $zip->addFromString('xl/worksheets/sheet1.xml', $sheetXml);
    $zip->close();

    $bytes = file_get_contents($arquivoTemp);
    unlink($arquivoTemp);

    return $bytes !== false ? $bytes : '';
}

/**
 * Gera o .xlsx e já envia os headers de download, encerrando a requisição.
 *
 * @param string[] $cabecalhos
 * @param array<int, array<int, string|int|float|null>> $linhas
 */
function gerarXlsxDownload(string $nomeArquivo, string $nomeAba, array $cabecalhos, array $linhas): void
{
    $bytes = gerarXlsxBytes($nomeAba, $cabecalhos, $linhas);
    header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
    header('Content-Disposition: attachment; filename="' . $nomeArquivo . '"');
    header('Content-Length: ' . strlen($bytes));
    echo $bytes;
    exit;
}
