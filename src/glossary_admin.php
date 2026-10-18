<?php
declare(strict_types=1);

function glossary_admin_term(int $id): ?array {
    $term=q('SELECT * FROM glossary_terms WHERE id=?',[$id])->fetch();
    return $term ?: null;
}

function glossary_admin_categories(): array {
    return q('SELECT c.id,c.slug,c.name,COUNT(tc.term_id) AS term_count FROM glossary_categories c LEFT JOIN glossary_term_categories tc ON tc.category_id=c.id GROUP BY c.id,c.slug,c.name ORDER BY c.name')->fetchAll();
}

function glossary_admin_terms(string $search='',string $category='',string $status=''): array {
    $where=[]; $params=[];
    if ($search!=='') { $where[]='(t.term_en LIKE ? OR t.term_fa LIKE ? OR t.search_text LIKE ?)'; $needle='%'.mb_substr($search,0,100).'%'; $params=[$needle,$needle,$needle]; }
    if ($category!=='' && preg_match('/^[a-z0-9-]+$/',$category)) { $where[]='EXISTS (SELECT 1 FROM glossary_term_categories tc JOIN glossary_categories c ON c.id=tc.category_id WHERE tc.term_id=t.id AND c.slug=?)'; $params[]=$category; }
    if (in_array($status,['draft','review','published','archived'],true)) { $where[]='t.publication_status=?'; $params[]=$status; }
    $sql='SELECT t.id,t.slug,t.term_en,t.term_fa,t.publication_status,t.difficulty_level,t.reviewed_at,t.updated_at,GROUP_CONCAT(c.name) AS categories FROM glossary_terms t LEFT JOIN glossary_term_categories tc ON tc.term_id=t.id LEFT JOIN glossary_categories c ON c.id=tc.category_id';
    if ($where) $sql.=' WHERE '.implode(' AND ',$where);
    $sql.=' GROUP BY t.id ORDER BY t.updated_at DESC,t.id DESC LIMIT 200';
    return q($sql,$params)->fetchAll();
}

function glossary_admin_json_lines(string $value): array {
    $items=[];
    foreach (preg_split('/\R/u',$value) ?: [] as $line) { $line=trim($line); if ($line!=='') $items[]=$line; }
    return array_values(array_unique(array_slice($items,0,50)));
}

function glossary_admin_slug(string $value): string {
    $value=strtolower(trim($value));
    $value=preg_replace('/[^a-z0-9-]+/','-',$value) ?? '';
    return trim($value,'-');
}

