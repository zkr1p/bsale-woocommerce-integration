///////////////////////////////////////////////////////////////////////////////////////////////////////////////////////////////////////////////////

Integración de Bsale y WooCommerce
Versión: 2.1.0
Autor: WHYDOTCO
Compatible con: WordPress 5.3+, WooCommerce 3.5+, PHP 7.4+

///////////////////////////////////////////////////////////////////////////////////////////////////////////////////////////////////////////////////


Descripción
Este plugin proporciona una integración robusta y completa entre el sistema de gestión Bsale y WooCommerce. Sincroniza productos, stock y precios, y automatiza la creación de Documentos Tributarios Electrónicos (Boletas y Facturas) directamente desde los pedidos de WooCommerce.

La integración está diseñada siguiendo las mejores prácticas de seguridad y rendimiento, utilizando tareas asíncronas en segundo plano para no afectar la velocidad de tu tienda.

///////////////////////////////////////////////////////////////////////////////////////////////////////////////////////////////////////////////////

Funcionalidades Principales
Sincronización de Catálogo: Importa y actualiza productos y variantes desde Bsale a WooCommerce.

Sincronización de Stock en Tiempo Real: Mantiene el stock de WooCommerce alineado con el de Bsale, utilizando sincronización periódica y Webhooks para actualizaciones instantáneas.

Facturación Automática: Genera Boletas y Facturas Electrónicas en Bsale automáticamente cuando un pedido de WooCommerce alcanza un estado configurable (ej. "Procesando").

Campos de Facturación en el Checkout: Añade opciones en la página de pago para que los clientes elijan entre Boleta o Factura e introduzcan su RUT si es necesario.

Seguro y Escalable: Las credenciales se almacenan de forma segura en wp-config.php y las operaciones pesadas se ejecutan en segundo plano para soportar catálogos de cualquier tamaño.

///////////////////////////////////////////////////////////////////////////////////////////////////////////////////////////////////////////////////

Instalación
Descarga el plugin en formato .zip.

En tu panel de WordPress, ve a Plugins > Añadir nuevo > Subir plugin.

Selecciona el archivo .zip y haz clic en "Instalar ahora".

Una vez instalado, haz clic en "Activar".

Configuración (Paso Crítico)
Para que el plugin funcione, debes configurar tus credenciales de forma segura y luego ajustar las opciones en el panel de WordPress.

Paso 1: Definir Credenciales en wp-config.php
Por motivos de seguridad, tus claves de API no se guardan en la base de datos. Debes añadirlas a tu archivo wp-config.php, que se encuentra en la raíz de tu instalación de WordPress.

Añade las siguientes líneas justo antes de la línea /* That's all, stop editing! Happy publishing. */:

/**
 * Credenciales para la Integración Bsale y WooCommerce.
 */
define('BWI_ACCESS_TOKEN', 'tu_token_de_acceso_de_bsale_aqui');
define('BWI_WEBHOOK_SECRET', 'genera_una_cadena_larga_y_aleatoria_aqui');

BWI_ACCESS_TOKEN: Reemplázalo con el token de acceso que obtuviste de tu cuenta de Bsale.

BWI_WEBHOOK_SECRET: Genera una contraseña larga y aleatoria (puedes usar un generador de contraseñas online) y pégala aquí. Será utilizada para asegurar tus webhooks.

Paso 2: Configurar el Plugin en WordPress
Una vez activado el plugin y definidas las constantes, ve a Ajustes > Bsale Integración en tu panel de WordPress.

Verifica las Credenciales: La página te mostrará si las constantes BWI_ACCESS_TOKEN y BWI_WEBHOOK_SECRET fueron definidas correctamente.

Sucursal para Stock: Selecciona la sucursal de Bsale cuyo stock deseas sincronizar con tu tienda WooCommerce. La lista se carga directamente desde la API de Bsale.

Facturación y Documentos:

Activa la creación de documentos.

Selecciona el estado del pedido (ej. "Procesando") que activará la creación de la boleta/factura.

Verifica que los Códigos SII para Boletas (39) y Facturas (33) sean los correctos.

Guarda los cambios.

Paso 3: Configurar el Webhook en Bsale
Para que el stock se actualice en tiempo real (ej. cuando vendes algo en tu tienda física), debes configurar un webhook en Bsale.

En la página de ajustes del plugin en WordPress, copia la URL para Webhooks que se genera automáticamente.

En tu panel de Bsale, ve a la sección de configuración de Webhooks.

Crea un nuevo webhook y pega la URL.

Suscríbete a los eventos de stock.update y stock.created.

Preguntas Frecuentes (FAQ)
P: Me aparece un aviso de incompatibilidad con "Almacenamiento de pedidos de alto rendimiento (HPOS)". ¿Cómo lo soluciono?
R: Este es un problema común de caché de WordPress. Simplemente desactiva el plugin y vuélvelo a activar. Esto fuerza a WooCommerce a re-leer la declaración de compatibilidad del plugin y el aviso desaparecerá.

P: Los productos no se sincronizan. ¿Qué hago?
R:

Asegúrate de que cada producto y variante en Bsale tenga un SKU único. El SKU es la clave que conecta los productos entre ambos sistemas.

Ve a WooCommerce > Estado > Logs y selecciona el log bwi-sync del menú desplegable para ver si hay mensajes de error.

P: Las facturas no se están creando. ¿Por qué?
R:

Verifica que el estado del pedido que configuraste como "disparador" en los ajustes del plugin coincide con el estado que alcanza tu pedido después de un pago exitoso (normalmente "Procesando").

Revisa las "Notas del pedido" en el detalle de un pedido en WooCommerce. El plugin dejará una nota si hubo un error al intentar crear el documento en Bsale.