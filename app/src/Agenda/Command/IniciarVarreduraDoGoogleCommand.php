<?php

declare(strict_types=1);

namespace App\Agenda\Command;

use App\Agenda\Service\GarantirVarreduraDoGoogle;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(name: 'app:agenda:google-varredura', description: 'Põe para andar a sincronia periódica do Google Agenda, se estiver parada')]
final class IniciarVarreduraDoGoogleCommand extends Command
{
    public function __construct(
        private readonly GarantirVarreduraDoGoogle $garantir,
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $io->writeln($this->garantir->garantir()
            ? 'Sincronia semeada: o worker da fila async passa a sincronizar o Google Agenda a cada 5 minutos.'
            : 'Nada a fazer: a sincronia já está andando, ou o Google Agenda está desligado (sem cliente OAuth).');

        return Command::SUCCESS;
    }
}
