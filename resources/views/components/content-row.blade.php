@props(['title', 'items', 'ranked' => false, 'myListIds' => []])

@if ($items->isNotEmpty())
    <section class="mt-8" x-data="{ scroll(dir) { $refs.rail.scrollBy({ left: dir * $refs.rail.clientWidth * 0.85, behavior: 'smooth' }); } }">
        <h2 class="mb-2 flex items-center gap-2.5 px-[4vw] text-lg font-semibold sm:text-xl">
            <span class="nx-gradient h-5 w-1 shrink-0 rounded-full sm:h-6" aria-hidden="true"></span>
            <span>{{ $title }}</span>
        </h2>
        <div class="group/row relative">
            <button type="button" @click="scroll(-1)"
                    class="absolute left-0 top-0 z-20 hidden h-full w-[4vw] items-center justify-center bg-gradient-to-r from-ink/80 to-transparent text-2xl opacity-0 transition group-hover/row:opacity-100 lg:flex">‹</button>
            <div x-ref="rail" class="nx-rail px-[4vw] pb-2">
                @foreach ($items as $i => $content)
                    <x-content-card :content="$content"
                                    :in-list="in_array($content->id, $myListIds)"
                                    :ranked="$ranked ? $i + 1 : null" />
                @endforeach
            </div>
            <button type="button" @click="scroll(1)"
                    class="absolute right-0 top-0 z-20 hidden h-full w-[4vw] items-center justify-center bg-gradient-to-l from-ink/80 to-transparent text-2xl opacity-0 transition group-hover/row:opacity-100 lg:flex">›</button>
        </div>
    </section>
@endif
