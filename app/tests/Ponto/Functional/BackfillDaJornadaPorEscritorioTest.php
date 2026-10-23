<?php

declare(strict_types=1);

namespace App\Tests\Ponto\Functional;

use App\Entity\Auth\User;
use App\Entity\Auth\UserTenant;
use App\Entity\Tenant\Tenant;
use App\Ponto\Entity\BlocoJornadaColaborador;
use App\Ponto\Entity\JornadaColaborador;
use App\Ponto\Entity\RegistroPonto;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use DoctrineMigrations\Version20261020150700;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\TestDox;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * O backfill da migration `Version20261020150700` (spec seguranca-b-jornada, regras 4 e 5): roda o SQL dela sobre
 * jornadas ainda sem escritório. O teste solta o NOT NULL dentro da transação do DAMA, que desfaz tudo no fim.
 *
 * As migrations não estão no autoload do composer (o Doctrine as carrega por caminho), daí o require_once.
 */
#[CoversNothing]
final class BackfillDaJornadaPorEscritorioTest extends KernelTestCase
{
    private Connection $banco;
    private EntityManagerInterface $em;
    private Tenant $t1;
    private Tenant $t2;

    public static function setUpBeforeClass(): void
    {
        require_once __DIR__ . '/../../../migrations/Version20261020150700.php';
    }

    protected function setUp(): void
    {
        $this->em = static::getContainer()->get(EntityManagerInterface::class);
        $this->banco = $this->em->getConnection();
        $this->banco->executeStatement('ALTER TABLE jornada_colaborador ALTER tenant_id DROP NOT NULL');
        $this->t1 = $this->escritorio('T1');
        $this->t2 = $this->escritorio('T2');
    }

    #[TestDox('Regra 4: um escritório ativo, vários ativos com batida, vários sem batida, sem vínculo com e sem batida')]
    public function testDestinoDaJornada(): void
    {
        $varios = (int) $this->banco->fetchOne(Version20261020150700::SQL_CONTA_VARIOS_ATIVOS_SEM_BATIDA);
        $orfas = (int) $this->banco->fetchOne(Version20261020150700::SQL_CONTA_ORFAS);

        $umAtivo = $this->pessoaCom([$this->t1]);
        $variosComBatida = $this->pessoaCom([$this->t1, $this->t2]);
        $this->batida($variosComBatida, $this->t2, '-40 days');
        $this->batida($variosComBatida, $this->t1, '-50 days');
        $variosSemBatida = $this->pessoaCom([$this->t2, $this->t1]);   // o vínculo em T2 é o mais antigo
        $semVinculo = $this->pessoaCom([]);
        $this->batida($semVinculo, $this->t1, '-400 days');
        $this->batida($semVinculo, $this->t2, '-300 days');
        $orfa = $this->pessoaCom([]);

        self::assertSame($varios + 1, (int) $this->banco->fetchOne(Version20261020150700::SQL_CONTA_VARIOS_ATIVOS_SEM_BATIDA));
        self::assertSame($orfas + 1, (int) $this->banco->fetchOne(Version20261020150700::SQL_CONTA_ORFAS));

        $this->rodarBackfillDaJornada();

        self::assertSame([$this->t1->getId()], $this->escritoriosDaJornada($umAtivo));
        self::assertSame([$this->t2->getId()], $this->escritoriosDaJornada($variosComBatida), 'a última batida foi em T2');
        self::assertSame([$this->t2->getId()], $this->escritoriosDaJornada($variosSemBatida), 'o vínculo mais antigo');
        self::assertSame([$this->t2->getId()], $this->escritoriosDaJornada($semVinculo), 'o último escritório, pela batida');
        self::assertSame([], $this->escritoriosDaJornada($orfa), 'sem vínculo e sem batida: apagada');
        self::assertSame(0, (int) $this->banco->fetchOne('SELECT COUNT(*) FROM bloco_jornada_colaborador b JOIN jornada_colaborador j ON j.id = b.jornada_colaborador_id WHERE j.user_id = ?', [$orfa->getId()]));
        self::assertSame(0, (int) $this->banco->fetchOne('SELECT COUNT(*) FROM jornada_colaborador WHERE tenant_id IS NULL'));
    }

