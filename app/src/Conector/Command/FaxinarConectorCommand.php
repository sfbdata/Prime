<?php

declare(strict_types=1);

namespace App\Conector\Command;

use App\Conector\UseCase\FaxinarConectorUseCase;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * A faxina diária do conector de I.A. (`docs/specs/mcp-remoto-oauth.md`, 13.2), agendada no
 * {@see \App\Shared\Scheduler\AgendaDeTarefas}. As regras moram no {@see FaxinarConectorUseCase}; aqui só o resumo.
 */
#[AsCommand(
    name: 'app:conector:faxinar',
    description: 'Apaga do conector de I.A. os códigos, tokens, concessões e propostas que já venceram.',
)]
final class FaxinarConectorCommand extends Command
{
    public function __construct(private readonly FaxinarConectorUseCase $useCase)
    {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $apagados = $this->useCase->executar();
        $output->writeln(sprintf(
            'Conector de I.A.: %d concessão(ões), %d token(s), %d código(s) e %d proposta(s) apagado(s).',
            $apagados['concessoes'],
            $apagados['tokens'],
            $apagados['codigos'],
            $apagados['propostas'],
        ));

        return Command::SUCCESS;
    }
}
