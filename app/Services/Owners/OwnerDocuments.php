<?php

namespace App\Services\Owners;

use App\Enums\FilePurpose;
use App\Enums\OwnerDocumentKind;
use App\Enums\OwnerVerificationStatus;
use App\Models\OwnerDocument;
use App\Models\OwnerProfile;
use App\Services\Files\Contracts\FileService;
use Carbon\CarbonImmutable;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Proof files for an owner application: a valid ID and a proof of the
 * property are required, up to MAX files. Files are kept only until
 * RETENTION_DAYS after the admin's decision (minimum personal data).
 */
class OwnerDocuments
{
    public const MAX = 5;

    public const RETENTION_DAYS = 90;

    public function __construct(private readonly FileService $files) {}

    /** Validation rules for `documents[i][kind|file]` and `remove_document_ids[]`. */
    public function rules(): array
    {
        return [
            'documents' => ['sometimes', 'array', 'max:'.self::MAX],
            'documents.*.kind' => ['required', Rule::enum(OwnerDocumentKind::class)],
            'documents.*.file' => $this->files->rules(FilePurpose::OwnerDocument),
            'remove_document_ids' => ['sometimes', 'array'],
            'remove_document_ids.*' => ['integer'],
        ];
    }

    /**
     * Remove the chosen files, add the new ones, and make sure what is left
     * still covers the required kinds. Checked before anything is changed.
     *
     * @param  list<array{kind: string, file: UploadedFile}>  $new
     * @param  list<int>  $removeIds
     */
    public function sync(OwnerProfile $profile, array $new, array $removeIds): void
    {
        $kept = $profile->exists
            ? $profile->documents()->kept()->whereNotIn('id', $removeIds)->get()
            : collect();

        $kinds = $kept->pluck('kind')->merge(array_map(fn ($d) => OwnerDocumentKind::from($d['kind']), $new));
        $missing = array_filter(OwnerDocumentKind::required(), fn (OwnerDocumentKind $k) => ! $kinds->contains($k));

        if ($missing !== []) {
            throw ValidationException::withMessages([
                'documents' => 'Attach: '.implode('; ', array_map(fn (OwnerDocumentKind $k) => $k->label(), $missing)).'.',
            ]);
        }
        if ($kinds->count() > self::MAX) {
            throw ValidationException::withMessages(['documents' => 'You can attach up to '.self::MAX.' files. Remove some first.']);
        }

        if ($profile->exists && $removeIds !== []) {
            $profile->documents()->kept()->whereIn('id', $removeIds)->get()->each(function (OwnerDocument $document) {
                $document->delete();
                // Only once the application is saved; a failed save keeps the file.
                DB::afterCommit(fn () => $this->files->delete($document->path, FilePurpose::OwnerDocument));
            });
        }

        foreach ($new as $document) {
            $path = $this->files->store($document['file'], FilePurpose::OwnerDocument);
            // If the application is not saved after all, do not leave the file behind.
            DB::afterRollBack(fn () => $this->files->delete($path, FilePurpose::OwnerDocument));

            $profile->documents()->create([
                'kind' => $document['kind'],
                'path' => $path,
                'original_name' => mb_substr($document['file']->getClientOriginalName(), 0, 255),
                'mime_type' => (string) $document['file']->getMimeType(),
                'size_bytes' => (int) $document['file']->getSize(),
            ]);
        }
    }

    /** Daily: delete the files of owners decided over RETENTION_DAYS ago. The rows stay as a record. */
    public function purgeDecided(CarbonImmutable $day): int
    {
        $due = OwnerDocument::query()
            ->kept()
            ->whereHas('ownerProfile', fn ($q) => $q
                ->where('verification_status', '!=', OwnerVerificationStatus::Pending->value)
                ->whereNotNull('reviewed_at')
                ->where('reviewed_at', '<', $day->subDays(self::RETENTION_DAYS)->startOfDay()))
            ->get();

        foreach ($due as $document) {
            $this->files->delete($document->path, FilePurpose::OwnerDocument);
            $document->forceFill(['path' => null, 'purged_at' => now()])->save();
        }

        return $due->count();
    }
}
