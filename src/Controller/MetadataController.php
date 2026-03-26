<?php

declare(strict_types=1);

namespace App\Controller;

use App\Repository\PaymentMethodRepository;
use App\Repository\ProviderRepository;
use App\Utils\JsonResponse;
use App\Utils\Logger;
use Throwable;

final class MetadataController
{
    public function __construct(
        private readonly ProviderRepository $providerRepository,
        private readonly PaymentMethodRepository $paymentMethodRepository,
        private readonly Logger $logger
    ) {
    }

    public function index(): void
    {
        try {
            JsonResponse::send([
                'success' => true,
                'data' => [
                    'providers' => $this->providerRepository->getIds(),
                    'payment_methods' => $this->paymentMethodRepository->getIds(),
                ],
            ]);
        } catch (Throwable $exception) {
            $this->logger->error('Error al cargar metadatos de formulario', [
                'message' => $exception->getMessage(),
            ]);

            JsonResponse::send([
                'success' => false,
                'message' => 'No se pudieron cargar proveedores y formas de pago.',
            ], 500);
        }
    }
}
