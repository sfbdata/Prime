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
use Psr\Log\LoggerInterface;

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
        private readonly LoggerInterface $logger,
    ) {
    }

    /**
     * A volta diária, para todos os escritórios. Um escritório que falha vai para o log e a volta segue (o comando
     * acusa a falha); só para se a falha fechou o EntityManager.
     */
    public function executar(): ResultadoDaSincronizacao
    {
        $ids = array_map(static fn (EscritorioParaConvenio $e): int => $e->tenantId, $this->vinculos->escritoriosParaConvenio());
        $resultado = $this->sincronizar($ids, MotivoDoFimDoVinculo::UfDesligada, seguirAposFalha: true);
        if ($this->chave->ativo()) {
            $this->marcarSincronizados(\DateTimeImmutable::createFromInterface($this->relogio->now()));
        }

        return $resultado;
    }

    /** Um escritório só, logo depois de ele mudar de estado. */
    public function doEscritorio(int $tenantId, MotivoDoFimDoVinculo $motivoDaUf = MotivoDoFimDoVinculo::EstadoMudou): ResultadoDaSincronizacao
    {
        return $this->sincronizar([$tenantId], $motivoDaUf, seguirAposFalha: false);
    }

    /** Os escritórios de uma UF, logo depois de o Master mudar o convênio dela. */
    public function daUf(string $uf): ResultadoDaSincronizacao
    {
        $daUf = array_filter($this->vinculos->escritoriosParaConvenio(), static fn (EscritorioParaConvenio $e): bool => $e->uf === $uf);

        return $this->sincronizar(array_values(array_map(static fn (EscritorioParaConvenio $e): int => $e->tenantId, $daUf)), MotivoDoFimDoVinculo::UfDesligada, seguirAposFalha: false);
    }

    /** @param list<int> $tenantIds */
    private function sincronizar(array $tenantIds, MotivoDoFimDoVinculo $motivoDaUf, bool $seguirAposFalha): ResultadoDaSincronizacao
    {
        $resultado = new ResultadoDaSincronizacao();
        $contexto = $this->contexto($motivoDaUf);
        foreach ($tenantIds as $tenantId) {
            try {
                $this->conexao->transactional(fn () => $this->sincronizarUm($tenantId, $contexto, $resultado));
            } catch (\Throwable $e) {
                $this->logger->error('Convênios: falha ao sincronizar os vínculos de um escritório.', ['tenantId' => $tenantId, 'erro' => $e::class, 'mensagem' => $e->getMessage()]);
                if (!$seguirAposFalha || !$this->em->isOpen()) {
                    throw $e;
                }
                ++$resultado->falhas;
                // Descarta o que ficou pela metade, para o próximo escritório não gravar junto; o contexto é relido.
                $this->em->clear();
                $contexto = $this->contexto($motivoDaUf);
            }
        }

        return $resultado;
    }

    /** Depois da trava, relê o escritório: um gancho pode ter mudado o estado enquanto a volta diária andava. */
    private function sincronizarUm(int $tenantId, ContextoDosVinculos $contexto, ResultadoDaSincronizacao $resultado): void
    {
        $this->conexao->executeQuery("SELECT pg_advisory_xact_lock(hashtextextended('convenio_vinculo:' || ?, 0))", [$tenantId]);
        $escritorio = $this->vinculos->escritoriosParaConvenio([$tenantId])[0] ?? null;
        if ($escritorio === null) {
            return;
        }
        $abertos = $this->vinculos->abertosDosTenants([$tenantId])[$tenantId] ?? [];
        $mudancas = $this->regra->aplicar($this->em->getReference(Tenant::class, $tenantId), $escritorio, $abertos, $contexto);
        if ($mudancas->houveMudanca()) {
            $this->escopoDePlataforma->executarComoConta($tenantId, function () use ($mudancas): void {
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
