<?php

declare(strict_types=1);

namespace App\Tests\Cobranca\Functional;

use App\Cobranca\Controller\CasoController;
use App\Cobranca\Entity\CasoCobranca;
use App\Cobranca\Entity\EventoHistorico;
use App\Cobranca\Enum\TipoEventoHistorico;
use App\Entity\Auth\User;
use App\Entity\Tenant\Tenant;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\TestDox;

/**
 * Correção e exclusão de anotação na janela de 48h (ajuste 2026-07-22), via HTTP. Prova as três
 * guardas no caminho real — só anotação, só o autor, só dentro da janela — mais o isolamento entre
 * escritórios.
 *
 * Os POSTs saem do FORMULÁRIO RENDERIZADO, não de token forjado: o CSRF deste projeto é stateless
 * (o valor real é injetado no submit), então montar o token à mão testaria outra coisa. De quebra,
 * submeter o form da página prova também que os controles aparecem — e somem — na hora certa.
 */
#[CoversClass(CasoController::class)]
final class AnotacaoEdicaoControllerTest extends CobrancaWebTestCase
{
    #[TestDox('Autor corrige a própria anotação e a timeline passa a mostrar "(editado)"')]
    public function testAutorCorrigeDentroDaJanela(): void
    {
        $client = static::createClient();
        [$usuario, $tenant] = $this->criarAdminLogado($client);
        [, $caso] = $this->semearGrafo($tenant);
        $evento = $this->semearAnotacao($caso, $tenant, $usuario, 'texto errado');
        $id = (int) $evento->getId();

        $crawler = $client->request('GET', '/cobrancas/objetos/' . $caso->getObjeto()->getId());
        self::assertResponseIsSuccessful();
        // Desde a reorganização de UX a anotação aparece em DOIS lugares — "Anotações recentes" (aba
        // Cobrança) e a linha do tempo (aba Histórico) —, e o lápis acompanha os dois. Contar o total
        // esconderia a regressão de um dos lados; por isso conferimos cada painel.
        self::assertCount(1, $crawler->filter('#tab-cobranca .js-editar-anotacao'), 'o autor vê o lápis nas anotações recentes');
        self::assertCount(1, $crawler->filter('#tab-historico .js-editar-anotacao'), 'e também na linha do tempo');

        // O modal é compartilhado: no navegador o JS injeta a action da linha clicada. Aqui fazemos o
        // mesmo, para o teste exercitar o form real (com o CSRF real) na URL daquela anotação.
        $form = $crawler->filter('#formEditarAnotacao')->form();
        $form->getNode()->setAttribute('action', '/cobrancas/casos/anotacoes/' . $id . '/editar');
        $form['editar_anotacao[texto]'] = 'texto corrigido';
        $client->submit($form);

        self::assertResponseRedirects();
        $fresco = $this->recarregar($id);
        self::assertSame('texto corrigido', $fresco->getDescricao());
        self::assertNotNull($fresco->getEditadoEm(), 'a correção precisa deixar marca');

        $client->request('GET', '/cobrancas/objetos/' . $caso->getObjeto()->getId());
        $html = (string) $client->getResponse()->getContent();
        self::assertStringContainsString('texto corrigido', $html);
        self::assertStringContainsString('(editado)', $html);
    }

    #[TestDox('Autor exclui a própria anotação e ela some do histórico')]
    public function testAutorExcluiDentroDaJanela(): void
    {
        $client = static::createClient();
        [$usuario, $tenant] = $this->criarAdminLogado($client);
        [, $caso] = $this->semearGrafo($tenant);
        $evento = $this->semearAnotacao($caso, $tenant, $usuario, 'para apagar');
        $id = (int) $evento->getId();

        $crawler = $client->request('GET', '/cobrancas/objetos/' . $caso->getObjeto()->getId());

        // A exclusão passou a exigir confirmação num modal COMPARTILHADO (SPEC UX §10): o botão da linha
        // carrega a URL e o CSRF daquela anotação, e o JS os injeta no form único. Aqui fazemos o mesmo,
        // para o teste exercitar o form real com o token real — como já se faz no modal de correção.
        $botao = $crawler->filter('.js-excluir-anotacao[data-url*="/anotacoes/' . $id . '/excluir"]')->eq(0);
        self::assertCount(1, $botao, 'a lixeira da anotação precisa existir para o autor');

        $form = $crawler->filter('#formExcluirAnotacao')->form();
        $form->getNode()->setAttribute('action', (string) $botao->attr('data-url'));
        $form['_token'] = (string) $botao->attr('data-token');
        $client->submit($form);

        self::assertResponseRedirects();
        self::assertNull($this->buscar($id), 'exclusão é definitiva, sem marca de removida');
    }

