<?php

namespace App\Tarefa\Service;

use App\Entity\Tarefa\Tarefa;
use App\Pasta\DTO\TimelineItemDTO;
use App\Pasta\DTO\TimelineItemType;
use App\Repository\AuditLogRepository;
use App\Repository\TarefaMensagemRepository;
use App\Repository\UserRepository;

class TarefaTimelineAssembler
{
    private const FIELD_LABELS = [
        'titulo'        => 'Título',
        'descricao'     => 'Descrição',
        'status'        => 'Status',
        'prazo'         => 'Prazo',
        'dataConclusao' => 'Data de conclusão',
        'dataCriacao'   => 'Data de criação',
        'responsavel'   => 'Responsável',
    ];

    private const STATUS_LABELS = [
        'pendente'   => 'Pendente',
        'em_revisao' => 'Para Revisão',
        'concluida'  => 'Concluída',
    ];

    /** @var array<int, string|null> */
    private array $nomeCache = [];

    public function __construct(
        private readonly TarefaMensagemRepository $mensagemRepository,
        private readonly AuditLogRepository $auditLogRepository,
        private readonly UserRepository $userRepository,
    ) {}

    /**
     * @return TimelineItemDTO[]
     */
    public function montar(Tarefa $tarefa, int $tenantId, int $limit = 150): array
    {
        $items = [];

        // Mensagens manuais da tarefa
        foreach ($tarefa->getMensagens() as $msg) {
            $items[] = new TimelineItemDTO(
                tipo:       TimelineItemType::MENSAGEM,
                dataHora:   $msg->getCriadoEm(),
                titulo:     'Mensagem',
                detalhe:    $msg->getMensagem(),
                autorNome:  $msg->getUsuario()?->getFullName(),
                autorEmail: $msg->getUsuario()?->getEmail(),
                icone:      'bi-chat-left-text',
                badgeCss:   'text-bg-info',
            );
        }

        // Eventos de auditoria
        $rows = $this->auditLogRepository->findForTarefaTimeline(
            (int) $tarefa->getId(),
            $tenantId,
            $limit
        );

        foreach ($rows as $row) {
            $dto = $this->buildFromAuditRow($row);
            if ($dto !== null) {
                $items[] = $dto;
            }
        }

        usort($items, static fn(TimelineItemDTO $a, TimelineItemDTO $b) => $b->dataHora <=> $a->dataHora);

        return array_slice($items, 0, $limit);
    }

    private function buildFromAuditRow(array $row): ?TimelineItemDTO
    {
        $entityClass = (string) ($row['entity_class'] ?? '');
        $action      = (string) ($row['action'] ?? '');
        $changes     = isset($row['changes']) && is_string($row['changes'])
            ? json_decode($row['changes'], true)
            : null;

        [$titulo, $icone, $badgeCss, $detalhe] = $this->resolveEventoVisual($entityClass, $action, $changes);

        if ($titulo === null) {
            return null;
        }

        $createdAt = new \DateTimeImmutable((string) ($row['created_at'] ?? 'now'));

        $actorUserId = isset($row['actor_user_id']) && $row['actor_user_id'] !== '' && $row['actor_user_id'] !== null
            ? (int) $row['actor_user_id']
            : null;

        return new TimelineItemDTO(
            tipo:       TimelineItemType::EVENTO,
            dataHora:   $createdAt,
            titulo:     $titulo,
            detalhe:    $detalhe,
            autorNome:  $this->resolverNomeAtor($actorUserId),
            autorEmail: isset($row['actor_email']) && $row['actor_email'] !== '' ? (string) $row['actor_email'] : null,
            icone:      $icone,
            badgeCss:   $badgeCss,
        );
    }

    /**
     * @return array{string|null, string, string, string|null}
     */
    private function resolveEventoVisual(string $entityClass, string $action, ?array $changes): array
    {
        return match (true) {
            // ── Tarefa ──────────────────────────────────────────────────
            str_ends_with($entityClass, '\Tarefa') && $action === 'create' => [
                'Tarefa criada',
                'bi-list-task',
                'text-bg-success',
                null,
            ],
            str_ends_with($entityClass, '\Tarefa') && $action === 'update' => [
                ...$this->resolverAtualizacaoTarefa($changes),
            ],
            str_ends_with($entityClass, '\Tarefa') && $action === 'delete' => [
                'Tarefa removida',
                'bi-trash',
                'text-bg-danger',
                null,
            ],

            // ── TarefaMensagem ────────────────────────────────────────────
            // (já tratado como MENSAGEM via getMensagens(); audit é descartado)
            str_ends_with($entityClass, 'TarefaMensagem') => [
                null, // descarta — mensagem já vem do relacionamento
                'bi-chat',
                'text-bg-light',
                null,
            ],

            default => [
                'Evento registrado',
                'bi-clock-history',
                'text-bg-light',
                null,
            ],
        };
    }

