<?php

declare(strict_types=1);

namespace App\Tests\Cobranca\Functional;

use App\Cobranca\Controller\CasoController;
use App\Cliente\Entity\ClientePF;
use App\Cobranca\Entity\CasoCobranca;
use App\Cobranca\Entity\Pessoa;
use App\Cobranca\Entity\PessoaEndereco;
use App\Cobranca\Enum\StatusCaso;
use App\Entity\Tenant\Tenant;
use App\Pasta\Entity\Pasta;
use App\Tests\Factory\Cliente\ClientePFFactory;
use App\Tests\Factory\Cliente\ClientePJFactory;
use App\Tests\Factory\Cobranca\PessoaFactory;
use App\Tests\Factory\Pasta\PastaFactory;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\TestDox;

/**
 * Judicialização do caso (Onda 8B, em CasoController). Desde 2026-08-27
 * (`docs/specs/cobranca-judicializar-cria-pasta.md`) o caminho normal CRIA a pasta, e vincular uma
 * EXISTENTE virou o secundário — os dois mudam o status para judicializado (sem encerrar). Cobre gate de capacidade `resources.cobranca.gerenciar` +
 * módulo `pastas`, CSRF, anti-IDOR (404 no caso e Pasta de outro tenant → PastaNaoEncontrada), erro de
 * domínio (já judicializado) e o happy path. O admin de teste é `isSystem` → passa o gate de `pastas`.
 */
#[CoversClass(CasoController::class)]
final class JudicializarMutacaoControllerTest extends CobrancaWebTestCase
{
    #[TestDox('Judicializar: happy path vincula a pasta e muda o status (não encerra)')]
    public function testJudicializarHappy(): void
    {
        $client = static::createClient();
        [, $tenant] = $this->criarAdminLogado($client);
        [, $caso] = $this->semearGrafo($tenant);
        $pasta = PastaFactory::createOne(['tenant' => $tenant])->_real();
        $casoId = (int) $caso->getId();

        $crawler = $client->request('GET', '/cobrancas/objetos/' . $caso->getObjeto()->getId());
        $token = $this->tokenDoFormulario($crawler, 'judicializar_caso');

        $client->request('POST', '/cobrancas/casos/' . $casoId . '/judicializar', [
            'judicializar_caso' => ['modo' => 'vincular', 'pastaId' => (string) $pasta->getId(), '_token' => $token],
        ]);

        self::assertResponseRedirects('/cobrancas/objetos/' . $caso->getObjeto()->getId());

        $em = static::getContainer()->get(EntityManagerInterface::class);
        $em->clear();
        $fresh = $em->find(CasoCobranca::class, $casoId);
        self::assertSame(StatusCaso::Judicializado, $fresh->getStatus());
        self::assertNotNull($fresh->getPastaJudicial(), 'pasta vinculada ao caso');
    }

    #[TestDox('Judicializar com Pasta de OUTRO tenant: erro de domínio, não judicializa')]
    public function testJudicializarPastaDeOutroTenant(): void
    {
        $client = static::createClient();
        [, $tenant] = $this->criarAdminLogado($client);
        [, $caso] = $this->semearGrafo($tenant);
        $pastaAlheia = PastaFactory::createOne(['tenant' => $this->tenantAvulso()])->_real();
        $casoId = (int) $caso->getId();

        $crawler = $client->request('GET', '/cobrancas/objetos/' . $caso->getObjeto()->getId());
        $token = $this->tokenDoFormulario($crawler, 'judicializar_caso');

        $client->request('POST', '/cobrancas/casos/' . $casoId . '/judicializar', [
            'judicializar_caso' => ['modo' => 'vincular', 'pastaId' => (string) $pastaAlheia->getId(), '_token' => $token],
        ]);

        self::assertResponseRedirects('/cobrancas/objetos/' . $caso->getObjeto()->getId());

        $em = static::getContainer()->get(EntityManagerInterface::class);
        $em->clear();
        self::assertSame(StatusCaso::Ativo, $em->find(CasoCobranca::class, $casoId)->getStatus(), 'pasta cross-tenant não pode judicializar');
    }

