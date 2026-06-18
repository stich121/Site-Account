<?php
$page = 'politica-cookies';
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Política de Cookies | ACCOUNT Contabilidade Estratégica</title>
    <link rel="icon" type="image/svg+xml" href="favicon.svg">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600&family=Montserrat:wght@500;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        :root {
            --bg-main: #0A0A0A;
            --bg-card: #161616;
            --primary: #74C92C;
            --primary-hover: #5EA522;
            --secondary: #8E8E93;
            --text-white: #FFFFFF;
            --text-light: #F5F5F7;
            --text-muted: #A1A1A6;
            --font-titles: 'Montserrat', sans-serif;
            --font-body: 'Inter', sans-serif;
            --transition: all 0.3s ease;
        }

        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        html {
            scrollbar-color: var(--primary) transparent;
            scrollbar-width: thin;
        }

        *::-webkit-scrollbar {
            width: 10px;
        }

        *::-webkit-scrollbar-thumb {
            background: linear-gradient(135deg, var(--primary), var(--primary-hover));
            border-radius: 999px;
        }

        body {
            font-family: var(--font-body);
            background-color: var(--bg-main);
            color: var(--text-light);
            line-height: 1.6;
            overflow-x: hidden;
        }

        h1, h2, h3, h4, h5, h6 {
            font-family: var(--font-titles);
            text-transform: uppercase;
            color: var(--text-white);
        }

        a {
            text-decoration: none;
            color: inherit;
            transition: var(--transition);
        }

        .container {
            width: 100%;
            max-width: 900px;
            margin: 0 auto;
            padding: 0 2rem;
        }

        header {
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            padding: 0.5rem 0;
            background: rgba(10, 10, 10, 0.85);
            backdrop-filter: blur(10px);
            z-index: 1000;
            border-bottom: 1px solid rgba(255, 255, 255, 0.05);
        }

        .header-content {
            display: flex;
            justify-content: space-between;
            align-items: center;
        }

        .logo {
            display: flex;
            align-items: center;
        }

        .logo-img {
            max-height: 32px;
            width: auto;
            display: block;
        }

        nav ul {
            display: flex;
            gap: 1.5rem;
            align-items: center;
        }

        nav a {
            font-size: 0.9rem;
            font-weight: 500;
            color: var(--text-muted);
        }

        nav a:hover {
            color: var(--primary);
        }

        .section {
            padding-top: 140px;
            min-height: 100vh;
            padding-bottom: 4rem;
        }

        .legal-content {
            background: var(--bg-card);
            border-radius: 12px;
            padding: 3rem;
            margin-bottom: 3rem;
            border: 1px solid rgba(255, 255, 255, 0.05);
        }

        .legal-content h2 {
            font-size: 1.8rem;
            margin-top: 2.5rem;
            margin-bottom: 1rem;
            color: var(--primary);
        }

        .legal-content h3 {
            font-size: 1.2rem;
            margin-top: 2rem;
            margin-bottom: 0.8rem;
        }

        .legal-content p {
            color: var(--text-muted);
            margin-bottom: 1rem;
            line-height: 1.8;
        }

        .legal-content ul, .legal-content ol {
            margin-left: 2rem;
            margin-bottom: 1rem;
        }

        .legal-content li {
            margin-bottom: 0.5rem;
            color: var(--text-muted);
        }

        .legal-content strong {
            color: var(--text-light);
        }

        .cookie-table {
            width: 100%;
            margin: 1.5rem 0;
            border-collapse: collapse;
        }

        .cookie-table th, .cookie-table td {
            border: 1px solid rgba(255, 255, 255, 0.1);
            padding: 1rem;
            text-align: left;
            color: var(--text-muted);
        }

        .cookie-table th {
            background: rgba(116, 201, 44, 0.1);
            color: var(--primary);
            font-weight: 600;
        }

        footer {
            background: #050505;
            padding: 4rem 0 2rem;
            border-top: 1px solid rgba(255, 255, 255, 0.05);
        }

        .footer-grid {
            display: grid;
            grid-template-columns: 2fr 1fr 1fr;
            gap: 4rem;
            margin-bottom: 3rem;
        }

        .footer-about p {
            color: var(--text-muted);
            max-width: 400px;
            margin-top: 1rem;
        }

        .footer-links h4 {
            margin-bottom: 1.5rem;
            color: var(--text-white);
        }

        .footer-links ul li {
            margin-bottom: 0.8rem;
        }

        .footer-links a {
            color: var(--text-muted);
        }

        .footer-links a:hover {
            color: var(--primary);
        }

        .footer-bottom {
            text-align: center;
            padding-top: 2rem;
            border-top: 1px solid rgba(255, 255, 255, 0.05);
            color: var(--secondary);
            font-size: 0.8rem;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }

        .logo-img-footer {
            max-height: 40px;
            width: auto;
        }

        @media (max-width: 768px) {
            .footer-grid {
                grid-template-columns: 1fr;
                gap: 2rem;
            }

            .footer-bottom {
                flex-direction: column;
                gap: 1rem;
            }

            .legal-content {
                padding: 2rem;
            }

            .legal-content h2 {
                font-size: 1.4rem;
            }

            .cookie-table {
                font-size: 0.9rem;
            }

            .cookie-table th, .cookie-table td {
                padding: 0.7rem;
            }
        }
    </style>
