<?php

declare(strict_types=1);

namespace App\Contribua\Twig;

use App\Contribua\Service\SolicitanteDaSessao;
use App\Contribua\UseCase\MontarPagamentoDoContribuaUseCase;
use Psr\Log\LoggerInterface;
use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;

/**
 * `assinatura_no_perfil()`: os dados do bloco "Assinatura" do Meu Perfil (parcial
 * `contribua/_assinatura_no_perfil.html.twig`, incluído pelo Meu Perfil). Null quando o escritório não tem
 * assinatura. Nunca lança: o Meu Perfil não pode cair por causa do bloco do Contribua.
 */
final class AssinaturaNoPerfilExtension extends AbstractExtension
{
    public function __construct(
        private readonly SolicitanteDaSessao $sessao,
        private readonly MontarPagamentoDoContribuaUseCase $pagamento,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function getFunctions(): array
    {
        return [new TwigFunction('assinatura_no_perfil', $this->dados(...))];
    }

    /** @return array<string, mixed>|null */
    public function dados(): ?array
    {
        try {
            $quem = $this->sessao->atual();
            $assinatura = $this->pagamento->assinaturaDoEscritorio($quem->tenantId());

            return $assinatura === null ? null : $assinatura + ['podeCancelar' => $assinatura['cancelavel'] && ($quem->ehPrimario || $quem->ehMaster)];
        } catch (\Throwable $falha) {
            $this->logger->warning('Contribua: bloco Assinatura do Meu Perfil não montou', ['erro' => $falha->getMessage()]);

            return null;
        }
    }
}
