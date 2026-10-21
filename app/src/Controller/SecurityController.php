<?php

declare(strict_types=1);

namespace App\Controller;

use App\Auth\Infra\GoogleLoginApi;
use App\Auth\Service\PaginaDeEntrada;
use App\Entity\Auth\User;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\Security\Http\Authentication\AuthenticationUtils;

final class SecurityController extends AbstractController
{
    /** As quatro frases da tarja do desenho 10; o servidor escolhe a do dia. */
    public const FRASES = [
        'Organização hoje, segurança para os próximos anos do seu escritório.',
        'Cada pasta no lugar certo, cada prazo no seu tempo.',
        'Confiança se constrói com organização, todos os dias.',
        'Seus dados protegidos hoje e daqui a dez anos.',
    ];

    /** Passos do modal que a tela pode abrir ao carregar (`?abrir=`). */
    private const PASSOS_ABRIVEIS = ['senha', 'cadastro'];

    /**
     * Tela de entrada (desenho 10, spec `login-desenho-10.md`). Anônimo vê a página; o Master logado também,
     * com as ferramentas SÓ MASTER; qualquer outro logado segue para o sistema.
     */
    #[Route(path: '/login', name: 'app_login')]
    public function login(AuthenticationUtils $authenticationUtils, PaginaDeEntrada $paginaDeEntrada, Request $request, GoogleLoginApi $googleLogin): Response
    {
        $logado = $this->getUser();
        $usuario = $logado instanceof User ? $logado : null;
        if ($logado !== null && !PaginaDeEntrada::ehMaster($usuario)) {
            return $this->redirectToRoute('homepage');
        }

        return $this->render('security/login.html.twig', [
            'last_username' => $authenticationUtils->getLastUsername(),
            'error' => $authenticationUtils->getLastAuthenticationError(),
            'pagina' => $paginaDeEntrada->montar($usuario),
            'abrir' => $this->passoParaAbrir($request),
            'frase' => self::FRASES[intdiv(time(), 86400) % count(self::FRASES)],
            // Entrar com o Google (spec entrar-com-google): sem o cliente OAuth no servidor, o botão não aparece.
            'googleLigado' => $googleLogin->ligado(),
        ]);
    }

    private function passoParaAbrir(Request $request): ?string
    {
        // `all()` e não `get()`: `?abrir[]=x` faria o `get()` lançar e a tela de entrada cair com 400.
        $abrir = $request->query->all()['abrir'] ?? null;

        return is_string($abrir) && in_array($abrir, self::PASSOS_ABRIVEIS, true) ? $abrir : null;
    }

    #[Route(path: '/logout', name: 'app_logout')]
    public function logout(): void
    {
        // Symfony cuida do logout automaticamente
        throw new \LogicException('Este método pode ficar vazio - o Symfony intercepta a rota de logout.');
    }
}
