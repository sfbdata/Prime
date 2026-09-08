<?php

declare(strict_types=1);

namespace App\Tests\Cobranca\Functional;

use App\Cobranca\Controller\CasoController;
use App\Cobranca\Entity\CasoCobranca;
use App\Cobranca\Enum\StatusCaso;
use App\Tests\Factory\Pasta\PastaFactory;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\TestDox;

/**
 * Cancelar judicialização (par inverso de `judicializar()`, em `CasoController`). Existe para dois
 * problemas reais medidos em produção: a pasta vinculada pode ter sido excluída sem que Cobrança
 * fosse avisada (o caso ficava preso em `Judicializado` para sempre — Problema A), ou pode ter sido
 * vinculada por engano a outro caso. Mesmo gate de `judicializar()`: capacidade
 * `resources.cobranca.gerenciar` + módulo `pastas`.
 */
#[CoversClass(CasoController::class)]
final class CancelarJudicializacaoControllerTest extends CobrancaWebTestCase
{
    #[TestDox('Happy path: desvincula a pasta e volta o status para ativo')]
    public function testCancelarHappyPathComPastaVinculada(): void
    {
        $client = static::createClient();
        [, $tenant] = $this->criarAdminLogado($client);
        $pasta = PastaFactory::createOne(['tenant' => $tenant])->_real();
        [, $caso] = $this->semearGrafo($tenant, ['status' => StatusCaso::Judicializado, 'pastaJudicial' => $pasta]);
        $casoId = (int) $caso->getId();

        $crawler = $client->request('GET', '/cobrancas/objetos/' . $caso->getObjeto()->getId());
        $token = $this->tokenDoFormulario($crawler, 'cancelar_judicializacao');

        $client->request('POST', '/cobrancas/casos/' . $casoId . '/cancelar-judicializacao', [
            'cancelar_judicializacao' => ['motivo' => 'Judicializado por engano', '_token' => $token],
        ]);

        self::assertResponseRedirects('/cobrancas/objetos/' . $caso->getObjeto()->getId());

        $em = static::getContainer()->get(EntityManagerInterface::class);
        $em->clear();
        $fresh = $em->find(CasoCobranca::class, $casoId);
        self::assertSame(StatusCaso::Ativo, $fresh->getStatus());
        self::assertNull($fresh->getPastaJudicial());
        // A pasta em si não é apagada — só o vínculo com o caso.
        self::assertNotNull($em->find(\App\Pasta\Entity\Pasta::class, $pasta->getId()));
    }

    #[TestDox('Problema A de verdade: pasta já excluída (pastaJudicial null) — cancela do mesmo jeito')]
    public function testCancelarComPastaJaNula(): void
    {
        $client = static::createClient();
        [, $tenant] = $this->criarAdminLogado($client);
        // Simula o estado travado: judicializado, mas sem pasta (como ficaria depois de uma exclusão
        // real, cuja FK ON DELETE SET NULL zera o vínculo sem avisar Cobrança).
        [, $caso] = $this->semearGrafo($tenant, ['status' => StatusCaso::Judicializado]);
        $casoId = (int) $caso->getId();

        $crawler = $client->request('GET', '/cobrancas/objetos/' . $caso->getObjeto()->getId());
        $token = $this->tokenDoFormulario($crawler, 'cancelar_judicializacao');

        $client->request('POST', '/cobrancas/casos/' . $casoId . '/cancelar-judicializacao', [
            'cancelar_judicializacao' => ['motivo' => 'Pasta já excluída, status preso', '_token' => $token],
        ]);

        self::assertResponseRedirects('/cobrancas/objetos/' . $caso->getObjeto()->getId());

        $em = static::getContainer()->get(EntityManagerInterface::class);
        $em->clear();
        self::assertSame(StatusCaso::Ativo, $em->find(CasoCobranca::class, $casoId)->getStatus());
    }

    #[TestDox('Depois de cancelar, Judicializar volta a funcionar no mesmo caso')]
    public function testFechaOCicloJudicializarDeNovoDepoisDeCancelar(): void
    {
        $client = static::createClient();
        [, $tenant] = $this->criarAdminLogado($client);
        $pasta = PastaFactory::createOne(['tenant' => $tenant])->_real();
        [, $caso] = $this->semearGrafo($tenant, ['status' => StatusCaso::Judicializado, 'pastaJudicial' => $pasta]);
        $casoId = (int) $caso->getId();
        $objetoId = (int) $caso->getObjeto()->getId();

        $crawler = $client->request('GET', '/cobrancas/objetos/' . $objetoId);
        $token = $this->tokenDoFormulario($crawler, 'cancelar_judicializacao');
        $client->request('POST', '/cobrancas/casos/' . $casoId . '/cancelar-judicializacao', [
            'cancelar_judicializacao' => ['motivo' => 'Vou judicializar de novo', '_token' => $token],
        ]);

        $pastaNova = PastaFactory::createOne(['tenant' => $tenant])->_real();
        $crawler = $client->request('GET', '/cobrancas/objetos/' . $objetoId);
        $tokenJudicializar = $this->tokenDoFormulario($crawler, 'judicializar_caso');

        $client->request('POST', '/cobrancas/casos/' . $casoId . '/judicializar', [
            'judicializar_caso' => ['modo' => 'vincular', 'pastaId' => (string) $pastaNova->getId(), '_token' => $tokenJudicializar],
        ]);

        $em = static::getContainer()->get(EntityManagerInterface::class);
        $em->clear();
        $fresh = $em->find(CasoCobranca::class, $casoId);
        self::assertSame(StatusCaso::Judicializado, $fresh->getStatus());
        self::assertSame((int) $pastaNova->getId(), (int) $fresh->getPastaJudicial()->getId());
    }

