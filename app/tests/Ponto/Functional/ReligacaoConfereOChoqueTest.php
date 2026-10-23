<?php

declare(strict_types=1);

namespace App\Tests\Ponto\Functional;

use App\Entity\Auth\User;
use App\Entity\Auth\UserTenant;
use App\Entity\Tenant\Tenant;
use App\Master\DTO\SituacaoDaContaInput;
use App\Master\UseCase\ReativarContaUseCase;
use App\Ponto\Service\RegistroDePontoInicial;
use App\Tenant\Enum\SituacaoNaEquipe;
use App\Tenant\UseCase\AlterarSituacaoNaEquipeUseCase;
use App\Tests\Functional\JusPrimeWebTestCase;
use App\Tests\Ponto\Support\CenarioDeJornadaPorEscritorioTrait;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\TestDox;

/**
 * Religação de um vínculo com a chave do ponto ligada (spec seguranca-b-jornada §3.1): enquanto o vínculo ou o
 * escritório estavam fora do ar, o choque não os via. Ao voltar, a mesma conferência do vínculo novo: havendo choque, a
 * chave fica desligada. A pessoa trabalha em A de segunda das 09:00 às 12:00.
 */
#[CoversClass(RegistroDePontoInicial::class)]
#[CoversClass(AlterarSituacaoNaEquipeUseCase::class)]
#[CoversClass(ReativarContaUseCase::class)]
final class ReligacaoConfereOChoqueTest extends JusPrimeWebTestCase
{
    use CenarioDeJornadaPorEscritorioTrait;

    private Tenant $a;
    private Tenant $b;
    private User $pessoa;
    private User $master;
    private UserTenant $vinculoEmA;

    protected function setUp(): void
    {
        static::bootKernel();
        $this->a = $this->escritorio('Escritório A');
        $this->b = $this->escritorio('Escritório B');
        $this->pessoa = $this->pessoa('Pessoa nos dois');
        $this->master = $this->pessoa('Master', ['ROLE_SUPER_ADMIN']);
        $this->vinculoEmA = $this->vincular($this->pessoa, $this->a);
        $this->vincular($this->pessoa, $this->b);
        $this->jornadaPessoal($this->pessoa, $this->a, [[[1], '09:00', null, null, '12:00']]);
    }

    #[TestDox('Suspensa em A, B grava o mesmo horário; de volta a aprovada em A, a chave de A fica desligada')]
    public function testVoltaAAprovadoComChoqueDesliga(): void
    {
        $this->suspenderEmA();
        $this->jornadaPessoal($this->pessoa, $this->b, [[[1], '10:00', null, null, '11:00']]);

        $this->aprovarEmA();

        self::assertFalse($this->chaveEmA());
    }

    #[TestDox('Suspensa em A, B grava outro horário; de volta a aprovada em A, a chave continua ligada')]
    public function testVoltaAAprovadoSemChoqueContinuaLigada(): void
    {
        $this->suspenderEmA();
        $this->jornadaPessoal($this->pessoa, $this->b, [[[1], '14:00', null, null, '15:00']]);

        $this->aprovarEmA();

        self::assertTrue($this->chaveEmA());
    }

    #[TestDox('O Master reativa o escritório A, e B marcou o mesmo horário enquanto A estava fora do ar: a chave de A desliga')]
    public function testMasterReativaOEscritorio(): void
    {
        $this->a->setIsActive(false);
        $this->em()->flush();
        $this->jornadaPessoal($this->pessoa, $this->b, [[[1], '10:00', null, null, '11:00']]);

        static::getContainer()->get(ReativarContaUseCase::class)->executar(new SituacaoDaContaInput($this->master, (int) $this->a->getId()));

        self::assertFalse($this->chaveEmA());
    }

    private function suspenderEmA(): void
    {
        $this->vinculoEmA->alterarSituacao(SituacaoNaEquipe::Suspenso);
        $this->em()->flush();
    }

    private function aprovarEmA(): void
    {
        static::getContainer()->get(AlterarSituacaoNaEquipeUseCase::class)
            ->executar($this->master, $this->a, (int) $this->vinculoEmA->getId(), SituacaoNaEquipe::Aprovado);
    }

    private function chaveEmA(): bool
    {
        return (bool) $this->em()->getConnection()->fetchOne('SELECT registra_ponto FROM user_tenant WHERE id = ?', [$this->vinculoEmA->getId()]);
    }
}
