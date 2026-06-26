<?php

namespace App\Command;

use App\Ponto\Entity\Feriado;
use App\Entity\Tenant\Tenant;
use App\Ponto\Repository\FeriadoRepository;
use App\Repository\TenantRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Popula os feriados nacionais brasileiros em todos os tenants que ainda não os possuem.
 *
 * Idempotente: compara por mês/dia — não duplica feriados já cadastrados.
 *
 * Uso:
 *   docker exec jusprime_php_dev bash -c 'cd app && php bin/console app:seed-feriados-nacionais'
 *   docker exec jusprime_php_dev bash -c 'cd app && php bin/console app:seed-feriados-nacionais --dry-run'
 */
#[AsCommand(
    name: 'app:seed-feriados-nacionais',
    description: 'Adiciona os feriados nacionais brasileiros em todos os tenants que ainda não os possuem',
)]
class SeedFeriadosNacionaisCommand extends Command
{
    private const FERIADOS_NACIONAIS = [
        ['nome' => 'Confraternização Universal (Ano Novo)', 'mes' => 1,  'dia' => 1],
        ['nome' => 'Tiradentes',                            'mes' => 4,  'dia' => 21],
        ['nome' => 'Dia Mundial do Trabalho',               'mes' => 5,  'dia' => 1],
        ['nome' => 'Independência do Brasil',               'mes' => 9,  'dia' => 7],
        ['nome' => 'Nossa Senhora Aparecida',               'mes' => 10, 'dia' => 12],
        ['nome' => 'Finados',                               'mes' => 11, 'dia' => 2],
        ['nome' => 'Proclamação da República',              'mes' => 11, 'dia' => 15],
        ['nome' => 'Consciência Negra',                     'mes' => 11, 'dia' => 20],
        ['nome' => 'Natal',                                 'mes' => 12, 'dia' => 25],
    ];

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly TenantRepository $tenantRepository,
        private readonly FeriadoRepository $feriadoRepository,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('dry-run', null, InputOption::VALUE_NONE, 'Simula sem persistir nada');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $dryRun = $input->getOption('dry-run');

        if ($dryRun) {
            $io->note('Modo dry-run — nenhuma alteração será salva.');
        }

        $tenants = $this->tenantRepository->findAll();

        if (empty($tenants)) {
            $io->warning('Nenhum tenant encontrado.');
            return Command::SUCCESS;
        }

        $totalAdicionados = 0;

        foreach ($tenants as $tenant) {
            $adicionados = $this->processarTenant($tenant, $io, $dryRun);
            $totalAdicionados += $adicionados;
        }

        if ($dryRun) {
            $io->success(sprintf('Dry-run concluído. Seriam adicionados %d feriado(s) no total.', $totalAdicionados));
        } else {
            $io->success(sprintf('%d feriado(s) adicionado(s) no total.', $totalAdicionados));
        }

        return Command::SUCCESS;
    }

    private function processarTenant(Tenant $tenant, SymfonyStyle $io, bool $dryRun): int
    {
        $existentes = $this->feriadoRepository->findByTenant($tenant);

        $cadastrados = [];
        foreach ($existentes as $f) {
            $cadastrados[$f->getData()->format('m-d')] = true;
        }

        $adicionados = 0;

        foreach (self::FERIADOS_NACIONAIS as $dado) {
            $chave = sprintf('%02d-%02d', $dado['mes'], $dado['dia']);

            if (isset($cadastrados[$chave])) {
                continue;
            }

            $io->writeln(sprintf(
                '  [%s] Adicionando: %s (%s)',
                $tenant->getName(),
                $dado['nome'],
                sprintf('%02d/%02d', $dado['dia'], $dado['mes'])
            ));

            if (!$dryRun) {
                $feriado = new Feriado();
                $feriado->setNome($dado['nome']);
                $feriado->setData(new \DateTime(sprintf('2000-%02d-%02d', $dado['mes'], $dado['dia'])));
                $feriado->setRecorrente(true);
                $feriado->setTenant($tenant);

                $this->em->persist($feriado);
            }

            $adicionados++;
        }

        if ($adicionados === 0) {
            $io->writeln(sprintf('  [%s] Todos os feriados nacionais já estavam cadastrados.', $tenant->getName()));
        } else {
            if (!$dryRun) {
                $this->em->flush();
            }
        }

        return $adicionados;
    }
}
