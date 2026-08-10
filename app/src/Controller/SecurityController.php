<?php

namespace App\Controller;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\Security\Http\Authentication\AuthenticationUtils;

class SecurityController extends AbstractController
{
    #[Route(path: '/login', name: 'app_login')]
    public function login(AuthenticationUtils $authenticationUtils): Response
    {
        // Se já estiver logado, redireciona para a homepage
        if ($this->getUser()) {
            return $this->redirectToRoute('homepage'); // ajuste o nome da rota conforme sua aplicação
        }

        // pega o último erro de login, se houver
        $error = $authenticationUtils->getLastAuthenticationError();
        // pega o último email digitado
        $lastUsername = $authenticationUtils->getLastUsername();

        return $this->render('security/login.html.twig', [
            'last_username' => $lastUsername,
            'error' => $error,
            // Formulário externo de suporte: quem não consegue logar não alcança o
            // ServiceDesk interno. Vazio = o botão aparece, mas não leva a lugar nenhum.
            'suporte_form_url' => $this->suporteFormUrl(),
        ]);
    }

    /**
     * Endereço do formulário externo de suporte, ou string vazia.
     *
     * Só http(s) passa: o valor vira o `href` de um link na única página que qualquer
     * anônimo alcança, e `javascript:` ali seria execução a um clique de distância.
     * Endereço mal configurado devolve o botão ao estado inerte — nunca derruba o login.
     */
    private function suporteFormUrl(): string
    {
        $url = trim((string) $this->getParameter('suporte_form_url'));

        if ($url === '' || !filter_var($url, FILTER_VALIDATE_URL)) {
            return '';
        }

        return in_array(strtolower((string) parse_url($url, PHP_URL_SCHEME)), ['http', 'https'], true)
            ? $url
            : '';
    }

    #[Route(path: '/logout', name: 'app_logout')]
    public function logout(): void
    {
        // Symfony cuida do logout automaticamente
        throw new \LogicException('Este método pode ficar vazio - o Symfony intercepta a rota de logout.');
    }
}