function glossary_admin_term_data(array $post,int $id=0): array {
    $slug=glossary_admin_slug((string)($post['slug'] ?? ''));
    $termEn=trim((string)($post['term_en'] ?? ''));
    $termFa=trim((string)($post['term_fa'] ?? ''));
    $short=trim((string)($post['short_definition'] ?? ''));
    $full=trim((string)($post['full_definition'] ?? ''));
    $difficulty=(string)($post['difficulty_level'] ?? 'intermediate');
    $reviewed=trim((string)($post['reviewed_at'] ?? ''));
    if ($slug==='' || mb_strlen($slug)>160 || !preg_match('/^[a-z0-9]+(?:-[a-z0-9]+)*$/',$slug)) throw new InvalidArgumentException('شناسهٔ انگلیسی معتبر وارد کنید.');
    if (mb_strlen($termEn)<2 || mb_strlen($termEn)>160 || mb_strlen($termFa)<2 || mb_strlen($termFa)>200) throw new InvalidArgumentException('نام انگلیسی و فارسی باید معتبر و کامل باشند.');
    if (mb_strlen($short)<10 || mb_strlen($short)>500 || mb_strlen($full)>6000) throw new InvalidArgumentException('تعریف کوتاه باید بین ۱۰ تا ۵۰۰ نویسه باشد و تعریف کامل حداکثر ۶۰۰۰ نویسه.');
    if (!in_array($difficulty,['beginner','intermediate','advanced'],true)) throw new InvalidArgumentException('سطح دشواری معتبر نیست.');
    if ($reviewed!=='' && !preg_match('/^\d{4}-\d{2}-\d{2}$/',$reviewed)) throw new InvalidArgumentException('تاریخ بازبینی باید به شکل YYYY-MM-DD باشد.');
    $duplicate=q('SELECT id,term_en FROM glossary_terms WHERE id<>? AND (slug=? OR canonical_key=? OR LOWER(term_en)=LOWER(?) OR term_fa=?) LIMIT 1',[$id,$slug,db_glossary_normalize($termEn),$termEn,$termFa])->fetch();
    if ($duplicate) throw new InvalidArgumentException('این اصطلاح تکراری است: '.(string)$duplicate['term_en']);
    return [
        'slug'=>$slug,'term_en'=>$termEn,'term_fa'=>$termFa,'alternative_translations'=>glossary_admin_json_lines((string)($post['alternative_translations'] ?? '')),
        'abbreviation'=>trim((string)($post['abbreviation'] ?? '')),'short_definition'=>$short,'full_definition'=>$full,
        'beginner_explanation'=>trim((string)($post['beginner_explanation'] ?? '')),'technical_explanation'=>trim((string)($post['technical_explanation'] ?? '')),
        'advanced_explanation'=>trim((string)($post['advanced_explanation'] ?? '')),'how_it_works'=>trim((string)($post['how_it_works'] ?? '')),
        'practical_example'=>trim((string)($post['practical_example'] ?? '')),'code_example'=>trim((string)($post['code_example'] ?? '')),
        'use_cases'=>glossary_admin_json_lines((string)($post['use_cases'] ?? '')),'advantages'=>glossary_admin_json_lines((string)($post['advantages'] ?? '')),
        'limitations'=>glossary_admin_json_lines((string)($post['limitations'] ?? '')),'difficulty_level'=>$difficulty,
        'reviewed_at'=>$reviewed!==''?$reviewed:null,'category_ids'=>array_values(array_unique(array_filter(array_map('intval',(array)($post['category_ids'] ?? [])),fn(int $id): bool=>$id>0)))
    ];
}

function glossary_admin_snapshot(array $data): string {
    return json_encode($data,JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE);
}

function glossary_admin_save(): never {
    $admin=require_admin();
    $id=filter_var($_POST['id'] ?? 0,FILTER_VALIDATE_INT,['options'=>['min_range'=>0]]);
    if ($id===false || $id===null) throw new InvalidArgumentException('شناسهٔ اصطلاح معتبر نیست.');
    $data=glossary_admin_term_data($_POST,(int)$id);
    $pdo=db(); $pdo->beginTransaction();
    try {
        $search=db_glossary_normalize(implode(' ',[$data['term_en'],$data['term_fa'],implode(' ',$data['alternative_translations']),$data['abbreviation'],$data['short_definition'],$data['full_definition']]));
        $json=fn(array $items): string=>json_encode($items,JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE);
        if ($id===0) {
            q('INSERT INTO glossary_terms(slug,term_en,term_fa,letter,alternative_translations,abbreviation,short_definition,full_definition,beginner_explanation,technical_explanation,advanced_explanation,how_it_works,practical_example,code_example,use_cases,advantages,limitations,search_text,difficulty_level,canonical_key,publication_status,reviewed_at) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)',[$data['slug'],$data['term_en'],$data['term_fa'],strtoupper(substr($data['term_en'],0,1)),$json($data['alternative_translations']),$data['abbreviation'],$data['short_definition'],$data['full_definition'],$data['beginner_explanation'],$data['technical_explanation'],$data['advanced_explanation'],$data['how_it_works'],$data['practical_example'],$data['code_example'],$json($data['use_cases']),$json($data['advantages']),$json($data['limitations']),$search, $data['difficulty_level'],db_glossary_normalize($data['term_en']), 'draft',$data['reviewed_at']]);
            $id=(int)$pdo->lastInsertId();
        } else {
            if (!glossary_admin_term($id)) throw new InvalidArgumentException('اصطلاح پیدا نشد.');
            q('UPDATE glossary_terms SET slug=?,term_en=?,term_fa=?,letter=?,alternative_translations=?,abbreviation=?,short_definition=?,full_definition=?,beginner_explanation=?,technical_explanation=?,advanced_explanation=?,how_it_works=?,practical_example=?,code_example=?,use_cases=?,advantages=?,limitations=?,search_text=?,difficulty_level=?,canonical_key=?,reviewed_at=?,updated_at=CURRENT_TIMESTAMP WHERE id=?',[$data['slug'],$data['term_en'],$data['term_fa'],strtoupper(substr($data['term_en'],0,1)),$json($data['alternative_translations']),$data['abbreviation'],$data['short_definition'],$data['full_definition'],$data['beginner_explanation'],$data['technical_explanation'],$data['advanced_explanation'],$data['how_it_works'],$data['practical_example'],$data['code_example'],$json($data['use_cases']),$json($data['advantages']),$json($data['limitations']),$search,$data['difficulty_level'],db_glossary_normalize($data['term_en']),$data['reviewed_at'],$id]);
        }
        q('DELETE FROM glossary_term_categories WHERE term_id=?',[$id]);
        $categoryIds=$data['category_ids'];
        if ($categoryIds) {
            $placeholders=implode(',',array_fill(0,count($categoryIds),'?'));
            $valid=q("SELECT id FROM glossary_categories WHERE id IN ($placeholders)",$categoryIds)->fetchAll(PDO::FETCH_COLUMN);
            foreach ($valid as $categoryId) q('INSERT INTO glossary_term_categories(term_id,category_id) VALUES (?,?)',[$id,(int)$categoryId]);
        }
        q('INSERT INTO glossary_revisions(term_id,editor_id,snapshot_json) VALUES (?,?,?)',[$id,$admin['id'],glossary_admin_snapshot($data)]);
        $pdo->commit();
    } catch (Throwable $e) { if ($pdo->inTransaction()) $pdo->rollBack(); throw $e; }
    flash($id && (int)($_POST['id'] ?? 0) ? 'اصطلاح ویرایش و به‌صورت پیش‌نویس ذخیره شد.' : 'اصطلاح جدید به‌صورت پیش‌نویس ذخیره شد.');
    redirect('dashboard/glossary/edit?id='.$id);
}

