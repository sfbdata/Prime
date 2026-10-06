<?php

declare(strict_types=1);

namespace App\Auditoria\UseCase;

use App\Entity\Audit\AuditLog;
use App\Repository\AuditLogRepository;
use App\Shared\Contract\Descartavel;
use App\Shared\Doctrine\Filter\AcessoALixeira;
use Doctrine\ORM\EntityManagerInterface;

final class DesfazerAlteracaoAuditLogUseCase
{
    /** Entidades de risco BAIXO — únicas reversíveis. */
    private const ENTIDADES_REVERSIVEIS = [
        \App\Pasta\Entity\Pasta::class,
        \App\Pasta\Entity\PastaDocumento::class,
        \App\Pasta\Entity\PastaSecao::class,
        \App\Pasta\Entity\PastaChecklistItem::class,
        \App\Pasta\Entity\PastaMensagem::class,
        \App\Pasta\Entity\PastaObservacaoDetalhes::class,
        \App\Pasta\Entity\PrioridadePasta::class,
        \App\Entity\Tarefa\Tarefa::class,
        \App\Entity\Tarefa\TarefaMensagem::class,
        \App\Entity\ServiceDesk\Chamado::class,
        \App\Entity\ServiceDesk\ChamadoAnexo::class,
        \App\Entity\ServiceDesk\ChamadoInteracao::class,
        \App\Entity\Agenda\Evento::class,
        \App\Entity\Agenda\LegendaCor::class,
        \App\Entity\Notificacao::class,
        \App\Entity\Tenant\Cargo::class,
        \App\Entity\Tenant\Lotacao::class,
        \App\Entity\Tenant\Sede::class,
    ];

    /**
     * Campos da lixeira (D7): a ida e a volta não se desfazem por aqui — as entidades não têm
     * setter para eles de propósito, então o desfazer "daria certo" sem mudar nada. O caminho é
     * a lixeira da pasta (restaurar), e a mensagem aponta para lá.
     */
    private const CAMPOS_DA_LIXEIRA = ['excluidoEm', 'excluidoPor'];

    public const MENSAGEM_ITEM_NA_LIXEIRA = 'Este item está na lixeira da pasta; restaure-o antes de desfazer a alteração.';
    public const MENSAGEM_ALTERACAO_DA_LIXEIRA = 'A ida e a volta da lixeira não se desfazem por aqui: use a lixeira da pasta.';

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly AuditLogRepository $auditLogRepository,
        private readonly AcessoALixeira $lixeira,
    ) {}

    public function podeReverter(AuditLog $log): bool
    {
        return $log->getAction() === 'update'
            && in_array($log->getEntityClass(), self::ENTIDADES_REVERSIVEIS, true)
            && $log->getEntityId() !== null;
    }

    public function executar(int $auditLogId, int $tenantId): DesfazerResultado
    {
        $log = $this->auditLogRepository->find($auditLogId);

        if ($log === null || $log->getTenantId() !== $tenantId) {
            return new DesfazerResultado(false, 'Registro não encontrado.');
        }

        if (!$this->podeReverter($log)) {
            return new DesfazerResultado(false, 'Esta alteração não pode ser desfeita automaticamente.');
        }

        $changes = $log->getChanges() ?? [];
        $diff = $changes['diff']['changes'] ?? [];

        if (is_array($diff) && array_intersect(self::CAMPOS_DA_LIXEIRA, array_keys($diff)) !== []) {
            return new DesfazerResultado(false, self::MENSAGEM_ALTERACAO_DA_LIXEIRA);
        }

        $entity = $this->em->find($log->getEntityClass(), $log->getEntityId());

        if ($entity === null) {
            // O `find()` passa pelo LixeiraFilter: um documento/subpasta na lixeira "não existe"
            // para ele. Distinguir aqui evita o diagnóstico errado — a linha está lá, só escondida.
            if ($this->estaNaLixeira($log->getEntityClass(), $log->getEntityId())) {
                return new DesfazerResultado(false, self::MENSAGEM_ITEM_NA_LIXEIRA);
            }

            return new DesfazerResultado(false, 'Entidade não existe mais.');
        }

        if ($diff === []) {
            return new DesfazerResultado(false, 'Nada a reverter.');
        }

        $truncado = false;

        foreach ($diff as $campo => $fieldDiff) {
            if (str_contains($campo, '[+:') || str_contains($campo, '[-:')) {
                continue;
            }

            $from = $fieldDiff['from'] ?? null;

            if (is_array($from) && isset($from['class'], $from['id'])) {
                if ($from['id'] === null) {
                    continue;
                }

                $realClass = str_replace('Proxies\\__CG__\\', '', (string) $from['class']);
                $related = $this->em->find($realClass, $from['id']);

                if ($related === null) {
                    continue;
                }

                $setter = 'set' . ucfirst($campo);
                if (method_exists($entity, $setter)) {
                    $entity->$setter($related);
                }
            } else {
                if (is_string($from) && str_ends_with($from, '…')) {
                    $truncado = true;
                }

                $setter = 'set' . ucfirst($campo);
                if (method_exists($entity, $setter)) {
                    $entity->$setter($from);
                }
            }
        }

        $this->em->flush();

        return new DesfazerResultado(sucesso: true, truncado: $truncado);
    }

    /** Só para entidades `Descartavel`; nas outras o `find()` nulo é mesmo "não existe mais". */
    private function estaNaLixeira(string $classe, string $id): bool
    {
        if (!is_a($classe, Descartavel::class, true)) {
            return false;
        }

        $item = $this->lixeira->comLixeiraVisivel(fn (): ?object => $this->em->find($classe, $id));

        return $item instanceof Descartavel && $item->estaNaLixeira();
    }
}
