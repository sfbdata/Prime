<?php

declare(strict_types=1);

namespace App\Expediente\UseCase;

use App\Entity\Auth\User;
use App\Entity\Permission\AccessRequest;
use App\Pasta\Entity\Pasta;
use App\Entity\Tenant\Tenant;
use App\Pasta\Exception\SemPermissaoParaEditarPastaException;
use App\Service\PermissionChecker;
use App\Expediente\Repository\MarcadorRepository;
use App\Pasta\Repository\PastaRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Os marcadores da pasta são classificação compartilhada (painéis, filtros e contagens do trilho do Expediente):
 * trocá-los é EDITAR a pasta, como renomear e mudar a situação (auditoria 3.10/3.11). A guarda mora aqui para
 * valer nas duas rotas, a do Expediente e a das Demandas.
 */
class SincronizarMarcadoresDaPastaUseCase
{
    public function __construct(
        private readonly PastaRepository $pastaRepository,
        private readonly MarcadorRepository $marcadorRepository,
        private readonly EntityManagerInterface $em,
        private readonly PermissionChecker $permissionChecker,
    ) {}

    /**
     * Define exatamente quais marcadores a pasta deve ter.
     * Adiciona os novos e remove os que não estão na lista.
     * Valida que todos os marcadores pertencem ao tenant.
     *
     * Pasta de outro escritório: 404, como se não existisse. Sem permissão de editar a pasta:
     * SemPermissaoParaEditarPastaException (403 nos controllers), antes de qualquer gravação.
     *
     * @param int[] $marcadorIds IDs desejados (pode ser vazio para remover todos)
     */
    public function executar(int $pastaId, array $marcadorIds, User $usuario, Tenant $tenant): Pasta
    {
        $pasta = $this->pastaRepository->findOneBy(['id' => $pastaId, 'tenant' => $tenant]);
        if ($pasta === null) {
            throw new NotFoundHttpException('Pasta não encontrada.');
        }

        if (!$this->permissionChecker->canAccessResource($usuario, $tenant, AccessRequest::RESOURCE_PASTA, $pastaId, AccessRequest::ACTION_EDIT)) {
            throw new SemPermissaoParaEditarPastaException('Sem permissão para editar esta pasta.');
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
