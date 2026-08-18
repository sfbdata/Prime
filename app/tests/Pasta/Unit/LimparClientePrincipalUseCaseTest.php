<?php

declare(strict_types=1);

namespace App\Tests\Pasta\Unit;

use App\Cliente\Entity\Cliente;
use App\Cliente\Entity\ClientePF;
use App\Entity\Tenant\Tenant;
use App\Pasta\Entity\Pasta;
use App\Pasta\UseCase\DefinirClientePrincipalUseCase;
use App\Pasta\UseCase\LimparClientePrincipalUseCase;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * A volta ao automático.
 *
 * Sem esta ação, marcar era via de mão única: escolhido um cliente, a única saída pela interface
 * era desvincular e revincular alguém — destrutivo e nada óbvio. O que importa provar aqui é que
 * limpar devolve o comando ao critério de cadastro mais antigo E que a coluna some de verdade,
 * não que ela apenas pare de aparecer.
 */
#[CoversClass(LimparClientePrincipalUseCase::class)]
final class LimparClientePrincipalUseCaseTest extends TestCase
{
    private EntityManagerInterface&MockObject $em;
    private LimparClientePrincipalUseCase $useCase;
    private DefinirClientePrincipalUseCase $marcar;
    private Tenant $tenant;

    protected function setUp(): void
    {
        $this->em      = $this->createMock(EntityManagerInterface::class);
        $this->useCase = new LimparClientePrincipalUseCase($this->em);
        $this->marcar  = new DefinirClientePrincipalUseCase($this->em);
        $this->tenant  = $this->createStub(Tenant::class);
    }

    #[TestDox('Desmarcar devolve a média ao cliente de cadastro mais antigo')]
    public function testLimparVoltaAoCriterioAutomatico(): void
    {
        $pasta   = $this->novaPasta();
        $antigo  = $this->novoCliente(10, 'Antonio Antigo');
        $recente = $this->novoCliente(99, 'Zulmira Recente');
        $pasta->addCliente($antigo);
        $pasta->addCliente($recente);

        $this->marcar->executar($pasta, $recente);
        self::assertSame($recente, $pasta->getClientePrincipal(), 'pré-condição: a escolha vale');

        $this->useCase->executar($pasta);

        self::assertSame($antigo, $pasta->getClientePrincipal(), 'sem marcação, manda o mais antigo');
        self::assertNull($pasta->getClientePrincipalMarcado(), 'a marcação tem de sumir, não só deixar de aparecer');
    }

    #[TestDox('Desmarcar grava (flush), senão a escolha volta no próximo carregamento')]
    public function testLimparPersiste(): void
    {
        $pasta  = $this->novaPasta();
        $unico  = $this->novoCliente(10, 'Antonio Antigo');
        $pasta->addCliente($unico);
        $pasta->definirClientePrincipal($unico);

        $this->em->expects($this->once())->method('flush');

        $this->useCase->executar($pasta);
    }

    #[TestDox('Desmarcar uma pasta que já está no automático é inócuo, não é erro')]
    public function testLimparPastaSemMarcacaoNaoExplode(): void
    {
        $pasta  = $this->novaPasta();
        $antigo = $this->novoCliente(10, 'Antonio Antigo');
        $pasta->addCliente($antigo);

        $this->useCase->executar($pasta);

        self::assertSame($antigo, $pasta->getClientePrincipal());
        self::assertNull($pasta->getClientePrincipalMarcado());
    }

    #[TestDox('Desmarcar não desvincula ninguém — a pasta continua com os mesmos clientes')]
    public function testLimparNaoMexeNosVinculos(): void
    {
        $pasta   = $this->novaPasta();
        $antigo  = $this->novoCliente(10, 'Antonio Antigo');
        $recente = $this->novoCliente(99, 'Zulmira Recente');
        $pasta->addCliente($antigo);
        $pasta->addCliente($recente);
        $this->marcar->executar($pasta, $recente);

        $this->useCase->executar($pasta);

        self::assertCount(2, $pasta->getClientes(), 'desmarcar mexe na preferência, não no vínculo');
        self::assertTrue($pasta->getClientes()->contains($recente));
    }

    #[TestDox('Depois de desmarcar dá para marcar outro — a ação não trava a pasta')]
    public function testDepoisDeLimparDaParaMarcarDeNovo(): void
    {
        $pasta   = $this->novaPasta();
        $antigo  = $this->novoCliente(10, 'Antonio Antigo');
        $recente = $this->novoCliente(99, 'Zulmira Recente');
        $pasta->addCliente($antigo);
        $pasta->addCliente($recente);

        $this->marcar->executar($pasta, $recente);
        $this->useCase->executar($pasta);
        $this->marcar->executar($pasta, $antigo);

        self::assertSame($antigo, $pasta->getClientePrincipalMarcado());
    }

    /**
     * O motivo de "fixar o automático" existir na tela: sem marcar, vincular um cliente de
     * cadastro mais antigo TROCA a média — que é o defeito de origem da feature. Depois de
     * desmarcar, a pasta volta a ficar exposta a ele, e isso é o esperado, não um bug.
     */
    #[TestDox('Desmarcado, o número volta a ser vulnerável ao vínculo de um cliente mais antigo')]
    public function testDesmarcadoVoltaAFicarVulneravel(): void
    {
        $pasta     = $this->novaPasta();
        $escolhido = $this->novoCliente(50, 'Escolhido Pelo Dono');
        $pasta->addCliente($escolhido);
        $this->marcar->executar($pasta, $escolhido);

        $this->useCase->executar($pasta);

        $maisAntigo = $this->novoCliente(1, 'Antigo No Cadastro');
        $pasta->addCliente($maisAntigo);

        self::assertSame(
            $maisAntigo,
            $pasta->getClientePrincipal(),
            'sem marcação o critério automático volta a mandar — é por isso que a tela oferece "fixar"'
        );
    }

    private function novaPasta(): Pasta
    {
        $pasta = new Pasta();
        $pasta->setTenant($this->tenant);

        return $pasta;
    }

    private function novoCliente(int $id, string $nome): Cliente
    {
        $cliente = new ClientePF();
        $cliente->setTenant($this->tenant);
        $cliente->setNomeCompleto($nome);

        $refl = new \ReflectionProperty(Cliente::class, 'id');
        $refl->setValue($cliente, $id);

        return $cliente;
    }
}
