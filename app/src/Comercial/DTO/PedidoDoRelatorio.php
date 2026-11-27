<?php

declare(strict_types=1);

namespace App\Comercial\DTO;

use App\Comercial\Enum\CentralDaCampanha;
use App\Comercial\Repository\RelatorioDeCampanhasRepository;

/**
 * O pedido da lista do Relatório (desenho 05 VS 21.10.26, L300-305): a aba (`env`, `ag`, `rec`, `est` ou `arq`; fora
 * disso, `env`), a busca por campanha ou e-mail (aparada, até 120 caracteres), a página (a partir de 1) e a seção da
 * tela (`secao`: `com` ou `ind`; pedido do Farlei M1, D5), que decide de qual Central são as campanhas listadas.
 */
final class PedidoDoRelatorio
{
    public const BUSCA_MAXIMA = 120;

    public function __construct(
        public readonly string $aba = 'env',
        public readonly string $busca = '',
        public readonly int $pagina = 1,
        /** Nula: as das duas Centrais (o conector de I.A., que lê o Comercial inteiro, como antes). */
        public readonly ?CentralDaCampanha $central = null,
    ) {
    }

    /** @param array<string, mixed> $query os parâmetros da URL (`aba`, `q`, `p`, `secao`) */
    public static function deQuery(array $query): self
    {
        $aba = \is_string($query['aba'] ?? null) && \in_array($query['aba'], RelatorioDeCampanhasRepository::ABAS, true) ? $query['aba'] : 'env';
        $busca = \is_string($query['q'] ?? null) ? mb_substr(trim($query['q']), 0, self::BUSCA_MAXIMA) : '';
        $pagina = \is_string($query['p'] ?? null) && preg_match('/^[1-9]\d{0,5}$/', $query['p']) === 1 ? (int) $query['p'] : 1;

        return new self($aba, $busca, $pagina, CentralDaCampanha::daSecao($query['secao'] ?? null));
    }
}
