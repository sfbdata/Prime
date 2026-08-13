<?php

declare(strict_types=1);

namespace App\Tests\Pasta\Functional;

use App\Entity\Tenant\Tenant;
use App\Pasta\Entity\Pasta;
use App\Pasta\UseCase\GerarNumeroDePasta;
use App\Tests\Factory\Pasta\PastaFactory;
use App\Tests\Factory\Tenant\TenantFactory;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\TestDox;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Zenstruck\Foundry\Test\Factories;

/**
 * Funcional (não unit) de propósito: o que precisa ser provado é a expressão SQL do prefixo
 * numérico e o recorte por tenant_id contra o Postgres de verdade. Com EntityManager mockado
 * o teste passaria sem provar nada disso.
 */
#[CoversClass(GerarNumeroDePasta::class)]
final class GerarNumeroDePastaTest extends KernelTestCase
{
    use Factories;

    private function gerador(): GerarNumeroDePasta
    {
        return static::getContainer()->get(GerarNumeroDePasta::class);
    }

    private function em(): EntityManagerInterface
    {
        return static::getContainer()->get(EntityManagerInterface::class);
    }

    /** Roda o gerador com transação aberta, que é o contrato da classe. */
    private function proximoDe(Tenant $tenant): string
    {
        return $this->em()->wrapInTransaction(fn (): string => $this->gerador()->executar($tenant));
    }

    #[TestDox('Escritório sem nenhuma pasta começa no 1')]
    public function testEscritorioVazioComecaNoUm(): void
    {
        self::bootKernel();
        $tenant = TenantFactory::createOne()->_real();

        self::assertSame('1', $this->proximoDe($tenant));
    }

    #[TestDox('Devolve o maior número + 1')]
    public function testDevolveMaiorMaisUm(): void
    {
        self::bootKernel();
        $tenant = TenantFactory::createOne()->_real();
        foreach (['7', '30', '9'] as $nup) {
            PastaFactory::createOne(['nup' => $nup, 'tenant' => $tenant]);
        }

        self::assertSame('31', $this->proximoDe($tenant));
    }

    #[TestDox('NÃO preenche buracos: com 1, 2 e 9 o próximo é 10, não 3')]
    public function testNaoPreencheBuracos(): void
    {
        self::bootKernel();
        $tenant = TenantFactory::createOne()->_real();
        foreach (['1', '2', '9'] as $nup) {
            PastaFactory::createOne(['nup' => $nup, 'tenant' => $tenant]);
        }

        self::assertSame('10', $this->proximoDe($tenant));
    }

    #[TestDox('Sufixo de letra do legado conta pelo prefixo: 10A e 10B levam ao 11')]
    public function testSufixoDeLetraContaPeloPrefixo(): void
    {
        self::bootKernel();
        $tenant = TenantFactory::createOne()->_real();
        foreach (['9', '10A', '10B'] as $nup) {
            PastaFactory::createOne(['nup' => $nup, 'tenant' => $tenant]);
        }

        self::assertSame('11', $this->proximoDe($tenant));
    }

    #[TestDox('NUP não numérico é ignorado no cálculo')]
    public function testNupNaoNumericoEhIgnorado(): void
    {
        self::bootKernel();
        $tenant = TenantFactory::createOne()->_real();
        PastaFactory::createOne(['nup' => 'PROC-ABC', 'tenant' => $tenant]);
        PastaFactory::createOne(['nup' => '5', 'tenant' => $tenant]);

        self::assertSame('6', $this->proximoDe($tenant));
    }

    #[TestDox('Só não numéricos: volta ao 1 em vez de quebrar')]
    public function testSoNaoNumericosVoltaAoUm(): void
    {
        self::bootKernel();
        $tenant = TenantFactory::createOne()->_real();
        PastaFactory::createOne(['nup' => 'PROC-ABC', 'tenant' => $tenant]);

        self::assertSame('1', $this->proximoDe($tenant));
    }

    #[TestDox('ISOLAMENTO: a numeração de um escritório ignora as pastas do outro')]
    public function testNumeracaoEhIsoladaPorEscritorio(): void
    {
        self::bootKernel();
        $meu    = TenantFactory::createOne()->_real();
        $outro  = TenantFactory::createOne()->_real();

        PastaFactory::createOne(['nup' => '900', 'tenant' => $outro]);
        PastaFactory::createOne(['nup' => '4', 'tenant' => $meu]);

        // Se o filtro de tenant_id caísse do SQL cru, viria '901' — o número do vizinho.
        self::assertSame('5', $this->proximoDe($meu));
        self::assertSame('901', $this->proximoDe($outro));
    }

    #[TestDox('Sem transação aberta a trava não seguraria: falha alto em vez de gerar número inseguro')]
    public function testSemTransacaoLancaLogicException(): void
    {
        self::bootKernel();
        $tenant = TenantFactory::createOne()->_real();

        // A DAMA segura a transação de rollback no nível do DRIVER, que o DBAL não enxerga:
        // `isTransactionActive()` é false aqui, mesmo com a suíte transacionada. Ou seja,
        // basta chamar o gerador sem `wrapInTransaction` para exercitar o guard — que é
        // exatamente o descuido que ele existe para pegar.
        self::assertFalse($this->em()->getConnection()->isTransactionActive());

        $this->expectException(\LogicException::class);
        $this->gerador()->executar($tenant);
    }

    #[TestDox('O número gerado grava e o seguinte já conta com ele')]
    public function testNumeroGeradoAvancaAposGravar(): void
    {
        self::bootKernel();
        $tenant = TenantFactory::createOne()->_real();
        PastaFactory::createOne(['nup' => '12', 'tenant' => $tenant]);

        $primeiro = $this->proximoDe($tenant);
        self::assertSame('13', $primeiro);

        PastaFactory::createOne(['nup' => $primeiro, 'tenant' => $tenant]);
        self::assertSame('14', $this->proximoDe($tenant));
    }

    #[TestDox('Escritório não persistido é recusado')]
    public function testTenantSemIdEhRecusado(): void
    {
        self::bootKernel();
        $tenant = new Tenant();
        $tenant->setName('Nunca gravado');

        $this->expectException(\InvalidArgumentException::class);
        $this->proximoDe($tenant);
    }

    #[TestDox('LIMITE CONHECIDO: apagar a pasta de MAIOR número faz esse número ser reusado')]
    public function testApagarAMaiorPastaReusaONumero(): void
    {
        self::bootKernel();
        $tenant = TenantFactory::createOne()->_real();
        $pasta  = PastaFactory::createOne(['nup' => '50', 'tenant' => $tenant])->_real();

        self::assertSame('51', $this->proximoDe($tenant));

        $em = $this->em();
        $em->remove($em->find(Pasta::class, $pasta->getId()));
        $em->flush();

        // Sem outras pastas o MAX volta a 0 e o próximo vira 1 — o número da pasta apagada é
        // REUSADO. É o limite real de derivar a sequência do MAX: "não preencher buracos" só
        // vale para buracos no meio; o topo, se apagado, volta. Guardar um contador por
        // escritório resolveria, mas exige migration (fora do escopo desta fatia, D12.2).
        // Impacto hoje: nenhum. A limpeza do R6 apaga as pastas 1178, 1177 e 1191 — nups
        // 1214/1221/1227, todos abaixo do topo 1231, que não sai.
        self::assertSame('1', $this->proximoDe($tenant));
    }
}
