<?php

declare(strict_types=1);

namespace App\Shared\EspelhoRemoto\Replica;

use App\Shared\Armazenamento\ArmazenamentoDeArquivos;
use App\Shared\Armazenamento\Exception\ArquivoNaoEncontrado;
use App\Shared\Armazenamento\Exception\ChaveDeArquivoInvalida;
use App\Shared\Armazenamento\Exception\FalhaDeArmazenamento;
use App\Shared\Armazenamento\Sha256DeArquivo;
use App\Shared\EspelhoRemoto\EspelhoRemoto;
use App\Shared\EspelhoRemoto\FalhaNoEspelhoRemoto;
use App\Shared\EspelhoRemoto\Outbox\ItemDeReplicacao;
use App\Shared\EspelhoRemoto\Outbox\OutboxDeReplicacao;
use Psr\Log\LoggerInterface;

/**
 * O worker da réplica: reivindica linhas da outbox e as leva ao espelho (spec P2 §3.3, §14).
 *
 * Para cada item, nesta ordem:
 *  1. o arquivo local sumiu → `descartado` (nada é enviado — e nada é apagado no R2);
 *  2. o conteúdo local não é mais o da linha (SHA ou tamanho) → `superado`: a linha mais nova do
 *     mesmo arquivo é que vale; uma versão velha nunca cai por cima de uma nova;
 *  3. o espelho já tem o objeto com o mesmo SHA e tamanho → `verificado`, sem PUT (idempotência:
 *     reprocessar depois de uma queda não reenvia);
 *  4. senão, envia pelo stream do disco local e marca `verificado`.
 *
 * Falha transitória (espelho fora, disco momentaneamente ilegível, erro inesperado) → reagenda com
 * backoff até {@see self::MAXIMO_DE_TENTATIVAS}; depois, `falhou`. Erro que não melhora com o tempo
 * (chave inválida, parâmetro recusado) → `falhou` na hora. O texto guardado nunca tem o nome do
 * arquivo nem a chave remota.
 */
final class ReplicadorDeArquivos
{
    public const MAXIMO_DE_TENTATIVAS = 6;
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
            $item = $this->outbox->reivindicarProxima(self::POSSE_EM_SEGUNDOS);
            if ($item === null) {
                break;
            }

            $resumo->reivindicados++;
            $this->processarItem($espelho, $item, $resumo);
        }

        return $resumo;
    }

    private function processarItem(EspelhoRemoto $espelho, ItemDeReplicacao $item, ResumoDaRodada $resumo): void
    {
        try {
            $noDisco = $this->local->metadados($item->chave);
            if ($noDisco === null) {
                $this->descartar($item, $resumo);

                return;
            }

            $shaNoDisco = Sha256DeArquivo::deChave($this->local, $item->chave);
            if ($noDisco->tamanhoBytes !== $item->tamanhoBytes || !hash_equals($shaNoDisco, $item->sha256)) {
                $this->outbox->marcarSuperado($item, 'o conteúdo local mudou depois do registro; vale o registro mais novo');
                $resumo->superados++;
                $this->registrar($item, 'superado (conteúdo local mudou)');

                return;
            }

            $remoto = $espelho->consultar($item->chave);
            if ($remoto !== null && $remoto->confereCom($item->tamanhoBytes, $item->sha256)) {
                $this->outbox->marcarVerificado($item);
                $resumo->jaEstavamNoEspelho++;

                return;
            }

            $stream = $this->local->abrir($item->chave);
            try {
                $espelho->enviar($item->chave, $stream, $item->tamanhoBytes, $item->sha256, $item->mimeType);
            } finally {
                if (\is_resource($stream)) {
                    fclose($stream);
                }
            }

            $this->outbox->marcarVerificado($item);
            $resumo->enviados++;
        } catch (ArquivoNaoEncontrado) {
            $this->descartar($item, $resumo);
        } catch (ChaveDeArquivoInvalida|\InvalidArgumentException|\LogicException $e) {
            $this->outbox->marcarFalhou($item, self::semDadosPessoais($e, $item));
            $resumo->falharam++;
            $this->registrar($item, 'falhou (permanente: ' . $e::class . ')');
        } catch (FalhaNoEspelhoRemoto|FalhaDeArmazenamento|\RuntimeException $e) {
            $this->falhaTransitoria($item, $e, $resumo);
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
        $this->outbox->reagendar($item, $erro, $espera);
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
