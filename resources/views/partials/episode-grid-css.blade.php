{{--
    Fixed-size tile geometry for every episode grid (both players' เลือกตอน overlays and the title
    modal's episode list). Its own partial so the players and the modal cannot drift apart, and plain
    CSS rather than Tailwind utilities so shipping it never depends on a `npm run build` landing first.
--}}
<style>
/* The column IS the tile. The old grids used `minmax(150px,1fr)`, and the `1fr` was the bug: the tile
   became whatever was left after the browser divided the container, so the same picker showed a
   comfortable cover on one screen and a stamp on another. `justify-content:center` absorbs the
   leftover width instead of the covers doing it. */
.nx-ep-grid { display:grid; align-content:start; justify-content:center; gap:14px;
              grid-template-columns:repeat(auto-fill,var(--nx-ep-w)); --nx-ep-w:120px; }
.nx-ep-grid--wide { --nx-ep-w:172px; }   /* 16:9 captured frames need width to stay readable */
@media (min-width:640px)  { .nx-ep-grid { --nx-ep-w:140px } .nx-ep-grid--wide { --nx-ep-w:200px } }
@media (min-width:1024px) { .nx-ep-grid { --nx-ep-w:156px } .nx-ep-grid--wide { --nx-ep-w:224px } }
</style>
