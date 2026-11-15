<?php

namespace App\Services;

use App\Models\ComprehensiveDocument;
use App\Models\User;
use Illuminate\Support\Facades\Schema;

class ComprehensiveDocumentChecklist
{
    public const TYPES = [
        'tor' => 'Transcript of Records (TOR)',
        'psa' => 'PSA document (as specified by the Registrar)',
        'id' => 'Identification document (ID)',
        'form137' => 'Form 137 (if applicable)',
    ];

    public function pending(User $student): array
    {
        if (! Schema::hasTable('comprehensive_documents')) {
            return [];
        }

        return ComprehensiveDocument::where('student_id', $student->id)
            ->where('required', true)->where('status', '!=', 'verified')->pluck('type')
            ->map(fn ($type) => self::TYPES[$type])->all();
    }

    public function apply(array $eligibility, User $student): array
    {
        if (! Schema::hasTable('comprehensive_documents')) {
            return $eligibility;
        }
        $missing = $this->pending($student);
        $eligibility['pending_document_requirements'] = $missing;
        if ($missing) {
            $eligibility['eligible'] = false;
            $eligibility['requirements'] = collect($eligibility['requirements'] ?? [])->map(function ($requirement) use ($missing) {
                if ($requirement['name'] === 'Complete documents submitted') {
                    $requirement['completed'] = false;
                    $requirement['description'] = 'Awaiting document review: '.implode(', ', $missing);
                }

                return $requirement;
            })->all();
        }

        // Local reviews can block application, but never replace official school clearance.
        return $eligibility;
    }
}
