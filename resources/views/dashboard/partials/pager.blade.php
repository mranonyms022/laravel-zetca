@if ($paginator->hasPages())
    <nav class="flex items-center justify-between text-sm" aria-label="Pagination">
        @if ($paginator->onFirstPage())
            <span></span>
        @else
            <a href="{{ $paginator->previousPageUrl() }}" class="rounded-md border border-slate-300 px-3 py-1.5 hover:bg-slate-100 dark:border-slate-700 dark:hover:bg-slate-800">← Previous</a>
        @endif
        @if (method_exists($paginator, 'lastPage'))
            <span class="text-slate-500">Page {{ $paginator->currentPage() }} of {{ $paginator->lastPage() }}</span>
        @endif
        @if ($paginator->hasMorePages())
            <a href="{{ $paginator->nextPageUrl() }}" class="rounded-md border border-slate-300 px-3 py-1.5 hover:bg-slate-100 dark:border-slate-700 dark:hover:bg-slate-800">Next →</a>
        @else
            <span></span>
        @endif
    </nav>
@endif
