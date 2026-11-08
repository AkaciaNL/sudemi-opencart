<?php
// Heading
$_['heading_title'] = 'موصّل sudemi';

// Text
$_['text_extension'] = 'الإضافات';
$_['text_success'] = 'تم حفظ الإعدادات.';
$_['text_edit'] = 'اربط هذا المتجر بـ sudemi';
$_['text_intro'] = 'ربط هذا المتجر يتيح لمساعد ذكاء اصطناعي العثور على منتجاتك وإرسال المشترين إليك. تؤكد الاتصال على sudemi بعد تسجيل الدخول إلى حسابك الخاص - هذه الإضافة لا تطلب أبدًا كلمة مرور sudemi الخاصة بك.';
$_['text_connected'] = 'تم الاتصال بـ sudemi.';
$_['text_not_confirmed'] = 'لم يتم التأكيد بعد. افتح الرابط، وأكّد المتجر على sudemi، ثم تحقق مرة أخرى.';
$_['text_disconnected'] = 'تم قطع الاتصال.';
$_['text_sync_started'] = 'بدأت مزامنة الكتالوج وتعمل في الخلفية؛ سيُرسل الكتالوج خلال بضع دقائق.';
$_['text_synced'] = 'تم إرسال الكتالوج.';
$_['text_sync_failed'] = 'تعذّر إرسال الكتالوج. لم يُسحب أي منتج من العرض.';
$_['text_sync_held'] = 'تم إيقاف مزامنة الكتالوج مؤقتًا: كانت ستزيل كثيرًا من المنتجات المعروضة من sudemi، لذلك لم يتغير شيء. إذا كانت هذه المنتجات قد أُزيلت فعلًا من هذا المتجر، فتواصل مع دعم sudemi (support@sudemi.com).';
$_['text_locked'] = 'اسمك القانوني وبلدك وصفتك التجارية ورقم ضريبة القيمة المضافة تحدد ما يستحقه المشتري، لذلك تُؤكَّد مرة واحدة على sudemi وتُغيَّر هناك. لا تستطيع هذه الإضافة تعديلها.';
$_['text_shop_url_fixed'] = 'ثابت. تحقق sudemi من أن هذا المتجر أجاب من هذا العنوان، والقائمة تُخبر المشتري بمصدرها - لذا فإن تغييره يعني الربط من جديد من العنوان الجديد.';
$_['text_key_held'] = 'الجزء الخاص من مفتاح التوقيع لا يغادر هذا الخادم أبدًا ولا يُرسل إلى sudemi أبدًا. ولا يمكن عرضه هنا أيضًا.';

// Entry
$_['entry_shop_url'] = 'عنوان المتجر';
$_['entry_install'] = 'التثبيت';
$_['entry_key'] = 'مفتاح التوقيع';

// Button
$_['button_connect'] = 'ربط هذا المتجر';
$_['button_confirm'] = 'أكّد هذا المتجر على sudemi';
$_['button_check'] = 'تحقق مرة أخرى';
$_['button_sync'] = 'إرسال الكتالوج الآن';
$_['button_disconnect'] = 'قطع الاتصال';

// Error
$_['error_permission'] = 'ليست لديك صلاحية تغيير هذا.';
$_['error_token'] = 'تعذّر التحقق من هذا الطلب. حاول مرة أخرى من هذه الصفحة.';
$_['error_sodium'] = 'إضافة sodium غير موجودة على هذا الخادم. يحتاجها الاتصال لتوقيع الطلبات ولن يعمل بدونها. اطلب من مزوّد الاستضافة تفعيلها.';

// The listing-status panel. These describe a failure the shop owner cannot see from their own
// admin - products stop being shown when the heartbeat stops arriving, and from this side
// everything looks fine - so they say what happened and what to do about it.
$_['text_listing_status'] = 'حالة العرض';
$_['text_never_reported'] = 'لم يُرسل هذا المتجر أي تقرير إلى sudemi بعد. أضف مدخل cron أدناه؛ وإلى أن يعمل، لا تُرسل التقارير إلا ما دامت هذه الصفحة مفتوحة.';
$_['text_unreachable'] = 'تعذّر على هذا المتجر الوصول إلى sudemi. تبقى منتجاته معروضة لفترة قصيرة ثم تُعلَّق، لأن sudemi لا يعرض سعرًا لا يستطيع تأكيده.';
$_['text_listed'] = 'المنتجات معروضة على sudemi.';
$_['entry_pending'] = 'تغييرات الأسعار المعلّقة';
$_['text_pending'] = 'تغييرات في الأسعار أو المخزون لم يتسنَّ إرسالها بعد. ستُعاد محاولتها مع التقرير التالي ولن تضيع.';
$_['entry_trusted_until'] = 'الأسعار مؤكدة حتى';
$_['text_trusted_until'] = 'بعد ذلك، يتوقف sudemi عن عرض هذا المتجر حتى يُرسل تقريرًا من جديد. لا يُحذف شيء.';
$_['entry_cron'] = 'مدخل cron';
$_['text_cron'] = 'أضف هذا إلى crontab الخاص بالحساب الذي يشغّل هذا المتجر. يُبلغ sudemi كل خمس دقائق بأن هذا المتجر ما زال موجودًا وأن أسعاره ما زالت سارية، ويُرسل أي تغيير في الأسعار لم يصل في المرة الأولى. يحتوي الرابط على سرّ؛ تعامل معه ككلمة مرور.';

