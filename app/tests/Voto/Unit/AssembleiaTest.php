<?php

declare(strict_types=1);

namespace App\Tests\Voto\Unit;

use App\Entity\Tenant\Tenant;
use App\Voto\Entity\Assembleia;
use App\Voto\Entity\OpcaoDaPauta;
use App\Voto\Entity\PautaDaAssembleia;
use App\Voto\Entity\UnidadeDaAssembleia;
use App\Voto\Enum\EstadoDaAssembleia;
use App\Voto\Enum\PesoDoVoto;
use App\Voto\Exception\OperacaoInvalidaNaAssembleiaException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;

#[CoversClass(Assembleia::class)]
final class AssembleiaTest extends TestCase
{
    #[TestDox('Nasce em rascunho, com quem criou e quando; pautas e unidades podem mudar')]
    public function testNasceEmRascunho(): void
    {
        $assembleia = new Assembleia(new Tenant(), '  AGO 2026  ', PesoDoVoto::Fracao, 'Gestora');

        self::assertSame(EstadoDaAssembleia::Rascunho, $assembleia->getEstado());
        self::assertSame('AGO 2026', $assembleia->getTitulo());
        self::assertSame('Gestora', $assembleia->getCriadaPorNome());
        self::assertNull($assembleia->getPublicadaEm());
        self::assertTrue($assembleia->podeAlterarPautas());
        self::assertTrue($assembleia->podeAlterarUnidades());
    }

    #[TestDox('Título vazio é recusado')]
    public function testTituloVazio(): void
    {
        $this->expectException(OperacaoInvalidaNaAssembleiaException::class);

        new Assembleia(new Tenant(), '   ', PesoDoVoto::Unidade, 'Gestora');
    }

    #[TestDox('Publicar: de rascunho para publicada, grava a hora e trava as pautas (não as unidades)')]
    public function testPublicar(): void
    {
        $assembleia = $this->assembleiaPronta();
        $quando = new \DateTimeImmutable('2026-11-10 19:00');

        $assembleia->publicar($quando);

        self::assertSame(EstadoDaAssembleia::Publicada, $assembleia->getEstado());
        self::assertSame($quando, $assembleia->getPublicadaEm());
        self::assertFalse($assembleia->podeAlterarPautas());
        self::assertTrue($assembleia->podeAlterarUnidades());
    }

    #[TestDox('Publicar duas vezes lança exceção de domínio')]
    public function testPublicarDuasVezes(): void
    {
        $assembleia = $this->assembleiaPronta();
        $assembleia->publicar();

        $this->expectException(OperacaoInvalidaNaAssembleiaException::class);
        $this->expectExceptionMessage('A assembleia já foi publicada.');

        $assembleia->publicar();
    }

    #[TestDox('Publicar sem pauta é recusado')]
    public function testPublicarSemPauta(): void
    {
        $assembleia = new Assembleia(new Tenant(), 'AGO', PesoDoVoto::Unidade, 'Gestora');
        new UnidadeDaAssembleia($assembleia, '101', str_repeat('a', 32), str_repeat('b', 64));

        $this->expectException(OperacaoInvalidaNaAssembleiaException::class);
        $this->expectExceptionMessage('Inclua ao menos uma pauta.');

        $assembleia->publicar();
    }

    #[TestDox('Publicar com pauta de uma opção de voto só (a abstenção não conta) é recusado')]
    public function testPublicarComPautaDeUmaOpcao(): void
    {
        $assembleia = new Assembleia(new Tenant(), 'AGO', PesoDoVoto::Unidade, 'Gestora');
        $pauta = new PautaDaAssembleia($assembleia, 1, 'Contas');
        new OpcaoDaPauta($pauta, 1, 'Sim');
        new OpcaoDaPauta($pauta, 0, 'Abstenção', true);
        new UnidadeDaAssembleia($assembleia, '101', str_repeat('a', 32), str_repeat('b', 64));

        $this->expectException(OperacaoInvalidaNaAssembleiaException::class);
        $this->expectExceptionMessage('A pauta "Contas" precisa de ao menos duas opções.');

        $assembleia->publicar();
    }

