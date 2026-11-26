<?php

declare(strict_types=1);

namespace App\Tests\Profile\Unit;

use App\Auth\Entity\InscricaoOab;
use App\Auth\Enum\StatusOab;
use App\Auth\Enum\TipoInscricaoOab;
use App\Auth\Repository\InscricaoOabRepository;
use App\Auth\DTO\ConsultaOabResultado;
use App\Auth\Service\EspelhoDaOabPrincipal;
use App\Auth\Service\ValidadorOab;
use App\Entity\Auth\User;
use App\Profile\DTO\InscricaoOabInput;
use App\Profile\UseCase\RemoverInscricaoOabUseCase;
use App\Profile\UseCase\SalvarInscricaoOabUseCase;
use App\Tests\Auth\Doubles\OabWebServiceClientFake;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;

#[CoversClass(SalvarInscricaoOabUseCase::class)]
#[CoversClass(RemoverInscricaoOabUseCase::class)]
#[CoversClass(EspelhoDaOabPrincipal::class)]
#[CoversClass(InscricaoOab::class)]
#[CoversClass(ValidadorOab::class)]
final class SalvarInscricaoOabUseCaseTest extends TestCase
{
    #[TestDox('primeira inscrição nasce principal (gravada pela troca ordenada), mesmo pedida como suplementar')]
    public function testPrimeira(): void
    {
        $user = new User();
        [$sut, $repo] = $this->sut($user, []);
        $repo->expects(self::once())->method('gravarComNovaPrincipal')
            ->with(self::callback(static fn (InscricaoOab $i): bool => [$i->getNumero(), $i->getUf()] === ['49613', 'DF']), []);

        self::assertSame(StatusOab::NaoVerificada, $sut->executar($user, new InscricaoOabInput(null, '49.613', 'df', TipoInscricaoOab::Suplementar)));
    }

    #[TestDox('nova principal vai pela gravação ordenada, que rebaixa a antiga antes')]
    public function testNovaPrincipal(): void
    {
        $user = new User();
        $df   = $this->inscricao($user, 1, '49613', 'DF', TipoInscricaoOab::Principal, StatusOab::Confirmada);
        [$sut, $repo] = $this->sut($user, [$df]);
        $repo->expects(self::once())->method('gravarComNovaPrincipal')
            ->with(self::callback(static fn (InscricaoOab $i): bool => $i->getUf() === 'GO'), [$df]);
        $repo->expects(self::never())->method('salvar');

        $sut->executar($user, new InscricaoOabInput(null, '700', 'GO', TipoInscricaoOab::Principal));
    }

    #[TestDox('mesmo número e UF: situação e data ficam e a validação não roda; devolve null')]
    public function testSemMudanca(): void
    {
        $user = new User();
        $df   = $this->inscricao($user, 1, '49613', 'DF', TipoInscricaoOab::Principal, StatusOab::Confirmada);
        [$sut] = $this->sut($user, [$df], (new OabWebServiceClientFake())->comResultado(new ConsultaOabResultado(false, null, null)));

        self::assertNull($sut->executar($user, new InscricaoOabInput(1, '49613', 'DF', TipoInscricaoOab::Principal)));
        self::assertSame(StatusOab::Confirmada, $df->getStatus());
        self::assertNotNull($df->getVerificadaEm());
    }

    /** @return iterable<string, array{0: string, 1: string, 2: string, 3: TipoInscricaoOab, 4: string}> */
    public static function recusas(): iterable
    {
        yield 'letra no número' => ['12A', 'SP', '', TipoInscricaoOab::Suplementar, 'somente números'];
        yield 'UF inexistente' => ['123', 'XX', '', TipoInscricaoOab::Suplementar, 'Escolha a UF'];
        yield 'UF repetida' => ['123', 'DF', '', TipoInscricaoOab::Suplementar, 'Já existe uma inscrição na OAB/DF'];
        yield 'sem principal' => ['49613', 'DF', '1', TipoInscricaoOab::Suplementar, 'É preciso ter uma inscrição principal'];
        yield 'id de outra pessoa' => ['123', 'SP', '99', TipoInscricaoOab::Suplementar, 'Inscrição não encontrada'];
    }

    #[DataProvider('recusas')]
    #[TestDox('recusa com a mensagem do desenho: $_dataName')]
    public function testRecusas(string $numero, string $uf, string $id, TipoInscricaoOab $tipo, string $mensagem): void
    {
        $user = new User();
        $df   = $this->inscricao($user, 1, '49613', 'DF', TipoInscricaoOab::Principal, StatusOab::Confirmada);
        [$sut, $repo] = $this->sut($user, [$df]);
        $repo->expects(self::never())->method('salvar');

        $this->expectException(\DomainException::class);
        $this->expectExceptionMessage($mensagem);
        $sut->executar($user, new InscricaoOabInput($id === '' ? null : (int) $id, $numero, $uf, $tipo));
    }

