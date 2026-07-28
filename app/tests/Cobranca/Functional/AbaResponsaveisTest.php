<?php

declare(strict_types=1);

namespace App\Tests\Cobranca\Functional;

use App\Cobranca\Controller\ObjetoController;
use App\Cobranca\Entity\CasoCobranca;
use App\Cobranca\Entity\Pessoa;
use App\Cobranca\Entity\PessoaEmail;
use App\Cobranca\Entity\PessoaEndereco;
use App\Cobranca\Entity\PessoaTelefone;
use App\Cobranca\Enum\EstadoCivil;
use App\Cobranca\Enum\QualificacaoContato;
use App\Cobranca\Enum\StatusCaso;
use App\Cobranca\Enum\TipoTelefone;
use App\Cobranca\Enum\TipoEventoHistorico;
use App\Cobranca\Enum\TipoVinculo;
use App\Entity\Auth\User;
use App\Entity\Tenant\Tenant;
use App\Tests\Factory\Cobranca\CasoCobrancaFactory;
use App\Tests\Factory\Cobranca\EventoHistoricoFactory;
use App\Tests\Factory\Cobranca\ObjetoCobrancaFactory;
use App\Tests\Factory\Cobranca\PessoaFactory;
use App\Tests\Factory\Cobranca\VinculoPessoaObjetoFactory;
use App\Tests\Factory\Tenant\TenantFactory;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\TestDox;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Component\DomCrawler\Crawler;

/**
 * Aba Responsáveis redesenhada e painel de qualificação (spec
 * `cobranca-objeto-show-cabecalho-responsaveis` §2 e §3, Etapa 6). São testes de RENDERIZAÇÃO: o que
 * se prova aqui é que o dado do DTO chegou ao lugar certo do HTML, e que o que a spec proíbe não está
 * na tela.
 *
 * As regras propriamente ditas já têm prova própria em outro lugar e NÃO são reprovadas aqui:
 * as quatro condições do desfazer vivem no `DesfazerQualificacaoContatoUseCase` (unitários) e o
 * `podeDesfazer` chega pronto do servidor; as rotas e suas guardas (capacidade, tenant, CSRF) estão
 * no `QualificacaoContatoControllerTest`.
 *
 * ⚠️ O que este arquivo trava, e é onde a tela erraria em silêncio:
 * - a lista de telefones vem da FICHA (`objeto.fichaCobrada.telefones`) e não do telefone único
 *   derivado do vínculo — voltar ao derivado esconde todos os outros números;
 * - o e-mail e o endereço da faixa são os marcados como ATUAL, não o primeiro cadastrado;
 * - o `desfazer` aparece SÓ na linha que o servidor marcou, e a decisão não é recalculada no Twig;
 * - os selos `WhatsApp` / `Não atende` por telefone continuam FORA (não há vínculo contato↔telefone).
 */
#[CoversClass(ObjetoController::class)]
final class AbaResponsaveisTest extends CobrancaWebTestCase
{
    // ───────────────────────────── §2.1 Cabeçalho da aba ─────────────────────────────

    #[TestDox('§2.1: o cabeçalho conta os vínculos e, com a ficha completa, NÃO mostra o badge')]
    public function testCabecalhoContaVinculosESemBadgeComFichaCompleta(): void
    {
        $client = static::createClient();
        [, $tenant] = $this->criarAdminLogado($client);
        [, $caso] = $this->semearGrafo($tenant);
        $cobrada = $this->cobradaVinculada($tenant, $caso);
        $this->completarFicha($tenant, $cobrada);
        // Uma segunda pessoa: a contagem é de VÍNCULOS, não de "quem é a cobrada".
        $this->vincular($tenant, $caso, PessoaFactory::createOne(['tenant' => $tenant])->_real(), TipoVinculo::Representante);

        $aba = $this->abrirAba($client, $caso);

        self::assertSame('(2)', trim($aba->filter('.cob-resp-contagem')->text()), 'o cabeçalho conta os dois vínculos');
        self::assertCount(0, $aba->filter('[data-badge="qualificacao-incompleta"]'), 'ficha completa não pode acusar incompleta');
    }

    #[TestDox('§2.1: sem CPF nem CNPJ o badge acusa e leva à ficha')]
    public function testBadgePorFaltaDeDocumento(): void
    {
        $client = static::createClient();
        [, $tenant] = $this->criarAdminLogado($client);
        [, $caso] = $this->semearGrafo($tenant);
        // Tudo o mais COMPLETO: a única coisa que falta é o documento, então o badge só pode ser dele.
        $cobrada = $this->cobradaVinculada($tenant, $caso);
        $this->completarFicha($tenant, $cobrada, cpf: null);

        $badge = $this->abrirAba($client, $caso)->filter('[data-badge="qualificacao-incompleta"]');

        self::assertCount(1, $badge, 'sem CPF/CNPJ a qualificação está incompleta');
        self::assertSame(
            '/cobrancas/pessoas/' . $cobrada->getId(),
            $badge->attr('href'),
            'o badge tem de LEVAR À FICHA — é lá que se corrige; sem link ele seria só uma reclamação',
        );
    }

    #[TestDox('§2.1: sem estado civil o badge acusa (com documento e endereço presentes)')]
    public function testBadgePorFaltaDeEstadoCivil(): void
    {
        $client = static::createClient();
        [, $tenant] = $this->criarAdminLogado($client);
        [, $caso] = $this->semearGrafo($tenant);
        $cobrada = $this->cobradaVinculada($tenant, $caso);
        $this->completarFicha($tenant, $cobrada, estadoCivil: null);

        self::assertCount(
            1,
            $this->abrirAba($client, $caso)->filter('[data-badge="qualificacao-incompleta"]'),
            'estado civil vazio é uma das três pernas do OU da §2.1',
        );
    }

    #[TestDox('§2.1: sem nenhum endereço o badge acusa (com documento e estado civil presentes)')]
    public function testBadgePorFaltaDeEndereco(): void
    {
        $client = static::createClient();
        [, $tenant] = $this->criarAdminLogado($client);
        [, $caso] = $this->semearGrafo($tenant);
        $cobrada = $this->cobradaVinculada($tenant, $caso);
        $this->completarFicha($tenant, $cobrada, comEndereco: false);

        self::assertCount(
            1,
            $this->abrirAba($client, $caso)->filter('[data-badge="qualificacao-incompleta"]'),
            'nenhum endereço cadastrado é a terceira perna do OU da §2.1',
        );
    }

    // ───────────────────────────── §2.2 Card da pessoa cobrada ─────────────────────────────

    #[TestDox('§2.2: o bloco traz nome, rótulo sem fundo, papel · desde mm/aaaa e o selo de vínculo encerrado')]
    public function testCardDaPessoaCobrada(): void
    {
        $client = static::createClient();
        [, $tenant] = $this->criarAdminLogado($client);
        [, $caso] = $this->semearGrafo($tenant);
        $cobrada = PessoaFactory::createOne(['tenant' => $tenant, 'nome' => 'Joana Ribeiro Souza'])->_real();
        $this->trocarCobrada($caso, $cobrada);
        $vinculo = $this->vincular($tenant, $caso, $cobrada, TipoVinculo::Proprietario);
        // Vínculo ENCERRADO com a pessoa seguindo como cobrada atual: o encerramento do vínculo não
        // troca de quem se cobra, e o selo é o que conta essa história na tela.
        $vinculo->setDataFim(new \DateTimeImmutable('-1 day'));
        $vinculo->setDataInicio(new \DateTimeImmutable('2024-03-15'));
        $this->salvar();

        $card = $this->abrirAba($client, $caso)->filter('.cob-resp-atual');

        self::assertCount(1, $card, 'exatamente um bloco de pessoa cobrada');
        self::assertStringContainsString('Joana Ribeiro Souza', $card->filter('.jp-pessoa-nome')->text());
        // O avatar de iniciais e o fundo verde saíram em 2026-07-28 (pedido do dono: menos poluição).
        // Estas duas asserções são o que impede a volta silenciosa: o círculo não pode reaparecer, e o
        // rótulo `Responsável atual` tem de continuar existindo SEM o badge colorido do Bootstrap.
        self::assertCount(0, $card->filter('.jp-pessoa-avatar'), 'o círculo de iniciais foi removido');
        self::assertCount(0, $card->filter('.badge.text-bg-success'), 'nada de fundo verde no bloco da pessoa');
        self::assertSame('Responsável atual', trim($card->filter('.cob-resp-rotulo-atual')->text()));
        self::assertSame(
            'Proprietário · desde 03/2024',
            trim($card->filter('[data-meta="papel-desde"]')->text()),
            'o papel e o mês/ano de início ficam na mesma linha, como a §2.2 pede',
        );
        // Ordem pedida pelo dono: o NOME primeiro, os metadados (rótulo + papel · desde) na linha de
        // baixo. Sem esta asserção a inversão voltaria sem ninguém notar — o texto continuaria todo lá.
        self::assertStringContainsString(
            'jp-pessoa-nome',
            (string) $card->children()->first()->attr('class'),
            'o nome é o primeiro elemento do bloco',
        );
        self::assertCount(1, $card->filter('.cob-resp-meta .cob-resp-rotulo-atual'), 'o rótulo mora na linha de metadados, sob o nome');
        self::assertCount(1, $card->filter('[data-selo="vinculo-encerrado"]'), 'vínculo com dataFim tem de exibir o selo');
    }

    // ───────────────────────────── §2.3 Telefones ─────────────────────────────

    #[TestDox('§2.3: a lista traz TODOS os telefones da ficha, com o selo Atual no marcado e a data de cadastro')]
    public function testListaDeTelefonesVemDaFicha(): void
    {
        $client = static::createClient();
        [, $tenant] = $this->criarAdminLogado($client);
        [, $caso] = $this->semearGrafo($tenant);
        $cobrada = $this->cobradaVinculada($tenant, $caso);

        // Três números: o telefone ÚNICO derivado do vínculo mostraria no máximo um deles. Exigir os
        // três na tela é o que impede a volta ao campo derivado, que era o defeito da versão anterior.
        $this->telefone($tenant, $cobrada, '(21) 3333-1111', atual: false);
        $atual = $this->telefone($tenant, $cobrada, '(21) 99999-2222', atual: true);
        $this->telefone($tenant, $cobrada, '(21) 3333-3333', atual: false);

        $lista = $this->abrirAba($client, $caso)->filter('.cob-resp-telefones');
        $linhas = $lista->filter('.cob-resp-telefone');

        self::assertCount(3, $linhas, 'a lista é a da FICHA (todos os números), não o telefone único derivado');
        foreach (['(21) 3333-1111', '(21) 99999-2222', '(21) 3333-3333'] as $numero) {
            self::assertStringContainsString($numero, $lista->text(), "o número {$numero} sumiu da lista");
        }

        $selos = $lista->filter('[data-selo="telefone-atual"]');
        self::assertCount(1, $selos, 'exatamente um telefone é o atual');
        $linhaAtual = $lista->filter('[data-telefone="' . $atual->getId() . '"]');
        self::assertCount(1, $linhaAtual->filter('[data-selo="telefone-atual"]'), 'o selo Atual tem de estar na linha do telefone marcado');
        self::assertCount(0, $linhaAtual->filter('[data-acao="marcar-telefone-atual"]'), 'quem já é o atual não oferece "Marcar como atual"');

        self::assertMatchesRegularExpression('/cadastrado em \d{2}\/\d{2}\/\d{4}/', $linhas->first()->text(), 'cada linha diz de quando é o número');

        // "Marcar como atual" nos outros dois, apontando para a rota que já existe, com o token da
        // intenção por ITEM (`marcar_telefone_atual_<id>`) — token genérico aceitaria trocar o alvo.
        $botoes = $lista->filter('[data-acao="marcar-telefone-atual"]');
        self::assertCount(2, $botoes, 'os dois não-atuais oferecem a troca');
        $form = $botoes->first()->closest('form');
        self::assertNotNull($form);
        self::assertMatchesRegularExpression(
            '#^/cobrancas/pessoas/' . $cobrada->getId() . '/telefones/\d+/atual$#',
            (string) $form->attr('action'),
        );
        self::assertNotSame('', (string) $form->filter('input[name="_token"]')->attr('value'), 'sem token a rota recusa');
    }

