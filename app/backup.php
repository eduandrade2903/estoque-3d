<?php
require_once __DIR__ . '/src/Database.php';

// Localiza o caminho do arquivo SQLite dependendo do ambiente (Docker ou local)
$dbPath = file_exists('/var/www/database/estoque.sqlite')
    ? '/var/www/database/estoque.sqlite'
    : __DIR__ . '/../database/estoque.sqlite';

if (file_exists($dbPath)) {
    // Nome do arquivo gerado com a data e hora atual
    $nomeArquivo = 'estoque_backup_' . date('Y-m-d_H-i-s') . '.sqlite';

    // Força o download pelo navegador
    header('Content-Description: File Transfer');
    header('Content-Type: application/octet-stream');
    header('Content-Disposition: attachment; filename="' . $nomeArquivo . '"');
    header('Expires: 0');
    header('Cache-Control: must-revalidate');
    header('Pragma: public');
    header('Content-Length: ' . filesize($dbPath));
    
    // Limpa qualquer buffer e lê o arquivo para download
    flush();
    readfile($dbPath);
    exit;
} else {
    echo "<script>alert('Erro: Arquivo de banco de dados não encontrado.'); window.location.href='index.php';</script>";
}