<?php

declare(strict_types=1);

namespace App\Auth\Controller;

use App\Auth\Enum\ReenvioDeConfirmacao;
use App\Auth\Service\ContaAConfirmar;
use App\Auth\Service\TetoDoReenvioDeConfirmacao;
use App\Auth\UseCase\ReenviarConfirmacaoDeEmailUseCase;
use App\Entity\Auth\User;
use App\Repository\UserRepository;
use App\Security\PedidoDeNavegacao;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\Session\SessionInterface;
use Symfony\Component\Routing\Attribute\Route;

/**
 * "Reenviar o link de confirmação" sem login (spec `cadastro-exige-confirmacao.md`): o botão junto da recusa do login
 * e o do modal do cadastro. A conta vem da sessão (`ContaAConfirmar::CHAVE_SESSAO`), gravada só depois da senha
 * certa ou do próprio cadastro, nunca de um e-mail digitado. Os tetos são os da rota logada.
 *
 * Responde em JSON ao `fetch` do modal e com flash + volta ao login ao formulário da tela de entrada. Nenhuma
 * resposta diz algo sobre uma conta que a pessoa não provou ser dela.
 */
final class ReenvioPublicoDeConfirmacaoController extends AbstractController
{
    public const SESSAO_EXPIRADA = 'Sessão expirada. Recarregue a página.';

    public function __construct(
        private readonly ReenviarConfirmacaoDeEmailUseCase $reenviar,
        private readonly TetoDoReenvioDeConfirmacao $teto,
        private readonly UserRepository $userRepository,
        private readonly ContaAConfirmar $contaAConfirmar,
    ) {
    }

    #[Route('/login/reenviar-confirmacao', name: 'auth_confirmacao_reenviar_publico', methods: ['POST'])]
    public function reenviar(Request $request): Response
    {
        if (!$this->isCsrfTokenValid(ContaAConfirmar::CSRF, self::tokenDoPedido($request))) {
            return $this->responder($request, 'warning', self::SESSAO_EXPIRADA, Response::HTTP_FORBIDDEN);
        }

        $sessao = $request->getSession();

        $neutro = $this->contaAConfirmar->neutroNaSessao($sessao);
        if ($neutro !== null) {
            return $this->reenviarPeloNeutro($request, $sessao, $neutro['emissoes']);
        }

        $user = $this->contaDaSessao($sessao);
        if ($user === null) {
            return $this->responder($request, 'info', ContaAConfirmar::SEM_CONTA_NA_SESSAO);
        }

        $recusa = $user->isEmailConfirmado() ? null : $this->teto->recusa($user);
        if ($recusa !== null) {
            return $this->responder($request, 'warning', $recusa);
        }

        $resultado = $this->reenviar->executar($user);
        if ($resultado === ReenvioDeConfirmacao::JaConfirmado) {
            ContaAConfirmar::esquecer($sessao);
        }

        [$tipo, $texto] = match ($resultado) {
            ReenvioDeConfirmacao::Enviado => ['success', ContaConfirmacaoController::FLASH_ENVIADO],
            ReenvioDeConfirmacao::JaConfirmado => ['info', ContaConfirmacaoController::FLASH_JA_CONFIRMADO],
            ReenvioDeConfirmacao::FalhaNoEnvio => ['danger', ContaConfirmacaoController::FLASH_FALHA],
        };

        return $this->responder($request, $tipo, $texto);
    }

    /**
     * Cadastro de um e-mail que já tem conta (raiz E da spec seguranca-a-convites): a mesma resposta e o mesmo teto
     * da conta nova, sem mandar nada a ninguém.
     *
     * @param list<int> $emissoes
     */
    private function reenviarPeloNeutro(Request $request, SessionInterface $sessao, array $emissoes): Response
    {
        $recusa = $this->teto->recusaPelosInstantes($emissoes);
        if ($recusa !== null) {
            return $this->responder($request, 'warning', $recusa);
        }
        $this->contaAConfirmar->registrarEmissaoNeutra($sessao);

        return $this->responder($request, 'success', ContaConfirmacaoController::FLASH_ENVIADO);
    }

    /** A conta guardada na sessão, se ainda existe e está ativa; senão, esquece a chave. */
    private function contaDaSessao(SessionInterface $sessao): ?User
    {
        $id = $this->contaAConfirmar->idNaSessao($sessao);
        $user = $id === null ? null : $this->userRepository->find($id);
        if ($user instanceof User && $user->isActive()) {
            return $user;
        }

        ContaAConfirmar::esquecer($sessao);

        return null;
    }

    /** O token vem do formulário (`_token`) ou do corpo JSON do modal. */
    private static function tokenDoPedido(Request $request): string
    {
        $token = $request->request->get('_token');
        if (is_string($token)) {
            return $token;
        }

        try {
            $json = $request->toArray();
        } catch (\Throwable) {
            return '';
        }

        return is_string($json['_token'] ?? null) ? $json['_token'] : '';
    }

    private function responder(Request $request, string $tipo, string $texto, int $status = Response::HTTP_OK): Response
    {
        if (!PedidoDeNavegacao::eNavegacao($request)) {
            return $this->json(['ok' => $tipo === 'success' || $tipo === 'info', 'mensagem' => $texto], $status);
        }

        $this->addFlash($tipo, $texto);

        return $this->redirectToRoute('app_login');
    }
}
