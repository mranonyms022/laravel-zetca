<div class="mb-4 flex gap-1 border-b border-slate-200 text-sm dark:border-slate-800">
    @foreach (['errors.index' => 'Errors', 'errors.groups' => 'Groups', 'errors.warnings' => 'Warnings'] as $route => $label)
        <a href="{{ $zroute($route) }}" class="-mb-px border-b-2 px-3 py-2 {{ $active === $route ? 'border-emerald-600 font-medium text-emerald-700 dark:text-emerald-400' : 'border-transparent text-slate-500 hover:text-slate-700' }}">{{ $label }}</a>
    @endforeach
</div>
