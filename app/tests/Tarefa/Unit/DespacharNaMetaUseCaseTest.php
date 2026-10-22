<?php

declare(strict_types=1);

namespace App\Tests\Tarefa\Unit;

use App\Entity\Auth\User;
use App\Entity\Auth\UserTenant;
use App\Entity\Tarefa\Tarefa;
use App\Entity\Tarefa\TarefaMensagem;
use App\Entity\Tenant\Cargo;
use App\Entity\Notificacao;
use App\Entity\Tenant\Tenant;
use App\Pasta\Armazenamento\ChavesDePasta;
use App\Pasta\Entity\Pasta;
use App\Pasta\Entity\PastaDocumento;
use App\Repository\TarefaMensagemRepository;
use App\Repository\UserTenantRepository;
use App\Service\NotificacaoService;
use App\Shared\Armazenamento\ChaveDeArquivo;
use App\Tarefa\DTO\DespacharNaMetaInput;
use App\Tarefa\Enum\AcaoDoDespacho;
use App\Tarefa\Enum\NaturezaDoDespacho;
use App\Tarefa\Exception\DespachoRecusadoException;
use App\Tarefa\Service\AnexoDoDespacho;
use App\Tarefa\Service\SorteioDeCodigoDoDespacho;
use App\Tarefa\Service\TextoDoDespacho;
use App\Tarefa\Service\TransacaoDoDespacho;
use App\Tarefa\UseCase\DespacharNaMetaUseCase;
use App\Tests\Processo\Unit\CriaResolucaoDeMencoesDaNota;
use App\Tests\Shared\CriaSanitizadorTextoRico;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\File\UploadedFile;

#[CoversClass(DespacharNaMetaUseCase::class)]
#[Group('tarefa')]
final class DespacharNaMetaUseCaseTest extends TestCase
{
    use CriaResolucaoDeMencoesDaNota;
    use CriaSanitizadorTextoRico;

    /** O escritório da sessão, com o nome que o trait das menções espera. */
    private Tenant $tenant;
    private Tenant $escritorio;
    private Pasta $pasta;
    private AnexoDoDespacho&MockObject $anexos;
    private User $autor;
    private Tarefa $meta;
    private TarefaMensagemRepository&MockObject $mensagens;
    private NotificacaoService&MockObject $notificacoes;
    private SorteioDeCodigoDoDespacho&MockObject $sorteio;
    /** @var TransacaoDoDespacho&object{naFalha: list<ChaveDeArquivo>|null} */
    private TransacaoDoDespacho $transacao;

    protected function setUp(): void
    {
        $this->escritorio = self::comId(new Tenant(), 7);
        $this->autor = self::comId((new User())->setFullName('Ana Souza'), 1);
        $this->meta = new Tarefa();
        $this->meta->setTitulo('Contestação');
        $this->meta->setDescricao('...');
        $this->meta->setTenant($this->escritorio);
        $this->meta->setStatus(Tarefa::STATUS_PENDENTE);
        $this->pasta = self::comId((new Pasta())->setNup('2026.0042')->setTenant($this->escritorio), 40);
        $this->meta->setPasta($this->pasta);
        self::comId($this->meta, 30);
        $this->meta->numerar(12);
        $this->tenant = $this->escritorio;
        $this->montarMencoes();
        $this->anexos = $this->createMock(AnexoDoDespacho::class);
        $this->mensagens = $this->createMock(TarefaMensagemRepository::class);
        $this->notificacoes = $this->createMock(NotificacaoService::class);
        $this->sorteio = $this->createMock(SorteioDeCodigoDoDespacho::class);
        $this->transacao = new class () implements TransacaoDoDespacho {
            /** @var list<ChaveDeArquivo>|null as chaves que a transação recebeu na falha; null se não falhou */
            public ?array $naFalha = null;

            public function executar(\Closure $passos, \Closure $arquivosNovos): mixed
            {
                try {
                    return $passos();
                } catch (\Throwable $e) {
                    $this->naFalha = $arquivosNovos();

                    throw $e;
                }
            }
        };
    }

