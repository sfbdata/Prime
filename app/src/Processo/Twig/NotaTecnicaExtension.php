<?php

declare(strict_types=1);

namespace App\Processo\Twig;

use App\Pasta\Repository\PastaRepository;
use App\Processo\DTO\NotaTecnicaOutput;
use App\Processo\Entity\NotaTecnica;
use App\Processo\Entity\Processo;
use App\Processo\Repository\NotaTecnicaRepository;
use App\Service\Tenant\TenantContext;
use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;

/**
 * `notas_tecnicas_do_processo(processo)` — as notas de um processo, já como DTO, para a aba
 * Processo da pasta.
 *
 * Por que uma função Twig e não o controller: o partial `pasta/_processos_vinculados.html.twig`
 * é renderizado em QUATRO lugares (o show da pasta e as três respostas XHR de vincular /
 * desvincular / tornar principal), sempre só com `pasta` no contexto. Pôr as notas em cada um
 * desses pontos é espalhar a mesma consulta por quatro ações de um controller legado; a função
 * deixa um ponto só, escopado pelo escritório da sessão (sem tenant, nada). O precedente é
 * `can_access_module`, que também consulta a partir do template.
 *
 * `nota_tecnica_somente_leitura(pastaId)` — a pasta de onde as notas são vistas é lápide (ou não
 * é deste escritório)? O bloco de notas chega por dois caminhos (aba Processo, com a `pasta` no
 * contexto, e o teor do Push, só com o `pastaId`); a função é o ponto único. Na dúvida (pasta
 * inexistente ou alheia) responde `true`: o menu da nota mostra só a leitura (PMC-017). O
 * `find()` sai do identity map na pasta_show, onde a pasta já está carregada.
 */
final class NotaTecnicaExtension extends AbstractExtension
{
    public function __construct(
        private readonly NotaTecnicaRepository $repository,
        private readonly TenantContext $tenantContext,
        private readonly PastaRepository $pastaRepository,
    ) {}

    public function getFunctions(): array
    {
        return [
            new TwigFunction('notas_tecnicas_do_processo', $this->notasDoProcesso(...)),
            new TwigFunction('nota_tecnica_somente_leitura', $this->somenteLeitura(...)),
        ];
    }

    /** @return NotaTecnicaOutput[] */
    public function notasDoProcesso(Processo $processo): array
    {
        $tenant = $this->tenantContext->getCurrentTenant();
        if ($tenant === null) {
            return [];
        }

        return array_map(
            static fn (NotaTecnica $nota): NotaTecnicaOutput => NotaTecnicaOutput::fromEntity($nota),
            $this->repository->listarPorProcesso($processo, $tenant),
        );
    }

    public function somenteLeitura(?int $pastaId): bool
    {
        $tenant = $this->tenantContext->getCurrentTenant();
        if ($tenant === null || $pastaId === null || $pastaId <= 0) {
            return true;
        }

        // find() por PK não passa pelo TenantFilter: o dono da pasta é conferido explicitamente.
        $pasta = $this->pastaRepository->find($pastaId);
        if ($pasta === null || $pasta->getTenant()?->getId() !== $tenant->getId()) {
            return true;
        }

        return $pasta->estaExcluida();
    }
}
