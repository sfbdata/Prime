<?php

declare(strict_types=1);

namespace App\Comercial\Command;

use App\Comercial\Message\VarrerAutomacoes;
use App\Comercial\Service\SementeDoExpansor;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Messenger\MessageBusInterface;

/**
 * Semeia a cadeia de varredura das automações (spec comercial-e-emails.md §3.4) e a do expansor de campanhas (§8.5).
 * Idempotente: rodar de novo, no deploy ou à mão, só cria um passo que morre se outra cadeia já estiver viva (a
 * reivindicação na linha 1 de cada controle). Se uma cadeia morrer em `failed`, rodar este comando a recomeça. Não cria
 * campanha nem envio (§8.1.3): sem campanha ativa, o passo do expansor só confere a chave e para.
 */
#[AsCommand(name: 'app:comercial:varredura', description: 'Semeia a cadeia de varredura das automações do Comercial')]
final class VarreduraComercialCommand extends Command
{
    public function __construct(
        private readonly MessageBusInterface $bus,
        private readonly SementeDoExpansor $expansor,
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $this->bus->dispatch(new VarrerAutomacoes());
        $this->expansor->semear();
        $output->writeln('Varredura do Comercial semeada.');

        return Command::SUCCESS;
    }
}
