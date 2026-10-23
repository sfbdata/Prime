<?php

declare(strict_types=1);

namespace App\Shared\EspelhoRemoto\Replica;

use App\Shared\Armazenamento\ArmazenamentoDeArquivos;
use App\Shared\Armazenamento\Exception\ArquivoNaoEncontrado;
use App\Shared\Armazenamento\Exception\ChaveDeArquivoInvalida;
use App\Shared\Armazenamento\Exception\FalhaDeArmazenamento;
use App\Shared\EspelhoRemoto\EspelhoRemoto;
use App\Shared\EspelhoRemoto\FalhaNoEspelhoRemoto;
use App\Shared\EspelhoRemoto\MedidaDeConteudo;
use App\Shared\EspelhoRemoto\R2\ClienteR2;
use App\Shared\EspelhoRemoto\Outbox\ItemDeReplicacao;
use App\Shared\EspelhoRemoto\Outbox\OutboxDeReplicacao;
use Psr\Log\LoggerInterface;

/**
 * O worker da réplica: reivindica linhas da outbox e as leva ao espelho (spec P2 §3.3, §14).
 *
 * Para cada item, nesta ordem:
 *  1. o arquivo local sumiu → `descartado` (nada é enviado — e nada é apagado no R2);
 *  2. o conteúdo local não é mais o da linha (SHA ou tamanho) → `superado`. Se NÃO existe registro mais
 *     novo da mesma chave (a outbox falhou numa regravação, a política foi desligada entre duas
 *     gravações, uma transação desfeita regravou o disco), o worker registra um envio novo com o
 *     conteúdo atual antes de superar este: a fila converge para o que está no disco;
 *  3. o espelho já tem o objeto com o mesmo SHA e tamanho → `verificado`, sem PUT (idempotência:
 *     reprocessar depois de uma queda não reenvia);
 *  4. senão, RENOVA a posse e só então envia pelo stream do disco local. Sem a posse renovada, não
 *     envia. Como a duração máxima do HTTP ({@see ClienteR2::DURACAO_MAXIMA_DA_REQUISICAO}) é menor
 *     que a posse, o PUT termina antes que outro worker possa pegar a mesma chave — um envio velho
 *     não cai por cima de um novo.
 *
 * Falha transitória (espelho fora, disco momentaneamente ilegível, qualquer erro inesperado) →
 * reagenda com backoff até {@see self::MAXIMO_DE_TENTATIVAS}; depois, `falhou`. Erro que não melhora
 * com o tempo (chave inválida, parâmetro recusado) → `falhou` na hora. Se até a marcação de estado
 * falhar, a rodada segue com o próximo item e a posse vence sozinha — e o teto vale também aí (a
 * reivindicação marca `falhou` a linha que já esgotou as tentativas). O texto guardado nunca tem o
 * nome do arquivo nem a chave remota.
 */
final class ReplicadorDeArquivos
{
    public const MAXIMO_DE_TENTATIVAS = 6;

    /**
     * Prazo da posse. Tem de ser maior que a duração máxima de uma requisição ao espelho, com folga
     * para o adaptador reler o arquivo — `ReplicadorDeArquivosTest` trava a relação.
     */
    public const POSSE_EM_SEGUNDOS = 900;

    /** Espera antes da próxima tentativa, pela tentativa que acabou de falhar (1ª, 2ª, …). */
    private const ESPERAS_EM_SEGUNDOS = [60, 300, 1_800, 7_200, 43_200];

