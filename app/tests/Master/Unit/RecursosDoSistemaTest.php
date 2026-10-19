<?php

declare(strict_types=1);

namespace App\Tests\Master\Unit;

use App\Master\Repository\RecursoEstadoRepository;
use App\Master\Service\RecursosDoSistema;
use App\Master\Service\RegraDosMasters;
use Doctrine\DBAL\Driver\Exception as ExcecaoDoDriver;
use Doctrine\DBAL\Exception\TableNotFoundException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;
use Psr\Log\AbstractLogger;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\HttpFoundation\RequestStack;

/**
 * Leitura do estado das telas e recursos (spec perfis-1.2-telas-e-recursos): robustez de deploy.
 */
#[CoversClass(RecursosDoSistema::class)]
final class RecursosDoSistemaTest extends TestCase
{
    #[TestDox('sem a tabela recurso_estado (migration pendente) tudo vale como ativo, com aviso no log')]
    public function testTabelaInexistenteViraTudoAtivo(): void
    {
        $repositorio = $this->createMock(RecursoEstadoRepository::class);
        $repositorio->expects($this->once())->method('todosPorChave')
            ->willThrowException(new TableNotFoundException($this->createStub(ExcecaoDoDriver::class), null));
        $log = new class () extends AbstractLogger {
            /** @var list<string> */
            public array $avisos = [];

            public function log($level, \Stringable|string $message, array $context = []): void
            {
                $this->avisos[] = $level . ': ' . $message;
            }
        };
        $recursos = new RecursosDoSistema($repositorio, new MockClock(), $this->createStub(Security::class), new RegraDosMasters(), new RequestStack(), $log);

        self::assertTrue($recursos->estaAtivo('dashboard'));
        self::assertTrue($recursos->visivel('metas'));
        self::assertSame('', $recursos->marca('metas'));
        self::assertCount(1, $log->avisos, 'uma leitura só (cache) e um aviso');
        self::assertStringStartsWith('warning: ', $log->avisos[0]);
    }
}
