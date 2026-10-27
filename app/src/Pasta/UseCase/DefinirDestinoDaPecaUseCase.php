<?php

declare(strict_types=1);

namespace App\Pasta\UseCase;

use App\Pasta\Entity\PastaDocumento;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Processo de destino de uma peça no Peticionar (desenho 07, seletor do cabeçalho): o principal
 * ou um apensado da PRÓPRIA pasta. Nulo volta ao principal. O vínculo de outra pasta é recusado.
 */
final class DefinirDestinoDaPecaUseCase
{
    public function __construct(private readonly EntityManagerInterface $em)
    {
    }

    /** @throws \InvalidArgumentException quando o vínculo não é da pasta da peça */
    public function executar(PastaDocumento $documento, ?int $vinculoId): void
    {
        $vinculo = null;
        if ($vinculoId !== null) {
            foreach ($documento->getPasta()?->getPastaProcessos() ?? [] as $candidato) {
                if ($candidato->getId() === $vinculoId) {
                    $vinculo = $candidato;
                    break;
                }
            }
            if ($vinculo === null) {
                throw new \InvalidArgumentException('Esse processo não pertence a esta pasta.');
            }
        }

        $documento->setProcessoDestino($vinculo);
        $this->em->flush();
    }
}
