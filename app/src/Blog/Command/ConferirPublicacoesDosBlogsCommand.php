<?php

declare(strict_types=1);

namespace App\Blog\Command;

use App\Blog\UseCase\ConferirPublicacoesDosBlogsUseCase;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * `blog:conferir-publicacoes`: lê o resultado do builder dos blogs (spec painel-artigos-blogs, seção 6.1). Roda a cada
 * minuto pela {@see \App\Shared\Scheduler\AgendaDeTarefas}.
 *
 * Sai com erro quando algum pedido terminou como falhou nesta rodada: é o que faz o aviso de falha do agendador avisar
 * o Master (moldura, Central de Notificações e e-mail de alerta), sem um canal novo. Nada a conferir termina com
 * sucesso, sem saída.
 */
#[AsCommand(name: 'blog:conferir-publicacoes', description: 'Lê o resultado das gerações dos blogs pedidas ao builder')]
final class ConferirPublicacoesDosBlogsCommand extends Command
{
    public function __construct(private readonly ConferirPublicacoesDosBlogsUseCase $conferir)
    {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $resumo = $this->conferir->executar();
        if ($resumo === ['gerando' => 0, 'noAr' => 0, 'falhou' => 0]) {
            return Command::SUCCESS;
        }

        $output->writeln(sprintf('Gerando: %d. No ar: %d. Falharam: %d.', $resumo['gerando'], $resumo['noAr'], $resumo['falhou']));
        if ($resumo['falhou'] > 0) {
            $output->writeln('<error>A geração dos blogs falhou. Veja o histórico na seção Artigos dos blogs do Painel do Master.</error>');

            return Command::FAILURE;
        }

        return Command::SUCCESS;
    }
}