    #[TestDox('Passadas as 48h os controles somem e o servidor recusa')]
    public function testForaDaJanelaRecusa(): void
    {
        $client = static::createClient();
        [$usuario, $tenant] = $this->criarAdminLogado($client);
        [, $caso] = $this->semearGrafo($tenant);
        $evento = $this->semearAnotacao($caso, $tenant, $usuario, 'antiga', new \DateTimeImmutable('-3 days'));
        $id = (int) $evento->getId();

        $crawler = $client->request('GET', '/cobrancas/objetos/' . $caso->getObjeto()->getId());
        self::assertCount(0, $crawler->filter('.js-editar-anotacao'), 'lápis some depois de 48h');
        self::assertCount(0, $crawler->filter('.js-excluir-anotacao[data-url*="/anotacoes/' . $id . '/excluir"]'), 'lixeira some depois de 48h');

        // A UI escondeu, mas quem decide é o servidor: POST forjado tem de ser recusado.
        $client->request('POST', '/cobrancas/casos/anotacoes/' . $id . '/editar', [
            'editar_anotacao' => ['texto' => 'tarde demais'],
        ]);
        self::assertResponseRedirects();
        self::assertSame('antiga', $this->recarregar($id)->getDescricao(), 'texto não pode ter mudado');
    }

    #[TestDox('Anotação de outra pessoa não mostra controles nem aceita POST forjado')]
    public function testAnotacaoDeOutroUsuario(): void
    {
        $client = static::createClient();
        [, $tenant] = $this->criarAdminLogado($client);
        [, $caso] = $this->semearGrafo($tenant);
        $autorAlheio = $this->criarColega();
        $evento = $this->semearAnotacao($caso, $tenant, $autorAlheio, 'do colega');
        $id = (int) $evento->getId();

        $crawler = $client->request('GET', '/cobrancas/objetos/' . $caso->getObjeto()->getId());
        self::assertCount(0, $crawler->filter('.js-editar-anotacao'), 'ninguém edita anotação de colega');

        $client->request('POST', '/cobrancas/casos/anotacoes/' . $id . '/editar', [
            'editar_anotacao' => ['texto' => 'mexendo no alheio'],
        ]);

        self::assertResponseRedirects();
        self::assertSame('do colega', $this->recarregar($id)->getDescricao());
    }

    #[TestDox('Evento automático (contato) não é editável nem apagável')]
    public function testEventoAutomaticoNaoEditavel(): void
    {
        $client = static::createClient();
        [$usuario, $tenant] = $this->criarAdminLogado($client);
        [, $caso] = $this->semearGrafo($tenant);
        $evento = $this->semearAnotacao($caso, $tenant, $usuario, 'Contato por Telefone', null, TipoEventoHistorico::ContatoRealizado);
        $id = (int) $evento->getId();

        $crawler = $client->request('GET', '/cobrancas/objetos/' . $caso->getObjeto()->getId());
        self::assertCount(0, $crawler->filter('.js-editar-anotacao'), 'contato registrado não ganha lápis');
        // Seletor do gatilho ATUAL (o form por linha virou botão + modal compartilhado). Com o seletor
        // antigo (`form[action*=…]`) este assert passaria sempre, provando nada.
        self::assertCount(
            0,
            $crawler->filter('.js-excluir-anotacao[data-url*="/anotacoes/' . $id . '/excluir"]'),
            'contato registrado não ganha lixeira',
        );

        $client->request('POST', '/cobrancas/casos/anotacoes/' . $id . '/editar', [
            'editar_anotacao' => ['texto' => 'reescrevendo um contato'],
        ]);

        self::assertResponseRedirects();
        self::assertSame('Contato por Telefone', $this->recarregar($id)->getDescricao());
    }

