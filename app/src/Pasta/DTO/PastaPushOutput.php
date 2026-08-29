<?php

declare(strict_types=1);

namespace App\Pasta\DTO;

use App\Djen\DTO\PublicacaoDjenListaItem;

/**
 * O que a aba Push Processual da pasta precisa mostrar, já resolvido — a tela não decide nada.
 *
 * Os dois "vazios" desta aba têm causas diferentes e merecem textos diferentes: pasta SEM processo
 * vinculado nunca receberá publicação (é o caso de 991 das 1.079 pastas em produção), enquanto
 * pasta COM processo e sem publicação depende de o processo estar sob uma OAB monitorada. Dizer
 * "nenhuma publicação" nos dois casos esconderia do usuário o que ele precisa fazer.
 */
final readonly class PastaPushOutput
{
    /** @param PublicacaoDjenListaItem[] $itens */
    private function __construct(
        public array $itens,
        public bool $temProcesso,
        public int $total,
        public int $naoLidas,
        public bool $limiteAtingido,
        public ?string $numeroUnico,
    ) {}

    /**
     * @param PublicacaoDjenListaItem[] $itens publicações já casadas com os processos da pasta
     * @param string[]                  $numeros números CNJ dos processos vinculados à pasta
     * @param int                       $limite teto aplicado na consulta
     */
    public static function montar(array $itens, array $numeros, int $limite): self
    {
        $numeros = array_values(array_filter(
            array_map(static fn (?string $n): string => trim((string) $n), $numeros),
            static fn (string $n): bool => $n !== '',
        ));

        $naoLidas = count(array_filter($itens, static fn (PublicacaoDjenListaItem $i): bool => !$i->lida));

        return new self(
            itens: array_values($itens),
            temProcesso: $numeros !== [],
            total: count($itens),
            naoLidas: $naoLidas,
            limiteAtingido: count($itens) >= $limite,
            // O módulo filtra por UM texto de busca. Com dois processos não há número único que
            // represente a pasta, e mandar um deles seria oferecer um recorte errado com cara de certo.
            numeroUnico: count($numeros) === 1 ? $numeros[0] : null,
        );
    }
}
