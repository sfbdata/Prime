<?php

declare(strict_types=1);

namespace App\ChatIa\UseCase;

use App\ChatIa\DTO\RespostaPreparada;
use App\ChatIa\Entity\MensagemDeChat;
use App\ChatIa\Repository\MensagemDeChatRepository;
use App\ChatIa\Service\TituloAutomaticoDoChat;
use App\ChatIa\Service\TransacaoDoChat;
use App\Inteligencia\DTO\UsoDaTransmissao;
use App\Inteligencia\Exception\FalhaDoProvedorException;
use App\Inteligencia\Exception\ProvedorIndisponivelException;
use App\Inteligencia\Service\ProvedorDeLinguagemEmFluxo;
use Psr\Clock\ClockInterface;
use Psr\Log\LoggerInterface;

/**
 * A resposta em FLUXO de uma pergunta já preparada ({@see EnviarMensagemNoChatUseCase}). É um gerador de eventos
 * `{evento, dados}` que o controller escreve como SSE:
 *
 * - `delta {t}`: um trecho de texto;
 * - `fim {mensagemId, tokensEntrada, tokensSaida, titulo?, cortada?}`: a resposta inteira foi gravada (tokens REAIS do
 *   provedor); `titulo` só vem na primeira resposta (automático, ou o início da pergunta se a chamada curta falhar);
 * - `erro {codigo, mensagem}`: o provedor falhou. O que já chegou é gravado como resposta interrompida.
 *
 * Parar (o cliente abortou o fetch): `$clienteSaiu` é consultado depois de cada trecho entregue; se verdadeiro, o texto
 * parcial é gravado com `interrompida = true` e o gerador termina em silêncio. Nesse caso o provedor não informa o
 * consumo, então os tokens gravados são uma ESTIMATIVA (texto: caracteres / 3,5; PDF e imagem anexos: bytes / 3, no máximo
 * 30 mil por arquivo), para o teto diário não ficar cego.
 *
 * Nunca registra texto de pergunta, de resposta nem de contexto: o log leva ids, a classe do erro e se foi transitório.
 */
final class TransmitirRespostaDoChatUseCase
{
    private const CARACTERES_POR_TOKEN = 3.5;
    /** PDF e imagem anexos: estimativa de bytes por token e teto por arquivo (a API cobra páginas e pixels, não bytes). */
    private const BYTES_POR_TOKEN_DE_ANEXO = 3;
    private const TETO_DE_TOKENS_POR_ANEXO = 30_000;

    public function __construct(
        private readonly ProvedorDeLinguagemEmFluxo $provedor,
        private readonly MensagemDeChatRepository $mensagens,
        private readonly TituloAutomaticoDoChat $titulo,
        private readonly TransacaoDoChat $transacao,
        private readonly ClockInterface $relogio,
        private readonly LoggerInterface $logger,
    ) {
    }

    /**
     * @param callable(): bool $clienteSaiu verdadeiro quando o cliente já foi embora
     *
     * @return \Generator<int, array{evento: string, dados: array<string, mixed>}>
     */
    public function executar(RespostaPreparada $preparada, callable $clienteSaiu): \Generator
    {
        $texto = '';
        $fluxo = $this->provedor->transmitir($preparada->pedido);
        try {
            foreach ($fluxo as $trecho) {
                $texto .= $trecho;
                yield ['evento' => 'delta', 'dados' => ['t' => $trecho]];
                if ($clienteSaiu()) {
                    $this->gravar($preparada, $texto, null, true);

                    return;
                }
            }
            $uso = $fluxo->getReturn();
        } catch (FalhaDoProvedorException|ProvedorIndisponivelException $e) {
            yield $this->falhou($preparada, $texto, $e);

            return;
        }

        yield $this->terminou($preparada, $texto, $uso);
    }