    #[TestDox('Evento automático também não pode ser EXCLUÍDO por POST forjado')]
    public function testEventoAutomaticoNaoPodeSerExcluido(): void
    {
        $client = static::createClient();
        [$usuario, $tenant] = $this->criarAdminLogado($client);
        [, $caso] = $this->semearGrafo($tenant);
        $evento = $this->semearAnotacao($caso, $tenant, $usuario, 'Pagamento registrado: R$ 10,00', null, TipoEventoHistorico::PagamentoRegistrado);
        $id = (int) $evento->getId();

        // `tokenCsrf()` lê a sessão da última requisição — precisa de um GET antes.
        $client->request('GET', '/cobrancas/objetos/' . $caso->getObjeto()->getId());

        // Com o CSRF CERTO daquele evento: o que recusa aqui é a regra (só anotação livre é apagável),
        // não o token. Sem passar o token válido, o teste provaria só o CSRF e deixaria a regra sem prova.
        $client->request('POST', '/cobrancas/casos/anotacoes/' . $id . '/excluir', [
            '_token' => $this->tokenCsrf($client, 'excluir_anotacao_' . $id),
        ]);

        self::assertResponseRedirects();
        self::assertNotNull($this->buscar($id), 'evento do sistema é imutável — não pode ser apagado');
    }

    #[TestDox('Excluir anotação de outro escritório responde 404 e não apaga nada (anti-IDOR)')]
    public function testExclusaoDeAnotacaoDeOutroTenant(): void
    {
        $client = static::createClient();
        [, $meuTenant] = $this->criarAdminLogado($client);
        [, $meuCaso] = $this->semearGrafo($meuTenant);
        $outroTenant = $this->tenantAvulso();
        [, $casoAlheio] = $this->semearGrafo($outroTenant);
        $autorAlheio = $this->criarColega();
        $evento = $this->semearAnotacao($casoAlheio, $outroTenant, $autorAlheio, 'anotacao do vizinho');
        $id = (int) $evento->getId();

        // GET na MINHA página (não na do vizinho — essa daria 404 e é o que o POST vai provar) só para
        // `tokenCsrf()` ter uma sessão de onde ler.
        $client->request('GET', '/cobrancas/objetos/' . $meuCaso->getObjeto()->getId());

        $client->request('POST', '/cobrancas/casos/anotacoes/' . $id . '/excluir', [
            '_token' => $this->tokenCsrf($client, 'excluir_anotacao_' . $id),
        ]);

        self::assertResponseStatusCodeSame(404);

        // Por SQL cru: o filtro de tenant do Doctrine esconde do ORM o registro do outro escritório —
        // e é justamente esse registro que precisamos provar que continua lá.
        $texto = static::getContainer()->get(EntityManagerInterface::class)->getConnection()
            ->fetchOne('SELECT descricao FROM cobranca_evento_historico WHERE id = ?', [$id]);
        self::assertSame('anotacao do vizinho', $texto, 'anotação de outro escritório não pode ser apagada');
    }

