PRAGMA forign_keys = ON;

-- Tabela de tipos de produtos
CREATE TABLE IF NOT EXISTS tipos_produto (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    nome TEXT NOT NULL,
    descricao TEXT
);

-- tabela de produtos
CREATE TABLE IF NOT EXISTS produtos (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    sku TEXT UNIQUE,
    nome TEXT NOT NULL,
    tipo_id INTEGER NOT NULL,
    unidade_medida TEXT NOT NULL DEFAULT 'UN',
    custo_unitario REAL NOT NULL DEFAULT 0.0,
    preco_venda  REAL NOT NULL DEFAULT 0.0,
    estoque_minimo INTEGER NOT NULL DEFAULT 0.0,
    ativo INTEGER NOT NULL DEFAULT 1,
    criado_em DATETIME DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY(tipo_id) REFERENCES tipos_produto(id)
);

-- Locais de estoque
CREATE TABLE IF NOT EXISTS locais_clientes (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    nome TEXT NOT NULL,
    tipo TEXT NOT NULL CHECK(tipo IN ('INTERNO','LOJA_PARCEIRA','CLIENTE_FINAL')),
    documento TEXT NOT NULL,
    telefone TEXT NOT NULL,
    endereco TEXT NOT NULL,
    comissao_percentual REAL DEFAULT 0.0,
    observacoes TEXT,
    criado_em DATETIME DEFAULT CURRENT_TIMESTAMP
);

-- Saldo de produtos em cada local
create TABLE IF NOT EXISTS saltos_estoque (
    produto_id INTEGER NOT NULL,
    local_id INTEGER NOT NULL,
    quantidade REAL NOT NULL DEFAULT 0.0,
    atualizado_em DATETIME DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (produto_id,local_id),
    FOREIGN KEY(produto_id) REFERENCES produtos(id),
    FOREIGN KEY(local_id) REFERENCES locais_clientes(id)
);

-- Histórico de movimentações
CREATE TABLE IF NOT EXISTS movimentacoes (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    produto_id INTEGER NOT NULL,
    local_origem_id INTEGER , -- nulo quando for entrada
    local_destino_id INTEGER, -- nulo quando for saída
    tipo_movimento TEXT NOT NULL CHECK (tipo_movimento IN (
        'ENTRADA',            -- Compra de insumo ou nova peça impressa (soma no destino)
        'SAIDA_VENDA',        -- Venda direta ou baixa de consignado vendido (subtrai da origem)
        'CONSUMO_PRODUCAO',   -- Gasto de filamento/resina para imprimir (subtrai da origem)
        'PERDA_FALHA',        -- Falha de impressão ou peça quebrada (subtrai da origem)
        'TRANSFERENCIA',      -- Envio da Oficina para Loja Parceira ou devolução (subtrai origem, soma destino)
        'AJUSTE_INVENTARIO'   -- Correção gerada pelo balanço de estoque
    )),
    quantidade REAL NOT NULL CHECK (quantidade > 0),
    valor_unitario REAL NOT NULL DEFAULT 0.0,
    observacao TEXT,
    data_movimentacao DATETIME DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY(produto_id) REFERENCES produtos(id),
    FOREIGN KEY(local_origem_id) REFERENCES locais_clientes(id),
    FOREIGN KEY(local_destino_id) REFERENCES locais_clientes(id)

);

--  Cabeçalho de inventário
CREATE TABLE IF NOT EXISTS inventarios (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    local_id INTEGER NOT NULL,
    status TEXT NOT NULL DEFAULT 'ABERTO' CHECK(status IN ('ABERTO', 'FINALIZADO', 'CANCELADO')),
    data_abertura DATETIME DEFAULT CURRENT_TIMESTAMP,
    data_fechamento DATETIME,
    observacao TEXT,
    FOREIGN KEY(local_id) REFERENCES locais_clientes(id)
);

-- Itens contados no inventário
CREATE TABLE IF NOT EXISTS itens_inventario (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    inventario_id INTEGER NOT NULL,
    produto_id INTEGER NOT NULL,
    quantidade_sistema REAL NOT NULL,
    quantidade_contada REAL NOT NULL,
    diferenca REAL GENERATED ALWAYS AS (quantidade_contada - quantidade_sistema) VIRTUAL,
    FOREIGN KEY(inventario_id) REFERENCES inventarios(id),
    FOREIGN KEY(produto_id) REFERENCES produtos(id)
);

-- TRIGGERS AUTOMÁTICAS DE ATUALIZAÇÃO DE SALDO

-- Quando houver local_origem_id preenchido, desconta o saldo da origem