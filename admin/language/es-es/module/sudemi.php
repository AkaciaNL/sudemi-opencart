<?php
// Heading
$_['heading_title'] = 'Conector de sudemi';

// Text
$_['text_extension'] = 'Extensiones';
$_['text_success'] = 'Se han guardado los ajustes.';
$_['text_edit'] = 'Conectar esta tienda a sudemi';
$_['text_intro'] = 'Al conectar esta tienda, un asistente de IA puede encontrar sus productos y enviarle compradores. La conexión se confirma en sudemi, con la sesión iniciada en su propia cuenta — esta extensión nunca le pide su contraseña de sudemi.';
$_['text_connected'] = 'Conectado a sudemi.';
$_['text_not_confirmed'] = 'Aún no está confirmado. Abra el enlace, confirme la tienda en sudemi y vuelva a comprobarlo.';
$_['text_disconnected'] = 'Desconectado.';
$_['text_synced'] = 'Se ha enviado el catálogo.';
$_['text_sync_failed'] = 'No se ha podido enviar el catálogo. No se ha retirado nada.';
$_['text_locked'] = 'Su razón social, país, condición de empresario o profesional y número de IVA determinan lo que se le debe al comprador, por lo que se confirman una sola vez en sudemi y se modifican allí. Esta extensión no puede editarlos.';
$_['text_shop_url_fixed'] = 'Fija. sudemi comprobó que esta tienda respondía desde esta dirección, y cada anuncio indica al comprador de dónde procede — por eso, cambiarla supone volver a conectar desde la nueva dirección.';
$_['text_key_held'] = 'La parte privada de la clave de firma nunca sale de este servidor y nunca se envía a sudemi. Tampoco puede mostrarse aquí.';

// Entry
$_['entry_shop_url'] = 'Dirección de la tienda';
$_['entry_install'] = 'Instalación';
$_['entry_key'] = 'Clave de firma';

// Button
$_['button_connect'] = 'Conectar esta tienda';
$_['button_confirm'] = 'Confirmar esta tienda en sudemi';
$_['button_check'] = 'Volver a comprobar';
$_['button_sync'] = 'Enviar el catálogo ahora';
$_['button_disconnect'] = 'Desconectar';

// Error
$_['error_permission'] = 'No tiene permiso para cambiar esto.';
$_['error_token'] = 'No se ha podido verificar la solicitud. Inténtelo de nuevo desde esta página.';
$_['error_sodium'] = 'A este servidor le falta la extensión sodium. La conexión la necesita para firmar las solicitudes y no funcionará sin ella. Pida a su proveedor de alojamiento que la active.';

// The listing-status panel. These describe a failure the shop owner cannot see from their own
// admin — products stop being shown when the heartbeat stops arriving, and from this side
// everything looks fine — so they say what happened and what to do about it.
$_['text_listing_status'] = 'Estado de publicación';
$_['text_never_reported'] = 'Esta tienda todavía no se ha comunicado con sudemi. Añada la tarea cron de abajo; mientras no se ejecute, las comunicaciones solo se envían cuando esta página está abierta.';
$_['text_unreachable'] = 'Esta tienda no ha podido contactar con sudemi. Sus productos siguen publicados durante un breve tiempo y después se pausan, porque sudemi no ofrece un precio que no puede confirmar.';
$_['text_listed'] = 'Los productos se están mostrando en sudemi.';
$_['entry_pending'] = 'Cambios de precio pendientes';
$_['text_pending'] = 'Cambios de precio o de stock que aún no se han podido enviar. Se reintentan en la próxima comunicación y no se pierden.';
$_['entry_trusted_until'] = 'Precios confirmados hasta';
$_['text_trusted_until'] = 'A partir de ese momento, sudemi deja de mostrar esta tienda hasta que vuelva a comunicarse. No se elimina nada.';
$_['entry_cron'] = 'Tarea cron';
$_['text_cron'] = 'Añada esto al crontab de la cuenta que ejecuta esta tienda. Avisa a sudemi cada cinco minutos de que esta tienda sigue activa y de que sus precios siguen vigentes, y envía cualquier cambio de precio que no llegara la primera vez. El enlace contiene un secreto; trátelo como una contraseña.';

