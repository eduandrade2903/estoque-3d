<?php
require_once __DIR__ . '/../src/Database.php';
$pdo = Database::getConnection();
$paginaAtual = basename($_SERVER['PHP_SELF']);

/**
 * Converte string em formato Real ("1.250,50" ou "12,50") para float do banco (1250.50)
 */
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
    <title>Estoque 3D & Consignado</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
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
<body class="bg-light">
    <!-- navbar-expand garante que os itens fiquem sempre lado a lado sem quebrar -->
    <nav class="navbar navbar-expand navbar-dark bg-dark mb-4 shadow-sm py-2">
        <div class="container-fluid px-3">
            <a class="navbar-brand fw-bold fs-5 me-3 text-nowrap" href="index.php">🖨️ Estoque 3D</a>
            
            <div class="navbar-nav me-auto flex-row overflow-auto py-1" style="gap: 2px;">
                <a class="nav-link px-2 text-nowrap <?= $paginaAtual === 'index.php' ? 'active fw-bold text-white' : '' ?>" href="index.php">📊 Painel</a>
                <a class="nav-link px-2 text-nowrap <?= $paginaAtual === 'produtos.php' ? 'active fw-bold text-white' : '' ?>" href="produtos.php">📦 Produtos</a>
                <a class="nav-link px-2 text-nowrap <?= $paginaAtual === 'locais.php' ? 'active fw-bold text-white' : '' ?>" href="locais.php">🏪 Lojas</a>
                <a class="nav-link px-2 text-nowrap <?= $paginaAtual === 'movimentacoes.php' ? 'active fw-bold text-white' : '' ?>" href="movimentacoes.php">🔄 Movimentações</a>
                <a class="nav-link px-2 text-nowrap <?= $paginaAtual === 'inventario.php' ? 'active fw-bold text-white' : '' ?>" href="inventario.php">📋 Inventário</a>
                <a class="nav-link px-2 text-nowrap <?= $paginaAtual === 'romaneio.php' ? 'active fw-bold text-white' : '' ?>" href="romaneio.php">📄 Romaneio</a>
                <a class="nav-link px-2 text-nowrap <?= $paginaAtual === 'relatorios.php' ? 'active fw-bold text-white' : '' ?>" href="relatorios.php">📈 Relatórios</a>
            </div>

            <div class="navbar-nav ms-2">
                <a href="backup.php" class="btn btn-outline-light btn-sm fw-semibold text-nowrap">
                    💾 Backup
                </a>
            </div>
        </div>
    </nav>
    <div class="container pb-5">