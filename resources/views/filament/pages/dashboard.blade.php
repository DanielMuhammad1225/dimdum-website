<x-filament-panels::page>
    <div class="space-y-6">
        <section
            class="rounded-xl border border-gray-200 bg-white p-6 dark:border-white/10 dark:bg-gray-900"
        >
            <h2 class="text-xl font-semibold text-gray-950 dark:text-white">
                Halo, {{ $userName }} 👋
            </h2>

            <p class="mt-2 text-sm text-gray-600 dark:text-gray-400">
                Kamu masuk sebagai
                <span class="font-semibold text-gray-950 dark:text-white">{{ $roleLabel }}</span>.
            </p>
        </section>

        <section
            class="rounded-xl border border-gray-200 bg-white p-6 dark:border-white/10 dark:bg-gray-900"
        >
            <div class="flex items-start gap-3">
                <span
                    class="mt-0.5 inline-flex h-2.5 w-2.5 shrink-0 rounded-full bg-primary-500"
                    aria-hidden="true"
                ></span>

                <div>
                    <h3 class="text-base font-semibold text-gray-950 dark:text-white">
                        Admin panel DIMDUM sudah aktif
                    </h3>

                    <p class="mt-2 text-sm leading-relaxed text-gray-600 dark:text-gray-400">
                        Fondasi panel (login, role, dan permission) sudah berjalan.
                        Modul pengelolaan konten akan ditambahkan bertahap pada fase
                        berikutnya, dimulai dari konten homepage.
                    </p>

                    <p class="mt-3 text-sm leading-relaxed text-gray-600 dark:text-gray-400">
                        Belum ada menu konten karena modulnya memang belum dibuat.
                    </p>
                </div>
            </div>
        </section>
    </div>
</x-filament-panels::page>
