<?php

declare(strict_types=1);

namespace App\Tests\Ponto\Functional;

use App\Entity\Auth\User;
use App\Entity\Auth\UserTenant;
use App\Entity\Tenant\Tenant;
use App\Ponto\Entity\JustificativaPonto;
use App\Ponto\Exception\JustificativaJaAnalisadaException;
use App\Ponto\Repository\JustificativaPontoRepository;
use App\Ponto\UseCase\ConfirmarEdicaoDeJustificativaUseCase;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\TestDox;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * C2-01 — a metade da regra que a rota não enxerga: a análise do admin que comita DEPOIS de a
 * justificativa ser carregada e ANTES de o colaborador gravar. Contra o banco de teste real;
 * "nada gravado" é conferido pela conexão, porque na recusa o EntityManager fecha.
 */
#[CoversClass(ConfirmarEdicaoDeJustificativaUseCase::class)]
#[CoversClass(JustificativaPontoRepository::class)]
final class ConfirmarEdicaoDeJustificativaUseCaseTest extends KernelTestCase
{
    #[TestDox('Pendente no banco: grava o que a rota editou, e o status continua pendente')]
    public function testPendenteGrava(): void
    {
        self::bootKernel();
        [$tenant, $user] = $this->cenario();
        $j               = $this->justificativa($tenant, $user, 'pendente');

        $j->setTipo('atestado_medico');
        $j->setAbonoParcial(true);
        $j->setHoraInicioAbono(new \DateTime('14:00'));
        $j->setHoraFimAbono(new \DateTime('16:00'));
        $this->useCase()->executar($j, $tenant);

        $linha = $this->linha($j);
        self::assertSame('atestado_medico', $linha['tipo']);
        self::assertTrue((bool) $linha['abono_parcial']);
        self::assertSame('pendente', $linha['status']);
    }

    /** @return iterable<string, array{string}> */
    public static function analisesConcorrentes(): iterable
    {
        yield 'admin abonou' => ['abonado'];
        yield 'admin rejeitou' => ['rejeitado'];
    }

    /**
     * A corrida que importa: a entidade chega `pendente` (EntityValueResolver) e o admin analisa
     * antes de a edição ser gravada. O identity map não relê campos — decidir pelo getter gravaria o
     * tipo novo numa justificativa já abonada.
     */
    #[TestDox('Admin analisa depois do carregamento ($_dataName): a decisão usa o banco e nada é gravado')]
    #[DataProvider('analisesConcorrentes')]
    public function testAnaliseDepoisDoCarregamentoEhVista(string $statusDoAdmin): void
    {
        self::bootKernel();
        [$tenant, $user] = $this->cenario();
        $j               = $this->justificativa($tenant, $user, 'pendente');

        // Por fora do UnitOfWork, como faria a transação do admin: em memória segue pendente.
        $this->em()->getConnection()->executeStatement(
            'UPDATE justificativa_ponto SET status = ? WHERE id = ?',
            [$statusDoAdmin, $j->getId()],
        );
        self::assertSame('pendente', $j->getStatus(), 'premissa: a entidade em memória está velha');

        $j->setTipo('ajuste_jornada');
        $j->setAbonoParcial(true);
        $j->setHoraInicioAbono(new \DateTime('00:00'));
        $j->setHoraFimAbono(new \DateTime('23:59'));

        $capturada = null;
        try {
            $this->useCase()->executar($j, $tenant);
        } catch (JustificativaJaAnalisadaException $e) {
            $capturada = $e;
        }

        self::assertNotNull($capturada, 'a edição de uma justificativa analisada tem de ser recusada');
        $linha = $this->linha($j);
        self::assertSame('licenca', $linha['tipo'], 'nada do que a rota editou chega ao banco');
        self::assertFalse((bool) $linha['abono_parcial']);
        self::assertNull($linha['hora_inicio_abono']);
        self::assertSame($statusDoAdmin, $linha['status'], 'a decisão do admin fica como está');
    }

    /**
     * A análise do admin não pega trava. O que a serializa com a edição é a trava de LINHA da leitura
     * do status, que tem de vir ANTES da gravação, na mesma transação. Sob o DAMA não há segunda
     * conexão que enxergue a fixture, então a prova é a sequência que o banco recebeu.
     */
    #[TestDox('O status é lido com FOR UPDATE, antes do UPDATE da edição')]
    public function testLeituraTravadaVemAntesDaGravacao(): void
    {
        self::bootKernel();
        [$tenant, $user] = $this->cenario();
        $j               = $this->justificativa($tenant, $user, 'pendente');

        $coletor = static::getContainer()->get('doctrine.debug_data_holder');
        $coletor->reset();

        $j->setTipo('atestado_medico');
        $this->useCase()->executar($j, $tenant);

        $consultas = array_column($coletor->getData()['default'] ?? [], 'sql');
        $leitura   = $this->primeiroIndice($consultas, '/^SELECT\s+\S+\.status\b.*\bFROM\s+justificativa_ponto\b/is');
        $gravacao  = $this->primeiroIndice($consultas, '/^UPDATE\s+justificativa_ponto\b/i');

        self::assertNotNull($leitura, 'a decisão lê o status pelo banco');
        self::assertMatchesRegularExpression('/\bFOR\s+UPDATE\b/i', $consultas[$leitura]);
        self::assertNotNull($gravacao);
        self::assertLessThan($gravacao, $leitura, 'a trava vem antes da gravação');
    }

