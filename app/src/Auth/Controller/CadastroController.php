<?php

declare(strict_types=1);

namespace App\Auth\Controller;

use App\Auth\DTO\CriarContaEEscritorioInput;
use App\Auth\DTO\CriarSenhaPeloLinkInput;
use App\Auth\Enum\SituacaoDoLinkDeConfirmacao;
use App\Auth\Exception\CadastroNaoDisponivel;
use App\Auth\Service\CadastroPeloFacebook;
use App\Auth\Service\ContaAConfirmar;
use App\Auth\Service\LinkDeConfirmacaoDeEmail;
use App\Auth\Service\PaginaDeEntrada;
use App\Auth\UseCase\ConfirmarCadastroUseCase;
use App\Auth\UseCase\CriarContaEEscritorioUseCase;
use App\Auth\UseCase\CriarSenhaPeloLinkUseCase;
use App\Indicacao\Service\CodigoDeIndicacao;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\RateLimiter\RateLimiterFactoryInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Cadastro público do desenho 10: o formulário mora na tela de entrada (passo "Criar conta e escritório"); aqui
 * ficam o POST em JSON que cria a conta e o link de confirmação do e-mail. A conta nasce sem login: só entra depois
 * de confirmar o e-mail (spec `cadastro-exige-confirmacao.md`), e a senha é criada no próprio link (spec
 * `cadastro-senha-no-link.md`): o GET do link só mostra o formulário, o POST cria a senha e confirma. O
 * `/cadastro/confirmar/{token}` antigo continua para os cadastros pendentes já enviados (valem 24 h).
 */
final class CadastroController extends AbstractController
{
    /** Intenção do CSRF do "Crie sua senha" do link. */
    public const CSRF_CRIAR_SENHA = 'cadastro_criar_senha';

    private const SESSAO_EXPIRADA = 'Sessão expirada. Recarregue a página.';
    private const TELA_DO_LINK = 'auth/cadastro/confirmar_email.html.twig';

    public function __construct(
        private readonly CriarContaEEscritorioUseCase $criarConta,
        private readonly LinkDeConfirmacaoDeEmail $linkDeConfirmacao,
        private readonly CriarSenhaPeloLinkUseCase $criarSenha,
        private readonly ConfirmarCadastroUseCase $confirmarCadastro,
        private readonly RateLimiterFactoryInterface $cadastroAutoLimiter,
        private readonly ContaAConfirmar $contaAConfirmar,
        private readonly PaginaDeEntrada $paginaDeEntrada,
        private readonly TranslatorInterface $tradutor,
    ) {}

    #[Route('/cadastro', name: 'auth_cadastro', methods: ['GET'])]
    public function cadastrar(Request $request): Response
    {
        if ($this->getUser() !== null) {
            return $this->redirectToRoute('tenant_selecionar');
        }
        // O cupom do link curto segue para o "Criar conta" (desenho 10 VS 25.10.26); `all()` para `?ind[]=` não lançar.
        $cupom = CodigoDeIndicacao::doCupom($request->query->all()['ind'] ?? null);

        return $this->redirectToRoute('app_login', ['abrir' => 'cadastro'] + ($cupom !== null ? ['ind' => $cupom] : []));
    }

