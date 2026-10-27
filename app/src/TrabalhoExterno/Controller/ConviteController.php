<?php

declare(strict_types=1);

namespace App\TrabalhoExterno\Controller;

use App\Auth\Exception\CadastroNaoDisponivel;
use App\Entity\Auth\User;
use App\Security\UserAuthenticator;
use App\TrabalhoExterno\Exception\DadosInvalidos;
use App\TrabalhoExterno\Exception\DemandaNaoEncontrada;
use App\TrabalhoExterno\Exception\TransicaoInvalida;
use App\TrabalhoExterno\UseCase\AbrirConviteUseCase;
use App\TrabalhoExterno\UseCase\AceitarConviteUseCase;
use App\TrabalhoExterno\UseCase\CriarContaDeConvidadoUseCase;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\TooManyRequestsHttpException;
use Symfony\Component\RateLimiter\RateLimiterFactory;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * O link do convite do Trabalho Externo (spec §8). Público (`access_control`), fora das guardas de
 * termo e de escritório: quem chega aqui pode não ter conta, nem escritório.
 *
 * O token não abre a demanda sozinho: aceitar exige estar logado com o e-mail convidado, e criar
 * conta só serve ao e-mail do convite. Depois do aceite, a pessoa vai para a demanda.
 */
#[Route('/trabalho-externo/convite/{token}', requirements: ['token' => '[a-f0-9]{64}'])]
final class ConviteController extends AbstractController
{
    public function __construct(
        private readonly AbrirConviteUseCase $abrir,
        private readonly TranslatorInterface $tradutor,
    ) {
    }

    #[Route('', name: 'trabalho_externo_convite', methods: ['GET'])]
    public function ver(string $token): Response
    {
        return $this->pagina($token);
    }

    #[Route('/criar-conta', name: 'trabalho_externo_convite_criar_conta', methods: ['POST'])]
    public function criarConta(
        Request $request,
        string $token,
        CriarContaDeConvidadoUseCase $criar,
        AceitarConviteUseCase $aceitar,
        Security $security,
        RateLimiterFactory $conviteAceiteLimiter,
    ): Response {
        if (!$conviteAceiteLimiter->create($request->getClientIp())->consume()->isAccepted()) {
            throw new TooManyRequestsHttpException();
        }
        if (!$this->isCsrfTokenValid('te_convite_' . $token, (string) $request->request->get('_token'))) {
            throw $this->createAccessDeniedException('Token CSRF inválido.');
        }
        if ($this->getUser() !== null) {
            return $this->redirectToRoute('trabalho_externo_convite', ['token' => $token]);
        }

        try {
            $usuario = $criar->executar(
                $token,
                (string) $request->request->get('full_name', ''),
                (string) $request->request->get('senha', ''),
                $request->request->getBoolean('aceite_termos'),
                $request->getClientIp(),
                $request->headers->get('User-Agent'),
                (string) $request->request->get('genero', ''),
            );
        } catch (CadastroNaoDisponivel|DadosInvalidos|DemandaNaoEncontrada $e) {
            return $this->pagina($token, $this->mensagemDaRecusa($e), (string) $request->request->get('full_name', ''), (string) $request->request->get('genero', ''));
        }

        // Entra direto: a resposta do autenticador (que mandaria escolher escritório) é ignorada,
        // a pessoa vai para a demanda do convite.
        $security->login($usuario, UserAuthenticator::class, 'main');

        return $this->paraADemanda($aceitar, $token, $usuario);
    }

    #[Route('/aceitar', name: 'trabalho_externo_convite_aceitar', methods: ['POST'])]
    public function aceitar(Request $request, string $token, AceitarConviteUseCase $aceitar): Response
    {
        $usuario = $this->getUser();
        if (!$usuario instanceof User) {
            return $this->redirectToRoute('trabalho_externo_convite', ['token' => $token]);
        }
        if (!$this->isCsrfTokenValid('te_convite_' . $token, (string) $request->request->get('_token'))) {
            throw $this->createAccessDeniedException('Token CSRF inválido.');
        }

        return $this->paraADemanda($aceitar, $token, $usuario);
    }

    /** Cadastro fechado pelo Master (spec login-config-cadastro): o texto da política; as demais recusas trazem o próprio. */
    private function mensagemDaRecusa(\Throwable $e): string
    {
        return $e instanceof CadastroNaoDisponivel ? $this->tradutor->trans($e->chaveDeTraducao()) : $e->getMessage();
    }

    private function paraADemanda(AceitarConviteUseCase $aceitar, string $token, User $usuario): Response
    {
        try {
            $convite = $aceitar->executar($token, $usuario);
        } catch (TransicaoInvalida|DemandaNaoEncontrada $e) {
            return $this->pagina($token, $e->getMessage());
        }

        return $this->redirectToRoute('trabalho_externo_index', ['te' => $convite->getDemanda()->getCodigo()]);
    }

    /**
     * A página do convite. Com erro, ela é desenhada de novo NA MESMA resposta (422), com a
     * mensagem dentro do formulário, o nome digitado e o gênero escolhido: um aviso de sessão seria consumido pelo
     * layout antes do formulário e a pessoa veria a página sem saber o que errou.
     */
    private function pagina(string $token, ?string $erro = null, ?string $nomeDigitado = null, ?string $generoDigitado = null): Response
    {
        $usuario = $this->getUser();
        $pagina = $this->abrir->executar($token, $usuario instanceof User ? $usuario : null);
        if ($nomeDigitado !== null && $nomeDigitado !== '' && is_array($pagina['convite'])) {
            $pagina['convite']['nome'] = $nomeDigitado;
        }

        return $this->render('trabalho_externo/convite.html.twig', $pagina + ['token' => $token, 'erro' => $erro, 'generoDigitado' => $generoDigitado], new Response(
            '',
            $erro !== null ? Response::HTTP_UNPROCESSABLE_ENTITY : Response::HTTP_OK,
        ));
    }
}