    #[TestDox('§2.3: editar/excluir AGEM (não são mais botões mortos) e os selos WhatsApp/Não atende NÃO existem')]
    public function testTelefonesTemAcoesQueAgemESemSelosInventados(): void
    {
        $client = static::createClient();
        [, $tenant] = $this->criarAdminLogado($client);
        [, $caso] = $this->semearGrafo($tenant);
        $cobrada = $this->cobradaVinculada($tenant, $caso);
        $telefone = $this->telefone($tenant, $cobrada, '(21) 99999-2222', atual: true);
        $objetoId = $caso->getObjeto()->getId();

        $lista = $this->abrirAba($client, $caso)->filter('.cob-resp-telefones');

        // Até 2026-07-28 os dois nasciam `disabled` por falta de rota. Agora agem — e nenhum dos dois
        // pode voltar a ser botão morto sem derrubar este teste.
        foreach (['editar-telefone', 'excluir-telefone'] as $acao) {
            $botao = $lista->filter('[data-acao="' . $acao . '"]');
            self::assertCount(1, $botao, "sumiu o botão {$acao}");
            self::assertNull($botao->attr('disabled'), "{$acao} não pode mais nascer desabilitado — a rota existe");
        }

        // Editar é INLINE: o botão abre o painel da própria linha, que traz o form apontando para a
        // rota do OBJETO (a da ficha levaria o gestor para outra página) e o número já preenchido.
        self::assertSame(
            '#editarTelefone' . $telefone->getId(),
            $lista->filter('[data-acao="editar-telefone"]')->attr('data-bs-target'),
        );
        $formEditar = $lista->filter('#editarTelefone' . $telefone->getId() . ' form');
        self::assertCount(1, $formEditar);
        self::assertSame(
            '/cobrancas/objetos/' . $objetoId . '/responsaveis/telefones/' . $telefone->getId(),
            $formEditar->attr('action'),
        );
        self::assertSame('(21) 99999-2222', $formEditar->filter('input[name="numero"]')->attr('value'));
        self::assertNotSame('', (string) $formEditar->filter('input[name="_token"]')->attr('value'), 'CSRF por item');

        // Excluir passa pelo modal compartilhado: o botão carrega a rota, o token DAQUELE item e o
        // número, que é o que a confirmação mostra. Token genérico aceitaria trocar o alvo.
        $botaoExcluir = $lista->filter('[data-acao="excluir-telefone"]');
        self::assertSame(
            '/cobrancas/objetos/' . $objetoId . '/responsaveis/telefones/' . $telefone->getId() . '/excluir',
            $botaoExcluir->attr('data-url'),
        );
        self::assertNotSame('', (string) $botaoExcluir->attr('data-token'));
        self::assertSame('(21) 99999-2222', $botaoExcluir->attr('data-numero'));
        // Só o ATUAL avisa que a exclusão vai promover outro número.
        self::assertSame('1', $botaoExcluir->attr('data-atual'));

        // A maquete pedia selos de CONTATO por telefone ("WhatsApp 21/07", "Não atende"). O contato
        // registrado não guarda a qual telefone se referia — exibi-los seria inventar o vínculo, e a
        // §2.3 os proíbe até a frente que o crie.
        //
        // 2026-07-28: a asserção deixou de ser "a palavra WhatsApp não aparece". Ela passou a aparecer
        // legitimamente como TIPO do telefone — que é declarado por quem cadastra, não inferido de um
        // evento de contato. O que continua proibido é o selo de contato: WhatsApp seguido de DATA.
        self::assertDoesNotMatchRegularExpression(
            '/whatsapp\s*\d{2}\/\d{2}/i',
            $lista->text(),
            'selo de contato por telefone (WhatsApp + data) exigiria um vínculo que o dado não tem',
        );
        self::assertStringNotContainsStringIgnoringCase('Não atende', $lista->text());
    }

    #[TestDox('§2.3: o mini-form inline de adicionar telefone aponta para a rota do OBJETO, não para a da ficha')]
    public function testMiniFormDeAdicionarTelefone(): void
    {
        $client = static::createClient();
        [, $tenant] = $this->criarAdminLogado($client);
        [, $caso] = $this->semearGrafo($tenant);
        $cobrada = $this->cobradaVinculada($tenant, $caso);
        // Um telefone cadastrado para que exista lista — é em relação a ela que a posição do `+` é
        // verificada mais abaixo. Sem nenhum número a aba mostra o estado vazio, e "abaixo do último
        // telefone" não teria o que ancorar.
        $this->telefone($tenant, $cobrada, '(21) 99999-2222', atual: true);

        $aba = $this->abrirAba($client, $caso);
        $form = $aba->filter('[data-form="adicionar-telefone"]');

        self::assertCount(1, $form, 'o mini-form de adicionar telefone é inline, na própria aba');

        // 2026-07-28: o form deixou de ficar sempre aberto. O único gatilho é o `+` LOGO ABAIXO do
        // último telefone, e o painel nasce COLAPSADO — mas continua no DOM, com o CSRF já renderizado
        // (é o que permite abrir sem ida ao servidor, e o que os testes de POST abaixo consomem).
        $botao = $aba->filter('[data-acao="abrir-novo-telefone"]');
        self::assertCount(1, $botao, 'o bloco de telefones tem o botão + de adicionar telefone');
        self::assertSame('#cobRespTelefoneNovo', $botao->attr('data-bs-target'), 'o + tem de apontar para o painel do form');
        self::assertSame('collapse', $botao->attr('data-bs-toggle'));

        $painel = $aba->filter('#cobRespTelefoneNovo');
        self::assertCount(1, $painel);
        self::assertStringContainsString('collapse', (string) $painel->attr('class'), 'o painel do form é um collapse');
        self::assertStringNotContainsString('show', (string) $painel->attr('class'), 'e nasce fechado — quem só consulta a lista não vê o campo');
        self::assertCount(1, $painel->filter('[data-form="adicionar-telefone"]'), 'o form mora dentro do painel colapsado');
        // O + fica FORA do collapse: dentro, fechar o form o levaria junto e não haveria como reabrir.
        self::assertCount(0, $painel->filter('[data-acao="abrir-novo-telefone"]'), 'o + não pode morar dentro do collapse que ele abre');
        // E DEPOIS da lista, que é onde o pedido o pôs — logo abaixo do último telefone.
        $ordem = $aba->filter('.cob-resp-telefone-lista, [data-acao="abrir-novo-telefone"]')->each(
            static fn ($no) => $no->attr('data-acao') === 'abrir-novo-telefone' ? 'botao' : 'lista',
        );
        self::assertSame(['lista', 'botao'], $ordem, 'o + vem abaixo do último telefone, não no cabeçalho da lista');
        // A rota da FICHA (`/cobrancas/pessoas/{id}/telefones`) sempre termina em `cobranca_pessoa_show`
        // e tirava o gestor da cobrança. Apontar para o objeto é o que permite responder com o
        // fragmento (AJAX) ou com o redirect de volta para esta aba (2026-07-28).
        self::assertSame(
            '/cobrancas/objetos/' . $caso->getObjeto()->getId() . '/responsaveis/telefones',
            $form->attr('action'),
        );
        self::assertCount(1, $form->filter('input[name="adicionar_telefone[numero]"]'), 'o campo do número tem de vir do Form Type');

        // 2026-07-28 (terceira rodada): o + ficou discreto e ganhou a dica ao lado. O `+` sozinho não
        // dizia o que fazia para quem nunca clicou; a dica é o texto que a referência aprovada pede.
        self::assertStringNotContainsString(
            'jp-btn-accent',
            (string) $botao->attr('class'),
            'o + é oferta, não ação primária — nada de botão verde cheio',
        );
        self::assertSame('Clique em + para adicionar.', trim($aba->filter('[data-dica="adicionar-telefone"]')->text()));
    }

    #[TestDox('§2.3: adicionar por AJAX devolve a lista já atualizada, sem redirect')]
    public function testAdicionarTelefonePorAjaxDevolveAListaAtualizada(): void
    {
        $client = static::createClient();
        [, $tenant] = $this->criarAdminLogado($client);
        [, $caso] = $this->semearGrafo($tenant);
        $cobrada = $this->cobradaVinculada($tenant, $caso);
        // Um número JÁ cadastrado: o fragmento devolvido tem de trazer a lista INTEIRA (o novo mais o
        // antigo), não só o item recém-criado — é ele que substitui o bloco na tela.
        $this->telefone($tenant, $cobrada, '(21) 3333-1111', atual: true);

        $crawler = $client->request('GET', '/cobrancas/objetos/' . $caso->getObjeto()->getId());
        $token = $this->tokenDoFormulario($crawler, 'adicionar_telefone');

        $client->request(
            'POST',
            '/cobrancas/objetos/' . $caso->getObjeto()->getId() . '/responsaveis/telefones',
            ['adicionar_telefone' => ['numero' => '(21) 98888-7777', '_token' => $token]],
            [],
            ['HTTP_X_REQUESTED_WITH' => 'XMLHttpRequest'],
        );

        self::assertResponseIsSuccessful('o AJAX responde a página nenhuma — nem redirect');
        $dados = json_decode((string) $client->getResponse()->getContent(), true);

        self::assertTrue($dados['ok']);
        self::assertStringContainsString('(21) 98888-7777', $dados['html'], 'o número novo tem de vir no fragmento');
        self::assertStringContainsString('(21) 3333-1111', $dados['html'], 'o fragmento é a lista inteira, não só o item novo');
        self::assertStringContainsString('data-form="adicionar-telefone"', $dados['html'], 'o fragmento traz o mini-form de volta (com CSRF novo)');
        // ...e traz o mini-form FECHADO: depois de cadastrar, o campo some até alguém apertar o `+` de
        // novo (pedido do dono, 2026-07-28). Esta é a metade da regra que o servidor decide; a outra é
        // o JS não reabrir o painel, e essa nenhum teste de PHP alcança — vai no smoke.
        $fragmento = new Crawler($dados['html']);
        $painel = $fragmento->filter('#cobRespTelefoneNovo');
        self::assertCount(1, $painel);
        self::assertStringNotContainsString('show', (string) $painel->attr('class'), 'o fragmento devolve o mini-form colapsado');

        $em = static::getContainer()->get(EntityManagerInterface::class);
        $em->clear();
        $telefones = $em->getRepository(PessoaTelefone::class)->findBy(['pessoa' => $cobrada->getId()]);
        self::assertCount(2, $telefones, 'adicionar acrescenta — nada do que existia é apagado');
    }

