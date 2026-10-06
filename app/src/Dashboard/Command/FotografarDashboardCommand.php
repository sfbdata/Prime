<?php

declare(strict_types=1);

namespace App\Dashboard\Command;

use App\Dashboard\UseCase\FotografarDashboardUseCase;
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
 * Job diário (cron no host → docker exec) que fotografa o estoque do Dashboard de cada
 * escritório ativo: metas ativas, demandas ativas, metas vencidas e prazos próximos de cada
 * colaborador ativo, em `dashboard_foto`. É a fonte da tendência dessas quatro colunas — o
 * painel compara com a foto de `data_de − 1 dia` e, sem foto, não mostra tendência.
 *
 * Multi-tenant no CLI: o TenantFilter fica DESLIGADO fora de um request, então iteramos os
 * escritórios e o UseCase recebe o Tenant EXPLICITAMENTE. Re-buscamos um Tenant gerenciado a
 * cada iteração (após em->clear()). Idempotente: rodar o mesmo dia de novo regrava os números
 * (ON CONFLICT DO UPDATE), então dois crons sobrepostos ou uma repetição manual não duplicam.
 *
 * `--data` no passado é só para reparar uma noite perdida: vencidas e prazos próximos ficam
 * relativos ao fim daquele dia, mas o status das metas/pastas é o de AGORA (o passado não é
 * reconstruível — é por isso que a foto existe). O comando avisa quando isso acontece.
 */
#[AsCommand(
    name: 'app:dashboard:fotografar',
    description: 'Fotografa o estoque do Dashboard (ativas, vencidas, prazos) de cada colaborador.',
)]
final class FotografarDashboardCommand extends Command
{
    public function __construct(
        private readonly TenantRepository $tenantRepository,
        private readonly FotografarDashboardUseCase $fotografar,
        private readonly EntityManagerInterface $em,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('data', null, InputOption::VALUE_REQUIRED, 'Dia da foto (Y-m-d). Padrão: hoje.')
            ->addOption('tenant', null, InputOption::VALUE_REQUIRED, 'Restringe a um escritório específico (id).')
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Simula: calcula e mostra, mas não grava.');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io     = new SymfonyStyle($input, $output);
        $dryRun = (bool) $input->getOption('dry-run');
        $agora  = new \DateTimeImmutable();
        $hoje   = $agora->setTime(0, 0);

        $referencia = $this->lerData($input->getOption('data'), $hoje);
        if ($referencia === null) {
            $io->error('Data inválida: use --data=Y-m-d (ex.: 2026-10-06).');

            return Command::FAILURE;
        }

        if ($referencia > $hoje) {
            $io->error(sprintf('Não dá para fotografar o futuro (%s).', $referencia->format('Y-m-d')));

            return Command::FAILURE;
        }

        // Hoje: o instante da execução, como o painel ("agora"). Dia passado: fim daquele dia.
        $ehHoje  = $referencia->format('Y-m-d') === $hoje->format('Y-m-d');
        $momento = $ehHoje ? $agora : $referencia->setTime(23, 59, 59);
        if (!$ehHoje) {
            $io->warning('Data no passado: vencidas/prazos ficam relativos ao fim daquele dia, mas o status das metas e pastas é o de agora (aproximação).');
        }

        $ids = $this->resolverEscritorios($input, $io);
        if ($ids === null) {
            return Command::FAILURE;
        }

        if ($dryRun) {
            $io->note('Modo simulação (--dry-run): nada será gravado.');
        }

        $linhas        = [];
        $colaboradores = 0;
        $gravadas      = 0;
        $falhas        = 0;

        foreach ($ids as $id) {
            // Re-busca gerenciada (a iteração anterior fez em->clear()).
            $tenant = $this->tenantRepository->find($id);
            if (!$tenant instanceof Tenant) {
                continue;
            }

            $nome = $tenant->getName() ?? '';

            try {
                $resultado      = $this->fotografar->executar($tenant, $referencia, $momento, $dryRun);
                $colaboradores += $resultado->colaboradores();
                $gravadas      += $resultado->gravadas;
                $linhas[]       = [$id, $nome, $resultado->colaboradores(), $resultado->gravadas];
            } catch (\Throwable $e) {
                ++$falhas;
                $io->error(sprintf('Falha ao fotografar o escritório #%d (%s): %s', $id, $nome, $e->getMessage()));
            } finally {
                $this->em->clear();
            }
        }

        if ($linhas !== []) {
            $io->table(['ID', 'Escritório', 'Colaboradores', 'Gravadas'], $linhas);
        }

        $io->writeln(sprintf(
            'referencia=%s modo=%s escritorios=%d colaboradores=%d gravadas=%d falhas=%d',
            $referencia->format('Y-m-d'),
            $dryRun ? 'simulacao' : 'gravacao',
            \count($linhas),
            $colaboradores,
            $gravadas,
            $falhas,
        ));

        return $falhas > 0 ? Command::FAILURE : Command::SUCCESS;
    }

    private function lerData(mixed $valor, \DateTimeImmutable $hoje): ?\DateTimeImmutable
    {
        if ($valor === null || trim((string) $valor) === '') {
            return $hoje;
        }

        $valor = trim((string) $valor);
        $data  = \DateTimeImmutable::createFromFormat('!Y-m-d', $valor, $hoje->getTimezone());
        if ($data === false || $data->format('Y-m-d') !== $valor) {
            return null;
        }

        return $data;
    }

    /**
     * Ids dos escritórios a fotografar: o `--tenant` pedido (mesmo inativo, porque foi pedido),
     * ou todos os ativos. Null quando o `--tenant` não existe.
     *
     * @return int[]|null
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
