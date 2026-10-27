<?php

declare(strict_types=1);

namespace App\Tests\Profile\Functional;

use App\Dashboard\Repository\PreferenciaDoUsuarioRepository;
use App\Entity\Auth\User;
use App\Entity\Auth\UserTenant;
use App\Entity\Tenant\Tenant;
use App\Entity\Tenant\TenantRole;
use App\Profile\Controller\PreferenciasDoPerfilController;
use App\Profile\Controller\ProfileController;
use App\Profile\Entity\UserProfile;
use App\Profile\Entity\VinculoNoEscritorio;
use App\Auth\Enum\StatusOab;
use App\Profile\Enum\Atuacao;
use App\Profile\Enum\Genero;
use App\Profile\Enum\VinculoColaborador;
use App\Shared\Email\Descadastro\DescadastroDeEmailInterface;
use App\Tests\Functional\JusPrimeWebTestCase;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\TestDox;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Component\DomCrawler\Crawler;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\Security\Csrf\TokenStorage\ClearableTokenStorageInterface;

/**
 * Página Meu Perfil do desenho "07 - Meu Perfil - VS 18.10.26" (spec docs/specs/meu-perfil-pagina.md):
 * selos, completude, grade conforme o vínculo, modal Editar (vínculo só para o primário),
 * preferências e revisão mensal.
 */
#[CoversClass(ProfileController::class)]
#[CoversClass(PreferenciasDoPerfilController::class)]
final class MeuPerfilPaginaTest extends JusPrimeWebTestCase
{
    private KernelBrowser $client;
    private EntityManagerInterface $em;
    private Tenant $tenant;
    private User $primario;
    private User $secundario;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $this->em     = static::getContainer()->get(EntityManagerInterface::class);

