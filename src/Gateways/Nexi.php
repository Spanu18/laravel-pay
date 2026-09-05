<?php

declare(strict_types=1);

namespace LaravelPay\LaravelPay\Gateways;

use LaravelPay\LaravelPay\Contracts\CheckoutUrls;
use LaravelPay\LaravelPay\Contracts\PaymentGatewayInterface;
use LaravelPay\LaravelPay\DTO\CheckoutResult;
use LaravelPay\LaravelPay\DTO\PaymentRequest;
use LaravelPay\LaravelPay\DTO\PaymentResult;
use Psr\Log\LoggerInterface;
use Throwable;

class Nexi implements PaymentGatewayInterface
{
    protected const LIVE_URL = 'https://ecommerce.nexi.it/ecomm/ecomm/XPayLight';

    protected const SANDBOX_URL = 'https://int-ecommerce.nexi.it/ecomm/ecomm/XPayLight';

    public function __construct(
        protected bool $sandbox,
        protected LoggerInterface $logger,
        protected CheckoutUrls $checkoutUrls,
    ) {}

    protected function hostedUrl(): string
    {
        return $this->sandbox ? self::SANDBOX_URL : self::LIVE_URL;
    }

    protected function computeMac(string $codTrans, string $divisa, int $importo, string $macKey): string
    {
        return hash('sha1', "codTrans={$codTrans}divisa={$divisa}importo={$importo}{$macKey}");
    }

    public function createCredentials(string $code): array
    {
        $parts = explode('|', $code, 2);

        return [
            'merchant_id' => $parts[0],
            'email' => null,
            'credentials' => [
                'alias' => $parts[0],
                'mac_key' => $parts[1] ?? '',
            ],
        ];
    }

    public function createPayment(PaymentRequest $request): PaymentResult
    {
        try {
            $alias = $request->to->credentials['alias'] ?? '';
            $macKey = $request->to->credentials['mac_key'] ?? '';
            $divisa = strtoupper($request->currency);
            $importo = (int) round($request->amount * 100);
            $codTrans = 'ref'.substr($request->reference, 0, 16);

            $mac = $this->computeMac($codTrans, $divisa, $importo, $macKey);

            $params = http_build_query([
                'alias' => $alias,
                'importo' => $importo,
                'divisa' => $divisa,
                'codTrans' => $codTrans,
                'url' => $this->checkoutUrls->success($request, ['codTrans' => $codTrans]),
                'url_back' => $this->checkoutUrls->cancel(),
                'mac' => $mac,
                'languageId' => 'ITA',
                'note1' => 'Ref '.$request->reference,
            ]);

            return new PaymentResult(
                success: true,
                clientSecret: $this->hostedUrl().'?'.$params,
                transactionId: $codTrans,
                status: 'created',
                reference: $request->reference,
            );
        } catch (Throwable $e) {
            $this->logger->error('Nexi createPayment error: '.$e->getMessage(), ['exception' => $e]);

            return new PaymentResult(false, null, null, 'failed', null);
        }
    }

    public function refundPayment(string $transactionId, array $credentials, ?float $amount = null): bool
    {
        // Nexi XPay refunds require a server-to-server storno call not yet
        // automated for this contract version — requires the Nexi operator
        // portal or a REST upgrade. Matches pre-migration behavior exactly.
        $this->logger->info('Nexi refund requested', [
            'transaction_id' => $transactionId,
            'amount' => $amount,
        ]);

        return false;
    }

    public function deactivateCheckout(string $transactionId, array $credentials): bool
    {
        return true; // Nexi hosted links are stateless URLs; nothing to deactivate.
    }

    public function getCheckout(string $checkoutId, array $credentials): ?CheckoutResult
    {
        return new CheckoutResult(
            providerId: $checkoutId,
            status: 'unknown',
            currency: 'EUR',
            amount: 0,
            timestamp: '',
            transactions: [],
            response: [],
        );
    }

    public function refreshCredentials(string $refreshToken): array
    {
        return []; // static alias/mac_key credentials — nothing to refresh.
    }
}
