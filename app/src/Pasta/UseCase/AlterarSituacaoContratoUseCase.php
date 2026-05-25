<?php

declare(strict_types=1);

namespace App\Pasta\UseCase;

use App\Pasta\Entity\Pasta;
use Doctrine\ORM\EntityManagerInterface;

final class AlterarSituacaoContratoUseCase
{
    private const SITUACOES_VALIDAS = ['PENDENTE', 'REGULAR'];

    public function __construct(
        private readonly EntityManagerInterface $em,
    ) {}

    public function executar(Pasta $pasta, string $situacao): void
    {
        if (!in_array($situacao, self::SITUACOES_VALIDAS, true)) {
            throw new \InvalidArgumentException(
                sprintf('Situação inválida. Valores aceitos: %s.', implode(', ', self::SITUACOES_VALIDAS))
            );
        }

        $pasta->setSituacaoContrato($situacao);
        $this->em->flush();
    }
}
