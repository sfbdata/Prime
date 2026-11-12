<?php

declare(strict_types=1);

namespace App\Tests\Voto\Functional;

use App\Entity\Tenant\Tenant;
use App\Tests\Factory\Tenant\TenantFactory;
use App\Tests\Factory\Voto\AssembleiaFactory;
use App\Tests\Factory\Voto\PautaDaAssembleiaFactory;
use App\Tests\Factory\Voto\UnidadeDaAssembleiaFactory;
use App\Voto\DTO\RegistrarVotoInput;
use App\Voto\Entity\Assembleia;
use App\Voto\Entity\LiberacaoDoVoto;
use App\Voto\Entity\OpcaoDaPauta;
use App\Voto\Entity\ParticipacaoNaPauta;
use App\Voto\Entity\PautaDaAssembleia;
use App\Voto\Entity\UnidadeDaAssembleia;
use App\Voto\Enum\EstadoDaPauta;
use App\Voto\Exception\OperacaoInvalidaNaAssembleiaException;
use App\Voto\Service\TransacaoDoVotoDoctrine;
use App\Voto\UseCase\RegistrarVotoUseCase;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\TestDox;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Zenstruck\Foundry\Test\Factories;

/**
 * O voto contra o banco (spec docs/specs/voto-assembleia-condominio.md, seção 2): a pauta travada dentro da transação,
 * a participação gravada, o peso somado no contador por SQL direto, o encerramento pelo prazo confirmado mesmo com o
 * voto recusado, e o sigilo no esquema: nenhuma tabela, coluna ou entidade liga a unidade à opção.
 */
#[CoversClass(RegistrarVotoUseCase::class)]
#[CoversClass(TransacaoDoVotoDoctrine::class)]
#[Group('voto')]
final class RegistrarVotoNoBancoTest extends KernelTestCase
{
    use Factories;