function glossary_admin_publish(bool $publish): never {
    $admin=require_admin();
    $id=filter_var($_POST['id'] ?? 0,FILTER_VALIDATE_INT,['options'=>['min_range'=>1]]);
    if (!$id) { http_response_code(422); exit('شناسهٔ اصطلاح معتبر نیست.'); }
    $term=glossary_admin_term($id);
    if (!$term) { http_response_code(404); exit('اصطلاح پیدا نشد.'); }
    if ($publish) {
        if (trim((string)$term['reviewed_at'])==='') { flash('پیش از انتشار، تاریخ بازبینی انسانی را ثبت کنید.','error'); redirect('dashboard/glossary/edit?id='.$id); }
        if ((int)q('SELECT COUNT(*) FROM glossary_term_sources WHERE term_id=?',[$id])->fetchColumn()<1) { flash('پیش از انتشار، دست‌کم یک منبع معتبر ثبت کنید.','error'); redirect('dashboard/glossary/edit?id='.$id); }
        q("UPDATE glossary_terms SET publication_status='published',updated_at=CURRENT_TIMESTAMP WHERE id=?",[$id]);
        flash('اصطلاح منتشر شد.');
    } else { q("UPDATE glossary_terms SET publication_status='draft',updated_at=CURRENT_TIMESTAMP WHERE id=?",[$id]); flash('انتشار اصطلاح لغو شد.'); }
    q('INSERT INTO glossary_revisions(term_id,editor_id,snapshot_json) VALUES (?,?,?)',[$id,$admin['id'],json_encode(['action'=>$publish?'publish':'unpublish','status'=>$publish?'published':'draft'],JSON_THROW_ON_ERROR)]);
    redirect('dashboard/glossary/edit?id='.$id);
}

function glossary_admin_delete(): never {
    $admin=require_admin();
    $id=filter_var($_POST['id'] ?? 0,FILTER_VALIDATE_INT,['options'=>['min_range'=>1]]);
    $term=$id?glossary_admin_term((int)$id):null;
    if (!$term) { http_response_code(404); exit('اصطلاح پیدا نشد.'); }
    if ($term['publication_status']==='published') { flash('اصطلاح منتشرشده حذف نمی‌شود؛ ابتدا انتشار را لغو کنید.','error'); redirect('dashboard/glossary/edit?id='.$id); }
    if ((string)($_POST['confirm'] ?? '')!=='DELETE') { flash('برای حذف کنترل‌شده، عبارت DELETE را وارد کنید.','error'); redirect('dashboard/glossary/edit?id='.$id); }
    q('INSERT INTO glossary_revisions(term_id,editor_id,snapshot_json) VALUES (?,?,?)',[$id,$admin['id'],json_encode(['action'=>'delete','slug'=>$term['slug']],JSON_THROW_ON_ERROR)]);
    q('DELETE FROM glossary_terms WHERE id=?',[$id]);
    flash('اصطلاح پیش‌نویس حذف شد.'); redirect('dashboard/glossary');
}

