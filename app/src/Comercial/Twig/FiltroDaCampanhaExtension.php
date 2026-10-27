<?php

declare(strict_types=1);

namespace App\Comercial\Twig;

use App\Contribua\DTO\PlanoDoCatalogo;
use App\Contribua\Service\CatalogoDePlanos;
use App\Master\Service\LocalizacaoDaPessoa;
use App\Shared\Email\IdentidadeDeEmail;
use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;

/**
 * As opções dos selects de "Filtrar destinatários" que vêm do sistema (desenho 05 VS 21.10.26, L263; spec
 * comercial-e-emails.md §9): o Plano pelo catálogo do Contribua (D4: os ativos e os que ainda têm escritório) e as 27
 * UFs na ordem do dc. As outras opções são fixas do dc e ficam no template. Também o remetente real, para o "Ver como
 * vai chegar" (dc L500; o dc mostra contato@, e a troca está com o dono, spec §10).
 */
final class FiltroDaCampanhaExtension extends AbstractExtension
{
    public function __construct(
        private readonly CatalogoDePlanos $catalogo,
        private readonly IdentidadeDeEmail $identidade,
    ) {
    }

    public function getFunctions(): array
    {
        return [
            new TwigFunction('comercial_planos_do_filtro', $this->planos(...)),
            new TwigFunction('comercial_ufs_do_filtro', static fn (): array => LocalizacaoDaPessoa::UFS),
            new TwigFunction('comercial_remetente', fn (): string => $this->identidade->remetente()),
        ];
    }

    /** @return list<array{codigo: string, nome: string}> */
    public function planos(): array
    {
        $usados = array_filter($this->catalogo->todos(), static fn (PlanoDoCatalogo $p): bool => $p->ativo || $p->escritorios > 0);

        return array_values(array_map(static fn (PlanoDoCatalogo $p): array => ['codigo' => $p->codigo, 'nome' => $p->nome], $usados));
    }
}
