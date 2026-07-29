@if ($module === 'distribusi')
    @include('livewire.v3.operations.distribution-index')
@elseif ($module === 'pencucian')
    @include('livewire.v3.operations.washing-index')
@else
    @include('livewire.v3.operations.index-generic')
@endif
