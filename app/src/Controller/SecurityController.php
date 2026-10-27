<?php

declare(strict_types=1);

namespace App\Controller;

use App\Auth\Controller\EntrarComGoogleController;
use App\Auth\Infra\FacebookLoginApi;
use App\Auth\Infra\GoogleLoginApi;
use App\Auth\Service\CadastroPeloFacebook;
use App\Auth\Service\CadastroPeloGoogle;
use App\Auth\Service\ContaAConfirmar;
use App\Auth\Service\PaginaDeEntrada;
use App\Auth\Service\PoliticaDeCadastro;
use App\Entity\Auth\User;
use App\Indicacao\Service\CodigoDeIndicacao;
use App\Repository\UserRepository;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\Security\Core\Exception\AuthenticationException;
use Symfony\Component\Security\Core\Exception\CustomUserMessageAccountStatusException;
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
    public function login(AuthenticationUtils $authenticationUtils, PaginaDeEntrada $paginaDeEntrada, Request $request, UserRepository $userRepository, ContaAConfirmar $contaAConfirmar, CadastroPeloGoogle $cadastroPeloGoogle, GoogleLoginApi $googleLogin, FacebookLoginApi $facebookLogin, CadastroPeloFacebook $cadastroPeloFacebook, PoliticaDeCadastro $politicaDeCadastro): Response
    {
        $logado = $this->getUser();
        $usuario = $logado instanceof User ? $logado : null;
        if ($logado !== null && !PaginaDeEntrada::ehMaster($usuario)) {
            return $this->redirectToRoute('homepage');
        }

        $erro = $authenticationUtils->getLastAuthenticationError();
        // Política de cadastro do Master (spec login-config-cadastro): fora de `on`, a tela não oferece "Criar conta".
        $cadastro = $politicaDeCadastro->atual();

        return $this->render('security/login.html.twig', [
            'last_username' => $authenticationUtils->getLastUsername(),
            'error' => $erro,
            'a_confirmar' => $this->avisoDeContaAConfirmar($request, $erro, $userRepository, $contaAConfirmar),
            'pagina' => $paginaDeEntrada->montar($usuario),
            'cadastro' => $cadastro,
            'abrir' => $this->passoParaAbrir($request),
            // Cupom de indicação na URL (desenho 10 VS 25.10.26): o JS abre o "Criar conta" com ele preenchido.
            'cupom' => self::cupomDaUrl($request),
            'frase' => self::FRASES[intdiv(time(), 86400) % count(self::FRASES)],
            // Entrar com o Google (spec entrar-com-google, decisão do dono de 24/10): sem o cliente OAuth no servidor, a
            // tela não mostra nenhum botão do Google (nem o do cartão nem o do "Criar conta"). A URL aberta direto ainda
            // volta com o aviso de "não configurado", no cartão (`login`) ou no "Criar conta" (`cadastro`).
            'googleDisponivel' => $googleLogin->ligado(),
            'avisoGoogle' => $request->hasSession() ? EntrarComGoogleController::avisoNaoConfigurado($request->getSession()) : null,
            // Continuar com Facebook (spec login-cupom-e-facebook, PR B): sem app da Meta, o botão some (e a rota dá 404).
            'facebookDisponivel' => $facebookLogin->ligado(),
            // Criar conta conectado por um provedor (Google na entrega 2, Facebook no PR B): a identidade que espera o
            // cadastro, só para o anônimo. No Google o e-mail provado esconde o campo; no Facebook o campo segue visível,
            // com o e-mail só como sugestão.
            'cadastroSocial' => $usuario === null && $request->hasSession() ? $this->cadastroSocial($request, $cadastroPeloGoogle, $cadastroPeloFacebook) : null,
        ]);
    }

    /**
     * A identidade que espera o "Criar conta", do Google ou do Facebook (só uma por vez; o Google vence se houver as duas).
     *
     * @return array{chave: string, provedor: string, nome: string, email: string, emailVisivel: bool}|null
     */
    private function cadastroSocial(Request $request, CadastroPeloGoogle $google, CadastroPeloFacebook $facebook): ?array
    {
        $doGoogle = $google->ler($request->getSession());
        if ($doGoogle !== null) {
            return ['chave' => 'google', 'provedor' => 'Google', 'nome' => $doGoogle->nome, 'email' => $doGoogle->email, 'emailVisivel' => false];
        }
        $doFacebook = $facebook->ler($request->getSession());
        if ($doFacebook !== null) {
            return ['chave' => 'facebook', 'provedor' => 'Facebook', 'nome' => $doFacebook->nome, 'email' => (string) $doFacebook->email, 'emailVisivel' => true];
        }

        return null;
    }

    /**
     * O aviso "Confirme o seu e-mail para entrar..." com o botão de reenviar (spec `cadastro-exige-confirmacao.md`),
     * quando a sessão guarda uma conta ainda a confirmar e o erro da tela, se houver, é essa mesma recusa. Outro erro
     * (senha errada de outra conta, por exemplo) não leva o botão. Conta que já confirmou sai da sessão.
     */
    private function avisoDeContaAConfirmar(Request $request, ?AuthenticationException $erro, UserRepository $userRepository, ContaAConfirmar $contaAConfirmar): ?string
    {
        if (!$request->hasSession() || ($erro !== null && !$erro instanceof CustomUserMessageAccountStatusException)) {
            return null;
        }

        // Marcador neutro do cadastro de um e-mail que já tem conta: o mesmo aviso da conta nova, com o e-mail digitado.
        $neutro = $contaAConfirmar->neutroNaSessao($request->getSession());
        if ($neutro !== null) {
            return ContaAConfirmar::mensagemDeLogin($neutro['email']);
        }

        $id = $contaAConfirmar->idNaSessao($request->getSession());
        $conta = $id === null ? null : $userRepository->find($id);
        if (!$conta instanceof User || $conta->isEmailConfirmado()) {
            ContaAConfirmar::esquecer($request->getSession());

            return null;
        }

        return ContaAConfirmar::mensagemDeLogin((string) $conta->getEmail());
    }

    /** `?ind=`, `?codigo=` ou `?ref=` no formato do cupom, como `BJ-` + código; vazio fora do formato. */
    private static function cupomDaUrl(Request $request): string
    {
        $query = $request->query->all();
        foreach (['ind', 'codigo', 'ref'] as $chave) {
            $codigo = CodigoDeIndicacao::doCupom($query[$chave] ?? null);
            if ($codigo !== null) {
                return 'BJ-' . $codigo;
            }
        }

        return '';
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
