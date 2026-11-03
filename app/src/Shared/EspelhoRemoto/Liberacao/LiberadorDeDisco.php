<?php

declare(strict_types=1);

namespace App\Shared\EspelhoRemoto\Liberacao;

use App\Shared\Armazenamento\CategoriaDeArquivo;
use App\Shared\Armazenamento\ChaveDeArquivo;
use App\Shared\Armazenamento\DiscoDaLiberacao;
use App\Shared\Armazenamento\Exception\ArquivoNaoEncontrado;
use App\Shared\Armazenamento\Exception\ChaveDeArquivoInvalida;
use App\Shared\Armazenamento\MovimentoNoDisco;
use App\Shared\EspelhoRemoto\EspelhoRemoto;
use App\Shared\EspelhoRemoto\FalhaNoEspelhoRemoto;
use App\Shared\EspelhoRemoto\LayoutDeChaveRemota;
use App\Shared\EspelhoRemoto\Outbox\OutboxDeReplicacao;
use App\Shared\EspelhoRemoto\Reconciliacao\FonteDeReferencias;
use App\Shared\EspelhoRemoto\Reconciliacao\ReferenciaDeArquivo;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Psr\Clock\ClockInterface;

/**
 * Libera o disco sem esvaziar (spec `leitura-pelo-r2-e-liberacao-de-disco.md` §5.2 e §5.3): escolhe os
 * candidatos e, para cada um, prova o R2 por download, abre a linha de liberação e tira a cópia local
 * do lugar para `.liberados/<lote>/`. Nada é apagado aqui; o esvaziamento é outra etapa (bloqueada).
 *
 * Candidato (todas as condições):
 *  1. referenciado no banco, pela mesma fonte da reconciliação ({@see FonteDeReferencias});
 *  2. a linha mais nova da outbox é o envio `verificado` da chave (exclusão, reenvio pendente ou falha
 *     depois dele tiram a prova), e o SHA local de agora é o mesmo desse envio;
 *  3. sem linha de liberação aberta, e nenhuma linha do banco que a cita na lixeira;
 *  4. frio: a data do banco ({@see DatasDasReferencias}), ou o `mtime` quando o banco não tem, mais
 *     velha que os dias pedidos; e, com o critério de acesso ligado, o `atime` mais velho que
 *     {@see DIAS_SEM_ACESSO}.
 * Ordem: do mais antigo para o mais novo.
 *
 * Por arquivo: (1) baixa do R2 para um temporário e mede o SHA do que chegou; (2) abre a linha (o
 * DBAL confirma na hora, sem transação aberta); (3) mede de novo o SHA local (mudou: fecha a linha e
 * pula); (4) move por `rename`, sem sobrescrever (destino ocupado: fecha a linha e pula); (5) mede o
 * SHA da cópia já nos liberados (mudou entre a medida e o movimento: devolve e fecha; sem conseguir
 * devolver, a cópia fica presa nos liberados com a linha aberta e entra no manifesto). Falha num
 * arquivo conta e segue.
 */
final class LiberadorDeDisco
{
    /** As categorias em que liberar compensa o risco (§5.6). */
    public const CATEGORIAS_LIBERAVEIS = [CategoriaDeArquivo::PASTA_DOCUMENTO, CategoriaDeArquivo::PASTA_IMAGEM_EDITOR];

    /**
     * A trava de execução única (tabela `lock_keys`, vale entre contêineres) que liberar e restaurar
     * dividem: os dois mexem nas mesmas linhas e nos mesmos liberados. Renovada a cada arquivo.
     */
    public const TRAVA = 'armazenamento-liberacao-do-disco';

    public const DURACAO_DA_TRAVA_EM_SEGUNDOS = 1800;

    /** Sem entrega (pelo `atime`) há pelo menos isto, quando o critério de acesso está ligado. */
    public const DIAS_SEM_ACESSO = 30;

