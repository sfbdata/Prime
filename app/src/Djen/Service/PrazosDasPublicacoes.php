<?php

declare(strict_types=1);

namespace App\Djen\Service;

use App\Djen\DTO\PrazoDaPublicacao;
use App\Djen\DTO\PublicacaoDjenListaItem;
use App\Djen\Repository\PublicacaoDjenRepository;
use App\Entity\Tenant\Tenant;

/** Prazo de cada publicação da lista (selo da linha) a partir do que o usuário escolheu e do que o teor gerou na pasta. */
final class PrazosDasPublicacoes
{
    public function __construct(
        private readonly PublicacaoDjenRepository $publicacoes,
        private readonly PrazoDaPublicacaoCalculador $calculador,
    ) {
    }

    /**
     * @param list<PublicacaoDjenListaItem> $itens
     *
     * @return array<int, PrazoDaPublicacao> só as publicações que têm prazo
     */
    public function dosItens(Tenant $tenant, array $itens, \DateTimeImmutable $hoje): array
    {
        $doTeor = $this->publicacoes->prazosDoPushPorPublicacao($tenant, array_map(static fn (PublicacaoDjenListaItem $i): int => $i->id, $itens));
        $prazos = [];
        foreach ($itens as $item) {
            $teor = $doTeor[$item->id] ?? null;
            $disponibilizacao = $item->dataIso !== null ? new \DateTimeImmutable($item->dataIso) : null;
            $prazo = $this->calculador->calcular($disponibilizacao, $item->prazoDias, $teor['data'] ?? null, $item->prazoCumprido || ($teor['cumprido'] ?? false), $tenant->getEstado(), $hoje);
            if ($prazo !== null) {
                $prazos[$item->id] = $prazo;
            }
        }

        return $prazos;
    }
}
