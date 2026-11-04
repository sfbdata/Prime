<?php

declare(strict_types=1);

namespace App\Blog\Command;

use App\Blog\Enum\MotivoDaPublicacao;
use App\Blog\Service\PedirPublicacaoDosBlogs;
use Psr\Clock\ClockInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * `blog:gerar-de-novo`: pede ao builder uma geração dos blogs com os artigos publicados de agora (spec
 * painel-artigos-blogs, seção 6.1, "Quem escreve em sites-publicados"). É como uma mudança de CÓDIGO dos blogs
 * (layout, componentes) vai ao ar: o `publicar.sh` dos sites só publica o `site/`.
 *
 * Usa o mesmo {@see PedirPublicacaoDosBlogs} do Publicar, com motivo `manual`: se já há um pedido na fila esperando a
 * montagem, ele é reaproveitado.
 */
#[AsCommand(name: 'blog:gerar-de-novo', description: 'Pede ao builder uma nova geração dos blogs (Blog BlueJus e Grátis)')]
final class GerarBlogsDeNovoCommand extends Command
{
    public function __construct(
        private readonly PedirPublicacaoDosBlogs $pedir,
        private readonly ClockInterface $relogio,
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $publicacao = $this->pedir->pedir(MotivoDaPublicacao::Manual, null, $this->relogio->now());
        $output->writeln(sprintf(
            'Geração dos blogs pedida: pedido nº %d, na fila. Acompanhe na seção Artigos dos blogs do Painel do Master.',
            $publicacao->getId(),
        ));

        return Command::SUCCESS;
    }
}