    #[Route('/cadastro/criar', name: 'auth_cadastro_criar', methods: ['POST'])]
    public function criar(Request $request): JsonResponse
    {
        if ($this->getUser() !== null) {
            return $this->json(['erro' => 'Saia da sua conta para criar outra.'], Response::HTTP_FORBIDDEN);
        }

        $dados = self::lerJson($request);

        if (!$this->isCsrfTokenValid('cadastro_criar', self::texto($dados, '_token'))) {
            return $this->json(['erro' => 'Sessão expirada. Recarregue a página.'], Response::HTTP_FORBIDDEN);
        }

        // Depois do CSRF: um POST forjado não gasta a cota de quem divide o IP.
        if (!$this->cadastroAutoLimiter->create((string) $request->getClientIp())->consume()->isAccepted()) {
            return $this->json(['erro' => 'Muitas tentativas. Tente de novo mais tarde.'], Response::HTTP_TOO_MANY_REQUESTS);
        }

        $input = self::input($dados);
        try {
            $conta = $this->criarConta->executar($input, (string) $request->getClientIp(), $request->headers->get('User-Agent'));
        } catch (CadastroNaoDisponivel $e) {
            // Política do Master: 403 com o texto da política, sem dizer nada sobre o e-mail (spec login-config-cadastro).
            return $this->json(['erro' => $this->tradutor->trans($e->chaveDeTraducao())], Response::HTTP_FORBIDDEN);
        } catch (\InvalidArgumentException | \DomainException $e) {
            return $this->json(['erro' => $e->getMessage()], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        // Sem login: a tela mostra "Confirme seu e-mail". A sessão guarda a conta para o botão de reenviar o link.
        // E-mail que já tinha conta (null): a mesma resposta, e na sessão o marcador neutro no lugar da conta de outra
        // pessoa (achado de auditoria interna F14). Ele também substitui a chave de um cadastro anterior deste navegador.
        $email = CriarContaEEscritorioUseCase::normalizarEmail($input->email);
        if ($conta !== null) {
            $this->contaAConfirmar->guardar($request->getSession(), $conta->user);
        } else {
            $this->contaAConfirmar->guardarNeutro($request->getSession(), $email);
        }

        return $this->json(['ok' => true, 'confirmar' => true, 'email' => $email]);
    }

    /** O link do e-mail: só olha. Com o e-mail a confirmar, mostra "Crie sua senha"; não confirma nada. */
    #[Route('/cadastro/confirmar-email/{token}', name: 'auth_cadastro_confirmar_email', requirements: ['token' => '[a-f0-9]{64}'], methods: ['GET'])]
    public function confirmarEmail(string $token): Response
    {
        try {
            $situacao = $this->linkDeConfirmacao->situacao($token);
        } catch (\DomainException $e) {
            return $this->telaDoLink(['motivo' => $e->getMessage()]);
        }

        return $this->telaDoLink(['situacao' => $situacao, 'token' => $token]);
    }

    /**
     * "Criar senha e confirmar": a caixa "Li e aceito" é obrigatória e grava um aceite novo (D2 da spec). Erro de
     * aceite ou de senha volta ao formulário sem consumir o link. Não faz login.
     */
    #[Route('/cadastro/confirmar-email/{token}', name: 'auth_cadastro_criar_senha', requirements: ['token' => '[a-f0-9]{64}'], methods: ['POST'])]
    public function criarSenha(Request $request, string $token): Response
    {
        if (!$this->isCsrfTokenValid(self::CSRF_CRIAR_SENHA, (string) $request->request->get('_token'))) {
            return $this->csrfInvalido($token);
        }

        $input = new CriarSenhaPeloLinkInput(
            token: $token,
            senha: (string) $request->request->get('senha'),
            confirmacao: (string) $request->request->get('confirmacao'),
            aceite: $request->request->getBoolean('aceite'),
            ip: (string) $request->getClientIp(),
            userAgent: $request->headers->get('User-Agent'),
        );

        try {
            $situacao = $this->criarSenha->executar($input, $this->criadaNestaSessaoPeloFacebook($request, $token));
        } catch (\DomainException $e) {
            return $this->telaDoLink(['motivo' => $e->getMessage()]);
        } catch (\InvalidArgumentException $e) {
            return $this->telaDoLink(['situacao' => SituacaoDoLinkDeConfirmacao::Pendente, 'token' => $token, 'erro' => $e->getMessage()], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        return $this->telaDoLink(['situacao' => $situacao]);
    }

    /**
     * O link foi aberto na mesma sessão que criou, pelo Facebook, a conta do token? Só então o `facebook_id` dela
     * sobrevive à confirmação (pré-sequestro, spec login-cupom-e-facebook). Qualquer dúvida: não.
     */
    private function criadaNestaSessaoPeloFacebook(Request $request, string $token): bool
    {
        $marcada = CadastroPeloFacebook::contaDaSessao($request->getSession());
        if ($marcada === null) {
            return false;
        }
        try {
            return $this->linkDeConfirmacao->pendente($token)?->getUser()->getId() === $marcada;
        } catch (\DomainException) {
            return false;
        }
    }

    /** CSRF inválido: nada é gravado, e a tela mostra a situação real do link (o formulário só se ele ainda vale). */
    private function csrfInvalido(string $token): Response
    {
        try {
            $situacao = $this->linkDeConfirmacao->situacao($token);
        } catch (\DomainException $e) {
            return $this->telaDoLink(['motivo' => $e->getMessage()], Response::HTTP_FORBIDDEN);
        }
        if ($situacao !== SituacaoDoLinkDeConfirmacao::Pendente) {
            return $this->telaDoLink(['situacao' => $situacao], Response::HTTP_FORBIDDEN);
        }

        return $this->telaDoLink(['situacao' => $situacao, 'token' => $token, 'erro' => self::SESSAO_EXPIRADA], Response::HTTP_FORBIDDEN);
    }

    #[Route('/cadastro/confirmar/{token}', name: 'auth_cadastro_confirmar', methods: ['GET'])]
    public function confirmar(string $token): Response
    {
        // Usuário já logado não confirma cadastro novo (evita criar conta alheia / queimar token de terceiro).
        if ($this->getUser() !== null) {
            $this->addFlash('warning', 'Saia da sua conta atual para confirmar um novo cadastro.');

            return $this->redirectToRoute('tenant_selecionar');
        }

        try {
            $this->confirmarCadastro->executar($token);
            $this->addFlash('success', 'Cadastro confirmado! Faça login para entrar no seu escritório.');

            return $this->redirectToRoute('app_login');
        } catch (\DomainException $e) {
            return $this->render('auth/cadastro/erro.html.twig', ['motivo' => $e->getMessage()]);
        }
    }

    /**
     * A página do link leva o token na URL: sem Referer para fora e sem cópia em cache.
     *
     * @param array<string, mixed> $dados
     */
    private function telaDoLink(array $dados, int $status = Response::HTTP_OK): Response
    {
        $dados += ['motivo' => null, 'situacao' => null, 'token' => null, 'erro' => null];
        // Com o formulário, a caixa "Li e aceito" leva aos mesmos Termos e Política da tela de entrada.
        $dados['pagina'] = $dados['token'] !== null ? $this->paginaDeEntrada->montar(null) : null;
        $resposta = $this->render(self::TELA_DO_LINK, $dados);
        $resposta->setStatusCode($status);
        $resposta->headers->set('Referrer-Policy', 'no-referrer');
        $resposta->headers->set('Cache-Control', 'no-store, private');

        return $resposta;
    }

    /** @return array<string, mixed> */
    private static function lerJson(Request $request): array
    {
        try {
            return $request->toArray();
        } catch (\Throwable) {
            return [];
        }
    }

    /** @param array<string, mixed> $dados */
    private static function input(array $dados): CriarContaEEscritorioInput
    {
        return new CriarContaEEscritorioInput(
            nome: self::texto($dados, 'nome'),
            escritorio: self::texto($dados, 'escritorio'),
            email: self::texto($dados, 'email'),
            senha: self::texto($dados, 'senha'),
            aceite: in_array($dados['aceite'] ?? false, [true, 1, '1', 'true', 'on'], true),
            genero: self::texto($dados, 'genero'),
        );
    }

    /** @param array<string, mixed> $dados */
    private static function texto(array $dados, string $chave): string
    {
        $valor = $dados[$chave] ?? '';

        return is_string($valor) ? $valor : '';
    }
}
