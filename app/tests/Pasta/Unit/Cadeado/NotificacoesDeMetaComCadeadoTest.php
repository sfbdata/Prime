<?php

declare(strict_types=1);

namespace App\Tests\Pasta\Unit\Cadeado;

use App\Entity\Notificacao;
use App\Entity\Tarefa\Tarefa;
use App\Pasta\Entity\CadeadoDaPasta;
use App\Pasta\Entity\ModoDoCadeado;
use App\Repository\NotificacaoRepository;
use App\Repository\UserRepository;
use App\Service\NotificacaoService;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;

/** Cadeado (spec pasta-cadeado D3): os avisos da meta não vão para quem está Oculto na pasta dela. */
#[CoversClass(NotificacaoService::class)]
final class NotificacoesDeMetaComCadeadoTest extends TestCase
{
    use CriaCenarioDoCadeadoTrait;

    protected function setUp(): void
    {
        $this->montarCenario();
    }

    #[TestDox('meta criada, devolvida e concluída: só avisa quem vê a pasta; quem está Oculto fica de fora')]
    public function testOcultoNaoEAvisado(): void
    {
        $primario = $this->pessoa('Paula Primária', primario: true);
        $ana      = $this->pessoa('Ana Equipe');
        $pasta    = $this->pasta();
        $this->gravarCadeado(new CadeadoDaPasta($this->tenant, $pasta, ModoDoCadeado::Todos, null, new \DateTimeImmutable()));
        $meta = new Tarefa();
        $meta->setTitulo('Meta');
        $meta->setPasta($pasta);
        $meta->setTenant($this->tenant);
        $meta->setCriadoPor($ana);
        $meta->addResponsavel($ana);
        $meta->addResponsavel($primario);

        $avisados = [];
        $em = $this->createMock(EntityManagerInterface::class);
        $em->method('persist')->willReturnCallback(static function (object $o) use (&$avisados): void {
            if ($o instanceof Notificacao) {
                $avisados[] = $o->getUsuario()?->getFullName();
            }
        });
        $servico = new NotificacaoService($em, $this->createStub(NotificacaoRepository::class), $this->createStub(UserRepository::class), $this->permissionChecker, $this->nivelNoCadeado());

        $servico->notificarTarefaCriada($meta);
        $servico->notificarTarefaPendente($meta);
        $servico->notificarTarefaConcluida($meta);
        $servico->notificarTarefaEmRevisao($meta);

        self::assertSame(['Paula Primária', 'Paula Primária', 'Paula Primária'], $avisados, 'a criadora oculta também não recebe o "em revisão"');
    }
}
