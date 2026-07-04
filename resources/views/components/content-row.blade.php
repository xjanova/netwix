@props(['title', 'items', 'ranked' => false, 'myListIds' => [], 'link' => null, 'en' => null])

@if ($items->isNotEmpty())
    <section class="mt-8" x-data="nxRail()">
        <h2 class="mb-2 flex items-center gap-2 px-[4vw] text-lg font-semibold sm:text-xl">
            <span class="nx-gradient h-5 w-1 shrink-0 rounded-full sm:h-6" aria-hidden="true"></span>
            <span>{{ $title }}</span>
            @if ($en)<span class="text-[14px] font-normal text-cream/40">{{ $en }}</span>@endif
            @if ($link)
                <a href="{{ $link }}" class="ml-1 whitespace-nowrap rounded-full bg-white/10 px-2.5 py-0.5 text-[12px] font-semibold text-cream/85 transition hover:bg-brand hover:text-white">ดูทั้งหมด ›</a>
            @endif
        </h2>
        <div class="group/row relative">
            <button type="button" @click="scroll(-1)"
                    class="absolute left-0 top-0 z-20 hidden h-full w-[4vw] items-center justify-center bg-gradient-to-r from-ink/80 to-transparent text-2xl pointer-events-none opacity-0 transition group-hover/row:pointer-events-auto group-hover/row:opacity-100 lg:flex">‹</button>
            <div x-ref="rail" class="nx-rail px-[4vw] pb-2" @mousemove="edgeMove($event)" @mouseleave="edgeLeave()">
                @foreach ($items as $i => $content)
                    <x-content-card :content="$content"
                                    :in-list="in_array($content->id, $myListIds)"
                                    :ranked="$ranked ? $i + 1 : null" />
                @endforeach
            </div>
            <button type="button" @click="scroll(1)"
                    class="absolute right-0 top-0 z-20 hidden h-full w-[4vw] items-center justify-center bg-gradient-to-l from-ink/80 to-transparent text-2xl pointer-events-none opacity-0 transition group-hover/row:pointer-events-auto group-hover/row:opacity-100 lg:flex">›</button>
        </div>
    </section>
@endif
