<?php

declare(strict_types=1);

namespace App\Contribua\Twig;

use App\Contribua\DTO\CampanhaView;
use App\Contribua\Service\SolicitanteDaSessao;
use App\Contribua\UseCase\MontarCampanhaViewUseCase;
use Psr\Log\LoggerInterface;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;
use Symfony\Contracts\Service\ResetInterface;
use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;

/**
 * `campanha()`: a campanha "BlueJus Black" para o usuário logado (aba do topo, faixa e FAQ do Contribua). Null sem
 * campanha, sem usuário ou sem escritório. Memorizada por requisição e nunca lança: nenhuma tela cai por causa dela.
 */
final class CampanhaExtension extends AbstractExtension implements ResetInterface
{
    private bool $montada = false;
    private ?CampanhaView $view = null;

    public function __construct(
        private readonly SolicitanteDaSessao $sessao,
        private readonly MontarCampanhaViewUseCase $montar,
        private readonly LoggerInterface $logger,
    ) {
    }

    /** Entre requisições no mesmo processo (worker, testes), a memória é descartada. */
    public function reset(): void
    {
        $this->montada = false;
        $this->view = null;
    }

    public function getFunctions(): array
    {
        return [new TwigFunction('campanha', $this->campanha(...))];
    }

    public function campanha(): ?CampanhaView
    {
        if ($this->montada) {
            return $this->view;
        }
        $this->montada = true;
        try {
            $this->view = $this->montar->executar($this->sessao->usuarioNoEscritorio(), $this->sessao->ehMaster());
        } catch (AccessDeniedException) {
            $this->view = null;
        } catch (\Throwable $falha) {
            $this->logger->warning('Contribua: a campanha não montou', ['erro' => $falha->getMessage()]);
            $this->view = null;
        }

        return $this->view;
    }
}
