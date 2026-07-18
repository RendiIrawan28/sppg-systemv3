<?php

namespace App\Services;

use App\Models\Beneficiary;
use App\Models\BeneficiaryCategory;
use App\Models\BeneficiaryImport;
use App\Models\Posyandu;
use App\Models\School;
use App\Models\User;
use DateTimeInterface;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Shared\Date as ExcelDate;
use Throwable;

class BeneficiaryImportService
{
    private const REQUIRED_HEADERS = [
        'nisn_nik',
        'nama_penerima',
        'tanggal_lahir',
        'jenis_kelamin_id',
        'nama_orang_tua',
        'posisi',
        'status_menerima',
    ];

    public function preview(
        TemporaryUploadedFile $file,
        School|Posyandu $institution,
        ?string $defaultSchoolCategoryCode = null
    ): array {
        $parsed = $this->parse(
            file: $file,
            institution: $institution,
            defaultSchoolCategoryCode: $defaultSchoolCategoryCode
        );

        return [
            'total' => count($parsed['rows']) +
                count($parsed['errors']),

            'valid' => count($parsed['rows']),

            'invalid' => count($parsed['errors']),

            'existing' => collect($parsed['rows'])
                ->filter(
                    fn(array $row): bool =>
                    $row['existing_id'] !== null
                )
                ->count(),

            'sample' => collect($parsed['rows'])
                ->take(10)
                ->values()
                ->all(),

            'errors' => $parsed['errors'],
        ];
    }

    public function import(
        TemporaryUploadedFile $file,
        School|Posyandu $institution,
        User $user,
        bool $updateExisting = false,
        ?string $defaultSchoolCategoryCode = null
    ): BeneficiaryImport {
        $this->assertInstitutionUnit(
            institution: $institution
        );

        $import = BeneficiaryImport::query()->create([
            'sppg_unit_id' =>
            $institution->sppg_unit_id,

            'institution_type' =>
            $institution->getMorphClass(),

            'institution_id' =>
            $institution->getKey(),

            'uploaded_by' => $user->getKey(),

            'original_filename' =>
            $file->getClientOriginalName(),

            'status' => 'processing',

            'started_at' => now(),
        ]);

        try {
            $parsed = $this->parse(
                file: $file,
                institution: $institution,
                defaultSchoolCategoryCode: $defaultSchoolCategoryCode
            );

            $errors = $parsed['errors'];
            $inserted = 0;
            $updated = 0;

            DB::transaction(function () use (
                $parsed,
                $institution,
                $import,
                $updateExisting,
                &$errors,
                &$inserted,
                &$updated
            ): void {
                foreach ($parsed['rows'] as $row) {
                    $existing = $row['existing_id']
                        ? Beneficiary::query()->find(
                            $row['existing_id']
                        )
                        : null;

                    if (
                        $existing &&
                        ! $updateExisting
                    ) {
                        $errors[] = [
                            'row' => $row['row_number'],
                            'messages' => [
                                "Nomor induk {$row['external_id']} sudah tersedia.",
                            ],
                        ];

                        continue;
                    }

                    $values = [
                        'sppg_unit_id' =>
                        $institution->sppg_unit_id,

                        'beneficiary_category_id' =>
                        $row['beneficiary_category_id'],

                        'external_id' =>
                        $row['external_id'],

                        'name' => $row['name'],

                        'group_name' =>
                        $row['group_name'],

                        'parent_name' =>
                        $row['parent_name'],

                        'recipient_position' =>
                        $row['recipient_position'],

                        'birth_date' =>
                        $row['birth_date'],

                        'address' =>
                        $row['address'] ?? null,

                        'gender' => $row['gender'],

                        'start_date' =>
                        $row['start_date'],

                        'end_date' =>
                        $row['end_date'],

                        'allergy_notes' =>
                        $row['allergy_notes'],

                        'special_needs' =>
                        $row['special_needs'],

                        'notes' => $row['notes'],

                        'data_source' => 'import',

                        'last_import_id' =>
                        $import->getKey(),

                        'is_active' =>
                        $row['is_active'],
                    ];

                    if ($existing) {
                        $existing->update($values);
                        $updated++;

                        continue;
                    }

                    $institution
                        ->beneficiaries()
                        ->create($values);

                    $inserted++;
                }
            });

            $invalidRows = count($errors);

            $status = match (true) {
                $inserted + $updated === 0 &&
                    $invalidRows > 0 => 'failed',

                $invalidRows > 0 =>
                'partially_completed',

                default => 'completed',
            };

            $import->update([
                'status' => $status,

                'total_rows' =>
                count($parsed['rows']) +
                    count($parsed['errors']),

                'valid_rows' =>
                count($parsed['rows']),

                'invalid_rows' =>
                $invalidRows,

                'imported_rows' =>
                $inserted,

                'updated_rows' =>
                $updated,

                'errors' =>
                array_slice($errors, 0, 250),

                'completed_at' => now(),
            ]);

            return $import->refresh();
        } catch (Throwable $exception) {
            $import->update([
                'status' => 'failed',
                'errors' => [
                    [
                        'row' => null,
                        'messages' => [
                            $exception->getMessage(),
                        ],
                    ],
                ],
                'completed_at' => now(),
            ]);

            throw $exception;
        }
    }

