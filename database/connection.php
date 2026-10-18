<?php
declare(strict_types=1);

require_once dirname(__DIR__).'/config/config.php';

function db_glossary_normalize(string $value): string {
    $value=strtr(mb_strtolower($value),['ي'=>'ی','ك'=>'ک']);
    return trim(preg_replace('/\s+/u',' ',$value) ?? '');
}

function glossary_backfill_legacy(PDO $db): void {
    $hasTerms=(int)$db->query('SELECT COUNT(*) FROM glossary_terms')->fetchColumn()>0;
    $source=dirname(__DIR__).'/curriculum/glossary.json';
    if (!is_file($source)) return;
    $data=json_decode((string)file_get_contents($source),true,512,JSON_THROW_ON_ERROR);
    $db->beginTransaction();
    try {
        $taxonomy=[
            'ai-fundamentals'=>'مبانی هوش مصنوعی','python'=>'Python','math-statistics'=>'ریاضیات و آمار','machine-learning'=>'یادگیری ماشین','deep-learning'=>'یادگیری عمیق','neural-networks'=>'شبکه‌های عصبی','nlp'=>'پردازش زبان طبیعی','llm'=>'مدل‌های زبانی بزرگ','generative-ai'=>'هوش مصنوعی مولد','prompt-engineering'=>'مهندسی پرامپت','ai-agents'=>'عامل‌های هوش مصنوعی','rag'=>'RAG','embedding'=>'Embedding','vector-database'=>'Vector Database','computer-vision'=>'بینایی ماشین','audio-processing'=>'پردازش صوت','data-science'=>'علم داده','fine-tuning-peft'=>'Fine-tuning و PEFT','model-evaluation'=>'ارزیابی مدل‌ها','quantization'=>'Quantization','deployment'=>'استقرار مدل‌ها','mlops-llmops'=>'MLOps و LLMOps','ai-safety'=>'امنیت و ایمنی هوش مصنوعی','ai-infrastructure'=>'زیرساخت هوش مصنوعی'
        ];
        $legacyMap=['fundamentals'=>'ai-fundamentals','ml'=>'machine-learning','dl'=>'deep-learning','generative'=>'generative-ai','llm'=>'llm','prompt'=>'prompt-engineering','agents'=>'ai-agents','rag'=>'rag','training'=>'fine-tuning-peft','evaluation'=>'model-evaluation','ethics'=>'ai-safety','security'=>'ai-safety','application'=>'data-science','software'=>'mlops-llmops','hardware'=>'ai-infrastructure','math'=>'math-statistics','reinforcement'=>'machine-learning','deployment'=>'deployment','alignment'=>'ai-safety'];
        $categoryInsert=$db->prepare('INSERT INTO glossary_categories(slug,name) VALUES (?,?)');
        $categoryIds=[];
        foreach ($taxonomy as $slug=>$name) {
            $existing=$db->prepare('SELECT id FROM glossary_categories WHERE slug=?');
            $existing->execute([$slug]);
            $categoryIds[$slug]=(int)$existing->fetchColumn();
            if (!$categoryIds[$slug]) { $categoryInsert->execute([$slug,$name]); $categoryIds[$slug]=(int)$db->lastInsertId(); }
        }
        if ($hasTerms) { $db->commit(); return; }
        $termInsert=$db->prepare('INSERT INTO glossary_terms(slug,term_en,term_fa,letter,alternative_translations,short_definition,full_definition,canonical_key,search_text,publication_status) VALUES (?,?,?,?,?,?,?,?,?,?)');
        $termCategoryInsert=$db->prepare('INSERT INTO glossary_term_categories(term_id,category_id) VALUES (?,?)');
        $termIds=[];
        foreach ($data['entries'] as $entry) {
            $term=(string)$entry['term'];
            $title=(string)$entry['title'];
            $definition=(string)$entry['definition'];
            $termInsert->execute([(string)$entry['key'],$term,$title,strtoupper(substr($term,0,1)),'[]',$definition,$definition,db_glossary_normalize($term.' '.$title),db_glossary_normalize((string)($entry['search'] ?? $term.' '.$title.' '.$definition)),'published']);
            $termId=(int)$db->lastInsertId();
            $termIds[(string)$entry['key']]=$termId;
            if (isset($legacyMap[$entry['category']],$categoryIds[$legacyMap[$entry['category']]])) $termCategoryInsert->execute([$termId,$categoryIds[$legacyMap[$entry['category']]]]);
        }
        $relationInsert=$db->prepare("INSERT INTO glossary_term_relations(term_id,related_term_id,relation_type) VALUES (?,?, 'related')");
        $relationKeys=[];
        foreach ($data['entries'] as $entry) foreach (($entry['related'] ?? []) as $related) if (isset($termIds[$entry['key']],$termIds[$related]) && $termIds[$entry['key']]!==$termIds[$related]) {
            $relationKey=$termIds[$entry['key']].':'.$termIds[$related];
            if (isset($relationKeys[$relationKey])) continue;
            $relationKeys[$relationKey]=true;
            $relationInsert->execute([$termIds[$entry['key']],$termIds[$related]]);
        }
        $db->commit();
    } catch (Throwable $e) {
        if ($db->inTransaction()) $db->rollBack();
        throw $e;
    }
}

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
        $migration=$driver==='mysql' ? '/database/migrations/001_glossary.mysql.sql' : '/database/migrations/001_glossary.sqlite.sql';
        $db->exec(file_get_contents(dirname(__DIR__).$migration));
        glossary_backfill_legacy($db);
        $columns=$db->query('SELECT * FROM otp_codes LIMIT 0');
        $names=[];
        for ($i=0; $i<$columns->columnCount(); $i++) $names[]=$columns->getColumnMeta($i)['name'];
        if (in_array('code_hash',$names,true)) $db->exec('ALTER TABLE otp_codes RENAME COLUMN code_hash TO otp_hash');
        if (in_array('sent_at',$names,true)) $db->exec('ALTER TABLE otp_codes RENAME COLUMN sent_at TO created_at');
        if (!in_array('used_at',$names,true)) $db->exec('ALTER TABLE otp_codes ADD COLUMN used_at '.($driver==='mysql' ? 'BIGINT NULL' : 'INTEGER'));
        if (!in_array('pending_password_hash',$names,true)) $db->exec('ALTER TABLE otp_codes ADD COLUMN pending_password_hash '.($driver==='mysql' ? 'VARCHAR(255) NULL' : 'TEXT'));
        if (!in_array('pending_source',$names,true)) $db->exec("ALTER TABLE otp_codes ADD COLUMN pending_source ".($driver==='mysql' ? "VARCHAR(64) NOT NULL DEFAULT 'Unknown'" : "TEXT NOT NULL DEFAULT 'Unknown'"));
        if (!in_array('pending_campaign',$names,true)) $db->exec('ALTER TABLE otp_codes ADD COLUMN pending_campaign '.($driver==='mysql' ? 'VARCHAR(64) NULL DEFAULT NULL' : 'TEXT DEFAULT NULL'));
        try { $db->exec('ALTER TABLE users ADD COLUMN password_hash '.($driver==='mysql' ? "VARCHAR(255) NOT NULL DEFAULT ''" : "TEXT NOT NULL DEFAULT ''")); } catch (Throwable $e) { }
        $userColumns=$db->query('SELECT * FROM users LIMIT 0');
        $hasVerification=false;
        $hasAcquisitionSource=false;
        $hasAcquisitionCampaign=false;
        for ($i=0; $i<$userColumns->columnCount(); $i++) {
            $name=$userColumns->getColumnMeta($i)['name'];
            if ($name==='phone_verified_at') $hasVerification=true;
            if ($name==='acquisition_source') $hasAcquisitionSource=true;
            if ($name==='acquisition_campaign') $hasAcquisitionCampaign=true;
        }
        if (!$hasAcquisitionSource) $db->exec("ALTER TABLE users ADD COLUMN acquisition_source ".($driver==='mysql' ? "VARCHAR(64) NOT NULL DEFAULT 'Unknown'" : "TEXT NOT NULL DEFAULT 'Unknown'"));
        if (!$hasAcquisitionCampaign) $db->exec('ALTER TABLE users ADD COLUMN acquisition_campaign '.($driver==='mysql' ? 'VARCHAR(64) NULL DEFAULT NULL' : 'TEXT DEFAULT NULL'));
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
            'completed'=>$driver==='mysql' ? 'TINYINT(1) NOT NULL DEFAULT 0' : 'INTEGER NOT NULL DEFAULT 0',
            'quiz_version'=>$driver==='mysql' ? 'VARCHAR(64) NULL' : 'TEXT'
        ];
        foreach ($progressDefinitions as $name=>$definition) if (!in_array($name,$progressNames,true)) $db->exec('ALTER TABLE lesson_progress ADD COLUMN '.$name.' '.$definition);
        if (!in_array('completed',$progressNames,true)) $db->exec("UPDATE lesson_progress SET completed=CASE WHEN status='completed' THEN 1 ELSE 0 END");
        $activityColumns=$db->query('SELECT * FROM activities LIMIT 0');
        $activityNames=[];
        for ($i=0; $i<$activityColumns->columnCount(); $i++) $activityNames[]=$activityColumns->getColumnMeta($i)['name'];
        if (in_array('kind',$activityNames,true)) $db->exec('ALTER TABLE activities RENAME COLUMN kind TO event_name');
        if (!in_array('metadata',$activityNames,true)) $db->exec('ALTER TABLE activities ADD COLUMN metadata '.($driver==='mysql' ? 'JSON NULL' : 'TEXT'));
        if ($driver==='mysql') {
            if (!$db->query("SHOW INDEX FROM activities WHERE Key_name='idx_activities_event_created'")->fetch()) $db->exec('CREATE INDEX idx_activities_event_created ON activities(event_name,created_at)');
            if (!$db->query("SHOW INDEX FROM activities WHERE Key_name='idx_activities_created'")->fetch()) $db->exec('CREATE INDEX idx_activities_created ON activities(created_at)');
        } else $db->exec('CREATE INDEX IF NOT EXISTS idx_activities_event_created ON activities(event_name,created_at)');
        $runColumns=$db->query('SELECT * FROM workspace_runs LIMIT 0');
        $runNames=[];
        for ($i=0;$i<$runColumns->columnCount();$i++) $runNames[]=$runColumns->getColumnMeta($i)['name'];
        foreach (['assessment_key','assessment_version'] as $column) if (!in_array($column,$runNames,true)) $db->exec('ALTER TABLE workspace_runs ADD COLUMN '.$column.' '.($driver==='mysql'?'VARCHAR(64) NULL':'TEXT'));
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

function record_activity_event(int $userId, string $eventName, ?array $metadata=null): int {
    if (trim($eventName)==='' || mb_strlen($eventName)>80) throw new InvalidArgumentException('Invalid event name.');
    q('INSERT INTO activities(user_id,event_name,metadata) VALUES (?,?,?)',[$userId,$eventName,$metadata===null ? null : json_encode($metadata,JSON_THROW_ON_ERROR)]);
    return (int)db()->lastInsertId();
}
