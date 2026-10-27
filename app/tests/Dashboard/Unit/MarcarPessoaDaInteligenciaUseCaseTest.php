<?php

declare(strict_types=1);

namespace App\Tests\Dashboard\Unit;

use App\Dashboard\DTO\MarcarPessoaDaInteligenciaInput;
use App\Dashboard\Exception\ColaboradorForaDoEscritorioException;
use App\Dashboard\Exception\PreferenciaInvalidaException;
use App\Dashboard\Preferencia\MarcasDasPessoas;
use App\Dashboard\Repository\PreferenciaDoUsuarioRepository;
use App\Dashboard\UseCase\MarcarPessoaDaInteligenciaUseCase;
use App\Dashboard\UseCase\RegistrarAuditoriaDoDesempenhoIndividualUseCase;
use App\Entity\Audit\AuditLog;
use App\Entity\Auth\User;
use App\Entity\Tenant\Tenant;
use App\Repository\UserRepository;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

#[CoversClass(MarcarPessoaDaInteligenciaUseCase::class)]
#[CoversClass(RegistrarAuditoriaDoDesempenhoIndividualUseCase::class)]
#[Group('dashboard')]
final class MarcarPessoaDaInteligenciaUseCaseTest extends TestCase
{
    private UserRepository&MockObject $users;
    private PreferenciaDoUsuarioRepository&MockObject $repo;
    private EntityManagerInterface&MockObject $em;
    private MarcarPessoaDaInteligenciaUseCase $sut;
    private Tenant $tenant;
    private User $usuario;

    protected function setUp(): void
    {
        $this->users   = $this->createMock(UserRepository::class);
        $this->repo    = $this->createMock(PreferenciaDoUsuarioRepository::class);
        $this->em      = $this->createMock(EntityManagerInterface::class);
        $this->sut     = new MarcarPessoaDaInteligenciaUseCase($this->users, $this->repo, new RegistrarAuditoriaDoDesempenhoIndividualUseCase($this->em));
        $this->tenant  = new Tenant();
        $this->usuario = new User();
    }

    #[TestDox('Marca a pessoa do escritório, grava para o usuário da sessão e audita sem texto livre')]
    public function testGravaEAudita(): void
    {
        $this->users->method('findColaboradorAtivoPorIdETenant')->with(7, $this->tenant)->willReturn(new User());
        $this->repo->method('valoresDoUsuario')->willReturn([]);
        $this->repo->expects($this->once())->method('gravar')->with($this->tenant, $this->usuario, MarcasDasPessoas::CHAVE, ['7' => ['status' => 'analisado', 'feedback' => null]]);
        $gravados = [];
        $this->em->method('persist')->willReturnCallback(static function (object $o) use (&$gravados): void {
            $gravados[] = $o;
        });

        $out = $this->sut->executar(new MarcarPessoaDaInteligenciaInput($this->tenant, $this->usuario, 7, 'analisado'));

        self::assertSame(['pessoa' => 7, 'status' => 'analisado', 'feedback' => null], $out->paraArray());
        self::assertCount(1, $gravados);
        self::assertInstanceOf(AuditLog::class, $gravados[0]);
        self::assertSame('ia_marca', $gravados[0]->getAction());
        self::assertLessThanOrEqual(10, strlen($gravados[0]->getAction()));
        self::assertSame(['evento' => 'analisado', 'pessoa_id' => 7], $gravados[0]->getChanges());
    }

    #[TestDox('Pessoa de outro escritório: exceção (404) e nada é gravado nem auditado')]
    public function testPessoaDeFora(): void
    {
        $this->users->method('findColaboradorAtivoPorIdETenant')->willReturn(null);
        $this->repo->expects($this->never())->method('gravar');
        $this->em->expects($this->never())->method('persist');

        $this->expectException(ColaboradorForaDoEscritorioException::class);
        $this->sut->executar(new MarcarPessoaDaInteligenciaInput($this->tenant, $this->usuario, 7, 'analisado'));
    }

    #[TestDox('Ação fora da lista: exceção e nada é gravado nem auditado')]
    public function testAcaoInvalida(): void
    {
        $this->users->method('findColaboradorAtivoPorIdETenant')->willReturn(new User());
        $this->repo->method('valoresDoUsuario')->willReturn([]);
        $this->repo->expects($this->never())->method('gravar');
        $this->em->expects($this->never())->method('persist');

        $this->expectException(PreferenciaInvalidaException::class);
        $this->sut->executar(new MarcarPessoaDaInteligenciaInput($this->tenant, $this->usuario, 7, 'apagar'));
    }
}
