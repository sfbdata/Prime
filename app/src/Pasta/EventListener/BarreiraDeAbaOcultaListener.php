<?php

declare(strict_types=1);

namespace App\Pasta\EventListener;

use App\Entity\Auth\User;
use App\Pasta\Attribute\PastaPelaFilha;
use App\Pasta\Entity\AbaDaPasta;
use App\Pasta\Entity\Pasta;
use App\Pasta\Entity\PastaDocumento;
use App\Pasta\Service\PastaDaRota;
use App\Pasta\Service\VisibilidadeDasAbasDaPasta;
use App\Service\Tenant\TenantContext;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\ControllerArgumentsEvent;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * Ocultar aba é barreira de servidor, não só tela (spec pasta-ocultar-aba D3 e §4): para quem a aba
 * está oculta nesta pasta, as rotas daquela aba respondem 403.
 *
 * O alcance é o `MAPA` (nome de rota, ou prefixo terminado em `*`, para a aba). A pasta sai do
 * `PastaDaRota`, o mesmo resolvedor da lápide e do cadeado (spec pasta-cadeado §3): os argumentos já
 * resolvidos (`Pasta` ou filha com `getPasta()`), o `#[PastaPelaFilha]` ou o `#[PastaPorId]`. Pasta de
 * outro escritório passa reto: a própria action responde o 404 dela, sem que a barreira revele nada.
 *
 * O `BarreiraDeAbaOcultaMapaTest` confere o mapa contra o roteador: todo nome ou prefixo casa com ao
 * menos uma rota, e toda rota alcançada tem de onde tirar a pasta.
 */
#[AsEventListener(event: KernelEvents::CONTROLLER_ARGUMENTS)]
final class BarreiraDeAbaOcultaListener
{
    /**
     * Rotas de cada aba. As rotas `tarefa_*` são da meta, não da pasta, e ficam de fora (spec §4).
     *
     * `pasta_checklist_*` é da aba Documentos: o checklist de documentação mora no explorador dela
     * (`_documentos_explorador` → `_documentos_checklist`). `pasta_reu_*` fica de fora: os réus vivem
     * na janela Cadastro (cliente-cadastro.js, aba Réu), aberta pelo cabeçalho, e não em aba da pasta.
     *
     * @var array<string, string>
     */
    public const MAPA = [
        'pasta_push_teor'                  => 'push',
        'pasta_push_texto'                 => 'push',
        'pasta_push_lida'                  => 'push',
        'pasta_push_links_*'               => 'push',
        'inteligencia_push_*'              => 'push',
        'pasta_financeiro_*'               => 'financeiro',
        'pasta_pagamento_*'                => 'financeiro',
        'pasta_alternar_situacao_contrato' => 'financeiro',
        'pasta_atualizar_pro_bono'         => 'financeiro',
        'pasta_atualizar_valor_causa'      => 'financeiro',
        'pasta_documento*'                 => 'documentos',
        'pasta_secao*'                     => 'documentos',
        'pasta_secoes_*'                   => 'documentos',
        'pasta_peticionar*'                => 'documentos',
        'inteligencia_agente_pastas_documentos' => 'documentos',
        'pasta_checklist_*'                => 'documentos',
        'pasta_detalhes_*'                 => 'detalhes',
        'pasta_vincular_processo'          => 'processo',
        'pasta_desvincular_processo'       => 'processo',
        'pasta_definir_processo_principal' => 'processo',
        'pasta_administrativa_definir'     => 'processo',
        'pasta_buscar_processos'           => 'processo',
        'pasta_prazo_*'                    => 'tarefas',
    ];

    public function __construct(
        private readonly VisibilidadeDasAbasDaPasta $visibilidade,
        private readonly TenantContext $tenantContext,
        private readonly EntityManagerInterface $em,
        private readonly Security $security,
        private readonly PastaDaRota $pastaDaRota,
    ) {
    }

