<?php

declare(strict_types=1);

namespace App\Tests\Pasta\Unit;

use App\Entity\Auth\User;
use App\Entity\Notificacao;
use App\Entity\Tenant\Tenant;
use App\Pasta\Entity\Pasta;
use App\Pasta\Entity\PastaObservacaoDetalhes;
use App\Pasta\Exception\ObservacaoDetalhesNaoEditavelException;
use App\Tests\Processo\Unit\CriaResolucaoDeMencoesDaNota;
use App\Tests\Shared\CriaSanitizadorTextoRico;
use App\Pasta\UseCase\EditarObservacaoDetalhesUseCase;
use App\Tests\Pasta\Support\CriaPrazoDoComentario;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;

#[CoversClass(EditarObservacaoDetalhesUseCase::class)]
final class EditarObservacaoDetalhesUseCaseTest extends TestCase
{
    use CriaPrazoDoComentario;

    use CriaSanitizadorTextoRico;
    use CriaResolucaoDeMencoesDaNota;

    private EditarObservacaoDetalhesUseCase $useCase;
    private Tenant $tenant;
    private User $autor;

    protected function setUp(): void
    {
        $this->tenant  = $this->definirId(new Tenant(), 1);
        $this->montarMencoes();
        $this->useCase = new EditarObservacaoDetalhesUseCase($this->em, $this->criarSanitizadorTextoRico(), $this->prazoDoComentario(), $this->resolucao);
        $this->autor   = $this->pessoa(1, 'Ana Souza');
    }

    private function novaObservacao(string $conteudo = 'Original'): PastaObservacaoDetalhes
    {
        return (new PastaObservacaoDetalhes())
            ->setPasta(new Pasta())
            ->setAutor($this->autor)
            ->setTenant($this->tenant)
            ->setConteudo($conteudo);
    }

    public function testAutorDentroDaJanelaEdita(): void
    {
        $obs = $this->novaObservacao();
        $this->em->expects($this->once())->method('flush');

        $this->useCase->executar($obs, $this->autor, $this->tenant, 'Corrigido');

        self::assertSame('Corrigido', $obs->getConteudo());
        self::assertNotNull($obs->getEditadaEm());
    }

    #[TestDox('PAS-045b: a edição guarda o texto anterior INTEIRO (acima dos 500 do audit_log); só a última versão')]
    public function testGuardaOTextoAnteriorInteiro(): void
    {
        $longo = str_repeat('Texto original longo. ', 200); // ~4400 caracteres
        $obs   = $this->novaObservacao($longo);
        self::assertNull($obs->getConteudoAnterior());

        $this->useCase->executar($obs, $this->autor, $this->tenant, 'Primeira correção');
        self::assertSame($longo, $obs->getConteudoAnterior());
        self::assertGreaterThan(500, mb_strlen((string) $obs->getConteudoAnterior()));

        $this->useCase->executar($obs, $this->autor, $this->tenant, 'Segunda correção');
        self::assertSame('Primeira correção', $obs->getConteudoAnterior());
        self::assertSame('Segunda correção', $obs->getConteudo());
    }

    #[TestDox('PAS-045b: salvar sem mudar o texto não apaga o "Antes" da edição real')]
    public function testSalvarSemMudarMantemOAnterior(): void
    {
        $obs = $this->novaObservacao('Original');
        $this->useCase->executar($obs, $this->autor, $this->tenant, 'Corrigido');
        $this->useCase->executar($obs, $this->autor, $this->tenant, 'Corrigido');

        self::assertSame('Original', $obs->getConteudoAnterior());
        self::assertSame('Corrigido', $obs->getConteudo());
    }

    #[TestDox('PAS-045b: edição recusada não toca no texto anterior')]
    public function testEdicaoRecusadaNaoMexeNoAnterior(): void
    {
        $obs   = $this->novaObservacao('Original');
        $outro = (new User())->setEmail('outro@test.com');

        try {
            $this->useCase->executar($obs, $outro, $this->tenant, 'Invasor');
            self::fail('o não autor devia ser recusado');
        } catch (ObservacaoDetalhesNaoEditavelException) {
        }

        self::assertNull($obs->getConteudoAnterior());
        self::assertSame('Original', $obs->getConteudo());
    }

    public function testNaoAutorLancaExcecao(): void
    {
        $obs   = $this->novaObservacao();
        $outro = (new User())->setEmail('outro@test.com');

        $this->em->expects($this->never())->method('flush');

        $this->expectException(ObservacaoDetalhesNaoEditavelException::class);

        $this->useCase->executar($obs, $outro, $this->tenant, 'Corrigido');
    }

