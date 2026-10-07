<?php

declare(strict_types=1);

namespace App\Pasta\UseCase;

use App\Entity\Tenant\Tenant;
use App\Pasta\Entity\Pasta;
use App\Pasta\Entity\PastaPagamento;
use App\Shared\Service\ValorEmReais;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Corrige o valor de um pagamento já lançado na pasta — o "Editar ou corrigir
 * valores" do ⋮ do card Pagamentos (desenho 1.2.3, dc 3443-3447).
 *
 * Quem: alguém da equipe com permissão de editar a pasta, que lançou a parcela
 * com o valor errado ou viu o combinado mudar. Antes a única saída era excluir
 * e lançar de novo, o que apagava a data de criação e a quitação junto.
 *
 * O HISTÓRICO não é gravado aqui: `PastaPagamento` é `Auditavel`, e o
 * `AuditLogSubscriber` registra de/para, usuário e hora no mesmo flush. Por
 * isso o UseCase só pode dar UM flush — e nenhum quando nada mudou, senão o
 * histórico ganharia uma linha "R$ a → R$ a".
 *
 * Mexe só no `valor` deste lançamento da pasta. Cobrança e contabilidade não
 * passam por aqui: aquele dinheiro é outra coisa, com outro dono.
 */
final class CorrigirValorDoPagamentoUseCase
{
    public function __construct(
        private readonly EntityManagerInterface $em,
    ) {}

    /**
     * @return bool `true` quando o valor mudou e foi gravado; `false` quando o
     *              valor informado é o mesmo (nada é gravado, nada é auditado)
     *
     * @throws \InvalidArgumentException quando o texto não é um valor em reais maior que zero
     * @throws \DomainException          quando o pagamento não é desta pasta ou deste escritório
     */
    public function executar(PastaPagamento $pagamento, Pasta $pasta, Tenant $tenant, string $novoValor): bool
    {
        // O controller já busca com `findByIdAndPastaAndTenant`; a conferência
        // aqui é a segunda trava, para quem chamar este UseCase por outro caminho.
        if (!self::mesmo($pagamento->getPasta(), $pasta) || !self::mesmo($pagamento->getTenant(), $tenant)) {
            throw new \DomainException('Pagamento não encontrado nesta pasta.');
        }

        $decimal = ValorEmReais::normalizar($novoValor, 'valor do pagamento');

        // Mesma regra do lançamento: pagamento de R$ 0,00 não é uma correção,
        // é uma linha que não deveria existir — para isso há o Excluir.
        if ($decimal === null || ValorEmReais::paraCentavos($decimal) <= 0) {
            throw new \InvalidArgumentException('Informe um valor maior que zero.');
        }

        if (ValorEmReais::paraCentavos($decimal) === ValorEmReais::paraCentavos($pagamento->getValor())) {
            return false;
        }

        $pagamento->setValor($decimal);
        $this->em->flush();

        return true;
    }

    /**
     * Mesma instância, ou o mesmo id. A instância do contexto de tenant nem
     * sempre é a que o Doctrine pendurou no pagamento; o id é que decide.
     */
    private static function mesmo(Pasta|Tenant|null $a, Pasta|Tenant $b): bool
    {
        if ($a === null) {
            return false;
        }

        if ($a === $b) {
            return true;
        }

        return $a->getId() !== null && $a->getId() === $b->getId();
    }
}