    #[TestDox('Sem a capacidade de gerenciar cobrança, editar e excluir são barrados no servidor')]
    public function testEdicaoEExclusaoExigemCapacidadeDeGerenciar(): void
    {
        $client = static::createClient();
        // Operador do próprio tenant, com o MÓDULO cobranças, mas SEM `resources.cobranca.gerenciar`.
        // É o caso que a UI já esconde — aqui provamos que esconder não é o que protege.
        [$operador, $tenant] = $this->criarOperadorSemCapacidade($client);
        [, $caso] = $this->semearGrafo($tenant);
        $evento = $this->semearAnotacao($caso, $tenant, $operador, 'texto original');
        $id = (int) $evento->getId();

        $client->request('POST', '/cobrancas/casos/anotacoes/' . $id . '/editar', [
            'editar_anotacao' => ['texto' => 'sem capacidade nao edita'],
        ]);
        self::assertResponseRedirects();
        self::assertSame('texto original', $this->recarregar($id)->getDescricao(), 'edição sem capacidade não pode passar');

        $client->request('POST', '/cobrancas/casos/anotacoes/' . $id . '/excluir', [
            '_token' => $this->tokenCsrf($client, 'excluir_anotacao_' . $id),
        ]);
        self::assertResponseRedirects();
        self::assertNotNull($this->buscar($id), 'exclusão sem capacidade não pode passar');
    }

    #[TestDox('Editar exige CSRF: dentro da janela, pelo autor, mas sem token o texto não muda')]
    public function testEdicaoExigeCsrf(): void
    {
        $client = static::createClient();
        [$usuario, $tenant] = $this->criarAdminLogado($client);
        [, $caso] = $this->semearGrafo($tenant);
        $evento = $this->semearAnotacao($caso, $tenant, $usuario, 'texto protegido');
        $id = (int) $evento->getId();

        // Tudo o mais está CERTO (autor, janela, capacidade, tenant): o único ingrediente que falta é o
        // token. Assim a recusa só pode ser atribuída ao CSRF.
        $client->request('POST', '/cobrancas/casos/anotacoes/' . $id . '/editar', [
            'editar_anotacao' => ['texto' => 'reescrita sem token'],
        ]);

        self::assertResponseRedirects();
        self::assertSame('texto protegido', $this->recarregar($id)->getDescricao(), 'sem CSRF a correção não pode passar');
    }

    #[TestDox('Conteúdo perigoso é sanitizado na ESCRITA — o banco não guarda script')]
    public function testConteudoPerigosoEhSanitizadoAoGravar(): void
    {
        $client = static::createClient();
        [, $tenant] = $this->criarAdminLogado($client);
        [, $caso] = $this->semearGrafo($tenant);

        $crawler = $client->request('GET', '/cobrancas/objetos/' . $caso->getObjeto()->getId());
        $form = $crawler->filter('form[action*="/anotacoes"]')->form();
        $form['registrar_anotacao[texto]'] = '<p>combinado<script>alert(1)</script></p><p onclick="roubar()">x</p>';
        $client->submit($form);

        self::assertResponseRedirects();

        $texto = (string) static::getContainer()->get(EntityManagerInterface::class)->getConnection()
            ->fetchOne("SELECT descricao FROM cobranca_evento_historico WHERE caso_id = ? AND tipo = 'anotacao' ORDER BY id DESC LIMIT 1", [$caso->getId()]);

        self::assertStringContainsString('combinado', $texto, 'o texto legítimo tem de sobreviver');
        self::assertStringNotContainsString('<script', $texto, 'script não pode chegar ao banco');
        self::assertStringNotContainsString('onclick', $texto, 'handler não pode chegar ao banco');
    }

