<?php

namespace App\Support;

use DateTimeInterface;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class FileNaming
{
    public static function upload(
        UploadedFile $file,
        string $directory,
        string $module,
        string $type,
        ?string $object = null,
        DateTimeInterface|string|null $date = null,
        int $sequence = 1,
        string $disk = 'public',
    ): string {
        $extension = strtolower($file->extension() ?: $file->getClientOriginalExtension() ?: 'jpg');
        $name = self::uploadName($module, $type, $object, $date, $extension, $sequence);
        $name = self::availableName($disk, $directory, $name);

        return $file->storeAs(trim($directory, '/'), $name, $disk);
    }

    public static function uploadName(
        string $module,
        string $type,
        ?string $object,
        DateTimeInterface|string|null $date,
        string $extension,
        int $sequence = 1,
    ): string {
        return implode('-', array_filter([
            self::part($module),
            self::part($type),
            self::part($object),
            self::date($date),
            now()->format('His'),
            str_pad((string) max(1, $sequence), 2, '0', STR_PAD_LEFT),
        ])).'.'.self::extension($extension);
    }

    public static function encodedImagePath(
        string $directory,
        string $module,
        string $type,
        ?string $object,
        DateTimeInterface|string|null $date,
        string $extension,
        int $sequence = 1,
        string $disk = 'public',
    ): string {
        $name = self::uploadName($module, $type, $object, $date, $extension, $sequence);

        return trim($directory, '/').'/'.self::availableName($disk, $directory, $name);
    }

    public static function report(
        string $type,
        ?string $object,
        DateTimeInterface|string|null $date,
        string $extension,
        DateTimeInterface|string|null $endDate = null,
    ): string {
        $parts = [self::part($type), self::part($object), self::date($date)];
        if ($endDate !== null) {
            $parts[] = 'sd';
            $parts[] = self::date($endDate);
        }

        return implode('-', array_filter($parts)).'.'.self::extension($extension);
    }

    public static function part(mixed $value, string $fallback = ''): string
    {
        $slug = Str::of((string) $value)->ascii()->lower()->slug('-')->trim('-')->toString();
        $slug = rtrim(mb_substr($slug, 0, 80), '-');

        return $slug !== '' ? $slug : $fallback;
    }

    public static function date(DateTimeInterface|string|null $date): string
    {
        if ($date instanceof DateTimeInterface) {
            return Carbon::instance($date)->format('d-m-Y');
        }

        return filled($date) ? Carbon::parse($date)->format('d-m-Y') : today()->format('d-m-Y');
    }

    private static function extension(string $extension): string
    {
        return preg_replace('/[^a-z0-9]+/', '', strtolower(ltrim($extension, '.'))) ?: 'dat';
    }

    private static function availableName(string $disk, string $directory, string $name): string
    {
        $directory = trim($directory, '/');
        if (! Storage::disk($disk)->exists($directory.'/'.$name)) {
            return $name;
        }

        $extension = pathinfo($name, PATHINFO_EXTENSION);
        $stem = pathinfo($name, PATHINFO_FILENAME);
        for ($counter = 2; $counter <= 99; $counter++) {
            $candidate = $stem.'-'.str_pad((string) $counter, 2, '0', STR_PAD_LEFT).'.'.$extension;
            if (! Storage::disk($disk)->exists($directory.'/'.$candidate)) {
                return $candidate;
            }
        }

        return $stem.'-'.Str::lower(Str::random(6)).'.'.$extension;
    }
}
