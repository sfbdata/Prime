<?php

declare(strict_types=1);

namespace App\Comercial\Controller;

use App\Comercial\DTO\ConfirmacaoRealizada;
use App\Comercial\Service\ConfirmacaoDeEndereco;
use App\Comercial\Service\RotulosDoConsentimento;
use App\Shared\Email\Descadastro\DescadastroDeEmailInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * O link do e-mail de confirmação de endereço (double opt-in, spec `comercial-e-emails.md` §3.3 e §3.9).
 *
 * Rota PÚBLICA (PUBLIC_ACCESS de `^/e/confirmar/` no security.yaml): quem clica pode não ter conta.
 *   - GET NÃO grava: só mostra o botão "Confirmar". Os leitores de link do Outlook e afins abrem o GET e não podem
 *     confirmar ninguém sozinhos (I9);
 *   - POST na mesma URL, com CSRF próprio do token, confirma com o IP e o agente da requisição.
 * Nenhuma página revela o endereço: nem o GET, nem o resultado.
 */
final class ConfirmacaoDeEnderecoController extends AbstractController
{
    public const ROTA_GRAVAR = 'comercial_confirmar_endereco_gravar';
    private const CAMINHO = '/e/confirmar/{token}';
    private const TOKEN = '[0-9A-Za-z]{1,128}';

    public function __construct(
        private readonly ConfirmacaoDeEndereco $confirmacao,
        private readonly DescadastroDeEmailInterface $descadastro,
    ) {
    }

    #[Route(self::CAMINHO, name: ConfirmacaoDeEndereco::ROTA, requirements: ['token' => self::TOKEN], methods: ['GET'])]
    public function mostrar(string $token): Response
    {
        return $this->pagina('comercial/confirmacao/confirmar.html.twig', ['token' => $token, 'csrfInvalido' => false]);
    }

    #[Route(self::CAMINHO, name: self::ROTA_GRAVAR, requirements: ['token' => self::TOKEN], methods: ['POST'])]
    public function gravar(string $token, Request $request): Response
    {
        if (!$this->isCsrfTokenValid(self::idDoCsrf($token), (string) $request->request->get('_token'))) {
            return $this->pagina('comercial/confirmacao/confirmar.html.twig', ['token' => $token, 'csrfInvalido' => true], Response::HTTP_BAD_REQUEST);
        }

        $agente = $request->headers->get('User-Agent');
        $feita = $this->confirmacao->confirmarComDetalhes($token, (string) $request->getClientIp(), $agente === null ? null : mb_substr($agente, 0, 255));
        if ($feita === null) {
            return $this->pagina('comercial/confirmacao/vencido.html.twig', []);
        }

        return $this->pagina('comercial/confirmacao/confirmado.html.twig', [
            'nomeDaLista' => RotulosDoConsentimento::daLista($feita->lista),
            'linkDeDescadastro' => $this->linkDeDescadastro($feita),
        ]);
    }

    /** O id do CSRF é por token: o formulário de um link não serve para outro. */
    public static function idDoCsrf(string $token): string
    {
        return 'comercial_confirmar_endereco_' . $token;
    }

    private function linkDeDescadastro(ConfirmacaoRealizada $feita): string
    {
        return $this->descadastro->gerarLink($feita->escopo, $feita->email, $feita->lista);
    }

    /** @param array<string, mixed> $contexto */
    private function pagina(string $template, array $contexto, int $status = Response::HTTP_OK): Response
    {
        $resposta = $this->render($template, $contexto, new Response(status: $status));
        $resposta->headers->set('X-Robots-Tag', 'noindex');
        $resposta->headers->set('Referrer-Policy', 'no-referrer');

        return $resposta;
    }
}
