<label class="block text-[13px] text-cream/60">{{ $label }}
    <input type="number" name="{{ $name }}" value="{{ old($name, $value) }}" min="0" step="1"
           class="mt-1 w-full rounded-lg border border-white/10 bg-surface-2 px-3 py-2 text-sm outline-none focus:border-brand">
    @isset($hint)<span class="mt-1 block text-[11px] text-cream/35">{{ $hint }}</span>@endisset
</label>