    #[TestDox('Registrar, corrigir e excluir voltam para a aba Cobrança, onde as anotações recentes ficam')]
    public function testVoltaParaAbaCobranca(): void
    {
        $client = static::createClient();
        [$usuario, $tenant] = $this->criarAdminLogado($client);
        [, $caso] = $this->semearGrafo($tenant);
        $objetoId = $caso->getObjeto()->getId();
        $evento = $this->semearAnotacao($caso, $tenant, $usuario, 'para mexer');
        $id = (int) $evento->getId();

        $crawler = $client->request('GET', '/cobrancas/objetos/' . $objetoId);

        // 1) Registrar
        $form = $crawler->filter('form[action*="/anotacoes"]')->form();
        $form['registrar_anotacao[texto]'] = 'nova anotacao';
        $client->submit($form);
        self::assertResponseRedirects('/cobrancas/objetos/' . $objetoId . '?aba=cobranca');

        // 2) Corrigir
        $crawler = $client->followRedirect();
        $formEdicao = $crawler->filter('#formEditarAnotacao')->form();
        $formEdicao->getNode()->setAttribute('action', '/cobrancas/casos/anotacoes/' . $id . '/editar');
        $formEdicao['editar_anotacao[texto]'] = 'texto corrigido';
        $client->submit($formEdicao);
        self::assertResponseRedirects('/cobrancas/objetos/' . $objetoId . '?aba=cobranca');

        // 3) Excluir (modal compartilhado: action e CSRF vêm do botão da linha)
        $crawler = $client->followRedirect();
        $botaoExcluir = $crawler->filter('.js-excluir-anotacao[data-url*="/anotacoes/' . $id . '/excluir"]')->eq(0);
        $formExclusao = $crawler->filter('#formExcluirAnotacao')->form();
        $formExclusao->getNode()->setAttribute('action', (string) $botaoExcluir->attr('data-url'));
        $formExclusao['_token'] = (string) $botaoExcluir->attr('data-token');
        $client->submit($formExclusao);
        self::assertResponseRedirects('/cobrancas/objetos/' . $objetoId . '?aba=cobranca');
    }

    #[TestDox('Anotação de outro escritório responde 404 (anti-IDOR)')]
    public function testAnotacaoDeOutroTenant(): void
    {
        $client = static::createClient();
        $this->criarAdminLogado($client);
        $outroTenant = $this->tenantAvulso();
        [, $casoAlheio] = $this->semearGrafo($outroTenant);
        $autorAlheio = $this->criarColega();
        $evento = $this->semearAnotacao($casoAlheio, $outroTenant, $autorAlheio, 'de outro escritorio');
        $id = (int) $evento->getId();

        $client->request('POST', '/cobrancas/casos/anotacoes/' . $id . '/editar', [
            'editar_anotacao' => ['texto' => 'cross-tenant'],
        ]);

        self::assertResponseStatusCodeSame(404);

        // Verificação por SQL cru: o filtro de tenant do Doctrine esconde do ORM o registro do outro
        // escritório — e é exatamente esse registro que precisamos provar que ficou intacto.
        $texto = static::getContainer()->get(EntityManagerInterface::class)->getConnection()
            ->fetchOne('SELECT descricao FROM cobranca_evento_historico WHERE id = ?', [$id]);
        self::assertSame('de outro escritorio', $texto);
    }

    /** Lê o estado REAL do banco: o EM é resetado entre requisições e o cache de identidade mente. */
    private function buscar(int $id): ?EventoHistorico
    {
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $em->clear();

        return $em->getRepository(EventoHistorico::class)->find($id);
    }

    private function recarregar(int $id): EventoHistorico
    {
        $evento = $this->buscar($id);
        self::assertNotNull($evento, 'o evento deveria existir');

        return $evento;
    }

    private function semearAnotacao(
        CasoCobranca $caso,
        Tenant $tenant,
        User $autor,
        string $texto,
        ?\DateTimeImmutable $quando = null,
        TipoEventoHistorico $tipo = TipoEventoHistorico::Anotacao,
    ): EventoHistorico {
        $em = static::getContainer()->get(EntityManagerInterface::class);

        $evento = new EventoHistorico();
        $evento->setTenant($tenant)
            ->setCaso($caso)
            ->setTipo($tipo)
            ->setUsuario($autor)
            ->setDescricao($texto)
            ->setOcorridoEm($quando ?? new \DateTimeImmutable());

        $em->persist($evento);
        $em->flush();

        return $evento;
    }

    private function criarColega(): User
    {
        $em = static::getContainer()->get(EntityManagerInterface::class);

        $user = new User();
        $user->setEmail('colega' . uniqid() . '@teste.local');
        $user->setFullName('Colega de Cobranca');   // NOT NULL no schema
        $user->setRoles(['ROLE_USER']);
        $user->setPassword('x');
        $em->persist($user);
        $em->flush();

        return $user;
    }
}
