<?php

declare(strict_types=1);

/*
 * Starter site languages (English).
 *
 * Only the strings this starter uses. Add a language by copying this file to
 * resources/lang/<code>.php and adding the code to LOCALES in .env.
 */

return [
    'lang.name' => 'English',
    'lang.dir' => 'ltr',

    'common.sign_in' => 'Sign in',
    'common.sign_out' => 'Sign out',
    'common.dashboard' => 'Dashboard',
    'common.save' => 'Save',
    'common.cancel' => 'Cancel',
    'common.back' => 'Back',
    'common.new' => 'New',
    'common.edit' => 'Edit',
    'common.delete' => 'Delete',
    'common.confirm_delete' => 'Delete this record?',
    'common.empty' => 'Nothing here yet.',
    'common.next_page' => 'Next page',
    'common.email' => 'Email',
    'common.role' => 'Role',
    'common.yes' => 'Yes',
    'common.no' => 'No',
    'common.never' => 'Never',

    'field.id' => 'ID',
    'field.email' => 'Email',

    'home.title' => 'Home',
    'home.hero_title' => 'A Polaris site',
    'home.hero_body' => 'This is the starter page. Replace it with your own, and let the kernel look after sessions, security, email and deployment.',
    'home.next_title' => 'What to do next',
    'home.next_agents' => 'Read AGENTS.md: the conventions an AI agent must follow here.',
    'home.next_crud' => 'Scaffold your first entity with php bin/make.php crud.',
    'home.next_api' => 'Every operation you declare is a REST endpoint and an MCP tool at once.',

    'dashboard.title' => 'Dashboard',
    'dashboard.body' => 'You are signed in. This page is the starting point for your account area.',

    'auth.login_title' => 'Sign in',
    'auth.login_body' => 'We email you a single-use link. There is no password to remember or leak.',
    'auth.send_link' => 'Email me a link',
    'auth.link_sent_title' => 'Check your inbox',
    'auth.link_sent_body' => 'If :email has an account here, a login link is on its way.',
    'auth.link_sent_help' => 'The link expires in 15 minutes and works once.',
    'auth.sign_in' => 'Sign in',
    'auth.sign_out' => 'Sign out',
    'auth.invalid_link' => 'That link is no longer valid. Request a new one.',
    'auth.welcome' => 'Signed in as :name.',
    'auth.too_many' => 'Too many attempts. Wait a few minutes and try again.',

    'admin.title' => 'Admin',
    'admin.body' => 'Is this site healthy? Everything worth checking in one screen.',

    'setup.title' => 'Create the first admin',
    'setup.body' => 'This site has no accounts yet. Create the admin here; the route disappears as soon as one exists.',
    'setup.create' => 'Create admin',
    'setup.done' => 'Admin created. Sign in with this single-use link:',
    'setup.link_help' => 'It expires in 15 minutes. Configure SMTP in .env afterwards so future links are emailed.',

    // Scaffolded entity: Product
    'admin.products_title' => 'Products',
    'admin.products_new' => 'New product',
    'admin.products_edit' => 'Edit product',
    'flash.saved' => 'Saved.',
    'flash.deleted' => 'Deleted.',
    'field.name' => 'Name',
    'field.price' => 'Price',
    'field.stock' => 'Stock',
    'field.active' => 'Active',

    'validation.required' => ':field is required.',
    'validation.email' => ':field must be a valid email address.',
    'validation.email_exists' => 'An account with that email already exists.',
    'validation.handle_required' => 'Please choose a handle.',
    'validation.handle_format' => 'Handle must be 3-30 characters: lowercase letters, numbers and hyphens.',
    'validation.handle_reserved' => 'That handle is reserved.',
    'validation.handle_taken' => 'That handle is already taken.',
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

    'mail.magic_greeting' => 'Hi,',
    'mail.magic_body' => 'Click the button below to sign in to :app.',
    'mail.magic_button' => 'Sign in',
    'mail.magic_expires' => 'This link expires in 15 minutes and can only be used once.',
    'mail.magic_fallback' => 'Or copy and paste this link into your browser:',
    'mail.magic_ignore' => 'If you did not request this, you can safely ignore this email.',
    'mail.magic_subject' => 'Your :app login link',
    'mail.magic_heading' => 'Your login link',

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
