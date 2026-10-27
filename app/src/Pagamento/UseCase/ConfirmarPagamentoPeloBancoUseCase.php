<?php

declare(strict_types=1);

namespace App\Pagamento\UseCase;

use App\Contribua\Banco\BancosPix;
use App\Contribua\Banco\SituacaoDaCobranca;
use App\Contribua\Repository\IntegracaoBancariaRepository;
use App\Contribua\Service\AvisoAoMaster;
use App\Pagamento\DTO\ConfirmarPagamentoInput;
use App\Pagamento\Entity\Pagamento;
use App\Pagamento\Entity\PagamentoEvento;
use App\Pagamento\Enum\ResultadoDaConfirmacao;
use App\Pagamento\Enum\ResultadoDaReconsulta;
use App\Pagamento\Enum\StatusDoPagamento;
use App\Pagamento\Repository\PagamentoEventoRepository;
use App\Pagamento\Repository\PagamentoRepository;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autoconfigure;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

/**
 * História: o banco avisou (webhook) ou a conciliação passou; reconsultamos a cobrança por mTLS e só a resposta do
 * banco confirma (o aviso da Cora vem sem corpo e sem assinatura). O escritório sai do próprio pagamento, nunca do aviso.
 *
 * - só se reconsulta pagamento que ainda pode mudar (`aguardando`, ou `expirada`/`cancelada` que pode ter recebido
 *   dinheiro tardio); o resto sai cedo;
 * - ambiente de testes (sandbox): registra o teste na trilha uma vez e avisa o Master, nunca confirma (decisão 27/10);
 * - o código que o banco devolve tem de ser a referência do pagamento, senão nada é confirmado;
 * - a confirmação em si é do {@see ConfirmarPagamentoUseCase} (travada, idempotente, sem exceção por regra);
 * - valor diferente ou dinheiro em cobrança inativa vira `divergente` e o Master é avisado; pagou a mais libera e avisa.
 * Nenhuma chamada ao banco acontece dentro de transação. Falha do banco sobe como {@see \App\Contribua\Banco\FalhaNoBanco}
 * para o Messenger repetir ou a conciliação contar.
 */
