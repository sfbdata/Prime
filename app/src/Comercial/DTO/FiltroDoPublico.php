<?php

declare(strict_types=1);

namespace App\Comercial\DTO;

use App\Comercial\Exception\CampanhaRecusada;
use App\Master\Service\LocalizacaoDaPessoa;

/**
 * "Filtrar destinatários" da campanha (desenho 05 VS 21.10.26, L260-264 e L634; spec comercial-e-emails.md §9). Cada
 * campo é um select de valor único e vazio quer dizer "todos"; os campos combinam com E. A busca livre separa os
 * termos por espaço, vírgula ou ponto e vírgula, e basta um termo aparecer no e-mail ou no nome (OU entre termos).
 * O formato de troca com a tela (e o que fica congelado na campanha) usa as chaves do dc: q, gen, id, uf, papel,
 * cargo, oab, plano e aniv.
 */
final class FiltroDoPublico
{
    public const OPCOES = [
        'gen' => ['F', 'M'],
        'id' => ['a', 'b', 'c', 'd', 'e'],
        'papel' => ['prim', 'sec'],
        'cargo' => ['adv', 'est', 'ctl', 'fin', 'adm', 'out'],
        'oab' => ['conf', 'pend', 'sem'],
        'aniv' => ['mes', 'prox'],
    ];

    /** As faixas de idade do dc (`FX`, L634): [de, até], em anos completos. */
    public const FAIXAS_DE_IDADE = ['a' => [18, 24], 'b' => [25, 34], 'c' => [35, 44], 'd' => [45, 54], 'e' => [55, 200]];

    private const TAMANHO_DA_BUSCA = 500;

    public function __construct(
        public readonly ?string $busca = null,
        public readonly ?string $genero = null,
        public readonly ?string $idade = null,
        public readonly ?string $uf = null,
        public readonly ?string $papel = null,
        public readonly ?string $cargo = null,
        public readonly ?string $oab = null,
        public readonly ?string $plano = null,
        public readonly ?string $aniversario = null,
    ) {
    }

    /**
     * Do pedido da tela ou da campanha congelada. Valor fora das opções do dc é recusado.
     *
     * @param array<mixed> $dados
     */
    public static function deArray(array $dados): self
    {
        $busca = self::texto($dados['q'] ?? null);
        if ($busca !== null && mb_strlen($busca) > self::TAMANHO_DA_BUSCA) {
            throw new CampanhaRecusada('Busca do filtro grande demais.');
        }
        $uf = self::texto($dados['uf'] ?? null);
        if ($uf !== null && LocalizacaoDaPessoa::uf($uf) === null) {
            throw new CampanhaRecusada('Filtro inválido.');
        }
        $plano = self::texto($dados['plano'] ?? null);
        if ($plano !== null && preg_match('/^[a-z0-9_]{1,30}$/', $plano) !== 1) {
            throw new CampanhaRecusada('Filtro inválido.');
        }

        return new self(
            $busca,
            self::opcao($dados, 'gen'),
            self::opcao($dados, 'id'),
            $uf === null ? null : LocalizacaoDaPessoa::uf($uf),
            self::opcao($dados, 'papel'),
            self::opcao($dados, 'cargo'),
            self::opcao($dados, 'oab'),
            $plano,
            self::opcao($dados, 'aniv'),
        );
    }

    /** @return array<string, string> só os campos preenchidos, nas chaves do dc */
    public function paraArray(): array
    {
        return array_filter([
            'q' => $this->busca, 'gen' => $this->genero, 'id' => $this->idade, 'uf' => $this->uf, 'papel' => $this->papel,
            'cargo' => $this->cargo, 'oab' => $this->oab, 'plano' => $this->plano, 'aniv' => $this->aniversario,
        ], static fn (?string $v): bool => $v !== null);
    }

    public function ativo(): bool
    {
        return $this->paraArray() !== [];
    }

    /** O selo do cabeçalho "Filtrar destinatários" (dc `cmSegNf`): quantos campos estão preenchidos. */
    public function quantos(): int
    {
        return \count($this->paraArray());
    }

    /** @return list<string> os termos da busca, em minúsculas */
    public function termos(): array
    {
        $partes = preg_split('/[\s,;]+/u', mb_strtolower((string) $this->busca)) ?: [];

        return array_values(array_filter($partes, static fn (string $t): bool => $t !== ''));
    }

    private static function texto(mixed $valor): ?string
    {
        if ($valor === null) {
            return null;
        }
        if (!\is_string($valor)) {
            throw new CampanhaRecusada('Filtro inválido.');
        }
        $valor = trim($valor);

        return $valor === '' ? null : $valor;
    }

    /** @param array<mixed> $dados */
    private static function opcao(array $dados, string $chave): ?string
    {
        $valor = self::texto($dados[$chave] ?? null);
        if ($valor !== null && !\in_array($valor, self::OPCOES[$chave], true)) {
            throw new CampanhaRecusada('Filtro inválido.');
        }

        return $valor;
    }
}