    #[TestDox('Publicar sem unidade apta é recusado (inapta não conta)')]
    public function testPublicarSemUnidadeApta(): void
    {
        $assembleia = new Assembleia(new Tenant(), 'AGO', PesoDoVoto::Unidade, 'Gestora');
        $pauta = new PautaDaAssembleia($assembleia, 1, 'Contas');
        new OpcaoDaPauta($pauta, 1, 'Sim');
        new OpcaoDaPauta($pauta, 2, 'Não');
        new UnidadeDaAssembleia($assembleia, '101', str_repeat('a', 32), str_repeat('b', 64), situacao: 'inadimplente');

        $this->expectException(OperacaoInvalidaNaAssembleiaException::class);
        $this->expectExceptionMessage('Inclua ao menos uma unidade apta a votar.');

        $assembleia->publicar();
    }

    #[TestDox('Editar título e peso só em rascunho')]
    public function testEditarSoEmRascunho(): void
    {
        $assembleia = $this->assembleiaPronta();
        $assembleia->editar('AGE', PesoDoVoto::Fracao);
        self::assertSame('AGE', $assembleia->getTitulo());
        self::assertSame(PesoDoVoto::Fracao, $assembleia->getPeso());

        $assembleia->publicar();

        $this->expectException(OperacaoInvalidaNaAssembleiaException::class);
        $assembleia->editar('Outra', PesoDoVoto::Unidade);
    }

    #[TestDox('Trocar para fração ideal com unidade apta sem fração (ou com zero) é recusado, nomeando as unidades; inapta não conta')]
    public function testEditarParaFracaoComAptaSemFracao(): void
    {
        $assembleia = $this->assembleiaPronta();
        new UnidadeDaAssembleia($assembleia, '102', str_repeat('c', 32), str_repeat('d', 64), bloco: 'A');
        new UnidadeDaAssembleia($assembleia, '103', str_repeat('e', 32), str_repeat('f', 64), bloco: 'A', fracao: '0.000000');
        new UnidadeDaAssembleia($assembleia, '104', str_repeat('g', 32), str_repeat('h', 64), bloco: 'B', situacao: 'inadimplente');

        try {
            $assembleia->editar('AGE', PesoDoVoto::Fracao);
            self::fail('a troca para fração ideal devia ser recusada');
        } catch (OperacaoInvalidaNaAssembleiaException $e) {
            self::assertSame('Com peso por fração ideal, toda unidade apta precisa de fração. Falta em: A · 102, A · 103.', $e->getMessage());
        }
        self::assertSame('AGO', $assembleia->getTitulo(), 'nada muda na recusa');
        self::assertSame(PesoDoVoto::Unidade, $assembleia->getPeso());
    }

    #[TestDox('A recusa da fração nomeia no máximo 5 unidades e conta as demais')]
    public function testRecusaDaFracaoNomeiaAteCinco(): void
    {
        $assembleia = new Assembleia(new Tenant(), 'AGO', PesoDoVoto::Unidade, 'Gestora');
        foreach (range(1, 7) as $n) {
            new UnidadeDaAssembleia($assembleia, '20' . $n, str_repeat((string) $n, 32), str_repeat((string) $n, 64));
        }

        $this->expectException(OperacaoInvalidaNaAssembleiaException::class);
        $this->expectExceptionMessage('Com peso por fração ideal, toda unidade apta precisa de fração. Falta em: 201, 202, 203, 204, 205 e mais 2.');

        $assembleia->editar('AGO', PesoDoVoto::Fracao);
    }

