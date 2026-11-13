<?php
require_once __DIR__ . '/includes/header.php';

// Filtros de Data (Padrão: Mês atual)
$dataInicio = $_GET['data_inicio'] ?? date('Y-m-01');
$dataFim    = $_GET['data_fim']    ?? date('Y-m-t');
$lojaFiltro = isset($_GET['loja_id']) ? (int)$_GET['loja_id'] : 0;

// Buscar lista de lojas para o filtro
$lojasParceiras = $pdo->query("SELECT * FROM locais_clientes WHERE tipo = 'LOJA_PARCEIRA' ORDER BY nome ASC")->fetchAll();

// 1. DADOS DE ACERTO POR LOJA NO PERÍODO
$sqlAcerto = "
    SELECT 
        l.id AS loja_id,
        l.nome AS loja_nome,
        l.comissao_percentual,
        l.telefone,
        l.observacoes,
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

// 2. EXTRATO DETALHADO DE VENDAS NO PERÍODO
$sqlVendas = "
    SELECT 
        m.*,
        p.nome AS produto_nome,
        p.sku,
        p.unidade_medida,
        l.nome AS loja_nome,
        l.comissao_percentual,
        (m.quantidade * m.valor_unitario) AS subtotal_bruto,
        ((m.quantidade * m.valor_unitario) * (l.comissao_percentual / 100.0)) AS comissao_valor,
        ((m.quantidade * m.valor_unitario) * (1 - (l.comissao_percentual / 100.0))) AS liquido_valor
    FROM movimentacoes m
    JOIN produtos p ON p.id = m.produto_id
    JOIN locais_clientes l ON l.id = m.local_origem_id
    WHERE m.tipo_movimento = 'SAIDA_VENDA' 
      AND l.tipo = 'LOJA_PARCEIRA'
      AND DATE(m.data_movimento) BETWEEN ? AND ?
";
$paramsVendas = [$dataInicio, $dataFim];
if ($lojaFiltro > 0) {
    $sqlVendas .= " AND l.id = ?";
    $paramsVendas[] = $lojaFiltro;
}
$sqlVendas .= " ORDER BY m.data_movimento DESC";
$stmtVendas = $pdo->prepare($sqlVendas);
$stmtVendas->execute($paramsVendas);
$detalheVendas = $stmtVendas->fetchAll();

// Totais gerais do período filtrado
$totaisGerais = [
    'bruto' => 0,
    'comissao' => 0,
    'liquido' => 0,
    'pecas' => 0
];
foreach ($acertosLojas as $al) {
    $totaisGerais['bruto'] += $al['valor_bruto'];
    $totaisGerais['comissao'] += $al['valor_comissao_loja'];
    $totaisGerais['liquido'] += $al['valor_liquido_receber'];
    $totaisGerais['pecas'] += $al['total_pecas_vendidas'];
}
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
                <input type="date" name="data_inicio" class="form-control" value="<?= $dataInicio ?>">
            </div>
            <div class="col-md-3">
                <label class="form-label fw-semibold">Data Final</label>
                <input type="date" name="data_fim" class="form-control" value="<?= $dataFim ?>">
            </div>
            <div class="col-md-4">
                <label class="form-label fw-semibold">Filtrar Loja Parceira</label>
                <select name="loja_id" class="form-select">
                    <option value="0">Todas as Lojas Parceiras</option>
                    <?php foreach ($lojasParceiras as $lp): ?>
                        <option value="<?= $lp['id'] ?>" <?= $lp['id'] === $lojaFiltro ? 'selected' : '' ?>>
                            <?= htmlspecialchars($lp['nome']) ?>
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

<!-- CABEÇALHO PARA IMPRESSÃO -->
<div class="text-center mb-4 d-none d-print-block">
    <h3 class="fw-bold">RELATÓRIO DE ACERTO DE CONTAS - CONSIGNADO</h3>
    <p class="text-muted">Período de: <?= date('d/m/Y', strtotime($dataInicio)) ?> até <?= date('d/m/Y', strtotime($dataFim)) ?></p>
