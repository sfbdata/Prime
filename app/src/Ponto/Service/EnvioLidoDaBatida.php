<?php

declare(strict_types=1);

namespace App\Ponto\Service;

use App\Ponto\Entity\RegistroPonto;

/**
 * O envio da batida depois de lido por {@see EnvioDaBatida} (spec ponto-desenho-0811 §5 E13): o identificador do
 * envio, a hora do aparelho, a chegada ao servidor e o momento da batida, em que as regras são avaliadas e que vai
 * para a folha.
 *
 * `recusaDoPedido` é o pedido malformado: recusa antes de procurar a batida que já entrou com o mesmo envio.
 */
final readonly class EnvioLidoDaBatida
{
    public function __construct(
        public \DateTimeImmutable $chegada,
        public \DateTimeImmutable $momento,
        public ?string $idEnvio = null,
        public ?\DateTimeImmutable $horaAparelho = null,
        public ?RecusaDoEnvio $recusaDoPedido = null,
    ) {
    }

    public static function recusado(\DateTimeImmutable $chegada, RecusaDoEnvio $recusa): self
    {
        return new self($chegada, $chegada, recusaDoPedido: $recusa);
    }

    /** Leva à batida o momento, as duas horas e o identificador do envio. */
    public function gravarEm(RegistroPonto $registro): void
    {
        $registro->setDataHora(\DateTime::createFromImmutable($this->momento));
        $registro->setHoraChegada($this->chegada);
        $registro->setHoraAparelho($this->horaAparelho);
        $registro->setIdEnvio($this->idEnvio);
        $registro->setEnviadaSemInternet(false);
    }
}
