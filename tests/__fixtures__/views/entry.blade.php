<h1>{{ $title }}</h1>

@foreach (\Statamic\Facades\Entry::query()->where('collection', 'articles')->orderBy('slug', 'asc')->get() as $article)
    <li>{{ $article->title }}</li>
@endforeach

<footer>{{ \Statamic\Facades\GlobalSet::find('footer')->in('default')->phone }}</footer>
