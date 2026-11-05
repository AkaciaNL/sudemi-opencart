<?php
// Heading
$_['heading_title'] = 'sudemi 连接器';

// Text
$_['text_extension'] = '扩展';
$_['text_success'] = '设置已保存。';
$_['text_edit'] = '将此店铺连接到 sudemi';
$_['text_intro'] = '连接此店铺后，AI 助手即可找到您的商品并为您带来买家。您需要登录自己的账户，在 sudemi 上确认连接——本扩展绝不会索要您的 sudemi 密码。';
$_['text_connected'] = '已连接到 sudemi。';
$_['text_not_confirmed'] = '尚未确认。请打开链接，在 sudemi 上确认店铺，然后再次检查。';
$_['text_disconnected'] = '已断开连接。';
$_['text_synced'] = '商品目录已发送。';
$_['text_sync_failed'] = '无法发送商品目录。没有任何商品被下架。';
$_['text_locked'] = '您的法定名称、国家/地区、经营者身份和增值税号决定了买家享有哪些权益，因此它们只在 sudemi 上确认一次，并在那里修改。本扩展无法编辑这些信息。';
$_['text_shop_url_fixed'] = '已固定。sudemi 已核实此店铺从该地址作出响应，且商品信息会告诉买家其来源——因此更改地址意味着需要从新地址重新连接。';
$_['text_key_held'] = '签名密钥的私钥部分绝不会离开此服务器，也绝不会发送给 sudemi。它也无法在此显示。';

// Entry
$_['entry_shop_url'] = '店铺地址';
$_['entry_install'] = '安装';
$_['entry_key'] = '签名密钥';

// Button
$_['button_connect'] = '连接此店铺';
$_['button_confirm'] = '在 sudemi 上确认此店铺';
$_['button_check'] = '再次检查';
$_['button_sync'] = '立即发送商品目录';
$_['button_disconnect'] = '断开连接';

// Error
$_['error_permission'] = '您无权更改此项。';
$_['error_token'] = '无法验证该请求。请从本页面重试。';
$_['error_sodium'] = '此服务器缺少 sodium 扩展。连接需要它来签名请求，没有它将无法工作。请联系您的主机服务商启用它。';

// The listing-status panel. These describe a failure the shop owner cannot see from their own
// admin — products stop being shown when the heartbeat stops arriving, and from this side
// everything looks fine — so they say what happened and what to do about it.
$_['text_listing_status'] = '上架状态';
$_['text_never_reported'] = '此店铺尚未向 sudemi 报告。请添加下方的 cron 条目；在其运行之前，只有在此页面打开时才会发送报告。';
$_['text_unreachable'] = '此店铺无法连接到 sudemi。其商品会继续上架一小段时间，然后被暂停，因为 sudemi 不会报出无法确认的价格。';
$_['text_listed'] = '商品正在 sudemi 上展示。';
$_['entry_pending'] = '待发送的价格变更';
$_['text_pending'] = '尚未能发送的价格或库存变更。它们会在下次报告时重试，不会丢失。';
$_['entry_trusted_until'] = '价格确认有效至';
$_['text_trusted_until'] = '此时间之后，sudemi 将停止展示此店铺，直到它再次报告。不会删除任何内容。';
$_['entry_cron'] = 'Cron 条目';
$_['text_cron'] = '请将此内容添加到运行此店铺的账户的 crontab 中。它每五分钟告知 sudemi 此店铺仍在运行且价格依然有效，并会发送首次未能送达的价格变更。该链接包含密钥，请像对待密码一样妥善保管。';

$_['text_delivery_title'] = '向 sudemi 买家配送';
$_['text_delivery_preview'] = '发送给 sudemi 的运费（来自您的配送方式；按一件商品、以您的第一个商品计算）：';
$_['text_delivery_skipped'] = '没有固定价格的国家/地区（其他扩展、有最低消费的免运费、仅覆盖国家部分地区的地理区域）将被略去，而不是估算。';
$_['text_delivery_none'] = '无法从您的配送方式读取运费，因此 sudemi 买家无法下单。请为您配送的地理区域启用统一费率、按件计费、按重量计费或无条件免运费。';
$_['entry_handling_days'] = '您发出订单所需天数（最多）';
$_['entry_transit_days'] = '承运商运送所需天数（最多）';
$_['button_delivery'] = '保存配送时间';
$_['text_delivery_help'] = '买家看到的两者之和即为最晚预计送达时间。请设置您的真实时间；默认值特意设得较为保守。商品目录将在下次同步时重新发送。';
$_['text_delivery_saved'] = '配送时间已保存。';
