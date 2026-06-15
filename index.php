<?php

declare(strict_types=1);

session_start();
date_default_timezone_set('America/Sao_Paulo');

$configFile = __DIR__ . '/config.php';
if (!file_exists($configFile)) {
    http_response_code(500);
    echo 'Arquivo de configuração não encontrado.';
    exit;
}

$appConfig = require $configFile;

function h(mixed $value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
}


function repositoryPath(string $path): string
{
    return __DIR__ . '/' . ltrim($path, '/');
}

function setFlash(string $message, string $type = 'success'): void
{
    $_SESSION['flash'] = [
        'message' => $message,
        'type' => $type,
    ];
}

function consumeFlash(): ?array
{
    if (!isset($_SESSION['flash'])) {
        return null;
    }

    $flash = $_SESSION['flash'];
    unset($_SESSION['flash']);

    return $flash;
}

function appUrl(string $action = 'dashboard', array $query = []): string
{
    $params = array_merge(['action' => $action], $query);

    return '?' . http_build_query($params);
}

function oldValue(string $key, string $default = ''): string
{
    $value = $_POST[$key] ?? $default;

    return is_string($value) ? $value : $default;
}

function formatMoney(mixed $value): string
{
    if ($value === null || $value === '') {
        return 'R$ 0,00';
    }

    return 'R$ ' . number_format((float) $value, 2, ',', '.');
}

function dbConnection(array $config, ?string &$error = null): ?PDO
{
    if (!isset($config['mysql']) || !is_array($config['mysql'])) {
        $error = 'Configuração MySQL não encontrada em config.php.';
        return null;
    }

    $m = $config['mysql'];
    $host = $m['host'] ?? '127.0.0.1';
    $port = $m['port'] ?? 3306;
    $db = $m['database'] ?? '';
    $user = $m['username'] ?? '';
    $pass = $m['password'] ?? '';
    $charset = $m['charset'] ?? 'utf8mb4';

    $dsn = sprintf('mysql:host=%s;port=%s;dbname=%s;charset=%s', $host, $port, $db, $charset);

    try {
        $pdo = new PDO($dsn, $user, $pass, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
        ]);

        return $pdo;
    } catch (Throwable $exception) {
        $error = 'Falha ao conectar MySQL: ' . $exception->getMessage();
        return null;
    }
}

function fetchAll(PDO $pdo, string $sql, array $params = []): array
{
    $statement = $pdo->prepare($sql);
    $statement->execute($params);

    return $statement->fetchAll();
}

function fetchOne(PDO $pdo, string $sql, array $params = []): ?array
{
    $statement = $pdo->prepare($sql);
    $statement->execute($params);
    $row = $statement->fetch();

    return $row === false ? null : $row;
}

function executeStatement(PDO $pdo, string $sql, array $params = []): int
{
    $statement = $pdo->prepare($sql);
    $statement->execute($params);

    return $statement->rowCount();
}

function callProcedure(PDO $pdo, string $procedure, array $params = []): array
{
    return match ($procedure) {
        'cadastrar_cliente' => (function () use ($pdo, $params): array {
            executeStatement($pdo, 'INSERT INTO clientes (nome, telefone, email) VALUES (:nome, :telefone, :email)', [
                'nome' => $params['nome'] ?? '',
                'telefone' => $params['telefone'] ?? '',
                'email' => $params['email'] ?? '',
            ]);

            return [];
        })(),
        'inserir_pedido' => (function () use ($pdo, $params): array {
            executeStatement($pdo, 'INSERT INTO pedidos (id_cliente, id_mesa, id_garcom, status_pedido, data_pedido) VALUES (:id_cliente, :id_mesa, :id_garcom, :status_pedido, CURRENT_TIMESTAMP)', [
                'id_cliente' => $params['id_cliente'] ?? null,
                'id_mesa' => $params['id_mesa'] ?? null,
                'id_garcom' => $params['id_garcom'] ?? null,
                'status_pedido' => 'ABERTO',
            ]);

            return [];
        })(),
        'atualizar_preco_produto' => (function () use ($pdo, $params): array {
            executeStatement($pdo, 'UPDATE produtos SET preco = :novo_preco WHERE id_produto = :id_produto', [
                'id_produto' => $params['id_produto'] ?? null,
                'novo_preco' => $params['novo_preco'] ?? null,
            ]);

            return [];
        })(),
        'listar_pedidos_cliente' => (function () use ($pdo, $params): array {
            return fetchAll($pdo, '
                SELECT
                    p.id_pedido,
                    c.nome AS cliente,
                    m.numero_mesa AS mesa,
                    g.nome AS garcom,
                    p.status_pedido AS status,
                    p.data_pedido
                FROM pedidos p
                INNER JOIN clientes c ON c.id_cliente = p.id_cliente
                INNER JOIN mesas m ON m.id_mesa = p.id_mesa
                INNER JOIN garcons g ON g.id_garcom = p.id_garcom
                WHERE p.id_cliente = :id_cliente
                ORDER BY p.data_pedido DESC, p.id_pedido DESC
            ', [
                'id_cliente' => $params['id_cliente'] ?? null,
            ]);
        })(),
        default => throw new InvalidArgumentException('Procedure desconhecida: ' . $procedure),
    };
}

