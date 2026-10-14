<?php

declare(strict_types=1);

namespace App\Tests\Central\Unit;

use App\Central\Service\MencoesDaCentral;
use App\Entity\Auth\User;
use App\Tests\Inteligencia\Support\DefineId;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;

/** @menção da Central A2 (`docs/specs/central-comunicacao-a2.md` §2): só quem é mencionável vira menção, com o nome do banco. */
#[CoversClass(MencoesDaCentral::class)]
final class MencoesDaCentralTest extends TestCase
{
    private function pessoa(int $id, string $nome): User
    {
        $u = new User();
        $u->setFullName($nome);
        DefineId::em($u, $id);

        return $u;
    }

    #[TestDox('Token de quem é mencionável fica, com o rótulo reescrito pelo nome do banco')]
    public function testRotuloReescrito(): void
    {
        $ana = $this->pessoa(2, 'Ana Souza');

        $r = (new MencoesDaCentral())->resolver('oi @[Diretor](user:2), veja', [2 => $ana]);

        self::assertSame('oi @[Ana Souza](user:2), veja', $r->conteudo);
        self::assertSame([$ana], $r->mencionados);
    }

    #[TestDox('Token de quem NÃO é mencionável (terceiro, outro escritório, o próprio autor) vira texto e não menciona')]
    public function testForaDaConversaViraTexto(): void
    {
        $r = (new MencoesDaCentral())->resolver('@[Bruno](user:9) e @[Eu](user:1)', [2 => $this->pessoa(2, 'Ana')]);

        self::assertSame('@Bruno e @Eu', $r->conteudo);
        self::assertSame([], $r->mencionados);
    }

    #[TestDox('A mesma pessoa duas vezes conta uma; passado o teto de 20 tokens, o resto vira texto')]
    public function testRepeticaoETeto(): void
    {
        $ana = $this->pessoa(2, 'Ana');
        $texto = str_repeat('@[x](user:9) ', 19) . '@[Ana](user:2) @[Ana](user:2)';

        $r = (new MencoesDaCentral())->resolver($texto, [2 => $ana]);

        self::assertSame([$ana], $r->mencionados);
        self::assertStringContainsString('@[Ana](user:2) @Ana', $r->conteudo);
    }

    #[TestDox('comoTexto troca cada token por @Nome — prévia, citação, cópia')]
    public function testComoTexto(): void
    {
        self::assertSame('oi @Ana Souza!', MencoesDaCentral::comoTexto('oi @[Ana Souza](user:2)!'));
    }
}