    /** Uma chave de documento da pasta, como a que o upload entrega a `$aoGravar`. */
    private static function chaveGravada(): ChaveDeArquivo
    {
        return ChavesDePasta::documentoPorNome(7, str_repeat('a', 32) . '.pdf');
    }

    /** O dublê do anexo grava (entrega a chave) e depois devolve o documento ou falha. */
    private function anexoGrava(PastaDocumento|\Throwable $resultado): void
    {
        $this->anexos->method('juntar')->willReturnCallback(static function (Tarefa $m, UploadedFile $a, User $u, Tenant $t, \Closure $aoGravar) use ($resultado): PastaDocumento {
            $aoGravar(self::chaveGravada());
            if ($resultado instanceof \Throwable) {
                throw $resultado;
            }

            return $resultado;
        });
    }

    /** O repositório de verdade grava o código por UPDATE condicional; aqui, código "ocupado" devolve false. */
    private function codigosOcupados(string ...$ocupados): void
    {
        $this->mensagens->method('gravarCodigoSeLivre')->willReturnCallback(static function (TarefaMensagem $d, string $codigo) use ($ocupados): bool {
            if (in_array($codigo, $ocupados, true)) {
                return false;
            }
            (new \ReflectionProperty(TarefaMensagem::class, 'codigo'))->setValue($d, $codigo);

            return true;
        });
    }

    #[TestDox('Despachar grava texto em HTML, natureza, prazo, o código sorteado e quem assinou, sem mudar o status')]
    public function testDespacharGravaODespacho(): void
    {
        $this->sorteio->method('sortear')->willReturn('1234.5678');
        $this->codigosOcupados();
        $this->mensagens->expects(self::once())->method('salvar');
        $this->mensagens->expects(self::once())->method('confirmar');
        $this->notificacoes->expects(self::never())->method(self::anything());

        $saida = $this->useCase()->executar($this->meta, $this->autor, $this->escritorio, $this->input("Cumpra-se:\njuntar", '', 'cumpra_se', '2026-10-29'));

        $despacho = $this->unicoDespacho();
        self::assertSame('1234.5678', $saida->codigo);
        self::assertSame('1234.5678', $despacho->getCodigo());
        self::assertSame('<p>Cumpra-se:<br />juntar</p>', $despacho->getMensagem());
        self::assertSame(NaturezaDoDespacho::CumpraSe, $despacho->getNatureza());
        self::assertSame('2026-10-29', $despacho->getPrazoCumprimento()?->format('Y-m-d'));
        self::assertSame($this->escritorio, $despacho->getTenant());
        self::assertSame('Ana Souza', $despacho->getRegistradoPorNome());
        self::assertSame('Advogada', $despacho->getRegistradoPorCargo());
        self::assertFalse($despacho->podeSerEditado());
        self::assertSame(AcaoDoDespacho::Despacho, $despacho->getAcao());
        self::assertSame(Tarefa::STATUS_PENDENTE, $this->meta->getStatus());
        self::assertFalse($saida->enviada);
    }

    #[TestDox('Código colidiu no índice único: sorteia de novo, até 3 vezes')]
    public function testColisaoSorteiaDeNovo(): void
    {
        $this->sorteio->expects(self::exactly(3))->method('sortear')->willReturnOnConsecutiveCalls('1111.1111', '2222.2222', '3333.3333');
        $this->codigosOcupados('1111.1111', '2222.2222');

        $saida = $this->useCase()->executar($this->meta, $this->autor, $this->escritorio, $this->input('Ok.'));

        self::assertSame('3333.3333', $saida->codigo);
    }

    #[TestDox('Sem código livre depois de 3 sorteios: recusa dentro da transação (que volta) e não avisa ninguém')]
    public function testSemCodigoLivre(): void
    {
        $this->sorteio->expects(self::exactly(3))->method('sortear')->willReturn('1111.1111');
        $this->codigosOcupados('1111.1111');
        $this->notificacoes->expects(self::never())->method(self::anything());

        $this->expectExceptionObject(DespachoRecusadoException::semCodigoLivre());

        $this->useCase()->executar($this->meta, $this->autor, $this->escritorio, $this->input('Ok.'));
    }

