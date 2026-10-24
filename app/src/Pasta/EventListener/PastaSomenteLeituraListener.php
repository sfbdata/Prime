<?php

declare(strict_types=1);

namespace App\Pasta\EventListener;

use App\Pasta\Entity\Pasta;
use App\Pasta\Service\PastaDaRota;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\Session\Session;
use Symfony\Component\HttpKernel\Event\ControllerArgumentsEvent;
use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Component\Routing\RouterInterface;

/**
 * Pasta excluída (lápide) é somente-leitura: ela abre e mostra tudo, mas não aceita mais escrita.
 *
 * Está aqui, num ponto só, e não carimbado nas rotas: só o `PastaController` tem 35 rotas POST que
 * recebem a pasta, e ainda há as de seção, peça e expediente. Checagem por rota daria a mesma
 * cobertura hoje e nenhuma amanhã — rota nova nasceria destravada, sem ninguém perceber, porque a
 * pasta riscada é caso raro que ninguém testa à mão.
 *
 * O recorte é `Request` não-segura (POST/PUT/PATCH/DELETE) que receba uma `Pasta` — direta ou por
 * uma filha dela (documento, seção, mensagem, checklist, observação, processo, pagamento: todas
 * têm `getPasta()`) ou uma neta pela meta (`TarefaMensagem`: `getTarefa()->getPasta()`). Rota de leitura é GET e passa reto; as buscas da tela (`pasta_clientes_buscar`,
 * `pasta_buscar_processos`) são GET de propósito e continuam funcionando na pasta riscada.
 *
 * Rota que recebe só o id (`int`) de uma filha — documento, seção — declara de onde vem a pasta com
 * `#[PastaPelaFilha]`; rota que recebe o id cru da PRÓPRIA pasta (marcadores do Expediente, meta
 * criada pela pasta) declara `#[PastaPorId]`. Nos dois casos a entidade é carregada escopada ao
 * escritório da sessão e a recusa é igual. Quem acha a pasta é o `PastaDaRota`, o mesmo resolvedor do
 * cadeado (`BarreiraDoCadeadoListener`) e da aba oculta.
 *
 * O alcance não é garantido por este arquivo, e sim pelo `PastaSomenteLeituraRotasArquiteturaTest`:
 * ele percorre o router inteiro e falha se uma rota de escrita que envolva pasta (nome `pasta_*`,
 * `/pasta/` no path, variável `pastaId`/`pasta_id`/`pasta`, ou action que recebe a pasta ou uma
 * filha) não for alcançada por nenhum dos caminhos nem estiver numa das listas abaixo. Rota que
 * escape dos quatro sinais (id da pasta no corpo da requisição, por exemplo) não é vista nem pelo
 * listener nem pelo teste.
 */
#[AsEventListener(event: KernelEvents::CONTROLLER_ARGUMENTS)]
final class PastaSomenteLeituraListener
{
    /**
     * Escritas (ou POST de leitura) que a pasta riscada aceita, cada uma pelo seu motivo:
     *
     *  - `pasta_restaurar`: é o que desfaz o estado — a única escrita de verdade liberada;
     *  - `pasta_documentos_zip`: é LEITURA. É POST só porque a seleção (ids de documentos e
     *    subpastas) vai no corpo, com CSRF; a permissão exigida é a de VER e o único efeito é o
     *    registro no `audit_log`. A pasta riscada "abre e mostra tudo" — baixar o que ela mostra
     *    faz parte de consultar.
     *  - `inteligencia_analise_registro` (C6-IA-01): o mesmo motivo do zip. Baixar ou imprimir a
     *    análise da IA é leitura (GET puro do .txt); este POST só grava a linha no `audit_log`
     *    ("Download"/"Impressão" do Registro de auditoria), com CSRF e a permissão de VER. Sem ele,
     *    baixar na pasta riscada não deixaria rastro.
     *
     * Ficam de FORA de propósito (continuam recusadas): `pasta_favorito_alternar` e
     * `pasta_documentos_favorito`. Estrela é preferência pessoal, mas o precedente da casa é o
     * `PastaFavorita`, que recebe a recusa deste listener na pasta riscada (está escrito no
     * `PastaFavoritoController`); a estrela de documento segue o mesmo critério para a tela não
     * ter duas regras para o mesmo gesto.
     *
     * @var list<string>
     */
    public const ROTAS_LIBERADAS = ['pasta_restaurar', 'pasta_documentos_zip', 'inteligencia_analise_registro'];

    /**
     * Rotas de escrita `pasta_*` que não operam sobre uma pasta existente — não há lápide para
     * olhar. O listener não as usa (nelas não há pasta nos argumentos); a lista existe para o
     * teste de arquitetura distinguir "não precisa" de "esqueceram".
     *
     *  - `pasta_new`: cria uma pasta nova.
     *
     * @var list<string>
     */
    public const ROTAS_SEM_PASTA_EXISTENTE = ['pasta_new'];

    private const METODOS_DE_LEITURA = ['GET', 'HEAD', 'OPTIONS'];

    /** Também a recusa do desfazer da auditoria na pasta excluída e nas filhas dela (`EstadoDaTelaNoDesfazer`). */
    public const MENSAGEM = 'Esta pasta foi excluída e está somente para leitura. Restaure a pasta para voltar a editá-la.';

    public function __construct(
        private readonly RequestStack $requestStack,
        private readonly RouterInterface $router,
        private readonly PastaDaRota $pastaDaRota,
    ) {}

    public function __invoke(ControllerArgumentsEvent $event): void
    {
        if (!$event->isMainRequest()) {
            return;
        }

        $request = $event->getRequest();

        if (in_array($request->getMethod(), self::METODOS_DE_LEITURA, true)) {
            return;
        }

        if (in_array((string) $request->attributes->get('_route'), self::ROTAS_LIBERADAS, true)) {
            return;
        }

        $pasta = $this->pastaDaRota->resolver($event)?->pasta;

        if ($pasta === null || !$pasta->estaExcluida()) {
            return;
        }

        // Curto-circuito: troca o controller por quem só devolve a recusa. Os argumentos já
        // resolvidos são zerados junto, senão o kernel os passaria para este substituto.
        $resposta = $this->recusa($request, $pasta);
        $event->setController(static fn (): Response => $resposta);
        $event->setArguments([]);
    }

    private function recusa(Request $request, Pasta $pasta): Response
    {
        if ($request->isXmlHttpRequest()) {
            // Mesmo formato que os endpoints AJAX da pasta já devolvem, para o JS da tela poder
            // mostrar a mensagem em vez de cair no "Erro de comunicação".
            return new JsonResponse(
                ['status' => 'erro', 'mensagem' => self::MENSAGEM],
                Response::HTTP_FORBIDDEN,
            );
        }

        $sessao = $this->requestStack->getSession();

        if ($sessao instanceof Session) {
            $sessao->getFlashBag()->add('warning', self::MENSAGEM);
        }

        return new RedirectResponse($this->router->generate('pasta_show', ['id' => $pasta->getId()]));
    }
}
