<?php

declare(strict_types=1);

namespace App\Ponto\DTO;

use App\Ponto\Entity\AceiteDaFolhaDoPonto;
use App\Ponto\Enum\SituacaoDoAceiteDaFolha;

/**
 * O carimbo do aceite acima da linha "Assinatura do Empregado" (desenho 16 L697): "Aceite eletrônico em dd/mm/aaaa,
 * hh:mm:ss" em verde ou "Aceite com ressalva em ..." em vermelho, e "Ressalva: <texto>" em vermelho. Só vai para a
 * folha o aceite que vale (o último) e quando a folha não mudou depois dele (spec ponto-desenho-0811, E16, decisões 1
 * e 3).
 */
final readonly class CarimboDoAceiteNaFolha
{
    public function __construct(
        public SituacaoDoAceiteDaFolha $situacao,
        public \DateTimeImmutable $aceitoEm,
        public ?string $ressalva,
    ) {
    }

    public static function doAceite(AceiteDaFolhaDoPonto $aceite): self
    {
        return new self($aceite->getSituacao(), $aceite->getAceitoEm(), $aceite->getRessalva());
    }

    public function concordou(): bool
    {
        return $this->situacao === SituacaoDoAceiteDaFolha::Concordo;
    }

    public function texto(): string
    {
        $quando = $this->aceitoEm->format('d/m/Y, H:i:s');

        return $this->concordou() ? 'Aceite eletrônico em ' . $quando : 'Aceite com ressalva em ' . $quando;
    }

    /** "Ressalva: <texto>", ou null no concordo. O PDF corta numa linha (decisão 19). */
    public function textoDaRessalva(): ?string
    {
        return $this->ressalva === null ? null : 'Ressalva: ' . $this->ressalva;
    }
}
