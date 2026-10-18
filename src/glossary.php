<?php
declare(strict_types=1);

function glossary_data(): array {
    static $data;
    if ($data === null) {
        $data=json_decode((string)file_get_contents(dirname(__DIR__).'/curriculum/glossary.json'),true,512,JSON_THROW_ON_ERROR);
    }
    return $data;
}

function glossary_normalize(string $value): string {
    return trim(preg_replace('/\s+/u',' ',strtr(mb_strtolower($value),['ي'=>'ی','ك'=>'ک']))) ?? '';
}

function glossary_entries(string $query='',string $category='',int $page=1): array {
    $data=glossary_data();
    $query=glossary_normalize($query);
    $category=trim($category);
    $filtered=array_values(array_filter($data['entries'],static function(array $entry) use ($query,$category): bool {
        if ($category!=='' && $entry['category']!==$category) return false;
        return $query==='' || str_contains(glossary_normalize($entry['search']),$query);
    }));
    $perPage=24;
    $pages=max(1,(int)ceil(count($filtered)/$perPage));
    $page=max(1,min($page,$pages));
    return ['entries'=>array_slice($filtered,($page-1)*$perPage,$perPage),'total'=>count($filtered),'page'=>$page,'pages'=>$pages,'categories'=>$data['categories']];
}

function glossary_entry(string $key): ?array {
    foreach (glossary_data()['entries'] as $entry) if ($entry['key']===$key) return $entry;
    return null;
}

function glossary_category_label(array $categories,string $key): string {
    foreach ($categories as $category) if ($category['key']===$key) return (string)$category['label'];
    return $key;
}

function glossary_query_url(string $query,string $category,int $page=1): string {
    $params=[];
    if ($query!=='') $params['q']=$query;
    if ($category!=='') $params['category']=$category;
    if ($page>1) $params['page']=(string)$page;
    return url('glossary'.($params ? '?'.http_build_query($params,'','&',PHP_QUERY_RFC3986) : ''));
}

function page_glossary(array $result,string $query,string $category): void {
    echo '<main class="glossary-page"><div class="container"><section class="page-hero"><span class="eyebrow">دانش‌نامهٔ هوش مصنوعی</span><h1>لغت‌نامه‌ای برای فهمیدن، نه حفظ کردن</h1><p>۳۴۵ اصطلاح مهم هوش مصنوعی، یادگیری ماشین و علم داده را با تعریف روشن، دسته‌بندی و ارتباط بین مفاهیم پیدا کن.</p></section>';
    echo '<form class="glossary-search" action="'.h(url('glossary')).'" method="get" role="search"><label class="sr-only" for="glossary-q">جست‌وجوی اصطلاح</label><input id="glossary-q" name="q" value="'.h($query).'" maxlength="80" placeholder="مثلاً RAG، ترنسفورمر یا دقت"><button class="btn btn-primary">جست‌وجو</button></form>';
    echo '<nav class="glossary-categories" aria-label="دسته‌بندی لغت‌نامه"><a class="'.($category===''?'active':''). '" href="'.h(glossary_query_url($query,'')).'">همه</a>';
    foreach ($result['categories'] as $item) echo '<a class="'.($category===$item['key']?'active':'').'" href="'.h(glossary_query_url($query,$item['key'])).'">'.h($item['label']).' <small>'.fa((int)$item['count']).'</small></a>';
    echo '</nav><p class="muted">'.fa($result['total']).' اصطلاح'.($query!==''?' برای «'.h($query).'»':'').' </p>';
    if (!$result['entries']) echo '<div class="empty-state"><h2>اصطلاحی پیدا نشد</h2><p>املای عبارت را تغییر بده یا یک دستهٔ دیگر را امتحان کن.</p></div>';
    else {
        echo '<div class="glossary-grid">';
        foreach ($result['entries'] as $entry) echo '<article class="glossary-card"><div class="meta-row"><span class="pill">'.h(glossary_category_label($result['categories'],$entry['category'])).'</span><span dir="ltr">'.h($entry['term']).'</span></div><h2><a href="'.h(url('glossary/'.$entry['key'])).'">'.h($entry['title']).'</a></h2><p>'.h($entry['definition']).'</p><a class="inline-link" href="'.h(url('glossary/'.$entry['key'])).'">ادامهٔ تعریف ←</a></article>';
        echo '</div>';
        if ($result['pages']>1) { echo '<nav class="pager" aria-label="صفحه‌بندی لغت‌نامه">'; if ($result['page']>1) echo '<a href="'.h(glossary_query_url($query,$category,$result['page']-1)).'">صفحهٔ قبل</a>'; else echo '<span class="is-disabled">صفحهٔ قبل</span>'; echo '<span>صفحهٔ '.fa($result['page']).' از '.fa($result['pages']).'</span>'; if ($result['page']<$result['pages']) echo '<a href="'.h(glossary_query_url($query,$category,$result['page']+1)).'">صفحهٔ بعد</a>'; else echo '<span class="is-disabled">صفحهٔ بعد</span>'; echo '</nav>'; }
    }
    echo '</div></main>';
}

function page_glossary_entry(array $entry): void {
    $data=glossary_data();
    echo '<main class="glossary-page"><div class="container"><nav class="profile-crumb" aria-label="مسیر صفحه"><a href="'.h(url('glossary')).'">لغت‌نامه</a><span aria-current="page">'.h($entry['title']).'</span></nav><article class="glossary-entry"><div class="meta-row"><span class="pill">'.h(glossary_category_label($data['categories'],$entry['category'])).'</span><span dir="ltr">'.h($entry['term']).'</span></div><h1>'.h($entry['title']).'</h1><p class="glossary-definition">'.h($entry['definition']).'</p>';
    if ($entry['related']) { echo '<section><span class="eyebrow">مفاهیم مرتبط</span><div class="related-terms">'; foreach ($entry['related'] as $key) { $related=glossary_entry($key); if ($related) echo '<a class="pill" href="'.h(url('glossary/'.$related['key'])).'">'.h($related['title']).'</a>'; } echo '</div></section>'; }
    echo '<a class="btn btn-outline" href="'.h(url('glossary')).'">بازگشت به لغت‌نامه</a></article></div></main>';
}