    #[TestDox('§2.3: sem AJAX, adicionar volta para a PRÓPRIA aba e não para a ficha da pessoa')]
    public function testAdicionarTelefoneSemAjaxVoltaParaAAba(): void
    {
        $client = static::createClient();
        [, $tenant] = $this->criarAdminLogado($client);
        [, $caso] = $this->semearGrafo($tenant);
        $cobrada = $this->cobradaVinculada($tenant, $caso);
        $objetoId = (int) $caso->getObjeto()->getId();

        $crawler = $client->request('GET', '/cobrancas/objetos/' . $objetoId);
        $token = $this->tokenDoFormulario($crawler, 'adicionar_telefone');

        $client->request('POST', '/cobrancas/objetos/' . $objetoId . '/responsaveis/telefones', [
            'adicionar_telefone' => ['numero' => '(21) 98888-7777', '_token' => $token],
        ]);

        // O `?aba=responsaveis` é o que o JS do show consome para reabrir esta aba: sem ele o gestor
        // voltaria na aba Cobrança. E o destino ser o OBJETO é o fim do desvio para `cobranca_pessoa_show`.
        self::assertResponseRedirects('/cobrancas/objetos/' . $objetoId . '?aba=responsaveis');

        $em = static::getContainer()->get(EntityManagerInterface::class);
        $em->clear();
        self::assertCount(1, $em->getRepository(PessoaTelefone::class)->findBy(['pessoa' => $cobrada->getId()]));
    }

    #[TestDox('§2.3: número em branco é recusado com mensagem e não grava nada')]
    public function testAdicionarTelefoneEmBrancoNaoGrava(): void
    {
        $client = static::createClient();
        [, $tenant] = $this->criarAdminLogado($client);
        [, $caso] = $this->semearGrafo($tenant);
        $cobrada = $this->cobradaVinculada($tenant, $caso);

        $crawler = $client->request('GET', '/cobrancas/objetos/' . $caso->getObjeto()->getId());
        $token = $this->tokenDoFormulario($crawler, 'adicionar_telefone');

        $client->request(
            'POST',
            '/cobrancas/objetos/' . $caso->getObjeto()->getId() . '/responsaveis/telefones',
            ['adicionar_telefone' => ['numero' => '', '_token' => $token]],
            [],
            ['HTTP_X_REQUESTED_WITH' => 'XMLHttpRequest'],
        );

        $dados = json_decode((string) $client->getResponse()->getContent(), true);

        self::assertFalse($dados['ok']);
        self::assertSame('Informe o telefone.', $dados['mensagem'], 'a recusa tem de dizer O QUE está errado');
        self::assertNull($dados['html'], 'lista recusada não troca o bloco da tela');

        $em = static::getContainer()->get(EntityManagerInterface::class);
        $em->clear();
        self::assertCount(0, $em->getRepository(PessoaTelefone::class)->findBy(['pessoa' => $cobrada->getId()]));
    }

    #[TestDox('§2.3: objeto de outro escritório responde 404 mesmo com token podre (anti-IDOR)')]
    public function testAdicionarTelefoneEmObjetoDeOutroTenant(): void
    {
        $client = static::createClient();
        $this->criarAdminLogado($client);
        $outroTenant = $this->tenantAvulso();
        [, $casoAlheio] = $this->semearGrafo($outroTenant);
        $cobradaAlheia = $casoAlheio->getPessoaCobradaAtual();
        self::assertNotNull($cobradaAlheia);

        $client->request('POST', '/cobrancas/objetos/' . $casoAlheio->getObjeto()->getId() . '/responsaveis/telefones', [
            'adicionar_telefone' => ['numero' => '(21) 98888-7777', '_token' => 'token-deliberadamente-podre'],
        ]);

        self::assertResponseStatusCodeSame(404);

        $em = static::getContainer()->get(EntityManagerInterface::class);
        $em->clear();
        self::assertCount(
            0,
            $em->getRepository(PessoaTelefone::class)->findBy(['pessoa' => $cobradaAlheia->getId()]),
            'nada pode ser gravado na pessoa do vizinho',
        );
    }

    #[TestDox('§2.3: sem a capacidade de gerenciar, adicionar telefone é barrado no servidor')]
    public function testAdicionarTelefoneExigeCapacidadeDeGerenciar(): void
    {
        $client = static::createClient();
        // Operador com o MÓDULO cobranças (vê a página) mas sem `resources.cobranca.gerenciar` — o
        // mesmo gate que esconde a lista de telefones pelo PII tem de barrar a escrita.
        [, $tenant] = $this->criarOperadorSemCapacidade($client);
        [, $caso] = $this->semearGrafo($tenant);
        $cobrada = $this->cobradaVinculada($tenant, $caso);

        $client->request('GET', '/cobrancas/objetos/' . $caso->getObjeto()->getId());
        self::assertResponseIsSuccessful('o operador enxerga a página; o que ele não pode é escrever');

        $client->request('POST', '/cobrancas/objetos/' . $caso->getObjeto()->getId() . '/responsaveis/telefones', [
            'adicionar_telefone' => ['numero' => '(21) 98888-7777', '_token' => 'token-qualquer'],
        ]);

        // Destino EXPLÍCITO: `semAcesso()` vai para a homepage. Um `assertResponseRedirects()` sem alvo
        // aceitaria também o redirect de recusa por CSRF (`?aba=responsaveis`) e deixaria o gate sem prova.
        self::assertResponseRedirects('/');

        $em = static::getContainer()->get(EntityManagerInterface::class);
        $em->clear();
        self::assertCount(0, $em->getRepository(PessoaTelefone::class)->findBy(['pessoa' => $cobrada->getId()]));
    }

    // ─────────────────── §2.3 Corrigir e excluir telefone (2026-07-28) ───────────────────

    #[TestDox('§2.3: corrigir por AJAX troca o número no lugar e devolve a lista atualizada')]
    public function testEditarTelefonePorAjaxCorrigeONumero(): void
    {
        $client = static::createClient();
        [, $tenant] = $this->criarAdminLogado($client);
        [, $caso] = $this->semearGrafo($tenant);
        $cobrada = $this->cobradaVinculada($tenant, $caso);
        $telefone = $this->telefone($tenant, $cobrada, '(21) 3333-111', atual: true);
        $objetoId = (int) $caso->getObjeto()->getId();

        $crawler = $client->request('GET', '/cobrancas/objetos/' . $objetoId);
        $token = $this->tokenDeEdicao($crawler, (int) $telefone->getId());

        $client->request(
            'POST',
            '/cobrancas/objetos/' . $objetoId . '/responsaveis/telefones/' . $telefone->getId(),
            ['numero' => '(21) 3333-1111', '_token' => $token],
            [],
            ['HTTP_X_REQUESTED_WITH' => 'XMLHttpRequest'],
        );

        self::assertResponseIsSuccessful();
        $dados = json_decode((string) $client->getResponse()->getContent(), true);

        self::assertTrue($dados['ok']);
        self::assertStringContainsString('(21) 3333-1111', $dados['html']);

        $em = static::getContainer()->get(EntityManagerInterface::class);
        $em->clear();
        $telefones = $em->getRepository(PessoaTelefone::class)->findBy(['pessoa' => $cobrada->getId()]);
        self::assertCount(1, $telefones, 'corrigir mexe na MESMA linha — não cria uma nova');
        self::assertSame('(21) 3333-1111', $telefones[0]->getNumero());
        // A coluna-sombra é o que as listagens leem sem N+1: sem ela, a correção do ATUAL ficaria
        // invisível justamente onde o telefone mais aparece.
        self::assertSame('(21) 3333-1111', $em->getRepository(Pessoa::class)->find($cobrada->getId())?->getTelefone());
    }

    // ─────────────── Tipo do telefone: WhatsApp × Telefone (2026-07-28) ───────────────

    #[TestDox('tipo: adicionar como WhatsApp grava o tipo e a lista devolve o link do wa.me')]
    public function testAdicionarTelefoneComoWhatsApp(): void
    {
        $client = static::createClient();
        [, $tenant] = $this->criarAdminLogado($client);
        [, $caso] = $this->semearGrafo($tenant);
        $cobrada = $this->cobradaVinculada($tenant, $caso);
        $objetoId = (int) $caso->getObjeto()->getId();

        $crawler = $client->request('GET', '/cobrancas/objetos/' . $objetoId);
        $token = $this->tokenDoFormulario($crawler, 'adicionar_telefone');

        $client->request(
            'POST',
            '/cobrancas/objetos/' . $objetoId . '/responsaveis/telefones',
            ['adicionar_telefone' => ['numero' => '(21) 98888-7777', 'tipo' => 'whatsapp', '_token' => $token]],
            [],
            ['HTTP_X_REQUESTED_WITH' => 'XMLHttpRequest'],
        );

        self::assertResponseIsSuccessful();
        $dados = json_decode((string) $client->getResponse()->getContent(), true);
        self::assertTrue($dados['ok']);

        // O fragmento devolvido é o que substitui o bloco na tela: se o link e o ícone não vierem
        // nele, o número aparece como fixo até a página ser recarregada.
        self::assertStringContainsString('https://wa.me/5521988887777', $dados['html'], 'WhatsApp discável abre a conversa');
        self::assertStringContainsString('bi-whatsapp', $dados['html'], 'e mostra o símbolo do WhatsApp');

        $em = static::getContainer()->get(EntityManagerInterface::class);
        $em->clear();
        $telefones = $em->getRepository(PessoaTelefone::class)->findBy(['pessoa' => $cobrada->getId()]);
        self::assertSame(TipoTelefone::WhatsApp, $telefones[0]->getTipo());
    }

