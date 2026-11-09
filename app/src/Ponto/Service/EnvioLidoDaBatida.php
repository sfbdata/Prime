<?php

declare(strict_types=1);

namespace App\Ponto\Service;

use App\Ponto\Entity\RegistroPonto;

/**
 * O envio da batida depois de lido por {@see EnvioDaBatida} (spec ponto-desenho-0811 §5 E13 e E14): o identificador do
 * envio, a hora do aparelho, a chegada ao servidor e o momento da batida, em que as regras são avaliadas e que vai
 * para a folha. Na batida normal o momento é a chegada; na guardada sem internet, a hora do aparelho.
 *
 * As duas recusas valem em momentos diferentes. `recusaDoPedido` é o pedido malformado: recusa antes de procurar a
 * batida que já entrou com o mesmo envio. `recusaDaFila` (outra conta ou escritório, além do teto de idade, relógio
 * adiantado) só vale depois dessa procura: a nova tentativa de uma batida que já entrou responde "já registrada" mesmo
 * que o teto tenha passado no meio do caminho.
 */
final readonly class EnvioLidoDaBatida
{
    public function __construct(
        public \DateTimeImmutable $chegada,
        public \DateTimeImmutable $momento,
        public ?string $idEnvio = null,
        public ?\DateTimeImmutable $horaAparelho = null,
        public bool $semInternet = false,
        public ?RecusaDoEnvio $recusaDoPedido = null,
        public ?RecusaDoEnvio $recusaDaFila = null,
    ) {
    }

    public static function recusado(\DateTimeImmutable $chegada, RecusaDoEnvio $recusa): self
    {
        return new self($chegada, $chegada, recusaDoPedido: $recusa);
    }

    /** Leva à batida o momento, as duas horas, o identificador do envio e a marca "enviada sem internet". */
    public function gravarEm(RegistroPonto $registro): void
    {
        $registro->setDataHora(\DateTime::createFromImmutable($this->momento));
        $registro->setHoraChegada($this->chegada);
        $registro->setHoraAparelho($this->horaAparelho);
        $registro->setIdEnvio($this->idEnvio);
        $registro->setEnviadaSemInternet($this->semInternet);
    }
}
