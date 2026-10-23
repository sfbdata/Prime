<?php

declare(strict_types=1);

namespace App\Tests\Tarefa\Unit;

use App\Agenda\Service\CalendarioDoSistema;
use App\Entity\Auth\User;
use App\Entity\Tarefa\Tarefa;
use App\Entity\Tarefa\TarefaMensagem;
use App\Entity\Tenant\Tenant;
use App\Pasta\Entity\Pasta;
use App\Pasta\Entity\PastaDocumento;
use App\Pasta\Repository\PastaDocumentoRepository;
use App\Pasta\Service\DiasUteisDoEscritorio;
use App\Tarefa\Enum\AcaoDoDespacho;
use App\Tarefa\Enum\NaturezaDoDespacho;
use App\Tarefa\Service\QuemCuidaDaMeta;
use App\Tarefa\UseCase\MontarDespachosDaMetaUseCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;

#[CoversClass(MontarDespachosDaMetaUseCase::class)]
#[Group('tarefa')]
final class MontarDespachosDaMetaUseCaseTest extends TestCase
{
    private Tenant $escritorio;
    private User $criador;
    private User $responsavel;
    private Tarefa $meta;
    /** @var list<PastaDocumento> o que o repositório devolve na consulta dos juntados */
    private array $documentosDaPasta = [];
    /** @var array<string, mixed>|null o filtro da última consulta dos juntados */
    private ?array $filtroDosJuntados = null;

    protected function setUp(): void
    {
        $this->escritorio = (new Tenant())->setCidade('Maceió')->setEstado('AL');
        $this->criador = self::pessoa(1, 'Carla Dias');
        $this->responsavel = self::pessoa(2, 'Rui Prado');
        $this->meta = new Tarefa();
        $this->meta->setTenant($this->escritorio);
        $this->meta->setCriadoPor($this->criador);
        $this->meta->addResponsavel($this->responsavel);
    }

    #[TestDox('Formulário: prazo padrão de 5 dias úteis pela regra do escritório e dias não úteis da janela')]
    public function testFormulario(): void
    {
        // 15/09/2026 (terça) em AL: 16/09 é feriado estadual; 5 úteis = 17, 18, 21, 22, 23.
        $saida = $this->useCase()->executar($this->meta, $this->criador, $this->escritorio, new \DateTimeImmutable('2026-09-15 14:00'));

        self::assertSame('2026-09-15', $saida->formulario->hoje);
        self::assertSame('2026-09-23', $saida->formulario->prazoPadrao);
        self::assertSame(5, $saida->formulario->uteisDoPrazoPadrao);
        self::assertContains('2026-09-16', $saida->formulario->naoUteis);
        self::assertContains('2026-09-19', $saida->formulario->naoUteis);
        self::assertNotContains('2026-09-17', $saida->formulario->naoUteis);
        self::assertCount(5, $saida->formulario->naturezas);
        self::assertSame(['valor' => 'ciencia', 'rotulo' => 'metas.despacho.natureza.ciencia', 'inicio' => 'metas.despacho.inicio.ciencia'], $saida->formulario->naturezas[0]);
    }

    #[TestDox('Linhas: numeradas do mais antigo, a última é a mais recente; nome e cargo do registro congelados')]
    public function testLinhas(): void
    {
        $antigo = $this->despacho(10, $this->criador, '2026-10-01 09:00');
        $novo = $this->despacho(11, $this->responsavel, '2026-10-02 09:00');
        $novo->setNatureza(NaturezaDoDespacho::Ciencia)->registrar('1234.5678', 'Rui Prado', 'Advogado');
        $this->responsavel->setFullName('Rui Prado Neto');

        $linhas = $this->useCase()->executar($this->meta, $this->criador, $this->escritorio, new \DateTimeImmutable('2026-10-22'))->linhas;

        self::assertNull($linhas[10]->codigo, 'mensagem antiga, sem registro');
        self::assertSame('metas.despacho.natureza.ciencia', $linhas[11]->natureza);
        self::assertSame('Rui Prado', $linhas[11]->registradoPor, 'nome congelado no registro');
        self::assertSame('Advogado', $linhas[11]->cargo);
        self::assertSame('Maceió', $linhas[11]->cidade);
        self::assertSame('Rui Prado Neto', $linhas[11]->autor, 'o cabeçalho usa o nome de hoje');
    }

