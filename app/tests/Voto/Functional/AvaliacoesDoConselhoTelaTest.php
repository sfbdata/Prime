<?php

declare(strict_types=1);

namespace App\Tests\Voto\Functional;

use App\Entity\Auth\User;
use App\Tests\Factory\Voto\AssembleiaFactory;
use App\Tests\Factory\Voto\UnidadeDaAssembleiaFactory;
use App\Tests\Functional\JusPrimeWebTestCase;
use App\Voto\Controller\AvaliacoesDoConselhoController;
use App\Voto\Controller\UnidadesDaAssembleiaController;
use App\Voto\Entity\Assembleia;
use App\Voto\Entity\OcorrenciaDaUnidade;
use App\Voto\Enum\AvaliacaoDaOcorrencia;
use App\Voto\Enum\OrigemDaOcorrencia;
use App\Voto\Enum\PapelNaEquipe;
use App\Voto\EventListener\BarreiraDaEquipeListener;
use App\Voto\Service\AcessoAAssembleia;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\TestDox;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Zenstruck\Foundry\Test\Factories;

/**
 * As ocorrências e as avaliações do conselho pelas telas (spec voto-avaliacoes-do-conselho-1611, seções 3 e 4): a tabela de
 * quem pode (a decisão é a única escrita do Visualizar; auditoria e fiscal recebem 403 nela, o conselho recebe 403 no
 * registro), os botões só para quem pode, a aba, a Lista de aptos com hash e contador, a cédula, a ata e o 404 de outro
 * escritório.
 */
#[CoversClass(AvaliacoesDoConselhoController::class)]
#[CoversClass(UnidadesDaAssembleiaController::class)]
#[CoversClass(BarreiraDaEquipeListener::class)]
#[CoversClass(AcessoAAssembleia::class)]
final class AvaliacoesDoConselhoTelaTest extends JusPrimeWebTestCase
{
    use ComPessoasDoEscritorioDoVoto;
    use Factories;

