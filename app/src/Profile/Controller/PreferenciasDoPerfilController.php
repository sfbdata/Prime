<?php

declare(strict_types=1);

namespace App\Profile\Controller;

use App\Entity\Auth\User;
use App\Profile\EventListener\FormatoDaRequisicaoListener;
use App\Profile\EventListener\IdiomaDaRequisicaoListener;
use App\Profile\Exception\IdiomaIndisponivelException;
use App\Profile\Exception\OpcaoAvancadaInvalidaException;
use App\Profile\UseCase\ConfirmarRevisaoDoCadastroUseCase;
use App\Profile\UseCase\DefinirIdiomaDoPerfilUseCase;
use App\Profile\UseCase\ObterOuCriarPerfilUseCase;
use App\Profile\UseCase\SalvarOpcoesAvancadasUseCase;
use App\Profile\UseCase\SalvarPreferenciaDoPerfilUseCase;
use App\Service\Tenant\TenantContext;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Card "Preferências" e revisão mensal do Meu Perfil (desenho "07 - Meu Perfil", VS 18.10.26).
 * Respostas JSON chamadas pelo JS da página; o CSRF vem no header X-CSRF-Token (token 'ajax' do base).
 */
#[Route('/perfil', name: 'app_profile')]
final class PreferenciasDoPerfilController extends AbstractController
{
    public function __construct(
        private readonly SalvarPreferenciaDoPerfilUseCase $salvarPreferencia,
        private readonly ConfirmarRevisaoDoCadastroUseCase $confirmarRevisao,
        private readonly ObterOuCriarPerfilUseCase $obterOuCriarPerfil,
        private readonly TenantContext $tenantContext,
        private readonly DefinirIdiomaDoPerfilUseCase $definirIdioma,
        private readonly SalvarOpcoesAvancadasUseCase $salvarOpcoesAvancadas,
        private readonly TranslatorInterface $tradutor,
    ) {
    }

    #[Route('/preferencia', name: '_preferencia', methods: ['POST'])]
    public function salvarPreferencia(Request $request): JsonResponse
    {
        if (!$this->isCsrfTokenValid('ajax', (string) $request->headers->get('X-CSRF-Token'))) {
            return new JsonResponse(['erro' => 'Token inválido.'], Response::HTTP_FORBIDDEN);
        }
        $tenant = $this->tenantContext->getCurrentTenant();
        if ($tenant === null) {
            return new JsonResponse(['erro' => 'Escolha um escritório para gravar suas preferências.'], Response::HTTP_CONFLICT);
        }

        $dados = json_decode($request->getContent(), true);
        if (!is_array($dados) || !is_string($dados['chave'] ?? null) || !is_bool($dados['ligado'] ?? null)) {
            return new JsonResponse(['erro' => 'Pedido inválido.'], Response::HTTP_BAD_REQUEST);
        }

        /** @var User $user */
        $user = $this->getUser();
        try {
            $this->salvarPreferencia->executar($tenant, $user, $dados['chave'], $dados['ligado']);
        } catch (\InvalidArgumentException $e) {
            return new JsonResponse(['erro' => $e->getMessage()], Response::HTTP_BAD_REQUEST);
        }

        return new JsonResponse(['ok' => true, 'chave' => $dados['chave'], 'ligado' => $dados['ligado']]);
    }

