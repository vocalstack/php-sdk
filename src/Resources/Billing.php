<?php

declare(strict_types=1);

namespace VocalStack\Resources;

/**
 * `$vs->billing`: the account's plan, credit balance and limits, the
 * price list, the ledger and the per-recording charges.
 */
final class Billing extends Group
{
    /**
     * The account: plan, rates, balance, limits, subscription, top-up URL.
     *
     * @return array<string, mixed>
     */
    public function get(): array
    {
        return $this->t->json('GET', '/v1/billing');
    }

    /**
     * The visible plans with their current rates and limits (no credential needed).
     *
     * @return array<string, mixed>
     */
    public function plans(): array
    {
        return $this->t->json('GET', '/v1/billing/plans');
    }

    /**
     * The ledger, newest first; `cursor` is the previous page's `nextCursor`.
     *
     * @return array<string, mixed>
     */
    public function ledger(?string $cursor = null, ?int $limit = null): array
    {
        return $this->t->json('GET', '/v1/billing/ledger', query: ['cursor' => $cursor, 'limit' => $limit]);
    }

    /**
     * The per-recording charges, newest first.
     *
     * @return array<string, mixed>
     */
    public function charges(?string $cursor = null, ?int $limit = null): array
    {
        return $this->t->json('GET', '/v1/billing/charges', query: ['cursor' => $cursor, 'limit' => $limit]);
    }

    /**
     * Open a checkout at the payment provider (`'kind' => 'topup', 'amount' => ...`
     * or `'kind' => 'subscribe', 'planId' => ..., 'interval' => ...`, with
     * `'acceptTerms' => true`: the Terms and the request that the purchase
     * start now); send the customer to `url`. A signed-in person only,
     * never an API key.
     *
     * @param array<string, mixed> $fields CreateCheckout
     * @return array<string, mixed>
     */
    public function createCheckout(array $fields, ?string $idempotencyKey = null): array
    {
        return $this->t->json('POST', '/v1/billing/checkouts', json: self::body($fields), idempotencyKey: $idempotencyKey);
    }

    /**
     * A checkout's status (by its id or the provider's).
     *
     * @return array<string, mixed>
     */
    public function getCheckout(string $id): array
    {
        return $this->t->json('GET', "/v1/billing/checkouts/" . self::p($id));
    }

    /**
     * Cancel the subscription at the end of its period.
     *
     * @return array<string, mixed>
     */
    public function cancelSubscription(): array
    {
        return $this->t->json('POST', '/v1/billing/subscription/cancel');
    }

    /**
     * Take back a scheduled cancellation.
     *
     * @return array<string, mixed>
     */
    public function uncancelSubscription(): array
    {
        return $this->t->json('POST', '/v1/billing/subscription/uncancel');
    }

    /**
     * Turn auto top-up on (`threshold` and `amount` from the plan list's choices) or off.
     *
     * @param array<string, mixed> $fields AutoTopUpSettings
     * @return array<string, mixed>
     */
    public function setAutoTopUp(array $fields): array
    {
        return $this->t->json('PUT', '/v1/billing/auto-top-up', json: self::body($fields));
    }

    /**
     * A link to the provider's customer portal (payment methods, invoices, receipts).
     *
     * @return array<string, mixed>
     */
    public function portal(): array
    {
        return $this->t->json('GET', '/v1/billing/portal');
    }

    /**
     * Charge the configured auto top-up amount now (under the day's cap).
     *
     * @return array<string, mixed>
     */
    public function retryAutoTopUp(): array
    {
        return $this->t->json('POST', '/v1/billing/auto-top-up/retry');
    }

    /**
     * Turn the low-balance email on or off (`enabled`) and set its line (`threshold`).
     *
     * @param array<string, mixed> $fields LowBalanceAlertSettings
     * @return array<string, mixed>
     */
    public function setLowBalanceAlert(array $fields): array
    {
        return $this->t->json('PUT', '/v1/billing/low-balance-alert', json: self::body($fields));
    }

    /**
     * Renew `'interval' => 'month'` or `'year'` from the next period, at
     * `subscription.switchPrices` (needs `'acceptTerms' => true` and
     * `'noNewWithdrawalPeriodShown' => true`).
     *
     * @param array<string, mixed> $fields SetInterval
     * @return array<string, mixed>
     */
    public function setInterval(array $fields): array
    {
        return $this->t->json('POST', '/v1/billing/subscription/interval', json: self::body($fields));
    }

