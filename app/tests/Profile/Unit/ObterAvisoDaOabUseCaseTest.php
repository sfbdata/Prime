<?php

declare(strict_types=1);

namespace App\Tests\Profile\Unit;

use App\Auth\Entity\InscricaoOab;
use App\Auth\Enum\StatusOab;
use App\Auth\Enum\TipoInscricaoOab;
use App\Auth\Repository\InscricaoOabRepository;
use App\Entity\Auth\User;
use App\Profile\UseCase\ObterAvisoDaOabUseCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;

#[CoversClass(ObterAvisoDaOabUseCase::class)]
final class ObterAvisoDaOabUseCaseTest extends TestCase
{
    #[TestDox('a inscrição que o CNA não achou (Divergente) dá o aviso com número e UF')]
    public function testDivergenteAvisa(): void
    {
        $user = new User();
        $df   = $this->inscricao($user, 1, '81269', 'DF', TipoInscricaoOab::Principal, StatusOab::Divergente);
        $go   = $this->inscricao($user, 2, '700', 'GO', TipoInscricaoOab::Suplementar, StatusOab::Confirmada);

        self::assertSame(['id' => 1, 'numero' => '81269', 'uf' => 'DF', 'motivo' => 'nao_encontrada'], $this->sut([$df, $go])->executar($user));
    }

    #[TestDox('o motivo é o mesmo da Revisão do Master: nome lido no CNA dá "nome"; número fora da regra dá "formato"')]
    public function testMotivos(): void
    {
        $user = new User();
        $nome = new InscricaoOab($user, '81269', 'DF', TipoInscricaoOab::Principal);
        $nome->registrarVerificacao(StatusOab::Divergente, 'Outra Pessoa');
        $formato = $this->inscricao($user, 2, '12', 'GO', TipoInscricaoOab::Principal, StatusOab::Divergente);

        self::assertSame('nome', $this->sut([$nome])->executar($user)['motivo'] ?? null);
        self::assertSame('formato', $this->sut([$formato])->executar($user)['motivo'] ?? null);
    }

    #[TestDox('Pendente (ainda não consultada) e Confirmada não avisam')]
    public function testSemAviso(): void
    {
        $user = new User();
        $df   = $this->inscricao($user, 1, '81269', 'DF', TipoInscricaoOab::Principal, StatusOab::NaoVerificada);
        $go   = $this->inscricao($user, 2, '700', 'GO', TipoInscricaoOab::Suplementar, StatusOab::Confirmada);

        self::assertNull($this->sut([$df, $go])->executar($user));
        self::assertNull($this->sut([])->executar(new User()), 'sem OAB nenhuma');
    }

    #[TestDox('OAB só no espelho do User, Divergente, avisa sem id (o perfil cria a principal ao abrir)')]
    public function testSoNoEspelho(): void
    {
        $user = (new User())->setOabNumero('81269')->setOabUf('DF')->setOabStatus(StatusOab::Divergente);

        self::assertSame(['id' => null, 'numero' => '81269', 'uf' => 'DF', 'motivo' => 'nao_encontrada'], $this->sut([])->executar($user));
    }

    /** @param list<InscricaoOab> $lista */
    private function sut(array $lista): ObterAvisoDaOabUseCase
    {
        $repo = $this->createStub(InscricaoOabRepository::class);
        $repo->method('doUsuario')->willReturn($lista);

        return new ObterAvisoDaOabUseCase($repo);
    }

    private function inscricao(User $user, int $id, string $numero, string $uf, TipoInscricaoOab $tipo, StatusOab $status): InscricaoOab
    {
        $i = new InscricaoOab($user, $numero, $uf, $tipo);
        $i->registrarVerificacao($status, null);
        (new \ReflectionProperty(InscricaoOab::class, 'id'))->setValue($i, $id);

        return $i;
    }
}
