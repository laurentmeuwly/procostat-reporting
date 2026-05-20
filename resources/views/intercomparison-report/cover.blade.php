<div class="page cover">

    {{-- Logo --}}
    <div class="cover__logo">
        <img src="{{ public_path('images/logo_procorad_2025_2026.png') }}" alt="PROCORAD">
    </div>

    {{-- Titre centré --}}
    <div class="cover__body">
        <div class="cover__subtitle">Inter-Laboratory Comparaison</div>
        <div class="cover__title">{{ $model->icTitle }}</div>
        <div class="cover__year">{{ $model->year }}</div>
    </div>

</div>
