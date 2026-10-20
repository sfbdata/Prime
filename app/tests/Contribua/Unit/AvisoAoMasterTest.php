<?php

declare(strict_types=1);

namespace App\Tests\Contribua\Unit;

use App\Contribua\Service\AvisoAoMaster;
use App\Entity\Auth\User;
use App\Entity\Auth\UserTenant;
use App\Entity\Tenant\Tenant;
use App\Repository\UserRepository;
use App\Repository\UserTenantRepository;
use App\Service\NotificacaoService;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

#[CoversClass(AvisoAoMaster::class)]
final class AvisoAoMasterTest extends TestCase
{
    #[TestDox('Mensagem longa (nome, escritório e texto grandes) é cortada em 255')]
    public function testCabeEm255(): void
    {
        $longa = str_repeat('á', 300);

        self::assertSame(255, mb_strlen(AvisoAoMaster::caber($longa)));
        self::assertStringEndsWith('...', AvisoAoMaster::caber($longa));
        self::assertSame('curta', AvisoAoMaster::caber('curta'));
    }

    #[TestDox('Falha ao criar a notificação não sobe: a ação da pessoa já está gravada')]
    public function testFalhaNaoSobe(): void
    {
        $escritorio = new Tenant();
        $master = new User();
        $usuarios = $this->createStub(UserRepository::class);
        $usuarios->method('findComPapelGlobal')->willReturn([$master]);
        $vinculos = $this->createStub(UserTenantRepository::class);
        $vinculos->method('findActiveByUser')->willReturn([new UserTenant($master, $escritorio)]);
        $notificacoes = $this->createMock(NotificacaoService::class);
        $notificacoes->expects(self::once())->method('criarNotificacao')
            ->with($master, $escritorio, 'contribua.sugestao', self::callback(static fn (string $m): bool => mb_strlen($m) <= 255))
            ->willThrowException(new \RuntimeException('value too long'));

        (new AvisoAoMaster($usuarios, $vinculos, $notificacoes, new NullLogger()))
            ->avisar($escritorio, 'contribua.sugestao', str_repeat('x', 400), '/contribua#sug');

        $this->addToAssertionCount(1);
    }
}
