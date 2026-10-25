<?php

declare(strict_types=1);

namespace App\Tests\Inteligencia\Unit;

use App\Entity\Auth\User;
use App\Inteligencia\DTO\MudancaDeStatusDaQuestaoOutput;
use App\Inteligencia\DTO\VersaoDeDocumentoJuridicoOutput;
use App\Inteligencia\Entity\EstadoDaQuestaoJuridica;
use App\Inteligencia\Entity\VersaoDeDocumentoJuridico;
use App\Inteligencia\Enum\DocumentoJuridico;
use App\Inteligencia\Enum\StatusDaQuestaoJuridica;
use App\Inteligencia\Repository\EstadoDaQuestaoJuridicaRepository;
use App\Inteligencia\Repository\MudancaDeStatusDaQuestaoJuridicaRepository;
use App\Inteligencia\Repository\VersaoDeDocumentoJuridicoRepository;
use App\Inteligencia\UseCase\ContarQuestoesJuridicasEmAbertoUseCase;
use App\Inteligencia\UseCase\ListarQuestoesJuridicasUseCase;
use App\Tests\Inteligencia\Support\CatalogoJuridicoDeTeste;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;

#[CoversClass(ListarQuestoesJuridicasUseCase::class)]
#[CoversClass(ContarQuestoesJuridicasEmAbertoUseCase::class)]
#[CoversClass(MudancaDeStatusDaQuestaoOutput::class)]
#[CoversClass(VersaoDeDocumentoJuridicoOutput::class)]
final class ListarQuestoesJuridicasUseCaseTest extends TestCase
{
    #[TestDox('sem nada no banco: as questões do catálogo, na ordem, todas pendentes, sem histórico nem versões')]
    public function testSemNadaNoBanco(): void
    {
        $saida = $this->sut([], [], [])->executar();

        self::assertSame(['primeira', 'segunda'], array_map(static fn ($q) => $q->questao->chave, $saida));
        self::assertSame([StatusDaQuestaoJuridica::Pendente, StatusDaQuestaoJuridica::Pendente], array_map(static fn ($q) => $q->status, $saida));
        self::assertSame([], $saida[0]->historico);
        self::assertSame([DocumentoJuridico::PoliticaDePrivacidade, DocumentoJuridico::AceiteDaIa], $saida[0]->questao->documentosJuridicos());
    }

    #[TestDox('documentos: os 4 do enum, uma vez cada, mesmo com a Política em várias questões e mesmo sem questão que os peça')]
    public function testDocumentosUmaVezCada(): void
    {
        $saida = $this->sut([], [], [])->documentos();

        self::assertSame(DocumentoJuridico::cases(), array_map(static fn ($d) => $d->documento, $saida));
        self::assertCount(4, $saida);
        self::assertSame([[], [], [], []], array_map(static fn ($d) => $d->versoes, $saida));
    }

    #[TestDox('com estado, histórico e versões: o status gravado, o histórico da chave e as versões do documento com o título da questão')]
    public function testComDados(): void
    {
        $master = (new User())->setFullName('Dra. Master')->setRoles(['ROLE_SUPER_ADMIN']);
        $estado = new EstadoDaQuestaoJuridica('primeira', new \DateTimeImmutable('2026-10-24 09:00'));
        $mudanca = $estado->mudarPara(StatusDaQuestaoJuridica::EmAnalise, $master, new \DateTimeImmutable('2026-10-24 10:00'));
        self::assertNotNull($mudanca);
        $versao = new VersaoDeDocumentoJuridico(DocumentoJuridico::PoliticaDePrivacidade, 'primeira', 'politica-v2.docx', 'abc.docx', 'application/pdf', 10, $master, new \DateTimeImmutable('2026-10-24 11:00'));
        $solta = new VersaoDeDocumentoJuridico(DocumentoJuridico::PoliticaDePrivacidade, null, 'solta.pdf', 'def.pdf', 'application/pdf', 5, $master, new \DateTimeImmutable('2026-10-24 12:00'));

        $saida = $this->sut(['primeira' => $estado], ['primeira' => [$mudanca]], ['politica_privacidade' => [$solta, $versao]])->executar();

        self::assertSame(StatusDaQuestaoJuridica::EmAnalise, $saida[0]->status);
        self::assertSame(StatusDaQuestaoJuridica::Pendente, $saida[1]->status);
        self::assertCount(1, $saida[0]->historico);
        self::assertSame([StatusDaQuestaoJuridica::Pendente, StatusDaQuestaoJuridica::EmAnalise, 'Dra. Master'], [$saida[0]->historico[0]->de, $saida[0]->historico[0]->para, $saida[0]->historico[0]->por]);
        $documentos = $this->sut(['primeira' => $estado], [], ['politica_privacidade' => [$solta, $versao]])->documentos();
        $versoes = $documentos[0]->versoes;
        self::assertSame(DocumentoJuridico::PoliticaDePrivacidade, $documentos[0]->documento);
        self::assertSame(['solta.pdf', 'politica-v2.docx'], array_map(static fn ($v) => $v->nomeOriginal, $versoes));
        self::assertSame([null, 'Questão primeira'], array_map(static fn ($v) => $v->questaoTitulo, $versoes));
        self::assertSame([], $documentos[3]->versoes, 'o aceite não tem versão');
    }

