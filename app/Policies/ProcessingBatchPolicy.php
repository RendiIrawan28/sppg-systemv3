<?php

namespace App\Policies;

use App\Models\ProcessingBatch;
use App\Models\User;

class ProcessingBatchPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can('processing.view');
    }

    public function view(User $user, ProcessingBatch $batch): bool
    {
        return $user->can('processing.view');
    }

    public function create(User $user): bool
    {
        return $user->can('processing.create');
    }

    public function update(User $user, ProcessingBatch $batch): bool
    {
        if ($batch->isReportEditable()) {
            return $user->can('processing.update');
        }

        return $user->can('processing.view');
    }

    public function delete(User $user, ProcessingBatch $batch): bool
    {
        return $user->can('processing.delete') && $batch->canBeDeleted();
    }

    public function deleteAny(User $user): bool
    {
        return false;
    }
}
