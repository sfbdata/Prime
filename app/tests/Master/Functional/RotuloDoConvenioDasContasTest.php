<?php

declare(strict_types=1);

namespace App\Tests\Master\Functional;

use App\Entity\Auth\User;
use App\Entity\Tenant\Tenant;
use App\Master\Entity\ConfiguracaoGlobal;
use App\Master\Entity\Convenio;
use App\Master\Entity\ConvenioDaUf;
use App\Master\Entity\ConvenioVinculo;
use App\Master\Enum\AbrangenciaDoConvenio;
use App\Master\Enum\BeneficioDoConvenio;
use App\Master\Enum\MotivoDoFimDoVinculo;
use App\Master\Enum\OrigemDoVinculo;
use App\Master\Enum\ProvaDoConvenio;
use App\Master\Repository\ConfiguracaoGlobalRepository;
use App\Master\Repository\ConvenioDaUfRepository;
use App\Master\Repository\ConvenioRepository;
use App\Master\Repository\ConvenioVinculoRepository;
use App\Master\Service\ConveniosDoEscritorio;
use App\Master\UseCase\ConsultarContasUseCase;
use App\Tests\Functional\JusPrimeWebTestCase;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\TestDox;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;

/**
 * "Mesma saída" do PR 2 dos convênios (spec docs/specs/convenios.md, §3.4, decisão de 24/11): o rótulo de convênio das
 * contas no painel do Master, agora lido pela ConveniosDoEscritorio, sai igual ao de antes (livre, dif, sem). Com a
 * chave geral desligada, some (ficou fora do PR #922). Também prova as consultas do ConvenioVinculoRepository no banco.
 */
