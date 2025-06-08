@props(['label' => 'Accedi con AAC'])

<a href="{{ config('spid-laravel-trentino.routes.login', '/aac/login') }}"
  {{ $attributes->merge(['class' => 'btn btn-light-primary align-self-center w-100"']) }}>
  {{ $label }}
</a>