    #[TestDox('Judicializar caso já judicializado: erro de domínio, mantém a pasta original')]
    public function testJudicializarJaJudicializado(): void
    {
        $client = static::createClient();
        [, $tenant] = $this->criarAdminLogado($client);
        // Caso já judicializado COM uma pasta original: tentar revincular outra não pode trocá-la.
        $pastaOriginal = PastaFactory::createOne(['tenant' => $tenant])->_real();
        [, $caso] = $this->semearGrafo($tenant, ['status' => StatusCaso::Judicializado, 'pastaJudicial' => $pastaOriginal]);
        $pastaNova = PastaFactory::createOne(['tenant' => $tenant])->_real();
        $casoId = (int) $caso->getId();

        // Token colhido de um caso ativo (o judicializado esconde o botão, mas o token é por form/sessão).
        [, $casoAtivo] = $this->semearGrafo($tenant);
        $crawler = $client->request('GET', '/cobrancas/objetos/' . $casoAtivo->getObjeto()->getId());
        $token = $this->tokenDoFormulario($crawler, 'judicializar_caso');

        $client->request('POST', '/cobrancas/casos/' . $casoId . '/judicializar', [
            'judicializar_caso' => ['modo' => 'vincular', 'pastaId' => (string) $pastaNova->getId(), '_token' => $token],
        ]);

        self::assertResponseRedirects('/cobrancas/objetos/' . $caso->getObjeto()->getId());

        $em = static::getContainer()->get(EntityManagerInterface::class);
        $em->clear();
        self::assertSame((int) $pastaOriginal->getId(), (int) $em->find(CasoCobranca::class, $casoId)->getPastaJudicial()->getId(), 'não revincula pasta num caso já judicializado');
    }

    #[TestDox('Judicializar sem o módulo pastas (mesmo com gerenciar): negado no servidor')]
    public function testJudicializarSemModuloPastas(): void
    {
        $client = static::createClient();
        // Operador COM cobranca.gerenciar mas SEM o módulo `pastas` — gate adicional do SPEC §16/§22.
        [, $tenant] = $this->criarOperadorComCapacidades($client, ['resources.cobranca.gerenciar']);
        [, $caso] = $this->semearGrafo($tenant);
        $pasta = PastaFactory::createOne(['tenant' => $tenant])->_real();
        $casoId = (int) $caso->getId();

        $client->request('POST', '/cobrancas/casos/' . $casoId . '/judicializar', [
            'judicializar_caso' => ['modo' => 'vincular', 'pastaId' => (string) $pasta->getId(), '_token' => 'irrelevante'],
        ]);

        self::assertResponseRedirects();
        self::assertStringNotContainsString('/cobrancas/casos/' . $casoId, (string) $client->getResponse()->headers->get('Location'), 'sem o módulo pastas a judicialização é negada');

        $em = static::getContainer()->get(EntityManagerInterface::class);
        $em->clear();
        self::assertSame(StatusCaso::Ativo, $em->find(CasoCobranca::class, $casoId)->getStatus());
    }

    #[TestDox('Judicializar sem a capacidade: negado (redirect, não caso)')]
    public function testJudicializarSemCapacidade(): void
    {
        $client = static::createClient();
        [, $tenant] = $this->criarOperadorSemCapacidade($client);
        [, $caso] = $this->semearGrafo($tenant);
        $casoId = (int) $caso->getId();

        $client->request('POST', '/cobrancas/casos/' . $casoId . '/judicializar', [
            'judicializar_caso' => ['pastaId' => '1', '_token' => 'irrelevante'],
        ]);

        self::assertResponseRedirects();
        self::assertStringNotContainsString('/cobrancas/casos/' . $casoId, (string) $client->getResponse()->headers->get('Location'));
    }

    #[TestDox('IDOR: judicializar caso de OUTRO tenant devolve 404')]
    public function testJudicializarCrossTenant404(): void
    {
        $client = static::createClient();
        $this->criarAdminLogado($client);
        [, $casoAlheio] = $this->semearGrafo($this->tenantAvulso());

        $client->request('POST', '/cobrancas/casos/' . $casoAlheio->getId() . '/judicializar', [
            'judicializar_caso' => ['pastaId' => '1', '_token' => 'irrelevante'],
        ]);

        self::assertResponseStatusCodeSame(404);
    }

    #[TestDox('CSRF inválido: judicializar não muda o status')]
    public function testJudicializarCsrfInvalido(): void
    {
        $client = static::createClient();
        [, $tenant] = $this->criarAdminLogado($client);
        [, $caso] = $this->semearGrafo($tenant);
        $pasta = PastaFactory::createOne(['tenant' => $tenant])->_real();
        $casoId = (int) $caso->getId();

        $client->request('POST', '/cobrancas/casos/' . $casoId . '/judicializar', [
            'judicializar_caso' => ['modo' => 'vincular', 'pastaId' => (string) $pasta->getId(), '_token' => 'token-falso'],
        ]);

        self::assertResponseRedirects('/cobrancas/objetos/' . $caso->getObjeto()->getId());

        $em = static::getContainer()->get(EntityManagerInterface::class);
        $em->clear();
        self::assertSame(StatusCaso::Ativo, $em->find(CasoCobranca::class, $casoId)->getStatus());
    }