#[CoversClass(ConsultarContasUseCase::class)]
#[CoversClass(ConveniosDoEscritorio::class)]
#[CoversClass(ConvenioVinculoRepository::class)]
final class RotuloDoConvenioDasContasTest extends JusPrimeWebTestCase
{
    private KernelBrowser $client;
    private EntityManagerInterface $em;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $this->client->disableReboot();
        $this->em = static::getContainer()->get(EntityManagerInterface::class);
    }

    #[TestDox('chave ligada e sem vínculo gravado: livre, dif e sem como antes; conta sem estado segue livre')]
    public function testMesmaSaida(): void
    {
        $this->entrarComoMaster();
        $this->oab();
        $this->excecao('RJ', ConvenioDaUf::MODO_DIFERENCIADO, 9.9);
        $this->excecao('MG', ConvenioDaUf::MODO_SEM, 0.0);
        $sp = $this->conta('SP');
        $rj = $this->conta('RJ');
        $mg = $this->conta('MG');
        $semEstado = $this->conta(null);

        $contas = $this->contas();

        self::assertSame(['uf' => 'SP', 'convenio' => ['modo' => 'livre', 'valor' => 0]], $this->rotulo($contas, $sp));
        self::assertSame(['uf' => 'RJ', 'convenio' => ['modo' => 'dif', 'valor' => 9.9]], $this->rotulo($contas, $rj));
        self::assertSame(['uf' => 'MG', 'convenio' => ['modo' => 'sem', 'valor' => 0]], $this->rotulo($contas, $mg));
        self::assertSame(['uf' => null, 'convenio' => ['modo' => 'livre', 'valor' => 0]], $this->rotulo($contas, $semEstado));
    }

    #[TestDox('chave geral desligada: nenhuma conta traz rótulo de convênio')]
    public function testChaveDesligada(): void
    {
        $this->entrarComoMaster();
        $this->oab();
        $this->excecao('MG', ConvenioDaUf::MODO_SEM, 0.0);
        $sp = $this->conta('SP');
        $mg = $this->conta('MG');
        $this->chaveGeral(false);

        $contas = $this->contas();

        self::assertNull($this->rotulo($contas, $sp)['convenio']);
        self::assertNull($this->rotulo($contas, $mg)['convenio']);
    }

    #[TestDox('repositório: só vínculos abertos voltam; aberto ou fechado, o escritório conta como "com vínculo"')]
    public function testConsultasDosVinculos(): void
    {
        $oab = $this->oab();
        $caarj = new Convenio('caarj-' . uniqid(), 'Convênio CAARJ', 'CAARJ', 'caarj', AbrangenciaDoConvenio::Instituicao, null, ProvaDoConvenio::Codigo, BeneficioDoConvenio::EspacoExtra, 5, 'CONVÊNIO CAARJ', new \DateTimeImmutable('2026-11-01'), new \DateTimeImmutable(), substr('C' . uniqid(), 0, 16));
        $this->em->persist($caarj);
        $aberto = $this->conta('RJ');
        $fechado = $this->conta('SP');
        $nenhum = $this->conta('SP');
        $this->em->persist(new ConvenioVinculo($aberto, $caarj, OrigemDoVinculo::Codigo, new \DateTimeImmutable('2026-11-01')));
        $antigo = new ConvenioVinculo($fechado, $oab, OrigemDoVinculo::Oab, new \DateTimeImmutable('2026-11-01'));
        $antigo->encerrar(MotivoDoFimDoVinculo::EstadoMudou, new \DateTimeImmutable('2026-11-10'));
        $this->em->persist($antigo);
        $this->em->flush();
        $ids = [(int) $aberto->getId(), (int) $fechado->getId(), (int) $nenhum->getId()];
        $repositorio = static::getContainer()->get(ConvenioVinculoRepository::class);

        $abertos = $repositorio->abertosDosTenants($ids);
        $comVinculo = $repositorio->tenantsComVinculo($ids);

        self::assertSame([(int) $aberto->getId()], array_keys($abertos));
        self::assertSame($caarj, $abertos[(int) $aberto->getId()][0]->getConvenio());
        sort($comVinculo);
        self::assertSame([(int) $aberto->getId(), (int) $fechado->getId()], $comVinculo);
        self::assertSame([], $repositorio->abertosDosTenants([]));
    }

    /** A linha `oab` vem da migration; no banco montado só com o mapeamento, o teste a cria. */
    private function oab(): Convenio
    {
        $oab = static::getContainer()->get(ConvenioRepository::class)->porChave(Convenio::CHAVE_OAB);
        if ($oab === null) {
            $oab = new Convenio(Convenio::CHAVE_OAB, 'Convênio OAB', 'OAB', 'oab', AbrangenciaDoConvenio::Nacional, null, ProvaDoConvenio::Oab, BeneficioDoConvenio::Gratuidade, 0, 'CONVÊNIO OAB', new \DateTimeImmutable('2026-10-26'), new \DateTimeImmutable());
            $this->em->persist($oab);
            $this->em->flush();
        }

        return $oab;
    }

    private function excecao(string $uf, string $modo, float $valor): void
    {
        $repositorio = static::getContainer()->get(ConvenioDaUfRepository::class);
        $agora = new \DateTimeImmutable();
        $existente = $repositorio->buscar($uf);
        if ($existente !== null) {
            $existente->definir($modo, $valor, $agora, null, null, $agora);
        } else {
            $this->em->persist(new ConvenioDaUf($uf, $modo, $valor, $agora, null, null, $agora));
        }
        $this->em->flush();
    }

    private function chaveGeral(bool $ligada): void
    {
        $configuracoes = static::getContainer()->get(ConfiguracaoGlobalRepository::class);
        $configuracao = $configuracoes->buscarUnica() ?? new ConfiguracaoGlobal();
        $configuracao->definirConvenioAtivo($ligada, null, new \DateTimeImmutable());
        $configuracoes->salvar($configuracao, true);
    }

    private function conta(?string $uf): Tenant
    {
        $tenant = (new Tenant())->setName('Conta do rótulo ' . uniqid())->setEstado($uf);
        $this->em->persist($tenant);
        $this->em->flush();

        return $tenant;
    }

    /** @return array<int, array<string, mixed>> */
    private function contas(): array
    {
        $this->client->request('GET', '/master/dados/contas');
        self::assertResponseIsSuccessful();
        $json = json_decode((string) $this->client->getResponse()->getContent(), true, 16, \JSON_THROW_ON_ERROR);

        return array_column($json['contas'], null, 'id');
    }

    /**
     * @param array<int, array<string, mixed>> $contas
     *
     * @return array{uf: mixed, convenio: mixed}
     */
    private function rotulo(array $contas, Tenant $tenant): array
    {
        $conta = $contas[$tenant->getId()];

        return ['uf' => $conta['uf'], 'convenio' => $conta['convenio']];
    }

    private function entrarComoMaster(): void
    {
        $master = new User();
        $master->setEmail('rotulo_' . uniqid() . '@teste.com');
        $master->setFullName('Master do Rótulo');
        $master->setPassword('x');
        $master->setRoles(['ROLE_SUPER_ADMIN', 'ROLE_MASTER_PRIMARIO']);
        $master->setIsActive(true);
        $this->em->persist($master);
        $this->em->flush();
        $this->client->loginUser($master);
        $this->marcarTermosAceitos($this->client);
    }
}
