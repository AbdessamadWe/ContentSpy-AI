<?php
namespace App\Services\Billing;

use App\Models\CreditTransaction;
use App\Models\Workspace;
use App\Services\Credits\CreditService;
use Illuminate\Support\Facades\Log;
use Stripe\Customer;
use Stripe\Exception\ApiErrorException;
use Stripe\Price;
use Stripe\Stripe;
use Stripe\Subscription;
use Stripe\Webhook;

class StripeService
{
    public function __construct(private readonly CreditService $credits)
    {
        Stripe::setApiKey(config('services.stripe.secret'));
    }

    // ─── Customer Management ──────────────────────────────────────────────────

    /**
     * Get or create a Stripe customer for the workspace.
     */
    public function ensureCustomer(Workspace $workspace, string $email): string
    {
        if ($workspace->stripe_customer_id) {
            return $workspace->stripe_customer_id;
        }

        $customer = Customer::create([
            'email'    => $email,
            'name'     => $workspace->name,
            'metadata' => ['workspace_id' => $workspace->id, 'workspace_ulid' => $workspace->ulid],
        ]);

        $workspace->update(['stripe_customer_id' => $customer->id]);
        return $customer->id;
    }

    // ─── Subscriptions ────────────────────────────────────────────────────────

    /**
     * Create a checkout session for a subscription plan.
     * Returns the Stripe Checkout session URL.
     */
    public function createSubscriptionCheckout(Workspace $workspace, string $plan, string $email): string
    {
        $customerId = $this->ensureCustomer($workspace, $email);
        $priceId    = config("contentspy.plans.{$plan}.stripe_price_id");

        if (! $priceId) {
            throw new \InvalidArgumentException("No Stripe price configured for plan: {$plan}");
        }

        $session = \Stripe\Checkout\Session::create([
            'customer'            => $customerId,
            'mode'                => 'subscription',
            'payment_method_types' => ['card'],
            'line_items'          => [['price' => $priceId, 'quantity' => 1]],
            'success_url'         => config('app.frontend_url') . '/billing/success?session_id={CHECKOUT_SESSION_ID}',
            'cancel_url'          => config('app.frontend_url') . '/billing/cancel',
            'metadata'            => ['workspace_id' => $workspace->id, 'plan' => $plan],
            'subscription_data'   => ['metadata' => ['workspace_id' => $workspace->id]],
        ]);

        return $session->url;
    }

    /**
     * Create a checkout session for a one-time credit pack purchase.
     */
    public function createCreditPackCheckout(Workspace $workspace, string $packKey, string $email): string
    {
        $customerId = $this->ensureCustomer($workspace, $email);
        $pack       = config("contentspy.credit_packs.{$packKey}");

        if (! $pack) {
            throw new \InvalidArgumentException("Unknown credit pack: {$packKey}");
        }

        $session = \Stripe\Checkout\Session::create([
            'customer'            => $customerId,
            'mode'                => 'payment',
            'payment_method_types' => ['card'],
            'line_items'          => [[
                'price_data' => [
                    'currency'     => 'usd',
                    'unit_amount'  => $pack['price_cents'],
                    'product_data' => ['name' => $pack['name'], 'description' => "{$pack['credits']} ContentSpy credits"],
                ],
                'quantity'   => 1,
            ]],
            'success_url'  => config('app.frontend_url') . '/billing/success?session_id={CHECKOUT_SESSION_ID}',
            'cancel_url'   => config('app.frontend_url') . '/billing/cancel',
            'metadata'     => ['workspace_id' => $workspace->id, 'pack_key' => $packKey, 'credits' => $pack['credits']],
        ]);

        return $session->url;
    }

    /**
     * Cancel a workspace subscription immediately.
     */
    public function cancelSubscription(Workspace $workspace): void
    {
        if (! $workspace->stripe_subscription_id) return;

        Subscription::update($workspace->stripe_subscription_id, ['cancel_at_period_end' => true]);
    }

    // ─── Webhooks ─────────────────────────────────────────────────────────────

