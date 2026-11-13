<?php
require_once __DIR__ . '/includes/header.php';

// Filtros de Data (Padrão: Mês atual)
$dataInicio = $_GET['data_inicio'] ?? date('Y-m-01');
$dataFim    = $_GET['data_fim']    ?? date('Y-m-t');

// Regra de segurança: Se a data final for menor que a inicial, iguala à inicial
if ($dataFim < $dataInicio) {
    $dataFim = $dataInicio;
}

$lojaFiltro = isset($_GET['loja_id']) ? (int)$_GET['loja_id'] : 0;

// Buscar lista de lojas parceiras para o filtro
$lojasParceiras = $pdo->query("SELECT * FROM locais_clientes WHERE tipo = 'LOJA_PARCEIRA' ORDER BY nome ASC")->fetchAll();

// 1. FATURAMENTO GERAL CONSOLIDADO NO PERÍODO (Todas as saídas por venda)
$sqlGeralResumo = "
    SELECT 
        COALESCE(SUM(m.quantidade * m.valor_unitario), 0) AS bruto_geral,
        COALESCE(SUM(
            CASE 
                WHEN origem.tipo = 'LOJA_PARCEIRA' THEN (m.quantidade * m.valor_unitario) * (origem.comissao_percentual / 100.0)
                ELSE 0 
            END
        ), 0) AS comissao_geral,
        COALESCE(SUM(
            CASE 
                WHEN origem.tipo = 'LOJA_PARCEIRA' THEN (m.quantidade * m.valor_unitario) * (1 - (origem.comissao_percentual / 100.0))
                ELSE (m.quantidade * m.valor_unitario)
            END
        ), 0) AS liquido_geral,
        COUNT(m.id) AS total_transacoes,
        COALESCE(SUM(m.quantidade), 0) AS total_pecas_vendidas
    FROM movimentacoes m
    LEFT JOIN locais_clientes origem ON origem.id = m.local_origem_id
    WHERE m.tipo_movimento = 'SAIDA_VENDA'
      AND DATE(m.data_movimento) BETWEEN ? AND ?
";
$paramsGeral = [$dataInicio, $dataFim];
if ($lojaFiltro > 0) {
    $sqlGeralResumo .= " AND origem.id = ?";
    $paramsGeral[] = $lojaFiltro;
}
$stmtGeralResumo = $pdo->prepare($sqlGeralResumo);
$stmtGeralResumo->execute($paramsGeral);
$resumoGeral = $stmtGeralResumo->fetch();

// 2. DADOS DE ACERTO POR LOJA NO PERÍODO (Consignado)
$sqlAcerto = "
    SELECT 
        l.id AS loja_id,
        l.nome AS loja_nome,
        l.comissao_percentual,
        COUNT(m.id) AS total_vendas,
        COALESCE(SUM(m.quantidade), 0) AS total_pecas_vendidas,
        COALESCE(SUM(m.quantidade * m.valor_unitario), 0) AS valor_bruto,
        COALESCE(SUM((m.quantidade * m.valor_unitario) * (l.comissao_percentual / 100.0)), 0) AS valor_comissao_loja,
        COALESCE(SUM((m.quantidade * m.valor_unitario) * (1 - (l.comissao_percentual / 100.0))), 0) AS valor_liquido_receber
    FROM locais_clientes l
    JOIN movimentacoes m ON m.local_origem_id = l.id AND m.tipo_movimento = 'SAIDA_VENDA'
    WHERE l.tipo = 'LOJA_PARCEIRA' 
      AND DATE(m.data_movimento) BETWEEN ? AND ?
";
$paramsAcerto = [$dataInicio, $dataFim];
if ($lojaFiltro > 0) {
    $sqlAcerto .= " AND l.id = ?";
    $paramsAcerto[] = $lojaFiltro;
}
$sqlAcerto .= " GROUP BY l.id ORDER BY l.nome ASC";
$stmtAcerto = $pdo->prepare($sqlAcerto);
$stmtAcerto->execute($paramsAcerto);
$acertosLojas = $stmtAcerto->fetchAll();

