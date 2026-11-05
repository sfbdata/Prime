<?php

declare(strict_types=1);

namespace App\Shared\Command;

use App\Shared\Service\ConferenteDeSegredos;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Input\StreamableInputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Falha (código 1) quando um segredo de produção é fraco ou o do modelo público (auditoria 4.11). Chamado pelo
 * scripts/go-live-check.sh. Nunca imprime valor de segredo: só o nome e o que está errado.
 */
#[AsCommand(
    name: 'app:conferir-segredos',
    description: 'Confere APP_SECRET, SYNC_CRYPTO_KEY e CONTRIBUA_CRYPTO_KEY sem mostrar os valores',
)]
final class ConferirSegredosCommand extends Command
{
    public function __construct(
        private readonly ConferenteDeSegredos $conferente,
        #[Autowire('%env(string:default::APP_SECRET)%')]
        private readonly string $segredoDaAplicacao,
        #[Autowire('%env(string:default::SYNC_CRYPTO_KEY)%')]
        private readonly string $chaveDeCifraDoSync,
        #[Autowire('%env(string:default::CONTRIBUA_CRYPTO_KEY)%')]
        private readonly string $chaveDeCifraDoContribua,
        #[Autowire('%kernel.project_dir%/../.env.prod.example')]
        private readonly string $caminhoDoModelo,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption(
            'modelo',
            null,
            InputOption::VALUE_REQUIRED,
            'Caminho do .env.prod.example para comparar; "-" lê da entrada padrão (o contêiner de produção não tem o arquivo)',
        );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $modelo = $this->lerModelo($input);
        if ($modelo === null) {
            $io->error('Não consegui ler o .env.prod.example para comparar. Passe o caminho em --modelo, ou --modelo=- com o arquivo na entrada padrão.');

            return Command::FAILURE;
        }

        $problemas = $this->conferente->conferir(
            $this->segredoDaAplicacao,
            $this->chaveDeCifraDoSync,
            $this->chaveDeCifraDoContribua,
            $modelo,
        );

        if ($problemas !== []) {
            $io->listing($problemas);
            $io->error('Segredos de produção recusados. Corrija o .env.prod e recrie o contêiner.');

            return Command::FAILURE;
        }

        $io->success('APP_SECRET, SYNC_CRYPTO_KEY e CONTRIBUA_CRYPTO_KEY conferidos.');

        return Command::SUCCESS;
    }

    private function lerModelo(InputInterface $input): ?string
    {
        $caminho = $input->getOption('modelo') ?? $this->caminhoDoModelo;

        if ($caminho === '-') {
            $entrada = $input instanceof StreamableInputInterface ? $input->getStream() : null;
            $conteudo = stream_get_contents($entrada ?? \STDIN);

            return $conteudo === false ? null : $conteudo;
        }

        if (!is_file($caminho) || !is_readable($caminho)) {
            return null;
        }

        $conteudo = file_get_contents($caminho);

        return $conteudo === false ? null : $conteudo;
    }
}