    /**
     * "Idioma do sistema" (desenho 07; spec idioma-do-sistema, entrega 1): grava no perfil e na sessão; a página
     * recarrega no idioma novo. Vale só para a pessoa, com ou sem escritório escolhido.
     */
    #[Route('/idioma', name: '_idioma', methods: ['POST'])]
    public function definirIdioma(Request $request): JsonResponse
    {
        if (!$this->isCsrfTokenValid('ajax', (string) $request->headers->get('X-CSRF-Token'))) {
            return new JsonResponse(['erro' => 'Token inválido.'], Response::HTTP_FORBIDDEN);
        }
        $dados = json_decode($request->getContent(), true);
        if (!is_array($dados) || !is_string($dados['idioma'] ?? null)) {
            return new JsonResponse(['erro' => 'Pedido inválido.'], Response::HTTP_BAD_REQUEST);
        }

        /** @var User $user */
        $user = $this->getUser();
        try {
            $idioma = $this->definirIdioma->executar($user, $dados['idioma']);
        } catch (IdiomaIndisponivelException $e) {
            return new JsonResponse(['erro' => $e->getMessage()], Response::HTTP_UNPROCESSABLE_ENTITY);
        }
        IdiomaDaRequisicaoListener::lembrar($request, $user, $idioma);

        return new JsonResponse(['ok' => true, 'idioma' => $idioma->value]);
    }

    /**
     * "Opções avançadas" (spec sistema-em-ingles, §2): idioma, fuso horário, formato de data e de números, gravados de
     * uma vez no perfil e na sessão; a página recarrega com as escolhas. Vale só para a pessoa, com ou sem escritório.
     * Vazio no fuso e nos formatos = o padrão.
     */
    #[Route('/opcoes-avancadas', name: '_opcoes_avancadas', methods: ['POST'])]
    public function salvarOpcoesAvancadas(Request $request): JsonResponse
    {
        if (!$this->isCsrfTokenValid('ajax', (string) $request->headers->get('X-CSRF-Token'))) {
            return new JsonResponse(['erro' => $this->tradutor->trans('opcoes_avancadas.erro_token')], Response::HTTP_FORBIDDEN);
        }
        $dados = json_decode($request->getContent(), true);
        if (!is_array($dados) || !is_string($dados['idioma'] ?? null) || !self::textoOuNulo($dados, 'fuso') || !self::textoOuNulo($dados, 'formatoData') || !self::textoOuNulo($dados, 'formatoNumero')) {
            return new JsonResponse(['erro' => $this->tradutor->trans('opcoes_avancadas.erro_pedido')], Response::HTTP_BAD_REQUEST);
        }

        /** @var User $user */
        $user = $this->getUser();
        try {
            $perfil = $this->salvarOpcoesAvancadas->executar($user, $dados['idioma'], $dados['fuso'] ?? null, $dados['formatoData'] ?? null, $dados['formatoNumero'] ?? null);
        } catch (IdiomaIndisponivelException) {
            return new JsonResponse(['erro' => $this->tradutor->trans('opcoes_avancadas.erro_idioma')], Response::HTTP_UNPROCESSABLE_ENTITY);
        } catch (OpcaoAvancadaInvalidaException $e) {
            return new JsonResponse(['erro' => $this->tradutor->trans($e->getMessage())], Response::HTTP_UNPROCESSABLE_ENTITY);
        }
        IdiomaDaRequisicaoListener::lembrar($request, $user, $perfil->getIdioma());
        FormatoDaRequisicaoListener::lembrar($request, $user, $perfil);

        return new JsonResponse(['ok' => true]);
    }

    /** @param array<mixed> $dados */
    private static function textoOuNulo(array $dados, string $campo): bool
    {
        return !isset($dados[$campo]) || is_string($dados[$campo]);
    }

    #[Route('/revisao', name: '_revisao', methods: ['POST'])]
    public function confirmarRevisao(Request $request): JsonResponse
    {
        if (!$this->isCsrfTokenValid('ajax', (string) $request->headers->get('X-CSRF-Token'))) {
            return new JsonResponse(['erro' => 'Token inválido.'], Response::HTTP_FORBIDDEN);
        }

        /** @var User $user */
        $user   = $this->getUser();
        $perfil = $this->obterOuCriarPerfil->executar($user);
        $this->confirmarRevisao->executar($perfil);

        return new JsonResponse(['ok' => true, 'revisadoEm' => $perfil->getRevisadoEm()?->format('Y-m-d')]);
    }
}