    /**
     * @return array{string, string, string, string|null}
     */
    private function resolverAtualizacaoTarefa(?array $changes): array
    {
        $diff = $changes['diff']['changes'] ?? [];

        if (!is_array($diff)) {
            return ['Tarefa atualizada', 'bi-pencil-square', 'text-bg-secondary', null];
        }

        foreach (array_keys($diff) as $field) {
            if ((string) $field === 'status') {
                $to = $diff[$field]['to'] ?? null;
                return match ((string) $to) {
                    'em_revisao' => ['Tarefa enviada para revisão', 'bi-send', 'text-bg-warning', null],
                    'concluida'  => ['Tarefa concluída', 'bi-check-circle', 'text-bg-success', null],
                    'pendente'   => ['Tarefa devolvida como pendência', 'bi-arrow-return-left', 'text-bg-secondary', null],
                    'reaberta'   => ['Tarefa reaberta', 'bi-arrow-repeat', 'text-bg-info', null],
                    default      => ['Status atualizado', 'bi-pencil-square', 'text-bg-secondary', $this->extractChangeSummary($changes)],
                };
            }

            if ((string) $field === 'prazo') {
                return ['Prazo alterado', 'bi-calendar-event', 'text-bg-warning', $this->extractChangeSummary($changes)];
            }
        }

        $detalhe = $this->extractChangeSummary($changes);
        if ($detalhe === null) {
            return ['Tarefa atualizada', 'bi-pencil-square', 'text-bg-secondary', null];
        }

        return ['Tarefa atualizada', 'bi-pencil-square', 'text-bg-secondary', $detalhe];
    }

    private function resolverNomeAtor(?int $userId): ?string
    {
        if ($userId === null) {
            return null;
        }

        if (!array_key_exists($userId, $this->nomeCache)) {
            $user = $this->userRepository->find($userId);
            $this->nomeCache[$userId] = $user?->getFullName();
        }

        return $this->nomeCache[$userId];
    }

    private const DATE_FIELDS = ['prazo', 'dataConclusao', 'dataCriacao'];

    private function extractChangeSummary(?array $changes): ?string
    {
        $diff = $changes['diff']['changes'] ?? [];
        if (!is_array($diff) || $diff === []) {
            return null;
        }

        $parts = [];
        foreach ($diff as $field => $change) {
            $frase = $this->descreverMudanca((string) $field, $change);
            if ($frase !== null) {
                $parts[] = $frase;
            }
        }

        if ($parts === []) {
            return null;
        }

        return implode(' · ', array_slice($parts, 0, 3));
    }

    private function descreverMudanca(string $field, mixed $change): ?string
    {
        $to   = $change['to'] ?? null;
        $from = $change['from'] ?? null;

        if ($field === 'status' && is_string($to)) {
            return 'Status: ' . (self::STATUS_LABELS[$to] ?? $to);
        }

        if (in_array($field, self::DATE_FIELDS, true) && is_string($to) && $to !== '') {
            $label = self::FIELD_LABELS[$field] ?? $field;
            return sprintf('%s: %s', $label, $this->formatarData($to));
        }

        if (is_array($to) && isset($to['label'])) {
            $label = self::FIELD_LABELS[$field] ?? $field;
            return $to['label']
                ? sprintf('%s alterado para "%s"', $label, $to['label'])
                : sprintf('%s removido', $label);
        }

        $label = self::FIELD_LABELS[$field] ?? null;
        if ($label === null) {
            return null;
        }

        if (is_string($to) && $to !== '') {
            return sprintf('%s atualizado', $label);
        }

        if ($to === null && $from !== null) {
            return sprintf('%s removido', $label);
        }

        return null;
    }

    private function formatarData(string $valor): string
    {
        try {
            return (new \DateTimeImmutable($valor))->format('d/m/Y');
        } catch (\Throwable) {
            return $valor;
        }
    }
}
