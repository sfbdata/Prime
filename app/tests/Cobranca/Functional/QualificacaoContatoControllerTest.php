<?php

declare(strict_types=1);

namespace App\Tests\Cobranca\Functional;

use App\Cobranca\Controller\QualificacaoContatoController;
use App\Cobranca\Entity\CasoCobranca;
use App\Cobranca\Entity\EventoHistorico;
use App\Cobranca\Enum\QualificacaoContato;
use App\Cobranca\Enum\StatusCaso;
use App\Cobranca\Enum\TipoEventoHistorico;
use App\Entity\Auth\User;
use App\Entity\Tenant\Tenant;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\TestDox;

/**
 * As duas rotas da qualificação de contato (spec §3.5, 2026-07-27), via HTTP.
 *
 * As três guardas do caminho — capacidade, tenant e CSRF — são provadas isoladamente: cada teste deixa
 * TODO o resto certo e tira um ingrediente só, para a recusa não poder ser atribuída a outra coisa.
 *
 * A **ordem** entre elas também é comportamento sob teste, não detalhe: os testes cross-tenant mandam
 * um token deliberadamente PODRE e ainda assim exigem 404. Se a validação viesse antes da resolução
 * tenant-safe, o POST forjado voltaria com "sessão expirada" e o 404 do anti-IDOR não apareceria — o
 * atacante saberia que a rota existe e ficaria sem saber que o registro não é dele.
 *
 * Os POSTs são diretos (não saem de form renderizado) porque o painel da aba Responsáveis só nasce na
 * Etapa 6: hoje não há markup de onde raspar o `<input _token>`. `tokenCsrf()` existe para exatamente
 * este caso — token por INTENÇÃO, lido do gerenciador, com a sessão da requisição anterior.
 */
#[CoversClass(QualificacaoContatoController::class)]
final class QualificacaoContatoControllerTest extends CobrancaWebTestCase
{
    // ───────────────────────────── Registrar ─────────────────────────────

    #[TestDox('Um clique grava a qualificação e volta para a aba Responsáveis')]
    public function testRegistraQualificacaoEmUmClique(): void
    {
        $client = static::createClient();
        [$usuario, $tenant] = $this->criarAdminLogado($client);
        [, $caso] = $this->semearGrafo($tenant);
        $casoId = (int) $caso->getId();
        $objetoId = (int) $caso->getObjeto()->getId();

        $client->request('GET', '/cobrancas/objetos/' . $objetoId);
        self::assertResponseIsSuccessful();

        $client->request('POST', '/cobrancas/casos/' . $casoId . '/qualificacoes', [
            '_token' => $this->tokenCsrf($client, 'registrar_qualificacao_' . $casoId),
            'qualificacao' => QualificacaoContato::RecusaPagamento->value,
        ]);

        // A aba precisa vir no redirect: o painel mora nela, e é lá que a listinha nova aparece.
        self::assertResponseRedirects('/cobrancas/objetos/' . $objetoId . '?aba=responsaveis');

        $linha = $this->qualificacoesDoCaso($casoId);
        self::assertCount(1, $linha, 'o clique tem de gerar exatamente um evento');
        self::assertSame('qualificacao_contato', $linha[0]['tipo']);
        self::assertSame('Recusa de pagamento', $linha[0]['descricao'], 'a descrição é o rótulo, para o Histórico renderizar sem conhecer o enum');
        self::assertSame(['qualificacao' => 'recusa_pagamento'], json_decode((string) $linha[0]['dados'], true), 'o payload é o que permite a listinha reidratar o enum');
        self::assertSame((int) $usuario->getId(), (int) $linha[0]['usuario_id'], 'a autoria é o que autoriza o desfazer depois');
        // O rótulo entra como COMPLEMENTO da frase, não como sujeito: "Telefone inexistente registrada"
        // seria o que sai se alguém concordar o particípio com o rótulo (dois dos três são masculinos).
        self::assertSame(
            ['Qualificação registrada: Recusa de pagamento.'],
            $client->getRequest()->getSession()->getFlashBag()->peek('success'),
        );
    }

