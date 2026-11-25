<?php

declare(strict_types=1);

namespace App\Tests\Master\Functional;

use App\Auth\Entity\InscricaoOab;
use App\Auth\Enum\StatusOab;
use App\Auth\Enum\TipoInscricaoOab;
use App\Entity\Audit\AuditLog;
use App\Entity\Auth\User;
use App\Entity\Tenant\Tenant;
use App\Master\Command\SincronizarVinculosDeConvenioCommand;
use App\Master\Entity\ConfiguracaoGlobal;
use App\Master\Entity\Convenio;
use App\Master\Entity\ConvenioDaUf;
use App\Master\Entity\ConvenioVinculo;
use App\Master\Enum\AbrangenciaDoConvenio;
use App\Master\Enum\BeneficioDoConvenio;
use App\Master\Enum\MotivoDoFimDoVinculo;
use App\Master\Enum\ProvaDoConvenio;
use App\Master\Repository\ConfiguracaoGlobalRepository;
use App\Master\Repository\ConvenioDaUfRepository;
use App\Master\Repository\ConvenioRepository;
use App\Master\Repository\ConvenioVinculoRepository;
use App\Master\Repository\PainelMasterRepository;
use App\Master\Service\ConveniosDoEscritorio;
use App\Master\Service\UfDoEscritorio;
use App\Master\UseCase\SincronizarVinculosDeConvenioUseCase;
use App\Tenant\DTO\SalvarDadosDoEscritorioInput;
use App\Tenant\UseCase\SalvarDadosDoEscritorioUseCase;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\TestDox;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * A sincronização dos vínculos de convênio no banco (spec docs/specs/convenios.md, §5.1, PR 3): a tarefa diária cria o
 * vínculo OAB de quem cabe, não duplica, marca o fim do OAB implícito e grava a Trilha no escritório do vínculo; os
 * ganchos da mudança de estado e da UF acompanham na hora.
 */
