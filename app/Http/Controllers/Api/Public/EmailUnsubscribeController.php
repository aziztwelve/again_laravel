<?php

namespace App\Http\Controllers\Api\Public;

use App\Http\Controllers\Controller;
use App\Models\EmailUnsubscribe;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * Отписка от рекламных писем по личной подписанной ссылке из подвала письма.
 * GET показывает подтверждение (почтовые сканеры ссылок не должны отписывать сами), POST отписывает.
 */
class EmailUnsubscribeController extends Controller
{
    public function show(Request $request): Response
    {
        $email = EmailUnsubscribe::normalize((string) $request->query('email'));

        return response()->view('emails.unsubscribe', [
            'email' => $email,
            'done' => EmailUnsubscribe::isUnsubscribed($email),
            'action' => $request->fullUrl(),
        ]);
    }

    public function confirm(Request $request): Response
    {
        $email = EmailUnsubscribe::normalize((string) $request->query('email'));
        EmailUnsubscribe::unsubscribe($email, 'email_link');

        return response()->view('emails.unsubscribe', [
            'email' => $email,
            'done' => true,
            'action' => $request->fullUrl(),
        ]);
    }
}
