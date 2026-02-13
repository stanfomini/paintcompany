<?php

namespace App\Http\Controllers;

use App\Models\CompanySetting;
use App\Models\CrewSignedContract;
use App\Models\User;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\Response;

class CrewDocumentPdfController extends Controller
{
    public function w9(User $user): Response
    {
        abort_unless(auth()->user()?->can('people.view'), 403);
        abort_unless($user->isCrew(), 404);

        $w9 = $user->crewW9Form;
        abort_unless($w9, 404);

        $pdf = Pdf::loadView('pdf.crew-w9', [
            'user' => $user,
            'w9' => $w9,
            'settings' => CompanySetting::getInstance(),
        ]);

        $filename = 'w9-'.str($user->name)->slug().'.pdf';

        return $pdf->download($filename);
    }

    public function contract(CrewSignedContract $contract): Response
    {
        abort_unless(auth()->user()?->can('people.view'), 403);

        $contract->load('user');

        $pdf = Pdf::loadView('pdf.crew-contract', [
            'contract' => $contract,
            'settings' => CompanySetting::getInstance(),
        ]);

        $filename = 'contract-'.str($contract->type_label)->slug().'-'.str($contract->user->name)->slug().'.pdf';

        return $pdf->download($filename);
    }

    public function compliancePack(User $user): Response
    {
        abort_unless(auth()->user()?->can('people.view'), 403);
        abort_unless($user->isCrew(), 404);

        $user->load([
            'crewProfile',
            'crewSignedContracts',
            'crewW9Form',
        ]);

        $pdf = Pdf::loadView('pdf.crew-compliance-pack', [
            'user' => $user,
            'settings' => CompanySetting::getInstance(),
        ]);

        $filename = 'compliance-pack-'.str($user->name)->slug().'.pdf';

        return $pdf->download($filename);
    }
}
