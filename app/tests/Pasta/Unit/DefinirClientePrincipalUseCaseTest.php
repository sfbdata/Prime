<?php

declare(strict_types=1);

namespace App\Tests\Pasta\Unit;

use App\Cliente\Entity\Cliente;
use App\Cliente\Entity\ClientePF;
use App\Entity\Tenant\Tenant;
use App\Pasta\Entity\Pasta;
use App\Pasta\UseCase\DefinirClientePrincipalUseCase;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * A regra do cliente principal, no nível em que ela mora: a entidade.
 *
 * O teste que importa aqui não é "a marcação grava" — é que ela **resiste**. O defeito que a
 * feature veio matar é a média trocar de dono sozinha quando alguém vincula um cliente mais antigo,
 * e é esse cenário que o testMarcacaoResisteAVinculoDeClienteMaisAntigo() reproduz.
 */
#[CoversClass(DefinirClientePrincipalUseCase::class)]
final class DefinirClientePrincipalUseCaseTest extends TestCase
{
    private EntityManagerInterface&MockObject $em;
    private DefinirClientePrincipalUseCase $useCase;
    private Tenant $tenant;

    protected function setUp(): void
    {
        $this->em      = $this->createMock(EntityManagerInterface::class);
        $this->useCase = new DefinirClientePrincipalUseCase($this->em);
        $this->tenant  = $this->createStub(Tenant::class);
    }

    #[TestDox('Sem marcação, manda o cliente de cadastro mais antigo (o critério de antes)')]
    public function testSemMarcacaoCaiNoClienteMaisAntigo(): void
    {
        $pasta   = $this->novaPasta();
        $antigo  = $this->novoCliente(10, 'Antonio Antigo');
        $recente = $this->novoCliente(99, 'Zulmira Recente');
        $pasta->addCliente($recente);   // vinculado primeiro de propósito
        $pasta->addCliente($antigo);

        self::assertSame($antigo, $pasta->getClientePrincipal());
        self::assertNull($pasta->getClientePrincipalMarcado(), 'sem marcação explícita');
    }

    #[TestDox('Marcar o cliente de id MAIOR faz a média ser dele — o cenário impossível antes')]
    public function testMarcarClienteDeIdMaiorPassaAMandar(): void
    {
        $pasta   = $this->novaPasta();
        $antigo  = $this->novoCliente(10, 'Antonio Antigo');
        $recente = $this->novoCliente(99, 'Zulmira Recente');
        $pasta->addCliente($antigo);
        $pasta->addCliente($recente);

        $this->em->expects($this->once())->method('flush');

        $this->useCase->executar($pasta, $recente);

        self::assertSame($recente, $pasta->getClientePrincipal());
        self::assertSame($recente, $pasta->getClientePrincipalMarcado());
    }

    #[TestDox('A REGRESSÃO QUE A FEATURE MATA: vincular depois um cliente mais antigo NÃO troca o principal')]
    public function testMarcacaoResisteAVinculoDeClienteMaisAntigo(): void
    {
        $pasta    = $this->novaPasta();
        $escolhido = $this->novoCliente(50, 'Escolhido Pelo Dono');
        $pasta->addCliente($escolhido);
        $this->useCase->executar($pasta, $escolhido);

        // Chega um cliente cadastrado ANTES. Pelo critério antigo, ele roubaria a média.
        $maisAntigoNoCadastro = $this->novoCliente(1, 'Antigo No Cadastro');
        $pasta->addCliente($maisAntigoNoCadastro);

        self::assertSame(
            $escolhido,
            $pasta->getClientePrincipal(),
            'vincular um cliente mais antigo não pode trocar o principal marcado — era exatamente esse o defeito'
        );
    }

    #[TestDox('Marcar outro cliente substitui o anterior — só existe um principal')]
    public function testMarcarOutroSubstituiOAnterior(): void
    {
        $pasta = $this->novaPasta();
        $a     = $this->novoCliente(10, 'Primeiro A');
        $b     = $this->novoCliente(20, 'Segundo B');
        $pasta->addCliente($a);
        $pasta->addCliente($b);

        $this->useCase->executar($pasta, $a);
        self::assertSame($a, $pasta->getClientePrincipalMarcado());

        $this->useCase->executar($pasta, $b);
        self::assertSame($b, $pasta->getClientePrincipalMarcado());
        self::assertNotSame($a, $pasta->getClientePrincipal());
    }

