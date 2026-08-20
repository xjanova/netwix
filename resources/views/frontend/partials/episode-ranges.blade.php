{{--
    Range pills for the title modal's episode list — the server-rendered twin of the players'
    picker pills (partials/episode-picker). Same rule: a title with more than one chunk gets
    "ตอน 1–50 / 51–100 …" instead of dumping every episode into one column.

    Drives `epChunk` on the title-modal Alpine component. Renders nothing for a short title.

    param $groups —  episodes already chunk()ed, values()-reindexed
--}}
@if ($groups->count() > 1)
    <div class="no-scrollbar mb-3 flex gap-2 overflow-x-auto pb-1">
        @foreach ($groups as $gi => $g)
            <button type="button" @click="epChunk = {{ $gi }}"
                    class="shrink-0 rounded-full px-3.5 py-1.5 text-[13px] font-bold transition"
                    :class="epChunk === {{ $gi }} ? 'nx-gradient text-white' : 'bg-white/10 text-cream/70 hover:bg-white/20'">
                ตอน {{ $g->first()->number }}–{{ $g->last()->number }}
            </button>
        @endforeach
    </div>
@endif