#[CoversClass(SincronizarVinculosDeConvenioUseCase::class)]
#[CoversClass(SincronizarVinculosDeConvenioCommand::class)]
#[CoversClass(ConvenioVinculoRepository::class)]
#[CoversClass(PainelMasterRepository::class)]
#[CoversClass(UfDoEscritorio::class)]
final class SincronizarVinculosDeConvenioTest extends KernelTestCase
{
    private EntityManagerInterface $em;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->em = static::getContainer()->get(EntityManagerInterface::class);
        $this->oab();
    }

    #[TestDox('tarefa diária: cria o vínculo de quem cabe, só dele, sem duplicar, e o OAB implícito acaba')]
    public function testTarefaDiaria(): void
    {
        $advogado = $this->escritorio('Goiânia/GO', $this->criador(StatusOab::Confirmada));
        $semOab = $this->escritorio('Goiânia/GO', $this->criador(null));
        $divergente = $this->escritorio('Goiânia/GO', $this->criadorComInscricao(StatusOab::Divergente));
        $semEstadoComOab = $this->escritorio(null, $this->criador(StatusOab::NaoVerificada));
        $semEstadoSemOab = $this->escritorio(null, $this->criador(null));

        $saida = $this->rodarTarefa();
        $this->rodarTarefa();

        self::assertStringContainsString('criados:', $saida);
        self::assertCount(1, $this->abertos($advogado));
        self::assertSame([], $this->abertos($semOab));
        self::assertSame([], $this->abertos($divergente));
        self::assertCount(1, $this->abertos($semEstadoComOab), 'sem estado, vale a UF da OAB do criador');
        self::assertSame([], $this->abertos($semEstadoSemOab));
        self::assertNotNull(static::getContainer()->get(ConfiguracaoGlobalRepository::class)->buscarUnica()?->getVinculosDeConvenioSincronizadosEm());
        $vigentes = static::getContainer()->get(ConveniosDoEscritorio::class)->dosTenants([(int) $advogado->getId() => 'GO', (int) $semOab->getId() => 'GO']);
        self::assertFalse($vigentes[(int) $advogado->getId()][0]->implicito);
        self::assertSame([], $vigentes[(int) $semOab->getId()], 'depois da primeira volta, sem vínculo não há OAB implícito');
    }

    #[TestDox('a Trilha do vínculo criado fica no escritório dele, não na plataforma')]
    public function testTrilhaNoEscritorio(): void
    {
        $advogado = $this->escritorio('Goiânia/GO', $this->criador(StatusOab::Confirmada));

        $this->useCase()->doEscritorio((int) $advogado->getId());

        self::assertCount(1, $this->abertos($advogado));
        // Na inserção o id ainda não existe quando a Trilha grava: a linha é a mais recente do vínculo.
        $linha = $this->em->getRepository(AuditLog::class)->findOneBy(['entityClass' => ConvenioVinculo::class], ['id' => 'DESC']);
        self::assertNotNull($linha);
        self::assertSame('create', $linha->getAction());
        self::assertSame($advogado->getId(), $linha->getTenantId());
    }

    #[TestDox('apagar o estado mantém o vínculo pela UF da OAB; ir para uma UF "sem convênio" fecha com "estado_mudou" (gancho dos dados do escritório)')]
    public function testMudouDeEstado(): void
    {
        $advogado = $this->escritorio('Goiânia/GO', $this->criador(StatusOab::Confirmada));
        $this->useCase()->doEscritorio((int) $advogado->getId());
        $vinculo = $this->abertos($advogado)[0];
        $salvar = static::getContainer()->get(SalvarDadosDoEscritorioUseCase::class);

        $salvar->executar($this->master(), $advogado, new SalvarDadosDoEscritorioInput(nome: (string) $advogado->getName(), cidadeUf: ''));
        self::assertSame([$vinculo->getId()], array_map(static fn (ConvenioVinculo $v): ?int => $v->getId(), $this->abertos($advogado)), 'sem estado, a UF é a da OAB (GO)');

        $sp = static::getContainer()->get(ConvenioDaUfRepository::class)->buscar('SP');
        if ($sp !== null) {
            $this->em->remove($sp);
            $this->em->flush();
        }
        $this->em->persist(new ConvenioDaUf('SP', ConvenioDaUf::MODO_SEM, 0.0, new \DateTimeImmutable('2026-01-01'), null, null, new \DateTimeImmutable()));
        $this->em->flush();
        $salvar->executar($this->master(), $advogado, new SalvarDadosDoEscritorioInput(nome: (string) $advogado->getName(), cidadeUf: 'São Paulo/SP'));

        self::assertSame([], $this->abertos($advogado));
        self::assertSame(MotivoDoFimDoVinculo::EstadoMudou, $vinculo->getMotivoFim());
    }

    #[TestDox('UF marcada "sem convênio" fecha os vínculos dela com "uf_desligada"; de volta a grátis, reabre em linha nova')]
    public function testUfDesligadaEReligada(): void
    {
        $advogado = $this->escritorio('Goiânia/GO', $this->criador(StatusOab::Confirmada));
        $this->useCase()->doEscritorio((int) $advogado->getId());
        $vinculo = $this->abertos($advogado)[0];

        $excecao = new ConvenioDaUf('GO', ConvenioDaUf::MODO_SEM, 0.0, new \DateTimeImmutable('2026-01-01'), null, null, new \DateTimeImmutable());
        $this->em->persist($excecao);
        $this->em->flush();
        $this->useCase()->daUf('GO');

        self::assertSame(MotivoDoFimDoVinculo::UfDesligada, $vinculo->getMotivoFim());
        self::assertSame([], $this->abertos($advogado));

        $this->em->remove($excecao);
        $this->em->flush();
        $this->useCase()->daUf('GO');

        $novo = $this->abertos($advogado);
        self::assertCount(1, $novo);
        self::assertNotSame($vinculo->getId(), $novo[0]->getId());
    }

    #[TestDox('chave geral desligada: nenhum vínculo novo e o OAB implícito não acaba')]
    public function testChaveDesligada(): void
    {
        $advogado = $this->escritorio('Goiânia/GO', $this->criador(StatusOab::Confirmada));
        $configuracoes = static::getContainer()->get(ConfiguracaoGlobalRepository::class);
        $configuracao = $configuracoes->buscarUnica() ?? new ConfiguracaoGlobal();
        $configuracao->definirConvenioAtivo(false, null, new \DateTimeImmutable());
        $configuracoes->salvar($configuracao, true);
        $antes = $configuracao->getVinculosDeConvenioSincronizadosEm();

        $this->useCase()->executar();

        self::assertSame([], $this->abertos($advogado));
        self::assertEquals($antes, $configuracoes->buscarUnica()?->getVinculosDeConvenioSincronizadosEm());
    }

    #[TestDox('consulta em lote: OAB válida pela inscrição (uma não divergente basta) ou, sem inscrição, pelo espelho do usuário')]
    public function testOabValidaNaConsulta(): void
    {
        $semCriador = $this->escritorio('Goiânia/GO', null);
        $espelhoConfirmado = $this->escritorio('Goiânia/GO', $this->criador(StatusOab::Confirmada));
        $espelhoDivergente = $this->escritorio('Goiânia/GO', $this->criador(StatusOab::Divergente));
        $mista = $this->criadorComInscricao(StatusOab::Divergente);
        $suplementar = new InscricaoOab($mista, (string) random_int(10000, 99999), 'SP', TipoInscricaoOab::Suplementar);
        $suplementar->registrarVerificacao(StatusOab::Confirmada, null);
        $this->em->persist($suplementar);
        $this->em->flush();
        $inscricaoMista = $this->escritorio('Goiânia/GO', $mista);
        $emQuarentena = $this->escritorio('Goiânia/GO', $this->criador(StatusOab::Confirmada));
        $emQuarentena->setExcluidoEm(new \DateTimeImmutable());
        $this->em->flush();

        $lidos = static::getContainer()->get(ConvenioVinculoRepository::class)->escritoriosParaConvenio(array_map(static fn (Tenant $t): int => (int) $t->getId(), [$semCriador, $espelhoConfirmado, $espelhoDivergente, $inscricaoMista, $emQuarentena]));
        $porId = [];
        foreach ($lidos as $e) {
            $porId[$e->tenantId] = [$e->uf, $e->oabValida, $e->emQuarentena];
        }

        self::assertSame(['GO', false, false], $porId[$semCriador->getId()]);
        self::assertSame(['GO', true, false], $porId[$espelhoConfirmado->getId()]);
        self::assertSame(['GO', false, false], $porId[$espelhoDivergente->getId()]);
        self::assertSame(['GO', true, false], $porId[$inscricaoMista->getId()]);
        self::assertSame(['GO', true, true], $porId[$emQuarentena->getId()]);
    }

    #[TestDox('escritório sem estado: a UF é a da OAB válida do criador (principal primeiro); com estado, vale o estado')]
    public function testSemEstadoUsaAUfDaOab(): void
    {
        $doCriador = $this->criadorComInscricao(StatusOab::Confirmada, 'SP');
        $suplementar = new InscricaoOab($doCriador, (string) random_int(10000, 99999), 'RJ', TipoInscricaoOab::Suplementar);
        $suplementar->registrarVerificacao(StatusOab::Confirmada, null);
        $this->em->persist($suplementar);
        $this->em->flush();
        $pelaInscricao = $this->escritorio(null, $doCriador);
        $peloEspelho = $this->escritorio(null, $this->criador(StatusOab::NaoVerificada));
        $estadoInvalido = $this->escritorio(null, $this->criador(StatusOab::Confirmada));
        $estadoInvalido->setEstado('XX');
        $soDivergente = $this->escritorio(null, $this->criadorComInscricao(StatusOab::Divergente, 'SP'));
        $comEstado = $this->escritorio('Rio de Janeiro/RJ', $this->criadorComInscricao(StatusOab::Confirmada, 'SP'));
        $this->em->flush();

        $ids = array_map(static fn (Tenant $t): int => (int) $t->getId(), [$pelaInscricao, $peloEspelho, $estadoInvalido, $soDivergente, $comEstado]);
        $uf = [];
        foreach (static::getContainer()->get(ConvenioVinculoRepository::class)->escritoriosParaConvenio($ids) as $e) {
            $uf[$e->tenantId] = $e->uf;
        }
        $this->useCase()->dosEscritorios($ids);
        $doPainel = [];
        foreach (static::getContainer()->get(PainelMasterRepository::class)->escritorios() as $linha) {
            if (in_array($linha['id'], $ids, true)) {
                $doPainel[$linha['id']] = UfDoEscritorio::daLinha($linha);
            }
        }

        self::assertSame('SP', $uf[$pelaInscricao->getId()], 'a inscrição principal, não a suplementar');
        self::assertSame('GO', $uf[$peloEspelho->getId()], 'sem linha em inscricao_oab, o espelho do usuário');
        self::assertSame('GO', $uf[$estadoInvalido->getId()], 'estado que não é UF conta como vazio');
        self::assertNull($uf[$soDivergente->getId()]);
        self::assertSame('RJ', $uf[$comEstado->getId()], 'o estado do escritório vem antes da OAB');
        self::assertCount(1, $this->abertos($pelaInscricao));
        self::assertCount(1, $this->abertos($peloEspelho));
        self::assertSame([], $this->abertos($soDivergente));
        ksort($uf);
        ksort($doPainel);
        self::assertSame($uf, $doPainel, 'o painel do Master (rótulo, contagem e aviso da UF) lê a mesma UF');
    }

    private function useCase(): SincronizarVinculosDeConvenioUseCase
    {
        return static::getContainer()->get(SincronizarVinculosDeConvenioUseCase::class);
    }

    private function rodarTarefa(): string
    {
        $tester = new CommandTester((new Application(self::$kernel))->find('app:convenios:sincronizar-vinculos'));
        $tester->execute([]);
        $tester->assertCommandIsSuccessful();

        return $tester->getDisplay();
    }

    /** @return list<ConvenioVinculo> */
    private function abertos(Tenant $tenant): array
    {
        return static::getContainer()->get(ConvenioVinculoRepository::class)->abertosDosTenants([(int) $tenant->getId()])[(int) $tenant->getId()] ?? [];
    }

    /** A linha `oab` vem da migration; no banco montado só com o mapeamento, o teste a cria. Goiás sem exceção. */
    private function oab(): void
    {
        if (static::getContainer()->get(ConvenioRepository::class)->porChave(Convenio::CHAVE_OAB) === null) {
            $this->em->persist(new Convenio(Convenio::CHAVE_OAB, 'Convênio OAB', 'OAB', 'oab', AbrangenciaDoConvenio::Nacional, null, ProvaDoConvenio::Oab, BeneficioDoConvenio::Gratuidade, 0, 'CONVÊNIO OAB', new \DateTimeImmutable('2026-10-26'), new \DateTimeImmutable()));
        }
        $go = static::getContainer()->get(ConvenioDaUfRepository::class)->buscar('GO');
        if ($go !== null) {
            $this->em->remove($go);
        }
        $this->em->flush();
    }

    private function escritorio(?string $cidadeUf, ?User $criador): Tenant
    {
        $tenant = (new Tenant())->setName('Escritório do convênio ' . uniqid())->setEstado($cidadeUf === null ? null : substr($cidadeUf, -2))->setCriadoPor($criador);
        $this->em->persist($tenant);
        $this->em->flush();

        return $tenant;
    }

    private function criador(?StatusOab $status): User
    {
        $u = $this->usuario(['ROLE_USER']);
        if ($status !== null) {
            $u->setOabNumero((string) random_int(10000, 99999))->setOabUf('GO')->setOabStatus($status);
            $this->em->flush();
        }

        return $u;
    }

    private function criadorComInscricao(StatusOab $status, string $uf = 'GO'): User
    {
        $u = $this->criador($status);
        $inscricao = new InscricaoOab($u, (string) random_int(10000, 99999), $uf, TipoInscricaoOab::Principal);
        $inscricao->registrarVerificacao($status, null);
        $this->em->persist($inscricao);
        $this->em->flush();

        return $u;
    }

    private function master(): User
    {
        return $this->usuario(['ROLE_SUPER_ADMIN', 'ROLE_MASTER_PRIMARIO']);
    }

    /** @param list<string> $papeis */
    private function usuario(array $papeis): User
    {
        $u = new User();
        $u->setEmail('vinculo_' . uniqid() . '@teste.com');
        $u->setFullName('Pessoa do Vínculo');
        $u->setPassword('x');
        $u->setRoles($papeis);
        $u->setIsActive(true);
        $this->em->persist($u);
        $this->em->flush();

        return $u;
    }
}
