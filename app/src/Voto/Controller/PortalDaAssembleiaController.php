<?php

declare(strict_types=1);

namespace App\Voto\Controller;

use App\Voto\DTO\SolicitarCodigoDaAssembleiaInput;
use App\Voto\Service\AssembleiaDoPortal;
use App\Voto\UseCase\MontarPortalDaAssembleiaUseCase;
use App\Voto\UseCase\SolicitarCodigoDaAssembleiaUseCase;
use App\Voto\UseCase\ConfirmarCodigoDaAssembleiaUseCase;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\RateLimiter\RateLimiterFactoryInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Validator\Validator\ValidatorInterface;

#[Route('/voto/v/{codigo}', requirements: ['codigo' => '[a-f0-9]{32}'])]
final class PortalDaAssembleiaController extends AbstractController
{
    public function __construct(
        private readonly MontarPortalDaAssembleiaUseCase $montar,
        private readonly AssembleiaDoPortal $acesso,
        private readonly SolicitarCodigoDaAssembleiaUseCase $solicitar,
        private readonly ConfirmarCodigoDaAssembleiaUseCase $confirmar,
        private readonly RateLimiterFactoryInterface $votoPortalEnvioLimiter,
        private readonly ValidatorInterface $validator,
        private readonly RateLimiterFactoryInterface $votoPortalConfirmacaoLimiter,
        private readonly \App\Voto\Service\EnderecoPublicoDaAssembleia $endereco,
    ) {
    }

    #[Route('', name: 'voto_assembleia_portal', methods: ['GET'])]
    public function portal(Request $request, string $codigo): Response
    {
        if ($request->query->getBoolean('presencial')) {
            $request->getSession()->set('voto_terminal_codigo', $codigo);
            $request->getSession()->remove('voto_terminal_token_hash');
            $request->getSession()->remove($this->chave($codigo));
        }

        return $this->pagina($request, $codigo);
    }

    #[Route('/localizar', name: 'voto_assembleia_portal_localizar', methods: ['POST'])]
    public function localizar(Request $request, string $codigo): Response
    {
        $this->acesso->exigir($codigo);
        if (!$this->csrfValido($request, $codigo)) {
            return $this->pagina($request, $codigo, 'A página expirou. Tente novamente.', 400);
        }
        $input = new SolicitarCodigoDaAssembleiaInput(
            trim($request->request->getString('nome')),
            trim($request->request->getString('unidade')),
            trim($request->request->getString('bloco')),
            'localizacao@example.com',
            str_repeat('a', 32),
        );
        if (\count($this->validator->validate($input)) > 0) {
            return $this->pagina($request, $codigo, 'Informe seu nome completo e a unidade conforme a lista da votação.', 422);
        }
        $request->getSession()->set($this->chave($codigo), ['nome' => $input->nome, 'unidade' => $input->unidade, 'bloco' => $input->bloco]);

        return $this->voltar($codigo);
    }

    #[Route('/codigo', name: 'voto_assembleia_portal_solicitar', methods: ['POST'])]
    public function solicitar(Request $request, string $codigo): Response
    {
        $a = $this->acesso->exigir($codigo);
        if (!$this->csrfValido($request, $codigo)) {
            return $this->pagina($request, $codigo, 'A página expirou. Tente novamente.', 400);
        }
        if (!$this->votoPortalEnvioLimiter->create($request->getClientIp())->consume()->isAccepted()) {
            return $this->pagina($request, $codigo, 'Muitas solicitações. Aguarde alguns minutos e tente novamente.', 429);
        }
        $estado = $this->estado($request, $codigo);
        $estado['email'] = mb_strtolower(trim($request->request->getString('email')));
        $estado['desafio'] ??= bin2hex(random_bytes(16));
        $input = $this->input($estado);
        if (\count($this->validator->validate($input)) > 0) {
            return $this->pagina($request, $codigo, 'Confira os dados e informe o e-mail cadastrado na unidade.', 422);
        }
        $this->solicitar->executar($a, $input);
        $request->getSession()->set($this->chave($codigo), $estado);

        return $this->voltar($codigo);
    }

