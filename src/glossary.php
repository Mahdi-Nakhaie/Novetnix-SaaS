<?php
declare(strict_types=1);

function glossary_normalize(string $value): string {
    return trim(preg_replace('/\s+/u',' ',strtr(mb_strtolower($value),['ي'=>'ی','ك'=>'ک']))) ?? '';
}

function glossary_search_parts(string $query,string $category,string $letter): array {
    $query=glossary_normalize($query);
    $where=["t.publication_status='published'"];
    $params=[];
    if ($category!=='') { $where[]='EXISTS (SELECT 1 FROM glossary_term_categories tc JOIN glossary_categories c ON c.id=tc.category_id WHERE tc.term_id=t.id AND c.slug=?)'; $params[]=$category; }
    if ($letter!=='') { $where[]='t.letter=?'; $params[]=$letter; }
    if ($query!=='') {
        $like='%'.$query.'%';
        $where[]='(t.search_text LIKE ? OR t.term_en LIKE ? OR t.term_fa LIKE ? OR COALESCE(t.alternative_translations,\'\') LIKE ? OR COALESCE(t.abbreviation,\'\') LIKE ? OR t.short_definition LIKE ? OR t.full_definition LIKE ?)';
        array_push($params,$like,$like,$like,$like,$like,$like,$like);
    }
    return [implode(' AND ',$where),$params,$query];
}

function glossary_categories(): array {
    return q("SELECT c.slug AS key,c.name AS label,COUNT(DISTINCT CASE WHEN t.publication_status='published' THEN t.id END) AS count FROM glossary_categories c LEFT JOIN glossary_term_categories tc ON tc.category_id=c.id LEFT JOIN glossary_terms t ON t.id=tc.term_id GROUP BY c.id,c.slug,c.name ORDER BY c.id")->fetchAll();
}

function glossary_letter_counts(string $query='',string $category=''): array {
    [$where,$params]=glossary_search_parts($query,trim($category),'');
    $counts=[];
    foreach (q('SELECT t.letter,COUNT(*) AS count FROM glossary_terms t WHERE '.$where.' GROUP BY t.letter',$params)->fetchAll() as $row) $counts[(string)$row['letter']]=(int)$row['count'];
    return $counts;
}

function glossary_entries(string $query='',string $category='',string $letter='',int $page=1): array {
    [$where,$params,$normalizedQuery]=glossary_search_parts($query,trim($category),strtoupper(trim($letter)));
    $total=(int)q('SELECT COUNT(*) FROM glossary_terms t WHERE '.$where,$params)->fetchColumn();
    $perPage=24;
    $pages=max(1,(int)ceil($total/$perPage));
    $page=max(1,min($page,$pages));
    $offset=($page-1)*$perPage;
    $orderParams=[];
    if ($normalizedQuery!=='') {
        $exact=$normalizedQuery;
        $orderParams=[$exact,$exact,'%'.$exact.'%','%'.$exact.'%'];
        $order="CASE WHEN lower(t.term_en)=lower(?) THEN 0 WHEN t.term_fa=? THEN 1 WHEN t.term_en LIKE ? THEN 2 WHEN t.term_fa LIKE ? THEN 3 ELSE 4 END, t.term_en";
    } else $order='t.term_en';
    $sql="SELECT t.slug AS key,t.term_en AS term,t.term_fa AS title,t.letter,t.short_definition AS definition,(SELECT c.slug FROM glossary_term_categories tc JOIN glossary_categories c ON c.id=tc.category_id WHERE tc.term_id=t.id ORDER BY c.id LIMIT 1) AS category,(SELECT GROUP_CONCAT(rt.slug) FROM glossary_term_relations r JOIN glossary_terms rt ON rt.id=r.related_term_id WHERE r.term_id=t.id) AS related FROM glossary_terms t WHERE $where ORDER BY $order LIMIT ? OFFSET ?";
    $rows=q($sql,array_merge($params,$orderParams,[$perPage,$offset]))->fetchAll();
    foreach ($rows as &$row) $row['related']=$row['related'] ? explode(',',$row['related']) : [];
    unset($row);
    return ['entries'=>$rows,'total'=>$total,'page'=>$page,'pages'=>$pages,'categories'=>glossary_categories()];
}

