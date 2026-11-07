<?php
// Heading
$_['heading_title'] = 'sudemi 连接器';

// Text
$_['text_extension'] = '扩展';
$_['text_success'] = '设置已保存。';
$_['text_edit'] = '将此店铺连接到 sudemi';
$_['text_intro'] = '连接此店铺后，AI 助手即可找到您的商品并为您带来买家。您需要登录自己的账户，在 sudemi 上确认连接，本扩展绝不会索要您的 sudemi 密码。';
$_['text_connected'] = '已连接到 sudemi。';
$_['text_not_confirmed'] = '尚未确认。请打开链接，在 sudemi 上确认店铺，然后再次检查。';
$_['text_disconnected'] = '已断开连接。';
$_['text_synced'] = '商品目录已发送。';
$_['text_sync_failed'] = '无法发送商品目录。没有任何商品被下架。';
$_['text_sync_held'] = '商品目录同步已暂停：它会从 sudemi 移除许多在售商品，因此未做任何更改。如果这些商品确实已从本店删除，请联系 sudemi 支持（support@sudemi.com）。';
$_['text_locked'] = '您的法定名称、国家/地区、经营者身份和增值税号决定了买家享有哪些权益，因此它们只在 sudemi 上确认一次，并在那里修改。本扩展无法编辑这些信息。';
$_['text_shop_url_fixed'] = '已固定。sudemi 已核实此店铺从该地址作出响应，且商品信息会告诉买家其来源，因此更改地址意味着需要从新地址重新连接。';
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
// admin - products stop being shown when the heartbeat stops arriving, and from this side
// everything looks fine - so they say what happened and what to do about it.
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

// Order history on this page
$_['text_history_title'] = '通过 sudemi 的订单';
$_['text_history_error'] = '目前无法连接 sudemi 加载订单历史。订单本身没有问题，请稍后重试。';
$_['text_history_empty'] = '尚无通过 sudemi 的订单。';
$_['column_order'] = '订单';
$_['column_items'] = '售出商品';
$_['column_status'] = '状态';
$_['column_net'] = '您的收入';
$_['column_customer'] = '客户';
$_['text_status_awaiting_seller'] = '等待您处理，买家未扣款';
$_['text_status_accepted'] = '已接受，正在等待买家本次付款中的其他店铺；买家尚未扣款，请勿发货';
$_['text_status_paid'] = '已确认并扣款';
$_['text_status_declined'] = '已拒绝，未扣款';
$_['text_status_shipped'] = '已发货';
$_['text_status_delivered'] = '已送达';
$_['text_status_cancelled'] = '已取消';
$_['text_status_refunded'] = '已退款';
$_['text_history_awaiting'] = '待确认订单：%d。这些买家尚未被扣款。';
$_['text_options_skipped'] = '有 %d 个带必选选项（例如需要选择尺码）的商品不会在 sudemi 上展示：来自买家 AI 助手的订单不包含任何选项，您将无法知道该发哪一件。请改用 OpenCart 变体（每个变体都是独立商品）来提供它们。';

// Product form: right of withdrawal (Directive 2011/83/EU, art. 16)
$_['entry_withdrawal'] = '撤回权';
$_['text_withdrawal_none'] = '无 - 适用正常的撤回权';
$_['help_withdrawal'] = '仅适用于依法属于例外情形的商品。买家在付款前即可看到；不得滥用。';
$_['text_withdrawal_hygiene'] = '因健康或卫生原因密封（拆封后不可退货）';
$_['text_withdrawal_custom'] = '按买家要求定制或个性化';
$_['text_withdrawal_perishable'] = '易腐或保质期短';
$_['text_withdrawal_media'] = '密封的音像制品或软件（拆封后不可退货）';
$_['text_withdrawal_press'] = '报纸、期刊或杂志';
$_['text_withdrawal_mixed'] = '交付后与其他物品不可分离地混合';
$_['text_withdrawal_alcohol'] = '价格随市场波动、30 天后交付的酒精饮料';

