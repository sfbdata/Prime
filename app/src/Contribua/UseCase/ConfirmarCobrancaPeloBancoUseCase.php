<?php

declare(strict_types=1);

namespace App\Contribua\UseCase;

use App\Contribua\Banco\BancosPix;
use App\Contribua\Entity\Apoio;
use App\Contribua\Entity\CobrancaPixDaConta;
use App\Contribua\Repository\CobrancaPixDaContaRepository;
use App\Contribua\Service\AvisoAoMaster;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

/**
 * História: o escritório pagou o PIX da cobrança da conta. O banco avisa (webhook) ou a conciliação passa;
 * nos dois casos reconsultamos o banco por mTLS, e só a resposta dele confirma (o aviso da Cora vem sem
 * corpo e sem assinatura). Paga: nasce o `Apoio` confirmado (origem do banco, id da cobrança, nosso
 * código), a cobrança fica paga, o Master é avisado e o prêmio do apoiador aparece na próxima visita da
 * conta à página Contribua. Repetir não duplica (cobrança já paga sai cedo; o id do banco é único no Apoio).
 */
final class ConfirmarCobrancaPeloBancoUseCase
{
    public function __construct(
        private readonly CobrancaPixDaContaRepository $cobrancas,
        private readonly BancosPix $bancos,
        private readonly EntityManagerInterface $em,
        private readonly AvisoAoMaster $avisoAoMaster,
        private readonly UrlGeneratorInterface $urls,
    ) {
    }

    /** @return bool se confirmou um apoio agora */
    public function executar(string $codigoDoBanco, string $idNoBanco): bool
    {
        $cobranca = $this->cobrancas->doBanco($codigoDoBanco, $idNoBanco);
        $banco = $this->bancos->doCodigo($codigoDoBanco);
        if (null === $cobranca || null === $banco || CobrancaPixDaConta::ABERTA !== $cobranca->getSituacao()) {
            return false;
        }
        $situacao = $banco->consultar($idNoBanco);
        if ($situacao->cancelada) {
            $cobranca->marcarCancelada();
            $this->em->flush();

            return false;
        }
        if (!$situacao->paga) {
            return false;
        }

        return $this->confirmar($cobranca, $codigoDoBanco, $situacao->valorPagoCentavos ?: $cobranca->getValorCentavos(), $situacao->pagaEm ?? new \DateTimeImmutable());
    }

    private function confirmar(CobrancaPixDaConta $cobranca, string $codigoDoBanco, int $valorCentavos, \DateTimeImmutable $pagaEm): bool
    {
        $apoio = Apoio::confirmadoPeloBanco($cobranca->getTenant(), $valorCentavos, $codigoDoBanco, (string) $cobranca->getIdNoBanco(), (string) $cobranca->getCodigo(), $pagaEm);
        $cobranca->marcarPaga();
        try {
            $this->em->persist($apoio);
            $this->em->flush();
        } catch (UniqueConstraintViolationException) {
            // Outra reconsulta do mesmo pagamento confirmou primeiro: o apoio já existe.
            return false;
        }
        $this->avisoAoMaster->avisar(
            $cobranca->getTenant(),
            AvisoAoMaster::TIPO_APOIO_CONFIRMADO,
            sprintf('Apoio confirmado pelo banco: %s (conta Nº %s), %s.', (string) $cobranca->getTenant()->getName(), str_pad((string) $cobranca->getTenant()->getId(), 6, '0', \STR_PAD_LEFT), RegistrarApoioUseCase::reais($valorCentavos)),
            $this->urls->generate('contribua_index') . '#apoie',
        );

        return true;
    }
}