function glossary_admin_category_save(): never {
    require_admin();
    $id=filter_var($_POST['id'] ?? 0,FILTER_VALIDATE_INT,['options'=>['min_range'=>0]]) ?: 0;
    $name=trim((string)($_POST['name'] ?? '')); $slug=glossary_admin_slug((string)($_POST['slug'] ?? $name));
    if (mb_strlen($name)<2 || mb_strlen($name)>100 || !preg_match('/^[a-z0-9]+(?:-[a-z0-9]+)*$/',$slug)) { flash('نام و slug دسته‌بندی معتبر نیست.','error'); redirect('dashboard/glossary'); }
    try { if ($id) q('UPDATE glossary_categories SET name=? WHERE id=?',[$name,$id]); else q('INSERT INTO glossary_categories(slug,name) VALUES (?,?)',[$slug,$name]); flash('دسته‌بندی ذخیره شد.'); }
    catch (Throwable $e) { flash('این slug یا نام دسته‌بندی قبلاً ثبت شده است.','error'); }
    redirect('dashboard/glossary');
}

function glossary_admin_category_delete(): never {
    require_admin(); $id=filter_var($_POST['id'] ?? 0,FILTER_VALIDATE_INT,['options'=>['min_range'=>1]]);
    if (!$id) { http_response_code(422); exit('دسته‌بندی معتبر نیست.'); }
    if ((int)q('SELECT COUNT(*) FROM glossary_term_categories WHERE category_id=?',[$id])->fetchColumn()>0) { flash('دسته‌بندی دارای اصطلاح است و حذف نمی‌شود.','error'); redirect('dashboard/glossary'); }
    q('DELETE FROM glossary_categories WHERE id=?',[$id]); flash('دسته‌بندی حذف شد.'); redirect('dashboard/glossary');
}

function glossary_admin_relation_save(): never {
    require_admin(); $termId=(int)($_POST['term_id'] ?? 0); $related=(int)($_POST['related_term_id'] ?? 0); $type=(string)($_POST['relation_type'] ?? 'related');
    if (!$termId || !$related || $termId===$related || !in_array($type,['related','prerequisite','successor','broader','narrower'],true) || !glossary_admin_term($termId) || !glossary_admin_term($related)) { flash('اصطلاح مرتبط معتبر نیست.','error'); redirect('dashboard/glossary/edit?id='.$termId); }
    $exists=q('SELECT 1 FROM glossary_term_relations WHERE term_id=? AND related_term_id=? AND relation_type=?',[$termId,$related,$type])->fetchColumn();
    if (!$exists) q('INSERT INTO glossary_term_relations(term_id,related_term_id,relation_type) VALUES (?,?,?)',[$termId,$related,$type]); flash('رابطهٔ اصطلاح ثبت شد.'); redirect('dashboard/glossary/edit?id='.$termId);
}

function glossary_admin_relation_delete(): never {
    require_admin(); $termId=(int)($_POST['term_id'] ?? 0); $related=(int)($_POST['related_term_id'] ?? 0); $type=(string)($_POST['relation_type'] ?? 'related');
    q('DELETE FROM glossary_term_relations WHERE term_id=? AND related_term_id=? AND relation_type=?',[$termId,$related,$type]); flash('رابطه حذف شد.'); redirect('dashboard/glossary/edit?id='.$termId);
}