    #[TestDox('tipo: adicionar como Telefone mantém o ícone e o link de discagem de sempre')]
    public function testAdicionarTelefoneComoFixo(): void
    {
        $client = static::createClient();
        [, $tenant] = $this->criarAdminLogado($client);
        [, $caso] = $this->semearGrafo($tenant);
        $cobrada = $this->cobradaVinculada($tenant, $caso);
        $objetoId = (int) $caso->getObjeto()->getId();

        $crawler = $client->request('GET', '/cobrancas/objetos/' . $objetoId);
        $token = $this->tokenDoFormulario($crawler, 'adicionar_telefone');

        $client->request(
            'POST',
            '/cobrancas/objetos/' . $objetoId . '/responsaveis/telefones',
            ['adicionar_telefone' => ['numero' => '(21) 3333-1111', 'tipo' => 'fixo', '_token' => $token]],
            [],
            ['HTTP_X_REQUESTED_WITH' => 'XMLHttpRequest'],
        );

        $dados = json_decode((string) $client->getResponse()->getContent(), true);
        self::assertTrue($dados['ok']);
        self::assertStringNotContainsString('wa.me', $dados['html'], 'fixo não vira link de WhatsApp');

        $em = static::getContainer()->get(EntityManagerInterface::class);
        $em->clear();
        $telefones = $em->getRepository(PessoaTelefone::class)->findBy(['pessoa' => $cobrada->getId()]);
        self::assertSame(TipoTelefone::Fixo, $telefones[0]->getTipo());
    }

    #[TestDox('tipo: o formulário de adicionar nasce com Telefone marcado — é o comportamento de hoje')]
    public function testFormularioDeAdicionarNasceComFixoMarcado(): void
    {
        $client = static::createClient();
        [, $tenant] = $this->criarAdminLogado($client);
        [, $caso] = $this->semearGrafo($tenant);
        $this->cobradaVinculada($tenant, $caso);

        $aba = $this->abrirAba($client, $caso);
        $marcado = $aba->filter('[data-form="adicionar-telefone"] input[name="adicionar_telefone[tipo]"][checked]');

        self::assertCount(1, $marcado, 'exatamente uma das duas opções vem marcada');
        self::assertSame('fixo', $marcado->attr('value'));
    }

    #[TestDox('tipo: corrigir um telefone pode marcá-lo como WhatsApp')]
    public function testEditarTelefoneMarcaComoWhatsApp(): void
    {
        $client = static::createClient();
        [, $tenant] = $this->criarAdminLogado($client);
        [, $caso] = $this->semearGrafo($tenant);
        $cobrada = $this->cobradaVinculada($tenant, $caso);
        $telefone = $this->telefone($tenant, $cobrada, '(21) 98888-7777', atual: true);
        $objetoId = (int) $caso->getObjeto()->getId();

        $crawler = $client->request('GET', '/cobrancas/objetos/' . $objetoId);
        $token = $this->tokenDeEdicao($crawler, (int) $telefone->getId());

        $client->request(
            'POST',
            '/cobrancas/objetos/' . $objetoId . '/responsaveis/telefones/' . $telefone->getId(),
            ['numero' => '(21) 98888-7777', 'tipo' => 'whatsapp', '_token' => $token],
            [],
            ['HTTP_X_REQUESTED_WITH' => 'XMLHttpRequest'],
        );

        self::assertResponseIsSuccessful();

        $em = static::getContainer()->get(EntityManagerInterface::class);
        $em->clear();
        $telefones = $em->getRepository(PessoaTelefone::class)->findBy(['pessoa' => $cobrada->getId()]);
        self::assertSame(TipoTelefone::WhatsApp, $telefones[0]->getTipo());
    }

    #[TestDox('tipo: corrigir só o número NÃO apaga o tipo que já estava marcado')]
    public function testEditarSemTipoPreservaOTipoGravado(): void
    {
        $client = static::createClient();
        [, $tenant] = $this->criarAdminLogado($client);
        [, $caso] = $this->semearGrafo($tenant);
        $cobrada = $this->cobradaVinculada($tenant, $caso);
        $telefone = $this->telefone($tenant, $cobrada, '(21) 98888-777', atual: true);
        $telefone->setTipo(TipoTelefone::WhatsApp);
        $this->salvar();
        $objetoId = (int) $caso->getObjeto()->getId();

        $crawler = $client->request('GET', '/cobrancas/objetos/' . $objetoId);
        $token = $this->tokenDeEdicao($crawler, (int) $telefone->getId());

        // POST sem o campo `tipo` — é o que uma página aberta antes desta frente mandaria, e é o que o
        // navegador manda se nenhum rádio estiver marcado. Apagar o tipo aqui seria perder dado que
        // alguém declarou, num gesto que só queria consertar um dígito.
        $client->request(
            'POST',
            '/cobrancas/objetos/' . $objetoId . '/responsaveis/telefones/' . $telefone->getId(),
            ['numero' => '(21) 98888-7777', '_token' => $token],
            [],
            ['HTTP_X_REQUESTED_WITH' => 'XMLHttpRequest'],
        );

        self::assertResponseIsSuccessful();

        $em = static::getContainer()->get(EntityManagerInterface::class);
        $em->clear();
        $telefones = $em->getRepository(PessoaTelefone::class)->findBy(['pessoa' => $cobrada->getId()]);
        self::assertSame('(21) 98888-7777', $telefones[0]->getNumero(), 'o número foi corrigido');
        self::assertSame(TipoTelefone::WhatsApp, $telefones[0]->getTipo(), 'e o tipo continuou o mesmo');
    }

    #[TestDox('tipo: telefone legado (sem tipo) abre a edição com os dois rádios em branco')]
    public function testEdicaoDeTelefoneLegadoNaoPreMarcaTipo(): void
    {
        $client = static::createClient();
        [, $tenant] = $this->criarAdminLogado($client);
        [, $caso] = $this->semearGrafo($tenant);
        $cobrada = $this->cobradaVinculada($tenant, $caso);
        $telefone = $this->telefone($tenant, $cobrada, '(21) 3333-1111', atual: true);

        $aba = $this->abrirAba($client, $caso);
        $radios = $aba->filter('#editarTelefone' . $telefone->getId() . ' input[name="tipo"]');

        self::assertCount(2, $radios, 'as duas opções aparecem na edição');
        self::assertCount(0, $radios->filter('[checked]'), 'nenhuma vem marcada: o legado não declarou tipo');
    }

    #[TestDox('tipo: número torto marcado como WhatsApp NÃO vira link de conversa')]
    public function testWhatsAppComNumeroTortoNaoViraLink(): void
    {
        $client = static::createClient();
        [, $tenant] = $this->criarAdminLogado($client);
        [, $caso] = $this->semearGrafo($tenant);
        $cobrada = $this->cobradaVinculada($tenant, $caso);
        // 7 dígitos: existe assim no banco hoje, vindo de importação. `wa.me` com isso abriria conversa
        // com ninguém — o link tem de cair em `tel:`, que ao menos disca o que existe.
        $telefone = $this->telefone($tenant, $cobrada, '6666666', atual: true);
        $telefone->setTipo(TipoTelefone::WhatsApp);
        $this->salvar();

        $lista = $this->abrirAba($client, $caso)->filter('.cob-resp-telefones');

        self::assertStringNotContainsString('wa.me', $lista->html());
        self::assertStringContainsString('tel:6666666', $lista->html());
        // O ícone continua sendo o do WhatsApp: o tipo foi declarado, quem não serve é o número.
        self::assertStringContainsString('bi-whatsapp', $lista->html());
    }

    #[TestDox('§2.3: corrigir com número em branco é recusado e não muda nada')]
    public function testEditarTelefoneEmBrancoNaoGrava(): void
    {
        $client = static::createClient();
        [, $tenant] = $this->criarAdminLogado($client);
        [, $caso] = $this->semearGrafo($tenant);
        $cobrada = $this->cobradaVinculada($tenant, $caso);
        $telefone = $this->telefone($tenant, $cobrada, '(21) 3333-1111', atual: true);
        $objetoId = (int) $caso->getObjeto()->getId();

        $crawler = $client->request('GET', '/cobrancas/objetos/' . $objetoId);
        $token = $this->tokenDeEdicao($crawler, (int) $telefone->getId());

        $client->request(
            'POST',
            '/cobrancas/objetos/' . $objetoId . '/responsaveis/telefones/' . $telefone->getId(),
            ['numero' => '   ', '_token' => $token],
            [],
            ['HTTP_X_REQUESTED_WITH' => 'XMLHttpRequest'],
        );

        $dados = json_decode((string) $client->getResponse()->getContent(), true);

        self::assertFalse($dados['ok']);
        self::assertSame('Informe o telefone.', $dados['mensagem'], 'a recusa tem de dizer O QUE está errado');
        self::assertNull($dados['html'], 'recusa não troca o bloco da tela');

        $em = static::getContainer()->get(EntityManagerInterface::class);
        $em->clear();
        self::assertSame('(21) 3333-1111', $em->getRepository(PessoaTelefone::class)->find($telefone->getId())?->getNumero());
    }

    #[TestDox('§2.3: corrigir com token de OUTRO telefone é recusado (CSRF por item)')]
    public function testEditarTelefoneComTokenDeOutroItemNaoGrava(): void
    {
        $client = static::createClient();
        [, $tenant] = $this->criarAdminLogado($client);
        [, $caso] = $this->semearGrafo($tenant);
        $cobrada = $this->cobradaVinculada($tenant, $caso);
        $alvo = $this->telefone($tenant, $cobrada, '(21) 3333-1111', atual: true);
        $outro = $this->telefone($tenant, $cobrada, '(21) 4444-2222', atual: false);
        $objetoId = (int) $caso->getObjeto()->getId();

        $crawler = $client->request('GET', '/cobrancas/objetos/' . $objetoId);
        // Token do OUTRO item, contra o alvo: é exatamente o que o token por intenção existe para
        // recusar. Um `csrf_token('editar_telefone')` genérico deixaria isto passar.
        $tokenDoOutro = $this->tokenDeEdicao($crawler, (int) $outro->getId());

        $client->request(
            'POST',
            '/cobrancas/objetos/' . $objetoId . '/responsaveis/telefones/' . $alvo->getId(),
            ['numero' => '(21) 9999-9999', '_token' => $tokenDoOutro],
            [],
            ['HTTP_X_REQUESTED_WITH' => 'XMLHttpRequest'],
        );

        $dados = json_decode((string) $client->getResponse()->getContent(), true);

        self::assertFalse($dados['ok']);
        self::assertSame('Token de segurança inválido.', $dados['mensagem']);

        $em = static::getContainer()->get(EntityManagerInterface::class);
        $em->clear();
        self::assertSame('(21) 3333-1111', $em->getRepository(PessoaTelefone::class)->find($alvo->getId())?->getNumero());
    }