    private function parse(
        TemporaryUploadedFile $file,
        School|Posyandu $institution,
        ?string $defaultSchoolCategoryCode = null
    ): array {
        return match (true) {
            $institution instanceof School =>
            $this->parseSchool(
                file: $file,
                institution: $institution,
                defaultSchoolCategoryCode: $defaultSchoolCategoryCode
            ),

            $institution instanceof Posyandu =>
            $this->parsePosyandu($file, $institution),
        };
    }

    private function parseSchool(
        TemporaryUploadedFile $file,
        School $institution,
        ?string $defaultSchoolCategoryCode = null
    ): array {
        $this->assertInstitutionUnit($institution);

        if (! $institution instanceof School) {
            throw ValidationException::withMessages([
                'file' => 'Template ini hanya digunakan untuk sekolah.',
            ]);
        }

        $spreadsheet = IOFactory::load(
            $file->getRealPath()
        );

        $sheet = $spreadsheet->getSheetByName(
            'Template Penerima'
        );

        if (! $sheet) {
            throw ValidationException::withMessages([
                'file' =>
                'Sheet "Template Penerima" tidak ditemukan.',
            ]);
        }

        $rawRows = $sheet->toArray(
            null,
            true,
            false,
            false
        );

        if (count($rawRows) < 3) {
            throw ValidationException::withMessages([
                'file' =>
                'Template tidak memiliki baris data.',
            ]);
        }

        $headerRow = $rawRows[0];

        $headers = collect($headerRow)
            ->map(
                fn($header): string =>
                $this->normalizeHeader(
                    (string) $header
                )
            )
            ->all();

        $missingHeaders = array_diff(
            self::REQUIRED_HEADERS,
            $headers
        );

        if (! empty($missingHeaders)) {
            throw ValidationException::withMessages([
                'file' =>
                'Kolom template tidak lengkap: ' .
                    implode(', ', $missingHeaders),
            ]);
        }

        /*
     * Baris 1 adalah header.
     * Baris 2 adalah keterangan.
     * Data dimulai dari baris 3.
     */
        $dataRows = array_slice($rawRows, 2);

        $categories = BeneficiaryCategory::query()
            ->where(
                'sppg_unit_id',
                $institution->sppg_unit_id
            )
            ->where('is_active', true)
            ->get()
            ->keyBy(
                fn(BeneficiaryCategory $category): string =>
                Str::lower($category->code)
            );

        $rows = [];
        $errors = [];
        $seenExternalIds = [];

        foreach ($dataRows as $index => $rawRow) {
            $rowNumber = $index + 3;

            $firstColumn = trim(
                (string) ($rawRow[0] ?? '')
            );

            /*
         * Hentikan pembacaan saat bagian catatan ditemukan.
         */
            if (
                Str::startsWith(
                    Str::upper($firstColumn),
                    'CATATAN'
                )
            ) {
                break;
            }

            $rawRow = array_pad(
                $rawRow,
                count($headers),
                null
            );

            $data = array_combine(
                $headers,
                array_slice(
                    $rawRow,
                    0,
                    count($headers)
                )
            );

            if ($this->isEmptyRow($data)) {
                continue;
            }

            $externalId = trim(
                (string) ($data['nisn_nik'] ?? '')
            );

            $name = trim(
                (string) (
                    $data['nama_penerima'] ?? ''
                )
            );

            $rowErrors = [];

            if ($externalId === '') {
                $rowErrors[] =
                    'NISN/NIK wajib diisi.';
            }

            if (mb_strlen($externalId) > 20) {
                $rowErrors[] =
                    'NISN/NIK maksimal 20 karakter.';
            }

            if ($name === '') {
                $rowErrors[] =
                    'Nama penerima wajib diisi.';
            }

            /*
         * Mencegah data contoh masuk ke database.
         */
            if (
                Str::contains(
                    Str::upper($name),
                    'UBAH:'
                ) ||
                Str::contains($name, '⚠')
            ) {
                $rowErrors[] =
                    'Data contoh wajib diubah atau dihapus.';
            }

            if (
                $externalId !== '' &&
                isset($seenExternalIds[$externalId])
            ) {
                $rowErrors[] =
                    "NISN/NIK duplikat di dalam file. " .
                    "Pertama ditemukan pada baris " .
                    $seenExternalIds[$externalId] .
                    '.';
            }

            if ($externalId !== '') {
                $seenExternalIds[$externalId] =
                    $rowNumber;
            }

            $gender = $this->normalizeSchoolGender(
                $data['jenis_kelamin_id'] ?? null
            );

            if ($gender === null) {
                $rowErrors[] =
                    'Jenis Kelamin ID harus 392 atau 64.';
            }

            $position = $this->normalizeSchoolPosition(
                $data['posisi'] ?? null
            );

            if ($position === null) {
                $rowErrors[] =
                    'Posisi harus 1, 2, atau 3.';
            }

            $status = $this->normalizeReceivingStatus(
                $data['status_menerima'] ?? null
            );

            if ($status === null) {
                $rowErrors[] =
                    'Status Menerima harus true atau false.';
            }

            $birthDate = $this->normalizeDate(
                $data['tanggal_lahir'] ?? null
            );

            if (
                filled(
                    $data['tanggal_lahir'] ?? null
                ) &&
                $birthDate === null
            ) {
                $rowErrors[] =
                    'Tanggal lahir harus menggunakan format DD-MM-YYYY.';
            }

            $category = null;
            $resolvedGroupName = $this->nullableString(
                $data['kelas'] ??
                $data['kelas_kelompok'] ??
                $data['kelompok'] ??
                null
            );

            if ($position !== null) {
                $resolution = $this->resolveSchoolCategory(
                    data: $data,
                    institution: $institution,
                    categories: $categories,
                    position: $position,
                    defaultSchoolCategoryCode: $defaultSchoolCategoryCode
                );

                $category = $resolution['category'];
                $resolvedGroupName =
                    $resolvedGroupName ??
                    $resolution['group_name'];

                if ($resolution['error'] !== null) {
                    $rowErrors[] = $resolution['error'];
                }
            }

            if (! empty($rowErrors)) {
                $errors[] = [
                    'row' => $rowNumber,
                    'messages' => $rowErrors,
                ];

                continue;
            }

            $existingId = Beneficiary::query()
                ->where(
                    'sppg_unit_id',
                    $institution->sppg_unit_id
                )
                ->where(
                    'beneficiaryable_type',
                    $institution->getMorphClass()
                )
                ->where(
                    'beneficiaryable_id',
                    $institution->getKey()
                )
                ->where(
                    'external_id',
                    $externalId
                )
                ->value('id');

            $parentName = $this->nullableString(
                $data['nama_orang_tua'] ?? null
            );

            if ($parentName === '-') {
                $parentName = null;
            }

            $rows[] = [
                'row_number' => $rowNumber,
                'existing_id' => $existingId,
                'external_id' => $externalId,
                'name' => $name,

                'beneficiary_category_id' =>
                $category->getKey(),

                'group_name' => $resolvedGroupName,
                'parent_name' => $parentName,

                'recipient_position' =>
                $position['code'],

                'position_label' =>
                $position['label'],

                'category_label' =>
                $category->name,

                'birth_date' => $birthDate,
                'address' => $this->nullableString(
                    $data['alamat'] ?? null
                ),
                'gender' => $gender,
                'start_date' => $this->normalizeDate(
                    $data['tanggal_mulai_periode'] ?? null
                ),
                'end_date' => $this->normalizeDate(
                    $data['tanggal_selesai_periode'] ?? null
                ),
                'allergy_notes' => $this->nullableString(
                    $data['alergi'] ?? null
                ),
                'special_needs' => $this->nullableString(
                    $data['kebutuhan_khusus'] ?? null
                ),
                'notes' => $this->nullableString(
                    $data['catatan'] ?? null
                ),
                'is_active' => $status,
            ];
        }

        return [
            'rows' => $rows,
            'errors' => $errors,
        ];
    }

