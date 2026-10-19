<?php

declare(strict_types=1);

namespace App\Comercial\Command;

use App\Comercial\Message\VarrerAutomacoes;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Messenger\MessageBusInterface;

/**
 * Semeia a cadeia de varredura das automações (spec comercial-e-emails.md §3.4). Idempotente: rodar de novo, no deploy
 * ou à mão, só cria um passo que morre se outra cadeia já estiver viva (a reivindicação na linha 1). Se a cadeia morrer
 * em `failed`, rodar este comando a recomeça.
 */
#[AsCommand(name: 'app:comercial:varredura', description: 'Semeia a cadeia de varredura das automações do Comercial')]
final class VarreduraComercialCommand extends Command
{
    public function __construct(
        private readonly MessageBusInterface $bus,
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $this->bus->dispatch(new VarrerAutomacoes());
        $output->writeln('Varredura do Comercial semeada.');

        return Command::SUCCESS;
    }
}
