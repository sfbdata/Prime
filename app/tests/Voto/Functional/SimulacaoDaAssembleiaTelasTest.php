<?php

declare(strict_types=1);

namespace App\Tests\Voto\Functional;

use App\Entity\Auth\User;
use App\Entity\Auth\UserTenant;
use App\Entity\Tenant\Tenant;
use App\Entity\Tenant\TenantRole;
use App\Master\Entity\RecursoEstado;
use App\Tests\Factory\Auth\UserFactory;
use App\Tests\Factory\Tenant\TenantFactory;
use App\Tests\Factory\Voto\AssembleiaFactory;
use App\Tests\Factory\Voto\ParticipacaoNaPautaFactory;
use App\Tests\Factory\Voto\PautaDaAssembleiaFactory;
use App\Tests\Factory\Voto\UnidadeDaAssembleiaFactory;
use App\Tests\Functional\JusPrimeWebTestCase;
use App\Voto\Controller\CicloDaPautaController;
use App\Voto\Entity\Assembleia;
use App\Voto\Entity\LiberacaoDoVoto;
use App\Voto\Entity\PautaDaAssembleia;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\TestDox;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Component\DomCrawler\Crawler;
use Zenstruck\Foundry\Test\Factories;

/**
 * Votação de simulação nas telas (W2-4, desenho 18): a marca no formulário, o menu (Reiniciar e Apagar), reiniciar e
 * apagar de verdade no banco, a recusa em votação real, o 404 de outro escritório, os KPIs sem a simulação, a seleção em
 * lote e os selos (conferência e cédula).
 */
#[CoversClass(CicloDaPautaController::class)]
#[Group('voto')]
final class SimulacaoDaAssembleiaTelasTest extends JusPrimeWebTestCase
{
    use Factories;

