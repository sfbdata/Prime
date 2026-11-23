<?php

declare(strict_types=1);

namespace App\Produto\Service;

use App\Entity\Tenant\Tenant;
use App\Produto\Entity\Contratacao;
use App\Produto\Enum\Produto;
use App\Produto\Repository\ContratacaoRepository;
use Psr\Clock\ClockInterface;
use Symfony\Contracts\Service\ResetInterface;

/**
 * Quais produtos valem para um escritório (spec docs/specs/multiproduto-contratacao-e-voto.md, §4):
 *
 * | Linhas do escritório                | Produtos que valem     |
 * |-------------------------------------|------------------------|
 * | nenhuma, em qualquer situação       | todos                  |
 * | `completo` ativa                    | todos                  |
 * | outras linhas ativas no período     | só esses produtos      |
 * | só encerradas                       | nenhum (só o núcleo)   |
 *
 * Linha ativa que ainda não começou não vale: com só ela, o escritório fica só com o núcleo até o início.
 *
 * O resultado fica em memória durante a requisição (ResetInterface: o worker limpa entre requisições).
 */
final class ProdutosDoEscritorio implements ResetInterface
{
    /** @var array<int, list<Produto>> produtos que valem, por id do tenant */
    private array $memoria = [];

    public function __construct(
        private readonly ContratacaoRepository $contratacoes,
        private readonly ClockInterface $relogio,
    ) {
    }

    /** @return list<Produto> os produtos que valem; todos (com o `completo`) para o escritório completo. */
    public function doTenant(Tenant $tenant): array
    {
        $id = $tenant->getId();
        // Escritório ainda não gravado não tem linha nem entra na memória (todos dividiriam a chave nula).
        if (null === $id) {
            return Produto::cases();
        }

        return $this->memoria[$id] ??= $this->calcular($this->contratacoes->todasDoTenant($tenant));
    }

    /**
     * O escritório tem o produto? Com lista, basta um (a rota de Clientes abre com Expediente ou Cobranças).
     *
     * @param Produto|list<Produto> $produtos
     */
    public function inclui(Tenant $tenant, Produto|array $produtos): bool
    {
        $valem = $this->doTenant($tenant);
        foreach (is_array($produtos) ? $produtos : [$produtos] as $produto) {
            if (in_array($produto, $valem, true)) {
                return true;
            }
        }

        return false;
    }

    /** O escritório é completo (sem linha nenhuma, ou com `completo` ativa)? */
    public function ehCompleto(Tenant $tenant): bool
    {
        return in_array(Produto::Completo, $this->doTenant($tenant), true);
    }

    public function reset(): void
    {
        $this->memoria = [];
    }

    /**
     * @param list<Contratacao> $linhas
     *
     * @return list<Produto>
     */
    private function calcular(array $linhas): array
    {
        if ([] === $linhas) {
            return Produto::cases();
        }

        $agora = \DateTimeImmutable::createFromInterface($this->relogio->now());
        $valem = [];
        foreach ($linhas as $linha) {
            if ($linha->valeEm($agora)) {
                $valem[$linha->getProduto()->value] = $linha->getProduto();
            }
        }

        return isset($valem[Produto::Completo->value]) ? Produto::cases() : array_values($valem);
    }
}
