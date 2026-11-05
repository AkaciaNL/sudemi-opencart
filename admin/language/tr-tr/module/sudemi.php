<?php
// Heading
$_['heading_title'] = 'sudemi Bağlayıcı';

// Text
$_['text_extension'] = 'Eklentiler';
$_['text_success'] = 'Ayarlar kaydedildi.';
$_['text_edit'] = 'Bu mağazayı sudemi\'ye bağlayın';
$_['text_intro'] = 'Bu mağazayı bağladığınızda bir yapay zekâ asistanı ürünlerinizi bulabilir ve alıcıları size yönlendirebilir. Bağlantıyı, kendi hesabınızla oturum açarak sudemi\'de onaylarsınız — bu eklenti sudemi şifrenizi asla sormaz.';
$_['text_connected'] = 'sudemi\'ye bağlandı.';
$_['text_not_confirmed'] = 'Henüz onaylanmadı. Bağlantıyı açın, mağazayı sudemi\'de onaylayın, ardından tekrar kontrol edin.';
$_['text_disconnected'] = 'Bağlantı kesildi.';
$_['text_synced'] = 'Katalog gönderildi.';
$_['text_sync_failed'] = 'Katalog gönderilemedi. Hiçbir ürün listeden kaldırılmadı.';
$_['text_locked'] = 'Yasal adınız, ülkeniz, tacir statünüz ve KDV numaranız, alıcının hangi haklara sahip olduğunu belirler; bu nedenle sudemi\'de bir kez onaylanır ve orada değiştirilir. Bu eklenti bunları düzenleyemez.';
$_['text_shop_url_fixed'] = 'Sabittir. sudemi bu mağazanın bu adresten yanıt verdiğini doğruladı ve her ilan, alıcıya nereden geldiğini gösterir — bu yüzden adresi değiştirmek, yeni adresten yeniden bağlanmak anlamına gelir.';
$_['text_key_held'] = 'İmzalama anahtarının özel kısmı bu sunucudan asla çıkmaz ve sudemi\'ye asla gönderilmez. Burada da gösterilemez.';

// Entry
$_['entry_shop_url'] = 'Mağaza adresi';
$_['entry_install'] = 'Kurulum';
$_['entry_key'] = 'İmzalama anahtarı';

// Button
$_['button_connect'] = 'Bu mağazayı bağla';
$_['button_confirm'] = 'Bu mağazayı sudemi\'de onayla';
$_['button_check'] = 'Tekrar kontrol et';
$_['button_sync'] = 'Kataloğu şimdi gönder';
$_['button_disconnect'] = 'Bağlantıyı kes';

// Error
$_['error_permission'] = 'Bunu değiştirme yetkiniz yok.';
$_['error_token'] = 'Bu istek doğrulanamadı. Bu sayfadan tekrar deneyin.';
$_['error_sodium'] = 'Bu sunucuda sodium eklentisi eksik. Bağlantı, istekleri imzalamak için buna ihtiyaç duyar ve onsuz çalışmaz. Barındırma sağlayıcınızdan bunu etkinleştirmesini isteyin.';

// The listing-status panel. These describe a failure the shop owner cannot see from their own
// admin — products stop being shown when the heartbeat stops arriving, and from this side
// everything looks fine — so they say what happened and what to do about it.
$_['text_listing_status'] = 'Listeleme durumu';
$_['text_never_reported'] = 'Bu mağaza henüz sudemi\'ye durum bildirmedi. Aşağıdaki cron girdisini ekleyin; o çalışana kadar bildirimler yalnızca bu sayfa açıkken gönderilir.';
$_['text_unreachable'] = 'Bu mağaza sudemi\'ye ulaşamadı. Ürünleri kısa bir süre daha listede kalır, ardından duraklatılır; çünkü sudemi doğrulayamadığı bir fiyatı sunmaz.';
$_['text_listed'] = 'Ürünler sudemi\'de gösteriliyor.';
$_['entry_pending'] = 'Bekleyen fiyat değişiklikleri';
$_['text_pending'] = 'Henüz gönderilemeyen fiyat veya stok değişiklikleri. Bir sonraki bildirimde yeniden denenir ve kaybolmaz.';
$_['entry_trusted_until'] = 'Fiyatların onaylı olduğu son tarih';
$_['text_trusted_until'] = 'Bu tarihten sonra sudemi, mağaza yeniden durum bildirene kadar bu mağazayı göstermez. Hiçbir şey silinmez.';
$_['entry_cron'] = 'Cron girdisi';
$_['text_cron'] = 'Bunu, bu mağazayı çalıştıran hesabın crontab dosyasına ekleyin. Her beş dakikada bir sudemi\'ye bu mağazanın hâlâ burada olduğunu ve fiyatlarının hâlâ geçerli olduğunu bildirir, ayrıca ilk seferde iletilemeyen fiyat değişikliklerini gönderir. Bağlantı gizli bir anahtar içerir; onu bir şifre gibi koruyun.';

