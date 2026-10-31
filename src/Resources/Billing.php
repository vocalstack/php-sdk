<?php

declare(strict_types=1);

namespace VocalStack\Resources;

final class Billing extends Group
{
    public function get(): array
    {
        return $this->t->json('GET', '/v1/billing');
    }

    public function plans(): array
    {
        return $this->t->json('GET', '/v1/billing/plans');
    }

    public function ledger(?string $cursor = null, ?int $limit = null): array
    {
        return $this->t->json('GET', '/v1/billing/ledger', query: ['cursor' => $cursor, 'limit' => $limit]);
    }

    public function charges(?string $cursor = null, ?int $limit = null): array
    {
        return $this->t->json('GET', '/v1/billing/charges', query: ['cursor' => $cursor, 'limit' => $limit]);
    }

    public function createCheckout(array $fields, ?string $idempotencyKey = null): array
    {
        return $this->t->json('POST', '/v1/billing/checkouts', json: self::body($fields), idempotencyKey: $idempotencyKey);
    }

    public function getCheckout(string $id): array
    {
        return $this->t->json('GET', "/v1/billing/checkouts/" . self::p($id));
    }

    public function cancelSubscription(): array
    {
        return $this->t->json('POST', '/v1/billing/subscription/cancel');
    }

    public function uncancelSubscription(): array
    {
        return $this->t->json('POST', '/v1/billing/subscription/uncancel');
    }

    public function setAutoTopUp(array $fields): array
    {
        return $this->t->json('PUT', '/v1/billing/auto-top-up', json: self::body($fields));
    }

    public function portal(): array
    {
        return $this->t->json('GET', '/v1/billing/portal');
    }

    public function retryAutoTopUp(): array
    {
        return $this->t->json('POST', '/v1/billing/auto-top-up/retry');
    }

    public function setLowBalanceAlert(array $fields): array
    {
        return $this->t->json('PUT', '/v1/billing/low-balance-alert', json: self::body($fields));
    }

    public function setInterval(array $fields): array
    {
        return $this->t->json('POST', '/v1/billing/subscription/interval', json: self::body($fields));
    }

    public function optInYearly(array $fields): array
    {
        return $this->t->json('POST', '/v1/billing/subscription/yearly-opt-in', json: self::body($fields));
    }

    public function unsetInterval(): array
    {
        return $this->t->json('DELETE', '/v1/billing/subscription/interval');
    }

    public function usage(?string $startDate = null, ?string $endDate = null, ?string $tz = null): array
    {
        return $this->t->json('GET', '/v1/billing/usage', query: ['startDate' => $startDate, 'endDate' => $endDate, 'tz' => $tz]);
    }

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

    public function payments(?string $cursor = null, ?int $limit = null): array
    {
        return $this->t->json('GET', '/v1/billing/payments', query: ['cursor' => $cursor, 'limit' => $limit]);
    }

    public function paymentDocument(string $id): array
    {
        return $this->t->json('GET', "/v1/billing/payments/" . self::p($id) . "/document");
    }

    public function paymentMethods(): array
    {
        return $this->t->json('GET', '/v1/billing/payment-methods');
    }

    public function deletePaymentMethod(string $id): void
    {
        $this->t->json('DELETE', "/v1/billing/payment-methods/" . self::p($id));
    }

    public function managePaymentMethods(): array
    {
        return $this->t->json('POST', '/v1/billing/payment-methods/manage');
    }

    public function details(): array
    {
        return $this->t->json('GET', '/v1/billing/details');
    }

    public function setDetails(array $fields): array
    {
        return $this->t->json('PUT', '/v1/billing/details', json: self::body($fields));
    }

    public function checkPromoCode(array $fields): array
    {
        return $this->t->json('POST', '/v1/billing/promo-codes/check', json: self::body($fields));
    }
}