    #[TestDox('Prazo vencido fica marcado; com certidão o "Cumprir até" some e não se certifica de novo')]
    public function testPrazoECertidao(): void
    {
        $vencido = $this->despacho(10, $this->criador, '2026-10-01 09:00')->setPrazoCumprimento(new \DateTimeImmutable('2026-10-21'))->setAcao(AcaoDoDespacho::Despacho);
        $certificado = $this->despacho(11, $this->criador, '2026-10-02 09:00')->setPrazoCumprimento(new \DateTimeImmutable('2026-10-21'));
        $certificado->certificar($this->responsavel, 'Certifico que fiz.', new \DateTimeImmutable('2026-10-20 10:00'));
        $vencido->darCiencia($this->responsavel, new \DateTimeImmutable('2026-10-01 10:00'));

        $linhas = $this->useCase()->executar($this->meta, $this->responsavel, $this->escritorio, new \DateTimeImmutable('2026-10-22'))->linhas;

        self::assertTrue($linhas[10]->prazoVencido);
        self::assertTrue($linhas[10]->podeCertificar);
        self::assertSame('Rui', $linhas[10]->ciente);
        self::assertNull($linhas[11]->prazo);
        self::assertFalse($linhas[11]->podeCertificar);
        self::assertSame('Rui Prado', $linhas[11]->certificadoPor);
    }

    #[TestDox('Quem não cuida da meta não vê o Certificar; escritório sem cidade, sem linha de local')]
    public function testSemPermissaoESemCidade(): void
    {
        $this->despacho(10, $this->criador, '2026-10-01 09:00')->setAcao(AcaoDoDespacho::Despacho)->registrar('1234.5678', 'Carla Dias', null);
        $this->escritorio->setCidade(null);

        $linhas = $this->useCase()->executar($this->meta, self::pessoa(9, 'Olga'), $this->escritorio, new \DateTimeImmutable('2026-10-22'))->linhas;

        self::assertFalse($linhas[10]->podeCertificar);
        self::assertNull($linhas[10]->cidade);
    }

    #[TestDox('Tipo no cabeçalho: natureza, senão o rótulo da ação (L127); só a ação despacho se certifica')]
    public function testTipoECertificarPorAcao(): void
    {
        $this->despacho(10, $this->criador, '2026-10-01 09:00');
        $this->despacho(11, $this->criador, '2026-10-02 09:00')->setAcao(AcaoDoDespacho::Envio);
        $this->despacho(12, $this->criador, '2026-10-03 09:00')->setAcao(AcaoDoDespacho::Devolucao)->setNatureza(NaturezaDoDespacho::Providencia);
        $this->despacho(13, $this->criador, '2026-10-04 09:00')->setAcao(AcaoDoDespacho::Devolucao);
        $this->despacho(14, $this->criador, '2026-10-05 09:00')->setAcao(AcaoDoDespacho::Despacho);

        $linhas = $this->useCase()->executar($this->meta, $this->responsavel, $this->escritorio, new \DateTimeImmutable('2026-10-22'))->linhas;

        self::assertSame(['metas.despacho.tipo.despacho', null, false], [$linhas[10]->tipo, $linhas[10]->acao, $linhas[10]->podeCertificar]);
        self::assertSame(['metas.despacho.tipo.envio', 'envio', false], [$linhas[11]->tipo, $linhas[11]->acao, $linhas[11]->podeCertificar]);
        self::assertSame(['metas.despacho.natureza.providencia', 'devolucao', false], [$linhas[12]->tipo, $linhas[12]->acao, $linhas[12]->podeCertificar]);
        self::assertSame('metas.despacho.tipo.devolucao', $linhas[13]->tipo);
        self::assertSame(['metas.despacho.tipo.despacho', 'despacho', true], [$linhas[14]->tipo, $linhas[14]->acao, $linhas[14]->podeCertificar]);
    }

