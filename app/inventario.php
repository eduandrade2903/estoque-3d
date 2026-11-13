<?php
require_once __DIR__ . '/includes/header.php';

$mensagem = '';
$erro = '';

// Local selecionado para contagem (Padrão: 1 - Oficina Principal)
$localSelecionadoId = isset($_GET['local_id']) ? (int)$_GET['local_id'] : (int)($_POST['local_id'] ?? 1);

// PROCESSAR FINALIZAÇÃO DO INVENTÁRIO
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['acao']) && $_POST['acao'] === 'finalizar_inventario') {
    $localId     = (int)($_POST['local_id'] ?? 0);
    $observacoes = trim($_POST['observacoes'] ?? '');
    $contagens   = $_POST['contagem'] ?? []; // Array [produto_id => qtd_contada]
    $sistemas    = $_POST['sistema'] ?? [];  // Array [produto_id => qtd_sistema]

    if ($localId <= 0 || empty($contagens)) {
        $erro = "Selecione um local válido e informe a contagem dos produtos.";
    } else {
        try {
            $pdo->beginTransaction();

            // 1. Cria o cabeçalho do inventário já finalizado
            $stmtInv = $pdo->prepare("
                INSERT INTO inventarios (local_id, status, data_abertura, data_fechamento, observacoes)
                VALUES (?, 'FINALIZADO', CURRENT_TIMESTAMP, CURRENT_TIMESTAMP, ?)
            ");
            $stmtInv->execute([$localId, $observacoes ?: 'Balanço de estoque realizado pelo painel']);
            $inventarioId = (int)$pdo->lastInsertId();

            $stmtItem = $pdo->prepare("
                INSERT INTO inventario_itens (inventario_id, produto_id, quantidade_sistema, quantidade_contada)
                VALUES (?, ?, ?, ?)
            ");

            $stmtMov = $pdo->prepare("
                INSERT INTO movimentacoes 
                    (produto_id, local_origem_id, local_destino_id, tipo_movimento, quantidade, valor_unitario, observacao)
                VALUES (?, ?, ?, 'AJUSTE_INVENTARIO', ?, 0, ?)
            ");

            $totalAjustes = 0;

            foreach ($contagens as $prodId => $valorContadoStr) {
                $prodId     = (int)$prodId;
                $qtdSistema = (float)($sistemas[$prodId] ?? 0);
                // Aceita tanto número com ponto quanto formatado com vírgula
                $qtdContada = moedaParaFloat((string)$valorContadoStr);
                $diferenca  = round($qtdContada - $qtdSistema, 4);

                // Registra o item auditado no histórico do inventário
                $stmtItem->execute([$inventarioId, $prodId, $qtdSistema, $qtdContada]);

                // Se houve divergência entre o físico e o sistema, gera movimentação de ajuste
                if (abs($diferenca) > 0.0001) {
                    if ($diferenca > 0) {
                        // Sobrou no físico -> Entrada de ajuste no local (local_destino_id = $localId)
                        $obsMov = "Ajuste de Inventário #{$inventarioId} (Sobra física: +{$diferenca})";
                        $stmtMov->execute([$prodId, null, $localId, abs($diferenca), $obsMov]);
                    } else {
                        // Faltou no físico -> Saída de ajuste do local (local_origem_id = $localId)
                        $obsMov = "Ajuste de Inventário #{$inventarioId} (Falta física: {$diferenca})";
                        $stmtMov->execute([$prodId, $localId, null, abs($diferenca), $obsMov]);
                    }
                    $totalAjustes++;
                }
            }

            $pdo->commit();
            $mensagem = "Inventário #{$inventarioId} finalizado com sucesso! ({$totalAjustes} produto(s) tiveram o saldo ajustado automaticamente).";
        } catch (PDOException $e) {
            $pdo->rollBack();
            $erro = "Erro ao processar inventário: " . $e->getMessage();
        }
    }
}

// Buscar todos os locais de estoque (Internos e Lojas Parceiras)
$locais = $pdo->query("
    SELECT * FROM locais_clientes 
    WHERE tipo IN ('INTERNO', 'LOJA_PARCEIRA')
    ORDER BY CASE WHEN tipo = 'INTERNO' THEN 0 ELSE 1 END, nome ASC
")->fetchAll();

// Buscar todos os produtos ativos e o saldo atual deles no local selecionado
$stmtProdutos = $pdo->prepare("
    SELECT 
        p.id,
        p.sku,
        p.nome,
        p.unidade_medida,
        t.nome AS tipo_nome,
        COALESCE(s.quantidade, 0) AS saldo_atual
    FROM produtos p
    JOIN tipos_produto t ON t.id = p.tipo_id
    LEFT JOIN saldos_estoque s ON s.produto_id = p.id AND s.local_id = ?
    WHERE p.ativo = 1
    ORDER BY t.nome ASC, p.nome ASC
");
$stmtProdutos->execute([$localSelecionadoId]);
$produtosParaContagem = $stmtProdutos->fetchAll();

// Buscar histórico dos últimos inventários realizados
$historicoInventarios = $pdo->query("
    SELECT 
        i.*,
        l.nome AS local_nome,
        l.tipo AS local_tipo,
        COUNT(ii.id) AS total_itens_auditados,
        SUM(CASE WHEN ABS(ii.diferenca) > 0.0001 THEN 1 ELSE 0 END) AS itens_com_divergencia
    FROM inventarios i
    JOIN locais_clientes l ON l.id = i.local_id
    LEFT JOIN inventario_itens ii ON ii.inventario_id = i.id
    GROUP BY i.id
    ORDER BY i.data_abertura DESC, i.id DESC
    LIMIT 10
")->fetchAll();

// Se o usuário clicou para ver os detalhes de um inventário passado
$detalheInventarioId = isset($_GET['ver_id']) ? (int)$_GET['ver_id'] : null;
$itensDetalhe = [];
if ($detalheInventarioId) {
    $stmtDet = $pdo->prepare("
        SELECT 
            ii.*,
            p.nome AS produto_nome,
            p.sku,
            p.unidade_medida
        FROM inventario_itens ii
        JOIN produtos p ON p.id = ii.produto_id
        WHERE ii.inventario_id = ?
        ORDER BY ABS(ii.diferenca) DESC, p.nome ASC
    ");
    $stmtDet->execute([$detalheInventarioId]);
    $itensDetalhe = $stmtDet->fetchAll();
}
?>

<?php if ($mensagem): ?>
    <div class="alert alert-success alert-dismissible fade show"><?= htmlspecialchars($mensagem) ?></div>
<?php endif; ?>
<?php if ($erro): ?>
    <div class="alert alert-danger alert-dismissible fade show"><?= htmlspecialchars($erro) ?></div>
<?php endif; ?>

<!-- SELETOR DE LOCAL PARA AUDITORIA -->
<div class="card shadow-sm mb-4">
    <div class="card-body d-flex flex-wrap justify-content-between align-items-center gap-3">
        <div>
            <h5 class="mb-1 fw-bold">📋 Balanço e Auditoria de Estoque (Inventário)</h5>
            <small class="text-muted">Compare o estoque físico real com o saldo do sistema e gere ajustes automáticos.</small>
        </div>

        <form method="GET" action="inventario.php" class="d-flex align-items-center gap-2">
            <label class="fw-semibold text-nowrap">Local Auditado:</label>
            <select name="local_id" class="form-select border-primary fw-semibold" onchange="this.form.submit()">
                <?php foreach ($locais as $l): ?>
                    <option value="<?= $l['id'] ?>" <?= $l['id'] === $localSelecionadoId ? 'selected' : '' ?>>
                        <?= $l['tipo'] === 'INTERNO' ? '🏠 ' : '🏪 ' ?><?= htmlspecialchars($l['nome']) ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </form>
    </div>
</div>

<!-- TABELA DE CONTAGEM FÍSICA -->
<div class="card shadow-sm mb-4">
    <div class="card-header bg-white d-flex justify-content-between align-items-center">
        <span class="fw-bold">🔍 Folha de Contagem Física</span>
        <span class="badge bg-secondary"><?= count($produtosParaContagem) ?> produtos listados</span>
    </div>
    <form method="POST" action="inventario.php" onsubmit="return confirm('Confirma a finalização do inventário? Os saldos divergentes serão ajustados automaticamente para igualar à sua contagem física.');">
        <input type="hidden" name="acao" value="finalizar_inventario">
        <input type="hidden" name="local_id" value="<?= $localSelecionadoId ?>">

        <div class="table-responsive">
            <table class="table table-hover align-middle mb-0">
                <thead class="table-light">
                    <tr>
                        <th>SKU</th>
                        <th>Produto / Material</th>
                        <th>Categoria</th>
                        <th class="text-center">Saldo no Sistema</th>
                        <th class="text-center" style="width: 200px;">Contagem Física Real *</th>
                        <th class="text-center">Diferença (Ajuste)</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($produtosParaContagem)): ?>
                        <tr>
                            <td colspan="6" class="text-center text-muted py-4">
                                Nenhum produto ativo cadastrado para realizar inventário.
                            </td>
                        </tr>
                    <?php endif; ?>
                    <?php foreach ($produtosParaContagem as $p): ?>
                        <?php
                        // Define se o campo usa casas decimais (ex: KG, ML) ou inteiro (UN, G)
                        $casasDecimais = in_array($p['unidade_medida'], ['KG', 'ML']) ? 2 : 0;
                        $valorFormatado = number_format((float)$p['saldo_atual'], $casasDecimais, ',', '.');
                        ?>
                        <tr>
                            <td><code><?= htmlspecialchars($p['sku'] ?? '-') ?></code></td>
                            <td class="fw-semibold"><?= htmlspecialchars($p['nome']) ?></td>
                            <td><span class="badge bg-light text-dark border"><?= htmlspecialchars($p['tipo_nome']) ?></span></td>
                            <td class="text-center">
                                <span class="fw-bold"><?= $valorFormatado ?></span> <small class="text-muted"><?= $p['unidade_medida'] ?></small>
                                <input type="hidden" name="sistema[<?= $p['id'] ?>]" id="sis_<?= $p['id'] ?>" value="<?= (float)$p['saldo_atual'] ?>">
                            </td>
                            <td>
                                <div class="input-group input-group-sm">
                                    <input type="text" 
                                           inputmode="numeric"
                                           name="contagem[<?= $p['id'] ?>]" 
                                           id="cont_<?= $p['id'] ?>"
                                           class="form-control moeda-brl text-end fw-bold border-primary input-contagem" 
                                           data-decimais="<?= $casasDecimais ?>"
                                           data-prod-id="<?= $p['id'] ?>"
                                           data-unidade="<?= $p['unidade_medida'] ?>"
                                           value="<?= $valorFormatado ?>"
                                           required>
                                    <span class="input-group-text"><?= $p['unidade_medida'] ?></span>
                                </div>
                            </td>
                            <td class="text-center">
                                <span id="dif_<?= $p['id'] ?>" class="badge bg-success">0 <?= $p['unidade_medida'] ?> (OK)</span>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>

        <?php if (!empty($produtosParaContagem)): ?>
            <div class="card-footer bg-white p-3">
                <div class="row g-3 align-items-center">
                    <div class="col-md-8">
                        <input type="text" name="observacoes" class="form-control" placeholder="Observação da auditoria (Ex: Conferência mensal com o dono da loja / Ajuste de sobra de filamento)">
                    </div>
                    <div class="col-md-4 text-end">
                        <button type="submit" class="btn btn-success w-100 fw-bold">
                            ✅ Finalizar Inventário e Ajustar Saldos
                        </button>
                    </div>
                </div>
            </div>
        <?php endif; ?>
    </form>
</div>

<!-- MODAL / CARD DE DETALHE DE UM INVENTÁRIO ANTERIOR (SE SELECIONADO) -->
<?php if ($detalheInventarioId && !empty($itensDetalhe)): ?>
<div class="card shadow-sm mb-4 border-info">
    <div class="card-header bg-info text-dark d-flex justify-content-between align-items-center">
        <span class="fw-bold">🔎 Detalhes da Contagem do Inventário #<?= $detalheInventarioId ?></span>
        <a href="inventario.php?local_id=<?= $localSelecionadoId ?>" class="btn btn-sm btn-dark">Fechar Detalhes</a>
    </div>
    <div class="table-responsive">
        <table class="table table-sm table-hover align-middle mb-0">
            <thead class="table-light">
                <tr>
                    <th>SKU</th>
                    <th>Produto</th>
                    <th class="text-center">Estava no Sistema</th>
                    <th class="text-center">Foi Contado</th>
                    <th class="text-center">Diferença Aplicada</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($itensDetalhe as $det): ?>
                    <tr>
                        <td><code><?= htmlspecialchars($det['sku'] ?? '-') ?></code></td>
                        <td class="fw-semibold"><?= htmlspecialchars($det['produto_nome']) ?></td>
                        <td class="text-center"><?= $det['quantidade_sistema'] ?> <?= $det['unidade_medida'] ?></td>
                        <td class="text-center fw-bold"><?= $det['quantidade_contada'] ?> <?= $det['unidade_medida'] ?></td>
                        <td class="text-center">
                            <?php if (abs($det['diferenca']) < 0.0001): ?>
                                <span class="badge bg-secondary">0 (Bateu)</span>
                            <?php elseif ($det['diferenca'] > 0): ?>
                                <span class="badge bg-success">+<?= $det['diferenca'] ?> <?= $det['unidade_medida'] ?> (Sobra)</span>
                            <?php else: ?>
                                <span class="badge bg-danger"><?= $det['diferenca'] ?> <?= $det['unidade_medida'] ?> (Falta)</span>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>
<?php endif; ?>

<!-- HISTÓRICO DE INVENTÁRIOS REALIZADOS -->
<div class="card shadow-sm">
    <div class="card-header bg-white fw-bold">
        📜 Histórico de Auditorias e Inventários Finalizados
    </div>
    <div class="table-responsive">
        <table class="table table-hover align-middle mb-0">
            <thead class="table-light">
                <tr>
                    <th>#ID</th>
                    <th>Data / Hora</th>
                    <th>Local Auditado</th>
                    <th class="text-center">Itens Conferidos</th>
                    <th class="text-center">Divergências Encontradas</th>
                    <th>Observações</th>
                    <th class="text-end">Ações</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($historicoInventarios)): ?>
                    <tr>
                        <td colspan="7" class="text-center text-muted py-4">Nenhum inventário realizado até o momento.</td>
                    </tr>
                <?php endif; ?>
                <?php foreach ($historicoInventarios as $inv): ?>
                    <tr>
                        <td class="fw-bold">#<?= $inv['id'] ?></td>
                        <td class="small text-muted"><?= date('d/m/Y H:i', strtotime($inv['data_fechamento'])) ?></td>
                        <td class="fw-semibold">
                            <?= htmlspecialchars($inv['local_nome']) ?>
                        </td>
                        <td class="text-center"><?= $inv['total_itens_auditados'] ?></td>
                        <td class="text-center">
                            <?php if ($inv['itens_com_divergencia'] > 0): ?>
                                <span class="badge bg-warning text-dark"><?= $inv['itens_com_divergencia'] ?> ajuste(s)</span>
                            <?php else: ?>
                                <span class="badge bg-success">100% Preciso</span>
                            <?php endif; ?>
                        </td>
                        <td class="small text-muted"><?= htmlspecialchars($inv['observacoes'] ?: '-') ?></td>
                        <td class="text-end">
                            <a href="inventario.php?local_id=<?= $localSelecionadoId ?>&ver_id=<?= $inv['id'] ?>" class="btn btn-sm btn-outline-info">
                                Ver Itens
                            </a>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>

<script>
// Converte string formatada em pt-BR ("1.250,50" ou "10") para float do JS
function brlParaFloatJS(valor) {
    if (!valor) return 0;
    let limpo = String(valor).replace(/\./g, '').replace(',', '.');
    let num = parseFloat(limpo);
    return isNaN(num) ? 0 : num;
}

// Atualiza o badge de diferença em tempo real conforme o usuário digita a contagem
document.addEventListener('input', function (e) {
    if (e.target && e.target.classList.contains('input-contagem')) {
        // Aguarda a máscara do header.php formatar o valor primeiro
        setTimeout(() => {
            const prodId = e.target.getAttribute('data-prod-id');
            const unidade = e.target.getAttribute('data-unidade');
            const casas = parseInt(e.target.getAttribute('data-decimais') || '0', 10);

            const qtdSistema = parseFloat(document.getElementById('sis_' + prodId).value || '0');
            const qtdContada = brlParaFloatJS(e.target.value);
            const diff = qtdContada - qtdSistema;

            const badge = document.getElementById('dif_' + prodId);
            const diffFormatada = diff.toLocaleString('pt-BR', {
                minimumFractionDigits: casas,
                maximumFractionDigits: casas
            });

            if (Math.abs(diff) < 0.0001) {
                badge.className = 'badge bg-success';
                badge.textContent = `0 ${unidade} (OK)`;
            } else if (diff > 0) {
                badge.className = 'badge bg-primary';
                badge.textContent = `+${diffFormatada} ${unidade} (Sobra)`;
            } else {
                badge.className = 'badge bg-danger';
                badge.textContent = `${diffFormatada} ${unidade} (Falta)`;
            }
        }, 20);
    }
});
</script>

</div>
</body>
</html>