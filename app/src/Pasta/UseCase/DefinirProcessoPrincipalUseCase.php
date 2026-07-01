<?php

declare(strict_types=1);

namespace App\Pasta\UseCase;

use App\Pasta\Entity\Pasta;
use App\Processo\Entity\Processo;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Define qual processo vinculado é o principal da pasta.
 *
 * Quem: usuário com permissão de edição na pasta. O quê: escolher o processo que representa
 * a pasta nos resumos (barra lateral, listagem, timeline, peticionar). O processo precisa já
 * estar vinculado — caso contrário a entidade lança \DomainException. Marcar um novo principal
 * zera o anterior (exatamente um principal por pasta).
 */
final class DefinirProcessoPrincipalUseCase
{
    public function __construct(
        private readonly EntityManagerInterface $em,
    ) {
    }

    public function executar(Pasta $pasta, Processo $processo): void
    {
        $pasta->definirProcessoPrincipal($processo);
        $this->em->flush();
    }
}
