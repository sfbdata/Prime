<?php

declare(strict_types=1);

namespace App\Tests\Voto\Unit;

use App\Entity\Tenant\Tenant;
use App\Shared\Armazenamento\FonteDeConteudo;
use App\Voto\Armazenamento\ChavesDoVoto;
use App\Voto\Entity\Assembleia;
use App\Voto\Entity\OpcaoDaPauta;
use App\Voto\Entity\PautaDaAssembleia;
use App\Voto\Entity\UnidadeDaAssembleia;
use App\Voto\Enum\PesoDoVoto;
use App\Voto\Enum\TipoDaPauta;
use App\Voto\Exception\AssembleiaNaoEncontradaException;
use App\Voto\Exception\OperacaoInvalidaNaAssembleiaException;
use App\Voto\Repository\AssembleiaRepository;
use App\Voto\Repository\PautaDaAssembleiaRepository;
use App\Voto\Service\SerializacaoDaConfiguracao;
use App\Voto\UseCase\DuplicarPautaUseCase;
use App\Voto\UseCase\MoverPautaUseCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Lock\LockFactory;
use Symfony\Component\Lock\Store\InMemoryStore;

/** "Mover para cima/baixo" e "Duplicar" do menu da pauta: só em rascunho, na assembleia do escritório, renumerando a lista. */
#[CoversClass(MoverPautaUseCase::class)]
#[CoversClass(DuplicarPautaUseCase::class)]
final class MoverEDuplicarPautaUseCaseTest extends TestCase
{
    use ComFotosDasCandidaturas;

    /** @return array{Assembleia, PautaDaAssembleia, PautaDaAssembleia, PautaDaAssembleia} */
    private static function assembleiaComTresPautas(Tenant $tenant): array
    {
        $assembleia = new Assembleia($tenant, 'AGO 2026', PesoDoVoto::Fracao, 'Gestora');
        $a = new PautaDaAssembleia($assembleia, 1, 'Síndico(a)', null, TipoDaPauta::EleicaoDeCargo);
        (new OpcaoDaPauta($a, 1, 'Ana'))->detalhar('Moradora do 101', 'Mais jardim', "Presidente: Ana\nVice: Bia", '101');
        new OpcaoDaPauta($a, 0, 'Abstenção', true);
        $b = new PautaDaAssembleia($assembleia, 2, 'Contas');
        $c = new PautaDaAssembleia($assembleia, 3, 'Obra');

        return [$assembleia, $a, $b, $c];
    }

    /** @return list<string> os títulos pela ordem gravada */
    private static function titulos(Assembleia $assembleia): array
    {
        $pautas = $assembleia->getPautas()->toArray();
        usort($pautas, static fn (PautaDaAssembleia $x, PautaDaAssembleia $y): int => $x->getOrdem() <=> $y->getOrdem());

        return array_map(static fn (PautaDaAssembleia $p): string => $p->getTitulo() . '#' . $p->getOrdem(), $pautas);
    }

    private static function mover(Tenant $tenant, ?Assembleia $assembleia, ?PautaDaAssembleia $achada): MoverPautaUseCase
    {
        return new MoverPautaUseCase(self::assembleias($tenant, $assembleia), self::repositorio($achada), self::serializacao());
    }

    private static function assembleias(Tenant $tenant, ?Assembleia $assembleia): AssembleiaRepository
    {
        $repo = (new self('x'))->createMock(AssembleiaRepository::class);
        $repo->method('buscarDoEscritorio')->with(7, $tenant)->willReturn($assembleia);

        return $repo;
    }

    private static function repositorio(?PautaDaAssembleia $achada): PautaDaAssembleiaRepository
    {
        $repo = (new self('x'))->createMock(PautaDaAssembleiaRepository::class);
        $repo->method('buscarDaAssembleia')->willReturn($achada);

        return $repo;
    }

    private static function serializacao(): SerializacaoDaConfiguracao
    {
        return new SerializacaoDaConfiguracao(new LockFactory(new InMemoryStore()));
    }

