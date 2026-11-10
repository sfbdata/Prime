<?php

declare(strict_types=1);

namespace App\Tests\Ponto\Unit;

use App\Entity\Auth\User;
use App\Entity\Tenant\Tenant;
use App\Ponto\Entity\ConfiguracaoDoPonto;
use App\Ponto\Entity\RegistroPonto;
use App\Ponto\Repository\ConfiguracaoDoPontoRepository;
use App\Ponto\Service\TrabalhoExternoDaBatida;
use App\Service\NotificacaoService;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;
use Psr\Log\AbstractLogger;
use Symfony\Component\Routing\Generator\UrlGenerator;
use Symfony\Component\Routing\RequestContext;
use Symfony\Component\Routing\Route;
use Symfony\Component\Routing\RouteCollection;

/**
 * A batida de trabalho externo (E12, P18 e P74): o pedido, o local e o motivo, a marca na batida, a justificativa em
 * análise ligada a ela e o aviso aos gestores, que nunca desfaz a batida.
 */
#[CoversClass(TrabalhoExternoDaBatida::class)]
#[Group('ponto')]
final class TrabalhoExternoDaBatidaTest extends TestCase
{
    #[TestDox('Só o booleano `trabalhoExterno: true` pede o trabalho externo')]
    public function testFoiPedido(): void
    {
        self::assertTrue(TrabalhoExternoDaBatida::foiPedido(['trabalhoExterno' => true]));
        foreach ([[], ['trabalhoExterno' => false], ['trabalhoExterno' => 'true'], ['trabalhoExterno' => 1]] as $dados) {
            self::assertFalse(TrabalhoExternoDaBatida::foiPedido($dados));
        }
    }

    #[TestDox('Local e motivo: sem espaços nas pontas; vazio ou só espaços recusa; até 500 caracteres passa; 501 recusa; o que não é texto vale vazio')]
    public function testMotivo(): void
    {
        self::assertSame('Cliente na Av. Paulista', TrabalhoExternoDaBatida::motivoDo(['motivoTrabalhoExterno' => "  Cliente na Av. Paulista \n"]));
        self::assertSame('', TrabalhoExternoDaBatida::motivoDo(['motivoTrabalhoExterno' => ['x']]));
        self::assertSame('', TrabalhoExternoDaBatida::motivoDo([]));

        self::assertSame('Informe o local e o motivo.', TrabalhoExternoDaBatida::recusaDoMotivo(''));
        self::assertSame('Informe o local e o motivo.', TrabalhoExternoDaBatida::recusaDoMotivo(TrabalhoExternoDaBatida::motivoDo(['motivoTrabalhoExterno' => '   '])));
        self::assertNull(TrabalhoExternoDaBatida::recusaDoMotivo(str_repeat('é', 500)), '500 caracteres, contados como caracteres e não bytes');
        self::assertSame('O local e o motivo podem ter até 500 caracteres.', TrabalhoExternoDaBatida::recusaDoMotivo(str_repeat('a', 501)));
    }

    #[TestDox('Sem a linha de configuração, o trabalho externo vale permitido; com ela, o que o escritório gravou')]
    public function testPermitido(): void
    {
        $tenant = new Tenant();
        $desligada = new ConfiguracaoDoPonto($tenant);
        $desligada->definirTrabalhoExterno(false, new User(), new \DateTimeImmutable());
        $configuracoes = $this->createMock(ConfiguracaoDoPontoRepository::class);
        $configuracoes->method('doTenant')->willReturnOnConsecutiveCalls(null, $desligada);

        $servico = $this->servico($configuracoes, $this->createMock(NotificacaoService::class), new class () extends AbstractLogger {
            public function log($level, \Stringable|string $message, array $context = []): void
            {
            }
        });

        self::assertTrue($servico->permitido($tenant));
        self::assertFalse($servico->permitido($tenant));
    }

