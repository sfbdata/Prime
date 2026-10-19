<?php

declare(strict_types=1);

namespace App\Contribua\Twig;

use App\Contribua\DTO\RetratoDoEspaco;
use App\Contribua\Enum\FaixaDoEspaco;
use App\Contribua\Repository\ConfiguracaoDoEspacoRepository;
use App\Contribua\Service\SituacaoDoEspaco;
use App\Service\Tenant\TenantContext;
use Symfony\Contracts\Service\ResetInterface;
use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;

/**
 * `aviso_de_espaco()`: os dados do aviso no topo de toda tela (desenho 08, `bj-plano.js`), ou null
 * quando não há aviso. O parcial `layout/_globais/aviso_espaco.html.twig` só chama para o primário.
 *
 * Regras do desenho: a partir da faixa Alerta configurada pelo Master (padrão 90%); "Espaço
 * esgotado" (sem botão de fechar) só quando o bloqueio está ligado e o escritório chegou à faixa
 * Bloqueio, porque só aí os envios estão de fato pausados.
 */
final class AvisoDeEspacoExtension extends AbstractExtension implements ResetInterface
{
    /** @var array{tenant: int, aviso: ?array<string, mixed>}|null */
    private ?array $daRequisicao = null;

    public function __construct(
        private readonly TenantContext $tenantContext,
        private readonly SituacaoDoEspaco $situacao,
        private readonly ConfiguracaoDoEspacoRepository $configuracoes,
    ) {
    }

    public function getFunctions(): array
    {
        return [new TwigFunction('aviso_de_espaco', $this->avisoDeEspaco(...))];
    }

    /** @return array<string, mixed>|null */
    public function avisoDeEspaco(): ?array
    {
        $tenantId = $this->tenantContext->getCurrentTenant()?->getId();
        if ($tenantId === null) {
            return null;
        }
        if ($this->daRequisicao === null || $this->daRequisicao['tenant'] !== $tenantId) {
            $this->daRequisicao = ['tenant' => $tenantId, 'aviso' => $this->montar($this->situacao->doEscritorio($tenantId))];
        }

        return $this->daRequisicao['aviso'];
    }

    public function reset(): void
    {
        $this->daRequisicao = null;
    }

    /** @return array<string, mixed>|null */
    private function montar(RetratoDoEspaco $r): ?array
    {
        if ($r->percentual === null || $r->bytesLimite === null || $r->percentual < $this->configuracoes->atual()->getFaixaAlerta()) {
            return null;
        }

        return [
            'cheio' => $r->bloqueioAtivo && $r->faixa === FaixaDoEspaco::Bloqueio,
            'percentual' => $r->percentual,
            'largura' => min(100, $r->percentual) . '%',
            'uso' => RetratoDoEspaco::formatarGb($r->bytesUsados),
            'limite' => RetratoDoEspaco::formatarGb($r->bytesLimite),
            'faltam' => RetratoDoEspaco::formatarGb(max(0, $r->bytesLimite - $r->bytesUsados)),
            'plano' => $r->planoNome,
        ];
    }
}
