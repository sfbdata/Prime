<?php

declare(strict_types=1);

namespace App\Recompensa\DTO;

use App\Recompensa\Entity\LancamentoDeRecompensa;

/**
 * Uma linha do extrato para o Master. Traz nome e e-mail do INDICADOR (quem recebe), nunca dado do indicado. Se o
 * indicador foi apagado (LGPD) nome e e-mail vêm nulos e a linha continua. Dinheiro em centavos com sinal.
 */
final readonly class LancamentoDoExtratoOutput
{
    public function __construct(
        public int $id,
        public \DateTimeImmutable $em,
        public ?int $userId,
        public ?string $nome,
        public ?string $email,
        public string $tipo,
        public int $valorCentavos,
        public ?string $observacao,
        public ?string $forma = null,
        public bool $temComprovante = false,
    ) {
    }

    public static function da(LancamentoDeRecompensa $l): self
    {
        $usuario = $l->getUser();

        return new self(
            (int) $l->getId(),
            $l->getCriadoEm(),
            $usuario?->getId(),
            $usuario?->getFullName(),
            $usuario?->getEmail(),
            $l->getTipo(),
            $l->getValorCentavos(),
            $l->getObservacao(),
            $l->getForma()?->value,
            $l->temComprovante(),
        );
    }

    /** @return array<string, mixed> */
    public function paraArray(): array
    {
        return [
            'id' => $this->id, 'em' => $this->em->format(\DATE_ATOM), 'userId' => $this->userId, 'nome' => $this->nome,
            'email' => $this->email, 'tipo' => $this->tipo, 'valorCentavos' => $this->valorCentavos, 'observacao' => $this->observacao,
            'forma' => $this->forma, 'temComprovante' => $this->temComprovante,
        ];
    }
}