    #[TestDox('Despachar sem texto e sem anexo: recusa, nada gravado')]
    public function testSemConteudo(): void
    {
        $this->mensagens->expects(self::never())->method('salvar');
        $this->mensagens->expects(self::never())->method('confirmar');

        $this->expectExceptionObject(DespachoRecusadoException::semConteudo());

        $this->useCase()->executar($this->meta, $this->autor, $this->escritorio, $this->input(''));
    }

    #[TestDox('Só anexo: grava "[Arquivo anexado]" e o anexo entra nos documentos da pasta (C4); a mensagem guarda o documento')]
    public function testSoAnexo(): void
    {
        $this->sorteio->method('sortear')->willReturn('1234.5678');
        $this->codigosOcupados();
        $arquivo = $this->arquivo();
        $this->anexos->expects(self::once())->method('juntar')
            ->with($this->identicalTo($this->meta), $this->identicalTo($arquivo), $this->identicalTo($this->autor), $this->identicalTo($this->escritorio))
            ->willReturn(self::comId(new PastaDocumento(), 55));

        $this->useCase()->executar($this->meta, $this->autor, $this->escritorio, $this->input(''), $arquivo);

        self::assertNull($this->transacao->naFalha, 'a transação não falhou');

        self::assertSame('[Arquivo anexado]', $this->unicoDespacho()->getMensagem());
        self::assertSame(55, $this->unicoDespacho()->getDocumentoJuntadoId());
        self::assertSame('1234.5678', $this->unicoDespacho()->getCodigo());
    }

    #[TestDox('Anexo recusado pela regra dos documentos (tipo ou tamanho): recusa, nada gravado, status intacto')]
    public function testAnexoRecusado(): void
    {
        $this->anexos->method('juntar')->willThrowException(new \InvalidArgumentException('Tipo de arquivo não permitido: application/x-dosexec.'));
        $this->mensagens->expects(self::never())->method('salvar');
        $this->notificacoes->expects(self::never())->method(self::anything());

        try {
            $this->useCase()->executar($this->meta, $this->autor, $this->escritorio, $this->input('Segue.', 'enviar'), $this->arquivo());
            self::fail('Devia recusar o anexo.');
        } catch (DespachoRecusadoException $e) {
            self::assertSame('Tipo de arquivo não permitido: application/x-dosexec. Nada foi gravado.', $e->getMessage());
        }
        self::assertCount(0, $this->meta->getMensagens());
        self::assertSame(Tarefa::STATUS_PENDENTE, $this->meta->getStatus());
        self::assertSame([], $this->transacao->naFalha, 'recusado antes de gravar: não há arquivo novo');
    }

    #[TestDox('A transação volta depois do anexo gravado (sem código livre): a chave do arquivo chega à transação, que decide o destino')]
    public function testFalhaDepoisDoAnexoEntregaAChave(): void
    {
        $this->anexoGrava(self::comId(new PastaDocumento(), 55));
        $this->sorteio->method('sortear')->willReturn('1111.1111');
        $this->codigosOcupados('1111.1111');

        try {
            $this->useCase()->executar($this->meta, $this->autor, $this->escritorio, $this->input('Segue.'), $this->arquivo());
            self::fail('Devia recusar sem código livre.');
        } catch (DespachoRecusadoException $e) {
            self::assertEquals(DespachoRecusadoException::semCodigoLivre(), $e);
        }
        self::assertEquals([self::chaveGravada()], $this->transacao->naFalha);
    }

