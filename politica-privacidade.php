<?php
$page = 'politica-privacidade';
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Política de Privacidade | ACCOUNT Contabilidade Estratégica</title>
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
                    <h1>Política de Privacidade</h1>
                    <p><strong>Última atualização: <?php echo date('d/m/Y'); ?></strong></p>

                    <h2>1. Introdução</h2>
                    <p>A ACCOUNT Contabilidade Estratégica está comprometida em proteger sua privacidade. Esta Política de Privacidade explica como coletamos, usamos, divulgamos e salvaguardamos suas informações ao visitar nosso website.</p>

                    <h2>2. Informações que Coletamos</h2>
                    <p>Coletamos informações de diferentes formas:</p>

                    <h3>2.1 Informações Fornecidas Voluntariamente</h3>
                    <p>Quando você preenche formulários em nosso site, como formulários de orçamento ou contato, coletamos:</p>
                    <ul>
                        <li>Nome completo</li>
                        <li>Email</li>
                        <li>Número de telefone/WhatsApp</li>
                        <li>Informações sobre sua empresa (segmento, faturamento)</li>
                        <li>Qualquer outra informação que você escolha fornecer</li>
                    </ul>

                    <h3>2.2 Informações Coletadas Automaticamente</h3>
                    <p>Quando você acessa nosso site, coletamos automaticamente:</p>
                    <ul>
                        <li>Endereço IP</li>
                        <li>Tipo de navegador e versão</li>
                        <li>Sistema operacional</li>
                        <li>Páginas visitadas e tempo gasto</li>
                        <li>Informações de referência</li>
                        <li>Dados de geolocalização (país/região)</li>
                    </ul>

                    <h2>3. Como Usamos Suas Informações</h2>
                    <p>Usamos as informações coletadas para:</p>
                    <ul>
                        <li>Responder a seus pedidos e consultas</li>
                        <li>Fornecer orçamentos e serviços solicitados</li>
                        <li>Enviar comunicações de marketing (com seu consentimento)</li>
                        <li>Melhorar nosso site e serviços</li>
                        <li>Cumprir obrigações legais</li>
                        <li>Prevenir atividades fraudulentas ou ilegais</li>
                        <li>Analisar tendências e comportamento do usuário</li>
                    </ul>

                    <h2>4. Compartilhamento de Informações</h2>
                    <p>Não vendemos, alugamos ou compartilhamos suas informações pessoais com terceiros, exceto:</p>
                    <ul>
                        <li>Com seu consentimento explícito</li>
                        <li>Para cumprir obrigações legais</li>
                        <li>Com prestadores de serviço que nos ajudam a operar o site (sob acordos de confidencialidade)</li>
                        <li>Em caso de fusão, aquisição ou venda de ativos</li>
                    </ul>

                    <h2>5. Segurança de Dados</h2>
                    <p>Implementamos medidas de segurança técnicas, administrativas e físicas para proteger suas informações pessoais contra acesso não autorizado, alteração, divulgação ou destruição. No entanto, nenhum método de transmissão pela Internet é 100% seguro.</p>

                    <h2>6. Retenção de Dados</h2>
                    <p>Mantemos seus dados pessoais apenas pelo tempo necessário para alcançar os fins para os quais foram coletados ou conforme exigido por lei. Para informações de contato de formulários, retenemos por até 2 anos para fins de acompanhamento comercial.</p>

                    <h2>7. Seus Direitos</h2>
                    <p>Você tem direito a:</p>
                    <ul>
                        <li>Acessar suas informações pessoais</li>
                        <li>Corrigir dados imprecisos</li>
                        <li>Solicitar exclusão de seus dados</li>
                        <li>Optar por não receber comunicações de marketing</li>
                        <li>Portar seus dados para outro serviço</li>
                    </ul>
                    <p>Para exercer esses direitos, entre em contato conosco usando as informações fornecidas no final desta política.</p>

                    <h2>8. Links Externos</h2>
                    <p>Nosso site pode conter links para outros sites. Não somos responsáveis pelas políticas de privacidade de sites de terceiros. Recomendamos que você revise suas políticas ao acessar links externos.</p>

                    <h2>9. Conformidade com LGPD</h2>
                    <p>Este site opera em conformidade com a Lei Geral de Proteção de Dados (LGPD) - Lei nº 13.709/2018. Processamos seus dados pessoais apenas com base em fundamentos legítimos, como seu consentimento ou interesse legítimo.</p>

                    <h2>10. Alterações nesta Política</h2>
                    <p>Podemos atualizar esta Política de Privacidade periodicamente. As alterações entram em vigor quando publicadas no site. O uso continuado do site após as mudanças indica sua aceitação.</p>

                    <h2>11. Contato</h2>
                    <p>Se você tiver perguntas sobre esta Política de Privacidade ou sobre como tratamos suas informações, entre em contato:</p>
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