    #[TestDox('Com CSRF inválido a qualificação não é gravada')]
    public function testRegistrarExigeCsrf(): void
    {
        $client = static::createClient();
        [, $tenant] = $this->criarAdminLogado($client);
        [, $caso] = $this->semearGrafo($tenant);
        $casoId = (int) $caso->getId();

        // Tudo o mais está CERTO (capacidade, tenant, valor do enum): o único ingrediente errado é o
        // token, então a recusa só pode ser atribuída ao CSRF. Token PODRE e não ausente — é o que a
        // spec §5 pede, e cobre também a implementação que aceitasse qualquer string não vazia.
        $client->request('POST', '/cobrancas/casos/' . $casoId . '/qualificacoes', [
            '_token' => 'token-deliberadamente-podre',
            'qualificacao' => QualificacaoContato::RecusaPagamento->value,
        ]);

        self::assertResponseRedirects('/cobrancas/objetos/' . $caso->getObjeto()->getId() . '?aba=responsaveis');
        self::assertCount(0, $this->qualificacoesDoCaso($casoId), 'sem CSRF nada pode ser gravado');
    }

    #[TestDox('Caso de outro escritório responde 404 mesmo com token podre (anti-IDOR antes da validação)')]
    public function testRegistrarEmCasoDeOutroTenant(): void
    {
        $client = static::createClient();
        $this->criarAdminLogado($client);
        $outroTenant = $this->tenantAvulso();
        [, $casoAlheio] = $this->semearGrafo($outroTenant);
        $casoId = (int) $casoAlheio->getId();

        $client->request('POST', '/cobrancas/casos/' . $casoId . '/qualificacoes', [
            '_token' => 'token-deliberadamente-podre',
            'qualificacao' => QualificacaoContato::RecusaPagamento->value,
        ]);

        self::assertResponseStatusCodeSame(404);
        self::assertCount(0, $this->qualificacoesDoCaso($casoId), 'nada pode ser gravado no caso do vizinho');
    }

    #[TestDox('Caso encerrado não recebe qualificação nova')]
    public function testRegistrarEmCasoEncerrado(): void
    {
        $client = static::createClient();
        [, $tenant] = $this->criarAdminLogado($client);
        [, $caso] = $this->semearGrafo($tenant, ['status' => StatusCaso::Encerrado]);
        $casoId = (int) $caso->getId();
        $objetoId = (int) $caso->getObjeto()->getId();

        $client->request('GET', '/cobrancas/objetos/' . $objetoId);

        $client->request('POST', '/cobrancas/casos/' . $casoId . '/qualificacoes', [
            '_token' => $this->tokenCsrf($client, 'registrar_qualificacao_' . $casoId),
            'qualificacao' => QualificacaoContato::RecusaPagamento->value,
        ]);

        // Recusa com mensagem, não 404: o caso é do próprio escritório e existe — o que acabou foi o
        // ciclo dele (§17).
        self::assertResponseRedirects('/cobrancas/objetos/' . $objetoId . '?aba=responsaveis');
        self::assertCount(0, $this->qualificacoesDoCaso($casoId), 'caso encerrado não recebe lançamento novo');
    }

    #[TestDox('Valor de qualificação desconhecido é recusado, não gravado cru')]
    public function testRegistrarComValorInvalido(): void
    {
        $client = static::createClient();
        [, $tenant] = $this->criarAdminLogado($client);
        [, $caso] = $this->semearGrafo($tenant);
        $casoId = (int) $caso->getId();

        $client->request('GET', '/cobrancas/objetos/' . $caso->getObjeto()->getId());

        $client->request('POST', '/cobrancas/casos/' . $casoId . '/qualificacoes', [
            '_token' => $this->tokenCsrf($client, 'registrar_qualificacao_' . $casoId),
            'qualificacao' => 'devedor_simpatico',
        ]);

        self::assertResponseRedirects();
        self::assertCount(0, $this->qualificacoesDoCaso($casoId), 'valor fora do enum não pode virar evento');
    }