// 3. EXTRATO DETALHADO DE TODAS AS VENDAS NO PERÍODO
$sqlVendas = "
    SELECT 
        m.*,
        p.nome AS produto_nome,
        p.sku,
        p.unidade_medida,
        origem.nome AS origem_nome,
        origem.tipo AS origem_tipo,
        origem.comissao_percentual,
        (m.quantidade * m.valor_unitario) AS subtotal_bruto,
        CASE 
            WHEN origem.tipo = 'LOJA_PARCEIRA' THEN (m.quantidade * m.valor_unitario) * (origem.comissao_percentual / 100.0)
            ELSE 0 
        END AS comissao_valor,
        CASE 
            WHEN origem.tipo = 'LOJA_PARCEIRA' THEN (m.quantidade * m.valor_unitario) * (1 - (origem.comissao_percentual / 100.0))
            ELSE (m.quantidade * m.valor_unitario)
        END AS liquido_valor
    FROM movimentacoes m
    JOIN produtos p ON p.id = m.produto_id
    LEFT JOIN locais_clientes origem ON origem.id = m.local_origem_id
    WHERE m.tipo_movimento = 'SAIDA_VENDA' 
      AND DATE(m.data_movimento) BETWEEN ? AND ?
";
$paramsVendas = [$dataInicio, $dataFim];
if ($lojaFiltro > 0) {
    $sqlVendas .= " AND origem.id = ?";
    $paramsVendas[] = $lojaFiltro;
}
$sqlVendas .= " ORDER BY m.data_movimento DESC";
$stmtVendas = $pdo->prepare($sqlVendas);
$stmtVendas->execute($paramsVendas);
$detalheVendas = $stmtVendas->fetchAll();
?>

<style>
@media print {
    nav.navbar, .nao-imprimir { display: none !important; }
    body { background-color: #fff !important; font-size: 11pt !important; }
    .card { border: none !important; box-shadow: none !important; }
}
</style>

<!-- FILTROS DE PERÍODO (Não sai na impressão) -->
<div class="card shadow-sm mb-4 nao-imprimir">
    <div class="card-body">
        <form method="GET" action="relatorios.php" class="row g-3 align-items-end">
            <div class="col-md-3">
                <label class="form-label fw-semibold">Data Inicial</label>
                <input type="date" id="data_inicio" name="data_inicio" class="form-control" value="<?= $dataInicio ?>">
            </div>
            <div class="col-md-3">
                <label class="form-label fw-semibold">Data Final</label>
                <input type="date" id="data_fim" name="data_fim" class="form-control" value="<?= $dataFim ?>">
            </div>
            <div class="col-md-4">
                <label class="form-label fw-semibold">Filtrar por Local / Loja</label>
                <select name="loja_id" class="form-select">
                    <option value="0">Todas as Lojas e Origens</option>
                    <?php foreach ($lojasParceiras as $lp): ?>
                        <option value="<?= $lp['id'] ?>" <?= $lp['id'] === $lojaFiltro ? 'selected' : '' ?>>
                            🏪 <?= htmlspecialchars($lp['nome']) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-2">
                <button type="submit" class="btn btn-primary w-100 fw-semibold">Filtrar</button>
            </div>
        </form>
    </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', function() {
    const inputInicio = document.getElementById('data_inicio');
    const inputFim = document.getElementById('data_fim');

    function validarDatas() {
        if (inputInicio.value) {
            inputFim.min = inputInicio.value;
            if (inputFim.value && inputFim.value < inputInicio.value) {
                inputFim.value = inputInicio.value;
            }
        }
    }

    validarDatas();
    inputInicio.addEventListener('change', validarDatas);
});
</script>

<!-- CABEÇALHO PARA IMPRESSÃO -->
<div class="text-center mb-4 d-none d-print-block">
    <h3 class="fw-bold">RELATÓRIO GERAL DE FATURAMENTO E VENDAS</h3>
    <p class="text-muted">Período de: <?= date('d/m/Y', strtotime($dataInicio)) ?> até <?= date('d/m/Y', strtotime($dataFim)) ?></p>
</div>

<!-- SEÇÃO 1: RESUMO DO FATURAMENTO GERAL -->
<div class="row g-3 mb-4">
    <div class="col-md-4">
        <div class="card shadow-sm h-100 p-3">
            <span class="text-muted small fw-semibold text-uppercase">Faturamento Bruto Geral</span>
            <div class="fs-3 val-bruto mt-1">
                R$ <?= number_format($resumoGeral['bruto_geral'], 2, ',', '.') ?>
            </div>
            <small class="text-muted"><?= $resumoGeral['total_pecas_vendidas'] ?> peças em <?= $resumoGeral['total_transacoes'] ?> vendas</small>
        </div>
    </div>
    <div class="col-md-4">
        <div class="card shadow-sm h-100 p-3">
            <span class="text-muted small fw-semibold text-uppercase">Total Comissões (Lojas)</span>
            <div class="fs-3 val-comissao mt-1">
                R$ <?= number_format($resumoGeral['comissao_geral'], 2, ',', '.') ?>
            </div>
            <small class="text-muted">Retido pelos parceiros nas vendas</small>
        </div>
    </div>
    <div class="col-md-4">
        <div class="card shadow-sm h-100 p-3">
            <span class="text-muted small fw-semibold text-uppercase">Seu Líquido Geral Realizado</span>
            <div class="fs-3 val-liquido mt-1">
                R$ <?= number_format($resumoGeral['liquido_geral'], 2, ',', '.') ?>
            </div>
            <small class="text-muted">Dinheiro efetivo gerado para você</small>
        </div>
    </div>
