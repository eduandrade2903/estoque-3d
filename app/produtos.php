<?php
require_once __DIR__ . '/includes/header.php';

$mensagem = '';
$erro = '';

// 1. AÇÃO: Salvar Novo Tipo de Produto
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['acao']) && $_POST['acao'] === 'salvar_tipo') {
    $nomeTipo = trim($_POST['nome_tipo'] ?? '');
    $descTipo = trim($_POST['descricao_tipo'] ?? '');
    if ($nomeTipo !== '') {
        try {
            $stmt = $pdo->prepare("INSERT INTO tipos_produto (nome, descricao) VALUES (?, ?)");
            $stmt->execute([$nomeTipo, $descTipo]);
            $mensagem = "Tipo de produto cadastrado com sucesso!";
        } catch (PDOException $e) {
            $erro = "Erro ao cadastrar tipo (verifique se o nome já existe).";
        }
    }
}

// 2. AÇÃO: Salvar ou Atualizar Produto
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['acao']) && $_POST['acao'] === 'salvar_produto') {
    $id             = !empty($_POST['id']) ? (int)$_POST['id'] : null;
    $sku            = trim($_POST['sku'] ?? '') ?: null;
    $nome           = trim($_POST['nome'] ?? '');
    $tipo_id        = (int)($_POST['tipo_id'] ?? 1);
    $unidade_medida = $_POST['unidade_medida'] ?? 'UN';
    $custo_unitario = moedaParaFloat($_POST['custo_unitario'] ?? '0');
    $preco_venda    = moedaParaFloat($_POST['preco_venda'] ?? '0');
    $estoque_minimo = moedaParaFloat($_POST['estoque_minimo'] ?? '0');

    try {
        if ($id) {
            $stmt = $pdo->prepare("
                UPDATE produtos 
                SET sku = ?, nome = ?, tipo_id = ?, unidade_medida = ?, 
                    custo_unitario = ?, preco_venda = ?, estoque_minimo = ?
                WHERE id = ?
            ");
            $stmt->execute([$sku, $nome, $tipo_id, $unidade_medida, $custo_unitario, $preco_venda, $estoque_minimo, $id]);
            $mensagem = "Produto atualizado com sucesso!";
        } else {
            $stmt = $pdo->prepare("
                INSERT INTO produtos (sku, nome, tipo_id, unidade_medida, custo_unitario, preco_venda, estoque_minimo)
                VALUES (?, ?, ?, ?, ?, ?, ?)
            ");
            $stmt->execute([$sku, $nome, $tipo_id, $unidade_medida, $custo_unitario, $preco_venda, $estoque_minimo]);
            $mensagem = "Produto cadastrado com sucesso!";
        }
    } catch (PDOException $e) {
        $erro = "Erro ao salvar produto: verifique se o SKU já está em uso.";
    }
}

// 3. AÇÃO: Alternar Status Ativo/Inativo ou Excluir
if (isset($_GET['toggle_id'])) {
    $stmt = $pdo->prepare("UPDATE produtos SET ativo = CASE WHEN ativo = 1 THEN 0 ELSE 1 END WHERE id = ?");
    $stmt->execute([(int)$_GET['toggle_id']]);
    header("Location: produtos.php");
    exit;
}

// Carregar produto para edição, se solicitado
$produtoEdicao = null;
if (isset($_GET['editar_id'])) {
    $stmt = $pdo->prepare("SELECT * FROM produtos WHERE id = ?");
    $stmt->execute([(int)$_GET['editar_id']]);
    $produtoEdicao = $stmt->fetch();
}

// Consultas para preencher a tela
$tipos = $pdo->query("SELECT * FROM tipos_produto ORDER BY nome")->fetchAll();
$produtos = $pdo->query("
    SELECT 
        p.*, 
        t.nome AS tipo_nome,
        COALESCE(SUM(s.quantidade), 0) AS saldo_total
    FROM produtos p
    JOIN tipos_produto t ON t.id = p.tipo_id
    LEFT JOIN saldos_estoque s ON s.produto_id = p.id
    GROUP BY p.id
    ORDER BY p.ativo DESC, p.nome ASC
")->fetchAll();
?>

<?php if ($mensagem): ?>
    <div class="alert alert-success alert-dismissible fade show"><?= htmlspecialchars($mensagem) ?></div>
<?php endif; ?>
<?php if ($erro): ?>
    <div class="alert alert-danger alert-dismissible fade show"><?= htmlspecialchars($erro) ?></div>
<?php endif; ?>

<div class="row">
    <!-- Formulário de Produto -->
    <div class="col-md-8">
        <div class="card shadow-sm mb-4">
            <div class="card-header bg-white fw-bold">
                <?= $produtoEdicao ? '✏️ Editar Produto #' . $produtoEdicao['id'] : '➕ Novo Produto / Material 3D' ?>
            </div>
            <div class="card-body">
                <form method="POST" action="produtos.php">
                    <input type="hidden" name="acao" value="salvar_produto">
                    <input type="hidden" name="id" value="<?= $produtoEdicao['id'] ?? '' ?>">

                    <div class="row g-3">
                        <div class="col-md-3">
                            <label class="form-label">SKU / Código</label>
                            <input type="text" name="sku" class="form-control" placeholder="Ex: FIL-PLA-01" value="<?= htmlspecialchars($produtoEdicao['sku'] ?? '') ?>">
                        </div>
                        <div class="col-md-5">
                            <label class="form-label">Nome do Produto *</label>
                            <input type="text" name="nome" class="form-control" required placeholder="Ex: Filamento PLA Preto / Vaso Groot" value="<?= htmlspecialchars($produtoEdicao['nome'] ?? '') ?>">
                        </div>
                        <div class="col-md-4">
                            <label class="form-label">Tipo *</label>
                            <select name="tipo_id" class="form-select" required>
                                <?php foreach ($tipos as $t): ?>
                                    <option value="<?= $t['id'] ?>" <?= ($produtoEdicao['tipo_id'] ?? '') == $t['id'] ? 'selected' : '' ?>>
                                        <?= htmlspecialchars($t['nome']) ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <div class="col-md-3">
                            <label class="form-label">Unidade</label>
                            <select name="unidade_medida" class="form-select">
                                <?php foreach (['UN' => 'Unidade (UN)', 'G' => 'Gramas (G)', 'KG' => 'Quilos (KG)', 'ML' => 'Mililitros (ML)'] as $sigla => $rotulo): ?>
                                    <option value="<?= $sigla ?>" <?= ($produtoEdicao['unidade_medida'] ?? 'UN') === $sigla ? 'selected' : '' ?>>
                                        <?= $rotulo ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-3">
                            <label class="form-label">Custo Unit.</label>
                            <div class="input-group">
                                <span class="input-group-text">R$</span>
                                <input type="text" name="custo_unitario" class="form-control moeda-brl" 
                                    value="<?= number_format((float)($produtoEdicao['custo_unitario'] ?? 0), 2, ',', '.') ?>">
                            </div>
                        </div>
                        <div class="col-md-3">
                            <label class="form-label">Preço Venda</label>
                            <div class="input-group">
                                <span class="input-group-text">R$</span>
                                <input type="text" name="preco_venda" class="form-control moeda-brl" 
                                    value="<?= number_format((float)($produtoEdicao['preco_venda'] ?? 0), 2, ',', '.') ?>">
                            </div>
                        </div>
                        <div class="col-md-3">
                            <label class="form-label">Estoque Mínimo</label>
                            <input type="number" step="0.01" name="estoque_minimo" class="form-control" value="<?= $produtoEdicao['estoque_minimo'] ?? '0' ?>">
                        </div>
                    </div>

                    <div class="mt-3">
                        <button type="submit" class="btn btn-primary">
                            <?= $produtoEdicao ? 'Salvar Alterações' : 'Cadastrar Produto' ?>
                        </button>
                        <?php if ($produtoEdicao): ?>
                            <a href="produtos.php" class="btn btn-outline-secondary">Cancelar</a>
                        <?php endif; ?>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- Cadastro Rápido de Tipo de Produto -->
    <div class="col-md-4">
        <div class="card shadow-sm mb-4">
            <div class="card-header bg-white fw-bold">🏷️ Novo Tipo de Produto</div>
            <div class="card-body">
                <form method="POST" action="produtos.php">
                    <input type="hidden" name="acao" value="salvar_tipo">
                    <div class="mb-2">
                        <label class="form-label">Nome da Categoria *</label>
                        <input type="text" name="nome_tipo" class="form-control form-control-sm" required placeholder="Ex: Resinas UV">
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Descrição</label>
                        <input type="text" name="descricao_tipo" class="form-control form-control-sm" placeholder="Opcional">
                    </div>
                    <button type="submit" class="btn btn-sm btn-secondary w-100">Adicionar Tipo</button>
                </form>
            </div>
        </div>
    </div>
</div>

<!-- Tabela de Produtos Cadastrados -->
<div class="card shadow-sm">
    <div class="card-header bg-white fw-bold">📦 Produtos e Materiais Cadastrados</div>
    <div class="table-responsive">
        <table class="table table-hover align-middle mb-0">
            <thead class="table-light">
                <tr>
                    <th>SKU</th>
                    <th>Produto</th>
                    <th>Tipo</th>
                    <th>Custo</th>
                    <th>Venda</th>
                    <th>Est. Mínimo</th>
                    <th>Saldo Geral</th>
                    <th class="text-end">Ações</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($produtos)): ?>
                    <tr><td colspan="8" class="text-center text-muted py-4">Nenhum produto cadastrado ainda.</td></tr>
                <?php endif; ?>
                <?php foreach ($produtos as $p): ?>
                    <tr class="<?= !$p['ativo'] ? 'table-secondary text-muted' : '' ?>">
                        <td><code><?= htmlspecialchars($p['sku'] ?? '-') ?></code></td>
                        <td class="fw-semibold"><?= htmlspecialchars($p['nome']) ?></td>
                        <td><span class="badge bg-secondary"><?= htmlspecialchars($p['tipo_nome']) ?></span></td>
                        <td>R$ <?= number_format($p['custo_unitario'], 2, ',', '.') ?></td>
                        <td>R$ <?= number_format($p['preco_venda'], 2, ',', '.') ?></td>
                        <td><?= $p['estoque_minimo'] ?> <?= $p['unidade_medida'] ?></td>
                        <td>
                            <span class="fw-bold <?= ($p['saldo_total'] < $p['estoque_minimo']) ? 'text-danger' : 'text-success' ?>">
                                <?= $p['saldo_total'] ?> <?= $p['unidade_medida'] ?>
                            </span>
                        </td>
                        <td class="text-end">
                            <a href="produtos.php?editar_id=<?= $p['id'] ?>" class="btn btn-sm btn-outline-primary">Editar</a>
                            <a href="produtos.php?toggle_id=<?= $p['id'] ?>" class="btn btn-sm <?= $p['ativo'] ? 'btn-outline-danger' : 'btn-outline-success' ?>">
                                <?= $p['ativo'] ? 'Inativar' : 'Ativar' ?>
                            </a>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>

</div>
</body>
</html>