    #[TestDox('Falha dentro do upload depois de gravar o arquivo: a chave já chegou à transação e a falha original sobe')]
    public function testFalhaDentroDoUploadEntregaAChave(): void
    {
        $falha = new \RuntimeException('flush do documento falhou');
        $this->anexoGrava($falha);
        $this->mensagens->expects(self::never())->method('salvar');

        try {
            $this->useCase()->executar($this->meta, $this->autor, $this->escritorio, $this->input('Segue.'), $this->arquivo());
            self::fail('Devia subir a falha do upload.');
        } catch (\RuntimeException $e) {
            self::assertSame($falha, $e, 'a falha original não é trocada');
        }
        self::assertEquals([self::chaveGravada()], $this->transacao->naFalha);
    }

    #[TestDox('@menção válida (C3): o rótulo vira o nome do banco e o mencionado que vê a pasta é avisado na Central')]
    public function testMencaoAvisaNaCentral(): void
    {
        $rui = $this->pessoa(2, 'Rui Prado');
        $this->colegasDoEscritorio([$this->autor, $rui]);
        $this->acessoAPasta(40, [2 => true]);
        $avisos = $this->capturarAvisos();
        $this->despachoGanhaId(99);

        $this->useCase()->executar($this->meta, $this->autor, $this->escritorio, $this->input('Veja @[Rui](user:2), por favor.'));

        self::assertMatchesRegularExpression('/(@|&#64;)\[Rui Prado\]\(user:2\)/', $this->unicoDespacho()->getMensagem(), 'o rótulo digitado vira o nome do banco');
        self::assertCount(1, $avisos);
        self::assertSame($rui, $avisos[0]['usuario']);
        self::assertSame(Notificacao::TIPO_PASTA_MENCAO_REGISTRO, $avisos[0]['tipo']);
        self::assertSame('Ana mencionou você na Pasta 2026.0042 · Meta #12', $avisos[0]['titulo']);
        self::assertSame('Veja @Rui Prado, por favor.', $avisos[0]['texto']);
        self::assertStringEndsWith('#despacho-99', (string) $avisos[0]['notificacao']->getUrl());
    }

    #[TestDox('@menção de quem não é colega deste escritório vira texto comum, sem aviso; quem não vê a pasta não é avisado')]
    public function testMencaoInvalidaOuSemAcesso(): void
    {
        $semAcesso = $this->pessoa(3, 'Lia Mota');
        $this->colegasDoEscritorio([$this->autor, $semAcesso]);
        $this->acessoAPasta(40, [3 => false]);
        $avisos = $this->capturarAvisos();
        $this->despachoGanhaId(99);

        $this->useCase()->executar($this->meta, $this->autor, $this->escritorio, $this->input('Para @[Estranho](user:77) e @[Lia](user:3).'));

        $texto = $this->unicoDespacho()->getMensagem();
        self::assertStringNotContainsString('user:77', $texto);
        self::assertMatchesRegularExpression('/(@|&#64;)Estranho e/', $texto, 'id de fora do escritório perde a marcação');
        self::assertMatchesRegularExpression('/(@|&#64;)\[Lia Mota\]\(user:3\)/', $texto);
        self::assertCount(0, $avisos);
    }

    #[TestDox('Quem se menciona a si mesmo não recebe aviso')]
    public function testAutoMencaoNaoAvisa(): void
    {
        $this->colegasDoEscritorio([$this->autor]);
        $this->acessoAPasta(40, [1 => true]);
        $avisos = $this->capturarAvisos();
        $this->despachoGanhaId(99);

        $this->useCase()->executar($this->meta, $this->autor, $this->escritorio, $this->input('Eu, @[Ana](user:1).'));

        self::assertCount(0, $avisos);
    }

    #[TestDox('Enviar para revisão: muda o status, avisa e não leva natureza nem prazo (desenho L146)')]
    public function testEnviarParaRevisao(): void
    {
        $this->sorteio->method('sortear')->willReturn('1234.5678');
        $this->codigosOcupados();
        $this->notificacoes->expects(self::once())->method('notificarTarefaEmRevisao')->with($this->meta);

        $saida = $this->useCase()->executar($this->meta, $this->autor, $this->escritorio, $this->input('Pronto.', 'enviar', 'ciencia', '2026-10-29'));

        self::assertTrue($saida->enviada);
        self::assertSame(Tarefa::STATUS_EM_REVISAO, $this->meta->getStatus());
        self::assertNull($this->unicoDespacho()->getNatureza());
        self::assertNull($this->unicoDespacho()->getPrazoCumprimento());
        self::assertSame(AcaoDoDespacho::Envio, $this->unicoDespacho()->getAcao());
        self::assertSame('1234.5678', $this->unicoDespacho()->getCodigo());
    }

