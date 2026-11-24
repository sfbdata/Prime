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
use App\Master\Service\ConveniosDoEscritorio;
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
        $semEstado = $this->escritorio(null, $this->criador(StatusOab::NaoVerificada));

        $saida = $this->rodarTarefa();
        $this->rodarTarefa();

        self::assertStringContainsString('criados:', $saida);
        self::assertCount(1, $this->abertos($advogado));
        self::assertSame([], $this->abertos($semOab));
        self::assertSame([], $this->abertos($divergente));
        self::assertSame([], $this->abertos($semEstado));
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

    #[TestDox('mudar o estado para fora de um estado válido fecha o vínculo com "estado_mudou" (gancho dos dados do escritório)')]
    public function testMudouDeEstado(): void
    {
        $advogado = $this->escritorio('Goiânia/GO', $this->criador(StatusOab::Confirmada));
        $this->useCase()->doEscritorio((int) $advogado->getId());
        $vinculo = $this->abertos($advogado)[0];

        static::getContainer()->get(SalvarDadosDoEscritorioUseCase::class)->executar($this->master(), $advogado, new SalvarDadosDoEscritorioInput(nome: (string) $advogado->getName(), cidadeUf: ''));

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

    private function escritorio(?string $cidadeUf, User $criador): Tenant
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

    private function criadorComInscricao(StatusOab $status): User
    {
        $u = $this->criador($status);
        $inscricao = new InscricaoOab($u, (string) random_int(10000, 99999), 'GO', TipoInscricaoOab::Principal);
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
