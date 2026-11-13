PRAGMA foreign_keys = ON;

-- 1. Tipos de Produto (Ex: Insumo/Filamento, Produto Acabado, Embalagem)
CREATE TABLE IF NOT EXISTS tipos_produto (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    nome TEXT NOT NULL UNIQUE,
    descricao TEXT
);

-- 2. Produtos (Materiais de impressão 3D e Peças prontas)
CREATE TABLE IF NOT EXISTS produtos (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    sku TEXT UNIQUE,
    nome TEXT NOT NULL,
    tipo_id INTEGER NOT NULL,
    unidade_medida TEXT NOT NULL DEFAULT 'UN', -- 'UN', 'G', 'KG', 'ML'
    custo_unitario REAL NOT NULL DEFAULT 0.0,
    preco_venda REAL NOT NULL DEFAULT 0.0,
    estoque_minimo REAL NOT NULL DEFAULT 0.0,
    ativo INTEGER NOT NULL DEFAULT 1, -- 1 = Ativo, 0 = Inativo
    criado_em DATETIME DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (tipo_id) REFERENCES tipos_produto(id)
);

-- 3. Locais de Estoque / Clientes (Oficina própria, Lojas Parceiras/Consignado, Clientes)
CREATE TABLE IF NOT EXISTS locais_clientes (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    nome TEXT NOT NULL,
    tipo TEXT NOT NULL CHECK(tipo IN ('INTERNO', 'LOJA_PARCEIRA', 'CLIENTE_FINAL')),
    documento TEXT,
    telefone TEXT,
    endereco TEXT,
    comissao_percentual REAL DEFAULT 0.0, -- % que a loja parceira retém na venda
    observacoes TEXT,
    criado_em DATETIME DEFAULT CURRENT_TIMESTAMP
);

-- 4. Saldos de Estoque por Local (Onde está cada produto e em qual quantidade)
CREATE TABLE IF NOT EXISTS saldos_estoque (
    produto_id INTEGER NOT NULL,
    local_id INTEGER NOT NULL,
    quantidade REAL NOT NULL DEFAULT 0.0,
    atualizado_em DATETIME DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (produto_id, local_id),
    FOREIGN KEY (produto_id) REFERENCES produtos(id),
    FOREIGN KEY (local_id) REFERENCES locais_clientes(id)
);

-- 5. Histórico de Movimentações (Entradas, Saídas, Remessas para Lojas, Perdas)
CREATE TABLE IF NOT EXISTS movimentacoes (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    produto_id INTEGER NOT NULL,
    local_origem_id INTEGER,  -- NULL quando for Entrada (compra ou produção)
    local_destino_id INTEGER, -- NULL quando for Saída definitiva (venda, perda ou consumo)
    tipo_movimento TEXT NOT NULL CHECK(tipo_movimento IN (
        'ENTRADA',            -- Compra de insumo ou nova peça impressa (soma no destino)
        'SAIDA_VENDA',        -- Venda direta ou baixa de consignado vendido (subtrai da origem)
        'CONSUMO_PRODUCAO',   -- Gasto de filamento/resina para imprimir (subtrai da origem)
        'PERDA_FALHA',        -- Falha de impressão ou peça quebrada (subtrai da origem)
        'TRANSFERENCIA',      -- Envio da Oficina para Loja Parceira ou devolução (subtrai origem, soma destino)
        'AJUSTE_INVENTARIO'   -- Correção gerada pelo balanço de estoque
    )),
    quantidade REAL NOT NULL CHECK(quantidade > 0),
    valor_unitario REAL DEFAULT 0.0,
    observacao TEXT,
    data_movimento DATETIME DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (produto_id) REFERENCES produtos(id),
    FOREIGN KEY (local_origem_id) REFERENCES locais_clientes(id),
    FOREIGN KEY (local_destino_id) REFERENCES locais_clientes(id)
);

-- 6. Cabeçalho de Inventário (Auditorias / Contagens físicas)
CREATE TABLE IF NOT EXISTS inventarios (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    local_id INTEGER NOT NULL,
    status TEXT NOT NULL DEFAULT 'ABERTO' CHECK(status IN ('ABERTO', 'FINALIZADO', 'CANCELADO')),
    data_abertura DATETIME DEFAULT CURRENT_TIMESTAMP,
    data_fechamento DATETIME,
    observacoes TEXT,
    FOREIGN KEY (local_id) REFERENCES locais_clientes(id)
);

-- 7. Itens Contados no Inventário
CREATE TABLE IF NOT EXISTS inventario_itens (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    inventario_id INTEGER NOT NULL,
    produto_id INTEGER NOT NULL,
    quantidade_sistema REAL NOT NULL, -- Saldo que constava no momento da abertura
    quantidade_contada REAL NOT NULL, -- Saldo físico contado pelo seu amigo
    diferenca REAL GENERATED ALWAYS AS (quantidade_contada - quantidade_sistema) VIRTUAL,
    FOREIGN KEY (inventario_id) REFERENCES inventarios(id) ON DELETE CASCADE,
    FOREIGN KEY (produto_id) REFERENCES produtos(id)
);

-- ============================================================================
-- TRIGGERS AUTOMÁTICAS DE ATUALIZAÇÃO DE SALDO
-- ============================================================================

-- Quando houver local_origem_id preenchido, desconta o saldo da origem
CREATE TRIGGER IF NOT EXISTS trg_movimentacao_saida
AFTER INSERT ON movimentacoes
WHEN NEW.local_origem_id IS NOT NULL
BEGIN
    INSERT INTO saldos_estoque (produto_id, local_id, quantidade, atualizado_em)
    VALUES (NEW.produto_id, NEW.local_origem_id, -NEW.quantidade, CURRENT_TIMESTAMP)
    ON CONFLICT(produto_id, local_id) DO UPDATE SET
        quantidade = quantidade - NEW.quantidade,
        atualizado_em = CURRENT_TIMESTAMP;
END;

-- Quando houver local_destino_id preenchido, soma o saldo no destino
CREATE TRIGGER IF NOT EXISTS trg_movimentacao_entrada
AFTER INSERT ON movimentacoes
WHEN NEW.local_destino_id IS NOT NULL
BEGIN
    INSERT INTO saldos_estoque (produto_id, local_id, quantidade, atualizado_em)
    VALUES (NEW.produto_id, NEW.local_destino_id, NEW.quantidade, CURRENT_TIMESTAMP)
    ON CONFLICT(produto_id, local_id) DO UPDATE SET
        quantidade = quantidade + NEW.quantidade,
        atualizado_em = CURRENT_TIMESTAMP;
END;

-- ============================================================================
-- DADOS INICIAIS (SEED PARA TESTES)
-- ============================================================================
INSERT OR IGNORE INTO tipos_produto (id, nome, descricao) VALUES
    (1, 'Matéria-Prima 3D', 'Filamentos, resinas, álcool isopropílico e insumos'),
    (2, 'Produto Acabado', 'Peças impressas prontas para venda'),
    (3, 'Componentes / Outros', 'Ímãs, argolas de chaveiro, parafusos, embalagens');

INSERT OR IGNORE INTO locais_clientes (id, nome, tipo, comissao_percentual, observacoes) VALUES
    (1, 'Oficina Principal (Casa)', 'INTERNO', 0.0, 'Estoque físico principal'),
    (2, 'Loja Geek Center', 'LOJA_PARCEIRA', 20.0, 'Consignado - Acerto todo dia 10');