function glossary_admin_source_save(): never {
    require_admin(); $termId=(int)($_POST['term_id'] ?? 0); $title=trim((string)($_POST['title'] ?? '')); $publisher=trim((string)($_POST['publisher'] ?? '')); $url=trim((string)($_POST['url'] ?? ''));
    if (!$termId || !glossary_admin_term($termId) || mb_strlen($title)<2 || mb_strlen($title)>200 || ($url!=='' && !filter_var($url,FILTER_VALIDATE_URL))) { flash('اطلاعات منبع معتبر نیست.','error'); redirect('dashboard/glossary/edit?id='.$termId); }
    $source=$url!=='' ? q('SELECT id FROM glossary_sources WHERE identifier=? OR url=? LIMIT 1',[$url,$url])->fetchColumn() : null;
    if (!$source) { q('INSERT INTO glossary_sources(title,publisher,source_type,url,identifier) VALUES (?,?,?,?,?)',[$title,$publisher,'reference',$url,$url!==''?$url:null]); $source=(int)db()->lastInsertId(); }
    $exists=q('SELECT 1 FROM glossary_term_sources WHERE term_id=? AND source_id=?',[$termId,(int)$source])->fetchColumn();
    if (!$exists) q('INSERT INTO glossary_term_sources(term_id,source_id) VALUES (?,?)',[$termId,(int)$source]); flash('منبع به اصطلاح متصل شد.'); redirect('dashboard/glossary/edit?id='.$termId);
}

function glossary_admin_source_delete(): never {
    require_admin(); $termId=(int)($_POST['term_id'] ?? 0); $sourceId=(int)($_POST['source_id'] ?? 0); q('DELETE FROM glossary_term_sources WHERE term_id=? AND source_id=?',[$termId,$sourceId]); flash('منبع از اصطلاح جدا شد.'); redirect('dashboard/glossary/edit?id='.$termId);
}

function glossary_admin_post(string $path): bool {
    if (!str_starts_with($path,'glossary/admin/')) return false;
    try {
        return match ($path) {
            'glossary/admin/save'=>glossary_admin_save(),
            'glossary/admin/publish'=>glossary_admin_publish(true),
            'glossary/admin/unpublish'=>glossary_admin_publish(false),
            'glossary/admin/delete'=>glossary_admin_delete(),
            'glossary/admin/category-save'=>glossary_admin_category_save(),
            'glossary/admin/category-delete'=>glossary_admin_category_delete(),
            'glossary/admin/relation-save'=>glossary_admin_relation_save(),
            'glossary/admin/relation-delete'=>glossary_admin_relation_delete(),
            'glossary/admin/source-save'=>glossary_admin_source_save(),
            'glossary/admin/source-delete'=>glossary_admin_source_delete(),
            default=>false,
        };
    } catch (InvalidArgumentException $e) {
        flash($e->getMessage(),'error');
        $id=filter_var($_POST['id'] ?? 0,FILTER_VALIDATE_INT,['options'=>['min_range'=>1]]);
        redirect($id ? 'dashboard/glossary/edit?id='.$id : 'dashboard/glossary');
    }
}

function glossary_admin_field(string $label,string $name,string $value='',string $type='text',string $attrs=''): void {
    echo '<label>'.h($label);
    if ($type==='textarea') echo '<textarea name="'.h($name).'" '.$attrs.'>'.h($value).'</textarea>';
    else echo '<input type="'.h($type).'" name="'.h($name).'" value="'.h($value).'" '.$attrs.'>';
    echo '</label>';
}

function glossary_admin_lines(array|string $items): string { return is_array($items) ? implode("\n",$items) : $items; }

