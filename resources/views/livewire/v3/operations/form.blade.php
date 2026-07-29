@if ($module === 'distribusi')
    @include('livewire.v3.operations.distribution-form')
@elseif ($module === 'pencucian')
    @include('livewire.v3.operations.washing-form')
@else
    @include('livewire.v3.operations.form-generic')
@endif