    #[TestDox('§2.3: excluir o telefone ATUAL promove o mais recente que sobrou e avisa qual foi')]
    public function testExcluirTelefoneAtualPromoveOMaisRecente(): void
    {
        $client = static::createClient();
        [, $tenant] = $this->criarAdminLogado($client);
        [, $caso] = $this->semearGrafo($tenant);
        $cobrada = $this->cobradaVinculada($tenant, $caso);
        $antigo = $this->telefone($tenant, $cobrada, '(21) 3333-1111', atual: false);
        $meio = $this->telefone($tenant, $cobrada, '(21) 4444-2222', atual: false);
        $atual = $this->telefone($tenant, $cobrada, '(21) 99999-3333', atual: true);
        $objetoId = (int) $caso->getObjeto()->getId();

        $crawler = $client->request('GET', '/cobrancas/objetos/' . $objetoId);
        $token = $this->tokenDeExclusao($crawler, (int) $atual->getId());

        $client->request(
            'POST',
            '/cobrancas/objetos/' . $objetoId . '/responsaveis/telefones/' . $atual->getId() . '/excluir',
            ['_token' => $token],
            [],
            ['HTTP_X_REQUESTED_WITH' => 'XMLHttpRequest'],
        );

        $dados = json_decode((string) $client->getResponse()->getContent(), true);

        self::assertTrue($dados['ok']);
        // A mensagem DIZ qual número virou o atual: sem isso o selo muda de linha e o gestor descobre
        // sozinho — decisão do dono de promover o sucessor exige contar o que aconteceu.
        self::assertSame('Telefone excluído. (21) 4444-2222 passou a ser o atual.', $dados['mensagem']);

        $em = static::getContainer()->get(EntityManagerInterface::class);
        $em->clear();
        self::assertNull($em->getRepository(PessoaTelefone::class)->find($atual->getId()), 'a linha some do banco');
        self::assertTrue($em->getRepository(PessoaTelefone::class)->find($meio->getId())?->isAtual());
        self::assertFalse($em->getRepository(PessoaTelefone::class)->find($antigo->getId())?->isAtual());
        self::assertSame('(21) 4444-2222', $em->getRepository(Pessoa::class)->find($cobrada->getId())?->getTelefone());
    }

    #[TestDox('§2.3: excluir um item do histórico não mexe em quem é o atual')]
    public function testExcluirTelefoneDoHistoricoNaoPromoveNinguem(): void
    {
        $client = static::createClient();
        [, $tenant] = $this->criarAdminLogado($client);
        [, $caso] = $this->semearGrafo($tenant);
        $cobrada = $this->cobradaVinculada($tenant, $caso);
        $velho = $this->telefone($tenant, $cobrada, '(21) 3333-1111', atual: false);
        $atual = $this->telefone($tenant, $cobrada, '(21) 99999-3333', atual: true);
        $objetoId = (int) $caso->getObjeto()->getId();

        $crawler = $client->request('GET', '/cobrancas/objetos/' . $objetoId);
        $token = $this->tokenDeExclusao($crawler, (int) $velho->getId());

        $client->request(
            'POST',
            '/cobrancas/objetos/' . $objetoId . '/responsaveis/telefones/' . $velho->getId() . '/excluir',
            ['_token' => $token],
            [],
            ['HTTP_X_REQUESTED_WITH' => 'XMLHttpRequest'],
        );

        $dados = json_decode((string) $client->getResponse()->getContent(), true);

        self::assertTrue($dados['ok']);
        self::assertSame('Telefone excluído.', $dados['mensagem'], 'sem promoção não se anuncia promoção');

        $em = static::getContainer()->get(EntityManagerInterface::class);
        $em->clear();
        self::assertNull($em->getRepository(PessoaTelefone::class)->find($velho->getId()));
        self::assertTrue($em->getRepository(PessoaTelefone::class)->find($atual->getId())?->isAtual());
    }

    #[TestDox('§2.3: excluir o ÚNICO telefone deixa a pessoa sem telefone (sombra zerada)')]
    public function testExcluirOUnicoTelefoneZeraASombra(): void
    {
        $client = static::createClient();
        [, $tenant] = $this->criarAdminLogado($client);
        [, $caso] = $this->semearGrafo($tenant);
        $cobrada = $this->cobradaVinculada($tenant, $caso);
        $unico = $this->telefone($tenant, $cobrada, '(21) 99999-3333', atual: true);
        $objetoId = (int) $caso->getObjeto()->getId();

        $crawler = $client->request('GET', '/cobrancas/objetos/' . $objetoId);
        $token = $this->tokenDeExclusao($crawler, (int) $unico->getId());

        $client->request(
            'POST',
            '/cobrancas/objetos/' . $objetoId . '/responsaveis/telefones/' . $unico->getId() . '/excluir',
            ['_token' => $token],
            [],
            ['HTTP_X_REQUESTED_WITH' => 'XMLHttpRequest'],
        );

        $dados = json_decode((string) $client->getResponse()->getContent(), true);
        self::assertTrue($dados['ok']);
        self::assertSame('Telefone excluído.', $dados['mensagem']);

        $em = static::getContainer()->get(EntityManagerInterface::class);
        $em->clear();
        self::assertCount(0, $em->getRepository(PessoaTelefone::class)->findBy(['pessoa' => $cobrada->getId()]));
        // Deixar a sombra com o número apagado faria as listagens seguirem mostrando um telefone que
        // não existe mais em lugar nenhum.
        self::assertNull($em->getRepository(Pessoa::class)->find($cobrada->getId())?->getTelefone());
    }

    #[TestDox('§2.3: sem AJAX, corrigir e excluir voltam para a PRÓPRIA aba')]
    public function testEditarEExcluirSemAjaxVoltamParaAAba(): void
    {
        $client = static::createClient();
        [, $tenant] = $this->criarAdminLogado($client);
        [, $caso] = $this->semearGrafo($tenant);
        $cobrada = $this->cobradaVinculada($tenant, $caso);
        $telefone = $this->telefone($tenant, $cobrada, '(21) 3333-1111', atual: true);
        $objetoId = (int) $caso->getObjeto()->getId();

        $crawler = $client->request('GET', '/cobrancas/objetos/' . $objetoId);
        $tokenEditar = $this->tokenDeEdicao($crawler, (int) $telefone->getId());

        $client->request('POST', '/cobrancas/objetos/' . $objetoId . '/responsaveis/telefones/' . $telefone->getId(), [
            'numero' => '(21) 3333-2222',
            '_token' => $tokenEditar,
        ]);
        self::assertResponseRedirects('/cobrancas/objetos/' . $objetoId . '?aba=responsaveis');

        $crawler = $client->request('GET', '/cobrancas/objetos/' . $objetoId);
        $tokenExcluir = $this->tokenDeExclusao($crawler, (int) $telefone->getId());

        $client->request('POST', '/cobrancas/objetos/' . $objetoId . '/responsaveis/telefones/' . $telefone->getId() . '/excluir', [
            '_token' => $tokenExcluir,
        ]);
        self::assertResponseRedirects('/cobrancas/objetos/' . $objetoId . '?aba=responsaveis');

        $em = static::getContainer()->get(EntityManagerInterface::class);
        $em->clear();
        self::assertCount(0, $em->getRepository(PessoaTelefone::class)->findBy(['pessoa' => $cobrada->getId()]));
    }

    #[TestDox('§2.3: corrigir/excluir telefone de objeto de OUTRO escritório responde 404 (anti-IDOR)')]
    public function testEditarEExcluirTelefoneEmObjetoDeOutroTenant(): void
    {
        $client = static::createClient();
        $this->criarAdminLogado($client);
        $outroTenant = $this->tenantAvulso();
        [, $casoAlheio] = $this->semearGrafo($outroTenant);
        $cobradaAlheia = $casoAlheio->getPessoaCobradaAtual();
        self::assertNotNull($cobradaAlheia);
        $telefoneAlheio = $this->telefone($outroTenant, $cobradaAlheia, '(21) 3333-1111', atual: true);
        $objetoAlheio = (int) $casoAlheio->getObjeto()->getId();

        $client->request('POST', '/cobrancas/objetos/' . $objetoAlheio . '/responsaveis/telefones/' . $telefoneAlheio->getId(), [
            'numero' => '(21) 9999-9999',
            '_token' => 'token-deliberadamente-podre',
        ]);
        self::assertResponseStatusCodeSame(404);

        $client->request('POST', '/cobrancas/objetos/' . $objetoAlheio . '/responsaveis/telefones/' . $telefoneAlheio->getId() . '/excluir', [
            '_token' => 'token-deliberadamente-podre',
        ]);
        self::assertResponseStatusCodeSame(404);

        $em = static::getContainer()->get(EntityManagerInterface::class);
        $em->clear();
        // ⚠️ O filtro `tenant` fica LIGADO no EM depois da última request (TenantFilterListener), com o
        // tenant do usuário logado. Consultar o telefone do vizinho com ele ligado devolve null — e a
        // asserção "sobreviveu" falharia por motivo errado, ou pior: um "assertCount(0)" passaria
        // mesmo se o dado tivesse vazado. Desligar aqui é o que faz a prova ser sobre o DADO.
        if ($em->getFilters()->isEnabled('tenant')) {
            $em->getFilters()->disable('tenant');
        }
        $sobrevivente = $em->getRepository(PessoaTelefone::class)->find($telefoneAlheio->getId());
        self::assertNotNull($sobrevivente, 'o telefone do vizinho continua lá');
        self::assertSame('(21) 3333-1111', $sobrevivente->getNumero(), 'e intacto');
    }

    #[TestDox('§2.3: sem a capacidade de gerenciar, corrigir e excluir são barrados no servidor')]
    public function testEditarEExcluirTelefoneExigemCapacidadeDeGerenciar(): void
    {
        $client = static::createClient();
        // Mesmo gate do PII que esconde a lista: quem não pode VER os telefones não pode mexer neles.
        [, $tenant] = $this->criarOperadorSemCapacidade($client);
        [, $caso] = $this->semearGrafo($tenant);
        $cobrada = $this->cobradaVinculada($tenant, $caso);
        $telefone = $this->telefone($tenant, $cobrada, '(21) 3333-1111', atual: true);
        $objetoId = (int) $caso->getObjeto()->getId();

        $client->request('POST', '/cobrancas/objetos/' . $objetoId . '/responsaveis/telefones/' . $telefone->getId(), [
            'numero' => '(21) 9999-9999',
            '_token' => 'token-qualquer',
        ]);
        // Destino EXPLÍCITO: `semAcesso()` vai para a homepage — um redirect sem alvo aceitaria também
        // a recusa por CSRF (`?aba=responsaveis`) e deixaria o gate sem prova.
        self::assertResponseRedirects('/');

        $client->request('POST', '/cobrancas/objetos/' . $objetoId . '/responsaveis/telefones/' . $telefone->getId() . '/excluir', [
            '_token' => 'token-qualquer',
        ]);
        self::assertResponseRedirects('/');

        $em = static::getContainer()->get(EntityManagerInterface::class);
        $em->clear();
        $sobrevivente = $em->getRepository(PessoaTelefone::class)->find($telefone->getId());
        self::assertNotNull($sobrevivente);
        self::assertSame('(21) 3333-1111', $sobrevivente->getNumero());
    }