$_['text_delivery_title'] = 'التوصيل لمشتري sudemi';
$_['text_delivery_preview'] = 'أسعار الشحن المُرسلة إلى sudemi (من طرق الشحن لديك؛ لسلعة واحدة، أول منتج لديك):';
$_['text_delivery_skipped'] = 'البلدان التي ليس لها سعر ثابت (إضافات أخرى، الشحن المجاني بحد أدنى، مناطق جغرافية تغطي جزءًا من البلد فقط) تُستبعد بدل تقديرها.';
$_['text_delivery_none'] = 'تعذّرت قراءة أي سعر شحن من طرق الشحن لديك، لذلك لا يستطيع مشترو sudemi الطلب. فعّل السعر الثابت أو السعر لكل سلعة أو السعر حسب الوزن أو الشحن المجاني غير المشروط للمناطق الجغرافية التي تشحن إليها.';
$_['entry_handling_days'] = 'عدد الأيام حتى ترسل الطلب (حدًا أقصى)';
$_['entry_transit_days'] = 'عدد الأيام التي يستغرقها الناقل (حدًا أقصى)';
$_['button_delivery'] = 'حفظ أوقات التوصيل';
$_['text_delivery_help'] = 'يرى المشترون المجموع على أنه أبعد موعد تسليم متوقع. أدخل أوقاتك الفعلية؛ القيم الافتراضية متحفّظة عن قصد. سيُرسل الكتالوج من جديد مع المزامنة التالية.';
$_['text_delivery_saved'] = 'تم حفظ أوقات التوصيل.';

// Order history on this page
$_['text_history_title'] = 'الطلبات عبر sudemi';
$_['text_history_error'] = 'تعذّر الوصول إلى sudemi لتحميل سجل الطلبات حاليًا. الطلبات نفسها سليمة؛ حاول مجددًا بعد قليل.';
$_['text_history_empty'] = 'لا توجد طلبات عبر sudemi بعد.';
$_['column_order'] = 'الطلب';
$_['column_items'] = 'ما بيع';
$_['column_status'] = 'الحالة';
$_['column_net'] = 'ما تستلمه';
$_['column_customer'] = 'العميل';
$_['text_status_awaiting_seller'] = 'بانتظارك - لم يُخصم من المشتري';
$_['text_status_accepted'] = 'مقبول - بانتظار المتاجر الأخرى في دفعة المشتري؛ لم يُخصم من المشتري بعد، لا تشحن';
$_['text_status_paid'] = 'مؤكَّد ومحصَّل';
$_['text_status_declined'] = 'مرفوض - لم يُخصم شيء';
$_['text_status_shipped'] = 'تم الشحن';
$_['text_status_delivered'] = 'تم التسليم';
$_['text_status_cancelled'] = 'ملغى';
$_['text_status_refunded'] = 'تم الاسترداد';
$_['text_history_awaiting'] = 'طلبات بانتظار التأكيد: %d. لم يُخصم أي مبلغ من هؤلاء المشترين بعد.';
$_['text_options_skipped'] = 'المنتجات التي لها خيار إلزامي (مثل مقاس يجب اختياره): %d. لا تُعرض على sudemi: الطلب القادم من مساعد الذكاء الاصطناعي للمشتري لا يتضمن أي خيار، فلن تعرف أي قطعة ترسل. اعرضها بدلًا من ذلك كمتغيرات OpenCart (كل متغير منتج مستقل).';

