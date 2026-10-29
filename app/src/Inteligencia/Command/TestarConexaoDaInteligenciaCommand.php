<?php

declare(strict_types=1);

namespace App\Inteligencia\Command;

use App\Inteligencia\DTO\PedidoDeLinguagem;
use App\Inteligencia\Exception\FalhaDoProvedorException;
use App\Inteligencia\Exception\ProvedorIndisponivelException;
use App\Inteligencia\Service\ProvedorDeLinguagem;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Confere se a chave da Anthropic funciona, com UMA chamada mínima (`max_tokens` 16 no pedido). Só o dono roda, depois
 * de colar a chave em `app/.env.local` (`ANTHROPIC_API_KEY`, nunca versionado; veja `docs/guias/chat-ia-dev.md`).
 *
 * - Sem chave (provedor dormente): diz o que falta e sai com código 1, SEM chamar a API.
 * - Com chave: imprime `conectado (modelo X)` e sai com 0, ou o erro e sai com 1.
 *
 * O erro impresso é a mensagem do provedor, que leva só status, tipo e id da requisição: nunca a chave, o corpo da
 * resposta nem o texto do pedido.
 */
#[AsCommand(
    name: 'app:inteligencia:testar-conexao',
    description: 'Faz uma chamada mínima à Anthropic para conferir se a chave está conectada.',
)]
final class TestarConexaoDaInteligenciaCommand extends Command
{
    private const MAXIMO_DE_TOKENS = 16;

    public function __construct(private readonly ProvedorDeLinguagem $provedor)
    {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        if (!$this->provedor->estaConfigurado()) {
            $output->writeln('Sem conexão: a ANTHROPIC_API_KEY não está configurada neste ambiente (ou o modelo é inválido).');
            $output->writeln('Cole a chave em app/.env.local (arquivo que não vai para o Git) e rode este comando de novo.');

            return Command::FAILURE;
        }

        try {
            $resposta = $this->provedor->completar(new PedidoDeLinguagem(
                sistema: 'Teste de conexão. Responda apenas: ok',
                mensagens: [['papel' => 'usuario', 'conteudo' => 'ok?']],
                maxTokens: self::MAXIMO_DE_TOKENS,
                temperatura: 0.0,
                exigeJson: false,
                rotuloDeUso: 'testar_conexao',
            ));
        } catch (ProvedorIndisponivelException|FalhaDoProvedorException $e) {
            $output->writeln('Falha na conexão: ' . $e->getMessage());

            return Command::FAILURE;
        }

        $output->writeln(sprintf('conectado (modelo %s)', $resposta->modelo));

        return Command::SUCCESS;
    }
}
