<?php

namespace App\Pasta\Service;

use App\Pasta\Entity\Pasta;
use App\Entity\Tenant\Tenant;
use App\Expediente\Repository\MarcadorRepository;
use App\Pasta\DTO\TimelineItemDTO;
use App\Pasta\DTO\TimelineItemType;
use App\Repository\AuditLogRepository;
use App\Pasta\Repository\PastaMensagemRepository;
use App\Repository\UserRepository;

class PastaTimelineAssembler
{
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

    /** @var array<int, string|null> */
    private array $nomeCache = [];

    /** @var array<int, string|null> */
    private array $marcadorCache = [];

    public function __construct(
        private readonly PastaMensagemRepository $mensagemRepository,
        private readonly AuditLogRepository $auditLogRepository,
        private readonly UserRepository $userRepository,
        private readonly MarcadorRepository $marcadorRepository,
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

        // Descartar se update de Pasta sem informação útil (apenas checklist ou título genérico)
        if ($detalhe === null && $action === 'update' && str_ends_with($entityClass, '\Pasta') && $titulo === 'Pasta atualizada') {
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
            str_ends_with($entityClass, '\Pasta') && $action === 'create' => [
                'Pasta criada',
                'bi-folder-plus',
                'text-bg-success',
                null,
            ],
            str_ends_with($entityClass, '\Pasta') && $action === 'update' => [
                ...($this->resolverAtualizacaoPasta($changes)),
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

    private function resolverNomeMarcador(mixed $entry): ?string
    {
        $label = is_array($entry) ? ($entry['label'] ?? null) : null;
        if (is_string($label) && $label !== '') {
            return $label;
        }

        $id = is_array($entry) ? ($entry['id'] ?? null) : null;
        if ($id === null) {
            return null;
        }

        $id = (int) $id;
        if (!array_key_exists($id, $this->marcadorCache)) {
            $marcador = $this->marcadorRepository->find($id);
            $this->marcadorCache[$id] = $marcador?->getNome();
        }

        return $this->marcadorCache[$id];
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

    /**
     * @return array{string, string, string, string|null} [titulo, icone, badgeCss, detalhe]
     */
    private function resolverAtualizacaoPasta(?array $changes): array
    {
        $diff = $changes['diff']['changes'] ?? [];

        if (is_array($diff)) {
            foreach (array_keys($diff) as $field) {
                if (preg_match('/^clientes\[/', (string) $field)) {
                    return ['Cliente da pasta alterado', 'bi-pencil-square', 'text-bg-secondary', $this->extractChangeSummary($changes)];
                }
                if (preg_match('/^marcadores\[/', (string) $field)) {
                    [$titulo, $detalhe] = $this->resolverTituloMudancaMarcadores($diff);
                    return [$titulo, 'bi-tag', 'text-bg-warning', $detalhe];
                }
                if ((string) $field === 'responsavel') {
                    return ['Responsável alterado', 'bi-person-check', 'text-bg-secondary', $this->extractResponsavelTransicao($changes)];
                }
                if ((string) $field === 'status') {
                    return ['Status da pasta alterado', 'bi-pencil-square', 'text-bg-secondary', $this->extractChangeSummary($changes)];
                }
                if ((string) $field === 'processo') {
                    return ['Processo vinculado atualizado', 'bi-pencil-square', 'text-bg-secondary', $this->extractChangeSummary($changes)];
                }
            }
        }

        return ['Pasta atualizada', 'bi-pencil-square', 'text-bg-secondary', $this->extractChangeSummary($changes)];
    }

    private function extractResponsavelTransicao(?array $changes): ?string
    {
        $diff = $changes['diff']['changes']['responsavel'] ?? null;
        if (!is_array($diff)) {
            return null;
        }

        $para = $this->resolverNomeEntrada($diff['to'] ?? null);
        $de   = $this->resolverNomeEntrada($diff['from'] ?? null);

        if ($para === null && $de === null) {
            return null;
        }

        if ($para !== null && $de !== null) {
            return sprintf("Responsável atualizado para %s\nAntes: %s", $para, $de);
        }

        if ($para !== null) {
            return sprintf('Responsável atualizado para %s', $para);
        }

        return sprintf('Responsável removido (era %s)', $de);
    }

    private function resolverNomeEntrada(mixed $entry): ?string
    {
        if (!is_array($entry)) {
            return null;
        }

        $label = isset($entry['label']) && is_string($entry['label']) && $entry['label'] !== ''
            ? $entry['label']
            : null;

        if ($label !== null) {
            return $label;
        }

        $id = isset($entry['id']) ? (int) $entry['id'] : null;
        if ($id === null || $id === 0) {
            return null;
        }

        return $this->resolverNomeAtor($id);
    }

    /**
     * @return array{string, string|null} [titulo, detalhe]
     */
    private function resolverTituloMudancaMarcadores(array $diff): array
    {
        $removidos   = [];
        $adicionados = [];

        foreach ($diff as $field => $change) {
            if (!preg_match('/^marcadores\[([+\-]):/', (string) $field, $m)) {
                continue;
            }

            $entry = $m[1] === '+' ? ($change['to'] ?? null) : ($change['from'] ?? null);
            $nome  = $this->resolverNomeMarcador($entry);

            if ($nome === null) {
                continue;
            }

            if ($m[1] === '+') {
                $adicionados[] = $nome;
            } else {
                $removidos[] = $nome;
            }
        }

        $temRemovidos   = $removidos !== [];
        $temAdicionados = $adicionados !== [];

        if ($temRemovidos && $temAdicionados) {
            $titulo  = 'Marcadores alterados';
            $detalhe = sprintf(
                "Removido de: %s\nAdicionado em: %s",
                implode(', ', $removidos),
                implode(', ', $adicionados)
            );
            return [$titulo, $detalhe];
        }

        if ($temAdicionados) {
            return ['Marcador adicionado', implode(', ', $adicionados)];
        }

        if ($temRemovidos) {
            return ['Marcador removido', implode(', ', $removidos)];
        }

        return ['Marcadores da pasta alterados', null];
    }

    private const DATE_FIELDS = ['dataAbertura', 'dataEncerramento', 'createdAt', 'updatedAt'];

    private function extractChangeSummary(?array $changes): ?string
    {
        $diff = $changes['diff']['changes'] ?? [];
        if (!is_array($diff) || $diff === []) {
            return null;
        }

        $parts = [];
        foreach ($diff as $field => $change) {
            $frase = $this->descreverMudanca($field, $change);
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
        // Mudanças em coleções ManyToMany: clientes[+:1], clientes[-:2]
        if (preg_match('/^(\w+)\[([+\-]):/', $field, $m)) {
            $colecao = $m[1];
            $operacao = $m[2];

            // Marcador: nome já vai no título, detalhe desnecessário
            if ($colecao === 'marcadores') {
                return null;
            }

            $label = self::FIELD_LABELS[$colecao] ?? $colecao;
            $nome = null;

            if (is_array($change['to'] ?? null)) {
                $nome = $change['to']['label'] ?? null;
            } elseif (is_array($change['from'] ?? null)) {
                $nome = $change['from']['label'] ?? null;
            }

            if ($operacao === '+') {
                return $nome ? sprintf('%s adicionado: %s', $label, $nome) : sprintf('%s adicionado', $label);
            }
            return $nome ? sprintf('%s removido: %s', $label, $nome) : sprintf('%s removido', $label);
        }

        $to   = $change['to'] ?? null;
        $from = $change['from'] ?? null;

        // Associação com label (responsavel, processo, etc.)
        if (is_array($to) && isset($to['label'])) {
            $label = self::FIELD_LABELS[$field] ?? $field;
            return $to['label']
                ? sprintf('%s alterado para "%s"', $label, $to['label'])
                : sprintf('%s removido', $label);
        }

        // Campo de data
        if (in_array($field, self::DATE_FIELDS, true) && is_string($to) && $to !== '') {
            $label = self::FIELD_LABELS[$field] ?? $field;
            return sprintf('%s alterada para %s', $label, $this->formatarData($to));
        }

        // Status
        if ($field === 'status' && is_string($to)) {
            $mapa = [
                'ativo'       => 'ativa',
                'arquivado'   => 'arquivada',
                'arquivada'   => 'arquivada',
            ];
            return 'Pasta ' . ($mapa[$to] ?? $to);
        }

        // Campo de texto simples
        $label = self::FIELD_LABELS[$field] ?? null;
        if ($label === null) {
            return null;
        }

        if (is_string($to) && $to !== '') {
            return sprintf('%s alterado para "%s"', $label, $to);
        }

        if ($to === null && $from !== null) {
            return sprintf('%s removido', $label);
        }

        return sprintf('%s atualizado', $label);
    }

    private function formatarData(string $valor): string
    {
        try {
            $dt = new \DateTimeImmutable($valor);
            return $dt->format('d/m/Y');
        } catch (\Throwable) {
            return $valor;
        }
    }
}