// Público para o teste funcional alcançá-lo pelo container (sem mexer no services.yaml, arquivo quente).
#[Autoconfigure(public: true)]
final class ConfirmarPagamentoPeloBancoUseCase
{
    public function __construct(
        private readonly PagamentoRepository $pagamentos,
        private readonly PagamentoEventoRepository $eventos,
        private readonly IntegracaoBancariaRepository $integracoes,
        private readonly BancosPix $bancos,
        private readonly ConfirmarPagamentoUseCase $confirmar,
        private readonly EntityManagerInterface $em,
        private readonly AvisoAoMaster $avisoAoMaster,
        private readonly UrlGeneratorInterface $urls,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function executar(string $provedor, string $idNoBanco): ResultadoDaReconsulta
    {
        $iguais = $this->pagamentos->todosPorIdExterno($provedor, $idNoBanco);
        if (count($iguais) > 1) {
            return $this->duplicado($iguais);
        }
        $pagamento = $iguais[0] ?? null;
        $banco = $this->bancos->doCodigo($provedor);
        if ($pagamento === null || $banco === null || !$this->podeMudar($pagamento) || !$this->integracoes->atual()->temCertificado()) {
            return ResultadoDaReconsulta::Ignorado;
        }

        $situacao = $banco->consultar($idNoBanco);
        if ($situacao->cancelada) {
            return $this->cancelar($pagamento);
        }
        if (!$situacao->paga) {
            return ResultadoDaReconsulta::Aguardando;
        }
        if ($situacao->codigo === null || $situacao->valorPagoCentavos <= 0) {
            // Sem o código ou sem o valor não há o que conferir: nada se confirma, a próxima reconsulta decide.
            $this->logger->warning('Pagamento: o banco deu a cobrança por paga sem código ou valor; não confirmado', ['pagamento' => $pagamento->getId()]);

            return ResultadoDaReconsulta::Aguardando;
        }
        if ($situacao->codigo !== $pagamento->getReferencia()) {
            return $this->inconsistente($pagamento);
        }
        if ($this->integracoes->atual()->getAmbiente() !== 'producao') {
            return $this->teste($pagamento, $situacao);
        }

        return $this->confirmarPago($pagamento, $situacao);
    }

    /** @param list<Pagamento> $iguais */
    private function duplicado(array $iguais): ResultadoDaReconsulta
    {
        $this->logger->error('Pagamento: mais de um registro com o mesmo id no banco; nada confirmado', ['pagamentos' => array_map(static fn (Pagamento $p): ?int => $p->getId(), $iguais)]);
        foreach ($iguais as $pagamento) {
            if ($pagamento->marcarDivergente()) {
                $this->eventos->salvar(new PagamentoEvento($pagamento->getTenant(), $pagamento, 'duplicado', 'sistema', 'Há mais de um pagamento com o mesmo id no banco; o Master decide.'));
                $this->em->flush();
                $this->avisar($pagamento, 'Cobranças repetidas no banco (mesmo id em mais de um pagamento)');
            }
        }

        return ResultadoDaReconsulta::Divergente;
    }

    private function podeMudar(Pagamento $pagamento): bool
    {
        return in_array($pagamento->getStatus(), [StatusDoPagamento::Aguardando, StatusDoPagamento::Expirada, StatusDoPagamento::Cancelada], true);
    }

    private function confirmarPago(Pagamento $pagamento, SituacaoDaCobranca $situacao): ResultadoDaReconsulta
    {
        $valor = $situacao->valorPagoCentavos;
        $saida = $this->confirmar->executar(new ConfirmarPagamentoInput(
            $pagamento->getReferencia(),
            $valor,
            null,
            $situacao->pagaEm ?? new \DateTimeImmutable(),
            'banco',
        ));

        return match ($saida->resultado) {
            ResultadoDaConfirmacao::Confirmado, ResultadoDaConfirmacao::JaConfirmado => ResultadoDaReconsulta::Confirmado,
            ResultadoDaConfirmacao::ConfirmadoComDiferenca => $this->liberouComDiferenca($pagamento, $saida->diferencaCentavos),
            ResultadoDaConfirmacao::ValorMenor, ResultadoDaConfirmacao::CobrancaInativa => $this->divergiu($pagamento, $saida->resultado),
            ResultadoDaConfirmacao::PagamentoRepetido, ResultadoDaConfirmacao::NaoEncontrado => ResultadoDaReconsulta::Ignorado,
        };
    }

    private function liberouComDiferenca(Pagamento $pagamento, int $diferenca): ResultadoDaReconsulta
    {
        $this->avisar($pagamento, sprintf('Pagamento recebido com %s a mais que o cobrado', self::reais($diferenca)));

        return ResultadoDaReconsulta::Confirmado;
    }

    private function divergiu(Pagamento $pagamento, ResultadoDaConfirmacao $resultado): ResultadoDaReconsulta
    {
        $this->avisar($pagamento, $resultado === ResultadoDaConfirmacao::ValorMenor
            ? 'Pagamento recebido com valor menor que o cobrado'
            : 'Dinheiro recebido em cobrança cancelada ou vencida');

        return ResultadoDaReconsulta::Divergente;
    }

    private function cancelar(Pagamento $pagamento): ResultadoDaReconsulta
    {
        if ($pagamento->getStatus() !== StatusDoPagamento::Aguardando) {
            return ResultadoDaReconsulta::Ignorado;
        }
        $this->em->wrapInTransaction(function () use ($pagamento): void {
            $this->pagamentos->travar($pagamento);
            if ($pagamento->getStatus() === StatusDoPagamento::Aguardando) {
                $pagamento->cancelar();
                $this->eventos->salvar(new PagamentoEvento($pagamento->getTenant(), $pagamento, 'cancelado', 'banco', 'O banco cancelou a cobrança.'));
            }
        });

        return ResultadoDaReconsulta::Cancelado;
    }

    private function inconsistente(Pagamento $pagamento): ResultadoDaReconsulta
    {
        $this->logger->error('Pagamento: o banco devolveu outro código para a cobrança; não confirmado', ['pagamento' => $pagamento->getId()]);
        if (!$this->eventos->jaTem((int) $pagamento->getId(), 'inconsistente')) {
            $this->eventos->salvar(new PagamentoEvento($pagamento->getTenant(), $pagamento, 'inconsistente', 'banco', 'O código devolvido pelo banco não é o da cobrança; nada foi confirmado.'));
            $this->em->flush();
        }

        return ResultadoDaReconsulta::Inconsistente;
    }

    private function teste(Pagamento $pagamento, SituacaoDaCobranca $situacao): ResultadoDaReconsulta
    {
        if (!$this->eventos->jaTem((int) $pagamento->getId(), 'teste')) {
            $this->eventos->salvar(new PagamentoEvento($pagamento->getTenant(), $pagamento, 'teste', 'banco', sprintf('Ambiente de testes: banco deu a cobrança por paga (%s). Nada foi confirmado.', self::reais($situacao->valorPagoCentavos ?: $pagamento->getValorCentavos()))));
            $this->em->flush();
            $this->avisar($pagamento, 'Teste do banco (sandbox): cobrança dada por paga, nada foi confirmado');
        }

        return ResultadoDaReconsulta::Teste;
    }

    private function avisar(Pagamento $pagamento, string $texto): void
    {
        $tenant = $pagamento->getTenant();
        $this->avisoAoMaster->avisar(
            $tenant,
            AvisoAoMaster::TIPO_PAGAMENTO_DIVERGENTE,
            sprintf('%s: %s (escritório Nº %s), referência %s.', $texto, (string) $tenant->getName(), str_pad((string) $tenant->getId(), 6, '0', \STR_PAD_LEFT), $pagamento->getReferencia()),
            $this->urls->generate('central_index'),
        );
    }

    private static function reais(int $centavos): string
    {
        return 'R$ ' . number_format($centavos / 100, 2, ',', '.');
    }
}
