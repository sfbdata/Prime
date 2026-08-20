<?php
declare(strict_types=1);

namespace App\Tenant\UseCase;

use App\Entity\Audit\AuditLog;
use App\Entity\Auth\User;
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

        // Spec §3.3 ("Sequência, em uma transação"): passarOBastao() dispara entre 7 e 11
        // statements de SQL nativo (depende de haver substituto) que não passam pelo Unit of
        // Work do Doctrine — sem transação explícita, uma falha no meio deixa parte das
        // responsabilidades transferida, o resto intacto, e o vínculo nem chega a ser removido.
        // Mesmo padrão de App\Tenant\UseCase\PurgarEscritorioUseCase
        // (beginTransaction/commit/rollBack+rethrow).
        $conn = $this->em->getConnection();
        $conn->beginTransaction();

        try {
            $this->passarOBastao($input);
            $this->revogarAcessos($input);

            $this->registrarAuditoria($input, $vinculo);

            $this->em->remove($vinculo);
            $this->em->flush();

            $conn->commit();
        } catch (\Throwable $e) {
            $conn->rollBack();

            throw $e;
        }
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

        if ($input->substituto !== null) {
            // existeVinculoAtivo() devolve true para a própria pessoa removida — o vínculo só
            // cai no fim desta operação. Sem esta trava, a "transferência" colapsa em no-op e
            // Pasta.responsavel/Chamado.responsavel ficam apontando para quem acabou de deixar
            // de ser colaborador: o defeito exato que esta feature existe para evitar.
            if ($input->substituto === $input->colaborador) {
                throw new \InvalidArgumentException(
                    'O substituto não pode ser a própria pessoa removida.'
                );
            }

            if (!$this->userTenantRepository->existeVinculoAtivo($input->substituto, $input->tenant)) {
                throw new \InvalidArgumentException('O substituto precisa ser colaborador ativo deste escritório.');
            }
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
     * Transfere (com substituto) ou desatribui (sem substituto) tudo que estava no nome do
     * colaborador removido, escopado ao tenant da remoção: Pasta/Chamado (responsável direto)
     * e os quatro vínculos N:N.
     *
     * TUDO aqui é SQL nativo de propósito, porque SQL nativo é o único que NÃO herda o
     * TenantFilter. Em DQL a história é outra: `Pasta` e `Chamado` implementam TenantAware, e
     * o SqlWalker aplica os filtros do alias raiz também em UPDATE (walkUpdateStatement →
     * walkWhereClause), não só em SELECT. Pela porta da saída por conta própria a rota é
     * `/escritorio/{id}/sair`, SEM {tenantId} — o TenantUrlScopeListener não reaponta nada e o
     * filtro fica preso ao tenant da SESSÃO. Quem está logado no escritório A e sai do B
     * geraria `... AND tenant_id = B AND pasta.tenant_id = A`: SQL válido, zero linhas,
     * nenhum erro. O vínculo cairia (isto aqui é nativo) e as pastas e chamados do B ficariam
     * no nome de quem não é mais colaborador — o defeito exato que esta feature existe para
     * evitar, e silencioso. Com SQL nativo o resultado não depende de para onde o filtro da
     * sessão aponta: a única fronteira é o tenant_id explícito de cada statement, sempre na
     * tabela DONA do dado.
     */
    private function passarOBastao(RemoverColaboradorInput $input): void
    {
        $uid = $input->colaborador->getId();
        $tid = $input->tenant->getId();
        $sub = $input->substituto?->getId();

        $conn = $this->em->getConnection();

        // Responsável direto: as duas tabelas carregam responsavel_id e tenant_id.
        foreach (['pasta', 'chamado'] as $tabela) {
            if ($sub !== null) {
                $conn->executeStatement(
                    "UPDATE {$tabela} SET responsavel_id = :sub
                     WHERE responsavel_id = :uid AND tenant_id = :tid",
                    ['sub' => $sub, 'uid' => $uid, 'tid' => $tid]
                );

                continue;
            }

            $conn->executeStatement(
                "UPDATE {$tabela} SET responsavel_id = NULL
                 WHERE responsavel_id = :uid AND tenant_id = :tid",
                ['uid' => $uid, 'tid' => $tid]
            );
        }

        // Cada trio: tabela de vínculo, coluna do dono, tabela dona que carrega o tenant_id.
        $vinculos = [
            ['tarefa_responsaveis',       'tarefa_id',       'tarefa'],
            ['evento_participante',       'evento_id',       'evento'],
            ['kanban_card_responsavel',   'kanban_card_id',  'kanban_card'],
            ['kanban_board_participante', 'kanban_board_id', 'kanban_board'],
        ];

        foreach ($vinculos as [$tabela, $coluna, $dona]) {
            if ($sub !== null) {
                // NOT IN global de propósito: evita colisão de PK quando o substituto já participa.
                $conn->executeStatement(
                    "INSERT INTO {$tabela} ({$coluna}, user_id)
                     SELECT {$coluna}, :sub FROM {$tabela}
                     WHERE user_id = :uid
                       AND {$coluna} IN (SELECT id FROM {$dona} WHERE tenant_id = :tid)
                       AND {$coluna} NOT IN (SELECT {$coluna} FROM {$tabela} WHERE user_id = :sub2)",
                    ['sub' => $sub, 'uid' => $uid, 'tid' => $tid, 'sub2' => $sub]
                );
            }

            $conn->executeStatement(
                "DELETE FROM {$tabela}
                 WHERE user_id = :uid
                   AND {$coluna} IN (SELECT id FROM {$dona} WHERE tenant_id = :tid)",
                ['uid' => $uid, 'tid' => $tid]
            );
        }

        // Quadro sem dono some para o escritório inteiro: KanbanBoardRepository só lista
        // para criador ou participante, e não existe visão de admin (spec §8).
        $herdeiro = $this->resolverHerdeiroDosQuadros($input);
        if ($herdeiro !== null) {
            $conn->executeStatement(
                'UPDATE kanban_board SET criado_por_id = :herdeiro
                 WHERE criado_por_id = :uid AND tenant_id = :tid',
                ['herdeiro' => $herdeiro->getId(), 'uid' => $uid, 'tid' => $tid]
            );
        }
    }

    /**
     * A quem vão os quadros que o colaborador removido criou: o substituto se houver; senão,
     * pela porta do painel, o próprio executor — mas SÓ se ele tiver vínculo ativo com este
     * escritório. O controller deixa um super-admin sem vínculo executar a remoção (o guard
     * de permissão bypassa por ROLE_SUPER_ADMIN), e dar os quadros a quem não é colaborador
     * esconde o quadro do escritório inteiro: o KanbanBoardRepository só lista para criador
     * ou participante, e não existe visão de admin (spec §8). Sem vínculo, o executor cai no
     * mesmo caminho da porta da saída — o admin ativo de vínculo mais antigo.
     */
    private function resolverHerdeiroDosQuadros(RemoverColaboradorInput $input): ?User
    {
        if ($input->substituto !== null) {
            return $input->substituto;
        }

        if (
            $input->origem === OrigemRemocao::Painel
            && $this->userTenantRepository->existeVinculoAtivo($input->executor, $input->tenant)
        ) {
            return $input->executor;
        }

        return $this->userTenantRepository
            ->findAdminAtivoMaisAntigo($input->tenant, $input->colaborador)?->getUser();
    }

    /**
     * Corta o acesso do colaborador removido naquele escritório (spec §3.3, item 3): permissão
     * item a item, pedido de acesso pendente, liberação de geocerca do ponto (D5) e notificação.
     * SQL nativo NÃO herda o TenantFilter: cada DELETE carrega tenant_id explícito, senão
     * remover alguém de um escritório apagaria o acesso dela em outro.
     */
    private function revogarAcessos(RemoverColaboradorInput $input): void
    {
        $uid = $input->colaborador->getId();
        $tid = $input->tenant->getId();

        $conn = $this->em->getConnection();

        foreach (['resource_access', 'access_request', 'home_office_config'] as $tabela) {
            $conn->executeStatement(
                "DELETE FROM {$tabela} WHERE user_id = :uid AND tenant_id = :tid",
                ['uid' => $uid, 'tid' => $tid]
            );
        }

        // A notificação usa usuario_id, não user_id.
        $conn->executeStatement(
            'DELETE FROM notificacao WHERE usuario_id = :uid AND tenant_id = :tid',
            ['uid' => $uid, 'tid' => $tid]
        );
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