    /**
     * Verify and parse an incoming Stripe webhook.
     * Returns the event or throws on invalid signature.
     *
     * @throws \Stripe\Exception\SignatureVerificationException
     */
    public function parseWebhook(string $payload, string $signature): \Stripe\Event
    {
        return Webhook::constructEvent(
            $payload,
            $signature,
            config('services.stripe.webhook_secret')
        );
    }

    /**
     * Handle incoming Stripe webhook event.
     * Routes to specific handler based on event type.
     */
    public function handleWebhookEvent(\Stripe\Event $event): void
    {
        match ($event->type) {
            'checkout.session.completed'          => $this->onCheckoutCompleted($event->data->object),
            'customer.subscription.updated'       => $this->onSubscriptionUpdated($event->data->object),
            'customer.subscription.deleted'       => $this->onSubscriptionDeleted($event->data->object),
            'invoice.payment_failed'              => $this->onPaymentFailed($event->data->object),
            'invoice.payment_succeeded'           => $this->onPaymentSucceeded($event->data->object),
            default                               => null,
        };
    }

    // ─── Webhook Handlers ─────────────────────────────────────────────────────

    private function onCheckoutCompleted(\Stripe\Checkout\Session $session): void
    {
        $workspaceId = $session->metadata['workspace_id'] ?? null;
        if (! $workspaceId) return;

        $workspace = Workspace::find($workspaceId);
        if (! $workspace) return;

        if ($session->mode === 'payment') {
            // One-time credit pack purchase
            $credits = (int) ($session->metadata['credits'] ?? 0);
            $packKey = $session->metadata['pack_key'] ?? 'unknown';

            if ($credits > 0) {
                $this->credits->addCredits(
                    workspace:   $workspace,
                    amount:      $credits,
                    type:        'purchase',
                    description: "Credit pack: {$packKey} ({$credits} credits)",
                    metadata:    ['stripe_session_id' => $session->id, 'pack_key' => $packKey],
                );
                Log::info("[Stripe] Added {$credits} credits to workspace #{$workspaceId} (pack: {$packKey})");
            }
        }
    }

    private function onSubscriptionUpdated(\Stripe\Subscription $sub): void
    {
        $workspaceId = $sub->metadata['workspace_id'] ?? null;
        if (! $workspaceId) return;

        $workspace = Workspace::find($workspaceId);
        if (! $workspace) return;

        $plan = $this->resolvePlanFromSubscription($sub);

        $workspace->update([
            'stripe_subscription_id' => $sub->id,
            'plan'                   => $plan,
            'plan_expires_at'        => \Carbon\Carbon::createFromTimestamp($sub->current_period_end),
            'is_active'              => in_array($sub->status, ['active', 'trialing']),
        ]);

        // Grant monthly plan credits
        $monthlyCredits = config("contentspy.plans.{$plan}.monthly_credits", 0);
        if ($monthlyCredits > 0 && $sub->status === 'active') {
            $this->credits->addCredits(
                workspace:   $workspace,
                amount:      $monthlyCredits,
                type:        'plan_grant',
                description: "Monthly {$plan} plan credit grant",
                metadata:    ['stripe_subscription_id' => $sub->id],
            );
        }
    }

    private function onSubscriptionDeleted(\Stripe\Subscription $sub): void
    {
        $workspaceId = $sub->metadata['workspace_id'] ?? null;
        if (! $workspaceId) return;

        Workspace::where('id', $workspaceId)->update([
            'plan'      => 'starter',
            'is_active' => true, // Keep account active but downgrade plan
        ]);
    }

    private function onPaymentFailed(\Stripe\Invoice $invoice): void
    {
        Log::warning("[Stripe] Payment failed for customer {$invoice->customer}");
        // TODO: send email notification via Resend
    }

    private function onPaymentSucceeded(\Stripe\Invoice $invoice): void
    {
        Log::info("[Stripe] Payment succeeded for customer {$invoice->customer}");
    }

    private function resolvePlanFromSubscription(\Stripe\Subscription $sub): string
    {
        $priceId = $sub->items->data[0]->price->id ?? null;
        $plans   = config('contentspy.plans', []);

        foreach ($plans as $planKey => $planConfig) {
            if (($planConfig['stripe_price_id'] ?? null) === $priceId) {
                return $planKey;
            }
        }

        return 'starter';
    }
}