    #[TestDox('B5: judicializar sem escolher a pasta reabre o modal com o erro')]
    public function testJudicializarInvalidaReabreModalComErro(): void
    {
        $client = static::createClient();
        [, $tenant] = $this->criarAdminLogado($client);
        [, $caso] = $this->semearGrafo($tenant);
        $casoId = (int) $caso->getId();
        $objetoId = (int) $caso->getObjeto()->getId();

        $crawler = $client->request('GET', '/cobrancas/objetos/' . $objetoId);
        $token = $this->tokenDoFormulario($crawler, 'judicializar_caso');

        $client->request('POST', '/cobrancas/casos/' . $casoId . '/judicializar', [
            'judicializar_caso' => ['modo' => 'vincular', 'pastaId' => '', '_token' => $token],
        ]);

        self::assertResponseRedirects('/cobrancas/objetos/' . $objetoId);
        $crawler = $client->followRedirect();

        self::assertSame('modalJudicializar', $crawler->filter('[data-modal-erro]')->attr('data-modal-erro'));
        self::assertStringContainsString('Informe a pasta judicial.', $crawler->filter('#modalJudicializar')->html());
    }

    // ── O caminho NOVO: judicializar CRIA a pasta ──────────────────────────────────────────────────

    #[TestDox('O modal abre preenchido com o responsável principal e AÇÃO MONITÓRIA')]
    public function testModalAbrePreenchido(): void
    {
        $client = static::createClient();
        [, $tenant] = $this->criarAdminLogado($client);
        $pessoa = PessoaFactory::createOne(['tenant' => $tenant, 'nome' => 'JOANA RESPONSAVEL'])->_real();
        [, $caso] = $this->semearGrafo($tenant, ['pessoaCobradaAtual' => $pessoa]);

        $crawler = $client->request('GET', '/cobrancas/objetos/' . $caso->getObjeto()->getId());

        self::assertSame(
            'JOANA RESPONSAVEL',
            $crawler->filter('#modalJudicializar input[name="judicializar_caso[nomeCliente]"]')->attr('value'),
            'o nome do cliente nasce com o responsável principal',
        );
        self::assertSame(
            'AÇÃO MONITÓRIA',
            $crawler->filter('#modalJudicializar input[name="judicializar_caso[nomeAcao]"]')->attr('value'),
            'a ação é a de todos os casos de cobrança',
        );
        // O modo `criar` vem marcado: quem só clica em Judicializar cria a pasta, sem escolher nada.
        self::assertNotNull(
            $crawler->filter('#modalJudicializar input[name="judicializar_caso[modo]"][value="criar"][checked]')->getNode(0),
            'criar é o modo padrão',
        );
        // Guarda contra o defeito que o `form_end` produz ao renderizar campo não-renderizado: os
        // rádios apareceriam DUAS vezes, e o segundo par (sem `checked`) venceria o primeiro.
        self::assertCount(
            2,
            $crawler->filter('#modalJudicializar input[name="judicializar_caso[modo]"]'),
            'o grupo de modo é renderizado UMA vez (dois rádios, não quatro)',
        );

        // Arranjo: a regra que AFASTA as duas opções (`cobrancas.css`, `.cob-judicializar-opcoes`)
        // precisa que os rádios sejam FILHOS DIRETOS dessa classe. Sem a âncora eles voltam a sair
        // colados, e nenhum teste de HTML enxergaria isso. O combinador de filho direto é o que
        // distingue "está aninhado assim" de "existe em algum lugar do modal".
        self::assertCount(
            2,
            $crawler->filter('#modalJudicializar .cob-judicializar-opcoes > input[name="judicializar_caso[modo]"]'),
            'os rádios são filhos diretos da classe que o CSS de espaçamento ancora',
        );
    }

    #[TestDox('O modal marca os dois blocos por modo, para alternarem conforme o rádio')]
    public function testModalTemOsBlocosMarcadosPorModo(): void
    {
        $client = static::createClient();
        [, $tenant] = $this->criarAdminLogado($client);
        [, $caso] = $this->semearGrafo($tenant);

        $crawler = $client->request('GET', '/cobrancas/objetos/' . $caso->getObjeto()->getId());

        // O que a suíte consegue provar é o CONTRATO do markup: os dois blocos existem e estão
        // rotulados pelo modo a que pertencem. Esconder e mostrar é do JS, e a suíte é cega para isso
        // — precisa do smoke na tela.
        foreach (['criar', 'vincular'] as $modo) {
            self::assertNotNull(
                $crawler->filter('#modalJudicializar [data-modo-bloco="' . $modo . '"]')->getNode(0),
                sprintf('o bloco do modo %s está marcado', $modo),
            );
        }

        // Sem JavaScript nada é escondido: os dois blocos continuam visíveis, como antes. É realce
        // progressivo — o caminho de vincular não pode depender de script para existir.
        self::assertCount(
            0,
            $crawler->filter('#modalJudicializar [data-modo-bloco][hidden]'),
            'o HTML entregue não esconde bloco nenhum',
        );

        // A busca vive DENTRO do bloco de vincular: fora dele, o JS a mostraria no modo errado.
        self::assertNotNull(
            $crawler->filter('#modalJudicializar [data-modo-bloco="vincular"] [data-buscar-pasta]')->getNode(0),
            'o campo de busca está dentro do bloco de vincular',
        );
    }

