<?php

declare(strict_types=1);

namespace App\Tests\Ponto\Integration;

use App\Entity\Auth\User;
use App\Entity\Tenant\Tenant;
use App\Ponto\Entity\AceiteDaFolhaDoPonto;
use App\Ponto\Entity\FolhaEmitidaDoPonto;
use App\Ponto\Enum\SituacaoDoAceiteDaFolha;
use App\Ponto\Repository\AceiteDaFolhaDoPontoRepository;
use App\Ponto\Repository\FolhaEmitidaDoPontoRepository;
use App\Ponto\Service\CodigoDaFolha;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\TestDox;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * A folha emitida e o aceite no banco (E16): as consultas levam o escritório escrito (outro escritório, outra pessoa
 * ou outro mês não acham nada), a consulta por código atravessa os escritórios de propósito (validação pública), o
 * histórico numerado do aceite vale pelo último, e os índices únicos seguram código repetido e aceite de mesmo número.
 */
#[Group('ponto')]
#[CoversClass(FolhaEmitidaDoPontoRepository::class)]
#[CoversClass(AceiteDaFolhaDoPontoRepository::class)]
#[CoversClass(FolhaEmitidaDoPonto::class)]
#[CoversClass(AceiteDaFolhaDoPonto::class)]
final class FolhaEmitidaEAceiteNoBancoTest extends KernelTestCase
{
    private EntityManagerInterface $em;
    private FolhaEmitidaDoPontoRepository $folhas;
    private AceiteDaFolhaDoPontoRepository $aceites;
    private int $codigos = 0;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->em = static::getContainer()->get(EntityManagerInterface::class);
        $this->folhas = static::getContainer()->get(FolhaEmitidaDoPontoRepository::class);
        $this->aceites = static::getContainer()->get(AceiteDaFolhaDoPontoRepository::class);
    }

    #[TestDox('Por código: acha a folha de qualquer escritório, mesmo com o filtro ligado em outro (validação pública)')]
    public function testDoCodigoAtravessaEscritorios(): void
    {
        [$a, $b] = [$this->escritorio(), $this->escritorio()];
        $folha = $this->emitir($a, $this->pessoa(), 2026, 10);
        $this->em->clear();

        $this->em->getFilters()->enable('tenant')->setParameter('tenant', $b->getId(), Types::INTEGER);
        try {
            self::assertSame($folha->getId(), $this->folhas->doCodigo($folha->getCodigo())?->getId());
            self::assertTrue($this->folhas->existeCodigo($folha->getCodigo()));
            self::assertNull($this->folhas->doCodigo('BJF-ZZZZ-ZZZZ-ZZZZ'));
            self::assertTrue($this->em->getFilters()->isEnabled('tenant'), 'o filtro volta ligado depois da consulta');
        } finally {
            $this->em->getFilters()->disable('tenant');
        }
    }

    #[TestDox('Última da competência: a mais nova da pessoa no escritório; outro escritório, outra pessoa e outro mês não acham')]
    public function testUltimaDaCompetencia(): void
    {
        [$a, $b] = [$this->escritorio(), $this->escritorio()];
        [$maria, $joao] = [$this->pessoa(), $this->pessoa()];
        $this->emitir($a, $maria, 2026, 10, '2026-11-03 09:00:00');
        $maisNova = $this->emitir($a, $maria, 2026, 10, '2026-11-04 10:00:00');
        $mesmaHora = $this->emitir($a, $maria, 2026, 10, '2026-11-04 10:00:00');
        $daMariaEmB = $this->emitir($b, $maria, 2026, 10, '2026-11-05 10:00:00');
        $this->emitir($a, $maria, 2026, 9, '2026-11-06 10:00:00');

        self::assertSame($mesmaHora->getId(), $this->folhas->ultimaDaCompetencia($a, $maria, 2026, 10)?->getId(), 'mesma hora: a de id maior');
        self::assertNotSame($maisNova->getId(), $daMariaEmB->getId());
        self::assertSame($daMariaEmB->getId(), $this->folhas->ultimaDaCompetencia($b, $maria, 2026, 10)?->getId(), 'em B, só a de B');
        self::assertNull($this->folhas->ultimaDaCompetencia($a, $joao, 2026, 10), 'outra pessoa');
        self::assertNull($this->folhas->ultimaDaCompetencia($a, $maria, 2026, 8), 'outro mês');
        self::assertNull($this->folhas->ultimaDaCompetencia($b, $maria, 2026, 9), 'o mês 9 é só de A');
    }

    #[TestDox('Aceite: histórico numerado 1, 2...; vale o último; outro escritório, outra pessoa e outro mês começam do 1')]
    public function testAceiteNumeradoEUltimo(): void
    {
        [$a, $b] = [$this->escritorio(), $this->escritorio()];
        [$maria, $joao] = [$this->pessoa(), $this->pessoa()];
        self::assertNull($this->aceites->ultimoDaCompetencia($a, $maria, 2026, 10));
        self::assertSame(1, $this->aceites->proximoNumero($a, $maria, 2026, 10));

        $primeiro = $this->assinar($this->emitir($a, $maria, 2026, 10), 1, SituacaoDoAceiteDaFolha::Concordo, null);
        self::assertSame(2, $this->aceites->proximoNumero($a, $maria, 2026, 10));
        $segundo = $this->assinar($this->emitir($a, $maria, 2026, 10), 2, SituacaoDoAceiteDaFolha::Ressalva, 'Faltou o dia 3.');

        self::assertSame($segundo->getId(), $this->aceites->ultimoDaCompetencia($a, $maria, 2026, 10)?->getId());
        self::assertNotSame($primeiro->getId(), $segundo->getId());
        self::assertSame(3, $this->aceites->proximoNumero($a, $maria, 2026, 10));
        self::assertNull($this->aceites->ultimoDaCompetencia($b, $maria, 2026, 10), 'outro escritório');
        self::assertSame(1, $this->aceites->proximoNumero($b, $maria, 2026, 10));
        self::assertNull($this->aceites->ultimoDaCompetencia($a, $joao, 2026, 10), 'outra pessoa');
        self::assertSame(1, $this->aceites->proximoNumero($a, $maria, 2026, 11), 'outro mês');
    }

    #[TestDox('Gravado e lido do banco: situação, ressalva, data, IP, navegador, selo e a folha vista voltam iguais')]
    public function testIdaEVoltaDoBanco(): void
    {
        $escritorio = $this->escritorio();
        $maria = $this->pessoa();
        $folha = $this->emitir($escritorio, $maria, 2026, 10);
        $id = $this->assinar($folha, 1, SituacaoDoAceiteDaFolha::Ressalva, 'Faltou a batida do dia 3.')->getId();
        $this->em->clear();

        $lido = $this->em->find(AceiteDaFolhaDoPonto::class, $id);
        self::assertNotNull($lido);
        self::assertSame(SituacaoDoAceiteDaFolha::Ressalva, $lido->getSituacao());
        self::assertSame('Faltou a batida do dia 3.', $lido->getRessalva());
        self::assertSame(['2026-11-03 09:05:00', '189.6.10.2', 'Mozilla/5.0'], [$lido->getAceitoEm()->format('Y-m-d H:i:s'), $lido->getIp(), $lido->getNavegador()]);
        self::assertSame([str_repeat('e', 64), 1], [$lido->getHmac(), $lido->getVersaoChave()]);
        self::assertSame($folha->getCodigo(), $lido->getFolhaVista()->getCodigo());
        self::assertSame([$escritorio->getId(), $maria->getId(), 2026, 10], [$lido->getTenant()?->getId(), $lido->getUser()->getId(), $lido->getAno(), $lido->getMes()]);
    }

    #[TestDox('Quem emitiu sai do banco: a folha fica, com a ligação nula (SET NULL)')]
    public function testEmissorQueSaiFicaNulo(): void
    {
        $gestor = $this->pessoa();
        $folha = $this->emitir($this->escritorio(), $this->pessoa(), 2026, 10, emitidaPor: $gestor);
        $conexao = $this->em->getConnection();

        $conexao->executeStatement('DELETE FROM "user" WHERE id = ?', [$gestor->getId()]);

        self::assertNull($conexao->fetchOne('SELECT emitida_por_id FROM ponto_folha_emitida WHERE id = ?', [$folha->getId()]));
    }

    #[TestDox('Dois aceites com o mesmo número na mesma competência (dois cliques ao mesmo tempo): o índice único recusa o segundo')]
    public function testAceiteDeMesmoNumeroRecusado(): void
    {
        $folha = $this->emitir($this->escritorio(), $this->pessoa(), 2026, 10);
        $this->assinar($folha, 1, SituacaoDoAceiteDaFolha::Concordo, null);

        $this->expectException(UniqueConstraintViolationException::class);
        $this->assinar($folha, 1, SituacaoDoAceiteDaFolha::Concordo, null);
    }

    #[TestDox('Código repetido, mesmo em outro escritório: o índice único recusa')]
    public function testCodigoRepetidoRecusado(): void
    {
        $folha = $this->emitir($this->escritorio(), $this->pessoa(), 2026, 10);

        $this->expectException(UniqueConstraintViolationException::class);
        $this->emitir($this->escritorio(), $this->pessoa(), 2026, 10, codigo: $folha->getCodigo());
    }

    #[TestDox('Código novo com o repositório de verdade: formato BJF, diferente de todos os gravados')]
    public function testCodigoNovoNaoRepeteOGravado(): void
    {
        $gravado = $this->emitir($this->escritorio(), $this->pessoa(), 2026, 10, codigo: 'BJF-ABCD-EFGH-JK23');
        $gerador = new CodigoDaFolha($this->folhas);

        self::assertTrue($this->folhas->existeCodigo($gravado->getCodigo()));
        for ($i = 0; $i < 20; ++$i) {
            $codigo = $gerador->novo();
            self::assertTrue(CodigoDaFolha::temFormato($codigo));
            self::assertFalse($this->folhas->existeCodigo($codigo));
        }
    }

    private function emitir(Tenant $escritorio, User $pessoa, int $ano, int $mes, string $em = '2026-11-03 09:00:00', ?User $emitidaPor = null, ?string $codigo = null): FolhaEmitidaDoPonto
    {
        $folha = new FolhaEmitidaDoPonto(
            $escritorio,
            $pessoa,
            $emitidaPor ?? $pessoa,
            $ano,
            $mes,
            $codigo ?? sprintf('BJF-TEST-%04d-%s', ++$this->codigos, substr(strtoupper(bin2hex(random_bytes(2))), 0, 4)),
            str_repeat('f', 64),
            1,
            new \DateTimeImmutable($em),
            hash('sha256', 'resumo'),
            '{"formato":1}',
        );
        $this->folhas->adicionar($folha);
        $this->em->flush();

        return $folha;
    }

    private function assinar(FolhaEmitidaDoPonto $folha, int $numero, SituacaoDoAceiteDaFolha $situacao, ?string $ressalva): AceiteDaFolhaDoPonto
    {
        $aceite = new AceiteDaFolhaDoPonto($folha, $numero, $situacao, $ressalva, new \DateTimeImmutable('2026-11-03 09:05:00'), '189.6.10.2', 'Mozilla/5.0', str_repeat('e', 64), 1);
        $this->aceites->adicionar($aceite);
        $this->em->flush();

        return $aceite;
    }

    private function escritorio(): Tenant
    {
        $tenant = new Tenant();
        $tenant->setName('Escritório da folha ' . uniqid());
        $this->em->persist($tenant);
        $this->em->flush();

        return $tenant;
    }

    private function pessoa(): User
    {
        $pessoa = new User();
        $pessoa->setEmail('folha_' . uniqid('', true) . '@test.com');
        $pessoa->setFullName('Pessoa da folha');
        $pessoa->setRoles(['ROLE_USER']);
        $pessoa->setIsActive(true);
        $pessoa->setPassword('x');
        $this->em->persist($pessoa);
        $this->em->flush();

        return $pessoa;
    }
}