    #[TestDox('Publicar por fração ideal com unidade apta sem fração é recusado e a assembleia segue em rascunho')]
    public function testPublicarPorFracaoComAptaSemFracao(): void
    {
        $assembleia = new Assembleia(new Tenant(), 'AGO', PesoDoVoto::Fracao, 'Gestora');
        $pauta = new PautaDaAssembleia($assembleia, 1, 'Contas');
        new OpcaoDaPauta($pauta, 1, 'Sim');
        new OpcaoDaPauta($pauta, 2, 'Não');
        new UnidadeDaAssembleia($assembleia, '101', str_repeat('a', 32), str_repeat('b', 64), fracao: '0.500000');
        new UnidadeDaAssembleia($assembleia, '102', str_repeat('c', 32), str_repeat('d', 64));

        try {
            $assembleia->publicar();
            self::fail('a publicação devia ser recusada');
        } catch (OperacaoInvalidaNaAssembleiaException $e) {
            self::assertSame('Com peso por fração ideal, toda unidade apta precisa de fração. Falta em: 102.', $e->getMessage());
        }
        self::assertSame(EstadoDaAssembleia::Rascunho, $assembleia->getEstado());
        self::assertNull($assembleia->getPublicadaEm());
    }

    #[TestDox('Publicar por fração ideal com a fração de toda apta passa (inapta sem fração não impede)')]
    public function testPublicarPorFracaoComTodasAsFracoes(): void
    {
        $assembleia = new Assembleia(new Tenant(), 'AGO', PesoDoVoto::Fracao, 'Gestora');
        $pauta = new PautaDaAssembleia($assembleia, 1, 'Contas');
        new OpcaoDaPauta($pauta, 1, 'Sim');
        new OpcaoDaPauta($pauta, 2, 'Não');
        new UnidadeDaAssembleia($assembleia, '101', str_repeat('a', 32), str_repeat('b', 64), fracao: '0.500000');
        new UnidadeDaAssembleia($assembleia, '102', str_repeat('c', 32), str_repeat('d', 64), situacao: 'inadimplente');

        $assembleia->publicar();

        self::assertSame(EstadoDaAssembleia::Publicada, $assembleia->getEstado());
    }

    #[TestDox('A lista de unidades bloqueia quando a primeira pauta é aberta')]
    public function testUnidadesBloqueiamNaAbertura(): void
    {
        $assembleia = $this->assembleiaPronta();
        $assembleia->publicar();
        $assembleia->exigirUnidadesAlteraveis();

        $assembleia->getPautas()->first()->abrir('Mesa', 1, null);

        self::assertFalse($assembleia->podeAlterarUnidades());
        $this->expectException(OperacaoInvalidaNaAssembleiaException::class);
        $this->expectExceptionMessage('A lista fica bloqueada depois da abertura da votação.');
        $assembleia->exigirUnidadesAlteraveis();
    }

    #[TestDox('Pautas só mudam em rascunho (exigirPautasAlteraveis)')]
    public function testPautasSoEmRascunho(): void
    {
        $assembleia = $this->assembleiaPronta();
        $assembleia->exigirPautasAlteraveis();
        $assembleia->publicar();

        $this->expectException(OperacaoInvalidaNaAssembleiaException::class);
        $assembleia->exigirPautasAlteraveis();
    }

    #[TestDox('Filhas criadas pelo construtor entram nas coleções da assembleia')]
    public function testColecoesAcompanhamOsFilhos(): void
    {
        $assembleia = $this->assembleiaPronta();

        self::assertCount(1, $assembleia->getPautas());
        self::assertCount(1, $assembleia->getUnidades());
        self::assertCount(3, $assembleia->getPautas()->first()->getOpcoes());
    }

    private function assembleiaPronta(): Assembleia
    {
        $assembleia = new Assembleia(new Tenant(), 'AGO', PesoDoVoto::Unidade, 'Gestora');
        $pauta = new PautaDaAssembleia($assembleia, 1, 'Contas');
        new OpcaoDaPauta($pauta, 1, 'Sim');
        new OpcaoDaPauta($pauta, 2, 'Não');
        new OpcaoDaPauta($pauta, 0, 'Abstenção', true);
        new UnidadeDaAssembleia($assembleia, '101', str_repeat('a', 32), str_repeat('b', 64), fracao: '0.500000');

        return $assembleia;
    }
}
