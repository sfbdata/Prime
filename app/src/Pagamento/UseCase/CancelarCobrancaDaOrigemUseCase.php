<?php

declare(strict_types=1);

namespace App\Pagamento\UseCase;

use App\Pagamento\Entity\PagamentoEvento;
use App\Pagamento\Enum\OrigemDoPagamento;
use App\Pagamento\Enum\StatusDoPagamento;
use App\Pagamento\Repository\PagamentoEventoRepository;
use App\Pagamento\Repository\PagamentoRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autoconfigure;

/**
 * O consumidor mudou o que cobra (valor ajustado) ou resolveu à mão: a cobrança `aguardando` mais recente é cancelada
 * aqui, com o motivo na trilha. PIX tardio nela cai em `divergente` para o Master decidir. Devolve se cancelou algo.
 * O banco não tem cancelamento na interface {@see \App\Contribua\Banco\BancoPix}: o cancelamento é só nosso.
 */
// Público para o teste funcional alcançá-lo pelo container.
#[Autoconfigure(public: true)]
final class CancelarCobrancaDaOrigemUseCase
{
    public function __construct(
        private readonly PagamentoRepository $pagamentos,
        private readonly PagamentoEventoRepository $eventos,
        private readonly EntityManagerInterface $em,
    ) {
    }

    /** @throws \DomainException com `$recusarSePaga`, quando a cobrança mais recente já foi paga (conferido com o pagamento travado) */
    public function executar(int $tenantId, OrigemDoPagamento $origem, int $origemId, string $motivo, bool $recusarSePaga = false): bool
    {
        $ultimo = $this->pagamentos->ultimoDaOrigem($tenantId, $origem, $origemId);
        if ($ultimo === null || ($ultimo->getStatus() !== StatusDoPagamento::Aguardando && !$recusarSePaga)) {
            return false;
        }

        return (bool) $this->em->wrapInTransaction(function () use ($ultimo, $motivo, $recusarSePaga): bool {
            $this->pagamentos->travar($ultimo);
            if ($recusarSePaga && $ultimo->getStatus() === StatusDoPagamento::Paga) {
                throw new \DomainException('O banco já confirmou o pagamento desta demanda.');
            }
            if ($ultimo->getStatus() !== StatusDoPagamento::Aguardando) {
                return false;
            }
            $ultimo->cancelar();
            $this->eventos->salvar(new PagamentoEvento($ultimo->getTenant(), $ultimo, 'cancelado', 'sistema', mb_substr($motivo, 0, 500)));

            return true;
        });
    }
}