    private function parsePosyandu(
        TemporaryUploadedFile $file,
        Posyandu $institution
    ): array {
        $this->assertInstitutionUnit($institution);

        $spreadsheet = IOFactory::load(
            $file->getRealPath()
        );

        if ($spreadsheet->getSheetCount() !== 1) {
            throw ValidationException::withMessages([
                'file' =>
                'Template Posyandu harus memiliki tepat satu sheet.',
            ]);
        }

        $sheet = $spreadsheet->getSheet(0);

        $rawRows = $sheet->toArray(
            null,
            true,
            false,
            false
        );

        if (count($rawRows) < 4) {
            throw ValidationException::withMessages([
                'file' =>
                'Template tidak memiliki data penerima.',
            ]);
        }

        /*
     * Header berada pada baris ketiga.
     */
        $headerRow = $rawRows[2];

        $headers = collect($headerRow)
            ->map(
                fn($header): string =>
                $this->normalizeHeader(
                    (string) $header
                )
            )
            ->all();

        $requiredHeaders = [
            'no',
            'kapanewon',
            'sppg_melayani',
            'nama_lengkap',
            'nik',
            'tanggal_lahir',
            'alamat',
            'tanggal_mulai_distribusi',
            'golongan_balita_busui_bumil_kader',
        ];

        $missingHeaders = array_diff(
            $requiredHeaders,
            $headers
        );

        if (! empty($missingHeaders)) {
            throw ValidationException::withMessages([
                'file' =>
                'Kolom template Posyandu tidak lengkap: ' .
                    implode(', ', $missingHeaders),
            ]);
        }

        /*
     * Data dimulai pada baris keempat.
     */
        $dataRows = array_slice($rawRows, 3);

        $categories = BeneficiaryCategory::query()
            ->where(
                'sppg_unit_id',
                $institution->sppg_unit_id
            )
            ->where('is_active', true)
            ->get()
            ->keyBy(
                fn(BeneficiaryCategory $category): string =>
                Str::lower($category->code)
            );

        $categoryMap = [
            'balita' => 'balita',
            'busui' => 'ibu_menyusui',
            'bumil' => 'ibu_hamil',
            'kader' => 'kader',
        ];

        $rows = [];
        $errors = [];
        $seenExternalIds = [];

        foreach ($dataRows as $index => $rawRow) {
            $rowNumber = $index + 4;

            $rawRow = array_pad(
                $rawRow,
                count($headers),
                null
            );

            $data = array_combine(
                $headers,
                array_slice(
                    $rawRow,
                    0,
                    count($headers)
                )
            );

            if ($this->isEmptyRow($data)) {
                continue;
            }

            $externalId = trim(
                (string) ($data['nik'] ?? '')
            );

            $name = trim(
                (string) (
                    $data['nama_lengkap'] ?? ''
                )
            );

            $golongan = Str::lower(
                trim(
                    (string) (
                        $data['golongan_balita_busui_bumil_kader'] ?? ''
                    )
                )
            );

            $rowErrors = [];

            if ($externalId === '') {
                $rowErrors[] = 'NIK wajib diisi.';
            } elseif (
                ! preg_match('/^\d{16}$/', $externalId)
            ) {
                $rowErrors[] =
                    'NIK harus terdiri dari tepat 16 digit.';
            }

            if ($name === '') {
                $rowErrors[] =
                    'Nama lengkap wajib diisi.';
            }

            if (
                Str::contains(
                    Str::upper($name),
                    'CONTOH'
                )
            ) {
                $rowErrors[] =
                    'Baris contoh harus dihapus atau diubah.';
            }

            $categoryCode =
                $categoryMap[$golongan] ?? null;

            if ($categoryCode === null) {
                $rowErrors[] =
                    'Golongan harus Balita, Busui, Bumil, atau Kader.';
            }

            $category = $categoryCode
                ? $categories->get($categoryCode)
                : null;

            if (
                $categoryCode !== null &&
                ! $category
            ) {
                $rowErrors[] =
                    "Kategori {$categoryCode} belum tersedia.";
            }

            if (
                isset($seenExternalIds[$externalId]) &&
                $externalId !== ''
            ) {
                $rowErrors[] =
                    "NIK duplikat di dalam file. " .
                    "Pertama ditemukan pada baris " .
                    $seenExternalIds[$externalId] .
                    '.';
            }

            if ($externalId !== '') {
                $seenExternalIds[$externalId] =
                    $rowNumber;
            }

            $birthDate = $this->normalizeDate(
                $data['tanggal_lahir'] ?? null
            );

            if (
                filled(
                    $data['tanggal_lahir'] ?? null
                ) &&
                $birthDate === null
            ) {
                $rowErrors[] =
                    'Format tanggal lahir tidak valid.';
            }

            $startDate = $this->normalizeDate(
                $data['tanggal_mulai_distribusi'] ?? null
            );

            if (
                filled(
                    $data['tanggal_mulai_distribusi'] ?? null
                ) &&
                $startDate === null
            ) {
                $rowErrors[] =
                    'Format tanggal mulai distribusi tidak valid.';
            }

            if (! empty($rowErrors)) {
                $errors[] = [
                    'row' => $rowNumber,
                    'messages' => $rowErrors,
                ];

                continue;
            }

            $existingId = Beneficiary::query()
                ->where(
                    'sppg_unit_id',
                    $institution->sppg_unit_id
                )
                ->where(
                    'beneficiaryable_type',
                    $institution->getMorphClass()
                )
                ->where(
                    'beneficiaryable_id',
                    $institution->getKey()
                )
                ->where(
                    'external_id',
                    $externalId
                )
                ->value('id');

            $rows[] = [
                'row_number' => $rowNumber,
                'existing_id' => $existingId,
                'external_id' => $externalId,
                'name' => $name,

                'beneficiary_category_id' =>
                $category->getKey(),

                'group_name' => null,
                'parent_name' => null,

                'recipient_position' =>
                $categoryCode,

                'birth_date' => $birthDate,

                'address' =>
                $this->nullableString(
                    $data['alamat'] ?? null
                ),

                'gender' => null,
                'start_date' => $startDate,
                'end_date' => null,
                'allergy_notes' => null,
                'special_needs' => null,

                /*
             * Kapanewon dan SPPG tidak disimpan ulang
             * karena sudah merupakan data lembaga/unit.
             */
                'notes' => null,

                'is_active' => true,

                'category_label' =>
                $category->name,
            ];
        }

        return [
            'rows' => $rows,
            'errors' => $errors,
        ];
    }

