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
        self::assertCount(1, $crawler->filter('.js-editar-anotacao'), 'o autor vê o lápis na própria anotação');

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
        $client->submit($crawler->filter('form[action*="/anotacoes/' . $id . '/excluir"]')->form());

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
        self::assertCount(0, $crawler->filter('form[action*="/anotacoes/' . $id . '/excluir"]'), 'lixeira some depois de 48h');

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
        self::assertCount(0, $crawler->filter('form[action*="/anotacoes/' . $id . '/excluir"]'));

        $client->request('POST', '/cobrancas/casos/anotacoes/' . $id . '/editar', [
            'editar_anotacao' => ['texto' => 'reescrevendo um contato'],
        ]);

        self::assertResponseRedirects();
        self::assertSame('Contato por Telefone', $this->recarregar($id)->getDescricao());
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
