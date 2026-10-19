<?php

declare(strict_types=1);

namespace App\Contribua\UseCase;

use App\Contribua\DTO\Solicitante;
use App\Contribua\Entity\Plano;
use App\Contribua\Repository\PlanoRepository;
use App\Contribua\Service\AvisoAoMaster;
use App\Contribua\Service\SituacaoDoEspaco;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

/**
 * "Quero +N GB" enquanto a assinatura por cartão não está ligada (C2): o pedido entra em lista de
 * espera e o Master é avisado. É o caminho do próprio desenho para gateway não configurado
 * (`coPedir`). Na C3 o botão abre o checkout de cartão.
 *
 * Só o usuário primário (ou o Master) pede espaço, como no desenho; só plano pago maior que o limite
 * atual.
 */
final class PedirEspacoUseCase
{
    public const PEDIDO_REGISTRADO = 'Pedido registrado. Avisaremos quando a assinatura por cartão for liberada.';

    public function __construct(
        private readonly PlanoRepository $planos,
        private readonly SituacaoDoEspaco $situacao,
        private readonly AvisoAoMaster $avisoAoMaster,
        private readonly UrlGeneratorInterface $urls,
    ) {
    }

    public function executar(Solicitante $quem, string $codigoDoPlano): string
    {
        if (!$quem->ehPrimario && !$quem->ehMaster) {
            throw new \DomainException('Quem amplia o espaço é o usuário primário do escritório.');
        }
        $plano = $this->planoQueAmplia($quem->tenantId(), $codigoDoPlano);

        $this->avisoAoMaster->avisar(
            $quem->escritorio,
            'contribua.pedido_espaco',
            sprintf('Pedido de espaço: %s (conta Nº %s) quer o plano %s (%d GB). Lista de espera.', $quem->escritorio->getName(), $quem->numeroDaConta(), $plano->getNome(), $plano->getCotaGb()),
            $this->urls->generate('contribua_index') . '#espaco',
        );

        return self::PEDIDO_REGISTRADO;
    }

    private function planoQueAmplia(int $tenantId, string $codigo): Plano
    {
        $plano = $this->planos->findOneBy(['codigo' => $codigo, 'ativo' => true]);
        $limite = $this->situacao->doEscritorio($tenantId)->bytesLimite;
        if (!$plano instanceof Plano || $plano->ehGratuito() || $limite === null || $plano->getCotaGb() * 1024 ** 3 <= $limite) {
            throw new \DomainException('Escolha um plano maior que o espaço atual.');
        }

        return $plano;
    }
}