    #[TestDox('Cancelar caso que nunca foi judicializado: erro de domínio, nada muda')]
    public function testCancelarCasoAtivoNaoMudaNada(): void
    {
        $client = static::createClient();
        [, $tenant] = $this->criarAdminLogado($client);
        [, $caso] = $this->semearGrafo($tenant);
        $casoId = (int) $caso->getId();

        $crawler = $client->request('GET', '/cobrancas/objetos/' . $caso->getObjeto()->getId());
        $token = $this->tokenDoFormulario($crawler, 'judicializar_caso');

        $client->request('POST', '/cobrancas/casos/' . $casoId . '/cancelar-judicializacao', [
            'cancelar_judicializacao' => ['motivo' => 'Qualquer', '_token' => 'irrelevante-mas-precisa-existir'],
        ]);

        self::assertResponseRedirects('/cobrancas/objetos/' . $caso->getObjeto()->getId());

        $em = static::getContainer()->get(EntityManagerInterface::class);
        $em->clear();
        self::assertSame(StatusCaso::Ativo, $em->find(CasoCobranca::class, $casoId)->getStatus());
    }

    #[TestDox('IDOR: cancelar judicialização de caso de OUTRO tenant devolve 404')]
    public function testCancelarCrossTenant404(): void
    {
        $client = static::createClient();
        $this->criarAdminLogado($client);
        [, $casoAlheio] = $this->semearGrafo($this->tenantAvulso(), ['status' => StatusCaso::Judicializado]);

        $client->request('POST', '/cobrancas/casos/' . $casoAlheio->getId() . '/cancelar-judicializacao', [
            'cancelar_judicializacao' => ['motivo' => 'x', '_token' => 'irrelevante'],
        ]);

        self::assertResponseStatusCodeSame(404);
    }

    #[TestDox('Sem o módulo pastas (mesmo com gerenciar): negado no servidor')]
    public function testCancelarSemModuloPastas(): void
    {
        $client = static::createClient();
        [, $tenant] = $this->criarOperadorComCapacidades($client, ['resources.cobranca.gerenciar']);
        [, $caso] = $this->semearGrafo($tenant, ['status' => StatusCaso::Judicializado]);
        $casoId = (int) $caso->getId();

        $client->request('POST', '/cobrancas/casos/' . $casoId . '/cancelar-judicializacao', [
            'cancelar_judicializacao' => ['motivo' => 'x', '_token' => 'irrelevante'],
        ]);

        self::assertResponseRedirects();
        self::assertStringNotContainsString('/cobrancas/casos/' . $casoId, (string) $client->getResponse()->headers->get('Location'));

        $em = static::getContainer()->get(EntityManagerInterface::class);
        $em->clear();
        self::assertSame(StatusCaso::Judicializado, $em->find(CasoCobranca::class, $casoId)->getStatus());
    }

    #[TestDox('Sem a capacidade: negado (redirect, não caso)')]
    public function testCancelarSemCapacidade(): void
    {
        $client = static::createClient();
        [, $tenant] = $this->criarOperadorSemCapacidade($client);
        [, $caso] = $this->semearGrafo($tenant, ['status' => StatusCaso::Judicializado]);
        $casoId = (int) $caso->getId();

        $client->request('POST', '/cobrancas/casos/' . $casoId . '/cancelar-judicializacao', [
            'cancelar_judicializacao' => ['motivo' => 'x', '_token' => 'irrelevante'],
        ]);

        self::assertResponseRedirects();
        self::assertStringNotContainsString('/cobrancas/casos/' . $casoId, (string) $client->getResponse()->headers->get('Location'));
    }

    #[TestDox('CSRF inválido: não muda o status')]
    public function testCancelarCsrfInvalido(): void
    {
        $client = static::createClient();
        [, $tenant] = $this->criarAdminLogado($client);
        [, $caso] = $this->semearGrafo($tenant, ['status' => StatusCaso::Judicializado]);
        $casoId = (int) $caso->getId();

        $client->request('POST', '/cobrancas/casos/' . $casoId . '/cancelar-judicializacao', [
            'cancelar_judicializacao' => ['motivo' => 'x', '_token' => 'token-falso'],
        ]);

        self::assertResponseRedirects('/cobrancas/objetos/' . $caso->getObjeto()->getId());

        $em = static::getContainer()->get(EntityManagerInterface::class);
        $em->clear();
        self::assertSame(StatusCaso::Judicializado, $em->find(CasoCobranca::class, $casoId)->getStatus());
    }

    #[TestDox('B5: motivo vazio reabre o modal com o erro')]
    public function testCancelarInvalidoReabreModalComErro(): void
    {
        $client = static::createClient();
        [, $tenant] = $this->criarAdminLogado($client);
        [, $caso] = $this->semearGrafo($tenant, ['status' => StatusCaso::Judicializado]);
        $casoId = (int) $caso->getId();
        $objetoId = (int) $caso->getObjeto()->getId();

        $crawler = $client->request('GET', '/cobrancas/objetos/' . $objetoId);
        $token = $this->tokenDoFormulario($crawler, 'cancelar_judicializacao');

        $client->request('POST', '/cobrancas/casos/' . $casoId . '/cancelar-judicializacao', [
            'cancelar_judicializacao' => ['motivo' => '', '_token' => $token],
        ]);

        self::assertResponseRedirects('/cobrancas/objetos/' . $objetoId);
        $crawler = $client->followRedirect();

        self::assertSame('modalCancelarJudicializacao', $crawler->filter('[data-modal-erro]')->attr('data-modal-erro'));
        self::assertStringContainsString('Informe o motivo do cancelamento.', $crawler->filter('#modalCancelarJudicializacao')->html());
    }
}
