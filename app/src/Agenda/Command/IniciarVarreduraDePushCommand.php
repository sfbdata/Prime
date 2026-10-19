<?php

declare(strict_types=1);

namespace App\Agenda\Command;

use App\Agenda\Service\GarantirVarreduraDePush;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(name: 'app:agenda:push-varredura', description: 'Põe para andar a varredura do push dos lembretes da Agenda, se estiver parada')]
final class IniciarVarreduraDePushCommand extends Command
{
    public function __construct(
        private readonly GarantirVarreduraDePush $garantir,
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $io->writeln($this->garantir->garantir()
            ? 'Varredura semeada: o worker da fila async passa a varrer a cada minuto.'
            : 'Nada a fazer: a varredura já está andando, ou o push está desligado (sem chaves VAPID).');

        return Command::SUCCESS;
    }
}
