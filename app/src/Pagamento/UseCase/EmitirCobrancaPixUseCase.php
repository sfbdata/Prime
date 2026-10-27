<?php

declare(strict_types=1);

namespace App\Pagamento\UseCase;

use App\Contribua\Banco\BancosPix;
use App\Contribua\Banco\FalhaNoBanco;
use App\Contribua\Banco\PedidoDeCobrancaPix;
use App\Contribua\Repository\IntegracaoBancariaRepository;
use App\Pagamento\DTO\CobrancaPixOutput;
use App\Pagamento\DTO\CriarPagamentoInput;
use App\Pagamento\DTO\EmitirCobrancaPixInput;
use App\Pagamento\Entity\PagamentoEvento;
use App\Pagamento\Enum\MetodoDePagamento;
use App\Pagamento\Enum\StatusDoPagamento;
use App\Pagamento\Repository\PagamentoEventoRepository;
use App\Pagamento\Repository\PagamentoRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autoconfigure;
use Psr\Log\LoggerInterface;

/**
 * História: o consumidor (Trabalho Externo) pede o QR dinâmico. Gravamos o `pagamento` ANTES de falar com o banco
 * (idempotente pela referência) e só então criamos a cobrança, com a mesma chave de idempotência: se o banco criou e a
 * resposta se perdeu, a próxima chamada recebe a mesma cobrança. Nenhuma chamada ao banco dentro de transação.
 *
 * Devolve `null` quando não há QR dinâmico a mostrar (integração desligada ou sem a chave do consumidor, banco fora
 * do ar agora, recusa do banco): o consumidor cai no PIX estático. Recusa certa do banco cancela o pagamento;
 * falha passageira o deixa `aguardando` sem `brCode`, e a próxima abertura repete o pedido.
 */
// Público para o teste funcional alcançá-lo pelo container.
#[Autoconfigure(public: true)]
final class EmitirCobrancaPixUseCase
{
    public function __construct(
        private readonly IntegracaoBancariaRepository $integracoes,
        private readonly BancosPix $bancos,
        private readonly CriarPagamentoUseCase $criar,
        private readonly PagamentoRepository $pagamentos,
        private readonly PagamentoEventoRepository $eventos,
        private readonly EntityManagerInterface $em,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function executar(EmitirCobrancaPixInput $input): ?CobrancaPixOutput
    {
        $integracao = $this->integracoes->atual();
        $banco = $this->bancos->doCodigo($integracao->getBanco());
        if ($banco === null || !$integracao->cobraPorConta() || !($integracao->getRecursos()[$input->recurso] ?? false)) {
            return null;
        }

        $criada = $this->criar->executar(new CriarPagamentoInput(
            $input->tenantId,
            $input->origem,
            $input->origemId,
            $input->referencia,
            $input->valorCentavos,
            MetodoDePagamento::Pix,
            $banco->codigo(),
            $input->expiraEm,
        ));
        $pagamento = $this->pagamentos->find($criada->id);
        if ($pagamento === null || $pagamento->getStatus() !== StatusDoPagamento::Aguardando) {
            return null;
        }
        if ($pagamento->getBrCode() !== null) {
            return CobrancaPixOutput::fromEntity($pagamento);
        }

        try {
            $cobranca = $banco->criarCobranca(new PedidoDeCobrancaPix(
                $pagamento->getReferencia(),
                $pagamento->getIdempotencyKey(),
                $input->nomeDoCliente,
                $input->cnpj,
                $pagamento->getValorCentavos(),
                $input->expiraEm,
            ));
        } catch (FalhaNoBanco $falha) {
            $this->logger->warning('Pagamento: cobrança PIX não criada no banco', ['pagamento' => $pagamento->getId(), 'passageira' => $falha->passageira, 'motivo' => $falha->getMessage()]);
            if (!$falha->passageira) {
                $this->cancelarPorRecusa($pagamento->getId());
            }

            return null;
        }

        $this->em->wrapInTransaction(function () use ($pagamento, $cobranca): void {
            $this->pagamentos->travar($pagamento);
            $pagamento->registrarCobrancaNoBanco($cobranca->idNoBanco, $cobranca->copiaECola);
            $this->eventos->salvar(new PagamentoEvento($pagamento->getTenant(), $pagamento, 'cobranca_emitida', 'sistema', 'Cobrança PIX criada no banco.'));
        });

        return CobrancaPixOutput::fromEntity($pagamento);
    }

    private function cancelarPorRecusa(?int $id): void
    {
        $pagamento = $this->pagamentos->find((int) $id);
        if ($pagamento === null) {
            return;
        }
        $this->em->wrapInTransaction(function () use ($pagamento): void {
            $this->pagamentos->travar($pagamento);
            if ($pagamento->getStatus() === StatusDoPagamento::Aguardando) {
                $pagamento->cancelar();
                $this->eventos->salvar(new PagamentoEvento($pagamento->getTenant(), $pagamento, 'cancelado', 'sistema', 'O banco recusou criar a cobrança.'));
            }
        });
    }
}
