<?php

declare(strict_types=1);

namespace App\Tests\Inteligencia\Integration;

use App\ChatIa\Service\ProtecaoDoTextoDoChat;
use App\Inteligencia\Entity\ConfiguracaoDeInteligencia;
use App\Inteligencia\Service\PoliticaDeMascaramento;
use App\Tests\Conector\Support\CenarioDoConectorTrait;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\TestDox;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * A máscara dentro do conector de I.A. (spec mcp-remoto-oauth 6.2, regra 6), com o container e o portão de verdade:
 * aberto o portão, mascara mesmo com o escritório tendo desligado a máscara; no `reset()` do fim da requisição, volta a
 * preferência. O Chat I.A. (fora do portão) não muda.
 */
#[CoversClass(PoliticaDeMascaramento::class)]
final class PoliticaDeMascaramentoNoConectorTest extends KernelTestCase
{
    use CenarioDoConectorTrait;

    private const CPF = '529.982.247-25';

    protected function setUp(): void
    {
        self::bootKernel();
    }

    protected function tearDown(): void
    {
        static::getContainer()->get('services_resetter')->reset();
        parent::tearDown();
    }

    #[TestDox('com a máscara desligada no escritório: fora do conector o CPF fica; com o portão aberto, sai; depois do reset, fica de novo')]
    public function testSempreMascaraNoConector(): void
    {
        $escritorio = $this->escritorio();
        $pessoa = $this->pessoa();
        $this->vinculo($pessoa, $escritorio, permissoes: ['modules.pastas.view']);
        $configuracao = new ConfiguracaoDeInteligencia($escritorio);
        $configuracao->atualizar(true, 10, 100, false, $pessoa);
        $this->em()->persist($configuracao);
        $this->em()->flush();
        $c = static::getContainer();

        self::assertFalse($c->get(PoliticaDeMascaramento::class)->mascarar($escritorio), 'fora do conector, a preferência');
        self::assertStringContainsString(self::CPF, $c->get(ProtecaoDoTextoDoChat::class)->comoDado($escritorio, 'CPF ' . self::CPF));

        $this->portao()->abrir((int) $pessoa->getId(), (int) $escritorio->getId());
        self::assertTrue($c->get(PoliticaDeMascaramento::class)->mascarar($escritorio));
        self::assertStringNotContainsString(self::CPF, $c->get(ProtecaoDoTextoDoChat::class)->comoDado($escritorio, 'CPF ' . self::CPF));
        self::assertStringNotContainsString(self::CPF, $c->get(ProtecaoDoTextoDoChat::class)->livre($escritorio, 'meu CPF é ' . self::CPF));

        $c->get('services_resetter')->reset();
        self::assertFalse($c->get(PoliticaDeMascaramento::class)->mascarar($escritorio), 'a requisição seguinte volta a seguir a preferência');
    }
}
