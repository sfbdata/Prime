<?php

declare(strict_types=1);

namespace App\Tests\Processo\Unit;

use App\Entity\Auth\User;
use App\Entity\Notificacao;
use App\Entity\Tenant\Tenant;
use App\Pasta\Entity\Pasta;
use App\Pasta\Service\JanelaDeEdicaoDeComentario;
use App\Processo\Entity\NotaTecnica;
use App\Processo\Entity\NotaTecnicaVersao;
use App\Processo\Entity\Processo;
use App\Processo\Exception\NotaTecnicaForaDoEscopoException;
use App\Processo\Exception\NotaTecnicaNaoEditavelException;
use App\Processo\Repository\NotaTecnicaRepository;
use App\Processo\Repository\NotaTecnicaVersaoRepository;
use App\Processo\UseCase\EditarNotaTecnicaUseCase;
use App\Tests\Shared\CriaSanitizadorTextoRico;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

#[CoversClass(EditarNotaTecnicaUseCase::class)]
final class EditarNotaTecnicaUseCaseTest extends TestCase
{
    use CriaSanitizadorTextoRico;
    use CriaResolucaoDeMencoesDaNota;

    private NotaTecnicaRepository&MockObject $repository;
    private NotaTecnicaVersaoRepository&MockObject $versaoRepository;
    /** @var list<NotaTecnicaVersao> */
    private array $versoes = [];
    private EditarNotaTecnicaUseCase $useCase;
    private Tenant $tenant;
    private User $autor;

    protected function setUp(): void
    {
        $this->tenant     = new Tenant();
        $this->montarMencoes();
        $this->repository = $this->createMock(NotaTecnicaRepository::class);
        $this->versaoRepository = $this->createMock(NotaTecnicaVersaoRepository::class);
        $this->versoes    = [];
        $this->versaoRepository->method('adicionar')->willReturnCallback(function (NotaTecnicaVersao $v): void {
            $this->versoes[] = $v;
        });
        $this->useCase    = new EditarNotaTecnicaUseCase($this->repository, $this->criarSanitizadorTextoRico(), new JanelaDeEdicaoDeComentario(), $this->em, $this->resolucao, $this->versaoRepository);
        $this->autor      = $this->pessoa(1, 'Ana Advogada');
    }

    private function novaNota(string $conteudo = '<p>Original</p>'): NotaTecnica
    {
        $processo = (new Processo())->setTenant($this->tenant);

        return new NotaTecnica($this->tenant, $processo, $this->autor, $conteudo);
    }

    #[TestDox('o autor, dentro da janela, edita — e a nota registra editadaEm')]
    public function testAutorDentroDaJanelaEdita(): void
    {
        $nota = $this->novaNota();
        $this->repository->expects($this->once())->method('salvar')->with($nota, true);

        $saida = $this->useCase->executar($nota, $this->autor, $this->tenant, '<p>Corrigido</p>');

        self::assertSame('<p>Corrigido</p>', $nota->getConteudo());
        self::assertNotNull($nota->getEditadaEm());
        self::assertSame('<p>Corrigido</p>', $saida->conteudo);
        self::assertNotNull($saida->editadaEm);
    }

    #[TestDox('PMC-020: cada edição que muda o texto guarda o anterior INTEIRO (acima dos 500 do audit_log), com autor e quando foi escrito')]
    public function testEdicaoGuardaVersaoAnteriorInteira(): void
    {
        $longo = '<p>' . str_repeat('Despacho original. ', 150) . 'FIM</p>'; // ~2800 caracteres
        $nota  = $this->novaNota($longo);
        $criadaEm = $nota->getCriadaEm();

        $this->useCase->executar($nota, $this->autor, $this->tenant, '<p>Primeira correção</p>');
        self::assertCount(1, $this->versoes);
        self::assertSame($longo, $this->versoes[0]->getConteudo());
        self::assertGreaterThan(500, mb_strlen($this->versoes[0]->getConteudo()));
        self::assertSame($this->autor, $this->versoes[0]->getAutor());
        self::assertSame($this->tenant, $this->versoes[0]->getTenant());
        self::assertSame($nota, $this->versoes[0]->getNota());
        self::assertSame($criadaEm, $this->versoes[0]->getEscritaEm());

        $editadaEm = $nota->getEditadaEm();
        $this->useCase->executar($nota, $this->autor, $this->tenant, '<p>Segunda correção</p>');
        self::assertCount(2, $this->versoes);
        self::assertSame('<p>Primeira correção</p>', $this->versoes[1]->getConteudo());
        self::assertSame($editadaEm, $this->versoes[1]->getEscritaEm());
        self::assertSame('<p>Segunda correção</p>', $nota->getConteudo());
    }

