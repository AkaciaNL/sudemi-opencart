<?php
// Heading
$_['heading_title'] = 'מחבר sudemi';

// Text
$_['text_extension'] = 'הרחבות';
$_['text_success'] = 'ההגדרות נשמרו.';
$_['text_edit'] = 'חיבור החנות הזו ל-sudemi';
$_['text_intro'] = 'חיבור החנות הזו מאפשר לעוזר בינה מלאכותית למצוא את המוצרים שלך ולשלוח אליך קונים. את החיבור מאשרים ב-sudemi, כשמחוברים לחשבון שלך - ההרחבה הזו לעולם לא מבקשת את הסיסמה שלך ב-sudemi.';
$_['text_connected'] = 'מחובר ל-sudemi.';
$_['text_not_confirmed'] = 'עדיין לא אושר. יש לפתוח את הקישור, לאשר את החנות ב-sudemi ואז לבדוק שוב.';
$_['text_disconnected'] = 'נותק.';
$_['text_synced'] = 'הקטלוג נשלח.';
$_['text_sync_failed'] = 'לא ניתן היה לשלוח את הקטלוג. אף מוצר לא הוסר מהרישום.';
$_['text_locked'] = 'השם המשפטי, המדינה, מעמד העוסק ומספר המע״מ שלך קובעים מה מגיע לקונה, ולכן הם מאושרים פעם אחת ב-sudemi ומשתנים שם. ההרחבה הזו לא יכולה לערוך אותם.';
$_['text_shop_url_fixed'] = 'קבועה. sudemi בדק שהחנות הזו ענתה מהכתובת הזו, ורישום מוצר מציין לקונה מאיפה הוא הגיע - לכן שינוי הכתובת פירושו חיבור מחדש מהכתובת החדשה.';
$_['text_key_held'] = 'החלק הפרטי של מפתח החתימה לעולם לא יוצא מהשרת הזה ולעולם לא נשלח ל-sudemi. גם אי אפשר להציג אותו כאן.';

// Entry
$_['entry_shop_url'] = 'כתובת החנות';
$_['entry_install'] = 'התקנה';
$_['entry_key'] = 'מפתח חתימה';

// Button
$_['button_connect'] = 'חיבור החנות הזו';
$_['button_confirm'] = 'אישור החנות הזו ב-sudemi';
$_['button_check'] = 'בדיקה חוזרת';
$_['button_sync'] = 'שליחת הקטלוג עכשיו';
$_['button_disconnect'] = 'ניתוק';

// Error
$_['error_permission'] = 'אין לך הרשאה לשנות זאת.';
$_['error_token'] = 'לא ניתן היה לאמת את הבקשה. יש לנסות שוב מהדף הזה.';
$_['error_sodium'] = 'בשרת הזה חסרה ההרחבה sodium. החיבור זקוק לה כדי לחתום על בקשות ולא יעבוד בלעדיה. יש לבקש מספק האחסון להפעיל אותה.';

// The listing-status panel. These describe a failure the shop owner cannot see from their own
// admin - products stop being shown when the heartbeat stops arriving, and from this side
// everything looks fine - so they say what happened and what to do about it.
$_['text_listing_status'] = 'סטטוס הרישום';
$_['text_never_reported'] = 'החנות הזו עדיין לא דיווחה ל-sudemi. יש להוסיף את רשומת ה-cron שלמטה; עד שהיא תפעל, דיווחים נשלחים רק כשהדף הזה פתוח.';
$_['text_unreachable'] = 'החנות הזו לא הצליחה להגיע ל-sudemi. המוצרים שלה נשארים רשומים לזמן קצר ואז מושהים, כי sudemi לא יציע מחיר שאינו יכול לאשר.';
$_['text_listed'] = 'המוצרים מוצגים ב-sudemi.';
$_['entry_pending'] = 'שינויי מחיר ממתינים';
$_['text_pending'] = 'שינויי מחיר או מלאי שעדיין לא ניתן היה לשלוח. הם יישלחו שוב בדיווח הבא ולא יאבדו.';
$_['entry_trusted_until'] = 'המחירים מאושרים עד';
$_['text_trusted_until'] = 'לאחר מכן, sudemi מפסיק להציג את החנות הזו עד שתדווח שוב. שום דבר לא נמחק.';
$_['entry_cron'] = 'רשומת cron';
$_['text_cron'] = 'יש להוסיף את זה ל-crontab של החשבון שמריץ את החנות הזו. הרשומה מודיעה ל-sudemi כל חמש דקות שהחנות עדיין כאן ושהמחירים שלה עדיין בתוקף, ושולחת כל שינוי מחיר שלא עבר בפעם הראשונה. הקישור מכיל סוד; יש להתייחס אליו כמו לסיסמה.';