function initialiseDatabase(PDO $pdo): void
{
    $pdo->exec('
        CREATE TABLE IF NOT EXISTS clientes (
            id_cliente INTEGER PRIMARY KEY AUTO_INCREMENT,
            nome TEXT NOT NULL,
            telefone TEXT NOT NULL DEFAULT "",
            email TEXT NOT NULL DEFAULT ""
        );

        CREATE TABLE IF NOT EXISTS mesas (
            id_mesa INTEGER PRIMARY KEY AUTO_INCREMENT,
            numero_mesa INTEGER NOT NULL UNIQUE,
            capacidade INTEGER NOT NULL,
            status TEXT NOT NULL DEFAULT "DISPONIVEL"
        );

        CREATE TABLE IF NOT EXISTS garcons (
            id_garcom INTEGER PRIMARY KEY AUTO_INCREMENT,
            nome TEXT NOT NULL,
            telefone TEXT NOT NULL DEFAULT ""
        );

        CREATE TABLE IF NOT EXISTS produtos (
            id_produto INTEGER PRIMARY KEY AUTO_INCREMENT,
            nome TEXT NOT NULL,
            descricao TEXT NOT NULL DEFAULT "",
            preco REAL NOT NULL DEFAULT 0
        );

        CREATE TABLE IF NOT EXISTS pedidos (
            id_pedido INTEGER PRIMARY KEY AUTO_INCREMENT,
            id_cliente INTEGER NOT NULL,
            id_mesa INTEGER NOT NULL,
            id_garcom INTEGER NOT NULL,
            status_pedido TEXT NOT NULL DEFAULT "ABERTO",
            data_pedido TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY (id_cliente) REFERENCES clientes(id_cliente) ON DELETE RESTRICT,
            FOREIGN KEY (id_mesa) REFERENCES mesas(id_mesa) ON DELETE RESTRICT,
            FOREIGN KEY (id_garcom) REFERENCES garcons(id_garcom) ON DELETE RESTRICT
        );

        CREATE TABLE IF NOT EXISTS itens_pedido (
            id_item INTEGER PRIMARY KEY AUTO_INCREMENT,
            id_pedido INTEGER NOT NULL,
            id_produto INTEGER NOT NULL,
            quantidade INTEGER NOT NULL,
            preco_unitario REAL NOT NULL,
            FOREIGN KEY (id_pedido) REFERENCES pedidos(id_pedido) ON DELETE CASCADE,
            FOREIGN KEY (id_produto) REFERENCES produtos(id_produto) ON DELETE RESTRICT
        );
    ');

    $count = (int) $pdo->query('SELECT COUNT(*) FROM clientes')->fetchColumn();
    if ($count > 0) {
        return;
    }

    $pdo->beginTransaction();
    try {
        $pdo->exec("INSERT INTO clientes (nome, telefone, email) VALUES
            ('Ana Lima', '(11) 98888-7777', 'ana@exemplo.com'),
            ('João Pedro', '(21) 97777-6666', 'joao@exemplo.com'),
            ('Marina Souza', '(31) 96666-5555', 'marina@exemplo.com')");

        $pdo->exec("INSERT INTO mesas (numero_mesa, capacidade, status) VALUES
            (1, 2, 'DISPONIVEL'),
            (2, 4, 'DISPONIVEL'),
            (3, 6, 'OCUPADA'),
            (4, 4, 'DISPONIVEL')");

        $pdo->exec("INSERT INTO garcons (nome, telefone) VALUES
            ('Carlos Mendes', '(11) 90000-1111'),
            ('Fernanda Alves', '(11) 90000-2222'),
            ('Paulo Costa', '(11) 90000-3333')");

        $pdo->exec("INSERT INTO produtos (nome, descricao, preco) VALUES
            ('Filé à parmegiana', 'Acompanha arroz e fritas', 42.90),
            ('Pizza Margherita', 'Molho, mussarela e manjericão', 39.90),
            ('Hambúrguer artesanal', 'Pão brioche, carne e cheddar', 28.50),
            ('Salada Caesar', 'Alface, croutons e molho caesar', 24.00),
            ('Refrigerante', 'Lata 350ml', 6.50),
            ('Brownie com sorvete', 'Sobremesa da casa', 18.00)");

        $pdo->exec("INSERT INTO pedidos (id_cliente, id_mesa, id_garcom, status_pedido, data_pedido) VALUES
            (1, 1, 1, 'PAGO', NOW() - INTERVAL 2 DAY),
            (2, 3, 2, 'ABERTO', NOW() - INTERVAL 1 DAY)");

        $pdo->exec("INSERT INTO itens_pedido (id_pedido, id_produto, quantidade, preco_unitario) VALUES
            (1, 1, 1, 42.90),
            (1, 5, 2, 6.50),
            (2, 2, 1, 39.90),
            (2, 6, 2, 18.00)");

        $pdo->commit();
    } catch (Throwable $exception) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }

        throw $exception;
    }
}

function renderPageStart(string $title): void
{
    echo '<!doctype html>';
    echo '<html lang="pt-BR"><head><meta charset="UTF-8">';
    echo '<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">';
    echo '<title>' . h($title) . '</title>';
    echo '<link rel="preconnect" href="https://fonts.googleapis.com">';
    echo '<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>';
    echo '<link href="https://fonts.googleapis.com/css2?family=Inter:opsz,wght@14..32,300;14..32,400;14..32,500;14..32,600;14..32,700&display=swap" rel="stylesheet">';
    echo '<style>';
    echo ':root {
        --bg-primary: #0f172a;
        --bg-secondary: #1e293b;
        --bg-tertiary: #334155;
        --surface: rgba(30, 41, 59, 0.95);
        --surface-light: rgba(51, 65, 85, 0.95);
        --text-primary: #f1f5f9;
        --text-secondary: #cbd5e1;
        --text-muted: #94a3b8;
        --accent: #f59e0b;
        --accent-dark: #d97706;
        --success: #10b981;
        --danger: #ef4444;
        --warning: #f59e0b;
        --info: #3b82f6;
        --border: #334155;
        --shadow-sm: 0 1px 2px 0 rgb(0 0 0 / 0.05);
        --shadow-md: 0 4px 6px -1px rgb(0 0 0 / 0.1);
        --shadow-lg: 0 10px 15px -3px rgb(0 0 0 / 0.1);
        --shadow-xl: 0 20px 25px -5px rgb(0 0 0 / 0.1);
        --radius-sm: 0.5rem;
        --radius-md: 0.75rem;
        --radius-lg: 1rem;
        --radius-xl: 1.5rem;
        --sidebar-width: 280px;
        --header-height: 70px;
        --transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
    }
    
    * {
        margin: 0;
        padding: 0;
        box-sizing: border-box;
    }
    
    body {
        font-family: "Inter", sans-serif;
        background: linear-gradient(135deg, #0f172a 0%, #1e1b4b 50%, #0f172a 100%);
        color: var(--text-primary);
        line-height: 1.5;
        overflow-x: hidden;
    }
    
    /* Layout */
    .app {
        display: flex;
        min-height: 100vh;
    }
    
    /* Sidebar */
    .sidebar {
        position: fixed;
        left: 0;
        top: 0;
        width: var(--sidebar-width);
        height: 100vh;
        background: rgba(15, 23, 42, 0.95);
        backdrop-filter: blur(20px);
        border-right: 1px solid rgba(51, 65, 85, 0.5);
        z-index: 100;
        transform: translateX(0);
        transition: var(--transition);
        overflow-y: auto;
    }
    
    .sidebar.closed {
        transform: translateX(-100%);
    }
    
    .sidebar-header {
        padding: 1.5rem;
        border-bottom: 1px solid var(--border);
        margin-bottom: 1.5rem;
    }
    
    .logo {
        display: flex;
        align-items: center;
        gap: 0.75rem;
        font-size: 1.5rem;
        font-weight: 700;
        background: linear-gradient(135deg, #f59e0b, #f97316);
        -webkit-background-clip: text;
        background-clip: text;
        color: transparent;
    }
    
    .logo-icon {
        font-size: 2rem;
    }
    
    .sidebar-nav {
        list-style: none;
        padding: 0 0.75rem;
    }
    
    .sidebar-nav-item {
        margin-bottom: 0.25rem;
    }
    
    .sidebar-nav-link {
        display: flex;
        align-items: center;
        gap: 0.75rem;
        padding: 0.75rem 1rem;
        border-radius: var(--radius-md);
        color: var(--text-secondary);
        transition: var(--transition);
        text-decoration: none;
    }
    
    .sidebar-nav-link:hover {
        background: rgba(245, 158, 11, 0.1);
        color: var(--accent);
        transform: translateX(4px);
    }
    
    .sidebar-nav-link.active {
        background: linear-gradient(135deg, rgba(245, 158, 11, 0.15), rgba(245, 158, 11, 0.05));
        color: var(--accent);
        font-weight: 600;
    }
    
    .sidebar-nav-icon {
        font-size: 1.25rem;
        width: 1.5rem;
    }
    
    /* Main Content */
    .main-content {
        flex: 1;
        margin-left: var(--sidebar-width);
        transition: var(--transition);
        min-height: 100vh;
    }
    
    .main-content.sidebar-closed {
        margin-left: 0;
    }
    
    /* Header */
    .top-bar {
        background: rgba(15, 23, 42, 0.8);
        backdrop-filter: blur(10px);
        border-bottom: 1px solid var(--border);
        padding: 1rem 2rem;
        position: sticky;
        top: 0;
        z-index: 90;
        display: flex;
        justify-content: space-between;
        align-items: center;
    }
    
    .menu-toggle {
        display: none;
        background: var(--surface);
        border: 1px solid var(--border);
        border-radius: var(--radius-md);
        padding: 0.5rem;
        cursor: pointer;
        color: var(--text-primary);
        transition: var(--transition);
    }
    
    .menu-toggle:hover {
        background: var(--accent);
        border-color: var(--accent);
    }
    
    .page-title {
        font-size: 1.5rem;
        font-weight: 600;
    }
    
    .user-info {
        display: flex;
        align-items: center;
        gap: 1rem;
    }
    
    /* Content Wrapper */
    .content-wrapper {
        padding: 2rem;
        max-width: 1400px;
        margin: 0 auto;
    }
    
    /* Cards */
    .card {
        background: var(--surface);
        backdrop-filter: blur(10px);
        border: 1px solid rgba(51, 65, 85, 0.5);
        border-radius: var(--radius-lg);
        padding: 1.5rem;
        transition: var(--transition);
    }
    
    .card:hover {
        transform: translateY(-4px);
        box-shadow: var(--shadow-xl);
        border-color: rgba(245, 158, 11, 0.3);
    }
    
    .card-header {
        margin-bottom: 1rem;
        display: flex;
        justify-content: space-between;
        align-items: center;
    }
    
    .card-title {
        font-size: 1.25rem;
        font-weight: 600;
        color: var(--text-primary);
    }
    
    /* Grid */
    .grid {
        display: grid;
        grid-template-columns: repeat(auto-fit, minmax(320px, 1fr));
        gap: 1.5rem;
        margin-bottom: 1.5rem;
    }
    
    /* Forms */
    .form-group {
        margin-bottom: 1rem;
    }
    
    .form-label {
        display: block;
        margin-bottom: 0.5rem;
        font-weight: 500;
        color: var(--text-secondary);
        font-size: 0.875rem;
    }
    
    .form-control {
        width: 100%;
        padding: 0.625rem 0.875rem;
        background: rgba(15, 23, 42, 0.8);
        border: 1px solid var(--border);
        border-radius: var(--radius-md);
        color: var(--text-primary);
        font-size: 0.875rem;
        transition: var(--transition);
    }
    
    .form-control:focus {
        outline: none;
        border-color: var(--accent);
        box-shadow: 0 0 0 3px rgba(245, 158, 11, 0.1);
    }
    
    /* Buttons */
    .btn {
        display: inline-flex;
        align-items: center;
        gap: 0.5rem;
        padding: 0.625rem 1.25rem;
        border: none;
        border-radius: var(--radius-md);
        font-weight: 500;
        font-size: 0.875rem;
        cursor: pointer;
        transition: var(--transition);
        text-decoration: none;
    }
    
    .btn-primary {
        background: linear-gradient(135deg, var(--accent), var(--accent-dark));
        color: #0f172a;
    }
    
    .btn-primary:hover {
        transform: translateY(-2px);
        box-shadow: var(--shadow-lg);
    }
    
    .btn-secondary {
        background: var(--surface-light);
        color: var(--text-primary);
        border: 1px solid var(--border);
    }
    
    .btn-secondary:hover {
        background: var(--bg-tertiary);
        transform: translateY(-2px);
    }
    
    .btn-danger {
        background: linear-gradient(135deg, var(--danger), #dc2626);
        color: white;
    }
    
    .btn-danger:hover {
        transform: translateY(-2px);
        box-shadow: var(--shadow-lg);
    }
    
    /* Tables */
    .table-container {
        overflow-x: auto;
        border-radius: var(--radius-lg);
        border: 1px solid var(--border);
    }
    
    .table {
        width: 100%;
        border-collapse: collapse;
    }
    
    .table th {
        padding: 0.75rem 1rem;
        text-align: left;
        background: var(--surface-light);
        color: var(--text-secondary);
        font-weight: 600;
        font-size: 0.75rem;
        text-transform: uppercase;
        letter-spacing: 0.05em;
    }
    
    .table td {
        padding: 0.75rem 1rem;
        border-top: 1px solid var(--border);
        color: var(--text-primary);
    }
    
    .table tbody tr:hover {
        background: rgba(245, 158, 11, 0.05);
        transition: var(--transition);
    }
    
    /* Flash Messages */
    .flash {
        padding: 1rem;
        border-radius: var(--radius-md);
        margin-bottom: 1.5rem;
        animation: slideDown 0.3s ease-out;
    }
    
    .flash-success {
        background: rgba(16, 185, 129, 0.1);
        border: 1px solid rgba(16, 185, 129, 0.3);
        color: #10b981;
    }
    
    .flash-error {
        background: rgba(239, 68, 68, 0.1);
        border: 1px solid rgba(239, 68, 68, 0.3);
        color: #ef4444;
    }
    
    /* Badges */
    .badge {
        display: inline-flex;
        align-items: center;
        padding: 0.25rem 0.75rem;
        border-radius: var(--radius-sm);
        font-size: 0.75rem;
        font-weight: 600;
    }
    
    .badge-success {
        background: rgba(16, 185, 129, 0.2);
        color: #10b981;
    }
    
    .badge-warning {
        background: rgba(245, 158, 11, 0.2);
        color: #f59e0b;
    }
    
    .badge-danger {
        background: rgba(239, 68, 68, 0.2);
        color: #ef4444;
    }
    
    /* Animations */
    @keyframes slideDown {
        from {
            opacity: 0;
            transform: translateY(-20px);
        }
        to {
            opacity: 1;
            transform: translateY(0);
        }
    }
    
    @keyframes fadeIn {
        from {
            opacity: 0;
        }
        to {
            opacity: 1;
        }
    }
    
    /* Responsive */
    @media (max-width: 768px) {
        .sidebar {
            transform: translateX(-100%);
        }
        
        .sidebar.open {
            transform: translateX(0);
        }
        
        .main-content {
            margin-left: 0;
        }
        
        .menu-toggle {
            display: inline-flex;
        }
        
        .content-wrapper {
            padding: 1rem;
        }
        
        .grid {
            grid-template-columns: 1fr;
        }
        
        .top-bar {
            padding: 1rem;
        }
        
        .page-title {
            font-size: 1.25rem;
        }
    }
    
    /* Loading States */
    .btn-loading {
        position: relative;
        pointer-events: none;
        opacity: 0.7;
    }
    
    .btn-loading::after {
        content: "";
        position: absolute;
        width: 1rem;
        height: 1rem;
        border: 2px solid transparent;
        border-top-color: currentColor;
        border-radius: 50%;
        animation: spin 0.6s linear infinite;
        margin-left: 0.5rem;
    }
    
    @keyframes spin {
        to {
            transform: rotate(360deg);
        }
    }
    
    /* Scrollbar */
    ::-webkit-scrollbar {
        width: 8px;
        height: 8px;
    }
    
    ::-webkit-scrollbar-track {
        background: var(--bg-secondary);
    }
    
    ::-webkit-scrollbar-thumb {
        background: var(--border);
        border-radius: 4px;
    }
    
    ::-webkit-scrollbar-thumb:hover {
        background: var(--accent);
    }
    </style>';
    echo '<script>';
    echo 'function toggleSidebar() {
        const sidebar = document.getElementById("sidebar");
        const mainContent = document.getElementById("mainContent");
        sidebar.classList.toggle("open");
        sidebar.classList.toggle("closed");
        mainContent.classList.toggle("sidebar-closed");
    }';
    
    echo 'document.addEventListener("DOMContentLoaded", function() {
        // Add loading effect to forms
        document.querySelectorAll("form").forEach(form => {
            form.addEventListener("submit", function(e) {
                const submitBtn = form.querySelector("button[type=\'submit\']");
                if(submitBtn) {
                    submitBtn.classList.add("btn-loading");
                    submitBtn.disabled = true;
                }
            });
        });
        
        // Auto-hide flash messages after 5 seconds
        setTimeout(() => {
            document.querySelectorAll(".flash").forEach(flash => {
                flash.style.animation = "fadeOut 0.3s ease-out forwards";
            });
        }, 5000);
    });';
    
    echo 'const style = document.createElement("style");
    style.textContent = `
        @keyframes fadeOut {
            to {
                opacity: 0;
                transform: translateY(-10px);
                display: none;
            }
        }
    `;
    document.head.appendChild(style);';
    echo '</script>';
    echo '</head><body>';
    echo '<div class="app">';
}

function renderSidebar(string $currentAction): void
{
    $menuItems = [
        'dashboard' => ['label' => 'Dashboard', 'icon' => '🏠', 'color' => '#f59e0b'],
        'customers' => ['label' => 'Clientes', 'icon' => '👥', 'color' => '#3b82f6'],
        'orders' => ['label' => 'Pedidos', 'icon' => '📝', 'color' => '#10b981'],
        'menu' => ['label' => 'Cardápio', 'icon' => '🍽️', 'color' => '#8b5cf6'],
        'reports' => ['label' => 'Relatórios', 'icon' => '📊', 'color' => '#ef4444'],
    ];
    
    echo '<aside class="sidebar" id="sidebar">';
    echo '<div class="sidebar-header">';
    echo '<div class="logo">';
    echo '<span class="logo-icon">🍽️</span>';
    echo '<span>Gestão Restaurante</span>';
    echo '</div>';
    echo '<p style="font-size: 0.75rem; color: var(--text-muted); margin-top: 0.5rem;">Sistema de gerenciamento</p>';
    echo '</div>';
    echo '<ul class="sidebar-nav">';
    
    foreach ($menuItems as $action => $item) {
        $activeClass = $action === $currentAction ? 'active' : '';
        echo '<li class="sidebar-nav-item">';
        echo '<a href="' . h(appUrl($action)) . '" class="sidebar-nav-link ' . $activeClass . '">';
        echo '<span class="sidebar-nav-icon">' . $item['icon'] . '</span>';
        echo '<span>' . $item['label'] . '</span>';
        echo '</a>';
        echo '</li>';
    }
    
    echo '</ul>';
    echo '</aside>';
}

function renderTopBar(string $title): void
{
    echo '<div class="top-bar">';
    echo '<button class="menu-toggle" onclick="toggleSidebar()">☰</button>';
    echo '<h1 class="page-title">' . h($title) . '</h1>';
    echo '<div class="user-info">';
    echo '<span class="badge badge-success">Online</span>';
    echo '</div>';
    echo '</div>';
}

function renderPageEnd(): void
{
    echo '</div>'; // close app
    echo '</body></html>';
}

function renderFlash(?array $flash): void
{
    if ($flash === null) {
        return;
    }

    $type = $flash['type'] === 'error' ? 'flash-error' : 'flash-success';
    echo '<div class="flash ' . $type . '">';
    echo '📢 ' . h($flash['message']);
    echo '</div>';
}

function renderTable(array $rows, array $headers = []): void
{
    if (empty($rows)) {
        echo '<div class="card">';
        echo '<p style="text-align: center; color: var(--text-muted); padding: 2rem;">📭 Nenhum registro encontrado</p>';
        echo '</div>';
        return;
    }

    if (empty($headers)) {
        $headers = array_keys($rows[0]);
    }

    echo '<div class="table-container">';
    echo '<table class="table">';
    echo '<thead><tr>';
    foreach ($headers as $header) {
        echo '<th>' . h(ucfirst(str_replace('_', ' ', $header))) . '</th>';
    }
    echo '</tr></thead><tbody>';
    foreach ($rows as $row) {
        echo '<tr>';
        foreach ($headers as $header) {
            $value = $row[$header] ?? '';
            if (is_float($value) || is_int($value)) {
                $value = (string) $value;
            }
            echo '<td>' . h($value) . '</td>';
        }
        echo '</tr>';
    }
    echo '</tbody></table>';
    echo '</div>';
}

function renderSectionTitle(string $title, string $subtitle = ''): void
{
    echo '<div style="margin-bottom: 1.5rem;">';
    echo '<h2 style="font-size: 1.5rem; font-weight: 600; margin-bottom: 0.5rem;">' . h($title) . '</h2>';
    if ($subtitle !== '') {
        echo '<p style="color: var(--text-muted);">' . h($subtitle) . '</p>';
    }
    echo '</div>';
}

function renderDashboard(?PDO $pdo, ?string $dbError): void
{
    renderSectionTitle('Dashboard', 'Visão geral do sistema');
    
    if ($dbError !== null) {
        echo '<div class="flash flash-error">⚠️ ' . h($dbError) . '</div>';
        return;
    }
    
    // Stats cards
    echo '<div class="grid">';
    
    // Total customers
    $totalCustomers = $pdo ? (int) $pdo->query('SELECT COUNT(*) FROM clientes')->fetchColumn() : 0;
    echo '<div class="card">';
    echo '<div class="card-header">';
    echo '<div class="card-title">Clientes</div>';
    echo '<span style="font-size: 2rem;">👥</span>';
    echo '</div>';
    echo '<p style="font-size: 2rem; font-weight: 700; margin: 0;">' . $totalCustomers . '</p>';
    echo '<p style="color: var(--text-muted); font-size: 0.875rem;">clientes cadastrados</p>';
    echo '</div>';
    
    // Total orders
    $totalOrders = $pdo ? (int) $pdo->query('SELECT COUNT(*) FROM pedidos')->fetchColumn() : 0;
    echo '<div class="card">';
    echo '<div class="card-header">';
    echo '<div class="card-title">Pedidos</div>';
    echo '<span style="font-size: 2rem;">📝</span>';
    echo '</div>';
    echo '<p style="font-size: 2rem; font-weight: 700; margin: 0;">' . $totalOrders . '</p>';
    echo '<p style="color: var(--text-muted); font-size: 0.875rem;">pedidos realizados</p>';
    echo '</div>';
    
    // Total products
    $totalProducts = $pdo ? (int) $pdo->query('SELECT COUNT(*) FROM produtos')->fetchColumn() : 0;
    echo '<div class="card">';
    echo '<div class="card-header">';
    echo '<div class="card-title">Cardápio</div>';
    echo '<span style="font-size: 2rem;">🍽️</span>';
    echo '</div>';
    echo '<p style="font-size: 2rem; font-weight: 700; margin: 0;">' . $totalProducts . '</p>';
    echo '<p style="color: var(--text-muted); font-size: 0.875rem;">produtos disponíveis</p>';
    echo '</div>';
    
    echo '</div>';
    
    // Quick actions
    echo '<div class="card" style="margin-top: 1.5rem;">';
    echo '<div class="card-header">';
    echo '<div class="card-title">⚡ Ações rápidas</div>';
    echo '</div>';
    echo '<div style="display: flex; gap: 1rem; flex-wrap: wrap;">';
    echo '<a href="' . h(appUrl('customers')) . '" class="btn btn-primary">➕ Novo Cliente</a>';
    echo '<a href="' . h(appUrl('orders')) . '" class="btn btn-primary">📝 Novo Pedido</a>';
    echo '<a href="' . h(appUrl('menu')) . '" class="btn btn-primary">🍽️ Adicionar Prato</a>';
    echo '</div>';
    echo '</div>';
}

function renderCustomersPage(PDO $pdo, ?string $dbError, ?array $flash): void
{
    renderSectionTitle('Clientes', 'Gerencie seus clientes cadastrados');
    
    echo '<div class="grid">';
    
    // Create customer form
    echo '<div class="card">';
    echo '<div class="card-header">';
    echo '<div class="card-title">➕ Cadastrar cliente</div>';
    echo '</div>';
    echo '<form method="post" action="' . h(appUrl('customers')) . '">';
    echo '<input type="hidden" name="task" value="create">';
    echo '<div class="form-group">';
    echo '<label class="form-label">Nome completo *</label>';
    echo '<input type="text" name="nome" class="form-control" required placeholder="Ex: João Silva">';
    echo '</div>';
    echo '<div class="form-group">';
    echo '<label class="form-label">Telefone</label>';
    echo '<input type="tel" name="telefone" class="form-control" placeholder="(11) 99999-9999">';
    echo '</div>';
    echo '<div class="form-group">';
    echo '<label class="form-label">E-mail</label>';
    echo '<input type="email" name="email" class="form-control" placeholder="cliente@exemplo.com">';
    echo '</div>';
    echo '<button type="submit" class="btn btn-primary">💾 Cadastrar cliente</button>';
    echo '</form>';
    echo '</div>';
    
    // Search form
    echo '<div class="card">';
    echo '<div class="card-header">';
    echo '<div class="card-title">🔍 Buscar cliente</div>';
    echo '</div>';
    echo '<form method="post" action="' . h(appUrl('customers')) . '">';
    echo '<input type="hidden" name="task" value="search">';
    echo '<div class="form-group">';
    echo '<label class="form-label">Nome ou parte do nome</label>';
    echo '<input type="text" name="search_term" class="form-control" placeholder="Digite o nome...">';
    echo '</div>';
    echo '<button type="submit" class="btn btn-primary">🔎 Buscar</button>';
    echo '</form>';
    echo '</div>';
    
    echo '</div>';
    
    if ($dbError !== null) {
        echo '<div class="flash flash-error">⚠️ ' . h($dbError) . '</div>';
        return;
    }
    
    // Customer list
    echo '<div class="card" style="margin-top: 1.5rem;">';
    echo '<div class="card-header">';
    echo '<div class="card-title">📋 Lista de clientes</div>';
    echo '</div>';
    renderTable(listCustomers($pdo), ['id_cliente', 'nome', 'telefone', 'email']);
    echo '</div>';
    
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['task'] ?? '') === 'search') {
        $results = searchCustomers($pdo);
        echo '<div class="card" style="margin-top: 1.5rem;">';
        echo '<div class="card-header">';
        echo '<div class="card-title">🔍 Resultado da busca</div>';
        echo '</div>';
        renderTable($results, ['id_cliente', 'nome', 'telefone', 'email']);
        echo '</div>';
    }
}

function renderOrdersPage(PDO $pdo, ?string $dbError, ?array $flash): void
{
    renderSectionTitle('Pedidos', 'Gerencie os pedidos do restaurante');
    
    if ($dbError !== null) {
        echo '<div class="flash flash-error">⚠️ ' . h($dbError) . '</div>';
        return;
    }
    
    echo '<div class="grid">';
    
    // Create order form
    echo '<div class="card">';
    echo '<div class="card-header">';
    echo '<div class="card-title">📝 Criar novo pedido</div>';
    echo '</div>';
    echo '<form method="post" action="' . h(appUrl('orders')) . '">';
    echo '<input type="hidden" name="task" value="create">';
    echo '<div class="form-group">';
    echo '<label class="form-label">Cliente</label>';
    echo '<select name="cliente_id" class="form-control" required>';
    echo '<option value="">Selecione um cliente</option>';
    foreach (listCustomers($pdo) as $customer) {
        echo '<option value="' . h($customer['id_cliente']) . '">' . h($customer['nome']) . '</option>';
    }
    echo '</select>';
    echo '</div>';
    echo '<div class="form-group">';
    echo '<label class="form-label">Mesa</label>';
    echo '<select name="mesa_id" class="form-control" required>';
    echo '<option value="">Selecione uma mesa</option>';
    foreach (listTables($pdo) as $table) {
        echo '<option value="' . h($table['id_mesa']) . '">Mesa ' . h($table['numero_mesa']) . ' - Capacidade: ' . h($table['capacidade']) . '</option>';
    }
    echo '</select>';
    echo '</div>';
    echo '<div class="form-group">';
    echo '<label class="form-label">Garçom</label>';
    echo '<select name="garcom_id" class="form-control" required>';
    echo '<option value="">Selecione um garçom</option>';
    foreach (listWaiters($pdo) as $waiter) {
        echo '<option value="' . h($waiter['id_garcom']) . '">' . h($waiter['nome']) . '</option>';
    }
    echo '</select>';
    echo '</div>';
    echo '<button type="submit" class="btn btn-primary">✅ Criar pedido</button>';
    echo '</form>';
    echo '</div>';
    
    // Add item form
    echo '<div class="card">';
    echo '<div class="card-header">';
    echo '<div class="card-title">➕ Adicionar item ao pedido</div>';
    echo '</div>';
    echo '<form method="post" action="' . h(appUrl('orders')) . '">';
    echo '<input type="hidden" name="task" value="add_item">';
    echo '<div class="form-group">';
    echo '<label class="form-label">ID do pedido</label>';
    echo '<input type="number" name="order_id" class="form-control" value="' . h($_GET['order_id'] ?? '') . '" required placeholder="Ex: 1">';
    echo '</div>';
    echo '<div class="form-group">';
    echo '<label class="form-label">Produto</label>';
    echo '<select name="product_id" class="form-control" required>';
    echo '<option value="">Selecione um produto</option>';
    foreach (listProducts($pdo) as $product) {
        echo '<option value="' . h($product['id_produto']) . '">' . h($product['nome']) . ' - ' . h(formatMoney($product['preco'])) . '</option>';
    }
    echo '</select>';
    echo '</div>';
    echo '<div class="form-group">';
    echo '<label class="form-label">Quantidade</label>';
    echo '<input type="number" name="quantity" class="form-control" value="1" min="1" required>';
    echo '</div>';
    echo '<button type="submit" class="btn btn-primary">➕ Adicionar item</button>';
    echo '</form>';
    echo '</div>';
    
    echo '</div>';
    
    // Update status form
    echo '<div class="grid" style="margin-top: 1rem;">';
    echo '<div class="card">';
    echo '<div class="card-header">';
    echo '<div class="card-title">🔄 Atualizar status do pedido</div>';
    echo '</div>';
    echo '<form method="post" action="' . h(appUrl('orders')) . '">';
    echo '<input type="hidden" name="task" value="status">';
    echo '<div class="form-group">';
    echo '<label class="form-label">ID do pedido</label>';
    echo '<input type="number" name="status_order_id" class="form-control" value="' . h($_GET['order_id'] ?? '') . '" required>';
    echo '</div>';
    echo '<div class="form-group">';
    echo '<label class="form-label">Novo status</label>';
    echo '<select name="new_status" class="form-control" required>';
    echo '<option value="ABERTO">🟡 ABERTO</option>';
    echo '<option value="PAGO">✅ PAGO</option>';
    echo '<option value="CANCELADO">❌ CANCELADO</option>';
    echo '</select>';
    echo '</div>';
    echo '<button type="submit" class="btn btn-primary">🔄 Atualizar status</button>';
    echo '</form>';
    echo '</div>';
    
    // Search orders
    echo '<div class="card">';
    echo '<div class="card-header">';
    echo '<div class="card-title">🔍 Consultar itens do pedido</div>';
    echo '</div>';
    echo '<form method="get" action="">';
    echo '<input type="hidden" name="action" value="orders">';
    echo '<div class="form-group">';
    echo '<label class="form-label">ID do pedido</label>';
    echo '<input type="number" name="order_id" class="form-control" value="' . h($_GET['order_id'] ?? '') . '" placeholder="Digite o ID">';
    echo '</div>';
    echo '<button type="submit" class="btn btn-primary">🔎 Visualizar itens</button>';
    echo '</form>';
    echo '</div>';
    echo '</div>';
    
    // Orders list
    echo '<div class="card" style="margin-top: 1.5rem;">';
    echo '<div class="card-header">';
    echo '<div class="card-title">📋 Lista de pedidos</div>';
    echo '</div>';
    renderTable(listOrders($pdo), ['id_pedido', 'cliente', 'mesa', 'garcom', 'status', 'data_pedido']);
    echo '</div>';
    
    if (isset($_GET['order_id']) && parseInt((string) $_GET['order_id']) !== null) {
        $orderId = (int) $_GET['order_id'];
        echo '<div class="card" style="margin-top: 1.5rem;">';
        echo '<div class="card-header">';
        echo '<div class="card-title">📋 Itens do pedido #' . h($orderId) . '</div>';
        echo '</div>';
        renderTable(listOrderItems($pdo, $orderId), ['id_item', 'prato', 'quantidade', 'preco_unitario', 'subtotal']);
        echo '</div>';
    }
    
    if (($_GET['view'] ?? '') === 'classification') {
        echo '<div class="card" style="margin-top: 1.5rem;">';
        echo '<div class="card-header">';
        echo '<div class="card-title">📊 Classificação dos pedidos</div>';
        echo '</div>';
        renderTable(classifyOrders($pdo), ['id_pedido', 'cliente', 'total_itens', 'classificacao']);
        echo '</div>';
    }
}

function renderMenuPage(PDO $pdo, ?string $dbError, ?array $flash): void
{
    renderSectionTitle('Cardápio', 'Gerencie os produtos do restaurante');
    
    echo '<div class="grid">';
    
    // Create product form
    echo '<div class="card">';
    echo '<div class="card-header">';
    echo '<div class="card-title">➕ Cadastrar novo prato</div>';
    echo '</div>';
    echo '<form method="post" action="' . h(appUrl('menu')) . '">';
    echo '<input type="hidden" name="task" value="create">';
    echo '<div class="form-group">';
    echo '<label class="form-label">Nome do prato *</label>';
    echo '<input type="text" name="dish_name" class="form-control" required placeholder="Ex: Filé à parmegiana">';
    echo '</div>';
    echo '<div class="form-group">';
    echo '<label class="form-label">Descrição</label>';
    echo '<textarea name="dish_description" class="form-control" rows="3" placeholder="Descreva o prato..."></textarea>';
    echo '</div>';
    echo '<div class="form-group">';
    echo '<label class="form-label">Preço *</label>';
    echo '<input type="number" step="0.01" name="dish_price" class="form-control" required placeholder="Ex: 42.90">';
    echo '</div>';
    echo '<button type="submit" class="btn btn-primary">💾 Cadastrar prato</button>';
    echo '</form>';
    echo '</div>';
    
    // Update price form
    echo '<div class="card">';
    echo '<div class="card-header">';
    echo '<div class="card-title">💰 Atualizar preço</div>';
    echo '</div>';
    echo '<form method="post" action="' . h(appUrl('menu')) . '">';
    echo '<input type="hidden" name="task" value="price">';
    echo '<div class="form-group">';
    echo '<label class="form-label">Selecione o prato</label>';
    echo '<select name="dish_id" class="form-control" required>';
    echo '<option value="">Selecione um prato</option>';
    foreach (listProducts($pdo) as $product) {
        echo '<option value="' . h($product['id_produto']) . '">' . h($product['nome']) . '</option>';
    }
    echo '</select>';
    echo '</div>';
    echo '<div class="form-group">';
    echo '<label class="form-label">Novo preço</label>';
    echo '<input type="number" step="0.01" name="new_price" class="form-control" required placeholder="Ex: 49.90">';
    echo '</div>';
    echo '<button type="submit" class="btn btn-primary">💰 Atualizar preço</button>';
    echo '</form>';
    echo '</div>';
    
    echo '</div>';
    
    if ($dbError !== null) {
        echo '<div class="flash flash-error">⚠️ ' . h($dbError) . '</div>';
        return;
    }
    
    // Products list
    echo '<div class="card" style="margin-top: 1.5rem;">';
    echo '<div class="card-header">';
    echo '<div class="card-title">📋 Cardápio completo</div>';
    echo '</div>';
    renderTable(listProducts($pdo), ['id_produto', 'nome', 'descricao', 'preco']);
    echo '</div>';
}

function renderReportsPage(PDO $pdo, ?string $dbError, ?array $flash): void
{
    renderSectionTitle('Relatórios', 'Análise de dados do restaurante');
    
    if ($dbError !== null) {
        echo '<div class="flash flash-error">⚠️ ' . h($dbError) . '</div>';
        return;
    }
    
    echo '<div class="grid">';
    
    // Sales by period
    echo '<div class="card">';
    echo '<div class="card-header">';
    echo '<div class="card-title">📊 Vendas por período</div>';
    echo '</div>';
    echo '<form method="post" action="' . h(appUrl('reports')) . '">';
    echo '<input type="hidden" name="task" value="sales">';
    echo '<div class="form-group">';
    echo '<label class="form-label">Data inicial</label>';
    echo '<input type="date" name="start_date" class="form-control" required>';
    echo '</div>';
    echo '<div class="form-group">';
    echo '<label class="form-label">Data final</label>';
    echo '<input type="date" name="end_date" class="form-control" required>';
    echo '</div>';
    echo '<button type="submit" class="btn btn-primary">📊 Gerar relatório</button>';
    echo '</form>';
    echo '</div>';
    
    // Other reports
    echo '<div class="card">';
    echo '<div class="card-header">';
    echo '<div class="card-title">📈 Relatórios rápidos</div>';
    echo '</div>';
    echo '<div style="display: flex; flex-direction: column; gap: 0.75rem;">';
    echo '<a href="' . h(appUrl('reports', ['report' => 'waiters'])) . '" class="btn btn-secondary">👨‍🍳 Ranking de garçons</a>';
    echo '<a href="' . h(appUrl('reports', ['report' => 'best_dishes'])) . '" class="btn btn-secondary">🏆 Pratos mais vendidos</a>';
    echo '<a href="' . h(appUrl('reports', ['report' => 'revenue_tables'])) . '" class="btn btn-secondary">💰 Faturamento por mesa</a>';
    echo '</div>';
    echo '</div>';
    
    echo '</div>';
    
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['task'] ?? '') === 'sales') {
        $rows = salesByPeriod($pdo);
        echo '<div class="card" style="margin-top: 1.5rem;">';
        echo '<div class="card-header">';
        echo '<div class="card-title">📊 Resultado das vendas</div>';
        echo '</div>';
        renderTable($rows, ['dia', 'total_vendas']);
        echo '</div>';
    }
    
    $report = $_GET['report'] ?? '';
    if ($report === 'waiters') {
        echo '<div class="card" style="margin-top: 1.5rem;">';
        echo '<div class="card-header">';
        echo '<div class="card-title">👨‍🍳 Ranking de garçons</div>';
        echo '</div>';
        renderTable(waiterRanking($pdo), ['id_garcom', 'nome', 'total_pedidos']);
        echo '</div>';
    } elseif ($report === 'best_dishes') {
        echo '<div class="card" style="margin-top: 1.5rem;">';
        echo '<div class="card-header">';
        echo '<div class="card-title">🏆 Pratos mais vendidos</div>';
        echo '</div>';
        renderTable(bestSellingDishes($pdo), ['id_produto', 'nome', 'quantidade_vendida', 'faturamento']);
        echo '</div>';
    } elseif ($report === 'revenue_tables') {
        echo '<div class="card" style="margin-top: 1.5rem;">';
        echo '<div class="card-header">';
        echo '<div class="card-title">💰 Faturamento por mesa</div>';
        echo '</div>';
        renderTable(revenueByTable($pdo), ['id_mesa', 'numero_mesa', 'faturamento']);
        echo '</div>';
    }
}