function glossary_admin_form(?array $term,array $categories): void {
    $edit=$term!==null; $jsonFields=['alternative_translations','use_cases','advantages','limitations'];
    foreach ($jsonFields as $field) if ($term!==null) $term[$field]=json_decode((string)$term[$field],true) ?: [];
    $selected=$term ? array_map('intval',q('SELECT category_id FROM glossary_term_categories WHERE term_id=?',[$term['id']])->fetchAll(PDO::FETCH_COLUMN)) : [];
    echo '<section class="panel-card"><h2>'.($edit?'ویرایش اصطلاح':'ایجاد اصطلاح').'</h2><p class="muted">ذخیرهٔ این فرم همیشه پیش‌نویس است. انتشار فقط پس از ثبت تاریخ بازبینی انسانی ممکن است.</p>'; form_start('glossary/admin/save','stack-form');
    if ($edit) echo '<input type="hidden" name="id" value="'.(int)$term['id'].'">';
    echo '<div class="form-grid">'; glossary_admin_field('Slug انگلیسی','slug',$term['slug'] ?? ''); glossary_admin_field('نام انگلیسی','term_en',$term['term_en'] ?? ''); glossary_admin_field('معادل فارسی','term_fa',$term['term_fa'] ?? ''); glossary_admin_field('مخفف','abbreviation',$term['abbreviation'] ?? ''); echo '</div>';
    glossary_admin_field('ترجمه‌های جایگزین، هر مورد در یک خط','alternative_translations',glossary_admin_lines($term['alternative_translations'] ?? []),'textarea','rows="3"');
    glossary_admin_field('تعریف کوتاه','short_definition',$term['short_definition'] ?? '','textarea','rows="3" required minlength="10" maxlength="500"');
    glossary_admin_field('تعریف کامل','full_definition',$term['full_definition'] ?? '','textarea','rows="6" maxlength="6000"');
    glossary_admin_field('توضیح مبتدی','beginner_explanation',$term['beginner_explanation'] ?? '','textarea','rows="4"'); glossary_admin_field('توضیح فنی','technical_explanation',$term['technical_explanation'] ?? '','textarea','rows="4"'); glossary_admin_field('توضیح پیشرفته','advanced_explanation',$term['advanced_explanation'] ?? '','textarea','rows="4"'); glossary_admin_field('نحوهٔ عملکرد','how_it_works',$term['how_it_works'] ?? '','textarea','rows="4"'); glossary_admin_field('مثال کاربردی','practical_example',$term['practical_example'] ?? '','textarea','rows="4"'); glossary_admin_field('مثال کدنویسی','code_example',$term['code_example'] ?? '','textarea','rows="5" dir="ltr"');
    foreach ([['use_cases','کاربردها'],['advantages','مزایا'],['limitations','محدودیت‌ها']] as [$name,$label]) glossary_admin_field($label.'، هر مورد در یک خط',$name,glossary_admin_lines($term[$name] ?? []),'textarea','rows="4"');
    echo '<div class="form-grid"><label>سطح دشواری<select name="difficulty_level"><option value="beginner"'.(($term['difficulty_level'] ?? '')==='beginner'?' selected':'').'>مقدماتی</option><option value="intermediate"'.(($term['difficulty_level'] ?? 'intermediate')==='intermediate'?' selected':'').'>متوسط</option><option value="advanced"'.(($term['difficulty_level'] ?? '')==='advanced'?' selected':'').'>پیشرفته</option></select></label><label>تاریخ بازبینی انسانی<input type="date" name="reviewed_at" value="'.h($term['reviewed_at'] ?? '').'" max="'.date('Y-m-d').'" ></label></div><fieldset><legend>دسته‌بندی</legend><div class="check-list">'; foreach ($categories as $category) echo '<label><input type="checkbox" name="category_ids[]" value="'.(int)$category['id'].'"'.(in_array((int)$category['id'],$selected,true)?' checked':'').'><span>'.h($category['name']).'</span></label>'; echo '</div></fieldset><button class="btn btn-primary">ذخیره پیش‌نویس</button></form></section>';
}

