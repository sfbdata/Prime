<?php

declare(strict_types=1);

namespace App\Tests\Ponto\Unit;

use App\Entity\Auth\User;
use App\Entity\Auth\UserTenant;
use App\Entity\Tenant\Tenant;
use App\Ponto\Entity\BlocoJornada;
use App\Ponto\Entity\BlocoJornadaColaborador;
use App\Ponto\Entity\JornadaColaborador;
use App\Ponto\Entity\JornadaTenant;
use App\Ponto\Repository\JornadaColaboradorRepository;
use App\Ponto\Repository\JornadaTenantRepository;
use App\Ponto\Service\ChoqueDeJornadaEntreEscritorios;
use App\Ponto\Service\IntervaloDeTrabalho;
use App\Ponto\Service\IntervalosDeTrabalho;
use App\Ponto\Service\JornadaResolver;
use App\Repository\UserTenantRepository;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;

/**
 * O choque de horário entre escritórios (spec seguranca-b-jornada, regra 3): a pessoa em A contra os outros escritórios
 * onde ela registra ponto. B trabalha de segunda a sexta das 08:00 às 12:00.
 */
#[CoversClass(ChoqueDeJornadaEntreEscritorios::class)]
final class ChoqueDeJornadaEntreEscritoriosTest extends TestCase
{
    private User $pessoa;
    private Tenant $a;
    private Tenant $b;
    private bool $chaveEmB = true;

    /** @var array<int, JornadaColaborador> a jornada pessoal da pessoa em cada escritório, pelo id */
    private array $pessoais = [];

    /** @var array<int, JornadaTenant> a jornada padrão de cada escritório, pelo id */
    private array $padroes = [];

    /** @var list<UserTenant> */
    private array $vinculosDeA = [];

    protected function setUp(): void
    {
        $this->pessoa = (new User())->setFullName('Pessoa nos dois');
        $this->a = $this->escritorio(1);
        $this->b = $this->escritorio(2);
        $this->pessoais[2] = $this->pessoal([[1, 2, 3, 4, 5], '08:00', '12:00']);
    }

    #[TestDox('Proposta que bate com B: a mensagem traz o primeiro trecho de B que bate, sem o nome de B')]
    public function testPropostaQueBateComB(): void
    {
        $mensagem = $this->sut()->mensagem($this->pessoa, $this->a, [new IntervaloDeTrabalho(1, 600, 840)]);

        self::assertSame(sprintf(ChoqueDeJornadaEntreEscritorios::MENSAGEM_CHOQUE, '08:00', '12:00'), $mensagem);
    }

    #[TestDox('Encostar no horário de B, outro dia da semana ou B com a chave desligada: sem choque')]
    public function testSemChoque(): void
    {
        self::assertNull($this->sut()->mensagem($this->pessoa, $this->a, [new IntervaloDeTrabalho(1, 720, 900)]));
        self::assertNull($this->sut()->mensagem($this->pessoa, $this->a, [new IntervaloDeTrabalho(6, 480, 720)]));
        self::assertNull($this->sut()->mensagem($this->pessoa, $this->a, []));

        $this->chaveEmB = false;
        self::assertNull($this->sut()->mensagem($this->pessoa, $this->a, [new IntervaloDeTrabalho(1, 600, 840)]));
    }

    #[TestDox('Na batida: dentro do horário de hoje de B recusa; fora, ou em B com a chave desligada, segue')]
    public function testNaBatida(): void
    {
        $segundaAsNove = new \DateTimeImmutable('2026-10-19 09:00:00');

        self::assertSame(
            sprintf(ChoqueDeJornadaEntreEscritorios::MENSAGEM_BATIDA, '08:00', '12:00'),
            $this->sut()->naBatida($this->pessoa, $this->a, $segundaAsNove),
        );
        self::assertNull($this->sut()->naBatida($this->pessoa, $this->a, new \DateTimeImmutable('2026-10-19 12:30:00')));
        self::assertNull($this->sut()->naBatida($this->pessoa, $this->a, new \DateTimeImmutable('2026-10-18 09:00:00')), 'domingo');

        $this->chaveEmB = false;
        self::assertNull($this->sut()->naBatida($this->pessoa, $this->a, $segundaAsNove));
    }

