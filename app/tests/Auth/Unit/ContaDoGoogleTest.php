<?php

declare(strict_types=1);

namespace App\Tests\Auth\Unit;

use App\Auth\DTO\IdentidadeDoGoogle;
use App\Auth\Exception\EntradaPeloGoogleRecusadaException;
use App\Auth\Service\ContaDoGoogle;
use App\Entity\Auth\User;
use App\Repository\UserRepository;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;

#[CoversClass(ContaDoGoogle::class)]
final class ContaDoGoogleTest extends TestCase
{
    #[TestDox('Pelo sub primeiro; senão pelo e-mail ignorando a caixa; com duas contas que diferem só na caixa, a que bate exatamente')]
    public function testLocaliza(): void
    {
        $peloSub = (new User())->setEmail('a@x.com');
        $minusculo = (new User())->setEmail('pessoa@gmail.com');
        $maiusculo = (new User())->setEmail('Pessoa@gmail.com');

        self::assertSame($peloSub, $this->sut($peloSub, [])->localizar($this->identidade('qualquer@gmail.com')));
        self::assertSame($maiusculo, $this->sut(null, [$maiusculo])->localizar($this->identidade('pessoa@gmail.com')));
        self::assertSame($minusculo, $this->sut(null, [$maiusculo, $minusculo])->localizar($this->identidade('pessoa@gmail.com')));
    }

    #[TestDox('Sem conta, ou com duas contas sem nenhuma exata: recusa com "Não encontramos conta"; e-mail ligado a outro sub: recusa')]
    public function testRecusa(): void
    {
        $ligada = (new User())->setEmail('pessoa@gmail.com');
        $ligada->ligarAoGoogle('outro-sub');

        foreach ([[[], EntradaPeloGoogleRecusadaException::SEM_CONTA], [[(new User())->setEmail('PESSOA@gmail.com'), (new User())->setEmail('Pessoa@gmail.com')], EntradaPeloGoogleRecusadaException::SEM_CONTA], [[$ligada], EntradaPeloGoogleRecusadaException::OUTRA_CONTA_GOOGLE]] as [$contas, $mensagem]) {
            try {
                $this->sut(null, $contas)->localizar($this->identidade('pessoa@gmail.com'));
                self::fail('devia recusar');
            } catch (EntradaPeloGoogleRecusadaException $e) {
                self::assertSame($mensagem, $e->getMessage());
            }
        }
    }

    #[TestDox('A conta não troca de conta Google depois de ligada')]
    public function testNaoTrocaDeSub(): void
    {
        $conta = new User();
        $conta->ligarAoGoogle('s1');
        $conta->ligarAoGoogle('s1');

        $this->expectException(\DomainException::class);
        $conta->ligarAoGoogle('s2');
    }

    /** @param list<User> $peloEmail */
    private function sut(?User $peloSub, array $peloEmail): ContaDoGoogle
    {
        $repo = $this->createStub(UserRepository::class);
        $repo->method('findOneBy')->willReturn($peloSub);
        $repo->method('encontrarPorEmailIgnorandoCaixa')->willReturn($peloEmail);

        return new ContaDoGoogle($repo);
    }

    private function identidade(string $email): IdentidadeDoGoogle
    {
        return new IdentidadeDoGoogle('sub-1', $email, 'Pessoa');
    }
}
