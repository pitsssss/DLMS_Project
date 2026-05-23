@props([
    'action',
    'label',
    'class' => 'btn btn-sm btn-syrtak',
    'method' => 'POST',
    'extra' => [],
    'multipart' => false,
])

@php
    $devRoutes = $devRoutes ?? [
        'action' => route('dev-dashboard.action', absolute: false),
        'reset' => route('dev-dashboard.reset', absolute: false),
        'index' => route('dev-dashboard.index', absolute: false),
    ];
@endphp
<form method="POST" action="{{ $devRoutes['action'] }}" class="d-inline"
      @if($multipart) enctype="multipart/form-data" @endif>
    @csrf
    <input type="hidden" name="action" value="{{ $action }}">
    @foreach($extra as $name => $value)
        <input type="hidden" name="{{ $name }}" value="{{ $value }}">
    @endforeach
    {{ $slot ?? '' }}
    <button type="submit" class="{{ $class }}">{{ $label }}</button>
</form>