    #[TestDox('Judicializar cria a pasta com o nome do responsável e AÇÃO MONITÓRIA')]
    public function testJudicializarCriaPasta(): void
    {
        $client = static::createClient();
        [, $tenant] = $this->criarAdminLogado($client);
        // Sem CPF na ficha: a pasta nasce só com nome e ação — é o caso de 202 dos 248 em produção.
        $pessoa = PessoaFactory::createOne(['tenant' => $tenant, 'nome' => 'JOANA RESPONSAVEL'])->_real();
        [, $caso] = $this->semearGrafo($tenant, ['pessoaCobradaAtual' => $pessoa]);
        $casoId = (int) $caso->getId();

        $crawler = $client->request('GET', '/cobrancas/objetos/' . $caso->getObjeto()->getId());
        $token = $this->tokenDoFormulario($crawler, 'judicializar_caso');

        $client->request('POST', '/cobrancas/casos/' . $casoId . '/judicializar', [
            'judicializar_caso' => [
                'modo' => 'criar',
                'nomeCliente' => 'JOANA RESPONSAVEL',
                'nomeAcao' => 'AÇÃO MONITÓRIA',
                '_token' => $token,
            ],
        ]);

        self::assertResponseRedirects('/cobrancas/objetos/' . $caso->getObjeto()->getId());

        $em = static::getContainer()->get(EntityManagerInterface::class);
        $em->clear();
        $fresh = $em->find(CasoCobranca::class, $casoId);

        self::assertSame(StatusCaso::Judicializado, $fresh->getStatus());
        $pasta = $fresh->getPastaJudicial();
        self::assertNotNull($pasta, 'a pasta foi criada e vinculada');
        self::assertSame('JOANA RESPONSAVEL', $pasta->getNomeCliente());
        self::assertSame('AÇÃO MONITÓRIA', $pasta->getNomeAcao());
        self::assertNotNull($pasta->getNup(), 'o número da pasta é gerado pelo sistema');
        self::assertSame($tenant->getId(), $pasta->getTenant()->getId(), 'a pasta nasce no escritório DO CASO');
        self::assertCount(0, $pasta->getClientes(), 'sem CPF na ficha não há cliente a cadastrar');
    }

    #[TestDox('Com CPF na ficha, o responsável vira o cliente principal da pasta (RG em branco)')]
    public function testJudicializarCadastraOResponsavelComoClientePrincipal(): void
    {
        $client = static::createClient();
        [, $tenant] = $this->criarAdminLogado($client);
        $pessoa = PessoaFactory::createOne([
            'tenant' => $tenant,
            'nome' => 'MARIA DA FICHA',
            'cpf' => '52998224725',
            'email' => 'maria@exemplo.test',
        ])->_real();
        $this->darEnderecoAtual($pessoa, $tenant);
        [, $caso] = $this->semearGrafo($tenant, ['pessoaCobradaAtual' => $pessoa]);
        $casoId = (int) $caso->getId();

        $crawler = $client->request('GET', '/cobrancas/objetos/' . $caso->getObjeto()->getId());
        $token = $this->tokenDoFormulario($crawler, 'judicializar_caso');

        $client->request('POST', '/cobrancas/casos/' . $casoId . '/judicializar', [
            'judicializar_caso' => [
                'modo' => 'criar',
                'nomeCliente' => 'MARIA DA FICHA',
                'nomeAcao' => 'AÇÃO MONITÓRIA',
                '_token' => $token,
            ],
        ]);

        $em = static::getContainer()->get(EntityManagerInterface::class);
        $em->clear();
        $pasta = $em->find(CasoCobranca::class, $casoId)->getPastaJudicial();

        self::assertCount(1, $pasta->getClientes());
        $clientePrincipal = $pasta->getClientePrincipal();
        self::assertInstanceOf(ClientePF::class, $clientePrincipal, 'o responsável virou o cliente principal');
        self::assertSame('MARIA DA FICHA', $clientePrincipal->getNomeCompleto());
        // A cobrança guarda 11 dígitos; `cliente_pf.cpf` é varchar(14), o tamanho da máscara.
        self::assertSame('529.982.247-25', $clientePrincipal->getCpf(), 'o CPF é gravado formatado');
        // 🔴 Decisão do dono (spec §3): as colunas são NOT NULL e NENHUMA pessoa cobrada tem RG.
        // Em branco é DE PROPÓSITO — não "consertar" apagando o cadastro.
        self::assertSame('', $clientePrincipal->getRg());
        self::assertSame('', $clientePrincipal->getRgOrgaoExpedidor());
        self::assertSame('maria@exemplo.test', $clientePrincipal->getEmail());
        self::assertSame('DF', $clientePrincipal->getEstado(), 'o endereço atual da ficha desceu para o cliente');
    }

