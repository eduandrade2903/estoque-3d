<?php
require_once __DIR__ . '/includes/header.php';

$mensagem = '';
$erro = '';

// 1. AÇÃO: Salvar ou Atualizar Local / Cliente
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['acao']) && $_POST['acao'] === 'salvar_local') {
    $id                  = !empty($_POST['id']) ? (int)$_POST['id'] : null;
    $nome                = trim($_POST['nome'] ?? '');
    $tipo                = $_POST['tipo'] ?? 'LOJA_PARCEIRA';
    $documento           = trim($_POST['documento'] ?? '');
    $telefone            = trim($_POST['telefone'] ?? '');
    $endereco            = trim($_POST['endereco'] ?? '');
    $comissao_percentual = (float)str_replace(',', '.', $_POST['comissao_percentual'] ?? '0');
    $observacoes         = trim($_POST['observacoes'] ?? '');

    try {
        if ($id) {
            $stmt = $pdo->prepare("
                UPDATE locais_clientes 
                SET nome = ?, tipo = ?, documento = ?, telefone = ?, endereco = ?, comissao_percentual = ?, observacoes = ?
                WHERE id = ?
            ");
            $stmt->execute([$nome, $tipo, $documento, $telefone, $endereco, $comissao_percentual, $observacoes, $id]);
            $mensagem = "Cadastro atualizado com sucesso!";
        } else {
            $stmt = $pdo->prepare("
                INSERT INTO locais_clientes (nome, tipo, documento, telefone, endereco, comissao_percentual, observacoes)
                VALUES (?, ?, ?, ?, ?, ?, ?)
            ");
            $stmt->execute([$nome, $tipo, $documento, $telefone, $endereco, $comissao_percentual, $observacoes]);
            $mensagem = "Local / Cliente cadastrado com sucesso!";
        }
    } catch (PDOException $e) {
        $erro = "Erro ao salvar registro: " . $e->getMessage();
    }
}

// 2. AÇÃO: Excluir (apenas se não tiver movimentações vinculadas)
if (isset($_GET['excluir_id'])) {
    try {
        $stmt = $pdo->prepare("DELETE FROM locais_clientes WHERE id = ?");
        $stmt->execute([(int)$_GET['excluir_id']]);
        $mensagem = "Registro removido com sucesso!";
    } catch (PDOException $e) {
        $erro = "Não é possível excluir um local que já possui saldo ou histórico de movimentações.";
    }
}

$localEdicao = null;
if (isset($_GET['editar_id'])) {
    $stmt = $pdo->prepare("SELECT * FROM locais_clientes WHERE id = ?");
    $stmt->execute([(int)$_GET['editar_id']]);
    $localEdicao = $stmt->fetch();
}

$locais = $pdo->query("
    SELECT 
        l.*,
        COALESCE(SUM(s.quantidade), 0) AS total_itens
    FROM locais_clientes l
    LEFT JOIN saldos_estoque s ON s.local_id = l.id
    GROUP BY l.id
    ORDER BY l.tipo ASC, l.nome ASC
")->fetchAll();
?>

<?php if ($mensagem): ?>
    <div class="alert alert-success"><?= htmlspecialchars($mensagem) ?></div>
<?php endif; ?>
<?php if ($erro): ?>
    <div class="alert alert-danger"><?= htmlspecialchars($erro) ?></div>
<?php endif; ?>

<div class="card shadow-sm mb-4">
    <div class="card-header bg-white fw-bold">
        <?= $localEdicao ? '✏️ Editar Local / Cliente #' . $localEdicao['id'] : '🏪 Novo Local de Estoque, Loja Parceira ou Cliente' ?>
    </div>
    <div class="card-body">
        <form method="POST" action="locais.php">
            <input type="hidden" name="acao" value="salvar_local">
            <input type="hidden" name="id" value="<?= $localEdicao['id'] ?? '' ?>">

            <div class="row g-3">
                <div class="col-md-5">
                    <label class="form-label">Nome (Loja, Pessoa ou Estoque) *</label>
                    <input type="text" name="nome" class="form-control" required placeholder="Ex: Tabacaria do Marcos / Cliente João" value="<?= htmlspecialchars($localEdicao['nome'] ?? '') ?>">
                </div>
                <div class="col-md-4">
                    <label class="form-label">Tipo de Vínculo *</label>
                    <select name="tipo" class="form-select" required>
                        <option value="LOJA_PARCEIRA" <?= ($localEdicao['tipo'] ?? 'LOJA_PARCEIRA') === 'LOJA_PARCEIRA' ? 'selected' : '' ?>>🏪 Loja Parceira (Consignado)</option>
                        <option value="CLIENTE_FINAL" <?= ($localEdicao['tipo'] ?? '') === 'CLIENTE_FINAL' ? 'selected' : '' ?>>👤 Cliente Final (Compra Direta)</option>
                        <option value="INTERNO" <?= ($localEdicao['tipo'] ?? '') === 'INTERNO' ? 'selected' : '' ?>>🏠 Estoque Interno (Oficina/Casa)</option>
                    </select>
                </div>
                <div class="col-md-3">
                    <label class="form-label">Comissão da Loja (%)</label>
                    <input type="number" step="0.1" name="comissao_percentual" class="form-control" value="<?= $localEdicao['comissao_percentual'] ?? '0.0' ?>">
                </div>

                <div class="col-md-3">
                    <label class="form-label">Telefone / WhatsApp</label>
                    <input type="text" name="telefone" class="form-control" placeholder="(41) 99999-9999" value="<?= htmlspecialchars($localEdicao['telefone'] ?? '') ?>">
                </div>
                <div class="col-md-3">
                    <label class="form-label">CPF / CNPJ</label>
                    <input type="text" name="documento" class="form-control" value="<?= htmlspecialchars($localEdicao['documento'] ?? '') ?>">
                </div>
                <div class="col-md-6">
                    <label class="form-label">Endereço</label>
                    <input type="text" name="endereco" class="form-control" value="<?= htmlspecialchars($localEdicao['endereco'] ?? '') ?>">
                </div>

                <div class="col-md-12">
                    <label class="form-label">Observações (Dia de acerto, responsável, etc.)</label>
                    <input type="text" name="observacoes" class="form-control" value="<?= htmlspecialchars($localEdicao['observacoes'] ?? '') ?>">
                </div>
            </div>

            <div class="mt-3">
                <button type="submit" class="btn btn-primary">
                    <?= $localEdicao ? 'Salvar Alterações' : 'Cadastrar Local / Cliente' ?>
                </button>
                <?php if ($localEdicao): ?>
                    <a href="locais.php" class="btn btn-outline-secondary">Cancelar</a>
                <?php endif; ?>
            </div>
        </form>
    </div>
</div>

<!-- Listagem -->
<div class="card shadow-sm">
    <div class="card-header bg-white fw-bold">📋 Locais, Lojas Parceiras e Clientes</div>
    <div class="table-responsive">
        <table class="table table-hover align-middle mb-0">
            <thead class="table-light">
                <tr>
                    <th>Nome</th>
                    <th>Tipo</th>
                    <th>Telefone</th>
                    <th>Comissão</th>
                    <th>Peças no Local</th>
                    <th>Observações</th>
                    <th class="text-end">Ações</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($locais as $l): ?>
                    <tr>
                        <td class="fw-semibold"><?= htmlspecialchars($l['nome']) ?></td>
                        <td>
                            <?php if ($l['tipo'] === 'INTERNO'): ?>
                                <span class="badge bg-primary">INTERNO</span>
                            <?php elseif ($l['tipo'] === 'LOJA_PARCEIRA'): ?>
                                <span class="badge bg-warning text-dark">LOJA PARCEIRA</span>
                            <?php else: ?>
                                <span class="badge bg-info text-dark">CLIENTE FINAL</span>
                            <?php endif; ?>
                        </td>
                        <td><?= htmlspecialchars($l['telefone'] ?: '-') ?></td>
                        <td><?= number_format($l['comissao_percentual'], 1, ',', '.') ?>%</td>
                        <td class="fw-bold"><?= $l['total_itens'] ?></td>
                        <td class="text-muted small"><?= htmlspecialchars($l['observacoes'] ?: '-') ?></td>
                        <td class="text-end">
                            <a href="locais.php?editar_id=<?= $l['id'] ?>" class="btn btn-sm btn-outline-primary">Editar</a>
                            <?php if ($l['id'] > 1): ?>
                                <a href="locais.php?excluir_id=<?= $l['id'] ?>" class="btn btn-sm btn-outline-danger" onclick="return confirm('Deseja realmente excluir este registro?')">Excluir</a>
                            <?php endif; ?>
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