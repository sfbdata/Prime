<?php

declare(strict_types=1);

namespace App\Produto\UseCase;

use App\Entity\Auth\User;
use App\Entity\Tenant\Tenant;
use App\Inteligencia\UseCase\AlterarIaDoEscritorioUseCase;
use App\Produto\Enum\OrigemDaContratacao;
use App\Produto\Enum\Produto;
use App\Produto\Exception\EscritorioNaoEncontradoException;
use App\Produto\Service\ContratacoesDoEscritorio;
use App\Produto\Service\GuardaDoMasterDasFerramentas;
use App\Repository\TenantRepository;
use App\Shared\Audit\EscopoDeAuditoriaDePlataforma;
use App\Voto\UseCase\AlterarLiberacaoDoVotoUseCase;
use Psr\Clock\ClockInterface;

/**
 * O Master liga ou desliga uma ferramenta de UM escritório pela aba "Ferramentas" (spec
 * docs/specs/multiproduto-contratacao-e-voto.md, §10, D10, D16). Grava com origem `master`: desligar vira a trava (o
 * dono não religa pelo Meu Perfil) e ligar a desfaz.
 *
 * - Voto e I.A.: passam pelos UseCases das abas de hoje, que mexem na liberação e na contratação juntos, sob a trava do
 *   escritório e com a auditoria no escritório alterado (ligar o Voto também libera a `LiberacaoDoVoto`, D10). O modo e o
 *   teto da I.A. ficam como estão.
 * - As demais, e `completo`: incluir ou excluir a contratação sob a trava, com a auditoria no escritório alterado.
 *   `completo` pode ser posto e tirado; tirar materializa uma linha para cada ferramenta (D5).
 *
 * A D7 (não desligar a única ferramenta ligada) não vale para o Master: o veto é dele. Ligar o Expediente aqui não semeia
 * marcadores (isso é do dono, §9, regra 10).
 *
 * Escritório inexistente, suspenso ou excluído: {@see EscritorioNaoEncontradoException} (404).
 */
final class AlterarFerramentaDoEscritorioPeloMasterUseCase
{
    public function __construct(
        private readonly TenantRepository $tenants,
        private readonly ContratacoesDoEscritorio $contratacoes,
        private readonly EscopoDeAuditoriaDePlataforma $escopoDePlataforma,
        private readonly AlterarLiberacaoDoVotoUseCase $voto,
        private readonly AlterarIaDoEscritorioUseCase $ia,
        private readonly ClockInterface $relogio,
    ) {
    }

    public function executar(int $tenantId, Produto $produto, bool $ligado, User $master): void
    {
        GuardaDoMasterDasFerramentas::exigir($master);
        $tenant = $this->escritorioAtivo($tenantId);

        if (Produto::Voto === $produto) {
            $this->voto->executar($tenantId, $ligado, $master);

            return;
        }
        if (Produto::Ia === $produto) {
            $this->ia->alterarLiberacao($tenantId, $ligado, $master);

            return;
        }

        $agora = \DateTimeImmutable::createFromInterface($this->relogio->now());
        $this->escopoDePlataforma->executarComoConta((int) $tenant->getId(), fn () => $this->contratacoes->sobTrava($tenant, function () use ($tenant, $produto, $ligado, $master, $agora): void {
            if ($ligado) {
                $this->contratacoes->incluir($tenant, $produto, OrigemDaContratacao::Master, $master, $agora);

                return;
            }
            $this->contratacoes->excluir($tenant, $produto, OrigemDaContratacao::Master, $master, $agora);
        }));
    }

    private function escritorioAtivo(int $tenantId): Tenant
    {
        $tenant = $tenantId > 0 ? $this->tenants->find($tenantId) : null;
        if (!$tenant instanceof Tenant || $tenant->isActive() !== true || $tenant->getExcluidoEm() !== null) {
            throw new EscritorioNaoEncontradoException($tenantId);
        }

        return $tenant;
    }
}
