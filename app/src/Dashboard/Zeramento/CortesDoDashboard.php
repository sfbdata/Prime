<?php

declare(strict_types=1);

namespace App\Dashboard\Zeramento;

use App\Dashboard\Entity\DashboardZeramento;

/**
 * Os pontos de corte ativos do escritório (DASH-014), lidos dos zeramentos que não foram desfeitos.
 *
 * O corte de uma coluna para uma pessoa é o MAIS RECENTE entre o "para todos" e o dela (o `dzCorte`
 * do desenho: `Math.max(z.todos, z.porId[id])`). A contagem passa a começar em
 * max(início do período, corte): os repositórios aplicam as duas condições juntas (`contar_desde`).
 */
final class CortesDoDashboard
{
    /**
     * @param array<string, array{todos: ?\DateTimeImmutable, pessoas: array<int, \DateTimeImmutable>}> $porColuna
     */
    private function __construct(private readonly array $porColuna)
    {
    }

    public static function nenhum(): self
    {
        return new self([]);
    }

    /** @param iterable<DashboardZeramento> $zeramentos */
    public static function deZeramentos(iterable $zeramentos): self
    {
        $porColuna = [];
        foreach ($zeramentos as $z) {
            if (!$z->estaAtivo()) {
                continue;
            }
            $coluna = $z->getColuna();
            $atual  = $porColuna[$coluna] ?? ['todos' => null, 'pessoas' => []];
            $porColuna[$coluna] = self::comCorte($atual, $z);
        }

        return new self($porColuna);
    }

    public function corte(string $coluna, int $pessoa): ?\DateTimeImmutable
    {
        $c = $this->porColuna[$coluna] ?? null;
        if ($c === null) {
            return null;
        }

        return self::maisRecente($c['todos'], $c['pessoas'][$pessoa] ?? null);
    }

    public function temCorte(string $coluna): bool
    {
        return isset($this->porColuna[$coluna]);
    }

    /**
     * As pessoas com corte nesta coluna, agrupadas pelo mesmo instante (uma consulta por grupo).
     *
     * @param list<int> $pessoas
     *
     * @return list<array{desde: \DateTimeImmutable, pessoas: list<int>}>
     */
    public function grupos(string $coluna, array $pessoas): array
    {
        $grupos = [];
        foreach ($pessoas as $id) {
            $desde = $this->corte($coluna, $id);
            if ($desde === null) {
                continue;
            }
            $chave = $desde->format('Y-m-d H:i:s');
            $grupos[$chave] ??= ['desde' => $desde, 'pessoas' => []];
            $grupos[$chave]['pessoas'][] = $id;
        }

        return array_values($grupos);
    }

    /**
     * As colunas em que a pessoa tem corte (para a tela tirar o link e a seta dessas células).
     *
     * @return list<string>
     */
    public function colunasDe(int $pessoa): array
    {
        return array_values(array_filter(
            array_keys($this->porColuna),
            fn (string $coluna): bool => $this->corte($coluna, $pessoa) !== null,
        ));
    }

    /** @return list<string> */
    public function colunas(): array
    {
        return array_keys($this->porColuna);
    }

    /**
     * O que o menu da coluna precisa para o "Contando desde" e o "Desfazer zeramentos".
     *
     * @return array<string, array{todos: ?string, pessoas: array<int, string>}> datas em ISO 8601
     */
    public function paraTela(): array
    {
        $tela = [];
        foreach ($this->porColuna as $coluna => $c) {
            $tela[$coluna] = [
                'todos'   => $c['todos']?->format(\DateTimeInterface::ATOM),
                'pessoas' => array_map(static fn (\DateTimeImmutable $d): string => $d->format(\DateTimeInterface::ATOM), $c['pessoas']),
            ];
        }

        return $tela;
    }

    /**
     * @param array{todos: ?\DateTimeImmutable, pessoas: array<int, \DateTimeImmutable>} $atual
     *
     * @return array{todos: ?\DateTimeImmutable, pessoas: array<int, \DateTimeImmutable>}
     */
    private static function comCorte(array $atual, DashboardZeramento $z): array
    {
        $desde = $z->getContarDesde();
        if ($z->valeParaTodos()) {
            $atual['todos'] = self::maisRecente($atual['todos'], $desde);

            return $atual;
        }
        $id = (int) $z->getUsuario()?->getId();
        $atual['pessoas'][$id] = self::maisRecente($atual['pessoas'][$id] ?? null, $desde);

        return $atual;
    }

    private static function maisRecente(?\DateTimeImmutable $a, ?\DateTimeImmutable $b): ?\DateTimeImmutable
    {
        if ($a === null || $b === null) {
            return $a ?? $b;
        }

        return $a > $b ? $a : $b;
    }
}