function glossary_suggestions(string $query): array {
    $query=glossary_normalize($query);
    if ($query==='') return [];
    [$where,$params]=glossary_search_parts($query,'','');
    $rows=q("SELECT t.slug AS key,t.term_en AS term,t.term_fa AS title FROM glossary_terms t WHERE $where ORDER BY CASE WHEN lower(t.term_en)=lower(?) THEN 0 WHEN t.term_en LIKE ? THEN 1 ELSE 2 END,t.term_en LIMIT 8",array_merge($params,[$query,'%'.$query.'%']))->fetchAll();
    return array_map(static fn(array $row): array => ['key'=>$row['key'],'term'=>$row['term'],'title'=>$row['title']],$rows);
}

function glossary_entry(string $key): ?array {
    $entry=q("SELECT t.* FROM glossary_terms t WHERE t.slug=? AND t.publication_status='published'",[$key])->fetch();
    if (!$entry) return null;
    $entry['alternative_translations']=json_decode((string)$entry['alternative_translations'],true,512,JSON_THROW_ON_ERROR);
    foreach (['use_cases','advantages','limitations'] as $field) $entry[$field]=json_decode((string)$entry[$field],true,512,JSON_THROW_ON_ERROR);
    $entry['categories']=q("SELECT c.slug AS key,c.name AS label FROM glossary_term_categories tc JOIN glossary_categories c ON c.id=tc.category_id WHERE tc.term_id=? ORDER BY c.id",[$entry['id']])->fetchAll();
    $entry['related_terms']=q("SELECT rt.slug AS key,rt.term_en AS term,rt.term_fa AS title,r.relation_type FROM glossary_term_relations r JOIN glossary_terms rt ON rt.id=r.related_term_id WHERE r.term_id=? AND rt.publication_status='published' ORDER BY r.sort_order,rt.term_en",[$entry['id']])->fetchAll();
    $entry['sources']=q("SELECT s.title,s.publisher,s.source_type,s.url,s.identifier,s.published_at FROM glossary_term_sources ts JOIN glossary_sources s ON s.id=ts.source_id WHERE ts.term_id=? ORDER BY ts.citation_order,s.id",[$entry['id']])->fetchAll();
    return $entry;
}

function glossary_category_label(array $categories,string $key): string {
    foreach ($categories as $category) if ($category['key']===$key) return (string)$category['label'];
    return $key;
}

function glossary_query_url(string $query,string $category,string $letter='',int $page=1): string {
    $params=[];
    if ($query!=='') $params['q']=$query;
    if ($category!=='') $params['category']=$category;
    if ($letter!=='') $params['letter']=$letter;
    if ($page>1) $params['page']=(string)$page;
    return url('glossary'.($params ? '?'.http_build_query($params,'','&',PHP_QUERY_RFC3986) : ''));
}

