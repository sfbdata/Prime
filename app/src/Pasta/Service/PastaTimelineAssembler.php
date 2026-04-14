<?php

namespace App\Pasta\Service;

use App\Entity\Pasta\Pasta;
use App\Entity\Tenant\Tenant;
use App\Pasta\DTO\TimelineItemDTO;
use App\Pasta\DTO\TimelineItemType;
use App\Repository\AuditLogRepository;
use App\Repository\Pasta\PastaMensagemRepository;

class PastaTimelineAssembler
{
    private const CHECKLIST_FIELDS = [
        'docPecaOk',
        'docProcuracaoOk',
        'docIdentificacaoOk',
        'docComprovanteResidenciaOk',
        'docGratuidadeJusticaOk',
        'docDemaisOk',
        'statusDocumentos',
    ];

    private const FIELD_LABELS = [
        'nup'          => 'NUP',
        'status'       => 'Status',
        'nomeCliente'  => 'Nome do cliente',
        'nomeAcao'     => 'Nome da ação',
        'responsavel'  => 'Responsável',
        'dataAbertura' => 'Data de abertura',
        'descricao'    => 'Descrição',
        'titulo'       => 'Título',
        'categoria'    => 'Categoria',
        'numero'       => 'Número',
        'nome'         => 'Nome',
        'processo'     => 'Processo',
    ];

    public function __construct(
        private readonly PastaMensagemRepository $mensagemRepository,
        private readonly AuditLogRepository $auditLogRepository,
    ) {}

    /**
     * @return TimelineItemDTO[]
     */
    public function montar(
        Pasta $pasta,
        ?Tenant $tenant,
        int $tenantId,
        ?int $processoId,
        int $limit = 150
    ): array {
        $items = [];

        if ($tenant !== null) {
            foreach ($this->mensagemRepository->findByPasta($pasta, $tenant, $limit) as $msg) {
                $items[] = new TimelineItemDTO(
                    tipo:       TimelineItemType::MENSAGEM,
                    dataHora:   $msg->getCriadaEm(),
                    titulo:     'Mensagem',
                    detalhe:    $msg->getConteudo(),
                    autorNome:  $msg->getAutor()?->getFullName(),
                    autorEmail: $msg->getAutor()?->getEmail(),
                    icone:      'bi-chat-left-text',
                    badgeCss:   'text-bg-info',
                );
            }
        }

        $rows = $this->auditLogRepository->findForPastaTimeline(
            (int) $pasta->getId(),
            $tenantId,
            $processoId,
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

        // Descartar se update de Pasta sem campos legíveis (apenas checklist)
        if ($detalhe === null && $action === 'update' && str_ends_with($entityClass, '\Pasta')) {
            return null;
        }

        $createdAt = new \DateTimeImmutable((string) ($row['created_at'] ?? 'now'));

        return new TimelineItemDTO(
            tipo:       TimelineItemType::EVENTO,
            dataHora:   $createdAt,
            titulo:     $titulo,
            detalhe:    $detalhe,
            autorNome:  null,
            autorEmail: isset($row['actor_email']) && $row['actor_email'] !== '' ? (string) $row['actor_email'] : null,
            icone:      $icone,
            badgeCss:   $badgeCss,
        );
    }

    /**
     * @return array{string, string, string, string|null}
     */
    private function resolveEventoVisual(string $entityClass, string $action, ?array $changes): array
    {
        return match (true) {
            str_ends_with($entityClass, 'PastaDocumento') && $action === 'create' => [
                'Documento enviado',
                'bi-file-earmark-plus',
                'text-bg-primary',
                $this->extractDocumentoNome($changes),
            ],
            str_ends_with($entityClass, 'PastaDocumento') && $action === 'delete' => [
                'Documento removido',
                'bi-file-earmark-x',
                'text-bg-danger',
                $this->extractDocumentoNome($changes),
            ],
            str_ends_with($entityClass, 'PastaDocumento') && $action === 'update' => [
                'Documento atualizado',
                'bi-file-earmark-check',
                'text-bg-secondary',
                $this->extractChangeSummary($changes),
            ],
            str_ends_with($entityClass, 'ParteContraria') && $action === 'create' => [
                'Parte contrária adicionada',
                'bi-person-dash',
                'text-bg-warning',
                $this->extractParteName($changes),
            ],
            str_ends_with($entityClass, 'ParteContraria') && $action === 'delete' => [
                'Parte contrária removida',
                'bi-person-x',
                'text-bg-danger',
                $this->extractParteName($changes),
            ],
            str_ends_with($entityClass, '\Pasta') && $action === 'create' => [
                'Pasta criada',
                'bi-folder-plus',
                'text-bg-success',
                null,
            ],
            str_ends_with($entityClass, '\Pasta') && $action === 'update' => [
                'Pasta atualizada',
                'bi-pencil-square',
                'text-bg-secondary',
                $this->extractChangeSummary($changes),
            ],
            str_ends_with($entityClass, 'Processo') && $action === 'create' => [
                'Processo vinculado',
                'bi-briefcase',
                'text-bg-info',
                null,
            ],
            str_ends_with($entityClass, 'Processo') && $action === 'update' => [
                'Processo atualizado',
                'bi-briefcase-fill',
                'text-bg-secondary',
                $this->extractChangeSummary($changes),
            ],
            default => [
                'Evento registrado',
                'bi-clock-history',
                'text-bg-light',
                null,
            ],
        };
    }

    private function extractDocumentoNome(?array $changes): ?string
    {
        return $changes['diff']['after']['nomeOriginal']
            ?? $changes['diff']['before']['nomeOriginal']
            ?? null;
    }

    private function extractParteName(?array $changes): ?string
    {
        return $changes['diff']['after']['nome']
            ?? $changes['diff']['before']['nome']
            ?? null;
    }

    private function extractChangeSummary(?array $changes): ?string
    {
        $diff = $changes['diff']['changes'] ?? [];
        if (!is_array($diff) || $diff === []) {
            return null;
        }

        $parts = [];
        foreach ($diff as $field => $change) {
            if (in_array($field, self::CHECKLIST_FIELDS, true)) {
                continue;
            }
            $label = self::FIELD_LABELS[$field] ?? $field;
            $to    = $change['to'] ?? null;
            if (is_array($to)) {
                $to = $to['label'] ?? 'alterado';
            }
            if ($to !== null && $to !== '') {
                $parts[] = sprintf('%s: %s', $label, $to);
            }
        }

        if ($parts === []) {
            return null;
        }

        return implode(', ', array_slice($parts, 0, 3));
    }
}
