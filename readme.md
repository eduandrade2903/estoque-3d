# Lumen 3D Manager — Sistema de Controle de Estoque

Sistema de gestão de estoque, inventário e romaneios desenvolvido sob medida para oficinas e produção de peças em 3D, com arquitetura leve em PHP/SQLite e empacotamento desktop via PHPDesktop.

## 🚀 Tecnologias Utilizadas
* **Backend:** PHP 8.x com PDO
* **Banco de Dados:** SQLite (arquivo único, leve e portátil)
* **Interface:** Bootstrap 5, CSS Customizado (*Dark Luxury & Gold*)
* **Ambiente Desktop:** PHPDesktop (Chromium embutido para Windows)
* **Arquitetura de Dados:** Controle de saldos por local, movimentações auditáveis e romaneios de consignação.

## ✨ Principais Funcionalidades
1. **Painel de Controle:** Visão consolidada do faturamento bruto, comissões de parceiros, saldo líquido e atalhos rápidos.
2. **Gestão de Produtos:** Cadastro completo de itens (matéria-prima, filamentos e peças prontas) com SKU, preço de custo e preço de venda.
3. **Controle de Lojas e Locais:** Gestão de estoques internos (Oficina Principal) e Lojas Parceiras em Consignação com percentuais de comissão customizáveis.
4. **Movimentações de Estoque:** Entradas de produção, transferências entre locais e saídas por vendas diretas ou consignadas.
5. **Inventário e Balanço:** Auditoria física de estoque com geração automática de divergências e ajustes.
6. **Romaneio de Consignação:** Emissão de termo de remessa formal para lojas parceiras, com formatação limpa pronta para impressão e exportação em PDF.
7. **Relatórios e Faturamento:** Filtros por período com validação de datas, resumo de faturamento geral e extrato detalhado de vendas.
8. **Backup Integrado:** Ferramenta rápida para descarregar o arquivo de banco de dados (`estoque.sqlite`) diretamente pelo sistema.

## 🛠️ Como Executar o Projeto
1. Certifique-se de que a pasta `www` e a pasta `database` contendo o arquivo `estoque.sqlite` estão posicionadas na raiz do diretório do **PHPDesktop**.
2. Dê um duplo clique no executável do PHPDesktop (`phpdesktop-chrome.exe`) para iniciar a aplicação localmente.
3. Acesse o sistema através do navegador embutido.

## 📦 Limpeza do Banco para Produção / Envio
Para limpar os dados de testes e enviar o sistema zerado:
* Execute o script SQL de limpeza para esvaziar as movimentações mantendo a estrutura, ou recrie as tabelas utilizando o script `schema.sql` no seu gerenciador de banco de dados (como o DBeaver).