$_['text_delivery_title'] = 'משלוח לקונים של sudemi';
$_['text_delivery_preview'] = 'מחירי משלוח שנשלחו ל-sudemi (משיטות המשלוח שלך; פריט אחד, המוצר הראשון שלך):';
$_['text_delivery_skipped'] = 'מדינות ללא מחיר קבוע (הרחבות אחרות, משלוח חינם עם מינימום, אזורים גאוגרפיים שמכסים רק חלק ממדינה) מושמטות במקום לנחש.';
$_['text_delivery_none'] = 'לא ניתן היה לקרוא אף מחיר משלוח משיטות המשלוח שלך, ולכן קונים ב-sudemi לא יכולים להזמין. יש להפעיל תעריף קבוע, לפי פריט, לפי משקל או משלוח חינם ללא תנאים עבור האזורים הגאוגרפיים שאליהם אתה שולח.';
$_['entry_handling_days'] = 'ימים עד שליחת הזמנה (לכל היותר)';
$_['entry_transit_days'] = 'ימים שלוקח לחברת המשלוחים (לכל היותר)';
$_['button_delivery'] = 'שמירת זמני משלוח';
$_['text_delivery_help'] = 'הקונים רואים את הסכום כהערכת המסירה המאוחרת ביותר. יש להגדיר את הזמנים האמיתיים שלך; ברירות המחדל זהירות בכוונה. הקטלוג יישלח שוב בסנכרון הבא.';
$_['text_delivery_saved'] = 'זמני המשלוח נשמרו.';

// Order history on this page
$_['text_history_title'] = 'הזמנות דרך sudemi';
$_['text_history_error'] = 'לא ניתן היה להגיע ל-sudemi כדי לטעון את היסטוריית ההזמנות כרגע. אין שום בעיה בהזמנות עצמן; כדאי לנסות שוב בעוד רגע.';
$_['text_history_empty'] = 'עדיין אין הזמנות דרך sudemi.';
$_['column_order'] = 'הזמנה';
$_['column_items'] = 'מה נמכר';
$_['column_status'] = 'סטטוס';
$_['column_net'] = 'הסכום שתקבל';
$_['column_customer'] = 'לקוח';
$_['text_status_awaiting_seller'] = 'ממתין לך - הקונה לא חויב';
$_['text_status_accepted'] = 'התקבלה - ממתינה לחנויות האחרות בתשלום של הקונה; הקונה עדיין לא חויב, אין לשלוח';
$_['text_status_paid'] = 'אושרה וחויבה';
$_['text_status_declined'] = 'נדחתה - לא בוצע חיוב';
$_['text_status_shipped'] = 'נשלחה';
$_['text_status_delivered'] = 'נמסרה';
$_['text_status_cancelled'] = 'בוטל';
$_['text_status_refunded'] = 'הוחזר';
$_['text_history_awaiting'] = 'הזמנות שממתינות לאישור: %d. הקונים האלה עדיין לא חויבו.';
$_['text_options_skipped'] = 'מוצרים עם אפשרות חובה (למשל מידה לבחירה): %d. הם לא מוצעים ב-sudemi: הזמנה מעוזר ה-AI של הקונה לא כוללת אפשרות, כך שלא תדעו איזה פריט לשלוח. הציעו אותם במקום זאת כגרסאות (variants) של OpenCart (כל גרסה היא מוצר נפרד).';

// Product form: right of withdrawal (Directive 2011/83/EU, art. 16)
$_['entry_withdrawal'] = 'זכות ביטול';
$_['text_withdrawal_none'] = 'אין - חלה זכות הביטול הרגילה';
$_['help_withdrawal'] = 'רק עבור מוצרים שחל עליהם חריג על פי חוק. הקונים רואים זאת לפני התשלום; שימוש לרעה אסור.';
$_['text_withdrawal_hygiene'] = 'אטום מטעמי בריאות או היגיינה (לא ניתן להחזיר לאחר פתיחת האריזה)';
$_['text_withdrawal_custom'] = 'מיוצר לפי מפרט הקונה או מותאם אישית';
$_['text_withdrawal_perishable'] = 'מתכלה או בעל תוקף קצר';
$_['text_withdrawal_media'] = 'הקלטות שמע, וידאו או תוכנה באריזה אטומה (לא ניתן להחזיר לאחר פתיחה)';
$_['text_withdrawal_press'] = 'עיתון, כתב עת או מגזין';
$_['text_withdrawal_mixed'] = 'מתערבב לאחר המסירה באופן בלתי נפרד עם פריטים אחרים';
$_['text_withdrawal_alcohol'] = 'משקאות אלכוהוליים במחיר תלוי שוק, הנמסרים לאחר יותר מ-30 יום';

