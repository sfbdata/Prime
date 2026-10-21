<?php

declare(strict_types=1);

namespace App\Pasta\Service;

use App\Pasta\Entity\Pasta;
use App\Pasta\Repository\PastaChecklistCobrancaRepository;
use Symfony\Contracts\Service\ResetInterface;

/**
 * O que a tela mostra da cobrança do checklist (desenho 02): o selo "cobrado Nx" de cada item pendente, com
 * a dica das datas (dc 6745-6749), e a contagem para a linha de pendências da aba ("N documentos cobrados e
 * ainda não anexados", dc 5496). Com o checklist desativado não há nada: a cobrança está pausada.
 */
final class CobrancasDoChecklistDaPasta implements ResetInterface
{
    /** @var array<int, array<int, list<string>>> pasta => itens cobrados (a página pede duas vezes: selo e pendência) */
    private array $memoria = [];

    /** A partir de 3 cobranças o selo fica vermelho (dc 6749). */
    private const VERMELHO_A_PARTIR_DE = 3;

    public function __construct(
        private readonly PastaChecklistCobrancaRepository $cobrancas,
    ) {
    }

    /** @return array<int, array{texto: string, dica: string, vermelho: bool}> id do item => selo, só dos pendentes cobrados */
    public function selos(Pasta $pasta): array
    {
        $selos = [];
        foreach ($this->pendentesCobrados($pasta) as $itemId => $envios) {
            $datas = array_map(static fn (string $d): string => (new \DateTimeImmutable($d))->format('d/m'), $envios);
            $selos[$itemId] = [
                'texto' => 'cobrado ' . \count($envios) . 'x',
                'dica' => 'Cobranças enviadas ao responsável: ' . implode(', ', $datas),
                'vermelho' => \count($envios) >= self::VERMELHO_A_PARTIR_DE,
            ];
        }

        return $selos;
    }

    public function cobradosPendentes(Pasta $pasta): int
    {
        return \count($this->pendentesCobrados($pasta));
    }

    public function reset(): void
    {
        $this->memoria = [];
    }

    /** @return array<int, list<string>> */
    private function pendentesCobrados(Pasta $pasta): array
    {
        $tenantId = $pasta->getTenant()?->getId();
        if ($tenantId === null || $pasta->getId() === null || !$pasta->isChecklistAtivo()) {
            return [];
        }

        return $this->memoria[(int) $pasta->getId()] ??= $this->lerPendentesCobrados($tenantId, (int) $pasta->getId());
    }

    /** @return array<int, list<string>> */
    private function lerPendentesCobrados(int $tenantId, int $pastaId): array
    {
        $porItem = [];
        foreach ($this->cobrancas->daPasta($tenantId, $pastaId) as $itemId => $registro) {
            if (!$registro->getItem()->isConcluido() && $registro->getEnvios() !== []) {
                $porItem[$itemId] = $registro->getEnvios();
            }
        }

        return $porItem;
    }
}