    #[TestDox('VINCULAR normaliza a pasta existente: nome, ação e cliente principal')]
    public function testVincularNormalizaAPastaExistente(): void
    {
        $client = static::createClient();
        [, $tenant] = $this->criarAdminLogado($client);

        $credor = ClientePJFactory::createOne(['tenant' => $tenant, 'nomeFantasia' => 'APLC TOP LIFE 1']);
        $pessoa = PessoaFactory::createOne([
            'tenant' => $tenant,
            'nome' => 'SALVADOR PAULO DE OLIVEIRA',
            'cpf' => '52998224725',
            'email' => 'salvador@exemplo.test',
        ])->_real();
        $this->darEnderecoAtual($pessoa, $tenant);

        // A pasta existente é o retrato do que acontece em produção: criada à mão, nomeada com OUTRO
        // coproprietário e com uma ação diferente. 26 das 30 pastas judicializadas vieram por aqui.
        $pasta = PastaFactory::createOne([
            'tenant' => $tenant,
            'nomeCliente' => 'APLC TOP LIFE 1 - EVANDRO CAMPELO COUTINHO',
            'nomeAcao' => 'EXECUÇÃO DE TÍTULO',
        ])->_real();

        [, $caso] = $this->semearGrafo($tenant, ['pessoaCobradaAtual' => $pessoa], ['cliente' => $credor]);
        $casoId = (int) $caso->getId();

        $crawler = $client->request('GET', '/cobrancas/objetos/' . $caso->getObjeto()->getId());
        $token = $this->tokenDoFormulario($crawler, 'judicializar_caso');

        $client->request('POST', '/cobrancas/casos/' . $casoId . '/judicializar', [
            'judicializar_caso' => ['modo' => 'vincular', 'pastaId' => (string) $pasta->getId(), '_token' => $token],
        ]);

        $em = static::getContainer()->get(EntityManagerInterface::class);
        $em->clear();
        $fresh = $em->find(CasoCobranca::class, $casoId)->getPastaJudicial();

        self::assertSame(
            'APLC TOP LIFE 1 - SALVADOR PAULO DE OLIVEIRA',
            $fresh->getNomeCliente(),
            'vincular normaliza o nome pelo credor + responsável, sobrescrevendo o que foi digitado',
        );
        self::assertSame(
            'AÇÃO MONITÓRIA',
            $fresh->getNomeAcao(),
            'a ação é sobrescrita mesmo quando já havia outra — decisão do dono',
        );

        $principal = $fresh->getClientePrincipal();
        self::assertInstanceOf(ClientePF::class, $principal, 'o responsável vira o cliente principal também ao vincular');
        self::assertSame('SALVADOR PAULO DE OLIVEIRA', $principal->getNomeCompleto());
    }

    #[TestDox('VINCULAR sem pessoa com CPF: normaliza o nome e a ação, e NÃO inventa cliente')]
    public function testVincularSemCpfNormalizaMasNaoCadastraCliente(): void
    {
        $client = static::createClient();
        [, $tenant] = $this->criarAdminLogado($client);

        $credor = ClientePJFactory::createOne(['tenant' => $tenant, 'nomeFantasia' => 'APLC TOP LIFE 1']);
        // Sem CPF na ficha — o caso de 202 dos 248 responsáveis medidos em 27/08.
        $pessoa = PessoaFactory::createOne(['tenant' => $tenant, 'nome' => 'JOANA SEM CPF'])->_real();
        $pasta = PastaFactory::createOne(['tenant' => $tenant, 'nomeCliente' => 'NOME ANTIGO'])->_real();

        [, $caso] = $this->semearGrafo($tenant, ['pessoaCobradaAtual' => $pessoa], ['cliente' => $credor]);
        $casoId = (int) $caso->getId();

        $crawler = $client->request('GET', '/cobrancas/objetos/' . $caso->getObjeto()->getId());
        $token = $this->tokenDoFormulario($crawler, 'judicializar_caso');

        $client->request('POST', '/cobrancas/casos/' . $casoId . '/judicializar', [
            'judicializar_caso' => ['modo' => 'vincular', 'pastaId' => (string) $pasta->getId(), '_token' => $token],
        ]);

        $em = static::getContainer()->get(EntityManagerInterface::class);
        $em->clear();
        $fresh = $em->find(CasoCobranca::class, $casoId)->getPastaJudicial();

        self::assertSame('APLC TOP LIFE 1 - JOANA SEM CPF', $fresh->getNomeCliente());
        self::assertCount(0, $fresh->getClientes(), 'sem CPF não há identidade para cadastrar — não inventa');
    }