// Support: tickets between this shop and sudemi support (sudemi sends the e-mails, not this extension)
$_['text_support_title'] = 'תמיכה';
$_['text_support_not_connected'] = 'חברו תחילה את החנות ל-sudemi; לאחר מכן תוכלו לפנות מכאן לתמיכה של sudemi.';
$_['text_support_intro'] = 'שאלות על הזמנות, תשלומים, העברות כספים או על התוסף הזה יש להפנות לתמיכה של sudemi. התשובות מופיעות כאן ונשלחות גם בדוא״ל לכתובת של חשבון ה-sudemi שלכם.';
$_['text_support_tickets'] = 'הפניות שלכם';
$_['text_support_empty'] = 'עדיין לא פתחתם פניות לתמיכה.';
$_['text_support_new'] = 'פתיחת פנייה';
$_['text_support_email'] = 'sudemi ישלח לכם דוא״ל כשהתמיכה תענה. התוסף עצמו אינו שולח דוא״ל.';
$_['text_support_opened'] = 'פנייה %s נפתחה. התמיכה של sudemi תחזור אליכם.';
$_['text_support_replied'] = 'התשובה שלכם נשלחה.';
$_['text_support_reply_reopens'] = 'מענה יפתח מחדש את הפנייה הסגורה הזו.';
$_['text_support_from_support'] = 'התמיכה של sudemi';
$_['text_support_from_shop'] = 'אתם (החנות הזו)';
$_['text_support_status_open'] = 'פתוחה';
$_['text_support_status_waiting_for_customer'] = 'ממתינה לתשובתכם';
$_['text_support_status_answered'] = 'נענתה';
$_['text_support_status_closed'] = 'סגורה';
$_['text_support_category_order'] = 'הזמנה';
$_['text_support_category_payment'] = 'תשלום';
$_['text_support_category_refund_return'] = 'החזר כספי או החזרת מוצר';
$_['text_support_category_account'] = 'חשבון ה-sudemi שלכם';
$_['text_support_category_shop_plugin'] = 'החנות או התוסף';
$_['text_support_category_payouts'] = 'העברות כספים';
$_['text_support_category_other'] = 'נושא אחר';
$_['column_support_number'] = 'פנייה';
$_['column_support_subject'] = 'נושא';
$_['column_support_status'] = 'סטטוס';
$_['column_support_updated'] = 'עדכון אחרון';
$_['entry_support_category'] = 'קטגוריה';
$_['entry_support_subject'] = 'נושא';
$_['entry_support_message'] = 'הודעה';
$_['entry_support_order'] = 'מזהה הזמנה ב-sudemi (לא חובה)';
$_['help_support_order'] = 'לדוגמה 3f2a9c1e-…; השאירו ריק אם הפנייה אינה על הזמנה אחת';
$_['entry_support_reply'] = 'התשובה שלכם';
$_['button_support_open'] = 'פתיחת פנייה';
$_['button_support_reply'] = 'שליחת תשובה';
$_['button_support_back'] = 'חזרה לכל הפניות';
$_['error_support_invalid'] = 'נא לבדוק את הטופס: הנושא צריך להכיל 3 עד 200 תווים, ההודעה עד 10,000 תווים, ומזהה ההזמנה חייב להיות של הזמנת sudemi של החנות הזו.';
$_['error_support_not_found'] = 'הפנייה לא נמצאה עבור החנות הזו.';
$_['error_support_too_many'] = 'יותר מדי פניות בבת אחת: עד 20 פניות פתוחות ועד 10 פניות חדשות בשעה. השיבו בפנייה קיימת או נסו שוב מאוחר יותר.';
$_['error_support_unavailable'] = 'לא ניתן להתחבר כרגע לתמיכה של sudemi. דבר לא נשלח; נסו שוב בעוד רגע.';

// sudemi refused this site as not being the connected shop (409 site_mismatch): a staging copy,
// a restored backup, or the shop moved. %s is the address the shop is connected under.
$_['text_site_mismatch_at'] = 'sudemi דחה בקשות מהאתר הזה: החנות הזו מחוברת ל-sudemi בכתובת אחרת (%s). אם האתר הזה הוא עותק בדיקות (staging) או גיבוי משוחזר, יש לנתק את sudemi כאן. אם החנות עברה לכתובת הזו, יש לחבר אותה מחדש.';
$_['text_site_mismatch'] = 'sudemi דחה בקשות מהאתר הזה: החנות הזו מחוברת ל-sudemi בכתובת אחרת. אם האתר הזה הוא עותק בדיקות (staging) או גיבוי משוחזר, יש לנתק את sudemi כאן. אם החנות עברה לכתובת הזו, יש לחבר אותה מחדש.';
