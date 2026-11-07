<?php

declare(strict_types=1);

namespace App\ChatIa\UseCase;

use App\ChatIa\DTO\RespostaPreparada;
use App\ChatIa\Entity\MensagemDeChat;
use App\ChatIa\Entity\PropostaDeCadastro;
use App\ChatIa\Repository\MensagemDeChatRepository;
use App\ChatIa\Repository\PropostaDeCadastroRepository;
use App\ChatIa\Service\ConsultasDoChat;
use App\ChatIa\Service\MontadorDoCartaoDaProposta;
use App\ChatIa\Service\RegistradorDasPropostas;
use App\ChatIa\Service\TituloAutomaticoDoChat;
use App\ChatIa\Service\TransacaoDoChat;
use App\Inteligencia\DTO\ChamadaDeFerramenta;
use App\Inteligencia\DTO\PedidoDeLinguagem;
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
 * - `proposta {id, tipo, estado, ...}`: o cartão de uma proposta de cadastro que o modelo pediu (tool use), já gravada como
 *   `pendente` e ligada à resposta. Vem antes do `fim`. O modelo só PROPÕE: nada vira Cliente nem Pasta sem o clique da pessoa
 *   ({@see ConfirmarPropostaDeCadastroUseCase}). Sem proposta válida o texto ganha um aviso (também enviado como `delta`);
 * - `fim {mensagemId, tokensEntrada, tokensSaida, titulo?, cortada?}`: a resposta inteira foi gravada (tokens REAIS do
 *   provedor); `titulo` só vem na primeira resposta (automático, ou o início da pergunta se a chamada curta falhar);
 * - `erro {codigo, mensagem}`: o provedor falhou. O que já chegou é gravado como resposta interrompida.
 *
 * Consulta (spec `chat-ia-prazos-e-metas`): se o modelo pedir uma ferramenta de CONSULTA (só leitura, {@see ConsultasDoChat}), o
 * servidor roda a consulta e faz UMA segunda chamada, com o resultado em bloco delimitado e sem ferramentas (não há laço). O texto
 * das duas sai no mesmo fluxo e é gravado como uma resposta, com os tokens das duas somados; as propostas são as da primeira.
 * Se a resposta terminar sem texto nenhum (a segunda falhou ou voltou vazia), o consumo já conhecido vira um registro de custo
 * sem texto ({@see guardarSoOCusto()}): o teto diário não perde a primeira chamada.
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
    private const AVISO_SEM_PROPOSTA = 'Não consegui montar o cadastro. Descreva de novo os dados que você quer cadastrar.';
    private const AVISO_COM_PROPOSTA = 'Montei a proposta abaixo. Confira os dados e confirme se estiverem certos.';

    public function __construct(
        private readonly ProvedorDeLinguagemEmFluxo $provedor,
        private readonly MensagemDeChatRepository $mensagens,
        private readonly TituloAutomaticoDoChat $titulo,
        private readonly RegistradorDasPropostas $registrador,
        private readonly PropostaDeCadastroRepository $propostas,
        private readonly MontadorDoCartaoDaProposta $cartao,
        private readonly TransacaoDoChat $transacao,
        private readonly ClockInterface $relogio,
        private readonly LoggerInterface $logger,
        private readonly ConsultasDoChat $consultas,
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
        $pedido = $preparada->pedido;
        $anterior = null;   // o consumo da primeira chamada, quando houve consulta
        $inicio = 0;        // onde começa, no texto, o que a chamada em curso escreveu
        try {
            $uso = yield from $this->umaChamada($pedido, $texto, $clienteSaiu);
            $comResultado = $uso === null ? null : $this->pedidoDaConsulta($preparada, $uso);
            if ($comResultado !== null) {
                [$pedido, $anterior] = [$comResultado, $uso];
                yield from $this->separarAsChamadas($texto);
                $inicio = \strlen($texto);
                $uso = yield from $this->umaChamada($pedido, $texto, $clienteSaiu);
            }
        } catch (FalhaDoProvedorException|ProvedorIndisponivelException $e) {
            yield $this->falhou($preparada, $texto, $e, $this->estimativa($pedido, substr($texto, $inicio), $anterior), $anterior);

            return;
        }
        if ($uso === null) {
            $this->gravar($preparada, $texto, $this->estimativa($pedido, substr($texto, $inicio), $anterior), true);

            return;
        }

        yield from $this->terminou($preparada, $texto, self::somar($anterior, $uso), $anterior !== null);
    }

    /**
     * Uma chamada ao provedor em fluxo: cada trecho vira `delta` e entra no texto. Devolve o consumo, ou nulo se o cliente saiu
     * (consultado depois de cada trecho entregue).
     *
     * @param callable(): bool $clienteSaiu
     *
     * @return \Generator<int, array{evento: string, dados: array<string, mixed>}, mixed, ?UsoDaTransmissao>
     */
    private function umaChamada(PedidoDeLinguagem $pedido, string &$texto, callable $clienteSaiu): \Generator
    {
        $fluxo = $this->provedor->transmitir($pedido);
        foreach ($fluxo as $trecho) {
            $texto .= $trecho;
            yield $this->evento('delta', ['t' => $trecho]);
            if ($clienteSaiu()) {
                return null;
            }
        }

        return $fluxo->getReturn();
    }

    /**
     * A segunda chamada, quando o modelo pediu uma ferramenta de CONSULTA (só leitura): o mesmo pedido com o resultado e sem
     * ferramentas ({@see ConsultasDoChat}). Nulo = nenhuma consulta pedida.
     */
    private function pedidoDaConsulta(RespostaPreparada $preparada, UsoDaTransmissao $uso): ?PedidoDeLinguagem
    {
        $consultas = array_values(array_filter($uso->chamadasDeFerramenta, fn (ChamadaDeFerramenta $c): bool => $this->consultas->ehConsulta($c)));

        return $consultas === [] ? null : $this->consultas->pedidoComResultado($preparada, $consultas);
    }

    /**
     * O texto da primeira chamada ("vou consultar...") e o da segunda viram uma resposta só, separados por uma linha em branco.
     *
     * @return \Generator<int, array{evento: string, dados: array<string, mixed>}>
     */
    private function separarAsChamadas(string &$texto): \Generator
    {
        if (trim($texto) === '') {
            return;
        }
        $texto .= "\n\n";
        yield $this->evento('delta', ['t' => "\n\n"]);
    }

    /**
     * Parou ou falhou na SEGUNDA chamada: a primeira entra com os tokens reais e a segunda, estimada (o provedor não informou).
     * Na primeira chamada, nulo: o {@see gravar()} estima tudo, como sempre.
     */
    private function estimativa(PedidoDeLinguagem $pedido, string $textoDaChamada, ?UsoDaTransmissao $anterior): ?UsoDaTransmissao
    {
        if ($anterior === null) {
            return null;
        }

        return new UsoDaTransmissao(
            modelo: $anterior->modelo,
            tokensEntrada: ($anterior->tokensEntrada ?? 0) + self::estimarAEntrada($pedido),
            tokensSaida: ($anterior->tokensSaida ?? 0) + self::estimar($textoDaChamada),
        );
    }

    /** As duas chamadas somadas (o teto diário vê as duas); as chamadas de ferramenta são as da primeira (a segunda não tem). */
    private static function somar(?UsoDaTransmissao $anterior, UsoDaTransmissao $uso): UsoDaTransmissao
    {
        if ($anterior === null) {
            return $uso;
        }
        $somar = static fn (?int $a, ?int $b): ?int => $a === null && $b === null ? null : ($a ?? 0) + ($b ?? 0);

        return new UsoDaTransmissao(
            modelo: $uso->modelo,
            tokensEntrada: $somar($anterior->tokensEntrada, $uso->tokensEntrada),
            tokensSaida: $somar($anterior->tokensSaida, $uso->tokensSaida),
            duracaoMs: $somar($anterior->duracaoMs, $uso->duracaoMs),
            cortadaNoLimite: $uso->cortadaNoLimite,
            chamadasDeFerramenta: $anterior->chamadasDeFerramenta,
        );
    }

    /**
     * @param bool $comConsulta houve a segunda chamada (consulta): resposta vazia ainda guarda o custo das duas
     *
     * @return \Generator<int, array{evento: string, dados: array<string, mixed>}>
     */
    private function terminou(RespostaPreparada $preparada, string $texto, UsoDaTransmissao $uso, bool $comConsulta): \Generator
    {
        $agora = \DateTimeImmutable::createFromInterface($this->relogio->now());
        [$propostas, $aviso] = $this->registrarPropostas($preparada, $texto, $uso, $agora);
        if ($aviso !== '') {
            // O texto que o modelo escreveu fica como veio; o aviso do sistema entra depois e também vai ao cliente.
            $aviso = trim($texto) === '' ? $aviso : "\n\n" . $aviso;
            $texto .= $aviso;
            yield $this->evento('delta', ['t' => $aviso]);
        }
        if (trim($texto) === '') {
            if ($comConsulta) {
                $this->guardarSoOCusto($preparada, $uso);
            }
            yield $this->evento('erro', ['codigo' => 'resposta_vazia', 'mensagem' => 'A IA não devolveu texto. Tente de novo.']);

            return;
        }

        $mensagem = $this->gravar($preparada, $texto, $uso, false, $propostas);
        foreach ($propostas as $proposta) {
            yield $this->evento('proposta', $this->cartao->montar($proposta, $agora)->paraArray());
        }
        $dados = ['mensagemId' => (int) $mensagem->getId(), 'tokensEntrada' => $mensagem->getTokensEntrada(), 'tokensSaida' => $mensagem->getTokensSaida()];
        if ($uso->cortadaNoLimite) {
            $dados['cortada'] = true;
        }
        $titulo = $this->tituloDaPrimeiraResposta($preparada, $texto);
        if ($titulo !== null) {
            $dados['titulo'] = $titulo;
        }

        yield $this->evento('fim', $dados);
    }

    /**
     * Só as chamadas de PROPOSTA viram cartão; a de consulta já foi respondida na segunda chamada e não gera aviso.
     *
     * @return array{0: list<PropostaDeCadastro>, 1: string}
     */
    private function registrarPropostas(RespostaPreparada $preparada, string $texto, UsoDaTransmissao $uso, \DateTimeImmutable $agora): array
    {
        $chamadas = array_values(array_filter($uso->chamadasDeFerramenta, fn (ChamadaDeFerramenta $c): bool => !$this->consultas->ehConsulta($c)));
        if ($chamadas === []) {
            return [[], ''];
        }
        $conversa = $preparada->conversa;
        $propostas = $this->registrador->preparar($conversa->getUsuario(), $preparada->tenant, $conversa, $chamadas, $agora);
        $aviso = match (true) {
            $propostas === [] => self::AVISO_SEM_PROPOSTA,
            trim($texto) === '' => self::AVISO_COM_PROPOSTA,
            default => '',
        };

        return [$propostas, $aviso];
    }

    /**
     * @param UsoDaTransmissao|null $estimativa o consumo da resposta parcial ({@see estimativa()}); nulo = o gravar estima
     * @param UsoDaTransmissao|null $anterior   o consumo REAL da primeira chamada, quando a falha veio na segunda (consulta)
     *
     * @return array{evento: string, dados: array<string, mixed>}
     */
    private function falhou(RespostaPreparada $preparada, string $texto, FalhaDoProvedorException|ProvedorIndisponivelException $e, ?UsoDaTransmissao $estimativa, ?UsoDaTransmissao $anterior): array
    {
        if (trim($texto) !== '') {
            $this->gravar($preparada, $texto, $estimativa, true);
        } elseif ($anterior !== null) {
            $this->guardarSoOCusto($preparada, $anterior);
        }
        $transitoria = $e instanceof FalhaDoProvedorException && $e->transitoria;
        $this->logger->warning('Chat I.A: o provedor falhou durante a resposta.', [
            'tenantId' => $preparada->tenant->getId(),
            'conversaId' => $preparada->conversa->getId(),
            'erro' => $e::class,
            'transitoria' => $transitoria,
            'motivo' => $e->getMessage(), // saneada pelo provedor: só tipo, status e id da requisição, nunca corpo nem texto
        ]);

        return $e instanceof ProvedorIndisponivelException
            ? $this->evento('erro', ['codigo' => 'sem_chave', 'mensagem' => 'A IA ainda não está conectada.'])
            : $this->evento('erro', [
                'codigo' => 'falha_do_provedor',
                'mensagem' => match (true) {
                    $e->deRede => 'A IA demorou demais para responder. Tente de novo.',
                    $transitoria => 'A IA está sobrecarregada agora. Tente de novo em instantes.',
                    default => 'Não consegui responder agora. Tente de novo.',
                },
            ]);
    }

    /** @param list<PropostaDeCadastro> $propostas as propostas desta resposta, gravadas na mesma transação que ela */
    private function gravar(RespostaPreparada $preparada, string $texto, ?UsoDaTransmissao $uso, bool $interrompida, array $propostas = []): MensagemDeChat
    {
        $agora = \DateTimeImmutable::createFromInterface($this->relogio->now());
        $entrada = $uso->tokensEntrada ?? self::estimarAEntrada($preparada->pedido);
        $saida = $uso->tokensSaida ?? self::estimar($texto);
        $mensagem = MensagemDeChat::doAssistente($preparada->conversa, $texto, $uso->modelo ?? $preparada->modelo->value, $entrada, $saida, $interrompida, $agora);

        $this->transacao->executar(function () use ($mensagem, $preparada, $agora, $propostas): void {
            $this->mensagens->salvar($mensagem);
            if ($propostas !== []) {
                // Aproveita para zerar os dados pessoais das propostas deste escritório que já venceram.
                $this->propostas->expirarVencidas($preparada->tenant, $agora);
            }
            foreach ($propostas as $proposta) {
                $proposta->associarAMensagem($mensagem);
                $this->propostas->salvar($proposta);
            }
            $preparada->conversa->registrarAtividade($agora);
        });

        return $mensagem;
    }

    /**
     * Resposta que terminou sem texto depois de uma consulta: o consumo conhecido vira um registro de custo, como a resposta
     * substituída (sem texto, fora da conversa e do histórico, dentro da soma do teto do dia).
     */
    private function guardarSoOCusto(RespostaPreparada $preparada, UsoDaTransmissao $uso): void
    {
        $registro = MensagemDeChat::doAssistente(
            $preparada->conversa,
            '',
            $uso->modelo,
            $uso->tokensEntrada,
            $uso->tokensSaida,
            true,
            \DateTimeImmutable::createFromInterface($this->relogio->now()),
        );
        $registro->substituir();
        $this->transacao->executar(function () use ($registro): void {
            $this->mensagens->salvar($registro);
        });
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

    /** Sistema, mensagens e anexos do pedido, estimados (texto: caracteres / 3,5; anexo: bytes / 3, com teto por arquivo). */
    private static function estimarAEntrada(PedidoDeLinguagem $pedido): int
    {
        $conteudo = implode('', array_map(static fn (array $m): string => $m['conteudo'], $pedido->mensagens));

        return self::estimar($pedido->sistema . $conteudo) + self::estimarOsAnexos($pedido);
    }

    private static function estimarOsAnexos(PedidoDeLinguagem $pedido): int
    {
        $total = 0;
        foreach ($pedido->anexos as $anexo) {
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
