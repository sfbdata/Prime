<?php

declare(strict_types=1);

namespace App\Tests\Voto\Functional;

use App\Entity\Tenant\Tenant;
use App\Tests\Factory\Tenant\TenantFactory;
use App\Tests\Factory\Voto\AssembleiaFactory;
use App\Tests\Factory\Voto\PautaDaAssembleiaFactory;
use App\Tests\Factory\Voto\UnidadeDaAssembleiaFactory;
use App\Voto\Command\ExecutarProgramacaoDasPautasCommand;
use App\Voto\DTO\RegistrarVotoInput;
use App\Voto\Entity\Assembleia;
use App\Voto\Entity\LiberacaoDoVoto;
use App\Voto\Entity\PautaDaAssembleia;
use App\Voto\Entity\UnidadeDaAssembleia;
use App\Voto\Entity\VotoNominal;
use App\Voto\Enum\EstadoDaPauta;
use App\Voto\Enum\SigiloDoVoto;
use App\Voto\Exception\OperacaoInvalidaNaAssembleiaException;
use App\Voto\UseCase\RegistrarVotoUseCase;
use Doctrine\DBAL\Exception as ExcecaoDoBanco;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\TestDox;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Tester\CommandTester;
use Zenstruck\Foundry\Test\Factories;

/**
 * Voto aberto e horário programado contra o banco (W2-3): o voto aberto grava o registro nominal e o secreto nunca grava
 * (nem por erro de código: o gatilho do banco recusa); o grupo da pauta barra a unidade de fora; o agendador abre e
 * encerra as pautas programadas de todos os escritórios e respeita a mesa.
 */
#[CoversClass(RegistrarVotoUseCase::class)]
#[CoversClass(ExecutarProgramacaoDasPautasCommand::class)]
#[Group('voto')]
final class VotoAbertoEProgramacaoNoBancoTest extends KernelTestCase
{
    use Factories;