    public const JA_LIBERADO          = 'ja_liberado';
    public const SEM_ENVIO_VERIFICADO = 'sem_envio_verificado';
    public const AUSENTE_NO_DISCO     = 'ausente_no_disco';
    public const TAMANHO_DIVERGENTE   = 'tamanho_divergente';
    public const RECENTE              = 'recente';
    public const ACESSADO_RECENTEMENTE = 'acessado_recentemente';
    public const NA_LIXEIRA           = 'na_lixeira';
    public const SHA_LOCAL_DIVERGENTE = 'sha_local_divergente';
    public const FALHA_NO_R2          = 'falha_no_r2';
    public const R2_DIVERGENTE        = 'r2_divergente';
    public const MUDOU_NO_DISCO       = 'mudou_no_disco';
    public const DESTINO_EXISTE       = 'destino_existe';
    public const FALHA_NO_DISCO       = 'falha_no_disco';
    /** Moveu, e a conferência depois do movimento falhou: a cópia fica nos liberados e a linha, aberta. */
    public const FALHA_DEPOIS_DE_MOVER = 'falha_depois_de_mover';
    /**
     * Regravado entre a medida e o movimento, e o lugar original já ocupado de novo: a cópia (que não é
     * o que o R2 tem) fica nos liberados e a linha, aberta, para conferência à mão.
     */
    public const COPIA_PRESA_NOS_LIBERADOS = 'copia_presa_nos_liberados';

    /** Os motivos em que a linha fica aberta e a cópia nos liberados: entram no manifesto. */
    public const MOTIVOS_COM_COPIA_NOS_LIBERADOS = [self::FALHA_DEPOIS_DE_MOVER, self::COPIA_PRESA_NOS_LIBERADOS];

    public function __construct(
        private readonly FonteDeReferencias $fontes,
        private readonly DatasDasReferencias $datas,
        private readonly OutboxDeReplicacao $outbox,
        private readonly RegistroDeLiberacao $liberacoes,
        private readonly DiscoDaLiberacao $area,
        private readonly ClockInterface $relogio,
    ) {
    }

    public function selecionar(CategoriaDeArquivo $categoria, int $frioDias, bool $comAcesso, int $tamanhoDoLote): SelecaoParaLiberar
    {
        $fora       = [];
        $candidatos = [];
        $agora      = $this->relogio->now();
        $limiteFrio = $agora->modify(sprintf('-%d days', $frioDias));
        $limiteUso  = $agora->modify(sprintf('-%d days', self::DIAS_SEM_ACESSO));

        foreach ($this->referenciadas($categoria) as $chaveRemota => ['chave' => $chave, 'data' => $data, 'naLixeira' => $naLixeira]) {
            $avaliado = $naLixeira ? self::NA_LIXEIRA : $this->avaliar($chave, $chaveRemota, $data, $limiteFrio, $comAcesso ? $limiteUso : null);
            if (\is_string($avaliado)) {
                $fora[$avaliado] = ($fora[$avaliado] ?? 0) + 1;

                continue;
            }
            $candidatos[] = $avaliado;
        }

        usort($candidatos, static fn (CandidatoALiberar $a, CandidatoALiberar $b): int => [$a->dataDeReferencia, $a->chaveRemota] <=> [$b->dataDeReferencia, $b->chaveRemota]);
        $bytes = array_sum(array_map(static fn (CandidatoALiberar $c): int => $c->tamanhoBytes, $candidatos));

        $lote = [];
        foreach ($candidatos as $candidato) {
            if (\count($lote) >= $tamanhoDoLote) {
                break;
            }
            $motivo = $this->conferirShaLocal($candidato);
            if ($motivo !== null) {
                $fora[$motivo] = ($fora[$motivo] ?? 0) + 1;

                continue;
            }
            $lote[] = $candidato;
        }
        ksort($fora);

        return new SelecaoParaLiberar(\count($candidatos), $bytes, $lote, $fora);
    }