    #[TestDox('CPF já cadastrado no escritório REUSA o cliente, mesmo com máscara diferente')]
    public function testJudicializarReusaClienteDoMesmoCpf(): void
    {
        $client = static::createClient();
        [, $tenant] = $this->criarAdminLogado($client);
        // O cadastro existente está COM máscara; a cobrança guarda só dígitos. Comparar a string crua
        // criaria o duplicado que a unicidade por escritório existe para evitar (spec §3.2).
        $jaCadastrado = ClientePFFactory::createOne([
            'tenant' => $tenant,
            'cpf' => '529.982.247-25',
            'nomeCompleto' => 'MARIA JA CADASTRADA',
        ])->_real();
        $pessoa = PessoaFactory::createOne(['tenant' => $tenant, 'nome' => 'MARIA DA FICHA', 'cpf' => '52998224725'])->_real();
        [, $caso] = $this->semearGrafo($tenant, ['pessoaCobradaAtual' => $pessoa]);
        $casoId = (int) $caso->getId();

        $crawler = $client->request('GET', '/cobrancas/objetos/' . $caso->getObjeto()->getId());
        $token = $this->tokenDoFormulario($crawler, 'judicializar_caso');

        $client->request('POST', '/cobrancas/casos/' . $casoId . '/judicializar', [
            'judicializar_caso' => [
                'modo' => 'criar',
                'nomeCliente' => 'MARIA DA FICHA',
                'nomeAcao' => 'AÇÃO MONITÓRIA',
                '_token' => $token,
            ],
        ]);

        $em = static::getContainer()->get(EntityManagerInterface::class);
        $em->clear();
        $pasta = $em->find(CasoCobranca::class, $casoId)->getPastaJudicial();

        self::assertSame(
            (int) $jaCadastrado->getId(),
            (int) $pasta->getClientePrincipal()->getId(),
            'reusa o cliente do mesmo CPF em vez de duplicar',
        );
    }

    #[TestDox('Nome maior que 50 caracteres: a pasta nasce, o cliente NÃO')]
    public function testNomeLongoNaoCadastraCliente(): void
    {
        $client = static::createClient();
        [, $tenant] = $this->criarAdminLogado($client);
        // `cliente_pf.nome_completo` é varchar(50) e `cobranca_pessoa.nome` é varchar(255). Truncar o
        // nome de uma parte processual seria inventar dado — melhor a pasta sem cadastro.
        $nomeLongo = str_repeat('A', 51);
        $pessoa = PessoaFactory::createOne(['tenant' => $tenant, 'nome' => $nomeLongo, 'cpf' => '52998224725'])->_real();
        [, $caso] = $this->semearGrafo($tenant, ['pessoaCobradaAtual' => $pessoa]);
        $casoId = (int) $caso->getId();

        $crawler = $client->request('GET', '/cobrancas/objetos/' . $caso->getObjeto()->getId());
        $token = $this->tokenDoFormulario($crawler, 'judicializar_caso');

        $client->request('POST', '/cobrancas/casos/' . $casoId . '/judicializar', [
            'judicializar_caso' => [
                'modo' => 'criar',
                'nomeCliente' => $nomeLongo,
                'nomeAcao' => 'AÇÃO MONITÓRIA',
                '_token' => $token,
            ],
        ]);

        $em = static::getContainer()->get(EntityManagerInterface::class);
        $em->clear();
        $pasta = $em->find(CasoCobranca::class, $casoId)->getPastaJudicial();

        self::assertNotNull($pasta, 'a judicialização não pode falhar por causa do cadastro');
        self::assertCount(0, $pasta->getClientes());
    }

