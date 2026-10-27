<?php

declare(strict_types=1);

namespace App\Tests\Pasta\Unit;

use App\Entity\Auth\User;
use App\Entity\Tenant\Tenant;
use App\Pasta\Entity\Pasta;
use App\Pasta\Service\ContextoDaPecaDaPasta;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;

#[CoversClass(ContextoDaPecaDaPasta::class)]
#[Group('pasta')]
final class ContextoDaPecaDaPastaTest extends TestCase
{
    private function pasta(): Pasta
    {
        $tenant = (new Tenant())->setCidade('Brasília');
        $pasta  = new Pasta();
        $pasta->setTenant($tenant);
        $pasta->setNup('1391');
        $pasta->setNomeCliente('Fulano de Tal');

        return $pasta;
    }

    private function advogado(string $nome, ?string $uf, ?string $numero): User
    {
        $user = new User();
        $user->setFullName($nome);
        $user->setOabUf($uf);
        $user->setOabNumero($numero);

        return $user;
    }

    #[TestDox('Preenche as 8 variáveis: nome da pasta, NUP, cidade do escritório, data por extenso, advogado e OAB (UF número)')]
    public function testMonta(): void
    {
        $dados = (new ContextoDaPecaDaPasta())->montar($this->pasta(), $this->advogado('Dra. Ana', 'DF', '12345'), new \DateTimeImmutable('2026-10-27'));

        self::assertSame(['cliente', 'cpf', 'processo', 'pasta', 'cidade', 'data', 'advogado', 'oab'], array_keys($dados));
        self::assertSame('1391', $dados['pasta']);
        self::assertSame('Brasília', $dados['cidade']);
        self::assertSame('27 de outubro de 2026', $dados['data']);
        self::assertSame('Dra. Ana', $dados['advogado']);
        self::assertSame('DF 12345', $dados['oab']);
        self::assertSame('', $dados['cpf'], 'Sem cliente pessoa física não há CPF');
        self::assertSame('', $dados['processo'], 'Sem processo principal o campo fica vazio');
    }

    #[TestDox('O advogado é o responsável da pasta; sem ele, quem usa o modelo')]
    public function testAdvogado(): void
    {
        $pasta = $this->pasta();
        $logado = $this->advogado('Quem Usa', null, null);

        $semResponsavel = (new ContextoDaPecaDaPasta())->montar($pasta, $logado, new \DateTimeImmutable('2026-01-05'));
        self::assertSame('Quem Usa', $semResponsavel['advogado']);
        self::assertSame('', $semResponsavel['oab']);
        self::assertSame('5 de janeiro de 2026', $semResponsavel['data']);

        $pasta->setResponsavel($this->advogado('Dr. Responsável', 'SP', '999'));
        $comResponsavel = (new ContextoDaPecaDaPasta())->montar($pasta, $logado, new \DateTimeImmutable('2026-01-05'));
        self::assertSame('Dr. Responsável', $comResponsavel['advogado']);
        self::assertSame('SP 999', $comResponsavel['oab']);
    }
}
