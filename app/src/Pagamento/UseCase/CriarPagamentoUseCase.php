<?php

declare(strict_types=1);

namespace App\Pagamento\UseCase;

use App\Entity\Tenant\Tenant;
use App\Pagamento\DTO\CriarPagamentoInput;
use App\Pagamento\DTO\PagamentoCriadoOutput;
use App\Pagamento\Entity\Pagamento;
use App\Pagamento\Entity\PagamentoEvento;
use App\Pagamento\Repository\PagamentoEventoRepository;
use App\Pagamento\Repository\PagamentoRepository;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Cria a cobrança no registro único, congelando o valor. Idempotente pela referência: repetir a chamada (reenvio,
 * timeout, duplo clique) devolve a cobrança que já existe, sem criar outra nem mudar o valor dela.
 * Quem chama garante que o `tenantId` é o do dono do recurso (o consumidor já autorizou o usuário).
 */
final class CriarPagamentoUseCase
{
    public function __construct(
        private readonly PagamentoRepository $pagamentos,
        private readonly PagamentoEventoRepository $eventos,
        private readonly EntityManagerInterface $em,
    ) {
    }

    public function executar(CriarPagamentoInput $input): PagamentoCriadoOutput
    {
        $existente = $this->pagamentos->porReferenciaDoAviso($input->referencia);
        if ($existente !== null) {
            $this->exigirMesmoEscritorio($existente, $input);

            return PagamentoCriadoOutput::fromEntity($existente, false);
        }

        $pagamento = $this->criar($input);
        $this->em->wrapInTransaction(function () use ($pagamento): void {
            $this->pagamentos->salvar($pagamento);
            $this->eventos->salvar(new PagamentoEvento($pagamento->getTenant(), $pagamento, 'criado', 'sistema', $this->resumoDaCriacao($pagamento)));
        });

        return PagamentoCriadoOutput::fromEntity($pagamento, true);
    }

    private function criar(CriarPagamentoInput $input): Pagamento
    {
        /** @var Tenant $escritorio */
        $escritorio = $this->em->getReference(Tenant::class, $input->tenantId);

        return new Pagamento(
            $escritorio,
            $input->origem,
            $input->origemId,
            $input->referencia,
            $input->valorCentavos,
            $input->metodo,
            $input->provedor,
            $input->expiraEm,
        );
    }

    /** Uma referência é de um escritório só: a mesma referência pedida por outro é erro de quem chama, não reaproveitamento. */
    private function exigirMesmoEscritorio(Pagamento $existente, CriarPagamentoInput $input): void
    {
        if ($existente->getTenant()->getId() !== $input->tenantId) {
            throw new \DomainException('Esta referência de pagamento já pertence a outro escritório.');
        }
    }

    private function resumoDaCriacao(Pagamento $pagamento): string
    {
        return sprintf('Cobrança de %d centavos criada (%s, %s).', $pagamento->getValorCentavos(), $pagamento->getMetodo()->value, $pagamento->getProvedor());
    }
}
