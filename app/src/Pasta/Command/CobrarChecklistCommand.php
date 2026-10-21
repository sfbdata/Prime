<?php

declare(strict_types=1);

namespace App\Pasta\Command;

use App\Entity\Tenant\Tenant;
use App\Pasta\Repository\PastaChecklistCobrancaRepository;
use App\Pasta\UseCase\CobrarChecklistDoEscritorioUseCase;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\Persistence\ManagerRegistry;
use Psr\Log\LoggerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Cobrança automática do checklist de documentos (desenho 02; spec `docs/specs/pasta-cobranca-do-checklist.md`).
 * Cron diário (dia de semana, 9h de Brasília, `DEPLOY.md`). Cada escritório decide se hoje é dia útil (feriados
 * da Agenda); rodar duas vezes no mesmo dia não repete cobrança.
 *
 * Para a primeira rodada com um piloto: `--escritorio=ID` roda só aquele escritório e `--simular` lista quem
 * seria cobrado sem enviar nada (tudo roda numa transação desfeita no fim).
 *
 * Um escritório com erro não para os outros: vira log e a rodada segue.
 */
#[AsCommand(
    name: 'app:pasta:cobrar-checklist',
    description: 'Cobra os documentos pendentes do checklist das pastas, audita itens sem anexo e lembra checklists desativados.',
)]
final class CobrarChecklistCommand extends Command
{
    public function __construct(
        private readonly PastaChecklistCobrancaRepository $cobrancas,
        private readonly CobrarChecklistDoEscritorioUseCase $cobrar,
        private readonly EntityManagerInterface $em,
        private readonly LoggerInterface $logger,
        private readonly ManagerRegistry $doctrine,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('escritorio', null, InputOption::VALUE_REQUIRED, 'Roda só este escritório (id), para um piloto')
            ->addOption('simular', null, InputOption::VALUE_NONE, 'Lista quem seria cobrado, sem enviar nem gravar nada');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $simular = (bool) $input->getOption('simular');
        $escritorios = $this->escritorios($input->getOption('escritorio'));
        if ($simular) {
            $this->em->getConnection()->beginTransaction();
        }

        try {
            $total = $this->rodar($escritorios, $io);
        } finally {
            if ($simular && $this->em->getConnection()->isTransactionActive()) {
                $this->em->getConnection()->rollBack();
                $this->em->clear();
            }
        }

        $io->writeln(sprintf('%sCobranças: %d · lembretes: %d · escritórios com falha: %d', $simular ? 'SIMULAÇÃO (nada foi enviado). ' : '', $total['cobrancas'], $total['lembretes'], $total['falhas']));

        return $total['falhas'] > 0 ? Command::FAILURE : Command::SUCCESS;
    }

    /** @return list<int> */
    private function escritorios(mixed $opcao): array
    {
        if ($opcao === null || $opcao === '') {
            return $this->cobrancas->escritoriosComChecklist();
        }

        return [(int) $opcao];
    }

    /**
     * @param list<int> $escritorios
     *
     * @return array{cobrancas: int, lembretes: int, falhas: int}
     */
    private function rodar(array $escritorios, SymfonyStyle $io): array
    {
        $agora = new \DateTimeImmutable();
        $total = ['cobrancas' => 0, 'lembretes' => 0, 'falhas' => 0];
        foreach ($escritorios as $tenantId) {
            try {
                $escritorio = $this->em->find(Tenant::class, $tenantId);
                if ($escritorio !== null) {
                    $r = $this->cobrar->executar($escritorio, $agora);
                    $total['cobrancas'] += $r['cobrancas'];
                    $total['lembretes'] += $r['lembretes'];
                    $io->listing(array_map(static fn (string $linha): string => 'Escritório ' . $tenantId . ' · ' . $linha, $r['detalhes']));
                }
            } catch (\Throwable $falha) {
                ++$total['falhas'];
                $this->logger->error('Cobrança do checklist falhou num escritório', ['tenant' => $tenantId, 'erro' => $falha->getMessage()]);
                $this->reabrirSeFechou();
            }
            $this->em->clear();
        }

        return $total;
    }

    /**
     * Erro de banco fecha o EntityManager; sem reabrir, todos os escritórios seguintes falhariam também. O
     * serviço `doctrine.orm.entity_manager` é preguiçoso: depois do reset, as referências injetadas (o UseCase,
     * os repositórios) passam a usar o novo.
     */
    private function reabrirSeFechou(): void
    {
        if (!$this->em->isOpen()) {
            $this->doctrine->resetManager();
        }
    }
}