    public function testTenantDiferenteLancaExcecao(): void
    {
        $obs = $this->novaObservacao();

        $this->em->expects($this->never())->method('flush');

        $this->expectException(ObservacaoDetalhesNaoEditavelException::class);

        $this->useCase->executar($obs, $this->autor, new Tenant(), 'Corrigido');
    }

    public function testConteudoVazioLancaInvalidArgument(): void
    {
        $obs = $this->novaObservacao();

        $this->em->expects($this->never())->method('flush');

        $this->expectException(\InvalidArgumentException::class);

        $this->useCase->executar($obs, $this->autor, $this->tenant, '   ');
    }

    public function testConteudoAcimaDe5000LancaInvalidArgument(): void
    {
        $obs = $this->novaObservacao();

        $this->em->expects($this->never())->method('flush');

        $this->expectException(\InvalidArgumentException::class);

        $this->useCase->executar($obs, $this->autor, $this->tenant, str_repeat('a', 5001));
    }

    public function testPodeEditarRetornaFalseForaDaJanela(): void
    {
        $obs      = $this->novaObservacao();
        $expirado = $obs->getCriadaEm()->add(new \DateInterval('PT15M1S'));

        self::assertFalse($this->useCase->podeEditar($obs, $this->autor, $this->tenant, $expirado));
    }

    public function testPodeEditarRetornaTrueDentroDaJanela(): void
    {
        $obs    = $this->novaObservacao();
        $dentro = $obs->getCriadaEm()->add(new \DateInterval('PT15M'));

        self::assertTrue($this->useCase->podeEditar($obs, $this->autor, $this->tenant, $dentro));
    }

    // ── @menção (EDT-010r) ───────────────────────────────────────────────────

    private function naPasta(): PastaObservacaoDetalhes
    {
        $pasta = $this->definirId((new Pasta())->setTenant($this->tenant)->setNup('0001/2026'), 77);

        return $this->definirId($this->novaObservacao()->setPasta($pasta), 40);
    }

    #[TestDox('EDT-010r: editar e mencionar um colega avisa quem passou a ser mencionado, com o link do cartão')]
    public function testEditarComMencaoAvisa(): void
    {
        $bruno = $this->pessoa(2, 'Bruno Lima');
        $this->colegasDoEscritorio([$this->autor, $bruno]);
        $this->acessoAPasta(77, [2 => true]);
        $avisos = $this->capturarAvisos();
        $this->em->expects($this->once())->method('wrapInTransaction');
        $obs = $this->naPasta();

        $this->useCase->executar($obs, $this->autor, $this->tenant, '<p>Fala com @[Bru](user:2) amanhã</p>');

        self::assertStringContainsString('@[Bruno Lima](user:2)', $obs->getConteudo(), 'o nome gravado é o do banco');
        self::assertCount(1, $avisos);
        self::assertSame($bruno, $avisos[0]['usuario']);
        self::assertSame(Notificacao::TIPO_PASTA_MENCAO_REGISTRO, $avisos[0]['tipo']);
        self::assertSame('Ana mencionou você na Pasta 0001/2026 · Detalhes', $avisos[0]['titulo']);
        self::assertSame('/pasta/77#detalhes-obs-40', $avisos[0]['notificacao']->getUrl());
    }

    #[TestDox('EDT-010r: token de quem não é colega ativo deste escritório vira texto e não avisa')]
    public function testMencaoForjadaViraTexto(): void
    {
        $this->colegasDoEscritorio([$this->autor]);
        $this->notificacaoService->expects($this->never())->method('criar');
        $obs = $this->naPasta();

        $this->useCase->executar($obs, $this->autor, $this->tenant, '<p>Oi @[Intruso](user:99)</p>');

        self::assertStringNotContainsString('(user:99)', $obs->getConteudo());
        self::assertStringContainsString('@Intruso', $obs->getConteudo());
    }

    #[TestDox('EDT-010r: numa resposta, o autor respondido não recebe a menção (já tem a resposta)')]
    public function testRespostaPulaOAutorRespondido(): void
    {
        $bruno = $this->pessoa(2, 'Bruno Lima');
        $this->colegasDoEscritorio([$this->autor, $bruno]);
        $this->acessoAPasta(77, [2 => true]);
        $this->notificacaoService->expects($this->never())->method('criar');
        $obs  = $this->naPasta();
        $raiz = $this->definirId((new PastaObservacaoDetalhes())->setPasta($obs->getPasta())->setAutor($bruno)->setTenant($this->tenant)->setConteudo('x'), 39);
        $obs->setRespostaA($raiz);

        $this->useCase->executar($obs, $this->autor, $this->tenant, '<p>@[Bruno Lima](user:2) ok</p>');
    }
}
