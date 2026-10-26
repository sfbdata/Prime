<?php

declare(strict_types=1);

namespace App\Tests\Auth\Unit;

use App\Auth\DTO\IdentidadeDoFacebook;
use App\Auth\Exception\EntradaPeloFacebookRecusadaException;
use App\Auth\Service\ContaDoFacebook;
use App\Entity\Auth\User;
use App\Repository\UserRepository;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;

#[CoversClass(ContaDoFacebook::class)]
#[CoversClass(IdentidadeDoFacebook::class)]
final class ContaDoFacebookTest extends TestCase
{
    #[TestDox('Acha a conta só pelo facebook_id')]
    public function testLocalizaPeloId(): void
    {
        $conta = (new User())->setEmail('a@x.com');
        $repo = $this->createMock(UserRepository::class);
        $repo->expects($this->once())->method('findOneBy')->with(['facebookId' => 'fb-1'])->willReturn($conta);
        $repo->expects($this->never())->method('encontrarPorEmailIgnorandoCaixa');

        self::assertSame($conta, (new ContaDoFacebook($repo))->localizar(new IdentidadeDoFacebook('fb-1', 'Ana', 'outra@x.com')));
    }

    #[TestDox('Sem conta com o id: SEM_CONTA, mesmo que o e-mail do Facebook seja o de uma conta existente (nunca casa por e-mail)')]
    public function testNuncaPeloEmail(): void
    {
        $repo = $this->createMock(UserRepository::class);
        $repo->method('findOneBy')->willReturn(null);
        $repo->expects($this->never())->method('encontrarPorEmailIgnorandoCaixa');

        $this->expectException(EntradaPeloFacebookRecusadaException::class);
        $this->expectExceptionMessage(EntradaPeloFacebookRecusadaException::SEM_CONTA);

        (new ContaDoFacebook($repo))->localizar(new IdentidadeDoFacebook('fb-2', 'Ana', 'dona@x.com'));
    }

    #[TestDox('A conta não troca de id do Facebook depois de ligada')]
    public function testNaoTrocaDeId(): void
    {
        $conta = new User();
        $conta->ligarAoFacebook('f1');
        $conta->ligarAoFacebook('f1');
        self::assertSame('f1', $conta->getFacebookId());

        $this->expectException(\DomainException::class);
        $conta->ligarAoFacebook('f2');
    }

    #[TestDox('A identidade vai e volta da sessão; sem id ou lixo vira null; e-mail vazio vira null')]
    public function testSessao(): void
    {
        $ida = new IdentidadeDoFacebook('fb-3', 'Ana Souza', 'ana@x.com');
        $volta = IdentidadeDoFacebook::daSessao($ida->paraSessao());
        self::assertSame('fb-3', $volta?->id);
        self::assertSame('Ana Souza', $volta?->nome);
        self::assertSame('ana@x.com', $volta?->email);
        self::assertNull(IdentidadeDoFacebook::daSessao(['email' => '']));
        self::assertNull(IdentidadeDoFacebook::daSessao(['id' => ''])?->id);
        self::assertNull(IdentidadeDoFacebook::daSessao(['id' => 'x', 'email' => ''])?->email);
    }
}