    #[TestDox('Cliente não vinculado à pasta é recusado, e nada é gravado')]
    public function testClienteNaoVinculadoLancaExcecao(): void
    {
        $pasta = $this->novaPasta();
        $pasta->addCliente($this->novoCliente(10, 'Vinculado'));

        $this->em->expects($this->never())->method('flush');
        $this->expectException(\DomainException::class);

        $this->useCase->executar($pasta, $this->novoCliente(11, 'De Fora'));
    }

    #[TestDox('Desvincular o principal apaga a marcação e devolve o critério automático')]
    public function testDesvincularOPrincipalVoltaAoAutomatico(): void
    {
        $pasta   = $this->novaPasta();
        $antigo  = $this->novoCliente(10, 'Antonio Antigo');
        $marcado = $this->novoCliente(99, 'Zulmira Marcada');
        $pasta->addCliente($antigo);
        $pasta->addCliente($marcado);
        $this->useCase->executar($pasta, $marcado);

        $pasta->removeCliente($marcado);

        self::assertNull($pasta->getClientePrincipalMarcado(), 'a marcação tem de sair junto com o vínculo');
        self::assertSame($antigo, $pasta->getClientePrincipal(), 'volta ao cliente de cadastro mais antigo');
    }

    #[TestDox('Desvincular um cliente qualquer não mexe na marcação')]
    public function testDesvincularOutroNaoMexeNaMarcacao(): void
    {
        $pasta   = $this->novaPasta();
        $marcado = $this->novoCliente(99, 'Zulmira Marcada');
        $outro   = $this->novoCliente(10, 'Antonio Outro');
        $pasta->addCliente($marcado);
        $pasta->addCliente($outro);
        $this->useCase->executar($pasta, $marcado);

        $pasta->removeCliente($outro);

        self::assertSame($marcado, $pasta->getClientePrincipal());
    }

    #[TestDox('Coluna apontando para quem NÃO está mais vinculado cai no automático')]
    public function testMarcacaoOrfaNaoVazaParaATela(): void
    {
        $pasta   = $this->novaPasta();
        $antigo  = $this->novoCliente(10, 'Antonio Antigo');
        $marcado = $this->novoCliente(99, 'Zulmira Marcada');
        $pasta->addCliente($antigo);
        $pasta->addCliente($marcado);
        $this->useCase->executar($pasta, $marcado);

        // Tira o cliente da coleção SEM passar por removeCliente(). Nenhum caminho REAL faz isso
        // hoje — a versão anterior deste comentário afirmava que o Symfony Form fazia, e é falso:
        // `PastaType` não é instanciado em lugar nenhum do `app/`. O que este teste prova é que a
        // guarda de "ainda vinculado" segura o estado inconsistente SE ele algum dia existir
        // (alguém religar `PastaType`/`syncClientes()`, ou um UPDATE manual no banco).
        // É teste de defesa preventiva, não de regressão de caminho vivo.
        $pasta->getClientes()->removeElement($marcado);

        self::assertSame(
            $antigo,
            $pasta->getClientePrincipal(),
            'marcação órfã não pode mostrar a média de quem não está na pasta'
        );
        self::assertNull($pasta->getClientePrincipalMarcado());
    }

    #[TestDox('Pasta sem cliente nenhum não tem principal')]
    public function testPastaSemClienteNaoTemPrincipal(): void
    {
        self::assertNull($this->novaPasta()->getClientePrincipal());
    }

    private function novaPasta(): Pasta
    {
        $pasta = new Pasta();
        $pasta->setTenant($this->tenant);

        return $pasta;
    }

    /**
     * O id importa: o critério automático ordena por ele, então sem id os cenários de fallback
     * não distinguem nada. Fora do banco só dá para pôr por reflexão.
     */
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