        $this->tenant = new Tenant();
        $this->tenant->setName('Escritório Perfil ' . uniqid());
        $this->em->persist($this->tenant);
        $this->primario   = $this->criarUsuario('Farlei Assis da Rocha', $this->criarPerfil(true));
        $this->secundario = $this->criarUsuario('Jéssica Martins', $this->criarPerfil(false));
        $this->em->flush();
    }

    #[TestDox('identificação: nome em caixa alta, selo do papel e frase vazia em itálico')]
    public function testIdentificacao(): void
    {
        $tela = $this->abrir($this->primario);

        self::assertSame('FARLEI ASSIS DA ROCHA', trim($tela->filter('.mp-ident .mp-nome-linha > h1.mp-nome')->text()));
        self::assertSame('USUÁRIO PRIMÁRIO', trim($tela->filter('.mp-nome-linha > .mp-selo-papel')->text()));
        self::assertCount(0, $tela->filter('.mp-nome-linha > .mp-selo-master'));
        self::assertSame('Adicione uma frase ao seu perfil', trim($tela->filter('.mp-ident > .mp-frase.vazia > span')->text()));
        self::assertSame('SECUNDÁRIO', trim($this->abrir($this->secundario)->filter('.mp-nome-linha > .mp-selo-papel')->text()));
    }

    #[TestDox('o avatar tem o #fotoPerfilAtual que o script da foto troca (sem foto, as iniciais), e nenhum .perfil-foto-btn')]
    public function testAvatarQueOScriptDaFotoTroca(): void
    {
        $tela = $this->abrir($this->primario);

        self::assertCount(1, $tela->filter('.mp-ident-linha > button.mp-avatar > span#fotoPerfilAtual.mp-avatar-ini'));
        self::assertCount(0, $tela->filter('.perfil-foto-btn'));
    }

    #[TestDox('cadastro vazio: alerta com 22% e os chips do que falta, na ordem do desenho')]
    public function testCadastroIncompleto(): void
    {
        $alerta = $this->abrir($this->primario)->filter('.mp-ident > .mp-incompleto[data-mp-incompleto]');

        self::assertCount(1, $alerta);
        self::assertSame('22% preenchido', trim($alerta->filter('.mp-incompleto-pct')->text()));
        self::assertSame(
            ['foto', 'telefone', 'CPF', 'nascimento', 'frase', 'OAB', 'CTPS'],
            $alerta->filter('.mp-falta > .mp-chip')->each(static fn (Crawler $c): string => trim($c->text()))
        );
    }

    #[TestDox('grade: CLT mostra CTPS, Série e Admissão; vazio aparece como "Não informado"')]
    public function testGradeClt(): void
    {
        $linhas = $this->linhasDaGrade($this->abrir($this->primario));

        self::assertSame(['E-mail', 'Vínculo', 'CPF', 'CTPS', 'Série', 'Admissão', 'Nascimento', 'Membro desde', 'Telefone'], array_keys($linhas));
        self::assertSame('Empregado CLT · bate ponto', $linhas['Vínculo']);
        self::assertSame('Não informado', $linhas['Telefone']);
    }

    #[TestDox('grade: PJ troca CTPS/Série/Admissão por CNPJ e Início do contrato; com a chave do ponto desligada, sem ponto')]
    public function testGradePj(): void
    {
        $vinculo = new VinculoNoEscritorio($this->tenant, $this->primario);
        $vinculo->definirTipo(VinculoColaborador::Pj);
        $vinculo->definirCnpj('12345678000190');
        $this->em->persist($vinculo);
        $this->chaveDoPonto(false);

        $linhas = $this->linhasDaGrade($this->abrir($this->primario));

        self::assertSame(['E-mail', 'Vínculo', 'CPF', 'CNPJ', 'Início do contrato', 'Nascimento', 'Membro desde', 'Telefone'], array_keys($linhas));
        self::assertSame('Prestador (PJ / autônomo) · sem ponto', $linhas['Vínculo']);
        self::assertSame('12345678000190', $linhas['CNPJ']);
    }

    #[TestDox('grade: o rótulo do ponto lê a chave "Registra ponto eletrônico" do escritório, não o tipo do vínculo')]
    public function testRotuloDoPontoLeAChave(): void
    {
        $this->chaveDoPonto(false);

        self::assertSame('Empregado CLT · sem ponto', $this->linhasDaGrade($this->abrir($this->primario))['Vínculo']);
    }

    #[TestDox('modal: o primário escolhe o vínculo; o secundário vê as opções travadas e o aviso')]
    public function testVinculoTravadoParaOSecundario(): void
    {
        $doPrimario = $this->abrir($this->primario)->filter('#formDadosPessoais [data-mp-vinculo]');
        self::assertCount(5, $doPrimario->filter('.mp-vinculo-opcoes > label.mp-vinculo-op > input[type=radio]:not([disabled])'));

        $doSecundario = $this->abrir($this->secundario)->filter('#formDadosPessoais [data-mp-vinculo]');
        self::assertCount(5, $doSecundario->filter('.mp-vinculo-opcoes > label.mp-vinculo-op.travado > input[type=radio][disabled]'));
        self::assertStringContainsString('Definido pelo escritório', $doSecundario->filter('[data-mp-vinculo-ajuda]')->text());
    }

    #[TestDox('salvar: o primário grava telefone, frase e vínculo PJ com CNPJ e início do contrato')]
    public function testPrimarioSalva(): void
    {
        $form = $this->abrir($this->primario)->filter('#formDadosPessoais')->form();
        $form['dados_pessoais[status]']         = 'O topo é o seu lugar.';
        $form['dados_pessoais[telefone]']       = '(82) 99999-1234';
        $form['dados_pessoais[vinculo]']        = 'pj';
        $form['dados_pessoais[cnpj]']           = '12.345.678/0001-90';
        $form['dados_pessoais[contratoInicio]'] = '2026-03-01';
        $this->client->submit($form);

        self::assertResponseRedirects('/perfil');
        $this->em->clear();
        $perfil  = $this->em->getRepository(UserProfile::class)->findOneBy(['user' => $this->primario->getId()]);
        $vinculo = $this->em->getRepository(VinculoNoEscritorio::class)->findOneBy(['tenant' => $this->tenant->getId(), 'usuario' => $this->primario->getId()]);
        self::assertSame('82999991234', $perfil?->getTelefone());
        self::assertSame('O topo é o seu lugar.', $perfil?->getStatus());
        self::assertSame(VinculoColaborador::Pj, $vinculo?->getTipo());
        self::assertSame('12345678000190', $vinculo?->getCnpj());
        self::assertSame('2026-03-01', $vinculo?->getContratoInicio()?->format('Y-m-d'));
    }

    #[TestDox('chave PIX para recompensas (desenho 07): o modal tem o campo com o placeholder do desenho, grava, recusa chave inválida e a grade não a mostra')]
    public function testChavePixDeRecompensas(): void
    {
        $tela = $this->abrir($this->primario);
        $campo = $tela->filter('#formDadosPessoais input[name="dados_pessoais[chavePixRecompensa]"]');
        self::assertCount(1, $campo);
        self::assertSame('CPF, e-mail, celular ou chave aleatória', $campo->attr('placeholder'));

        $form = $tela->filter('#formDadosPessoais')->form();
        $form['dados_pessoais[chavePixRecompensa]'] = 'texto solto sem formato';
        $this->client->submit($form);
        $this->em->clear();
        $perfil = $this->em->getRepository(UserProfile::class)->findOneBy(['user' => $this->primario->getId()]);
        self::assertNull($perfil?->getChavePixRecompensa(), 'chave inválida não grava');

        $form = $this->abrir($this->primario)->filter('#formDadosPessoais')->form();
        $form['dados_pessoais[chavePixRecompensa]'] = '  ana@pix.com.br ';
        $this->client->submit($form);
        self::assertResponseRedirects('/perfil');
        $this->em->clear();
        $perfil = $this->em->getRepository(UserProfile::class)->findOneBy(['user' => $this->primario->getId()]);
        self::assertSame('ana@pix.com.br', $perfil?->getChavePixRecompensa());

        $tela = $this->abrir($this->primario);
        self::assertSame('ana@pix.com.br', $tela->filter('#formDadosPessoais input[name="dados_pessoais[chavePixRecompensa]"]')->attr('value'), 'o modal reabre com a chave');
        self::assertStringNotContainsString('ana@pix.com.br', $tela->filter('.mp-grade')->html(), 'a grade de dados não mostra a chave');
    }

    #[TestDox('gênero e atuação (desenho 05 de 21/10): a pessoa declara no modal, grava e o modal reabre com eles')]
    public function testGeneroEAtuacao(): void
    {
        $form = $this->abrir($this->secundario)->filter('#formDadosPessoais')->form();
        $form['dados_pessoais[genero]']  = 'feminino';
        $form['dados_pessoais[atuacao]'] = 'controladoria';
        $this->client->submit($form);

        self::assertResponseRedirects('/perfil');
        $this->em->clear();
        $perfil = $this->em->getRepository(UserProfile::class)->findOneBy(['user' => $this->secundario->getId()]);
        self::assertSame([Genero::Feminino, Atuacao::Controladoria], [$perfil?->getGenero(), $perfil?->getAtuacao()]);
        // O desenho 07 não tem gênero nem atuação na grade: eles aparecem só no modal, que reabre com o que foi gravado.
        $modal = $this->abrir($this->secundario)->filter('#formDadosPessoais');
        self::assertSame(['feminino', 'controladoria'], [
            $modal->filter('select[name="dados_pessoais[genero]"] option[selected]')->attr('value'),
            $modal->filter('select[name="dados_pessoais[atuacao]"] option[selected]')->attr('value'),
        ]);
        self::assertCount(0, $modal->filter('[data-mp-atuacao-sugerida]'), 'atuação declarada não é sugestão');
    }

    #[TestDox('atuação vazia: o modal mostra a sugestão (OAB confirmada sugere advogado) sem marcar o campo, e nada grava')]
    public function testSugestaoDeAtuacao(): void
    {
        $this->primario->setOabNumero('49613')->setOabUf('DF')->setOabStatus(StatusOab::Confirmada);
        $this->em->flush();

        $tela = $this->abrir($this->primario);

        self::assertCount(0, $tela->filter('#formDadosPessoais select[name="dados_pessoais[atuacao]"] option[selected][value="advogado"]'), 'quem só troca o telefone não grava a sugestão');
        $dica = $tela->filter('#formDadosPessoais [data-mp-atuacao-sugerida]');
        self::assertSame('advogado', $dica->attr('data-mp-atuacao-sugerida'));
        self::assertStringContainsString('Sugestão do sistema: Advogado(a).', $dica->text());
        self::assertCount(1, $dica->filter('[data-mp-usar-sugestao]'));
        $this->em->clear();
        $perfil = $this->em->getRepository(UserProfile::class)->findOneBy(['user' => $this->primario->getId()]);
        self::assertNull($perfil?->getAtuacao(), 'a sugestão só vale se a pessoa salvar');
    }

    #[TestDox('papel: só o selo do desenho 07, com o escritório da sessão na dica, sem linha repetida')]
    public function testPapelNoCabecalho(): void
    {
        $tela = $this->abrir($this->primario);

        self::assertSame((string) $this->tenant->getName(), $tela->filter('.mp-nome-linha > .mp-selo-papel')->attr('title'));
        self::assertCount(0, $tela->filter('[data-mp-papel]'));
    }

    #[TestDox('salvar: o secundário que forja o vínculo no POST continua CLT, mas grava a própria admissão')]
    public function testSecundarioNaoTrocaOVinculo(): void
    {
        $tela  = $this->abrir($this->secundario);
        $token = (string) $tela->filter('#formDadosPessoais input[name="dados_pessoais[_token]"]')->attr('value');
        $this->client->request('POST', '/perfil/dados', ['dados_pessoais' => [
            '_token' => $token, 'nomeCompleto' => 'Jéssica Martins', 'vinculo' => 'socio', 'dataAdmissao' => '2024-02-10',
        ]]);

        self::assertResponseRedirects('/perfil');
        $this->em->clear();
        $vinculo = $this->em->getRepository(VinculoNoEscritorio::class)->findOneBy(['tenant' => $this->tenant->getId(), 'usuario' => $this->secundario->getId()]);
        $userTenant = $this->em->getRepository(UserTenant::class)->findOneBy(['tenant' => $this->tenant->getId(), 'user' => $this->secundario->getId()]);
        self::assertSame(VinculoColaborador::Clt, $vinculo?->getTipo());
        self::assertSame('2024-02-10', $userTenant?->getDataAdmissao()?->format('Y-m-d'));
    }

    #[TestDox('preferências: ligadas por padrão; desligar grava e a tela volta desligada')]
    public function testPreferencias(): void
    {
        $tela = $this->abrir($this->primario);
        self::assertSame('true', $tela->filter('.mp-prefs > button[data-mp-pref="perfil.sons"]')->attr('aria-checked'));

        $this->postarJson('/perfil/preferencia', $tela, ['chave' => 'perfil.sons', 'ligado' => false]);
        self::assertResponseIsSuccessful();
        $gravado = static::getContainer()->get(PreferenciaDoUsuarioRepository::class)->valoresDoUsuario($this->tenant, $this->primario, ['perfil.sons']);
        self::assertFalse($gravado['perfil.sons']);

        self::assertSame('false', $this->abrir($this->primario)->filter('.mp-prefs > button[data-mp-pref="perfil.sons"]')->attr('aria-checked'));
    }

    #[TestDox('aprendizado das metas (D4): a chave ligada por padrão e, logo depois, a linha "Apagar o que foi aprendido" com confirmação e token')]
    public function testAprendizadoDasMetas(): void
    {
        $tela = $this->abrir($this->primario);

        $chave = $tela->filter('.mp-prefs > button.mp-pref[role="switch"][data-mp-pref="perfil.sugestoes_despachos"]');
        self::assertSame('true', $chave->attr('aria-checked'));
        self::assertSame('Sugestões a partir dos meus despachos', $chave->filter('.mp-pref-texto > span')->text());
        $form = $tela->filter('.mp-prefs > button[data-mp-pref="perfil.sugestoes_despachos"] + form.mp-pref-form');
        self::assertCount(1, $form);
        self::assertSame('/perfil/aprendizado/apagar', $form->attr('action'));
        self::assertNotSame('', (string) $form->attr('data-confirmar'));
        self::assertCount(1, $form->filter('form.mp-pref-form > input[type="hidden"][name="_token"]'));
        self::assertSame('Apagar o que foi aprendido', $form->filter('form.mp-pref-form > button.mp-pref[data-mp-apagar-aprendizado] > .mp-pref-texto > span')->text());
    }

    #[TestDox('Resumo diário: o descadastro do rodapé desliga a chave; religar pelo Meu Perfil reativa só o resumo, mesmo com "todos"')]
    public function testResumoDiarioSegueODescadastro(): void
    {
        $descadastro = static::getContainer()->get(DescadastroDeEmailInterface::class);
        $email = (string) $this->primario->getEmail();
        $chave = '.mp-prefs > button[data-mp-pref="perfil.resumo_email"]';
        self::assertSame('true', $this->abrir($this->primario)->filter($chave)->attr('aria-checked'));

        $descadastro->descadastrar($this->tenant, $email, DescadastroDeEmailInterface::ASSUNTO_TODOS, 'link_do_email');
        $tela = $this->abrir($this->primario);
        self::assertSame('false', $tela->filter($chave)->attr('aria-checked'), 'quem disse "não quero nada" vê o resumo desligado');

        $this->postarJson('/perfil/preferencia', $tela, ['chave' => 'perfil.resumo_email', 'ligado' => true]);
        self::assertResponseIsSuccessful();
        self::assertFalse($descadastro->estaDescadastrado($this->tenant, $email, 'resumo_diario'), 'religar libera o resumo');
        self::assertTrue($descadastro->estaDescadastrado($this->tenant, $email, 'outro_assunto'), 'e só ele: o "todos" continua para o resto');
        self::assertSame('true', $this->abrir($this->primario)->filter($chave)->attr('aria-checked'));

        $this->postarJson('/perfil/preferencia', $this->abrir($this->primario), ['chave' => 'perfil.resumo_email', 'ligado' => false]);
        self::assertTrue($descadastro->estaDescadastrado($this->tenant, $email, 'resumo_diario'), 'desligar descadastra');
        self::assertSame('false', $this->abrir($this->primario)->filter($chave)->attr('aria-checked'));
    }

    #[TestDox('preferências: chave fora do catálogo responde 400; sem o token AJAX, 403')]
    public function testPreferenciaRecusada(): void
    {
        $tela = $this->abrir($this->primario);
        $this->postarJson('/perfil/preferencia', $tela, ['chave' => 'dashboard.sons', 'ligado' => false]);
        self::assertResponseStatusCodeSame(400);

        $this->client->request('POST', '/perfil/preferencia', [], [], ['CONTENT_TYPE' => 'application/json'], '{"chave":"perfil.sons","ligado":false}');
        self::assertResponseStatusCodeSame(403);
    }

    #[TestDox('revisão mensal: "Está tudo certo" grava a data de hoje')]
    public function testRevisao(): void
    {
        $tela = $this->abrir($this->primario);
        self::assertCount(1, $tela->filter('#modalRevisao [data-mp-revisao-ok]'));

        $this->postarJson('/perfil/revisao', $tela, []);

        self::assertResponseIsSuccessful();
        $this->em->clear();
        $perfil = $this->em->getRepository(UserProfile::class)->findOneBy(['user' => $this->primario->getId()]);
        self::assertSame((new \DateTimeImmutable('today'))->format('Y-m-d'), $perfil?->getRevisadoEm()?->format('Y-m-d'));
    }

    #[TestDox('dois escritórios: a tela e o salvar só tocam o vínculo do escritório da sessão')]
    public function testOutroEscritorioNaoMistura(): void
    {
        $outro = new Tenant();
        $outro->setName('Outro Escritório ' . uniqid());
        $this->em->persist($outro);
        $noOutro = new UserTenant($this->primario, $outro);
        $this->em->persist($noOutro);
        $vinculoOutro = new VinculoNoEscritorio($outro, $this->primario);
        $vinculoOutro->definirTipo(VinculoColaborador::Pj);
        $vinculoOutro->definirCnpj('11222333000144');
        $this->em->persist($vinculoOutro);
        $this->em->flush();

        $tela = $this->abrir($this->primario);
        self::assertSame('Empregado CLT · bate ponto', $this->linhasDaGrade($tela)['Vínculo']);

        $form = $tela->filter('#formDadosPessoais')->form();
        $form['dados_pessoais[vinculo]'] = 'socio';
        $this->client->submit($form);
        // Direto no banco: o TenantFilter do ORM esconderia a linha do outro escritório.
        $linhas = $this->em->getConnection()->fetchAllAssociativeIndexed(
            'SELECT tenant_id, tipo, cnpj FROM vinculo_no_escritorio WHERE user_id = ?',
            [$this->primario->getId()],
        );
        self::assertSame('socio', $linhas[$this->tenant->getId()]['tipo'] ?? null);
        self::assertSame(['tipo' => 'pj', 'cnpj' => '11222333000144'], $linhas[$outro->getId()] ?? null);
    }

    #[TestDox('Master fora de escritório: a página abre sem o bloco de vínculo e salva só os dados da pessoa')]
    public function testMasterSemEscritorio(): void
    {
        $master = $this->criarUsuarioSemVinculo('Master Solto', ['ROLE_USER', 'ROLE_SUPER_ADMIN']);
        $this->client->loginUser($master);
        $this->marcarTermosAceitos($this->client);

        $tela = $this->client->request('GET', '/perfil');
        self::assertResponseIsSuccessful();
        self::assertCount(0, $tela->filter('#formDadosPessoais [data-mp-vinculo]'));

        $form = $tela->filter('#formDadosPessoais')->form();
        $form['dados_pessoais[telefone]'] = '61 98888-7777';
        $this->client->submit($form);

        self::assertResponseRedirects('/perfil');
        $this->em->clear();
        self::assertSame('61988887777', $this->em->getRepository(UserProfile::class)->findOneBy(['user' => $master->getId()])?->getTelefone());
        self::assertSame(0, $this->em->getRepository(VinculoNoEscritorio::class)->count(['usuario' => $master->getId()]));
    }

    #[TestDox('tela de dados pessoais do admin: salvar não apaga a frase nem o telefone da pessoa')]
    public function testAdminNaoApagaFraseNemTelefone(): void
    {
        $perfil = new UserProfile($this->secundario);
        $perfil->setNomeCompleto('Jéssica Martins');
        $perfil->setStatus('Frase da Jéssica');
        $perfil->setTelefone('82911112222');
        $perfil->setChavePixRecompensa('jessica@pix.com.br');
        $perfil->setGenero(Genero::Feminino);
        $perfil->setAtuacao(Atuacao::Advogado);
        $this->em->persist($perfil);
        $this->em->flush();

        $this->logarComTenant($this->client, $this->primario, $this->tenant);
        $url = sprintf('/tenant/%d/user/%d/dados-pessoais', $this->tenant->getId(), $this->secundario->getId());
        $this->client->request('POST', $url, ['dados_pessoais' => [
            '_token' => $this->tokenCsrf('profile_dados'), 'nomeCompleto' => 'Jéssica Martins Souza', 'cpf' => '123',
        ]]);

        self::assertResponseRedirects();
        $this->em->clear();
        $salvo = $this->em->getRepository(UserProfile::class)->findOneBy(['user' => $this->secundario->getId()]);
        self::assertSame('Jéssica Martins Souza', $salvo?->getNomeCompleto(), 'o admin salvou');
        self::assertSame('Frase da Jéssica', $salvo?->getStatus());
        self::assertSame('82911112222', $salvo?->getTelefone());
        self::assertSame('jessica@pix.com.br', $salvo?->getChavePixRecompensa(), 'o admin não apaga a chave PIX');
        self::assertSame([Genero::Feminino, Atuacao::Advogado], [$salvo?->getGenero(), $salvo?->getAtuacao()], 'o admin não apaga gênero nem atuação');
        self::assertSame('123', $salvo?->getCpf(), 'a regra dos 11 números é só do modal do perfil');
    }

    #[TestDox('revisão mensal sem o token AJAX responde 403 e não grava')]
    public function testRevisaoSemTokenRecusada(): void
    {
        $this->abrir($this->primario);
        $this->client->request('POST', '/perfil/revisao');

        self::assertResponseStatusCodeSame(403);
    }

    private function abrir(User $usuario): Crawler
    {
        $this->logarComTenant($this->client, $usuario, $this->tenant);
        $tela = $this->client->request('GET', '/perfil');
        self::assertResponseIsSuccessful();

        return $tela;
    }

    /** A chave do ponto do primário neste escritório (spec seguranca-b-jornada, regra 2). */
    private function chaveDoPonto(bool $ligada): void
    {
        $this->em->getRepository(UserTenant::class)->findOneBy(['user' => $this->primario, 'tenant' => $this->tenant])
            ?->alterarRegistroDePonto($ligada);
        $this->em->flush();
    }

    /** @return array<string, string> rótulo => valor */
    private function linhasDaGrade(Crawler $tela): array
    {
        $linhas = [];
        $tela->filter('.mp-ident > .mp-grade > .mp-linha')->each(static function (Crawler $l) use (&$linhas): void {
            $linhas[trim($l->filter('b')->text())] = trim($l->filter('span')->text());
        });

        return $linhas;
    }

    /** @param array<string, mixed> $dados */
    private function postarJson(string $url, Crawler $tela, array $dados): void
    {
        $token = (string) $tela->filter('meta[name="csrf-token"]')->attr('content');
        $this->client->request('POST', $url, [], [], ['CONTENT_TYPE' => 'application/json', 'HTTP_X_CSRF_TOKEN' => $token], (string) json_encode($dados));
    }

    private function criarPerfil(bool $sistema): TenantRole
    {
        $perfil = new TenantRole();
        $perfil->setTenant($this->tenant);
        $perfil->setName($sistema ? 'Administrador do Escritório' : 'Advogado (a)');
        $perfil->setIsSystem($sistema);
        $this->em->persist($perfil);

        return $perfil;
    }

    /** @param list<string> $papeis */
    private function criarUsuarioSemVinculo(string $nome, array $papeis): User
    {
        $user = new User();
        $user->setEmail(uniqid('perfil_') . '@test.com');
        $user->setFullName($nome);
        $user->setRoles($papeis);
        $user->setIsActive(true);
        $user->setPassword('x');
        $this->em->persist($user);
        $this->em->flush();

        return $user;
    }

    /** Token CSRF previsível (molde do PerfisDoEscritorioControllerTest): chame logo antes do request. */
    private function tokenCsrf(string $id): string
    {
        $storage = new class () implements ClearableTokenStorageInterface {
            public function getToken(string $tokenId): string
            {
                return 'TOKEN_' . $tokenId;
            }

            public function setToken(string $tokenId, string $token): void
            {
            }

            public function removeToken(string $tokenId): ?string
            {
                return null;
            }

            public function hasToken(string $tokenId): bool
            {
                return true;
            }

            public function clear(): void
            {
            }
        };
        static::getContainer()->set('security.csrf.token_storage', $storage);

        return 'TOKEN_' . $id;
    }

    private function criarUsuario(string $nome, TenantRole $perfil): User
    {
        $hasher = static::getContainer()->get(UserPasswordHasherInterface::class);
        $user   = new User();
        $user->setEmail(uniqid('perfil_') . '@test.com');
        $user->setFullName($nome);
        $user->setRoles(['ROLE_USER']);
        $user->setIsActive(true);
        $user->setPassword($hasher->hashPassword($user, 'senha123'));
        $this->em->persist($user);
        $vinculo = new UserTenant($user, $this->tenant);
        $vinculo->setTenantRole($perfil);
        $this->em->persist($vinculo);

        return $user;
    }
}
