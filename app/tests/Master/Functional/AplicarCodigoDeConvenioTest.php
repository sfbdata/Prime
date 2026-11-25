<?php

declare(strict_types=1);

namespace App\Tests\Master\Functional;

use App\Auth\Enum\StatusOab;
use App\Entity\Audit\AuditLog;
use App\Entity\Auth\User;
use App\Entity\Auth\UserTenant;
use App\Entity\Tenant\Tenant;
use App\Entity\Tenant\TenantRole;
use App\Master\DTO\DadosDoConvenio;
use App\Master\Entity\ConfiguracaoGlobal;
use App\Master\Entity\Convenio;
use App\Master\Entity\ConvenioVinculo;
use App\Master\Enum\AbrangenciaDoConvenio;
use App\Master\Enum\BeneficioDoConvenio;
use App\Master\Enum\MotivoDoFimDoVinculo;
use App\Master\Enum\OrigemDoVinculo;
use App\Master\Exception\ConvenioRecusado;
use App\Master\Repository\ConfiguracaoGlobalRepository;
use App\Master\Repository\ConvenioRepository;
use App\Master\Repository\ConvenioVinculoRepository;
use App\Master\UseCase\AplicarCodigoDeConvenioUseCase;
use App\Tenant\Exception\OperacaoDePrimarioRecusada;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\TestDox;
use Psr\Clock\ClockInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * Código de convite (spec docs/specs/convenios.md, §5.3, PR 5): o primário aplica, o vínculo nasce com origem
 * "codigo" e Trilha no escritório; toda recusa tem o mesmo texto; o convênio que já vale diz isso.
 */