    // ───────────────────────────── §2.4 Faixa de qualificação ─────────────────────────────

    #[TestDox('§2.4: a faixa mostra o dado real, e o e-mail/endereço são os marcados como ATUAL')]
    public function testFaixaDeQualificacaoUsaOsItensAtuais(): void
    {
        $client = static::createClient();
        [, $tenant] = $this->criarAdminLogado($client);
        [, $caso] = $this->semearGrafo($tenant);
        $cobrada = $this->cobradaVinculada($tenant, $caso);

        $cobrada->setCpf('123.456.789-00');
        $cobrada->setEstadoCivil(EstadoCivil::Casado);
        $this->salvar();

        // Dois de cada, com o ATUAL sendo o SEGUNDO: se a faixa pegasse o primeiro da lista (ordenada
        // por `criadoEm ASC`) em vez do marcado, o teste cai. É o erro natural de quem usa `|first`.
        $this->email($tenant, $cobrada, 'antigo@exemplo.com', atual: false);
        $this->email($tenant, $cobrada, 'atual@exemplo.com', atual: true);
        $this->endereco($tenant, $cobrada, 'Rua Antiga', '10', atual: false);
        $this->endereco($tenant, $cobrada, 'Rua Atual', '20', atual: true);

        $faixa = $this->abrirAba($client, $caso)->filter('[data-faixa="qualificacao"]');

        self::assertSame('CPF', trim($faixa->filter('[data-qualif="documento"] .cob-resp-qualif-rot')->text()));
        self::assertSame('123.456.789-00', trim($faixa->filter('[data-qualif="documento"] .cob-resp-qualif-val')->text()));
        self::assertSame('Casado(a)', trim($faixa->filter('[data-qualif="estado-civil"] .cob-resp-qualif-val')->text()));

        $email = $faixa->filter('[data-qualif="email"]')->text();
        self::assertStringContainsString('atual@exemplo.com', $email);
        self::assertStringNotContainsString('antigo@exemplo.com', $email, 'a faixa mostra o e-mail ATUAL, não o primeiro cadastrado');

        $endereco = $faixa->filter('[data-qualif="endereco"]')->text();
        self::assertStringContainsString('Rua Atual, 20', $endereco);
        self::assertStringNotContainsString('Rua Antiga', $endereco, 'a faixa mostra o endereço ATUAL, não o primeiro cadastrado');
    }

    #[TestDox('§2.4: campo vazio vira "não informado", e a pessoa jurídica vê o rótulo CNPJ')]
    public function testFaixaDeQualificacaoComCamposVazios(): void
    {
        $client = static::createClient();
        [, $tenant] = $this->criarAdminLogado($client);
        [, $caso] = $this->semearGrafo($tenant);
        $cobrada = $this->cobradaVinculada($tenant, $caso);
        $cobrada->setCpf(null);
        $cobrada->setCnpj('11.222.333/0001-44');
        $cobrada->setEstadoCivil(null);
        $this->salvar();

        $faixa = $this->abrirAba($client, $caso)->filter('[data-faixa="qualificacao"]');

        // Um item só de documento, com o rótulo certo: "CPF: não informado" numa empresa seria falso.
        self::assertSame('CNPJ', trim($faixa->filter('[data-qualif="documento"] .cob-resp-qualif-rot')->text()));
        self::assertSame('11.222.333/0001-44', trim($faixa->filter('[data-qualif="documento"] .cob-resp-qualif-val')->text()));

        foreach (['email', 'estado-civil', 'endereco'] as $campo) {
            self::assertSame(
                'não informado',
                trim($faixa->filter('[data-qualif="' . $campo . '"] .cob-resp-qualif-val')->text()),
                "o campo {$campo} vazio tem de dizer 'não informado', não ficar em branco",
            );
        }
    }

    // ───────────────────────────── §2.5 / §2.6 ─────────────────────────────

    #[TestDox('§2.5: o accordion das outras pessoas e o Adicionar pessoa continuam inteiros')]
    public function testAccordionEAdicionarPessoaPreservados(): void
    {
        $client = static::createClient();
        [, $tenant] = $this->criarAdminLogado($client);
        [, $caso] = $this->semearGrafo($tenant);
        $this->cobradaVinculada($tenant, $caso);
        $outra = PessoaFactory::createOne(['tenant' => $tenant, 'nome' => 'Maria Fiadora'])->_real();
        $this->vincular($tenant, $caso, $outra, TipoVinculo::Representante);

        $aba = $this->abrirAba($client, $caso);
        $itens = $aba->filter('.cob-resp-accordion .accordion-item');

        self::assertCount(1, $itens, 'a segunda pessoa entra no accordion');
        self::assertStringContainsString('Maria Fiadora', $itens->text());
        self::assertCount(1, $itens->filter('.js-definir-cobrada'), 'Definir como atual preservado');
        self::assertCount(1, $itens->filter('[data-bs-target="#modalEncerrarVinculo"]'), 'Encerrar vínculo preservado');
        self::assertSelectorExists('#tab-responsaveis [data-bs-target="#modalVincularPessoaObjeto"]', 'Adicionar pessoa: vincular existente');
        self::assertSelectorExists('#tab-responsaveis [data-bs-target="#modalNovaPessoa"]', 'Adicionar pessoa: cadastrar nova');
    }

    #[TestDox('§2.6: o rodapé volta para a carteira e avança para a próxima unidade; na última, desabilitado')]
    public function testRodapeVoltarEProximaUnidade(): void
    {
        $client = static::createClient();
        [, $tenant] = $this->criarAdminLogado($client);
        [$carteira, $caso] = $this->semearGrafo($tenant);
        $this->cobradaVinculada($tenant, $caso);

        $objeto = $caso->getObjeto();
        $objeto->setIdentificacao('Unidade 100');
        $this->salvar();
        // Vizinho pela ordem `identificacao ASC` — a MESMA de `objetoProximoId` das setas do cabeçalho.
        $proximo = ObjetoCobrancaFactory::createOne([
            'tenant' => $tenant, 'carteira' => $carteira, 'identificacao' => 'Unidade 200',
        ])->_real();
        CasoCobrancaFactory::createOne([
            'tenant' => $tenant, 'objeto' => $proximo,
            'pessoaCobradaAtual' => PessoaFactory::createOne(['tenant' => $tenant]),
        ]);

        $rodape = $this->abrirAba($client, $caso)->filter('.cob-resp-rodape');
        self::assertSame(
            '/cobrancas/carteiras/' . $carteira->getId(),
            $rodape->filter('[data-rodape="voltar"]')->attr('href'),
            '← Voltar leva à carteira',
        );
        self::assertStringStartsWith(
            '/cobrancas/objetos/' . $proximo->getId(),
            (string) $rodape->filter('[data-rodape="proximo"]')->attr('href'),
            'Próxima unidade reusa o vizinho das setas do cabeçalho',
        );

        // Na ÚLTIMA da carteira não há para onde ir: o botão fica desabilitado, não some nem quebra.
        $naUltima = $this->abrirAba($client, $this->casoDoObjeto($proximo))->filter('.cob-resp-rodape [data-rodape="proximo"]');
        self::assertNull($naUltima->attr('href'), 'na última unidade o botão não navega');
        self::assertNotNull($naUltima->attr('disabled'));
    }

    // ───────────────────────────── §3 Painel de qualificação ─────────────────────────────

    #[TestDox('§3: os três botões gravam num clique, na ordem do enum, com o CSRF da intenção do caso')]
    public function testPainelTemOsTresBotoes(): void
    {
        $client = static::createClient();
        [, $tenant] = $this->criarAdminLogado($client);
        [, $caso] = $this->semearGrafo($tenant);
        $this->cobradaVinculada($tenant, $caso);

        $painel = $this->abrirAba($client, $caso)->filter('[data-painel="qualificacao"]');
        $botoes = $painel->filter('[data-qualificacao]');

        self::assertCount(3, $botoes, 'os três botões da §3 têm de estar na tela');
        self::assertSame(
            array_map(static fn (QualificacaoContato $q): string => $q->value, QualificacaoContato::doPainel()),
            $botoes->each(static fn (Crawler $b): string => (string) $b->attr('data-qualificacao')),
            'a ordem é a do enum (as duas negativas, depois a positiva) — o template não reordena',
        );

        $form = $botoes->first()->closest('form');
        self::assertNotNull($form);
        self::assertSame('/cobrancas/casos/' . $caso->getId() . '/qualificacoes', $form->attr('action'));
        self::assertSame('post', strtolower((string) $form->attr('method')));
        self::assertSame(
            QualificacaoContato::RecusaPagamento->value,
            $form->filter('input[name="qualificacao"]')->attr('value'),
            'o valor do enum vai no campo oculto — é o que a rota hidrata',
        );
        // Intenção STATEFUL (o painel não usa Symfony Form): sem `csrf_token()` no Twig o POST é recusado.
        self::assertNotSame('', (string) $form->filter('input[name="_token"]')->attr('value'));
    }

    #[TestDox('§3.4: a listinha traz só as qualificações, mais recente primeiro, com data e autor')]
    public function testListinhaMaisRecentePrimeiro(): void
    {
        $client = static::createClient();
        [$usuario, $tenant] = $this->criarAdminLogado($client);
        [, $caso] = $this->semearGrafo($tenant);
        $this->cobradaVinculada($tenant, $caso);

        $this->qualificar($tenant, $caso, $usuario, QualificacaoContato::TelefoneInexistente, new \DateTimeImmutable('-3 minutes'));
        $this->qualificar($tenant, $caso, $usuario, QualificacaoContato::RecusaPagamento, new \DateTimeImmutable('-1 minute'));
        // Uma ANOTAÇÃO no mesmo caso: a listinha é só de qualificações e não pode arrastar o resto do
        // histórico junto ("nada além disso", §3.4). O payload dela carrega a chave `qualificacao` DE
        // PROPÓSITO: sem isso quem a excluiria seria o guard de payload vazio, e não o filtro por TIPO —
        // o teste passaria mesmo com o filtro removido (foi exatamente o defeito achado na Etapa 3).
        EventoHistoricoFactory::createOne([
            'tenant' => $tenant, 'caso' => $caso, 'tipo' => TipoEventoHistorico::Anotacao,
            'descricao' => 'Anotação que não é qualificação', 'usuario' => $usuario,
            'dados' => ['qualificacao' => QualificacaoContato::PromessaPagamento->value],
        ]);

        $itens = $this->abrirAba($client, $caso)->filter('[data-painel="qualificacao"] .cob-qualif-item');

        self::assertCount(2, $itens, 'só as duas qualificações entram na listinha');
        self::assertStringContainsString('Recusa de pagamento', $itens->eq(0)->text(), 'a mais recente vem primeiro');
        self::assertStringContainsString('Telefone inexistente', $itens->eq(1)->text());
        self::assertStringContainsString('Gestor Cobrança', $itens->eq(0)->text(), 'a linha diz quem qualificou');
        self::assertMatchesRegularExpression('/\d{2}\/\d{2}\/\d{4}/', $itens->eq(0)->text(), 'e quando');
    }

