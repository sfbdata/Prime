<?php

declare(strict_types=1);

namespace App\ChatIa\Controller;

use App\ChatIa\Exception\ConversaDeChatNaoEncontradaException;
use App\ChatIa\Exception\SemAcessoAoChatException;
use App\ChatIa\UseCase\AbrirPaginaDoChatUseCase;
use App\Entity\Auth\User;
use App\Inteligencia\Enum\ModeloDeIa;
use App\Service\Tenant\TenantContext;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Csrf\CsrfTokenManagerInterface;

/**
 * A página do Chat I.A (`GET /chat-ia` e `GET /chat-ia/{id}`, spec chat-pagina-inteira). Abre para quem tem vínculo ativo e
 * o módulo da I.A, mesmo sem chave (a tela mostra o aviso). Conversa de outra pessoa: 404. O HTML mora em
 * `chat_ia/pagina.html.twig`; as rotas JSON/SSE estão nos outros controllers do domínio.
 *
 * Variáveis do template: `semChave` (bool), `aviso` (?string), `nome` (string, primeiro nome), `csrf` (string, token
 * `ajax` para o cabeçalho `X-CSRF-Token`), `conversaId` (?int), `modelos` (list<{valor,rotulo}>), `modeloPadrao` (string)
 * e `urls` (array<string,string>; `__ID__` marca onde entra o id).
 */
final class ChatIaPaginaController extends AbstractController
{
    private const MARCADOR_DO_ID = '__ID__';
    private const ID_DE_MOLDE = 999999999;

    public function __construct(
        private readonly TenantContext $tenantContext,
        private readonly AbrirPaginaDoChatUseCase $abrir,
        private readonly CsrfTokenManagerInterface $csrf,
    ) {
    }

    #[Route('/chat-ia', name: 'chat_ia_pagina', methods: ['GET'])]
    #[Route('/chat-ia/{id}', name: 'chat_ia_pagina_conversa', requirements: ['id' => '\d{1,9}'], methods: ['GET'])]
    public function pagina(?int $id = null): Response
    {
        /** @var User $usuario */
        $usuario = $this->getUser();
        try {
            $pagina = $this->abrir->executar($usuario, $this->tenantContext->getCurrentTenant(), $id);
        } catch (SemAcessoAoChatException $e) {
            throw $this->createAccessDeniedException($e->getMessage());
        } catch (ConversaDeChatNaoEncontradaException $e) {
            throw $this->createNotFoundException($e->getMessage());
        }

        $resposta = $this->render('chat_ia/pagina.html.twig', [
            'semChave' => $pagina->semChave,
            'aviso' => $pagina->aviso,
            'nome' => $pagina->nome,
            'csrf' => $this->csrf->getToken('ajax')->getValue(),
            'conversaId' => $pagina->conversaId,
            'modelos' => array_map(static fn (ModeloDeIa $m): array => ['valor' => $m->value, 'rotulo' => $m->rotulo()], ModeloDeIa::cases()),
            'modeloPadrao' => ModeloDeIa::PADRAO->value,
            'urls' => $this->urls(),
        ]);
        $resposta->headers->set('Cache-Control', 'private, no-store');

        return $resposta;
    }

    /** @return array<string, string> */
    private function urls(): array
    {
        $com = fn (string $rota): string => str_replace((string) self::ID_DE_MOLDE, self::MARCADOR_DO_ID, $this->generateUrl($rota, ['id' => self::ID_DE_MOLDE]));

        return [
            'pagina' => $this->generateUrl('chat_ia_pagina'),
            'paginaDaConversa' => $com('chat_ia_pagina_conversa'),
            'conversas' => $this->generateUrl('chat_ia_conversas_listar'),
            'conversa' => $com('chat_ia_conversa_ver'),
            'mensagens' => $com('chat_ia_mensagens_enviar'),
            'anexos' => $this->generateUrl('chat_ia_anexos_enviar'),
            'anexo' => $com('chat_ia_anexo_remover'),
            'pastas' => $this->generateUrl('chat_ia_pastas_buscar'),
        ];
    }
}
