<?php
declare(strict_types=1);

function seo_origin(): ?string {
    $value=rtrim(trim((string)getenv('PUBLIC_SITE_URL')),'/');
    $parts=parse_url($value);
    if (!$parts || ($parts['scheme'] ?? '')!=='https' || empty($parts['host']) || isset($parts['user']) || isset($parts['pass']) || isset($parts['query']) || isset($parts['fragment']) || !empty($parts['path']) || filter_var($value,FILTER_VALIDATE_URL)===false) return null;
    return $value;
}
function seo_public_path(string $path): bool {
    return in_array($path,['','courses','projects','pricing','about','contact','terms','privacy'],true)
        || (bool)preg_match('~^(course/[a-z0-9-]+|project/[0-9]+)$~D',$path);
}
function seo_head(string $title,string $description,bool $panel): void {
    global $path;
    $indexable=!$panel && seo_public_path($path) && http_response_code()!==404;
    echo '<meta name="robots" content="'.($indexable?'index,follow':'noindex,follow').'">';
    $full=$title==='خانه' ? 'Noventix | از یادگیری تا ساختن' : $title.' | Noventix';
    foreach (['og:title'=>$full,'og:description'=>$description,'og:type'=>'website','og:locale'=>'fa_IR','og:site_name'=>'Noventix'] as $property=>$value) echo '<meta property="'.h($property).'" content="'.h($value).'">';
    foreach (['twitter:card'=>'summary','twitter:title'=>$full,'twitter:description'=>$description] as $name=>$value) echo '<meta name="'.h($name).'" content="'.h($value).'">';
    $origin=seo_origin();
    if ($origin && $indexable) {
        $canonical=$origin.'/'.implode('/',array_map('rawurlencode',explode('/',$path)));
        echo '<link rel="canonical" href="'.h($canonical).'"><meta property="og:url" content="'.h($canonical).'">';
        $data=['@context'=>'https://schema.org','@type'=>'WebPage','name'=>$title,'description'=>$description,'url'=>$canonical,'inLanguage'=>'fa-IR'];
        if (str_starts_with($path,'course/')) {
            $course=q("SELECT c.id FROM courses c WHERE c.slug=? AND c.status='published' AND EXISTS (SELECT 1 FROM lessons l WHERE l.course_id=c.id AND l.status='published')",[substr($path,7)])->fetchColumn();
            if ($course) $data['@type']='Course';
        }
        global $seoNonce;
        if (!empty($seoNonce)) echo '<script type="application/ld+json" nonce="'.h($seoNonce).'">'.json_encode($data,JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE|JSON_HEX_TAG|JSON_HEX_AMP|JSON_HEX_APOS|JSON_HEX_QUOT).'</script>';
    }
}
function seo_routes(): array {
    $paths=['','courses','projects','pricing','about','contact','terms','privacy'];
    foreach (published_courses() as $course) $paths[]='course/'.$course['slug'];
    foreach (array_keys(PROJECTS) as $index) $paths[]='project/'.$index;
    return $paths;
}
function seo_route(string $path): bool {
    if ($path==='robots.txt') {
        header('Content-Type: text/plain; charset=utf-8');
        echo "User-agent: *\nAllow: /\nDisallow: /dashboard\nDisallow: /workspace\nDisallow: /progress\nDisallow: /payment\nDisallow: /auth\nDisallow: /login\nDisallow: /verify\nDisallow: /admin-login\nDisallow: /member\n";
        if ($origin=seo_origin()) echo 'Sitemap: '.$origin."/sitemap.xml\n";
        return true;
    }
    if ($path!=='sitemap.xml') return false;
    $origin=seo_origin();
    if (!$origin) { http_response_code(503); header('Content-Type: text/plain; charset=utf-8'); echo 'نشانی نهایی سایت هنوز تنظیم نشده است.'; return true; }
    header('Content-Type: application/xml; charset=utf-8');
    echo '<?xml version="1.0" encoding="UTF-8"?><urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">';
    foreach (seo_routes() as $route) echo '<url><loc>'.htmlspecialchars($origin.'/'.implode('/',array_map('rawurlencode',explode('/',$route))),ENT_XML1|ENT_QUOTES,'UTF-8').'</loc></url>';
    echo '</urlset>';
    return true;
}
