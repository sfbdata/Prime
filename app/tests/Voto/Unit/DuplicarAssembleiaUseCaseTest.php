<?php

declare(strict_types=1);

namespace App\Tests\Voto\Unit;

use App\Entity\Tenant\Tenant;
use App\Shared\Armazenamento\FonteDeConteudo;
use App\Voto\Armazenamento\ChavesDoVoto;
use App\Voto\DTO\ResultadoDaDuplicacao;
use App\Voto\Entity\Assembleia;
use App\Voto\Entity\OpcaoDaPauta;
use App\Voto\Entity\PautaDaAssembleia;
use App\Voto\Entity\UnidadeDaAssembleia;
use App\Voto\Enum\BaseDaDecisao;
use App\Voto\Enum\CenarioDaAssembleia;
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
    use ComFotosDasCandidaturas;

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
        self::assertSame('Residencial Jardim', $salva->getEntidadeNome(), 'a cópia fica na mesma entidade');
        self::assertSame('11222333000181', $salva->getCnpj());
        self::assertSame(CenarioDaAssembleia::Cooperativa, $salva->getCenario(), 'a cópia mantém o cenário');
        self::assertSame('Nova gestora', $salva->getCriadaPorNome());
        self::assertSame(EstadoDaAssembleia::Rascunho, $salva->getEstado());
        self::assertNull($salva->getPublicadaEm());
        self::assertNotSame($origem->getCodigoPublico(), $salva->getCodigoPublico());
        self::assertSame(2, $resultado->unidadesCopiadas);
        self::assertTrue($resultado->emailsDeixados, 'a origem tinha e-mail: o aviso pede para reimportar');

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
            self::assertNull($copia->getEmail(), 'a cópia sai sem os e-mails, como o desenho');
            self::assertSame($originais[$i]->getTelefone(), $copia->getTelefone(), 'o telefone é copiado, como o desenho (tel)');
            self::assertSame($originais[$i]->isApta(), $copia->isApta());
            self::assertNotSame($originais[$i]->getSemente(), $copia->getSemente(), 'o link do original não vale na cópia');
            self::assertNotSame($originais[$i]->getTokenHash(), $copia->getTokenHash());
            self::assertNull($copia->getLinkEnviadoEm());
        }
        self::assertSame('61999990001', $copias[0]->getTelefone());
        self::assertNull($copias[1]->getTelefone());
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
        (new DuplicarAssembleiaUseCase($repo, new TokenDaUnidade('segredo'), self::serializacao(), $this->fotosDasCandidaturas()))->executar(new Tenant(), 99, 'Gestora');
    }

    private function executar(Tenant $tenant, Assembleia $origem, ?Assembleia &$salva): ResultadoDaDuplicacao
    {
        $repo = $this->createMock(AssembleiaRepository::class);
        $repo->method('buscarDoEscritorio')->with(7, $tenant)->willReturn($origem);
        $repo->expects(self::once())->method('salvar')->willReturnCallback(static function (Assembleia $a, bool $flush) use (&$salva): void {
            self::assertTrue($flush);
            $salva = $a;
        });

        return (new DuplicarAssembleiaUseCase($repo, new TokenDaUnidade('segredo'), self::serializacao(), $this->fotosDasCandidaturas()))->executar($tenant, 7, 'Nova gestora');
    }

    private function publicadaComVotos(Tenant $tenant): Assembleia
    {
        $origem = new Assembleia($tenant, 'AGO 2026', PesoDoVoto::Fracao, 'Gestora antiga', CenarioDaAssembleia::Cooperativa);
        $origem->identificarEntidade('Residencial Jardim', '11222333000181');
        $pauta = new PautaDaAssembleia($origem, 2, 'Síndico(a)', 'Mandato de 2 anos', TipoDaPauta::EleicaoDeCargo);
        $pauta->configurar(RegraDeDecisao::Mais, BaseDaDecisao::Validos, 2, 2, 60, null);
        new OpcaoDaPauta($pauta, 11, 'Ana');
        new OpcaoDaPauta($pauta, 12, 'Beto');
        new OpcaoDaPauta($pauta, 0, 'Abstenção', true);
        $u1 = new UnidadeDaAssembleia($origem, '101', str_repeat('a', 32), str_repeat('b', 64), 'A', 'adimplente', '0.050000', 'Maria', 'maria@exemplo.com', '61999990001');
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

    #[TestDox('Duplicar a assembleia leva a foto das candidaturas: arquivo NOVO de chave própria, origem intacta')]
    public function testCopiaAFotoDasCandidaturas(): void
    {
        $tenant = new Tenant();
        (new \ReflectionProperty(Tenant::class, 'id'))->setValue($tenant, 5);
        $origem = new Assembleia($tenant, 'AGO 2026', PesoDoVoto::Unidade, 'Gestora');
        $pauta = new PautaDaAssembleia($origem, 1, 'Síndico(a)', null, TipoDaPauta::EleicaoDeCargo);
        $ana = new OpcaoDaPauta($pauta, 1, 'Ana');
        $bia = new OpcaoDaPauta($pauta, 2, 'Bia');
        $this->fotosDasCandidaturas();
        $ana->definirFoto('0123456789abcdef0123456789abcdef.jpg');
        $this->armazenamento->gravar(ChavesDoVoto::fotoDaCandidatura($ana), FonteDeConteudo::deTexto('JPEG-DA-ANA'));
        $bia->definirFoto('fedcba9876543210fedcba9876543210.png');

        $salva = null;
        $this->executar($tenant, $origem, $salva);

        self::assertInstanceOf(Assembleia::class, $salva);
        $copias = $salva->getPautas()->first()->getOpcoesDeVoto();
        $novaAna = $copias[0];
        self::assertNotNull($novaAna->getFoto());
        self::assertNotSame($ana->getFoto(), $novaAna->getFoto());
        self::assertStringEndsWith('.jpg', (string) $novaAna->getFoto());
        self::assertSame('JPEG-DA-ANA', $this->armazenamento->ler(ChavesDoVoto::fotoDaCandidatura($novaAna)));
        self::assertSame('JPEG-DA-ANA', $this->armazenamento->ler(ChavesDoVoto::fotoDaCandidatura($ana)), 'a origem segue intacta');
        self::assertNull($copias[1]->getFoto(), 'foto da origem ausente no storage: sem foto, sem erro');
        self::assertSame('0123456789abcdef0123456789abcdef.jpg', $ana->getFoto());
        self::assertSame(['commit'], $this->ordem);
    }
}