    private EntityManagerInterface $em;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->em = static::getContainer()->get(EntityManagerInterface::class);
    }

    #[TestDox('Voto aberto: grava a participação, o contador e o registro nominal (unidade, opção, peso, hora); sem rastro no audit_log')]
    public function testVotoAbertoGravaONominal(): void
    {
        [$assembleia, $pauta] = $this->pautaAberta(['sigilo' => SigiloDoVoto::Aberto]);
        $unidade = UnidadeDaAssembleiaFactory::createOne(['assembleia' => $assembleia, 'fracao' => '0.025000'])->_real();
        $sim = $pauta->getOpcoesDeVoto()[0];

        $this->votar($unidade, $pauta, (int) $sim->getId());

        $linhas = $this->em->getConnection()->fetchAllAssociative('SELECT unidade_id, opcao_id, numero_nulo, peso FROM voto.assembleia_voto_nominal WHERE pauta_id = ?', [$pauta->getId()]);
        self::assertCount(1, $linhas);
        self::assertSame($unidade->getId(), (int) $linhas[0]['unidade_id']);
        self::assertSame($sim->getId(), (int) $linhas[0]['opcao_id']);
        self::assertNull($linhas[0]['numero_nulo']);
        self::assertSame('0.025000', $linhas[0]['peso']);
        self::assertSame(1, $this->contar('voto.assembleia_participacao', $pauta));
        self::assertSame(0, (int) $this->em->getConnection()->fetchOne('SELECT COUNT(*) FROM audit_log WHERE entity_class = ?', [VotoNominal::class]));
    }

    #[TestDox('Voto secreto (padrão): o voto é registrado como sempre e nenhuma linha nominal existe')]
    public function testVotoSecretoNaoGravaNominal(): void
    {
        [$assembleia, $pauta] = $this->pautaAberta();
        $unidade = UnidadeDaAssembleiaFactory::createOne(['assembleia' => $assembleia, 'fracao' => '0.025000'])->_real();

        $this->votar($unidade, $pauta, (int) $pauta->getOpcoesDeVoto()[0]->getId());

        self::assertSame(1, $this->contar('voto.assembleia_participacao', $pauta));
        self::assertSame(0, $this->contar('voto.assembleia_voto_nominal', $pauta));
    }

    #[TestDox('O banco recusa a linha nominal de pauta secreta, mesmo que o código erre (gatilho)')]
    public function testGatilhoRecusaNominalEmPautaSecreta(): void
    {
        [$assembleia, $pauta] = $this->pautaAberta();
        $unidade = UnidadeDaAssembleiaFactory::createOne(['assembleia' => $assembleia, 'fracao' => '0.025000'])->_real();

        try {
            $this->em->getConnection()->executeStatement(
                'INSERT INTO voto.assembleia_voto_nominal (tenant_id, unidade_id, pauta_id, opcao_id, peso, registrada_em) VALUES (?, ?, ?, ?, 1, now())',
                [$assembleia->getTenant()->getId(), $unidade->getId(), $pauta->getId(), $pauta->getOpcoesDeVoto()[0]->getId()],
            );
            self::fail('O banco devia recusar a linha nominal de pauta secreta.');
        } catch (ExcecaoDoBanco $recusa) {
            self::assertStringContainsString('A pauta de voto secreto nao guarda voto nominal', $recusa->getMessage());
        }
    }

    #[TestDox('Pauta com grupos: a unidade de outro bloco é recusada e nada é gravado; a do grupo vota')]
    public function testGruposNoBanco(): void
    {
        [$assembleia, $pauta] = $this->pautaAberta(['grupos' => ['Bloco A']]);
        $dentro = UnidadeDaAssembleiaFactory::createOne(['assembleia' => $assembleia, 'bloco' => 'bloco a', 'identificacao' => '101', 'fracao' => '0.025000'])->_real();
        $fora = UnidadeDaAssembleiaFactory::createOne(['assembleia' => $assembleia, 'bloco' => 'Bloco B', 'identificacao' => '201', 'fracao' => '0.025000'])->_real();
        $opcao = (int) $pauta->getOpcoesDeVoto()[0]->getId();

        try {
            $this->votar($fora, $pauta, $opcao);
            self::fail('A unidade fora dos grupos devia ser recusada.');
        } catch (OperacaoInvalidaNaAssembleiaException $recusa) {
            self::assertSame(RegistrarVotoUseCase::UNIDADE_FORA_DO_GRUPO, $recusa->getMessage());
        }
        self::assertSame(0, $this->contar('voto.assembleia_participacao', $pauta));

        $this->votar($dentro, $pauta, $opcao);
        self::assertSame(1, $this->contar('voto.assembleia_participacao', $pauta));
    }

    #[TestDox('Agendador: abre a pauta programada na hora ("Programado", 1ª convocação, prazo no encerramento) e não abre a retirada nem a fora da janela')]
    public function testAgendadorAbre(): void
    {
        [$assembleia] = $this->pautaAberta();
        $abre = new \DateTimeImmutable('-1 hour');
        $encerra = new \DateTimeImmutable('+1 hour');
        $programada = $this->pautaProgramada($assembleia, 'Na janela', $abre, $encerra);
        $retirada = $this->pautaProgramada($assembleia, 'Retirada', $abre, $encerra, ['retiradaEm' => new \DateTimeImmutable('-2 hours'), 'retiradaMotivo' => 'Fora da pauta', 'retiradaPorNome' => 'Mesa']);
        $futura = $this->pautaProgramada($assembleia, 'Futura', new \DateTimeImmutable('+1 hour'), new \DateTimeImmutable('+2 hours'));
        $passada = $this->pautaProgramada($assembleia, 'Janela passada', new \DateTimeImmutable('-3 hours'), new \DateTimeImmutable('-2 hours'));
        $mesa = $this->pautaProgramada($assembleia, 'Sem programação', null, null);

        $saida = $this->executarComando();

        $this->em->clear();
        $relida = $this->em->find(PautaDaAssembleia::class, $programada->getId());
        self::assertNotNull($relida);
        self::assertSame(EstadoDaPauta::Aberta, $relida->getEstado());
        self::assertSame('Programado', $relida->getAbertaPorNome());
        self::assertSame(1, $relida->getConvocacao());
        self::assertSame($encerra->format('Y-m-d H:i'), $relida->getPrazoAte()?->format('Y-m-d H:i'));
        foreach ([$retirada, $futura, $passada, $mesa] as $naoAbre) {
            self::assertSame(EstadoDaPauta::Aguardando, $this->em->find(PautaDaAssembleia::class, $naoAbre->getId())?->getEstado(), $naoAbre->getTitulo());
        }
        self::assertStringContainsString('Pautas abertas: 1. Pautas encerradas: 0.', $saida);
    }

    #[TestDox('Agendador: encerra a pauta aberta pela programação quando o horário chega, em todos os escritórios, menos a suspensa')]
    public function testAgendadorEncerra(): void
    {
        [$assembleiaA] = $this->pautaAberta();
        [$assembleiaB] = $this->pautaAberta();
        $vencida = new \DateTimeImmutable('-1 minute');
        $a = $this->pautaProgramada($assembleiaA, 'Escritório A', new \DateTimeImmutable('-2 hours'), $vencida, ['estado' => EstadoDaPauta::Aberta, 'abertaEm' => new \DateTimeImmutable('-2 hours'), 'abertaPorNome' => 'Programado', 'convocacao' => 1, 'quorumExigido' => '50.00', 'prazoAte' => $vencida]);
        $b = $this->pautaProgramada($assembleiaB, 'Escritório B', new \DateTimeImmutable('-2 hours'), $vencida, ['estado' => EstadoDaPauta::Aberta, 'abertaEm' => new \DateTimeImmutable('-2 hours'), 'abertaPorNome' => 'Programado', 'convocacao' => 1, 'quorumExigido' => '50.00', 'prazoAte' => $vencida]);
        $suspensa = $this->pautaProgramada($assembleiaA, 'Suspensa', new \DateTimeImmutable('-2 hours'), $vencida, ['estado' => EstadoDaPauta::Aberta, 'abertaEm' => new \DateTimeImmutable('-2 hours'), 'abertaPorNome' => 'Programado', 'convocacao' => 1, 'quorumExigido' => '50.00', 'prazoAte' => $vencida, 'suspensaEm' => new \DateTimeImmutable('-30 minutes'), 'suspensaMotivo' => 'Queda de energia', 'suspensaPorNome' => 'Mesa']);

        $this->executarComando();

        $this->em->clear();
        foreach ([$a, $b] as $pauta) {
            $relida = $this->em->find(PautaDaAssembleia::class, $pauta->getId());
            self::assertSame(EstadoDaPauta::Encerrada, $relida?->getEstado(), $pauta->getTitulo());
            self::assertSame('Encerramento programado', $relida->getMotivoEncerramento());
        }
        $relida = $this->em->find(PautaDaAssembleia::class, $suspensa->getId());
        self::assertSame(EstadoDaPauta::Aberta, $relida?->getEstado());
        self::assertTrue($relida->suspensa());
    }

    #[TestDox('Agendador: assembleia arquivada não abre e a cancelada continua encerrada')]
    public function testAgendadorRespeitaArquivadaECancelada(): void
    {
        $arquivada = $this->assembleia(['arquivadaEm' => new \DateTimeImmutable('-1 day'), 'arquivadaMotivo' => 'Arquivada de teste', 'arquivadaPorNome' => 'Mesa']);
        $naArquivada = $this->pautaProgramada($arquivada, 'Na arquivada', new \DateTimeImmutable('-1 hour'), new \DateTimeImmutable('+1 hour'));
        $normal = $this->assembleia();
        $cancelada = $this->pautaProgramada($normal, 'Cancelada', new \DateTimeImmutable('-2 hours'), new \DateTimeImmutable('-1 minute'), [
            'estado' => EstadoDaPauta::Encerrada, 'abertaEm' => new \DateTimeImmutable('-2 hours'), 'abertaPorNome' => 'Programado', 'convocacao' => 1, 'quorumExigido' => '50.00',
            'prazoAte' => new \DateTimeImmutable('-1 minute'), 'encerradaEm' => new \DateTimeImmutable('-30 minutes'), 'encerradaPorNome' => 'Mesa',
            'motivoEncerramento' => 'Votação cancelada: erro na pauta', 'canceladaEm' => new \DateTimeImmutable('-30 minutes'), 'canceladaMotivo' => 'Erro na pauta', 'canceladaPorNome' => 'Mesa',
        ]);

        $saida = $this->executarComando();

        $this->em->clear();
        self::assertSame(EstadoDaPauta::Aguardando, $this->em->find(PautaDaAssembleia::class, $naArquivada->getId())?->getEstado());
        $relida = $this->em->find(PautaDaAssembleia::class, $cancelada->getId());
        self::assertSame('Votação cancelada: erro na pauta', $relida?->getMotivoEncerramento(), 'a cancelada não é tocada');
        self::assertSame('', $saida, 'nada a fazer: sem saída');
    }

    private function executarComando(): string
    {
        $aplicacao = new Application(self::$kernel);
        $teste = new CommandTester($aplicacao->find('voto:executar-programacao'));
        self::assertSame(0, $teste->execute([]));

        return $teste->getDisplay();
    }

    /**
     * @param array<string, mixed> $campos
     *
     * @return array{Assembleia, PautaDaAssembleia}
     */
    private function pautaAberta(array $campos = []): array
    {
        $assembleia = $this->assembleia();
        $pauta = PautaDaAssembleiaFactory::new()->comOpcoesPadrao()->aberta()->create(['assembleia' => $assembleia] + $campos)->_real();

        return [$assembleia, $pauta];
    }

    /** @param array<string, mixed> $campos */
    private function assembleia(array $campos = []): Assembleia
    {
        /** @var Tenant $tenant */
        $tenant = TenantFactory::createOne()->_real();
        $liberacao = new LiberacaoDoVoto($tenant);
        $liberacao->liberar();
        $this->em->persist($liberacao);
        $this->em->flush();

        return AssembleiaFactory::new()->porFracao()->publicada()->create(['tenant' => $tenant] + $campos)->_real();
    }

    /** @param array<string, mixed> $campos */
    private function pautaProgramada(Assembleia $assembleia, string $titulo, ?\DateTimeImmutable $abre, ?\DateTimeImmutable $encerra, array $campos = []): PautaDaAssembleia
    {
        return PautaDaAssembleiaFactory::new()->comOpcoesPadrao()->create(['assembleia' => $assembleia, 'titulo' => $titulo, 'abreEm' => $abre, 'encerraEm' => $encerra] + $campos)->_real();
    }

    private function votar(UnidadeDaAssembleia $unidade, PautaDaAssembleia $pauta, int $opcaoId): void
    {
        static::getContainer()->get(RegistrarVotoUseCase::class)->executar(
            RegistrarVotoInput::deUma(UnidadeDaAssembleiaFactory::tokenDe($unidade), (int) $pauta->getId(), $opcaoId),
        );
    }

    private function contar(string $tabela, PautaDaAssembleia $pauta): int
    {
        return (int) $this->em->getConnection()->fetchOne('SELECT COUNT(*) FROM ' . $tabela . ' WHERE pauta_id = ?', [$pauta->getId()]);
    }
}
