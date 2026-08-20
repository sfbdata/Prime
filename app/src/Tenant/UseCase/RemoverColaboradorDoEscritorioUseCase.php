<?php
declare(strict_types=1);

namespace App\Tenant\UseCase;

use App\Entity\Audit\AuditLog;
use App\Entity\Auth\UserTenant;
use App\Entity\Tenant\Tenant;
use App\Repository\UserTenantRepository;
use App\Tenant\DTO\OrigemRemocao;
use App\Tenant\DTO\RemoverColaboradorInput;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Remove uma pessoa do escritório: apaga o vínculo de verdade.
 *
 * Substitui a demissão. Atende as duas portas — o painel do admin e a saída por
 * conta própria — distinguidas por OrigemRemocao. A conta da pessoa não é tocada:
 * ela pode ser convidada de volta, e volta zerada.
 */
final class RemoverColaboradorDoEscritorioUseCase
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly UserTenantRepository $userTenantRepository,
    ) {}

    public function executar(RemoverColaboradorInput $input): void
    {
        $vinculo = $this->userTenantRepository->findAtivoPorUserETenant($input->colaborador, $input->tenant);
        if ($vinculo === null) {
            throw new \InvalidArgumentException('Vínculo ativo não encontrado.');
        }

        $this->validar($input, $vinculo);

        // Tasks 2 e 3 encaixam aqui, antes da auditoria.

        $this->registrarAuditoria($input, $vinculo);

        $this->em->remove($vinculo);
        $this->em->flush();
    }

    private function validar(RemoverColaboradorInput $input, UserTenant $vinculo): void
    {
        if ($input->origem === OrigemRemocao::Painel) {
            if ($input->executor === $input->colaborador) {
                throw new \InvalidArgumentException(
                    'Para deixar o escritório, use a opção "Sair do escritório".'
                );
            }

            // Cinto secundário: sabidamente inerte onde tenant.criado_por é nulo (spec §4).
            if ($input->tenant->getCriadoPor() === $input->colaborador) {
                throw new \InvalidArgumentException('Não é permitido remover o criador do escritório.');
            }
        }

        if ($this->ehUltimoAdmin($vinculo, $input->tenant)) {
            throw new \InvalidArgumentException(
                'Este é o último administrador do escritório. '
                . 'Promova outro administrador antes de removê-lo.'
            );
        }

        if ($input->substituto !== null
            && !$this->userTenantRepository->existeVinculoAtivo($input->substituto, $input->tenant)) {
            throw new \InvalidArgumentException('O substituto precisa ser colaborador ativo deste escritório.');
        }
    }

    private function ehUltimoAdmin(UserTenant $vinculo, Tenant $tenant): bool
    {
        $role = $vinculo->getTenantRole();
        if ($role === null || !$role->isSystem()) {
            return false;
        }

        return $this->userTenantRepository->contarAdminsAtivos($tenant) <= 1;
    }

    /**
     * O AuditLogSubscriber grava o tenant da SESSÃO, não o da rota — um super-admin sem
     * escritório selecionado produz log com tenant nulo, invisível na trilha. Por isso o
     * rastro desta ação é gravado aqui, explicitamente, com o tenant que veio da rota.
     */
    private function registrarAuditoria(RemoverColaboradorInput $input, UserTenant $vinculo): void
    {
        $log = new AuditLog();
        $log->setAction('delete')
            ->setEntityClass(UserTenant::class)
            ->setEntityId((string) $vinculo->getId())
            ->setTenantId($input->tenant->getId())
            ->setActorUserId($input->executor->getId())
            ->setActorEmail($input->executor->getEmail())
            ->setChanges([
                'colaborador_id'     => $input->colaborador->getId(),
                'colaborador_email'  => $input->colaborador->getEmail(),
                'colaborador_nome'   => $input->colaborador->getFullName(),
                'codigo_funcionario' => $vinculo->getCodigoFuncionario(),
                'data_admissao'      => $vinculo->getDataAdmissao()?->format('Y-m-d'),
                'perfil'             => $vinculo->getTenantRole()?->getName(),
                'origem'             => $input->origem->value,
                'substituto_id'      => $input->substituto?->getId(),
            ]);

        $this->em->persist($log);
    }
}
