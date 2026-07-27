<?php

declare(strict_types=1);

namespace App\Tests\Cobranca\Functional;

use App\Cobranca\Controller\CasoController;
use App\Cobranca\Entity\CasoCobranca;
use App\Tests\Factory\Cobranca\PessoaFactory;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\TestDox;

/**
 * Alteração manual da pessoa cobrada do caso (Onda 8B, em CasoController). Cobre gate de capacidade
 * `resources.cobranca.gerenciar`, CSRF, anti-IDOR (404 no caso, Pessoa de outro tenant →
 * PessoaNaoEncontrada) e o happy path. A troca não afeta dívida/pagamentos/acordos (SPEC §8).
 */
#[CoversClass(CasoController::class)]
final class AlterarPessoaCobradaControllerTest extends CobrancaWebTestCase
{
    #[TestDox('Alterar pessoa cobrada: happy path troca a pessoa e volta ao caso')]
    public function testAlterarPessoaHappy(): void
    {
        $client = static::createClient();
        [, $tenant] = $this->criarAdminLogado($client);
        [, $caso] = $this->semearGrafo($tenant);
        $nova = PessoaFactory::createOne(['tenant' => $tenant, 'nome' => 'Nova Devedora'])->_real();
        $casoId = (int) $caso->getId();

        $crawler = $client->request('GET', '/cobrancas/objetos/' . $caso->getObjeto()->getId());
        $token = $this->tokenDoFormulario($crawler, 'alterar_pessoa_cobrada');

        $client->request('POST', '/cobrancas/casos/' . $casoId . '/pessoa-cobrada', [
            'alterar_pessoa_cobrada' => ['novaPessoaCobradaId' => (string) $nova->getId(), 'motivo' => 'Mudança de titularidade', '_token' => $token],
        ]);

        self::assertResponseRedirects('/cobrancas/objetos/' . $caso->getObjeto()->getId());

        $em = static::getContainer()->get(EntityManagerInterface::class);
        $em->clear();
        $fresh = $em->find(CasoCobranca::class, $casoId);
        self::assertSame((int) $nova->getId(), (int) $fresh->getPessoaCobradaAtual()->getId(), 'pessoa cobrada trocada');
    }

    #[TestDox('Homônimas: cada uma pode ser definida como cobrada, e o id escolhido é o que vale')]
    public function testAlterarPessoaEntreHomonimas(): void
    {
        $client = static::createClient();
        [, $tenant] = $this->criarAdminLogado($client);
        [, $caso] = $this->semearGrafo($tenant);
        $casoId = (int) $caso->getId();
        $objetoId = (int) $caso->getObjeto()->getId();

        // Mesmo nome, documentos diferentes: antes do conserto uma delas nem chegava ao select, e a que
        // sobrava respondia pelas duas — trocar o responsável podia cobrar a pessoa errada.
        $primeira = PessoaFactory::createOne(['tenant' => $tenant, 'nome' => 'JOSE DA SILVA', 'cpf' => '111.111.111-11'])->_real();
        $segunda = PessoaFactory::createOne(['tenant' => $tenant, 'nome' => 'JOSE DA SILVA', 'cpf' => '222.222.222-22'])->_real();

        $crawler = $client->request('GET', '/cobrancas/objetos/' . $objetoId);
        $token = $this->tokenDoFormulario($crawler, 'alterar_pessoa_cobrada');

        $client->request('POST', '/cobrancas/casos/' . $casoId . '/pessoa-cobrada', [
            'alterar_pessoa_cobrada' => ['novaPessoaCobradaId' => (string) $primeira->getId(), 'motivo' => 'Primeira homônima', '_token' => $token],
        ]);
        self::assertResponseRedirects('/cobrancas/objetos/' . $objetoId);
        self::assertSame((int) $primeira->getId(), $this->pessoaCobradaDe($casoId), 'a homônima escolhida foi a gravada');

        // A segunda também: o formulário aceita as duas, e cada envio resolve o id exato.
        $crawler = $client->request('GET', '/cobrancas/objetos/' . $objetoId);
        $token = $this->tokenDoFormulario($crawler, 'alterar_pessoa_cobrada');

        $client->request('POST', '/cobrancas/casos/' . $casoId . '/pessoa-cobrada', [
            'alterar_pessoa_cobrada' => ['novaPessoaCobradaId' => (string) $segunda->getId(), 'motivo' => 'Segunda homônima', '_token' => $token],
        ]);
        self::assertResponseRedirects('/cobrancas/objetos/' . $objetoId);
        self::assertSame((int) $segunda->getId(), $this->pessoaCobradaDe($casoId), 'a outra homônima também é alcançável');
    }

