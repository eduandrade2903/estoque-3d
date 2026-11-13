<?php

class Database {

    private static ?PDO $instance = null;

    public static function getConnection(): PDO { 
        if(self::$instance === null) {
            $dbPath = file_exists('/var/www/database/estoque.sqlite') 
            ? '/var/www/database/estoque.sqlite'
            : __DIR__ . '/../../database/estoque.sqlite';

            self::$instance = new PDO("sqlite:". $dbPath);
            self::$instance->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
            self::$instance->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);

            self::$instance->exec("PRAGMA foreign_keys = ON");
            self::$instance->exec("PRAGMA journal_mode = WAL");
        }
        return self::$instance;
    }
       

}