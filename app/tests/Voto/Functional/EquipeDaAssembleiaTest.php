<?php

declare(strict_types=1);

namespace App\Tests\Voto\Functional;

use App\Entity\Auth\User;
use App\Entity\Auth\UserTenant;
use App\Entity\Permission\Permission;
use App\Entity\Tenant\Tenant;
use App\Entity\Tenant\TenantRole;
use App\Entity\Tenant\TenantRolePermission;
use App\Master\Entity\RecursoEstado;
use App\Tests\Factory\Auth\UserFactory;
use App\Tests\Factory\Tenant\TenantFactory;
use App\Tests\Factory\Voto\AssembleiaFactory;
use App\Tests\Factory\Voto\PautaDaAssembleiaFactory;
use App\Tests\Functional\JusPrimeWebTestCase;
use App\Voto\Controller\EquipeDoVotoController;
use App\Voto\Entity\Assembleia;
use App\Voto\Entity\LiberacaoDoVoto;
use App\Voto\Entity\MembroDaEquipe;
use App\Voto\EventListener\BarreiraDaEquipeListener;
use App\Voto\Enum\NivelNaAssembleia;
use App\Voto\Enum\PapelNaEquipe;
use App\Voto\Service\AcessoAAssembleia;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\TestDox;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Zenstruck\Foundry\Test\Factories;

/**
 * O teto da equipe por assembleia pela tela (spec docs/specs/voto-equipe-e-master-1611.md, seções 1.2 e 4): sem equipe
 * nada muda; com equipe, a comissão edita, a auditoria lê, quem não está na lista não vê nada; a Administração sempre
 * edita; linha sem conta e quem a guarda do módulo recusa não ganham acesso; outro escritório dá 404. Mais a tela
 * Perfis e as regras de edição da equipe.
 */