    #[TestDox('Enviar para revisão sem texto: grava "Meta enviada para revisão do autor." e avisa o autor uma vez (04B L146)')]
    public function testEnviarSemTexto(): void
    {
        $this->sorteio->method('sortear')->willReturn('1234.5678');
        $this->codigosOcupados();
        $this->mensagens->expects(self::once())->method('salvar');
        $this->notificacoes->expects(self::once())->method('notificarTarefaEmRevisao')->with($this->meta);

        $saida = $this->useCase()->executar($this->meta, $this->autor, $this->escritorio, $this->input('', 'enviar'));

        self::assertSame('1234.5678', $saida->codigo);
        self::assertSame('<p>Meta enviada para revisão do autor.</p>', $this->unicoDespacho()->getMensagem());
        self::assertSame(AcaoDoDespacho::Envio, $this->unicoDespacho()->getAcao());
        self::assertNull($this->unicoDespacho()->getNatureza());
        self::assertSame(Tarefa::STATUS_EM_REVISAO, $this->meta->getStatus());
    }

    #[TestDox('Enviar para revisão só com anexo: o texto é o do envio e o anexo vai junto')]
    public function testEnviarSoComAnexo(): void
    {
        $this->sorteio->method('sortear')->willReturn('1234.5678');
        $this->codigosOcupados();

        $this->anexos->method('juntar')->willReturn(self::comId(new PastaDocumento(), 56));

        $this->useCase()->executar($this->meta, $this->autor, $this->escritorio, $this->input('', 'enviar'), $this->arquivo());

        self::assertSame('<p>Meta enviada para revisão do autor.</p>', $this->unicoDespacho()->getMensagem());
        self::assertSame(56, $this->unicoDespacho()->getDocumentoJuntadoId());
    }

    /** @return iterable<string, array{string, string, DespachoRecusadoException}> */
    public static function acoesForaDoStatus(): iterable
    {
        yield 'enviar em revisão' => [Tarefa::STATUS_EM_REVISAO, 'enviar', DespachoRecusadoException::envioForaDePendente()];
        yield 'enviar concluída' => [Tarefa::STATUS_CONCLUIDA, 'enviar', DespachoRecusadoException::envioForaDePendente()];
        yield 'devolver pendente' => [Tarefa::STATUS_PENDENTE, 'devolver', DespachoRecusadoException::devolucaoForaDeRevisao()];
        yield 'devolver concluída' => [Tarefa::STATUS_CONCLUIDA, 'devolver', DespachoRecusadoException::devolucaoForaDeRevisao()];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('acoesForaDoStatus')]
    #[TestDox('Ação fora do status ($_dataName): recusa com aviso, nada gravado e o status não muda')]
    public function testAcaoForaDoStatusRecusa(string $status, string $acao, DespachoRecusadoException $esperada): void
    {
        $this->meta->setStatus($status);
        $this->mensagens->expects(self::never())->method('salvar');
        $this->notificacoes->expects(self::never())->method(self::anything());

        try {
            $this->useCase()->executar($this->meta, $this->autor, $this->escritorio, $this->input('Com texto.', $acao));
            self::fail('Deveria recusar.');
        } catch (DespachoRecusadoException $e) {
            self::assertSame($esperada->getMessage(), $e->getMessage());
        }
        self::assertSame($status, $this->meta->getStatus());
        self::assertCount(0, $this->meta->getMensagens());
    }

