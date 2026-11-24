<?php

declare(strict_types=1);

namespace App\Master\UseCase;

use App\Entity\Tenant\Tenant;
use App\Master\DTO\ContextoDosVinculos;
use App\Master\DTO\EscritorioParaConvenio;
use App\Master\DTO\ResultadoDaSincronizacao;
use App\Master\Entity\Convenio;
use App\Master\Entity\ConfiguracaoGlobal;
use App\Master\Enum\MotivoDoFimDoVinculo;
use App\Master\Repository\ConfiguracaoGlobalRepository;
use App\Master\Repository\ConvenioDaUfRepository;
use App\Master\Repository\ConvenioRepository;
use App\Master\Repository\ConvenioVinculoRepository;
use App\Master\Service\ChaveDoConvenio;
use App\Master\Service\RegraDosVinculosDeConvenio;
use App\Shared\Audit\EscopoDeAuditoriaDePlataforma;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Clock\ClockInterface;

/**
 * Grava os vínculos dos escritórios com os convênios (spec docs/specs/convenios.md, §5.1 e §5.2, PR 3). Idempotente;
 * a regra mora na {@see RegraDosVinculosDeConvenio}. Chamado:
 *
 * - pela tarefa diária (`app:convenios:sincronizar-vinculos`), para todos. A primeira volta completa, com a chave
 *   geral ligada, marca `convenio.vinculosSincronizadosEm`: daí em diante o convênio OAB não vale mais implícito;
 * - quando o escritório muda de estado (fecha com `estado_mudou`);
 * - quando o Master muda o convênio de uma UF (os escritórios daquela UF).
 *
 * Cada escritório numa transação curta, com trava por escritório (a tarefa e um gancho podem cruzar). A Trilha grava no
 * escritório do vínculo (escopo de plataforma com a conta alvo), nunca no da sessão de quem disparou.
 */
final class SincronizarVinculosDeConvenioUseCase
{
    public function __construct(
        private readonly ConvenioVinculoRepository $vinculos,
        private readonly ConvenioRepository $convenios,
        private readonly ConvenioDaUfRepository $excecoesPorUf,
        private readonly ChaveDoConvenio $chave,
        private readonly ConfiguracaoGlobalRepository $configuracoes,
        private readonly RegraDosVinculosDeConvenio $regra,
        private readonly EscopoDeAuditoriaDePlataforma $escopoDePlataforma,
        private readonly EntityManagerInterface $em,
        private readonly Connection $conexao,
        private readonly ClockInterface $relogio,
    ) {
    }

    /** A volta diária, para todos os escritórios. */
    public function executar(): ResultadoDaSincronizacao
    {
        $contexto = $this->contexto(MotivoDoFimDoVinculo::UfDesligada);
        $resultado = $this->sincronizar($this->vinculos->escritoriosParaConvenio(), $contexto);
        if ($contexto->chaveLigada) {
            $this->marcarSincronizados($contexto->agora);
        }

        return $resultado;
    }

    /** Um escritório só, logo depois de ele mudar de estado. */
    public function doEscritorio(int $tenantId, MotivoDoFimDoVinculo $motivoDaUf = MotivoDoFimDoVinculo::EstadoMudou): ResultadoDaSincronizacao
    {
        return $this->sincronizar($this->vinculos->escritoriosParaConvenio([$tenantId]), $this->contexto($motivoDaUf));
    }

    /** Os escritórios de uma UF, logo depois de o Master mudar o convênio dela. */
    public function daUf(string $uf): ResultadoDaSincronizacao
    {
        $daUf = array_values(array_filter($this->vinculos->escritoriosParaConvenio(), static fn (EscritorioParaConvenio $e): bool => $e->uf === $uf));

        return $this->sincronizar($daUf, $this->contexto(MotivoDoFimDoVinculo::UfDesligada));
    }

    /** @param list<EscritorioParaConvenio> $escritorios */
    private function sincronizar(array $escritorios, ContextoDosVinculos $contexto): ResultadoDaSincronizacao
    {
        $resultado = new ResultadoDaSincronizacao();
        foreach ($escritorios as $escritorio) {
            $this->conexao->transactional(function () use ($escritorio, $contexto, $resultado): void {
                $this->conexao->executeQuery("SELECT pg_advisory_xact_lock(hashtextextended('convenio_vinculo:' || ?, 0))", [$escritorio->tenantId]);
                $this->sincronizarUm($escritorio, $contexto, $resultado);
            });
        }

        return $resultado;
    }

    private function sincronizarUm(EscritorioParaConvenio $escritorio, ContextoDosVinculos $contexto, ResultadoDaSincronizacao $resultado): void
    {
        $abertos = $this->vinculos->abertosDosTenants([$escritorio->tenantId])[$escritorio->tenantId] ?? [];
        $tenant = $this->em->getReference(Tenant::class, $escritorio->tenantId);
        $mudancas = $this->regra->aplicar($tenant, $escritorio, $abertos, $contexto);
        if ($mudancas->houveMudanca()) {
            $this->escopoDePlataforma->executarComoConta($escritorio->tenantId, function () use ($mudancas): void {
                if ($mudancas->novo !== null) {
                    $this->vinculos->salvar($mudancas->novo);
                }
                $this->em->flush();
            });
        }
        $resultado->somar($mudancas);
    }

    private function contexto(MotivoDoFimDoVinculo $motivoDaUf): ContextoDosVinculos
    {
        return new ContextoDosVinculos(
            $this->convenios->porChave(Convenio::CHAVE_OAB),
            $this->excecoesPorUf->todosPorUf(),
            $this->chave->ativo(),
            \DateTimeImmutable::createFromInterface($this->relogio->now()),
            $motivoDaUf,
        );
    }

    private function marcarSincronizados(\DateTimeImmutable $agora): void
    {
        $configuracao = $this->configuracoes->buscarUnica() ?? new ConfiguracaoGlobal();
        if ($configuracao->getVinculosDeConvenioSincronizadosEm() !== null) {
            return;
        }
        $configuracao->marcarVinculosDeConvenioSincronizados($agora);
        $this->escopoDePlataforma->executar(fn () => $this->configuracoes->salvar($configuracao, true));
    }
}