    private function assertInstitutionUnit(
        School|Posyandu $institution
    ): void {
        if (
            ! $institution->sppg_unit_id
        ) {
            throw ValidationException::withMessages([
                'file' =>
                'Lembaga tidak memiliki Unit SPPG.',
            ]);
        }
    }

    private function normalizeHeader(string $header): string
    {
        return Str::of($header)
            ->ascii()
            ->trim()
            ->lower()
            ->replaceMatches('/[^a-z0-9]+/', '_')
            ->trim('_')
            ->toString();
    }

    private function normalizeDate(
        mixed $value
    ): ?string {
        if (
            $value === null ||
            $value === ''
        ) {
            return null;
        }

        try {
            /*
         * Apabila PhpSpreadsheet langsung
         * memberikan objek tanggal.
         */
            if ($value instanceof DateTimeInterface) {
                return $value->format('Y-m-d');
            }

            /*
         * Tanggal Excel dalam bentuk serial number.
         */
            if (
                is_numeric($value) &&
                (float) $value > 0
            ) {
                $date = ExcelDate::excelToDateTimeObject(
                    (float) $value
                );

                $year = (int) $date->format('Y');

                if (
                    $year < 1900 ||
                    $year > 2100
                ) {
                    return null;
                }

                return $date->format('Y-m-d');
            }

            $value = trim((string) $value);

            /*
         * Mendukung:
         * 9-3-2019
         * 09-03-2019
         * 9/3/2019
         * 09/03/2019
         */
            if (
                preg_match(
                    '/^(\d{1,2})[-\/](\d{1,2})[-\/](\d{4})$/',
                    $value,
                    $matches
                )
            ) {
                $day = (int) $matches[1];
                $month = (int) $matches[2];
                $year = (int) $matches[3];

                if (
                    ! checkdate(
                        $month,
                        $day,
                        $year
                    )
                ) {
                    return null;
                }

                return sprintf(
                    '%04d-%02d-%02d',
                    $year,
                    $month,
                    $day
                );
            }

            /*
         * Mendukung format YYYY-MM-DD.
         */
            if (
                preg_match(
                    '/^(\d{4})-(\d{1,2})-(\d{1,2})$/',
                    $value,
                    $matches
                )
            ) {
                $year = (int) $matches[1];
                $month = (int) $matches[2];
                $day = (int) $matches[3];

                if (
                    ! checkdate(
                        $month,
                        $day,
                        $year
                    )
                ) {
                    return null;
                }

                return sprintf(
                    '%04d-%02d-%02d',
                    $year,
                    $month,
                    $day
                );
            }

            /*
         * Format tambahan dari data Posyandu:
         * 19-Dec-04
         * 19-Dec-2004
         */
            foreach (
                [
                    'd-M-y',
                    'j-M-y',
                    'd-M-Y',
                    'j-M-Y',
                ] as $format
            ) {
                try {
                    $date = \Carbon\Carbon::createFromFormat(
                        $format,
                        $value
                    );

                    if ($date !== false) {
                        return $date->format(
                            'Y-m-d'
                        );
                    }
                } catch (Throwable) {
                    // Lanjut ke format berikutnya.
                }
            }
        } catch (Throwable) {
            return null;
        }

        return null;
    }

