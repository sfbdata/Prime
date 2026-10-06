<?php

declare(strict_types=1);

namespace App\Pasta\Twig;

use App\Pasta\DTO\SugestaoDeDocumentosOutput;
use App\Pasta\Entity\Pasta;
use App\Pasta\Entity\PastaChecklistItem;
use App\Pasta\Entity\PastaDocumento;
use App\Pasta\Service\DeterminacoesDoJuizo;
use App\Pasta\Service\SugestorDeDocumentos;
use App\Service\Tenant\TenantContext;
use Psr\Clock\ClockInterface;
use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;

/**
 * `documentos_sugeridos(pasta, checklistItens)` — o painel "Documentos sugeridos" da aba Documentos.
 *
 * Está aqui, e não no controller, pelo mesmo motivo do `PastaFavoritaExtension`: o painel é um
 * parcial incluído por uma linha só, sem variável nova no `show`. A função só traduz as entidades
 * para os dados simples que o `SugestorDeDocumentos` (puro) espera.
 *
 * Isolamento: pasta de outro escritório que não o da sessão (ou sem escritório na sessão) não
 * recebe sugestão — devolve `null` e o parcial não desenha nada. Documentos e itens de checklist
 * de outro escritório também são descartados, ainda que cheguem pela coleção.
 *
 * "Exigido pelo juízo" (DOC-79): `DeterminacoesDoJuizo::daPasta` lê por regras o teor das
 * publicações do Push dos processos DESTA pasta, do escritório da sessão. Roda em TODO `pasta_show`
 * de pasta com checklist ativo — o painel é desenhado escondido e só abre no clique —, numa
 * consulta com teto de 100 publicações (`DeterminacoesDoJuizo::LIMITE_DE_PUBLICACOES`, o mesmo da
 * aba Push). Com o checklist desativado (DOC-73) nada disso é calculado: o painel nem aparece.
 */
final class DocumentosSugeridosExtension extends AbstractExtension
{
    public function __construct(
        private readonly SugestorDeDocumentos $sugestor,
        private readonly TenantContext $tenantContext,
        private readonly DeterminacoesDoJuizo $determinacoes,
        private readonly ClockInterface $clock,
    ) {
    }

    public function getFunctions(): array
    {
        return [
            new TwigFunction('documentos_sugeridos', $this->documentosSugeridos(...)),
        ];
    }

    /**
     * @param iterable<PastaChecklistItem> $checklistItens
     */
    public function documentosSugeridos(Pasta $pasta, iterable $checklistItens = []): ?SugestaoDeDocumentosOutput
    {
        $tenant = $this->tenantContext->getCurrentTenant();

        $tenantId = $tenant?->getId();

        // Compara por id: proxy e entidade carregada por caminhos diferentes são o mesmo escritório.
        if ($tenantId === null || $pasta->getTenant()?->getId() !== $tenantId) {
            return null;
        }

        // Checklist desativado: as sugestões são para alimentá-lo, e o desenho tira o botão (dc L2088).
        if (!$pasta->isChecklistAtivo()) {
            return null;
        }

        $arquivos = [];
        foreach ($pasta->getDocumentos() as $documento) {
            if (!$documento instanceof PastaDocumento || $documento->getTenant()?->getId() !== $tenantId) {
                continue;
            }

            $arquivos[] = [
                'titulo'       => $documento->getTitulo(),
                'nomeOriginal' => $documento->getNomeOriginal(),
                'categoria'    => $documento->getCategoria(),
                'data'         => $documento->getCarregadoEm()->format('d/m/Y'),
            ];
        }

        $checklist = [];
        foreach ($checklistItens as $item) {
            if (!$item instanceof PastaChecklistItem || $item->getTenant()?->getId() !== $tenantId || $item->getPasta()?->getId() !== $pasta->getId()) {
                continue;
            }

            $checklist[] = ['titulo' => $item->getTitulo(), 'concluido' => $item->isConcluido()];
        }

        $processo = $pasta->getProcessoPrincipal();

        return $this->sugestor->sugerir(
            $pasta->getNomeAcao(),
            $processo?->getClasseProcessual(),
            $processo?->getNumeroProcesso(),
            $arquivos,
            $checklist,
            $this->determinacoes->daPasta($pasta, $tenant),
            $this->clock->now(),
        );
    }
}
