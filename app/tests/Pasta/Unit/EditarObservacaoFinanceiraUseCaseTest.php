<?php

declare(strict_types=1);

namespace App\Tests\Pasta\Unit;

use App\Entity\Auth\User;
use App\Entity\Notificacao;
use App\Entity\Tenant\Tenant;
use App\Pasta\Entity\Pasta;
use App\Pasta\Entity\PastaObservacaoFinanceira;
use App\Pasta\Exception\ObservacaoFinanceiraNaoEditavelException;
use App\Pasta\Service\JanelaDeEdicaoDeComentario;
use App\Tests\Processo\Unit\CriaResolucaoDeMencoesDaNota;
use App\Tests\Shared\CriaSanitizadorTextoRico;
use App\Pasta\UseCase\EditarObservacaoFinanceiraUseCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;

#[CoversClass(EditarObservacaoFinanceiraUseCase::class)]
final class EditarObservacaoFinanceiraUseCaseTest extends TestCase
{
    use CriaSanitizadorTextoRico;
    use CriaResolucaoDeMencoesDaNota;

    private EditarObservacaoFinanceiraUseCase $useCase;
    private Tenant $tenant;
    private User $autor;

    protected function setUp(): void
    {
        $this->tenant  = $this->definirId(new Tenant(), 1);
        $this->montarMencoes();
        $this->useCase = new EditarObservacaoFinanceiraUseCase($this->em, $this->criarSanitizadorTextoRico(), new JanelaDeEdicaoDeComentario(), $this->resolucao);
        $this->autor   = $this->pessoa(1, 'Ana Souza');
    }

    private function novaObservacao(string $conteudo = 'Original'): PastaObservacaoFinanceira
    {
        return (new PastaObservacaoFinanceira())
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
        } catch (ObservacaoFinanceiraNaoEditavelException) {
        }

        self::assertNull($obs->getConteudoAnterior());
        self::assertSame('Original', $obs->getConteudo());
    }

    public function testNaoAutorLancaExcecao(): void
    {
        $obs   = $this->novaObservacao();
        $outro = (new User())->setEmail('outro@test.com');

        $this->em->expects($this->never())->method('flush');

        $this->expectException(ObservacaoFinanceiraNaoEditavelException::class);

        $this->useCase->executar($obs, $outro, $this->tenant, 'Corrigido');
    }

    public function testTenantDiferenteLancaExcecao(): void
    {
        $obs = $this->novaObservacao();

        $this->em->expects($this->never())->method('flush');

        $this->expectException(ObservacaoFinanceiraNaoEditavelException::class);

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

    private function naPasta(): PastaObservacaoFinanceira
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
        self::assertSame('Ana mencionou você na Pasta 0001/2026 · Financeiro', $avisos[0]['titulo']);
        self::assertSame('/pasta/77#financeiro-obs-40', $avisos[0]['notificacao']->getUrl());
    }

    #[TestDox('C6-SEC-02: editar e mencionar no Relatório financeiro só avisa quem VÊ O FINANCEIRO — ver a pasta não basta')]
    public function testEditarComMencaoAQuemNaoVeOFinanceiroNaoAvisa(): void
    {
        $bruno = $this->pessoa(2, 'Bruno Lima');
        $carla = $this->pessoa(3, 'Carla Dias');
        $this->colegasDoEscritorio([$this->autor, $bruno, $carla]);
        // Os dois veem a pasta; só a Carla não vê o financeiro dela.
        $this->acessoAPasta(77, [2 => true, 3 => true]);
        $this->semVerOFinanceiro = [3];
        $avisos = $this->capturarAvisos();
        $obs    = $this->naPasta();

        $this->useCase->executar($obs, $this->autor, $this->tenant, '<p>@[Bru](user:2) e @[Car](user:3)</p>');

        // A marcação continua no texto (quem é mencionável não muda); só o sino é filtrado.
        self::assertStringContainsString('@[Carla Dias](user:3)', $obs->getConteudo());
        self::assertCount(1, $avisos, 'só o Bruno, que vê o financeiro, é avisado');
        self::assertSame($bruno, $avisos[0]['usuario']);
    }

    #[TestDox('C6-SEC-02: editar mencionando SÓ quem não vê o financeiro não cria aviso nenhum')]
    public function testEditarComMencaoSoAQuemNaoVeOFinanceiroNaoCriaAviso(): void
    {
        $carla = $this->pessoa(3, 'Carla Dias');
        $this->colegasDoEscritorio([$this->autor, $carla]);
        $this->acessoAPasta(77, [3 => true]);
        $this->semVerOFinanceiro = [3];
        $this->notificacaoService->expects($this->never())->method('criar');
        $obs = $this->naPasta();

        $this->useCase->executar($obs, $this->autor, $this->tenant, '<p>Veja @[Carla](user:3)</p>');

        self::assertStringContainsString('@[Carla Dias](user:3)', $obs->getConteudo(), 'a edição é gravada normalmente');
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
        $raiz = $this->definirId((new PastaObservacaoFinanceira())->setPasta($obs->getPasta())->setAutor($bruno)->setTenant($this->tenant)->setConteudo('x'), 39);
        $obs->setRespostaA($raiz);

        $this->useCase->executar($obs, $this->autor, $this->tenant, '<p>@[Bruno Lima](user:2) ok</p>');
    }
}
