<?php

declare(strict_types=1);

namespace App\Ponto\UseCase;

use App\Entity\Tenant\Tenant;
use App\Ponto\Exception\ConfiguracaoDoPontoRecusadaException;
use App\Ponto\Repository\FeriadoRepository;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Remove um feriado pela aba Configurações (spec `docs/specs/ponto-desenho-0811.md`, E6; D4: a tela confirma antes).
 * O feriado é buscado pelo id E pelo escritório da sessão: o de outro escritório é "não encontrado" (404).
 */
final class RemoverFeriadoDoPontoUseCase
{
    public function __construct(
        private readonly FeriadoRepository $feriados,
        private readonly EntityManagerInterface $em,
    ) {
    }

    /** @throws ConfiguracaoDoPontoRecusadaException */
    public function executar(int $id, Tenant $escritorio): void
    {
        $feriado = $this->feriados->findOneBy(['id' => $id, 'tenant' => $escritorio])
            ?? throw ConfiguracaoDoPontoRecusadaException::naoEncontrada('Feriado não encontrado.');

        $this->em->remove($feriado);
        $this->em->flush();
    }
}