    public function __construct(
        private readonly OutboxDeReplicacao $outbox,
        private readonly ArmazenamentoDeArquivos $local,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function processar(EspelhoRemoto $espelho, int $limite): ResumoDaRodada
    {
        $resumo = new ResumoDaRodada();

        for ($i = 0; $i < $limite; $i++) {
            $item = $this->outbox->reivindicarProxima(self::POSSE_EM_SEGUNDOS, self::MAXIMO_DE_TENTATIVAS);
            if ($item === null) {
                break;
            }

            $resumo->reivindicados++;
            try {
                $this->processarItem($espelho, $item, $resumo);
            } catch (\Throwable $e) {
                // Até a marcação falhou (banco fora?). A posse vence sozinha e o teto de tentativas vale.
                $this->logger->error(sprintf('Espelho: item #%d ficou sem marcação (%s); volta à fila quando a posse vencer.', $item->id, $e::class));
            }
        }

        return $resumo;
    }

    private function processarItem(EspelhoRemoto $espelho, ItemDeReplicacao $item, ResumoDaRodada $resumo): void
    {
        try {
            $this->replicar($espelho, $item, $resumo);
        } catch (ArquivoNaoEncontrado) {
            $this->descartar($item, $resumo);
        } catch (ChaveDeArquivoInvalida|\InvalidArgumentException|\LogicException $e) {
            $this->outbox->marcarFalhou($item, self::semDadosPessoais($e, $item));
            $resumo->falharam++;
            $this->registrar($item, 'falhou (permanente: ' . $e::class . ')');
        } catch (FalhaNoEspelhoRemoto|FalhaDeArmazenamento|\Throwable $e) {
            $this->falhaTransitoria($item, $e, $resumo);
        }
    }

    /** Os passos de um item; as exceções sobem para `processarItem()`, que as classifica. */
    private function replicar(EspelhoRemoto $espelho, ItemDeReplicacao $item, ResumoDaRodada $resumo): void
    {
        if ($this->local->metadados($item->chave) === null) {
            $this->descartar($item, $resumo);

            return;
        }

        $medida = MedidaDeConteudo::de($this->local, $item->chave);
        if ($medida->tamanhoBytes !== $item->tamanhoBytes || !hash_equals($medida->sha256, $item->sha256)) {
            $this->superarPorConteudoMudado($item, $medida, $resumo);

            return;
        }

        $remoto = $espelho->consultar($item->chave);
        if ($remoto !== null && $remoto->confereCom($item->tamanhoBytes, $item->sha256)) {
            $this->concluido($item, $this->outbox->marcarVerificado($item));
            $resumo->jaEstavamNoEspelho++;

            return;
        }

        if (!$this->outbox->renovarPosse($item, self::POSSE_EM_SEGUNDOS)) {
            $resumo->possesPerdidas++;
            $this->registrar($item, 'não enviado: a posse foi perdida antes do PUT');

            return;
        }

        $this->enviarDoDisco($espelho, $item);
        $this->concluido($item, $this->outbox->marcarVerificado($item));
        $resumo->enviados++;
    }

    /** O disco mudou depois do registro: vale o mais novo, e se não houver, registra o conteúdo atual. */
    private function superarPorConteudoMudado(ItemDeReplicacao $item, MedidaDeConteudo $medida, ResumoDaRodada $resumo): void
    {
        $reregistrou = false;
        if (!$this->outbox->existeRegistroMaisNovo($item)) {
            $this->outbox->registrarEnvio($item->chave, $medida->sha256, $medida->tamanhoBytes, $item->mimeType);
            $reregistrou = true;
        }
        $this->outbox->marcarSuperado($item, $reregistrou
            ? 'o conteúdo local mudou sem registro mais novo; registrado um envio com o conteúdo atual'
            : 'o conteúdo local mudou depois do registro; vale o registro mais novo');
        $resumo->superados++;
        $this->registrar($item, $reregistrou ? 'superado (re-registrado com o conteúdo atual)' : 'superado (conteúdo local mudou)');
    }

    private function enviarDoDisco(EspelhoRemoto $espelho, ItemDeReplicacao $item): void
    {
        $stream = $this->local->abrir($item->chave);
        try {
            $espelho->enviar($item->chave, $stream, $item->tamanhoBytes, $item->sha256, $item->mimeType);
        } finally {
            if (\is_resource($stream)) {
                fclose($stream);
            }
        }
    }

    private function concluido(ItemDeReplicacao $item, bool $marcou): void
    {
        if (!$marcou) {
            $this->logger->warning(sprintf('Espelho: item #%d terminou depois de perder a posse; o estado de quem a pegou foi preservado.', $item->id));
        }
    }

    private function descartar(ItemDeReplicacao $item, ResumoDaRodada $resumo): void
    {
        $this->outbox->marcarDescartado($item, 'o arquivo local não existe mais; nada foi enviado nem apagado no espelho');
        $resumo->descartados++;
        $this->registrar($item, 'descartado (ausente no disco local)');
    }

    private function falhaTransitoria(ItemDeReplicacao $item, \Throwable $e, ResumoDaRodada $resumo): void
    {
        $erro = self::semDadosPessoais($e, $item);

        if ($item->tentativas >= self::MAXIMO_DE_TENTATIVAS) {
            $this->outbox->marcarFalhou($item, 'tentativas esgotadas: ' . $erro);
            $resumo->falharam++;
            $this->registrar($item, 'falhou (tentativas esgotadas)');

            return;
        }

        $espera = self::ESPERAS_EM_SEGUNDOS[min($item->tentativas, \count(self::ESPERAS_EM_SEGUNDOS)) - 1];
        if (!$this->outbox->reagendar($item, $erro, $espera)) {
            $resumo->possesPerdidas++;
            $this->concluido($item, false);

            return;
        }
        $resumo->reagendados++;
        $this->registrar($item, sprintf('reagendado em %ds (tentativa %d, %s)', $espera, $item->tentativas, $e::class));
    }

    /**
     * O erro como pode ser guardado e logado: a chave remota e o nome do arquivo trocados por
     * marcadores com o id da linha (nos legados de texto livre o nome é nome de pessoa).
     */
    public static function semDadosPessoais(\Throwable $e, ItemDeReplicacao $item): string
    {
        $texto = str_replace(
            [$item->chaveRemota, $item->chave->nome],
            [sprintf('<chave #%d>', $item->id), sprintf('<nome #%d>', $item->id)],
            $e::class . ': ' . $e->getMessage(),
        );

        return mb_substr($texto, 0, 500);
    }

    private function registrar(ItemDeReplicacao $item, string $oQue): void
    {
        $this->logger->info(sprintf(
            'Espelho: item #%d (categoria=%s, escritório=%s) %s.',
            $item->id,
            $item->chave->categoria->value,
            $item->chave->escopo->comoTexto(),
            $oQue,
        ));
    }
}
