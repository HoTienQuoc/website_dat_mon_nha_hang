<?php

namespace App\Http\Controllers;

use App\Services\StripePayments;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Stripe\Exception\SignatureVerificationException;
use Stripe\Webhook;

class StripeWebhookController extends Controller
{
    public function __invoke(Request $request, StripePayments $payments): Response
    {
        $secret = (string) config('services.stripe.webhook_secret');
        abort_unless(str_starts_with($secret, 'whsec_'), 503);
        try {
            $event = Webhook::constructEvent($request->getContent(), (string) $request->header('Stripe-Signature'), $secret);
        } catch (SignatureVerificationException|\UnexpectedValueException $e) {
            return response('Invalid signature', 400);
        }
        if ($event->livemode) {
            return response('Test mode only', 400);
        }
        $payments->processEvent($event->toArray());

        return response('OK', 200);
    }
}
