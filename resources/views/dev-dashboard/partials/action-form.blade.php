@props([
    'action',
    'label',
    'class' => 'btn btn-sm btn-syrtak',
    'method' => 'POST',
    'extra' => [],
    'multipart' => false,
])

<form method="POST" action="{{ route('dev-dashboard.action') }}" class="d-inline"
      @if($multipart) enctype="multipart/form-data" @endif>
    @csrf
    <input type="hidden" name="action" value="{{ $action }}">
    @foreach($extra as $name => $value)
        <input type="hidden" name="{{ $name }}" value="{{ $value }}">
    @endforeach
    {{ $slot ?? '' }}
    <button type="submit" class="{{ $class }}">{{ $label }}</button>
</form>