function parseInt(string $value): ?int
{
    return filter_var($value, FILTER_VALIDATE_INT) !== false ? (int) $value : null;
}

function parseFloatValue(string $value): ?float
{
    $value = str_replace(',', '.', $value);

    return is_numeric($value) ? (float) $value : null;
}

function requirePost(string $key): string
{
    return trim((string) ($_POST[$key] ?? ''));
}

function redirect(string $action, array $query = []): never
{
    header('Location: ' . appUrl($action, $query));
    exit;
}

function listCustomers(PDO $pdo): array
{
    return fetchAll($pdo, 'SELECT id_cliente, nome, telefone, email FROM clientes ORDER BY nome');
}

function listProducts(PDO $pdo): array
{
    return fetchAll($pdo, 'SELECT id_produto, nome, descricao, preco FROM produtos ORDER BY nome');
}

function listWaiters(PDO $pdo): array
{
    return fetchAll($pdo, 'SELECT id_garcom, nome, telefone FROM garcons ORDER BY nome');
}

function listTables(PDO $pdo): array
{
    return fetchAll($pdo, 'SELECT id_mesa, numero_mesa, capacidade, status FROM mesas ORDER BY numero_mesa');
}

function listOrders(PDO $pdo): array
{
    return fetchAll($pdo, '
        SELECT
            p.id_pedido,
            c.nome AS cliente,
            m.numero_mesa AS mesa,
            g.nome AS garcom,
            p.status_pedido AS status,
            p.data_pedido
        FROM pedidos p
        INNER JOIN clientes c ON c.id_cliente = p.id_cliente
        INNER JOIN mesas m ON m.id_mesa = p.id_mesa
        INNER JOIN garcons g ON g.id_garcom = p.id_garcom
        ORDER BY p.data_pedido DESC, p.id_pedido DESC
    ');
}

function listOrderItems(PDO $pdo, int $pedidoId): array
{
    return fetchAll($pdo, '
        SELECT
            ip.id_item,
            pr.nome AS prato,
            ip.quantidade,
            ip.preco_unitario,
            (ip.quantidade * ip.preco_unitario) AS subtotal
        FROM itens_pedido ip
        INNER JOIN produtos pr ON pr.id_produto = ip.id_produto
        WHERE ip.id_pedido = :id_pedido
        ORDER BY ip.id_item
    ', ['id_pedido' => $pedidoId]);
}

function createCustomer(PDO $pdo): void
{
    $nome = trim(requirePost('nome'));
    $telefone = trim(requirePost('telefone'));
    $email = trim(requirePost('email'));

    if ($nome === '') {
        setFlash('Informe o nome do cliente.', 'error');
        redirect('customers');
    }

    try {
        callProcedure($pdo, 'cadastrar_cliente', [
            'nome' => $nome,
            'telefone' => $telefone,
            'email' => $email,
        ]);
        setFlash('Cliente cadastrado com sucesso!');
    } catch (Throwable $exception) {
        setFlash('Falha ao cadastrar cliente: ' . $exception->getMessage(), 'error');
    }

    redirect('customers');
}

function searchCustomers(PDO $pdo): array
{
    $term = trim(requirePost('search_term'));

    if ($term === '') {
        return listCustomers($pdo);
    }

    return fetchAll($pdo, 'SELECT id_cliente, nome, telefone, email FROM clientes WHERE nome LIKE :term ORDER BY nome', [
        'term' => '%' . $term . '%',
    ]);
}

function createDish(PDO $pdo): void
{
    $nome = trim(requirePost('dish_name'));
    $descricao = trim(requirePost('dish_description'));
    $preco = parseFloatValue(requirePost('dish_price'));

    if ($nome === '' || $preco === null) {
        setFlash('Preencha nome e preço do prato corretamente.', 'error');
        redirect('menu');
    }

    try {
        executeStatement($pdo, 'INSERT INTO produtos (nome, descricao, preco) VALUES (:nome, :descricao, :preco)', [
            'nome' => $nome,
            'descricao' => $descricao,
            'preco' => $preco,
        ]);
        setFlash('Prato cadastrado com sucesso!');
    } catch (Throwable $exception) {
        setFlash('Falha ao cadastrar prato: ' . $exception->getMessage(), 'error');
    }

    redirect('menu');
}

function updateDishPrice(PDO $pdo): void
{
    $produtoId = parseInt(requirePost('dish_id'));
    $novoPreco = parseFloatValue(requirePost('new_price'));

    if ($produtoId === null || $novoPreco === null) {
        setFlash('Informe um ID válido e um novo preço válido.', 'error');
        redirect('menu');
    }

    try {
        callProcedure($pdo, 'atualizar_preco_produto', [
            'id_produto' => $produtoId,
            'novo_preco' => $novoPreco,
        ]);
        setFlash('Preço atualizado com sucesso!');
    } catch (Throwable $exception) {
        setFlash('Falha ao atualizar preço: ' . $exception->GetMessage(), 'error');
    }

    redirect('menu');
}

function createOrder(PDO $pdo): void
{
    $clienteId = parseInt(requirePost('cliente_id'));
    $mesaId = parseInt(requirePost('mesa_id'));
    $garcomId = parseInt(requirePost('garcom_id'));

    if ($clienteId === null || $mesaId === null || $garcomId === null) {
        setFlash('Selecione cliente, mesa e garçom válidos.', 'error');
        redirect('orders');
    }

    try {
        callProcedure($pdo, 'inserir_pedido', [
            'id_cliente' => $clienteId,
            'id_mesa' => $mesaId,
            'id_garcom' => $garcomId,
        ]);

        $pedidoIdRow = fetchOne($pdo, 'SELECT LAST_INSERT_ID() AS id_pedido');
        $pedidoId = (int) ($pedidoIdRow['id_pedido'] ?? 0);

        setFlash('Pedido criado com sucesso! ID: ' . $pedidoId);
        redirect('orders', ['order_id' => $pedidoId]);
    } catch (Throwable $exception) {
        setFlash('Falha ao criar pedido: ' . $exception->getMessage(), 'error');
        redirect('orders');
    }
}

function addItemToOrder(PDO $pdo): void
{
    $pedidoId = parseInt(requirePost('order_id'));
    $produtoId = parseInt(requirePost('product_id'));
    $quantidade = parseInt(requirePost('quantity'));

    if ($pedidoId === null || $produtoId === null || $quantidade === null || $quantidade <= 0) {
        setFlash('Informe pedido, produto e quantidade válidos.', 'error');
        redirect('orders', ['order_id' => $_POST['order_id'] ?? '']);
    }

    $produto = fetchOne($pdo, 'SELECT id_produto, preco FROM produtos WHERE id_produto = :id_produto', [
        'id_produto' => $produtoId,
    ]);

    if ($produto === null) {
        setFlash('Produto não encontrado.', 'error');
        redirect('orders', ['order_id' => $pedidoId]);
    }

    try {
        executeStatement(
            $pdo,
            'INSERT INTO itens_pedido (id_pedido, id_produto, quantidade, preco_unitario) VALUES (:id_pedido, :id_produto, :quantidade, :preco_unitario)',
            [
                'id_pedido' => $pedidoId,
                'id_produto' => $produtoId,
                'quantidade' => $quantidade,
                'preco_unitario' => $produto['preco'],
            ]
        );
        setFlash('Item adicionado com sucesso!');
    } catch (Throwable $exception) {
        setFlash('Falha ao adicionar item: ' . $exception->getMessage(), 'error');
    }

    redirect('orders', ['order_id' => $pedidoId]);
}

function updateOrderStatus(PDO $pdo): void
{
    $pedidoId = parseInt(requirePost('status_order_id'));
    $status = strtoupper(trim(requirePost('new_status')));

    if ($pedidoId === null || !in_array($status, ['ABERTO', 'PAGO', 'CANCELADO'], true)) {
        setFlash('Status inválido.', 'error');
        redirect('orders');
    }

    try {
        executeStatement($pdo, 'UPDATE pedidos SET status_pedido = :status WHERE id_pedido = :id_pedido', [
            'status' => $status,
            'id_pedido' => $pedidoId,
        ]);
        setFlash('Status atualizado com sucesso!');
    } catch (Throwable $exception) {
        setFlash('Falha ao atualizar status: ' . $exception->getMessage(), 'error');
    }

    redirect('orders', ['order_id' => $pedidoId]);
}

function salesByPeriod(PDO $pdo): array
{
    $start = trim(requirePost('start_date'));
    $end = trim(requirePost('end_date'));

    if ($start === '' || $end === '') {
        return [];
    }

    return fetchAll($pdo, "
        SELECT
            DATE(p.data_pedido) AS dia,
            SUM(ip.quantidade * ip.preco_unitario) AS total_vendas
        FROM pedidos p
        INNER JOIN itens_pedido ip ON ip.id_pedido = p.id_pedido
        WHERE p.status_pedido = 'PAGO'
          AND DATE(p.data_pedido) BETWEEN :inicio AND :fim
        GROUP BY DATE(p.data_pedido)
        ORDER BY dia
    ", [
        'inicio' => $start,
        'fim' => $end,
    ]);
}

function waiterRanking(PDO $pdo): array
{
    return fetchAll($pdo, '
        SELECT
            g.id_garcom,
            g.nome,
            COUNT(p.id_pedido) AS total_pedidos
        FROM garcons g
        LEFT JOIN pedidos p ON p.id_garcom = g.id_garcom
        GROUP BY g.id_garcom, g.nome
        ORDER BY total_pedidos DESC, g.nome ASC
    ');
}

function bestSellingDishes(PDO $pdo): array
{
    return fetchAll($pdo, '
        SELECT
            pr.id_produto,
            pr.nome,
            SUM(ip.quantidade) AS quantidade_vendida,
            SUM(ip.quantidade * ip.preco_unitario) AS faturamento
        FROM produtos pr
        INNER JOIN itens_pedido ip ON ip.id_produto = pr.id_produto
        GROUP BY pr.id_produto, pr.nome
        ORDER BY quantidade_vendida DESC, faturamento DESC
    ');
}

function revenueByTable(PDO $pdo): array
{
    return fetchAll($pdo, "
        SELECT
            m.id_mesa,
            m.numero_mesa,
            SUM(ip.quantidade * ip.preco_unitario) AS faturamento
        FROM mesas m
        INNER JOIN pedidos p ON p.id_mesa = m.id_mesa
        INNER JOIN itens_pedido ip ON ip.id_pedido = p.id_pedido
        WHERE p.status_pedido = 'PAGO'
        GROUP BY m.id_mesa, m.numero_mesa
        ORDER BY faturamento DESC
    ");
}

function customerOrders(PDO $pdo, int $clienteId): array
{
    return callProcedure($pdo, 'listar_pedidos_cliente', [
        'id_cliente' => $clienteId,
    ]);
}

function classifyOrders(PDO $pdo): array
{
    return fetchAll($pdo, "
        SELECT
            p.id_pedido,
            c.nome AS cliente,
            COALESCE(SUM(ip.quantidade), 0) AS total_itens,
            CASE
                WHEN COALESCE(SUM(ip.quantidade), 0) <= 3 THEN 'PEQUENO'
                WHEN COALESCE(SUM(ip.quantidade), 0) BETWEEN 4 AND 6 THEN 'MEDIO'
                ELSE 'GRANDE'
            END AS classificacao
        FROM pedidos p
        INNER JOIN clientes c ON c.id_cliente = p.id_cliente
        LEFT JOIN itens_pedido ip ON ip.id_pedido = p.id_pedido
        GROUP BY p.id_pedido, c.nome
        ORDER BY p.id_pedido DESC
    ");
}

// Main execution
$pdo = dbConnection($appConfig, $dbError);

if ($pdo === null) {
    $dbError = $dbError ?? 'Falha ao abrir o banco MySQL. Verifique as configurações em config.php e o acesso ao servidor MySQL.';
}

$flash = consumeFlash();
$action = $_GET['action'] ?? 'dashboard';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $pdo !== null) {
    $task = $_POST['task'] ?? '';
    try {
        if ($action === 'customers' && $task === 'create') {
            createCustomer($pdo);
        } elseif ($action === 'orders' && $task === 'create') {
            createOrder($pdo);
        } elseif ($action === 'orders' && $task === 'add_item') {
            addItemToOrder($pdo);
        } elseif ($action === 'orders' && $task === 'status') {
            updateOrderStatus($pdo);
        } elseif ($action === 'menu' && $task === 'create') {
            createDish($pdo);
        } elseif ($action === 'menu' && $task === 'price') {
            updateDishPrice($pdo);
        }
    } catch (Throwable $exception) {
        setFlash('Erro inesperado: ' . $exception->getMessage(), 'error');
        redirect($action);
    }
}

renderPageStart('Sistema de Gerenciamento');
renderSidebar($action);
echo '<main class="main-content" id="mainContent">';
renderTopBar(ucfirst($action));
echo '<div class="content-wrapper">';
renderFlash($flash);

switch ($action) {
    case 'customers':
        if ($pdo !== null) {
            renderCustomersPage($pdo, $dbError, $flash);
        } else {
            echo '<div class="card"><p class="flash flash-error">' . h($dbError ?? 'Banco indisponível.') . '</p></div>';
        }
        break;
    case 'orders':
        if ($pdo !== null) {
            renderOrdersPage($pdo, $dbError, $flash);
        } else {
            echo '<div class="card"><p class="flash flash-error">' . h($dbError ?? 'Banco indisponível.') . '</p></div>';
        }
        break;
    case 'menu':
        if ($pdo !== null) {
            renderMenuPage($pdo, $dbError, $flash);
        } else {
            echo '<div class="card"><p class="flash flash-error">' . h($dbError ?? 'Banco indisponível.') . '</p></div>';
        }
        break;
    case 'reports':
        if ($pdo !== null) {
            renderReportsPage($pdo, $dbError, $flash);
        } else {
            echo '<div class="card"><p class="flash flash-error">' . h($dbError ?? 'Banco indisponível.') . '</p></div>';
        }
        break;
    default:
        renderDashboard($pdo, $dbError);
        break;
}

echo '</div>';
echo '</main>';
renderPageEnd();