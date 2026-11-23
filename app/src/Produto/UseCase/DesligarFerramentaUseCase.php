<?php

declare(strict_types=1);

namespace App\Produto\UseCase;

use App\Backup\Service\DonoDoEscritorio;
use App\Entity\Auth\User;
use App\Entity\Tenant\Tenant;
use App\Inteligencia\Repository\ConfiguracaoDeInteligenciaRepository;
use App\Produto\Enum\OrigemDaContratacao;
use App\Produto\Enum\Produto;
use App\Produto\Exception\FerramentaRecusadaException;
use App\Produto\Service\ContratacoesDoEscritorio;
use App\Voto\Repository\AssembleiaRepository;
use App\Voto\Repository\LiberacaoDoVotoRepository;
use Psr\Clock\ClockInterface;

/**
 * O dono desliga uma ferramenta no Meu Perfil (spec docs/specs/multiproduto-contratacao-e-voto.md, §9). Na ordem: só o
 * dono (403 antes de tudo); `completo` não se desliga (404); Voto com assembleia em andamento não desliga (regra 7,
 * 422). Depois, sob a trava do escritório: encerra a contratação (materializando, D5) e
 *
 * - Voto: bloqueia a `LiberacaoDoVoto`, se existir (decisão do PR 8a: o cartão e a liberação andam juntos);
 * - I.A.: `bloquearPeloMaster()`, se existir (D15: os trabalhos em segundo plano só olham a liberação). O aceite fica.
 *
 * Desligar não apaga dado: o que o escritório tem na ferramenta volta quando ela for religada.
 */
final class DesligarFerramentaUseCase
{
    public function __construct(
        private readonly DonoDoEscritorio $dono,
        private readonly ContratacoesDoEscritorio $contratacoes,
        private readonly AssembleiaRepository $assembleias,
        private readonly LiberacaoDoVotoRepository $liberacoesDoVoto,
        private readonly ConfiguracaoDeInteligenciaRepository $configuracoesDaIa,
        private readonly ClockInterface $relogio,
    ) {
    }

    /** @throws FerramentaRecusadaException */
    public function executar(User $dono, Tenant $tenant, Produto $produto): void
    {
        if (!$this->dono->ehDono($dono, $tenant)) {
            throw FerramentaRecusadaException::soODono();
        }
        if (Produto::Completo === $produto) {
            throw FerramentaRecusadaException::naoLigavel();
        }

        $this->contratacoes->sobTrava($tenant, fn () => $this->desligarSobTrava($dono, $tenant, $produto));
    }

    private function desligarSobTrava(User $dono, Tenant $tenant, Produto $produto): void
    {
        if (Produto::Voto === $produto && $this->assembleias->existeAbertaNoEscritorio($tenant)) {
            throw FerramentaRecusadaException::assembleiaAberta();
        }

        $agora = \DateTimeImmutable::createFromInterface($this->relogio->now());
        $this->contratacoes->excluir($tenant, $produto, OrigemDaContratacao::Dono, $dono, $agora);
        if (Produto::Voto === $produto) {
            $this->bloquearOVoto($tenant);
        }
        if (Produto::Ia === $produto) {
            $this->bloquearAIa($tenant);
        }
    }

    private function bloquearOVoto(Tenant $tenant): void
    {
        $liberacao = $this->liberacoesDoVoto->findDoTenant($tenant);
        if (null === $liberacao) {
            return;
        }

        $liberacao->bloquear();
        $this->liberacoesDoVoto->salvar($liberacao, flush: true);
    }

    private function bloquearAIa(Tenant $tenant): void
    {
        $configuracao = $this->configuracoesDaIa->findDoTenant($tenant);
        if (null === $configuracao) {
            return;
        }

        $configuracao->bloquearPeloMaster();
        $this->configuracoesDaIa->salvar($configuracao, flush: true);
    }
}
