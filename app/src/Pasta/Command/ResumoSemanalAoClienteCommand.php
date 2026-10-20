<?php

declare(strict_types=1);

namespace App\Pasta\Command;

use App\Entity\Tenant\Tenant;
use App\Pasta\UseCase\EnviarResumoSemanalAoClienteUseCase;
use App\Repository\TenantRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * "Resumo semanal" do envio automático ao cliente (spec push-envio-automatico §3): para cada pasta
 * cujo cliente ligou a chave, um e-mail com as movimentações da semana anterior (segunda a domingo).
 *
 * Agendado no cron da VPS toda segunda-feira (linha em DEPLOY.md; configurar é do dono). A semana é
 * contada do relógio, e o histórico `envio_ao_cliente` trava a repetição: rodar de novo (ou na terça,
 * se a segunda falhou) não manda o mesmo resumo duas vezes.
 *
 * Multi-tenant no CLI: o TenantFilter fica desligado fora de um request; cada escritório é rebuscado
 * (gerenciado) e passado ao UseCase, que escopa tudo por ele. Lock (flock) contra execuções
 * sobrepostas, como o `app:djen:sincronizar` e o `app:pastas:relatorio-sem-processo`.
 */
#[AsCommand(
    name: 'app:push:resumo-semanal-cliente',
    description: 'Envia aos clientes com o resumo semanal ligado as movimentações da semana anterior.',
)]
final class ResumoSemanalAoClienteCommand extends Command
{
    /** @var resource|null */
    private $lockHandle = null;

    public function __construct(
        private readonly TenantRepository $tenantRepository,
        private readonly EnviarResumoSemanalAoClienteUseCase $enviar,
        private readonly EntityManagerInterface $em,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Simula: conta as pastas e os e-mails, mas não envia nem grava.')
            ->addOption('tenant', null, InputOption::VALUE_REQUIRED, 'Restringe a um escritório específico (id).');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $dryRun = (bool) $input->getOption('dry-run');

        if (!$this->adquirirLock()) {
            $io->warning('Outro envio do resumo semanal ao cliente já está em andamento. Abortando.');

            return Command::SUCCESS;
        }

        try {
            $ids = $this->resolverEscritorios($input, $io);
            if ($ids === null) {
                return Command::FAILURE;
            }
            if ($dryRun) {
                $io->note('Modo simulação (--dry-run): nada será enviado nem gravado.');
            }

            return $this->enviarParaTodos($ids, $dryRun, $io);
        } finally {
            $this->liberarLock();
        }
    }

    /** @param list<int> $ids */
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
                $resultado = $this->enviar->executar($tenant, $dryRun);
                $linhas[] = [$id, (string) $tenant->getName(), $resultado->semana(), $resultado->pastas, $resultado->emails];
            } catch (\Throwable $e) {
                ++$falhas;
                $io->error(sprintf('Falha no escritório #%d: %s', $id, $e->getMessage()));
            } finally {
                $this->em->clear();
            }
        }

        if ($linhas !== []) {
            $io->table(['ID', 'Escritório', 'Semana', 'Pastas', 'E-mails'], $linhas);
        }

        return $falhas > 0 ? Command::FAILURE : Command::SUCCESS;
    }

    /** @return list<int>|null nulo quando o --tenant pedido não existe */
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
        $handle = fopen(sys_get_temp_dir() . '/jusprime-push-resumo-semanal-cliente.lock', 'c');
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
