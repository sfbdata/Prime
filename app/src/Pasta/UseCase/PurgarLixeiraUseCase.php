<?php

declare(strict_types=1);

namespace App\Pasta\UseCase;

use App\Pasta\Armazenamento\ChavesDePasta;
use App\Pasta\Entity\PastaDocumento;
use App\Pasta\Entity\PastaSecao;
use App\Pasta\Repository\PastaDocumentoRepository;
use App\Pasta\Repository\PastaSecaoRepository;
use App\Shared\Armazenamento\ChaveDeArquivo;
use App\Shared\Armazenamento\RemocaoAposTransacao;
use App\Shared\Doctrine\Filter\AcessoALixeira;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Clock\ClockInterface;

/**
 * Esvazia a lixeira vencida: apaga DE VERDADE (linha e arquivo) o que está na lixeira há mais de
 * N dias (D7, S-10: 30). É o único caminho pelo qual um documento da aba Documentos sai do disco
 * desde o L7 — e por isso herda os três casos INV-6 da exclusão antiga: o arquivo só sai DEPOIS do
 * COMMIT; banco que recusa → nada sai; disco que recusa depois do COMMIT → o banco é autoritativo,
 * o arquivo fica como órfão registrado.
 *
 * Quem dispara é o operador, pelo comando `app:documentos:purgar-lixeira` (sem cron nesta frente).
 * O que ele quer: liberar o espaço do que ninguém restaurou no prazo, sem tocar no que ainda pode
 * voltar.
 *
 * Como percorre:
 *  - **seções vencidas primeiro**, da mais antiga à mais nova. Apagar a seção leva a subárvore
 *    inteira (cascade do ORM e das FKs `secao_pai_id`/`secao_id`): as chaves de TODOS os
 *    documentos de dentro são coletadas antes do `remove()`, qualquer que seja o carimbo deles —
 *    o banco os levaria de qualquer jeito, e sem a coleta os arquivos ficariam órfãos. Em dados
 *    consistentes isso nunca apaga item vivo: nada vivo fica pendurado em seção excluída (o
 *    restaurar manda para a raiz);
 *  - **depois os documentos vencidos** que sobraram (os soltos e os de seções ainda vivas);
 *  - em lotes de {@see LOTE}: um `flush` (COMMIT) por lote, e só então os arquivos do lote saem.
 *    Um lote recusado pelo banco interrompe a execução com os anteriores já consistentes;
 *  - `--limite` conta ENTRADAS DA FILA (seções e documentos vencidos, não a descendência);
 *    rodar de novo retoma de onde parou, porque a fila é "quem ainda está vencido" — idempotente;
 *  - uma entrada que sumiu entre a listagem e a carga (caiu pelo cascade de um ancestral neste
 *    mesmo laço, ou foi purgada por outra execução) é pulada; uma que foi RESTAURADA no meio é
 *    pulada também — reconferida pelo carimbo antes do `remove()`.
 *
 * Roda inteiro dentro de `AcessoALixeira::comLixeiraVisivel()`: é um dos três lugares que
 * enxergam a lixeira. Auditoria: cada linha que sai é um `delete` do `AuditLogSubscriber`,
 * descendência inclusive (o cascade do ORM a agenda).
 */
