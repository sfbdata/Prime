<?php

declare(strict_types=1);

namespace App\Pasta\Command;

use App\Entity\Tenant\Tenant;
use App\Pasta\UseCase\LancarAlertasDaControladoriaUseCase;
use App\Repository\TenantRepository;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Rotina de segunda-feira da Controladoria (spec docs/specs/central-documentos-controladoria.md, "Rotina de
 * segunda-feira"): lança no Registro de cada pasta ativa só o alerta NOVO. Agendado em
 * {@see \App\Shared\Scheduler\AgendaDeTarefas} (segunda, 7h30 de Brasília).
 *
 * Multi-tenant no CLI: o TenantFilter fica desligado fora de um request; cada escritório é rebuscado e passado
 * ao UseCase, que escopa tudo por ele. Lock (flock) contra execuções sobrepostas.
 */
#[AsCommand(
    name: 'app:pastas:rotina-controladoria',
    description: 'Lança no Registro de cada pasta ativa os alertas novos da Controladoria (rotina de segunda-feira).',
)]
final class RotinaDaControladoriaCommand extends Command
{
    /** @var resource|null */
    private $lockHandle = null;

    public function __construct(
        private readonly TenantRepository $tenantRepository,
        private readonly LancarAlertasDaControladoriaUseCase $lancar,
        private readonly EntityManagerInterface $em,
        private readonly ManagerRegistry $doctrine,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Simula: analisa e conta, mas não grava.')
            ->addOption('simular', null, InputOption::VALUE_NONE, 'Como --dry-run, e lista quantos alertas novos lançaria por escritório e tipo.')
            ->addOption('tenant', null, InputOption::VALUE_REQUIRED, 'Restringe a um escritório específico (id).');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io     = new SymfonyStyle($input, $output);
        $simular = (bool) $input->getOption('simular');
        $dryRun = $simular || (bool) $input->getOption('dry-run');

        if (!$this->adquirirLock()) {
            $io->warning('Outra rotina da Controladoria já está em andamento. Abortando.');

            return Command::SUCCESS;
        }

        try {
            $ids = $this->resolverEscritorios($input, $io);
            if ($ids === null) {
                return Command::FAILURE;
            }
            if ($dryRun) {
                $io->note('Modo simulação: nada será gravado.');
            }

            return $this->lancarEmTodos($ids, $dryRun, $simular, $io);
        } finally {
            $this->liberarLock();
        }
    }

    /**
     * @param list<int> $ids
     */
    private function lancarEmTodos(array $ids, bool $dryRun, bool $detalhar, SymfonyStyle $io): int
    {
        $linhas = [];
        $tipos  = [];
        $falhas = 0;
        foreach ($ids as $id) {
            $tenant = $this->tenantRepository->find($id);
            if (!$tenant instanceof Tenant) {
                continue;
            }

            try {
                $resultado = $this->lancar->executar($tenant, $dryRun);
                foreach ($resultado->porTipo as $tipo => $qtd) {
                    $tipos[] = [$id, (string) $tenant->getName(), $tipo, $qtd];
                }
                $linhas[]  = [$id, (string) $tenant->getName(), $resultado->pastas, $resultado->pastasComAlertaNovo, $resultado->alertasNovos];
            } catch (\Throwable $e) {
                ++$falhas;
                $io->error(sprintf('Falha no escritório #%d: %s', $id, $e->getMessage()));
                $this->reabrirSeFechou();
            } finally {
                $this->em->clear();
            }
        }

        if ($linhas !== []) {
            $io->table(['ID', 'Escritório', 'Pastas', 'Com alerta novo', 'Alertas novos'], $linhas);
        }

        if ($detalhar && $tipos !== []) {
            $io->table(['ID', 'Escritório', 'Tipo', 'Cartões'], $tipos);
        }

        return $falhas > 0 ? Command::FAILURE : Command::SUCCESS;
    }

    /**
     * Erro de banco num escritório fecha o EntityManager; sem reabrir, todos os escritórios seguintes
     * falhariam com "EntityManager is closed". O EntityManager injetado é o proxy do serviço, que o
     * `resetManager()` reinicia no lugar.
     */
    private function reabrirSeFechou(): void
    {
        if (!$this->em->isOpen()) {
            $this->doctrine->resetManager();
        }
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
        $handle = fopen(sys_get_temp_dir() . '/jusprime-rotina-controladoria.lock', 'c');
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
