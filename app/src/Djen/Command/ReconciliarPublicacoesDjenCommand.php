<?php

declare(strict_types=1);

namespace App\Djen\Command;

use App\Djen\UseCase\ReconciliarPublicacoesComProcessosUseCase;
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
 * Religa ao Processo as publicações que ficaram avulsas porque o processo foi cadastrado DEPOIS da
 * captura. Conserta o passivo; o mesmo UseCase roda ao fim de cada sincronização, para o buraco não
 * reabrir.
 *
 * Multi-tenant no CLI: o TenantFilter fica desligado fora de um request, então iteramos os
 * escritórios e o UseCase recebe o Tenant EXPLICITAMENTE.
 *
 * Rode `--dry-run` antes: ele conta o que faria sem gravar nada.
 */
#[AsCommand(
    name: 'app:djen:reconciliar',
    description: 'Vincula ao Processo as publicações avulsas cujo número CNJ já existe no cadastro.',
)]
final class ReconciliarPublicacoesDjenCommand extends Command
{
    public function __construct(
        private readonly TenantRepository $tenantRepository,
        private readonly ReconciliarPublicacoesComProcessosUseCase $reconciliar,
        private readonly EntityManagerInterface $em,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Simula: conta quantas seriam religadas e não grava.')
            ->addOption('tenant', null, InputOption::VALUE_REQUIRED, 'Restringe a um escritório específico (id).');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io     = new SymfonyStyle($input, $output);
        $dryRun = (bool) $input->getOption('dry-run');

        $ids = $this->resolverEscritorios($input, $io);
        if ($ids === null) {
            return Command::FAILURE;
        }

        if ($dryRun) {
            $io->note('Modo simulação (--dry-run): nada será gravado.');
        }

        $linhas = 0;
        $total  = 0;
        $tabela = [];

        foreach ($ids as $id) {
            $tenant = $this->tenantRepository->find($id);
            if (!$tenant instanceof Tenant) {
                continue;
            }

            $religadas = $this->reconciliar->executar($tenant, $dryRun);
            $total    += $religadas;
            ++$linhas;

            if ($religadas > 0) {
                $tabela[] = [$id, $tenant->getName() ?? '', $religadas];
            }

            $this->em->clear();
        }

        if ($tabela !== []) {
            $io->table(['ID', 'Escritório', $dryRun ? 'Seriam religadas' : 'Religadas'], $tabela);
        }

        $io->success(sprintf(
            '%d publicação(ões) %s em %d escritório(s).',
            $total,
            $dryRun ? 'seriam religadas' : 'religadas ao processo',
            $linhas,
        ));

        return Command::SUCCESS;
    }

    /**
     * @return int[]|null null quando um --tenant específico não existe
     */
    private function resolverEscritorios(InputInterface $input, SymfonyStyle $io): ?array
    {
        $tenantOpt = $input->getOption('tenant');

        if ($tenantOpt !== null) {
            $id     = (int) $tenantOpt;
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
}
