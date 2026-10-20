<?php

declare(strict_types=1);

namespace App\Cobranca\UseCase;

use App\Cobranca\DTO\CasoResumoOutput;
use App\Cobranca\Entity\Carteira;

/**
 * Leitura: a lista de cobranças da carteira como a tela a mostra, inteira, para o "Exportar lista
 * (.xlsx)" do ⋮ (desenho Carteira 1B, L75-84).
 *
 * Quem dispara: quem já vê a carteira (mesmo gate de módulo e de tenant da tela). O que quer: levar
 * para a planilha EXATAMENTE o recorte que está olhando (busca, Estado e ordenação), sem a paginação.
 *
 * Por isso não há consulta própria aqui: reaproveita o `MontarVisaoCarteiraUseCase`, que é quem
 * define o que a lista mostra. Uma segunda consulta "parecida" divergiria da tela no primeiro ajuste
 * de regra (a busca, o recorte "Só com atraso", o saldo derivado). A carteira chega já resolvida por
 * tenant no controller; o UseCase da visão escopa o resto pela própria carteira.
 *
 * A planilha não leva nada além das colunas da tela: devolver `CasoResumoOutput` (e não entidades)
 * é o que garante isso, porque o DTO não carrega CPF, e-mail nem telefone.
 */
final class ListarCasosParaExportarCarteiraUseCase
{
    public function __construct(
        private readonly MontarVisaoCarteiraUseCase $montarVisaoCarteira,
    ) {
    }

    /**
     * @return array{rotuloObjeto: string, casos: list<CasoResumoOutput>}
     */
    public function executar(
        Carteira $carteira,
        string $busca = '',
        string $ordenar = 'saldo',
        string $direcao = 'desc',
        string $estado = '',
    ): array {
        // "Sem paginação" é uma página só do tamanho que couber: o UseCase da visão grampeia a página e
        // fatia a lista, e com `PHP_INT_MAX` por página a fatia é a lista inteira, já filtrada e ordenada.
        $visao = $this->montarVisaoCarteira->executar($carteira, $busca, 1, \PHP_INT_MAX, $ordenar, $direcao, $estado);

        return [
            'rotuloObjeto' => $visao['carteira']->rotuloObjeto ?? 'Objeto',
            'casos' => $visao['casos'],
        ];
    }
}
