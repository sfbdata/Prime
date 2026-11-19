<?php

declare(strict_types=1);

namespace App\Tests\Conector\Integration;

use App\Conector\Entity\EscritorioDoConector;
use App\Conector\Exception\AcessoDoConectorRecusado;
use App\Conector\Repository\EscritorioDoConectorRepository;
use App\Conector\Service\LiberacaoDoConector;
use App\Entity\Auth\User;
use App\Entity\Tenant\Tenant;
use App\Master\Entity\RecursoEstado;
use App\Master\Service\RecursosDoSistema;
use App\Tests\Factory\Auth\UserFactory;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\TestDox;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Zenstruck\Foundry\Test\Factories;

/**
 * Os dois níveis da liberação do conector (spec mcp-remoto-oauth, decisão 14.a, decisão do Samuel de 19/11) no banco:
 * chave geral do Master (interruptor de emergência) e conector ligado pelo escritório com o aceite vigente. Não há
 * liberação do Master por escritório. Cada teste sobe o kernel de novo, porque o
 * `RecursosDoSistema` guarda o estado das chaves na primeira leitura.
 */
#[CoversClass(LiberacaoDoConector::class)]
final class LiberacaoDoConectorTest extends KernelTestCase
{
    use Factories;
    use SemeiaConector;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->em = static::getContainer()->get(EntityManagerInterface::class);
    }

    private function sut(): LiberacaoDoConector
    {
        // Sem consumidor ainda (os blocos do PR 3 o injetam), o serviço sai do contêiner compilado: monta-se com as
        // dependências reais.
        return new LiberacaoDoConector(
            static::getContainer()->get(RecursosDoSistema::class),
            static::getContainer()->get(EscritorioDoConectorRepository::class),
        );
    }

    private function escritorio(Tenant $tenant, bool $ligado): EscritorioDoConector
    {
        $escritorio = new EscritorioDoConector($tenant);
        if ($ligado) {
            $escritorio->ligar($this->administrador(), new \DateTimeImmutable());
        }
        $this->em->persist($escritorio);
        $this->em->flush();

        return $escritorio;
    }

    private function administrador(): User
    {
        return UserFactory::createOne()->_real();
    }

    private function exigirRecusa(Tenant $tenant, string $porque): void
    {
        try {
            $this->sut()->exigir($tenant);
            self::fail('passou sem liberação: ' . $porque);
        } catch (AcessoDoConectorRecusado $e) {
            self::assertSame(AcessoDoConectorRecusado::MOTIVO_LIBERACAO, $e->motivo, $porque);
            self::assertSame(AcessoDoConectorRecusado::CONECTOR_NAO_LIBERADO, $e->getMessage(), $porque);
        }
    }

    #[TestDox('Os dois níveis completos (chave geral sem registro, ligado com o aceite vigente): passa, sem nenhuma ação do Master sobre o escritório')]
    public function testDoisNiveisPassam(): void
    {
        $tenant = $this->tenantNovo();
        $this->escritorio($tenant, ligado: true);

        $this->sut()->exigir($tenant);

        $this->addToAssertionCount(1);
    }

    #[TestDox('Nível 1: a chave geral conector_ia desligada pelo Master recusa mesmo com o escritório ligado e com o aceite')]
    public function testChaveGeralDesligadaRecusa(): void
    {
        $tenant = $this->tenantNovo();
        $this->escritorio($tenant, ligado: true);
        $estado = $this->em->getRepository(RecursoEstado::class)->findOneBy(['chave' => LiberacaoDoConector::CHAVE_GERAL]) ?? new RecursoEstado(LiberacaoDoConector::CHAVE_GERAL);
        $estado->desativar('Pausa', null, null, new \DateTimeImmutable());
        $this->em->persist($estado);
        $this->em->flush();

        $this->exigirRecusa($tenant, 'chave geral desligada');
    }

    #[TestDox('Nível 2: escritório sem linha recusa; sem ligar (sem aceite) recusa; desligado depois do aceite também')]
    public function testSemLigarRecusa(): void
    {
        $this->exigirRecusa($this->tenantNovo(), 'escritório sem linha em conector_ia_escritorio');

        $tenant = $this->tenantNovo();
        $this->escritorio($tenant, ligado: false);
        $this->exigirRecusa($tenant, 'sem ligar');

        $outro = $this->tenantNovo();
        $escritorio = $this->escritorio($outro, ligado: true);
        $escritorio->desligar();
        $this->em->flush();
        $this->exigirRecusa($outro, 'desligado depois do aceite');
    }

    #[TestDox('A ligação é por escritório: A ligado não liga B')]
    public function testOutroEscritorioNaoHerda(): void
    {
        $a = $this->tenantNovo();
        $b = $this->tenantNovo();
        $this->escritorio($a, ligado: true);

        $this->sut()->exigir($a);
        $this->exigirRecusa($b, 'outro escritório');
    }
}
