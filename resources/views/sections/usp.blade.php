@php($usp = $homepage['usp'])

<section class="mx-auto max-w-6xl px-4 py-14 sm:px-6 sm:py-20 lg:px-8">
    <x-section-heading :title="$usp['title']" />

    <div class="mt-10 grid gap-5 sm:grid-cols-2 lg:grid-cols-3">
        @foreach ($usp['items'] as $index => $item)
            <x-usp-card :title="$item['title']" :description="$item['description']" :index="$index" />
        @endforeach
    </div>
</section>
