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
