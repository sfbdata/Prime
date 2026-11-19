<?php

declare(strict_types=1);

namespace App\Tarefa\DTO;

use App\Entity\Auth\User;
use App\Entity\Tarefa\Tarefa;
use App\Tarefa\Enum\EtapaDaPasta;

/**
 * A meta que acabou de ser gravada: o id (destino da tela), o número no escritório e o que a resposta do
 * conector repete para a pessoa conferir (prazo, etapa e responsáveis).
 */
final class MetaCriadaOutput
{
    /**
     * @param list<array{id: int, nome: string}> $responsaveis
     */
    public function __construct(
        public readonly int $id,
        public readonly ?int $numero,
        public readonly string $titulo,
        public readonly ?\DateTimeImmutable $prazo,
        public readonly EtapaDaPasta $etapa,
        public readonly array $responsaveis,
    ) {
    }

    public static function de(Tarefa $meta): self
    {
        $responsaveis = [];
        foreach ($meta->getResponsaveis() as $pessoa) {
            /** @var User $pessoa */
            $responsaveis[] = ['id' => (int) $pessoa->getId(), 'nome' => (string) $pessoa->getFullName()];
        }

        return new self(
            (int) $meta->getId(),
            $meta->getNumero(),
            $meta->getTitulo(),
            $meta->getPrazo(),
            $meta->getEtapa(),
            $responsaveis,
        );
    }
}
