<main>
    @foreach ($sections as $section)
        <x-phi-cms-core::section :section="$section" :page="$page" :preview="$preview ?? false" :occurrence="$section->pivot->occurrence_id" />
    @endforeach
</main>
