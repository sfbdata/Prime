<?php

declare(strict_types=1);

namespace App\Pasta\EventListener;

use App\Pasta\Entity\Pasta;
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
 */
#[AsEventListener(event: KernelEvents::CONTROLLER_ARGUMENTS)]
final class PastaSomenteLeituraListener
{
    /** Restaurar é a única escrita que uma pasta riscada aceita — é o que desfaz o estado. */
    private const ROTAS_LIBERADAS = ['pasta_restaurar'];

    private const METODOS_DE_LEITURA = ['GET', 'HEAD', 'OPTIONS'];

    private const MENSAGEM = 'Esta pasta foi excluída e está somente para leitura. Restaure a pasta para voltar a editá-la.';

    public function __construct(
        private readonly RequestStack $requestStack,
        private readonly RouterInterface $router,
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

        $pasta = $this->pastaDosArgumentos($event->getArguments());

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