    private KernelBrowser $client;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $this->client->disableReboot();
        $this->prepararEscritorioDoVoto();
    }

    #[TestDox('O conselho vê a aba e a lista (pendentes primeiro, chips e cores do desenho), decide e a decisão fica registrada')]
    public function testConselhoVeEDecide(): void
    {
        [$a, $conselho] = $this->assembleiaComConselho();
        $mantida = $this->ocorrencia($a, '103', AvaliacaoDaOcorrencia::Mantida, 'Sem comprovante');
        $pendente = $this->ocorrencia($a, '102', AvaliacaoDaOcorrencia::Pendente, 'Quitou ontem');
        $this->entrar($conselho);

        $tela = $this->client->request('GET', '/voto/assembleias/' . $a->getId() . '/avaliacoes');

        self::assertResponseIsSuccessful();
        self::assertSame('Avaliações', trim($tela->filter('.voto-sub-abas .vTabOn')->text()));
        self::assertStringContainsString('Avaliações do conselho', $tela->filter('.voto-avaliacoes .vH')->text());
        $itens = $tela->filter('.voto-avaliacao');
        self::assertCount(2, $itens);
        self::assertStringContainsString('Beto Inapto', $itens->eq(0)->text(), 'a pendente vem primeiro');
        self::assertSame('Pendente', trim($itens->eq(0)->filter('.vChip')->text()));
        self::assertStringContainsString('background:#8a6200', (string) $itens->eq(0)->filter('.vChip')->attr('style'));
        self::assertSame('Mantida', trim($itens->eq(1)->filter('.vChip')->text()));
        self::assertStringContainsString('Inaptidão mantida em', $itens->eq(1)->text());
        self::assertStringContainsString('Sem comprovante', $itens->eq(1)->text());
        self::assertSame(1, $tela->filter('.voto-avaliacao-decisao')->count(), 'só a pendente tem o campo e os botões');
        self::assertSame('Justificativa da decisão', $tela->filter('input[name=justificativa]')->attr('placeholder'));
        self::assertSame(['Manter inaptidão', 'Tornar apto(a)'], $tela->filter('.voto-avaliacao-decisao button')->each(static fn ($b): string => trim($b->text())));

        $this->client->request('POST', '/voto/assembleias/' . $a->getId() . '/avaliacoes/' . $pendente->getId() . '/decidir', [
            '_token' => $this->token('voto_assembleia_' . $a->getId()), 'decisao' => 'alterar', 'justificativa' => 'Quitou, comprovante anexo',
        ]);
        self::assertResponseRedirects('/voto/assembleias/' . $a->getId() . '/avaliacoes');
        $this->em->clear();
        $decidida = $this->em->getRepository(OcorrenciaDaUnidade::class)->find($pendente->getId());
        self::assertSame(AvaliacaoDaOcorrencia::TornadaApta, $decidida->getAvaliacao());
        self::assertTrue($decidida->getUnidade()->isApta());
        $this->client->followRedirect();
        self::assertStringContainsString('Decisão registrada.', (string) $this->client->getResponse()->getContent());
        self::assertNotNull($mantida->getId());
    }

    #[TestDox('Decisão sem justificativa volta com "Informe a justificativa." e nada muda')]
    public function testSemJustificativa(): void
    {
        [$a, $conselho] = $this->assembleiaComConselho();
        $pendente = $this->ocorrencia($a, '102', AvaliacaoDaOcorrencia::Pendente, 'Quitou ontem');
        $this->entrar($conselho);

        $this->client->request('POST', '/voto/assembleias/' . $a->getId() . '/avaliacoes/' . $pendente->getId() . '/decidir', [
            '_token' => $this->token('voto_assembleia_' . $a->getId()), 'decisao' => 'manter', 'justificativa' => '  ',
        ]);
        $this->client->followRedirect();

        self::assertStringContainsString('Informe a justificativa.', (string) $this->client->getResponse()->getContent());
        $this->em->clear();
        self::assertTrue($this->em->getRepository(OcorrenciaDaUnidade::class)->find($pendente->getId())->pendente());
    }

    #[TestDox('Depois de uma pauta encerrada "Tornar apto(a)" aparece desabilitado e o POST dá a frase do desenho; "Manter" ainda vale')]
    public function testDepoisDoEncerramento(): void
    {
        $a = $this->assembleiaComUnidades('encerrada');
        $conselho = $this->colega();
        $this->membro($a, PapelNaEquipe::Conselho, $conselho);
        $pendente = $this->ocorrencia($a, '102', AvaliacaoDaOcorrencia::Pendente, 'Quitou ontem');
        $this->entrar($conselho);

        $tela = $this->client->request('GET', '/voto/assembleias/' . $a->getId() . '/avaliacoes');
        self::assertNotNull($tela->filter('button[value=alterar]')->attr('disabled'));
        self::assertNull($tela->filter('button[value=manter]')->attr('disabled'));

        $this->client->request('POST', '/voto/assembleias/' . $a->getId() . '/avaliacoes/' . $pendente->getId() . '/decidir', [
            '_token' => $this->token('voto_assembleia_' . $a->getId()), 'decisao' => 'alterar', 'justificativa' => 'Quitou',
        ]);
        $this->client->followRedirect();
        self::assertStringContainsString('Votação encerrada: a aptidão não pode mais ser alterada.', (string) $this->client->getResponse()->getContent());
    }

    /** @return iterable<string, array{PapelNaEquipe}> */
    public static function papeisQueSoLeemOuRegistram(): iterable
    {
        yield 'auditoria' => [PapelNaEquipe::Auditoria];
        yield 'fiscal' => [PapelNaEquipe::Fiscal];
        yield 'comissão' => [PapelNaEquipe::Comissao];
    }

    #[DataProvider('papeisQueSoLeemOuRegistram')]
    #[TestDox('$papel não decide: 403 na aba e na decisão, e a aba nem aparece')]
    public function testSoLeNaoDecide(PapelNaEquipe $papel): void
    {
        $a = $this->assembleiaComUnidades();
        $pessoa = $this->colega();
        $this->membro($a, $papel, $pessoa);
        $pendente = $this->ocorrencia($a, '102', AvaliacaoDaOcorrencia::Pendente, 'Quitou ontem');
        $this->entrar($pessoa);

        $this->client->request('GET', '/voto/assembleias/' . $a->getId() . '/avaliacoes');
        self::assertResponseStatusCodeSame(403);
        $tela = $this->client->request('GET', '/voto/assembleias/' . $a->getId() . '/unidades');
        self::assertResponseIsSuccessful();
        self::assertCount(0, $tela->filter('a.vTab[href$="/avaliacoes"]'), 'sem a aba Avaliações');

        $this->client->request('POST', '/voto/assembleias/' . $a->getId() . '/avaliacoes/' . $pendente->getId() . '/decidir', [
            '_token' => $this->token('voto_assembleia_' . $a->getId()), 'decisao' => 'manter', 'justificativa' => 'Invasão',
        ]);
        self::assertResponseStatusCodeSame(403);
        $this->em->clear();
        self::assertTrue($this->em->getRepository(OcorrenciaDaUnidade::class)->find($pendente->getId())->pendente());
    }

    #[TestDox('Sem equipe própria só a Administração decide: o colega com o módulo recebe 403 e não vê a aba; o primário decide')]
    public function testSemEquipeSoAAdministracao(): void
    {
        $a = $this->assembleiaComUnidades();
        $pendente = $this->ocorrencia($a, '102', AvaliacaoDaOcorrencia::Pendente, 'Quitou ontem');
        $this->entrar($this->colega());

        $this->client->request('GET', '/voto/assembleias/' . $a->getId() . '/avaliacoes');
        self::assertResponseStatusCodeSame(403);
        $tela = $this->client->request('GET', '/voto/assembleias/' . $a->getId() . '/unidades');
        self::assertCount(0, $tela->filter('a.vTab[href$="/avaliacoes"]'));
        $this->client->request('POST', '/voto/assembleias/' . $a->getId() . '/avaliacoes/' . $pendente->getId() . '/decidir', [
            '_token' => $this->token('voto_assembleia_' . $a->getId()), 'decisao' => 'manter', 'justificativa' => 'x',
        ]);
        self::assertResponseStatusCodeSame(403);

        $this->entrar($this->primario());
        $this->client->request('GET', '/voto/assembleias/' . $a->getId() . '/avaliacoes');
        self::assertResponseIsSuccessful();
        $this->client->request('POST', '/voto/assembleias/' . $a->getId() . '/avaliacoes/' . $pendente->getId() . '/decidir', [
            '_token' => $this->token('voto_assembleia_' . $a->getId()), 'decisao' => 'manter', 'justificativa' => 'Administração decide',
        ]);
        self::assertResponseRedirects();
    }

    #[TestDox('Quem está Oculto (fora da equipe) e quem a guarda do módulo recusa, mesmo como conselho, recebem 403 na aba e na decisão')]
    public function testOcultoESemModuloNaoDecidem(): void
    {
        $a = $this->assembleiaComUnidades();
        $this->membro($a, PapelNaEquipe::Comissao, $this->colega());
        $pendente = $this->ocorrencia($a, '102', AvaliacaoDaOcorrencia::Pendente, 'Quitou ontem');
        $semModulo = $this->colega(comModulo: false);
        $this->membro($a, PapelNaEquipe::Conselho, $semModulo);

        foreach ([$this->colega(), $semModulo] as $pessoa) {
            $this->entrar($pessoa);
            $this->client->request('GET', '/voto/assembleias/' . $a->getId() . '/avaliacoes');
            self::assertResponseStatusCodeSame(403);
            $this->client->request('POST', '/voto/assembleias/' . $a->getId() . '/avaliacoes/' . $pendente->getId() . '/decidir', [
                '_token' => $this->token('voto_assembleia_' . $a->getId()), 'decisao' => 'manter', 'justificativa' => 'x',
            ]);
            self::assertResponseStatusCodeSame(403);
        }
        $this->em->clear();
        self::assertTrue($this->em->getRepository(OcorrenciaDaUnidade::class)->find($pendente->getId())->pendente());
    }

    #[TestDox('O conselho não registra ocorrência: sem campo na Lista de aptos e 403 no POST; a comissão registra e vê o campo, mas não a aba')]
    public function testRegistroEBotoes(): void
    {
        [$a, $conselho] = $this->assembleiaComConselho();
        $comissao = $this->colega();
        $this->membro($a, PapelNaEquipe::Comissao, $comissao);
        $inapta = $this->unidadeDe($a, '102');

        $this->entrar($conselho);
        $tela = $this->client->request('GET', '/voto/assembleias/' . $a->getId() . '/unidades');
        self::assertResponseIsSuccessful();
        self::assertCount(0, $tela->filter('input[name=ocorrencia]'), 'conselho: nenhum campo');
        self::assertCount(1, $tela->filter('a.vTab[href$="/avaliacoes"]'), 'conselho: vê a aba');
        $this->client->request('POST', '/voto/assembleias/' . $a->getId() . '/unidades/' . $inapta->getId() . '/ocorrencia', [
            '_token' => $this->token('voto_assembleia_' . $a->getId()), 'ocorrencia' => 'Invasão',
        ]);
        self::assertResponseStatusCodeSame(403);
        self::assertSame(0, $this->contarOcorrencias($a));

        $this->entrar($comissao);
        $tela = $this->client->request('GET', '/voto/assembleias/' . $a->getId() . '/unidades');
        self::assertCount(3, $tela->filter('input[name=ocorrencia]'), 'comissão: um campo por unidade');
        self::assertSame('Ocorrência, ex.: cadastro desatualizado', $tela->filter('input[name=ocorrencia]')->first()->attr('placeholder'));
        self::assertSame('Registrar', trim($tela->filter('.voto-ocorrencia button')->first()->text()));
        self::assertSame('Procurar por nome ou unidade...', trim($tela->filter('label.voto-busca')->text()));
        self::assertCount(0, $tela->filter('a.vTab[href$="/avaliacoes"]'), 'comissão: sem a aba');

        $this->client->request('POST', '/voto/assembleias/' . $a->getId() . '/unidades/' . $inapta->getId() . '/ocorrencia', [
            '_token' => $this->token('voto_assembleia_' . $a->getId()), 'ocorrencia' => 'Quitou ontem',
        ]);
        self::assertResponseRedirects();
        $tela = $this->client->followRedirect();
        self::assertStringContainsString('Ocorrência registrada.', (string) $this->client->getResponse()->getContent());
        self::assertSame(1, $this->contarOcorrencias($a));
        self::assertStringContainsString('Avaliação pendente no conselho.', $tela->filter('#unidade-' . $inapta->getId())->text());
        self::assertCount(0, $tela->filter('#unidade-' . $inapta->getId() . ' input[name=ocorrencia]'), 'com avaliação pendente não há como registrar outra');
    }

    #[TestDox('Outro escritório dá 404 na aba, na decisão e no registro; ocorrência de outra assembleia do escritório também')]
    public function testOutroEscritorio(): void
    {
        $alheia = AssembleiaFactory::createOne()->_real();
        $alheiaUnidade = UnidadeDaAssembleiaFactory::createOne(['assembleia' => $alheia, 'identificacao' => '900'])->_real();
        $a = $this->assembleiaComUnidades();
        $outra = $this->assembleiaComUnidades();
        $deOutra = $this->ocorrencia($outra, '102', AvaliacaoDaOcorrencia::Pendente, 'De outra assembleia');
        $this->entrar($this->primario());

        $this->client->request('GET', '/voto/assembleias/' . $alheia->getId() . '/avaliacoes');
        self::assertResponseStatusCodeSame(404);
        $token = $this->token('voto_assembleia_' . $alheia->getId());
        $this->client->request('POST', '/voto/assembleias/' . $alheia->getId() . '/unidades/' . $alheiaUnidade->getId() . '/ocorrencia', ['_token' => $token, 'ocorrencia' => 'x']);
        self::assertResponseStatusCodeSame(404);
        $this->client->request('POST', '/voto/assembleias/' . $alheia->getId() . '/avaliacoes/1/decidir', ['_token' => $token, 'decisao' => 'manter', 'justificativa' => 'x']);
        self::assertResponseStatusCodeSame(404);

        $token = $this->token('voto_assembleia_' . $a->getId());
        $this->client->request('POST', '/voto/assembleias/' . $a->getId() . '/avaliacoes/' . $deOutra->getId() . '/decidir', ['_token' => $token, 'decisao' => 'manter', 'justificativa' => 'x']);
        self::assertResponseStatusCodeSame(404);
        $this->client->request('POST', '/voto/assembleias/' . $a->getId() . '/unidades/' . $alheiaUnidade->getId() . '/ocorrencia', ['_token' => $token, 'ocorrencia' => 'x']);
        self::assertResponseStatusCodeSame(404);
        $this->em->clear();
        self::assertTrue($this->em->getRepository(OcorrenciaDaUnidade::class)->find($deOutra->getId())->pendente());
    }

    #[TestDox('Lista de aptos: hash da lista e "N alteração(ões)" depois da trava; antes dela, nenhum dos dois')]
    public function testHashEContador(): void
    {
        $a = $this->assembleiaComUnidades();
        $this->entrar($this->primario());
        $tela = $this->client->request('GET', '/voto/assembleias/' . $a->getId() . '/unidades');
        self::assertCount(0, $tela->filter('[data-voto-hash]'));

        $a->getPautas()->first()->abrir('Mesa', 1, null, new \DateTimeImmutable('-30 minutes'));
        $this->em->flush();
        $hash = (string) $a->getHashDaLista();
        $tela = $this->client->request('GET', '/voto/assembleias/' . $a->getId() . '/unidades');
        self::assertSame('Hash da lista: ' . substr($hash, 0, 32), trim($tela->filter('[data-voto-hash]')->text()));
        self::assertCount(0, $tela->filter('[data-voto-alteracoes]'));

        $o = $this->ocorrencia($a, '102', AvaliacaoDaOcorrencia::Pendente, 'Quitou ontem');
        $this->client->request('POST', '/voto/assembleias/' . $a->getId() . '/avaliacoes/' . $o->getId() . '/decidir', [
            '_token' => $this->token('voto_assembleia_' . $a->getId()), 'decisao' => 'alterar', 'justificativa' => 'Quitou',
        ]);
        $tela = $this->client->request('GET', '/voto/assembleias/' . $a->getId() . '/unidades');
        self::assertSame('1 alteração(ões) por decisão do conselho.', trim($tela->filter('[data-voto-alteracoes]')->text()));
        self::assertSame('Hash da lista: ' . substr($hash, 0, 32), trim($tela->filter('[data-voto-hash]')->text()), 'a decisão não recalcula o hash');
    }

    #[TestDox('A cédula da unidade não apta mostra a ocorrência e "(em avaliação pelo conselho)"; decidida, só a ocorrência; a apta não vê nada')]
    public function testCedula(): void
    {
        $a = $this->assembleiaComUnidades('aberta');
        $apta = $this->unidadeDe($a, '101');
        $inapta = $this->unidadeDe($a, '102');
        $this->ocorrencia($a, '101', null, 'Cadastro desatualizado');
        $o = $this->ocorrencia($a, '102', AvaliacaoDaOcorrencia::Pendente, 'Quitou ontem');

        $this->client->request('GET', '/votar/' . UnidadeDaAssembleiaFactory::tokenDe($inapta));
        self::assertResponseIsSuccessful();
        self::assertStringContainsString('Ocorrência: Quitou ontem (em avaliação pelo conselho).', preg_replace('/\s+/', ' ', (string) $this->client->getResponse()->getContent()));

        $this->em->find(OcorrenciaDaUnidade::class, $o->getId())->decidir(AvaliacaoDaOcorrencia::Mantida, 'Sem comprovante', 'Conselho');
        $this->em->flush();
        $this->client->request('GET', '/votar/' . UnidadeDaAssembleiaFactory::tokenDe($inapta));
        $html = preg_replace('/\s+/', ' ', (string) $this->client->getResponse()->getContent());
        self::assertStringContainsString('Ocorrência: Quitou ontem.', $html);
        self::assertStringNotContainsString('em avaliação pelo conselho', $html);

        $this->client->request('GET', '/votar/' . UnidadeDaAssembleiaFactory::tokenDe($apta));
        self::assertStringNotContainsString('Cadastro desatualizado', (string) $this->client->getResponse()->getContent(), 'a apta não vê a ocorrência na cédula');
    }

    #[TestDox('A ata traz "Ocorrências e avaliações de aptidão" só se houver ocorrência com avaliação, e só para quem pode ver')]
    public function testAta(): void
    {
        [$a, $conselho] = $this->assembleiaComConselho();
        $admin = $this->primario();
        $this->entrar($admin);
        $this->client->request('GET', '/voto/assembleias/' . $a->getId() . '/ata');
        self::assertStringNotContainsString('Ocorrências e avaliações de aptidão', (string) $this->client->getResponse()->getContent());

        $o = $this->ocorrencia($a, '102', AvaliacaoDaOcorrencia::Pendente, 'Quitou ontem');
        $this->client->request('GET', '/voto/assembleias/' . $a->getId() . '/ata');
        $html = preg_replace('/\s+/', ' ', (string) $this->client->getResponse()->getContent());
        self::assertStringContainsString('Ocorrências e avaliações de aptidão', $html);
        self::assertStringContainsString('Beto Inapto · 102 · A (' . $a->getTitulo() . '). Ocorrência em', $html);
        self::assertStringContainsString('Quitou ontem. Avaliação pendente.', $html);

        $this->em->find(OcorrenciaDaUnidade::class, $o->getId())->decidir(AvaliacaoDaOcorrencia::TornadaApta, 'Quitou', 'Conselho');
        $this->em->flush();
        $this->client->request('GET', '/voto/assembleias/' . $a->getId() . '/ata');
        self::assertStringContainsString('Tornado apto em', preg_replace('/\s+/', ' ', (string) $this->client->getResponse()->getContent()));

        $this->entrar($conselho);
        $this->client->request('GET', '/voto/assembleias/' . $a->getId() . '/ata');
        self::assertStringContainsString('Ocorrências e avaliações de aptidão', (string) $this->client->getResponse()->getContent(), 'o conselho vê a seção');
    }

    /** @return array{0: Assembleia, 1: User} */
    private function assembleiaComConselho(): array
    {
        $a = $this->assembleiaComUnidades();
        UnidadeDaAssembleiaFactory::new(['assembleia' => $a, 'identificacao' => '103', 'responsavel' => 'Carla Inapta'])->inapta()->create();
        $this->em->refresh($a);
        $conselho = $this->colega();
        $this->membro($a, PapelNaEquipe::Conselho, $conselho);

        return [$a, $conselho];
    }

    private function ocorrencia(Assembleia $a, string $identificacao, ?AvaliacaoDaOcorrencia $avaliacao, string $texto): OcorrenciaDaUnidade
    {
        // Os pedidos do cliente limpam o EntityManager: a assembleia volta a ser uma entidade gerenciada.
        $a = $this->em->find(Assembleia::class, $a->getId());
        $o = new OcorrenciaDaUnidade($a, $this->unidadeDe($a, $identificacao), $texto, OrigemDaOcorrencia::Manual, 'Comissão X', AvaliacaoDaOcorrencia::Pendente === $avaliacao);
        if (AvaliacaoDaOcorrencia::Mantida === $avaliacao) {
            $o = new OcorrenciaDaUnidade($a, $this->unidadeDe($a, $identificacao), $texto, OrigemDaOcorrencia::Manual, 'Comissão X', true);
            $o->decidir(AvaliacaoDaOcorrencia::Mantida, 'Sem comprovante', 'Conselho');
        }
        $this->em->persist($o);
        $this->em->flush();

        return $o;
    }

    private function contarOcorrencias(Assembleia $a): int
    {
        return (int) $this->em->getConnection()->fetchOne('SELECT COUNT(*) FROM voto.ocorrencia_da_unidade WHERE assembleia_id = ?', [$a->getId()]);
    }

    private function entrar(User $user): void
    {
        $this->logarComTenant($this->client, $user, $this->escritorio);
    }

    private function token(string $id): string
    {
        $this->client->request('GET', '/voto/assembleias/nova');
        $sessao = $this->client->getRequest()->getSession();
        $sessao->set('_csrf/' . $id, 'token-de-teste-' . $id);
        $sessao->save();

        return 'token-de-teste-' . $id;
    }
}
