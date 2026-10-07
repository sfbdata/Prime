<?php

declare(strict_types=1);

namespace App\Tests\Pasta\Functional;

use App\Entity\Auth\User;
use App\Pasta\Entity\Pasta;
use App\Pasta\EventListener\PastaSomenteLeituraListener;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\TestDox;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\ControllerArgumentsEvent;
use Symfony\Component\HttpKernel\HttpKernelInterface;

/**
 * O degrau de dois níveis do `PastaSomenteLeituraListener` (P14b): argumento que chega à pasta por
 * `getTarefa()->getPasta()` (`TarefaMensagem`). Os casos de borda — sem meta, meta sem pasta — não
 * cabem no banco (`tarefa.pasta_id` é NOT NULL), por isso são provados aqui, com objetos de mão;
 * o caminho HTTP real (pasta riscada × viva) está no `PastaSomenteLeituraMensagemDaMetaTest`.
 */
#[CoversClass(PastaSomenteLeituraListener::class)]
final class PastaSomenteLeituraPelaMetaListenerTest extends KernelTestCase
{
    #[TestDox('argumento cuja meta está numa pasta excluída: o controller é trocado pela recusa (403 JSON)')]
    public function testMetaEmPastaExcluidaRecusa(): void
    {
        $pasta = new Pasta();
        $pasta->marcarExcluida((new User())->setEmail('autor@test.com'), new \DateTimeImmutable());

        $evento = $this->despachar($this->neta($this->meta($pasta)));

        $resposta = ($evento->getController())();
        self::assertInstanceOf(Response::class, $resposta);
        self::assertSame(403, $resposta->getStatusCode());
        self::assertStringContainsString('somente para leitura', (string) $resposta->getContent());
        self::assertSame([], $evento->getArguments());
    }

    #[TestDox('argumento cuja meta está numa pasta viva: segue para a action')]
    public function testMetaEmPastaVivaSegue(): void
    {
        $evento = $this->despachar($this->neta($this->meta(new Pasta())));

        self::assertSame('action', ($evento->getController())());
    }

    #[TestDox('argumento sem meta (getTarefa() nulo): segue para a action')]
    public function testSemMetaSegue(): void
    {
        $evento = $this->despachar($this->neta(null));

        self::assertSame('action', ($evento->getController())());
    }

    #[TestDox('meta sem pasta (getPasta() nulo): segue para a action')]
    public function testMetaSemPastaSegue(): void
    {
        $evento = $this->despachar($this->neta($this->meta(null)));

        self::assertSame('action', ($evento->getController())());
    }

    private function meta(?Pasta $pasta): object
    {
        return new class ($pasta) {
            public function __construct(private readonly ?Pasta $pasta) {}

            public function getPasta(): ?Pasta
            {
                return $this->pasta;
            }
        };
    }

    private function neta(?object $meta): object
    {
        return new class ($meta) {
            public function __construct(private readonly ?object $meta) {}

            public function getTarefa(): ?object
            {
                return $this->meta;
            }
        };
    }

    private function despachar(object $argumento): ControllerArgumentsEvent
    {
        $request = Request::create('/tarefas/mensagem/1/editar', 'POST');
        $request->headers->set('X-Requested-With', 'XMLHttpRequest');
        $request->attributes->set('_route', 'tarefa_mensagem_editar');

        $evento = new ControllerArgumentsEvent(
            $this->createStub(HttpKernelInterface::class),
            static fn (): string => 'action',
            [$argumento],
            $request,
            HttpKernelInterface::MAIN_REQUEST,
        );

        // O listener real, do container: sem `#[PastaPelaFilha]`/`#[PastaPorId]` na action ele não
        // consulta banco nem escritório, então nada precisa ser simulado além do evento.
        /** @var PastaSomenteLeituraListener $listener */
        $listener = static::getContainer()->get(PastaSomenteLeituraListener::class);
        $listener($evento);

        return $evento;
    }
}
