@php($budget = $homepage['budget'])

<section class="py-14 sm:py-20">
    <div class="mx-auto max-w-6xl px-4 sm:px-6 lg:px-8">
        <div class="rounded-card bg-brand-orange px-6 py-12 text-center sm:px-12">
            <x-section-heading :title="$budget['title']" />

            <p class="mx-auto mt-5 max-w-2xl font-display text-xl font-semibold text-brand-brown sm:text-2xl">
                {{ $budget['copy'] }}
            </p>

            <ul class="mt-8 flex flex-wrap items-center justify-center gap-3">
                @foreach ($budget['examples'] as $example)
                    <li class="rounded-pill bg-white/95 px-5 py-2.5 font-display text-base font-bold text-brand-brown sm:text-lg">
                        {{ $example }}
                    </li>
                @endforeach
            </ul>

            <p class="mx-auto mt-8 max-w-xl text-base font-medium text-brand-brown">{{ $budget['support_copy'] }}</p>
            <p class="mx-auto mt-3 max-w-xl text-xs text-brand-brown">{{ $budget['disclaimer'] }}</p>
        </div>
    </div>
</section>
