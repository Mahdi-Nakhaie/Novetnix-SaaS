<?php
declare(strict_types=1);

function articles(): array {
    static $items;
    if ($items===null) {
        $items=json_decode(file_get_contents(dirname(__DIR__).'/content/articles/catalog.json'),true,512,JSON_THROW_ON_ERROR);
        foreach ($items as &$item) {
            $item['body']=file_get_contents(dirname(__DIR__).'/content/articles/'.$item['slug'].'.txt');
            $prose=preg_replace('~```.*?```~s','',$item['body']);
            $item['word_count']=count(preg_split('/\s+/u',trim($prose),-1,PREG_SPLIT_NO_EMPTY));
            $item['reading_minutes']=(int)ceil($item['word_count']/180);
            $item['published_at']='2026-10-19';
        }
        unset($item);
    }
    return $items;
}
function article_entry(string $slug): ?array {
    foreach (articles() as $article) if ($article['slug']===$slug) return $article;
    return null;
}
function article_blocks(string $body): array {
    $blocks=[]; $code=false; $buffer=[]; $heading=0;
    foreach (explode("\n",trim($body)) as $line) {
        if (str_starts_with($line,'```')) {
            if ($code) { $blocks[]=['type'=>'code','text'=>implode("\n",$buffer)]; $buffer=[]; }
            $code=!$code;
        } elseif ($code) $buffer[]=$line;
        elseif (preg_match('/^(#{2,3}) (.+)$/u',$line,$match)) $blocks[]=['type'=>strlen($match[1])===2?'h2':'h3','text'=>$match[2],'id'=>'section-'.(++$heading)];
        elseif (trim($line)!=='') $blocks[]=['type'=>'p','text'=>$line];
    }
    return $blocks;
}
function article_cards(int $limit=12): void {
    echo '<div class="article-grid">';
    foreach (array_slice(articles(),0,$limit) as $article) {
        echo '<article class="article-card"><div class="meta-row"><span class="pill">'.h($article['category']).'</span><span>'.fa($article['reading_minutes']).' دقیقه مطالعه</span></div><h2><a href="'.h(url('articles/'.$article['slug'])).'">'.h($article['title']).'</a></h2><p>'.h($article['description']).'</p><a class="inline-link" href="'.h(url('articles/'.$article['slug'])).'" aria-label="'.h('مطالعهٔ '.$article['title']).'">مطالعهٔ مقاله ←</a></article>';
    }
    echo '</div>';
}
function page_articles(): void {
    head_page('مقاله‌های پایتون، علم داده و هوش مصنوعی','۱۲ راهنمای کاربردی فارسی دربارهٔ پایتون، تحلیل داده، یادگیری ماشین، پردازش زبان و استقرار مدل؛ همراه مثال، تمرین، منابع و مقاله‌های مرتبط.',false,true);
    echo '<main class="container section articles-index"><header class="section-header"><span class="eyebrow">مجلهٔ آموزشی نوونتیکس</span><h1>مقاله‌ها</h1><p>از شناخت مفهوم تا ساختن: ۱۲ راهنمای مفصل، همراه مثال و تمرین. مطالعهٔ مقاله‌ها رایگان و بدون ورود است.</p></header>';
    article_cards();
    if (in_array(FREE_COURSE_SLUG,array_column(published_courses(),'slug'),true)) {
        echo '<section class="article-course-note"><h2>دنبال دوره و ثبت پیشرفت هستید؟</h2><p>مقاله‌ها برای مطالعه‌اند؛ دورهٔ رایگان و پیشرفت درس‌ها همچنان از مسیر قبلی در دسترس است.</p>';
        linkto('course/'.FREE_COURSE_SLUG,'مشاهدهٔ دورهٔ رایگان','btn btn-outline');
        echo '</section>';
    }
    echo '</main>';
    foot_page();
}
function page_article(array $article): void {
    head_page($article['seo_title'],$article['description'],false,true);
    $blocks=article_blocks($article['body']);
    echo '<main class="container section article-page"><nav class="article-breadcrumb" aria-label="مسیر مقاله">';
    linkto('','خانه'); echo '<span aria-hidden="true">/</span>'; linkto('articles','مقاله‌ها');
    echo '<span aria-hidden="true">/</span><span aria-current="page">'.h($article['category']).'</span></nav><article><header class="article-header"><span class="eyebrow">'.h($article['category']).'</span><h1>'.h($article['title']).'</h1><p class="article-deck">'.h($article['description']).'</p><div class="article-meta"><span>تحریریهٔ نوونتیکس</span><time datetime="'.h($article['published_at']).'">'.h(jdate($article['published_at'].' 00:00:00')).'</time><span>'.fa($article['word_count']).' کلمه</span><span>'.fa($article['reading_minutes']).' دقیقه مطالعه</span></div></header><div class="article-layout"><aside class="article-toc"><nav aria-label="فهرست مطالب مقاله"><h2>در این مقاله می‌خوانید</h2><ol>';
    foreach ($blocks as $block) if ($block['type']==='h2') echo '<li><a href="#'.h($block['id']).'">'.h($block['text']).'</a></li>';
    echo '</ol></nav></aside><div class="article-body">';
    foreach ($blocks as $block) {
        if ($block['type']==='code') echo '<pre dir="ltr" tabindex="0" aria-label="نمونه‌کد"><code>'.h($block['text']).'</code></pre>';
        elseif (in_array($block['type'],['h2','h3'],true)) echo '<'.$block['type'].' id="'.h($block['id']).'">'.h($block['text']).'</'.$block['type'].'>';
        else echo '<p>'.h($block['text']).'</p>';
    }
    echo '<section class="article-sources" aria-labelledby="article-sources"><h2 id="article-sources">منابع و مطالعهٔ بیشتر</h2><p>برای بررسی جزئیات و ادامهٔ مطالعه؛ متن این راهنما مستقلاً برای نوونتیکس نوشته شده است.</p><ul>';
    foreach ($article['sources'] as $source) echo '<li><a href="'.h($source['url']).'" rel="external">'.h($source['title']).'</a></li>';
    echo '</ul></section></div></div></article><section class="article-related"><h2>مطالعهٔ بعدی</h2><ul>';
    foreach ($article['related'] as $slug) { $related=article_entry($slug); echo '<li><a href="'.h(url('articles/'.$slug)).'">'.h($related['title']).'</a></li>'; }
    echo '</ul>'; linkto('articles','همهٔ مقاله‌ها ←','inline-link'); echo '</section></main>';
    foot_page();
}
