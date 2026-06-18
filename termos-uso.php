<?php
$page = 'termos-uso';
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Termos de Uso | ACCOUNT Contabilidade Estratégica</title>
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
                    <h1>Termos de Uso</h1>
                    <p><strong>Última atualização: <?php echo date('d/m/Y'); ?></strong></p>

                    <h2>1. Aceição dos Termos</h2>
                    <p>Ao acessar e utilizar o site ACCOUNT Contabilidade Estratégica (https://accountassessoria.com.br), você concorda em estar vinculado por estes Termos de Uso. Se você não concorda com qualquer parte destes termos, você não deve utilizar este site.</p>

                    <h2>2. Uso Licenciado</h2>
                    <p>A permissão é concedida para visualizar e imprimir o conteúdo deste site temporariamente e apenas para fins informativos pessoais. Você não pode:</p>
                    <ul>
                        <li>Modificar ou copiar o conteúdo</li>
                        <li>Usar o conteúdo para fins comerciais ou públicos</li>
                        <li>Tentar descompilar ou fazer engenharia reversa de qualquer software contido no site</li>
                        <li>Remover quaisquer marcas de copyright ou propriedade</li>
                        <li>Transferir o conteúdo para outro servidor</li>
                        <li>Usar o site para enviar spam, malware ou qualquer código prejudicial</li>
                    </ul>

                    <h2>3. Aviso de Isenção de Responsabilidade</h2>
                    <p>O conteúdo deste site é fornecido "como está". A ACCOUNT Contabilidade Estratégica não oferece garantias, expressas ou implícitas, de que o conteúdo é preciso, completo ou livre de erros. O uso do site é por sua conta e risco.</p>
                    <p>As informações fornecidas no site têm caráter informativo e não substituem a consulta profissional com um contador ou consultor fiscal. Para orientações específicas sobre sua situação, entre em contato conosco diretamente.</p>

                    <h2>4. Limitação de Responsabilidade</h2>
                    <p>Em nenhum caso a ACCOUNT Contabilidade Estratégica será responsável por qualquer dano indireto, incidental, especial ou consequente resultante do seu acesso ou uso do site, incluindo perda de dados, lucros perdidos ou custos de substituição.</p>

                    <h2>5. Links Externos</h2>
                    <p>Este site pode conter links para sites de terceiros. Não controlamos esses sites e não somos responsáveis pelo seu conteúdo. O acesso a links externos é por sua conta e risco.</p>

                    <h2>6. Modificação dos Termos</h2>
                    <p>A ACCOUNT Contabilidade Estratégica reserva o direito de modificar estes Termos de Uso a qualquer momento. As alterações entram em vigor imediatamente após a publicação no site. O uso continuado do site após as mudanças constitui sua aceitação dos novos termos.</p>

                    <h2>7. Lei Aplicável</h2>
                    <p>Estes Termos de Uso são regidos pelas leis da República Federativa do Brasil. Qualquer disputa será resolvida nos tribunais competentes de Belo Horizonte, Minas Gerais.</p>

                    <h2>8. Contato</h2>
                    <p>Se você tiver perguntas sobre estes Termos de Uso, entre em contato conosco através de:</p>
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
