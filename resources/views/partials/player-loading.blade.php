{{-- Branded "connecting to the server" loader — shown while a stream resolves /
     buffers, in both the standard and the vertical player. --}}
<div class="flex h-full w-full flex-col items-center justify-center gap-6"
     style="background:radial-gradient(circle at 50% 42%, rgba(24,18,40,0.82), rgba(7,5,12,0.94))">
    <div class="relative flex h-24 w-24 items-center justify-center">
        <div class="absolute inset-0 rounded-full border-[3px] border-white/10 border-t-brand animate-spin"></div>
        <div class="absolute inset-1.5 rounded-full border-[2px] border-white/5 border-b-brand-2 animate-spin"
             style="animation-duration:1.6s;animation-direction:reverse"></div>
        <img src="{{ asset('assets/netwix-icon.png') }}" alt="NetWix" class="h-12 w-12 rounded-2xl animate-pulse"
             style="filter:drop-shadow(0 0 18px rgba(176,38,255,0.65))">
    </div>
    <div class="flex items-center gap-1.5 text-sm font-medium tracking-wide text-cream/85">
        <span>กำลังเชื่อมต่อเซิร์ฟเวอร์</span>
        <span class="inline-flex items-end gap-1 pb-0.5">
            <span class="h-1.5 w-1.5 animate-bounce rounded-full bg-brand" style="animation-delay:0ms"></span>
            <span class="h-1.5 w-1.5 animate-bounce rounded-full bg-brand" style="animation-delay:150ms"></span>
            <span class="h-1.5 w-1.5 animate-bounce rounded-full bg-brand" style="animation-delay:300ms"></span>
        </span>
    </div>
</div>
