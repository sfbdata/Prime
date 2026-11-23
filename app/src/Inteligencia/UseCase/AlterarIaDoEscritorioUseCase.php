<?php

declare(strict_types=1);

namespace App\Inteligencia\UseCase;

use App\Shared\Audit\EscopoDeAuditoriaDePlataforma;
use App\Entity\Auth\User;
use App\Entity\Tenant\Tenant;
use App\Inteligencia\DTO\AlterarIaDoEscritorioInput;
use App\Inteligencia\Entity\ConfiguracaoDeInteligencia;
use App\Inteligencia\Enum\ModoDeCobrancaDaIa;
use App\Inteligencia\Exception\EscritorioNaoEncontradoException;
use App\Inteligencia\Repository\ConfiguracaoDeInteligenciaRepository;
use App\Inteligencia\Service\GuardaDoMasterDaIa;
use App\Produto\Enum\OrigemDaContratacao;
use App\Produto\Enum\Produto;
use App\Produto\Service\ContratacoesDoEscritorio;
use App\Repository\TenantRepository;
use Psr\Clock\ClockInterface;

/**
 * O Master libera (ou bloqueia) a BlueJus IA de UM escritório e define o modo de cobrança e o orçamento mensal (spec
 * ia-anthropic-ligar §10.1). Liberar não liga a IA: o admin do escritório ainda precisa habilitar com o aceite.
 * Bloquear não apaga o aceite (é histórico) e corta a fila do escritório (IA-034L).
 *
 * Escritório inexistente, suspenso ou excluído: {@see EscritorioNaoEncontradoException} (404). Sem linha de
 * configuração, ela nasce aqui, com a IA desligada no escritório.
 *
 * Trava do Master (spec docs/specs/multiproduto-contratacao-e-voto.md, D16): bloquear também encerra a contratação da
 * I.A. com origem `master` (materializando, se preciso), e o dono não religa pelo Meu Perfil; liberar reabre a
 * contratação com origem `master` só quando a I.A. não está incluída (trocar só o teto não cria linha). Tudo sob a
 * trava das contratações do escritório.
 */
final class AlterarIaDoEscritorioUseCase
{
    public function __construct(
        private readonly TenantRepository $tenants,
        private readonly ConfiguracaoDeInteligenciaRepository $configuracoes,
        private readonly EscopoDeAuditoriaDePlataforma $escopoDePlataforma,
        private readonly ContratacoesDoEscritorio $contratacoes,
        private readonly ClockInterface $relogio,
    ) {
    }

    public function executar(AlterarIaDoEscritorioInput $input, User $master): void
    {
        GuardaDoMasterDaIa::exigir($master);
        $tenant = $this->escritorioAtivo($input->tenantId);
        $modo = $this->modo($input->modoDeCobranca);

        // A auditoria vai para o escritório alterado, não para o da sessão do Master. A configuração é lida DENTRO da
        // trava do escritório: duas trocas ao mesmo tempo (Master e dono) decidem sobre o estado já gravado.
        $this->escopoDePlataforma->executarComoConta(
            (int) $tenant->getId(),
            fn () => $this->contratacoes->sobTrava($tenant, fn () => $this->alterarSobTrava($tenant, $input->liberada, $master, $modo, $input->orcamentoMensalCentavos)),
        );
    }

    /**
     * "Liberar/bloquear todos" do Master, um escritório por vez: troca só a liberação. O modo e o teto ficam como estão,
     * lidos dentro da trava (nunca de uma leitura feita antes dela).
     */
    public function alterarLiberacao(int $tenantId, bool $liberada, User $master): void
    {
        GuardaDoMasterDaIa::exigir($master);
        $tenant = $this->escritorioAtivo($tenantId);

        $this->escopoDePlataforma->executarComoConta(
            (int) $tenant->getId(),
            fn () => $this->contratacoes->sobTrava($tenant, fn () => $this->alterarSobTrava($tenant, $liberada, $master)),
        );
    }

    /** `$modo` nulo: a cobrança do escritório não muda. */
    private function alterarSobTrava(Tenant $tenant, bool $liberada, User $master, ?ModoDeCobrancaDaIa $modo = null, ?int $orcamento = null): void
    {
        $configuracao = $this->configuracoes->findDoTenantParaOMaster($tenant) ?? new ConfiguracaoDeInteligencia($tenant);
        // D16: só a TROCA de estado mexe na contratação. Trocar só o teto, ou salvar "bloqueada" num escritório que nunca
        // foi liberado, não é trava (o estado de hoje deixa o dono ligar).
        $estava = $configuracao->isLiberadaPeloMaster();
        if ($liberada) {
            $configuracao->liberarPeloMaster();
        } else {
            $configuracao->bloquearPeloMaster();
        }
        if ($modo !== null) {
            $configuracao->definirCobranca($modo, $orcamento);
        }
        $this->configuracoes->salvar($configuracao, flush: true);
        if ($estava !== $liberada) {
            $this->ajustarContratacao($tenant, $liberada, $master);
        }
    }

    /** D16: o bloqueio do Master vira trava; a liberação dele desfaz. */
    private function ajustarContratacao(Tenant $tenant, bool $liberada, User $master): void
    {
        $agora = \DateTimeImmutable::createFromInterface($this->relogio->now());
        if ($liberada) {
            $this->contratacoes->incluir($tenant, Produto::Ia, OrigemDaContratacao::Master, $master, $agora);

            return;
        }

        $this->contratacoes->excluir($tenant, Produto::Ia, OrigemDaContratacao::Master, $master, $agora);
    }

    private function modo(string $valor): ModoDeCobrancaDaIa
    {
        return ModoDeCobrancaDaIa::tryFrom($valor) ?? throw new \InvalidArgumentException('Escolha um modo de cobrança da lista.');
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
