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
use App\Tests\Factory\Voto\OpcaoDaPautaFactory;
use App\Tests\Factory\Voto\PautaDaAssembleiaFactory;
use App\Tests\Factory\Voto\UnidadeDaAssembleiaFactory;
use App\Tests\Functional\JusPrimeWebTestCase;
use App\Voto\Controller\PautasDaAssembleiaController;
use App\Voto\Entity\Assembleia;
use App\Voto\Entity\LiberacaoDoVoto;
use App\Voto\Entity\PautaDaAssembleia;
use App\Voto\Entity\UnidadeDaAssembleia;
use App\Voto\Entity\VotoNominal;
use App\Voto\Enum\SigiloDoVoto;
use App\Voto\Service\LeituraDaAssembleia;
use App\Voto\UseCase\MontarCedulaUseCase;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\TestDox;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Component\DomCrawler\Crawler;
use Zenstruck\Foundry\Test\Factories;

/**
 * Onda 2 do redesign (W2-3) pelas telas: o formulário da pauta grava horário programado, grupos, ordem das candidaturas
 * e sigilo; a cédula avisa o voto aberto e só mostra a pauta aos grupos dela; a relação nominal aparece só na pauta de
 * voto aberto (Resultados e ata), nunca na secreta.
 */
#[CoversClass(PautasDaAssembleiaController::class)]
#[CoversClass(MontarCedulaUseCase::class)]
#[CoversClass(LeituraDaAssembleia::class)]
#[Group('voto')]
final class ProgramacaoEVotoAbertoNasTelasTest extends JusPrimeWebTestCase
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
        $this->escritorio = TenantFactory::createOne(['name' => 'Condomínio do Voto ' . uniqid()])->_real();
        $estado = $this->em->getRepository(RecursoEstado::class)->findOneBy(['chave' => 'voto']);
        if (null !== $estado) {
            $estado->reativar(null, new \DateTimeImmutable());
        }
        $liberacao = new LiberacaoDoVoto($this->escritorio);
        $liberacao->liberar();
        $this->em->persist($liberacao);
        $this->em->flush();
    }

    #[TestDox('O formulário da pauta mostra os campos do desenho e o padrão: secreto, mesa, sem grupos, ordem de registro')]
    public function testFormularioMostraOsCamposDoDesenho(): void
    {
        $this->logarComTenant($this->client, $this->usuario(), $this->escritorio);
        $assembleia = AssembleiaFactory::createOne(['tenant' => $this->escritorio])->_real();
        $pauta = PautaDaAssembleiaFactory::new(['assembleia' => $assembleia])->comOpcoesPadrao()->create()->_real();

        $tela = $this->client->request('GET', '/voto/assembleias/' . $assembleia->getId() . '/pautas');

        $caixa = $tela->filter('form#pauta-' . $pauta->getId() . ' [data-voto-participacao]');
        self::assertCount(1, $caixa);
        self::assertSame(['Secreto', 'Aberto: nominal, visível após o encerramento', 'Aberto: nominal, visível em tempo real'], $this->opcoes($caixa, 'sigilo_voto'));
        self::assertSame('secreto', $caixa->filter('select[name="sigilo_voto"] option[selected]')->attr('value'));
        self::assertSame(['Programados no horário', 'Conduzidos pela mesa'], $this->opcoes($caixa, 'modo_horario'));
        self::assertSame('mesa', $caixa->filter('select[name="modo_horario"] option[selected]')->attr('value'));
        self::assertSame(['Ordem de registro', 'Sorteada a cada visualização'], $this->opcoes($caixa, 'ordem_candidaturas'));
        self::assertSame('Grupos aptos (separados por vírgula; vazio para todos)', trim($caixa->filter('label.voto-pauta-grupos')->text()));
        self::assertSame(['Início', 'Encerramento'], $caixa->filter('label[data-voto-horario]')->each(static fn (Crawler $l): string => trim($l->text())));
        self::assertCount(2, $caixa->filter('label[data-voto-horario][hidden]'), 'o horário só aparece em "Programados no horário"');
        self::assertCount(1, $caixa->filter('.vAviso[data-voto-sigilo-aviso][hidden]'));
        self::assertStringContainsString('O eleitor é avisado antes de votar.', $caixa->filter('.vAviso[data-voto-sigilo-aviso]')->text());
    }

    #[TestDox('Salvar grava o horário, os grupos, a ordem e o sigilo; "Conduzidos pela mesa" limpa o horário')]
    public function testSalvarGravaAsRegras(): void
    {
        $this->logarComTenant($this->client, $this->usuario(), $this->escritorio);
        $assembleia = AssembleiaFactory::createOne(['tenant' => $this->escritorio])->_real();
        $pauta = PautaDaAssembleiaFactory::new(['assembleia' => $assembleia])->comOpcoesPadrao()->create()->_real();
        $url = '/voto/assembleias/' . $assembleia->getId() . '/pautas';
        $token = (string) $this->client->request('GET', $url)->filter('#pauta-' . $pauta->getId() . ' input[name="_token"]')->attr('value');
        $abre = (new \DateTimeImmutable('+2 days'))->format('Y-m-d\T10:00');
        $encerra = (new \DateTimeImmutable('+2 days'))->format('Y-m-d\T12:30');

        $this->client->xmlHttpRequest('POST', $url . '/' . $pauta->getId() . '/editar', $this->envio($token) + [
            'sigilo_voto' => 'aberto', 'modo_horario' => 'horario', 'abre_em' => $abre, 'encerra_em' => $encerra,
            'grupos' => 'Bloco A, Bloco B ,, bloco a', 'ordem_candidaturas' => 'sorteada',
        ]);

        self::assertResponseStatusCodeSame(200);
        $linha = $this->regrasNoBanco((int) $pauta->getId());
        self::assertSame('aberto', $linha['sigilo']);
        self::assertSame('sorteada', $linha['ordem_das_candidaturas']);
        self::assertSame(['Bloco A', 'Bloco B'], json_decode((string) $linha['grupos'], true, 512, \JSON_THROW_ON_ERROR));
        self::assertSame(str_replace('T', ' ', $abre) . ':00', $linha['abre_em']);
        self::assertSame(str_replace('T', ' ', $encerra) . ':00', $linha['encerra_em']);

        $tela = $this->client->request('GET', $url);
        $caixa = $tela->filter('form#pauta-' . $pauta->getId() . ' [data-voto-participacao]');
        self::assertSame('aberto', $caixa->filter('select[name="sigilo_voto"] option[selected]')->attr('value'));
        self::assertSame('horario', $caixa->filter('select[name="modo_horario"] option[selected]')->attr('value'));
        self::assertSame($abre, $caixa->filter('input[name="abre_em"]')->attr('value'));
        self::assertSame('Bloco A, Bloco B', $caixa->filter('input[name="grupos"]')->attr('value'));
        self::assertCount(0, $caixa->filter('label[data-voto-horario][hidden]'));
        self::assertCount(0, $caixa->filter('.vAviso[data-voto-sigilo-aviso][hidden]'));

        $this->client->xmlHttpRequest('POST', $url . '/' . $pauta->getId() . '/editar', $this->envio($token) + ['modo_horario' => 'mesa', 'abre_em' => $abre, 'encerra_em' => $encerra]);
        self::assertResponseStatusCodeSame(200);
        $linha = $this->regrasNoBanco((int) $pauta->getId());
        self::assertNull($linha['abre_em']);
        self::assertNull($linha['encerra_em']);
        self::assertSame('secreto', $linha['sigilo'], 'sem o campo, volta ao padrão secreto');
    }

    #[TestDox('Horário inválido é recusado com 422 e a mensagem, sem gravar')]
    public function testHorarioInvalidoERecusado(): void
    {
        $this->logarComTenant($this->client, $this->usuario(), $this->escritorio);
        $assembleia = AssembleiaFactory::createOne(['tenant' => $this->escritorio])->_real();
        $pauta = PautaDaAssembleiaFactory::new(['assembleia' => $assembleia])->comOpcoesPadrao()->create()->_real();
        $url = '/voto/assembleias/' . $assembleia->getId() . '/pautas';
        $token = (string) $this->client->request('GET', $url)->filter('#pauta-' . $pauta->getId() . ' input[name="_token"]')->attr('value');
        $depois = (new \DateTimeImmutable('+2 days'))->format('Y-m-d\TH:i');
        $antes = (new \DateTimeImmutable('+1 day'))->format('Y-m-d\TH:i');

        $casos = [
            ['abre_em' => $depois, 'encerra_em' => $antes, 'mensagem' => 'Confira a abertura e o encerramento: o encerramento vem depois.'],
            ['abre_em' => $depois, 'encerra_em' => '', 'mensagem' => 'Informe a abertura e o encerramento, ou deixe os dois vazios.'],
            ['abre_em' => 'ontem', 'encerra_em' => $depois, 'mensagem' => 'Confira a data e a hora da programação.'],
        ];
        foreach ($casos as ['abre_em' => $a, 'encerra_em' => $e, 'mensagem' => $mensagem]) {
            $this->client->xmlHttpRequest('POST', $url . '/' . $pauta->getId() . '/editar', $this->envio($token) + ['sigilo_voto' => 'aberto', 'modo_horario' => 'horario', 'abre_em' => $a, 'encerra_em' => $e]);
            self::assertResponseStatusCodeSame(422);
            self::assertSame($mensagem, json_decode((string) $this->client->getResponse()->getContent(), true)['mensagem']);
        }
        $linha = $this->regrasNoBanco((int) $pauta->getId());
        self::assertSame('secreto', $linha['sigilo']);
        self::assertNull($linha['abre_em']);
    }

    #[TestDox('Depois de publicada, a pauta recusa mudar o sigilo (fixo na publicação)')]
    public function testSigiloFixoDepoisDePublicar(): void
    {
        $this->logarComTenant($this->client, $this->usuario(), $this->escritorio);
        $assembleia = AssembleiaFactory::new()->publicada()->create(['tenant' => $this->escritorio])->_real();
        $pauta = PautaDaAssembleiaFactory::new(['assembleia' => $assembleia])->comOpcoesPadrao()->create()->_real();
        $url = '/voto/assembleias/' . $assembleia->getId() . '/pautas';
        $token = (string) $this->client->request('GET', $url)->filter('input[name="_token"]')->attr('value');

        $this->client->xmlHttpRequest('POST', $url . '/' . $pauta->getId() . '/editar', $this->envio($token) + ['sigilo_voto' => 'aberto']);

        self::assertResponseStatusCodeSame(422);
        self::assertSame('secreto', $this->regrasNoBanco((int) $pauta->getId())['sigilo']);
    }

    #[TestDox('Cédula: a pauta de voto aberto avisa "VOTO ABERTO" no cartão e na cabine; a secreta não avisa; o aviso de sigilo só vale se há pauta secreta')]
    public function testCedulaAvisaOVotoAberto(): void
    {
        [$assembleia, $unidade] = $this->assembleiaPublicada();
        $aberta = $this->pautaAberta($assembleia, 'Síndico', ['sigilo' => SigiloDoVoto::Aberto]);
        $secreta = $this->pautaAberta($assembleia, 'Contas');
        $link = '/votar/' . UnidadeDaAssembleiaFactory::tokenDe($unidade);

        $tela = $this->client->request('GET', $link);

        self::assertResponseIsSuccessful();
        $cartaoAberta = $tela->filter('.vcPauta[data-vc-pauta="' . $aberta->getId() . '"]');
        self::assertSame('VOTO ABERTO: seu nome e seu voto ficarão visíveis', trim($cartaoAberta->filter('[data-vc-voto-aberto]')->text()));
        self::assertCount(0, $tela->filter('.vcPauta[data-vc-pauta="' . $secreta->getId() . '"] [data-vc-voto-aberto]'));
        self::assertCount(1, $tela->filter('.vAviso[data-vc-sigilo]'), 'há pauta secreta na cédula');
        $dados = json_decode((string) $tela->filter('[data-vc-cabine-dados]')->attr('data-vc-cabine-dados'), true, 512, \JSON_THROW_ON_ERROR);
        self::assertSame(['Síndico' => true, 'Contas' => false], array_column($dados['pautas'], 'aberto', 'titulo'));
        self::assertSame('VOTO ABERTO: seu nome e seu voto ficarão visíveis', trim($tela->filter('.uBody > .uFaixa[data-u-faixa-aberto]')->text()));


        [$outra, $unidadeDaOutra] = $this->assembleiaPublicada();
        $this->pautaAberta($outra, 'Síndico', ['sigilo' => SigiloDoVoto::AbertoAoVivo]);
        $soAberta = $this->client->request('GET', '/votar/' . UnidadeDaAssembleiaFactory::tokenDe($unidadeDaOutra));
        self::assertCount(0, $soAberta->filter('.vAviso[data-vc-sigilo]'), 'só voto aberto na cédula: o "voto é secreto" some');
        self::assertCount(1, $soAberta->filter('[data-vc-voto-aberto]'));
    }

    #[TestDox('Cédula: a pauta com grupos só aparece para a unidade de um deles; a lista mostra os grupos')]
    public function testCedulaSoMostraAPautaAosGruposDela(): void
    {
        [$assembleia, $unidadeA] = $this->assembleiaPublicada();
        $unidadeB = UnidadeDaAssembleiaFactory::createOne(['assembleia' => $assembleia, 'bloco' => 'Bloco B', 'identificacao' => 'B-101', 'fracao' => '0.025000'])->_real();
        $paraA = $this->pautaAberta($assembleia, 'Reforma do Bloco A', ['grupos' => ['bloco a']]);
        $paraTodos = $this->pautaAberta($assembleia, 'Contas');

        $telaA = $this->client->request('GET', '/votar/' . UnidadeDaAssembleiaFactory::tokenDe($unidadeA));
        $telaB = $this->client->request('GET', '/votar/' . UnidadeDaAssembleiaFactory::tokenDe($unidadeB));

        self::assertSame(['Reforma do Bloco A', 'Contas'], $telaA->filter('.vcPauta .vcPautaNome > b')->each(static fn (Crawler $c): string => $c->text()));
        self::assertSame(['Contas'], $telaB->filter('.vcPauta .vcPautaNome > b')->each(static fn (Crawler $c): string => $c->text()));
        self::assertStringContainsString('Grupos: bloco a', $telaA->filter('.vcPauta[data-vc-pauta="' . $paraA->getId() . '"] .vcInfo')->text());
        self::assertStringContainsString('Todos os grupos', $telaA->filter('.vcPauta[data-vc-pauta="' . $paraTodos->getId() . '"] .vcInfo')->text());
    }

    #[TestDox('Ordem sorteada: a cédula traz as mesmas candidaturas, a abstenção por último; a de registro vem pelo número')]
    public function testOrdemNaCedula(): void
    {
        [$assembleia, $unidade] = $this->assembleiaPublicada();
        $pauta = $this->pautaAberta($assembleia, 'Síndico', ['ordemDasCandidaturas' => \App\Voto\Enum\OrdemDasCandidaturas::Sorteada], false);
        foreach ([1 => 'Ana', 2 => 'Bia', 3 => 'Caio', 4 => 'Duda', 5 => 'Eva', 6 => 'Fábio'] as $numero => $nome) {
            OpcaoDaPautaFactory::createOne(['pauta' => $pauta, 'numero' => $numero, 'rotulo' => $nome]);
        }
        OpcaoDaPautaFactory::new()->abstencao()->create(['pauta' => $pauta]);
        $this->em->flush();
        $link = '/votar/' . UnidadeDaAssembleiaFactory::tokenDe($unidade);
        $ordens = [];
        for ($i = 0; $i < 12; ++$i) {
            $dados = json_decode((string) $this->client->request('GET', $link)->filter('[data-vc-cabine-dados]')->attr('data-vc-cabine-dados'), true, 512, \JSON_THROW_ON_ERROR);
            $rotulos = array_column($dados['pautas'][0]['opcoes'], 'rotulo');
            self::assertSame('ABSTENÇÃO', $rotulos[6]);
            $semAbstencao = \array_slice($rotulos, 0, 6);
            sort($semAbstencao);
            self::assertSame(['Ana', 'Bia', 'Caio', 'Duda', 'Eva', 'Fábio'], $semAbstencao);
            $ordens[implode(',', $rotulos)] = true;
        }
        self::assertGreaterThan(1, \count($ordens), 'sorteada a cada visualização');

        $this->em->createQuery('UPDATE ' . PautaDaAssembleia::class . ' p SET p.ordemDasCandidaturas = :o WHERE p.id = :id')
            ->setParameter('o', \App\Voto\Enum\OrdemDasCandidaturas::Fixa)->setParameter('id', $pauta->getId())->execute();
        $this->em->clear();
        $dados = json_decode((string) $this->client->request('GET', $link)->filter('[data-vc-cabine-dados]')->attr('data-vc-cabine-dados'), true, 512, \JSON_THROW_ON_ERROR);
        self::assertSame(['Ana', 'Bia', 'Caio', 'Duda', 'Eva', 'Fábio', 'ABSTENÇÃO'], array_column($dados['pautas'][0]['opcoes'], 'rotulo'));
    }

    #[TestDox('Resultados e ata: a relação nominal aparece só na pauta de voto aberto, com a unidade, o voto e a hora em segundos')]
    public function testRelacaoNominalSoNaPautaAberta(): void
    {
        $this->logarComTenant($this->client, $this->usuario(), $this->escritorio);
        [$assembleia, $unidade] = $this->assembleiaPublicada();
        $aberta = $this->pautaEncerrada($assembleia, 'Síndico', SigiloDoVoto::Aberto);
        $secreta = $this->pautaEncerrada($assembleia, 'Contas', SigiloDoVoto::Secreto);
        $horario = new \DateTimeImmutable('2026-11-20 10:15:42');
        $sim = $aberta->getOpcoesDeVoto()[0];
        $this->em->persist(new VotoNominal($unidade, $aberta, $sim, null, $unidade->pesoDoVoto(), $horario));
        $this->em->flush();

        $resultados = $this->client->request('GET', '/voto?aba=tra');
        $nominal = $resultados->filter('[data-voto-nominal="' . $aberta->getId() . '"]');
        self::assertCount(1, $nominal);
        self::assertSame('Voto aberto: quem votou em quê', trim($nominal->filter('.voto-nominal-titulo')->text()));
        $linha = $nominal->filter('tbody > tr')->first()->filter('td')->each(static fn (Crawler $c): string => trim($c->text()));
        self::assertSame(['Bloco A · A-101', (string) $unidade->getResponsavel(), '1 Sim', '0,025', '20/11/2026 10:15:42'], $linha);
        self::assertCount(0, $resultados->filter('[data-voto-nominal="' . $secreta->getId() . '"]'));
        self::assertCount(1, $resultados->filter('.voto-nominal'), 'a pauta secreta nunca tem a relação');

        $ata = $this->client->request('GET', '/voto/assembleias/' . $assembleia->getId() . '/ata');
        self::assertCount(1, $ata->filter('#pauta-' . $aberta->getId() . ' [data-voto-nominal]'));
        self::assertCount(0, $ata->filter('#pauta-' . $secreta->getId() . ' [data-voto-nominal]'));
        self::assertStringContainsString('de voto aberto', $ata->filter('#pauta-' . $aberta->getId())->text());
        self::assertStringContainsString('Não indica em quem cada pessoa votou', $ata->filter('#pauta-' . $secreta->getId())->text());
    }

    #[TestDox('Voto aberto só mostra a relação depois do encerramento; o "ao vivo" mostra desde a abertura')]
    public function testQuandoARelacaoApareceNaAta(): void
    {
        $this->logarComTenant($this->client, $this->usuario(), $this->escritorio);
        [$assembleia, $unidade] = $this->assembleiaPublicada();
        $apos = $this->pautaAberta($assembleia, 'Depois', ['sigilo' => SigiloDoVoto::Aberto]);
        $vivo = $this->pautaAberta($assembleia, 'Ao vivo', ['sigilo' => SigiloDoVoto::AbertoAoVivo]);
        foreach ([$apos, $vivo] as $pauta) {
            $this->em->persist(new VotoNominal($unidade, $pauta, $pauta->getOpcoesDeVoto()[1], null, '1.000000', new \DateTimeImmutable()));
        }
        $this->em->flush();
        $url = '/voto/assembleias/' . $assembleia->getId() . '/ata';

        $ata = $this->client->request('GET', $url);
        self::assertCount(0, $ata->filter('#pauta-' . $apos->getId() . ' [data-voto-nominal]'), 'aberto: só depois de encerrar');
        self::assertCount(1, $ata->filter('#pauta-' . $vivo->getId() . ' [data-voto-nominal]'), 'ao vivo: já na votação aberta');

        $apos->encerrar('Mesa', '');
        $this->em->flush();
        $ata = $this->client->request('GET', $url);
        self::assertCount(1, $ata->filter('#pauta-' . $apos->getId() . ' [data-voto-nominal]'));
    }

    /** @return list<string> */
    private function opcoes(Crawler $caixa, string $nome): array
    {
        return $caixa->filter('select[name="' . $nome . '"] option')->each(static fn (Crawler $o): string => trim($o->text()));
    }

    /** @return array<string, mixed> */
    private function envio(string $token): array
    {
        return [
            '_token' => $token, 'titulo' => 'Síndico(a)', 'descricao' => '',
            'opcao_numero' => ['1', '2'], 'opcao_rotulo' => ['Sim', 'Não'],
            'criterio' => 'mais', 'base' => 'validos', 'digitos' => '1', 'vagas' => '1', 'quorum_primeira' => '50', 'quorum_segunda' => '0',
        ];
    }

    /** @return array<string, mixed> */
    private function regrasNoBanco(int $pautaId): array
    {
        $linha = $this->em->getConnection()->fetchAssociative(
            'SELECT abre_em, encerra_em, grupos, ordem_das_candidaturas, sigilo FROM voto.assembleia_pauta WHERE id = ?',
            [$pautaId],
        );
        self::assertIsArray($linha);

        return $linha;
    }

    /** @return array{Assembleia, UnidadeDaAssembleia} */
    private function assembleiaPublicada(): array
    {
        $assembleia = AssembleiaFactory::new()->porFracao()->publicada()->create(['tenant' => $this->escritorio, 'titulo' => 'AGO Residencial Ipê'])->_real();
        $unidade = UnidadeDaAssembleiaFactory::createOne([
            'assembleia' => $assembleia, 'bloco' => 'Bloco A', 'identificacao' => 'A-101', 'fracao' => '0.025000',
        ])->_real();

        return [$assembleia, $unidade];
    }

    /** @param array<string, mixed> $campos */
    private function pautaAberta(Assembleia $assembleia, string $titulo, array $campos = [], bool $comOpcoes = true): PautaDaAssembleia
    {
        $fabrica = PautaDaAssembleiaFactory::new();
        $fabrica = $comOpcoes ? $fabrica->comOpcoesPadrao() : $fabrica;

        return $fabrica->aberta()->create(['assembleia' => $assembleia, 'titulo' => $titulo] + $campos)->_real();
    }

    private function pautaEncerrada(Assembleia $assembleia, string $titulo, SigiloDoVoto $sigilo): PautaDaAssembleia
    {
        return PautaDaAssembleiaFactory::new()->comOpcoesPadrao()->encerrada()->create(['assembleia' => $assembleia, 'titulo' => $titulo, 'sigilo' => $sigilo])->_real();
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