    private function normalizeSchoolGender(
        mixed $value
    ): ?string {
        return match (trim((string) $value)) {
            '392' => 'male',
            '64' => 'female',
            default => null,
        };
    }

    private function normalizeSchoolPosition(
        mixed $value
    ): ?array {
        return match (trim((string) $value)) {
            '1' => [
                'code' => 'primary',
                'label' => 'Penerima Utama',
                'category_code' => null,
            ],

            '2' => [
                'code' => 'teacher',
                'label' => 'Guru',
                'category_code' => 'guru',
            ],

            '3' => [
                'code' => 'education_staff',
                'label' => 'Tenaga Kependidikan',
                'category_code' => 'tendik',
            ],

            default => null,
        };
    }

    /**
     * Menentukan kategori penerima sekolah berdasarkan data baris.
     *
     * Urutan prioritas:
     * 1. Kolom kelompok_penerima/kategori_penerima.
     * 2. Kelompok default yang dipilih pada form import file lama.
     * 3. Jenjang dan kelas pada baris/template.
     * 4. Jenjang sekolah untuk PAUD, TK, SMP, dan SMA.
     *
     * Untuk SD, kelas wajib tersedia karena kategori porsi kelas 1–3
     * berbeda dengan kelas 4–6.
     *
     * @return array{
     *     category: ?BeneficiaryCategory,
     *     group_name: ?string,
     *     error: ?string
     * }
     */
    private function resolveSchoolCategory(
        array $data,
        School $institution,
        Collection $categories,
        array $position,
        ?string $defaultSchoolCategoryCode
    ): array {
        if ($position['code'] !== 'primary') {
            $positionCategory = $position['category_code']
                ? $categories->get($position['category_code'])
                : null;

            $fallbackCategory = $categories->get('lainnya');
            $category = $positionCategory ?? $fallbackCategory;

            return [
                'category' => $category,
                'group_name' => $position['label'],
                'error' => $category
                    ? null
                    : "Kategori {$position['label']} atau kategori lainnya belum tersedia.",
            ];
        }

        $explicitValue =
            $data['kelompok_penerima'] ??
            $data['kategori_penerima'] ??
            $data['beneficiary_group'] ??
            null;

        $explicitCode = $this->normalizeSchoolCategoryCode(
            $explicitValue
        );

        if ($explicitCode !== null) {
            $category = $categories->get($explicitCode);

            return [
                'category' => $category,
                'group_name' => $this->schoolCategoryGroupName(
                    $explicitCode,
                    $data
                ),
                'error' => $category
                    ? null
                    : "Kategori {$explicitCode} belum tersedia pada Unit SPPG ini.",
            ];
        }

        $defaultCode = $this->normalizeSchoolCategoryCode(
            $defaultSchoolCategoryCode
        );

        if ($defaultCode !== null) {
            $category = $categories->get($defaultCode);

            return [
                'category' => $category,
                'group_name' => $this->schoolCategoryGroupName(
                    $defaultCode,
                    $data
                ),
                'error' => $category
                    ? null
                    : "Kelompok default {$defaultCode} belum tersedia pada Unit SPPG ini.",
            ];
        }

        $educationLevel = $this->normalizeEducationLevel(
            $data['jenjang_pendidikan'] ??
            $data['jenjang'] ??
            $institution->education_level
        );

        if ($educationLevel === null) {
            return [
                'category' => null,
                'group_name' => null,
                'error' => 'Jenjang pendidikan belum tersedia. Isi jenjang pada data sekolah atau kolom jenjang_pendidikan.',
            ];
        }

        if ($educationLevel !== 'sd') {
            $category = $categories->get($educationLevel);

            return [
                'category' => $category,
                'group_name' => Str::upper($educationLevel),
                'error' => $category
                    ? null
                    : "Kategori {$educationLevel} belum tersedia pada Unit SPPG ini.",
            ];
        }

        $rawClass =
            $data['kelas'] ??
            $data['kelas_kelompok'] ??
            $data['kelompok'] ??
            null;

        $grade = $this->normalizeSchoolGrade($rawClass);

        if ($grade === null) {
            return [
                'category' => null,
                'group_name' => $this->nullableString($rawClass),
                'error' => 'Kelas wajib diisi untuk siswa SD agar sistem dapat membedakan SD kelas 1–3 dan SD kelas 4–6. Untuk file lama, pilih Kelompok Penerima Default saat import.',
            ];
        }

        if ($grade < 1 || $grade > 6) {
            return [
                'category' => null,
                'group_name' => (string) $grade,
                'error' => 'Kelas siswa SD harus berada antara 1 sampai 6.',
            ];
        }

        $categoryCode = $grade <= 3
            ? 'sd_1_3'
            : 'sd_4_6';

        $category = $categories->get($categoryCode);

        return [
            'category' => $category,
            'group_name' => (string) $grade,
            'error' => $category
                ? null
                : "Kategori {$categoryCode} belum tersedia pada Unit SPPG ini.",
        ];
    }