    #[TestDox('Sem a capacidade de gerenciar cobrança, registrar é barrado no servidor')]
    public function testRegistrarExigeCapacidadeDeGerenciar(): void
    {
        $client = static::createClient();
        // Operador do próprio tenant, com o MÓDULO cobranças, mas SEM `resources.cobranca.gerenciar`.
        // É o caso que a UI já esconde — aqui provamos que esconder não é o que protege.
        [, $tenant] = $this->criarOperadorSemCapacidade($client);
        [, $caso] = $this->semearGrafo($tenant);
        $casoId = (int) $caso->getId();

        // Com o CSRF CERTO — o módulo de leitura basta para abrir a página e obter o token. Sem ele, a
        // recusa viria do CSRF e o gate de capacidade ficaria sem prova nenhuma (medido: rebaixar o gate
        // para `tenantComModulo()` deixava este teste VERDE).
        $client->request('GET', '/cobrancas/objetos/' . $caso->getObjeto()->getId());
        self::assertResponseIsSuccessful('o operador enxerga a página; o que ele não pode é escrever');

        $client->request('POST', '/cobrancas/casos/' . $casoId . '/qualificacoes', [
            '_token' => $this->tokenCsrf($client, 'registrar_qualificacao_' . $casoId),
            'qualificacao' => QualificacaoContato::RecusaPagamento->value,
        ]);

        // Destino EXPLÍCITO: `semAcesso()` manda para a homepage, e é justamente esse destino que separa
        // "barrado pelo gate" de "barrado pelo CSRF" (que voltaria para `?aba=responsaveis`). Um
        // `assertResponseRedirects()` sem alvo aceitaria os dois e deixaria o gate sem prova.
        self::assertResponseRedirects('/');
        self::assertCount(0, $this->qualificacoesDoCaso($casoId), 'sem capacidade nada pode ser gravado');
    }

    // ───────────────────────────── Desfazer ─────────────────────────────

    #[TestDox('O autor desfaz a última qualificação dentro dos 5 minutos e ela some')]
    public function testAutorDesfazDentroDaJanela(): void
    {
        $client = static::createClient();
        [$usuario, $tenant] = $this->criarAdminLogado($client);
        [, $caso] = $this->semearGrafo($tenant);
        $objetoId = (int) $caso->getObjeto()->getId();
        $evento = $this->semearQualificacao($caso, $tenant, $usuario);
        $id = (int) $evento->getId();

        $client->request('GET', '/cobrancas/objetos/' . $objetoId);

        $client->request('POST', '/cobrancas/qualificacoes/' . $id . '/desfazer', [
            '_token' => $this->tokenCsrf($client, 'desfazer_qualificacao_' . $id),
        ]);

        self::assertResponseRedirects('/cobrancas/objetos/' . $objetoId . '?aba=responsaveis');
        self::assertNull($this->buscarEvento($id), 'a remoção é definitiva, sem marca de desfeita');
    }

    #[TestDox('Recusa 1: passados os 5 minutos, o servidor não desfaz mais')]
    public function testDesfazerRecusaForaDaJanela(): void
    {
        $client = static::createClient();
        [$usuario, $tenant] = $this->criarAdminLogado($client);
        [, $caso] = $this->semearGrafo($tenant);
        $evento = $this->semearQualificacao($caso, $tenant, $usuario, quando: new \DateTimeImmutable('-10 minutes'));
        $id = (int) $evento->getId();

        $client->request('GET', '/cobrancas/objetos/' . $caso->getObjeto()->getId());

        $client->request('POST', '/cobrancas/qualificacoes/' . $id . '/desfazer', [
            '_token' => $this->tokenCsrf($client, 'desfazer_qualificacao_' . $id),
        ]);

        self::assertResponseRedirects();
        self::assertNotNull($this->buscarEvento($id), 'fora da janela a qualificação fica firme');
    }

    #[TestDox('Recusa 2: ninguém desfaz a qualificação registrada por um colega')]
    public function testDesfazerRecusaDeOutroUsuario(): void
    {
        $client = static::createClient();
        [, $tenant] = $this->criarAdminLogado($client);
        [, $caso] = $this->semearGrafo($tenant);
        $colega = $this->criarColega();
        $evento = $this->semearQualificacao($caso, $tenant, $colega);
        $id = (int) $evento->getId();

        $client->request('GET', '/cobrancas/objetos/' . $caso->getObjeto()->getId());

        $client->request('POST', '/cobrancas/qualificacoes/' . $id . '/desfazer', [
            '_token' => $this->tokenCsrf($client, 'desfazer_qualificacao_' . $id),
        ]);

        self::assertResponseRedirects();
        self::assertNotNull($this->buscarEvento($id), 'a válvula é do autor do engano, não de quem passar por ali');
    }