    #[TestDox('Justificativa de outro escritório: LogicException antes de tocar o banco')]
    public function testOutroEscritorioEhRecusado(): void
    {
        self::bootKernel();
        [$tenant, $user] = $this->cenario();
        [$outro]         = $this->cenario();
        $j               = $this->justificativa($tenant, $user, 'pendente');
        $j->setTipo('atestado_medico');

        $this->expectException(\LogicException::class);

        try {
            $this->useCase()->executar($j, $outro);
        } finally {
            self::assertSame('licenca', $this->linha($j)['tipo']);
        }
    }

    /** Sumir sob a trava é premissa quebrada — nunca "nada a recusar, pode gravar". */
    #[TestDox('Registro que some sob a trava: LogicException, sem tratar como editável')]
    public function testRegistroQueSumiuEhPremissaQuebrada(): void
    {
        self::bootKernel();
        [$tenant, $user] = $this->cenario();
        $j               = $this->justificativa($tenant, $user, 'pendente');
        $this->em()->getConnection()->executeStatement('DELETE FROM justificativa_ponto WHERE id = ?', [$j->getId()]);
        $j->setTipo('atestado_medico');

        $capturada = null;
        try {
            $this->useCase()->executar($j, $tenant);
        } catch (\LogicException $e) {
            $capturada = $e;
        }

        self::assertNotNull($capturada);
        self::assertNotInstanceOf(JustificativaJaAnalisadaException::class, $capturada);
    }

    /** O filtro de tenant da leitura travada é explícito — não depende do TenantFilter. */
    #[TestDox('A leitura travada só enxerga o registro dentro do escritório informado')]
    public function testLeituraTravadaFiltraPorEscritorio(): void
    {
        self::bootKernel();
        [$tenant, $user] = $this->cenario();
        [$outro]         = $this->cenario();
        $j               = $this->justificativa($tenant, $user, 'abonado');
        $repositorio     = static::getContainer()->get(JustificativaPontoRepository::class);

        [$noDono, $noOutro] = $this->em()->wrapInTransaction(static fn (): array => [
            $repositorio->statusNoBancoTravadoPorId((int) $j->getId(), $tenant),
            $repositorio->statusNoBancoTravadoPorId((int) $j->getId(), $outro),
        ]);

        self::assertSame('abonado', $noDono);
        self::assertNull($noOutro);
    }

    // ------------------------------------------------------------------ helpers

    /**
     * @param list<string> $consultas
     */
    private function primeiroIndice(array $consultas, string $padrao): ?int
    {
        foreach ($consultas as $i => $sql) {
            if (preg_match($padrao, $sql) === 1) {
                return $i;
            }
        }

        return null;
    }

    private function useCase(): ConfirmarEdicaoDeJustificativaUseCase
    {
        return static::getContainer()->get(ConfirmarEdicaoDeJustificativaUseCase::class);
    }

    private function em(): EntityManagerInterface
    {
        return static::getContainer()->get(EntityManagerInterface::class);
    }

    /** @return array<string, mixed> */
    private function linha(JustificativaPonto $j): array
    {
        return $this->em()->getConnection()->fetchAssociative(
            'SELECT status, tipo, abono_parcial, hora_inicio_abono, hora_fim_abono FROM justificativa_ponto WHERE id = ?',
            [$j->getId()],
        ) ?: [];
    }

    private function justificativa(Tenant $tenant, User $user, string $status): JustificativaPonto
    {
        $j = new JustificativaPonto();
        $j->setUser($user);
        $j->setTenant($tenant);
        $j->setData(new \DateTime('2026-04-07'));
        $j->setStatus($status);
        $j->setTipo('licenca');
        $j->setBatchId(bin2hex(random_bytes(12)));
        $this->em()->persist($j);
        $this->em()->flush();

        return $j;
    }

    /** @return array{0: Tenant, 1: User} */
    private function cenario(): array
    {
        $em     = $this->em();
        $tenant = new Tenant();
        $tenant->setName('Tenant C2-01 UC ' . uniqid());
        $em->persist($tenant);

        $user = new User();
        $user->setEmail('c201_uc_' . uniqid() . '@test.com');
        $user->setFullName('Colaborador C2-01');
        $user->setRoles(['ROLE_USER']);
        $user->setIsActive(true);
        $user->setPassword('x');
        $em->persist($user);

        $em->persist(new UserTenant($user, $tenant));
        $em->flush();

        return [$tenant, $user];
    }
}