    /** A aba de uma rota pelo `MAPA`, ou `null` se a rota não é de aba nenhuma. */
    public static function abaDaRota(string $rota): ?AbaDaPasta
    {
        foreach (self::MAPA as $padrao => $aba) {
            $casa = str_ends_with($padrao, '*')
                ? str_starts_with($rota, substr($padrao, 0, -1))
                : $rota === $padrao;
            if ($casa) {
                return AbaDaPasta::from($aba);
            }
        }

        return null;
    }

    public function __invoke(ControllerArgumentsEvent $event): void
    {
        if (!$event->isMainRequest()) {
            return;
        }

        $request = $event->getRequest();
        $aba = self::abaDaRota((string) $request->attributes->get('_route'));
        $user = $this->security->getUser();
        if ($aba === null || !$user instanceof User) {
            return;
        }

        $daRota = $this->pastaDaRota->resolver($event);
        if ($daRota === null || !$this->pastaDaRota->ehDoEscritorioDaSessao($daRota->pasta)) {
            return;
        }
        $pasta = $daRota->pasta;

        $resposta = $this->visibilidade->ocultaPara($pasta, $aba, $user)
            ? $this->recusa($request, $aba)
            : $this->recusaDoContrato($event, $request, $pasta, $user, $daRota->filha);
        if ($resposta !== null) {
            $event->setController(static fn (): Response => $resposta);
            $event->setArguments([]);
        }
    }

    /**
     * O contrato de honorários por id (ver, baixar, editar…) com o Financeiro oculto: 404, como se não
     * existisse (regra `VisibilidadeDasAbasDaPasta::documentoVisivel`).
     */
    private function recusaDoContrato(ControllerArgumentsEvent $event, Request $request, Pasta $pasta, User $user, ?object $filha): ?Response
    {
        $documento = $filha instanceof PastaDocumento ? $filha : $this->documentoDaRequisicao($event, $request);
        if ($documento === null) {
            return null;
        }
        $ocultas = array_map(static fn (AbaDaPasta $a): string => $a->value, $this->visibilidade->abasOcultasPara($pasta, $user));
        if (VisibilidadeDasAbasDaPasta::documentoVisivel($documento->getCategoria(), $ocultas)) {
            return null;
        }

        return new JsonResponse(['status' => 'erro', 'erro' => 'Documento não encontrado.', 'mensagem' => 'Documento não encontrado.'], Response::HTTP_NOT_FOUND);
    }

    /** O documento da rota: argumento já resolvido ou `#[PastaPelaFilha]` de documento (por id e escritório). */
    private function documentoDaRequisicao(ControllerArgumentsEvent $event, Request $request): ?PastaDocumento
    {
        foreach ($event->getArguments() as $argumento) {
            if ($argumento instanceof PastaDocumento) {
                return $argumento;
            }
        }
        foreach ($event->getAttributes(PastaPelaFilha::class) as $declaracao) {
            $id = $request->attributes->get($declaracao->argumento);
            if ($declaracao->entidade === PastaDocumento::class && is_numeric($id)) {
                $doc = $this->em->getRepository(PastaDocumento::class)->findOneBy(['id' => (int) $id, 'tenant' => $this->tenantContext->getCurrentTenant()]);

                return $doc instanceof PastaDocumento ? $doc : null;
            }
        }

        return null;
    }

    /**
     * JSON para as chamadas da tela (o formato dos endpoints AJAX da pasta, com `erro` e `mensagem`);
     * página 403 para a navegação (Peticionar, abrir documento em aba nova).
     */
    private function recusa(Request $request, AbaDaPasta $aba): Response
    {
        $mensagem = 'A aba ' . $aba->rotulo() . ' está oculta para você nesta pasta.';
        $navegacao = $request->isMethod('GET') && !$request->isXmlHttpRequest()
            && !str_contains((string) $request->headers->get('Accept'), 'json');
        if ($navegacao) {
            throw new AccessDeniedHttpException($mensagem);
        }

        return new JsonResponse(
            ['status' => 'erro', 'erro' => $mensagem, 'mensagem' => $mensagem],
            Response::HTTP_FORBIDDEN,
        );
    }
}
