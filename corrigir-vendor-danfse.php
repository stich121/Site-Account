<?php
// Corrige um bug de layout do pacote mendesalexandre/php-nfse-nacional (DANFSe V2):
// o bloco "SERVIÇO PRESTADO" reservava altura fixa (0.45cm, ~1 linha) para o texto
// oficial do CTN (xTribNac), que pode ter 2+ linhas - quando isso acontecia, o texto
// vazava por cima do bloco "Descrição do Serviço" logo abaixo.
//
// Como vendor/ está no .gitignore (não vai pro git push), esse patch não sobrevive a um
// `composer install` novo no servidor - por isso existe este script: rode-o sempre depois
// de `composer install` (ver README, checklist de deploy). Ele é idempotente: se o arquivo
// já estiver corrigido ou se a versão do pacote mudou o trecho, ele avisa e não faz nada.
//
// Uso: php corrigir-vendor-danfse.php

$arquivo = __DIR__ . '/vendor/mendesalexandre/php-nfse-nacional/src/Danfse/Layouts/DanfseLayoutV2.php';

if (!file_exists($arquivo)) {
    fwrite(STDERR, "Arquivo não encontrado: {$arquivo}\nRode 'composer install' antes.\n");
    exit(1);
}

$conteudo = file_get_contents($arquivo);

$marcador = 'Corrige altura fixa do bloco de tributação nacional (xTribNac) da DANFSe';
if (str_contains($conteudo, $marcador)) {
    echo "Já corrigido, nada a fazer.\n";
    exit(0);
}

$original = <<<'PHP'
        $this->renderCelulaTextoLongo(0.30, $this->cursorY, 20.40, 0.45,
            $s['descricao_tributacao_nacional'] ?? '-');
        $this->cursorY += 0.45;

        // Altura dinâmica: descrição de serviço não tem tamanho máximo (até
PHP;

$corrigido = <<<'PHP'
        // Corrige altura fixa do bloco de tributação nacional (xTribNac) da DANFSe
        // (ver corrigir-vendor-danfse.php na raiz do projeto).
        $textoTribNac = $s['descricao_tributacao_nacional'] ?? '-';
        $this->setFonte(DanfseLayout::FONTE_CONTEUDO, '', DanfseLayout::TAM_CONTEUDO);
        $alturaTribNacMm = $this->pdf->getStringHeight(
            DanfseLayout::cmToMm(20.40 - 1.0),
            $textoTribNac,
        );
        $alturaTribNacCm = max(0.45, ($alturaTribNacMm / 10) + 0.15);
        $this->renderCelulaTextoLongo(0.30, $this->cursorY, 20.40, $alturaTribNacCm,
            $textoTribNac);
        $this->cursorY += $alturaTribNacCm;

        // Altura dinâmica: descrição de serviço não tem tamanho máximo (até
PHP;

if (!str_contains($conteudo, $original)) {
    fwrite(STDERR, "Trecho esperado não encontrado - o pacote mendesalexandre/php-nfse-nacional deve ter mudado de versão.\nVerifique manualmente renderServico() em {$arquivo}.\n");
    exit(1);
}

$novoConteudo = str_replace($original, $corrigido, $conteudo);
file_put_contents($arquivo, $novoConteudo);

echo "Corrigido com sucesso: {$arquivo}\n";
