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
 * scripts/go-live-check.sh. O que é só aviso (o token do validador de PDF vazio) aparece sem mudar o código de saída.
 * Nunca imprime valor de segredo: só o nome e o que está errado.
 */
#[AsCommand(
    name: 'app:conferir-segredos',
    description: 'Confere APP_SECRET, SYNC_CRYPTO_KEY, CONTRIBUA_CRYPTO_KEY, ASSINATURA_SEGREDO, ASSINATURA_SELFIE_CHAVE, ASSINATURA_SELO_SEGREDO e ASSINATURA_VALIDADOR_TOKEN sem mostrar os valores',
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
        // Os mesmos parâmetros que a Assinatura lê, para conferir o valor que o sistema usa.
        #[Autowire(param: 'assinatura.segredo')]
        #[\SensitiveParameter]
        private readonly string $segredoDaAssinatura,
        #[Autowire(param: 'assinatura.selfie_chave')]
        #[\SensitiveParameter]
        private readonly string $chaveDaSelfie,
        #[Autowire(param: 'assinatura.selo_segredo')]
        #[\SensitiveParameter]
        private readonly string $segredoDoSelo,
        // Token do validador de PDF (etapa 6): o mesmo valor que o php manda no cabeçalho e o validador_pdf confere.
        #[Autowire('%env(string:default::ASSINATURA_VALIDADOR_TOKEN)%')]
        #[\SensitiveParameter]
        private readonly string $tokenDoValidador,
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

        $problemas = array_merge(
            $this->conferente->conferir(
                $this->segredoDaAplicacao,
                $this->chaveDeCifraDoSync,
                $this->chaveDeCifraDoContribua,
                $modelo,
            ),
            $this->conferente->conferirAssinatura($this->segredoDaAssinatura, $this->chaveDaSelfie, $modelo),
            $this->conferente->conferirSelo($this->segredoDoSelo, $this->segredoDaAssinatura, $this->segredoDaAplicacao, $modelo),
            $this->problemasDoValidador($modelo),
        );
        // Aviso não recusa: o token do validador vazio é um estado válido (E6-4) e não derruba o go-live-check.sh.
        $avisos = $this->conferente->avisosDoValidador($this->tokenDoValidador);

        if ($avisos !== []) {
            $io->warning($avisos);
        }

        if ($problemas !== []) {
            $io->listing($problemas);
            $io->error('Segredos de produção recusados. Corrija o .env.prod e recrie o contêiner.');

            return Command::FAILURE;
        }

        $io->success('APP_SECRET, SYNC_CRYPTO_KEY, CONTRIBUA_CRYPTO_KEY, ASSINATURA_SEGREDO, ASSINATURA_SELFIE_CHAVE, ASSINATURA_SELO_SEGREDO e ASSINATURA_VALIDADOR_TOKEN conferidos.' . ($avisos !== [] ? ' Veja o aviso acima.' : ''));

        return Command::SUCCESS;
    }

    /**
     * O token do validador comparado com todas as outras chaves que este comando lê: o contêiner do validador não
     * recebe nenhuma delas, então o token precisa ser próprio.
     *
     * @return list<string>
     */
    private function problemasDoValidador(string $modelo): array
    {
        return $this->conferente->conferirValidador(
            $this->tokenDoValidador,
            [
                'APP_SECRET' => $this->segredoDaAplicacao,
                'SYNC_CRYPTO_KEY' => $this->chaveDeCifraDoSync,
                'CONTRIBUA_CRYPTO_KEY' => $this->chaveDeCifraDoContribua,
                'ASSINATURA_SEGREDO' => $this->segredoDaAssinatura,
                'ASSINATURA_SELFIE_CHAVE' => $this->chaveDaSelfie,
                'ASSINATURA_SELO_SEGREDO' => $this->segredoDoSelo,
            ],
            $modelo,
        );
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