    #[TestDox('Homônima de OUTRO tenant não entra no select nem é aceita no POST')]
    public function testHomonimaDeOutroTenantNaoEhSelecionavel(): void
    {
        $client = static::createClient();
        [, $tenant] = $this->criarAdminLogado($client);
        [, $caso] = $this->semearGrafo($tenant);
        $casoId = (int) $caso->getId();
        $objetoId = (int) $caso->getObjeto()->getId();
        $pessoaAtualId = $this->pessoaCobradaDe($casoId);

        PessoaFactory::createOne(['tenant' => $tenant, 'nome' => 'JOSE DA SILVA', 'cpf' => '111.111.111-11']);
        $alheia = PessoaFactory::createOne(['tenant' => $this->tenantAvulso(), 'nome' => 'JOSE DA SILVA', 'cpf' => '222.222.222-22'])->_real();

        $crawler = $client->request('GET', '/cobrancas/objetos/' . $objetoId);
        $opcoes = $crawler->filter('#modalAlterarPessoa select option')->each(fn ($o) => $o->attr('value'));
        self::assertNotContains((string) $alheia->getId(), $opcoes, 'pessoa de outro escritório não pode ser oferecida');

        $token = $this->tokenDoFormulario($crawler, 'alterar_pessoa_cobrada');
        $client->request('POST', '/cobrancas/casos/' . $casoId . '/pessoa-cobrada', [
            'alterar_pessoa_cobrada' => ['novaPessoaCobradaId' => (string) $alheia->getId(), 'motivo' => 'tentativa', '_token' => $token],
        ]);

        self::assertResponseRedirects('/cobrancas/objetos/' . $objetoId);
        self::assertSame($pessoaAtualId, $this->pessoaCobradaDe($casoId), 'o nome igual não abre porta entre escritórios');
    }

    #[TestDox('Alterar para Pessoa de OUTRO tenant: erro de domínio, não troca')]
    public function testAlterarPessoaDeOutroTenant(): void
    {
        $client = static::createClient();
        [, $tenant] = $this->criarAdminLogado($client);
        [, $caso] = $this->semearGrafo($tenant);
        $pessoaAtualId = (int) $caso->getPessoaCobradaAtual()->getId();
        $pessoaAlheia = PessoaFactory::createOne(['tenant' => $this->tenantAvulso()])->_real();
        $casoId = (int) $caso->getId();

        $crawler = $client->request('GET', '/cobrancas/objetos/' . $caso->getObjeto()->getId());
        $token = $this->tokenDoFormulario($crawler, 'alterar_pessoa_cobrada');

        $client->request('POST', '/cobrancas/casos/' . $casoId . '/pessoa-cobrada', [
            'alterar_pessoa_cobrada' => ['novaPessoaCobradaId' => (string) $pessoaAlheia->getId(), 'motivo' => 'x', '_token' => $token],
        ]);

        self::assertResponseRedirects('/cobrancas/objetos/' . $caso->getObjeto()->getId());

        $em = static::getContainer()->get(EntityManagerInterface::class);
        $em->clear();
        // ChoiceType rejeita id fora do escopo do tenant → form inválido, sem troca (defesa em profundidade).
        self::assertSame($pessoaAtualId, (int) $em->find(CasoCobranca::class, $casoId)->getPessoaCobradaAtual()->getId());
    }