    #[TestDox('A batida marcada fica sem sede e com o motivo; a justificativa nasce em análise, do tipo trabalho externo, no dia do momento e ligada a ela')]
    public function testMarcaEJustificativa(): void
    {
        $tenant = new Tenant();
        $user = new User();
        $batida = (new RegistroPonto())->setUser($user)->setTenant($tenant)->setTipo('entrada')->setSedeNomeSnapshot('Velha');
        $batida->setDataHora(new \DateTime('2026-11-07 23:58:00'));   // na guardada sem internet, a hora do aparelho

        TrabalhoExternoDaBatida::marcar($batida, 'Fórum de Pinheiros, audiência');
        $justificativa = $this->servico()->justificativaDa($batida);

        self::assertSame([true, 'Fórum de Pinheiros, audiência', null, null], [$batida->isTrabalhoExterno(), $batida->getMotivoTrabalhoExterno(), $batida->getSede(), $batida->getSedeNomeSnapshot()]);
        self::assertSame(['trabalho_externo', 'pendente', '2026-11-07', 'Fórum de Pinheiros, audiência'], [$justificativa->getTipo(), $justificativa->getStatus(), $justificativa->getData()?->format('Y-m-d'), $justificativa->getMotivo()]);
        self::assertSame($batida, $justificativa->getRegistroPonto());
        self::assertTrue($justificativa->analisaUmaBatida());
        self::assertSame([$user, $tenant], [$justificativa->getUser(), $justificativa->getTenant()]);
        self::assertSame(32, strlen((string) $justificativa->getBatchId()), 'um lote de um dia, como o envio pela tela');
        self::assertFalse($justificativa->isAbonoParcial());
    }

    #[TestDox('A justificativa só nasce da batida marcada como trabalho externo')]
    public function testJustificativaSoDaBatidaMarcada(): void
    {
        $batida = (new RegistroPonto())->setUser(new User())->setTenant(new Tenant())->setTipo('entrada');

        $this->expectException(\LogicException::class);
        $this->servico()->justificativaDa($batida);
    }

    #[TestDox('O aviso aos gestores leva o endereço da aba de justificativas da pessoa; a falha nele vai para o log e não sobe')]
    public function testAvisoAosGestores(): void
    {
        $tenant = $this->comId(new Tenant(), 7);
        $batida = (new RegistroPonto())->setUser($this->comId(new User(), 42))->setTenant($tenant)->setTipo('entrada');
        TrabalhoExternoDaBatida::marcar($batida, 'Cliente');
        $justificativa = $this->servico()->justificativaDa($batida);

        $chamadas = 0;
        $notificacoes = $this->createMock(NotificacaoService::class);
        $notificacoes->expects(self::exactly(2))->method('notificarJustificativaEnviada')
            ->with($justificativa, '/tenant/7/user/42/edit-role?tab=justificativas', $tenant)
            ->willReturnCallback(static function () use (&$chamadas): void {
                if (++$chamadas === 2) {
                    throw new \RuntimeException('banco fora');
                }
            });
        $log = new class () extends AbstractLogger {
            /** @var list<string> */
            public array $erros = [];

            public function log($level, \Stringable|string $message, array $context = []): void
            {
                $this->erros[] = $level . ': ' . $message . ' (' . ($context['erro'] ?? '') . ')';
            }
        };
        $servico = $this->servico(null, $notificacoes, $log);

        $servico->avisarGestores($justificativa, $tenant);
        self::assertSame([], $log->erros);

        $servico->avisarGestores($justificativa, $tenant);
        self::assertSame(['error: Ponto E12: a batida de trabalho externo entrou, mas o aviso aos gestores falhou. (banco fora)'], $log->erros);
    }

    /**
     * @template T of object
     *
     * @param T $entidade
     *
     * @return T
     */
    private function comId(object $entidade, int $id): object
    {
        (new \ReflectionProperty($entidade, 'id'))->setValue($entidade, $id);

        return $entidade;
    }

    private function servico(?ConfiguracaoDoPontoRepository $configuracoes = null, ?NotificacaoService $notificacoes = null, ?AbstractLogger $log = null): TrabalhoExternoDaBatida
    {
        $rotas = new RouteCollection();
        $rotas->add('app_tenant_user_edit_role', new Route('/tenant/{tenantId}/user/{id}/edit-role'));

        return new TrabalhoExternoDaBatida(
            $configuracoes ?? $this->createMock(ConfiguracaoDoPontoRepository::class),
            $notificacoes ?? $this->createMock(NotificacaoService::class),
            new UrlGenerator($rotas, new RequestContext()),
            $log ?? new class () extends AbstractLogger {
                public function log($level, \Stringable|string $message, array $context = []): void
                {
                }
            },
        );
    }
}
