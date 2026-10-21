<?php

declare(strict_types=1);

namespace App\Processo\Command;

use App\Processo\DTO\CorrecaoDeAcentuacaoOutput;
use App\Processo\UseCase\CorrigirAcentuacaoDoOrgaoUseCase;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Reparo único dos nomes de órgão com a acentuação estragada ("6Âª VARA DO TRABALHO DE BRASÃ­LIA - DF").
 * Simula por padrão (mostra "antes → depois" de cada nome e as contagens); só grava com `--aplicar`.
 * Rodar depois do deploy do mapper corrigido, senão a próxima sincronização estraga de novo.
 */
#[AsCommand(
    name: 'app:processo:corrigir-acentuacao-do-orgao',
    description: 'Reverte a acentuação estragada dos nomes de órgão (processo e movimentações). Simula sem --aplicar.',
)]
final class CorrigirAcentuacaoDoOrgaoCommand extends Command
{
    public function __construct(
        private readonly CorrigirAcentuacaoDoOrgaoUseCase $corrigir,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('aplicar', null, InputOption::VALUE_NONE, 'Grava a correção (sem esta opção, só simula).');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $resultado = $this->corrigir->executar((bool) $input->getOption('aplicar'));

        if ($resultado->linhas === []) {
            $io->success('Nenhum nome de órgão com a acentuação estragada.');

            return Command::SUCCESS;
        }

        $this->mostrar($io, $resultado);

        return Command::SUCCESS;
    }

    private function mostrar(SymfonyStyle $io, CorrecaoDeAcentuacaoOutput $resultado): void
    {
        $io->table(['Onde', 'Antes', 'Depois', 'Linhas'], array_map(
            static fn ($l): array => [$l->onde, $l->antes, $l->depois ?? '(fica como está)', $l->quantidade],
            $resultado->linhas,
        ));

        foreach ($resultado->intocadas() as $linha) {
            $io->warning(sprintf('Não reverte limpo, fica como está (%s): %s', $linha->onde, $linha->antes));
        }

        $resultado->aplicada
            ? $io->success(sprintf('%d linha(s) corrigida(s).', $resultado->corrigidas()))
            : $io->note(sprintf('Simulação: %d linha(s) seriam corrigidas. Para gravar, rode de novo com --aplicar.', $resultado->corrigidas()));
    }
}
