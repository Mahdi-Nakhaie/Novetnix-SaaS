<?php
declare(strict_types=1);

$operations=[
    'duplicate'=>'تشخیص سفارش تکراری؛ اگر قبلاً ثبت شده بود همان نتیجه را برگردان و متوقف شو',
    'validate'=>'اعتبارسنجی تعداد اقلام؛ ورودی نامعتبر را رد و متوقف کن',
    'capacity'=>'بررسی موجودی کافی؛ در صورت کمبود سفارش را رد و متوقف کن',
    'reserve'=>'ثبت سفارش و کم‌کردن موجودی',
    'remember'=>'ثبت نتیجهٔ سفارش برای درخواست‌های بعدی',
];
$predictions=[
    'reserved:2:1'=>'سفارش ثبت می‌شود؛ موجودی ۲',
    'replayed:2:1'=>'نتیجهٔ قبلی برگردانده می‌شود؛ یک سفارش؛ موجودی ۲',
    'reserved:1:2'=>'دو سفارش ثبت می‌شود؛ موجودی ۱',
    'rejected:1:0'=>'سفارش رد می‌شود؛ تغییری در سفارش و موجودی ایجاد نمی‌شود؛ موجودی ۱',
    'rejected:3:0'=>'سفارش رد می‌شود؛ تغییری در سفارش و موجودی ایجاد نمی‌شود؛ موجودی ۳',
];
return [
    'scenario_key'=>'first-real-problem','content_version'=>'1.1','definition_schema_version'=>1,
    'engine_version'=>'1','assessment_version'=>'1','entry_step_key'=>'intro',
    'title'=>'اولین مسئله واقعی تو',
    'objectives'=>[
        'evidence'=>'کشف مسئله از راه شواهد','problem'=>'تعریف مسئلهٔ قابل آزمون',
        'algorithm'=>'طراحی فرایند قابل اجرا','testing'=>'آزمایش و تحلیل نتیجه','transfer'=>'توسعه و انتقال راه‌حل',
    ],
    'skills'=>['evidence'=>'problem-discovery','problem'=>'requirements','algorithm'=>'process-design','testing'=>'debugging','transfer'=>'problem-solving'],
    'skill_assessment'=>[
        'problem_definition'=>['label'=>'تعریف مسئله','description'=>'تفکیک نشانه، علت، هدف و محدودیت','criteria'=>[
            ['dimension'=>'problem','key'=>'person','label'=>'شناخت فرد درگیر'],['dimension'=>'problem','key'=>'cause','label'=>'بیان علت قابل آزمون'],['dimension'=>'problem','key'=>'goal','label'=>'هدف قابل مشاهده'],['dimension'=>'problem','key'=>'limits','label'=>'توجه به محدودیت']]],
        'information_gathering'=>['label'=>'جمع‌آوری اطلاعات','description'=>'انتخاب شواهد مرتبط و قابل اتکا','criteria'=>[
            ['dimension'=>'evidence','key'=>'same_id','label'=>'شناسهٔ تکراری'],['dimension'=>'evidence','key'=>'same_data','label'=>'دادهٔ یکسان'],['dimension'=>'evidence','key'=>'double_change','label'=>'تغییر واقعی'],['dimension'=>'evidence','key'=>'server_trace','label'=>'ردگیری سرور']]],
        'logical_thinking'=>['label'=>'تفکر منطقی','description'=>'چیدن علت‌ها و کنترل‌ها در ترتیب درست','criteria'=>[
            ['dimension'=>'algorithm','key'=>'history_before_change','label'=>'سابقه پیش از تغییر'],['dimension'=>'algorithm','key'=>'return_previous','label'=>'بازگرداندن نتیجه'],['dimension'=>'algorithm','key'=>'guard_before_reserve','label'=>'کنترل پیش از ثبت'],['dimension'=>'algorithm','key'=>'remember_result','label'=>'ثبت نتیجه']]],
        'decision_making'=>['label'=>'تصمیم‌گیری','description'=>'انتخاب بر اساس هدف و محدودیت، نه حدس ظاهری','criteria'=>[
            ['dimension'=>'problem','key'=>'goal','label'=>'انتخاب هدف درست'],['dimension'=>'problem','key'=>'limits','label'=>'انتخاب محدودیت درست'],['dimension'=>'algorithm','key'=>'guard_before_reserve','label'=>'تصمیم کنترل پیشینی']]],
        'solution_design'=>['label'=>'طراحی راه‌حل','description'=>'تبدیل تعریف مسئله به فرایند قابل اجرا','criteria'=>[
            ['dimension'=>'algorithm','key'=>'history_before_change','label'=>'طراحی کنترل تکرار'],['dimension'=>'algorithm','key'=>'return_previous','label'=>'طراحی پاسخ تکراری'],['dimension'=>'algorithm','key'=>'guard_before_reserve','label'=>'طراحی کنترل اعتبار و موجودی'],['dimension'=>'algorithm','key'=>'remember_result','label'=>'طراحی ثبت نتیجه']]],
        'testing_validation'=>['label'=>'آزمایش و اعتبارسنجی','description'=>'پیش‌بینی و مقایسهٔ نتیجه در وضعیت‌های مختلف','criteria'=>[
            ['dimension'=>'testing','key'=>'normal','label'=>'آزمون عادی'],['dimension'=>'testing','key'=>'repeat','label'=>'آزمون تکرار'],['dimension'=>'testing','key'=>'full','label'=>'آزمون کمبود موجودی'],['dimension'=>'testing','key'=>'invalid','label'=>'آزمون ورودی نامعتبر']]],
        'error_analysis'=>['label'=>'تحلیل خطا','description'=>'بررسی پیامد واقعی و تشخیص نقطهٔ شکست','criteria'=>[
            ['dimension'=>'evidence','key'=>'double_change','label'=>'تشخیص اثر خطا'],['dimension'=>'evidence','key'=>'server_trace','label'=>'استفاده از ردگیری'],['dimension'=>'testing','key'=>'repeat','label'=>'مقایسهٔ تکرار'],['dimension'=>'testing','key'=>'invalid','label'=>'بررسی ورودی نامعتبر']]],
        'systems_thinking'=>['label'=>'تفکر سیستمی','description'=>'دیدن رابطهٔ درخواست، فرایند، موجودی و نتیجه','criteria'=>[
            ['dimension'=>'evidence','key'=>'same_id','label'=>'ارتباط درخواست و شناسه'],['dimension'=>'evidence','key'=>'double_change','label'=>'ارتباط فرایند و موجودی'],['dimension'=>'problem','key'=>'cause','label'=>'تشخیص سازوکار علت'],['dimension'=>'algorithm','key'=>'history_before_change','label'=>'قرار دادن کنترل در سیستم']]],
        'planning_prioritization'=>['label'=>'برنامه‌ریزی و اولویت‌بندی','description'=>'قرار دادن اقدام‌های حیاتی پیش از اقدام‌های برگشت‌ناپذیر','criteria'=>[
            ['dimension'=>'problem','key'=>'limits','label'=>'اولویت محدودیت'],['dimension'=>'algorithm','key'=>'history_before_change','label'=>'اولویت سابقه'],['dimension'=>'algorithm','key'=>'guard_before_reserve','label'=>'اولویت اعتبارسنجی'],['dimension'=>'algorithm','key'=>'remember_result','label'=>'برنامهٔ ثبت نتیجه']]],
    ],
    'hints'=>[
        'بین چیزی که مشتری می‌گوید و چیزی که در ردگیری سفارش رخ داده است تفاوت بگذار.',
        'پیش از نوشتن کد یا تغییر ظاهر، مشخص کن یک سفارش معتبر چند بار باید اثر کند و چه محدودیت‌هایی دارد.',
        'نمونهٔ اولیهٔ قابل آزمون همین ترتیب است: تشخیص تکرار، اعتبارسنجی، بررسی موجودی، ثبت سفارش و ثبت نتیجه.',
    ],
    'operations'=>$operations,
    'tests'=>[
        ['key'=>'normal','label'=>'سفارش عادی یک قلم؛ موجودی اولیه ۳','capacity'=>3,'requests'=>[['A',1]],'expected'=>'reserved:2:1'],
        ['key'=>'repeat','label'=>'ارسال دوبارهٔ همان سفارش؛ موجودی اولیه ۳','capacity'=>3,'requests'=>[['A',1],['A',1]],'expected'=>'replayed:2:1'],
        ['key'=>'full','label'=>'سفارش دو قلم با موجودی یک؛ موجودی اولیه ۱','capacity'=>1,'requests'=>[['B',2]],'expected'=>'rejected:1:0'],
        ['key'=>'invalid','label'=>'سفارش صفر قلم؛ موجودی اولیه ۳','capacity'=>3,'requests'=>[['C',0]],'expected'=>'rejected:3:0'],
    ],
    'rubric'=>[
        'minimum_total'=>16,'minimum_dimension'=>3,
        'evidence'=>['same_id','same_data','double_change','server_trace'],
        'problem'=>['person'=>'requester','cause'=>'new_each_time','goal'=>'once','limits'=>'valid_capacity'],
    ],
    'transfer_bundles'=>[
        'tools'=>['exposure_key'=>'equipment-repeat-v1','label'=>'ثبت تحویل ابزار','text'=>'موجودی ابزار ۲ است. درخواست T-9 برای یک ابزار، به‌دلیل اختلال شبکه با همان داده دو بار می‌رسد.', 'remaining'=>'1'],
        'books'=>['exposure_key'=>'book-repeat-v1','label'=>'ثبت امانت کتاب','text'=>'موجودی کتاب ۴ است. درخواست K-3 برای یک کتاب، به‌دلیل اختلال شبکه با همان داده دو بار می‌رسد.', 'remaining'=>'3'],
    ],
    'steps'=>[
        'intro'=>['kind'=>'intro','title'=>'کشف مسئله','text'=>'امروز مسئول تحلیل فرایند سفارش‌های یک کسب‌وکار کوچک هستی. فروشنده می‌گوید بعضی سفارش‌ها دوبار ثبت می‌شوند، پیگیری مشتریان دشوار است و خطاها دیر دیده می‌شوند. مدیر پیشنهاد می‌کند سریع یک فرم تازه و چند خط کد بنویسیم؛ اما مأموریت تو این است که اول مسئله را بفهمی، سپس یک فرایند قابل آزمون بسازی. شبیه‌سازی ترتیبی است؛ پرداخت واقعی، هم‌زمانی و اجرای کد خارج از محدوده‌اند.','next'=>'evidence','fields'=>[]],
        'evidence'=>['kind'=>'evidence','title'=>'کشف مسئله با شواهد','text'=>'اطلاعات اولیه را بررسی کن و چهار شاهدی را انتخاب کن که برای فهم مسئله ضروری‌اند. مشاهدهٔ کارت به‌تنهایی امتیاز ندارد؛ باید بتوانی نشانه را از حدس جدا کنی.','next'=>'problem','fields'=>[
            'selected'=>['kind'=>'multi','label'=>'شواهد مرتبط با مسئله','min'=>4,'max'=>4,'options'=>[
                'same_id'=>'گزارش سرور: سفارش R-17 دو بار با همان شناسه دریافت شده است.',
                'same_data'=>'ردگیری نشان می‌دهد دادهٔ دو درخواست یکسان است و پاسخ اول با تأخیر به مشتری رسیده است.',
                'double_change'=>'موجودی از ۳ به ۱ رسیده و دو ثبت سفارش برای یک درخواست دیده می‌شود.',
                'server_trace'=>'در هر دریافت، فرایند بدون بررسی سابقه مستقیماً سفارش تازه می‌سازد؛ تغییر رنگ دکمه علت را رفع نمی‌کند.',
                'button_color'=>'فرضیهٔ تأییدنشده: اگر رنگ دکمه عوض شود، خطای ثبت سفارش از بین می‌رود.',
            ]],
        ]],
        'problem'=>['kind'=>'problem','title'=>'تعریف مسئله','text'=>'اکنون مسئلهٔ اصلی را از نشانه‌های ظاهری جدا کن. تعریف خوب باید فرد درگیر، علت قابل آزمون، هدف و محدودیت را روشن کند؛ نه اینکه مستقیماً به کدنویسی بپردازد.','next'=>'solution','fields'=>[
            'person'=>['kind'=>'single','label'=>'چه کسی در مسئله درگیر است؟','options'=>['requester'=>'مشتری یا ثبت‌کنندهٔ سفارش','designer'=>'طراح رنگ دکمه']],
            'cause'=>['kind'=>'single','label'=>'علت قابل بررسی چیست؟','options'=>['new_each_time'=>'هر دریافت بدون تشخیص تکرار، سفارش تازه محسوب می‌شود','careless'=>'کاربر همیشه بی‌دقت است']],
            'goal'=>['kind'=>'single','label'=>'هدف قابل مشاهده چیست؟','options'=>['once'=>'هر سفارش معتبر یک‌بار اثر کند و تکرارش نتیجهٔ قبلی بدهد','each'=>'هر دریافت یک سفارش تازه بسازد']],
            'limits'=>['kind'=>'single','label'=>'یکی از محدودیت‌های مهم چیست؟','options'=>['valid_capacity'=>'تعداد معتبر باشد و موجودی منفی نشود','appearance'=>'فقط ظاهر فرم تغییر کند']],
        ]],
        'solution'=>['kind'=>'solution','title'=>'طراحی نمونهٔ اولیهٔ فرایند','text'=>'به‌جای ادعای اجرای کد واقعی، یک نمونهٔ اولیهٔ قابل ارزیابی از فرایند سفارش بساز: ترتیب کنترل‌ها را مشخص کن. حذف یک کنترل مجاز است تا اثر آن را ببینی، اما کارت تکراری مجاز نیست.','next'=>'consequence','fields'=>[
            'order'=>['kind'=>'order','label'=>'ترتیب فرایند سفارش','options'=>$operations,'min'=>1,'max'=>5],
        ]],
        'consequence'=>['kind'=>'consequence','title'=>'آزمایش نمونهٔ اولیه','text'=>'پیش از اجرای فرایندت، برای هر وضعیت نتیجه را پیش‌بینی کن. سپس موتور آموزشی آن را روی مجموعهٔ آزمون‌های عادی، تکراری، کمبود موجودی و ورودی نامعتبر اجرا می‌کند. هر آزمون وضعیت اولیهٔ مستقل دارد.','next'=>'feedback','fields'=>[
            'normal'=>['kind'=>'single','label'=>'سفارش عادی؛ موجودی ۳، یک قلم','options'=>$predictions],
            'repeat'=>['kind'=>'single','label'=>'تکرار همان سفارش؛ موجودی اولیه ۳','options'=>$predictions],
            'full'=>['kind'=>'single','label'=>'دو قلم با موجودی ۱','options'=>$predictions],
            'invalid'=>['kind'=>'single','label'=>'صفر قلم با موجودی ۳','options'=>$predictions],
        ]],
        'feedback'=>['kind'=>'feedback','title'=>'تحلیل نتیجه و بازخورد','text'=>'نتیجهٔ واقعی نمونهٔ اولیه را با پیش‌بینی‌ها و معیار پذیرش مقایسه کن. ردگیری هر آزمون نشان می‌دهد کدام تصمیم مؤثر بوده و کدام کنترل، اگر حذف شده باشد، چه پیامدی ساخته است.','next'=>'retry','fields'=>[]],
        'retry'=>['kind'=>'retry','title'=>'توسعه و اصلاح','text'=>'اکنون برای گام بعدی تصمیم بگیر: نمونهٔ اولیه را اصلاح کن، به شواهد برگرد و مسئله را بازتعریف کن، یا راه‌حل فعلی را به یک موقعیت تازه منتقل کن. مسیر دیگر را نیز می‌توانی با شروع تلاش جدید امتحان کنی.','fields'=>[
            'route'=>['kind'=>'single','label'=>'برنامهٔ توسعه','options'=>['revise'=>'اصلاح طراحی فرایند','redefine'=>'بازگشت به شواهد و تعریف دوبارهٔ مسئله','continue'=>'انتقال آموخته به فرایندی تازه']],
        ],'routes'=>['revise'=>'solution','redefine'=>'evidence','continue'=>'transfer']],
        'transfer'=>['kind'=>'transfer','title'=>'انتقال به مسئله‌ای تازه','text'=>'اکنون همان اصل را در فرایند تحویل یا امانت به کار ببر. پاسخ تو یک تصمیم ثبت‌شده و قابل ارزیابی است، نه اجرای یک سامانهٔ واقعی.','next'=>'transfer_feedback','fields'=>[
            'cause'=>['kind'=>'single','label'=>'چرا ممکن است اثر دوباره رخ دهد؟','options'=>['duplicate'=>'تکرار همان درخواست به‌عنوان درخواست تازه تشخیص داده نمی‌شود','color'=>'رنگ دکمه مناسب نیست']],
            'order'=>['kind'=>'single','label'=>'کنترل کلیدی چه زمانی انجام شود؟','options'=>['before'=>'بررسی سابقه پیش از تغییر موجودی','after'=>'بررسی سابقه پس از تغییر موجودی']],
            'remaining'=>['kind'=>'single','label'=>'موجودی پس از دو دریافت یکسان چقدر است؟','options'=>['0'=>'۰','1'=>'۱','2'=>'۲','3'=>'۳','4'=>'۴']],
            'test'=>['kind'=>'single','label'=>'برای توسعهٔ بعدی کدام آزمون مهم است؟','options'=>['invalid'=>'درخواست نامعتبر یا موجودی ناکافی','font'=>'تغییر فونت']],
        ]],
        'transfer_feedback'=>['kind'=>'transfer_feedback','title'=>'تحلیل انتقال و برنامهٔ بعدی','text'=>'نتیجهٔ انتقال، استقلال پاسخ و فرصت‌های اصلاح در گزارش ثبت می‌شود. اگر پاسخ این بسته را دیده باشی، تلاش دوباره تمرین است و شاهد مستقل تازه محسوب نمی‌شود.','next'=>'report','fields'=>[
            'route'=>['kind'=>'single','label'=>'گام بعدی برنامه','options'=>['retry'=>'آزمودن یک بستهٔ تازه یا تمرین مجدد','finish'=>'ثبت گزارش نهایی تجربه']],
        ],'routes'=>['retry'=>'transfer','finish'=>'report']],
        'report'=>['kind'=>'report','title'=>'خروجی قابل ارزیابی','text'=>'گزارش این تجربه شامل تصمیم‌های ثبت‌شده، نتایج آزمون‌ها، امتیاز هر بُعد و کیفیت انتقال است. این خروجی برای بازبینی مسیر توست؛ اجرای واقعی کد یا تضمین آماده‌بودن سامانهٔ سفارش نیست.','fields'=>[]],
    ],
];
