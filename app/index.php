<?php
require_once __DIR__ . '/includes/header.php';

// 1. INDICADORES DE VENDAS REALIZADAS (O que já foi vendido / Faturamento)
$vendasRealizadas = $pdo->query("
    SELECT 
        COALESCE(SUM(m.quantidade * m.valor_unitario), 0) AS bruto_vendas,
        COALESCE(SUM(
            CASE 
                WHEN l.tipo = 'LOJA_PARCEIRA' THEN (m.quantidade * m.valor_unitario) * (l.comissao_percentual / 100.0)
                ELSE 0 
            END
        ), 0) AS comissoes_totais,
        COALESCE(SUM(
            CASE 
                WHEN l.tipo = 'LOJA_PARCEIRA' THEN (m.quantidade * m.valor_unitario) * (1 - (l.comissao_percentual / 100.0))
                ELSE (m.quantidade * m.valor_unitario)
            END
        ), 0) AS liquido_vendas
    FROM movimentacoes m
    LEFT JOIN locais_clientes l ON l.id = m.local_origem_id
    WHERE m.tipo_movimento = 'SAIDA_VENDA'
")->fetch();

// 2. INDICADORES DE ESTOQUE ATUAL (Custos e Potencial nas Lojas)
$estoqueAtual = $pdo->query("
    SELECT 
        COALESCE(SUM(s.quantidade * p.custo_unitario), 0) AS custo_total_estoque,
        COALESCE(SUM(CASE WHEN l.tipo = 'LOJA_PARCEIRA' THEN s.quantidade * p.preco_venda ELSE 0 END), 0) AS potencial_bruto_lojas,
        COALESCE(SUM(CASE WHEN l.tipo = 'LOJA_PARCEIRA' THEN (s.quantidade * p.preco_venda) * (l.comissao_percentual / 100.0) ELSE 0 END), 0) AS potencial_comissao_lojas,
        COALESCE(SUM(CASE WHEN l.tipo = 'LOJA_PARCEIRA' THEN (s.quantidade * p.preco_venda) * (1 - (l.comissao_percentual / 100.0)) ELSE 0 END), 0) AS potencial_liquido_lojas,
        COALESCE(SUM(CASE WHEN l.tipo = 'LOJA_PARCEIRA' THEN s.quantidade ELSE 0 END), 0) AS total_pecas_consignadas
    FROM saldos_estoque s
    JOIN produtos p ON p.id = s.produto_id
    JOIN locais_clientes l ON l.id = s.local_id
    WHERE s.quantidade > 0
")->fetch();

// 3. ALERTAS DE ESTOQUE MÍNIMO (Oficina)
$alertasEstoque = $pdo->query("
    SELECT 
        p.id, p.sku, p.nome, p.unidade_medida, p.estoque_minimo, t.nome AS tipo_nome,
        COALESCE(SUM(CASE WHEN l.tipo = 'INTERNO' THEN s.quantidade ELSE 0 END), 0) AS saldo_interno
    FROM produtos p
    JOIN tipos_produto t ON t.id = p.tipo_id
    LEFT JOIN saldos_estoque s ON s.produto_id = p.id
    LEFT JOIN locais_clientes l ON l.id = s.local_id
    WHERE p.ativo = 1 AND p.estoque_minimo > 0
    GROUP BY p.id
    HAVING saldo_interno <= p.estoque_minimo
    ORDER BY (saldo_interno / p.estoque_minimo) ASC
")->fetchAll();

// 4. RESUMO POR LOJA PARCEIRA (ESTOQUE ATUAL + COMISSÃO DETALHADA EM R$)
$resumoLojas = $pdo->query("
    SELECT 
        l.id, l.nome, l.telefone, l.comissao_percentual, l.observacoes,
        COALESCE(SUM(s.quantidade), 0) AS total_pecas,
        COALESCE(SUM(s.quantidade * p.preco_venda), 0) AS valor_bruto,
        COALESCE(SUM((s.quantidade * p.preco_venda) * (l.comissao_percentual / 100.0)), 0) AS valor_comissao,
        COALESCE(SUM((s.quantidade * p.preco_venda) * (1 - (l.comissao_percentual / 100.0))), 0) AS valor_liquido
    FROM locais_clientes l
    LEFT JOIN saldos_estoque s ON s.local_id = l.id AND s.quantidade > 0
    LEFT JOIN produtos p ON p.id = s.produto_id
    WHERE l.tipo = 'LOJA_PARCEIRA'
    GROUP BY l.id
    ORDER BY valor_bruto DESC, l.nome ASC
")->fetchAll();

// 5. ITENS NAS LOJAS
$itensNasLojas = $pdo->query("
    SELECT 
        l.nome AS loja_nome, p.sku, p.nome AS produto_nome, s.quantidade, p.unidade_medida, p.preco_venda,
        (s.quantidade * p.preco_venda) AS subtotal_venda
    FROM saldos_estoque s
    JOIN locais_clientes l ON l.id = s.local_id
    JOIN produtos p ON p.id = s.produto_id
    WHERE l.tipo = 'LOJA_PARCEIRA' AND s.quantidade > 0
    ORDER BY l.nome ASC, p.nome ASC
")->fetchAll();

// 6. ÚLTIMAS MOVIMENTAÇÕES
$ultimasMovimentacoes = $pdo->query("
    SELECT 
        m.*, p.nome AS produto_nome, p.unidade_medida, lo.nome AS origem_nome, ld.nome AS destino_nome
    FROM movimentacoes m
    JOIN produtos p ON p.id = m.produto_id
    LEFT JOIN locais_clientes lo ON lo.id = m.local_origem_id
    LEFT JOIN locais_clientes ld ON ld.id = m.local_destino_id
    ORDER BY m.data_movimento DESC, m.id DESC
    LIMIT 5
")->fetchAll();
?>

<!-- SEÇÃO 1: CAIXA / VENDAS REALIZADAS (O que já entrou de dinheiro) -->
<div class="card shadow-sm mb-4">
    <div class="card-header bg-white fw-bold">
        💰 Desempenho de Vendas Realizadas (Histórico de Saídas / Caixa)
    </div>
    <div class="card-body">
        <div class="row text-center g-3">
            <div class="col-md-4 border-end">
                <span class="text-muted small fw-semibold text-uppercase">Total de Vendas (Bruto)</span>
                <div class="fs-3 val-bruto mt-1">
                    R$ <?= number_format($vendasRealizadas['bruto_vendas'], 2, ',', '.') ?>
                </div>
                <small class="text-muted">Soma de todas as saídas por venda</small>
            </div>
            <div class="col-md-4 border-end">
                <span class="text-muted small fw-semibold text-uppercase">Comissões Destinadas às Lojas</span>
                <div class="fs-3 val-comissao mt-1">
                    R$ <?= number_format($vendasRealizadas['comissoes_totais'], 2, ',', '.') ?>
                </div>
                <small class="text-muted">Valor retido pelos parceiros nas vendas</small>
            </div>
            <div class="col-md-4">
                <span class="text-muted small fw-semibold text-uppercase">Seu Líquido Realizado</span>
                <div class="fs-3 val-liquido mt-1">
                    R$ <?= number_format($vendasRealizadas['liquido_vendas'], 2, ',', '.') ?>
                </div>
                <small class="text-muted">Dinheiro efetivo gerado para você</small>
            </div>
        </div>
    </div>
</div>

<!-- SEÇÃO 2: CARDS DE ESTOQUE ATUAL E POTENCIAL -->
<div class="row g-3 mb-4">
    <div class="col-md-4">
        <div class="card shadow-sm h-100 p-3">
            <div class="text-muted small fw-semibold text-uppercase">Custo Total em Estoque</div>
            <div class="fs-4 val-custo mt-1">
                R$ <?= number_format($estoqueAtual['custo_total_estoque'], 2, ',', '.') ?>
            </div>
            <small class="text-muted">Capital investido em materiais/peças</small>
        </div>
    </div>

    <div class="col-md-4">
        <div class="card shadow-sm h-100 p-3">
            <div class="text-muted small fw-semibold text-uppercase">Potencial Bruto nas Lojas</div>
            <div class="fs-4 val-bruto mt-1">
                R$ <?= number_format($estoqueAtual['potencial_bruto_lojas'], 2, ',', '.') ?> 
                <span class="fs-6 fw-normal text-muted">(<?= number_format($estoqueAtual['total_pecas_consignadas'], 0, ',', '.') ?> un.)</span>
            </div>
            <small class="text-muted">Valor de tabela das peças em exposição</small>
        </div>
    </div>

    <div class="col-md-4">
        <div class="card shadow-sm h-100 p-3">
            <div class="text-muted small fw-semibold text-uppercase">Alertas de Estoque Baixo</div>
            <div class="fs-4 val-alerta mt-1">
                <?= count($alertasEstoque) ?> <span class="fs-6 fw-normal">itens</span>
            </div>
            <small class="text-muted">Abaixo do mínimo na oficina</small>
        </div>
    </div>
</div>

<div class="row g-4">
    <!-- COLUNA ESQUERDA: RESUMO DE CONSIGNADO COM COMISSÃO DETALHADA EM R$ -->
    <div class="col-lg-7">
        <div class="card shadow-sm mb-4">
            <div class="card-header bg-white d-flex justify-content-between align-items-center">
                <span class="fw-bold">🏪 Resumo de Consignado por Loja (Estoque Atual)</span>
                <a href="locais.php" class="btn btn-sm btn-outline-light">Gerenciar Lojas</a>
            </div>
            <div class="table-responsive">
                <table class="table table-hover align-middle mb-0">
                    <thead class="table-light">
                        <tr>
                            <th style="width: 28%;">Loja Parceira</th>
                            <th class="text-center" style="width: 15%;">Peças disponíveis</th>
                            <th class="text-end" style="width: 19%;">Venda Bruta prevista</th>
                            <th class="text-end" style="width: 19%;">Comissão (Loja)</th>
                            <th class="text-end" style="width: 19%;">Saldo Líquido previsto</th>
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
                                    <div class="fw-semibold text-truncate" style="max-width: 180px;" title="<?= htmlspecialchars($loja['nome']) ?>">
                                        <?= htmlspecialchars($loja['nome']) ?>
                                    </div>
                                    <small class="text-muted">Comissão: <?= number_format($loja['comissao_percentual'], 1, ',', '.') ?>%</small>
                                </td>
                                <td class="text-center">
                                    <span class="badge bg-light text-dark fs-6">
                                        <?= $loja['total_pecas'] ?>
                                    </span>
                                </td>
                                <td class="text-end text-nowrap val-bruto">R$ <?= number_format($loja['valor_bruto'], 2, ',', '.') ?></td>
                                <td class="text-end text-nowrap val-comissao">R$ <?= number_format($loja['valor_comissao'], 2, ',', '.') ?></td>
                                <td class="text-end text-nowrap val-liquido">R$ <?= number_format($loja['valor_liquido'], 2, ',', '.') ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>

        <!-- Peças Atualmente nas Lojas -->
        <div class="card shadow-sm">
            <div class="card-header bg-white d-flex justify-content-between align-items-center">
                <span class="fw-bold">📦 Peças Expostas nas Lojas no Momento</span>
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
                                    Nenhuma peça nas lojas no momento (estoque atual zerado nas vitrines).
                                </td>
                            </tr>
                        <?php endif; ?>
                        <?php foreach ($itensNasLojas as $item): ?>
                            <tr>
                                <td class="fw-semibold"><?= htmlspecialchars($item['loja_nome']) ?></td>
                                <td><?= htmlspecialchars($item['produto_nome']) ?></td>
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

    <!-- COLUNA DIREITA: ALERTAS DE ESTOQUE + ÚLTIMAS MOVIMENTAÇÕES -->
    <div class="col-lg-5">
        <!-- Alertas -->
        <div class="card shadow-sm mb-4">
            <div class="card-header bg-white d-flex justify-content-between align-items-center">
                <span class="fw-bold">⚠️ Alerta de Estoque Mínimo (Oficina)</span>
                <a href="produtos.php" class="btn btn-sm btn-outline-light">Ver Produtos</a>
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
                                    ✅ Tudo em dia! Nenhum item abaixo do mínimo.
                                </td>
                            </tr>
                        <?php endif; ?>
                        <?php foreach ($alertasEstoque as $alerta): ?>
                            <tr>
                                <td>
                                    <div class="fw-semibold"><?= htmlspecialchars($alerta['nome']) ?></div>
                                    <small class="text-muted"><?= htmlspecialchars($alerta['tipo_nome']) ?></small>
                                </td>
                                <td class="text-center fw-bold val-alerta"><?= $alerta['saldo_interno'] ?> <?= $alerta['unidade_medida'] ?></td>
                                <td class="text-center text-muted"><?= $alerta['estoque_minimo'] ?> <?= $alerta['unidade_medida'] ?></td>
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
                <a href="movimentacoes.php" class="btn btn-sm btn-outline-light">Ver Todas</a>
            </div>
            <ul class="list-group list-group-flush">
                <?php if (empty($ultimasMovimentacoes)): ?>
                    <li class="list-group-item text-center text-muted py-3">Nenhuma movimentação registrada.</li>
                <?php endif; ?>
                <?php foreach ($ultimasMovimentacoes as $mov): ?>
                    <li class="list-group-item">
                        <div class="d-flex justify-content-between align-items-center">
                            <strong><?= htmlspecialchars($mov['produto_nome']) ?></strong>
                            <span class="badge bg-secondary"><?= $mov['tipo_movimento'] ?></span>
                        </div>
                        <div class="small text-muted mt-1">
                            Qtd: <strong><?= $mov['quantidade'] ?> <?= $mov['unidade_medida'] ?></strong>
                            <?php if ($mov['origem_nome']): ?> | Saiu de: <?= htmlspecialchars($mov['origem_nome']) ?><?php endif; ?>
                            <?php if ($mov['destino_nome']): ?> | Foi para: <?= htmlspecialchars($mov['destino_nome']) ?><?php endif; ?>
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