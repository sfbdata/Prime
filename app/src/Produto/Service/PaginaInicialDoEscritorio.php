<?php

declare(strict_types=1);

namespace App\Produto\Service;

use App\Entity\Tenant\Tenant;
use App\Produto\Enum\Produto;

/**
 * A primeira tela do escritório depois do login, da escolha do escritório e do `/inicio` (spec multiproduto, §5,
 * item 4): a rota inicial do primeiro produto que o escritório tem, nesta ordem; sem nenhum, o Meu Perfil (núcleo).
 *
 * O Expediente vem primeiro: o escritório completo (todos os de hoje) continua indo ao `expediente_index`.
 */
final class PaginaInicialDoEscritorio
{
    /** A rota de hoje, para quando ainda não há escritório escolhido (a seleção vem depois, como sempre). */
    public const SEM_ESCRITORIO = 'expediente_index';

    /** Só o núcleo: nenhum produto com tela inicial. */
    public const NUCLEO = 'app_profile';

    private const ORDEM = [
        Produto::Expediente,
        Produto::Voto,
        Produto::Cobrancas,
        Produto::Ponto,
        Produto::Calculo,
        Produto::Kanban,
        Produto::Assinatura,
    ];

    public function __construct(
        private readonly ProdutosDoEscritorio $produtos,
    ) {
    }

    public function rota(Tenant $tenant): string
    {
        foreach (self::ORDEM as $produto) {
            if ($this->produtos->inclui($tenant, $produto)) {
                return CatalogoDeProdutos::rotaInicial($produto);
            }
        }

        return self::NUCLEO;
    }

    /** Sem escritório (nenhum na sessão): a rota de hoje. */
    public function rotaOuPadrao(?Tenant $tenant): string
    {
        return null === $tenant ? self::SEM_ESCRITORIO : $this->rota($tenant);
    }
}