    #[TestDox('Mover para baixo troca com a vizinha e Mover para cima desfaz, sempre renumerando de 1 em diante')]
    public function testMoveParaBaixoEParaCima(): void
    {
        $tenant = new Tenant();
        [$assembleia, $a, $b] = self::assembleiaComTresPautas($tenant);

        self::mover($tenant, $assembleia, $a)->executar($tenant, 7, 1, MoverPautaUseCase::PARA_BAIXO);
        self::assertSame(['Contas#1', 'Síndico(a)#2', 'Obra#3'], self::titulos($assembleia));

        self::mover($tenant, $assembleia, $a)->executar($tenant, 7, 1, MoverPautaUseCase::PARA_CIMA);
        self::assertSame(['Síndico(a)#1', 'Contas#2', 'Obra#3'], self::titulos($assembleia));
        self::assertSame(2, $b->getOrdem());
    }

    #[TestDox('Na ponta da lista não há o que mover: nada muda e não dá erro')]
    public function testNaPontaNaoMuda(): void
    {
        $tenant = new Tenant();
        [$assembleia, $a, , $c] = self::assembleiaComTresPautas($tenant);

        self::mover($tenant, $assembleia, $a)->executar($tenant, 7, 1, MoverPautaUseCase::PARA_CIMA);
        self::mover($tenant, $assembleia, $c)->executar($tenant, 7, 3, MoverPautaUseCase::PARA_BAIXO);

        self::assertSame(['Síndico(a)#1', 'Contas#2', 'Obra#3'], self::titulos($assembleia));
    }

    #[TestDox('Publicada: não move nem duplica')]
    public function testPublicadaRecusa(): void
    {
        $tenant = new Tenant();
        $assembleia = new Assembleia($tenant, 'AGO 2026', PesoDoVoto::Unidade, 'Gestora');
        $a = new PautaDaAssembleia($assembleia, 1, 'Contas');
        new OpcaoDaPauta($a, 1, 'Sim');
        new OpcaoDaPauta($a, 2, 'Não');
        new OpcaoDaPauta($a, 0, 'Abstenção', true);
        new UnidadeDaAssembleia($assembleia, '101', 'semente', 'hash');
        $assembleia->publicar();

        try {
            self::mover($tenant, $assembleia, $a)->executar($tenant, 7, 1, MoverPautaUseCase::PARA_BAIXO);
            self::fail('Deveria recusar');
        } catch (OperacaoInvalidaNaAssembleiaException) {
            self::assertSame(['Contas#1'], self::titulos($assembleia));
        }
        $this->expectException(OperacaoInvalidaNaAssembleiaException::class);
        (new DuplicarPautaUseCase(self::assembleias($tenant, $assembleia), self::repositorio($a), self::serializacao(), $this->fotosDasCandidaturas()))->executar($tenant, 7, 1);
    }

    #[TestDox('Assembleia de outro escritório ou pauta que não é dela: não achada, nada muda')]
    public function testOutroEscritorioNaoAchada(): void
    {
        $tenant = new Tenant();
        [$assembleia, $a] = self::assembleiaComTresPautas($tenant);

        foreach ([[null, $a], [$assembleia, null]] as [$achadaA, $achadaP]) {
            try {
                self::mover($tenant, $achadaA, $achadaP)->executar($tenant, 7, 1, MoverPautaUseCase::PARA_BAIXO);
                self::fail('Deveria recusar o mover');
            } catch (AssembleiaNaoEncontradaException) {
            }
            try {
                (new DuplicarPautaUseCase(self::assembleias($tenant, $achadaA), self::repositorio($achadaP), self::serializacao(), $this->fotosDasCandidaturas()))->executar($tenant, 7, 1);
                self::fail('Deveria recusar o duplicar');
            } catch (AssembleiaNaoEncontradaException) {
            }
        }
        self::assertSame(['Síndico(a)#1', 'Contas#2', 'Obra#3'], self::titulos($assembleia));
        self::assertCount(3, $assembleia->getPautas());
    }

