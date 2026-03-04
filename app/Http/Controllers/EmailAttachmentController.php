<?php

namespace App\Http\Controllers;

use App\Models\EmailAttachment;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

class EmailAttachmentController extends Controller
{
    public function download(EmailAttachment $attachment): StreamedResponse
    {
        $this->authorizeAccess($attachment);

        abort_unless(Storage::exists($attachment->path), 404);

        return Storage::download($attachment->path, $attachment->original_filename);
    }

    public function preview(EmailAttachment $attachment): StreamedResponse
    {
        $this->authorizeAccess($attachment);

        abort_unless(Storage::exists($attachment->path), 404);

        return Storage::response($attachment->path, $attachment->original_filename, [
            'Content-Type' => $attachment->mime_type,
        ]);
    }

    private function authorizeAccess(EmailAttachment $attachment): void
    {
        $account = $attachment->message->account;
        abort_unless($account, 403, 'Unauthorized');

        $userId = auth()->id();

        // Personal account: owner check
        if ($account->isPersonal() && $account->user_id === $userId) {
            return;
        }

        // Shared account: member check
        if ($account->isShared() && $account->members()->where('users.id', $userId)->exists()) {
            return;
        }

        abort(403, 'Unauthorized');
    }
}
