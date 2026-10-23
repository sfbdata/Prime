<?php

declare(strict_types=1);

namespace App\Tests\Tenant\Integration;

use App\Entity\Auth\UserTenant;
use App\Entity\Tenant\TenantRole;
use App\Tenant\Repository\ConfiguracaoDeEdicaoRepository;
use App\Tenant\Service\PoderDeEdicaoSemPrazo;
use App\Tests\Tenant\Support\CenarioDeEdicaoSemPrazo;
use App\Tests\TrabalhoExterno\Unit\Apoio;
use App\TrabalhoExterno\Entity\ConfiguracaoCentral;
use App\TrabalhoExterno\Entity\DemandaExterna;
use App\TrabalhoExterno\Entity\MensagemDemanda;
use App\TrabalhoExterno\Enum\Perfil;
use App\TrabalhoExterno\Enum\TipoMensagem;
use App\TrabalhoExterno\Service\Contexto;
use App\TrabalhoExterno\Service\PoliticaDaDemanda;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\TestDox;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * A opção do primário lida para um escritório que NÃO é o da sessão (spec `edicao-sem-prazo-master-primario.md`,
 * item 8: o primário que vale é o do escritório dono da demanda do TE). O TenantFilter da sessão não pode esconder
 * a linha da opção desligada e fazer o "sem linha, ligada" liberar o poder (falha aberta).
 */
#[CoversClass(ConfiguracaoDeEdicaoRepository::class)]
#[CoversClass(PoderDeEdicaoSemPrazo::class)]
final class OpcaoDeEdicaoDeOutroEscritorioTest extends KernelTestCase
{
    use CenarioDeEdicaoSemPrazo;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->em = static::getContainer()->get(EntityManagerInterface::class);
    }

    #[TestDox('Sessão no escritório B, primário do escritório dono A com a opção DESLIGADA: sem poder, e não apaga a mensagem do TE')]
    public function testOpcaoDesligadaDoDonoValeComASessaoEmOutroEscritorio(): void
    {
        $dono = $this->novoEscritorio('Dono');
        $sessao = $this->novoEscritorio('Sede');
        $pessoa = $this->novaPessoa($sessao, 'Paula Equipe');
        $perfil = (new TenantRole())->setTenant($dono)->setName('Administrador do Escritório ' . uniqid())->setIsSystem(true);
        $this->em->persist($perfil);
        $this->em->persist((new UserTenant($pessoa, $dono))->setTenantRole($perfil));
        $this->em->flush();
        $this->desligarOpcaoDoPrimario($dono, $pessoa);
        $semLinha = $this->novoEscritorio('Sem linha');

        $this->em->getFilters()->enable('tenant')->setParameter('tenant', (int) $sessao->getId(), Types::INTEGER);

        $repositorio = static::getContainer()->get(ConfiguracaoDeEdicaoRepository::class);
        self::assertFalse($repositorio->primarioSemPrazo($dono), 'a linha do outro escritório não some pelo filtro da sessão');
        self::assertTrue($repositorio->primarioSemPrazo($semLinha), 'sem linha, continua ligada');
        self::assertNull(static::getContainer()->get(PoderDeEdicaoSemPrazo::class)->de($pessoa, $dono));

        $mensagem = new MensagemDemanda(new DemandaExterna($dono, $pessoa), Apoio::autor(Perfil::Solicitante), TipoMensagem::Normal, 'Olá');
        $ctx = new Contexto($pessoa, $sessao, Perfil::Equipe, new ConfiguracaoCentral());
        $politica = static::getContainer()->get(PoliticaDaDemanda::class);
        self::assertFalse($politica->podeApagarMensagem($ctx, $mensagem, new \DateTimeImmutable('+3 days')));
        self::assertNull($politica->seloDoApagar($ctx, $mensagem, new \DateTimeImmutable('+3 days')));
    }
}
