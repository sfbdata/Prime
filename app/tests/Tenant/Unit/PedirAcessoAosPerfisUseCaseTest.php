<?php

declare(strict_types=1);

namespace App\Tests\Tenant\Unit;

use App\Entity\Auth\User;
use App\Entity\Notificacao;
use App\Entity\Tenant\Tenant;
use App\Repository\NotificacaoRepository;
use App\Repository\UserTenantRepository;
use App\Service\NotificacaoService;
use App\Tenant\DTO\PedirAcessoAosPerfisInput;
use App\Tenant\UseCase\PedirAcessoAosPerfisUseCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

/**
 * "Pedir acesso" aos Perfis (spec perfis-1.2-permissoes-e-hierarquia C2; desenho 05 - Perfis 1.2, L822).
 */
#[CoversClass(PedirAcessoAosPerfisUseCase::class)]
final class PedirAcessoAosPerfisUseCaseTest extends TestCase
{
    private UserTenantRepository&MockObject $vinculos;
    private NotificacaoRepository&MockObject $notificacoes;
    private NotificacaoService&MockObject $servico;
    private PedirAcessoAosPerfisUseCase $useCase;
    private Tenant $tenant;
    private User $solicitante;

    protected function setUp(): void
    {
        $this->vinculos     = $this->createMock(UserTenantRepository::class);
        $this->notificacoes = $this->createMock(NotificacaoRepository::class);
        $this->servico      = $this->createMock(NotificacaoService::class);
        $urls = $this->createStub(UrlGeneratorInterface::class);
        $urls->method('generate')->willReturn('/tenant/5/roles?pedido=9');

        $this->useCase = new PedirAcessoAosPerfisUseCase(
            $this->vinculos,
            $this->notificacoes,
            $this->servico,
            $urls,
            new MockClock('2026-10-21 15:40:00'),
        );
        $this->tenant      = new Tenant();
        $this->solicitante = (new User())->setEmail('pede@teste.com')->setFullName('Bruna Secundária');
    }

    #[TestDox('avisa cada primário ativo com o título e o texto do desenho e o link da pessoa; conta desativada fica de fora')]
    public function testAvisaOsPrimariosAtivos(): void
    {
        $ativo     = (new User())->setEmail('a@teste.com')->setIsActive(true);
        $desligado = (new User())->setEmail('b@teste.com')->setIsActive(false);
        $this->vinculos->method('findUsuariosAdminsAtivos')->willReturn([$ativo, $desligado]);
        $this->notificacoes->method('existeDoTipoComUrlNoEscritorioDesde')->willReturn(false);

        $this->servico->expects($this->once())->method('criarNotificacao')->with(
            $ativo,
            $this->tenant,
            'perfis_pedido_acesso',
            'Pedido de acesso aos Perfis',
            '/tenant/5/roles?pedido=9',
            'Bruna Secundária pediu acesso ao menu Perfis do Escritório.',
        )->willReturn(new Notificacao());

        self::assertSame(1, $this->useCase->executar(new PedirAcessoAosPerfisInput($this->tenant, $this->solicitante)));
    }

    #[TestDox('teto: quem já pediu hoje (desde a meia-noite) não avisa de novo')]
    public function testUmPedidoPorDia(): void
    {
        $this->notificacoes->expects($this->atLeastOnce())->method('existeDoTipoComUrlNoEscritorioDesde')->with(
            $this->tenant,
            PedirAcessoAosPerfisUseCase::TIPO_NOTIFICACAO,
            '/tenant/5/roles?pedido=9',
            $this->callback(static fn (\DateTimeImmutable $desde): bool => $desde->format('Y-m-d H:i:s') === '2026-10-21 00:00:00'),
        )->willReturn(true);
        $this->servico->expects($this->never())->method('criarNotificacao');

        self::assertTrue($this->useCase->jaPediuHoje($this->tenant, $this->solicitante));
        self::assertSame(0, $this->useCase->executar(new PedirAcessoAosPerfisInput($this->tenant, $this->solicitante)));
    }
}