    #[TestDox('Recusa 3: só a qualificação do topo é desfeita — a penúltima não')]
    public function testDesfazerRecusaQuandoNaoEhAMaisRecente(): void
    {
        $client = static::createClient();
        [$usuario, $tenant] = $this->criarAdminLogado($client);
        [, $caso] = $this->semearGrafo($tenant);
        // As DUAS dentro dos 5 minutos e do MESMO autor: a única condição que falha na penúltima é a de
        // ser a mais recente. Sem isso o teste provaria a janela ou a autoria, não a quarta condição.
        $penultima = $this->semearQualificacao($caso, $tenant, $usuario, quando: new \DateTimeImmutable('-2 minutes'));
        $this->semearQualificacao($caso, $tenant, $usuario, QualificacaoContato::TelefoneInexistente);
        $id = (int) $penultima->getId();

        $client->request('GET', '/cobrancas/objetos/' . $caso->getObjeto()->getId());

        $client->request('POST', '/cobrancas/qualificacoes/' . $id . '/desfazer', [
            '_token' => $this->tokenCsrf($client, 'desfazer_qualificacao_' . $id),
        ]);

        self::assertResponseRedirects();
        self::assertNotNull($this->buscarEvento($id), 'desfazer a penúltima deixaria a listinha contando uma história que não aconteceu');
    }

    #[TestDox('Recusa 4: a rota do desfazer não apaga um evento de outro tipo')]
    public function testDesfazerRecusaEventoDeOutroTipo(): void
    {
        $client = static::createClient();
        [$usuario, $tenant] = $this->criarAdminLogado($client);
        [, $caso] = $this->semearGrafo($tenant);
        // Anotação do PRÓPRIO autor, agorinha: autoria e janela estão certas. O que recusa é o tipo —
        // e é isso que impede a rota de virar um apagador genérico de histórico.
        //
        // ⚠️ O tipo é defendido DUAS vezes, e este teste não distingue as duas: além do `podeSerDesfeitaPor`,
        // a consulta da 4ª condição (`ultimaQualificacaoDoCaso`) filtra por tipo, então para uma anotação
        // ela devolve `null` e a remoção cai ali de qualquer jeito. Medido por injeção (2026-07-27):
        // remover UMA das duas deixa o teste verde; só removendo AS DUAS ele cai. Prometer que este teste
        // prova a checagem da entidade sozinha seria falso — o que ele prova é que a rota, inteira, não
        // apaga evento de outro tipo.
        $evento = $this->semearEvento($caso, $tenant, $usuario, TipoEventoHistorico::Anotacao, 'anotacao livre', null);
        $id = (int) $evento->getId();

        $client->request('GET', '/cobrancas/objetos/' . $caso->getObjeto()->getId());

        $client->request('POST', '/cobrancas/qualificacoes/' . $id . '/desfazer', [
            '_token' => $this->tokenCsrf($client, 'desfazer_qualificacao_' . $id),
        ]);

        self::assertResponseRedirects();
        self::assertNotNull($this->buscarEvento($id), 'só qualificação é desfazível por esta rota');
    }

    #[TestDox('Com CSRF inválido a qualificação não é desfeita')]
    public function testDesfazerExigeCsrf(): void
    {
        $client = static::createClient();
        [$usuario, $tenant] = $this->criarAdminLogado($client);
        [, $caso] = $this->semearGrafo($tenant);
        $evento = $this->semearQualificacao($caso, $tenant, $usuario);
        $id = (int) $evento->getId();

        // Autor, dentro da janela, mais recente do caso, capacidade em dia: só o token está errado.
        $client->request('POST', '/cobrancas/qualificacoes/' . $id . '/desfazer', [
            '_token' => 'token-deliberadamente-podre',
        ]);

        self::assertResponseRedirects('/cobrancas/objetos/' . $caso->getObjeto()->getId() . '?aba=responsaveis');
        self::assertNotNull($this->buscarEvento($id), 'sem CSRF nada pode ser removido');
    }