function glossary_admin_detail(int $id): void {
    $term=glossary_admin_term($id); if (!$term) { http_response_code(404); echo '<div class="empty-state">اصطلاح پیدا نشد.</div>'; return; }
    glossary_admin_form($term,glossary_admin_categories());
    $related=q('SELECT r.related_term_id,r.relation_type,t.term_en,t.term_fa FROM glossary_term_relations r JOIN glossary_terms t ON t.id=r.related_term_id WHERE r.term_id=? ORDER BY r.sort_order,t.term_en',[$id])->fetchAll();
    echo '<section class="panel-card"><h2>اصطلاحات مرتبط</h2><form method="post" action="'.h(url('glossary/admin/relation-save')).'" class="form-grid"><input type="hidden" name="csrf" value="'.h(csrf()).'"><input type="hidden" name="term_id" value="'.$id.'"><label>اصطلاح<select name="related_term_id">'; foreach (q('SELECT id,term_en,term_fa FROM glossary_terms WHERE id<>? ORDER BY term_en LIMIT 1000',[$id])->fetchAll() as $candidate) echo '<option value="'.(int)$candidate['id'].'">'.h($candidate['term_en'].' — '.$candidate['term_fa']).'</option>'; echo '</select></label><label>نوع رابطه<select name="relation_type"><option value="related">مرتبط</option><option value="prerequisite">پیش‌نیاز</option><option value="successor">پس‌نیاز</option><option value="broader">کلی‌تر</option><option value="narrower">جزئی‌تر</option></select></label><button class="btn btn-outline">افزودن رابطه</button></form><div class="panel-list">'; foreach ($related as $row) { echo '<div class="list-item"><span>'.h($row['term_en'].' — '.$row['term_fa'].' · '.$row['relation_type']).'</span><form method="post" action="'.h(url('glossary/admin/relation-delete')).'"><input type="hidden" name="csrf" value="'.h(csrf()).'"><input type="hidden" name="term_id" value="'.$id.'"><input type="hidden" name="related_term_id" value="'.(int)$row['related_term_id'].'"><input type="hidden" name="relation_type" value="'.h($row['relation_type']).'"><button class="btn btn-small btn-outline">حذف</button></form></div>'; } echo '</div></section>';
    $sources=q('SELECT s.id,s.title,s.publisher,s.url FROM glossary_sources s JOIN glossary_term_sources ts ON ts.source_id=s.id WHERE ts.term_id=? ORDER BY ts.citation_order,s.id',[$id])->fetchAll();
    echo '<section class="panel-card"><h2>منابع</h2><form method="post" action="'.h(url('glossary/admin/source-save')).'" class="form-grid"><input type="hidden" name="csrf" value="'.h(csrf()).'"><input type="hidden" name="term_id" value="'.$id.'"><label>عنوان منبع<input name="title" required maxlength="200"></label><label>ناشر<input name="publisher" maxlength="160"></label><label>URL<input name="url" type="url" dir="ltr" maxlength="1000"></label><button class="btn btn-outline">افزودن منبع</button></form><div class="panel-list">'; foreach ($sources as $source) { echo '<div class="list-item"><div><strong>'.h($source['title']).'</strong><small class="muted">'.h($source['publisher'] ?? '').' · '.h($source['url'] ?? 'بدون URL').'</small></div><form method="post" action="'.h(url('glossary/admin/source-delete')).'"><input type="hidden" name="csrf" value="'.h(csrf()).'"><input type="hidden" name="term_id" value="'.$id.'"><input type="hidden" name="source_id" value="'.(int)$source['id'].'"><button class="btn btn-small btn-outline">جداکردن</button></form></div>'; } echo '</div></section>';
    $history=q('SELECT r.created_at,r.snapshot_json,u.name,u.phone FROM glossary_revisions r LEFT JOIN users u ON u.id=r.editor_id WHERE r.term_id=? ORDER BY r.id DESC LIMIT 20',[$id])->fetchAll(); echo '<section class="panel-card"><h2>تاریخچه تغییرات</h2><div class="panel-list">'; foreach ($history as $revision) echo '<div class="list-item"><span>'.h(($revision['name'] ?: $revision['phone'] ?: 'سیستم').' · '.$revision['created_at']).'</span><code dir="ltr">'.h(mb_substr($revision['snapshot_json'],0,180)).'</code></div>'; echo '</div></section>';
    echo '<section class="panel-card admin-danger"><h2>عملیات حساس</h2><div class="form-grid"><form method="post" action="'.h(url('glossary/admin/'.($term['publication_status']==='published'?'unpublish':'publish'))).'" class="stack-form"><input type="hidden" name="csrf" value="'.h(csrf()).'"><input type="hidden" name="id" value="'.$id.'"><button class="btn btn-primary">'.($term['publication_status']==='published'?'لغو انتشار':'انتشار اصطلاح').'</button></form><form method="post" action="'.h(url('glossary/admin/delete')).'" class="stack-form"><input type="hidden" name="csrf" value="'.h(csrf()).'"><input type="hidden" name="id" value="'.$id.'"><input name="confirm" placeholder="DELETE برای حذف پیش‌نویس" pattern="DELETE"><button class="btn btn-outline">حذف کنترل‌شده</button></form></div></section>';
}

