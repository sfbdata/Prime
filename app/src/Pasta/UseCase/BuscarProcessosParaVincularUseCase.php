<?php

declare(strict_types=1);

namespace App\Pasta\UseCase;

use App\Entity\Tenant\Tenant;
use App\Pasta\DTO\ProcessoParaVincularOutput;
use App\Pasta\Entity\Pasta;
use App\Pasta\Entity\PastaProcesso;
use App\Pasta\Exception\PastaDeOutroEscritorioException;
use App\Pasta\Repository\PastaRepository;
use App\Processo\Entity\Processo;
use App\Processo\Repository\ProcessoRepository;

/**
 * A lista "Processo já cadastrado" da janela "Vincular processo" (desenho 02, N:299-302 e `pvVals.cat`):
 * abre ao focar com os 8 primeiros processos do escritório, mesmo sem termo, e filtra enquanto se digita
 * (número, classe, assunto ou tribunal). Os processos já vinculados a ESTA pasta ficam de fora.
 *
 * Cada item leva a origem do processo no escritório, como `procCatalogo` (N:4513-4518): "Pasta N" quando
 * ele é o processo de uma pasta, "Apensado · pasta N" quando só aparece como apensado, "Push processual"
 * quando não está em pasta nenhuma (o Push monitora todo processo do escritório).
 *
 * Guarda: a pasta é do escritório da sessão, senão {@see PastaDeOutroEscritorioException}. A busca e os
 * vínculos filtram pelo tenant; quem chama já conferiu a permissão de VER a pasta.
 */
final class BuscarProcessosParaVincularUseCase
{
    public const LIMITE = 8;

    public function __construct(
        private readonly ProcessoRepository $processoRepository,
        private readonly PastaRepository $pastaRepository,
    ) {
    }

    /** @return list<ProcessoParaVincularOutput> */
    public function executar(Pasta $pasta, string $termo, Tenant $tenant): array
    {
        if ($pasta->getTenant() !== $tenant) {
            throw new PastaDeOutroEscritorioException('Pasta não encontrada.');
        }

        $idsVinculados = array_values(array_filter(array_map(
            static fn (Processo $p): ?int => $p->getId(),
            $pasta->getProcessos(),
        )));

        $processos = array_values($this->processoRepository->buscarPorTermoDoTenant(trim($termo), $tenant, $idsVinculados, self::LIMITE));
        $ids = array_values(array_filter(array_map(static fn (Processo $p): ?int => $p->getId(), $processos)));
        $origens = $this->origens($this->pastaRepository->vinculosDosProcessos($ids, $tenant));

        return array_map(static function (Processo $processo) use ($origens): ProcessoParaVincularOutput {
            [$origem, $nup] = $origens[(int) $processo->getId()] ?? [ProcessoParaVincularOutput::ORIGEM_PUSH, null];

            return ProcessoParaVincularOutput::de($processo, $origem, $nup);
        }, $processos);
    }

    /**
     * Por processo, a primeira pasta em que ele não é apensado; não havendo, a primeira em que é.
     *
     * @param list<PastaProcesso> $vinculos
     *
     * @return array<int, array{0: string, 1: string}>
     */
    private function origens(array $vinculos): array
    {
        $origens = [];
        foreach ($vinculos as $vinculo) {
            $id = (int) $vinculo->getProcesso()->getId();
            $apensado = !$vinculo->isPrincipal() && $vinculo->getTipoApenso() !== null;
            $atual = $origens[$id][0] ?? null;
            if ($atual === ProcessoParaVincularOutput::ORIGEM_PASTA || ($atual !== null && $apensado)) {
                continue;
            }
            $origens[$id] = [
                $apensado ? ProcessoParaVincularOutput::ORIGEM_APENSADO : ProcessoParaVincularOutput::ORIGEM_PASTA,
                self::numeroDaPasta($vinculo->getPasta()),
            ];
        }

        return $origens;
    }

    private static function numeroDaPasta(Pasta $pasta): string
    {
        $nup = trim((string) $pasta->getNup());

        return $nup !== '' ? $nup : '#' . $pasta->getId();
    }
}