$_['text_delivery_title'] = 'sudemi alıcılarına teslimat';
$_['text_delivery_preview'] = 'sudemi\'ye gönderilen kargo ücretleri (kargo yöntemlerinize göre; tek ürün, ilk ürününüz):';
$_['text_delivery_skipped'] = 'Sabit fiyatı olmayan ülkeler (diğer eklentiler, minimum tutarlı ücretsiz kargo, bir ülkenin yalnızca bir kısmını kapsayan coğrafi bölgeler) tahmin edilmek yerine dışarıda bırakılır.';
$_['text_delivery_none'] = 'Kargo yöntemlerinizden hiçbir kargo ücreti okunamadı, bu yüzden sudemi alıcıları sipariş veremiyor. Gönderim yaptığınız coğrafi bölgeler için Sabit ücret, Ürün başına, Ağırlığa göre veya koşulsuz Ücretsiz kargo yöntemini etkinleştirin.';
$_['entry_handling_days'] = 'Siparişi kargoya verene kadar geçen gün sayısı (en fazla)';
$_['entry_transit_days'] = 'Kargo firmasının teslimat süresi, gün (en fazla)';
$_['button_delivery'] = 'Teslimat sürelerini kaydet';
$_['text_delivery_help'] = 'Alıcılar bu iki sürenin toplamını en geç teslimat tahmini olarak görür. Gerçek sürelerinizi girin; varsayılan değerler bilerek temkinli tutulmuştur. Katalog bir sonraki eşitlemede yeniden gönderilir.';
$_['text_delivery_saved'] = 'Teslimat süreleri kaydedildi.';

// Order history on this page
$_['text_history_title'] = 'sudemi üzerinden gelen siparişler';
$_['text_history_error'] = 'Sipariş geçmişini yüklemek için şu anda sudemi\'ye ulaşılamadı. Siparişlerin kendisinde bir sorun yok; kısa bir süre sonra tekrar deneyin.';
$_['text_history_empty'] = 'Henüz sudemi üzerinden gelen sipariş yok.';
$_['column_order'] = 'Sipariş';
$_['column_items'] = 'Satılanlar';
$_['column_status'] = 'Durum';
$_['column_net'] = 'Size kalan';
$_['column_customer'] = 'Müşteri';
$_['text_status_awaiting_seller'] = 'Sizi bekliyor — alıcıdan ücret alınmadı';
$_['text_status_accepted'] = 'Kabul edildi — alıcının ödemesindeki diğer mağazalar bekleniyor; alıcıdan henüz ücret alınmadı, kargolamayın';
$_['text_status_paid'] = 'Onaylandı ve tahsil edildi';
$_['text_status_declined'] = 'Reddedildi — ücret alınmadı';
$_['text_status_shipped'] = 'Kargolandı';
$_['text_status_delivered'] = 'Teslim edildi';
$_['text_status_cancelled'] = 'İptal edildi';
$_['text_status_refunded'] = 'İade edildi';
$_['text_history_awaiting'] = 'Onay bekleyen siparişler: %d. Bu alıcılardan henüz ücret alınmadı.';
$_['text_options_skipped'] = 'Zorunlu seçeneği olan %d ürün (örneğin seçilmesi gereken bir beden) sudemi\'de sunulmaz: alıcının yapay zekâ asistanından gelen siparişte seçenek bulunmaz, bu yüzden hangi ürünü göndereceğinizi bilemezsiniz. Bunun yerine bunları OpenCart varyantları olarak sunun (her varyant ayrı bir üründür).';
