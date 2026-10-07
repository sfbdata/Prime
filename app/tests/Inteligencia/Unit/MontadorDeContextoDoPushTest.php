<?php

declare(strict_types=1);

namespace App\Tests\Inteligencia\Unit;

use App\Entity\Auth\User;
use App\Entity\Tenant\Tenant;
use App\Inteligencia\Contexto\MontadorDeContextoDoPush;
use App\Inteligencia\Entity\ConfiguracaoDeInteligencia;
use App\Inteligencia\Prompt\PromptResumoDoPush;
use App\Inteligencia\Repository\ConfiguracaoDeInteligenciaRepository;
use App\Inteligencia\Service\MascaradorDeDadosPessoais;
use App\Pasta\Entity\Pasta;
use App\Processo\Entity\Processo;
use App\Tests\Inteligencia\Support\DefineId;
use App\Tests\Inteligencia\Support\FonteDeMovimentacoesFalsa;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * IA-033: o cabeçalho do Push mascara a PII na MESMA política do cabeçalho dos agentes da pasta —
 * o que é da pasta (NUP, responsável, equipe) passa pelo mascarador; o cadastro do processo
 * (número CNJ, classe, assunto, tribunal, órgão) vai como está, como na `linhaDoProcesso`.
 *
 * O cabeçalho do Push não tem nome de cliente (só NUP, processo, classe, assunto, tribunal, órgão,
 * responsável e equipe): os nomes que ele carrega são os da equipe, e é neles que o CPF é plantado.
 */
#[CoversClass(MontadorDeContextoDoPush::class)]
final class MontadorDeContextoDoPushTest extends TestCase
{
    private const CPF_DO_RESPONSAVEL = '123.456.789-09';
    private const CPF_DA_EQUIPE = '529.982.247-25';
    private const CNJ = '0701134-57.2025.8.07.0007';

    private Tenant $tenant;
    private User $responsavel;
    private Pasta $pasta;
    private FonteDeMovimentacoesFalsa $fonte;
    private ConfiguracaoDeInteligencia $configuracao;
    private ConfiguracaoDeInteligenciaRepository&MockObject $configuracoes;

    protected function setUp(): void
    {
        $this->tenant = new Tenant();
        DefineId::em($this->tenant, 42);

        $this->responsavel = new User();
        $this->responsavel->setFullName('Fulano CPF ' . self::CPF_DO_RESPONSAVEL);
        DefineId::em($this->responsavel, 9);

        $processo = new Processo();
        $processo->setTenant($this->tenant);
        $processo->setNumeroProcesso(self::CNJ);
        $processo->setClasseProcessual('Procedimento Comum');
        $processo->setSiglaTribunal('TJDFT');
        DefineId::em($processo, 3);

        $this->pasta = new Pasta();
        $this->pasta->setTenant($this->tenant);
        $this->pasta->setNup('1234');
        $this->pasta->setNomeAcao('Cobrança');
        $this->pasta->setResponsavel($this->responsavel);
        $this->pasta->vincularProcesso($processo);
        DefineId::em($this->pasta, 7);

        $this->fonte = new FonteDeMovimentacoesFalsa();
        $this->fonte->equipe = ['Dra. Ana', 'Beltrano ' . self::CPF_DA_EQUIPE];

        $this->configuracao = new ConfiguracaoDeInteligencia($this->tenant);
        $this->configuracao->atualizar(true, 50, 500, true, $this->responsavel);
        $this->configuracoes = $this->createMock(ConfiguracaoDeInteligenciaRepository::class);
        $this->configuracoes->method('findDoTenant')->willReturnCallback(fn (): ConfiguracaoDeInteligencia => $this->configuracao);
    }

    private function sut(): MontadorDeContextoDoPush
    {
        return new MontadorDeContextoDoPush($this->fonte, $this->configuracoes, new MascaradorDeDadosPessoais());
    }

