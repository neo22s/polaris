<?php

declare(strict_types=1);

/*
 * Scaffold for resources/views/admin/<table>/form.twig.
 * Supplied by CrudGenerator: $table, $fields.
 */

return <<<TWIG
{% extends 'layout.twig' %}

{% block content %}
<div class="mx-auto max-w-2xl px-4 py-10">
    <h1 class="text-2xl font-semibold tracking-tight">{{ page_title }}</h1>

    <form method="post" action="{% if row.id %}/admin/{$table}/{{ row.id }}{% else %}/admin/{$table}{% endif %}"
          class="mt-8 space-y-5">
        {{ csrf_field() }}
{$fields}
        <div class="flex gap-3">
            <button type="submit" class="bg-ink text-white px-5 py-2">{{ t('common.save') }}</button>
            <a href="/admin/{$table}" class="px-5 py-2">{{ t('common.cancel') }}</a>
        </div>
    </form>
</div>
{% endblock %}
TWIG;