    private function normalizeSchoolCategoryCode(
        mixed $value
    ): ?string {
        if (blank($value)) {
            return null;
        }

        $normalized = Str::of((string) $value)
            ->ascii()
            ->lower()
            ->replaceMatches('/[^a-z0-9]+/', '_')
            ->trim('_')
            ->toString();

        return match ($normalized) {
            'paud',
            'pendidikan_anak_usia_dini' => 'paud',

            'tk',
            'taman_kanak_kanak' => 'tk',

            'sd_1_3',
            'sd_1_sampai_3',
            'sd_kelas_1_3',
            'sd_kelas_1_sampai_3' => 'sd_1_3',

            'sd_4_6',
            'sd_4_sampai_6',
            'sd_kelas_4_6',
            'sd_kelas_4_sampai_6' => 'sd_4_6',

            'smp',
            'mts' => 'smp',

            'sma',
            'smk',
            'ma' => 'sma',

            default => null,
        };
    }

    private function normalizeEducationLevel(
        mixed $value
    ): ?string {
        if (blank($value)) {
            return null;
        }

        $normalized = Str::of((string) $value)
            ->ascii()
            ->lower()
            ->replaceMatches('/[^a-z0-9]+/', '_')
            ->trim('_')
            ->toString();

        if (Str::contains($normalized, ['paud'])) {
            return 'paud';
        }

        if (in_array($normalized, ['tk', 'taman_kanak_kanak', 'ra'], true)) {
            return 'tk';
        }

        if (
            Str::startsWith($normalized, 'sd') ||
            Str::contains($normalized, ['sekolah_dasar', 'madrasah_ibtidaiyah']) ||
            $normalized === 'mi'
        ) {
            return 'sd';
        }

        if (
            Str::startsWith($normalized, 'smp') ||
            Str::contains($normalized, ['sekolah_menengah_pertama']) ||
            $normalized === 'mts'
        ) {
            return 'smp';
        }

        if (
            Str::startsWith($normalized, ['sma', 'smk']) ||
            Str::contains($normalized, ['sekolah_menengah_atas', 'sekolah_menengah_kejuruan']) ||
            $normalized === 'ma'
        ) {
            return 'sma';
        }

        return null;
    }