</div>

<!-- SEÇÃO 2: ACERTO DE CONTAS POR LOJA PARCEIRA -->
<div class="card shadow-sm mb-4">
    <div class="card-header bg-white d-flex justify-content-between align-items-center">
        <span class="fw-bold">📊 Acerto de Contas por Loja Parceira (Consignado)</span>
        <button onclick="window.print()" class="btn btn-sm btn-outline-light nao-imprimir">🖨️ Imprimir Relatório</button>
    </div>
    <div class="table-responsive">
        <table class="table table-hover align-middle mb-0">
            <thead class="table-light">
                <tr>
                    <th>Loja Parceira</th>
                    <th class="text-center">Peças Vendidas</th>
                    <th class="text-end">Venda Bruta (Total)</th>
                    <th class="text-end">Comissão (Fica c/ Loja)</th>
                    <th class="text-end">Líquido a Receber (Seu)</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($acertosLojas)): ?>
                    <tr>
                        <td colspan="5" class="text-center text-muted py-4">Nenhuma venda registrada em lojas parceiras neste período.</td>
                    </tr>
                <?php endif; ?>
                <?php foreach ($acertosLojas as $ac): ?>
                    <tr>
                        <td>
                            <div class="fw-semibold"><?= htmlspecialchars($ac['loja_nome']) ?></div>
                            <small class="text-muted">Comissão acordada: <?= number_format($ac['comissao_percentual'], 1, ',', '.') ?>%</small>
                        </td>
                        <td class="text-center fw-bold"><?= $ac['total_pecas_vendidas'] ?> un.</td>
                        <td class="text-end text-nowrap val-bruto">R$ <?= number_format($ac['valor_bruto'], 2, ',', '.') ?></td>
                        <td class="text-end text-nowrap val-comissao">R$ <?= number_format($ac['valor_comissao_loja'], 2, ',', '.') ?></td>
                        <td class="text-end text-nowrap val-liquido fs-5">R$ <?= number_format($ac['valor_liquido_receber'], 2, ',', '.') ?></td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>

<!-- SEÇÃO 3: EXTRATO DETALHADO DE TODAS AS VENDAS -->
<div class="card shadow-sm">
    <div class="card-header bg-white fw-bold">
        📝 Extrato Detalhado de Todas as Vendas Realizadas no Período
    </div>
    <div class="table-responsive">
        <table class="table table-sm table-hover align-middle mb-0">
            <thead class="table-light">
                <tr>
                    <th>Data / Hora</th>
                    <th>Origem / Local</th>
                    <th>Produto</th>
                    <th class="text-center">Qtd</th>
                    <th class="text-end">Preço Un.</th>
                    <th class="text-end">Bruto</th>
                    <th class="text-end">Comissão</th>
                    <th class="text-end">Líquido</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($detalheVendas)): ?>
                    <tr>
                        <td colspan="8" class="text-center text-muted py-4">Nenhum detalhe de venda encontrado para este período.</td>
                    </tr>
                <?php endif; ?>
                <?php foreach ($detalheVendas as $dv): ?>
                    <tr>
                        <td class="small text-muted"><?= date('d/m/Y H:i', strtotime($dv['data_movimento'])) ?></td>
                        <td>
                            <span class="badge <?= ($dv['origem_tipo'] === 'INTERNO') ? 'bg-primary' : 'bg-warning text-dark' ?>">
                                <?= htmlspecialchars($dv['origem_nome'] ?? 'Oficina / Direta') ?>
                            </span>
                        </td>
                        <td class="fw-semibold"><?= htmlspecialchars($dv['produto_nome']) ?></td>
                        <td class="text-center fw-bold"><?= $dv['quantidade'] ?> <?= $dv['unidade_medida'] ?></td>
                        <td class="text-end">R$ <?= number_format($dv['valor_unitario'], 2, ',', '.') ?></td>
                        <td class="text-end val-bruto">R$ <?= number_format($dv['subtotal_bruto'], 2, ',', '.') ?></td>
                        <td class="text-end val-comissao">R$ <?= number_format($dv['comissao_valor'], 2, ',', '.') ?></td>
                        <td class="text-end fw-semibold val-liquido">R$ <?= number_format($dv['liquido_valor'], 2, ',', '.') ?></td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>

</div>
</body>
</html>