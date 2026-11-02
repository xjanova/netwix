<div class="nx-card mb-6 p-5">
    <h2 class="text-lg font-bold">5 แคมเปญสำรวจ NetWix · เว็บและแอป</h2>
    <p class="mt-2 text-sm text-cream/60">เหรียญเงินส่วนนี้เป็นโบนัสเพิ่มจากเช็กอิน แชร์ และชวนเพื่อนตามปกติ ปรับค่าแล้วใช้ร่วมกันทุกอุปกรณ์ · รีเซ็ตตามเวลาไทย</p>
    @if(session('status'))<p class="mt-3 text-success">{{ session('status') }}</p>@endif
    @if($errors->any())<p class="mt-3 text-red-400">{{ $errors->first() }}</p>@endif
    <form method="POST" action="{{ route('admin.reward-campaigns.update') }}" class="mt-4 space-y-4">
        @csrf @method('PUT')
        @foreach($campaigns as $key => $c)
            <fieldset class="rounded-xl border border-white/10 p-4">
                <legend class="px-2 font-semibold">{{ $c['title'] }}</legend>
                <div class="grid gap-3 sm:grid-cols-3">
                    <label class="text-sm">ชื่อแคมเปญ<input name="campaigns[{{ $key }}][title]" value="{{ old("campaigns.$key.title", $c['title']) }}" class="nx-input mt-1 w-full" required maxlength="100"></label>
                    <label class="text-sm">เป้าหมาย (ครั้ง / เรื่อง / หมวด)<input type="number" name="campaigns[{{ $key }}][target]" value="{{ old("campaigns.$key.target", $c['target']) }}" min="1" max="{{ $key === 'explorer' ? 3 : 20 }}" class="nx-input mt-1 w-full" required></label>
                    <label class="text-sm">โบนัสเหรียญเงิน<input type="number" name="campaigns[{{ $key }}][coins]" value="{{ old("campaigns.$key.coins", $c['coins']) }}" min="0" max="10000" class="nx-input mt-1 w-full" required></label>
                    <label class="text-sm">รอบรับรางวัล<select name="campaigns[{{ $key }}][repeat]" class="nx-input mt-1 w-full">@foreach(['daily' => 'ทุกวัน', 'weekly' => 'ทุกสัปดาห์', 'once' => 'ครั้งเดียว'] as $value => $label)<option value="{{ $value }}" @selected(old("campaigns.$key.repeat", $c['repeat']) === $value)>{{ $label }}</option>@endforeach</select></label>
                    <label class="text-sm">เวลาดูต่อเรื่อง (วินาที)<input type="number" name="campaigns[{{ $key }}][seconds]" value="{{ old("campaigns.$key.seconds", $c['seconds']) }}" min="30" max="7200" class="nx-input mt-1 w-full" required></label>
                    <label class="flex items-center gap-2 text-sm"><input type="hidden" name="campaigns[{{ $key }}][enabled]" value="0"><input type="checkbox" name="campaigns[{{ $key }}][enabled]" value="1" @checked(old("campaigns.$key.enabled", $c['enabled']))> เปิดแคมเปญ</label>
                </div>
            </fieldset>
        @endforeach
        <button class="btn-brand px-5 py-2">บันทึกทั้ง 5 แคมเปญ</button>
    </form>
</div>
