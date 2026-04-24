<?php
declare(strict_types=1);
namespace App\Tests\Profile\Unit;

use App\Entity\Auth\User;
use App\Profile\DTO\AtualizarStatusInput;
use App\Profile\Entity\UserProfile;
use App\Profile\Repository\UserProfileRepository;
use App\Profile\UseCase\AtualizarStatusUseCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

#[CoversClass(AtualizarStatusUseCase::class)]
final class AtualizarStatusUseCaseTest extends TestCase
{
    private UserProfileRepository&MockObject $repository;
    private AtualizarStatusUseCase $sut;
    private UserProfile $perfil;

    protected function setUp(): void
    {
        $this->repository = $this->createMock(UserProfileRepository::class);
        $this->sut = new AtualizarStatusUseCase($this->repository);
        $this->perfil = new UserProfile($this->createStub(User::class));
    }

    #[TestDox('Texto válido persiste sem truncar e chama salvar')]
    public function testTextoValidoPersiste(): void
    {
        $this->repository->expects($this->once())->method('salvar');

        $this->sut->executar($this->perfil, new AtualizarStatusInput('Trabalhando remotamente'));

        self::assertSame('Trabalhando remotamente', $this->perfil->getStatus());
    }

    #[TestDox('String vazia define status como null')]
    public function testStringVaziaDefineNull(): void
    {
        $this->repository->expects($this->once())->method('salvar');

        $this->sut->executar($this->perfil, new AtualizarStatusInput(''));

        self::assertNull($this->perfil->getStatus());
    }

    #[TestDox('Null define status como null')]
    public function testNullDefineNull(): void
    {
        $this->repository->expects($this->once())->method('salvar');

        $this->sut->executar($this->perfil, new AtualizarStatusInput(null));

        self::assertNull($this->perfil->getStatus());
    }

    #[TestDox('Exatamente 255 caracteres mantém texto íntegro')]
    public function testExatamente255Caracteres(): void
    {
        $texto = str_repeat('a', 255);
        $this->repository->method('salvar');

        $this->sut->executar($this->perfil, new AtualizarStatusInput($texto));

        self::assertSame($texto, $this->perfil->getStatus());
    }

    #[TestDox('Mais de 255 caracteres trunca em 255')]
    public function testMaisDe255CaracteresTruncaEm255(): void
    {
        $this->repository->method('salvar');

        $this->sut->executar($this->perfil, new AtualizarStatusInput(str_repeat('b', 300)));

        self::assertSame(255, mb_strlen((string) $this->perfil->getStatus()));
    }

    #[TestDox('Texto multibyte trunca corretamente em 255 chars')]
    public function testMultibyteAteLimite(): void
    {
        $this->repository->method('salvar');
        $texto = str_repeat('ã', 255);

        $this->sut->executar($this->perfil, new AtualizarStatusInput($texto));

        self::assertSame(255, mb_strlen((string) $this->perfil->getStatus()));
        self::assertSame($texto, $this->perfil->getStatus());
    }

    #[TestDox('Texto multibyte acima do limite trunca em 255')]
    public function testMultibyteAcimaDoLimiteTruncaEm255(): void
    {
        $this->repository->method('salvar');

        $this->sut->executar($this->perfil, new AtualizarStatusInput(str_repeat('é', 300)));

        self::assertSame(255, mb_strlen((string) $this->perfil->getStatus()));
    }

    #[TestDox('Substituir status existente por novo valor')]
    public function testSubstituiStatusExistente(): void
    {
        $this->perfil->setStatus('status antigo');
        $this->repository->method('salvar');

        $this->sut->executar($this->perfil, new AtualizarStatusInput('status novo'));

        self::assertSame('status novo', $this->perfil->getStatus());
    }
}
