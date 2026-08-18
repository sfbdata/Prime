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
 * O INVARIANTE que estes testes existem para segurar: **pasta com cliente nunca fica sem
 * principal**, e ele é o **primeiro cliente vinculado** ou quem o dono marcou depois — nunca um
 * critério recalculado a cada leitura.
 *
 * Essa diferença é a feature inteira. Antes, "quem é o principal" era decidido na hora de ler,
 * pelo cliente de cadastro mais antigo; então vincular depois alguém cadastrado há mais tempo
 * TROCAVA o número na tela, sem ninguém ter pedido. O teste que prova a correção é o
 * testVincularOutroDepoisNaoTrocaOPrincipal().
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

    // ─────────────────── o primeiro vínculo grava o principal ───────────────────

    #[TestDox('O PRIMEIRO cliente vinculado já vira o principal, sem ninguém marcar nada')]
    public function testPrimeiroVinculoJaGravaOPrincipal(): void
    {
        $pasta     = $this->novaPasta();
        $primeiro  = $this->novoCliente(99, 'Zulmira Primeira');

        self::assertNull($pasta->getClientePrincipal(), 'pasta vazia não tem principal');

        $pasta->addCliente($primeiro);

        self::assertSame($primeiro, $pasta->getClientePrincipal());
    }

    /**
     * A REGRESSÃO QUE A FEATURE MATA. O id 1 é MENOR que o 50: pelo critério antigo, que ordenava
     * pelo id do cliente, este vínculo roubaria a média. Como agora a resposta está gravada, ele
     * não muda nada.
     */
    #[TestDox('Vincular DEPOIS um cliente de cadastro mais antigo NÃO troca o principal')]
    public function testVincularOutroDepoisNaoTrocaOPrincipal(): void
    {
        $pasta    = $this->novaPasta();
        $primeiro = $this->novoCliente(50, 'Primeiro Vinculado');
        $pasta->addCliente($primeiro);

        $pasta->addCliente($this->novoCliente(1, 'Antigo No Cadastro'));
        $pasta->addCliente($this->novoCliente(2, 'Outro Antigo'));

        self::assertSame(
            $primeiro,
            $pasta->getClientePrincipal(),
            'só se grava quando o campo está vazio — do segundo vínculo em diante nada muda'
        );
    }

    #[TestDox('Vincular o mesmo cliente duas vezes não duplica nem mexe no principal')]
    public function testVincularDuasVezesEhInocuo(): void
    {
        $pasta    = $this->novaPasta();
        $primeiro = $this->novoCliente(50, 'Primeiro Vinculado');
        $segundo  = $this->novoCliente(10, 'Segundo Vinculado');
        $pasta->addCliente($primeiro);
        $pasta->addCliente($segundo);
        $pasta->addCliente($primeiro);

        self::assertCount(2, $pasta->getClientes());
        self::assertSame($primeiro, $pasta->getClientePrincipal());
    }

    // ─────────────────── a troca manual pela estrela ───────────────────

    #[TestDox('Marcar outro cliente troca o principal e grava')]
    public function testMarcarOutroTrocaOPrincipal(): void
    {
        $pasta    = $this->novaPasta();
        $primeiro = $this->novoCliente(10, 'Primeiro Vinculado');
        $escolhido = $this->novoCliente(99, 'Escolhido Pelo Dono');
        $pasta->addCliente($primeiro);
        $pasta->addCliente($escolhido);

        $this->em->expects($this->once())->method('flush');

        $this->useCase->executar($pasta, $escolhido);

        self::assertSame($escolhido, $pasta->getClientePrincipal());
    }

    #[TestDox('A escolha do dono resiste a vínculos posteriores')]
    public function testEscolhaResisteAVinculoPosterior(): void
    {
        $pasta     = $this->novaPasta();
        $primeiro  = $this->novoCliente(10, 'Primeiro Vinculado');
        $escolhido = $this->novoCliente(99, 'Escolhido Pelo Dono');
        $pasta->addCliente($primeiro);
        $pasta->addCliente($escolhido);
        $this->useCase->executar($pasta, $escolhido);

        $pasta->addCliente($this->novoCliente(1, 'Antigo No Cadastro'));

        self::assertSame($escolhido, $pasta->getClientePrincipal());
    }

    #[TestDox('Marcar cliente que não está vinculado à pasta lança exceção')]
    public function testClienteNaoVinculadoLancaExcecao(): void
    {
        $pasta     = $this->novaPasta();
        $vinculado = $this->novoCliente(10, 'Fulano Vinculado');
        $deFora    = $this->novoCliente(20, 'Fulano De Fora');
        $pasta->addCliente($vinculado);

        $this->em->expects($this->never())->method('flush');

        $this->expectException(\DomainException::class);
        $this->useCase->executar($pasta, $deFora);
    }

    #[TestDox('Recusar cliente de fora não estraga o principal que já valia')]
    public function testRecusaNaoEstragaOPrincipalAtual(): void
    {
        $pasta     = $this->novaPasta();
        $vinculado = $this->novoCliente(10, 'Fulano Vinculado');
        $pasta->addCliente($vinculado);

        try {
            $this->useCase->executar($pasta, $this->novoCliente(20, 'Fulano De Fora'));
        } catch (\DomainException) {
            // esperado
        }

        self::assertSame($vinculado, $pasta->getClientePrincipal());
    }

    // ─────────────────── desvincular ───────────────────

    #[TestDox('Desvincular o principal PROMOVE outro — a pasta não fica sem principal')]
    public function testDesvincularOPrincipalPromoveOutro(): void
    {
        $pasta    = $this->novaPasta();
        $primeiro = $this->novoCliente(50, 'Primeiro Vinculado');
        $outro    = $this->novoCliente(10, 'Outro Cliente');
        $pasta->addCliente($primeiro);
        $pasta->addCliente($outro);

        $pasta->removeCliente($primeiro);

        self::assertSame($outro, $pasta->getClientePrincipal(), 'promoveu quem sobrou');
        self::assertCount(1, $pasta->getClientes());
    }

    #[TestDox('Desvincular quem NÃO é o principal não mexe na marcação')]
    public function testDesvincularOutroNaoMexeNoPrincipal(): void
    {
        $pasta    = $this->novaPasta();
        $primeiro = $this->novoCliente(50, 'Primeiro Vinculado');
        $outro    = $this->novoCliente(10, 'Outro Cliente');
        $pasta->addCliente($primeiro);
        $pasta->addCliente($outro);

        $pasta->removeCliente($outro);

        self::assertSame($primeiro, $pasta->getClientePrincipal());
    }

    #[TestDox('Desvincular o último cliente deixa a pasta sem principal')]
    public function testDesvincularOUltimoZeraOPrincipal(): void
    {
        $pasta = $this->novaPasta();
        $unico = $this->novoCliente(10, 'Cliente Unico');
        $pasta->addCliente($unico);

        $pasta->removeCliente($unico);

        self::assertNull($pasta->getClientePrincipal(), 'sem cliente não há principal');
    }

    #[TestDox('Vincular de novo depois de esvaziar volta a gravar o primeiro')]
    public function testAposEsvaziarOProximoVinculoVoltaAGravar(): void
    {
        $pasta = $this->novaPasta();
        $antigo = $this->novoCliente(10, 'Cliente Antigo');
        $pasta->addCliente($antigo);
        $pasta->removeCliente($antigo);

        $novo = $this->novoCliente(99, 'Cliente Novo');
        $pasta->addCliente($novo);

        self::assertSame($novo, $pasta->getClientePrincipal());
    }

    // ─────────────────── o invariante nas bordas ───────────────────

    #[TestDox('Pasta sem cliente nenhum não tem principal')]
    public function testPastaSemClienteNaoTemPrincipal(): void
    {
        self::assertNull($this->novaPasta()->getClientePrincipal());
    }

    /**
     * O caso do `ON DELETE SET NULL`: excluir o CLIENTE do sistema zera a coluna pelo banco, sem
     * passar por método nenhum da entidade. Sem o fallback, a pasta ficaria com clientes e sem
     * principal — o estado que o invariante proíbe — e a média sumiria da tela.
     */
    #[TestDox('Coluna zerada pelo banco (cliente excluído) não deixa a pasta sem principal')]
    public function testColunaZeradaPeloBancoCaiNoFallback(): void
    {
        $pasta = $this->novaPasta();
        $a     = $this->novoCliente(10, 'Cliente A');
        $b     = $this->novoCliente(20, 'Cliente B');
        $pasta->addCliente($a);
        $pasta->addCliente($b);

        // Simula o que a FK faz por fora: zera a coluna sem tocar na coleção.
        $refl = new \ReflectionProperty(Pasta::class, 'clientePrincipal');
        $refl->setValue($pasta, null);

        self::assertSame($a, $pasta->getClientePrincipal(), 'o fallback segura o invariante');
    }

    /**
     * A guarda de "ainda vinculado". Nenhum caminho vivo produz este estado hoje — foi medido:
     * `PastaType` e `PastaController::syncClientes()`, os dois candidatos, são código morto. A
     * guarda existe para o dia em que alguém religar um deles, ou para um UPDATE manual no banco.
     */
    #[TestDox('Marcação apontando para quem NÃO está mais vinculado não vaza para a tela')]
    public function testMarcacaoOrfaNaoVazaParaATela(): void
    {
        $pasta   = $this->novaPasta();
        $ficou   = $this->novoCliente(10, 'Cliente Que Ficou');
        $marcado = $this->novoCliente(99, 'Cliente Que Saiu');
        $pasta->addCliente($ficou);
        $pasta->addCliente($marcado);
        $this->useCase->executar($pasta, $marcado);

        // Tira da coleção SEM passar por removeCliente(), que é o que promoveria outro.
        $pasta->getClientes()->removeElement($marcado);

        self::assertSame(
            $ficou,
            $pasta->getClientePrincipal(),
            'marcação órfã não pode mostrar a média de quem não está na pasta'
        );
    }

    private function novaPasta(): Pasta
    {
        $pasta = new Pasta();
        $pasta->setTenant($this->tenant);

        return $pasta;
    }

    /**
     * O id importa: o fallback determinístico ordena por ele, então sem id os cenários de borda
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