</head>
<body>
    <header>
        <div class="container header-content">
            <a href="/" class="logo">
                <img src="logo-branca.png" alt="ACCOUNT CONTABILIDADE Logo" class="logo-img">
            </a>
            <nav>
                <ul>
                    <li><a href="/">Home</a></li>
                    <li><a href="servicos">Serviços</a></li>
                    <li><a href="contato">Contato</a></li>
                </ul>
            </nav>
        </div>
    </header>

    <main>
        <section class="section">
            <div class="container">
                <div class="legal-content">
                    <h1>Política de Cookies</h1>
                    <p><strong>Última atualização: <?php echo date('d/m/Y'); ?></strong></p>

                    <h2>1. O que são Cookies?</h2>
                    <p>Cookies são pequenos arquivos de texto armazenados no seu dispositivo quando você visita nosso website. Eles permitem que o site reconheça seu dispositivo, armazene preferências e melhore sua experiência de navegação.</p>

                    <h2>2. Tipos de Cookies que Usamos</h2>

                    <h3>2.1 Cookies Essenciais</h3>
                    <p>Necessários para o funcionamento básico do site. Incluem:</p>
                    <ul>
                        <li>Cookies de sessão para manter você conectado</li>
                        <li>Cookies de segurança</li>
                        <li>Cookies de preferência de idioma</li>
                    </ul>

                    <h3>2.2 Cookies de Performance</h3>
                    <p>Coletam informações sobre como você usa nosso site para melhorar o desempenho:</p>
                    <ul>
                        <li>Páginas visitadas</li>
                        <li>Tempo gasto em cada página</li>
                        <li>Cliques e interações</li>
                        <li>Erros de navegação</li>
                    </ul>

                    <h3>2.3 Cookies de Funcionalidade</h3>
                    <p>Permitem que o site lembre suas escolhas e preferências:</p>
                    <ul>
                        <li>Informações de formulário</li>
                        <li>Preferências de exibição</li>
                        <li>Dados de autenticação do cliente</li>
                    </ul>

                    <h3>2.4 Cookies de Marketing</h3>
                    <p>Usados para rastrear sua navegação e mostrar anúncios relevantes (apenas com consentimento):</p>
                    <ul>
                        <li>Cookies de campanhas publicitárias</li>
                        <li>Cookies de redes sociais</li>
                        <li>Cookies de análise de conversão</li>
                    </ul>

                    <h2>3. Tabela de Cookies Utilizados</h2>
                    <table class="cookie-table">
                        <thead>
                            <tr>
                                <th>Nome do Cookie</th>
                                <th>Tipo</th>
                                <th>Duração</th>
                                <th>Finalidade</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr>
                                <td>_ga</td>
                                <td>Performance</td>
                                <td>2 anos</td>
                                <td>Google Analytics - identificação de usuário</td>
                            </tr>
                            <tr>
                                <td>_gid</td>
                                <td>Performance</td>
                                <td>24 horas</td>
                                <td>Google Analytics - sessão</td>
                            </tr>
                            <tr>
                                <td>PHPSESSID</td>
                                <td>Essencial</td>
                                <td>Sessão</td>
                                <td>Manutenção de sessão do usuário</td>
                            </tr>
                            <tr>
                                <td>language</td>
                                <td>Funcionalidade</td>
                                <td>1 ano</td>
                                <td>Preferência de idioma</td>
                            </tr>
                            <tr>
                                <td>consent</td>
                                <td>Essencial</td>
                                <td>1 ano</td>
                                <td>Registro de consentimento de cookies</td>
                            </tr>
                        </tbody>
                    </table>

                    <h2>4. Tecnologias de Rastreamento</h2>
                    <p>Além de cookies, usamos:</p>
                    <ul>
                        <li><strong>Google Analytics:</strong> Para analisar tráfego e comportamento do usuário</li>
                        <li><strong>Pixels de rastreamento:</strong> Para medir conversões</li>
                        <li><strong>Local Storage:</strong> Para armazenar preferências do navegador</li>
                    </ul>

                    <h2>5. Seu Controle sobre Cookies</h2>
                    <p>Você pode controlar e deletar cookies através das configurações do seu navegador:</p>

                    <h3>Chrome</h3>
                    <p>Configurações → Privacidade e segurança → Cookies e outros dados do site → Gerenciar todos os dados do site</p>

                    <h3>Firefox</h3>
                    <p>Preferências → Privacidade e segurança → Cookies e dados do site</p>

                    <h3>Safari</h3>
                    <p>Preferências → Privacidade → Gerenciar dados do site</p>

                    <h3>Edge</h3>
                    <p>Configurações → Privacidade, pesquisa e serviços → Cookies e outros dados do site</p>

                    <h2>6. Consentimento de Cookies</h2>
                    <p>Ao acessar nosso site, você consentirá com o uso de cookies essenciais e de funcionalidade. Cookies de marketing e rastreamento requerem seu consentimento explícito, que você pode fornecer através de nosso banner de consentimento.</p>

                    <h2>7. Impacto de Desabilitar Cookies</h2>
                    <p>Se você optar por desabilitar cookies, algumas funcionalidades do site podem não funcionar corretamente, incluindo:</p>
                    <ul>
                        <li>Manutenção de login</li>
                        <li>Armazenamento de formulários</li>
                        <li>Preferências personalizadas</li>
                    </ul>

                    <h2>8. Cookies de Terceiros</h2>
                    <p>Nosso site pode conter cookies de terceiros através de:</p>
                    <ul>
                        <li>Google Analytics</li>
                        <li>Redes sociais (Facebook, Instagram, LinkedIn)</li>
                        <li>Serviços de análise</li>
                    </ul>
                    <p>Não controlamos esses cookies. Recomendamos consultar as políticas de privacidade dos respectivos provedores.</p>

                    <h2>9. Do Not Track (DNT)</h2>
                    <p>Se você ativar o sinal DNT em seu navegador, respeitaremos sua preferência e limitaremos o rastreamento de cookies de performance e marketing.</p>

                    <h2>10. Duração da Retenção</h2>
                    <p>Cookies essenciais são armazenados enquanto necessário para o funcionamento do site. Cookies de performance e marketing são retidos por até 2 anos, a menos que você os delete antes.</p>

                    <h2>11. Atualizações da Política</h2>
                    <p>Esta Política de Cookies pode ser atualizada periodicamente para refletir mudanças em nossas práticas ou requisitos legais. A data de última atualização está no topo desta página.</p>

                    <h2>12. Contato</h2>
                    <p>Se tiver dúvidas sobre nosso uso de cookies, entre em contato:</p>
                    <ul>
                        <li><strong>WhatsApp:</strong> +55 31 98525-8078</li>
                        <li><strong>Email:</strong> Disponível na página de contato</li>
                        <li><strong>Endereço:</strong> Belo Horizonte, MG</li>
                    </ul>
                </div>
            </div>
        </section>
    </main>

    <footer>
        <div class="container">
            <div class="footer-grid">
                <div class="footer-about">
                    <div>
                        <img src="logo-branca.png" alt="ACCOUNT Logo" class="logo-img-footer">
                    </div>
                    <p>Contabilidade estratégica e inteligência financeira para empresas que buscam alta performance, segurança jurídica e crescimento escalável.</p>
                </div>
                <div class="footer-links">
                    <h4>Navegação</h4>
                    <ul>
                        <li><a href="/">Home</a></li>
                        <li><a href="servicos">Serviços</a></li>
                        <li><a href="sobre">Sobre</a></li>
                        <li><a href="contato">Contato</a></li>
                    </ul>
                </div>
                <div class="footer-links">
                    <h4>Legal</h4>
                    <ul>
                        <li><a href="termos-uso">Termos de Uso</a></li>
                        <li><a href="politica-privacidade">Privacidade</a></li>
                        <li><a href="politica-cookies">Cookies</a></li>
                    </ul>
                </div>
            </div>
            <div class="footer-bottom">
                <p>© 2026 Account Contabilidade. Todos os direitos reservados.</p>
            </div>
        </div>
    </footer>
</body>
</html>
