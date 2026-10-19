<?php

declare(strict_types=1);

namespace App\Pasta\Command;

use App\Entity\Tenant\Tenant;
use App\Pasta\Repository\PastaChecklistCobrancaRepository;
use App\Pasta\UseCase\CobrarChecklistDoEscritorioUseCase;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Cobrança automática do checklist de documentos (desenho 02; spec `docs/specs/pasta-cobranca-do-checklist.md`).
 * Cron diário (dia de semana, de manhã, `DEPLOY.md`). Cada escritório decide se hoje é dia útil (feriados
 * da Agenda); rodar duas vezes no mesmo dia não repete cobrança.
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
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $agora = new \DateTimeImmutable();
        $total = ['cobrancas' => 0, 'lembretes' => 0, 'falhas' => 0];
        foreach ($this->cobrancas->escritoriosComChecklist() as $tenantId) {
            $escritorio = $this->em->find(Tenant::class, $tenantId);
            if ($escritorio === null || !$this->em->isOpen()) {
                continue;
            }
            try {
                $r = $this->cobrar->executar($escritorio, $agora);
                $total['cobrancas'] += $r['cobrancas'];
                $total['lembretes'] += $r['lembretes'];
            } catch (\Throwable $falha) {
                ++$total['falhas'];
                $this->logger->error('Cobrança do checklist falhou num escritório', ['tenant' => $tenantId, 'erro' => $falha->getMessage()]);
            }
            $this->em->clear();
        }
        $io->writeln(sprintf('Cobranças: %d · lembretes: %d · escritórios com falha: %d', $total['cobrancas'], $total['lembretes'], $total['falhas']));

        return $total['falhas'] > 0 ? Command::FAILURE : Command::SUCCESS;
    }
}