    private KernelBrowser $client;
    private EntityManagerInterface $em;
    private Tenant $escritorio;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $this->client->disableReboot();
        $this->em = static::getContainer()->get(EntityManagerInterface::class);
        $this->escritorio = TenantFactory::createOne(['name' => 'Condomínio do Treino ' . uniqid()])->_real();
        $estado = $this->em->getRepository(RecursoEstado::class)->findOneBy(['chave' => 'voto']);
        if (null !== $estado) {
            $estado->reativar(null, new \DateTimeImmutable());
        }
        $liberacao = new LiberacaoDoVoto($this->escritorio);
        $liberacao->liberar();
        $this->em->persist($liberacao);
        $this->em->flush();
        $this->logarComTenant($this->client, $this->usuario(), $this->escritorio);
    }

    #[TestDox('Formulário: a caixa "Votação de simulação" vem desmarcada, o texto é o do desenho e salvar grava a marca')]
    public function testFormularioMarcaESalva(): void
    {
        $tela = $this->client->request('GET', '/voto/assembleias/nova');
        self::assertResponseIsSuccessful();
        $caixa = $tela->filter('.vG2 > label.voto-simulacao > input[type="checkbox"][name="simulacao"]');
        self::assertCount(1, $caixa);
        self::assertNull($caixa->attr('checked'));
        self::assertSame(
            'Votação de simulação: para treinar a mesa e testar o link. Pode ser reiniciada e apagada depois; não entra nas estatísticas.',
            trim($tela->filter('label.voto-simulacao')->text()),
        );

        $this->client->request('POST', '/voto/assembleias/nova', ['_token' => $this->tokenValido('voto_assembleia_nova'), 'titulo' => 'Treino da mesa', 'peso' => 'unidade', 'simulacao' => '1']);
        $id = (int) $this->em->getConnection()->fetchOne("SELECT id FROM voto.assembleia WHERE titulo = 'Treino da mesa'");
        self::assertTrue((bool) $this->coluna('voto.assembleia', 'simulacao', $id));

        $editar = $this->client->request('GET', '/voto/assembleias/' . $id . '/editar');
        self::assertCount(1, $editar->filter('input[name="simulacao"][checked]'));
        $this->client->request('POST', '/voto/assembleias/' . $id . '/editar', ['_token' => $this->tokenValido('voto_assembleia_' . $id), 'titulo' => 'Treino da mesa', 'peso' => 'unidade']);
        self::assertFalse((bool) $this->coluna('voto.assembleia', 'simulacao', $id), 'sem a caixa marcada, vira real (ainda em rascunho)');
    }

    #[TestDox('Formulário: assembleia publicada não muda a marca (recusa e mantém)')]
    public function testPublicadaNaoMudaAMarca(): void
    {
        $publicada = AssembleiaFactory::new(['tenant' => $this->escritorio, 'titulo' => 'Real publicada'])->publicada()->create()->_real();

        $this->client->request('POST', '/voto/assembleias/' . $publicada->getId() . '/editar', ['_token' => $this->tokenValido('voto_assembleia_' . $publicada->getId()), 'titulo' => 'Real publicada', 'peso' => 'unidade', 'simulacao' => '1']);

        self::assertFalse((bool) $this->coluna('voto.assembleia', 'simulacao', (int) $publicada->getId()));
    }

    #[TestDox('Menu: a simulação tem Reiniciar e Apagar (vermelho, abre a faixa do desenho) e não tem Arquivar; a real não tem os dois')]
    public function testMenuDaSimulacao(): void
    {
        [$simulacao] = $this->simulacaoPublicada();
        $real = $this->realPublicada();

        $tela = $this->painel();

        $menu = static fn (Assembleia $a): Crawler => $tela->filter('#assembleia-' . $a->getId() . ' .vEvH .vKm');
        $rotulos = $menu($simulacao)->filter('[role="menuitem"]')->each(static fn (Crawler $c): string => trim($c->text()));
        self::assertContains('Reiniciar simulação', $rotulos);
        self::assertContains('Apagar simulação', $rotulos);
        self::assertNotContains('Arquivar', $rotulos);
        self::assertStringEndsWith('/simulacao/reiniciar', (string) $menu($simulacao)->filter('form')->reduce(static fn (Crawler $f): bool => str_contains(trim($f->text()), 'Reiniciar simulação'))->attr('action'));
        self::assertCount(1, $menu($simulacao)->filter('button.vKd[data-voto-revelar="#apagar-simulacao-' . $simulacao->getId() . '"]'));
        $faixa = $tela->filter('#apagar-simulacao-' . $simulacao->getId() . '[data-voto-revelavel][hidden] form');
        self::assertStringEndsWith('/simulacao/apagar', (string) $faixa->attr('action'));
        self::assertSame('Apagar esta simulação e todos os votos de treino?', trim($faixa->filter('b')->text()));
        self::assertSame(['Manter', 'Confirmar exclusão'], $faixa->filter('button')->each(static fn (Crawler $c): string => trim($c->text())));

        $rotulosReal = $menu($real)->filter('[role="menuitem"]')->each(static fn (Crawler $c): string => trim($c->text()));
        self::assertNotContains('Reiniciar simulação', $rotulosReal);
        self::assertNotContains('Apagar simulação', $rotulosReal);
        self::assertContains('Arquivar', $rotulosReal);
        self::assertCount(0, $tela->filter('#apagar-simulacao-' . $real->getId()));
    }

    #[TestDox('Reiniciar: apaga participações, contadores e nulos, volta a rascunho e a pauta a aguardando; a configuração fica')]
    public function testReiniciar(): void
    {
        [$simulacao, $pauta, $unidade] = $this->simulacaoPublicada();
        $this->votarNoBanco($pauta, $unidade);

        $this->client->request('POST', '/voto/assembleias/' . $simulacao->getId() . '/simulacao/reiniciar', ['_token' => $this->tokenValido('voto_assembleia_' . $simulacao->getId())]);

        self::assertResponseRedirects('/voto?aba=ges#assembleia-' . $simulacao->getId());
        self::assertSame('Simulação reiniciada: votos apagados, de volta a rascunho.', $this->avisoDoPainel());
        $c = $this->em->getConnection();
        self::assertSame('rascunho', $this->coluna('voto.assembleia', 'estado', (int) $simulacao->getId()));
        self::assertNull($this->coluna('voto.assembleia', 'publicada_em', (int) $simulacao->getId()));
        self::assertTrue((bool) $this->coluna('voto.assembleia', 'simulacao', (int) $simulacao->getId()));
        self::assertSame('aguardando', $this->coluna('voto.assembleia_pauta', 'estado', (int) $pauta->getId()));
        self::assertNull($this->coluna('voto.assembleia_pauta', 'aberta_em', (int) $pauta->getId()));
        self::assertSame(0, (int) $this->coluna('voto.assembleia_pauta', 'nulos_unidades', (int) $pauta->getId()));
        self::assertSame(0, (int) $c->fetchOne('SELECT COUNT(*) FROM voto.assembleia_participacao WHERE pauta_id = ?', [$pauta->getId()]));
        self::assertSame(0, (int) $c->fetchOne('SELECT COALESCE(SUM(total_unidades), 0) FROM voto.assembleia_opcao WHERE pauta_id = ?', [$pauta->getId()]));
        self::assertSame(3, (int) $c->fetchOne('SELECT COUNT(*) FROM voto.assembleia_opcao WHERE pauta_id = ?', [$pauta->getId()]), 'as opções ficam');
        self::assertSame(1, (int) $c->fetchOne('SELECT COUNT(*) FROM voto.assembleia_unidade WHERE assembleia_id = ?', [$simulacao->getId()]), 'a lista fica');
    }

    #[TestDox('Reiniciar e apagar a votação REAL: recusa com aviso e nenhum voto, participação ou linha é apagado')]
    public function testRealNuncaPerdeVoto(): void
    {
        $real = $this->realPublicada();
        $pauta = PautaDaAssembleiaFactory::new(['assembleia' => $real, 'ordem' => 1])->comOpcoesPadrao()->aberta()->create()->_real();
        $unidade = UnidadeDaAssembleiaFactory::new(['assembleia' => $real])->create()->_real();
        $this->votarNoBanco($pauta, $unidade);
        $c = $this->em->getConnection();

        foreach (['reiniciar' => 'Só votação de simulação pode ser reiniciada.', 'apagar' => 'Só votação de simulação pode ser apagada.'] as $acao => $mensagem) {
            $this->client->request('POST', '/voto/assembleias/' . $real->getId() . '/simulacao/' . $acao, ['_token' => $this->tokenValido('voto_assembleia_' . $real->getId())]);
            self::assertSame($mensagem, $this->avisoDoPainel(), $acao);
        }

        self::assertSame('publicada', $this->coluna('voto.assembleia', 'estado', (int) $real->getId()));
        self::assertSame('aberta', $this->coluna('voto.assembleia_pauta', 'estado', (int) $pauta->getId()));
        self::assertSame(1, (int) $c->fetchOne('SELECT COUNT(*) FROM voto.assembleia_participacao WHERE pauta_id = ?', [$pauta->getId()]));
        self::assertSame(1, (int) $c->fetchOne('SELECT SUM(total_unidades) FROM voto.assembleia_opcao WHERE pauta_id = ?', [$pauta->getId()]));
        self::assertSame(2, (int) $this->coluna('voto.assembleia_pauta', 'nulos_unidades', (int) $pauta->getId()));
    }

    #[TestDox('Apagar: a simulação sai com pautas, unidades e votos; só a dela')]
    public function testApagar(): void
    {
        [$simulacao, $pauta, $unidade] = $this->simulacaoPublicada();
        $this->votarNoBanco($pauta, $unidade);
        $outra = $this->realPublicada();
        $simulacaoId = (int) $simulacao->getId();
        $pautaId = (int) $pauta->getId();

        $this->client->request('POST', '/voto/assembleias/' . $simulacaoId . '/simulacao/apagar', ['_token' => $this->tokenValido('voto_assembleia_' . $simulacaoId)]);

        self::assertSame('Simulação apagada.', $this->avisoDoPainel());
        $c = $this->em->getConnection();
        self::assertNull($this->coluna('voto.assembleia', 'id', $simulacaoId));
        self::assertSame(0, (int) $c->fetchOne('SELECT COUNT(*) FROM voto.assembleia_pauta WHERE id = ?', [$pautaId]));
        self::assertSame(0, (int) $c->fetchOne('SELECT COUNT(*) FROM voto.assembleia_participacao WHERE pauta_id = ?', [$pautaId]));
        self::assertNotNull($this->coluna('voto.assembleia', 'id', (int) $outra->getId()));
    }

    #[TestDox('Outro escritório: reiniciar e apagar dão 404 e nada muda; CSRF inválido não faz nada')]
    public function testOutroEscritorioECsrf(): void
    {
        $alheia = AssembleiaFactory::new(['simulacao' => true])->publicada()->create()->_real();
        $alheiaId = (int) $alheia->getId();
        [$minha] = $this->simulacaoPublicada();

        foreach (['reiniciar', 'apagar'] as $acao) {
            $this->client->request('POST', '/voto/assembleias/' . $alheiaId . '/simulacao/' . $acao, ['_token' => $this->tokenValido('voto_assembleia_' . $alheiaId)]);
            self::assertResponseStatusCodeSame(404, $acao);

            $this->client->request('POST', '/voto/assembleias/' . $minha->getId() . '/simulacao/' . $acao, ['_token' => 'invalido']);
            self::assertSame('A sessão expirou. Recarregue a página e tente de novo.', $this->avisoDoPainel(), $acao);
        }
        self::assertSame('publicada', $this->coluna('voto.assembleia', 'estado', $alheiaId));
        self::assertSame('publicada', $this->coluna('voto.assembleia', 'estado', (int) $minha->getId()));
    }

    #[TestDox('KPIs do painel: a simulação aberta não conta em "Votações abertas" nem em "Cédulas depositadas"')]
    public function testKpisIgnoramSimulacao(): void
    {
        [$simulacao, $pautaSim, $unidadeSim] = $this->simulacaoPublicada();
        $this->votarNoBanco($pautaSim, $unidadeSim);
        $real = $this->realPublicada();
        $pautaReal = PautaDaAssembleiaFactory::new(['assembleia' => $real, 'ordem' => 1, 'titulo' => 'Pauta real'])->comOpcoesPadrao()->aberta()->create()->_real();
        $this->em->refresh($real);

        $tela = $this->painel('&kpi=abe');

        $kpis = $tela->filter('.voto-painel > .vKpis > a.vKpiB');
        self::assertSame('1', trim($kpis->eq(0)->filter('b')->text()));
        self::assertSame('0', trim($kpis->eq(1)->filter('b')->text()));
        self::assertSame(['Pauta real'], $tela->filter('[data-voto-kpi-lista="abe"] .voto-kpi-linha > b')->each(static fn (Crawler $b): string => trim($b->text())));
        self::assertNotNull($pautaReal->getId());
        self::assertNotNull($simulacao->getId());
    }

    #[TestDox('Lote: a simulação publicada é excluída junto do rascunho; a real publicada fica nas mantidas')]
    public function testLoteExcluiSimulacao(): void
    {
        [$simulacao] = $this->simulacaoPublicada();
        $real = $this->realPublicada();
        $simulacaoId = (int) $simulacao->getId();

        $tela = $this->painel();
        self::assertSame('1', $tela->filter('#assembleia-' . $simulacaoId . ' input.vSel')->attr('data-excluir'));
        self::assertSame('0', $tela->filter('#assembleia-' . $simulacaoId . ' input.vSel')->attr('data-arquivar'), 'simulação não se arquiva');
        self::assertSame('0', $tela->filter('#assembleia-' . $real->getId() . ' input.vSel')->attr('data-excluir'));

        $this->client->request('POST', '/voto/assembleias/lote/excluir', ['_token' => $this->tokenValido('voto_assembleia_lote'), 'ids' => [$simulacaoId, $real->getId()]]);

        self::assertSame('1 item(ns) excluído(s). 1 selecionada(s) não se encaixam e foram mantidas como estão.', $this->avisoDoPainel());
        self::assertNull($this->coluna('voto.assembleia', 'id', $simulacaoId));
        self::assertNotNull($this->coluna('voto.assembleia', 'id', (int) $real->getId()));
    }

    #[TestDox('Selos: "SIMULAÇÃO" na linha de modalidade da aba Votar e a faixa de treino na cabine do voto de verdade')]
    public function testSelos(): void
    {
        [$simulacao, $pauta, $unidade] = $this->simulacaoPublicada();
        $real = $this->realPublicada();
        $pautaReal = PautaDaAssembleiaFactory::new(['assembleia' => $real, 'ordem' => 1])->comOpcoesPadrao()->aberta()->create()->_real();
        $this->em->refresh($real);

        $votar = $this->client->request('GET', '/voto?aba=ele');
        self::assertStringContainsString('SIMULAÇÃO', trim($votar->filter('[data-pauta="' . $pauta->getId() . '"] .voto-votar-meta')->text()));
        self::assertStringNotContainsString('SIMULAÇÃO', trim($votar->filter('[data-pauta="' . $pautaReal->getId() . '"] .voto-votar-meta')->text()));

        $cedula = $this->client->request('GET', '/votar/' . UnidadeDaAssembleiaFactory::tokenDe($unidade));
        self::assertResponseIsSuccessful();
        self::assertSame('SIMULAÇÃO: votação de treino, sem validade', trim($cedula->filter('.uBody > .uFaixa[data-u-faixa-treino][hidden]')->text()));
        $dados = json_decode((string) $cedula->filter('[data-vc-cabine-dados]')->attr('data-vc-cabine-dados'), true, 512, \JSON_THROW_ON_ERROR);
        self::assertTrue($dados['treino']);
        self::assertNotNull($simulacao->getId());
    }

    #[TestDox('Conferência da publicação: a simulação traz a linha "Tipo: Simulação (pode ser apagada)" que leva ao passo 1')]
    public function testConferenciaMostraOTipo(): void
    {
        $rascunho = AssembleiaFactory::new(['tenant' => $this->escritorio, 'titulo' => 'Treino', 'simulacao' => true])->create()->_real();
        $pauta = PautaDaAssembleiaFactory::new(['assembleia' => $rascunho, 'ordem' => 1])->comOpcoesPadrao()->create()->_real();
        UnidadeDaAssembleiaFactory::new(['assembleia' => $rascunho])->create();
        $this->em->refresh($rascunho);

        $tela = $this->client->request('GET', '/voto/assembleias/' . $rascunho->getId() . '/publicar');

        self::assertResponseIsSuccessful();
        $linha = $tela->filter('.voto-revisao-linha')->reduce(static fn (Crawler $l): bool => str_contains($l->text(), 'Simulação (pode ser apagada)'));
        self::assertCount(1, $linha);
        self::assertStringEndsWith('/editar', (string) $linha->filter('a')->attr('href'));
        self::assertNotNull($pauta->getId());
    }

    /** @return array{Assembleia, PautaDaAssembleia, \App\Voto\Entity\UnidadeDaAssembleia} */
    private function simulacaoPublicada(): array
    {
        $simulacao = AssembleiaFactory::new(['tenant' => $this->escritorio, 'titulo' => 'Treino ' . uniqid()])->simulacao()->publicada()->create()->_real();
        $pauta = PautaDaAssembleiaFactory::new(['assembleia' => $simulacao, 'ordem' => 1, 'titulo' => 'Pauta de treino'])->comOpcoesPadrao()->aberta()->create()->_real();
        $unidade = UnidadeDaAssembleiaFactory::new(['assembleia' => $simulacao])->create()->_real();
        $this->em->refresh($simulacao);

        return [$simulacao, $pauta, $unidade];
    }

    private function realPublicada(): Assembleia
    {
        return AssembleiaFactory::new(['tenant' => $this->escritorio, 'titulo' => 'Real ' . uniqid()])->publicada()->create()->_real();
    }

    /** Um voto de verdade no banco: participação, contador da opção "Sim" e dois nulos, como o RegistrarVotoUseCase deixa. */
    private function votarNoBanco(PautaDaAssembleia $pauta, \App\Voto\Entity\UnidadeDaAssembleia $unidade): void
    {
        ParticipacaoNaPautaFactory::createOne(['unidade' => $unidade, 'pauta' => $pauta]);
        $c = $this->em->getConnection();
        $c->executeStatement('UPDATE voto.assembleia_opcao SET total_unidades = 1, total_peso = 1 WHERE pauta_id = ? AND numero = 1', [$pauta->getId()]);
        $c->executeStatement('UPDATE voto.assembleia_pauta SET nulos_unidades = 2, nulos_peso = 2 WHERE id = ?', [$pauta->getId()]);
    }

    private function painel(string $consulta = ''): Crawler
    {
        $tela = $this->client->request('GET', '/voto?aba=ges' . $consulta);
        self::assertResponseIsSuccessful();

        return $tela;
    }

    private function avisoDoPainel(): string
    {
        if (!$this->client->getResponse()->isRedirect()) {
            return '';
        }
        $tela = $this->client->followRedirect();

        return trim($tela->filter('.voto-toast')->count() > 0 ? $tela->filter('.voto-toast')->first()->text() : '');
    }

    private function coluna(string $tabela, string $coluna, int $id): mixed
    {
        $this->em->clear();
        $valor = $this->em->getConnection()->fetchOne('SELECT ' . $coluna . ' FROM ' . $tabela . ' WHERE id = ?', [$id]);

        return false === $valor ? null : $valor;
    }

    private function tokenValido(string $id): string
    {
        $this->client->request('GET', '/voto?aba=ele');
        $sessao = $this->client->getRequest()->getSession();
        $sessao->set('_csrf/' . $id, 'token-de-teste-' . $id);
        $sessao->save();

        return 'token-de-teste-' . $id;
    }

    private function usuario(): User
    {
        $user = UserFactory::createOne(['fullName' => 'Síndica Teste', 'roles' => ['ROLE_USER']])->_real();
        $papel = (new TenantRole())->setTenant($this->escritorio)->setName('Perfil do Voto ' . uniqid())->setIsSystem(true);
        $this->em->persist($papel);
        $this->em->persist((new UserTenant($user, $this->escritorio))->setTenantRole($papel));
        $this->em->flush();

        return $user;
    }
}
