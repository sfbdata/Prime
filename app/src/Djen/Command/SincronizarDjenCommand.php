<?php

declare(strict_types=1);

namespace App\Djen\Command;

use App\Djen\DTO\PeriodoConsulta;
use App\Djen\DTO\ResultadoSincronizacao;
use App\Djen\UseCase\SincronizarPublicacoesDjenUseCase;
use App\Entity\Tenant\Tenant;
use App\Repository\TenantRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Job de captação (rodado por cron no host → docker exec) que sincroniza as publicações do DJEN de
 * cada escritório ativo, a partir das OABs que ele monitora.
 *
 * Multi-tenant no CLI: o TenantFilter fica DESLIGADO fora de um request, então iteramos os escritórios
 * e o UseCase recebe o Tenant EXPLICITAMENTE (toda query/criação é escopada por ele). Re-buscamos um
 * Tenant gerenciado a cada iteração (após em->clear()) para não persistir publicações referenciando um
 * Tenant destacado. Um lock (flock) impede execuções sobrepostas. É idempotente: falhas transitórias do
 * DJEN são registradas e recuperadas na próxima execução diária (dedupe por (tenant, djen_id)).
 */
#[AsCommand(
    name: 'app:djen:sincronizar',
    description: 'Capta publicações do DJEN para as OABs monitoradas de cada escritório.',
)]
final class SincronizarDjenCommand extends Command
{
    /** @var resource|null */
    private $lockHandle = null;

    public function __construct(
        private readonly TenantRepository $tenantRepository,
        private readonly SincronizarPublicacoesDjenUseCase $sincronizar,
        private readonly EntityManagerInterface $em,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Simula: consulta o DJEN mas não grava nem notifica.')
            ->addOption('tenant', null, InputOption::VALUE_REQUIRED, 'Restringe a um escritório específico (id).')
            ->addOption('dias', null, InputOption::VALUE_REQUIRED, 'Janela de dias até hoje (default 1 = apenas hoje).', '1');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $dryRun = (bool) $input->getOption('dry-run');
        $dias = max(1, (int) $input->getOption('dias'));

        if ($this->adquirirLock() === false) {
            $io->warning('Outra sincronização do DJEN já está em andamento. Abortando.');

            return Command::SUCCESS;
        }

        try {
            $ids = $this->resolverEscritorios($input, $io);
            if ($ids === null) {
                return Command::FAILURE;
            }

            if ($ids === []) {
                $io->note('Nenhum escritório ativo para sincronizar.');

                return Command::SUCCESS;
            }

            if ($dryRun === true) {
                $io->note('Modo simulação (--dry-run): nada será gravado nem notificado.');
            }

            $periodo = PeriodoConsulta::ultimosDias($dias, new \DateTimeImmutable('today'));
            $total = new ResultadoSincronizacao();
            $linhas = [];
            $falhasComando = 0;

            foreach ($ids as $id) {
                // Re-busca gerenciada (a iteração anterior fez em->clear()).
                $tenant = $this->tenantRepository->find($id);
                if (!$tenant instanceof Tenant) {
                    continue;
                }

                $nome = $tenant->getName() ?? '';

                try {
                    $resultado = $this->sincronizar->executar($tenant, $periodo, $dryRun);
                    $total->somar($resultado);
                    $linhas[] = [
                        $id,
                        $nome,
                        $resultado->getNovas(),
                        $resultado->getVinculadas(),
                        $resultado->getNotificados(),
                        \count($resultado->getFalhas()),
                    ];

                    foreach ($resultado->getFalhas() as $rotulo => $motivo) {
                        $io->text(sprintf('  <comment>#%d OAB %s: %s</comment>', $id, $rotulo, $motivo));
                    }
                } catch (\Throwable $e) {
                    ++$falhasComando;
                    $io->error(sprintf('Falha ao sincronizar o escritório #%d (%s): %s', $id, $nome, $e->getMessage()));
                } finally {
                    // Higiene de memória entre iterações (job potencialmente longo).
                    $this->em->clear();
                }
            }

            if ($linhas !== []) {
                $io->table(['ID', 'Escritório', 'Novas', 'Vinculadas', 'Notificados', 'Falhas OAB'], $linhas);
            }

            $io->success($total->resumo());

            if ($falhasComando > 0) {
                $io->warning(sprintf('%d escritório(s) falharam na sincronização — ver erros acima.', $falhasComando));

                return Command::FAILURE;
            }

            // Falhas transitórias (429/sobrecarga/timeout) são recuperadas na próxima execução → SUCCESS.
            // Falhas NÃO transitórias (config/resposta inválida) devem alarmar o cron → FAILURE.
            if ($total->temFalhaPermanente()) {
                $io->warning('Falha(s) NÃO transitória(s) no DJEN (ex.: configuração ausente ou resposta inválida) — verifique.');

                return Command::FAILURE;
            }

            return Command::SUCCESS;
        } finally {
            $this->liberarLock();
        }
    }

    /**
     * Resolve os ids dos escritórios a sincronizar. Retorna null quando um --tenant específico não existe.
     *
     * @return int[]|null
     */
    private function resolverEscritorios(InputInterface $input, SymfonyStyle $io): ?array
    {
        $tenantOpt = $input->getOption('tenant');

        if ($tenantOpt !== null) {
            $id = (int) $tenantOpt;
            $tenant = $this->tenantRepository->find($id);
            if (!$tenant instanceof Tenant) {
                $io->error(sprintf('Escritório #%d não encontrado.', $id));

                return null;
            }

            return [$id];
        }

        $ids = [];
        foreach ($this->tenantRepository->findAll() as $tenant) {
            if ($tenant->isActive() === true) {
                $ids[] = (int) $tenant->getId();
            }
        }

        return $ids;
    }

    /**
     * Lock exclusivo por lockfile (flock) para impedir execuções sobrepostas — coerente com a stack
     * minimalista do projeto (sem symfony/lock).
     */
    private function adquirirLock(): bool
    {
        $handle = fopen(sys_get_temp_dir() . '/jusprime-djen-sincronizar.lock', 'c');

        if ($handle === false) {
            return false;
        }

        if (flock($handle, \LOCK_EX | \LOCK_NB) === false) {
            fclose($handle);

            return false;
        }

        $this->lockHandle = $handle;

        return true;
    }

    private function liberarLock(): void
    {
        if (is_resource($this->lockHandle)) {
            flock($this->lockHandle, \LOCK_UN);
            fclose($this->lockHandle);
            $this->lockHandle = null;
        }
    }
}
