<?php

declare(strict_types=1);

namespace App\Pasta\Command;

use App\Entity\Tenant\Tenant;
use App\Pasta\UseCase\EnviarRelatorioSemProcessoUseCase;
use App\Repository\TenantRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Envio AUTOMÁTICO do relatório de pastas sem processo à Controladoria (spec
 * relatorio-processos-controladoria, D5; desenho 1.2.3 `spEnviar` L4566).
 *
 * Agendado no cron da VPS todo dia às 10h05 de Brasília (linha em DEPLOY.md). Quem decide se o dia
 * é de envio é o UseCase: só na primeira segunda-feira do mês, a partir das 10h, e uma vez por mês
 * por escritório (o histórico `relatorio_sem_processo_envio` trava a repetição). Nos outros dias o
 * comando passa pelos escritórios e não envia nada.
 *
 * Multi-tenant no CLI: o TenantFilter fica desligado fora de um request; cada escritório é
 * rebuscado (gerenciado) e passado ao UseCase, que escopa tudo por ele. Lock (flock) contra
 * execuções sobrepostas, como o `app:djen:sincronizar`.
 */
#[AsCommand(
    name: 'app:pastas:relatorio-sem-processo',
    description: 'Envia à Controladoria o relatório mensal de pastas sem processo (primeira segunda-feira do mês).',
)]
final class RelatorioSemProcessoCommand extends Command
{
    /** @var resource|null */
    private $lockHandle = null;

    public function __construct(
        private readonly TenantRepository $tenantRepository,
        private readonly EnviarRelatorioSemProcessoUseCase $enviar,
        private readonly EntityManagerInterface $em,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Simula: analisa e conta os avisos, mas não grava nem notifica.')
            ->addOption('tenant', null, InputOption::VALUE_REQUIRED, 'Restringe a um escritório específico (id).');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io     = new SymfonyStyle($input, $output);
        $dryRun = (bool) $input->getOption('dry-run');

        if (!$this->adquirirLock()) {
            $io->warning('Outro envio do relatório de pastas sem processo já está em andamento. Abortando.');

            return Command::SUCCESS;
        }

        try {
            $ids = $this->resolverEscritorios($input, $io);
            if ($ids === null) {
                return Command::FAILURE;
            }
            if ($dryRun) {
                $io->note('Modo simulação (--dry-run): nada será gravado nem notificado.');
            }

            return $this->enviarParaTodos($ids, $dryRun, $io);
        } finally {
            $this->liberarLock();
        }
    }

    /**
     * @param list<int> $ids
     */
    private function enviarParaTodos(array $ids, bool $dryRun, SymfonyStyle $io): int
    {
        $linhas = [];
        $falhas = 0;
        foreach ($ids as $id) {
            $tenant = $this->tenantRepository->find($id);
            if (!$tenant instanceof Tenant) {
                continue;
            }

            try {
                $resultado = $this->enviar->executar($tenant, null, true, $dryRun);
                $linhas[]  = [$id, (string) $tenant->getName(), $resultado->enviado ? 'sim' : 'não', $resultado->avisados, $resultado->mensagem];
            } catch (\Throwable $e) {
                ++$falhas;
                $io->error(sprintf('Falha no escritório #%d: %s', $id, $e->getMessage()));
            } finally {
                $this->em->clear();
            }
        }

        if ($linhas !== []) {
            $io->table(['ID', 'Escritório', 'Enviado', 'Avisos', 'Resultado'], $linhas);
        }

        return $falhas > 0 ? Command::FAILURE : Command::SUCCESS;
    }

    /**
     * @return list<int>|null nulo quando o --tenant pedido não existe
     */
    private function resolverEscritorios(InputInterface $input, SymfonyStyle $io): ?array
    {
        $opcao = $input->getOption('tenant');
        if ($opcao !== null) {
            $tenant = $this->tenantRepository->find((int) $opcao);
            if (!$tenant instanceof Tenant) {
                $io->error(sprintf('Escritório #%d não encontrado.', (int) $opcao));

                return null;
            }

            return [(int) $tenant->getId()];
        }

        $ids = [];
        foreach ($this->tenantRepository->findAll() as $tenant) {
            if ($tenant->isActive() === true) {
                $ids[] = (int) $tenant->getId();
            }
        }

        return $ids;
    }

    private function adquirirLock(): bool
    {
        $handle = fopen(sys_get_temp_dir() . '/jusprime-relatorio-sem-processo.lock', 'c');
        if ($handle === false) {
            return false;
        }
        if (!flock($handle, \LOCK_EX | \LOCK_NB)) {
            fclose($handle);

            return false;
        }
        $this->lockHandle = $handle;

        return true;
    }

    private function liberarLock(): void
    {
        if (\is_resource($this->lockHandle)) {
            flock($this->lockHandle, \LOCK_UN);
            fclose($this->lockHandle);
            $this->lockHandle = null;
        }
    }
}