    #[TestDox('Criar IGNORA o que vier do formulário: quem nomeia a pasta é o caso')]
    public function testCriarIgnoraOQueVemDoFormulario(): void
    {
        $client = static::createClient();
        [, $tenant] = $this->criarAdminLogado($client);

        $credor = ClientePJFactory::createOne(['tenant' => $tenant, 'nomeFantasia' => 'APLC TOP LIFE 1']);
        $pessoa = PessoaFactory::createOne(['tenant' => $tenant, 'nome' => 'CLAUDIO SILVA DA CRUZ'])->_real();
        [, $caso] = $this->semearGrafo($tenant, ['pessoaCobradaAtual' => $pessoa], ['cliente' => $credor]);
        $casoId = (int) $caso->getId();
        $objetoId = (int) $caso->getObjeto()->getId();

        $crawler = $client->request('GET', '/cobrancas/objetos/' . $objetoId);
        $token = $this->tokenDoFormulario($crawler, 'judicializar_caso');

        // Nome VAZIO e ação ERRADA no payload. Os campos são somente-leitura na tela, mas readonly é
        // do navegador — quem manda o POST à mão passa por cima. O servidor tem de ignorar os dois:
        // antes de 02/09 isto devolvia "Informe o nome do cliente da pasta." e não criava pasta.
        $client->request('POST', '/cobrancas/casos/' . $casoId . '/judicializar', [
            'judicializar_caso' => ['modo' => 'criar', 'nomeCliente' => '', 'nomeAcao' => 'QUALQUER COISA', '_token' => $token],
        ]);

        $em = static::getContainer()->get(EntityManagerInterface::class);
        $em->clear();
        $pasta = $em->find(CasoCobranca::class, $casoId)->getPastaJudicial();

        self::assertNotNull($pasta, 'campo vazio não bloqueia mais a judicialização');
        self::assertSame('APLC TOP LIFE 1 - CLAUDIO SILVA DA CRUZ', $pasta->getNomeCliente());
        self::assertSame('AÇÃO MONITÓRIA', $pasta->getNomeAcao(), 'a ação enviada foi descartada');
    }

    #[TestDox('Os campos do modal são somente-leitura — servem para conferir, não para digitar')]
    public function testCamposDoModalSaoSomenteLeitura(): void
    {
        $client = static::createClient();
        [, $tenant] = $this->criarAdminLogado($client);
        [, $caso] = $this->semearGrafo($tenant);

        $crawler = $client->request('GET', '/cobrancas/objetos/' . $caso->getObjeto()->getId());

        foreach (['nomeCliente', 'nomeAcao'] as $campo) {
            self::assertNotNull(
                $crawler->filter('#modalJudicializar input[name="judicializar_caso[' . $campo . ']"][readonly]')->getNode(0),
                sprintf('o campo %s é somente-leitura', $campo),
            );
        }
    }

    #[TestDox('Caso já judicializado no modo criar: recusa ANTES de abrir a pasta')]
    public function testCriarEmCasoJaJudicializadoNaoDeixaPastaOrfa(): void
    {
        $client = static::createClient();
        [, $tenant] = $this->criarAdminLogado($client);
        $pastaOriginal = PastaFactory::createOne(['tenant' => $tenant])->_real();
        [, $caso] = $this->semearGrafo($tenant, ['status' => StatusCaso::Judicializado, 'pastaJudicial' => $pastaOriginal]);
        $casoId = (int) $caso->getId();

        [, $casoAtivo] = $this->semearGrafo($tenant);
        $crawler = $client->request('GET', '/cobrancas/objetos/' . $casoAtivo->getObjeto()->getId());
        $token = $this->tokenDoFormulario($crawler, 'judicializar_caso');
        $pastasAntes = $this->contarPastas($tenant);

        $client->request('POST', '/cobrancas/casos/' . $casoId . '/judicializar', [
            'judicializar_caso' => ['modo' => 'criar', 'nomeCliente' => 'QUEM SEJA', 'nomeAcao' => 'AÇÃO MONITÓRIA', '_token' => $token],
        ]);

        $em = static::getContainer()->get(EntityManagerInterface::class);
        $em->clear();
        self::assertSame(
            (int) $pastaOriginal->getId(),
            (int) $em->find(CasoCobranca::class, $casoId)->getPastaJudicial()->getId(),
            'não troca a pasta de um caso já judicializado',
        );
        self::assertSame($pastasAntes, $this->contarPastas($tenant), 'a guarda roda antes de abrir a pasta');
    }

