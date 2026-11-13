<?php
require_once __DIR__ . '/includes/header.php';

$lojaId = isset($_GET['loja_id']) ? (int)$_GET['loja_id'] : 0;

// Busca apenas as lojas parceiras para o select
$lojasParceiras = $pdo->query("
    SELECT * FROM locais_clientes 
    WHERE tipo = 'LOJA_PARCEIRA' 
    ORDER BY nome ASC
")->fetchAll();

// Dados da loja selecionada e os itens que estão lá no momento
$lojaSelecionada = null;
$itensConsignados = [];

if ($lojaId > 0) {
    $stmtLoja = $pdo->prepare("SELECT * FROM locais_clientes WHERE id = ? AND tipo = 'LOJA_PARCEIRA'");
    $stmtLoja->execute([$lojaId]);
    $lojaSelecionada = $stmtLoja->fetch();

    if ($lojaSelecionada) {
        $stmtItens = $pdo->prepare("
            SELECT 
                p.sku,
                p.nome AS produto_nome,
                p.unidade_medida,
                s.quantidade,
                p.preco_venda,
                (s.quantidade * p.preco_venda) AS subtotal
            FROM saldos_estoque s
            JOIN produtos p ON p.id = s.produto_id
            WHERE s.local_id = ? AND s.quantidade > 0
            ORDER BY p.nome ASC
        ");
        $stmtItens->execute([$lojaId]);
        $itensConsignados = $stmtItens->fetchAll();
    }
}
?>

<!-- ESTILOS EXCLUSIVOS PARA IMPRESSÃO (Oculta o menu e botões ao imprimir) -->
<style>
@media print {
    @page {
        size: A4;
        margin: 10mm; /* Margem compacta para caber tudo em 1 folha */
    }
    body {
        background-color: #fff !important;
        font-size: 12px !important; /* Reduz levemente a fonte para encaixe perfeito */
    }
    nav.navbar, .nao-imprimir {
        display: none !important;
    }
    .card {
        border: none !important;
        box-shadow: none !important;
        padding: 0 !important;
    }
    .container {
        max-width: 100% !important;
        padding: 0 !important;
        margin: 0 !important;
    }
    /* Compacta espaçamentos na impressão */
    .pb-4, .mb-4 {
        padding-bottom: 0.75rem !important;
        margin-bottom: 0.75rem !important;
    }
    .mt-5 {
        margin-top: 1.5rem !important;
    }
}

/* Evita quebras feias no SKU na tela e no PDF */
td code {
    white-space: nowrap;
    word-break: keep-all;
}
</style>

<!-- SELETOR DE LOJA (Não sai na impressão) -->
<div class="card shadow-sm mb-4 nao-imprimir">
    <div class="card-body d-flex flex-wrap justify-content-between align-items-center gap-3">
        <div>
            <h5 class="mb-1 fw-bold">📄 Romaneio de Entrega em Consignação</h5>
            <small class="text-muted">Selecione a loja parceira para gerar o recibo de carga atualizada.</small>
        </div>
        <form method="GET" action="romaneio.php" class="d-flex align-items-center gap-2">
            <select name="loja_id" class="form-select border-primary fw-semibold" required onchange="this.form.submit()">
                <option value="">Selecione a Loja Parceira...</option>
                <?php foreach ($lojasParceiras as $lp): ?>
                    <option value="<?= $lp['id'] ?>" <?= $lp['id'] === $lojaId ? 'selected' : '' ?>>
                        🏪 <?= htmlspecialchars($lp['nome']) ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </form>
    </div>
</div>

<?php if ($lojaSelecionada): ?>
    <!-- DOCUMENTO DE ROMANEIO (Área de Impressão) -->
    <div class="card shadow-sm p-4 bg-white">
        <!-- Cabeçalho do Recibo -->
        <div class="text-center border-bottom pb-4 mb-4">
            <h3 class="fw-bold text-dark">🖨️ TERMO DE REMESSA E CONSIGNAÇÃO DE PEÇAS</h3>
            <p class="text-muted mb-1">Comprovante de entrega de mercadorias para exposição e venda.</p>
            <span class="badge bg-dark fs-6 mt-1">Data de Emissão: <?= date('d/m/Y H:i') ?></span>
        </div>

        <!-- Dados das Partes -->
        <div class="row mb-4 g-3">
            <div class="col-md-6">
                <div class="p-3 border rounded bg-light h-100">
                    <h6 class="fw-bold text-uppercase text-secondary small mb-2">Fornecedor / Produtor:</h6>
                    <p class="mb-1 fw-bold">Oficina 3D (Estoque Principal)</p>
                    <p class="mb-0 text-muted small">Responsável: Produção de Peças Impressas</p>
                </div>
            </div>
            <div class="col-md-6">
                <div class="p-3 border rounded bg-light h-100">
                    <h6 class="fw-bold text-uppercase text-secondary small mb-2">Estabelecimento / Loja Parceira:</h6>
                    <p class="mb-1 fw-bold"><?= htmlspecialchars($lojaSelecionada['nome']) ?></p>
                    <p class="mb-1 text-muted small">Endereço: <?= htmlspecialchars($lojaSelecionada['endereco'] ?: 'Não informado') ?></p>
                    <p class="mb-0 text-muted small">
                        Contato: <?= htmlspecialchars($lojaSelecionada['telefone'] ?: '-') ?> | 
                        <strong>Comissão Acordada: <?= number_format($lojaSelecionada['comissao_percentual'], 1, ',', '.') ?>%</strong>
                    </p>
                </div>
            </div>
        </div>

        <!-- Tabela de Itens Consignados -->
        <div class="mb-4">
            <h6 class="fw-bold mb-3">📦 Relação de Itens Entregues / Em Exposição:</h6>
            <div class="table-responsive">
                <table class="table table-bordered align-middle mb-0">
                    <thead class="table-light">
                        <tr>
                            <th style="width: 10%;">SKU</th>
                            <th style="width: 45%;">Descrição do Produto</th>
                            <th class="text-center" style="width: 15%;">Quantidade</th>
                            <th class="text-end" style="width: 15%;">Preço Sugerido Un.</th>
                            <th class="text-end" style="width: 15%;">Subtotal (Bruto)</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php 
                        $totalPecas = 0;
                        $totalBruto = 0;
                        if (empty($itensConsignados)): 
                        ?>
                            <tr>
                                <td colspan="5" class="text-center text-muted py-4">Nenhum item registrado nesta loja no momento.</td>
                            </tr>
                        <?php endif; ?>
                        <?php foreach ($itensConsignados as $item): 
                            $totalPecas += $item['quantidade'];
                            $totalBruto += $item['subtotal'];
                        ?>
                            <tr>
                                <td><code><?= htmlspecialchars($item['sku'] ?? '-') ?></code></td>
                                <td class="fw-semibold"><?= htmlspecialchars($item['produto_nome']) ?></td>
                                <td class="text-center fw-bold"><?= $item['quantidade'] ?> <?= $item['unidade_medida'] ?></td>
                                <td class="text-end">R$ <?= number_format($item['preco_venda'], 2, ',', '.') ?></td>
                                <td class="text-end fw-semibold">R$ <?= number_format($item['subtotal'], 2, ',', '.') ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                    <?php if (!empty($itensConsignados)): ?>
                        <tfoot class="table-light">
                            <tr>
                                <td colspan="2" class="fw-bold text-end">TOTAIS DA CARGA:</td>
                                <td class="text-center fw-bold"><?= $totalPecas ?> un.</td>
                                <td colspan="2" class="text-end fw-bold text-success fs-5">R$ <?= number_format($totalBruto, 2, ',', '.') ?></td>
                            </tr>
                        </tfoot>
                    <?php endif; ?>
                </table>
            </div>
        </div>

        <!-- Observações e Condições -->
        <div class="p-3 border rounded mb-5 bg-light small text-muted">
            <strong>Condições da Consignação:</strong>
            <ul class="mb-0 ps-3">
                <li>As mercadorias acima descritas permanecem como propriedade do fornecedor até a efetiva venda ao consumidor final.</li>
                <li>A loja parceira compromete-se a zelar pela integridade dos produtos e repassar os valores devidos descontando a comissão acordada de <strong><?= number_format($lojaSelecionada['comissao_percentual'], 1, ',', '.') ?>%</strong> nas datas de acerto.</li>
                <li>Observações do local: <?= htmlspecialchars($lojaSelecionada['observacoes'] ?: 'Nenhuma observação cadastrada.') ?></li>
            </ul>
        </div>

        <!-- Campos de Assinatura -->
        <div class="row mt-5 pt-4 text-center">
            <div class="col-md-6 mb-4">
                <div class="border-top border-dark pt-2 w-75 mx-auto">
                    <p class="fw-bold mb-0">Oficina 3D (Fornecedor)</p>
                    <small class="text-muted">Assinatura do Responsável</small>
                </div>
            </div>
            <div class="col-md-6 mb-4">
                <div class="border-top border-dark pt-2 w-75 mx-auto">
                    <p class="fw-bold mb-0"><?= htmlspecialchars($lojaSelecionada['nome']) ?></p>
                    <small class="text-muted">Assinatura do Lojista / Recebedor</small>
                </div>
            </div>
        </div>

        <!-- Botão de Imprimir (Não sai na folha impressa) -->
        <div class="text-center mt-4 nao-imprimir">
            <button onclick="window.print()" class="btn btn-primary btn-lg px-5 fw-bold shadow">
                🖨️ Imprimir / Salvar PDF do Romaneio
            </button>
        </div>
    </div>

<?php else: ?>
    <div class="alert alert-info text-center py-5 shadow-sm">
        <h5>👆 Selecione uma loja parceira no campo acima para gerar o romaneio de entrega.</h5>
    </div>
<?php endif; ?>

</div>
</body>
</html>