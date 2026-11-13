<?php
require_once __DIR__ . '/includes/header.php';

// 1. INDICADORES GERAIS (KPIs)
$kpis = $pdo->query("
    SELECT 
        -- Valor total de custo de tudo que existe em qualquer estoque
        COALESCE(SUM(s.quantidade * p.custo_unitario), 0) AS custo_total_estoque,
        
        -- Valor de venda bruto apenas do que está nas Lojas Parceiras (Consignado)
        COALESCE(SUM(CASE WHEN l.tipo = 'LOJA_PARCEIRA' THEN s.quantidade * p.preco_venda ELSE 0 END), 0) AS valor_bruto_consignado,
        
        -- Valor líquido a receber das lojas (já descontando a comissão de cada loja)
        COALESCE(SUM(CASE WHEN l.tipo = 'LOJA_PARCEIRA' THEN (s.quantidade * p.preco_venda) * (1 - (l.comissao_percentual / 100.0)) ELSE 0 END), 0) AS valor_liquido_consignado,
        
        -- Quantidade total de peças em lojas parceiras
        COALESCE(SUM(CASE WHEN l.tipo = 'LOJA_PARCEIRA' THEN s.quantidade ELSE 0 END), 0) AS total_pecas_consignadas
    FROM saldos_estoque s
    JOIN produtos p ON p.id = s.produto_id
    JOIN locais_clientes l ON l.id = s.local_id
    WHERE s.quantidade > 0
")->fetch();

// 2. ALERTAS DE ESTOQUE MÍNIMO (Considera o saldo na Oficina Interna, ID = 1, ou saldo geral)
$alertasEstoque = $pdo->query("
    SELECT 
        p.id,
        p.sku,
        p.nome,
        p.unidade_medida,
        p.estoque_minimo,
        t.nome AS tipo_nome,
        COALESCE(SUM(CASE WHEN l.tipo = 'INTERNO' THEN s.quantidade ELSE 0 END), 0) AS saldo_interno,
        COALESCE(SUM(s.quantidade), 0) AS saldo_geral
    FROM produtos p
    JOIN tipos_produto t ON t.id = p.tipo_id
    LEFT JOIN saldos_estoque s ON s.produto_id = p.id
    LEFT JOIN locais_clientes l ON l.id = s.local_id
    WHERE p.ativo = 1 AND p.estoque_minimo > 0
    GROUP BY p.id
    HAVING saldo_interno <= p.estoque_minimo
    ORDER BY (saldo_interno / p.estoque_minimo) ASC
")->fetchAll();

// 3. RESUMO POR LOJA PARCEIRA (CONSIGNADO)
$resumoLojas = $pdo->query("
    SELECT 
        l.id,
        l.nome,
        l.telefone,
        l.comissao_percentual,
        l.observacoes,
        COALESCE(SUM(s.quantidade), 0) AS total_pecas,
        COALESCE(SUM(s.quantidade * p.preco_venda), 0) AS valor_bruto,
        COALESCE(SUM((s.quantidade * p.preco_venda) * (1 - (l.comissao_percentual / 100.0))), 0) AS valor_liquido
    FROM locais_clientes l
    LEFT JOIN saldos_estoque s ON s.local_id = l.id AND s.quantidade > 0
    LEFT JOIN produtos p ON p.id = s.produto_id
    WHERE l.tipo = 'LOJA_PARCEIRA'
    GROUP BY l.id
    ORDER BY valor_bruto DESC, l.nome ASC
")->fetchAll();

// 4. DETALHAMENTO DE ITENS CONSIGNADOS NAS LOJAS (O que está em cada loja agora)
$itensNasLojas = $pdo->query("
    SELECT 
        l.nome AS loja_nome,
        p.sku,
        p.nome AS produto_nome,
        s.quantidade,
        p.unidade_medida,
        p.preco_venda,
        (s.quantidade * p.preco_venda) AS subtotal_venda
    FROM saldos_estoque s
    JOIN locais_clientes l ON l.id = s.local_id
    JOIN produtos p ON p.id = s.produto_id
    WHERE l.tipo = 'LOJA_PARCEIRA' AND s.quantidade > 0
    ORDER BY l.nome ASC, p.nome ASC
")->fetchAll();

// 5. ÚLTIMAS 5 MOVIMENTAÇÕES
$ultimasMovimentacoes = $pdo->query("
    SELECT 
        m.*,
        p.nome AS produto_nome,
        p.unidade_medida,
        lo.nome AS origem_nome,
        ld.nome AS destino_nome
    FROM movimentacoes m
    JOIN produtos p ON p.id = m.produto_id
    LEFT JOIN locais_clientes lo ON lo.id = m.local_origem_id
    LEFT JOIN locais_clientes ld ON ld.id = m.local_destino_id
    ORDER BY m.data_movimento DESC, m.id DESC
    LIMIT 5
")->fetchAll();
?>

<!-- 1. CARDS DE INDICADORES (KPIs) -->
<div class="row g-3 mb-4">
    <div class="col-md-3">
        <div class="card shadow-sm border-0 border-start border-primary border-4 h-100">
            <div class="card-body">
                <div class="text-muted small fw-semibold text-uppercase">Custo Total em Estoque</div>
                <div class="fs-4 fw-bold text-dark mt-1">
                    R$ <?= number_format($kpis['custo_total_estoque'], 2, ',', '.') ?>
                </div>
                <small class="text-muted">Soma de insumos + peças prontas</small>
            </div>
        </div>
    </div>

    <div class="col-md-3">
        <div class="card shadow-sm border-0 border-start border-warning border-4 h-100">
            <div class="card-body">
                <div class="text-muted small fw-semibold text-uppercase">Peças em Consignado</div>
                <div class="fs-4 fw-bold text-dark mt-1">
                    <?= number_format($kpis['total_pecas_consignadas'], 0, ',', '.') ?> <span class="fs-6 fw-normal">un.</span>
                </div>
                <small class="text-muted">Bruto nas lojas: R$ <?= number_format($kpis['valor_bruto_consignado'], 2, ',', '.') ?></small>
            </div>
        </div>
    </div>

    <div class="col-md-3">
        <div class="card shadow-sm border-0 border-start border-success border-4 h-100">
            <div class="card-body">
                <div class="text-muted small fw-semibold text-uppercase">Líquido Estimado (Lojas)</div>
                <div class="fs-4 fw-bold text-success mt-1">
                    R$ <?= number_format($kpis['valor_liquido_consignado'], 2, ',', '.') ?>
                </div>
                <small class="text-muted">Já descontando comissões</small>
            </div>
        </div>
    </div>

    <div class="col-md-3">
        <div class="card shadow-sm border-0 border-start border-danger border-4 h-100">
            <div class="card-body">
                <div class="text-muted small fw-semibold text-uppercase">Alertas de Estoque Baixo</div>
                <div class="fs-4 fw-bold <?= count($alertasEstoque) > 0 ? 'text-danger' : 'text-success' ?> mt-1">
                    <?= count($alertasEstoque) ?> <span class="fs-6 fw-normal">itens</span>
                </div>
                <small class="text-muted">Abaixo do mínimo na oficina</small>
            </div>
        </div>
    </div>
</div>

<div class="row g-4">
    <!-- 2. COLUNA ESQUERDA: RESUMO DE LOJAS CONSIGNADAS + DETALHE DE ITENS -->
    <div class="col-lg-7">
        <!-- Resumo por Loja Parceira -->
        <div class="card shadow-sm mb-4">
            <div class="card-header bg-white d-flex justify-content-between align-items-center">
                <span class="fw-bold">🏪 Resumo de Consignado por Loja Parceira</span>
                <a href="locais.php" class="btn btn-sm btn-outline-secondary">Gerenciar Lojas</a>
            </div>
            <div class="table-responsive">
                <table class="table table-hover align-middle mb-0">
                    <thead class="table-light">
                        <tr>
                            <th>Loja Parceira</th>
                            <th class="text-center">Peças Lá</th>
                            <th class="text-center">Comissão</th>
                            <th class="text-end">Venda Bruta</th>
                            <th class="text-end">Seu Líquido</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($resumoLojas)): ?>
                            <tr>
                                <td colspan="5" class="text-center text-muted py-3">Nenhuma loja parceira cadastrada.</td>
                            </tr>
                        <?php endif; ?>
                        <?php foreach ($resumoLojas as $loja): ?>
                            <tr>
                                <td>
                                    <div class="fw-semibold"><?= htmlspecialchars($loja['nome']) ?></div>
                                    <small class="text-muted"><?= htmlspecialchars($loja['observacoes'] ?: $loja['telefone']) ?></small>
                                </td>
                                <td class="text-center">
                                    <span class="badge <?= $loja['total_pecas'] > 0 ? 'bg-warning text-dark' : 'bg-secondary' ?> fs-6">
                                        <?= $loja['total_pecas'] ?>
                                    </span>
                                </td>
                                <td class="text-center"><?= number_format($loja['comissao_percentual'], 1, ',', '.') ?>%</td>
                                <td class="text-end">R$ <?= number_format($loja['valor_bruto'], 2, ',', '.') ?></td>
                                <td class="text-end fw-bold text-success">R$ <?= number_format($loja['valor_liquido'], 2, ',', '.') ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>

        <!-- Detalhamento de Peças que estão nas Lojas agora -->
        <div class="card shadow-sm">
            <div class="card-header bg-white d-flex justify-content-between align-items-center">
                <span class="fw-bold">📦 Peças Atualmente nas Lojas (Em Exposição)</span>
                <a href="movimentacoes.php" class="btn btn-sm btn-primary">+ Nova Remessa / Baixa</a>
            </div>
            <div class="table-responsive">
                <table class="table table-sm table-hover align-middle mb-0">
                    <thead class="table-light">
                        <tr>
                            <th>Loja</th>
                            <th>Produto</th>
                            <th class="text-center">Qtd</th>
                            <th class="text-end">Preço Un.</th>
                            <th class="text-end">Subtotal</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($itensNasLojas)): ?>
                            <tr>
                                <td colspan="5" class="text-center text-muted py-4">
                                    Nenhuma peça enviada para lojas parceiras no momento.
                                </td>
                            </tr>
                        <?php endif; ?>
                        <?php foreach ($itensNasLojas as $item): ?>
                            <tr>
                                <td class="fw-semibold"><?= htmlspecialchars($item['loja_nome']) ?></td>
                                <td>
                                    <?= htmlspecialchars($item['produto_nome']) ?>
                                    <?php if ($item['sku']): ?>
                                        <small class="text-muted">(<?= htmlspecialchars($item['sku']) ?>)</small>
                                    <?php endif; ?>
                                </td>
                                <td class="text-center fw-bold"><?= $item['quantidade'] ?> <?= $item['unidade_medida'] ?></td>
                                <td class="text-end">R$ <?= number_format($item['preco_venda'], 2, ',', '.') ?></td>
                                <td class="text-end fw-semibold">R$ <?= number_format($item['subtotal_venda'], 2, ',', '.') ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <!-- 3. COLUNA DIREITA: ALERTAS DE ESTOQUE MÍNIMO + ÚLTIMAS MOVIMENTAÇÕES -->
    <div class="col-lg-5">
        <!-- Alertas de Estoque Mínimo -->
        <div class="card shadow-sm mb-4 border-danger">
            <div class="card-header bg-danger text-white d-flex justify-content-between align-items-center">
                <span class="fw-bold">⚠️ Alerta de Estoque Mínimo (Oficina)</span>
                <a href="produtos.php" class="btn btn-sm btn-light">Ver Produtos</a>
            </div>
            <div class="table-responsive">
                <table class="table table-hover align-middle mb-0">
                    <thead class="table-light">
                        <tr>
                            <th>Item / Material</th>
                            <th class="text-center">Na Oficina</th>
                            <th class="text-center">Mínimo</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($alertasEstoque)): ?>
                            <tr>
                                <td colspan="3" class="text-center text-success py-4 fw-semibold">
                                    ✅ Tudo em dia! Nenhum item abaixo do estoque mínimo.
                                </td>
                            </tr>
                        <?php endif; ?>
                        <?php foreach ($alertasEstoque as $alerta): ?>
                            <tr>
                                <td>
                                    <div class="fw-semibold text-danger"><?= htmlspecialchars($alerta['nome']) ?></div>
                                    <small class="text-muted"><?= htmlspecialchars($alerta['tipo_nome']) ?></small>
                                </td>
                                <td class="text-center fw-bold text-danger">
                                    <?= $alerta['saldo_interno'] ?> <?= $alerta['unidade_medida'] ?>
                                </td>
                                <td class="text-center text-muted">
                                    <?= $alerta['estoque_minimo'] ?> <?= $alerta['unidade_medida'] ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>

        <!-- Últimas Movimentações -->
        <div class="card shadow-sm">
            <div class="card-header bg-white d-flex justify-content-between align-items-center">
                <span class="fw-bold">🕒 Últimas Movimentações</span>
                <a href="movimentacoes.php" class="btn btn-sm btn-outline-primary">Ver Todas</a>
            </div>
            <ul class="list-group list-group-flush">
                <?php if (empty($ultimasMovimentacoes)): ?>
                    <li class="list-group-item text-center text-muted py-3">
                        Nenhuma movimentação registrada ainda.
                    </li>
                <?php endif; ?>
                <?php foreach ($ultimasMovimentacoes as $mov): ?>
                    <li class="list-group-item">
                        <div class="d-flex justify-content-between align-items-center">
                            <strong><?= htmlspecialchars($mov['produto_nome']) ?></strong>
                            <span class="badge bg-dark"><?= $mov['tipo_movimento'] ?></span>
                        </div>
                        <div class="small text-muted mt-1">
                            Qtd: <strong><?= $mov['quantidade'] ?> <?= $mov['unidade_medida'] ?></strong>
                            <?php if ($mov['origem_nome']): ?>
                                | Saiu de: <?= htmlspecialchars($mov['origem_nome']) ?>
                            <?php endif; ?>
                            <?php if ($mov['destino_nome']): ?>
                                | Foi para: <?= htmlspecialchars($mov['destino_nome']) ?>
                            <?php endif; ?>
                        </div>
                    </li>
                <?php endforeach; ?>
            </ul>
        </div>
    </div>
</div>

</div>
</body>
</html>