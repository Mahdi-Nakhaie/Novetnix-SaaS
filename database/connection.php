<?php
declare(strict_types=1);

require_once dirname(__DIR__).'/config/config.php';

function db(): PDO {
    static $db;
    if ($db instanceof PDO) return $db;

    try {
        $dsn = (string)config_value('DB_DSN', 'sqlite:'.dirname(__DIR__).'/storage/noventix.sqlite');
        if (str_starts_with($dsn,'sqlite:')) {
            $dir=dirname(substr($dsn,7));
            if (!is_dir($dir) && !mkdir($dir,0750,true) && !is_dir($dir)) throw new RuntimeException('Database storage is unavailable.');
        }
        $db = new PDO($dsn, config_value('DB_USER'), config_value('DB_PASSWORD'), [PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC, PDO::ATTR_EMULATE_PREPARES=>false]);
        $driver=$db->getAttribute(PDO::ATTR_DRIVER_NAME);
        if (!in_array($driver,['sqlite','mysql'],true)) throw new RuntimeException('Unsupported database driver.');
        if ($driver==='sqlite') $db->exec('PRAGMA foreign_keys=ON');
        $schema=$driver==='mysql' ? '/database/schema.mysql.sql' : '/database/schema.sqlite.sql';
        $db->exec(file_get_contents(dirname(__DIR__).$schema));
        $columns=$db->query('SELECT * FROM otp_codes LIMIT 0');
        $names=[];
        for ($i=0; $i<$columns->columnCount(); $i++) $names[]=$columns->getColumnMeta($i)['name'];
        if (in_array('code_hash',$names,true)) $db->exec('ALTER TABLE otp_codes RENAME COLUMN code_hash TO otp_hash');
        if (in_array('sent_at',$names,true)) $db->exec('ALTER TABLE otp_codes RENAME COLUMN sent_at TO created_at');
        if (!in_array('used_at',$names,true)) $db->exec('ALTER TABLE otp_codes ADD COLUMN used_at '.($driver==='mysql' ? 'BIGINT NULL' : 'INTEGER'));
        if (!in_array('pending_password_hash',$names,true)) $db->exec('ALTER TABLE otp_codes ADD COLUMN pending_password_hash '.($driver==='mysql' ? 'VARCHAR(255) NULL' : 'TEXT'));
        try { $db->exec('ALTER TABLE users ADD COLUMN password_hash '.($driver==='mysql' ? "VARCHAR(255) NOT NULL DEFAULT ''" : "TEXT NOT NULL DEFAULT ''")); } catch (Throwable $e) { }
        $userColumns=$db->query('SELECT * FROM users LIMIT 0');
        $hasVerification=false;
        for ($i=0; $i<$userColumns->columnCount(); $i++) if ($userColumns->getColumnMeta($i)['name']==='phone_verified_at') $hasVerification=true;
        if (!$hasVerification) {
            $db->exec('ALTER TABLE users ADD COLUMN phone_verified_at '.($driver==='mysql' ? 'BIGINT NULL' : 'INTEGER'));
            $db->exec('UPDATE users SET phone_verified_at=(SELECT used_at FROM otp_codes WHERE otp_codes.phone=users.phone AND used_at IS NOT NULL) WHERE EXISTS (SELECT 1 FROM otp_codes WHERE otp_codes.phone=users.phone AND used_at IS NOT NULL)');
        }
        $courseColumns=$db->query('SELECT * FROM courses LIMIT 0');
        $hasCourseDuration=false;
        for ($i=0; $i<$courseColumns->columnCount(); $i++) if ($courseColumns->getColumnMeta($i)['name']==='estimated_duration_minutes') $hasCourseDuration=true;
        if (!$hasCourseDuration) {
            $db->exec('ALTER TABLE courses ADD COLUMN estimated_duration_minutes '.($driver==='mysql' ? 'INT NULL' : 'INTEGER CHECK(estimated_duration_minutes IS NULL OR estimated_duration_minutes>0)'));
            if ($driver==='mysql') $db->exec('ALTER TABLE courses ADD CONSTRAINT chk_courses_duration CHECK (estimated_duration_minutes IS NULL OR estimated_duration_minutes>0)');
        }
        $lessonColumns=$db->query('SELECT * FROM lessons LIMIT 0');
        $hasLessonDuration=false;
        $hasLessonStatus=false;
        for ($i=0; $i<$lessonColumns->columnCount(); $i++) {
            $name=$lessonColumns->getColumnMeta($i)['name'];
            if ($name==='duration_minutes') $hasLessonDuration=true;
            if ($name==='status') $hasLessonStatus=true;
        }
        if (!$hasLessonDuration) {
            $db->exec('ALTER TABLE lessons ADD COLUMN duration_minutes '.($driver==='mysql' ? 'INT NULL' : 'INTEGER CHECK(duration_minutes IS NULL OR duration_minutes>0)'));
            if ($driver==='mysql') $db->exec('ALTER TABLE lessons ADD CONSTRAINT chk_lessons_duration CHECK (duration_minutes IS NULL OR duration_minutes>0)');
        }
        if (!$hasLessonStatus) {
            $db->exec('ALTER TABLE lessons ADD COLUMN status '.($driver==='mysql' ? "VARCHAR(20) NOT NULL DEFAULT 'draft'" : "TEXT NOT NULL DEFAULT 'draft' CHECK(status IN ('draft','published'))"));
            if ($driver==='mysql') $db->exec("ALTER TABLE lessons ADD CONSTRAINT chk_lessons_status CHECK (status IN ('draft','published'))");
        }
        $progressColumns=$db->query('SELECT * FROM lesson_progress LIMIT 0');
        $progressNames=[];
        for ($i=0; $i<$progressColumns->columnCount(); $i++) $progressNames[]=$progressColumns->getColumnMeta($i)['name'];
        $progressDefinitions=[
            'watched_seconds'=>$driver==='mysql' ? 'DOUBLE NOT NULL DEFAULT 0' : 'REAL NOT NULL DEFAULT 0',
            'last_position'=>$driver==='mysql' ? 'DOUBLE NOT NULL DEFAULT 0' : 'REAL NOT NULL DEFAULT 0',
            'percentage'=>$driver==='mysql' ? 'DOUBLE NOT NULL DEFAULT 0' : 'REAL NOT NULL DEFAULT 0',
            'completed'=>$driver==='mysql' ? 'TINYINT(1) NOT NULL DEFAULT 0' : 'INTEGER NOT NULL DEFAULT 0'
        ];
        foreach ($progressDefinitions as $name=>$definition) if (!in_array($name,$progressNames,true)) $db->exec('ALTER TABLE lesson_progress ADD COLUMN '.$name.' '.$definition);
        if (!in_array('completed',$progressNames,true)) $db->exec("UPDATE lesson_progress SET completed=CASE WHEN status='completed' THEN 1 ELSE 0 END");
        return $db;
    } catch (Throwable $e) {
        throw new RuntimeException('Database initialization failed.', 0, $e);
    }
}

function q(string $sql, array $params=[]): PDOStatement {
    $statement=db()->prepare($sql);
    $statement->execute($params);
    return $statement;
}