    #[TestDox('PMC-020: salvar sem mudar o texto não cria versão')]
    public function testSalvarSemMudarNaoCriaVersao(): void
    {
        $nota = $this->novaNota('<p>Original</p>');

        $this->useCase->executar($nota, $this->autor, $this->tenant, '<p>Original</p>');

        self::assertSame([], $this->versoes);
    }

    #[TestDox('PMC-020: edição recusada (não autor, vazio) não cria versão')]
    public function testEdicaoRecusadaNaoCriaVersao(): void
    {
        $nota  = $this->novaNota();
        $outro = (new User())->setEmail('outro@test.com');

        foreach ([[$outro, '<p>Invasor</p>'], [$this->autor, '<p><br></p>']] as [$quem, $texto]) {
            try {
                $this->useCase->executar($nota, $quem, $this->tenant, $texto);
            } catch (NotaTecnicaNaoEditavelException|\InvalidArgumentException) {
            }
        }

        self::assertSame([], $this->versoes);
    }

    #[TestDox('a edição também sanitiza — segunda porta do mesmo campo')]
    public function testEdicaoSanitiza(): void
    {
        $nota = $this->novaNota();
        $this->repository->expects($this->once())->method('salvar');

        $this->useCase->executar($nota, $this->autor, $this->tenant, '<p>Corrigido</p><img src=x onerror="alert(1)">');

        self::assertStringNotContainsStringIgnoringCase('onerror', $nota->getConteudo());
        self::assertStringContainsString('Corrigido', $nota->getConteudo());
    }

    #[TestDox('quem não é o autor não edita')]
    public function testNaoAutorLancaExcecao(): void
    {
        $nota  = $this->novaNota();
        $outro = (new User())->setEmail('outro@test.com');
        $this->repository->expects($this->never())->method('salvar');
        $this->expectException(NotaTecnicaNaoEditavelException::class);

        $this->useCase->executar($nota, $outro, $this->tenant, '<p>Corrigido</p>');
    }

    #[TestDox('escritório diferente não edita')]
    public function testTenantDiferenteLancaExcecao(): void
    {
        $nota = $this->novaNota();
        $this->repository->expects($this->never())->method('salvar');
        $this->expectException(NotaTecnicaNaoEditavelException::class);

        $this->useCase->executar($nota, $this->autor, new Tenant(), '<p>Corrigido</p>');
    }

    #[TestDox('conteúdo vazio é recusado e a nota não muda')]
    public function testConteudoVazioLancaInvalidArgument(): void
    {
        $nota = $this->novaNota();
        $this->repository->expects($this->never())->method('salvar');
        $this->expectException(\InvalidArgumentException::class);

        try {
            $this->useCase->executar($nota, $this->autor, $this->tenant, '<p><br></p>');
        } finally {
            self::assertSame('<p>Original</p>', $nota->getConteudo());
            self::assertNull($nota->getEditadaEm());
        }
    }

    #[TestDox('acima de 5000 caracteres visíveis é recusado')]
    public function testConteudoAcimaDe5000LancaInvalidArgument(): void
    {
        $nota = $this->novaNota();
        $this->repository->expects($this->never())->method('salvar');
        $this->expectException(\InvalidArgumentException::class);

        $this->useCase->executar($nota, $this->autor, $this->tenant, str_repeat('a', 5001));
    }

    #[TestDox('podeEditar: falso um segundo depois dos 15 min')]
    public function testPodeEditarRetornaFalseForaDaJanela(): void
    {
        $nota     = $this->novaNota();
        $expirado = $nota->getCriadaEm()->add(new \DateInterval('PT15M1S'));

        self::assertFalse($this->useCase->podeEditar($nota, $this->autor, $this->tenant, $expirado));
    }