    #[TestDox('§3.5: o desfazer aparece só na linha que o SERVIDOR marcou com podeDesfazer')]
    public function testDesfazerSoNaLinhaMarcadaPeloServidor(): void
    {
        $client = static::createClient();
        [$usuario, $tenant] = $this->criarAdminLogado($client);
        [, $caso] = $this->semearGrafo($tenant);
        $this->cobradaVinculada($tenant, $caso);

        // Duas do PRÓPRIO usuário, ambas dentro da janela de 5 minutos: a diferença entre elas é só a
        // quarta condição — ser a MAIS RECENTE. Se o template recalculasse prazo e autoria (o que a
        // §3.5 proíbe), as duas ganhariam botão.
        $antiga = $this->qualificar($tenant, $caso, $usuario, QualificacaoContato::TelefoneInexistente, new \DateTimeImmutable('-2 minutes'));
        $recente = $this->qualificar($tenant, $caso, $usuario, QualificacaoContato::RecusaPagamento, new \DateTimeImmutable('-1 minute'));

        $painel = $this->abrirAba($client, $caso)->filter('[data-painel="qualificacao"]');

        $botoes = $painel->filter('[data-acao="desfazer-qualificacao"]');
        self::assertCount(1, $botoes, 'só a mais recente pode ser desfeita');
        self::assertCount(1, $painel->filter('[data-qualif-linha="' . $recente->getId() . '"] [data-acao="desfazer-qualificacao"]'));
        self::assertCount(0, $painel->filter('[data-qualif-linha="' . $antiga->getId() . '"] [data-acao="desfazer-qualificacao"]'));

        $form = $botoes->first()->closest('form');
        self::assertNotNull($form);
        self::assertSame('/cobrancas/qualificacoes/' . $recente->getId() . '/desfazer', $form->attr('action'));
        self::assertNotSame('', (string) $form->filter('input[name="_token"]')->attr('value'));
    }

    #[TestDox('§3.5: qualificação de OUTRO usuário não ganha desfazer, mesmo sendo a mais recente')]
    public function testDesfazerNaoApareceParaQualificacaoDeOutroUsuario(): void
    {
        $client = static::createClient();
        [, $tenant] = $this->criarAdminLogado($client);
        [, $caso] = $this->semearGrafo($tenant);
        $this->cobradaVinculada($tenant, $caso);

        $colega = $this->outroUsuario();
        $this->qualificar($tenant, $caso, $colega, QualificacaoContato::RecusaPagamento, new \DateTimeImmutable('-1 minute'));

        $painel = $this->abrirAba($client, $caso)->filter('[data-painel="qualificacao"]');

        self::assertCount(1, $painel->filter('.cob-qualif-item'), 'a linha do colega aparece na listinha…');
        self::assertCount(0, $painel->filter('[data-acao="desfazer-qualificacao"]'), '…mas sem o desfazer: a autoria é do colega');
    }

    #[TestDox('§3: caso encerrado tira os três botões e MANTÉM o desfazer (decisão da Etapa 2)')]
    public function testCasoEncerradoTiraOsBotoesMasMantemODesfazer(): void
    {
        $client = static::createClient();
        [$usuario, $tenant] = $this->criarAdminLogado($client);
        [, $caso] = $this->semearGrafo($tenant, ['status' => StatusCaso::Encerrado]);
        $this->cobradaVinculada($tenant, $caso);
        $this->qualificar($tenant, $caso, $usuario, QualificacaoContato::RecusaPagamento, new \DateTimeImmutable('-1 minute'));

        $painel = $this->abrirAba($client, $caso)->filter('[data-painel="qualificacao"]');

        self::assertCount(0, $painel->filter('[data-qualificacao]'), 'caso encerrado não recebe lançamento novo (§17)');
        // E é justamente por isso que o desfazer continua: sem ele, o clique errado ficaria permanente e
        // sem saída, já que nem a qualificação corretiva por cima seria possível.
        self::assertCount(1, $painel->filter('[data-acao="desfazer-qualificacao"]'), 'o desfazer sobrevive ao encerramento');
    }

    // ───────────────────────────── Gates de capacidade ─────────────────────────────

    #[TestDox('Sem a capacidade de gerenciar, a aba não expõe mais PII do que a ficha protegeria')]
    public function testSemCapacidadeAAbaNaoAmpliaAExposicaoDePii(): void
    {
        $client = static::createClient();
        // Só o módulo `cobrancas` (leitura): a página do objeto abre, a ficha da pessoa não.
        [, $tenant] = $this->criarOperadorSemCapacidade($client);
        [, $caso] = $this->semearGrafo($tenant);
        $cobrada = $this->cobradaVinculada($tenant, $caso);
        $cobrada->setCpf('123.456.789-00');
        $cobrada->setEstadoCivil(EstadoCivil::Casado);
        $this->salvar();
        $this->telefone($tenant, $cobrada, '(21) 3333-1111', atual: false);
        $this->telefone($tenant, $cobrada, '(21) 99999-2222', atual: true);
        $this->endereco($tenant, $cobrada, 'Rua das Acácias', '100', atual: true);

        $aba = $this->abrirAba($client, $caso);

        // O que a Etapa 6 ACRESCENTOU fica atrás da capacidade que a ficha exige: sem o gate, quem não
        // pode abrir `cobranca_pessoa_show` leria aqui um superconjunto do que aquela rota protege.
        self::assertCount(0, $aba->filter('.cob-resp-telefones'), 'a lista completa de telefones exige a capacidade');
        self::assertCount(0, $aba->filter('[data-faixa="qualificacao"]'), 'a faixa (endereço/estado civil) exige a capacidade');
        self::assertStringNotContainsString('Rua das Acácias', $aba->text(), 'endereço completo não pode vazar para o perfil de leitura');
        self::assertStringNotContainsString('Casado(a)', $aba->text(), 'estado civil não pode vazar para o perfil de leitura');

        // …e o que já era visível ANTES da Etapa 6 continua: nada foi tirado de quem só lê.
        $restrita = $aba->filter('[data-faixa="qualificacao-restrita"]');
        self::assertCount(1, $restrita, 'a paridade com a aba anterior tem de sobreviver');
        self::assertStringContainsString('123.456.789-00', $restrita->text());

        // Badge: acusa (falta estado civil? não — falta nada aqui), mas SEM link, porque a rota da ficha
        // negaria o clique. Neste cenário a ficha está completa, então nem badge deve existir.
        self::assertCount(0, $aba->filter('[data-badge="qualificacao-incompleta"]'));
    }

    #[TestDox('Sem a capacidade, o badge de qualificação incompleta aparece SEM link para a ficha')]
    public function testSemCapacidadeOBadgeNaoLinkaAFicha(): void
    {
        $client = static::createClient();
        [, $tenant] = $this->criarOperadorSemCapacidade($client);
        [, $caso] = $this->semearGrafo($tenant);
        $cobrada = $this->cobradaVinculada($tenant, $caso);
        $cobrada->setCpf(null);
        $cobrada->setCnpj(null);
        $this->salvar();

        $badge = $this->abrirAba($client, $caso)->filter('[data-badge="qualificacao-incompleta"]');

        self::assertCount(1, $badge, 'o aviso continua — ele não é PII, é completude');
        self::assertNull($badge->attr('href'), 'sem a capacidade o clique cairia em "sem acesso": o badge vira aviso, não link');
    }

    #[TestDox('Sem a capacidade, o painel não oferece nem os três botões nem o desfazer da própria qualificação')]
    public function testSemCapacidadeOPainelNaoOfereceMutacao(): void
    {
        $client = static::createClient();
        [$operador, $tenant] = $this->criarOperadorSemCapacidade($client);
        [, $caso] = $this->semearGrafo($tenant);
        $this->cobradaVinculada($tenant, $caso);
        // Qualificação do PRÓPRIO operador, recém-criada: as quatro condições da §3.5 valem, e é só a
        // CAPACIDADE que separa. Sem o `and podeQualificarDesfazer` no Twig, o botão apareceria e a
        // rota (que exige `resources.cobranca.gerenciar`) recusaria depois do clique.
        $this->qualificar($tenant, $caso, $operador, QualificacaoContato::RecusaPagamento, new \DateTimeImmutable('-1 minute'));

        $painel = $this->abrirAba($client, $caso)->filter('[data-painel="qualificacao"]');

        self::assertCount(1, $painel, 'o painel continua visível: a listinha é leitura');
        self::assertCount(1, $painel->filter('.cob-qualif-item'), 'a linha registrada aparece…');
        self::assertCount(0, $painel->filter('[data-qualificacao]'), '…mas sem os três botões de gravar');
        self::assertCount(0, $painel->filter('[data-acao="desfazer-qualificacao"]'), '…e sem o desfazer');
    }

    #[TestDox('Sem a capacidade, "Marcar como atual" e o mini-form de telefone não são renderizados')]
    public function testSemCapacidadeNaoHaMutacaoDeTelefone(): void
    {
        $client = static::createClient();
        [, $tenant] = $this->criarOperadorSemCapacidade($client);
        [, $caso] = $this->semearGrafo($tenant);
        $cobrada = $this->cobradaVinculada($tenant, $caso);
        $this->telefone($tenant, $cobrada, '(21) 3333-1111', atual: false);
        $this->telefone($tenant, $cobrada, '(21) 99999-2222', atual: true);

        $aba = $this->abrirAba($client, $caso);

        // ⚠️ DEFESA DUPLA, e este teste não separa as duas. Quem esconde estes dois é o gate de PII que
        // tira a lista de telefones inteira da tela; o `{% elseif podeAbrirFicha %}` do botão e o
        // `formTelefoneCobrada` nulo no controller são a segunda camada. Medido em 2026-07-27: remover a
        // camada interna deixa este teste VERDE. O que ele prova é que a TELA não oferece a mutação —
        // não que o gate do botão sozinho funcione. Não prometa mais do que isso ao mexer aqui.
        self::assertCount(0, $aba->filter('[data-acao="marcar-telefone-atual"]'), 'a rota exige a capacidade — o botão não pode aparecer');
        self::assertCount(0, $aba->filter('[data-form="adicionar-telefone"]'), 'idem para o mini-form de adicionar');
        // Este, sim, é provado pelo próprio gate: o cabeçalho da aba não está atrás do gate de PII.
        self::assertCount(0, $aba->filter('[data-acao="editar-ficha"]'), 'e para o "Editar" do cabeçalho da aba');
    }

    // ───────────────────────────── Anti duplo-submit dos forms crus ─────────────────────────────

