<?php

declare(strict_types=1);

/*
 * Scaffold for resources/views/admin/<table>/index.twig.
 * Supplied by CrudGenerator: $table, $headers, $cells.
 */

return <<<TWIG
{% extends 'layout.twig' %}

{% block content %}
<div class="mx-auto max-w-5xl px-4 py-10">
    <div class="flex items-center justify-between">
        <h1 class="text-2xl font-semibold tracking-tight">{{ page_title }}</h1>
        <a href="/admin/{$table}/new" class="bg-ink text-white px-4 py-2 text-sm">{{ t('common.new') }}</a>
    </div>

    <table class="mt-8 w-full text-sm">
        <thead>
            <tr>
                <th class="text-left px-3 py-2">{{ t('field.id') }}</th>{$headers}
                <th></th>
            </tr>
        </thead>
        <tbody>
        {% for row in rows %}
            <tr class="border-t border-neutral-200">
                <td class="px-3 py-2">{{ row.id }}</td>{$cells}
                <td class="px-3 py-2 text-right">
                    <a href="/admin/{$table}/{{ row.id }}/edit" class="underline">{{ t('common.edit') }}</a>
                    <form method="post" action="/admin/{$table}/{{ row.id }}/delete" class="inline"
                          onsubmit="return confirm('{{ t('common.confirm_delete')|e('js') }}')">
                        {{ csrf_field() }}
                        <button type="submit" class="underline text-red-600">{{ t('common.delete') }}</button>
                    </form>
                </td>
            </tr>
        {% else %}
            <tr><td colspan="99" class="px-3 py-6 text-neutral-500">{{ t('common.empty') }}</td></tr>
        {% endfor %}
        </tbody>
    </table>

    {% if pagination.has_more %}
        <a class="mt-6 inline-block underline"
           href="?offset={{ pagination.next_offset }}&limit={{ pagination.limit }}">{{ t('common.next_page') }}</a>
    {% endif %}
</div>
{% endblock %}
TWIG;
