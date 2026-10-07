<?php

declare(strict_types=1);

namespace App\Pasta\EventListener;

use App\Pasta\Attribute\PastaPelaFilha;
use App\Pasta\Entity\Pasta;
use App\Service\Tenant\TenantContext;
use Doctrine\ORM\EntityManagerInterface;
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
 * têm `getPasta()`). Rota de leitura é GET e passa reto; as buscas da tela (`pasta_clientes_buscar`,
 * `pasta_buscar_processos`) são GET de propósito e continuam funcionando na pasta riscada.
 *
 * Rota que recebe só o id (`int`) de uma filha — documento, seção — declara de onde vem a pasta com
 * `#[PastaPelaFilha]`; o listener carrega a filha escopada ao escritório da sessão e recusa igual.
 * O `PastaSomenteLeituraRotasArquiteturaTest` percorre o router e falha se alguma rota de escrita
 * `pasta_*` não for alcançada por nenhum dos caminhos nem estiver numa das listas abaixo.
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
     *
     * Ficam de FORA de propósito (continuam recusadas): `pasta_favorito_alternar` e
     * `pasta_documentos_favorito`. Estrela é preferência pessoal, mas o precedente da casa é o
     * `PastaFavorita`, que recebe a recusa deste listener na pasta riscada (está escrito no
     * `PastaFavoritoController`); a estrela de documento segue o mesmo critério para a tela não
     * ter duas regras para o mesmo gesto.
     *
     * @var list<string>
     */
    public const ROTAS_LIBERADAS = ['pasta_restaurar', 'pasta_documentos_zip'];

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

    private const MENSAGEM = 'Esta pasta foi excluída e está somente para leitura. Restaure a pasta para voltar a editá-la.';

    public function __construct(
        private readonly RequestStack $requestStack,
        private readonly RouterInterface $router,
        private readonly EntityManagerInterface $em,
        private readonly TenantContext $tenantContext,
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

        $pasta = $this->pastaDosArgumentos($event->getArguments())
            ?? $this->pastaPelaFilhaDeclarada($event, $request);

        if ($pasta === null || !$pasta->estaExcluida()) {
            return;
        }

        // Curto-circuito: troca o controller por quem só devolve a recusa. Os argumentos já
        // resolvidos são zerados junto, senão o kernel os passaria para este substituto.
        $resposta = $this->recusa($request, $pasta);
        $event->setController(static fn (): Response => $resposta);
        $event->setArguments([]);
    }

    /** @param array<int, mixed> $argumentos */
    private function pastaDosArgumentos(array $argumentos): ?Pasta
    {
        foreach ($argumentos as $argumento) {
            if ($argumento instanceof Pasta) {
                return $argumento;
            }

            // Filha da pasta: a trava vale para ela também, senão daria para subir arquivo ou
            // apagar seção de uma pasta excluída passando pelo id da filha.
            if (is_object($argumento) && method_exists($argumento, 'getPasta')) {
                $pasta = $argumento->getPasta();

                if ($pasta instanceof Pasta) {
                    return $pasta;
                }
            }
        }

        return null;
    }

    /**
     * A pasta de uma rota que recebe o id cru da filha (`#[PastaPelaFilha]`).
     *
     * A busca é por id E escritório da sessão: é o guarda IDOR. Sem ele, o id de uma seção de
     * outro escritório numa pasta riscada devolveria a recusa com o link para a pasta alheia —
     * confirmando que ela existe. Não achou (outro escritório, id inexistente, documento na
     * lixeira pelo `LixeiraFilter`) → `null` e a action responde o próprio 404/403, como sempre.
     * Sem escritório na sessão não há como escopar a busca: devolve `null` e a action decide (ela
     * já recusa ou responde 404 nesse estado).
     */
    private function pastaPelaFilhaDeclarada(ControllerArgumentsEvent $event, Request $request): ?Pasta
    {
        $declaracoes = $event->getAttributes(PastaPelaFilha::class);

        if ($declaracoes === []) {
            return null;
        }

        $tenant = $this->tenantContext->getCurrentTenant();

        if ($tenant === null) {
            return null;
        }

        foreach ($declaracoes as $declaracao) {
            $id = $request->attributes->get($declaracao->argumento);

            if (!is_numeric($id)) {
                continue;
            }

            $filha = $this->em->getRepository($declaracao->entidade)->findOneBy([
                'id'     => (int) $id,
                'tenant' => $tenant,
            ]);

            if (is_object($filha) && method_exists($filha, 'getPasta')) {
                $pasta = $filha->getPasta();

                if ($pasta instanceof Pasta) {
                    return $pasta;
                }
            }
        }

        return null;
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
