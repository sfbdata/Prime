<?php

declare(strict_types=1);

namespace App\Conector\Service;

use App\Entity\Tenant\Tenant;
use App\Processo\Repository\ProcessoRepository;

/**
 * Tira das listas do conector o que está ligado a pasta em segredo de justiça e conta o que saiu
 * (`docs/specs/mcp-remoto-oauth.md`, 6.2, regra 3: em lista, só a contagem aparece). A regra do segredo é a da
 * entidade (`Processo::emSegredoDeJustica`: sigilo do tribunal ou o "Sim" manual do cartão), pela
 * {@see ProcessoRepository::idsDasPastasEmSegredoDeJustica()}, com o escritório explícito da chamada: não depende do
 * filtro de sessão. Pasta de outro escritório nunca é tida como "em segredo" aqui; quem a tira da lista é a guarda do
 * escritório da própria consulta.
 *
 * Uma consulta por lista, nunca uma por item.
 */
final class SegredoNasListas
{
    public function __construct(
        private readonly ProcessoRepository $processos,
    ) {
    }

    /**
     * Os ids de pasta fora do segredo, na ordem em que vieram, e quantos saíram.
     *
     * @param list<int> $pastaIds
     *
     * @return array{ids: list<int>, em_segredo: int}
     */
    public function tirar(array $pastaIds, Tenant $escritorio): array
    {
        $resultado = $this->tirarDe($pastaIds, static fn (int $id): int => $id, $escritorio);

        return ['ids' => $resultado['itens'], 'em_segredo' => $resultado['em_segredo']];
    }

    /**
     * Os itens cuja pasta não está em segredo, na ordem em que vieram, e quantos itens saíram. Item sem pasta
     * (`$pastaDe` devolve null) fica.
     *
     * @template T
     *
     * @param list<T>            $itens
     * @param \Closure(T): ?int $pastaDe
     *
     * @return array{itens: list<T>, em_segredo: int}
     */
    public function tirarDe(array $itens, \Closure $pastaDe, Tenant $escritorio): array
    {
        $pastaIds = [];
        foreach ($itens as $item) {
            $id = $pastaDe($item);
            if ($id !== null) {
                $pastaIds[$id] = true;
            }
        }
        if ($pastaIds === []) {
            return ['itens' => $itens, 'em_segredo' => 0];
        }

        $emSegredo = array_fill_keys($this->processos->idsDasPastasEmSegredoDeJustica(array_keys($pastaIds), $escritorio), true);
        $ficam = [];
        foreach ($itens as $item) {
            $id = $pastaDe($item);
            if ($id === null || !isset($emSegredo[$id])) {
                $ficam[] = $item;
            }
        }

        return ['itens' => $ficam, 'em_segredo' => \count($itens) - \count($ficam)];
    }
}