    #[TestDox('podeEditar: verdadeiro no último instante dos 15 min')]
    public function testPodeEditarRetornaTrueDentroDaJanela(): void
    {
        $nota   = $this->novaNota();
        $dentro = $nota->getCriadaEm()->add(new \DateInterval('PT15M'));

        self::assertTrue($this->useCase->podeEditar($nota, $this->autor, $this->tenant, $dentro));
    }

    #[TestDox('nota sem autor (colaborador desvinculado) não é de ninguém')]
    public function testNotaSemAutorNaoEEditavel(): void
    {
        $nota = $this->novaNota();
        (new \ReflectionProperty(NotaTecnica::class, 'autor'))->setValue($nota, null);

        self::assertFalse($this->useCase->podeEditar($nota, $this->autor, $this->tenant));
    }

    // ── @menções (PMC-019) ───────────────────────────────────────────────────

    /** Nota 700 já gravada, na pasta 41 do escritório que vincula o processo dela. */
    private function notaNaPasta(string $conteudo = '<p>Original</p>'): array
    {
        $nota = $this->definirId($this->novaNota($conteudo), 700);
        $pasta = $this->definirId((new Pasta())->setTenant($this->tenant)->setNup('1232'), 41);
        $pasta->vincularProcesso($nota->getProcesso());

        return [$nota, $pasta];
    }

    #[TestDox('editar: token forjado (id inexistente) e id de outro escritório viram texto comum, sem aviso')]
    public function testEdicaoComTokenForjadoViraTexto(): void
    {
        [$nota, $pasta] = $this->notaNaPasta();
        $this->colegasDoEscritorio([$this->autor]);
        $this->notificacaoService->expects($this->never())->method('criar');

        $this->useCase->executar($nota, $this->autor, $this->tenant, '<p>Falar com @[Diretor Geral](user:9999999999) e @[Carla](user:9)</p>', $pasta);

        self::assertSame('<p>Falar com @Diretor Geral e @Carla</p>', $nota->getConteudo());
    }

    #[TestDox('editar avisa só quem PASSOU a ser mencionado: quem já tem o aviso desta nota (mesmo link) não recebe de novo')]
    public function testEdicaoNaoRenotifica(): void
    {
        [$nota, $pasta] = $this->notaNaPasta('<p>@[Bruno Lima](user:2)</p>');
        $this->colegasDoEscritorio([$this->pessoa(2, 'Bruno Lima'), $this->pessoa(3, 'Carla Dias')]);
        $this->acessoAPasta(41, [2 => true, 3 => true]);
        // O Bruno já foi avisado por esta nota (no envio): o banco tem o aviso com o mesmo link.
        $this->notificacaoRepository->method('findOneBy')->willReturnCallback(
            static fn (array $criterio): ?Notificacao => (int) $criterio['usuario']->getId() === 2
                && $criterio['tipo'] === Notificacao::TIPO_NOTA_TECNICA_MENCAO
                && $criterio['url'] === '/pasta/41#nota-tecnica-700'
                ? new Notificacao() : null,
        );
        $avisos = $this->capturarAvisos();

        $this->useCase->executar($nota, $this->autor, $this->tenant, '<p>@[Bruno](user:2) e @[Carla](user:3)</p>', $pasta);

        self::assertCount(1, $avisos);
        self::assertSame(3, $avisos[0]['usuario']->getId());
        self::assertSame('/pasta/41#nota-tecnica-700', $avisos[0]['notificacao']->getUrl());
    }

    #[TestDox('editar com pasta de OUTRO escritório como contexto é recusado e a nota não muda')]
    public function testEdicaoComPastaAlheiaLancaForaDoEscopo(): void
    {
        $nota   = $this->novaNota();
        $alheia = (new Pasta())->setTenant(new Tenant());
        $alheia->vincularProcesso($nota->getProcesso());
        $this->repository->expects($this->never())->method('salvar');
        $this->expectException(NotaTecnicaForaDoEscopoException::class);

        try {
            $this->useCase->executar($nota, $this->autor, $this->tenant, '<p>Corrigido</p>', $alheia);
        } finally {
            self::assertSame('<p>Original</p>', $nota->getConteudo());
        }
    }
}
