<?php

namespace App\Livewire\V3\MasterData;

use App\Enums\UserRole;
use App\Livewire\V3\Concerns\InteractsWithV3Shell;
use App\Models\User;
use App\Services\UserUnitAccessService;
use App\Services\VolunteerAttendanceService;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;
use Spatie\Permission\Models\Role;

class Users extends Component
{
    use InteractsWithV3Shell;
    use WithPagination;

    #[Url(as: 'q', history: true)]
    public string $search = '';

    public ?int $userId = null;

    public string $employeeNumber = '';

    public string $name = '';

    public string $email = '';

    public string $phone = '';

    public string $password = '';

    public string $passwordConfirmation = '';

    public ?int $roleId = null;

    public bool $isActive = true;

    public ?string $actionMessage = null;

    public function mount(): void
    {
        $this->currentUnit();
        abort_unless($this->allowed('users.view'), 403);
    }

    public function updatedSearch(): void
    {
        $this->resetPage();
    }

    public function create(): void
    {
        abort_unless($this->allowed('users.create'), 403);
        $this->resetForm();
    }

    public function edit(int $id): void
    {
        abort_unless($this->allowed('users.update'), 403);
        $this->currentUnit();
        $user = User::query()->findOrFail($id);
        $user->unsetRelation('roles');
        $this->userId = $user->id;
        $this->employeeNumber = (string) $user->employee_number;
        $this->name = $user->name;
        $this->email = $user->email;
        $this->phone = (string) $user->phone;
        $this->isActive = (bool) $user->is_active;
        $this->roleId = $user->roles->first()?->id;
        $this->password = '';
        $this->passwordConfirmation = '';
        $this->actionMessage = null;
    }

    public function cancel(): void
    {
        $this->resetForm();
    }

    public function save(UserUnitAccessService $access): void
    {
        $this->userId ? abort_unless($this->allowed('users.update'), 403) : abort_unless($this->allowed('users.create'), 403);
        abort_unless($this->allowed('users.assign_role'), 403);
        $unit = $this->currentUnit();
        $rules = ['employeeNumber' => ['nullable', 'string', 'max:50', Rule::unique('users', 'employee_number')->ignore($this->userId)], 'name' => ['required', 'string', 'max:255'], 'email' => ['required', 'email', 'max:255', Rule::unique('users', 'email')->ignore($this->userId)], 'phone' => ['nullable', 'string', 'max:30'], 'roleId' => ['required', Rule::exists('roles', 'id')->where(fn ($query) => $query->where('guard_name', 'web'))], 'isActive' => ['boolean']];
        $rules['password'] = $this->userId ? ['nullable', 'string', 'min:8', 'same:passwordConfirmation'] : ['required', 'string', 'min:8', 'same:passwordConfirmation'];
        $data = $this->validate($rules);
        $user = DB::transaction(function () use ($data, $unit, $access): User {
            $user = $this->userId ? User::query()->findOrFail($this->userId) : new User;
            $payload = ['employee_number' => VolunteerAttendanceService::normalizeUid($data['employeeNumber']) ?: null, 'name' => trim($data['name']), 'email' => str($data['email'])->trim()->lower(), 'phone' => trim($data['phone']) ?: null];
            if (filled($data['password'])) {
                $payload['password'] = $data['password'];
            }
            if (auth()->user()->is_super_admin) {
                $payload['is_active'] = $data['isActive'];
            } elseif (! $this->userId) {
                $payload['is_active'] = true;
            }
            $user->fill($payload)->save();
            $access->sync($user, $unit, (int) $data['roleId'], isMembershipActive: (bool) $user->is_active);

            return $user;
        });
        $this->actionMessage = "Akun {$user->name} berhasil disimpan untuk satu unit sistem.";
        $this->resetForm(keepMessage: true);
    }

    public function delete(int $id): void
    {
        abort_unless($this->allowed('users.delete'), 403);
        abort_if($id === auth()->id(), 422, 'Akun sendiri tidak dapat dihapus.');
        try {
            User::query()->findOrFail($id)->delete();
            $this->actionMessage = 'Akun pengguna berhasil dihapus.';
        } catch (QueryException) {
            $this->addError('action', 'Akun sudah memiliki jejak transaksi. Nonaktifkan akun sebagai pengganti penghapusan.');
        }
    }

    public function render()
    {
        $unit = $this->currentUnit();
        abort_unless($this->allowed('users.view'), 403);
        $users = User::query()->with('roles')->when(trim($this->search) !== '', fn ($query) => $query->where(fn ($query) => $query->where('name', 'like', '%'.trim($this->search).'%')->orWhere('email', 'like', '%'.trim($this->search).'%')->orWhere('employee_number', 'like', '%'.trim($this->search).'%')))->orderBy('name')->paginate(15);
        $allowedRoles = UserRole::assignableValues();
        if (! auth()->user()->is_super_admin) {
            $allowedRoles = array_values(array_diff($allowedRoles, [UserRole::AdminSppg->value, UserRole::KepalaSppg->value]));
        }
        if ($this->userId && User::query()->whereKey($this->userId)->where('is_super_admin', true)->exists()) {
            $allowedRoles[] = UserRole::SuperAdmin->value;
        }
        $roles = Role::query()->where('guard_name', 'web')->whereIn('name', $allowedRoles)->get()->sortBy(fn (Role $role) => UserRole::sortOrderFor($role->name));

        return view('livewire.v3.master-data.users', [...$this->shellData($unit), 'users' => $users, 'roles' => $roles, 'canCreate' => $this->allowed('users.create') && $this->allowed('users.assign_role'), 'canUpdate' => $this->allowed('users.update') && $this->allowed('users.assign_role'), 'canDelete' => $this->allowed('users.delete')])->layout('layouts.v3', ['title' => 'Pengguna']);
    }

    private function resetForm(bool $keepMessage = false): void
    {
        $this->userId = null;
        $this->employeeNumber = '';
        $this->name = '';
        $this->email = '';
        $this->phone = '';
        $this->password = '';
        $this->passwordConfirmation = '';
        $this->roleId = null;
        $this->isActive = true;
        if (! $keepMessage) {
            $this->actionMessage = null;
        } $this->resetErrorBag();
    }
}