    #[TestDox('Alterar pessoa sem a capacidade: negado (redirect, não caso)')]
    public function testAlterarPessoaSemCapacidade(): void
    {
        $client = static::createClient();
        [, $tenant] = $this->criarOperadorSemCapacidade($client);
        [, $caso] = $this->semearGrafo($tenant);
        $casoId = (int) $caso->getId();

        $client->request('POST', '/cobrancas/casos/' . $casoId . '/pessoa-cobrada', [
            'alterar_pessoa_cobrada' => ['novaPessoaCobradaId' => '1', 'motivo' => 'x', '_token' => 'irrelevante'],
        ]);

        self::assertResponseRedirects();
        self::assertStringNotContainsString('/cobrancas/casos/' . $casoId, (string) $client->getResponse()->headers->get('Location'));
    }

    #[TestDox('IDOR: alterar pessoa em caso de OUTRO tenant devolve 404')]
    public function testAlterarPessoaCrossTenant404(): void
    {
        $client = static::createClient();
        $this->criarAdminLogado($client);
        [, $casoAlheio] = $this->semearGrafo($this->tenantAvulso());

        $client->request('POST', '/cobrancas/casos/' . $casoAlheio->getId() . '/pessoa-cobrada', [
            'alterar_pessoa_cobrada' => ['novaPessoaCobradaId' => '1', 'motivo' => 'x', '_token' => 'irrelevante'],
        ]);

        self::assertResponseStatusCodeSame(404);
    }

    #[TestDox('CSRF inválido: alterar pessoa não troca')]
    public function testAlterarPessoaCsrfInvalido(): void
    {
        $client = static::createClient();
        [, $tenant] = $this->criarAdminLogado($client);
        [, $caso] = $this->semearGrafo($tenant);
        $pessoaAtualId = (int) $caso->getPessoaCobradaAtual()->getId();
        $nova = PessoaFactory::createOne(['tenant' => $tenant])->_real();
        $casoId = (int) $caso->getId();

        $client->request('POST', '/cobrancas/casos/' . $casoId . '/pessoa-cobrada', [
            'alterar_pessoa_cobrada' => ['novaPessoaCobradaId' => (string) $nova->getId(), 'motivo' => 'x', '_token' => 'token-falso'],
        ]);

        self::assertResponseRedirects('/cobrancas/objetos/' . $caso->getObjeto()->getId());

        $em = static::getContainer()->get(EntityManagerInterface::class);
        $em->clear();
        self::assertSame($pessoaAtualId, (int) $em->find(CasoCobranca::class, $casoId)->getPessoaCobradaAtual()->getId());
    }

    /** Lê o estado REAL do banco: o cache de identidade do EM mentiria sobre a troca. */
    private function pessoaCobradaDe(int $casoId): int
    {
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $em->clear();

        return (int) $em->find(CasoCobranca::class, $casoId)->getPessoaCobradaAtual()->getId();
    }

    #[TestDox('B5: alterar pessoa sem escolher a nova reabre o modal com o motivo digitado e o erro')]
    public function testAlterarPessoaInvalidaReabreModalComErroEPreservaODigitado(): void
    {
        $client = static::createClient();
        [, $tenant] = $this->criarAdminLogado($client);
        [, $caso] = $this->semearGrafo($tenant);
        $casoId = (int) $caso->getId();
        $objetoId = (int) $caso->getObjeto()->getId();

        $crawler = $client->request('GET', '/cobrancas/objetos/' . $objetoId);
        $token = $this->tokenDoFormulario($crawler, 'alterar_pessoa_cobrada');

        // Digita o motivo mas não escolhe a nova pessoa — validação de campo falha.
        $client->request('POST', '/cobrancas/casos/' . $casoId . '/pessoa-cobrada', [
            'alterar_pessoa_cobrada' => ['novaPessoaCobradaId' => '', 'motivo' => 'Titularidade mudou', '_token' => $token],
        ]);

        self::assertResponseRedirects('/cobrancas/objetos/' . $objetoId);
        $crawler = $client->followRedirect();

        self::assertSame('modalAlterarPessoa', $crawler->filter('[data-modal-erro]')->attr('data-modal-erro'));
        $modalHtml = $crawler->filter('#modalAlterarPessoa')->html();
        self::assertStringContainsString('Informe a nova pessoa cobrada.', $modalHtml);
        self::assertStringContainsString('Titularidade mudou', $modalHtml, 'o motivo digitado tem de sobreviver ao redirect');
    }
}
