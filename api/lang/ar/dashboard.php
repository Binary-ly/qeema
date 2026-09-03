<?php

// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

return [
    'title' => 'كم يكلّف شهر الطفل، مدينةً مدينة',
    'tagline' => 'سعر الأشياء الخمسة عشر التي يحتاجها طفل واحد هذا الأسبوع — غذاء وحليب أطفال ودواء وأدوات مدرسية وغاز وماء — بسعر الصرف الذي يدفعه الناس فعلاً. مجاني لأي أحد.',
    'door_report' => 'عندي سعر',
    'door_report_sub' => 'أبلغ عن سعر واحد من مدينتك. نقرتان، ويعمل بلا إنترنت.',
    'door_data' => 'أحتاج الرقم',
    'door_data_sub' => 'واجهة برمجية أو ملف CSV أو هذه الصفحة بصيغة JSON. بلا مفتاح ولا حساب.',
    'afford_partial' => 'في :location، تبلغ الأصناف المسعّرة (:priced) :cost :currency — أي :share% من :income_label.',
    'afford_full' => 'شهر الطفل في :location يكلّف :cost :currency — أي :share% من :income_label.',
    'afford_basis' => 'قياساً إلى :income_label، وهو :income :currency في الشهر.',
    'list_lead' => 'في :location، هذا الشهر:',
    'list_priced' => ':priced من :total مسعّرة.',
    'list_estimated' => 'تقدير',
    'list_none' => 'لا سعر بعد',
    'list_total' => 'مجموع الأصناف المسعّرة: :cost :currency.',
    'qr_title' => 'وزّع هذا',
    'qr_body' => 'كل من يمسح هذا الرمز يستطيع الإبلاغ عن سعر من مدينته. اطبعه أو أرسل الرابط.',
    'qr_alt' => 'رمز QR يؤدي إلى صفحة الإبلاغ عن سعر',

    'headline_median' => 'الوسيط بين المواقع القابلة للمقارنة',
    'headline_usd' => 'بالدولار الأمريكي',
    'headline_spread' => 'من الأرخص إلى الأغلى',
    'headline_locations' => 'مواقع قابلة للمقارنة',
    'as_of' => 'حتى تاريخ :date',
    'no_data' => 'لا توجد أرقام منشورة بعد.',
    'no_comparable' => 'لا يوجد موقع بسلة مكتملة التسعير بعد، لذا لا يوجد وسيط قابل للمقارنة لنشره. تبقى الأرقام أدناه دقيقة لكل موقع على حدة.',
    'no_data_body' => 'تظهر هنا بعد الإبلاغ عن الأسعار وتشغيل المؤشر.',

    'map_title' => 'أين التكلفة أعلى',
    'map_desc' => 'كل نقطة موقع مشارك، ملوّنة حسب تكلفة السلة نفسها. النقاط المفرغة غير قابلة للمقارنة — انظر أدناه.',
    'map_alt' => 'خريطة المواقع المشاركة ملوّنة حسب تكلفة السلة.',
    'legend_cheaper' => 'أقل تكلفة',
    'legend_dearer' => 'أعلى تكلفة',
    'legend_incomparable' => 'غير قابل للمقارنة',

    'comparable_note' => 'غير قابل للمقارنة',
    'comparable_explain' => 'جزء من هذه السلة بلا سعر، لذا لا يقاس مجموعها على قدم المساواة مع موقع مكتمل التسعير. السلة الناقصة تبدو أرخص لمجرد أن جزءاً منها مفقود، ما يجعل المكان قليل البلاغات يبدو زهيداً. تُعرض هذه المواقع دون ترتيبها.',

    'quality' => 'جودة البيانات',
    'quality_good' => 'جيدة',
    'quality_moderate' => 'متوسطة',
    'quality_low' => 'منخفضة',
    'coverage' => 'التغطية',
    'imputed' => 'مُقدَّرة',
    'imputed_explain' => 'نسبة السلة المسعّرة بنموذج بدل الرصد المباشر. القيم المقدّرة لا تُقدَّم أبداً على أنها قياسات.',
    'observed' => 'مرصودة',

    'chart_national' => 'تكلفة السلة عبر الزمن',
    'chart_national_desc' => 'الوسيط بين المواقع المكتملة التسعير.',
    'chart_await' => 'لا يوجد خط بعد — يحتاج المنحنى إلى تاريخين سُعِّرت فيهما السلة كاملةً في مكان ما.',
    'chart_locations' => 'حسب الموقع',
    'chart_fx' => 'سعر الصرف',
    'chart_fx_desc' => 'السعر الرسمي والموازي. الفجوة بينهما غالباً أول مؤشر ظاهر على الضغط الاقتصادي.',
    'chart_premium' => 'علاوة السوق الموازي',
    'chart_official' => 'الرسمي',
    'chart_parallel' => 'الموازي',
    'chart_unavailable' => 'لا يوجد تاريخ كافٍ للرسم بعد.',

    'table_title' => 'كل المواقع',
    'table_location' => 'الموقع',
    'table_cost' => 'تكلفة السلة',
    'table_coverage' => 'التغطية',
    'table_quality' => 'الجودة',
    'table_updated' => 'آخر تحديث',
    /*
     * All six Arabic plural forms, in the order Laravel's MessageSelector asks
     * for them: 0, 1, 2, 3–10, 11–99, 100+.
     *
     * Two forms is not a shortcut here, it is a wrong answer. For 103 days the
     * selector computes index 3, finds no such segment, and falls back to the
     * *first* one — so a figure 103 days old was labelled "منذ يوم", one day
     * ago, in the table and under the headline date. English needs two forms
     * and Arabic needs six; a translation file that assumes the source
     * language's plural rules will always read fluently and mean something
     * else.
     */
    'days_ago' => 'اليوم|منذ يوم|منذ يومين|منذ :count أيام|منذ :count يوماً|منذ :count يوم',
    'today' => 'اليوم',

    'use_the_data' => 'استخدم هذه البيانات',
    'use_the_data_body' => 'كل ما في هذه الصفحة متاح عبر واجهة برمجية عامة لا تحتاج مفتاحاً، وكملف CSV. البيانات بترخيص CC BY 4.0، والبرمجية بترخيص Apache-2.0.',
    'spec_note' => 'الحقل المميّز يرد في كل استجابة. القيمة المقدّرة تُوسم دائماً بأنها تقدير، في الواجهة البرمجية كما في هذه الصفحة.',
    'api_link' => 'توثيق الواجهة البرمجية',
    'json_link' => 'هذه الصفحة بصيغة JSON',
    'csv_link' => 'تنزيل CSV',
    'source_link' => 'الشيفرة المصدرية',

    'hero_items' => 'بنود مُسعَّرة',
    'hero_locations' => 'مواقع لها بيانات',
    'hero_updated' => 'أحدث البيانات',

    'basket_title' => 'ما يحتاجه الطفل',
    'basket_desc' => 'السلة التي يجري تسعيرها، مرتبة من الأثقل وزناً. الوزن هو حصة الإنفاق على الطفل التي يمثلها البند، لذا فإن بنداً بلا سعر في أعلى القائمة يكلّف المؤشر أكثر بكثير مما يكلّفه في أسفلها.',
    'basket_item' => 'البند',
    'basket_weight' => 'الوزن',
    'basket_where' => 'مُسعَّر في',
    'basket_locations' => ':count من :total موقعاً',
    'basket_none' => 'لا سعر في أي موقع',
    'basket_stack_label' => '‏:percent% من سلة الطفل، بحسب الوزن، بلا سعر في أي موقع هنا.',
    'basket_gap' => ':count من :total بنداً يحتاجها الطفل بلا سعر في أي موقع هنا. هذه هي الفجوة التي وُجدت هذه المنصة لسدّها.',
    'gap_lead' => 'لـ :count من أصل :total شيئاً يحتاجها الطفل هذا الشهر، لم يسجّل أحد سعراً. ولا في أي مدينة.',
    'gap_hollow' => 'المفرغة منها بلا سعر في أي مكان. هي الفجوة التي وُجدت هذه المنصة لسدّها.',

    'footer_license' => 'البيانات :license · البرمجية Apache-2.0 · يُعاد نشر الأرقام بحرية مع الإسناد.',

    'language' => 'اللغة',
    'skip_to_content' => 'تخطَّ إلى المحتوى',
];
