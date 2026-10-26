<?php

declare(strict_types=1);

namespace App\Tests\Recompensa\Functional;

use App\Entity\Auth\User;
use App\Recompensa\Entity\LancamentoDeRecompensa;
use App\Recompensa\Repository\LancamentoDeRecompensaRepository;
use App\Shared\Audit\EscopoDeAuditoriaDePlataforma;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\TestDox;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/** O livro de lançamentos contra o banco (DAMA desfaz no fim de cada teste): chave única, soma, ordem, SET NULL e imutabilidade. */
#[CoversClass(LancamentoDeRecompensaRepository::class)]
#[CoversClass(LancamentoDeRecompensa::class)]
final class LivroDeLancamentosNoBancoTest extends KernelTestCase
{
    private EntityManagerInterface $em;
    private LancamentoDeRecompensaRepository $repo;
    private int $n = 0;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->em = static::getContainer()->get(EntityManagerInterface::class);
        $this->repo = static::getContainer()->get(LancamentoDeRecompensaRepository::class);
    }

    private function usuario(string $nome): User
    {
        $user = new User();
        $user->setEmail(strtolower(uniqid('lv_')) . '@exemplo.com');
        $user->setFullName($nome);
        $user->setPassword('x');
        $this->em->persist($user);
        $this->em->flush();

        return $user;
    }

    private function lancar(User $user, int $valor, ?string $chave = null, string $quando = '2026-10-26 12:00:00'): LancamentoDeRecompensa
    {
        $l = new LancamentoDeRecompensa($user, 'bonus', $valor, null, $chave ?? sprintf('chave-do-livro-%04d', ++$this->n), null, new \DateTimeImmutable($quando));
        static::getContainer()->get(EscopoDeAuditoriaDePlataforma::class)->executar(function () use ($l): void {
            $this->repo->salvar($l, flush: true);
        });

        return $l;
    }

    #[TestDox('a chave de idempotência é única no banco; buscarPorChave a encontra e a trava de chave executa')]
    public function testChaveUnica(): void
    {
        $ana = $this->usuario('Ana');
        $this->lancar($ana, 1000, 'chave-repetida-0001');

        $this->repo->travarChave('chave-repetida-0001');
        self::assertSame(1000, $this->repo->buscarPorChave('chave-repetida-0001')?->getValorCentavos());
        self::assertNull($this->repo->buscarPorChave('chave-que-nao-existe'));
        $this->expectException(UniqueConstraintViolationException::class);
        $this->em->getConnection()->executeStatement(
            "INSERT INTO lancamento_de_recompensa (tipo, valor_centavos, chave_de_idempotencia, criado_em) VALUES ('bonus', 5, 'chave-repetida-0001', now())",
        );
    }

    #[TestDox('o banco recusa valor zero e acima de R$ 100.000,00 mesmo fora da entidade')]
    public function testBancoRecusaValorInvalido(): void
    {
        foreach ([0, 10000001, -10000001] as $valor) {
            $this->em->getConnection()->executeStatement('SAVEPOINT bj_teste');
            try {
                $this->em->getConnection()->executeStatement(
                    "INSERT INTO lancamento_de_recompensa (tipo, valor_centavos, chave_de_idempotencia, criado_em) VALUES ('bonus', ?, ?, now())",
                    [$valor, 'chave-invalida-' . $valor],
                );
                self::fail('devia recusar ' . $valor);
            } catch (\Doctrine\DBAL\Exception\DriverException $e) {
                $this->addToAssertionCount(1);
                $this->em->getConnection()->executeStatement('ROLLBACK TO SAVEPOINT bj_teste');
            }
        }
    }

    #[TestDox('soma por indicador: crédito e estorno com sinal, cada um no seu; quem foi apagado (user nulo) fica de fora')]
    public function testSomaPorIndicador(): void
    {
        $ana = $this->usuario('Ana');
        $bia = $this->usuario('Bia');
        $this->lancar($ana, 2500);
        $this->lancar($ana, -1000);
        $this->lancar($bia, 300);
        $apagada = $this->usuario('Apagada');
        $this->lancar($apagada, 9999);
        $this->em->getConnection()->executeStatement('UPDATE lancamento_de_recompensa SET user_id = NULL WHERE user_id = ?', [$apagada->getId()]);

        $soma = $this->repo->somaDoBonusPorIndicador();

        self::assertSame(1500, $soma[(int) $ana->getId()]);
        self::assertSame(300, $soma[(int) $bia->getId()]);
        self::assertArrayNotHasKey(0, $soma);
        self::assertNotContains(9999, $soma);
    }

    #[TestDox('extrato: do mais novo ao mais antigo (empate pelo id), com limite e filtro por indicador')]
    public function testOrdemLimiteEFiltro(): void
    {
        $ana = $this->usuario('Ana');
        $bia = $this->usuario('Bia');
        $this->lancar($ana, 100, quando: '2026-10-01 10:00:00');
        $this->lancar($bia, 200, quando: '2026-10-03 10:00:00');
        $this->lancar($ana, 300, quando: '2026-10-03 10:00:00');
        $this->lancar($ana, 400, quando: '2026-10-02 10:00:00');
        $this->em->clear();

        $valores = static fn (array $ls): array => array_map(static fn (LancamentoDeRecompensa $l): int => $l->getValorCentavos(), $ls);

        $todos = $this->repo->doExtrato(null, null);
        self::assertSame([300, 200, 400, 100], array_slice($valores($todos), 0, 4));
        self::assertSame([300, 200], array_slice($valores($this->repo->doExtrato(null, 2)), 0, 2));
        self::assertSame([300, 400, 100], $valores($this->repo->doExtrato((int) $ana->getId(), null)));
        self::assertSame([200], $valores($this->repo->doExtrato((int) $bia->getId(), 60)));
    }

    #[TestDox('LGPD: apagar o usuário mantém o lançamento no extrato, sem nome (ON DELETE SET NULL)')]
    public function testApagarUsuarioMantemOLancamento(): void
    {
        $ana = $this->usuario('Ana');
        $this->lancar($ana, 700, 'chave-lgpd-0000001');
        $this->em->getConnection()->executeStatement('DELETE FROM "user" WHERE id = ?', [$ana->getId()]);
        $this->em->clear();

        $l = $this->repo->buscarPorChave('chave-lgpd-0000001');

        self::assertNotNull($l);
        self::assertNull($l->getUser());
        self::assertSame(700, $l->getValorCentavos());
    }

    #[TestDox('imutável: alterar a entidade carregada e dar flush não emite UPDATE')]
    public function testSemUpdate(): void
    {
        $ana = $this->usuario('Ana');
        $this->lancar($ana, 700, 'chave-imutavel-0001');
        $this->em->clear();
        $l = $this->repo->buscarPorChave('chave-imutavel-0001');
        self::assertNotNull($l);

        $ref = new \ReflectionProperty(LancamentoDeRecompensa::class, 'valorCentavos');
        $ref->setValue($l, 1);
        $this->em->flush();
        $this->em->clear();

        self::assertSame(700, $this->repo->buscarPorChave('chave-imutavel-0001')?->getValorCentavos());
    }
}