function page_glossary(array $result,string $query,string $category,string $letter=''): void {
    $letters=range('A','Z');
    $letterCounts=glossary_letter_counts($query,$category);
    $data=['entries'=>q("SELECT COUNT(*) FROM glossary_terms WHERE publication_status='published'")->fetchColumn(),'categories'=>$result['categories']];
    echo '<main class="glossary-page"><section class="glossary-hero"><div class="container"><span class="eyebrow">دانش‌نامهٔ Noventix</span><h1>واژه‌نامهٔ هوش مصنوعی</h1><p>مفاهیم و اصطلاحات هوش مصنوعی را عمیق‌تر بشناس.</p><div class="glossary-hero-stats"><span><strong>'.fa((int)$data['entries']).'</strong> اصطلاح آموزشی</span><span><strong>'.fa(count($data['categories'])).'</strong> حوزهٔ موضوعی</span><span><strong>رایگان</strong> برای همه</span></div></div></section><div class="container glossary-content"><form class="glossary-search glossary-search-panel" action="'.h(url('glossary')).'" method="get" role="search" data-glossary-search><div class="glossary-search-field"><span aria-hidden="true">⌕</span><label class="sr-only" for="glossary-q">جست‌وجوی اصطلاح</label><input id="glossary-q" name="q" value="'.h($query).'" maxlength="80" placeholder="جست‌وجوی اصطلاحات هوش مصنوعی..." autocomplete="off" data-glossary-input><button class="btn btn-primary" type="submit">جست‌وجو</button><button class="glossary-clear" type="button" data-glossary-clear hidden aria-label="پاک کردن جست‌وجو">×</button></div><div class="glossary-suggestions" data-glossary-suggestions role="status" aria-live="polite" hidden></div></form><section class="glossary-browse" aria-labelledby="glossary-browse-title"><div class="section-header compact"><span class="eyebrow">مرور سریع</span><h2 id="glossary-browse-title">از الفبا شروع کن</h2></div><nav class="glossary-alphabet" aria-label="مرور الفبایی">';
    echo '<a class="'.($letter===''?'active':'').'" href="'.h(glossary_query_url($query,$category)).'">همه اصطلاحات</a>';
    foreach ($letters as $item) { $count=$letterCounts[$item] ?? 0; echo '<a class="'.($letter===$item?'active':'').($count===0?' is-empty':'').'" href="'.h(glossary_query_url($query,$category,$item)).'" aria-label="اصطلاحات حرف '.$item.'، '.fa($count).' مورد">'.$item.' <small>'.fa($count).'</small></a>'; }
    echo '</nav></section><section class="glossary-directory" aria-labelledby="glossary-directory-title"><div class="glossary-directory-head"><div><span class="eyebrow">دسته‌بندی‌ها</span><h2 id="glossary-directory-title">موضوع موردنظرت را انتخاب کن</h2></div><span class="glossary-result-count">'.fa($result['total']).' اصطلاح</span></div><nav class="glossary-categories" aria-label="دسته‌بندی واژه‌نامه"><a class="'.($category===''?'active':'').'" href="'.h(glossary_query_url($query,'',$letter)).'">همه حوزه‌ها</a>';
    foreach ($result['categories'] as $item) echo '<a class="'.($category===$item['key']?'active':'').'" href="'.h(glossary_query_url($query,$item['key'],$letter)).'">'.h($item['label']).' <small>'.fa((int)$item['count']).'</small></a>';
    echo '</nav></section>';
    if ($query!=='' || $category!=='' || $letter!=='') echo '<div class="glossary-active-filter"><span>نتایج فیلترشده'.($query!==''?' برای «'.h($query).'»':'').($letter!==''?' · حرف '.$letter:'').'</span><a href="'.h(url('glossary')).'">حذف فیلترها</a></div>';
    if (!$result['entries']) echo '<div class="empty-state glossary-empty"><span class="glossary-empty-mark" aria-hidden="true">⌕</span><h2>'.($query!==''?'نتیجه‌ای برای جست‌وجو پیدا نشد':'هنوز اصطلاحی در این بخش نیست').'</h2><p>عبارت دیگری را امتحان کن یا فیلترهای انتخاب‌شده را تغییر بده.</p><a class="btn btn-outline" href="'.h(url('glossary')).'">مشاهدهٔ همه اصطلاحات</a></div>';
    else {
        echo '<div class="glossary-grid">';
        foreach ($result['entries'] as $entry) echo '<article class="glossary-card"><div class="glossary-card-top"><span class="glossary-letter" aria-hidden="true">'.h($entry['letter'] ?? '').'</span><span class="pill">'.h(glossary_category_label($result['categories'],$entry['category'])).'</span></div><span class="glossary-term" dir="ltr">'.h($entry['term']).'</span><h3><a href="'.h(url('glossary/'.$entry['key'])).'">'.h($entry['title']).'</a></h3><p>'.h($entry['definition']).'</p><a class="inline-link" href="'.h(url('glossary/'.$entry['key'])).'">مشاهدهٔ جزئیات <span aria-hidden="true">←</span></a></article>';
        echo '</div>';
        if ($result['pages']>1) { echo '<nav class="pager glossary-pager" aria-label="صفحه‌بندی لغت‌نامه">'; if ($result['page']>1) echo '<a href="'.h(glossary_query_url($query,$category,$letter,$result['page']-1)).'">صفحهٔ قبل</a>'; else echo '<span class="is-disabled">صفحهٔ قبل</span>'; echo '<span>صفحهٔ '.fa($result['page']).' از '.fa($result['pages']).'</span>'; if ($result['page']<$result['pages']) echo '<a href="'.h(glossary_query_url($query,$category,$letter,$result['page']+1)).'">صفحهٔ بعد</a>'; else echo '<span class="is-disabled">صفحهٔ بعد</span>'; echo '</nav>'; }
    }
    echo '</div></main>';
}

function glossary_detail_text(string $id,string $title,string $content): void {
    if (trim($content)==='') return;
    echo '<section class="glossary-detail-section" aria-labelledby="'.h($id).'" ><h2 id="'.h($id).'">'.h($title).'</h2><div class="glossary-detail-copy">'.nl2br(h($content)).'</div></section>';
}