// Product form: right of withdrawal (Directive 2011/83/EU, art. 16)
$_['entry_withdrawal'] = 'حق الانسحاب';
$_['text_withdrawal_none'] = 'لا يوجد - ينطبق حق الانسحاب العادي';
$_['help_withdrawal'] = 'فقط للمنتجات التي تندرج قانونيًا ضمن استثناء. يرى المشترون ذلك قبل الدفع؛ ويُمنع سوء الاستخدام.';
$_['text_withdrawal_hygiene'] = 'مختوم لأسباب صحية أو تتعلق بالنظافة (لا يُرَدّ بعد فتح الختم)';
$_['text_withdrawal_custom'] = 'مصنوع وفق مواصفات المشتري أو مخصّص له';
$_['text_withdrawal_perishable'] = 'سريع التلف أو قصير الصلاحية';
$_['text_withdrawal_media'] = 'تسجيلات صوتية أو مرئية أو برمجيات مختومة (لا تُرَدّ بعد فتح الختم)';
$_['text_withdrawal_press'] = 'صحيفة أو دورية أو مجلة';
$_['text_withdrawal_mixed'] = 'يختلط بعد التسليم بمواد أخرى بشكل لا يمكن فصله';
$_['text_withdrawal_alcohol'] = 'مشروبات كحولية بسعر مرتبط بالسوق، تُسلَّم بعد أكثر من 30 يومًا';

// Support: tickets between this shop and sudemi support (sudemi sends the e-mails, not this extension)
$_['text_support_title'] = 'الدعم';
$_['text_support_not_connected'] = 'اربط هذا المتجر بـ sudemi أولًا، وبعدها يمكنك التواصل مع دعم sudemi من هنا.';
$_['text_support_intro'] = 'الأسئلة المتعلقة بالطلبات أو المدفوعات أو التحويلات المالية أو هذه الإضافة توجَّه إلى دعم sudemi. تظهر الردود هنا وتُرسل أيضًا إلى البريد الإلكتروني لحسابك في sudemi.';
$_['text_support_tickets'] = 'تذاكرك';
$_['text_support_empty'] = 'لم تفتح أي تذكرة دعم بعد.';
$_['text_support_new'] = 'فتح تذكرة';
$_['text_support_email'] = 'يرسل إليك sudemi بريدًا إلكترونيًا عندما يرد فريق الدعم. لا ترسل هذه الإضافة أي بريد إلكتروني بنفسها.';
$_['text_support_opened'] = 'تم فتح التذكرة %s. سيتواصل معك دعم sudemi.';
$_['text_support_replied'] = 'تم إرسال ردك.';
$_['text_support_reply_reopens'] = 'الرد يعيد فتح هذه التذكرة المغلقة.';
$_['text_support_from_support'] = 'دعم sudemi';
$_['text_support_from_shop'] = 'أنت (هذا المتجر)';
$_['text_support_status_open'] = 'مفتوحة';
$_['text_support_status_waiting_for_customer'] = 'بانتظار ردك';
$_['text_support_status_answered'] = 'تم الرد';
$_['text_support_status_closed'] = 'مغلقة';
$_['text_support_category_order'] = 'طلب';
$_['text_support_category_payment'] = 'دفعة';
$_['text_support_category_refund_return'] = 'استرداد أو إرجاع';
$_['text_support_category_account'] = 'حسابك في sudemi';
$_['text_support_category_shop_plugin'] = 'هذا المتجر أو الإضافة';
$_['text_support_category_payouts'] = 'التحويلات المالية';
$_['text_support_category_other'] = 'أمر آخر';
$_['column_support_number'] = 'التذكرة';
$_['column_support_subject'] = 'الموضوع';
$_['column_support_status'] = 'الحالة';
$_['column_support_updated'] = 'آخر تحديث';
$_['entry_support_category'] = 'الفئة';
$_['entry_support_subject'] = 'الموضوع';
$_['entry_support_message'] = 'الرسالة';
$_['entry_support_order'] = 'رقم طلب sudemi (اختياري)';
$_['help_support_order'] = 'مثل 3f2a9c1e-…؛ اتركه فارغًا إن لم يكن الأمر متعلقًا بطلب واحد';
$_['entry_support_reply'] = 'ردك';
$_['button_support_open'] = 'فتح التذكرة';
$_['button_support_reply'] = 'إرسال الرد';
$_['button_support_back'] = 'العودة إلى كل التذاكر';
$_['error_support_invalid'] = 'يرجى مراجعة النموذج: يجب أن يتكون الموضوع من 3 إلى 200 حرف، والرسالة من 10,000 حرف كحد أقصى، وأن يكون رقم الطلب لطلب sudemi يخص هذا المتجر.';
$_['error_support_not_found'] = 'لم يتم العثور على هذه التذكرة لهذا المتجر.';
$_['error_support_too_many'] = 'عدد كبير جدًا من التذاكر: 20 تذكرة مفتوحة كحد أقصى و10 تذاكر جديدة في الساعة كحد أقصى. رد على تذكرة قائمة أو حاول لاحقًا.';
$_['error_support_unavailable'] = 'تعذّر الوصول إلى دعم sudemi الآن. لم يُرسل أي شيء؛ يرجى المحاولة بعد قليل.';

