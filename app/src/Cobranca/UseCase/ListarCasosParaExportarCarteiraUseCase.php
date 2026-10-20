<?php

declare(strict_types=1);

namespace App\Cobranca\UseCase;

use App\Cobranca\DTO\CasoResumoOutput;
use App\Cobranca\Entity\Carteira;
use App\Cobranca\Exception\ListaGrandeDemaisParaExportarException;

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
 *
 * Teto de linhas: acima de `$limiteLinhas` a planilha não é gerada (a maior carteira da produção tem
 * 224 casos em 20/10/2026; o teto existe para que uma carteira anômala não monte uma planilha de
 * memória ilimitada numa requisição). O teto é argumento do construtor para o teste poder baixá-lo.
 */
final class ListarCasosParaExportarCarteiraUseCase
{
    public const LIMITE_LINHAS = 5000;

    public function __construct(
        private readonly MontarVisaoCarteiraUseCase $montarVisaoCarteira,
        private readonly int $limiteLinhas = self::LIMITE_LINHAS,
    ) {
    }

    /**
     * @return array{rotuloObjeto: string, casos: list<CasoResumoOutput>}
     *
     * @throws ListaGrandeDemaisParaExportarException quando a lista recortada passa do teto
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

        if ($visao['total'] > $this->limiteLinhas) {
            throw new ListaGrandeDemaisParaExportarException($this->limiteLinhas);
        }

        // Mesmo critério da tela (`|default('Objeto')` do Twig também troca a string VAZIA).
        $rotulo = $visao['carteira']->rotuloObjeto;

        return [
            'rotuloObjeto' => ($rotulo ?? '') !== '' ? $rotulo : 'Objeto',
            'casos' => $visao['casos'],
        ];
    }
}