$_['text_delivery_title'] = 'Envío a compradores de sudemi';
$_['text_delivery_preview'] = 'Precios de envío enviados a sudemi (según sus métodos de envío; un artículo, su primer producto):';
$_['text_delivery_skipped'] = 'Los países sin precio fijo (otras extensiones, envío gratuito con importe mínimo, zonas geográficas que solo cubren parte de un país) se omiten en lugar de estimarse.';
$_['text_delivery_none'] = 'No se ha podido leer ningún precio de envío de sus métodos de envío, por lo que los compradores de sudemi no pueden hacer pedidos. Active Tarifa plana, Por artículo, Según peso o Envío gratuito sin condiciones para las zonas geográficas a las que envía.';
$_['entry_handling_days'] = 'Días hasta que despacha un pedido (como máximo)';
$_['entry_transit_days'] = 'Días que tarda el transportista (como máximo)';
$_['button_delivery'] = 'Guardar plazos de entrega';
$_['text_delivery_help'] = 'Los compradores ven la suma como la fecha de entrega estimada más tardía. Indique sus plazos reales; los valores predeterminados son deliberadamente prudentes. El catálogo se vuelve a enviar en la próxima sincronización.';
$_['text_delivery_saved'] = 'Plazos de entrega guardados.';

// Order history on this page
$_['text_history_title'] = 'Pedidos a través de sudemi';
$_['text_history_error'] = 'No se ha podido contactar con sudemi para cargar el historial de pedidos en este momento. Los pedidos en sí no tienen ningún problema; inténtelo de nuevo en breve.';
$_['text_history_empty'] = 'Todavía no hay pedidos a través de sudemi.';
$_['column_order'] = 'Pedido';
$_['column_items'] = 'Artículos vendidos';
$_['column_status'] = 'Estado';
$_['column_net'] = 'Usted recibe';
$_['column_customer'] = 'Cliente';
$_['text_status_awaiting_seller'] = 'Pendiente de su respuesta — no se ha cobrado al comprador';
$_['text_status_accepted'] = 'Aceptado — a la espera de las demás tiendas del pago del comprador; aún no se ha cobrado al comprador, no lo envíe';
$_['text_status_paid'] = 'Confirmado y cobrado';
$_['text_status_declined'] = 'Rechazado — no se ha cobrado nada';
$_['text_status_shipped'] = 'Enviado';
$_['text_status_delivered'] = 'Entregado';
$_['text_status_cancelled'] = 'Cancelado';
$_['text_status_refunded'] = 'Reembolsado';
$_['text_history_awaiting'] = 'Pedidos pendientes de confirmar: %d. A esos compradores todavía no se les ha cobrado.';
$_['text_options_skipped'] = '%d producto(s) con una opción obligatoria (por ejemplo, una talla que elegir) no se ofrecen en sudemi: un pedido del asistente de IA de un comprador no incluye ninguna opción, así que no sabrías qué artículo enviar. Ofrécelos como variantes de OpenCart (cada variante es un producto propio).';

// Product form: right of withdrawal (Directive 2011/83/EU, art. 16)
$_['entry_withdrawal'] = 'Derecho de desistimiento';
$_['text_withdrawal_none'] = 'Ninguna — se aplica el derecho de desistimiento normal';
$_['help_withdrawal'] = 'Solo para productos que legalmente están sujetos a una excepción. Los compradores lo ven antes de pagar; no se permite el uso indebido.';
$_['text_withdrawal_hygiene'] = 'Precintado por razones de salud o higiene (sin devolución una vez desprecintado)';
$_['text_withdrawal_custom'] = 'Fabricado según las especificaciones del comprador o personalizado';
$_['text_withdrawal_perishable'] = 'Perecedero o de caducidad rápida';
$_['text_withdrawal_media'] = 'Grabación de audio, vídeo o software precintado (sin devolución una vez desprecintado)';
$_['text_withdrawal_press'] = 'Prensa diaria, publicación periódica o revista';
$_['text_withdrawal_mixed'] = 'Mezclado de forma indisociable con otros bienes tras la entrega';
$_['text_withdrawal_alcohol'] = 'Bebidas alcohólicas con precio dependiente del mercado, entregadas después de más de 30 días';

