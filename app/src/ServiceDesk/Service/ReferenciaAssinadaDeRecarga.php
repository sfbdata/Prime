<?php

declare(strict_types=1);

namespace App\ServiceDesk\Service;

use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Referência de recarga (BJSD...) emitida ao abrir "Adicionar saldo", antes de existir qualquer registro.
 * O PIX mostrado leva a referência como txid; o pendente só é gravado em "Já paguei" (desenho 12), e a
 * assinatura (HMAC com o kernel.secret, presa ao escritório e ao valor) impede gravar referência alheia.
 */
final class ReferenciaAssinadaDeRecarga
{
    public function __construct(
        #[Autowire('%kernel.secret%')]
        private readonly string $segredo,
    ) {
    }

    /** @return array{referencia: string, assinatura: string} */
    public function emitir(int $tenantId, int $valorCentavos): array
    {
        $referencia = 'BJSD' . strtoupper(bin2hex(random_bytes(6)));

        return ['referencia' => $referencia, 'assinatura' => $this->assinar($tenantId, $valorCentavos, $referencia)];
    }

    public function confere(int $tenantId, int $valorCentavos, string $referencia, string $assinatura): bool
    {
        return hash_equals($this->assinar($tenantId, $valorCentavos, $referencia), $assinatura);
    }

    private function assinar(int $tenantId, int $valorCentavos, string $referencia): string
    {
        return hash_hmac('sha256', 'sd-recarga|' . $tenantId . '|' . $valorCentavos . '|' . $referencia, $this->segredo);
    }
}