    #[TestDox('Regra 5: a chave fica ligada só onde houve batida nos últimos 30 dias; desligada onde a jornada não ficou')]
    public function testChaveRegistraPonto(): void
    {
        $pessoa = $this->pessoaCom([$this->t1, $this->t2]);
        $this->batida($pessoa, $this->t2, '-3 days');
        $this->batida($pessoa, $this->t1, '-40 days');
        $semBatida = $this->pessoaCom([$this->t1]);

        $this->rodarBackfillDaJornada();
        $this->banco->executeStatement(Version20261020150700::SQL_CHAVE_REGISTRA_PONTO);

        self::assertSame([$this->t2->getId()], $this->escritoriosDaJornada($pessoa));
        self::assertTrue($this->chave($pessoa, $this->t2));
        self::assertFalse($this->chave($pessoa, $this->t1), 'T1 não ficou com a jornada e a última batida lá passou de 30 dias');
        self::assertFalse($this->chave($semBatida, $this->t1));
    }

    private function rodarBackfillDaJornada(): void
    {
        $this->banco->executeStatement(Version20261020150700::SQL_DESTINO_DA_JORNADA);
        $this->banco->executeStatement(Version20261020150700::SQL_APAGA_BLOCOS_ORFAOS);
        $this->banco->executeStatement(Version20261020150700::SQL_APAGA_JORNADAS_ORFAS);
    }

    /**
     * Pessoa com vínculo ativo nos escritórios dados, criados nessa ordem (o primeiro é o mais antigo), e uma jornada
     * pessoal com um bloco, ainda sem escritório.
     *
     * @param list<Tenant> $escritorios
     */
    private function pessoaCom(array $escritorios): User
    {
        $user = (new User())->setEmail('backfill_' . uniqid() . '@test.com')->setFullName('Backfill');
        $user->setRoles(['ROLE_USER']);
        $this->em->persist($user);
        foreach ($escritorios as $i => $escritorio) {
            $this->em->persist(new UserTenant($user, $escritorio, new \DateTimeImmutable(sprintf('-%d days', 100 - $i))));
        }
        $jornada = (new JornadaColaborador())->setUser($user)->setTenant($this->t1);
        $jornada->addBloco((new BlocoJornadaColaborador())->setDiasSemana([1])->setEntrada('08:00')->setSaida('12:00'));
        $this->em->persist($jornada);
        $this->em->flush();
        $this->banco->executeStatement('UPDATE jornada_colaborador SET tenant_id = NULL WHERE id = ?', [$jornada->getId()]);

        return $user;
    }

    private function batida(User $user, Tenant $tenant, string $quando): void
    {
        $registro = new RegistroPonto();
        $registro->setUser($user);
        $registro->setTenant($tenant);
        $registro->setTipo(RegistroPonto::TIPO_ENTRADA);
        $registro->setDataHora(new \DateTime($quando));
        $this->em->persist($registro);
        $this->em->flush();
    }

    /** @return list<int> */
    private function escritoriosDaJornada(User $user): array
    {
        return array_map('intval', $this->banco->fetchFirstColumn('SELECT tenant_id FROM jornada_colaborador WHERE user_id = ?', [$user->getId()]));
    }

    private function chave(User $user, Tenant $tenant): bool
    {
        return (bool) $this->banco->fetchOne('SELECT registra_ponto FROM user_tenant WHERE user_id = ? AND tenant_id = ?', [$user->getId(), $tenant->getId()]);
    }

    private function escritorio(string $nome): Tenant
    {
        $tenant = new Tenant();
        $tenant->setName('Backfill ' . $nome . ' ' . uniqid());
        $this->em->persist($tenant);
        $this->em->flush();

        return $tenant;
    }
}
