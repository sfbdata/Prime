<?php

declare(strict_types=1);

namespace App\Tests\Tarefa\Functional;

use App\Entity\Auth\User;
use App\Entity\Tarefa\Tarefa;
use App\Pasta\EventListener\BarreiraDoCadeadoListener;
use App\Pasta\Entity\NivelDeAcesso;
use App\Pasta\Entity\Pasta;
use App\Tarefa\Controller\DuplicarMetaController;
use App\Tests\Functional\JusPrimeWebTestCase;
use App\Tests\Pasta\Functional\CriaCenarioDoCadeadoFuncionalTrait;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\TestDox;

/**
 * Cadeado da pasta no "Duplicar" da meta (spec pasta-cadeado): a `BarreiraDoCadeadoListener` responde antes da action
 * (em `kernel.controller_arguments`, a action recebe a `Tarefa`, filha da pasta). `Oculto` e `Visualizar` saem com a
 * recusa dela (403 JSON com a mensagem de cada nível, como toda escrita na pasta trancada); o 404 do `AcessoAMeta`
 * para a pasta oculta fica como segunda trava, que a barreira não deixa chegar. Quem edita duplica. Em nenhuma
 * recusa nasce cópia.
 */
#[CoversClass(DuplicarMetaController::class)]
#[CoversClass(BarreiraDoCadeadoListener::class)]
#[Group('tarefa')]
final class DuplicarMetaNoCadeadoTest extends JusPrimeWebTestCase
{
    use CriaCenarioDoCadeadoFuncionalTrait;

    private const XHR = ['HTTP_X-Requested-With' => 'XMLHttpRequest', 'HTTP_ACCEPT' => 'application/json'];

    /** @return array{Pasta, Tarefa, User} a pasta trancada com a pessoa no nível pedido, a meta e a pessoa */
    private function cenario(NivelDeAcesso $nivel): array
    {
        $this->prepararCliente();
        [$primario, $tenant] = $this->criarEscritorio();
        $ana   = $this->criarColaborador($tenant, 'Ana Equipe', ['modules.tarefas.view', 'resources.pasta.view', 'resources.pasta.edit']);
        $pasta = $this->criarPasta($tenant);
        $pasta->setCriadoPor($primario);

        $meta = new Tarefa();
        $meta->setTitulo('Protocolar recurso');
        $meta->setDescricao('...');
        $meta->setPrazo(new \DateTimeImmutable('+5 days midnight'));
        $meta->setPasta($pasta);
        $meta->setTenant($tenant);
        $meta->setCriadoPor($primario);
        $meta->addResponsavel($ana);
        $this->em()->persist($meta);
        $pasta->getTarefas()->add($meta);
        $this->em()->flush();

        $this->trancar($pasta, $primario, [[$ana, $nivel]]);
        $this->entrar($ana, $tenant);
        $this->em()->clear();

        return [$pasta, $meta, $ana];
    }

    private function duplicar(Tarefa $meta): void
    {
        $id = (int) $meta->getId();
        $this->client->request('POST', "/tarefas/{$id}/duplicar", ['_token' => 'TOKEN_duplicar_tarefa_' . $id], [], self::XHR);
    }

    private function metasDaPasta(Pasta $pasta): int
    {
        return (int) $this->em()->getConnection()->fetchOne('SELECT COUNT(*) FROM tarefa WHERE pasta_id = ?', [$pasta->getId()]);
    }

    #[TestDox('Oculto: recusado pela barreira do cadeado (acesso restrito), nenhuma cópia')]
    public function testOcultoRecusa(): void
    {
        [$pasta, $meta] = $this->cenario(NivelDeAcesso::Oculto);

        $this->duplicar($meta);

        self::assertResponseStatusCodeSame(403);
        self::assertSame(BarreiraDoCadeadoListener::MENSAGEM_OCULTO, json_decode((string) $this->client->getResponse()->getContent(), true)['mensagem'] ?? null);
        self::assertSame(1, $this->metasDaPasta($pasta));
    }

    #[TestDox('Visualizar: recusado pela barreira do cadeado (somente visualização), nenhuma cópia')]
    public function testVisualizarRecusa(): void
    {
        [$pasta, $meta] = $this->cenario(NivelDeAcesso::Visualizar);

        $this->duplicar($meta);

        self::assertResponseStatusCodeSame(403);
        self::assertSame(BarreiraDoCadeadoListener::MENSAGEM_VISUALIZAR, json_decode((string) $this->client->getResponse()->getContent(), true)['mensagem'] ?? null);
        self::assertSame(1, $this->metasDaPasta($pasta));
    }

    #[TestDox('controle: Editar no cadeado duplica')]
    public function testEditarDuplica(): void
    {
        [$pasta, $meta] = $this->cenario(NivelDeAcesso::Editar);

        $this->duplicar($meta);

        self::assertResponseIsSuccessful();
        self::assertSame(2, $this->metasDaPasta($pasta));
    }
}
