<?php

declare(strict_types=1);

namespace App\Tests\Cobranca\Functional;

use App\Cobranca\Controller\CasoController;
use App\Cobranca\Entity\EventoHistorico;
use App\Cobranca\Enum\StatusCaso;
use App\Cobranca\Enum\TipoEventoHistorico;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\TestDox;

/**
 * Anotação livre na linha do tempo do Caso (ajuste 2026-07), via HTTP: o campo inline da aba Histórico
 * grava um evento do tipo Anotacao. Prova o gate de capacidade, o isolamento entre escritórios e o
 * bloqueio em caso encerrado — e que a anotação aparece na timeline junto dos eventos automáticos.
 */
#[CoversClass(CasoController::class)]
final class AnotacaoHistoricoControllerTest extends CobrancaWebTestCase
{
    #[TestDox('Anotar pela aba Histórico grava evento do tipo Anotacao com o autor logado')]
    public function testAnotacaoGravaEventoNoHistorico(): void
    {
        $client = static::createClient();
        [$usuario, $tenant] = $this->criarAdminLogado($client);
        [, $caso] = $this->semearGrafo($tenant);
        $em = static::getContainer()->get(EntityManagerInterface::class);

        $crawler = $client->request('GET', '/cobrancas/objetos/' . $caso->getObjeto()->getId());
        self::assertResponseIsSuccessful();

        $form = $crawler->filter('form[action*="anotacoes"]')->form();
        $form['registrar_anotacao[texto]'] = 'Sindico confirmou a venda do lote em 2024.';
        $client->submit($form);

        self::assertResponseRedirects();

        $eventos = $em->getRepository(EventoHistorico::class)->findBy([
            'caso' => $caso,
            'tipo' => TipoEventoHistorico::Anotacao,
        ]);

        self::assertCount(1, $eventos);
        self::assertSame('Sindico confirmou a venda do lote em 2024.', $eventos[0]->getDescricao());
        self::assertSame($usuario->getId(), $eventos[0]->getUsuario()?->getId(), 'autoria é do usuário logado');
        self::assertSame($tenant->getId(), $eventos[0]->getTenant()?->getId());

        // A anotação passa a conviver com os eventos automáticos na timeline.
        $client->followRedirect();
        self::assertStringContainsString('Sindico confirmou a venda do lote em 2024.', (string) $client->getResponse()->getContent());
    }

    #[TestDox('Anotação vazia é recusada e nada é gravado')]
    public function testAnotacaoVaziaNaoGrava(): void
    {
        $client = static::createClient();
        [, $tenant] = $this->criarAdminLogado($client);
        [, $caso] = $this->semearGrafo($tenant);
        $em = static::getContainer()->get(EntityManagerInterface::class);

        $crawler = $client->request('GET', '/cobrancas/objetos/' . $caso->getObjeto()->getId());
        $form = $crawler->filter('form[action*="anotacoes"]')->form();
        $form['registrar_anotacao[texto]'] = '   ';
        $client->submit($form);

        self::assertResponseRedirects();
        self::assertCount(0, $em->getRepository(EventoHistorico::class)->findBy([
            'caso' => $caso,
            'tipo' => TipoEventoHistorico::Anotacao,
        ]));
    }

    #[TestDox('Caso de outro escritório responde 404 e não grava (anti-IDOR)')]
    public function testAnotacaoEmCasoDeOutroTenantDa404(): void
    {
        $client = static::createClient();
        [, $tenant] = $this->criarAdminLogado($client);
        $outro = $this->tenantAvulso();
        [, $casoAlheio] = $this->semearGrafo($outro);
        $em = static::getContainer()->get(EntityManagerInterface::class);

        // Um GET antes: `tokenCsrf` depende de uma requisição já feita pelo browser de teste. Manter o
        // token VÁLIDO é proposital — assim o 404 prova a guarda de tenant, e não uma recusa de CSRF.
        $client->request('GET', '/cobrancas/casos');

        $client->request('POST', '/cobrancas/casos/' . $casoAlheio->getId() . '/anotacoes', [
            'registrar_anotacao' => [
                'texto' => 'tentativa cross-tenant',
                '_token' => $this->tokenCsrf($client, 'registrar_anotacao'),
            ],
        ]);

        self::assertResponseStatusCodeSame(404);
        self::assertCount(0, $em->getRepository(EventoHistorico::class)->findBy([
            'caso' => $casoAlheio,
            'tipo' => TipoEventoHistorico::Anotacao,
        ]));
        // O tenant do requisitante também não pode ter ganhado nada.
        self::assertCount(0, $em->getRepository(EventoHistorico::class)->findBy([
            'tenant' => $tenant,
            'tipo' => TipoEventoHistorico::Anotacao,
        ]));
    }

    #[TestDox('Caso encerrado não aceita anotação e o campo nem é exibido')]
    public function testCasoEncerradoNaoAceitaAnotacao(): void
    {
        $client = static::createClient();
        [, $tenant] = $this->criarAdminLogado($client);
        [, $caso] = $this->semearGrafo($tenant, ['status' => StatusCaso::Encerrado]);
        $em = static::getContainer()->get(EntityManagerInterface::class);

        $crawler = $client->request('GET', '/cobrancas/objetos/' . $caso->getObjeto()->getId());
        self::assertResponseIsSuccessful();
        self::assertCount(0, $crawler->filter('form[action*="anotacoes"]'), 'campo não aparece em caso encerrado');

        $client->request('POST', '/cobrancas/casos/' . $caso->getId() . '/anotacoes', [
            'registrar_anotacao' => [
                'texto' => 'anotação em caso encerrado',
                '_token' => $this->tokenCsrf($client, 'registrar_anotacao'),
            ],
        ]);

        self::assertResponseRedirects();
        self::assertCount(0, $em->getRepository(EventoHistorico::class)->findBy([
            'caso' => $caso,
            'tipo' => TipoEventoHistorico::Anotacao,
        ]));
    }

    #[TestDox('Sem a capacidade de gerenciar, a rota nega e nada é gravado')]
    public function testSemCapacidadeNaoAnota(): void
    {
        $client = static::createClient();
        [, $tenant] = $this->criarOperadorSemCapacidade($client);
        [, $caso] = $this->semearGrafo($tenant);
        $em = static::getContainer()->get(EntityManagerInterface::class);

        $client->request('POST', '/cobrancas/casos/' . $caso->getId() . '/anotacoes', [
            'registrar_anotacao' => ['texto' => 'sem permissão'],
        ]);

        // O módulo nega com redirect + flash (`AutorizacaoCobranca::semAcesso`), não com 403.
        self::assertResponseRedirects();
        self::assertCount(0, $em->getRepository(EventoHistorico::class)->findBy([
            'caso' => $caso,
            'tipo' => TipoEventoHistorico::Anotacao,
        ]));
    }
}
