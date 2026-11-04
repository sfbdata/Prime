<?php

declare(strict_types=1);

namespace App\Shared\EspelhoRemoto\Liberacao;

use App\Shared\Armazenamento\DiscoDaLiberacao;
use App\Shared\EspelhoRemoto\EspelhoRemoto;
use App\Shared\EspelhoRemoto\EspelhoRemotoNaoConfigurado;
use App\Shared\EspelhoRemoto\FalhaNoEspelhoRemoto;
use App\Shared\EspelhoRemoto\Outbox\OutboxDeReplicacao;
use Psr\Clock\ClockInterface;
use Psr\Log\LoggerInterface;

/**
 * Esvazia um lote liberado (spec `leitura-pelo-r2-e-liberacao-de-disco.md` §5.4): apaga de vez a cópia
 * em `.liberados/<lote>/`, e é aí que o disco cai. A linha continua ABERTA: o documento segue sendo
 * lido pelo R2, e `armazenamento:restaurar-lote` o traz de volta baixando do R2.
 *
 * Cada cópia só sai com todas as provas, nesta ordem, na hora:
 *  1. a linha tem mais de {@see DIAS_MINIMOS_DO_LOTE} dias;
 *  2. o sistema não mandou apagar a chave (exclusão na outbox) e o original não voltou ao lugar;
 *  3. o último registro da chave na outbox é um envio `verificado` com o SHA-256 e o tamanho da linha,
 *     ou a outbox não tem linha nenhuma da chave (a limpeza de 90 dias apagou o envio): a mesma regra
 *     da leitura (`ArmazenamentoEspelhado::provaDaChaveRemota`), em que a linha de liberação vira a prova;
 *  4. a cópia nos liberados tem o SHA-256 da linha;
 *  5. (só com `$executar`) o R2 entrega, num download inteiro, o SHA-256 e o tamanho da linha.
 *
 * Qualquer prova que falha: a cópia fica, a linha fica, e o motivo entra na contagem.
 */
final class EsvaziadorDeLote
{
    /** Dias desde a liberação antes de a cópia poder ser apagada (spec §5.4). */
    public const DIAS_MINIMOS_DO_LOTE = 7;

    public const ESVAZIADO            = 'esvaziado';
    public const ESVAZIARIA           = 'esvaziaria';
    public const RECENTE              = 'liberado_ha_menos_de_7_dias';
    public const JA_FORA              = 'ja_fora_dos_liberados';
    public const ORIGINAL_NO_LUGAR    = 'original_no_lugar';
    public const EXCLUSAO_REGISTRADA  = 'exclusao_registrada';
    public const SEM_ENVIO_VERIFICADO = 'sem_envio_verificado_igual';
    public const COPIA_DIVERGENTE     = 'copia_divergente';
    public const R2_DIVERGENTE        = 'r2_divergente';
    public const LINHA_SEM_CHAVE      = 'linha_sem_chave';
    public const FALHA_NO_R2          = 'falha_no_r2';
    public const FALHA_NO_DISCO       = 'falha_no_disco';

    public function __construct(
        private readonly OutboxDeReplicacao $outbox,
        private readonly DiscoDaLiberacao $area,
        private readonly ClockInterface $relogio,
        private readonly LoggerInterface $logger,
    ) {
    }

    /**
     * `$espelho` só é chamado (e montado) com `$executar`. `$aCadaArquivo` roda depois de cada linha
     * (o comando renova a trava de execução única).
     *
     * @param list<LiberacaoAberta>    $linhas
     * @param \Closure(): EspelhoRemoto $espelho
     * @param (\Closure(): void)|null  $aCadaArquivo
     */
    public function esvaziar(array $linhas, bool $executar, \Closure $espelho, ?\Closure $aCadaArquivo = null): ResumoDaRestauracao
    {
        $resumo = new ResumoDaRestauracao();
        $limite = $this->relogio->now()->modify(sprintf('-%d days', self::DIAS_MINIMOS_DO_LOTE));
        foreach ($linhas as $linha) {
            $resumo->contar($this->esvaziarUma($linha, $executar, $espelho, $limite));
            if ($aCadaArquivo !== null) {
                $aCadaArquivo();
            }
        }

        return $resumo;
    }

    /** @param \Closure(): EspelhoRemoto $espelho */
    private function esvaziarUma(LiberacaoAberta $linha, bool $executar, \Closure $espelho, \DateTimeImmutable $limite): string
    {
        $chave = $linha->chave;
        if ($chave === null) {
            return self::LINHA_SEM_CHAVE;
        }
        if ($linha->liberadoEm > $limite) {
            return self::RECENTE;
        }

        try {
            if ($this->outbox->temExclusaoDepoisDoUltimoEnvio($linha->chaveRemota)) {
                return self::EXCLUSAO_REGISTRADA;
            }
            if (!$this->area->estaNosLiberados($chave, $linha->lote)) {
                return self::JA_FORA;
            }
            if ($this->area->noDisco($chave) !== null) {
                return self::ORIGINAL_NO_LUGAR;
            }
            if (!$this->outboxConfirma($linha)) {
                return self::SEM_ENVIO_VERIFICADO;
            }
            if (!hash_equals($linha->sha256, $this->area->sha256NosLiberados($chave, $linha->lote))) {
                return self::COPIA_DIVERGENTE;
            }
            if (!$executar) {
                return self::ESVAZIARIA;
            }

            $r2      = $espelho();
            $baixado = $this->area->medirDownload(static function ($destino) use ($r2, $chave, $linha): void {
                $r2->baixar($chave, $destino, $linha->tamanhoBytes, $linha->sha256);
            });
            if ($baixado['tamanhoBytes'] !== $linha->tamanhoBytes || !hash_equals($linha->sha256, $baixado['sha256'])) {
                return self::R2_DIVERGENTE;
            }

            $this->area->apagarDosLiberados($chave, $linha->lote);
        } catch (EspelhoRemotoNaoConfigurado $e) {
            throw $e;
        } catch (FalhaNoEspelhoRemoto $e) {
            $this->registrarFalha($linha, $e);

            return self::FALHA_NO_R2;
        } catch (\Throwable $e) {
            $this->registrarFalha($linha, $e);

            return self::FALHA_NO_DISCO;
        }

        return self::ESVAZIADO;
    }

    /** Envio `verificado` igual à linha, ou nenhuma linha da chave na outbox (prova 3). */
    private function outboxConfirma(LiberacaoAberta $linha): bool
    {
        $envio = $this->outbox->envioVerificadoDe($linha->chaveRemota);
        if ($envio === null) {
            return $this->outbox->ultimoEstadoDe($linha->chaveRemota) === null;
        }

        return $envio->tamanhoBytes === $linha->tamanhoBytes && hash_equals($linha->sha256, $envio->sha256);
    }

    /** Só o id da linha e a classe do erro: a mensagem pode trazer o nome do arquivo. */
    private function registrarFalha(LiberacaoAberta $linha, \Throwable $e): void
    {
        $this->logger->warning('Esvaziamento: a cópia da linha {id} do lote {lote} ficou ({erro}).', [
            'id'   => $linha->id,
            'lote' => $linha->lote,
            'erro' => $e::class,
        ]);
    }
}
