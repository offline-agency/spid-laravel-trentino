@props(['label' => 'Entra con SPID'])

<a href="{{ route('spid.login') }}" {{ $attributes->merge(['class' => 'btn btn-light-primary align-self-center w-100']) }}>
    {{ $label }}
</a>
