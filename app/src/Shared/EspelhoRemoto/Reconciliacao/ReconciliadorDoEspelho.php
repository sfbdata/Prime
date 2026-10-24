<?php

declare(strict_types=1);

namespace App\Shared\EspelhoRemoto\Reconciliacao;

use App\Shared\Armazenamento\ArmazenamentoDeArquivos;
use App\Shared\Armazenamento\ChaveDeArquivo;
use App\Shared\Armazenamento\Exception\ArquivoNaoEncontrado;
use App\Shared\Armazenamento\Exception\ChaveDeArquivoInvalida;
use App\Shared\Armazenamento\Sha256DeArquivo;
use App\Shared\EspelhoRemoto\EspelhoRemoto;
use App\Shared\EspelhoRemoto\LayoutDeChaveRemota;
use App\Shared\EspelhoRemoto\Outbox\OutboxDeReplicacao;
use App\Shared\EspelhoRemoto\PoliticaDeEspelhamento;

/**
 * Reconciliação banco × disco × espelho — **só leitura** (spec P2 §3.4). Não enfileira, não envia,
 * não apaga: classifica e devolve o relatório. Corrigir é de outra etapa.
 *
 * Para cada referência do banco: o arquivo local existe? o espelho tem o objeto? com o mesmo tamanho?
 * com o mesmo SHA-256 (o do disco, medido agora, contra o metadado do espelho)? Ausente no espelho com
 * envio ainda na fila é separado de ausente sem nada na fila — o primeiro se resolve sozinho.
 *
 * O conteúdo de erro 401 do Drive (os 90 da P1) tem SHA conhecido: sai como `suspeito`, nunca como
 * `igual` (DP-7).
 */
final class ReconciliadorDoEspelho
{
    /** SHA-256 da resposta de erro 401 do Google gravada como documento (P1, 15/10/2026). */
    public const SHA_DO_ERRO_401_DO_DRIVE = '0a93453f82f4d413ae29ad3ecb8d334e2d5d7484f0253674203c0413a268a459';

    public function __construct(
        private readonly FonteDeReferencias $fontes,
        private readonly ArmazenamentoDeArquivos $local,
        private readonly OutboxDeReplicacao $outbox,
        private readonly string $shaSuspeito = self::SHA_DO_ERRO_401_DO_DRIVE,
    ) {
    }

    /**
     * `$soAsLigadas`: confere só as referências das categorias que a política espelha (o modo agendado,
     * P2.5). Sem ela, todas as fontes, como no dry-run da P2.3. O limite conta só o que foi conferido.
     */
    public function reconciliar(EspelhoRemoto $espelho, bool $conferirSha = true, ?int $limite = null, ?PoliticaDeEspelhamento $soAsLigadas = null): RelatorioDeReconciliacao
    {
        $relatorio = new RelatorioDeReconciliacao();
        $vistos    = 0;

        foreach ($this->fontes->referencias() as $referencia) {
            if ($soAsLigadas !== null) {
                $categoria = $referencia->categoria();
                if ($categoria === null || !$soAsLigadas->espelha($categoria)) {
                    continue;
                }
            }
            if ($limite !== null && $vistos >= $limite) {
                break;
            }
            $vistos++;

            $relatorio->registrar($referencia, $this->classificar($espelho, $referencia, $conferirSha));
        }

        return $relatorio;
    }

    private function classificar(EspelhoRemoto $espelho, ReferenciaDeArquivo $referencia, bool $conferirSha): ClasseDeReconciliacao
    {
        $chave = $referencia->chave;
        if ($chave === null) {
            return ClasseDeReconciliacao::CHAVE_INVALIDA;
        }

        try {
            return $this->comparar($espelho, $chave, $conferirSha);
        } catch (ArquivoNaoEncontrado) {
            return ClasseDeReconciliacao::LOCAL_AUSENTE;
        } catch (ChaveDeArquivoInvalida) {
            return ClasseDeReconciliacao::CHAVE_INVALIDA;
        } catch (\Throwable) {
            // Pane de qualquer lado (espelho, disco, banco da outbox): registra e segue — o relatório
            // parcial vale mais que a rodada abortada. Pane ≠ ausência (D10).
            return ClasseDeReconciliacao::ERRO_DE_CONSULTA;
        }
    }

    private function comparar(EspelhoRemoto $espelho, ChaveDeArquivo $chave, bool $conferirSha): ClasseDeReconciliacao
    {
        $noDisco = $this->local->metadados($chave);
        if ($noDisco === null) {
            return ClasseDeReconciliacao::LOCAL_AUSENTE;
        }

        $shaNoDisco = $conferirSha ? Sha256DeArquivo::deChave($this->local, $chave) : null;
        if ($shaNoDisco !== null && hash_equals($this->shaSuspeito, $shaNoDisco)) {
            return ClasseDeReconciliacao::SUSPEITO_ERRO_DO_DRIVE;
        }

        $remoto = $espelho->consultar($chave);
        if ($remoto === null) {
            $estado = $this->outbox->ultimoEstadoDe(LayoutDeChaveRemota::chaveDe($chave));

            return \in_array($estado, [OutboxDeReplicacao::ESTADO_PENDENTE, OutboxDeReplicacao::ESTADO_PROCESSANDO], true)
                ? ClasseDeReconciliacao::AUSENTE_PENDENTE_OUTBOX
                : ClasseDeReconciliacao::AUSENTE_NO_ESPELHO;
        }

        if ($remoto->tamanhoBytes !== $noDisco->tamanhoBytes) {
            return ClasseDeReconciliacao::TAMANHO_DIVERGENTE;
        }
        if ($shaNoDisco === null) {
            return ClasseDeReconciliacao::PRESENTE_SEM_SHA_CONFERIDO;
        }
        if ($remoto->sha256 === null) {
            return ClasseDeReconciliacao::SEM_SHA_NO_ESPELHO;
        }

        return hash_equals($remoto->sha256, $shaNoDisco) ? ClasseDeReconciliacao::IGUAL : ClasseDeReconciliacao::SHA_DIVERGENTE;
    }
}