    #[TestDox('Desfazer qualificação de outro escritório responde 404 mesmo com token podre')]
    public function testDesfazerEmOutroTenant(): void
    {
        $client = static::createClient();
        [, $meuTenant] = $this->criarAdminLogado($client);
        $this->semearGrafo($meuTenant);
        $outroTenant = $this->tenantAvulso();
        [, $casoAlheio] = $this->semearGrafo($outroTenant);
        $colega = $this->criarColega();
        $evento = $this->semearQualificacao($casoAlheio, $outroTenant, $colega);
        $id = (int) $evento->getId();

        $client->request('POST', '/cobrancas/qualificacoes/' . $id . '/desfazer', [
            '_token' => 'token-deliberadamente-podre',
        ]);

        self::assertResponseStatusCodeSame(404);

        // Por SQL cru: o filtro de tenant do Doctrine esconde do ORM o registro do outro escritório —
        // e é justamente esse registro que precisamos provar que continua lá.
        $existe = static::getContainer()->get(EntityManagerInterface::class)->getConnection()
            ->fetchOne('SELECT descricao FROM cobranca_evento_historico WHERE id = ?', [$id]);
        self::assertSame('Recusa de pagamento', $existe, 'qualificação de outro escritório não pode ser apagada');
    }

    #[TestDox('Sem a capacidade de gerenciar cobrança, desfazer é barrado no servidor')]
    public function testDesfazerExigeCapacidadeDeGerenciar(): void
    {
        $client = static::createClient();
        [$operador, $tenant] = $this->criarOperadorSemCapacidade($client);
        [, $caso] = $this->semearGrafo($tenant);
        // O próprio operador é o autor e está dentro da janela: o que barra é só a capacidade.
        $evento = $this->semearQualificacao($caso, $tenant, $operador);
        $id = (int) $evento->getId();

        // Com o CSRF CERTO, pela mesma razão do registrar: sem o token quem recusaria seria o CSRF, e o
        // gate ficaria sem prova.
        $client->request('GET', '/cobrancas/objetos/' . $caso->getObjeto()->getId());

        $client->request('POST', '/cobrancas/qualificacoes/' . $id . '/desfazer', [
            '_token' => $this->tokenCsrf($client, 'desfazer_qualificacao_' . $id),
        ]);

        self::assertResponseRedirects('/');   // homepage do `semAcesso()`, não a volta para a aba
        self::assertNotNull($this->buscarEvento($id), 'sem capacidade nada pode ser removido');
    }

    // ───────────────────────────── Apoio ─────────────────────────────

    /**
     * Qualificações do caso lidas por SQL cru — o EM é resetado entre requisições e o cache de
     * identidade mente sobre o que foi realmente gravado.
     *
     * @return list<array<string, mixed>>
     */
    private function qualificacoesDoCaso(int $casoId): array
    {
        return static::getContainer()->get(EntityManagerInterface::class)->getConnection()->fetchAllAssociative(
            "SELECT tipo, descricao, dados, usuario_id FROM cobranca_evento_historico
             WHERE caso_id = ? AND tipo = 'qualificacao_contato' ORDER BY id",
            [$casoId],
        );
    }

    private function buscarEvento(int $id): ?EventoHistorico
    {
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $em->clear();

        return $em->getRepository(EventoHistorico::class)->find($id);
    }

    private function semearQualificacao(
        CasoCobranca $caso,
        Tenant $tenant,
        User $autor,
        QualificacaoContato $qualificacao = QualificacaoContato::RecusaPagamento,
        ?\DateTimeImmutable $quando = null,
    ): EventoHistorico {
        return $this->semearEvento(
            $caso,
            $tenant,
            $autor,
            TipoEventoHistorico::QualificacaoContato,
            $qualificacao->label(),
            ['qualificacao' => $qualificacao->value],
            $quando,
        );
    }

    /** @param array<string, mixed>|null $dados */
    private function semearEvento(
        CasoCobranca $caso,
        Tenant $tenant,
        User $autor,
        TipoEventoHistorico $tipo,
        string $descricao,
        ?array $dados = null,
        ?\DateTimeImmutable $quando = null,
    ): EventoHistorico {
        $em = static::getContainer()->get(EntityManagerInterface::class);

        $evento = new EventoHistorico();
        $evento->setTenant($tenant)
            ->setCaso($caso)
            ->setTipo($tipo)
            ->setUsuario($autor)
            ->setDescricao($descricao)
            ->setDados($dados)
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