    /** @return array{evento: string, dados: array<string, mixed>} */
    private function terminou(RespostaPreparada $preparada, string $texto, UsoDaTransmissao $uso): array
    {
        if (trim($texto) === '') {
            return $this->evento('erro', ['codigo' => 'resposta_vazia', 'mensagem' => 'A IA não devolveu texto. Tente de novo.']);
        }

        $mensagem = $this->gravar($preparada, $texto, $uso, false);
        $dados = ['mensagemId' => (int) $mensagem->getId(), 'tokensEntrada' => $mensagem->getTokensEntrada(), 'tokensSaida' => $mensagem->getTokensSaida()];
        if ($uso->cortadaNoLimite) {
            $dados['cortada'] = true;
        }
        $titulo = $this->tituloDaPrimeiraResposta($preparada, $texto);
        if ($titulo !== null) {
            $dados['titulo'] = $titulo;
        }

        return $this->evento('fim', $dados);
    }

    /**
     * @return array{evento: string, dados: array<string, mixed>}
     */
    private function falhou(RespostaPreparada $preparada, string $texto, FalhaDoProvedorException|ProvedorIndisponivelException $e): array
    {
        if (trim($texto) !== '') {
            $this->gravar($preparada, $texto, null, true);
        }
        $transitoria = $e instanceof FalhaDoProvedorException && $e->transitoria;
        $this->logger->warning('Chat I.A: o provedor falhou durante a resposta.', [
            'tenantId' => $preparada->tenant->getId(),
            'conversaId' => $preparada->conversa->getId(),
            'erro' => $e::class,
            'transitoria' => $transitoria,
        ]);

        return $e instanceof ProvedorIndisponivelException
            ? $this->evento('erro', ['codigo' => 'sem_chave', 'mensagem' => 'A IA ainda não está conectada.'])
            : $this->evento('erro', [
                'codigo' => 'falha_do_provedor',
                'mensagem' => $transitoria ? 'A IA está sobrecarregada agora. Tente de novo em instantes.' : 'Não consegui responder agora. Tente de novo.',
            ]);
    }

    private function gravar(RespostaPreparada $preparada, string $texto, ?UsoDaTransmissao $uso, bool $interrompida): MensagemDeChat
    {
        $agora = \DateTimeImmutable::createFromInterface($this->relogio->now());
        $entrada = $uso->tokensEntrada ?? self::estimar($preparada->pedido->sistema . $this->conteudoDoPedido($preparada)) + self::estimarOsAnexos($preparada);
        $saida = $uso->tokensSaida ?? self::estimar($texto);
        $mensagem = MensagemDeChat::doAssistente($preparada->conversa, $texto, $uso->modelo ?? $preparada->modelo->value, $entrada, $saida, $interrompida, $agora);

        $this->transacao->executar(function () use ($mensagem, $preparada, $agora): void {
            $this->mensagens->salvar($mensagem);
            $preparada->conversa->registrarAtividade($agora);
        });

        return $mensagem;
    }

    private function tituloDaPrimeiraResposta(RespostaPreparada $preparada, string $texto): ?string
    {
        if (!$preparada->primeiraResposta) {
            return null;
        }
        $titulo = $this->titulo->gerar($preparada->pergunta, $texto);
        $this->transacao->executar(static function () use ($preparada, $titulo): void {
            $preparada->conversa->definirTituloAutomatico($titulo);
        });

        return $preparada->conversa->getTitulo();
    }

    private function conteudoDoPedido(RespostaPreparada $preparada): string
    {
        return implode('', array_map(static fn (array $m): string => $m['conteudo'], $preparada->pedido->mensagens));
    }

    private static function estimarOsAnexos(RespostaPreparada $preparada): int
    {
        $total = 0;
        foreach ($preparada->pedido->anexos as $anexo) {
            $total += min(self::TETO_DE_TOKENS_POR_ANEXO, (int) ceil(\strlen($anexo->bytes) / self::BYTES_POR_TOKEN_DE_ANEXO));
        }

        return $total;
    }

    private static function estimar(string $texto): int
    {
        return (int) ceil(\strlen($texto) / self::CARACTERES_POR_TOKEN);
    }

    /**
     * @param array<string, mixed> $dados
     *
     * @return array{evento: string, dados: array<string, mixed>}
     */
    private function evento(string $nome, array $dados): array
    {
        return ['evento' => $nome, 'dados' => $dados];
    }
}