    #[TestDox('Duplicar põe a cópia logo abaixo da original, com regras, opções e dados da candidatura, e renumera')]
    public function testDuplicaLogoAbaixo(): void
    {
        $tenant = new Tenant();
        [$assembleia, $a] = self::assembleiaComTresPautas($tenant);
        $salva = null;
        $pautas = self::repositorio($a);
        $pautas->expects(self::once())->method('salvar')->willReturnCallback(static function (PautaDaAssembleia $p, bool $flush) use (&$salva): void {
            self::assertTrue($flush);
            $salva = $p;
        });

        (new DuplicarPautaUseCase(self::assembleias($tenant, $assembleia), $pautas, self::serializacao(), $this->fotosDasCandidaturas()))->executar($tenant, 7, 1);

        self::assertSame(['Síndico(a)#1', 'Síndico(a)#2', 'Contas#3', 'Obra#4'], self::titulos($assembleia));
        self::assertInstanceOf(PautaDaAssembleia::class, $salva);
        self::assertNotSame($a, $salva);
        self::assertSame(2, $salva->getOrdem());
        self::assertSame(TipoDaPauta::EleicaoDeCargo, $salva->getTipo());
        $opcoes = $salva->getOpcoesDeVoto();
        self::assertCount(1, $opcoes);
        self::assertSame(['Ana', 'Moradora do 101', 'Mais jardim', "Presidente: Ana\nVice: Bia", '101'], [
            $opcoes[0]->getRotulo(), $opcoes[0]->getDescricao(), $opcoes[0]->getPropostas(), $opcoes[0]->getComposicao(), $opcoes[0]->getUnidadeCandidato(),
        ]);
        self::assertNotNull($salva->getAbstencao(), 'a abstenção vai junto');
        self::assertSame(0, $opcoes[0]->getTotalUnidades());
    }

    #[TestDox('Duplicar leva a foto: arquivo NOVO de chave própria, origem intacta; origem sem arquivo no storage: cópia sem foto')]
    public function testDuplicaComFoto(): void
    {
        $tenant = self::tenantComId();
        [$assembleia, $a] = self::assembleiaComTresPautas($tenant);
        $this->fotosDasCandidaturas();
        $ana = $a->getOpcoesDeVoto()[0];
        $ana->definirFoto('0123456789abcdef0123456789abcdef.png');
        $this->armazenamento->gravar(ChavesDoVoto::fotoDaCandidatura($ana), FonteDeConteudo::deTexto('PNG-DA-ANA'));
        $salva = null;
        $pautas = self::repositorio($a);
        $pautas->method('salvar')->willReturnCallback(static function (PautaDaAssembleia $p) use (&$salva): void {
            $salva = $p;
        });

        (new DuplicarPautaUseCase(self::assembleias($tenant, $assembleia), $pautas, self::serializacao(), $this->fotosDasCandidaturas()))->executar($tenant, 7, 1);

        self::assertInstanceOf(PautaDaAssembleia::class, $salva);
        $foto = $salva->getOpcoesDeVoto()[0]->getFoto();
        self::assertNotNull($foto);
        self::assertNotSame('0123456789abcdef0123456789abcdef.png', $foto, 'chave própria, nunca a da origem');
        self::assertStringEndsWith('.png', $foto);
        self::assertSame('PNG-DA-ANA', $this->armazenamento->ler(ChavesDoVoto::fotoDaCandidatura($salva->getOpcoesDeVoto()[0])));
        self::assertSame('PNG-DA-ANA', $this->armazenamento->ler(ChavesDoVoto::fotoDaCandidatura($ana)), 'a origem segue intacta');
        self::assertSame('0123456789abcdef0123456789abcdef.png', $ana->getFoto());
        self::assertSame([], $this->armazenamento->excluidas);
        self::assertSame(['commit'], $this->ordem);
    }

    #[TestDox('Duplicar com a foto da origem ausente no storage: a cópia sai sem foto e sem erro')]
    public function testDuplicaComFotoAusente(): void
    {
        $tenant = self::tenantComId();
        [$assembleia, $a] = self::assembleiaComTresPautas($tenant);
        $this->fotosDasCandidaturas();
        $a->getOpcoesDeVoto()[0]->definirFoto('0123456789abcdef0123456789abcdef.jpg');
        $salva = null;
        $pautas = self::repositorio($a);
        $pautas->method('salvar')->willReturnCallback(static function (PautaDaAssembleia $p) use (&$salva): void {
            $salva = $p;
        });

        (new DuplicarPautaUseCase(self::assembleias($tenant, $assembleia), $pautas, self::serializacao(), $this->fotosDasCandidaturas()))->executar($tenant, 7, 1);

        self::assertInstanceOf(PautaDaAssembleia::class, $salva);
        self::assertNull($salva->getOpcoesDeVoto()[0]->getFoto());
    }

    private static function tenantComId(): Tenant
    {
        $tenant = new Tenant();
        (new \ReflectionProperty(Tenant::class, 'id'))->setValue($tenant, 5);

        return $tenant;
    }
}
