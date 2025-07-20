Integración Profesional de Bsale y WooCommerce
Versión: 2.7.0
Autor: WHYDOTCO
Compatible con: WordPress 6.0+, WooCommerce 7.0+, PHP 7.4+

Descripción General
Este plugin proporciona una integración robusta y de alto rendimiento entre el sistema de gestión Bsale y una tienda online WooCommerce. Su filosofía principal es utilizar Bsale como la fuente única de verdad para el stock y los precios, mientras que WooCommerce actúa como el canal de ventas.

La integración está diseñada para ser liviana y escalable, enfocándose exclusivamente en la sincronización de datos volátiles (stock y precios) para productos que ya existen en ambas plataformas, y en la automatización del ciclo de facturación (Boletas, Facturas y Notas de Crédito).

Funcionalidades Principales
-Sincronización de Stock Unidireccional: Actualiza el inventario de los productos en WooCommerce basándose en el stock disponible en una sucursal específica de Bsale.

-Sincronización de Precios Unidireccional: Actualiza el precio regular de los productos en WooCommerce basándose en una lista de precios específica de Bsale.

-Facturación Automática: Genera Boletas y Facturas Electrónicas en Bsale automáticamente cuando un pedido de WooCommerce alcanza un estado configurable (ej. "Procesando").

-Gestión de Notas de Crédito: Genera automáticamente una Nota de Crédito en Bsale cuando un pedido es cancelado o reembolsado en WooCommerce.

-Campos de Facturación Mejorados: Añade campos para Razón Social, RUT y Giro en el checkout, que son visibles y requeridos solo cuando el cliente selecciona "Factura".

-Arquitectura Asíncrona: Todas las comunicaciones con la API de Bsale (creación de documentos, sincronización masiva) se ejecutan en segundo plano para no afectar la velocidad del sitio ni la experiencia del cliente.

-Seguro y Profesional: Las credenciales de la API se almacenan de forma segura en wp-config.php, no en la base de datos. El código es compatible con la última versión de WooCommerce y su Almacenamiento de Pedidos de Alto Rendimiento (HPOS).

⚠️ Lo que este Plugin NO HACE (Por Diseño)
Para garantizar la estabilidad y el control, este plugin no crea ni elimina productos en ninguna de las dos plataformas.

-No crea productos de Bsale a WooCommerce: Si un producto existe en Bsale pero no en WooCommerce, será ignorado durante la sincronización.

-No crea productos de WooCommerce a Bsale: Crear un producto en WooCommerce no tendrá ningún efecto en Bsale.

Flujo de Trabajo Requerido: El administrador de la tienda es responsable de crear los productos manualmente en ambas plataformas, asegurándose de que el SKU sea idéntico en ambos sistemas. El SKU es la clave que conecta los productos para la sincronización de stock y precio.

Configuración (Pasos Obligatorios)
Paso 1: Definir Credenciales en wp-config.php
Por motivos de seguridad, tus claves de API se configuran en tu archivo wp-config.php. Añade las siguientes líneas:

/**
 * Credenciales para la Integración Bsale y WooCommerce.
 */
define('BWI_ACCESS_TOKEN', 'tu_token_de_acceso_de_bsale_aqui');
define('BWI_WEBHOOK_SECRET', 'genera_una_cadena_larga_y_aleatoria_aqui');

BWI_ACCESS_TOKEN: Tu token de acceso de la API de Bsale.

BWI_WEBHOOK_SECRET: Una contraseña larga y aleatoria que tú inventes. Se usará para asegurar las notificaciones de Bsale.

Paso 2: Configurar el Plugin en WordPress
Ve a Ajustes > Bsale Integración.

Verifica las Credenciales: La página te confirmará si las constantes del paso anterior fueron definidas correctamente.

Sucursal para Stock: Selecciona la sucursal de Bsale cuyo stock se sincronizará.

Lista de Precios: Selecciona la lista de precios de Bsale que definirá el precio de tus productos en WooCommerce. Si no quieres sincronizar precios, deja la opción "-- No sincronizar precios --".

Facturación y Documentos:

Activa la creación de documentos.

Selecciona el estado del pedido (normalmente "Procesando") que activará la creación de la boleta/factura.

Guarda los cambios.

Paso 3: Configurar el Webhook en Bsale (Opcional, pero Recomendado)
Para que el stock se actualice en tiempo real, debes configurar un webhook en Bsale.

En Ajustes > Bsale Integración, copia la URL para Webhooks que se genera.

En tu panel de Bsale, ve a la configuración de Webhooks.

Crea un nuevo webhook, pega la URL y suscríbete a los eventos de stock.update.

Preguntas Frecuentes (FAQ)
P: Me aparece un aviso de incompatibilidad con HPOS.
R: El plugin es 100% compatible. Este es un problema de caché de WordPress. Simplemente desactiva el plugin y vuélvelo a activar. Esto fuerza a WooCommerce a re-leer la declaración de compatibilidad y el aviso desaparecerá.

P: El stock o el precio de un producto no se actualiza.
R: La causa más probable es una discrepancia de SKU. Verifica que el SKU del producto en WooCommerce sea exactamente igual al SKU de la variante en Bsale. También puedes forzar una actualización completa desde Ajustes > Bsale Integración > Sincronizar Productos y Stock Ahora.

P: Las facturas no se están creando.
R: Revisa las "Notas del pedido" en el detalle de un pedido en WooCommerce. Si hubo un error al comunicarse con la API de Bsale (ej. un SKU no existe en Bsale), el plugin dejará una nota detallada con el mensaje de error exacto.