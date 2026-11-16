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
use App\Tests\Factory\Voto\PautaDaAssembleiaFactory;
use App\Tests\Factory\Voto\UnidadeDaAssembleiaFactory;
use App\Tests\Functional\JusPrimeWebTestCase;
use App\Voto\Controller\CicloDaPautaController;
use App\Voto\Entity\Assembleia;
use App\Voto\Entity\LiberacaoDoVoto;
use App\Voto\Entity\PautaDaAssembleia;
use App\Voto\Entity\UnidadeDaAssembleia;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\TestDox;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Zenstruck\Foundry\Test\Factories;

/**
 * Suspender, retomar, cancelar, retirar (por pauta) e arquivar, desarquivar, excluir rascunho (por assembleia), pela
 * tela: POST com CSRF muda o banco e volta ao painel com o aviso do desenho; regra violada avisa sem mudar; token
 * inválido não faz nada; assembleia de outro escritório dá 404. No lado do eleitor, a pauta suspensa, cancelada ou
 * retirada não aceita voto e a cédula mostra o estado.
 */
#[CoversClass(CicloDaPautaController::class)]
#[Group('voto')]
final class CicloDaPautaControllerTest extends JusPrimeWebTestCase
{
    use Factories;

    private KernelBrowser $client;
    private EntityManagerInterface $em;
    private Tenant $escritorio;
    private Assembleia $assembleia;
    private PautaDaAssembleia $pauta;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $this->client->disableReboot();
        $this->em = static::getContainer()->get(EntityManagerInterface::class);
        $this->escritorio = TenantFactory::createOne(['name' => 'Condomínio do Ciclo ' . uniqid()])->_real();
        $estado = $this->em->getRepository(RecursoEstado::class)->findOneBy(['chave' => 'voto']);
        if (null !== $estado) {
            $estado->reativar(null, new \DateTimeImmutable());
        }
        $liberacao = new LiberacaoDoVoto($this->escritorio);
        $liberacao->liberar();
        $this->em->persist($liberacao);
        $this->em->flush();
        $this->logarComTenant($this->client, $this->usuario(), $this->escritorio);
        $this->assembleia = AssembleiaFactory::new(['tenant' => $this->escritorio, 'titulo' => 'AGO 2026'])->publicada()->create()->_real();
        $this->pauta = PautaDaAssembleiaFactory::new(['assembleia' => $this->assembleia])->comOpcoesPadrao()->aberta()->create()->_real();
    }

    #[TestDox('Suspender: grava quando, motivo e quem, volta ao painel com "Votação suspensa. Retome quando a mesa decidir."')]
    public function testSuspender(): void
    {
        $this->postar('pautas/' . $this->pauta->getId() . '/suspender', ['motivo' => 'Queda de energia no salão']);

        self::assertResponseRedirects('/voto?aba=ges#assembleia-' . $this->assembleia->getId());
        $linha = $this->pautaNoBanco();
        self::assertSame('Queda de energia no salão', $linha['suspensa_motivo']);
        self::assertSame('Síndica Teste', $linha['suspensa_por_nome']);
        self::assertNotNull($linha['suspensa_em']);
        self::assertSame('Votação suspensa. Retome quando a mesa decidir.', $this->avisoDoPainel());
    }

    #[TestDox('Suspender com motivo curto: avisa "Informe o motivo da suspensão." e nada muda')]
    public function testSuspenderSemMotivo(): void
    {
        $this->postar('pautas/' . $this->pauta->getId() . '/suspender', ['motivo' => 'abc']);

        self::assertNull($this->pautaNoBanco()['suspensa_em']);
        self::assertSame('Informe o motivo da suspensão.', $this->avisoDoPainel());
    }

    #[TestDox('Retomar: limpa a suspensão e avisa "Votação retomada."; sem estar suspensa, avisa a regra')]
    public function testRetomar(): void
    {
        $this->postar('pautas/' . $this->pauta->getId() . '/retomar');
        self::assertSame('A votação não está suspensa.', $this->avisoDoPainel());

        $this->postar('pautas/' . $this->pauta->getId() . '/suspender', ['motivo' => 'Queda de energia']);
        $this->postar('pautas/' . $this->pauta->getId() . '/retomar');

        self::assertNull($this->pautaNoBanco()['suspensa_em']);
        self::assertSame('Votação retomada.', $this->avisoDoPainel());
    }

    #[TestDox('Cancelar a pauta aberta: encerra, guarda o cancelamento e avisa que os votos ficam lacrados')]
    public function testCancelar(): void
    {
        $this->postar('pautas/' . $this->pauta->getId() . '/cancelar', ['motivo' => 'Convocação irregular']);

        $linha = $this->pautaNoBanco();
        self::assertSame('encerrada', $linha['estado']);
        self::assertSame('Convocação irregular', $linha['cancelada_motivo']);
        self::assertSame('Votação cancelada: Convocação irregular', $linha['motivo_encerramento']);
        self::assertSame('Síndica Teste', $linha['cancelada_por_nome']);
        self::assertSame('Votação cancelada. Os votos ficam lacrados, sem apuração.', $this->avisoDoPainel());
    }

    #[TestDox('Retirar a pauta que não abriu: guarda a retirada; a aberta é recusada com "A votação já está aberta: suspenda ou cancele."')]
    public function testRetirar(): void
    {
        $aguardando = PautaDaAssembleiaFactory::new(['assembleia' => $this->assembleia, 'ordem' => 2, 'titulo' => 'Orçamento'])->comOpcoesPadrao()->create()->_real();

        $this->postar('pautas/' . $this->pauta->getId() . '/retirar', ['motivo' => 'Matéria adiada']);
        self::assertSame('A votação já está aberta: suspenda ou cancele.', $this->avisoDoPainel());

        $this->postar('pautas/' . $aguardando->getId() . '/retirar', ['motivo' => 'Matéria adiada']);
        $linha = $this->em->getConnection()->fetchAssociative('SELECT * FROM voto.assembleia_pauta WHERE id = ?', [$aguardando->getId()]);
        self::assertIsArray($linha);
        self::assertSame('Matéria adiada', $linha['retirada_motivo']);
        self::assertNotNull($linha['retirada_em']);
        self::assertSame('Pauta retirada. Desfaça em Gestão eleitoral, Mostrar arquivadas.', $this->avisoDoPainel());

        $this->postar('pautas/' . $aguardando->getId() . '/devolver');
        $linha = $this->em->getConnection()->fetchAssociative('SELECT * FROM voto.assembleia_pauta WHERE id = ?', [$aguardando->getId()]);
        self::assertIsArray($linha);
        self::assertNull($linha['retirada_em']);
        self::assertNull($linha['retirada_motivo']);
        self::assertNull($linha['retirada_por_nome']);
        self::assertSame('aguardando', $linha['estado']);
        self::assertSame('Votação desarquivada.', $this->avisoDoPainel());

        $this->postar('pautas/' . $aguardando->getId() . '/devolver');
        self::assertSame('A pauta não está retirada.', $this->avisoDoPainel());
    }

    #[TestDox('Cancelar a pauta encerrada: avisa "Votação já apurada..." e nada muda')]
    public function testCancelarEncerradaRecusa(): void
    {
        $this->postar('pautas/' . $this->pauta->getId() . '/encerrar', ['motivo' => '']);
        $this->postar('pautas/' . $this->pauta->getId() . '/cancelar', ['motivo' => 'Ata contestada']);

        self::assertSame('Votação já apurada não pode ser cancelada: arquive se quiser tirá-la das listas.', $this->avisoDoPainel());
        self::assertNull($this->pautaNoBanco()['cancelada_em']);
    }

    #[TestDox('Arquivar e desarquivar: a assembleia guarda e devolve; com pauta aberta, avisa "Encerre a votação antes de arquivar."')]
    public function testArquivarEDesarquivar(): void
    {
        $this->postar('arquivar', ['motivo' => 'Assembleia concluída']);
        self::assertSame('Encerre a votação antes de arquivar.', $this->avisoDoPainel());
        self::assertNull($this->assembleiaNoBanco()['arquivada_em']);

        $this->postar('pautas/' . $this->pauta->getId() . '/encerrar', ['motivo' => '']);
        $this->postar('arquivar', ['motivo' => 'Assembleia concluída']);

        $linha = $this->assembleiaNoBanco();
        self::assertSame('Assembleia concluída', $linha['arquivada_motivo']);
        self::assertSame('Síndica Teste', $linha['arquivada_por_nome']);
        self::assertSame('Votação arquivada. Use Mostrar arquivadas para vê-la.', $this->avisoDoPainel());

        $this->postar('desarquivar');

        self::assertNull($this->assembleiaNoBanco()['arquivada_em']);
        self::assertSame('Votação desarquivada.', $this->avisoDoPainel());
    }

    #[TestDox('Excluir: apaga o rascunho nunca publicado com as pautas e volta ao painel; a publicada é recusada e fica')]
    public function testExcluirRascunho(): void
    {
        $rascunho = AssembleiaFactory::new(['tenant' => $this->escritorio, 'titulo' => 'Rascunho'])->create()->_real();
        $rascunhoId = $rascunho->getId();
        PautaDaAssembleiaFactory::new(['assembleia' => $rascunho])->comOpcoesPadrao()->create();
        UnidadeDaAssembleiaFactory::createOne(['assembleia' => $rascunho]);

        $this->postar('excluir', [], $rascunhoId);

        self::assertResponseRedirects('/voto?aba=ges#assembleia-' . $rascunhoId);
        $banco = $this->em->getConnection();
        self::assertFalse($banco->fetchOne('SELECT id FROM voto.assembleia WHERE id = ?', [$rascunhoId]));
        self::assertSame(0, (int) $banco->fetchOne('SELECT COUNT(*) FROM voto.assembleia_pauta WHERE assembleia_id = ?', [$rascunhoId]));
        self::assertSame(0, (int) $banco->fetchOne('SELECT COUNT(*) FROM voto.assembleia_unidade WHERE assembleia_id = ?', [$rascunhoId]));
        self::assertSame('Rascunho excluído.', $this->avisoDoPainel());

        $this->postar('excluir');

        self::assertSame('Só rascunho nunca publicado pode ser excluído. Votação publicada pode ser arquivada.', $this->avisoDoPainel());
        self::assertNotFalse($banco->fetchOne('SELECT id FROM voto.assembleia WHERE id = ?', [$this->assembleia->getId()]));
    }

    #[TestDox('Token CSRF inválido: aviso de sessão expirada e nada muda, em todas as ações')]
    public function testCsrfInvalido(): void
    {
        $acoes = ['pautas/' . $this->pauta->getId() . '/suspender', 'pautas/' . $this->pauta->getId() . '/cancelar', 'arquivar', 'excluir'];
        foreach ($acoes as $acao) {
            $this->client->request('POST', '/voto/assembleias/' . $this->assembleia->getId() . '/' . $acao, ['_token' => 'invalido', 'motivo' => 'Motivo válido']);

            self::assertResponseRedirects('/voto?aba=ges#assembleia-' . $this->assembleia->getId());
            self::assertSame('A sessão expirou. Recarregue a página e tente de novo.', $this->avisoDoPainel(), $acao);
        }
        $linha = $this->pautaNoBanco();
        self::assertNull($linha['suspensa_em']);
        self::assertNull($linha['cancelada_em']);
        self::assertNull($this->assembleiaNoBanco()['arquivada_em']);
    }

    #[TestDox('Assembleia (ou pauta) de outro escritório dá 404 em todas as ações e nada muda')]
    public function testOutroEscritorioDa404(): void
    {
        $alheia = AssembleiaFactory::new()->publicada()->create()->_real();
        $pautaAlheia = PautaDaAssembleiaFactory::new(['assembleia' => $alheia])->comOpcoesPadrao()->aberta()->create()->_real();
        $id = $alheia->getId();
        $acoes = [
            'pautas/' . $pautaAlheia->getId() . '/suspender', 'pautas/' . $pautaAlheia->getId() . '/retomar',
            'pautas/' . $pautaAlheia->getId() . '/cancelar', 'pautas/' . $pautaAlheia->getId() . '/retirar',
            'pautas/' . $pautaAlheia->getId() . '/devolver',
            'arquivar', 'desarquivar', 'excluir',
        ];
        foreach ($acoes as $acao) {
            $this->postar($acao, ['motivo' => 'Motivo válido'], $id);

            self::assertResponseStatusCodeSame(404, $acao);
        }
        // Pauta da própria assembleia, mas na assembleia de outro: também 404.
        $this->postar('pautas/' . $pautaAlheia->getId() . '/suspender', ['motivo' => 'Motivo válido']);
        self::assertResponseStatusCodeSame(404);

        $banco = $this->em->getConnection();
        self::assertNull($banco->fetchOne('SELECT suspensa_em FROM voto.assembleia_pauta WHERE id = ?', [$pautaAlheia->getId()]));
        self::assertNull($banco->fetchOne('SELECT cancelada_em FROM voto.assembleia_pauta WHERE id = ?', [$pautaAlheia->getId()]));
        self::assertNull($banco->fetchOne('SELECT arquivada_em FROM voto.assembleia WHERE id = ?', [$id]));
        self::assertNotFalse($banco->fetchOne('SELECT id FROM voto.assembleia WHERE id = ?', [$id]), 'a excluir de outro escritório não apaga');
    }

    #[TestDox('Leitura do painel: expõe suspensão, cancelamento (sem resultado), retirada e arquivamento (situação, quando, motivo)')]
    public function testLeituraExpoeOsCamposNovos(): void
    {
        $aguardando = PautaDaAssembleiaFactory::new(['assembleia' => $this->assembleia, 'ordem' => 2, 'titulo' => 'Orçamento'])->comOpcoesPadrao()->create()->_real();
        $outra = PautaDaAssembleiaFactory::new(['assembleia' => $this->assembleia, 'ordem' => 3, 'titulo' => 'Reforma'])->comOpcoesPadrao()->aberta()->create()->_real();
        UnidadeDaAssembleiaFactory::createOne(['assembleia' => $this->assembleia]);
        $this->postar('pautas/' . $this->pauta->getId() . '/cancelar', ['motivo' => 'Convocação irregular']);
        $this->postar('pautas/' . $outra->getId() . '/suspender', ['motivo' => 'Queda de energia']);
        $this->postar('pautas/' . $aguardando->getId() . '/retirar', ['motivo' => 'Matéria adiada']);
        $this->em->clear();

        $painel = static::getContainer()->get(\App\Voto\Service\LeituraDaAssembleia::class)
            ->montar($this->em->find(Assembleia::class, $this->assembleia->getId()));

        [$cancelada, $retirada, $suspensa] = $painel->pautas;
        self::assertSame(['cancelada', 'retirada', 'suspensa'], [$cancelada->ciclo(), $retirada->ciclo(), $suspensa->ciclo()]);
        self::assertNull($cancelada->resultado, 'cancelada não é apurada');
        self::assertSame('Convocação irregular', $cancelada->canceladaMotivo);
        self::assertSame('Síndica Teste', $cancelada->canceladaPorNome);
        self::assertSame('Matéria adiada', $retirada->retiradaMotivo);
        self::assertNotNull($retirada->retiradaEm);
        self::assertSame('Queda de energia', $suspensa->suspensaMotivo);
        self::assertNotNull($suspensa->suspensaEm);
        self::assertFalse($painel->arquivada());

        $this->postar('pautas/' . $outra->getId() . '/cancelar', ['motivo' => 'Convocação irregular']);
        $this->postar('arquivar', ['motivo' => 'Assembleia concluída']);
        $this->em->clear();
        $arquivada = static::getContainer()->get(\App\Voto\Service\LeituraDaAssembleia::class)
            ->montar($this->em->find(Assembleia::class, $this->assembleia->getId()));
        self::assertTrue($arquivada->arquivada());
        self::assertSame('Assembleia concluída', $arquivada->arquivadaMotivo);
        self::assertSame('Síndica Teste', $arquivada->arquivadaPorNome);
    }

    #[TestDox('GET não é aceito: só POST')]
    public function testGetNaoAceito(): void
    {
        $this->client->request('GET', '/voto/assembleias/' . $this->assembleia->getId() . '/arquivar');

        self::assertResponseStatusCodeSame(405);
    }

    #[TestDox('Eleitor: pauta suspensa mostra a mensagem do desenho, sem cabine, e o voto é recusado; retomada volta a aceitar')]
    public function testEleitorNaSuspensa(): void
    {
        $unidade = UnidadeDaAssembleiaFactory::createOne(['assembleia' => $this->assembleia, 'identificacao' => '101'])->_real();
        $aberta = $this->client->request('GET', $this->linkDa($unidade));
        $reserva = $aberta->filter('[data-vc-reserva] form')->first();
        $acao = (string) $reserva->attr('action');
        $csrf = (string) $reserva->filter('input[name="_token"]')->attr('value');
        $sim = $this->opcaoId('Sim');

        $this->pauta->suspender('Queda de energia', 'Mesa');
        $this->em->flush();
        $suspensa = $this->client->request('GET', $this->linkDa($unidade));

        self::assertSame('Votação suspensa pela mesa. Aguarde a retomada.', trim($suspensa->filter('.vcPauta [data-vc-suspensa]')->text()));
        self::assertCount(1, $suspensa->filter('.vcPauta [data-vc-suspensa] > i.bi-pause-circle'));
        self::assertSame('Suspensa', $suspensa->filter('.vcPautaTopo > .vChip')->text());
        self::assertCount(0, $suspensa->filter('[data-vc-reserva], form[data-vc-votar]'));

        $this->client->request('POST', $acao, ['_token' => $csrf, 'opcao' => (string) $sim]);
        $depois = $this->client->followRedirect();
        self::assertSame('Votação suspensa pela mesa. Aguarde a retomada.', trim($depois->filter('body > .vcToast[data-vc-toast]')->text()));
        self::assertSame(0, (int) $this->em->getConnection()->fetchOne('SELECT COUNT(*) FROM voto.assembleia_participacao WHERE pauta_id = ?', [$this->pauta->getId()]));

        $this->postar('pautas/' . $this->pauta->getId() . '/retomar');
        $this->client->request('POST', $acao, ['_token' => $csrf, 'opcao' => (string) $sim]);
        self::assertSame(1, (int) $this->em->getConnection()->fetchOne('SELECT COUNT(*) FROM voto.assembleia_participacao WHERE pauta_id = ?', [$this->pauta->getId()]));
    }

    #[TestDox('Eleitor: pauta cancelada mostra "Votação cancelada pela comissão." e nenhum resultado; retirada some da cédula')]
    public function testEleitorNaCanceladaERetirada(): void
    {
        $unidade = UnidadeDaAssembleiaFactory::createOne(['assembleia' => $this->assembleia, 'identificacao' => '101'])->_real();
        $retirada = PautaDaAssembleiaFactory::new(['assembleia' => $this->assembleia, 'ordem' => 2, 'titulo' => 'Pauta retirada do dia'])->comOpcoesPadrao()->create()->_real();
        $retirada->retirarDePauta('Matéria adiada', 'Mesa');
        $this->pauta->cancelar('Convocação irregular', 'Mesa');
        $this->em->flush();

        $cedula = $this->client->request('GET', $this->linkDa($unidade));

        self::assertResponseStatusCodeSame(200);
        self::assertCount(1, $cedula->filter('.vcMain > .vC.vcPauta'), 'a retirada não aparece');
        self::assertStringNotContainsString('Pauta retirada do dia', (string) $this->client->getResponse()->getContent());
        self::assertSame('Votação cancelada pela comissão.', trim($cedula->filter('.vcPauta [data-vc-cancelada]')->text()));
        self::assertCount(1, $cedula->filter('.vcPauta [data-vc-cancelada] > i.bi-x-circle'));
        self::assertSame('Cancelada', $cedula->filter('.vcPautaTopo > .vChip')->text());
        self::assertCount(0, $cedula->filter('.vcPauta .vcResultado, .vcPauta [data-vc-resultado]'), 'cédulas lacradas: sem apuração');
    }

    /** @param array<string, string> $dados */
    private function postar(string $caminho, array $dados = [], ?int $assembleiaId = null): void
    {
        $id = $assembleiaId ?? $this->assembleia->getId();
        $this->client->request('POST', '/voto/assembleias/' . $id . '/' . $caminho, ['_token' => $this->tokenValido('voto_assembleia_' . $id)] + $dados);
    }

    /** O aviso que a próxima página do painel mostra (o toast do Voto); vazio se não houve. */
    private function avisoDoPainel(): string
    {
        $resposta = $this->client->getResponse();
        if (!$resposta->isRedirect()) {
            return '';
        }
        $tela = $this->client->followRedirect();

        return trim($tela->filter('.voto-toast')->count() > 0 ? $tela->filter('.voto-toast')->first()->text() : '');
    }

    /** @return array<string, mixed> */
    private function pautaNoBanco(): array
    {
        $linha = $this->em->getConnection()->fetchAssociative('SELECT * FROM voto.assembleia_pauta WHERE id = ?', [$this->pauta->getId()]);
        self::assertIsArray($linha);

        return $linha;
    }

    /** @return array<string, mixed> */
    private function assembleiaNoBanco(): array
    {
        $linha = $this->em->getConnection()->fetchAssociative('SELECT * FROM voto.assembleia WHERE id = ?', [$this->assembleia->getId()]);
        self::assertIsArray($linha);

        return $linha;
    }

    private function linkDa(UnidadeDaAssembleia $unidade): string
    {
        return '/votar/' . UnidadeDaAssembleiaFactory::tokenDe($unidade);
    }

    private function opcaoId(string $rotulo): int
    {
        foreach ($this->pauta->getOpcoes() as $opcao) {
            if ($opcao->getRotulo() === $rotulo) {
                return (int) $opcao->getId();
            }
        }
        self::fail('Opção ' . $rotulo . ' não existe.');
    }

    /** Grava na sessão um token CSRF conhecido, como o SessionTokenStorage (ver AssembleiaGestorTest::tokenValido). */
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
