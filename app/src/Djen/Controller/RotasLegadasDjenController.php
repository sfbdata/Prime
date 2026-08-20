<?php

declare(strict_types=1);

namespace App\Djen\Controller;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Redireciona as URLs antigas `/djen*` para as novas `/push-processual*`.
 *
 * O módulo passou a se chamar "Push Processual" na interface e a URL acompanhou. Só que o endereço
 * antigo não morre com o deploy: em 19/08/2026 havia **199 notificações em produção com `url = '/djen'`
 * gravada na linha** (a mais recente do próprio dia) — o path é persistido pelo
 * `NotificadorPublicacoesDjen`, não montado na hora de exibir. Sem estas rotas, cada uma dessas
 * notificações vira 404 no clique, e o mesmo vale para favoritos e links já compartilhados.
 *
 * Só as rotas GET são espelhadas — são as únicas alcançáveis por link salvo ou notificação gravada.
 * As de POST (`sincronizar`, `alternar`, `remover`) nascem de um formulário renderizado pelo próprio
 * sistema, que a partir do deploy já aponta para o endereço novo.
 *
 * Isso deixa **uma janela descoberta, de propósito**: a página que já estava aberta no navegador no
 * instante do deploy continua com o form antigo. Clicar em "Sincronizar" ali posta em
 * `/djen/sincronizar` e recebe 404 — e mesmo que a rota existisse, o id do token CSRF mudou junto
 * (`djen_oab_X` → `push_processual_oab_X`), então a resposta seria "Token de segurança inválido".
 * Um F5 resolve. Espelhar POST com 301 seria pior: o padrão manda o navegador reenviar como GET,
 * o que transformaria uma ação de escrita numa navegação silenciosa.
 *
 * Permissão não é conferida aqui de propósito: o redirect não entrega conteúdo nenhum, e o destino
 * aplica o `canAccessModule('djen')` normalmente.
 */
final class RotasLegadasDjenController extends AbstractController
{
    #[Route('/djen', name: 'djen_index_legado', methods: ['GET'])]
    public function index(Request $request): Response
    {
        // Preserva filtros e paginação: a notificação aponta para `/djen` puro, mas um favorito
        // costuma carregar a query inteira (`?tribunal=...&pagina=3`).
        return $this->redirectToRoute('push_processual_index', $request->query->all(), Response::HTTP_MOVED_PERMANENTLY);
    }

    #[Route('/djen/oabs', name: 'djen_oabs_legado', methods: ['GET'])]
    public function oabs(): Response
    {
        return $this->redirectToRoute('push_processual_oabs', [], Response::HTTP_MOVED_PERMANENTLY);
    }

    #[Route('/djen/{id}', name: 'djen_show_legado', methods: ['GET'], requirements: ['id' => '\d+'])]
    public function show(int $id): Response
    {
        return $this->redirectToRoute('push_processual_show', ['id' => $id], Response::HTTP_MOVED_PERMANENTLY);
    }
}
