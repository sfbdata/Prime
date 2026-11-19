<?php

declare(strict_types=1);

namespace App\Tests\Conector\Integration;

use App\Conector\Command\LiberarConectorCommand;
use App\Conector\Entity\EscritorioDoConector;
use App\Conector\Enum\VersaoDoAceiteDoConector;
use App\Conector\Repository\EscritorioDoConectorRepository;
use App\Conector\Service\LiberacaoDoConector;
use App\Conector\UseCase\LiberarConectorNoEscritorioUseCase;
use App\Entity\Audit\AuditLog;
use App\Entity\Tenant\Tenant;
use App\Master\Service\RecursosDoSistema;
use App\Tests\Conector\Support\CenarioDaAutorizacaoTrait;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\TestDox;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * `conector:liberar <tenantId> --master=<email>` (spec mcp-remoto-oauth 13.1 e 14.a; 12.24): liga a liberação, o
 * "ligado" e o aceite vigente só do escritório alvo, com o Master como autor do aceite, e grava auditoria com o
 * escritório alvo. `--master` é obrigatório e precisa ser um Master ativo.
 */
#[CoversClass(LiberarConectorCommand::class)]
#[CoversClass(LiberarConectorNoEscritorioUseCase::class)]
final class LiberarConectorCommandTest extends KernelTestCase
{
    use CenarioDaAutorizacaoTrait;

    private function rodar(string $tenantId, ?string $master): CommandTester
    {
        $tester = new CommandTester((new Application(self::$kernel ?? self::bootKernel()))->find('conector:liberar'));
        $entrada = ['tenantId' => $tenantId];
        if ($master !== null) {
            $entrada['--master'] = $master;
        }
        $tester->execute($entrada);

        return $tester;
    }

    private function doEscritorio(Tenant $tenant): ?EscritorioDoConector
    {
        $this->em()->clear();
        $tenant = $this->em()->find(Tenant::class, $tenant->getId());
        self::assertInstanceOf(Tenant::class, $tenant);

        return static::getContainer()->get(EscritorioDoConectorRepository::class)->buscarDoEscritorio($tenant);
    }

    #[TestDox('liga só o escritório alvo, com o aceite vigente em nome do Master; o outro escritório não muda')]
    public function testLiberaSoOAlvo(): void
    {
        self::bootKernel();
        $alvo = $this->escritorio('Alvo');
        $outro = $this->escritorio('Outro');
        $master = $this->pessoa(['ROLE_SUPER_ADMIN']);

        $tester = $this->rodar((string) $alvo->getId(), strtoupper((string) $master->getEmail()));

        self::assertSame(Command::SUCCESS, $tester->getStatusCode(), $tester->getDisplay());
        $escritorio = $this->doEscritorio($alvo);
        self::assertInstanceOf(EscritorioDoConector::class, $escritorio);
        self::assertTrue($escritorio->ligadoComAceite());
        self::assertSame(VersaoDoAceiteDoConector::atual()->value, $escritorio->getAceiteVersao());
        self::assertSame($master->getId(), $escritorio->getAceitoPor()?->getId());
        self::assertNull($this->doEscritorio($outro), 'o outro escritório não ganhou linha');

        $liberacao = new LiberacaoDoConector(static::getContainer()->get(RecursosDoSistema::class), static::getContainer()->get(EscritorioDoConectorRepository::class));
        $liberacao->exigir($this->em()->find(Tenant::class, $alvo->getId()) ?? self::fail('alvo sumiu'));
    }

    #[TestDox('grava o audit_log com o tenant_id do escritório alvo')]
    public function testAuditoriaComOAlvo(): void
    {
        self::bootKernel();
        $alvo = $this->escritorio('Alvo');
        $master = $this->pessoa(['ROLE_SUPER_ADMIN']);

        $this->rodar((string) $alvo->getId(), (string) $master->getEmail());

        $registros = $this->em()->getRepository(AuditLog::class)->findBy(['entityClass' => EscritorioDoConector::class, 'tenantId' => $alvo->getId()]);
        self::assertCount(1, $registros);
        self::assertSame('create', $registros[0]->getAction());
    }

    #[TestDox('escritório já com linha (desligado): volta a ligado, sem linha nova')]
    public function testEscritorioJaComLinha(): void
    {
        self::bootKernel();
        $alvo = $this->escritorio('Alvo');
        $this->liberarConector($alvo, ligado: false);
        $master = $this->pessoa(['ROLE_SUPER_ADMIN']);

        $tester = $this->rodar((string) $alvo->getId(), (string) $master->getEmail());

        self::assertSame(Command::SUCCESS, $tester->getStatusCode());
        self::assertTrue($this->doEscritorio($alvo)?->ligadoComAceite());
        self::assertSame(1, $this->em()->getRepository(EscritorioDoConector::class)->count(['tenant' => $alvo->getId()]));
    }

    #[TestDox('sem --master, com e-mail de quem não é Master ou de Master desativado: recusa e não liga')]
    public function testExigeMaster(): void
    {
        self::bootKernel();
        $alvo = $this->escritorio('Alvo');
        $comum = $this->pessoa();
        $inativo = $this->pessoa(['ROLE_SUPER_ADMIN']);
        $inativo->setIsActive(false);
        $this->em()->flush();

        self::assertSame(Command::INVALID, $this->rodar((string) $alvo->getId(), null)->getStatusCode());
        foreach ([(string) $comum->getEmail(), (string) $inativo->getEmail(), 'ninguem@teste.com'] as $email) {
            $tester = $this->rodar((string) $alvo->getId(), $email);
            self::assertSame(Command::FAILURE, $tester->getStatusCode(), $email);
            self::assertStringContainsString('Master', $tester->getDisplay());
        }
        self::assertNull($this->doEscritorio($alvo));
    }

    #[TestDox('escritório inexistente, desativado ou id inválido: recusa')]
    public function testEscritorioInvalido(): void
    {
        self::bootKernel();
        $master = $this->pessoa(['ROLE_SUPER_ADMIN']);
        $desativado = $this->escritorio('Desativado');
        $desativado->setIsActive(false);
        $this->em()->flush();

        self::assertSame(Command::FAILURE, $this->rodar('999999999', (string) $master->getEmail())->getStatusCode());
        self::assertSame(Command::FAILURE, $this->rodar((string) $desativado->getId(), (string) $master->getEmail())->getStatusCode());
        self::assertSame(Command::INVALID, $this->rodar('abc', (string) $master->getEmail())->getStatusCode());
        self::assertNull($this->doEscritorio($desativado));
    }
}