// sudemi refused this site as not being the connected shop (409 site_mismatch): a staging copy,
// a restored backup, or the shop moved. %s is the address the shop is connected under.
$_['text_site_mismatch_at'] = 'رفض sudemi الطلبات القادمة من هذا الموقع: هذا المتجر مربوط بـ sudemi بعنوان آخر (%s). إذا كان هذا الموقع نسخة تجريبية (staging) أو نسخة احتياطية مستعادة، فافصل sudemi هنا. وإذا انتقل المتجر إلى هذا العنوان، فاربطه من جديد.';
$_['text_site_mismatch'] = 'رفض sudemi الطلبات القادمة من هذا الموقع: هذا المتجر مربوط بـ sudemi بعنوان آخر. إذا كان هذا الموقع نسخة تجريبية (staging) أو نسخة احتياطية مستعادة، فافصل sudemi هنا. وإذا انتقل المتجر إلى هذا العنوان، فاربطه من جديد.';

// Return requests from buyers (answered within 7 days: accept, or reject with a reason the buyer
// sees). The notes go into the order history; %s is the reason given.
$_['text_requests_title'] = 'طلبات الإرجاع';
$_['text_requests_intro'] = 'يرغب المشترون في إرجاع هذه الطلبات التي تم تسليمها. أجب عن كل طلب قبل التاريخ المعروض: اقبله، أو ارفضه مع ذكر السبب (سيراه المشتري). الطلب الذي لا يُجاب عنه يُحال إلى sudemi بعد 7 أيام. تُحدَّث القائمة مع كل تقرير يُرسل إلى sudemi.';
$_['text_requests_empty'] = 'لا توجد طلبات إرجاع مفتوحة.';
$_['column_request_order'] = 'الطلب';
$_['column_request_reason'] = 'سبب المشتري';
$_['column_request_requested'] = 'تاريخ الطلب';
$_['column_request_respond_by'] = 'الرد قبل';
$_['column_request_answer'] = 'ردّك';
$_['text_request_no_local'] = 'غير موجود في هذا المتجر';
$_['text_request_no_reason'] = 'لم يُذكر سبب';
$_['text_request_overdue'] = 'متأخر';
$_['entry_request_reject_reason'] = 'سبب الرفض (إلزامي، يظهر للمشتري)';
$_['button_request_accept'] = 'قبول الإرجاع';
$_['button_request_reject'] = 'رفض الإرجاع';
$_['text_request_accepted'] = 'تم قبول الإرجاع. سيعيد المشتري الآن إرسال المنتج. عند وصوله، رُدّ المبلغ بتغيير حالة الطلب إلى "تم الاسترداد": يصل المبلغ إلى المشتري عبر sudemi ويُغلق الطلب.';
$_['text_request_rejected'] = 'تم رفض الإرجاع. يرى المشتري السبب الذي ذكرته.';
$_['text_request_already'] = 'تمت الإجابة عن هذا الطلب من قبل. تم تحديث القائمة.';
$_['error_request_not_found'] = 'لم يعد هناك طلب إرجاع مفتوح لهذا الطلب. تم تحديث القائمة.';
$_['error_request_reason'] = 'رفض الإرجاع يتطلب ذكر سبب؛ سيظهر للمشتري.';
$_['error_request_unavailable'] = 'تعذّر الاتصال بـ sudemi حاليًا. لم يُرسل أي رد؛ يُرجى المحاولة مرة أخرى بعد قليل.';
$_['text_request_note_accepted'] = 'sudemi: تم قبول طلب الإرجاع. رُدّ مبلغ الطلب (الحالة "تم الاسترداد") عند عودة المنتج.';
$_['text_request_note_rejected'] = 'sudemi: تم رفض طلب الإرجاع. السبب المعروض للمشتري: %s';
$_['text_history_return_requested'] = 'طُلب الإرجاع';
// The totals of a sudemi order (order page, invoice), written in the store's language.
$_['text_order_sub_total'] = 'المجموع الفرعي';
$_['text_order_shipping'] = 'شحن sudemi';
$_['text_order_total'] = 'الإجمالي';