#[CoversClass(AplicarCodigoDeConvenioUseCase::class)]
#[CoversClass(ConvenioRepository::class)]
#[CoversClass(ConvenioVinculoRepository::class)]
final class AplicarCodigoDeConvenioTest extends KernelTestCase
{
    private EntityManagerInterface $em;
    private \DateTimeImmutable $hoje;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->em = static::getContainer()->get(EntityManagerInterface::class);
        $this->hoje = \DateTimeImmutable::createFromInterface(static::getContainer()->get(ClockInterface::class)->now())->setTime(0, 0);
    }

    #[TestDox('primário aplica o código (minúsculas e hífen valem): vínculo por código, de quem aplicou, com Trilha no escritório')]
    public function testAplica(): void
    {
        $convenio = $this->convenio();
        [$tenant, $primario] = $this->escritorio('SP');

        $resultado = $this->aplicar($primario, $tenant, ' ' . strtolower(substr($this->codigo($convenio), 0, 4)) . '-' . strtolower(substr($this->codigo($convenio), 4)));

        self::assertSame($convenio->getId(), $resultado->getId());
        $abertos = $this->abertos($tenant);
        self::assertCount(1, $abertos);
        self::assertSame(OrigemDoVinculo::Codigo, $abertos[0]->getOrigem());
        self::assertSame($primario->getId(), $abertos[0]->getCriadoPor()?->getId());
        $trilha = $this->em->getRepository(AuditLog::class)->findOneBy(['entityClass' => ConvenioVinculo::class], ['id' => 'DESC']);
        self::assertNotNull($trilha);
        self::assertSame($tenant->getId(), $trilha->getTenantId(), 'a Trilha do vínculo fica no escritório');
    }

    #[TestDox('o mesmo código de novo: "já vale"; secundário não aplica')]
    public function testJaValeESecundario(): void
    {
        $convenio = $this->convenio();
        [$tenant, $primario] = $this->escritorio('SP');
        $this->aplicar($primario, $tenant, $this->codigo($convenio));

        try {
            $this->aplicar($primario, $tenant, $this->codigo($convenio));
            self::fail('o segundo código deveria ser recusado');
        } catch (ConvenioRecusado $e) {
            self::assertSame('Este convênio já vale para o seu escritório.', $e->getMessage());
        }

        $secundario = $this->usuario();
        $this->em->persist(new UserTenant($secundario, $tenant));
        $this->em->flush();
        $this->expectException(OperacaoDePrimarioRecusada::class);
        $this->aplicar($secundario, $tenant, $this->codigo($this->convenio()));
    }

    #[TestDox('recusa com o mesmo texto: código que não existe, curto, desligado, vencido, com fim marcado e no limite de usos')]
    public function testRecusas(): void
    {
        [$tenant, $primario] = $this->escritorio('SP');
        $desligado = $this->convenio();
        $desligado->desligar(null, $this->hoje);
        $vencido = $this->convenio(validoAte: $this->hoje->modify('-1 day'));
        $comFim = $this->convenio();
        $comFim->marcarFim($this->hoje->modify('+40 days'), null, null, $this->hoje);
        $noLimite = $this->convenio(limite: 1);
        $usado = new ConvenioVinculo($this->escritorio('SP')[0], $noLimite, OrigemDoVinculo::Codigo, $this->hoje);
        $usado->encerrar(MotivoDoFimDoVinculo::Saiu, $this->hoje);
        $this->em->persist($usado);
        $this->em->flush();

        foreach (['ZZZZ9999', 'AB', $this->codigo($desligado), $this->codigo($vencido), $this->codigo($comFim), $this->codigo($noLimite)] as $codigo) {
            $this->assertRecusado($primario, $tenant, $codigo);
        }
        self::assertSame([], $this->abertos($tenant));
    }

    #[TestDox('convênio de um estado: recusa escritório de outro, aceita o do mesmo e o que ainda não tem estado')]
    public function testEstado(): void
    {
        $doGo = $this->convenio(uf: 'GO');
        [$deSp, $primarioSp] = $this->escritorio('SP');
        [$deGo, $primarioGo] = $this->escritorio('GO');
        [$semEstado, $primarioSemEstado] = $this->escritorio(null);

        $this->assertRecusado($primarioSp, $deSp, $this->codigo($doGo));
        $this->aplicar($primarioGo, $deGo, $this->codigo($doGo));
        $this->aplicar($primarioSemEstado, $semEstado, $this->codigo($doGo));

        self::assertCount(1, $this->abertos($deGo));
        self::assertCount(1, $this->abertos($semEstado));
    }

    #[TestDox('escritório sem estado: o convênio de um estado confere a UF da OAB do criador; sem OAB, aceita')]
    public function testSemEstadoConfereAUfDaOab(): void
    {
        $doGo = $this->convenio(uf: 'GO');
        [$comOabSp, $primarioSp] = $this->escritorio(null, 'SP');
        [$comOabGo, $primarioGo] = $this->escritorio(null, 'GO');

        $this->assertRecusado($primarioSp, $comOabSp, $this->codigo($doGo));
        $this->aplicar($primarioGo, $comOabGo, $this->codigo($doGo));

        self::assertSame([], $this->abertos($comOabSp));
        self::assertCount(1, $this->abertos($comOabGo));
    }

    #[TestDox('chave geral do convênio desligada: todo código é recusado')]
    public function testChaveGeralDesligada(): void
    {
        $convenio = $this->convenio();
        [$tenant, $primario] = $this->escritorio('SP');
        $configuracao = static::getContainer()->get(ConfiguracaoGlobalRepository::class)->buscarUnica() ?? new ConfiguracaoGlobal();
        $configuracao->definirConvenioAtivo(false, null, $this->hoje);
        $this->em->persist($configuracao);
        $this->em->flush();

        $this->assertRecusado($primario, $tenant, $this->codigo($convenio));
    }

    private function assertRecusado(User $quem, Tenant $tenant, string $codigo): void
    {
        try {
            $this->aplicar($quem, $tenant, $codigo);
            self::fail('o código ' . $codigo . ' deveria ser recusado');
        } catch (ConvenioRecusado $e) {
            self::assertSame(AplicarCodigoDeConvenioUseCase::RECUSA, $e->getMessage(), $codigo);
        }
    }

    private function aplicar(User $quem, Tenant $tenant, string $codigo): Convenio
    {
        return static::getContainer()->get(AplicarCodigoDeConvenioUseCase::class)->executar($quem, $tenant, $codigo);
    }

    private function convenio(?string $uf = null, ?\DateTimeImmutable $validoAte = null, ?int $limite = null): Convenio
    {
        $abrangencia = $uf === null ? AbrangenciaDoConvenio::Nacional : AbrangenciaDoConvenio::Uf;
        $dados = new DadosDoConvenio('Convênio ' . uniqid(), 'Caixa de Assistencia', 'caixa-de-assistencia', $abrangencia, $uf, BeneficioDoConvenio::Gratuidade, 0, 'CONVÊNIO', $this->hoje->modify('-10 days'), $validoAte, $limite);
        $convenio = Convenio::novoPorCodigo('t-' . substr(uniqid(), -10), $dados, substr(str_shuffle('ABCDEFGHJKLMNPQRSTUVWXYZ23456789'), 0, 8), null, $this->hoje);
        $this->em->persist($convenio);
        $this->em->flush();

        return $convenio;
    }

    private function codigo(Convenio $convenio): string
    {
        return (string) $convenio->getCodigoConvite();
    }

    /**
     * @param ?string $ufDaOab UF da OAB do criador (o primário), no espelho do usuário
     *
     * @return array{Tenant, User}
     */
    private function escritorio(?string $estado, ?string $ufDaOab = null): array
    {
        $primario = $this->usuario();
        if ($ufDaOab !== null) {
            $primario->setOabNumero((string) random_int(10000, 99999))->setOabUf($ufDaOab)->setOabStatus(StatusOab::Confirmada);
        }
        $tenant = (new Tenant())->setName('Conta do código ' . uniqid())->setEstado($estado)->setCriadoPor($primario);
        $this->em->persist($tenant);
        $papel = (new TenantRole())->setTenant($tenant)->setName('Administrador do Escritório')->setIsSystem(true);
        $this->em->persist($papel);
        $this->em->persist((new UserTenant($primario, $tenant))->setTenantRole($papel));
        $this->em->flush();

        return [$tenant, $primario];
    }

    private function usuario(): User
    {
        $user = (new User())->setEmail('codigo_' . uniqid() . '@adv.com')->setFullName('Pessoa do Código')->setRoles(['ROLE_USER'])->setIsActive(true)->setPassword('x');
        $this->em->persist($user);

        return $user;
    }

    /** @return list<ConvenioVinculo> */
    private function abertos(Tenant $tenant): array
    {
        return static::getContainer()->get(ConvenioVinculoRepository::class)->abertosDosTenants([(int) $tenant->getId()])[(int) $tenant->getId()] ?? [];
    }
}
