<?php

declare(strict_types=1);

namespace App\Tests\Voto\Unit;

use App\Entity\Tenant\Tenant;
use App\Voto\DTO\ResultadoDaDuplicacao;
use App\Voto\Entity\Assembleia;
use App\Voto\Entity\OpcaoDaPauta;
use App\Voto\Entity\PautaDaAssembleia;
use App\Voto\Entity\UnidadeDaAssembleia;
use App\Voto\Enum\BaseDaDecisao;
use App\Voto\Enum\EstadoDaAssembleia;
use App\Voto\Enum\EstadoDaPauta;
use App\Voto\Enum\PesoDoVoto;
use App\Voto\Enum\RegraDeDecisao;
use App\Voto\Enum\TipoDaPauta;
use App\Voto\Exception\AssembleiaNaoEncontradaException;
use App\Voto\Repository\AssembleiaRepository;
use App\Voto\Service\SerializacaoDaConfiguracao;
use App\Voto\Service\TokenDaUnidade;
use App\Voto\UseCase\DuplicarAssembleiaUseCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Lock\LockFactory;
use Symfony\Component\Lock\Store\InMemoryStore;

/** Duplicar: só o cadastro vai para o novo rascunho; nada de acesso, voto ou andamento; outro escritório não é achado. */
#[CoversClass(DuplicarAssembleiaUseCase::class)]
final class DuplicarAssembleiaUseCaseTest extends TestCase
{
    #[TestDox('Copia título com sufixo, peso, pautas com regras e opções (com abstenção) e as unidades, como rascunho')]
    public function testCopiaOCadastro(): void
    {
        $tenant = new Tenant();
        $origem = $this->publicadaComVotos($tenant);
        $salva = null;

        $resultado = $this->executar($tenant, $origem, $salva);

        self::assertInstanceOf(Assembleia::class, $salva);
        self::assertNotSame($origem, $salva);
        self::assertSame($tenant, $salva->getTenant());
        self::assertSame('AGO 2026 (cópia)', $salva->getTitulo());
        self::assertSame(PesoDoVoto::Fracao, $salva->getPeso());
        self::assertSame('Nova gestora', $salva->getCriadaPorNome());
        self::assertSame(EstadoDaAssembleia::Rascunho, $salva->getEstado());
        self::assertNull($salva->getPublicadaEm());
        self::assertNotSame($origem->getCodigoPublico(), $salva->getCodigoPublico());
        self::assertSame(2, $resultado->unidadesCopiadas);

        $pautas = $salva->getPautas()->toArray();
        self::assertCount(1, $pautas);
        $pauta = $pautas[0];
        self::assertSame('Síndico(a)', $pauta->getTitulo());
        self::assertSame('Mandato de 2 anos', $pauta->getDescricao());
        self::assertSame(TipoDaPauta::EleicaoDeCargo, $pauta->getTipo());
        self::assertSame(2, $pauta->getOrdem());
        self::assertSame(RegraDeDecisao::Mais, $pauta->getCriterio());
        self::assertSame(BaseDaDecisao::Validos, $pauta->getBase());
        self::assertSame(2, $pauta->getDigitos());
        self::assertSame(2, $pauta->getVagas());
        self::assertSame('60.00', $pauta->getQuorumPrimeira());
        self::assertNull($pauta->getQuorumSegunda());
        self::assertSame(
            [[11, 'Ana', false], [12, 'Beto', false], [0, 'Abstenção', true]],
            array_map(static fn (OpcaoDaPauta $o): array => [$o->getNumero(), $o->getRotulo(), $o->isAbstencao()], array_values($pauta->getOpcoes()->toArray())),
        );
    }

    #[TestDox('Não copia andamento, prazo, contadores de voto nem nada de acesso das unidades')]
    public function testNaoCopiaAcessoNemVotos(): void
    {
        $tenant = new Tenant();
        $origem = $this->publicadaComVotos($tenant);
        $salva = null;

        $this->executar($tenant, $origem, $salva);

        self::assertInstanceOf(Assembleia::class, $salva);
        $pauta = $salva->getPautas()->first();
        self::assertInstanceOf(PautaDaAssembleia::class, $pauta);
        self::assertSame(EstadoDaPauta::Aguardando, $pauta->getEstado());
        self::assertNull($pauta->getConvocacao());
        self::assertNull($pauta->getQuorumExigido());
        self::assertNull($pauta->getAbertaEm());
        self::assertNull($pauta->getPrazoAte());
        self::assertNull($pauta->getEncerradaEm());
        self::assertSame(0, $pauta->getNulosUnidades());
        foreach ($pauta->getOpcoes() as $opcao) {
            self::assertSame(0, $opcao->getTotalUnidades());
            self::assertSame('0.000000', $opcao->getTotalPeso());
        }

        $originais = array_values($origem->getUnidades()->toArray());
        $copias = array_values($salva->getUnidades()->toArray());
        self::assertCount(2, $copias);
        foreach ($copias as $i => $copia) {
            self::assertSame($originais[$i]->getIdentificacao(), $copia->getIdentificacao());
            self::assertSame($originais[$i]->getBloco(), $copia->getBloco());
            self::assertSame($originais[$i]->getSituacao(), $copia->getSituacao());
            self::assertSame($originais[$i]->getFracao(), $copia->getFracao());
            self::assertSame($originais[$i]->getResponsavel(), $copia->getResponsavel());
            self::assertSame($originais[$i]->getEmail(), $copia->getEmail());
            self::assertSame($originais[$i]->isApta(), $copia->isApta());
            self::assertNotSame($originais[$i]->getSemente(), $copia->getSemente(), 'o link do original não vale na cópia');
            self::assertNotSame($originais[$i]->getTokenHash(), $copia->getTokenHash());
            self::assertNull($copia->getLinkEnviadoEm());
        }
        self::assertNotNull($originais[0]->getLinkEnviadoEm(), 'o original segue intacto');
    }

