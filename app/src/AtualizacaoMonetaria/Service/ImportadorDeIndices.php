<?php

declare(strict_types=1);

namespace App\AtualizacaoMonetaria\Service;

use App\AtualizacaoMonetaria\Entity\IndiceMonetario;
use App\AtualizacaoMonetaria\Enum\SerieIndice;
use App\AtualizacaoMonetaria\Exception\ImportacaoIndicesException;
use App\AtualizacaoMonetaria\Repository\IndiceMonetarioRepository;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;

/**
 * Grava no banco o que o BCB devolveu para uma série: o que é novo entra, o que mudou é sobrescrito
 * e REGISTRADO, o que é igual não é tocado (idempotente).
 *
 * Serve ao comando de cron (`app:importar-indices-monetarios`) e ao botão "Atualizar índices" da tela
 * de Cálculo. `atualizar()` é o caminho da tela: as séries do cálculo, com o mesmo lock do comando,
 * e devolve só o que a tela precisa dizer ao usuário.
 */
final class ImportadorDeIndices
{
    /** Mesmo arquivo de lock do comando: cron e botão nunca importam ao mesmo tempo. */
    public const ARQUIVO_LOCK = 'jusprime-importar-indices-monetarios.lock';

    /** Séries que o cálculo de atualização monetária lê. */
    public const SERIES_DO_CALCULO = [SerieIndice::INPC, SerieIndice::IPCA, SerieIndice::SELIC];

    public function __construct(
        private readonly ClienteSgsBcbInterface $cliente,
        private readonly IndiceMonetarioRepository $repositorio,
        private readonly EntityManagerInterface $em,
        private readonly LoggerInterface $logger,
    ) {
    }

    /**
     * Atualiza as séries do cálculo. Série que falha ou volta vazia não leva as demais junto.
     *
     * @return array{ok: bool, ocupado: bool, falhas: list<string>}
     */
    public function atualizar(): array
    {
        $handle = fopen(sys_get_temp_dir() . '/' . self::ARQUIVO_LOCK, 'c');
        if ($handle === false || flock($handle, \LOCK_EX | \LOCK_NB) === false) {
            if (\is_resource($handle)) {
                fclose($handle);
            }

            return ['ok' => false, 'ocupado' => true, 'falhas' => []];
        }

        try {
            $agora = new \DateTimeImmutable();
            $falhas = [];
            foreach (self::SERIES_DO_CALCULO as $serie) {
                try {
                    $variacoes = $this->cliente->baixarSerie($serie);
                    if ($variacoes === []) {
                        $falhas[] = $serie->value;
                        $this->logger->error('Série de índices voltou vazia do BCB.', ['serie' => $serie->value]);

                        continue;
                    }
                    $this->importarSerie($serie, $variacoes, $agora, false);
                    $this->em->flush();
                } catch (ImportacaoIndicesException | \InvalidArgumentException $e) {
                    $falhas[] = $serie->value;
                    $this->em->clear();
                    $this->logger->error('Falha ao importar série de índices.', ['serie' => $serie->value, 'erro' => $e->getMessage()]);
                }
            }

            return ['ok' => $falhas === [], 'ocupado' => false, 'falhas' => $falhas];
        } finally {
            flock($handle, \LOCK_UN);
            fclose($handle);
        }
    }

    /**
     * @param array<string, string> $variacoes competência 'Y-m-01' => variação
     *
     * @return array{novos: int, revisados: int, inalterados: int, primeira: string, ultima: string,
     *               revisoes: list<array{string, string, string, string}>}
     */
    public function importarSerie(
        SerieIndice $serie,
        array $variacoes,
        \DateTimeImmutable $agora,
        bool $dryRun,
    ): array {
        // Uma consulta por série (não uma por mês): são ~570 competências no INPC.
        $existentes = $this->repositorio->mapaPorCompetencia($serie);

        $novos = 0;
        $revisados = 0;
        $inalterados = 0;
        $revisoes = [];

        foreach ($variacoes as $chave => $variacao) {
            $existente = $existentes[$chave] ?? null;

            if ($existente === null) {
                ++$novos;

                if ($dryRun === false) {
                    $this->repositorio->salvar(new IndiceMonetario(
                        $serie,
                        new \DateTimeImmutable($chave),
                        $variacao,
                        $serie->fonte(),
                        $agora,
                    ));
                }

                continue;
            }

            if ($existente->variacaoDivergeDe($variacao) === false) {
                ++$inalterados;

                continue;
            }

            ++$revisados;
            $anterior = $existente->getVariacaoPct();
            $novo = VariacaoPercentual::canonizar($variacao);
            $competencia = substr($chave, 0, 7);

            $revisoes[] = [$serie->value, $competencia, $anterior, $novo];
            $this->logger->warning('Índice monetário revisado pela fonte oficial.', [
                'serie' => $serie->value,
                'competencia' => $competencia,
                'anterior' => $anterior,
                'novo' => $novo,
            ]);

            if ($dryRun === false) {
                $existente->atualizarVariacao($variacao, $agora);
            }
        }

        $competencias = array_keys($variacoes);

        return [
            'novos' => $novos,
            'revisados' => $revisados,
            'inalterados' => $inalterados,
            'revisoes' => $revisoes,
            'primeira' => substr((string) reset($competencias), 0, 7),
            'ultima' => substr((string) end($competencias), 0, 7),
        ];
    }
}
