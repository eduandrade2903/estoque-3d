<?php
require_once __DIR__ . '/includes/header.php';

$mensagem = '';
$erro = '';

// PROCESSAR NOVA MOVIMENTAÇÃO
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['acao']) && $_POST['acao'] === 'registrar_movimento') {
    $tipo_movimento   = $_POST['tipo_movimento'] ?? '';
    $produto_id       = (int)($_POST['produto_id'] ?? 0);
    $local_origem_id  = !empty($_POST['local_origem_id']) ? (int)$_POST['local_origem_id'] : null;
    $local_destino_id = !empty($_POST['local_destino_id']) ? (int)$_POST['local_destino_id'] : null;
    $quantidade       = moedaParaFloat($_POST['quantidade'] ?? '0');
    $valor_unitario   = moedaParaFloat($_POST['valor_unitario'] ?? '0');
    $observacao       = trim($_POST['observacao'] ?? '');

    // Normaliza origem e destino conforme o tipo de operação para evitar erros de preenchimento
    if ($tipo_movimento === 'ENTRADA') {
        $local_origem_id = null;
    } elseif (in_array($tipo_movimento, ['SAIDA_VENDA', 'CONSUMO_PRODUCAO', 'PERDA_FALHA'])) {
        $local_destino_id = null;
    }

    // Validações de Regra de Negócio
    if ($produto_id <= 0 || $quantidade <= 0) {
        $erro = "Selecione um produto válido e informe uma quantidade maior que zero.";
    } elseif ($tipo_movimento === 'ENTRADA' && !$local_destino_id) {
        $erro = "Para registrar uma ENTRADA, selecione o Local de Destino (ex: Oficina).";
    } elseif (in_array($tipo_movimento, ['SAIDA_VENDA', 'CONSUMO_PRODUCAO', 'PERDA_FALHA']) && !$local_origem_id) {
        $erro = "Para registrar uma SAÍDA, VENDA ou PERDA, selecione de qual Local o item está saindo.";
    } elseif ($tipo_movimento === 'TRANSFERENCIA' && (!$local_origem_id || !$local_destino_id)) {
        $erro = "Na TRANSFERÊNCIA / REMESSA, selecione tanto o Local de Origem quanto o Local de Destino.";
    } elseif ($tipo_movimento === 'TRANSFERENCIA' && $local_origem_id === $local_destino_id) {
        $erro = "O Local de Origem e o Local de Destino não podem ser o mesmo.";
    } else {
        // Se houver saída de algum local, verifica se há saldo suficiente nesse local específico
        if ($local_origem_id !== null) {
            $stmtSaldo = $pdo->prepare("SELECT COALESCE(quantidade, 0) FROM saldos_estoque WHERE produto_id = ? AND local_id = ?");
            $stmtSaldo->execute([$produto_id, $local_origem_id]);
            $saldoAtual = (float)$stmtSaldo->fetchColumn();

            if ($saldoAtual < $quantidade) {
                $erro = "Saldo insuficiente no local de origem! Disponível no momento: {$saldoAtual}.";
            }
        }

        // Se passou por todas as validações, grava a movimentação (as Triggers atualizam saldos_estoque)
        if ($erro === '') {
            try {
                $pdo->beginTransaction();

                // Se não informou valor unitário manual, puxa custo (para entrada/perda) ou preço de venda (para venda/transferência)
                if ($valor_unitario <= 0) {
                    $stmtProd = $pdo->prepare("SELECT custo_unitario, preco_venda FROM produtos WHERE id = ?");
                    $stmtProd->execute([$produto_id]);
                    $dadosProd = $stmtProd->fetch();
                    if ($dadosProd) {
                        $valor_unitario = in_array($tipo_movimento, ['SAIDA_VENDA', 'TRANSFERENCIA'])
                            ? (float)$dadosProd['preco_venda']
                            : (float)$dadosProd['custo_unitario'];
                    }
                }

                $stmt = $pdo->prepare("
                    INSERT INTO movimentacoes 
                        (produto_id, local_origem_id, local_destino_id, tipo_movimento, quantidade, valor_unitario, observacao)
                    VALUES (?, ?, ?, ?, ?, ?, ?)
                ");
                $stmt->execute([
                    $produto_id,
                    $local_origem_id,
                    $local_destino_id,
                    $tipo_movimento,
                    $quantidade,
                    $valor_unitario,
                    $observacao
                ]);

                $pdo->commit();
                $mensagem = "Movimentação registrada e saldo atualizado com sucesso!";
            } catch (PDOException $e) {
                $pdo->rollBack();
                $erro = "Erro ao registrar movimentação: " . $e->getMessage();
            }
        }
    }
}

// Carregar produtos ativos e locais para os selects
$produtos = $pdo->query("
    SELECT p.*, t.nome AS tipo_nome 
    FROM produtos p 
    JOIN tipos_produto t ON t.id = p.tipo_id 
    WHERE p.ativo = 1 
    ORDER BY p.nome ASC
")->fetchAll();

$locais = $pdo->query("
    SELECT * FROM locais_clientes 
    WHERE tipo IN ('INTERNO', 'LOJA_PARCEIRA') 
    ORDER BY CASE WHEN tipo = 'INTERNO' THEN 0 ELSE 1 END, nome ASC
")->fetchAll();

// Carregar saldos atuais detalhados por local (apenas onde saldo != 0)
$saldosPorLocal = $pdo->query("
    SELECT 
        l.nome AS local_nome,
        l.tipo AS local_tipo,
        p.nome AS produto_nome,
        p.unidade_medida,
        s.quantidade
    FROM saldos_estoque s
    JOIN produtos p ON p.id = s.produto_id
    JOIN locais_clientes l ON l.id = s.local_id
    WHERE s.quantidade != 0
    ORDER BY l.tipo ASC, l.nome ASC, p.nome ASC
")->fetchAll();

// Histórico das últimas 30 movimentações
$historico = $pdo->query("
    SELECT 
        m.*,
        p.nome AS produto_nome,
        p.sku,
        p.unidade_medida,
        lo.nome AS origem_nome,
        ld.nome AS destino_nome
    FROM movimentacoes m
    JOIN produtos p ON p.id = m.produto_id
    LEFT JOIN locais_clientes lo ON lo.id = m.local_origem_id
    LEFT JOIN locais_clientes ld ON ld.id = m.local_destino_id
    ORDER BY m.data_movimento DESC, m.id DESC
    LIMIT 30
")->fetchAll();
?>

<?php if ($mensagem): ?>
    <div class="alert alert-success alert-dismissible fade show"><?= htmlspecialchars($mensagem) ?></div>
<?php endif; ?>
<?php if ($erro): ?>
    <div class="alert alert-danger alert-dismissible fade show"><?= htmlspecialchars($erro) ?></div>
<?php endif; ?>

<div class="row g-4 mb-4">
    <!-- FORMULÁRIO DE NOVA MOVIMENTAÇÃO -->
    <div class="col-lg-7">
        <div class="card shadow-sm h-100">
            <div class="card-header bg-white fw-bold">
                🔄 Registrar Nova Movimentação de Estoque
            </div>
            <div class="card-body">
                <form method="POST" action="movimentacoes.php" id="formMovimentacao">
                    <input type="hidden" name="acao" value="registrar_movimento">

                    <div class="row g-3">
                        <!-- Tipo de Operação -->
                        <div class="col-md-12">
                            <label class="form-label fw-semibold">Tipo de Operação *</label>
                            <select name="tipo_movimento" id="tipo_movimento" class="form-select border-primary" required onchange="ajustarCamposLocais()">
                                <option value="ENTRADA">🟢 ENTRADA — Compra de Filamento/Insumo ou Nova Peça Impressa</option>
                                <option value="TRANSFERENCIA">🚚 TRANSFERÊNCIA / REMESSA — Enviar p/ Loja Parceira (ou Devolução)</option>
                                <option value="SAIDA_VENDA">💰 SAÍDA / VENDA — Venda Direta ou Acerto de Peça Vendida na Loja</option>
                                <option value="CONSUMO_PRODUCAO">🧵 CONSUMO DE PRODUÇÃO — Baixa de Filamento/Resina usado na impressão</option>
                                <option value="PERDA_FALHA">⚠️ PERDA / FALHA — Falha de impressão ou peça danificada</option>
                            </select>
                        </div>

                        <!-- Produto -->
                        <div class="col-md-8">
                            <label class="form-label">Produto / Material *</label>
                            <select name="produto_id" id="produto_id" class="form-select" required onchange="preencherValorSugerido()">
                                <option value="">Selecione um item...</option>
                                <?php foreach ($produtos as $p): ?>
                                    <option value="<?= $p['id'] ?>" 
                                            data-custo="<?= $p['custo_unitario'] ?>" 
                                            data-venda="<?= $p['preco_venda'] ?>"
                                            data-unidade="<?= $p['unidade_medida'] ?>">
                                        <?= htmlspecialchars($p['nome']) ?> (<?= $p['unidade_medida'] ?>) — [<?= htmlspecialchars($p['tipo_nome']) ?>]
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <!-- Quantidade -->
                        <div class="col-md-4">
                            <label class="form-label">Quantidade *</label>
                            <div class="input-group">
                                <input type="number" step="0.01" min="0.01" name="quantidade" class="form-control" required placeholder="0.00">
                                <span class="input-group-text" id="label_unidade">UN</span>
                            </div>
                        </div>

                        <!-- Local de Origem -->
                        <div class="col-md-6" id="bloco_origem" style="display: none;">
                            <label class="form-label text-danger fw-semibold">📤 Local de Origem (Saiu de onde?) *</label>
                            <select name="local_origem_id" id="local_origem_id" class="form-select">
                                <option value="">Selecione a origem...</option>
                                <?php foreach ($locais as $l): ?>
                                    <option value="<?= $l['id'] ?>">
                                        <?= htmlspecialchars($l['nome']) ?> (<?= $l['tipo'] === 'INTERNO' ? 'Interno' : 'Consignado' ?>)
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <!-- Local de Destino -->
                        <div class="col-md-6" id="bloco_destino">
                            <label class="form-label text-success fw-semibold">📥 Local de Destino (Entrou onde?) *</label>
                            <select name="local_destino_id" id="local_destino_id" class="form-select">
                                <?php foreach ($locais as $l): ?>
                                    <option value="<?= $l['id'] ?>">
                                        <?= htmlspecialchars($l['nome']) ?> (<?= $l['tipo'] === 'INTERNO' ? 'Interno' : 'Consignado' ?>)
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <!-- Valor Unitário e Observação -->
                        <div class="col-md-4">
                            <label class="form-label">Valor Unitário</label>
                            <div class="input-group">
                                <span class="input-group-text">R$</span>
                                <input type="text" name="valor_unitario" id="valor_unitario" class="form-control moeda-brl" value="0,00">
                            </div>
                            <small class="text-muted">Puxa automático do produto</small>
                        </div>

                        <div class="col-md-8">
                            <label class="form-label">Observação / Lote / Nota</label>
                            <input type="text" name="observacao" class="form-control" placeholder="Ex: Remessa #02 deixada com o gerente / Impressão de 12h">
                        </div>
                    </div>

                    <div class="mt-4">
                        <button type="submit" class="btn btn-primary w-100 fw-semibold">
                            Confirmar e Atualizar Estoque
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- CONSULTA RÁPIDA DE SALDOS POR LOCAL -->
    <div class="col-lg-5">
        <div class="card shadow-sm h-100">
            <div class="card-header bg-white fw-bold">
                📍 Posição Atual de Estoque por Local
            </div>
            <div class="table-responsive" style="max-height: 380px; overflow-y: auto;">
                <table class="table table-sm table-hover align-middle mb-0">
                    <thead class="table-light sticky-top">
                        <tr>
                            <th>Local</th>
                            <th>Produto</th>
                            <th class="text-end">Saldo Atual</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($saldosPorLocal)): ?>
                            <tr>
                                <td colspan="3" class="text-center text-muted py-4">
                                    Nenhum saldo registrado ainda. Faça a primeira ENTRADA ao lado!
                                </td>
                            </tr>
                        <?php endif; ?>
                        <?php foreach ($saldosPorLocal as $s): ?>
                            <tr>
                                <td>
                                    <span class="badge <?= $s['local_tipo'] === 'INTERNO' ? 'bg-primary' : 'bg-warning text-dark' ?>">
                                        <?= htmlspecialchars($s['local_nome']) ?>
                                    </span>
                                </td>
                                <td><?= htmlspecialchars($s['produto_nome']) ?></td>
                                <td class="text-end fw-bold"><?= $s['quantidade'] ?> <?= $s['unidade_medida'] ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>

<!-- HISTÓRICO DE MOVIMENTAÇÕES -->
<div class="card shadow-sm">
    <div class="card-header bg-white fw-bold">
        📜 Histórico de Movimentações Recentes
    </div>
    <div class="table-responsive">
        <table class="table table-hover align-middle mb-0">
            <thead class="table-light">
                <tr>
                    <th>Data / Hora</th>
                    <th>Operação</th>
                    <th>Produto</th>
                    <th>Origem ➔ Destino</th>
                    <th class="text-center">Quantidade</th>
                    <th class="text-end">Valor Unit.</th>
                    <th>Observação</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($historico)): ?>
                    <tr>
                        <td colspan="7" class="text-center text-muted py-4">Nenhuma movimentação registrada.</td>
                    </tr>
                <?php endif; ?>
                <?php foreach ($historico as $h): ?>
                    <?php
                    $badges = [
                        'ENTRADA'           => 'bg-success',
                        'TRANSFERENCIA'     => 'bg-primary',
                        'SAIDA_VENDA'       => 'bg-info text-dark',
                        'CONSUMO_PRODUCAO'  => 'bg-secondary',
                        'PERDA_FALHA'       => 'bg-danger',
                        'AJUSTE_INVENTARIO' => 'bg-dark'
                    ];
                    $corBadge = $badges[$h['tipo_movimento']] ?? 'bg-secondary';
                    ?>
                    <tr>
                        <td class="small text-muted"><?= date('d/m/Y H:i', strtotime($h['data_movimento'])) ?></td>
                        <td><span class="badge <?= $corBadge ?>"><?= $h['tipo_movimento'] ?></span></td>
                        <td class="fw-semibold"><?= htmlspecialchars($h['produto_nome']) ?></td>
                        <td class="small">
                            <span class="text-danger"><?= htmlspecialchars($h['origem_nome'] ?? '—') ?></span>
                            <span class="mx-1">➔</span>
                            <span class="text-success"><?= htmlspecialchars($h['destino_nome'] ?? '—') ?></span>
                        </td>
                        <td class="text-center fw-bold"><?= $h['quantidade'] ?> <?= $h['unidade_medida'] ?></td>
                        <td class="text-end">R$ <?= number_format($h['valor_unitario'], 2, ',', '.') ?></td>
                        <td class="small text-muted"><?= htmlspecialchars($h['observacao'] ?: '-') ?></td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>

<script>
function ajustarCamposLocais() {
    const tipo = document.getElementById('tipo_movimento').value;
    const blocoOrigem = document.getElementById('bloco_origem');
    const blocoDestino = document.getElementById('bloco_destino');
    const selectOrigem = document.getElementById('local_origem_id');
    const selectDestino = document.getElementById('local_destino_id');

    if (tipo === 'ENTRADA') {
        blocoOrigem.style.display = 'none';
        blocoDestino.style.display = 'block';
        selectOrigem.required = false;
        selectDestino.required = true;
    } else if (tipo === 'TRANSFERENCIA') {
        blocoOrigem.style.display = 'block';
        blocoDestino.style.display = 'block';
        selectOrigem.required = true;
        selectDestino.required = true;
        // Sugere a Oficina (primeiro item) na origem e a primeira Loja Parceira no destino
        if (selectOrigem.options.length > 1) selectOrigem.selectedIndex = 1;
        if (selectDestino.options.length > 2) selectDestino.selectedIndex = 2;
    } else {
        // SAIDA_VENDA, CONSUMO_PRODUCAO, PERDA_FALHA
        blocoOrigem.style.display = 'block';
        blocoDestino.style.display = 'none';
        selectOrigem.required = true;
        selectDestino.required = false;
        if (selectOrigem.options.length > 1 && selectOrigem.selectedIndex === 0) {
            selectOrigem.selectedIndex = 1;
        }
    }
    preencherValorSugerido();
}

function preencherValorSugerido() {
    const selectProd = document.getElementById('produto_id');
    const tipo = document.getElementById('tipo_movimento').value;
    const inputValor = document.getElementById('valor_unitario');
    const labelUnidade = document.getElementById('label_unidade');

    const option = selectProd.options[selectProd.selectedIndex];
    if (!option || !option.value) return;

    labelUnidade.textContent = option.getAttribute('data-unidade') || 'UN';

    if (tipo === 'SAIDA_VENDA' || tipo === 'TRANSFERENCIA') {
        inputValor.value = floatParaMoedaBRL(option.getAttribute('data-venda') || 0);
    } else {
        inputValor.value = floatParaMoedaBRL(option.getAttribute('data-custo') || 0);
    }
}

document.addEventListener('DOMContentLoaded', ajustarCamposLocais);
</script>

</div>
</body>
</html>