    #[TestDox('"quem → para" (L161): os mencionados do texto, pelo nome curto, sem repetir; sem menção, lista vazia')]
    public function testParaSaiDasMencoes(): void
    {
        $this->despacho(10, $this->criador, '2026-10-01 09:00')->setMensagem('<p>&#64;[RUI PRADO NETO](user:2) e @[Lia Mota](user:3), de novo &#64;[RUI PRADO NETO](user:2)</p>');
        $this->despacho(11, $this->criador, '2026-10-02 09:00');

        $linhas = $this->useCase()->executar($this->meta, $this->criador, $this->escritorio, new \DateTimeImmutable('2026-10-22'))->linhas;

        self::assertSame(['Rui Prado', 'Lia Mota'], $linhas[10]->para);
        self::assertSame([], $linhas[11]->para);
    }

    #[TestDox('Anexo juntado à pasta (C4): nome e id do documento, lidos numa consulta presa ao escritório e à pasta; anexo antigo fica de fora')]
    public function testAnexoJuntado(): void
    {
        $pasta = new Pasta();
        $this->meta->setPasta($pasta);
        $documento = (new PastaDocumento())->setNomeOriginal('peticao.pdf');
        (new \ReflectionProperty(PastaDocumento::class, 'id'))->setValue($documento, 55);
        $this->documentosDaPasta = [$documento];
        $this->despacho(10, $this->criador, '2026-10-01 09:00')->juntarDocumentoDaPasta(55);
        $this->despacho(11, $this->criador, '2026-10-02 09:00')->setArquivoAnexo('/uploads/tarefas/chat/a.pdf');
        $this->despacho(12, $this->criador, '2026-10-03 09:00')->juntarDocumentoDaPasta(99);

        $linhas = $this->useCase()->executar($this->meta, $this->criador, $this->escritorio, new \DateTimeImmutable('2026-10-22'))->linhas;

        self::assertSame(['peticao.pdf', 55], [$linhas[10]->anexoNome, $linhas[10]->anexoDocumentoId]);
        self::assertSame([null, null], [$linhas[11]->anexoNome, $linhas[11]->anexoDocumentoId]);
        self::assertNull($linhas[12]->anexoNome, 'documento que não é desta pasta e deste escritório não aparece');
        self::assertSame(['id' => [55, 99], 'tenant' => $this->escritorio, 'pasta' => $pasta], $this->filtroDosJuntados);
    }

    private function useCase(): MontarDespachosDaMetaUseCase
    {
        $documentos = $this->createStub(PastaDocumentoRepository::class);
        $documentos->method('findBy')->willReturnCallback(function (array $filtro): array {
            $this->filtroDosJuntados = $filtro;

            return $this->documentosDaPasta;
        });

        return new MontarDespachosDaMetaUseCase(new DiasUteisDoEscritorio(new CalendarioDoSistema()), new QuemCuidaDaMeta(), $documentos);
    }

    private function despacho(int $id, User $autor, string $em): TarefaMensagem
    {
        $despacho = (new TarefaMensagem())->setUsuario($autor)->setMensagem('Texto')->setCriadoEm(new \DateTimeImmutable($em));
        (new \ReflectionProperty(TarefaMensagem::class, 'id'))->setValue($despacho, $id);
        $this->meta->addMensagem($despacho);

        return $despacho;
    }

    private static function pessoa(int $id, string $nome): User
    {
        $pessoa = (new User())->setFullName($nome);
        (new \ReflectionProperty(User::class, 'id'))->setValue($pessoa, $id);

        return $pessoa;
    }
}
