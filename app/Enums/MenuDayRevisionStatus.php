<?php

namespace App\Enums;

enum MenuDayRevisionStatus: string
{
    case PendingAuthorization = 'pending_authorization';
    case Authorized = 'authorized';
    case Submitted = 'submitted';
    case ChangesRequested = 'changes_requested';
    case Completed = 'completed';
    case Rejected = 'rejected';
    case Cancelled = 'cancelled';

    public function label(): string
    {
        return match ($this) {
            self::PendingAuthorization => 'Menunggu Izin Revisi',
            self::Authorized => 'Revisi Diizinkan',
            self::Submitted => 'Menunggu Persetujuan Hasil Revisi',
            self::ChangesRequested => 'Perlu Perbaikan Lagi',
            self::Completed => 'Revisi Selesai',
            self::Rejected => 'Permintaan Ditolak',
            self::Cancelled => 'Dibatalkan',
        };
    }

    public function color(): string
    {
        return match ($this) {
            self::PendingAuthorization, self::Submitted => 'warning',
            self::Authorized => 'info',
            self::ChangesRequested, self::Rejected => 'danger',
            self::Completed => 'success',
            self::Cancelled => 'gray',
        };
    }

    public function isOpen(): bool
    {
        return in_array($this, [
            self::PendingAuthorization,
            self::Authorized,
            self::Submitted,
            self::ChangesRequested,
        ], true);
    }
}
