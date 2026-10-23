<?php

declare(strict_types=1);

namespace App\Comercial\DTO;

use App\Comercial\Exception\CampanhaRecusada;
use App\Comercial\Repository\CampanhaMarcaRepository;

/**
 * O pedido das marcas do Relatório (spec `comercial-marcas.md`, regra 8): `{ids: [..], acao}` com até 50 ids e a ação
 * de uma lista fechada (estrela, sem_estrela, arquivar, desarquivar, remover). O id que não é um número inteiro
 * positivo fica de fora; o que não existe é ignorado na gravação. A recusa vai à tela como está (422).
 */
final class PedidoDeMarcas
{
    public const MAXIMO = 50;
    public const ACAO_INVALIDA = 'Ação inválida.';
    public const SEM_CAMPANHA = 'Selecione ao menos uma campanha.';
    public const DEMAIS = 'Selecione até 50 campanhas.';
    /** O maior id de uma coluna INT do PostgreSQL. */
    private const ID_MAXIMO = 2147483647;

    /** @param list<int> $ids */
    private function __construct(
        public readonly array $ids,
        public readonly string $acao,
    ) {
    }

    /** @param array<mixed> $json o corpo do POST */
    public static function deJson(array $json): self
    {
        $acao = $json['acao'] ?? null;
        if (!\is_string($acao) || !\in_array($acao, CampanhaMarcaRepository::ACOES, true)) {
            throw new CampanhaRecusada(self::ACAO_INVALIDA);
        }
        $brutos = \is_array($json['ids'] ?? null) ? $json['ids'] : [];
        if (\count($brutos) > self::MAXIMO) {
            throw new CampanhaRecusada(self::DEMAIS);
        }
        $ids = self::ids($brutos);
        if ($ids === []) {
            throw new CampanhaRecusada(self::SEM_CAMPANHA);
        }

        return new self($ids, $acao);
    }

    /**
     * @param array<mixed> $brutos
     *
     * @return list<int>
     */
    private static function ids(array $brutos): array
    {
        $ids = [];
        foreach ($brutos as $id) {
            $id = \is_string($id) && preg_match('/^[1-9]\d{0,9}$/', $id) === 1 ? (int) $id : $id;
            if (\is_int($id) && $id > 0 && $id <= self::ID_MAXIMO) {
                $ids[$id] = $id;
            }
        }

        return array_values($ids);
    }
}
