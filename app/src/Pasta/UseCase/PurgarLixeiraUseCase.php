<?php

declare(strict_types=1);

namespace App\Pasta\UseCase;

use App\Pasta\Armazenamento\ChavesDePasta;
use App\Pasta\Armazenamento\ReferenciasDeArquivoDaPasta;
use App\Pasta\Entity\PastaDocumento;
use App\Pasta\Entity\PastaSecao;
use App\Pasta\Repository\PastaDocumentoRepository;
use App\Pasta\Repository\PastaSecaoRepository;
use App\Shared\Armazenamento\ChaveDeArquivo;
use App\Shared\Armazenamento\Exception\ChaveDeArquivoInvalida;
use App\Shared\Armazenamento\RemocaoAposTransacao;
use App\Shared\Doctrine\Filter\AcessoALixeira;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Clock\ClockInterface;
use Psr\Log\LoggerInterface;

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
 *  - `--limite` conta ENTRADAS DA FILA EFETIVAMENTE PURGADAS (seções e documentos vencidos que
 *    saíram, ou sairiam na simulação; não a descendência). A fila inteira é lida (só ids, barato)
 *    e o limite vale sobre o que foi processado: item PULADO (caminho inválido, seção impedida,
 *    entrada sumida ou restaurada) não ocupa vaga — senão N itens legados no topo da fila
 *    prenderiam toda execução com `--limite=N` sem nunca avançar (SEC-007b). Rodar de novo
 *    retoma de onde parou, porque a fila é "quem ainda está vencido" — idempotente;
 *  - uma entrada que sumiu entre a listagem e a carga (caiu pelo cascade de um ancestral neste
 *    mesmo laço, ou foi purgada por outra execução) é pulada; uma que foi RESTAURADA no meio é
 *    pulada também — reconferida pelo carimbo antes do `remove()`.
 *
 * Linha legada com `caminho_arquivo` que não vira chave (SEC-007: vazio, com `/`, `\`, `..`,
 * controle, ou sem escritório): o documento é PULADO — nem linha, nem arquivo saem — e um
 * `warning` registra só ids e o TIPO do motivo — nunca trecho do caminho, que pode ter nome de
 * cliente. Os pulados entram na contagem do resultado. A seção que tenha um desses na árvore NÃO é removida, porque o
 * cascade levaria a linha legada e deixaria o arquivo sem rastro; os documentos válidos e vencidos
 * de dentro dela ainda saem pela fila de documentos, um a um, como qualquer outro. Antes, a exceção subia, o comando
 * parava, e parava de novo em toda execução seguinte no mesmo registro. Remover a linha "para
 * destravar" seria agir justamente quando a premissa (a chave) caiu: recusar é o seguro.
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
        private readonly ReferenciasDeArquivoDaPasta $referencias,
        private readonly ClockInterface $clock,
        private readonly LoggerInterface $logger,
    ) {
    }

    /**
     * @param int      $dias      retenção: só sai o que foi excluído há MAIS de $dias
     * @param bool     $simulacao `--dry-run`: conta e lista, não apaga nada
     * @param int|null $limite    máximo de entradas da fila nesta execução; null = todas
     * @param int|null $tenantId  só a lixeira deste escritório; null = a instalação inteira (a
     *                            retenção é política da instalação, não do escritório)
     */
    public function executar(int $dias, bool $simulacao, ?int $limite = null, ?int $tenantId = null): ResultadoPurgaDaLixeira
    {
        if ($dias < 1) {
            throw new \InvalidArgumentException('A retenção precisa ser de pelo menos 1 dia.');
        }
        if ($limite !== null && $limite < 1) {
            throw new \InvalidArgumentException('O limite precisa ser um inteiro positivo.');
        }
        if ($tenantId !== null && $tenantId < 1) {
            throw new \InvalidArgumentException('O id do escritório precisa ser um inteiro positivo.');
        }

        $corte = $this->clock->now()->modify(sprintf('-%d days', $dias));

        return $this->lixeira->comLixeiraVisivel(fn (): ResultadoPurgaDaLixeira => $this->purgar($corte, $simulacao, $limite, $tenantId));
    }

    private function purgar(\DateTimeImmutable $corte, bool $simulacao, ?int $limite, ?int $tenantId): ResultadoPurgaDaLixeira
    {
        $candidatosSecoes     = $this->secoes->contarNaLixeiraVencida($corte, $tenantId);
        $candidatosDocumentos = $this->documentos->contarNaLixeiraVencida($corte, $tenantId);

        // A fila INTEIRA (só ids): o `--limite` é aplicado ao que for purgado, não à leitura —
        // senão os pulados ocupariam as vagas (SEC-007b).
        $idsSecoes     = $this->secoes->idsNaLixeiraVencida($corte, null, $tenantId);
        $idsDocumentos = $this->documentos->idsNaLixeiraVencida($corte, null, $tenantId);

        /** Vagas que sobram nesta execução; null = sem limite. */
        $vagas = $limite;

        // Quem está na fila desta execução: uma seção cujo ancestral também está na fila é
        // coberta pela árvore dele (antes ou depois dela na ordem), e não entra por conta própria
        // — senão uma filha de id menor que a mãe seria contada duas vezes na simulação.
        /** @var array<int, true> $naFila */
        $naFila = array_fill_keys($idsSecoes, true);

        $secoesRemovidas     = 0;
        $documentosRemovidos = 0;
        $arquivosRemovidos   = 0;
        $naoRemovidos        = [];
        $secoesPuladas       = 0;
        $documentosPulados   = 0;

        // Seções e documentos já cobertos pela árvore de uma seção purgada nesta execução: uma
        // filha (ou um documento de dentro) que também está na fila não pode contar nem ser
        // coletado duas vezes. Vale para os dois modos — no real o ancestral já os levou pelo
        // cascade; na simulação continuam no banco e seriam achados de novo.
        /** @var array<int, true> $cobertas */
        $cobertas = [];
        /** @var array<int, true> $documentosCobertos */
        $documentosCobertos = [];

        foreach (array_chunk($idsSecoes, self::LOTE) as $lote) {
            if ($vagas === 0) {
                break;
            }
            /** @var list<ChaveDeArquivo> $chaves */
            $chaves = [];
            foreach ($lote as $id) {
                if ($vagas === 0) {
                    break;
                }
                if (isset($cobertas[$id])) {
                    continue;
                }
                $secao = $this->em->find(PastaSecao::class, $id);
                if ($secao === null || !self::vencida($secao->getExcluidoEm(), $corte)) {
                    continue;
                }
                if ($this->temAncestralNaFila($secao, $naFila)) {
                    continue;
                }

                $daArvore = $this->coletarArvore($secao);
                if ($daArvore['impedida']) {
                    ++$secoesPuladas;
                    $this->registrarPulo('Subpasta não purgada: há documento na árvore com caminho que não vira chave de armazenamento.', [
                        'secao_id' => (int) $secao->getId(),
                        'motivo'   => 'documento_na_arvore_sem_chave',
                    ]);

                    continue;
                }
                if ($vagas !== null) {
                    --$vagas;
                }
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
            if ($vagas === 0) {
                break;
            }
            $chaves = [];
            foreach ($lote as $id) {
                if ($vagas === 0) {
                    break;
                }
                if (isset($documentosCobertos[$id])) {
                    continue;
                }
                $documento = $this->em->find(PastaDocumento::class, $id);
                if ($documento === null || !self::vencida($documento->getExcluidoEm(), $corte)) {
                    continue;
                }

                $chave = $this->chaveOuNada($documento);
                if ($chave === null) {
                    ++$documentosPulados;

                    continue;
                }

                $chaves[] = $chave;
                ++$documentosRemovidos;
                if ($vagas !== null) {
                    --$vagas;
                }

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
            secoesPuladas: $secoesPuladas,
            documentosPulados: $documentosPulados,
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

        // Arquivo que outro documento ainda referencia fica (fatia C): só sai o da última referência.
        $livres = $this->referencias->semReferencia($chaves);
        $this->referencias->descartarRegistros($livres);
        $resultado = $this->remocao->remover($livres, 'PurgarLixeiraUseCase');
        $this->em->clear();

        return [$resultado->removidos, $resultado->naoRemovidas];
    }

    /**
     * As chaves de todos os documentos de $secao e da descendência, quantas subpastas DESCENDENTES
     * ela tem, os ids de todas as seções visitadas (a própria inclusive) e os ids dos documentos.
     * Lido com o filtro desligado: pega o que está na lixeira E o que, por inconsistência, ainda
     * estiver vivo lá dentro — o cascade do banco levaria os dois.
     *
     * `impedida`: algum documento da árvore não vira chave — quem chama não remove a seção.
     *
     * @return array{chaves: list<ChaveDeArquivo>, subpastas: int, ids: list<int>, idsDocumentos: list<int>, impedida: bool}
     */
    private function coletarArvore(PastaSecao $secao, int $profundidade = 0): array
    {
        if ($profundidade >= PastaSecao::LIMITE_SEGURANCA) {
            return ['chaves' => [], 'subpastas' => 0, 'ids' => [], 'idsDocumentos' => [], 'impedida' => false];
        }

        $chaves        = [];
        $subpastas     = 0;
        $ids           = [(int) $secao->getId()];
        $idsDocumentos = [];
        $impedida      = false;

        foreach ($secao->getDocumentos() as $documento) {
            $chave = $this->chaveOuNada($documento);
            if ($chave === null) {
                $impedida = true;

                continue;
            }
            $chaves[]        = $chave;
            $idsDocumentos[] = (int) $documento->getId();
        }

        foreach ($secao->getFilhas() as $filha) {
            $daFilha    = $this->coletarArvore($filha, $profundidade + 1);
            $subpastas += 1 + $daFilha['subpastas'];
            $impedida   = $impedida || $daFilha['impedida'];
            array_push($chaves, ...$daFilha['chaves']);
            array_push($ids, ...$daFilha['ids']);
            array_push($idsDocumentos, ...$daFilha['idsDocumentos']);
        }

        return ['chaves' => $chaves, 'subpastas' => $subpastas, 'ids' => $ids, 'idsDocumentos' => $idsDocumentos, 'impedida' => $impedida];
    }

    /**
     * A chave do documento, ou null quando o `caminho_arquivo` gravado não vira chave (linha
     * legada). Null quer dizer "não toque": quem chama pula o item, nunca apaga às cegas.
     */
    private function chaveOuNada(PastaDocumento $documento): ?ChaveDeArquivo
    {
        try {
            return ChavesDePasta::documento($documento);
        } catch (ChaveDeArquivoInvalida) {
            // Só ids e o TIPO do motivo: a mensagem da exceção traz trecho do caminho, que pode
            // ter nome de cliente (SEC-007b).
            $this->registrarPulo('Documento não purgado: o caminho gravado não vira chave de armazenamento.', [
                'documento_id' => (int) $documento->getId(),
                'pasta_id'     => $documento->getPasta()?->getId(),
                'motivo'       => self::tipoDaRecusa($documento),
            ]);

            return null;
        }
    }

    /**
     * Como no `RemocaoAposTransacao`: o registro nunca muda o desfecho — um logger que lança não
     * pode derrubar a purga que acabou de decidir NÃO apagar.
     *
     * @param array<string, mixed> $contexto
     */
    private function registrarPulo(string $mensagem, array $contexto): void
    {
        try {
            $this->logger->warning($mensagem, ['contexto' => 'PurgarLixeiraUseCase'] + $contexto);
        } catch (\Throwable) {
            // Sem onde registrar: o item continua na lixeira, que é o rastro.
        }
    }

    /** O tipo da recusa da chave, sem nada do conteúdo do caminho. */
    private static function tipoDaRecusa(PastaDocumento $documento): string
    {
        if ($documento->getTenant() === null) {
            return 'sem_escritorio';
        }

        return trim((string) $documento->getCaminhoArquivo()) === '' ? 'caminho_vazio' : 'caminho_invalido';
    }

    private static function vencida(?\DateTimeImmutable $excluidoEm, \DateTimeImmutable $corte): bool
    {
        return $excluidoEm !== null && $excluidoEm < $corte;
    }

    /**
     * Algum ancestral de $secao está na fila desta execução? Sobe por `getPai()` com o filtro
     * desligado (os pais na lixeira carregam normalmente), com a trava anti-ciclo da entidade.
     *
     * @param array<int, true> $naFila
     */
    private function temAncestralNaFila(PastaSecao $secao, array $naFila): bool
    {
        $passos = 0;
        $atual  = $secao->getPai();
        while ($atual !== null && $passos < PastaSecao::LIMITE_SEGURANCA) {
            if (isset($naFila[(int) $atual->getId()])) {
                return true;
            }
            $atual = $atual->getPai();
            ++$passos;
        }

        return false;
    }
}