    /** Os critérios baratos, na ordem: o primeiro que falha é o motivo de ficar fora. */
    private function avaliar(ChaveDeArquivo $chave, string $chaveRemota, ?\DateTimeImmutable $data, \DateTimeImmutable $limiteFrio, ?\DateTimeImmutable $limiteUso): CandidatoALiberar|string
    {
        if ($this->liberacoes->abertaDe($chaveRemota) !== null) {
            return self::JA_LIBERADO;
        }
        $envio = $this->outbox->envioVerificadoDe($chaveRemota);
        if ($envio === null) {
            return self::SEM_ENVIO_VERIFICADO;
        }
        $noDisco = $this->area->noDisco($chave);
        if ($noDisco === null) {
            return self::AUSENTE_NO_DISCO;
        }
        if ($noDisco->tamanhoBytes !== $envio->tamanhoBytes) {
            return self::TAMANHO_DIVERGENTE;
        }
        $referencia = $data ?? $noDisco->modificadoEm;
        if ($referencia > $limiteFrio) {
            return self::RECENTE;
        }
        if ($limiteUso !== null && $noDisco->acessadoEm > $limiteUso) {
            return self::ACESSADO_RECENTEMENTE;
        }

        return new CandidatoALiberar($chave, $chaveRemota, $envio->sha256, $envio->tamanhoBytes, $referencia);
    }

    /**
     * `$aCadaArquivo` roda depois de cada arquivo (o comando renova a trava de execução única).
     *
     * @param list<CandidatoALiberar> $candidatos
     * @param (\Closure(): void)|null $aCadaArquivo
     */
    public function liberar(EspelhoRemoto $espelho, array $candidatos, string $lote, ?\Closure $aCadaArquivo = null): ResumoDaLiberacao
    {
        $resumo = new ResumoDaLiberacao();
        foreach ($candidatos as $candidato) {
            $motivo = $this->liberarUm($espelho, $candidato, $lote);
            $motivo === null ? $resumo->liberado($candidato) : $resumo->pulado($motivo, $candidato);
            if ($aCadaArquivo !== null) {
                $aCadaArquivo();
            }
        }

        return $resumo;
    }

    /** @return string|null o motivo de ter pulado; null = liberado */
    private function liberarUm(EspelhoRemoto $espelho, CandidatoALiberar $c, string $lote): ?string
    {
        $motivo = $this->provarNoR2($espelho, $c);
        if ($motivo !== null) {
            return $motivo;
        }

        try {
            $this->liberacoes->registrar($lote, $c->chave, $c->sha256, $c->tamanhoBytes);
        } catch (UniqueConstraintViolationException) {
            return self::JA_LIBERADO;
        } catch (\Throwable) {
            return self::FALHA_NO_DISCO;
        }

        $movido = false;
        try {
            $motivo = $this->conferirShaLocal($c);
            $motivo = $motivo === null ? $this->moverConferindo($c, $lote, $movido) : ($motivo === self::SHA_LOCAL_DIVERGENTE ? self::MUDOU_NO_DISCO : $motivo);
        } catch (\Throwable) {
            $motivo = $movido ? self::FALHA_DEPOIS_DE_MOVER : self::FALHA_NO_DISCO;
        }
        if ($motivo !== null && !\in_array($motivo, self::MOTIVOS_COM_COPIA_NOS_LIBERADOS, true)) {
            $this->fecharSemFalhar($c->chaveRemota);
        }

        return $motivo;
    }

    /** Passo 1: baixa do R2 para um temporário e mede o que chegou. */
    private function provarNoR2(EspelhoRemoto $espelho, CandidatoALiberar $c): ?string
    {
        try {
            $baixado = $this->area->medirDownload(static function ($destino) use ($espelho, $c): void {
                $espelho->baixar($c->chave, $destino, $c->tamanhoBytes, $c->sha256);
            });
        } catch (FalhaNoEspelhoRemoto) {
            return self::FALHA_NO_R2;
        } catch (\Throwable) {
            return self::FALHA_NO_DISCO;
        }

        return $baixado['tamanhoBytes'] !== $c->tamanhoBytes || !hash_equals($c->sha256, $baixado['sha256']) ? self::R2_DIVERGENTE : null;
    }

