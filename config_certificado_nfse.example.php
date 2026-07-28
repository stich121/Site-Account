<?php
// Copie este arquivo para config_certificado_nfse.php e preencha com os dados reais
// do certificado digital A1 (.pfx) usado para assinar e transmitir as NFS-e.
//
// IMPORTANTE:
// - O arquivo .pfx NUNCA deve ficar dentro de uma pasta acessível publicamente pelo navegador.
//   Guarde-o fora da raiz do site (ex.: um nível acima de public_html) ou, se isso não for
//   possível na hospedagem, dentro de uma pasta já bloqueada por Require all denied (como
//   ".security/", que este projeto já usa e já está no .gitignore).
// - Nunca commite o certificado nem a senha no Git.
// - CERTIFICADO_NFSE_AMBIENTE_PADRAO deve ficar 'homologacao' até os testes serem
//   validados; só troque para 'producao' quando o time confirmar que pode emitir de verdade.

const CERTIFICADO_NFSE_CAMINHO = '/caminho/fora/da/pasta/publica/certificado.pfx';
const CERTIFICADO_NFSE_SENHA = 'senha_do_certificado';
const CERTIFICADO_NFSE_AMBIENTE_PADRAO = 'homologacao'; // 'homologacao' ou 'producao'

// URLs oficiais do Portal Nacional da NFS-e (SEFIN Nacional / ADN).
const NFSE_NACIONAL_SEFIN_HOMOLOGACAO = 'https://sefin.producaorestrita.nfse.gov.br';
const NFSE_NACIONAL_ADN_HOMOLOGACAO = 'https://adn.producaorestrita.nfse.gov.br';
const NFSE_NACIONAL_SEFIN_PRODUCAO = 'https://sefin.nfse.gov.br';
const NFSE_NACIONAL_ADN_PRODUCAO = 'https://adn.nfse.gov.br';

function certificadoNfseConfigurado(): bool
{
    return CERTIFICADO_NFSE_CAMINHO !== '' && is_file(CERTIFICADO_NFSE_CAMINHO);
}
?>
