<?php

declare(strict_types=1);

namespace App\Contribua\UseCase;

use App\Contribua\Banco\BancosPix;
use App\Contribua\Banco\SituacaoDaCobranca;
use App\Contribua\Entity\Apoio;
use App\Contribua\Entity\CobrancaPixDaConta;
use App\Contribua\Repository\ApoioRepository;
use App\Contribua\Repository\CobrancaPixDaContaRepository;
use App\Contribua\Repository\IntegracaoBancariaRepository;
use App\Contribua\Service\AvisoAoMaster;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

/**
 * História: o escritório pagou o PIX da cobrança da conta. O banco avisa (webhook) ou a conciliação passa;
 * nos dois casos reconsultamos o banco por mTLS, e só a resposta dele confirma (o aviso da Cora vem sem
 * corpo e sem assinatura). Regras:
 * - só se consulta a cobrança do ambiente configurado agora (as credenciais são do ambiente);
 * - o código devolvido pelo banco tem de ser o da cobrança;
 * - no ambiente de testes (sandbox) nenhum apoio nasce: a cobrança fica paga e o Master é avisado do teste;
 * - em produção, um pagamento é um apoio: se a conta já tinha informado ("Já contribuí") e o banco ainda não
 *   tinha confirmado, e clicou depois de a cobrança existir, é esse apoio que fica confirmado; senão nasce um, confirmado. Valor: o que o banco
 *   recebeu. Repetir não duplica (cobrança já paga sai cedo; o id do banco é conferido e é único).
 * O Master é avisado; o prêmio aparece na próxima visita da conta à página Contribua.
 */
final class ConfirmarCobrancaPeloBancoUseCase
{
    public function __construct(
        private readonly CobrancaPixDaContaRepository $cobrancas,
        private readonly ApoioRepository $apoios,
        private readonly IntegracaoBancariaRepository $integracoes,
        private readonly BancosPix $bancos,
        private readonly EntityManagerInterface $em,
        private readonly AvisoAoMaster $avisoAoMaster,
        private readonly UrlGeneratorInterface $urls,
        private readonly LoggerInterface $logger,
    ) {
    }

    /** @return bool se confirmou um apoio agora */
    public function executar(string $codigoDoBanco, string $idNoBanco): bool
    {
        $cobranca = $this->cobrancas->doBanco($codigoDoBanco, $idNoBanco);
        $banco = $this->bancos->doCodigo($codigoDoBanco);
        if (null === $cobranca || null === $banco || CobrancaPixDaConta::ABERTA !== $cobranca->getSituacao()
            || $cobranca->getAmbiente() !== $this->integracoes->atual()->getAmbiente()) {
            return false;
        }
        $situacao = $banco->consultar($idNoBanco);
        if ($situacao->cancelada) {
            $cobranca->marcarCancelada();
            $this->em->flush();

            return false;
        }
        if (!$situacao->paga || !$this->eDaCobranca($situacao, $cobranca)) {
            return false;
        }

        return 'producao' === $cobranca->getAmbiente()
            ? $this->confirmar($cobranca, $codigoDoBanco, $situacao)
            : $this->confirmarTeste($cobranca, $situacao);
    }

    private function eDaCobranca(SituacaoDaCobranca $situacao, CobrancaPixDaConta $cobranca): bool
    {
        if (null === $situacao->codigo || $situacao->codigo === $cobranca->getCodigo()) {
            return true;
        }
        $this->logger->error('Contribua: o banco devolveu outro código para a cobrança; não confirmado', ['cobranca' => $cobranca->getId()]);

        return false;
    }

    private function confirmar(CobrancaPixDaConta $cobranca, string $codigoDoBanco, SituacaoDaCobranca $situacao): bool
    {
        $cobranca->marcarPaga();
        if (null !== $this->apoios->doIdNoBanco((string) $cobranca->getIdNoBanco())) {
            $this->em->flush();

            return false;
        }
        $valor = min(max($situacao->valorPagoCentavos, 0) ?: $cobranca->getValorCentavos(), Apoio::VALOR_MAXIMO_CENTAVOS);
        $pagoEm = $situacao->pagaEm ?? new \DateTimeImmutable();
        $tenantId = (int) $cobranca->getTenant()->getId();
        // Só o "Já contribuí" clicado depois de a cobrança existir pode ser este pagamento.
        $informado = $this->apoios->informadoSemConfirmacao($tenantId, $cobranca->getCriadaEm());
        if (null !== $informado) {
            $informado->confirmarPeloBanco($valor, $codigoDoBanco, (string) $cobranca->getIdNoBanco(), (string) $cobranca->getCodigo(), $pagoEm);
        } else {
            $this->em->persist(Apoio::confirmadoPeloBanco($cobranca->getTenant(), $valor, $codigoDoBanco, (string) $cobranca->getIdNoBanco(), (string) $cobranca->getCodigo(), $pagoEm));
        }
        try {
            $this->em->flush();
        } catch (UniqueConstraintViolationException) {
            // Webhook e conciliação ao mesmo tempo: o outro confirmou primeiro.
            return false;
        }
        $this->avisar($cobranca, AvisoAoMaster::TIPO_APOIO_CONFIRMADO, 'Apoio confirmado pelo banco: %s (conta Nº %s), %s.', $valor);

        return true;
    }

    private function confirmarTeste(CobrancaPixDaConta $cobranca, SituacaoDaCobranca $situacao): bool
    {
        $cobranca->marcarPaga();
        $this->em->flush();
        $this->avisar($cobranca, AvisoAoMaster::TIPO_APOIO_CONFIRMADO, 'Teste da Cora (sandbox): pagamento confirmado de %s (conta Nº %s), %s. Nenhum apoio foi registrado.', $situacao->valorPagoCentavos ?: $cobranca->getValorCentavos());

        return false;
    }

    private function avisar(CobrancaPixDaConta $cobranca, string $tipo, string $modelo, int $valor): void
    {
        $tenant = $cobranca->getTenant();
        $this->avisoAoMaster->avisar(
            $tenant,
            $tipo,
            sprintf($modelo, (string) $tenant->getName(), str_pad((string) $tenant->getId(), 6, '0', \STR_PAD_LEFT), RegistrarApoioUseCase::reais($valor)),
            $this->urls->generate('contribua_index') . '#apoie',
        );
    }
}
