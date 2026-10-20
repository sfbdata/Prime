<?php

declare(strict_types=1);

namespace App\Contribua\DTO;

use App\Contribua\Entity\CobrancaPixDaConta;
use App\Contribua\UseCase\RegistrarApoioUseCase;

/** O boleto que a tela mostra: linha digitável, PDF, vencimento e o Pix copia e cola do boleto (se veio). */
final readonly class BoletoDaConta
{
    public function __construct(
        public string $linhaDigitavel,
        public string $url,
        public string $vencimento,
        public string $valor,
        public ?string $pixCopiaECola,
    ) {
    }

    public static function de(CobrancaPixDaConta $boleto): self
    {
        return new self(
            (string) $boleto->getLinhaDigitavel(),
            (string) $boleto->getUrlDoBoleto(),
            $boleto->getVencimento()->format('d/m/Y'),
            RegistrarApoioUseCase::reais($boleto->getValorCentavos()),
            $boleto->getCopiaECola(),
        );
    }
}
