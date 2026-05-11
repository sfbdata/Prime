<?php

namespace App\Expediente\UseCase;

use App\Entity\Pasta\Pasta;
use App\Entity\Tenant\Tenant;
use App\Expediente\Repository\MarcadorRepository;
use App\Repository\PastaRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

class SincronizarMarcadoresDaPastaUseCase
{
    public function __construct(
        private readonly PastaRepository $pastaRepository,
        private readonly MarcadorRepository $marcadorRepository,
        private readonly EntityManagerInterface $em,
    ) {}

    /**
     * Define exatamente quais marcadores a pasta deve ter.
     * Adiciona os novos e remove os que não estão na lista.
     * Valida que todos os marcadores pertencem ao tenant.
     *
     * @param int[] $marcadorIds IDs desejados (pode ser vazio para remover todos)
     */
    public function executar(int $pastaId, array $marcadorIds, Tenant $tenant): Pasta
    {
        $pasta = $this->pastaRepository->find($pastaId);
        if ($pasta === null) {
            throw new NotFoundHttpException('Pasta não encontrada.');
        }

        $marcadoresDesejados = [];
        foreach ($marcadorIds as $id) {
            $marcador = $this->marcadorRepository->findPorTenant((int) $id, $tenant);
            if ($marcador === null) {
                throw new NotFoundHttpException('Marcador não encontrado.');
            }
            $marcadoresDesejados[$marcador->getId()] = $marcador;
        }

        // Remove os que não estão mais na seleção.
        // Copia para array antes de iterar — evita modificar a coleção Doctrine durante o foreach.
        foreach ($pasta->getMarcadores()->toArray() as $marcadorAtual) {
            if (!isset($marcadoresDesejados[$marcadorAtual->getId()])) {
                $pasta->removeMarcador($marcadorAtual);
            }
        }

        // Adiciona os novos
        foreach ($marcadoresDesejados as $marcador) {
            $pasta->addMarcador($marcador);
        }

        $this->em->flush();

        return $pasta;
    }
}