function admin_glossary_panel(string $sub): void {
    if (preg_match('~^glossary/edit$~',$sub)) { $id=filter_var($_GET['id'] ?? 0,FILTER_VALIDATE_INT,['options'=>['min_range'=>1]]); panel_head('ویرایش اصطلاح','محتوای واژه‌نامه و روابط آن.'); glossary_admin_detail((int)$id); return; }
    if ($sub==='glossary/new') { panel_head('اصطلاح جدید','اصطلاح جدید ابتدا به‌صورت پیش‌نویس ذخیره می‌شود.'); glossary_admin_form(null,glossary_admin_categories()); return; }
    panel_head('مدیریت واژه‌نامه','جست‌وجو، بررسی، ویرایش و انتشار کنترل‌شدهٔ اصطلاحات.');
    $search=trim((string)($_GET['q'] ?? '')); $category=(string)($_GET['category'] ?? ''); $status=(string)($_GET['status'] ?? '');
    echo '<section class="panel-card"><form method="get" class="form-grid"><input type="hidden" name="path" value="dashboard/glossary"><label>جست‌وجو<input name="q" value="'.h($search).'" maxlength="100"></label><label>دسته‌بندی<select name="category"><option value="">همه</option>'; foreach (glossary_admin_categories() as $cat) echo '<option value="'.h($cat['slug']).'"'.($category===$cat['slug']?' selected':'').'>'.h($cat['name']).'</option>'; echo '</select></label><label>وضعیت<select name="status"><option value="">همه</option>'; foreach (['draft'=>'پیش‌نویس','review'=>'در بررسی','published'=>'منتشرشده','archived'=>'بایگانی‌شده'] as $key=>$label) echo '<option value="'.$key.'"'.($status===$key?' selected':'').'>'.$label.'</option>'; echo '</select></label><button class="btn btn-outline">اعمال فیلتر</button></form><p>'; linkto('dashboard/glossary/new','ایجاد اصطلاح جدید','btn btn-primary'); echo '</p></section>';
    echo '<section class="panel-card table-wrap"><table><thead><tr><th>اصطلاح</th><th>دسته‌بندی</th><th>وضعیت</th><th>بازبینی</th><th>عملیات</th></tr></thead><tbody>'; $rows=glossary_admin_terms($search,$category,$status); if (!$rows) echo '<tr><td colspan="5" class="muted">اصطلاحی پیدا نشد.</td></tr>'; foreach ($rows as $row) { $labels=['draft'=>'پیش‌نویس','review'=>'در بررسی','published'=>'منتشرشده','archived'=>'بایگانی‌شده']; echo '<tr><td><strong>'.h($row['term_en']).'</strong><small>'.h($row['term_fa']).'</small></td><td>'.h($row['categories'] ?? '—').'</td><td>'.h($labels[$row['publication_status']] ?? $row['publication_status']).'</td><td>'.h($row['reviewed_at'] ?: 'ثبت نشده').'</td><td>'; linkto('dashboard/glossary/edit?id='.(int)$row['id'],'ویرایش','btn btn-small btn-outline'); echo '</td></tr>'; } echo '</tbody></table></section>';
    echo '<section class="panel-card"><h2>مدیریت دسته‌بندی‌ها</h2><form method="post" action="'.h(url('glossary/admin/category-save')).'" class="form-grid"><input type="hidden" name="csrf" value="'.h(csrf()).'"><label>نام دسته‌بندی<input name="name" required maxlength="100"></label><label>Slug انگلیسی<input name="slug" required pattern="[a-z0-9-]+" maxlength="100"></label><button class="btn btn-outline">افزودن دسته‌بندی</button></form><div class="panel-list">'; foreach (glossary_admin_categories() as $cat) { echo '<div class="list-item"><form method="post" action="'.h(url('glossary/admin/category-save')).'" class="form-grid"><input type="hidden" name="csrf" value="'.h(csrf()).'"><input type="hidden" name="id" value="'.(int)$cat['id'].'"><input type="hidden" name="slug" value="'.h($cat['slug']).'"><input name="name" value="'.h($cat['name']).'" required maxlength="100"><button class="btn btn-small btn-outline">ذخیره</button></form><span class="muted">'.fa($cat['term_count']).' اصطلاح</span>'; if (!(int)$cat['term_count']) { echo '<form method="post" action="'.h(url('glossary/admin/category-delete')).'"><input type="hidden" name="csrf" value="'.h(csrf()).'"><input type="hidden" name="id" value="'.(int)$cat['id'].'"><button class="btn btn-small btn-outline">حذف</button></form>'; } echo '</div>'; } echo '</div></section>';
}