    #[TestDox('Meta concluída: o despacho entra como registro, sem mudar o status')]
    public function testDespachoEmMetaConcluida(): void
    {
        $this->meta->setStatus(Tarefa::STATUS_CONCLUIDA);
        $this->sorteio->method('sortear')->willReturn('1234.5678');
        $this->codigosOcupados();

        $saida = $this->useCase()->executar($this->meta, $this->autor, $this->escritorio, $this->input('Arquivado.'));

        self::assertSame('1234.5678', $saida->codigo);
        self::assertSame(AcaoDoDespacho::Despacho, $this->unicoDespacho()->getAcao());
        self::assertSame(Tarefa::STATUS_CONCLUIDA, $this->meta->getStatus());
    }

    #[TestDox('Devolver: em revisão volta a pendente e avisa os responsáveis')]
    public function testDevolver(): void
    {
        $this->meta->setStatus(Tarefa::STATUS_EM_REVISAO);
        $this->sorteio->method('sortear')->willReturn('1234.5678');
        $this->codigosOcupados();
        $this->notificacoes->expects(self::once())->method('notificarTarefaPendente')->with($this->meta);

        $saida = $this->useCase()->executar($this->meta, $this->autor, $this->escritorio, $this->input('Ajustar.', 'devolver', 'providencia'));

        self::assertTrue($saida->devolvida);
        self::assertSame(Tarefa::STATUS_PENDENTE, $this->meta->getStatus());
        self::assertSame(NaturezaDoDespacho::Providencia, $this->unicoDespacho()->getNatureza());
        self::assertSame(AcaoDoDespacho::Devolucao, $this->unicoDespacho()->getAcao());
    }

    #[TestDox('Meta de outro escritório: recusa, nada gravado')]
    public function testOutroEscritorio(): void
    {
        $this->mensagens->expects(self::never())->method('confirmar');

        $this->expectExceptionObject(DespachoRecusadoException::foraDoEscritorio());

        $this->useCase()->executar($this->meta, $this->autor, self::comId(new Tenant(), 8), $this->input('Ok.'));
    }

    private function useCase(): DespacharNaMetaUseCase
    {
        $vinculos = $this->createStub(UserTenantRepository::class);
        $vinculos->method('findPorUserETenant')->willReturn((new UserTenant($this->autor, $this->escritorio))->setCargo((new Cargo())->setNome('Advogada')));

        return new DespacharNaMetaUseCase(
            $this->mensagens,
            new TextoDoDespacho($this->criarSanitizadorTextoRico()),
            $this->sorteio,
            $this->notificacoes,
            $vinculos,
            $this->resolucao,
            $this->anexos,
            $this->transacao,
        );
    }

    /** Um upload qualquer: quem o lê é o `AnexoDoDespacho` (dublê); aqui só passa adiante. */
    private function arquivo(): UploadedFile
    {
        $caminho = tempnam(sys_get_temp_dir(), 'desp');
        file_put_contents($caminho, '%PDF-1.4');

        return new UploadedFile($caminho, 'peticao.pdf', 'application/pdf', null, true);
    }

    /** O repositório de verdade dá o id no flush; o link do aviso (`#despacho-<id>`) precisa dele. */
    private function despachoGanhaId(int $id): void
    {
        $this->sorteio->method('sortear')->willReturn('1234.5678');
        $this->codigosOcupados();
        $this->mensagens->method('salvar')->willReturnCallback(static function (TarefaMensagem $d) use ($id): void {
            self::comId($d, $id);
        });
    }

    private function input(string $texto, string $acao = '', ?string $natureza = null, ?string $prazo = null): DespacharNaMetaInput
    {
        return new DespacharNaMetaInput($texto, $acao, $natureza, $prazo, new \DateTimeImmutable('2026-10-22'));
    }

    private function unicoDespacho(): TarefaMensagem
    {
        self::assertCount(1, $this->meta->getMensagens());

        return $this->meta->getMensagens()->first();
    }

    /**
     * @template T of object
     * @param T $entidade
     * @return T
     */
    private static function comId(object $entidade, int $id): object
    {
        (new \ReflectionProperty($entidade::class, 'id'))->setValue($entidade, $id);

        return $entidade;
    }
}
