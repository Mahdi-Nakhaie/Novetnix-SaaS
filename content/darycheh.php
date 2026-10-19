<?php
declare(strict_types=1);

$operations=[
    'duplicate'=>'بررسی سابقه؛ اگر موجود بود نتیجهٔ قبلی را برگردان و متوقف شو',
    'validate'=>'بررسی مثبت و صحیح‌بودن تعداد؛ در غیر این صورت رد و توقف',
    'capacity'=>'بررسی ظرفیت کافی؛ در غیر این صورت رد و توقف',
    'reserve'=>'ایجاد رزرو و کم‌کردن ظرفیت',
    'remember'=>'ثبت نتیجهٔ درخواست برای تکرار بعدی',
];
$predictions=[
    'reserved:2:1'=>'یک رزرو؛ ظرفیت ۲',
    'replayed:2:1'=>'همان نتیجهٔ قبلی؛ یک رزرو؛ ظرفیت ۲',
    'reserved:1:2'=>'دو رزرو؛ ظرفیت ۱',
    'rejected:1:0'=>'رد؛ بدون رزرو؛ ظرفیت ۱',
    'rejected:3:0'=>'رد؛ بدون رزرو؛ ظرفیت ۳',
];
return [
    'scenario_key'=>'reservation-retry','content_version'=>'1.0','definition_schema_version'=>1,
    'engine_version'=>'1','assessment_version'=>'1','entry_step_key'=>'intro',
    'title'=>'چرا یک کلیک، دو رزرو ساخته است؟',
    'objectives'=>[
        'evidence'=>'تفکیک شواهد و فرضیه','problem'=>'تعریف مسئلهٔ قابل آزمون',
        'algorithm'=>'ساخت روند منطقی','testing'=>'پیش‌بینی و آزمودن','transfer'=>'انتقال آموخته',
    ],
    'skills'=>['evidence'=>'engineering-mindset','problem'=>'requirements','algorithm'=>'algorithmic-thinking','testing'=>'debugging','transfer'=>'problem-solving'],
    'hints'=>[
        'نتیجهٔ تست تکرار را با تعداد درخواست‌های متمایز مقایسه کن.',
        'پیش از هر تغییر ظرفیت، سابقهٔ همان شناسهٔ درخواست را بررسی کن.',
        'روند پیشنهادی: بررسی سابقه، اعتبار تعداد، ظرفیت، ایجاد رزرو، ثبت نتیجه. ردها نیز ثبت می‌شوند.',
    ],
    'operations'=>$operations,
    'tests'=>[
        ['key'=>'normal','label'=>'درخواست A برای یک صندلی؛ ظرفیت اولیه ۳','capacity'=>3,'requests'=>[['A',1]],'expected'=>'reserved:2:1'],
        ['key'=>'repeat','label'=>'درخواست A برای یک صندلی دو بار؛ ظرفیت اولیه ۳','capacity'=>3,'requests'=>[['A',1],['A',1]],'expected'=>'replayed:2:1'],
        ['key'=>'full','label'=>'درخواست B برای دو صندلی؛ ظرفیت اولیه ۱','capacity'=>1,'requests'=>[['B',2]],'expected'=>'rejected:1:0'],
        ['key'=>'invalid','label'=>'درخواست C برای صفر صندلی؛ ظرفیت اولیه ۳','capacity'=>3,'requests'=>[['C',0]],'expected'=>'rejected:3:0'],
    ],
    'rubric'=>[
        'minimum_total'=>16,'minimum_dimension'=>3,
        'evidence'=>['same_id','same_data','double_change','server_trace'],
        'problem'=>['person'=>'requester','cause'=>'new_each_time','goal'=>'once','limits'=>'valid_capacity'],
    ],
    'transfer_bundles'=>[
        'tools'=>['exposure_key'=>'equipment-repeat-v1','label'=>'امانت ابزار','text'=>'موجودی ابزار ۲ است. درخواست T-9 برای یک ابزار، با همان داده دو بار می‌رسد.', 'remaining'=>'1'],
        'books'=>['exposure_key'=>'book-repeat-v1','label'=>'امانت کتاب','text'=>'موجودی کتاب ۴ است. درخواست K-3 برای یک کتاب، با همان داده دو بار می‌رسد.', 'remaining'=>'3'],
    ],
    'steps'=>[
        'intro'=>['kind'=>'intro','title'=>'ورود به موقعیت','text'=>'عضو تیم کارگاه هستی. کاربر یک درخواست داده، اما دو رزرو ثبت شده است. مدیر پیشنهاد می‌کند دکمه را غیرفعال کنیم. مأموریت: درخواست معتبر فقط یک‌بار اثر کند و ظرفیت حفظ شود. شبیه‌سازی ترتیبی است؛ پرداخت و هم‌زمانی خارج از محدوده‌اند.','next'=>'evidence','fields'=>[]],
        'evidence'=>['kind'=>'evidence','title'=>'جمع‌آوری اطلاعات','text'=>'چهار شاهد مرتبط را انتخاب کن. مشاهدهٔ کارت به‌تنهایی امتیاز ندارد.','next'=>'problem','fields'=>[
            'selected'=>['kind'=>'multi','label'=>'شواهد مرتبط','min'=>4,'max'=>4,'options'=>[
                'same_id'=>'مشاهده: درخواست R-17 دو بار با همان شناسه رسیده است.',
                'same_data'=>'مشاهده: دادهٔ دو درخواست یکسان است؛ پاسخ اول با تأخیر رسیده است.',
                'double_change'=>'مشاهده: ظرفیت از ۳ به ۱ رسیده و دو رزرو ساخته شده است.',
                'server_trace'=>'مشاهده: ردگیری سرور نشان می‌دهد هر دریافت، رزرو تازه می‌سازد؛ تغییر رنگ علت را رفع نمی‌کند.',
                'button_color'=>'فرضیهٔ تأییدنشده: تغییر رنگ دکمه مشکل ثبت را رفع می‌کند.',
            ]],
        ]],
        'problem'=>['kind'=>'problem','title'=>'تعریف مسئله','text'=>'نشانه، علت و نتیجهٔ مطلوب را از هم جدا کن.','next'=>'solution','fields'=>[
            'person'=>['kind'=>'single','label'=>'کاربر اصلی','options'=>['requester'=>'درخواست‌کنندهٔ صندلی','designer'=>'طراح رنگ دکمه']],
            'cause'=>['kind'=>'single','label'=>'مسئلهٔ اصلی','options'=>['new_each_time'=>'هر دریافت بدون تشخیص تکرار عملیات تازه محسوب می‌شود','careless'=>'کاربر همیشه بی‌دقت است']],
            'goal'=>['kind'=>'single','label'=>'نتیجهٔ مطلوب','options'=>['once'=>'درخواست یک‌بار اثر کند و تکرارش نتیجهٔ قبلی بدهد','each'=>'هر دریافت یک رزرو بسازد']],
            'limits'=>['kind'=>'single','label'=>'معیار پذیرش','options'=>['valid_capacity'=>'تعداد معتبر باشد و ظرفیت منفی نشود','appearance'=>'فقط ظاهر دکمه تغییر کند']],
        ]],
        'solution'=>['kind'=>'solution','title'=>'ساخت راه‌حل','text'=>'ترتیب کارت‌ها را مشخص کن؛ برای مشاهدهٔ پیامد حذف یک کنترل، «حذف» را انتخاب کن. کارت تکراری مجاز نیست. در این مدل، رد درخواست نیز به‌عنوان نتیجه ثبت می‌شود.','next'=>'consequence','fields'=>[
            'order'=>['kind'=>'order','label'=>'روند پردازش','options'=>$operations,'min'=>1,'max'=>5],
        ]],
        'consequence'=>['kind'=>'consequence','title'=>'پیش‌بینی و مشاهدهٔ پیامد','text'=>'پیش از اجرای روند خودت، نتیجهٔ مورد انتظار هر تست را پیش‌بینی کن. هر تست با وضعیت اولیهٔ مستقل اجرا می‌شود.','next'=>'feedback','fields'=>[
            'normal'=>['kind'=>'single','label'=>'درخواست عادی؛ ظرفیت ۳، یک صندلی','options'=>$predictions],
            'repeat'=>['kind'=>'single','label'=>'تکرار همان درخواست؛ ظرفیت اولیه ۳','options'=>$predictions],
            'full'=>['kind'=>'single','label'=>'دو صندلی با ظرفیت ۱','options'=>$predictions],
            'invalid'=>['kind'=>'single','label'=>'صفر صندلی با ظرفیت ۳','options'=>$predictions],
        ]],
        'feedback'=>['kind'=>'feedback','title'=>'بازخورد مبتنی بر شواهد','text'=>'نتیجهٔ واقعی روند را با پیش‌بینی و معیار پذیرش مقایسه کن.','next'=>'retry','fields'=>[]],
        'retry'=>['kind'=>'retry','title'=>'بازبینی و تلاش مجدد','text'=>'می‌توانی راه‌حل یا تحلیل را اصلاح کنی. ادامه به انتقال فقط پس از عبور چهار تست ممکن است.','fields'=>[
            'route'=>['kind'=>'single','label'=>'گام بعد','options'=>['revise'=>'اصلاح راه‌حل','redefine'=>'بازبینی شواهد و تعریف مسئله','continue'=>'آزمون انتقال آموخته']],
        ],'routes'=>['revise'=>'solution','redefine'=>'evidence','continue'=>'transfer']],
        'transfer'=>['kind'=>'transfer','title'=>'انتقال به مسئله‌ای تازه','text'=>'بدون کدنویسی، همان اصل را در مسئلهٔ امانت به کار ببر.','next'=>'transfer_feedback','fields'=>[
            'cause'=>['kind'=>'single','label'=>'علت اثر دوباره','options'=>['duplicate'=>'تلقی تکرار همان درخواست به‌عنوان درخواست تازه','color'=>'رنگ دکمه']],
            'order'=>['kind'=>'single','label'=>'ترتیب کنترل','options'=>['before'=>'بررسی سابقه پیش از تغییر موجودی','after'=>'بررسی سابقه پس از تغییر موجودی']],
            'remaining'=>['kind'=>'single','label'=>'موجودی نهایی','options'=>['0'=>'۰','1'=>'۱','2'=>'۲','3'=>'۳','4'=>'۴']],
            'test'=>['kind'=>'single','label'=>'تست مرزی مناسب','options'=>['invalid'=>'درخواست با تعداد نامعتبر یا موجودی ناکافی','font'=>'تغییر فونت']],
        ]],
        'transfer_feedback'=>['kind'=>'transfer_feedback','title'=>'بازخورد انتقال','text'=>'نتیجهٔ انتقال و میزان استقلال در گزارش ثبت می‌شود. پس از نمایش پاسخ، این بسته دیگر شاهد مستقل نیست.','fields'=>[
            'route'=>['kind'=>'single','label'=>'گام بعد','options'=>['retry'=>'بستهٔ بعدی یا تمرین مجدد','finish'=>'ثبت گزارش نهایی']],
        ],'routes'=>['retry'=>'transfer','finish'=>'report']],
        'report'=>['kind'=>'report','title'=>'گزارش تجربه','text'=>'این گزارش شاهد عملکرد در همین موقعیت است؛ تضمین تسلط یا حرفه‌ای‌شدن نیست.','fields'=>[]],
    ],
];
