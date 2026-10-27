<?php

declare(strict_types=1);

namespace App\Tests\Recompensa\Unit;

use App\Entity\Auth\User;
use App\Profile\Entity\UserProfile;
use App\Profile\Repository\UserProfileRepository;
use App\Recompensa\Repository\DadosDeRecebimentoDeRecompensaRepository;
use App\Recompensa\UseCase\ConsultarDadosDeRecebimentoUseCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;

#[CoversClass(ConsultarDadosDeRecebimentoUseCase::class)]
final class ConsultarDadosDeRecebimentoUseCaseTest extends TestCase
{
    private function caso(?UserProfile $perfil): ConsultarDadosDeRecebimentoUseCase
    {
        $perfis = $this->createStub(UserProfileRepository::class);
        $perfis->method('findOneBy')->willReturnCallback(fn (array $criterio): ?UserProfile => $criterio === ['user' => 7] ? $perfil : null);

        return new ConsultarDadosDeRecebimentoUseCase($this->createStub(DadosDeRecebimentoDeRecompensaRepository::class), $perfis);
    }

    #[TestDox('a sugestão é a chave que a própria pessoa informou no perfil; sem perfil ou sem chave é nula')]
    public function testChavePixDoPerfil(): void
    {
        $perfil = (new UserProfile($this->createStub(User::class)))->setChavePixRecompensa('  ana@pix.com.br ');

        self::assertSame('ana@pix.com.br', $this->caso($perfil)->chavePixDoPerfil(7));
        self::assertNull($this->caso(new UserProfile($this->createStub(User::class)))->chavePixDoPerfil(7));
        self::assertNull($this->caso(null)->chavePixDoPerfil(7));
    }
}
