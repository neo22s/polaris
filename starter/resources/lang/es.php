<?php

declare(strict_types=1);

/*
 * Starter site languages (Spanish).
 *
 * The starter ships two languages on purpose: it is the cheapest proof that
 * the i18n layer works, and the first thing a site removes if it does not need
 * it. See docs/ADDING-A-LANGUAGE.md.
 */

return [
    'lang.name' => 'Español',
    'lang.dir' => 'ltr',

    'common.sign_in' => 'Entrar',
    'common.sign_out' => 'Salir',
    'common.dashboard' => 'Panel',
    'common.save' => 'Guardar',
    'common.cancel' => 'Cancelar',
    'common.back' => 'Volver',
    'common.new' => 'Nuevo',
    'common.edit' => 'Editar',
    'common.delete' => 'Eliminar',
    'common.confirm_delete' => '¿Eliminar este registro?',
    'common.empty' => 'Todavía no hay nada.',
    'common.next_page' => 'Página siguiente',
    'common.email' => 'Correo',
    'common.role' => 'Rol',
    'common.yes' => 'Sí',
    'common.no' => 'No',
    'common.never' => 'Nunca',

    'field.id' => 'ID',
    'field.email' => 'Correo electrónico',

    'home.title' => 'Inicio',
    'home.hero_title' => 'Un sitio Polaris',
    'home.hero_body' => 'Esta es la página de inicio de la plantilla. Sustitúyela por la tuya y deja que el kernel se ocupe de las sesiones, la seguridad, el correo y el despliegue.',
    'home.next_title' => 'Qué hacer ahora',
    'home.next_agents' => 'Lee AGENTS.md: las convenciones que debe seguir un agente de IA aquí.',
    'home.next_crud' => 'Genera tu primera entidad con php bin/make.php crud.',
    'home.next_api' => 'Cada operación que declares es a la vez un endpoint REST y una herramienta MCP.',

    'dashboard.title' => 'Panel',
    'dashboard.body' => 'Has entrado. Esta página es el punto de partida de tu área de cuenta.',

    'auth.login_title' => 'Entrar',
    'auth.login_body' => 'Te enviamos un enlace de un solo uso. No hay contraseña que recordar ni filtrar.',
    'auth.send_link' => 'Enviarme un enlace',
    'auth.link_sent_title' => 'Revisa tu correo',
    'auth.link_sent_body' => 'Si :email tiene cuenta aquí, el enlace de acceso va de camino.',
    'auth.link_sent_help' => 'El enlace caduca en 15 minutos y funciona una sola vez.',
    'auth.sign_in' => 'Entrar',
    'auth.sign_out' => 'Salir',
    'auth.invalid_link' => 'Ese enlace ya no es válido. Pide uno nuevo.',
    'auth.welcome' => 'Has entrado como :name.',
    'auth.too_many' => 'Demasiados intentos. Espera unos minutos y vuelve a probar.',

    'admin.title' => 'Administración',
    'admin.body' => '¿Está sano este sitio? Todo lo que merece la pena revisar, en una pantalla.',

    'setup.title' => 'Crea el primer administrador',
    'setup.body' => 'Este sitio no tiene cuentas todavía. Crea aquí el administrador; la ruta desaparece en cuanto exista una.',
    'setup.create' => 'Crear administrador',
    'setup.done' => 'Administrador creado. Entra con este enlace de un solo uso:',
    'setup.link_help' => 'Caduca en 15 minutos. Configura el SMTP en .env después para que los próximos enlaces se envíen por correo.',

    // Scaffolded entity: Product
    'admin.products_title' => 'Productos',
    'admin.products_new' => 'Nuevo producto',
    'admin.products_edit' => 'Editar producto',
    'flash.saved' => 'Guardado.',
    'flash.deleted' => 'Eliminado.',
    'field.name' => 'Nombre',
    'field.price' => 'Precio',
    'field.stock' => 'Stock',
    'field.active' => 'Activo',

    'validation.required' => ':field es obligatorio.',
    'validation.email' => ':field debe ser una dirección de correo válida.',
    'validation.email_exists' => 'Ya existe una cuenta con ese correo.',
    'validation.handle_required' => 'Elige un identificador.',
    'validation.handle_format' => 'El identificador debe tener 3-30 caracteres: minúsculas, números y guiones.',
    'validation.handle_reserved' => 'Ese identificador está reservado.',
    'validation.handle_taken' => 'Ese identificador ya está en uso.',
    'validation.url' => ':field debe ser una URL válida.',
    'validation.numeric' => ':field debe ser un número.',
    'validation.integer' => ':field debe ser un número entero.',
    'validation.min' => ':field debe tener al menos :min caracteres.',
    'validation.max' => ':field no puede tener más de :max caracteres.',
    'validation.in' => ':field no es una opción válida.',
    'validation.date' => ':field debe ser una fecha válida.',
    'validation.youtube' => ':field debe ser un enlace de YouTube válido.',
    'validation.between_min' => ':field debe ser al menos :min.',
    'validation.between_max' => ':field no puede ser más de :max.',

    'mail.magic_greeting' => 'Hola,',
    'mail.magic_body' => 'Pulsa el botón para entrar en :app.',
    'mail.magic_button' => 'Entrar',
    'mail.magic_expires' => 'Este enlace caduca en 15 minutos y solo se puede usar una vez.',
    'mail.magic_fallback' => 'O copia y pega este enlace en el navegador:',
    'mail.magic_ignore' => 'Si no lo has pedido, puedes ignorar este correo.',
    'mail.magic_subject' => 'Tu enlace de acceso a :app',
    'mail.magic_heading' => 'Tu enlace de acceso',

    'errors.404_title' => 'Esta página no existe',
    'errors.404_body' => 'Puede que la dirección esté mal o que la página se haya movido.',
    'errors.404_cta' => 'Volver al inicio',
    'errors.403_title' => 'No puedes acceder a esta página',
    'errors.403_body' => 'Tu cuenta no tiene permiso para verla.',
    'errors.403_cta' => 'Volver al inicio',
    'errors.500_title' => 'Algo ha ido mal',
    'errors.500_body' => 'Inténtalo de nuevo en un momento.',
    'errors.500_cta' => 'Volver al inicio',
];
