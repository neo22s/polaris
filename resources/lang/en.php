<?php

declare(strict_types=1);

/*
 * Polaris English strings.
 *
 * Only keys the kernel itself uses live here. An application adds its own
 * groups (admin.*, checkout.*, ...) in resources/lang too; the loader merges
 * the application files over the kernel ones, so a site can also translate or
 * reword a kernel message without editing this file.
 */

return [

    // Shared buttons and labels
    'common.save' => 'Save',
    'common.back' => 'Back',
    'common.cancel' => 'Cancel',
    'common.delete' => 'Delete',
    'common.confirm' => 'Confirm',
    'common.continue' => 'Continue',
    'common.skip' => 'Skip for now',
    'common.sign_in' => 'Sign in',
    'common.sign_out' => 'Sign out',

    // Accessibility
    'a11y.close' => 'Close',
    'a11y.previous' => 'Previous',
    'a11y.next' => 'Next',

    // Field labels used by the shared validation messages
    'field.email' => 'Email',

    // Validation
    'validation.required' => ':field is required.',
    'validation.email' => ':field must be a valid email address.',
    'validation.email_exists' => 'An account with that email already exists. Try signing in instead.',
    'validation.url' => ':field must be a valid URL.',
    'validation.numeric' => ':field must be a number.',
    'validation.integer' => ':field must be a whole number.',
    'validation.min' => ':field must be at least :min characters.',
    'validation.max' => ':field may not be more than :max characters.',
    'validation.in' => ':field is not a valid option.',
    'validation.date' => ':field must be a valid date.',
    'validation.youtube' => ':field must be a valid YouTube link.',
    'validation.between_min' => ':field must be at least :min.',
    'validation.between_max' => ':field may not be more than :max.',
    'validation.handle' => 'Handle must be 3-30 characters: lowercase letters, numbers, and hyphens only.',
    'validation.handle_required' => 'Please choose a handle.',
    'validation.handle_format' => 'Handle must be 3-30 characters: lowercase letters, numbers, and hyphens only.',
    'validation.handle_reserved' => 'That handle is reserved. Please choose another one.',
    'validation.handle_taken' => 'That handle is already taken. Please choose another one.',
    'validation.setting_unknown' => 'Unknown setting: :key.',

    // Authentication
    'flash.login_required' => 'Please sign in to continue.',
    'flash.session_expired' => 'Your session expired. Please try again.',

    // Email
    'mail.magic_greeting' => 'Hi,',
    'mail.magic_body' => 'Click the button below to sign in to :app.',
    'mail.magic_button' => 'Sign in',
    'mail.magic_expires' => 'This link expires in 15 minutes and can only be used once.',
    'mail.magic_fallback' => 'Or copy and paste this link into your browser:',
    'mail.magic_ignore' => 'If you did not request this, you can safely ignore this email.',
    'mail.magic_subject' => 'Your :app login link',
    'mail.magic_heading' => 'Your login link',

    // Settings and translations
    'validation.locale_unsupported' => 'That language is not supported.',
    'validation.translation_key_unknown' => 'That translation key does not exist in the language file.',
    'validation.translation_override_not_found' => 'That override no longer exists.',
    'validation.translation_value_too_long' => 'The translation may not be more than :max characters.',

    // Error pages
    'errors.404_title' => 'This page does not exist',
    'errors.404_body' => 'The address may be wrong, or the page may have moved.',
    'errors.404_cta' => 'Back to the home page',
    'errors.403_title' => 'You cannot access this page',
    'errors.403_body' => 'Your account does not have permission to view it.',
    'errors.403_cta' => 'Back to the home page',
    'errors.500_title' => 'Something went wrong',
    'errors.500_body' => 'Please try again in a moment.',
    'errors.500_cta' => 'Back to the home page',
];