    private EntityManagerInterface $em;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->em = static::getContainer()->get(EntityManagerInterface::class);
    }

    #[TestDox('Quatro unidades votam: o contador soma unidades e fração (abstenção à parte); o voto repetido é recusado')]
    public function testSomaOPesoEGravaAParticipacao(): void
    {
        [$assembleia, $pauta] = $this->assembleiaComPautaAberta();
        $votos = [['0.025000', 1], ['0.031000', 1], ['0.500000', 2], ['0.100000', 0]];
        foreach ($votos as [$fracao, $numero]) {
            $unidade = UnidadeDaAssembleiaFactory::createOne(['assembleia' => $assembleia, 'fracao' => $fracao])->_real();
            $this->votar($unidade, $pauta, $numero);
        }
        $primeira = $assembleia->getUnidades()->first();
        self::assertInstanceOf(UnidadeDaAssembleia::class, $primeira);

        try {
            $this->votar($primeira, $pauta, 2);
            self::fail('O segundo voto da unidade devia ser recusado.');
        } catch (OperacaoInvalidaNaAssembleiaException $recusa) {
            self::assertSame(RegistrarVotoUseCase::JA_VOTOU, $recusa->getMessage());
        }

        self::assertSame([1 => [2, '0.056000'], 2 => [1, '0.500000'], 0 => [1, '0.100000']], $this->contadores($pauta));
        self::assertSame(4, $this->participacoes($pauta));
    }

    #[TestDox('Pauta de 2 vagas: dois nomes somam o peso em cada contador, a terceira opção fica zerada e há uma participação só')]
    public function testVariosNomesNoBanco(): void
    {
        [$assembleia, $pauta] = $this->assembleiaComPautaAberta();
        (new \ReflectionProperty($pauta, 'vagas'))->setValue($pauta, 2);
        $this->em->flush();
        $unidade = UnidadeDaAssembleiaFactory::createOne(['assembleia' => $assembleia, 'fracao' => '0.025000'])->_real();
        $ids = array_map(static fn (OpcaoDaPauta $o): int => (int) $o->getId(), $pauta->getOpcoesDeVoto());
        self::assertGreaterThanOrEqual(2, \count($ids));

        static::getContainer()->get(RegistrarVotoUseCase::class)->executar(
            new RegistrarVotoInput(UnidadeDaAssembleiaFactory::tokenDe($unidade), (int) $pauta->getId(), [$ids[0], $ids[1]]),
        );

        $contadores = $this->contadores($pauta);
        self::assertSame([1, '0.025000'], $contadores[1]);
        self::assertSame([1, '0.025000'], $contadores[2]);
        self::assertSame([0, '0.000000'], $contadores[0], 'a abstenção não soma');
        self::assertSame(1, $this->participacoes($pauta));
    }

    #[TestDox('Uma unidade, um voto: o contador soma 1 por unidade')]
    public function testPesoPorUnidade(): void
    {
        [$assembleia, $pauta] = $this->assembleiaComPautaAberta(porFracao: false);
        $unidade = UnidadeDaAssembleiaFactory::createOne(['assembleia' => $assembleia, 'fracao' => '0.025000'])->_real();

        $this->votar($unidade, $pauta, 1);

        self::assertSame([1, '1.000000'], $this->contadores($pauta)[1]);
    }

    #[TestDox('Prazo vencido: o encerramento ("Prazo definido pela mesa") fica gravado e o voto é recusado')]
    public function testPrazoVencidoGravaOEncerramento(): void
    {
        $prazo = new \DateTimeImmutable('-1 minute');
        [$assembleia, $pauta] = $this->assembleiaComPautaAberta(prazo: $prazo);
        $unidade = UnidadeDaAssembleiaFactory::createOne(['assembleia' => $assembleia, 'fracao' => '0.025000'])->_real();

        try {
            $this->votar($unidade, $pauta, 1);
            self::fail('Voto depois do prazo devia ser recusado.');
        } catch (OperacaoInvalidaNaAssembleiaException $recusa) {
            self::assertSame(RegistrarVotoUseCase::PRAZO_ENCERRADO, $recusa->getMessage());
        }

        $this->em->clear();
        $relida = $this->em->find(PautaDaAssembleia::class, $pauta->getId());
        self::assertNotNull($relida);
        self::assertSame(EstadoDaPauta::Encerrada, $relida->getEstado());
        self::assertSame(PautaDaAssembleia::MOTIVO_DO_PRAZO, $relida->getMotivoEncerramento());
        self::assertSame($prazo->format('Y-m-d H:i:s'), $relida->getEncerradaEm()?->format('Y-m-d H:i:s'));
        self::assertSame(0, $this->participacoes($relida));
    }

    #[TestDox('Sigilo no esquema: nenhuma tabela do voto aponta ao mesmo tempo para a unidade e para a opção')]
    public function testNenhumaTabelaLigaUnidadeEOpcao(): void
    {
        $referencias = [];
        foreach ($this->chavesEstrangeirasDoVoto() as $linha) {
            $referencias[$linha['tabela']][] = $linha['referenciada'];
        }
        self::assertContains('assembleia_unidade', $referencias['assembleia_participacao'] ?? [], 'a consulta enxerga as FKs');
        foreach ($referencias as $tabela => $alvos) {
            self::assertFalse(
                \in_array('assembleia_unidade', $alvos, true) && \in_array('assembleia_opcao', $alvos, true),
                'a tabela voto.' . $tabela . ' liga unidade e opção',
            );
        }
        self::assertSame([], $this->colunasQueCitam('assembleia_participacao', 'opcao'));
        self::assertSame([], $this->colunasQueCitam('assembleia_opcao', 'unidade_id'));
        foreach ($this->em->getMetadataFactory()->getAllMetadata() as $metadata) {
            $alvos = array_column($metadata->getAssociationMappings(), 'targetEntity');
            self::assertFalse(
                \in_array(UnidadeDaAssembleia::class, $alvos, true) && \in_array(OpcaoDaPauta::class, $alvos, true),
                $metadata->getName() . ' liga unidade e opção',
            );
        }
    }

    #[TestDox('O voto não deixa rastro no audit_log da opção nem da participação (a hora ligaria as duas)')]
    public function testVotoSemAuditoria(): void
    {
        [$assembleia, $pauta] = $this->assembleiaComPautaAberta();
        $unidade = UnidadeDaAssembleiaFactory::createOne(['assembleia' => $assembleia, 'fracao' => '0.025000'])->_real();

        $this->votar($unidade, $pauta, 1);

        self::assertGreaterThan(0, $this->rastrosNoAuditLog([PautaDaAssembleia::class]), 'a auditoria está ligada (a pauta é Auditavel)');
        self::assertSame(0, $this->rastrosNoAuditLog([OpcaoDaPauta::class, ParticipacaoNaPauta::class]));
    }

    /** @param list<class-string> $classes */
    private function rastrosNoAuditLog(array $classes): int
    {
        return (int) $this->em->getConnection()->fetchOne(
            'SELECT COUNT(*) FROM audit_log WHERE entity_class IN (:classes)',
            ['classes' => $classes],
            ['classes' => ArrayParameterType::STRING],
        );
    }

    /** @return array{Assembleia, PautaDaAssembleia} */
    private function assembleiaComPautaAberta(bool $porFracao = true, ?\DateTimeImmutable $prazo = null): array
    {
        $tenant = TenantFactory::createOne()->_real();
        $this->liberar($tenant);
        $fabrica = AssembleiaFactory::new()->publicada();
        $assembleia = ($porFracao ? $fabrica->porFracao() : $fabrica)->create(['tenant' => $tenant])->_real();
        $pauta = PautaDaAssembleiaFactory::new()->comOpcoesPadrao()->aberta(1, $prazo)->create(['assembleia' => $assembleia])->_real();

        return [$assembleia, $pauta];
    }

    private function votar(UnidadeDaAssembleia $unidade, PautaDaAssembleia $pauta, int $numero): void
    {
        $opcao = 0 === $numero ? $pauta->getAbstencao() : current(array_filter(
            $pauta->getOpcoesDeVoto(),
            static fn (OpcaoDaPauta $o): bool => $o->getNumero() === $numero,
        ));
        self::assertInstanceOf(OpcaoDaPauta::class, $opcao);
        $token = UnidadeDaAssembleiaFactory::tokenDe($unidade);

        static::getContainer()->get(RegistrarVotoUseCase::class)
            ->executar(RegistrarVotoInput::deUma($token, (int) $pauta->getId(), (int) $opcao->getId()));
    }

    /** @return array<int, array{int, string}> número da opção (0 = abstenção) => [unidades, peso], relidos do banco */
    private function contadores(PautaDaAssembleia $pauta): array
    {
        $linhas = $this->em->getConnection()->fetchAllAssociative(
            'SELECT numero, total_unidades, total_peso FROM voto.assembleia_opcao WHERE pauta_id = :pauta ORDER BY abstencao, numero',
            ['pauta' => $pauta->getId()],
        );
        $contadores = [];
        foreach ($linhas as $linha) {
            $contadores[(int) $linha['numero']] = [(int) $linha['total_unidades'], (string) $linha['total_peso']];
        }

        return $contadores;
    }

    private function participacoes(PautaDaAssembleia $pauta): int
    {
        return (int) $this->em->getConnection()->fetchOne(
            'SELECT COUNT(*) FROM voto.assembleia_participacao WHERE pauta_id = :pauta',
            ['pauta' => $pauta->getId()],
        );
    }

    /** @return list<array{tabela: string, referenciada: string}> */
    private function chavesEstrangeirasDoVoto(): array
    {
        /** @var list<array{tabela: string, referenciada: string}> $linhas */
        $linhas = $this->em->getConnection()->fetchAllAssociative(
            "SELECT DISTINCT rel.relname AS tabela, alvo.relname AS referenciada
               FROM pg_constraint c
               JOIN pg_class rel ON rel.oid = c.conrelid
               JOIN pg_class alvo ON alvo.oid = c.confrelid
               JOIN pg_namespace n ON n.oid = rel.relnamespace
              WHERE c.contype = 'f' AND n.nspname = 'voto'",
        );

        return $linhas;
    }

    /** @return list<string> */
    private function colunasQueCitam(string $tabela, string $trecho): array
    {
        return array_values(array_filter(
            $this->em->getConnection()->fetchFirstColumn(
                "SELECT column_name FROM information_schema.columns WHERE table_schema = 'voto' AND table_name = :tabela",
                ['tabela' => $tabela],
            ),
            static fn (mixed $coluna): bool => str_contains((string) $coluna, $trecho),
        ));
    }

    private function liberar(Tenant $tenant): void
    {
        $liberacao = new LiberacaoDoVoto($tenant);
        $liberacao->liberar();
        $this->em->persist($liberacao);
        $this->em->flush();
    }
}
