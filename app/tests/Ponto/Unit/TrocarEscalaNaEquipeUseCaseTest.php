<?php

declare(strict_types=1);

namespace App\Tests\Ponto\Unit;

use App\Entity\Auth\User;
use App\Entity\Auth\UserTenant;
use App\Entity\Tenant\Tenant;
use App\Ponto\Entity\BlocoJornadaColaborador;
use App\Ponto\Entity\JornadaColaborador;
use App\Ponto\Exception\EscalaDesconhecidaException;
use App\Ponto\Exception\ForaDaEquipeException;
use App\Ponto\Repository\JornadaColaboradorRepository;
use App\Ponto\Repository\JornadaTenantRepository;
use App\Ponto\Service\ChoqueDaJornadaProposta;
use App\Ponto\Service\ChoqueDeJornadaEntreEscritorios;
use App\Ponto\Service\HistoricoDeJornadas;
use App\Ponto\Service\IntervalosDeTrabalho;
use App\Ponto\Service\JornadaResolver;
use App\Ponto\UseCase\SalvarVersaoDaJornadaUseCase;
use App\Ponto\UseCase\TrocarEscalaNaEquipeUseCase;
use App\Repository\UserRepository;
use App\Repository\UserTenantRepository;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Clock\MockClock;

/**
 * Trocar a escala de uma pessoa na aba Equipe (spec ponto-desenho-0811, E5, P56 a): grava uma versão da jornada
 * pessoal a partir de hoje com os blocos do modelo; quem não é da equipe e escala fora do catálogo não gravam nada.
 * O choque com outro escritório e o histórico pela ficha estão no teste funcional (TrocarEscalaNaEquipeTest).
 */
#[CoversClass(TrocarEscalaNaEquipeUseCase::class)]
#[Group('ponto')]
final class TrocarEscalaNaEquipeUseCaseTest extends TestCase
{
    private const AGORA = '2026-11-09 15:30:00';

    private UserRepository&MockObject $usuarios;
    private UserTenantRepository&MockObject $vinculos;
    private JornadaColaboradorRepository&MockObject $jornadas;
    private User $pessoa;
    private Tenant $escritorio;

    protected function setUp(): void
    {
        $this->usuarios = $this->createMock(UserRepository::class);
        $this->vinculos = $this->createMock(UserTenantRepository::class);
        $this->jornadas = $this->createMock(JornadaColaboradorRepository::class);
        $this->pessoa = (new User())->setEmail('ana@test.com');
        $this->escritorio = new Tenant();
    }

    #[TestDox('Pessoa que não existe ou sem vínculo ativo no escritório: fora da equipe, nada gravado')]
    public function testForaDaEquipe(): void
    {
        $this->usuarios->method('find')->willReturnOnConsecutiveCalls(null, $this->pessoa);
        $this->vinculos->method('findAtivoPorUserETenant')->willReturn(null);
        $this->jornadas->expects(self::never())->method('salvar');
        $this->jornadas->expects(self::never())->method('gravar');

        foreach ([404, 7] as $id) {
            try {
                $this->useCase()->executar($id, $this->escritorio, '44-6');
                self::fail('devia recusar a pessoa ' . $id);
            } catch (ForaDaEquipeException $recusa) {
                self::assertSame('Pessoa não encontrada na equipe deste escritório.', $recusa->getMessage());
            }
        }
    }

    #[TestDox('Escala fora do catálogo: recusada, nada gravado')]
    public function testEscalaDesconhecida(): void
    {
        $this->naEquipe();
        $this->jornadas->expects(self::never())->method('salvar');

        $this->expectException(EscalaDesconhecidaException::class);
        $this->expectExceptionMessage('Escolha uma das escalas da lista.');

        $this->useCase()->executar(7, $this->escritorio, '12x36');
    }