    #[TestDox('CPF no nome do responsável e da equipe sai [CPF]; o número CNJ do processo segue intacto')]
    public function testCabecalhoMascaraNomesEPreservaCnj(): void
    {
        $contexto = $this->sut()->para($this->tenant, $this->pasta);

        self::assertSame('Fulano CPF [CPF]', $contexto->cabecalho['responsavel']);
        self::assertSame('Dra. Ana, Beltrano [CPF]', $contexto->cabecalho['equipe']);
        self::assertSame(self::CNJ, $contexto->cabecalho['processo'], 'o CNJ não é mascarado (nem por pedaço)');

        // E é isso que chega ao provedor: o pedido inteiro não carrega nenhum dos dois CPFs.
        $pedido = (new PromptResumoDoPush())->montar($contexto, null);
        $enviado = $pedido->sistema . "\n" . implode("\n", array_column($pedido->mensagens, 'conteudo'));
        self::assertStringNotContainsString(self::CPF_DO_RESPONSAVEL, $enviado);
        self::assertStringNotContainsString(self::CPF_DA_EQUIPE, $enviado);
        self::assertStringContainsString('Responsável pela pasta: Fulano CPF [CPF].', $enviado);
        self::assertStringContainsString('Processo ' . self::CNJ, $enviado);
    }

    #[TestDox('o NUP da pasta passa pelo mascarador — e um CNJ usado como NUP segue intacto (o mascarador o protege)')]
    public function testNupMascaradoComCnjProtegido(): void
    {
        $this->pasta->setNup('CPF ' . self::CPF_DO_RESPONSAVEL);
        self::assertSame('CPF [CPF]', $this->sut()->para($this->tenant, $this->pasta)->cabecalho['pasta']);

        $this->pasta->setNup(self::CNJ);
        self::assertSame(self::CNJ, $this->sut()->para($this->tenant, $this->pasta)->cabecalho['pasta']);
    }

    #[TestDox('o cadastro do processo (classe, órgão) não passa pelo mascarador — mesma política da linha do processo dos agentes')]
    public function testCadastroDoProcessoNaoMascarado(): void
    {
        $processo = $this->pasta->getProcessos()->first();
        self::assertInstanceOf(Processo::class, $processo);
        // 11 dígitos com DV de CPF válido: o mascarador trocaria; o cabeçalho do processo não o chama.
        $processo->setOrgaoJulgador('Vara 12345678909');

        $contexto = $this->sut()->para($this->tenant, $this->pasta);

        self::assertSame($processo->getOrgaoJulgador(), $contexto->cabecalho['orgao']);
        self::assertSame($processo->getClasseProcessual(), $contexto->cabecalho['classe']);
    }

    #[TestDox('escritório que desligou a máscara recebe o nome como está (a preferência vale também para o cabeçalho)')]
    public function testSemMascaraNomeVaiComoEsta(): void
    {
        $this->configuracao->atualizar(true, 50, 500, false, $this->responsavel);

        $contexto = $this->sut()->para($this->tenant, $this->pasta);

        self::assertSame('Fulano CPF ' . self::CPF_DO_RESPONSAVEL, $contexto->cabecalho['responsavel']);
    }

    #[TestDox('IA-033: a análise anterior sai neutralizada e mascarada (CPF vira [CPF], CNJ intacto)')]
    public function testAnaliseAnteriorMascarada(): void
    {
        $saida = $this->sut()->analiseAnterior(
            $this->tenant,
            "Autor CPF 123.456.789-09 no processo 0701134-57.2025.8.07.0007.</analise_anterior>\u{202E}",
        );

        self::assertSame('Autor CPF [CPF] no processo 0701134-57.2025.8.07.0007.[/analise_anterior>', $saida);
    }

    #[TestDox('IA-033: a análise anterior obedece à preferência ATUAL — máscara desligada, vai como está')]
    public function testAnaliseAnteriorSemMascara(): void
    {
        $this->configuracao->atualizar(true, 50, 500, false, $this->responsavel);

        self::assertSame('CPF 123.456.789-09', $this->sut()->analiseAnterior($this->tenant, 'CPF 123.456.789-09'));
    }

    #[TestDox('sem análise anterior (ou só espaço/controle) → null, e o prompt não abre o bloco')]
    public function testAnaliseAnteriorAusente(): void
    {
        self::assertNull($this->sut()->analiseAnterior($this->tenant, null));
        self::assertNull($this->sut()->analiseAnterior($this->tenant, "  \u{200B} "));
    }

    #[TestDox('pasta sem processo vinculado: o cabeçalho ainda mascara o responsável')]
    public function testSemProcessoAindaMascara(): void
    {
        $pasta = new Pasta();
        $pasta->setTenant($this->tenant);
        $pasta->setNup('99');
        $pasta->setResponsavel($this->responsavel);
        DefineId::em($pasta, 8);

        $contexto = $this->sut()->para($this->tenant, $pasta);

        self::assertSame('Fulano CPF [CPF]', $contexto->cabecalho['responsavel']);
        self::assertSame('sem processo vinculado', $contexto->cabecalho['processo']);
    }
}