    #[Route('/confirmar', name: 'voto_assembleia_portal_confirmar', methods: ['POST'])]
    public function confirmar(Request $request, string $codigo): Response
    {
        $a = $this->acesso->exigir($codigo);
        if (!$this->csrfValido($request, $codigo)) {
            return $this->pagina($request, $codigo, 'A página expirou. Tente novamente.', 400);
        }
        if (!$this->votoPortalConfirmacaoLimiter->create($request->getClientIp())->consume()->isAccepted()) {
            return $this->pagina($request, $codigo, 'Muitas tentativas. Aguarde alguns minutos e tente novamente.', 429);
        }
        $input = $this->input($this->estado($request, $codigo));
        if (\count($this->validator->validate($input)) > 0) {
            return $this->pagina($request, $codigo, 'Solicite um novo código para continuar.', 422);
        }
        $token = $this->confirmar->executar($a, $input, $request->request->getString('codigo_acesso'));
        if ($token === null) {
            return $this->pagina($request, $codigo, 'Código inválido, expirado ou bloqueado. Confira os dados ou solicite um novo código.', 422);
        }
        $request->getSession()->remove($this->chave($codigo));
        if ($request->getSession()->get('voto_terminal_codigo') === $codigo) {
            $request->getSession()->set('voto_terminal_token_hash', hash('sha256', $token));
        }
        $request->getSession()->migrate(true);

        return $this->protegida($this->redirectToRoute('voto_assembleia_cedula', ['token' => $token], 303));
    }

    #[Route('/reiniciar', name: 'voto_assembleia_portal_reiniciar', methods: ['POST'])]
    public function reiniciar(Request $request, string $codigo): Response
    {
        if (!$this->csrfValido($request, $codigo)) {
            return $this->pagina($request, $codigo, 'A página expirou. Tente novamente.', 400);
        }
        $request->getSession()->remove($this->chave($codigo));
        $request->getSession()->remove('voto_terminal_token_hash');
        $request->getSession()->migrate(true);

        return $this->voltar($codigo);
    }

    #[Route('/sair-terminal', name: 'voto_assembleia_portal_sair_terminal', methods: ['POST'])]
    public function sairTerminal(Request $request, string $codigo): Response
    {
        if (!$this->csrfValido($request, $codigo)) {
            return $this->pagina($request, $codigo, 'A página expirou. Tente novamente.', 400);
        }
        $request->getSession()->remove('voto_terminal_codigo');
        $request->getSession()->remove('voto_terminal_token_hash');
        $request->getSession()->remove($this->chave($codigo));
        $request->getSession()->migrate(true);

        return $this->voltar($codigo);
    }

    private function pagina(Request $request, string $codigo, ?string $erro = null, int $status = 200): Response
    {
        $portal = $this->montar->executar($codigo);
        if ($portal === null) {
            return $this->protegida($this->render('voto/cedula/indisponivel.html.twig', ['limite' => false], new Response('', 404)));
        }

        return $this->protegida($this->render('voto/portal/entrada.html.twig', [
            'hostPublico' => parse_url($this->endereco->gerar($codigo), PHP_URL_HOST),
            'portal' => $portal, 'estado' => $this->estado($request, $codigo), 'erro' => $erro,
        ], new Response('', $status)));
    }

    /** @return array<string, string> */
    private function estado(Request $request, string $codigo): array
    {
        return $request->getSession()->get($this->chave($codigo), []);
    }

    /** @param array<string, string> $estado */
    private function input(array $estado): SolicitarCodigoDaAssembleiaInput
    {
        return new SolicitarCodigoDaAssembleiaInput($estado['nome'] ?? '', $estado['unidade'] ?? '', $estado['bloco'] ?? '', $estado['email'] ?? '', $estado['desafio'] ?? '');
    }

    private function chave(string $codigo): string
    {
        return 'voto_portal_' . $codigo;
    }

    private function csrfValido(Request $request, string $codigo): bool
    {
        return $this->isCsrfTokenValid($this->chave($codigo), $request->request->getString('_token'));
    }

    private function voltar(string $codigo): Response
    {
        return $this->protegida($this->redirectToRoute('voto_assembleia_portal', ['codigo' => $codigo], 303));
    }

    private function protegida(Response $resposta): Response
    {
        $resposta->headers->set('X-Robots-Tag', 'noindex, nofollow');
        $resposta->headers->set('Cache-Control', 'no-store, private');
        $resposta->headers->set('Referrer-Policy', 'no-referrer');

        return $resposta;
    }
}