    #[TestDox('Carteira de credor PJ: o modal abre com "FANTASIA - PESSOA COBRADA"')]
    public function testModalAbreComOPadraoDoCredorDaCarteira(): void
    {
        $client = static::createClient();
        [, $tenant] = $this->criarAdminLogado($client);
        $credor = ClientePJFactory::createOne(['tenant' => $tenant, 'nomeFantasia' => 'APLC TOP LIFE 1']);
        $pessoa = PessoaFactory::createOne(['tenant' => $tenant, 'nome' => 'CLAUDIO SILVA DA CRUZ'])->_real();
        [, $caso] = $this->semearGrafo($tenant, ['pessoaCobradaAtual' => $pessoa], ['cliente' => $credor]);

        $crawler = $client->request('GET', '/cobrancas/objetos/' . $caso->getObjeto()->getId());

        self::assertSame(
            'APLC TOP LIFE 1 - CLAUDIO SILVA DA CRUZ',
            $crawler->filter('#modalJudicializar input[name="judicializar_caso[nomeCliente]"]')->attr('value'),
            'o nome da pasta nasce no padrão do escritório: fantasia do credor + pessoa cobrada',
        );
    }

    #[TestDox('Enviar o modal como ele abre grava a pasta com o nome no padrão')]
    public function testJudicializarGravaAPastaComONomeNoPadrao(): void
    {
        $client = static::createClient();
        [, $tenant] = $this->criarAdminLogado($client);
        $credor = ClientePJFactory::createOne(['tenant' => $tenant, 'nomeFantasia' => 'APLC TOP LIFE 1']);
        $pessoa = PessoaFactory::createOne(['tenant' => $tenant, 'nome' => 'CLAUDIO SILVA DA CRUZ'])->_real();
        [, $caso] = $this->semearGrafo($tenant, ['pessoaCobradaAtual' => $pessoa], ['cliente' => $credor]);
        $casoId = (int) $caso->getId();

        $crawler = $client->request('GET', '/cobrancas/objetos/' . $caso->getObjeto()->getId());

        // Submete o formulário COMO ELE ABRE, sem digitar nada: é o que o gestor faz ao clicar em
        // Judicializar direto. Postar valores montados à mão provaria o UseCase, não o padrão.
        $client->submit($crawler->filter('#modalJudicializar button[type="submit"]')->form());

        $em = static::getContainer()->get(EntityManagerInterface::class);
        $em->clear();
        $pasta = $em->find(CasoCobranca::class, $casoId)->getPastaJudicial();

        self::assertNotNull($pasta, 'a pasta foi criada e vinculada');
        self::assertSame('APLC TOP LIFE 1 - CLAUDIO SILVA DA CRUZ', $pasta->getNomeCliente());
    }

    #[TestDox('Credor PJ sem nome fantasia: a pasta fica só com a pessoa cobrada')]
    public function testCredorSemNomeFantasiaNaoInventaPrefixo(): void
    {
        $client = static::createClient();
        [, $tenant] = $this->criarAdminLogado($client);
        $credor = ClientePJFactory::createOne(['tenant' => $tenant, 'nomeFantasia' => null]);
        $pessoa = PessoaFactory::createOne(['tenant' => $tenant, 'nome' => 'CLAUDIO SILVA DA CRUZ'])->_real();
        [, $caso] = $this->semearGrafo($tenant, ['pessoaCobradaAtual' => $pessoa], ['cliente' => $credor]);

        $crawler = $client->request('GET', '/cobrancas/objetos/' . $caso->getObjeto()->getId());

        // Sem fantasia cadastrada não há prefixo — e a razão social NÃO entra no lugar dela: tem 93
        // caracteres no maior caso de produção e viraria um nome de pasta ilegível.
        self::assertSame(
            'CLAUDIO SILVA DA CRUZ',
            $crawler->filter('#modalJudicializar input[name="judicializar_caso[nomeCliente]"]')->attr('value'),
        );
    }

    /** Endereço ATUAL da ficha — é dele que descem CEP, endereço, cidade e UF do cliente. */
    private function darEnderecoAtual(Pessoa $pessoa, Tenant $tenant): void
    {
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $endereco = new PessoaEndereco();
        $endereco->setTenant($tenant);
        $endereco->setPessoa($pessoa);
        $endereco->setLogradouro('QUADRA 10 CONJUNTO B');
        $endereco->setNumero('15');
        $endereco->setBairro('CENTRO');
        $endereco->setCidade('BRASILIA');
        $endereco->setUf('DF');
        $endereco->setCep('70000000');
        $endereco->setAtual(true);
        $em->persist($endereco);
        $em->flush();
    }

    private function contarPastas(Tenant $tenant): int
    {
        $em = static::getContainer()->get(EntityManagerInterface::class);

        return (int) $em->createQuery('SELECT COUNT(p.id) FROM ' . Pasta::class . ' p WHERE p.tenant = :t')
            ->setParameter('t', $tenant)
            ->getSingleScalarResult();
    }
}