function glossary_detail_list(string $id,string $title,array $items): void {
    $items=array_values(array_filter(array_map(static fn($item): string => trim((string)$item),$items),static fn(string $item): bool => $item!==''));
    if (!$items) return;
    echo '<section class="glossary-detail-section" aria-labelledby="'.h($id).'" ><h2 id="'.h($id).'">'.h($title).'</h2><ul class="glossary-detail-list">';
    foreach ($items as $item) echo '<li>'.h($item).'</li>';
    echo '</ul></section>';
}

function glossary_difficulty_label(string $level): string {
    return ['beginner'=>'مقدماتی','intermediate'=>'متوسط','advanced'=>'پیشرفته'][$level] ?? $level;
}

function page_glossary_entry(array $entry): void {
    echo '<main class="glossary-page"><div class="container"><nav class="profile-crumb" aria-label="مسیر صفحه"><a href="'.h(url('glossary')).'">لغت‌نامه</a><span aria-current="page">'.h($entry['term_en']).'</span></nav><article class="glossary-entry"><header class="glossary-entry-header"><span class="eyebrow">اصطلاح هوش مصنوعی</span><h1 dir="ltr">'.h($entry['term_en']).'</h1><p class="glossary-term-fa">'.h($entry['term_fa']).'</p>'.($entry['abbreviation'] ? '<p class="glossary-abbreviation">مخفف: <strong dir="ltr">'.h($entry['abbreviation']).'</strong></p>' : '').'<p class="glossary-definition">'.h($entry['short_definition']).'</p><div class="glossary-entry-meta">';
    foreach ($entry['categories'] as $category) echo '<a class="pill" href="'.h(url('glossary?category='.$category['key'])).'">'.h($category['label']).'</a>';
    if ($entry['difficulty_level']) echo '<span class="glossary-difficulty">سطح: '.h(glossary_difficulty_label($entry['difficulty_level'])).'</span>';
    echo '</div></header><div class="glossary-entry-body">';
    glossary_detail_text('full-definition','تعریف کامل',(string)$entry['full_definition']);
    glossary_detail_text('beginner-explanation','توضیح برای شروع',(string)$entry['beginner_explanation']);
    glossary_detail_text('technical-explanation','توضیح فنی',(string)$entry['technical_explanation']);
    glossary_detail_text('advanced-explanation','توضیح پیشرفته',(string)$entry['advanced_explanation']);
    glossary_detail_text('how-it-works','نحوهٔ عملکرد',(string)$entry['how_it_works']);
    glossary_detail_text('practical-example','مثال کاربردی',(string)$entry['practical_example']);
    if (trim((string)$entry['code_example'])!=='') echo '<section class="glossary-detail-section" aria-labelledby="code-example"><h2 id="code-example">مثال کدنویسی</h2><pre class="glossary-code"><code>'.h((string)$entry['code_example']).'</code></pre></section>';
    glossary_detail_list('use-cases','کاربردها',$entry['use_cases']);
    glossary_detail_list('advantages','مزایا',$entry['advantages']);
    glossary_detail_list('limitations','محدودیت‌ها',$entry['limitations']);
    if ($entry['related_terms']) { echo '<section class="glossary-detail-section" aria-labelledby="related-terms"><h2 id="related-terms">اصطلاحات مرتبط</h2><div class="related-terms">'; foreach ($entry['related_terms'] as $related) echo '<a class="related-term-card" href="'.h(url('glossary/'.$related['key'])).'"><strong>'.h($related['title']).'</strong><span dir="ltr">'.h($related['term']).'</span></a>'; echo '</div></section>'; }
    if ($entry['sources']) { echo '<section class="glossary-detail-section" aria-labelledby="sources"><h2 id="sources">منابع</h2><ol class="glossary-sources">'; foreach ($entry['sources'] as $source) { echo '<li>'; if ($source['url']) echo '<a href="'.h($source['url']).'" target="_blank" rel="noopener noreferrer">'.h($source['title']).'</a>'; else echo '<span>'.h($source['title']).'</span>'; if ($source['publisher']) echo '<small>'.h($source['publisher']).'</small>'; echo '</li>'; } echo '</ol></section>'; }
    if ($entry['reviewed_at']) echo '<p class="glossary-reviewed">آخرین بازبینی: <time datetime="'.h($entry['reviewed_at']).'">'.h($entry['reviewed_at']).'</time></p>';
    echo '</div><a class="btn btn-outline" href="'.h(url('glossary')).'">بازگشت به واژه‌نامه</a></article></div></main>';
}
