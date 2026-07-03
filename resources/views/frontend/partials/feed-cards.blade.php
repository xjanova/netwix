@foreach ($items as $content)
    <x-content-card :content="$content" :in-list="in_array($content->id, $myListIds)" />
@endforeach
