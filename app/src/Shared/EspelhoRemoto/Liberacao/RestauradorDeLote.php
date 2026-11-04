<?php

declare(strict_types=1);

namespace App\Shared\EspelhoRemoto\Liberacao;

use App\Shared\Armazenamento\ChaveDeArquivo;
use App\Shared\Armazenamento\DiscoDaLiberacao;
use App\Shared\Armazenamento\MovimentoNoDisco;
use App\Shared\EspelhoRemoto\EspelhoRemoto;
use App\Shared\EspelhoRemoto\EspelhoRemotoNaoConfigurado;
use App\Shared\EspelhoRemoto\FalhaNoEspelhoRemoto;
use App\Shared\EspelhoRemoto\Outbox\OutboxDeReplicacao;

/**
 * Volta atrás numa liberação (spec `leitura-pelo-r2-e-liberacao-de-disco.md` §5.4), linha aberta por
 * linha aberta:
 *  - a cópia ainda está em `.liberados/<lote>/`: volta ao lugar (link e depois unlink da cópia), só se
 *    o original não existe;
 *  - não está (lote esvaziado no futuro): baixa do R2, confere o SHA e o tamanho da linha e grava no
 *    lugar, sem sobrescrever;
 *  - nos dois casos, a linha fecha (`restaurado_em`).
 *
 * Nunca restaura o que o sistema mandou apagar: com exclusão registrada depois do último envio da chave
 * (D6), a linha fica como está. Original já presente com o SHA-256 da linha: a linha fecha ("já no
 * lugar"; na simulação só conta). Com outro SHA: pula e avisa, sem tocar em nada (a reconciliação
 * mostra o caso como `restaurado_sem_registro`).
 */
final class RestauradorDeLote
{
    public const DEVOLVIDO              = 'devolvido_dos_liberados';
    public const BAIXADO                = 'baixado_do_r2';
    public const DEVOLVERIA             = 'devolveria_dos_liberados';
    public const BAIXARIA               = 'baixaria_do_r2';
    public const ORIGINAL_EXISTE        = 'original_ja_existe';
    /** O original está no lugar com o SHA-256 da linha: a linha fecha (na simulação, fecharia). */
    public const JA_NO_LUGAR            = 'ja_no_lugar';
    /** O R2 entregou conteúdo que não tem o SHA-256 ou o tamanho da linha: nada foi gravado. */
    public const R2_DIVERGENTE          = 'r2_divergente';
    public const EXCLUSAO_REGISTRADA    = 'exclusao_registrada';
    public const LINHA_SEM_CHAVE        = 'linha_sem_chave';
    public const FALHA_NO_R2            = 'falha_no_r2';
    public const FALHA_NO_DISCO         = 'falha_no_disco';

    public function __construct(
        private readonly OutboxDeReplicacao $outbox,
        private readonly RegistroDeLiberacao $liberacoes,
        private readonly DiscoDaLiberacao $area,
    ) {
    }

    /**
     * `$espelho` só é chamado (e montado) quando uma linha precisa baixar do R2 com `$executar`.
     * `$aCadaArquivo` roda depois de cada linha (o comando renova a trava de execução única).
     *
     * @param list<LiberacaoAberta>    $linhas
     * @param \Closure(): EspelhoRemoto $espelho
     * @param (\Closure(): void)|null  $aCadaArquivo
     */
    public function restaurar(array $linhas, bool $executar, \Closure $espelho, ?\Closure $aCadaArquivo = null): ResumoDaRestauracao
    {
        $resumo = new ResumoDaRestauracao();
        foreach ($linhas as $linha) {
            $resumo->contar($this->restaurarUma($linha, $executar, $espelho));
            if ($aCadaArquivo !== null) {
                $aCadaArquivo();
            }
        }

        return $resumo;
    }

    /** @param \Closure(): EspelhoRemoto $espelho */
    private function restaurarUma(LiberacaoAberta $linha, bool $executar, \Closure $espelho): string
    {
        $chave = $linha->chave;
        if ($chave === null) {
            return self::LINHA_SEM_CHAVE;
        }

        try {
            if ($this->outbox->temExclusaoDepoisDoUltimoEnvio($linha->chaveRemota)) {
                return self::EXCLUSAO_REGISTRADA;
            }
            if ($this->area->noDisco($chave) !== null) {
                return $this->originalNoLugar($chave, $linha, $executar);
            }

            $nosLiberados = $this->area->estaNosLiberados($chave, $linha->lote);
            if (!$executar) {
                return $nosLiberados ? self::DEVOLVERIA : self::BAIXARIA;
            }
            $movimento = $nosLiberados ? $this->area->devolverDosLiberados($chave, $linha->lote) : $this->baixarParaOLugar($chave, $linha, $espelho());
        } catch (EspelhoRemotoNaoConfigurado $e) {
            throw $e;
        } catch (FalhaNoEspelhoRemoto) {
            return self::FALHA_NO_R2;
        } catch (\Throwable) {
            return self::FALHA_NO_DISCO;
        }

        return match ($movimento) {
            MovimentoNoDisco::DESTINO_EXISTE      => self::ORIGINAL_EXISTE,
            MovimentoNoDisco::ORIGEM_AUSENTE      => self::FALHA_NO_DISCO,
            MovimentoNoDisco::CONTEUDO_DIVERGENTE => self::R2_DIVERGENTE,
            MovimentoNoDisco::MOVIDO         => $this->fecharComo($linha, $nosLiberados ? self::DEVOLVIDO : self::BAIXADO),
        };
    }

    private function baixarParaOLugar(ChaveDeArquivo $chave, LiberacaoAberta $linha, EspelhoRemoto $r2): MovimentoNoDisco
    {
        return $this->area->publicarNoOriginal(
            $chave,
            static function ($destino) use ($r2, $chave, $linha): void {
                $r2->baixar($chave, $destino, $linha->tamanhoBytes, $linha->sha256);
            },
            $linha->sha256,
            $linha->tamanhoBytes,
        );
    }

    /** O original já está no lugar: com o SHA-256 da linha, a restauração está feita e a linha fecha. */
    private function originalNoLugar(ChaveDeArquivo $chave, LiberacaoAberta $linha, bool $executar): string
    {
        if (!hash_equals($linha->sha256, $this->area->sha256NoDisco($chave))) {
            return self::ORIGINAL_EXISTE;
        }

        return $executar ? $this->fecharComo($linha, self::JA_NO_LUGAR) : self::JA_NO_LUGAR;
    }

    private function fecharComo(LiberacaoAberta $linha, string $resultado): string
    {
        $this->liberacoes->fechar($linha->chaveRemota);

        return $resultado;
    }
}
