<?php

declare(strict_types=1);

namespace App\Contribua\Twig;

use App\Contribua\Service\ApoiadoresDoBlueJus;
use App\Contribua\Service\SolicitanteDaSessao;
use Psr\Log\LoggerInterface;
use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;

/**
 * `selo_apoiador()`: o "Selo Apoiador BlueJus" do escritório da sessão (desenho 08, benefício do prêmio: "Aparece
 * no seu perfil"), para o parcial `contribua/_selo_apoiador.html.twig` do Meu Perfil. Null quando o escritório
 * não é apoiador. Nunca lança: o Meu Perfil não pode cair por causa do selo.
 */
final class SeloApoiadorExtension extends AbstractExtension
{
    public function __construct(
        private readonly SolicitanteDaSessao $sessao,
        private readonly ApoiadoresDoBlueJus $apoiadores,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function getFunctions(): array
    {
        return [new TwigFunction('selo_apoiador', $this->doEscritorioDaSessao(...))];
    }

    /** @return array{dias: int}|null */
    public function doEscritorioDaSessao(): ?array
    {
        try {
            return $this->paraEscritorio($this->sessao->atual()->tenantId());
        } catch (\Throwable $falha) {
            $this->logger->warning('Contribua: selo de apoiador não montou', ['erro' => $falha->getMessage()]);

            return null;
        }
    }

    /** @return array{dias: int}|null */
    public function paraEscritorio(int $tenantId): ?array
    {
        return $this->apoiadores->ehApoiador($tenantId, new \DateTimeImmutable())
            ? ['dias' => ApoiadoresDoBlueJus::DIAS_DE_APOIADOR]
            : null;
    }
}