// Support: tickets between this shop and sudemi support (sudemi sends the e-mails, not this extension)
$_['text_support_title'] = '客服支持';
$_['text_support_not_connected'] = '请先将本店铺连接到 sudemi，之后即可在此联系 sudemi 客服。';
$_['text_support_intro'] = '有关订单、付款、结算或本扩展的问题，请提交给 sudemi 客服。回复会显示在这里，同时也会发送到您 sudemi 账户的邮箱。';
$_['text_support_tickets'] = '您的工单';
$_['text_support_empty'] = '您还没有提交过任何客服工单。';
$_['text_support_new'] = '提交工单';
$_['text_support_email'] = '客服回复后，sudemi 会通过电子邮件通知您。本扩展本身不发送任何邮件。';
$_['text_support_opened'] = '工单 %s 已提交，sudemi 客服会尽快回复您。';
$_['text_support_replied'] = '您的回复已发送。';
$_['text_support_reply_reopens'] = '回复后，这个已关闭的工单将重新打开。';
$_['text_support_from_support'] = 'sudemi 客服';
$_['text_support_from_shop'] = '您（本店铺）';
$_['text_support_status_open'] = '处理中';
$_['text_support_status_waiting_for_customer'] = '等待您的回复';
$_['text_support_status_answered'] = '已回复';
$_['text_support_status_closed'] = '已关闭';
$_['text_support_category_order'] = '订单';
$_['text_support_category_payment'] = '付款';
$_['text_support_category_refund_return'] = '退款或退货';
$_['text_support_category_account'] = '您的 sudemi 账户';
$_['text_support_category_shop_plugin'] = '本店铺或扩展';
$_['text_support_category_payouts'] = '结算';
$_['text_support_category_other'] = '其他问题';
$_['column_support_number'] = '工单';
$_['column_support_subject'] = '主题';
$_['column_support_status'] = '状态';
$_['column_support_updated'] = '最后更新';
$_['entry_support_category'] = '类别';
$_['entry_support_subject'] = '主题';
$_['entry_support_message'] = '内容';
$_['entry_support_order'] = 'sudemi 订单号（可选）';
$_['help_support_order'] = '例如 3f2a9c1e-…；如不涉及具体订单请留空';
$_['entry_support_reply'] = '您的回复';
$_['button_support_open'] = '提交工单';
$_['button_support_reply'] = '发送回复';
$_['button_support_back'] = '返回工单列表';
$_['error_support_invalid'] = '请检查表单：主题需 3 到 200 个字符，内容最多 10,000 个字符，订单号必须是本店铺的 sudemi 订单。';
$_['error_support_not_found'] = '未找到本店铺的该工单。';
$_['error_support_too_many'] = '工单过多：最多同时有 20 个未关闭的工单，每小时最多新建 10 个。请在现有工单中回复，或稍后再试。';
$_['error_support_unavailable'] = '暂时无法连接 sudemi 客服，未发送任何内容，请稍后再试。';

// sudemi refused this site as not being the connected shop (409 site_mismatch): a staging copy,
// a restored backup, or the shop moved. %s is the address the shop is connected under.
$_['text_site_mismatch_at'] = 'sudemi 拒绝了来自此网站的请求：此店铺以另一个地址（%s）连接到 sudemi。如果此网站是测试副本（staging）或恢复的备份，请在此断开 sudemi 连接。如果店铺已迁移到此地址，请重新连接。';
$_['text_site_mismatch'] = 'sudemi 拒绝了来自此网站的请求：此店铺以另一个地址连接到 sudemi。如果此网站是测试副本（staging）或恢复的备份，请在此断开 sudemi 连接。如果店铺已迁移到此地址，请重新连接。';

// Return requests from buyers (answered within 7 days: accept, or reject with a reason the buyer
// sees). The notes go into the order history; %s is the reason given.
$_['text_requests_title'] = '退货申请';
$_['text_requests_intro'] = '买家希望退回这些已送达的订单。请在显示的日期前答复每个申请：接受，或填写理由后拒绝（买家会看到理由）。7 天内未答复的申请将转交 sudemi 处理。每次向 sudemi 报告时，此列表都会更新。';
$_['text_requests_empty'] = '没有待处理的退货申请。';
$_['column_request_order'] = '订单';
$_['column_request_reason'] = '买家理由';
$_['column_request_requested'] = '申请时间';
$_['column_request_respond_by'] = '答复截止';
$_['column_request_answer'] = '您的答复';
$_['text_request_no_local'] = '在本店中未找到';
$_['text_request_no_reason'] = '未填写理由';
$_['text_request_overdue'] = '已逾期';
$_['entry_request_reject_reason'] = '拒绝理由（必填，买家可见）';
$_['button_request_accept'] = '接受退货';
$_['button_request_reject'] = '拒绝退货';
$_['text_request_accepted'] = '已接受退货。买家现在会寄回商品。商品寄到后，请将订单状态设为“已退款”来为订单退款：退款经 sudemi 退还给买家，并关闭此申请。';
$_['text_request_rejected'] = '已拒绝退货。买家会看到您的理由。';
$_['text_request_already'] = '此申请已答复过。列表已更新。';
$_['error_request_not_found'] = '此订单已没有待处理的退货申请。列表已更新。';
$_['error_request_reason'] = '拒绝退货需要填写理由；买家会看到该理由。';
$_['error_request_unavailable'] = '暂时无法连接 sudemi。未发送任何答复；请稍后重试。';
$_['text_request_note_accepted'] = 'sudemi：已接受退货申请。商品退回后请为订单退款（状态“已退款”）。';
$_['text_request_note_rejected'] = 'sudemi：已拒绝退货申请。向买家显示的理由：%s';
$_['text_history_return_requested'] = '已申请退货';