    /**
     * Keep the subscription by switching to yearly when monthly billing
     * is withdrawn (`subscription.yearlyOptIn`); needs `'acceptTerms' => true`
     * and `'noNewWithdrawalPeriodShown' => true`.
     *
     * @param array<string, mixed> $fields YearlyOptIn
     * @return array<string, mixed>
     */
    public function optInYearly(array $fields): array
    {
        return $this->t->json('POST', '/v1/billing/subscription/yearly-opt-in', json: self::body($fields));
    }

    /**
     * Take back a scheduled interval switch.
     *
     * @return array<string, mixed>
     */
    public function unsetInterval(): array
    {
        return $this->t->json('DELETE', '/v1/billing/subscription/interval');
    }

    /**
     * What the account spent per day (`YYYY-MM-DD` in `tz`), source and kind.
     *
     * @return array<string, mixed>
     */
    public function usage(?string $startDate = null, ?string $endDate = null, ?string $tz = null): array
    {
        return $this->t->json('GET', '/v1/billing/usage', query: ['startDate' => $startDate, 'endDate' => $endDate, 'tz' => $tz]);
    }

    /**
     * The usage report's charges one by one, newest first; `kind`
     * (`file`/`live`) and `source` (`dashboard` or a key id) filter,
     * `count` and `total` cover every match.
     *
     * @return array<string, mixed>
     */
    public function usageCharges(
        ?string $startDate = null,
        ?string $endDate = null,
        ?string $tz = null,
        ?string $kind = null,
        ?string $source = null,
        ?string $cursor = null,
        ?int $limit = null,
    ): array {
        $query = [
            'startDate' => $startDate,
            'endDate' => $endDate,
            'tz' => $tz,
            'kind' => $kind,
            'source' => $source,
            'cursor' => $cursor,
            'limit' => $limit,
        ];
        return $this->t->json('GET', '/v1/billing/usage/charges', query: $query);
    }

    /**
     * The payments history, newest first.
     *
     * @return array<string, mixed>
     */
    public function payments(?string $cursor = null, ?int $limit = null): array
    {
        return $this->t->json('GET', '/v1/billing/payments', query: ['cursor' => $cursor, 'limit' => $limit]);
    }

    /**
     * A payment's invoice or receipt: `url`, or `pending` while the provider makes it.
     *
     * @return array<string, mixed>
     */
    public function paymentDocument(string $id): array
    {
        return $this->t->json('GET', "/v1/billing/payments/" . self::p($id) . "/document");
    }

    /**
     * The saved payment methods.
     *
     * @return array<string, mixed>
     */
    public function paymentMethods(): array
    {
        return $this->t->json('GET', '/v1/billing/payment-methods');
    }

    /** Remove a saved payment method. */
    public function deletePaymentMethod(string $id): void
    {
        $this->t->json('DELETE', "/v1/billing/payment-methods/" . self::p($id));
    }

    /**
     * A link to the provider's portal to add a card or choose the default.
     *
     * @return array<string, mixed>
     */
    public function managePaymentMethods(): array
    {
        return $this->t->json('POST', '/v1/billing/payment-methods/manage');
    }

    /**
     * The invoice details.
     *
     * @return array<string, mixed>
     */
    public function details(): array
    {
        return $this->t->json('GET', '/v1/billing/details');
    }

    /**
     * Save the invoice details (`name`, `email`, `line1`, `city`, `postalCode`, `country`, `taxId`).
     *
     * @param array<string, mixed> $fields BillingDetails
     * @return array<string, mixed>
     */
    public function setDetails(array $fields): array
    {
        return $this->t->json('PUT', '/v1/billing/details', json: self::body($fields));
    }

    /**
     * What a promo code gives (`code`, `'for' => 'topup'` or `'subscribe'`).
     *
     * @param array<string, mixed> $fields CheckPromoCode
     * @return array<string, mixed>
     */
    public function checkPromoCode(array $fields): array
    {
        return $this->t->json('POST', '/v1/billing/promo-codes/check', json: self::body($fields));
    }
}