    #[TestDox('questão oculta: fora da listagem e da contagem de em aberto')]
    public function testQuestaoOcultaForaDaListaEDaContagem(): void
    {
        $catalogo = CatalogoJuridicoDeTeste::criar([
            CatalogoJuridicoDeTeste::questao('a'),
            CatalogoJuridicoDeTeste::questao('b', oculta: true),
        ]);
        $e = $this->createMock(EstadoDaQuestaoJuridicaRepository::class);
        $e->method('todasPorChave')->willReturn([]);
        $e->method('chavesResolvidas')->willReturn([]);
        $h = $this->createMock(MudancaDeStatusDaQuestaoJuridicaRepository::class);
        $h->method('todasPorChave')->willReturn([]);
        $v = $this->createMock(VersaoDeDocumentoJuridicoRepository::class);
        $v->method('todasPorDocumento')->willReturn([]);

        $saida = (new ListarQuestoesJuridicasUseCase($catalogo, $e, $h, $v))->executar();

        self::assertSame(['a'], array_map(static fn ($q) => $q->questao->chave, $saida));
        self::assertSame(1, (new ContarQuestoesJuridicasEmAbertoUseCase($catalogo, $e))->executar());
    }

    #[TestDox('em aberto: as chaves do catálogo menos as resolvidas (chave resolvida fora do catálogo não conta)')]
    public function testContarEmAberto(): void
    {
        $estados = $this->createMock(EstadoDaQuestaoJuridicaRepository::class);
        $estados->method('chavesResolvidas')->willReturnOnConsecutiveCalls([], ['primeira'], ['primeira', 'segunda', 'antiga']);
        $sut = new ContarQuestoesJuridicasEmAbertoUseCase(CatalogoJuridicoDeTeste::criar(), $estados);

        self::assertSame(2, $sut->executar());
        self::assertSame(1, $sut->executar());
        self::assertSame(0, $sut->executar());

        $estados2 = $this->createMock(EstadoDaQuestaoJuridicaRepository::class);
        $estados2->method('chavesResolvidas')->willReturn(['primeira']);
        self::assertSame(['segunda'], (new ContarQuestoesJuridicasEmAbertoUseCase(CatalogoJuridicoDeTeste::criar(), $estados2))->chaves());
    }

    /**
     * @param array<string, EstadoDaQuestaoJuridica> $estados
     * @param array<string, list<mixed>>             $historico
     * @param array<string, list<mixed>>             $versoes
     */
    private function sut(array $estados, array $historico, array $versoes): ListarQuestoesJuridicasUseCase
    {
        $e = $this->createMock(EstadoDaQuestaoJuridicaRepository::class);
        $e->method('todasPorChave')->willReturn($estados);
        $h = $this->createMock(MudancaDeStatusDaQuestaoJuridicaRepository::class);
        $h->method('todasPorChave')->willReturn($historico);
        $v = $this->createMock(VersaoDeDocumentoJuridicoRepository::class);
        $v->method('todasPorDocumento')->willReturn($versoes);

        return new ListarQuestoesJuridicasUseCase(CatalogoJuridicoDeTeste::criar(), $e, $h, $v);
    }
}
