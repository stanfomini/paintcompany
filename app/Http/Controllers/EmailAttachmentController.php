<?php

namespace App\Http\Controllers;

use App\Models\EmailAttachment;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

class EmailAttachmentController extends Controller
{
    public function download(EmailAttachment $attachment): StreamedResponse
    {
        // Verify the authenticated user owns the email account
        $account = $attachment->message->account;
        abort_unless(
            $account && auth()->id() === $account->user_id,
            403,
            'Unauthorized'
        );

        abort_unless(Storage::exists($attachment->path), 404);

        return Storage::download($attachment->path, $attachment->original_filename);
    }
}