    #[TestDox('Revisão 23/10: A das 09 às 13 e padrão de B das 09 às 18. Às 09:05 em A, às 14:00 em B e às 10:00 em B, passa')]
    public function testQuandoOsDoisCobremOMomentoABatidaPassa(): void
    {
        $this->pessoais = [1 => $this->pessoal([[1], '09:00', '13:00'])];
        $this->padroes[2] = (new JornadaTenant())->addBloco((new BlocoJornada())->setDiasSemana([1])->setEntrada('09:00')->setSaida('18:00'));

        self::assertNull($this->sut()->naBatida($this->pessoa, $this->a, new \DateTimeImmutable('2026-10-19 09:05:00')), 'A cobre o momento');
        self::assertNull($this->sut()->naBatida($this->pessoa, $this->b, new \DateTimeImmutable('2026-10-19 14:00:00')), 'A não trabalha às 14:00');
        // Decisão da revisão (23/10): às 10:00 os dois cobrem o momento, um choque que entrou sem conferência. B cobre,
        // então a batida em B passa; travar os dois lados prenderia a pessoa.
        self::assertNull($this->sut()->naBatida($this->pessoa, $this->b, new \DateTimeImmutable('2026-10-19 10:00:00')));
    }

    #[TestDox('O horário que é só de A recusa a batida em B')]
    public function testHorarioSoDeARecusaABatidaEmB(): void
    {
        $this->pessoais = [1 => $this->pessoal([[1], '07:00', '13:00'])];
        $this->padroes[2] = (new JornadaTenant())->addBloco((new BlocoJornada())->setDiasSemana([1])->setEntrada('09:00')->setSaida('18:00'));

        self::assertSame(
            sprintf(ChoqueDeJornadaEntreEscritorios::MENSAGEM_BATIDA, '07:00', '13:00'),
            $this->sut()->naBatida($this->pessoa, $this->b, new \DateTimeImmutable('2026-10-19 08:00:00')),
        );
    }

    #[TestDox('Padrão proposta em A: lista quem a usa e bate com B; quem tem jornada pessoal em blocos em A fica de fora')]
    public function testPadraoListaAsPessoasAfetadas(): void
    {
        $this->vinculosDeA = [new UserTenant($this->pessoa, $this->a)];
        $padrao = (new JornadaTenant())->addBloco((new BlocoJornada())->setDiasSemana([1])->setEntrada('09:00')->setSaida('18:00'));

        $afetadas = $this->sut()->pessoasEmChoqueComPadrao($this->a, $padrao);

        self::assertSame([['nome' => 'Pessoa nos dois', 'mensagem' => sprintf(ChoqueDeJornadaEntreEscritorios::MENSAGEM_CHOQUE, '08:00', '12:00')]], $afetadas);

        $this->pessoais[1] = $this->pessoal([[1], '13:00', '17:00']);
        self::assertSame([], $this->sut()->pessoasEmChoqueComPadrao($this->a, $padrao));
    }

    private function sut(): ChoqueDeJornadaEntreEscritorios
    {
        $jornadas = $this->createStub(JornadaColaboradorRepository::class);
        $jornadas->method('findByUserETenant')->willReturnCallback(
            fn (User $u, Tenant $t): ?JornadaColaborador => $this->pessoais[(int) $t->getId()] ?? null
        );
        $vinculos = $this->createStub(UserTenantRepository::class);
        $vinculos->method('findActiveByUser')->willReturnCallback(fn (): array => $this->vinculosDaPessoa());
        $vinculos->method('findAtivosQueRegistramPonto')->willReturnCallback(fn (): array => $this->vinculosDeA);
        $padroes = $this->createStub(JornadaTenantRepository::class);
        $padroes->method('doEscritorio')->willReturnCallback(fn (Tenant $t): ?JornadaTenant => $this->padroes[(int) $t->getId()] ?? null);
        $intervalos = new IntervalosDeTrabalho(new JornadaResolver(), $jornadas, $padroes);

        return new ChoqueDeJornadaEntreEscritorios($intervalos, $jornadas, $vinculos);
    }

    /** @return list<UserTenant> */
    private function vinculosDaPessoa(): array
    {
        $emB = new UserTenant($this->pessoa, $this->b);
        $emB->alterarRegistroDePonto($this->chaveEmB);

        return [new UserTenant($this->pessoa, $this->a), $emB];
    }

    private function escritorio(int $id): Tenant
    {
        $tenant = $this->createStub(Tenant::class);
        $tenant->method('getId')->willReturn($id);

        return $tenant;
    }

    /** @param array{0: list<int>, 1: string, 2: string} $bloco */
    private function pessoal(array $bloco): JornadaColaborador
    {
        [$dias, $entrada, $saida] = $bloco;

        return (new JornadaColaborador())->addBloco(
            (new BlocoJornadaColaborador())->setDiasSemana($dias)->setEntrada($entrada)->setSaida($saida)
        );
    }
}