    /**
     * Passos 4 e 5: move sem sobrescrever e mede a cópia já nos liberados. Regravado entre a medida e o
     * movimento, o que saiu não é o que o R2 tem: volta ao lugar, e quem chama fecha a linha. Com o
     * lugar já ocupado de novo, a cópia fica presa nos liberados e a linha, aberta. Nada é apagado.
     */
    private function moverConferindo(CandidatoALiberar $c, string $lote, bool &$movido): ?string
    {
        $movimento = $this->area->moverParaLiberados($c->chave, $lote);
        if ($movimento !== MovimentoNoDisco::MOVIDO) {
            return $movimento === MovimentoNoDisco::DESTINO_EXISTE ? self::DESTINO_EXISTE : self::AUSENTE_NO_DISCO;
        }
        $movido = true;

        if (!hash_equals($c->sha256, $this->area->sha256NosLiberados($c->chave, $lote))) {
            return $this->area->devolverDosLiberados($c->chave, $lote) === MovimentoNoDisco::MOVIDO ? self::MUDOU_NO_DISCO : self::COPIA_PRESA_NOS_LIBERADOS;
        }

        return null;
    }

    /** @return string|null o motivo de o SHA local não servir; null = igual ao do envio */
    private function conferirShaLocal(CandidatoALiberar $c): ?string
    {
        try {
            return hash_equals($c->sha256, $this->area->sha256NoDisco($c->chave)) ? null : self::SHA_LOCAL_DIVERGENTE;
        } catch (ArquivoNaoEncontrado) {
            return self::AUSENTE_NO_DISCO;
        } catch (\Throwable) {
            return self::FALHA_NO_DISCO;
        }
    }

    private function fecharSemFalhar(string $chaveRemota): void
    {
        try {
            $this->liberacoes->fechar($chaveRemota);
        } catch (\Throwable) {
            // A linha aberta sem o arquivo movido é inofensiva: a reconciliação a vê como
            // `restaurado_sem_registro` (arquivo no disco com linha aberta) e avisa para fechar.
        }
    }

    /**
     * As chaves da categoria que o banco referencia, uma vez cada, com a data mais nova entre as linhas
     * que a citam (null quando nenhuma tem data) e se alguma delas está na lixeira. A categoria é
     * filtrada antes de qualquer consulta, e as datas saem por bloco só das linhas da categoria.
     *
     * @return array<string, array{chave: ChaveDeArquivo, data: ?\DateTimeImmutable, naLixeira: bool}>
     */
    private function referenciadas(CategoriaDeArquivo $categoria): array
    {
        /** @var array<string, array{chave: ChaveDeArquivo, referencias: list<ReferenciaDeArquivo>}> $porChave */
        $porChave = [];
        foreach ($this->fontes->referencias() as $referencia) {
            $chave = $referencia->chave;
            if ($chave === null || $chave->categoria !== $categoria) {
                continue;
            }
            try {
                $chaveRemota = LayoutDeChaveRemota::chaveDe($chave);
            } catch (ChaveDeArquivoInvalida) {
                continue;
            }
            $porChave[$chaveRemota]['chave']          = $chave;
            $porChave[$chaveRemota]['referencias'][] = $referencia;
        }

        $doBanco = $this->datas->para(array_merge(...array_column($porChave, 'referencias')));
        $saida   = [];
        foreach ($porChave as $chaveRemota => ['chave' => $chave, 'referencias' => $referencias]) {
            $data      = null;
            $naLixeira = false;
            foreach ($referencias as $referencia) {
                $linha = $doBanco[DatasDasReferencias::chaveDe($referencia->fonte, $referencia->idDaLinha)] ?? null;
                if ($linha === null) {
                    continue;
                }
                $naLixeira = $naLixeira || $linha['naLixeira'];
                if ($linha['data'] !== null && ($data === null || $linha['data'] > $data)) {
                    $data = $linha['data'];
                }
            }
            $saida[(string) $chaveRemota] = ['chave' => $chave, 'data' => $data, 'naLixeira' => $naLixeira];
        }

        return $saida;
    }
}
