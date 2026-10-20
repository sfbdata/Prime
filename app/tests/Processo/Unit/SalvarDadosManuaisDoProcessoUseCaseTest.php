<?php

declare(strict_types=1);

namespace App\Tests\Processo\Unit;

use App\Entity\Audit\AuditLog;
use App\Entity\Auth\User;
use App\Entity\Tenant\Tenant;
use App\Pasta\Entity\Pasta;
use App\Processo\DTO\DadosDoProcessoInput;
use App\Processo\Entity\Processo;
use App\Processo\Exception\DadosDoProcessoInvalidosException;
use App\Processo\Integracao\IntegracaoComPjeNaoConfigurada;
use App\Processo\Repository\ProcessoRepository;
use App\Processo\Service\MontadorDosDadosDoProcesso;
use App\Processo\UseCase\SalvarDadosManuaisDoProcessoUseCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;

/** P5b: "Salvar" da edição no cartão (desenho `salvar`): só o diferente e preenchido vira manual. */
#[CoversClass(SalvarDadosManuaisDoProcessoUseCase::class)]
#[CoversClass(DadosDoProcessoInput::class)]
final class SalvarDadosManuaisDoProcessoUseCaseTest extends TestCase
{
    private ?AuditLog $registro = null;

    private function processo(): Processo
    {
        $p = new Processo();
        $p->setClasseProcessual('PROCEDIMENTO COMUM CÍVEL');
        $p->setOrgaoJulgador('1ª Vara Cível de Brasília');
        $p->setDataDistribuicao(new \DateTime('2025-03-14'));
        $p->setNivelSigilo(0);

        return $p;
    }

    /**
     * @param array<string, string>  $textos
     * @param array<string, ?bool>   $flags
     */
    private function salvar(Processo $p, array $textos, array $flags = [], ?Pasta $pasta = null, int $pastas = 1): int
    {
        $repo = $this->createMock(ProcessoRepository::class);
        $repo->method('salvarComAuditoria')->willReturnCallback(function (Processo $processo, AuditLog $registro): void {
            $this->registro = $registro;
        });
        $repo->method('contarPastasDoProcesso')->willReturn($pastas);
        $useCase = new SalvarDadosManuaisDoProcessoUseCase($repo, new MontadorDosDadosDoProcesso(new IntegracaoComPjeNaoConfigurada()));

        $usuario = new User();
        $usuario->setEmail('ana@teste.com');

        return $useCase->executar($p, $pasta ?? new Pasta(), new DadosDoProcessoInput($textos + [
            'classe' => '', 'assuntos' => '', 'jurisdicao' => '', 'orgao' => '', 'distribuicao' => '', 'valorCausa' => '',
        ], $flags), $usuario, new Tenant());
    }

    #[TestDox('igual ao base (sem diferença de caixa) e vazio não viram manual; o diferente vira; a trilha leva antes e depois')]
    public function testSoODiferenteViraManual(): void
    {
        $p = $this->processo();
        $pastas = $this->salvar($p, [
            'classe' => 'procedimento comum cível',
            'orgao' => '2ª Vara da Fazenda Pública do DF',
            'distribuicao' => '14/03/2025',
            'jurisdicao' => "  Brasília\u{200B} · Fórum  ",
            'assuntos' => "Dano moral\n\nDano moral\nLucros cessantes",
            'valorCausa' => 'R$ 1.234,56',
        ], ['gratuita' => true, 'segredo' => false, 'digital' => null], pastas: 3);

        $m = $p->getDadosManuais();
        self::assertNull($m->classe, 'igual ao Datajud volta ao base');
        self::assertNull($m->distribuicao);
        self::assertSame('2ª Vara da Fazenda Pública do DF', $m->orgao);
        self::assertSame('Brasília · Fórum', $m->jurisdicao);
        self::assertSame(['Dano moral', 'Lucros cessantes'], $m->assuntos);
        self::assertSame('1234.56', $m->valorCausa);
        self::assertTrue($m->gratuita);
        self::assertNull($m->segredo, '"Não" igual ao tribunal (público) não vira manual');
        self::assertNull($m->digital);
        self::assertNotNull($p->getDadosManuaisEm());
        self::assertSame(3, $pastas);

        self::assertNotNull($this->registro);
        self::assertSame('update', $this->registro->getAction());
        self::assertSame(Processo::class, $this->registro->getEntityClass());
        $mudancas = $this->registro->getChanges();
        self::assertSame('2ª Vara da Fazenda Pública do DF', $mudancas['dados_manuais']['to']['orgao'] ?? null);
        self::assertArrayHasKey('orgao', $mudancas['dados_manuais']['from']);
        self::assertNull($mudancas['dados_manuais']['from']['orgao']);
    }

