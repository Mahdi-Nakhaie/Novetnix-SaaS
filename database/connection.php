<?php
declare(strict_types=1);

require_once dirname(__DIR__).'/config/config.php';

function db(): PDO {
    static $db;
    if ($db instanceof PDO) return $db;
    $dsn = (string)config_value('DB_DSN', 'sqlite:'.dirname(__DIR__).'/storage/noventix.sqlite');
    if (str_starts_with($dsn,'sqlite:')) {
        $dir=dirname(substr($dsn,7));
        if (!is_dir($dir)) mkdir($dir,0750,true);
    }
    $db = new PDO($dsn, config_value('DB_USER'), config_value('DB_PASSWORD'), [PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC, PDO::ATTR_EMULATE_PREPARES=>false]);
    if ($db->getAttribute(PDO::ATTR_DRIVER_NAME)==='sqlite') $db->exec('PRAGMA foreign_keys=ON');
    $driver=$db->getAttribute(PDO::ATTR_DRIVER_NAME);
    $schema=$driver==='mysql' ? '/database/schema.mysql.sql' : '/database/schema.sqlite.sql';
    $db->exec(file_get_contents(dirname(__DIR__).$schema));
    $columns=$db->query('SELECT * FROM otp_codes LIMIT 0');
    $names=[];
    for ($i=0; $i<$columns->columnCount(); $i++) $names[]=$columns->getColumnMeta($i)['name'];
    if (in_array('code_hash',$names,true)) $db->exec('ALTER TABLE otp_codes RENAME COLUMN code_hash TO otp_hash');
    if (in_array('sent_at',$names,true)) $db->exec('ALTER TABLE otp_codes RENAME COLUMN sent_at TO created_at');
    if (!in_array('used_at',$names,true)) $db->exec('ALTER TABLE otp_codes ADD COLUMN used_at '.($driver==='mysql' ? 'BIGINT NULL' : 'INTEGER'));
    try { $db->exec('ALTER TABLE users ADD COLUMN password_hash '.($driver==='mysql' ? "VARCHAR(255) NOT NULL DEFAULT ''" : "TEXT NOT NULL DEFAULT ''")); } catch (Throwable $e) { }
    return $db;
}

function q(string $sql, array $params=[]): PDOStatement {
    $statement=db()->prepare($sql);
    $statement->execute($params);
    return $statement;
}