    #[TestDox('Título longo mantém o sufixo dentro dos 160 caracteres')]
    public function testTituloLongoMantemOSufixo(): void
    {
        $tenant = new Tenant();
        $origem = new Assembleia($tenant, str_repeat('a', 160), PesoDoVoto::Unidade, 'Gestora');
        $salva = null;

        $this->executar($tenant, $origem, $salva);

        self::assertInstanceOf(Assembleia::class, $salva);
        self::assertSame(160, mb_strlen($salva->getTitulo()));
        self::assertStringEndsWith(' (cópia)', $salva->getTitulo());
    }

    #[TestDox('Sem unidades no original, a cópia sai sem lista (o aviso pede a importação)')]
    public function testSemUnidades(): void
    {
        $tenant = new Tenant();
        $origem = new Assembleia($tenant, 'AGO', PesoDoVoto::Unidade, 'Gestora');
        $salva = null;

        $resultado = $this->executar($tenant, $origem, $salva);

        self::assertSame(0, $resultado->unidadesCopiadas);
    }

    #[TestDox('Assembleia de outro escritório ou inexistente: não duplica nada')]
    public function testOutroEscritorio(): void
    {
        $repo = $this->createMock(AssembleiaRepository::class);
        $repo->method('buscarDoEscritorio')->willReturn(null);
        $repo->expects(self::never())->method('salvar');

        $this->expectException(AssembleiaNaoEncontradaException::class);
        (new DuplicarAssembleiaUseCase($repo, new TokenDaUnidade('segredo'), self::serializacao()))->executar(new Tenant(), 99, 'Gestora');
    }

    private function executar(Tenant $tenant, Assembleia $origem, ?Assembleia &$salva): ResultadoDaDuplicacao
    {
        $repo = $this->createMock(AssembleiaRepository::class);
        $repo->method('buscarDoEscritorio')->with(7, $tenant)->willReturn($origem);
        $repo->expects(self::once())->method('salvar')->willReturnCallback(static function (Assembleia $a, bool $flush) use (&$salva): void {
            self::assertTrue($flush);
            $salva = $a;
        });

        return (new DuplicarAssembleiaUseCase($repo, new TokenDaUnidade('segredo'), self::serializacao()))->executar($tenant, 7, 'Nova gestora');
    }

    private function publicadaComVotos(Tenant $tenant): Assembleia
    {
        $origem = new Assembleia($tenant, 'AGO 2026', PesoDoVoto::Fracao, 'Gestora antiga');
        $pauta = new PautaDaAssembleia($origem, 2, 'Síndico(a)', 'Mandato de 2 anos', TipoDaPauta::EleicaoDeCargo);
        $pauta->configurar(RegraDeDecisao::Mais, BaseDaDecisao::Validos, 2, 2, 60, null);
        new OpcaoDaPauta($pauta, 11, 'Ana');
        new OpcaoDaPauta($pauta, 12, 'Beto');
        new OpcaoDaPauta($pauta, 0, 'Abstenção', true);
        $u1 = new UnidadeDaAssembleia($origem, '101', str_repeat('a', 32), str_repeat('b', 64), 'A', 'adimplente', '0.050000', 'Maria', 'maria@exemplo.com');
        new UnidadeDaAssembleia($origem, '102', str_repeat('c', 32), str_repeat('d', 64), 'A', 'inadimplente', '0.050000', null, null);
        $u1->registrarEnvioDoLink(new \DateTimeImmutable('-1 day'));
        $origem->publicar();
        $pauta->abrir('Mesa', 1, new \DateTimeImmutable('+1 day'));

        return $origem;
    }

    private static function serializacao(): SerializacaoDaConfiguracao
    {
        return new SerializacaoDaConfiguracao(new LockFactory(new InMemoryStore()));
    }
}