    #[TestDox('valor da causa igual ao da pasta não vira manual')]
    public function testValorIgualAoDaPasta(): void
    {
        $pasta = new Pasta();
        $pasta->setValorCausa('1500.5');
        $p = $this->processo();

        $this->salvar($p, ['valorCausa' => 'R$ 1.500,50'], pasta: $pasta);

        self::assertNull($p->getDadosManuais()->valorCausa);
    }

    /** @return iterable<string, array{array<string, string>, array<string, ?bool>, string}> */
    public static function invalidos(): iterable
    {
        yield 'data' => [['distribuicao' => '31/02/2025'], [], 'Data inválida. Use dd/mm/aaaa.'];
        yield 'data em outro formato' => [['distribuicao' => '2025-03-14'], [], 'Data inválida. Use dd/mm/aaaa.'];
        yield 'valor' => [['valorCausa' => 'mil reais'], [], 'Valor da causa inválido. Use R$ 0,00.'];
        yield 'valor grande demais' => [['valorCausa' => '99999999999999'], [], 'Valor da causa inválido. Use R$ 0,00.'];
        yield 'texto longo' => [['orgao' => str_repeat('a', 256)], [], 'Texto longo demais.'];
    }

    /**
     * @param array<string, string> $textos
     * @param array<string, ?bool>  $flags
     */
    #[DataProvider('invalidos')]
    #[TestDox('campo inválido: recusa com a mensagem e não grava nada')]
    public function testInvalidos(array $textos, array $flags, string $mensagem): void
    {
        $p = $this->processo();
        try {
            $this->salvar($p, $textos, $flags);
            self::fail('devia recusar');
        } catch (DadosDoProcessoInvalidosException $e) {
            self::assertSame($mensagem, $e->getMessage());
        }
        self::assertNull($p->getDadosManuaisEm());
        self::assertNull($this->registro);
    }

    #[TestDox('"Não" manual no segredo quando o tribunal informou sigilo: recusa (não desliga o bloqueio)')]
    public function testNaoManualContraSigiloDoTribunal(): void
    {
        $p = $this->processo();
        $p->setNivelSigilo(5);

        $this->expectException(DadosDoProcessoInvalidosException::class);
        $this->expectExceptionMessage('O tribunal informou segredo de justiça neste processo. Mantenha "Sim" ou "Não informado".');
        $this->salvar($p, [], ['segredo' => false]);
    }

    #[TestDox('o formulário: "1" = Sim, "0" = Não, o resto = Não informado; texto que não é string vira vazio')]
    public function testInputDaRequisicao(): void
    {
        $request = new \Symfony\Component\HttpFoundation\Request([], [
            'orgao' => 'X', 'classe' => ['a'], 'segredo' => '1', 'digital' => '0', 'gratuita' => '', 'tutela' => 'talvez',
        ]);
        $input = DadosDoProcessoInput::fromRequest($request);

        self::assertSame('X', $input->textos['orgao']);
        self::assertSame('', $input->textos['classe']);
        self::assertSame(['segredo' => true, 'digital' => false, 'gratuita' => null, 'tutela' => null, 'prioridade' => null], $input->flags);
    }
}