    private function normalizeSchoolGrade(
        mixed $value
    ): ?int {
        if (blank($value)) {
            return null;
        }

        $raw = Str::of((string) $value)
            ->ascii()
            ->upper()
            ->trim()
            ->toString();

        if (preg_match('/(?:KELAS\\s*)?(\\d{1,2})/', $raw, $matches)) {
            return (int) $matches[1];
        }

        $roman = preg_replace('/[^IVX]/', '', $raw);

        return match ($roman) {
            'I' => 1,
            'II' => 2,
            'III' => 3,
            'IV' => 4,
            'V' => 5,
            'VI' => 6,
            default => null,
        };
    }

    private function schoolCategoryGroupName(
        string $categoryCode,
        array $data
    ): ?string {
        $rawClass =
            $data['kelas'] ??
            $data['kelas_kelompok'] ??
            $data['kelompok'] ??
            null;

        $grade = $this->normalizeSchoolGrade($rawClass);

        if ($grade !== null) {
            return (string) $grade;
        }

        return match ($categoryCode) {
            'paud' => 'PAUD',
            'tk' => 'TK',
            'sd_1_3' => 'SD 1–3',
            'sd_4_6' => 'SD 4–6',
            'smp' => 'SMP',
            'sma' => 'SMA',
            default => null,
        };
    }

    private function normalizeReceivingStatus(
        mixed $value
    ): ?bool {
        return match (Str::lower(trim((string) $value))) {
            'true',
            '1',
            'ya',
            'aktif' => true,

            'false',
            '0',
            'tidak',
            'nonaktif' => false,

            default => null,
        };
    }

    private function normalizeBoolean(
        mixed $value
    ): bool {
        if (is_bool($value)) {
            return $value;
        }

        return in_array(
            Str::lower(
                trim((string) $value)
            ),
            [
                '1',
                'ya',
                'yes',
                'true',
                'aktif',
            ],
            true
        );
    }

    private function nullableString(
        mixed $value
    ): ?string {
        $value = trim((string) $value);

        return $value === '' ? null : $value;
    }

    private function isEmptyRow(
        array $row
    ): bool {
        return collect($row)
            ->every(
                fn($value): bool =>
                blank($value)
            );
    }
}