#[CoversClass(BarreiraDaEquipeListener::class)]
#[CoversClass(AcessoAAssembleia::class)]
#[CoversClass(EquipeDoVotoController::class)]
final class EquipeDaAssembleiaTest extends JusPrimeWebTestCase
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
        $this->escritorio = TenantFactory::createOne(['name' => 'Equipe do Voto ' . uniqid()])->_real();
        $estado = $this->em->getRepository(RecursoEstado::class)->findOneBy(['chave' => 'voto']);
        if (null !== $estado) {
            $estado->reativar(null, new \DateTimeImmutable());
        }
        $liberacao = new LiberacaoDoVoto($this->escritorio);
        $liberacao->liberar();
        $this->em->persist($liberacao);
        $this->em->flush();
    }

    #[TestDox('Sem equipe própria nada muda: o colega com o módulo lê e edita como sempre')]
    public function testSemEquipeNadaMuda(): void
    {
        $a = $this->assembleia();
        $this->entrar($this->colega());

        $this->client->request('GET', '/voto/assembleias/' . $a->getId() . '/pautas');
        self::assertResponseIsSuccessful();
        $this->client->request('GET', '/voto/assembleias/' . $a->getId() . '/editar');
        self::assertResponseIsSuccessful();
    }

    #[TestDox('Com equipe: a comissão edita, a auditoria lê e recebe 403 no POST')]
    public function testComissaoEditaEAuditoriaLe(): void
    {
        $a = $this->assembleia();
        $comissao = $this->colega();
        $auditoria = $this->colega();
        $this->membro($a, PapelNaEquipe::Comissao, $comissao);
        $this->membro($a, PapelNaEquipe::Auditoria, $auditoria);

        $this->entrar($comissao);
        $this->client->request('GET', '/voto/assembleias/' . $a->getId() . '/editar');
        self::assertResponseIsSuccessful();

        $this->entrar($auditoria);
        $this->client->request('GET', '/voto/assembleias/' . $a->getId() . '/pautas');
        self::assertResponseIsSuccessful();
        $this->client->request('POST', '/voto/assembleias/' . $a->getId() . '/duplicar');
        self::assertResponseStatusCodeSame(403);
        $this->client->request('POST', '/voto/assembleias/' . $a->getId() . '/editar', ['titulo' => 'Invasão']);
        self::assertResponseStatusCodeSame(403);
    }

    #[TestDox('A auditoria ainda gera a minuta por POST (leitura com motivo): a barreira não recusa')]
    public function testMinutaPorPostPassaEmVisualizar(): void
    {
        $a = $this->assembleia();
        $auditoria = $this->colega();
        $this->membro($a, PapelNaEquipe::Auditoria, $auditoria);
        $this->entrar($auditoria);

        $this->client->request('POST', '/voto/assembleias/' . $a->getId() . '/minuta/pdf');
        self::assertNotSame(403, $this->client->getResponse()->getStatusCode());
    }

    #[TestDox('Quem não está na lista, ou só consta como linha sem conta, recebe 403 em leitura e escrita, com a frase do desenho')]
    public function testForaDaListaRecebe403(): void
    {
        $a = $this->assembleia();
        $this->membro($a, PapelNaEquipe::Comissao, $this->colega());
        $this->membro($a, PapelNaEquipe::Comissao, null, 'Pessoa sem conta');
        $fora = $this->colega();
        $this->entrar($fora);

        foreach (['/pautas', '/editar', '/unidades', '/ata'] as $tela) {
            $this->client->request('GET', '/voto/assembleias/' . $a->getId() . $tela);
            self::assertResponseStatusCodeSame(403, $tela);
            self::assertStringContainsString('Seu perfil não atua nesta votação.', (string) $this->client->getResponse()->getContent(), $tela);
        }
        $this->client->request('POST', '/voto/assembleias/' . $a->getId() . '/duplicar');
        self::assertResponseStatusCodeSame(403);
    }

    #[TestDox('Linha com conta de quem a guarda do módulo recusa não dá acesso: o teto nunca concede')]
    public function testEquipeNaoDaAcessoASemModulo(): void
    {
        $a = $this->assembleia();
        $semModulo = $this->colega(comModulo: false);
        $this->membro($a, PapelNaEquipe::Comissao, $semModulo);
        $this->entrar($semModulo);

        $this->client->request('GET', '/voto/assembleias/' . $a->getId() . '/pautas');
        self::assertResponseStatusCodeSame(403);
    }

    #[TestDox('O primário (Administração) edita mesmo fora da lista')]
    public function testAdministracaoSempreEdita(): void
    {
        $a = $this->assembleia();
        $this->membro($a, PapelNaEquipe::Fiscal, $this->colega());
        $this->entrar($this->primario());

        $this->client->request('GET', '/voto/assembleias/' . $a->getId() . '/editar');
        self::assertResponseIsSuccessful();
    }

    #[TestDox('Assembleia de outro escritório passa reto pela barreira e dá 404, como um id que não existe')]
    public function testOutroEscritorioDa404(): void
    {
        $alheia = AssembleiaFactory::createOne()->_real();
        $this->entrar($this->colega());

        $this->client->request('GET', '/voto/assembleias/' . $alheia->getId() . '/pautas');
        self::assertResponseStatusCodeSame(404);
        $this->client->request('POST', '/voto/assembleias/' . $alheia->getId() . '/duplicar', ['_token' => $this->token('voto_assembleia_' . $alheia->getId())]);
        self::assertResponseStatusCodeSame(404);
    }

    #[TestDox('As listas (Gestão eleitoral, Resultados, Acompanhar e Votar) escondem a assembleia Oculto e a tirem dos KPIs')]
    public function testListasEscondemAOculta(): void
    {
        $visivel = $this->assembleia('Visível para todos', publicada: true);
        $oculta = $this->assembleia('Só da comissão', publicada: true);
        $comissao = $this->colega();
        $fora = $this->colega();
        $this->membro($oculta, PapelNaEquipe::Comissao, $comissao);

        foreach (['ges', 'tra', 'aco', 'ele'] as $aba) {
            $this->entrar($fora);
            $this->client->request('GET', '/voto?aba=' . $aba);
            self::assertResponseIsSuccessful();
            $html = (string) $this->client->getResponse()->getContent();
            self::assertStringContainsString('Visível para todos', $html, $aba . ': a sem equipe continua');
            self::assertStringNotContainsString('Só da comissão', $html, $aba . ': a oculta some');

            $this->entrar($comissao);
            $this->client->request('GET', '/voto?aba=' . $aba);
            self::assertStringContainsString('Só da comissão', (string) $this->client->getResponse()->getContent(), $aba . ': quem está na lista vê');
        }
        self::assertNotNull($visivel->getId());
    }

    #[TestDox('Lote: a auditoria não exclui nem arquiva o que só lê; o rascunho fica')]
    public function testLoteNaoMexeNoQueSoLe(): void
    {
        $a = $this->assembleia();
        $auditoria = $this->colega();
        $this->membro($a, PapelNaEquipe::Auditoria, $auditoria);
        $this->entrar($auditoria);
        $token = $this->token('voto_assembleia_lote');

        $this->client->request('POST', '/voto/assembleias/lote/excluir', ['_token' => $token, 'ids' => [(string) $a->getId()]]);
        self::assertSame(1, (int) $this->em->getConnection()->fetchOne('SELECT COUNT(*) FROM voto.assembleia WHERE id = ?', [$a->getId()]));
    }

    #[TestDox('Perfis: só a Administração entra; o colega recebe 403')]
    public function testTelaPerfisSoParaAdministracao(): void
    {
        $this->assembleia('Evento com equipe');
        $this->entrar($this->colega());
        $this->client->request('GET', '/voto/equipe');
        self::assertResponseStatusCodeSame(403);

        $this->entrar($this->primario());
        $this->client->request('GET', '/voto/equipe');
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('.vH', 'Perfis por assembleia ou votação');
        self::assertStringContainsString('Evento com equipe', (string) $this->client->getResponse()->getContent());
    }

    #[TestDox('Designar liga a linha à conta pelo e-mail, recusa a mesma pessoa duas vezes e deixa sem conta quem não tem vínculo')]
    public function testDesignar(): void
    {
        $a = $this->assembleia();
        $colega = $this->colega();
        $estranho = UserFactory::createOne(['email' => 'estranho-' . uniqid() . '@teste.com.br'])->_real();
        $this->entrar($this->primario());
        $token = $this->token('voto_equipe');
        $url = '/voto/equipe/' . $a->getId() . '/designar';

        $this->client->request('POST', $url, ['_token' => $token, 'nome' => 'Maria', 'email' => $colega->getEmail(), 'cargo' => 'Presidência', 'papel' => 'comissao']);
        $this->client->request('POST', $url, ['_token' => $token, 'nome' => 'Maria de novo', 'email' => strtoupper((string) $colega->getEmail()), 'cargo' => '', 'papel' => 'fiscal']);
        $this->client->request('POST', $url, ['_token' => $token, 'nome' => 'Estranho', 'email' => $estranho->getEmail(), 'cargo' => '', 'papel' => 'conselho']);

        $linhas = $this->em->getConnection()->fetchAllAssociative('SELECT nome, user_id, papel FROM voto.membro_da_equipe WHERE assembleia_id = ? ORDER BY id', [$a->getId()]);
        self::assertCount(2, $linhas, 'a mesma pessoa não acumula perfis');
        self::assertSame($colega->getId(), $linhas[0]['user_id']);
        self::assertNull($linhas[1]['user_id'], 'sem vínculo ativo no escritório não vira conta');
    }

    #[TestDox('A Administração não entra na equipe')]
    public function testAdministracaoNaoEntraNaEquipe(): void
    {
        $a = $this->assembleia();
        $primario = $this->primario();
        $this->entrar($primario);

        $this->client->request('POST', '/voto/equipe/' . $a->getId() . '/designar', ['_token' => $this->token('voto_equipe'), 'nome' => 'Eu', 'email' => $primario->getEmail(), 'papel' => 'comissao']);
        self::assertSame(0, $this->contarLinhas($a));
    }

    #[TestDox('Só em rascunho: com a assembleia publicada, a equipe não muda')]
    public function testSoEmRascunho(): void
    {
        $a = $this->assembleia('Publicada', publicada: true);
        $this->entrar($this->primario());

        $this->client->request('POST', '/voto/equipe/' . $a->getId() . '/designar', ['_token' => $this->token('voto_equipe'), 'nome' => 'Maria', 'papel' => 'comissao']);
        self::assertSame(0, $this->contarLinhas($a));
    }

    #[TestDox('Quem não é a Administração recebe 403 ao designar, alterar, remover, aplicar cargos e usar a equipe padrão')]
    public function testColegaNaoEditaAEquipe(): void
    {
        $a = $this->assembleia();
        $m = $this->membro($a, PapelNaEquipe::Comissao, null, 'Alguém');
        $this->entrar($this->colega());
        $token = $this->token('voto_equipe');
        $id = $a->getId();

        foreach (['/designar', '/' . $m->getId() . '/alterar', '/' . $m->getId() . '/remover', '/categoria', '/padrao'] as $acao) {
            $this->client->request('POST', '/voto/equipe/' . $id . $acao, ['_token' => $token, 'nome' => 'X', 'papel' => 'fiscal', 'categoria' => 'condominio']);
            self::assertResponseStatusCodeSame(403, $acao);
        }
        self::assertSame(1, $this->contarLinhas($a));
    }

    #[TestDox('Aplicar cargos cria 4 linhas "A designar" sem Guardião e Validação; alterar, remover e usar a equipe padrão funcionam')]
    public function testCargosAlterarRemoverEPadrao(): void
    {
        $a = $this->assembleia();
        $this->entrar($this->primario());
        $token = $this->token('voto_equipe');
        $base = '/voto/equipe/' . $a->getId();

        $this->client->request('POST', $base . '/categoria', ['_token' => $token, 'categoria' => 'condominio']);
        $this->client->request('POST', $base . '/categoria', ['_token' => $token, 'categoria' => 'condominio']);
        $linhas = $this->em->getConnection()->fetchAllAssociative('SELECT id, nome, cargo, papel FROM voto.membro_da_equipe WHERE assembleia_id = ? ORDER BY id', [$a->getId()]);
        self::assertSame(['comissao', 'conselho', 'auditoria', 'fiscal'], array_column($linhas, 'papel'), 'repetir não duplica');
        self::assertSame(['A designar'], array_values(array_unique(array_column($linhas, 'nome'))));
        self::assertSame('Presidência da assembleia', $linhas[0]['cargo']);

        $this->client->request('POST', $base . '/' . $linhas[0]['id'] . '/alterar', ['_token' => $token, 'nome' => 'Joana', 'email' => 'joana@teste.com.br', 'cargo' => 'Presidente', 'papel' => 'comissao']);
        self::assertSame('Joana', $this->em->getConnection()->fetchOne('SELECT nome FROM voto.membro_da_equipe WHERE id = ?', [$linhas[0]['id']]));

        $this->client->request('POST', $base . '/' . $linhas[1]['id'] . '/remover', ['_token' => $token]);
        self::assertSame(3, $this->contarLinhas($a));

        $this->client->request('POST', $base . '/padrao', ['_token' => $token]);
        self::assertSame(0, $this->contarLinhas($a));
    }

    #[TestDox('Equipe de assembleia de outro escritório: 404 e nada gravado')]
    public function testEquipeDeOutroEscritorioDa404(): void
    {
        $alheia = AssembleiaFactory::createOne()->_real();
        $this->entrar($this->primario());

        $this->client->request('POST', '/voto/equipe/' . $alheia->getId() . '/designar', ['_token' => $this->token('voto_equipe'), 'nome' => 'Maria', 'papel' => 'comissao']);
        self::assertResponseStatusCodeSame(404);
        self::assertSame(0, (int) $this->em->getConnection()->fetchOne('SELECT COUNT(*) FROM voto.membro_da_equipe WHERE assembleia_id = ?', [$alheia->getId()]));
    }

    #[TestDox('Linha cuja conta perdeu o vínculo ativo no escritório vira Oculto (mesma consulta das equipes)')]
    public function testVinculoPerdidoViraOculto(): void
    {
        $a = $this->assembleia();
        $comissao = $this->colega();
        $this->membro($a, PapelNaEquipe::Comissao, $comissao);
        $acesso = static::getContainer()->get(AcessoAAssembleia::class);
        self::assertSame(NivelNaAssembleia::Editar, $acesso->nivel($comissao, $a));

        $this->em->getConnection()->executeStatement('UPDATE user_tenant SET is_active = false WHERE user_id = ? AND tenant_id = ?', [$comissao->getId(), $this->escritorio->getId()]);

        self::assertSame(NivelNaAssembleia::Oculto, $acesso->nivel($comissao, $a));
    }

    #[TestDox('Quem só lê (auditoria) não vê o Publicar nem a seleção em lote; a comissão vê')]
    public function testVisualizarNaoVeOsBotoesDeEscrita(): void
    {
        $a = $this->assembleia('Evento dos botões');
        $auditoria = $this->colega();
        $comissao = $this->colega();
        $this->membro($a, PapelNaEquipe::Auditoria, $auditoria);
        $this->membro($a, PapelNaEquipe::Comissao, $comissao);

        $this->entrar($comissao);
        $this->client->request('GET', '/voto?aba=ges');
        self::assertSelectorExists('#assembleia-' . $a->getId() . ' a[href*="/publicar"], #assembleia-' . $a->getId() . ' form[action*="/publicar"]');
        self::assertSelectorExists('#assembleia-' . $a->getId() . ' input[data-voto-lote-item]');

        $this->entrar($auditoria);
        $this->client->request('GET', '/voto?aba=ges');
        self::assertSelectorExists('#assembleia-' . $a->getId());
        self::assertSelectorNotExists('#assembleia-' . $a->getId() . ' form[action*="/publicar"]');
        self::assertSelectorNotExists('#assembleia-' . $a->getId() . ' input[data-voto-lote-item]');
        self::assertSelectorNotExists('#assembleia-' . $a->getId() . ' [data-voto-revelar]');
        self::assertSelectorNotExists('#assembleia-' . $a->getId() . ' form[action*="/duplicar"]');
    }

    #[TestDox('Perfis traz o campo Votação do desenho, com a equipe padrão e uma opção por assembleia')]
    public function testPerfisTemOCampoVotacao(): void
    {
        $a = $this->assembleia('Evento do campo');
        $this->entrar($this->primario());

        $this->client->request('GET', '/voto/equipe?grupo=' . $a->getId());
        self::assertSelectorTextContains('.voto-equipe-votacao label', 'Votação');
        self::assertCount(2, $this->client->getCrawler()->filter('.voto-equipe-votacao option'));
        self::assertSelectorExists('.voto-equipe-votacao option[selected][value="' . $a->getId() . '"]');
        $this->client->request('GET', '/voto/equipe?grupo=padrao');
        self::assertSelectorTextContains('.voto-equipe', 'escolha a votação no campo acima.');
    }

    private function assembleia(string $titulo = 'Assembleia da equipe', bool $publicada = false): Assembleia
    {
        $fabrica = AssembleiaFactory::new(['tenant' => $this->escritorio, 'titulo' => $titulo]);
        $a = ($publicada ? $fabrica->publicada() : $fabrica)->create()->_real();
        $pauta = PautaDaAssembleiaFactory::new(['assembleia' => $a, 'titulo' => $titulo])->comOpcoesPadrao();
        ($publicada ? $pauta->encerrada() : $pauta)->create();

        return $a;
    }

    private function membro(Assembleia $a, PapelNaEquipe $papel, ?User $user, string $nome = 'Membro'): MembroDaEquipe
    {
        $m = new MembroDaEquipe($a, $papel, $nome, null, '', $user);
        $this->em->persist($m);
        $this->em->flush();

        return $m;
    }

    private function contarLinhas(Assembleia $a): int
    {
        return (int) $this->em->getConnection()->fetchOne('SELECT COUNT(*) FROM voto.membro_da_equipe WHERE assembleia_id = ?', [$a->getId()]);
    }

    private function primario(): User
    {
        return $this->usuarioNoEscritorio(true, true);
    }

    private function colega(bool $comModulo = true): User
    {
        return $this->usuarioNoEscritorio(false, $comModulo);
    }

    private function usuarioNoEscritorio(bool $sistema, bool $comModulo): User
    {
        $user = UserFactory::createOne(['fullName' => 'Pessoa ' . uniqid(), 'roles' => ['ROLE_USER']])->_real();
        $papel = (new TenantRole())->setTenant($this->escritorio)->setName('Perfil ' . uniqid())->setIsSystem($sistema);
        $this->em->persist($papel);
        if ($comModulo) {
            $permissao = $this->em->getRepository(Permission::class)->findOneBy(['code' => 'modules.voto.view']);
            if (null === $permissao) {
                $permissao = (new Permission())->setCode('modules.voto.view')->setDescription('Acesso ao módulo Voto (eleições e votações)')->setGroup('modules');
                $this->em->persist($permissao);
            }
            $concessao = (new TenantRolePermission())->setTenantRole($papel)->setPermission($permissao);
            $this->em->persist($concessao);
            $papel->getTenantRolePermissions()->add($concessao);
        }
        $this->em->persist((new UserTenant($user, $this->escritorio))->setTenantRole($papel));
        $this->em->flush();

        return $user;
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