</div>

<!-- PAINEL RESUMO DE ACERTO COM AS LOJAS -->
<div class="card shadow-sm mb-4">
    <div class="card-header bg-white d-flex justify-content-between align-items-center">
        <span class="fw-bold">📊 Acerto de Contas por Loja (Valores a Receber)</span>
        <button onclick="window.print()" class="btn btn-sm btn-outline-dark nao-imprimir">🖨️ Imprimir Extrato</button>
    </div>
    <div class="table-responsive">
        <table class="table table-hover align-middle mb-0">
            <thead class="table-light">
                <tr>
                    <th>Loja Parceira</th>
                    <th class="text-center">Peças Vendidas</th>
                    <th class="text-end">Venda Bruta (Total)</th>
                    <th class="text-end text-danger">Comissão (Fica c/ Loja)</th>
                    <th class="text-end text-success">Líquido a Receber (Seu)</th>
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
                        <td class="text-end text-nowrap">R$ <?= number_format($ac['valor_bruto'], 2, ',', '.') ?></td>
                        <td class="text-end text-danger text-nowrap">R$ <?= number_format($ac['valor_comissao_loja'], 2, ',', '.') ?></td>
                        <td class="text-end fw-bold text-success text-nowrap fs-5">R$ <?= number_format($ac['valor_liquido_receber'], 2, ',', '.') ?></td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
            <?php if (!empty($acertosLojas)): ?>
                <tfoot class="table-light">
                    <tr class="fw-bold">
                        <td>TOTAIS GERAIS:</td>
                        <td class="text-center"><?= $totaisGerais['pecas'] ?> un.</td>
                        <td class="text-end text-nowrap">R$ <?= number_format($totaisGerais['bruto'], 2, ',', '.') ?></td>
                        <td class="text-end text-danger text-nowrap">R$ <?= number_format($totaisGerais['comissao'], 2, ',', '.') ?></td>
                        <td class="text-end text-success text-nowrap fs-5">R$ <?= number_format($totaisGerais['liquido'], 2, ',', '.') ?></td>
                    </tr>
                </tfoot>
            <?php endif; ?>
        </table>
    </div>
</div>

<!-- EXTRATO DETALHADO DAS VENDAS NO PERÍODO -->
<div class="card shadow-sm">
    <div class="card-header bg-white fw-bold">
        📝 Detalhamento de Cada Venda Realizada nas Lojas no Período
    </div>
    <div class="table-responsive">
        <table class="table table-sm table-hover align-middle mb-0">
            <thead class="table-light">
                <tr>
                    <th>Data / Hora</th>
                    <th>Loja</th>
                    <th>Produto</th>
                    <th class="text-center">Qtd</th>
                    <th class="text-end">Preço Un.</th>
                    <th class="text-end">Bruto</th>
                    <th class="text-end text-danger">Comissão</th>
                    <th class="text-end text-success">Líquido</th>
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
                        <td class="fw-semibold"><?= htmlspecialchars($dv['loja_nome']) ?></td>
                        <td><?= htmlspecialchars($dv['produto_nome']) ?></td>
                        <td class="text-center fw-bold"><?= $dv['quantidade'] ?> <?= $dv['unidade_medida'] ?></td>
                        <td class="text-end">R$ <?= number_format($dv['valor_unitario'], 2, ',', '.') ?></td>
                        <td class="text-end">R$ <?= number_format($dv['subtotal_bruto'], 2, ',', '.') ?></td>
                        <td class="text-end text-danger">R$ <?= number_format($dv['comissao_valor'], 2, ',', '.') ?></td>
                        <td class="text-end fw-semibold text-success">R$ <?= number_format($dv['liquido_valor'], 2, ',', '.') ?></td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>

</div>
</body>
</html>