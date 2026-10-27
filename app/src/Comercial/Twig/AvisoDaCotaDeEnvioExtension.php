<?php

declare(strict_types=1);

namespace App\Comercial\Twig;

use App\Comercial\Controller\AvisoDaCotaDeEnvioController;
use App\Comercial\Envio\CotaDeEnvio;
use Psr\Clock\ClockInterface;
use Psr\Log\LoggerInterface;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Contracts\Service\ResetInterface;
use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;

/**
 * `aviso_da_cota_de_envio()`: o aviso da cota de e-mail para a moldura (`layout/_globais/aviso_cota_de_envio.html.twig`,
 * spec comercial-e-emails.md §3.6), ou null quando não há aviso.
 *
 * Só consulta para o Master; para os demais não toca no banco. Calculado na hora pela {@see CotaDeEnvio} (contagens no
 * índice (situacao, enviado_em)), uma vez por requisição. Dispensado na sessão, some até o estado mudar; sem aviso, a
 * dispensa antiga sai da sessão (o próximo episódio volta a aparecer). Uma falha aqui some com o aviso, nunca com a página.
 */
final class AvisoDaCotaDeEnvioExtension extends AbstractExtension implements ResetInterface
{
    private bool $montado = false;

    /** @var array{estado: string, chave: string, enviados: string, teto: string, hora: string, data: string, voltar: string}|null */
    private ?array $aviso = null;

    public function __construct(
        private readonly Security $security,
        private readonly CotaDeEnvio $cota,
        private readonly ClockInterface $relogio,
        private readonly LoggerInterface $logger,
        private readonly RequestStack $requests,
        private readonly UrlGeneratorInterface $urls,
    ) {
    }

    public function getFunctions(): array
    {
        return [new TwigFunction('aviso_da_cota_de_envio', $this->avisoDaCotaDeEnvio(...))];
    }

    /** @return array{estado: string, chave: string, enviados: string, teto: string, hora: string, data: string, voltar: string}|null */
    public function avisoDaCotaDeEnvio(): ?array
    {
        if (!$this->montado) {
            $this->aviso   = $this->security->isGranted('ROLE_SUPER_ADMIN') ? $this->montarSemDerrubar() : null;
            $this->montado = true;
        }

        return $this->aviso;
    }

    public function reset(): void
    {
        $this->montado = false;
        $this->aviso   = null;
    }

    /** @return array{estado: string, chave: string, enviados: string, teto: string, hora: string, data: string, voltar: string}|null */
    private function montarSemDerrubar(): ?array
    {
        try {
            $situacao = $this->cota->situacao();
        } catch (\Throwable $e) {
            $this->logger->error('Aviso da cota de envio não montado: {erro}', ['erro' => $e::class]);

            return null;
        }
        $chave = $situacao->chaveDoEpisodio($this->relogio->now());
        if ($this->dispensado($chave) || $chave === null) {
            return null;
        }

        return [
            'estado'   => (string) $situacao->estado(),
            'chave'    => $chave,
            'enviados' => number_format($situacao->enviados24h, 0, ',', '.'),
            'teto'     => number_format($situacao->teto, 0, ',', '.'),
            'hora'     => $situacao->liberaEm?->format('H:i') ?? '',
            'data'     => $situacao->liberaEm?->format('d/m') ?? '',
            'voltar'   => $this->voltar(),
        ];
    }

    /** A dispensa vale só para o mesmo episódio; sem aviso (chave null), a dispensa antiga é esquecida. */
    private function dispensado(?string $chave): bool
    {
        $request = $this->requests->getMainRequest();
        if ($request === null || !$request->hasSession()) {
            return false;
        }
        $sessao = $request->getSession();
        $dispensada = $sessao->get(AvisoDaCotaDeEnvioController::SESSAO);
        if ($dispensada !== null && $dispensada !== $chave) {
            $sessao->remove(AvisoDaCotaDeEnvioController::SESSAO);
        }

        return $chave !== null && $dispensada === $chave;
    }

    /**
     * Para onde o "Dispensar" volta: a própria página só quando ela veio de um GET (repetir a URL de um POST
     * não reabre a página e pode repetir a ação); fora disso, o início.
     */
    private function voltar(): string
    {
        $request = $this->requests->getMainRequest();

        return $request !== null && $request->isMethod('GET') ? $request->getRequestUri() : $this->urls->generate('homepage');
    }
}
