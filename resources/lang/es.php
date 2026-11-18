<?php

declare(strict_types=1);

/*
 * Polaris Spanish strings.
 *
 * Shipped as proof that the translation layer works and as a starting point
 * for a Spanish site. An application overrides any key it wants in its own
 * resources/lang/es.php; that file wins over this one.
 */

return [

    'common.save' => 'Guardar',
    'common.back' => 'Volver',
    'common.cancel' => 'Cancelar',
    'common.delete' => 'Eliminar',
    'common.confirm' => 'Confirmar',
    'common.continue' => 'Continuar',
    'common.skip' => 'Saltar por ahora',
    'common.sign_in' => 'Entrar',
    'common.sign_out' => 'Salir',

    'a11y.close' => 'Cerrar',
    'a11y.previous' => 'Anterior',
    'a11y.next' => 'Siguiente',

    'field.email' => 'Correo electrónico',

    'validation.required' => ':field es obligatorio.',
    'validation.email' => ':field debe ser una dirección de correo válida.',
    'validation.email_exists' => 'Ya existe una cuenta con ese correo. Prueba a entrar.',
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
    'validation.handle' => 'El identificador debe tener 3-30 caracteres: solo letras minúsculas, números y guiones.',
    'validation.handle_required' => 'Elige un identificador.',
    'validation.handle_format' => 'El identificador debe tener 3-30 caracteres: solo letras minúsculas, números y guiones.',
    'validation.handle_reserved' => 'Ese identificador está reservado. Elige otro.',
    'validation.handle_taken' => 'Ese identificador ya está en uso. Elige otro.',
    'validation.setting_unknown' => 'Ajuste desconocido: :key.',

    'flash.login_required' => 'Entra para continuar.',
    'flash.session_expired' => 'Tu sesión ha caducado. Inténtalo de nuevo.',

    'mail.magic_greeting' => 'Hola,',
    'mail.magic_body' => 'Pulsa el botón para entrar en :app.',
    'mail.magic_button' => 'Entrar',
    'mail.magic_expires' => 'Este enlace caduca en 15 minutos y solo se puede usar una vez.',
    'mail.magic_fallback' => 'O copia y pega este enlace en el navegador:',
    'mail.magic_ignore' => 'Si no lo has pedido, puedes ignorar este correo.',
    'mail.magic_subject' => 'Tu enlace de acceso a :app',
    'mail.magic_heading' => 'Tu enlace de acceso',

    // Ajustes y traducciones
    'validation.locale_unsupported' => 'Ese idioma no está disponible.',
    'validation.translation_key_unknown' => 'Esa clave de traducción no existe en el archivo de idioma.',
    'validation.translation_override_not_found' => 'Esa traducción personalizada ya no existe.',
    'validation.translation_value_too_long' => 'La traducción no puede tener más de :max caracteres.',

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
