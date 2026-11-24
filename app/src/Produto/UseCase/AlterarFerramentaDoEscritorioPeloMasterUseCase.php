<?php

declare(strict_types=1);

namespace App\Produto\UseCase;

use App\Entity\Auth\User;
use App\Entity\Tenant\Tenant;
use App\Inteligencia\Enum\ModoDeCobrancaDaIa;
use App\Inteligencia\Repository\ConfiguracaoDeInteligenciaRepository;
use App\Inteligencia\UseCase\AlterarIaDoEscritorioUseCase;
use App\Master\Repository\ConfiguracaoGlobalRepository;
use App\Produto\Enum\OrigemDaContratacao;
use App\Produto\Enum\Produto;
use App\Produto\Exception\EscritorioNaoEncontradoException;
use App\Produto\Service\ContratacoesDoEscritorio;
use App\Produto\Service\GuardaDoMasterDasFerramentas;
use App\Repository\TenantRepository;
use App\Service\TenantBootstrapService;
use App\Shared\Audit\EscopoDeAuditoriaDePlataforma;
use App\Voto\Repository\LiberacaoDoVotoRepository;
use App\Voto\UseCase\AlterarLiberacaoDoVotoUseCase;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Clock\ClockInterface;

/**
 * O Master liga ou desliga uma ferramenta de UM escritório pela aba "Ferramentas" (spec
 * docs/specs/multiproduto-contratacao-e-voto.md, §10, D10, D16). Grava com origem `master`: desligar vira a trava (o
 * dono não religa pelo Meu Perfil) e ligar a desfaz. Tudo sob a trava das contratações do escritório e com a auditoria
 * no escritório alterado.
 *
 * - Voto e I.A.: passam pelos UseCases das abas de hoje (liberação e contratação juntas; ligar o Voto libera a
 *   `LiberacaoDoVoto`, D10). Como eles só mexem na contratação quando a liberação MUDA, este UseCase completa depois: ligar
 *   termina com a contratação ativa e desligar com ela encerrada com origem `master`, mesmo que a liberação já estivesse no
 *   valor pedido (ex.: liberada, mas o dono encerrou a contratação). Exceção da D16: desligar o que nunca foi liberado
 *   não é trava. Ligar a I.A. segue a regra do dono (§9, regra 3): com o orçamento nulo, grava `Incluso` com o teto
 *   padrão (D14); o orçamento já gravado não muda.
 * - Expediente: ligar também semeia os marcadores padrão se faltarem (§9, regra 10). O criador é o dono do escritório (o
 *   Master é de fora dele; sem dono, vale o Master) e o `tenant` filter fica suspenso na chamada: a sessão do Master pode
 *   estar em outro escritório e a contagem de marcadores do alvo daria zero (duplicaria a cada religada).
 * - As demais, e `completo`: incluir ou excluir a contratação. Tirar o `completo` materializa uma linha por ferramenta (D5).
 *
 * A D7 (não desligar a única ferramenta ligada) não vale para o Master: o veto é dele.
 *
 * Escritório inexistente, suspenso ou excluído: {@see EscritorioNaoEncontradoException} (404).
 */
final class AlterarFerramentaDoEscritorioPeloMasterUseCase
{
    /** O teto padrão da I.A. quando o Master nunca o definiu (o mesmo do Meu Perfil). */
    private const TETO_PADRAO_DE_RESERVA = LigarFerramentaUseCase::TETO_PADRAO_DE_RESERVA;

    public function __construct(
        private readonly TenantRepository $tenants,
        private readonly ContratacoesDoEscritorio $contratacoes,
        private readonly EscopoDeAuditoriaDePlataforma $escopoDePlataforma,
        private readonly AlterarLiberacaoDoVotoUseCase $voto,
        private readonly AlterarIaDoEscritorioUseCase $ia,
        private readonly LiberacaoDoVotoRepository $liberacoesDoVoto,
        private readonly ConfiguracaoDeInteligenciaRepository $configuracoesDaIa,
        private readonly ConfiguracaoGlobalRepository $configuracaoGlobal,
        private readonly TenantBootstrapService $bootstrap,
        private readonly EntityManagerInterface $em,
        private readonly ClockInterface $relogio,
    ) {
    }

    public function executar(int $tenantId, Produto $produto, bool $ligado, User $master): void
    {
        GuardaDoMasterDasFerramentas::exigir($master);
        $tenant = $this->escritorioAtivo($tenantId);

        $this->escopoDePlataforma->executarComoConta((int) $tenant->getId(), fn () => $this->contratacoes->sobTrava(
            $tenant,
            fn () => $this->alterarSobTrava($tenant, $produto, $ligado, $master),
        ));
    }

    private function alterarSobTrava(Tenant $tenant, Produto $produto, bool $ligado, User $master): void
    {
        $agora = \DateTimeImmutable::createFromInterface($this->relogio->now());
        $estava = match ($produto) {
            Produto::Voto => $this->liberacoesDoVoto->findDoTenantParaOMaster($tenant)?->isLiberado() ?? false,
            Produto::Ia => $this->configuracoesDaIa->findDoTenantParaOMaster($tenant)?->isLiberadaPeloMaster() ?? false,
            default => true,
        };
        if (Produto::Voto === $produto) {
            $this->voto->executar((int) $tenant->getId(), $ligado, $master);
        }
        if (Produto::Ia === $produto) {
            $this->ia->alterarLiberacao((int) $tenant->getId(), $ligado, $master);
        }

        // Desligar o que nunca foi liberado (Voto e I.A.) não é trava (D16); as demais ferramentas sempre travam.
        if ($ligado) {
            $this->contratacoes->incluir($tenant, $produto, OrigemDaContratacao::Master, $master, $agora);
            $this->aoLigar($tenant, $produto, $master);
        } elseif ($estava) {
            $this->contratacoes->excluir($tenant, $produto, OrigemDaContratacao::Master, $master, $agora);
        }
    }

    private function aoLigar(Tenant $tenant, Produto $produto, User $master): void
    {
        if (Produto::Expediente === $produto) {
            $this->semearMarcadores($tenant, $tenant->getCriadoPor() ?? $master);
        }
        if (Produto::Ia === $produto) {
            $this->darOTetoPadraoSeFaltar($tenant);
        }
    }

    private function semearMarcadores(Tenant $tenant, User $criador): void
    {
        $filtros = $this->em->getFilters();
        $estavaLigado = $filtros->isEnabled('tenant');
        if ($estavaLigado) {
            $filtros->suspend('tenant');
        }
        try {
            $this->bootstrap->semearMarcadoresSeFaltarem($tenant, $criador);
        } finally {
            if ($estavaLigado) {
                $filtros->restore('tenant');
            }
        }
    }

    /** §9, regra 3 e D14: só com o orçamento nulo; o orçamento que já existe não muda. */
    private function darOTetoPadraoSeFaltar(Tenant $tenant): void
    {
        $configuracao = $this->configuracoesDaIa->findDoTenantParaOMaster($tenant);
        if (null === $configuracao || null !== $configuracao->getOrcamentoMensalCentavos()) {
            return;
        }

        $configuracao->definirCobranca(ModoDeCobrancaDaIa::Incluso, $this->configuracaoGlobal->tetoPadraoDaIaCentavos() ?? self::TETO_PADRAO_DE_RESERVA);
        $this->configuracoesDaIa->salvar($configuracao, flush: true);
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