// Support: tickets between this shop and sudemi support (sudemi sends the e-mails, not this extension)
$_['text_support_title'] = 'Soporte';
$_['text_support_not_connected'] = 'Primero conecte esta tienda a sudemi; después podrá contactar con el soporte de sudemi desde aquí.';
$_['text_support_intro'] = 'Las preguntas sobre pedidos, pagos, liquidaciones o esta extensión se dirigen al soporte de sudemi. Las respuestas aparecen aquí y también se envían por correo electrónico a la dirección de su cuenta de sudemi.';
$_['text_support_tickets'] = 'Sus tickets';
$_['text_support_empty'] = 'Todavía no ha abierto ningún ticket de soporte.';
$_['text_support_new'] = 'Abrir un ticket';
$_['text_support_email'] = 'sudemi le avisa por correo electrónico cuando el soporte responde. Esta extensión no envía correos por sí misma.';
$_['text_support_opened'] = 'Se ha abierto el ticket %s. El soporte de sudemi se pondrá en contacto con usted.';
$_['text_support_replied'] = 'Su respuesta se ha enviado.';
$_['text_support_reply_reopens'] = 'Al responder se reabre este ticket cerrado.';
$_['text_support_from_support'] = 'Soporte de sudemi';
$_['text_support_from_shop'] = 'Usted (esta tienda)';
$_['text_support_status_open'] = 'Abierto';
$_['text_support_status_waiting_for_customer'] = 'Esperando su respuesta';
$_['text_support_status_answered'] = 'Respondido';
$_['text_support_status_closed'] = 'Cerrado';
$_['text_support_category_order'] = 'Un pedido';
$_['text_support_category_payment'] = 'Un pago';
$_['text_support_category_refund_return'] = 'Un reembolso o devolución';
$_['text_support_category_account'] = 'Su cuenta de sudemi';
$_['text_support_category_shop_plugin'] = 'Esta tienda o la extensión';
$_['text_support_category_payouts'] = 'Liquidaciones';
$_['text_support_category_other'] = 'Otro asunto';
$_['column_support_number'] = 'Ticket';
$_['column_support_subject'] = 'Asunto';
$_['column_support_status'] = 'Estado';
$_['column_support_updated'] = 'Última actualización';
$_['entry_support_category'] = 'Tema';
$_['entry_support_subject'] = 'Asunto';
$_['entry_support_message'] = 'Mensaje';
$_['entry_support_order'] = 'ID de pedido de sudemi (opcional)';
$_['help_support_order'] = 'Por ejemplo 3f2a9c1e-…; déjelo vacío si no se trata de un pedido concreto';
$_['entry_support_reply'] = 'Su respuesta';
$_['button_support_open'] = 'Abrir ticket';
$_['button_support_reply'] = 'Enviar respuesta';
$_['button_support_back'] = 'Volver a todos los tickets';
$_['error_support_invalid'] = 'Revise el formulario: el asunto debe tener entre 3 y 200 caracteres, el mensaje como máximo 10 000, y el ID de pedido debe corresponder a un pedido de sudemi de esta tienda.';
$_['error_support_not_found'] = 'No se ha encontrado ese ticket para esta tienda.';
$_['error_support_too_many'] = 'Demasiados tickets a la vez: como máximo 20 tickets abiertos y 10 nuevos por hora. Responda a un ticket existente o inténtelo más tarde.';
$_['error_support_unavailable'] = 'No se ha podido contactar con el soporte de sudemi en este momento. No se ha enviado nada; inténtelo de nuevo en breve.';
