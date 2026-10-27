<?php

declare(strict_types=1);

namespace App\TrabalhoExterno\Command;

use App\TrabalhoExterno\Repository\ConfiguracaoCentralRepository;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Liga, desliga ou mostra o interruptor do termo de voluntariado do Trabalho Externo. Vem DESLIGADO: com ele desligado
 * o servidor não expõe rota, botão, filtro, coluna nem aviso do termo (os termos já gravados continuam no banco).
 * O Master também liga e desliga pelo endpoint da configuração da Central (chave `termo`).
 */
#[AsCommand(name: 'trabalho-externo:termo', description: 'Liga, desliga ou mostra o termo de voluntariado (on, off, status)')]
final class TermoDeVoluntariadoCommand extends Command
{
    public function __construct(private readonly ConfiguracaoCentralRepository $configuracoes)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addArgument('acao', InputArgument::OPTIONAL, 'on, off ou status', 'status');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $acao = (string) $input->getArgument('acao');
        $cfg = $this->configuracoes->atual();

        if (!in_array($acao, ['on', 'off', 'status'], true)) {
            $io->error('Use on, off ou status.');

            return Command::FAILURE;
        }
        if ($acao !== 'status') {
            $cfg->definirTermoLigado($acao === 'on');
            $this->configuracoes->salvar($cfg, flush: true);
        }
        $io->success('Termo de voluntariado: ' . ($cfg->termoLigado() ? 'LIGADO' : 'DESLIGADO') . '.');

        return Command::SUCCESS;
    }
}