    #[TestDox('Sem jornada pessoal: a primeira versão vale a partir de hoje, com o modelo e o alerta ligado')]
    public function testPrimeiraVersao(): void
    {
        $this->naEquipe();
        $this->jornadas->method('historicoDe')->willReturn(null);
        $salva = null;
        $this->jornadas->expects(self::once())->method('salvar')->willReturnCallback(static function (JornadaColaborador $j) use (&$salva): void {
            $salva = $j;
        });
        $this->jornadas->expects(self::once())->method('gravar');

        $jornada = $this->useCase()->executar(7, $this->escritorio, '44-6');

        self::assertSame($salva, $jornada);
        self::assertSame('2026-11-09 00:00', $jornada->getValeAPartirDe()->format('Y-m-d H:i'));
        self::assertFalse($jornada->cobreDiasAnteriores(), 'os dias de antes não mudam');
        self::assertSame(2640, $jornada->getCargaHorariaSemanal());
        self::assertTrue($jornada->isAlertaHabilitado());
        self::assertSame($this->pessoa, $jornada->getUser());
        self::assertSame($this->escritorio, $jornada->getTenant());
        $blocos = $jornada->getBlocos()->toArray();
        self::assertCount(1, $blocos);
        self::assertSame([[1, 2, 3, 4, 5, 6], '09:00', '12:00', '13:00', '17:20', 440], self::bloco(array_values($blocos)[0]));
    }

    #[TestDox('Com jornada pessoal: versão nova a partir de hoje; a de antes fica e o alerta segue como estava')]
    public function testVersaoNova(): void
    {
        $this->naEquipe();
        $antiga = (new JornadaColaborador())->setUser($this->pessoa)->setTenant($this->escritorio)
            ->setValeAPartirDe(new \DateTimeImmutable('2026-01-05'))->setCargaHorariaSemanal(2400)->setAlertaHabilitado(false);
        $this->jornadas->method('historicoDe')->willReturn(HistoricoDeJornadas::das([$antiga]));

        $nova = $this->useCase()->executar(7, $this->escritorio, '30-5');

        self::assertNotSame($antiga, $nova);
        self::assertSame('2026-01-05', $antiga->getValeAPartirDe()->format('Y-m-d'));
        self::assertSame(2400, $antiga->getCargaHorariaSemanal());
        self::assertSame('2026-11-09', $nova->getValeAPartirDe()->format('Y-m-d'));
        self::assertSame(1800, $nova->getCargaHorariaSemanal());
        self::assertFalse($nova->isAlertaHabilitado(), 'o alerta segue como estava');
        self::assertSame([[1, 2, 3, 4, 5], '09:00', '12:00', '13:00', '16:00', 360], self::bloco(array_values($nova->getBlocos()->toArray())[0]));
    }

    private function naEquipe(): void
    {
        $this->usuarios->method('find')->willReturn($this->pessoa);
        // Chave do ponto desligada: a conferência de choque não se aplica (ela tem teste próprio, funcional).
        $vinculo = new UserTenant($this->pessoa, $this->escritorio);
        $vinculo->alterarRegistroDePonto(false);
        $this->vinculos->method('findAtivoPorUserETenant')->willReturn($vinculo);
    }

    private function useCase(): TrocarEscalaNaEquipeUseCase
    {
        $padroes = $this->createStub(JornadaTenantRepository::class);
        $intervalos = new IntervalosDeTrabalho(new JornadaResolver(), $this->jornadas, $padroes);
        $choque = new ChoqueDaJornadaProposta(
            $this->vinculos,
            $intervalos,
            new ChoqueDeJornadaEntreEscritorios($intervalos, $this->jornadas, $this->vinculos),
            $padroes,
        );

        return new TrocarEscalaNaEquipeUseCase(
            $this->usuarios,
            $this->vinculos,
            $this->jornadas,
            $choque,
            new SalvarVersaoDaJornadaUseCase($this->jornadas),
            new MockClock(self::AGORA),
        );
    }

    /** @return array{list<int>, ?string, ?string, ?string, ?string, ?int} */
    private static function bloco(BlocoJornadaColaborador $bloco): array
    {
        return [$bloco->getDiasSemana(), $bloco->getEntrada(), $bloco->getRepouso(), $bloco->getRetorno(), $bloco->getSaida(), $bloco->getMinutosBloco()];
    }
}
