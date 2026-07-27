@if ($module === 'distribusi')
    @include('livewire.v3.operations.distribution-form')
@else
    @include('livewire.v3.operations.form-generic')
@endif