final class PurgarLixeiraUseCase
{
    /** Entradas da fila por flush/clear. */
    public const LOTE = 100;

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly PastaDocumentoRepository $documentos,
        private readonly PastaSecaoRepository $secoes,
        private readonly AcessoALixeira $lixeira,
        private readonly RemocaoAposTransacao $remocao,
        private readonly ClockInterface $clock,
    ) {
    }

    /**
     * @param int      $dias      retenção: só sai o que foi excluído há MAIS de $dias
     * @param bool     $simulacao `--dry-run`: conta e lista, não apaga nada
     * @param int|null $limite    máximo de entradas da fila nesta execução; null = todas
     */
    public function executar(int $dias, bool $simulacao, ?int $limite = null): ResultadoPurgaDaLixeira
    {
        if ($dias < 1) {
            throw new \InvalidArgumentException('A retenção precisa ser de pelo menos 1 dia.');
        }
        if ($limite !== null && $limite < 1) {
            throw new \InvalidArgumentException('O limite precisa ser um inteiro positivo.');
        }

        $corte = $this->clock->now()->modify(sprintf('-%d days', $dias));

        return $this->lixeira->comLixeiraVisivel(fn (): ResultadoPurgaDaLixeira => $this->purgar($corte, $simulacao, $limite));
    }

    private function purgar(\DateTimeImmutable $corte, bool $simulacao, ?int $limite): ResultadoPurgaDaLixeira
    {
        $candidatosSecoes     = $this->secoes->contarNaLixeiraVencida($corte);
        $candidatosDocumentos = $this->documentos->contarNaLixeiraVencida($corte);

        $idsSecoes     = $this->secoes->idsNaLixeiraVencida($corte, $limite);
        $sobra         = $limite === null ? null : max(0, $limite - count($idsSecoes));
        $idsDocumentos = $sobra === 0 ? [] : $this->documentos->idsNaLixeiraVencida($corte, $sobra);

        $secoesRemovidas     = 0;
        $documentosRemovidos = 0;
        $arquivosRemovidos   = 0;
        $naoRemovidos        = [];

        // Seções e documentos já cobertos pela árvore de uma seção purgada nesta execução: uma
        // filha (ou um documento de dentro) que também está na fila não pode contar nem ser
        // coletado duas vezes. Vale para os dois modos — no real o ancestral já os levou pelo
        // cascade; na simulação continuam no banco e seriam achados de novo.
        /** @var array<int, true> $cobertas */
        $cobertas = [];
        /** @var array<int, true> $documentosCobertos */
        $documentosCobertos = [];

        foreach (array_chunk($idsSecoes, self::LOTE) as $lote) {
            /** @var list<ChaveDeArquivo> $chaves */
            $chaves = [];
            foreach ($lote as $id) {
                if (isset($cobertas[$id])) {
                    continue;
                }
                $secao = $this->em->find(PastaSecao::class, $id);
                if ($secao === null || !self::vencida($secao->getExcluidoEm(), $corte)) {
                    continue;
                }

                $daArvore = $this->coletarArvore($secao);
                $secoesRemovidas     += 1 + $daArvore['subpastas'];
                $documentosRemovidos += count($daArvore['chaves']);
                array_push($chaves, ...$daArvore['chaves']);
                foreach ($daArvore['ids'] as $coberta) {
                    $cobertas[$coberta] = true;
                }
                foreach ($daArvore['idsDocumentos'] as $coberto) {
                    $documentosCobertos[$coberto] = true;
                }

                if (!$simulacao) {
                    $this->em->remove($secao);
                }
            }

            [$removidos, $falhas] = $this->confirmarEApagar($chaves, $simulacao);
            $arquivosRemovidos += $removidos;
            array_push($naoRemovidos, ...$falhas);
        }

        foreach (array_chunk($idsDocumentos, self::LOTE) as $lote) {
            $chaves = [];
            foreach ($lote as $id) {
                if (isset($documentosCobertos[$id])) {
                    continue;
                }
                $documento = $this->em->find(PastaDocumento::class, $id);
                if ($documento === null || !self::vencida($documento->getExcluidoEm(), $corte)) {
                    continue;
                }

                $chaves[] = ChavesDePasta::documento($documento);
                ++$documentosRemovidos;

                if (!$simulacao) {
                    $this->em->remove($documento);
                }
            }

            [$removidos, $falhas] = $this->confirmarEApagar($chaves, $simulacao);
            $arquivosRemovidos += $removidos;
            array_push($naoRemovidos, ...$falhas);
        }

        return new ResultadoPurgaDaLixeira(
            simulacao: $simulacao,
            corte: $corte,
            documentosCandidatos: $candidatosDocumentos,
            secoesCandidatas: $candidatosSecoes,
            documentosRemovidos: $documentosRemovidos,
            secoesRemovidas: $secoesRemovidas,
            arquivosRemovidos: $arquivosRemovidos,
            arquivosNaoRemovidos: $naoRemovidos,
        );
    }

    /**
     * A ordem INV-6, num lugar só: o banco decide (`flush` = COMMIT, sem transação por fora); só
     * com ele confirmado os arquivos saem — e uma falha física aí vira registro, nunca exceção. Um
     * `flush` recusado sobe antes de qualquer `excluir()`, com os arquivos intactos. O `clear()`
     * vem depois para o próximo lote não acordar a árvore que acabou de sair.
     *
     * @param list<ChaveDeArquivo> $chaves
     *
     * @return array{int, list<string>}
     */
    private function confirmarEApagar(array $chaves, bool $simulacao): array
    {
        if ($simulacao) {
            $this->em->clear();

            return [0, []];
        }

        $this->em->flush();

        $resultado = $this->remocao->remover($chaves, 'PurgarLixeiraUseCase');
        $this->em->clear();

        return [$resultado->removidos, $resultado->naoRemovidas];
    }

    /**
     * As chaves de todos os documentos de $secao e da descendência, quantas subpastas DESCENDENTES
     * ela tem, os ids de todas as seções visitadas (a própria inclusive) e os ids dos documentos.
     * Lido com o filtro desligado: pega o que está na lixeira E o que, por inconsistência, ainda
     * estiver vivo lá dentro — o cascade do banco levaria os dois.
     *
     * @return array{chaves: list<ChaveDeArquivo>, subpastas: int, ids: list<int>, idsDocumentos: list<int>}
     */
    private function coletarArvore(PastaSecao $secao, int $profundidade = 0): array
    {
        if ($profundidade >= PastaSecao::LIMITE_SEGURANCA) {
            return ['chaves' => [], 'subpastas' => 0, 'ids' => [], 'idsDocumentos' => []];
        }

        $chaves        = [];
        $subpastas     = 0;
        $ids           = [(int) $secao->getId()];
        $idsDocumentos = [];

        foreach ($secao->getDocumentos() as $documento) {
            $chaves[]        = ChavesDePasta::documento($documento);
            $idsDocumentos[] = (int) $documento->getId();
        }

        foreach ($secao->getFilhas() as $filha) {
            $daFilha    = $this->coletarArvore($filha, $profundidade + 1);
            $subpastas += 1 + $daFilha['subpastas'];
            array_push($chaves, ...$daFilha['chaves']);
            array_push($ids, ...$daFilha['ids']);
            array_push($idsDocumentos, ...$daFilha['idsDocumentos']);
        }

        return ['chaves' => $chaves, 'subpastas' => $subpastas, 'ids' => $ids, 'idsDocumentos' => $idsDocumentos];
    }

    private static function vencida(?\DateTimeImmutable $excluidoEm, \DateTimeImmutable $corte): bool
    {
        return $excluidoEm !== null && $excluidoEm < $corte;
    }
}
