{{--
    Edit / disable-enable / delete buttons for a table row.
    @include('zatca::dashboard.partials.row-actions', [
        'edit' => url|null, 'toggle' => ['enable' => url, 'disable' => url]|null, 'enabled' => bool,
        'delete' => url|null, 'confirm' => 'Delete … ?', 'name' => 'record name',
    ])
--}}
@php
    $btnBase = 'inline-flex items-center gap-1 rounded-md px-2 py-1 text-xs font-medium ring-1 ring-inset transition';
@endphp
<div class="flex items-center justify-end gap-1.5">
    @if (! empty($edit))
        <a href="{{ $edit }}" class="{{ $btnBase }} text-slate-700 ring-slate-300 hover:bg-slate-100 dark:text-slate-200 dark:ring-slate-600 dark:hover:bg-slate-800" title="Edit {{ $name }}">
            <svg class="h-3.5 w-3.5" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" viewBox="0 0 24 24" aria-hidden="true"><path d="M12 20h9M16.5 3.5a2.1 2.1 0 013 3L7 19l-4 1 1-4z"/></svg>
            Edit
        </a>
    @endif
    @if (! empty($toggle))
        <form method="POST" action="{{ $enabled ? $toggle['disable'] : $toggle['enable'] }}" class="inline"
              @if ($enabled) onsubmit="return confirm('Disable {{ e(addslashes($name)) }}? It will refuse new invoices until enabled again.')" @endif>
            @csrf
            @if ($enabled)
                <button class="{{ $btnBase }} text-amber-700 ring-amber-300 hover:bg-amber-50 dark:text-amber-300 dark:ring-amber-700 dark:hover:bg-amber-900/30" title="Disable {{ $name }}">
                    <svg class="h-3.5 w-3.5" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" viewBox="0 0 24 24" aria-hidden="true"><path d="M10 9v6m4-6v6M12 3a9 9 0 110 18 9 9 0 010-18z"/></svg>
                    Disable
                </button>
            @else
                <button class="{{ $btnBase }} text-emerald-700 ring-emerald-300 hover:bg-emerald-50 dark:text-emerald-300 dark:ring-emerald-700 dark:hover:bg-emerald-900/30" title="Enable {{ $name }}">
                    <svg class="h-3.5 w-3.5" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" viewBox="0 0 24 24" aria-hidden="true"><path d="M10 8l6 4-6 4zM12 3a9 9 0 110 18 9 9 0 010-18z"/></svg>
                    Enable
                </button>
            @endif
        </form>
    @endif
    @if (! empty($delete))
        <form method="POST" action="{{ $delete }}" class="inline" onsubmit="return confirm('{{ e(addslashes($confirm ?? ('Delete ' . $name . '?'))) }}')">
            @csrf
            @method('DELETE')
            <button class="{{ $btnBase }} text-red-700 ring-red-300 hover:bg-red-50 dark:text-red-300 dark:ring-red-800 dark:hover:bg-red-900/30" title="Delete {{ $name }}">
                <svg class="h-3.5 w-3.5" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" viewBox="0 0 24 24" aria-hidden="true"><path d="M3 6h18M8 6V4h8v2M6 6l1 14h10l1-14M10 11v6M14 11v6"/></svg>
                Delete
            </button>
        </form>
    @endif
</div>