    /**
     * O achado da revisão da Etapa 6: `data-disable-on-submit` era INERTE nesta página. O handler
     * existia só em `cobranca/pessoa/show.html.twig`, e ele varre apenas o DOM daquela página — os
     * forms daqui carregavam um atributo que ninguém lia, e um duplo clique gravava DUAS
     * qualificações (cada uma contando como trabalho de cobrança na Central, com o desfazer removendo
     * uma por vez). O handler foi copiado para `objeto/show.html.twig`, mas até aqui NADA impedia que
     * ele fosse removido de novo.
     *
     * As DUAS pontas são asseridas de propósito, porque cada uma sozinha passa verde no defeito da
     * outra: só o markup continuaria verde com o handler apagado (que é literalmente o bug original),
     * e só o handler continuaria verde se os forms perdessem o atributo. PHPUnit não executa JS — o
     * que se prova aqui é que o gancho e quem o lê continuam existindo, no mesmo documento.
     */
    #[TestDox('Os forms CRUS da aba carregam o gancho anti duplo-submit E a página carrega o handler que o lê')]
    public function testFormsDaAbaEstaoProtegidosContraDuploSubmit(): void
    {
        $client = static::createClient();
        [$usuario, $tenant] = $this->criarAdminLogado($client);
        [, $caso] = $this->semearGrafo($tenant);
        $cobrada = $this->cobradaVinculada($tenant, $caso);
        // Um telefone não-atual faz nascer o form de "Marcar como atual"; a qualificação recente do
        // próprio usuário faz nascer o do "desfazer". Sem os dois, o cenário cobriria só 4 dos 6 forms.
        $this->telefone($tenant, $cobrada, '(21) 3333-1111', atual: false);
        $this->telefone($tenant, $cobrada, '(21) 99999-2222', atual: true);
        $this->qualificar($tenant, $caso, $usuario, QualificacaoContato::RecusaPagamento, new \DateTimeImmutable('-1 minute'));

        $crawler = $client->request('GET', '/cobrancas/objetos/' . $caso->getObjeto()->getId());
        self::assertResponseIsSuccessful();

        // Ponta 1 — o markup. TODO form da aba, sem lista de exceções: um form novo sem o gancho
        // derruba este teste em vez de nascer desprotegido em silêncio.
        $forms = $crawler->filter('#tab-responsaveis form');
        self::assertCount(
            8,
            $forms,
            '3 de qualificação + desfazer + marcar telefone atual + adicionar telefone + 1 de corrigir POR telefone (2)',
        );

        $semGancho = $forms->reduce(
            static fn (Crawler $form): bool => $form->attr('data-disable-on-submit') === null,
        );
        self::assertCount(
            0,
            $semGancho,
            'form da aba sem `data-disable-on-submit`: ' . implode(', ', $semGancho->each(
                static fn (Crawler $f): string => (string) $f->attr('action'),
            )),
        );

        // Cada form tem de ter um `button[type=submit]` — é ele que o handler desabilita. Um form que
        // submeta por outro caminho carregaria o gancho e continuaria clicável duas vezes.
        foreach ($forms->each(static fn (Crawler $f): Crawler => $f) as $form) {
            self::assertGreaterThanOrEqual(
                1,
                $form->filter('button[type="submit"]')->count(),
                'o handler desabilita o `button[type=submit]`: sem ele o gancho não protege nada',
            );
        }

        // Ponta 2 — o handler. Não há seletor CSS para "existe uma função que lê este atributo", então
        // aqui se checa o texto do `<script>` (mesmo critério e mesma justificativa do
        // `ObjetoShowContratoJsTest`, que trava os outros ganchos de JS desta página).
        //
        // ⚠️ A checagem é por LITERAL: reformatar a linha do `querySelectorAll` em `show.html.twig`
        // (trocar aspas simples por duplas, quebrar a linha) derruba este teste com o handler intacto.
        // É falso-VERMELHO, não falso-verde — quem reformatar ajusta a string aqui e segue. O inverso
        // (afrouxar para um regex tolerante) devolveria o risco de dar verde com o handler morto, que é
        // exatamente o defeito que este teste existe para pegar.
        $html = (string) $client->getResponse()->getContent();
        $posHandler = strpos($html, "querySelectorAll('.cobrancas-page form[data-disable-on-submit]')");
        self::assertNotFalse($posHandler, 'o handler anti duplo-submit sumiu de objeto/show.html.twig — o gancho volta a ser inerte');

        // E ele fica ANTES do guard de bootstrap, de propósito: a proteção não depende do bundle e não
        // pode cair junto se ele não carregar. Trocar a ordem devolve o duplo-submit em toda página
        // onde o CDN/asset falhe — exatamente quando o usuário mais clica de novo.
        $posGuard = strpos($html, "typeof bootstrap === 'undefined'");
        self::assertNotFalse($posGuard);
        self::assertLessThan(
            $posGuard,
            $posHandler,
            'o handler tem de rodar ANTES do `return` do guard de bootstrap, senão morre junto com o bundle',
        );
    }

    // ── apoio ────────────────────────────────────────────────────────────────────────────────────

    private function abrirAba(KernelBrowser $client, CasoCobranca $caso): Crawler
    {
        $crawler = $client->request('GET', '/cobrancas/objetos/' . $caso->getObjeto()->getId());
        self::assertResponseIsSuccessful();

        return $crawler->filter('#tab-responsaveis');
    }

    /** Vincula a pessoa cobrada atual ao objeto — sem o vínculo a aba não tem card nem ficha na tela. */
    private function cobradaVinculada(Tenant $tenant, CasoCobranca $caso): Pessoa
    {
        $pessoa = $caso->getPessoaCobradaAtual();
        self::assertNotNull($pessoa);
        $this->vincular($tenant, $caso, $pessoa, TipoVinculo::Proprietario);

        return $pessoa;
    }

    private function vincular(Tenant $tenant, CasoCobranca $caso, Pessoa $pessoa, TipoVinculo $tipo): \App\Cobranca\Entity\VinculoPessoaObjeto
    {
        return VinculoPessoaObjetoFactory::createOne([
            'tenant' => $tenant, 'objeto' => $caso->getObjeto(), 'pessoa' => $pessoa, 'tipoVinculo' => $tipo,
        ])->_real();
    }

    private function trocarCobrada(CasoCobranca $caso, Pessoa $pessoa): void
    {
        $caso->setPessoaCobradaAtual($pessoa);
        $this->salvar();
    }

    /**
     * Ficha COMPLETA para o badge da §2.1: documento + estado civil + ao menos um endereço. Cada
     * parâmetro existe para ser anulado num teste só, isolando a perna do OU que se quer provar.
     */
    private function completarFicha(
        Tenant $tenant,
        Pessoa $pessoa,
        ?string $cpf = '123.456.789-00',
        ?EstadoCivil $estadoCivil = EstadoCivil::Solteiro,
        bool $comEndereco = true,
    ): void {
        $pessoa->setCpf($cpf);
        $pessoa->setCnpj(null);
        $pessoa->setEstadoCivil($estadoCivil);
        $this->salvar();

        if ($comEndereco) {
            $this->endereco($tenant, $pessoa, 'Rua das Acácias', '100', atual: true);
        }
    }

    /**
     * Os tokens de corrigir/excluir são RASPADOS DA PÁGINA, não gerados pelo `tokenCsrf()`. É de
     * propósito: assim o POST só passa se o template tiver emitido a MESMA intenção que o controller
     * valida (`editar_telefone_<id>` / `excluir_telefone_<id>`). Com o token gerado à parte, trocar a
     * intenção no Twig deixaria a suíte verde e a tela quebrada.
     */
    private function tokenDeEdicao(Crawler $crawler, int $telefoneId): string
    {
        return (string) $crawler->filter('#editarTelefone' . $telefoneId . ' input[name="_token"]')->attr('value');
    }

    /** O de excluir mora no botão que abre o modal compartilhado, não num `<input>`. */
    private function tokenDeExclusao(Crawler $crawler, int $telefoneId): string
    {
        return (string) $crawler
            ->filter('[data-acao="excluir-telefone"][data-url$="/telefones/' . $telefoneId . '/excluir"]')
            ->attr('data-token');
    }

    private function telefone(Tenant $tenant, Pessoa $pessoa, string $numero, bool $atual): PessoaTelefone
    {
        $telefone = new PessoaTelefone();
        $telefone->setTenant($tenant);
        $telefone->setPessoa($pessoa);
        $telefone->setNumero($numero);
        $telefone->setAtual($atual);
        $this->em()->persist($telefone);
        $this->salvar();

        return $telefone;
    }

    private function email(Tenant $tenant, Pessoa $pessoa, string $endereco, bool $atual): PessoaEmail
    {
        $email = new PessoaEmail();
        $email->setTenant($tenant);
        $email->setPessoa($pessoa);
        $email->setEmail($endereco);
        $email->setAtual($atual);
        $this->em()->persist($email);
        $this->salvar();

        return $email;
    }

    private function endereco(Tenant $tenant, Pessoa $pessoa, string $logradouro, string $numero, bool $atual): PessoaEndereco
    {
        $endereco = new PessoaEndereco();
        $endereco->setTenant($tenant);
        $endereco->setPessoa($pessoa);
        $endereco->setLogradouro($logradouro);
        $endereco->setNumero($numero);
        $endereco->setBairro('Centro');
        $endereco->setCidade('Rio de Janeiro');
        $endereco->setUf('RJ');
        $endereco->setCep('20000-000');
        $endereco->setAtual($atual);
        $this->em()->persist($endereco);
        $this->salvar();

        return $endereco;
    }

    /** Evento de qualificação já gravado, no formato que a listinha reidrata (payload + autor + marco). */
    private function qualificar(
        Tenant $tenant,
        CasoCobranca $caso,
        User $usuario,
        QualificacaoContato $qualificacao,
        \DateTimeImmutable $ocorridoEm,
    ): \App\Cobranca\Entity\EventoHistorico {
        return EventoHistoricoFactory::createOne([
            'tenant' => $tenant,
            'caso' => $caso,
            'tipo' => TipoEventoHistorico::QualificacaoContato,
            'descricao' => $qualificacao->label(),
            'dados' => ['qualificacao' => $qualificacao->value],
            'usuario' => $usuario,
            'ocorridoEm' => $ocorridoEm,
        ])->_real();
    }

    private function outroUsuario(): User
    {
        $user = new User();
        $user->setEmail('colega_' . uniqid() . '@test.com');
        $user->setFullName('Colega de Cobrança');
        $user->setRoles(['ROLE_USER']);
        $user->setIsActive(true);
        $user->setPassword('nao-usado');
        $this->em()->persist($user);
        $this->salvar();

        return $user;
    }

    private function casoDoObjeto(\App\Cobranca\Entity\ObjetoCobranca $objeto): CasoCobranca
    {
        $caso = $this->em()->getRepository(CasoCobranca::class)->findOneBy(['objeto' => $objeto]);
        self::assertNotNull($caso);

        return $caso;
    }

    private function em(): EntityManagerInterface
    {
        return static::getContainer()->get(EntityManagerInterface::class);
    }

    private function salvar(): void
    {
        $this->em()->flush();
    }
}
