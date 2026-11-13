<?php
require_once __DIR__ . '/../src/Database.php';
$pdo = Database::getConnection();
$paginaAtual = basename($_SERVER['PHP_SELF']);

function moedaParaFloat(string|null $valor): float {
    if (empty($valor)) return 0.0;
    $limpo = preg_replace('/[^\d,.-]/', '', $valor);
    if (str_contains($limpo, ',')) {
        $limpo = str_replace('.', '', $limpo);
        $limpo = str_replace(',', '.', $limpo);
    }
    return (float)$limpo;
}
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Studio Lumen 3D — Controle de Estoque</title>
    <!-- Bootstrap 5 -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    
    <style>
        :root {
            --lumen-bg: #18181a;
            --lumen-card-bg: #222226;
            --lumen-card-border: rgba(212, 175, 55, 0.4);
            --lumen-gold: #e2c04a;
            --lumen-gold-hover: #f1d36d;
            --lumen-text: #f0f0f5;        
            --lumen-muted: #c4c4d0;       
        }

        body {
            background-color: var(--lumen-bg) !important;
            color: var(--lumen-text) !important;
            font-family: system-ui, -apple-system, "Segoe UI", Roboto, sans-serif;
        }

        /* Navbar */
        .navbar-lumen {
            background-color: #1f1f23 !important;
            border-bottom: 1px solid var(--lumen-card-border);
        }
        .navbar-lumen .navbar-brand {
            color: var(--lumen-gold) !important;
            letter-spacing: 1px;
            font-weight: 700;
        }
        .navbar-lumen .nav-link {
            color: var(--lumen-muted) !important;
            transition: color 0.2s;
        }
        .navbar-lumen .nav-link:hover, 
        .navbar-lumen .nav-link.active {
            color: var(--lumen-gold-hover) !important;
            font-weight: 600;
        }

        /* Cards e Painéis - Padronização Absoluta (Apenas para cards escuros) */
        .card:not(.bg-white) {
            background-color: var(--lumen-card-bg) !important;
            border: 1px solid var(--lumen-card-border) !important;
            color: var(--lumen-text) !important;
            box-shadow: 0 4px 20px rgba(0, 0, 0, 0.5) !important;
        }
        .card:not(.bg-white) .card-body {
            color: var(--lumen-text) !important;
            background-color: var(--lumen-card-bg) !important;
        }
        /* Cabeçalhos de cards escuros (Exclui .bg-white) */
        .card:not(.bg-white) .card-header, 
        .bg-success:not(.bg-white), 
        .bg-danger:not(.bg-white), 
        .bg-info:not(.bg-white) {
            background-color: #2c2c32 !important;
            border-bottom: 1px solid var(--lumen-card-border) !important;
            color: var(--lumen-gold) !important;
            font-weight: 600;
        }
        .card:not(.bg-white) .card-header span, 
        .card:not(.bg-white) .card-header a, 
        .card:not(.bg-white) .card-header h5 {
            color: var(--lumen-gold) !important;
        }
        .card:not(.bg-white) .card-footer {
            background-color: #2c2c32 !important;
            border-top: 1px solid var(--lumen-card-border) !important;
            color: var(--lumen-text) !important;
        }

        /* ========================================================
           EXCEÇÃO CRUCIAL: Cartões Brancos (Romaneio e Documentos)
           ======================================================== */
        .card.bg-white, .bg-white {
            background-color: #ffffff !important;
            color: #212529 !important;
            border: none !important;
            box-shadow: 0 4px 20px rgba(0,0,0,0.15) !important;
        }
        .card.bg-white .card-body, .bg-white .card-body {
            background-color: #ffffff !important;
            color: #212529 !important;
        }
        .card.bg-white h1, .card.bg-white h2, .card.bg-white h3, .card.bg-white h4, .card.bg-white h5, .card.bg-white h6, 
        .card.bg-white p, .card.bg-white span, .card.bg-white strong, .card.bg-white b, .card.bg-white li, .card.bg-white label,
        .bg-white h1, .bg-white h2, .bg-white h3, .bg-white h4, .bg-white h5, .bg-white h6, 
        .bg-white p, .bg-white span, .bg-white strong, .bg-white b, .bg-white li, .bg-white label {
            color: #212529 !important;
        }
        .card.bg-white .text-muted, .bg-white .text-muted {
            color: #6c757d !important;
        }
        .card.bg-white .badge, .bg-white .badge {
            color: #ffffff !important;
        }
        .card.bg-white .badge.bg-dark, .bg-white .badge.bg-dark {
            background-color: #212529 !important;
            color: #ffffff !important;
        }

        /* Tabelas dentro de Cartões Brancos (Romaneio com bordas completas) */
        .card.bg-white .table, 
        .card.bg-white .table td, 
        .card.bg-white .table th,
        .bg-white .table, 
        .bg-white .table td, 
        .bg-white .table th {
            background-color: #ffffff !important;
            color: #212529 !important;
            border: 1px solid #dee2e6 !important;
        }
        .card.bg-white .table > thead, 
        .bg-white .table > thead,
        .card.bg-white .table tfoot, 
        .bg-white .table tfoot {
            background-color: #f8f9fa !important;
            color: #212529 !important;
        }

        /* Classes de Cores Dedicadas Apenas aos Números nos Cards Escuros */
        .val-bruto    { color: #e2c04a !important; font-weight: 700; } 
        .val-comissao { color: #f39c12 !important; font-weight: 700; } 
        .val-liquido  { color: #2ecc71 !important; font-weight: 700; } 
        .val-custo    { color: #3498db !important; font-weight: 700; } 
        .val-alerta   { color: #e74c3c !important; font-weight: 700; } 

        /* Tabelas Escuras (Painel) */
        .card:not(.bg-white) .table {
            color: var(--lumen-text) !important;
            border-color: #383842 !important;
            background-color: var(--lumen-card-bg) !important;
        }
        .card:not(.bg-white) .table > thead {
            background-color: #2c2c32 !important;
            color: var(--lumen-gold) !important;
            border-bottom: 2px solid var(--lumen-card-border);
        }
        .card:not(.bg-white) .table > thead > tr > th {
            color: var(--lumen-gold) !important;
            background-color: transparent !important;
        }
        .card:not(.bg-white) .table tbody tr {
            background-color: var(--lumen-card-bg) !important;
            color: var(--lumen-text) !important;
        }
        .card:not(.bg-white) .table tbody tr td {
            color: var(--lumen-text) !important;
            background-color: transparent !important;
            border-color: #383842 !important;
        }
        .table-hover > tbody > tr:hover {
            background-color: rgba(212, 175, 55, 0.12) !important;
            color: #fff !important;
        }
        .table-light, .table-secondary, tfoot.table-light {
            background-color: #2c2c32 !important;
            color: var(--lumen-text) !important;
            border-color: #383842 !important;
        }

        /* Listas */
        .list-group-item {
            background-color: var(--lumen-card-bg) !important;
            color: var(--lumen-text) !important;
            border-color: #383842 !important;
        }
        .list-group-item:hover {
            background-color: rgba(212, 175, 55, 0.08) !important;
        }

        /* Inputs, Formulários e Placeholders Corrigidos */
        .form-control, .form-select {
            background-color: #18181c !important;
            border: 1px solid #4a4a58 !important;
            color: #ffffff !important;
        }
        .form-control::placeholder, .form-select::placeholder {
            color: #8c8c9e !important;
            opacity: 1 !important;
        }
        .form-control:focus, .form-select:focus {
            background-color: #222228 !important;
            border-color: var(--lumen-gold) !important;
            color: #ffffff !important;
            box-shadow: 0 0 0 0.25rem rgba(212, 175, 55, 0.25) !important;
        }
        .input-group-text {
            background-color: #2c2c32 !important;
            border: 1px solid #4a4a58 !important;
            color: var(--lumen-gold) !important;
        }

        /* Botões */
        .btn-primary {
            background-color: #d4af37 !important;
            border-color: var(--lumen-gold) !important;
            color: #141414 !important;
            font-weight: 600;
        }
        .btn-primary:hover {
            background-color: var(--lumen-gold-hover) !important;
            border-color: var(--lumen-gold-hover) !important;
            color: #000 !important;
        }
        .btn-outline-light, .btn-outline-secondary {
            border-color: var(--lumen-card-border) !important;
            color: var(--lumen-gold) !important;
            background-color: transparent !important;
            font-weight: 600;
            transition: all 0.2s ease-in-out;
        }
        .btn-outline-light:hover, .btn-outline-secondary:hover {
            background-color: var(--lumen-gold) !important;
            border-color: var(--lumen-gold) !important;
            color: #141414 !important;
        }

        .text-muted {
            color: var(--lumen-muted) !important;
        }

        /* ========================================================
           EXCEÇÃO: APENAS PARA DOCUMENTOS DE IMPRESSÃO (Romaneio e Relatórios)
           ======================================================== */
        @media print {
            html, body {
                background-color: #ffffff !important;
                color: #000000 !important;
            }
            nav.navbar, .nao-imprimir, .btn {
                display: none !important;
            }
            .card, .card-body {
                background-color: #ffffff !important;
                border: none !important;
                box-shadow: none !important;
                color: #000000 !important;
            }
            .card-header, .card-footer, .table > thead, tfoot.table-light {
                background-color: #f8f9fa !important;
                color: #000000 !important;
            }
            .table, .table td, .table th {
                color: #000000 !important;
                border-color: #dee2e6 !important;
                background-color: transparent !important;
            }
            .text-muted, small, p, span, div, h3, h4, h5, h6, strong {
                color: #000000 !important;
            }
        }
    </style>

    <!-- Script de Máscara Numérica (Estilo Adianti) -->
    <script>
        function floatParaMoedaBRL(valor) {
            let num = parseFloat(valor);
            if (isNaN(num)) return '0,00';
            return num.toLocaleString('pt-BR', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
        }

        function aplicarMascaraAdianti(valorBruto, casasDecimais = 2) {
            let digitos = String(valorBruto).replace(/\D/g, '');
            digitos = digitos.replace(/^0+/, '');
            
            if (digitos.length === 0) {
                return casasDecimais > 0 ? '0,' + '0'.repeat(casasDecimais) : '0';
            }

            if (casasDecimais === 0) {
                return digitos.replace(/\B(?=(\d{3})+(?!\d))/g, '.');
            }

            digitos = digitos.padStart(casasDecimais + 1, '0');
            let parteInteira = digitos.slice(0, -casasDecimais);
            let parteDecimal = digitos.slice(-casasDecimais);
            parteInteira = parteInteira.replace(/\B(?=(\d{3})+(?!\d))/g, '.');

            return parteInteira + ',' + parteDecimal;
        }

        document.addEventListener('input', function (e) {
            if (e.target && e.target.classList.contains('moeda-brl')) {
                const casas = parseInt(e.target.getAttribute('data-decimais') || '2', 10);
                e.target.value = aplicarMascaraAdianti(e.target.value, casas);
            }
        });

        document.addEventListener('focusin', function (e) {
            if (e.target && e.target.classList.contains('moeda-brl')) {
                setTimeout(() => {
                    e.target.setSelectionRange(e.target.value.length, e.target.value.length);
                }, 10);
            }
        });

        document.addEventListener('keydown', function (e) {
            if (e.target && e.target.classList.contains('moeda-brl')) {
                const teclasPermitidas = [
                    'Backspace', 'Delete', 'Tab', 'Escape', 'Enter',
                    'ArrowLeft', 'ArrowRight', 'Home', 'End'
                ];
                if (teclasPermitidas.includes(e.key) || (e.ctrlKey || e.metaKey)) {
                    return;
                }
                if (!/^\d$/.test(e.key)) {
                    e.preventDefault();
                }
            }
        });
    </script>
</head>
<body>
    <nav class="navbar navbar-expand navbar-lumen mb-4 shadow-sm py-2">
        <div class="container-fluid px-3">
            <!-- LOGO NA NAVBAR -->
            <a class="navbar-brand fw-bold fs-5 me-3 text-nowrap d-flex align-items-center" href="index.php">
                <img src="img/logo.jpeg" alt="Studio Lumen 3D" style="height: 35px; width: auto;" class="me-2 rounded">
                <span>Studio Lumen 3D</span>
            </a>
            
            <div class="navbar-nav me-auto flex-wrap py-1" style="gap: 4px;">
                <a class="nav-link px-2 text-nowrap <?= $paginaAtual === 'index.php' ? 'active fw-bold' : '' ?>" href="index.php">📊 Painel</a>
                <a class="nav-link px-2 text-nowrap <?= $paginaAtual === 'produtos.php' ? 'active fw-bold' : '' ?>" href="produtos.php">📦 Produtos</a>
                <a class="nav-link px-2 text-nowrap <?= $paginaAtual === 'locais.php' ? 'active fw-bold' : '' ?>" href="locais.php">🏪 Lojas</a>
                <a class="nav-link px-2 text-nowrap <?= $paginaAtual === 'movimentacoes.php' ? 'active fw-bold' : '' ?>" href="movimentacoes.php">🔄 Movimentações</a>
                <a class="nav-link px-2 text-nowrap <?= $paginaAtual === 'inventario.php' ? 'active fw-bold' : '' ?>" href="inventario.php">📋 Inventário</a>
                <a class="nav-link px-2 text-nowrap <?= $paginaAtual === 'romaneio.php' ? 'active fw-bold' : '' ?>" href="romaneio.php">📄 Romaneio</a>
                <a class="nav-link px-2 text-nowrap <?= $paginaAtual === 'relatorios.php' ? 'active fw-bold' : '' ?>" href="relatorios.php">📈 Relatórios</a>
            </div>

            <div class="navbar-nav ms-2">
                <a href="backup.php" class="btn btn-outline-light btn-sm fw-semibold text-nowrap">
                    💾 Backup
                </a>
            </div>
        </div>
    </nav>
    <div class="container pb-5">