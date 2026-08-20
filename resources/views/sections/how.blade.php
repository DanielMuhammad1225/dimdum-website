@php($how = $homepage['how'])

<section id="{{ $how['id'] }}" class="py-14 sm:py-20">
    <div class="mx-auto max-w-6xl px-4 sm:px-6 lg:px-8">
        <x-section-heading :title="$how['title']" />

        <ol class="mt-10 grid gap-5 md:grid-cols-3">
            @foreach ($how['steps'] as $index => $step)
                <x-step-card :title="$step['title']" :description="$step['description']" :step="$index + 1" />
            @endforeach
        </ol>

        <p class="mx-auto mt-8 max-w-xl rounded-card bg-brand-cream px-6 py-5 text-center text-sm font-medium leading-relaxed text-brand-brown/80 sm:text-base">
            {{ $how['closing'] }}
        </p>
    </div>
</section>