    #[TestDox('remover: suplementar sai; principal é recusada')]
    public function testRemover(): void
    {
        $user = new User();
        $df   = $this->inscricao($user, 1, '49613', 'DF', TipoInscricaoOab::Principal, StatusOab::Confirmada);
        $go   = $this->inscricao($user, 2, '700', 'GO', TipoInscricaoOab::Suplementar, StatusOab::NaoVerificada);
        $repo = $this->createMock(InscricaoOabRepository::class);
        $repo->method('daPessoa')->willReturnCallback(static fn (int $id): ?InscricaoOab => [1 => $df, 2 => $go][$id] ?? null);
        $repo->expects(self::once())->method('remover')->with($go, true);
        $sut = new RemoverInscricaoOabUseCase($repo);

        self::assertSame('GO', $sut->executar($user, 2));
        $this->expectExceptionMessage('principal não pode ser removida');
        $sut->executar($user, 1);
    }

    #[TestDox('corrigir o número de uma inscrição não encontrada valida de novo na hora: o CNA confirma e o espelho acompanha')]
    public function testCorrecaoRevalida(): void
    {
        $user = (new User())->setFullName('Ana Paula Souza');
        $df   = $this->inscricao($user, 1, '81269', 'DF', TipoInscricaoOab::Principal, StatusOab::Divergente);
        $cna  = (new OabWebServiceClientFake())->comResultado(new ConsultaOabResultado(true, 'ANA PAULA DE SOUZA', 'Regular'));
        [$sut, $repo] = $this->sut($user, [$df], $cna);
        $repo->expects(self::once())->method('salvar')->with($df, true);

        self::assertSame(StatusOab::Confirmada, $sut->executar($user, new InscricaoOabInput(1, '82269', 'DF', TipoInscricaoOab::Principal)));
        self::assertSame(['82269', StatusOab::Confirmada, 'ANA PAULA DE SOUZA'], [$df->getNumero(), $df->getStatus(), $df->getNomeOficial()]);
        self::assertNotNull($df->getVerificadaEm());
        self::assertSame(['82269', StatusOab::Confirmada], [$user->getOabNumero(), $user->getOabStatus()]);
    }

    #[TestDox('número novo que o CNA não acha registra "não encontrada": Divergente, sem nome, com data')]
    public function testNumeroNovoNaoEncontrado(): void
    {
        $user = (new User())->setFullName('Ana Paula Souza');
        $df   = $this->inscricao($user, 1, '82269', 'DF', TipoInscricaoOab::Principal, StatusOab::Confirmada);
        [$sut] = $this->sut($user, [$df], (new OabWebServiceClientFake())->comResultado(new ConsultaOabResultado(false, null, null)));

        self::assertSame(StatusOab::Divergente, $sut->executar($user, new InscricaoOabInput(1, '81269', 'DF', TipoInscricaoOab::Principal)));
        self::assertSame([StatusOab::Divergente, null], [$df->getStatus(), $df->getNomeOficial()]);
        self::assertNotNull($df->getVerificadaEm(), 'resultado registrado');
    }

    #[TestDox('consulta que falha (CNA dormente) deixa a inscrição corrigida Pendente, sem data: não vira "não encontrada"')]
    public function testConsultaQueFalhaFicaPendente(): void
    {
        $user = (new User())->setFullName('Ana Paula Souza');
        $df   = $this->inscricao($user, 1, '81269', 'DF', TipoInscricaoOab::Principal, StatusOab::Divergente);
        [$sut] = $this->sut($user, [$df]);

        self::assertSame(StatusOab::NaoVerificada, $sut->executar($user, new InscricaoOabInput(1, '82269', 'DF', TipoInscricaoOab::Principal)));
        self::assertSame([StatusOab::NaoVerificada, null, null], [$df->getStatus(), $df->getNomeOficial(), $df->getVerificadaEm()]);
    }

    /**
     * @param list<InscricaoOab> $existentes
     *
     * @return array{0: SalvarInscricaoOabUseCase, 1: InscricaoOabRepository&\PHPUnit\Framework\MockObject\MockObject}
     */
    private function sut(User $user, array $existentes, ?OabWebServiceClientFake $cna = null): array
    {
        $repo = $this->createMock(InscricaoOabRepository::class);
        $repo->method('doUsuario')->willReturn($existentes);
        $validador = new ValidadorOab($cna ?? (new OabWebServiceClientFake())->indisponivel());

        return [new SalvarInscricaoOabUseCase($repo, new EspelhoDaOabPrincipal($repo), $validador), $repo];
    }

    private function inscricao(User $user, int $id, string $numero, string $uf, TipoInscricaoOab $tipo, StatusOab $status): InscricaoOab
    {
        $i = new InscricaoOab($user, $numero, $uf, $tipo);
        $i->registrarVerificacao($status, null);
        (new \ReflectionProperty(InscricaoOab::class, 'id'))->setValue($i, $id);

        return $i;
    }
}
