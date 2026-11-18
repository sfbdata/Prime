<?php

declare(strict_types=1);

namespace App\Tests\Inteligencia\Unit;

use App\ChatIa\Service\ProtecaoDoTextoDoChat;
use App\Entity\Auth\User;
use App\Entity\Tenant\Tenant;
use App\Inteligencia\Entity\ConfiguracaoDeInteligencia;
use App\Inteligencia\Repository\ConfiguracaoDeInteligenciaRepository;
use App\Inteligencia\Service\MascaradorDeDadosPessoais;
use App\Inteligencia\Service\PoliticaDeMascaramento;
use App\Tests\Inteligencia\Support\DefineId;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;

/**
 * A pergunta única da máscara fora do conector (spec mcp-remoto-oauth 6.2, regra 6): a preferência do escritório, com
 * "mascara" quando não há configuração. Dentro do conector (sempre mascara), com o portão de verdade:
 * `tests/Inteligencia/Integration/PoliticaDeMascaramentoNoConectorTest`.
 */
#[CoversClass(PoliticaDeMascaramento::class)]
#[CoversClass(ProtecaoDoTextoDoChat::class)]
final class PoliticaDeMascaramentoTest extends TestCase
{
    private const CPF = '529.982.247-25';

    private Tenant $escritorio;
    private ?ConfiguracaoDeInteligencia $configuracao = null;

    protected function setUp(): void
    {
        $this->escritorio = (new Tenant())->setName('Escritório da Máscara');
        DefineId::em($this->escritorio, 7);
    }

    #[TestDox('fora do conector: segue a preferência do escritório; sem configuração, mascara')]
    public function testForaDoConectorSegueAPreferencia(): void
    {
        self::assertTrue($this->politica()->mascarar($this->escritorio), 'sem configuração gravada, mascara');

        $this->preferencia(false);
        self::assertFalse($this->politica()->mascarar($this->escritorio));

        $this->preferencia(true);
        self::assertTrue($this->politica()->mascarar($this->escritorio));
    }

    #[TestDox('ProtecaoDoTextoDoChat pela política, fora do conector: com a máscara desligada no escritório, o CPF fica; ligada, sai')]
    public function testProtecaoSegueAPolitica(): void
    {
        $this->preferencia(false);
        $protecao = new ProtecaoDoTextoDoChat($this->repositorio(), new MascaradorDeDadosPessoais(), $this->politica());
        self::assertStringContainsString(self::CPF, $protecao->comoDado($this->escritorio, 'CPF ' . self::CPF));

        $this->preferencia(true);
        self::assertStringNotContainsString(self::CPF, $protecao->comoDado($this->escritorio, 'CPF ' . self::CPF));
        self::assertStringNotContainsString(self::CPF, $protecao->rotulo($this->escritorio, 'Fulano ' . self::CPF, 'sem nome'));
    }

    #[TestDox('sem a política no construtor (montagem antiga), a ProtecaoDoTextoDoChat segue a preferência do escritório')]
    public function testSemPoliticaSegueAPreferencia(): void
    {
        $this->preferencia(false);
        $protecao = new ProtecaoDoTextoDoChat($this->repositorio(), new MascaradorDeDadosPessoais());

        self::assertStringContainsString(self::CPF, $protecao->comoDado($this->escritorio, 'CPF ' . self::CPF));
    }

    private function politica(): PoliticaDeMascaramento
    {
        return new PoliticaDeMascaramento($this->repositorio());
    }

    private function repositorio(): ConfiguracaoDeInteligenciaRepository
    {
        $repositorio = $this->createStub(ConfiguracaoDeInteligenciaRepository::class);
        $repositorio->method('findDoTenant')->willReturnCallback(fn (): ?ConfiguracaoDeInteligencia => $this->configuracao);

        return $repositorio;
    }

    private function preferencia(bool $mascarar): void
    {
        $this->configuracao = new ConfiguracaoDeInteligencia($this->escritorio);
        $this->configuracao->atualizar(true, 10, 100, $mascarar, (new User())->setFullName('Quem configurou'));
    }
}
