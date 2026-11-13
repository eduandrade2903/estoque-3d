<?php
require_once __DIR__ . '/../src/Database.php';
$pdo = Database::getConnection();
$paginaAtual = basename($_SERVER['PHP_SELF']);


function moedaParaFloat(string|null $valor): float {
    if (empty($valor)) return 0.0;
    $limpo = preg_replace('/[^\d,.-]/', '', $valor);
    // Se tiver vírgula, assume formato BR (remove pontos de milhar e troca vírgula por ponto)
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
        // Converte um número float (ex: 1500.5) para o texto "1.500,50"
        function floatParaMoedaBRL(valor) {
            let num = parseFloat(valor);
            if (isNaN(num)) return '0,00';
            return num.toLocaleString('pt-BR', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
        }

        // Função que formata string de dígitos no estilo TNumeric do Adianti
        function aplicarMascaraAdianti(valorBruto, casasDecimais = 2) {
            // Remove tudo que não for dígito (0-9)
            let digitos = String(valorBruto).replace(/\D/g, '');
            
            // Remove zeros à esquerda desnecessários
            digitos = digitos.replace(/^0+/, '');
            
            if (digitos.length === 0) {
                return casasDecimais > 0 ? '0,' + '0'.repeat(casasDecimais) : '0';
            }

            if (casasDecimais === 0) {
                return digitos.replace(/\B(?=(\d{3})+(?!\d))/g, '.');
            }

            // Preenche com zeros à esquerda caso tenha menos dígitos que as casas decimais
            digitos = digitos.padStart(casasDecimais + 1, '0');

            // Separa parte inteira e parte decimal
            let parteInteira = digitos.slice(0, -casasDecimais);
            let parteDecimal = digitos.slice(-casasDecimais);

            // Adiciona os pontos de milhar a cada 3 números na parte inteira
            parteInteira = parteInteira.replace(/\B(?=(\d{3})+(?!\d))/g, '.');

            return parteInteira + ',' + parteDecimal;
        }

        // Delegação global: escuta qualquer digitação em campos com .moeda-brl em qualquer tela
        document.addEventListener('input', function (e) {
            if (e.target && e.target.classList.contains('moeda-brl')) {
                const casas = parseInt(e.target.getAttribute('data-decimais') || '2', 10);
                e.target.value = aplicarMascaraAdianti(e.target.value, casas);
            }
        });

        // Ao clicar/focar no campo, seleciona o texto ou joga o cursor pro final (igual ao Adianti)
        document.addEventListener('focusin', function (e) {
            if (e.target && e.target.classList.contains('moeda-brl')) {
                setTimeout(() => {
                    e.target.setSelectionRange(e.target.value.length, e.target.value.length);
                }, 10);
            }
        });

        // Bloqueia letras e teclas não numéricas antes mesmo de aparecerem
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
    <nav class="navbar navbar-expand-lg navbar-dark bg-dark mb-4 shadow-sm">
        <div class="container">
            <a class="navbar-brand fw-bold" href="index.php">🖨️ Estoque 3D</a>
            <div class="navbar-nav">
                <a class="nav-link <?= $paginaAtual === 'index.php' ? 'active' : '' ?>" href="index.php">📊 Painel</a>
                <a class="nav-link <?= $paginaAtual === 'produtos.php' ? 'active' : '' ?>" href="produtos.php">📦 Produtos & Insumos</a>
                <a class="nav-link <?= $paginaAtual === 'locais.php' ? 'active' : '' ?>" href="locais.php">🏪 Lojas & Clientes</a>
                <a class="nav-link <?= $paginaAtual === 'movimentacoes.php' ? 'active' : '' ?>" href="movimentacoes.php">🔄 Movimentações</a>
                <a class="nav-link <?= $paginaAtual === 'inventario.php' ? 'active' : '' ?>" href="inventario.php">📋 Inventário</a>
            </div>
        </div>
    </nav>
    <div class="container pb-5">