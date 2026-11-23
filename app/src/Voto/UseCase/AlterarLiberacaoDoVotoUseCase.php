<?php

declare(strict_types=1);

namespace App\Voto\UseCase;

use App\Entity\Auth\User;
use App\Entity\Tenant\Tenant;
use App\Produto\Enum\OrigemDaContratacao;
use App\Produto\Enum\Produto;
use App\Produto\Service\ContratacoesDoEscritorio;
use App\Repository\TenantRepository;
use App\Shared\Audit\EscopoDeAuditoriaDePlataforma;
use App\Voto\Entity\LiberacaoDoVoto;
use App\Voto\Exception\EscritorioNaoEncontradoException;
use App\Voto\Repository\LiberacaoDoVotoRepository;
use App\Voto\Service\GuardaDoMasterDoVoto;
use Psr\Clock\ClockInterface;

/**
 * O Master libera (ou bloqueia) o BlueJus Voto para UM escritório (spec docs/specs/bluejus-voto.md, D5 e D7, fase A1b).
 * Liberar não liga o Voto sozinho: a chave `voto` de Telas e recursos também precisa estar ligada, e quem usa ainda
 * precisa da permissão `modules.voto.view`. Todo escritório liberado começa em modo demonstração.
 *
 * Escritório inexistente, suspenso ou excluído: {@see EscritorioNaoEncontradoException} (404). Sem linha de liberação,
 * ela nasce aqui.
 *
 * Trava do Master (spec docs/specs/multiproduto-contratacao-e-voto.md, D16): bloquear também encerra a contratação do
 * Voto com origem `master` (materializando, se preciso), e o dono não religa pelo Meu Perfil; liberar reabre a
 * contratação com origem `master` quando o Voto não está incluído. Tudo sob a trava das contratações do escritório.
 */
final class AlterarLiberacaoDoVotoUseCase
{
    public function __construct(
        private readonly TenantRepository $tenants,
        private readonly LiberacaoDoVotoRepository $liberacoes,
        private readonly EscopoDeAuditoriaDePlataforma $escopoDePlataforma,
        private readonly ContratacoesDoEscritorio $contratacoes,
        private readonly ClockInterface $relogio,
    ) {
    }

    public function executar(int $tenantId, bool $liberado, User $master): void
    {
        GuardaDoMasterDoVoto::exigir($master);
        $tenant = $this->escritorioAtivo($tenantId);
        $liberacao = $this->liberacoes->findDoTenantParaOMaster($tenant) ?? new LiberacaoDoVoto($tenant);
        // D16: só a TROCA de estado mexe na contratação. Salvar "bloqueado" num escritório que nunca foi liberado não é
        // trava (o estado de hoje deixa o dono ligar).
        $estava = null !== $liberacao->getId() && $liberacao->isLiberado();

        if ($liberado) {
            $liberacao->liberar();
        } else {
            $liberacao->bloquear();
        }

        // A auditoria vai para o escritório alterado, não para o da sessão do Master.
        $this->escopoDePlataforma->executarComoConta(
            (int) $tenant->getId(),
            fn () => $this->contratacoes->sobTrava($tenant, function () use ($tenant, $liberacao, $liberado, $estava, $master): void {
                $this->liberacoes->salvar($liberacao, flush: true);
                if ($estava !== $liberado) {
                    $this->ajustarContratacao($tenant, $liberado, $master);
                }
            }),
        );
    }

    /** D16: o bloqueio do Master vira trava; a liberação dele desfaz. */
    private function ajustarContratacao(Tenant $tenant, bool $liberado, User $master): void
    {
        $agora = \DateTimeImmutable::createFromInterface($this->relogio->now());
        if ($liberado) {
            $this->contratacoes->incluir($tenant, Produto::Voto, OrigemDaContratacao::Master, $master, $agora);

            return;
        }

        $this->contratacoes->excluir($tenant, Produto::Voto, OrigemDaContratacao::Master, $master, $agora);
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
