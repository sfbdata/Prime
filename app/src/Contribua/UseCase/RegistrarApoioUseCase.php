<?php

declare(strict_types=1);

namespace App\Contribua\UseCase;

use App\Contribua\DTO\Solicitante;
use App\Contribua\Entity\Apoio;
use App\Contribua\Repository\ApoioRepository;
use App\Contribua\Service\AvisoAoMaster;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

/**
 * "Já contribuí, registrar meu apoio" (desenho 08): o escritório informa o PIX que fez. Um por mês
 * (30 dias), como no desenho; o Master é avisado para conferir no extrato.
 */
final class RegistrarApoioUseCase
{
    public const JA_REGISTRADO = 'Seu apoio deste mês já está registrado. Obrigado!';

    public function __construct(
        private readonly ApoioRepository $apoios,
        private readonly AvisoAoMaster $avisoAoMaster,
        private readonly UrlGeneratorInterface $urls,
    ) {
    }

    public function executar(Solicitante $quem, int $valorCentavos): string
    {
        if ($this->apoios->apoiouDesde($quem->tenantId(), new \DateTimeImmutable('-30 days'))) {
            throw new \DomainException(self::JA_REGISTRADO);
        }

        $this->apoios->salvar(new Apoio($quem->escritorio, $quem->usuario, $valorCentavos), flush: true);
        $this->avisoAoMaster->avisar(
            $quem->escritorio,
            'contribua.apoio',
            sprintf('Apoio registrado: %s (conta Nº %s) informou um apoio de %s. Confira no extrato.', $quem->escritorio->getName(), $quem->numeroDaConta(), self::reais($valorCentavos)),
            $this->urls->generate('contribua_index') . '#apoie',
        );

        return sprintf('Obrigado pelo apoio! Ele foi registrado para a conta Nº %s.', $quem->numeroDaConta());
    }

    public static function reais(int $centavos): string
    {
        return 'R$ ' . number_format($centavos / 100, 2, ',', '.');
    }
}
