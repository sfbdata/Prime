<?php

declare(strict_types=1);

namespace App\Comercial\Command;

use App\Comercial\Exception\CampanhaRecusada;
use App\Comercial\UseCase\EncerrarCampanhaUseCase;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * O freio de console das campanhas (spec comercial-e-emails.md §8.1.5): `app:comercial:encerrar-campanha <id>` encerra
 * qualquer campanha ativa, sem deploy. A chave `COMERCIAL_CAMPANHAS_LIGADAS` é a parada de emergência de todas.
 */
#[AsCommand(name: 'app:comercial:encerrar-campanha', description: 'Encerra uma campanha de e-mail ativa (o que estava na fila não sai)')]
final class EncerrarCampanhaCommand extends Command
{
    public function __construct(private readonly EncerrarCampanhaUseCase $encerrar)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addArgument('id', InputArgument::REQUIRED, 'O número da campanha');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $id = (string) $input->getArgument('id');
        if (preg_match('/^[1-9]\d{0,9}$/', $id) !== 1) {
            $output->writeln('<error>Informe o número da campanha.</error>');

            return Command::INVALID;
        }
        try {
            $output->writeln($this->encerrar->executar((int) $id));
        } catch (CampanhaRecusada $e) {
            $output->writeln('<error>' . $e->getMessage() . '</error>');

            return Command::FAILURE;
        }

        return Command::SUCCESS;
    }
}
