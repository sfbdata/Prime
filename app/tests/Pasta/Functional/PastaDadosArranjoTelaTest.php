<?php

declare(strict_types=1);

namespace App\Tests\Pasta\Functional;

use App\Cliente\Entity\ClientePF;
use App\Controller\PastaController;
use App\Entity\Tarefa\Tarefa;
use App\Entity\Auth\User;
use App\Entity\Auth\UserTenant;
use App\Entity\Tenant\Tenant;
use App\Pasta\Entity\Pasta;
use App\Pasta\Entity\PastaDocumento;
use App\Pasta\Entity\PastaProcesso;
use App\Processo\Entity\Processo;
use App\Tests\Functional\JusPrimeWebTestCase;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\TestDox;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * ARRANJO do redesenho da tela de pasta (desenho aprovado em
 * docs/design/pasta-show/, "Pasta 1A.dc.html"): cabeçalho comum a todas as
 * abas + aba Dados em duas colunas (anotações no centro, trilho à direita).
 *
 * Todo assert usa combinador de FILHO DIRETO ou de descendência a partir do
 * bloco certo. É a única coisa que o PHPUnit consegue provar sobre layout:
 * `.ps-grade > .ps-trilho [data-trilho="prazos"]` distingue "está no trilho" de
 * "existe em algum lugar da página", que continuaria verdade com os cartões
 * empilhados na coluna errada. Borda, fonte, cor, a pílula que desliza e a
 * animação de troca de aba seguem INVISÍVEIS para o teste — isso é smoke do
 * dono, e suíte verde não diz nada sobre aparência.
 */
#[CoversClass(PastaController::class)]
final class PastaDadosArranjoTelaTest extends JusPrimeWebTestCase
{
    /** @return array{0: EntityManagerInterface, 1: User, 2: Tenant, 3: Pasta} */
    private function criarBase(): array
    {
        $container = static::getContainer();
        $em        = $container->get(EntityManagerInterface::class);
        $hasher    = $container->get(UserPasswordHasherInterface::class);

        $tenant = new Tenant();
        $tenant->setName('Tenant Arranjo ' . uniqid());
        $em->persist($tenant);

        $user = new User();
        $user->setEmail('arranjo_' . uniqid() . '@test.com');
        $user->setFullName('Admin Arranjo');
        $user->setRoles(['ROLE_SUPER_ADMIN']);
        $user->setIsActive(true);
        $user->setPassword($hasher->hashPassword($user, 'senha123'));
        $em->persist($user);
        $em->persist(new UserTenant($user, $tenant));

        $pasta = new Pasta();
        $pasta->setNup('NUP-ARR-' . strtoupper(uniqid()));
        $pasta->setTenant($tenant);
        $pasta->setCriadoPor($user);
        $pasta->setNomeAcao('Execução de Título Extrajudicial');
        $pasta->setResponsavel($user);
        $em->persist($pasta);

        return [$em, $user, $tenant, $pasta];
    }

    private function abrir(object $client, Pasta $pasta): object
    {
        $crawler = $client->request('GET', '/pasta/' . $pasta->getId());
        self::assertResponseIsSuccessful();

        return $crawler;
    }

    // =========================================================================
    // Cabeçalho
    // =========================================================================

    #[TestDox('o cabeçalho é UM cartão, comum a todas as abas, e não vive dentro de nenhum painel')]
    public function testCabecalhoForaDosPaineis(): void
    {
        $client                       = static::createClient();
        [$em, $user, $tenant, $pasta] = $this->criarBase();
        $em->flush();

        $this->logarComTenant($client, $user, $tenant);
        $crawler = $this->abrir($client, $pasta);

        self::assertCount(1, $crawler->filter('.ps-page > .ps-cabecalho'), 'o cabeçalho é filho DIRETO da página');
        self::assertCount(
            0,
            $crawler->filter('.tab-pane .ps-cabecalho'),
            'se o cabeçalho cair dentro de um painel, ele some ao trocar de aba'
        );
        self::assertCount(
            1,
            $crawler->filter('.ps-page > .ps-paineis'),
            'os painéis são irmãos do cabeçalho, não filhos dele'
        );
    }

    #[TestDox('as quatro ações do topo estão na ordem aprovada: Arquivar · Editar · Histórico · ⋮')]
    public function testOrdemDasAcoesDoTopo(): void
    {
        $client                       = static::createClient();
        [$em, $user, $tenant, $pasta] = $this->criarBase();
        $em->flush();

        $this->logarComTenant($client, $user, $tenant);
        $crawler = $this->abrir($client, $pasta);

        $botoes = $crawler->filter('.ps-cab-linha1 > .ps-cab-acoes > .ps-btn, .ps-cab-linha1 > .ps-cab-acoes > .ps-pop-wrap > .ps-btn');
        self::assertCount(4, $botoes, 'são quatro ações, todas filhas diretas da barra de ações');

        self::assertSame('Arquivar', trim($botoes->eq(0)->text()));
        self::assertSame('Editar', trim($botoes->eq(1)->text()));
        self::assertSame('Histórico', trim($botoes->eq(2)->text()));
        self::assertSame(
            'psHistorico',
            $botoes->eq(2)->attr('aria-controls'),
            'o botão Histórico abre o drawer, não uma seção da página'
        );
    }

    #[TestDox('o menu ⋮ tem só ações com back-end: Duplicar pasta ficou de fora por não existir')]
    public function testMenuDeAcoesSemItemInerte(): void
    {
        $client                       = static::createClient();
        [$em, $user, $tenant, $pasta] = $this->criarBase();
        $em->flush();

        $this->logarComTenant($client, $user, $tenant);
        $crawler = $this->abrir($client, $pasta);

        $menu = $crawler->filter('#psMenuAcoes');
        self::assertCount(1, $menu);
        self::assertCount(3, $menu->filter('.ps-pop-item'), 'três itens: vincular, trocar responsável, excluir');

        self::assertStringNotContainsString(
            'Duplicar',
            $menu->text(),
            'não existe rota de duplicação; item inerte ensina o usuário a desconfiar do menu'
        );

        // O "Excluir Pasta" vermelho do rodapé mudou de lugar — o rodapé sumiu.
        self::assertCount(
            1,
            $menu->filter('form[action$="/deletar"] .ps-pop-item--perigo'),
            'excluir pasta é o último item do menu, em vermelho, e continua sendo um POST'
        );
        self::assertCount(0, $crawler->filter('.card-footer'), 'o rodapé da tela deixou de existir');
    }

    #[TestDox('a faixa de dados fecha com Situação, na ordem aprovada')]
    public function testOrdemDaFaixaDeDados(): void
    {
        $client                       = static::createClient();
        [$em, $user, $tenant, $pasta] = $this->criarBase();
        $em->flush();

        $this->logarComTenant($client, $user, $tenant);
        $crawler = $this->abrir($client, $pasta);

        $campos = $crawler->filter('.ps-cabecalho > .ps-cab-dados > [data-campo]');
        self::assertSame(
            ['cliente-principal', 'responsavel', 'processo', 'movimentacao', 'situacao'],
            $campos->each(fn ($n) => $n->attr('data-campo')),
            'ordem aprovada: Cliente principal · Responsável · Processo vinculado · Última movimentação · Situação'
        );
    }

    #[TestDox('o título grande é a AÇÃO; o número da pasta virou o rótulo pequeno acima')]
    public function testTituloEhAAcaoENaoONumero(): void
    {
        $client                       = static::createClient();
        [$em, $user, $tenant, $pasta] = $this->criarBase();
        $em->flush();

        $this->logarComTenant($client, $user, $tenant);
        $crawler = $this->abrir($client, $pasta);

        $titulo = $crawler->filter('.ps-cabecalho > h1.ps-cab-titulo');
        self::assertCount(1, $titulo);

        /* MAIÚSCULAS de propósito. O desenho mostra o título em caixa mista,
           mas `Pasta::setNomeAcao()` grava `mb_strtoupper` — é regra do domínio,
           a mesma de `ClientePF::setNomeCompleto`. A tela reflete o dado; um
           `text-transform` no CSS deixaria bonito mentindo sobre o que está
           gravado, e erraria as preposições ("de", "da") na volta. */
        self::assertSame('EXECUÇÃO DE TÍTULO EXTRAJUDICIAL', trim($titulo->text()));

        self::assertSame(
            'PASTA ' . $pasta->getNup(),
            trim($crawler->filter('.ps-cab-identidade > .ps-cab-nup')->text()),
            'o NUP fica no rótulo pequeno da linha de identidade'
        );
    }

    #[TestDox('as seis abas são o controle segmentado, com o indicador como filho direto do trilho')]
    public function testAbasSegmentadas(): void
    {
        $client                       = static::createClient();
        [$em, $user, $tenant, $pasta] = $this->criarBase();
        $em->flush();

        $this->logarComTenant($client, $user, $tenant);
        $crawler = $this->abrir($client, $pasta);

        /* Escopo na página, não no documento: os modais de cliente (#tabsPF,
           #tabsPJ) têm nav-tabs próprios e continuam legítimos. */
        self::assertCount(
            0,
            $crawler->filter('.ps-page ul.nav-tabs'),
            'as abas sublinhadas saíram da tela da pasta'
        );
        self::assertCount(1, $crawler->filter('#pastaTabs.ps-abas[role="tablist"]'));
        self::assertCount(
            1,
            $crawler->filter('#pastaTabs > .ps-abas-ind'),
            'a pílula que desliza é filha DIRETA do trilho: é dele que o JS mede offsetLeft/offsetWidth'
        );
        // Eram seis no desenho aprovado; a sétima (Push Processual) foi pedida pelo dono em
        // 29/08/2026 e entrou NO FIM, para não mover a posição das outras.
        self::assertCount(7, $crawler->filter('#pastaTabs > button.ps-aba'), 'sete abas, todas filhas diretas');

        // Sem JS a pílula nunca é medida; a classe é a degradação graciosa.
        self::assertStringContainsString(
            'ps-abas--sem-js',
            (string) $crawler->filter('#pastaTabs')->attr('class'),
            'o HTML nasce marcado como "sem JS"; quem remove a marca é o pasta-show.js'
        );
    }

    #[TestDox('a contagem das abas vem do DADO, nunca de um literal')]
    public function testBadgeDasAbasVemDoDado(): void
    {
        $client                       = static::createClient();
        [$em, $user, $tenant, $pasta] = $this->criarBase();
        $em->flush();

        $this->logarComTenant($client, $user, $tenant);
        $crawler = $this->abrir($client, $pasta);

        // Pasta recém-criada: zero metas e zero documentos. Badge nenhum.
        self::assertCount(
            0,
            $crawler->filter('#pastaTabs .ps-aba-badge'),
            'sem meta e sem documento não pode aparecer contagem — foi bug corrigido duas vezes na revisão do desenho'
        );
    }

    // =========================================================================
    // Aba Dados
    // =========================================================================

    #[TestDox('a aba Dados é uma grade de duas colunas: anotações no centro, trilho à direita')]
    public function testGradeDaAbaDados(): void
    {
        $client                       = static::createClient();
        [$em, $user, $tenant, $pasta] = $this->criarBase();
        $em->flush();

        $this->logarComTenant($client, $user, $tenant);
        $crawler = $this->abrir($client, $pasta);

        self::assertCount(1, $crawler->filter('#dados > .ps-grade'), 'a grade é filha direta do painel da aba');
        self::assertCount(
            1,
            $crawler->filter('#dados > .ps-grade > .ps-anotacoes'),
            'as anotações são a coluna central — filha DIRETA da grade'
        );
        self::assertCount(
            1,
            $crawler->filter('#dados > .ps-grade > .ps-trilho'),
            'o trilho é a segunda coluna'
        );
    }

    #[TestDox('o trilho traz os três cartões na ordem aprovada')]
    public function testOrdemDosCartoesDoTrilho(): void
    {
        $client                       = static::createClient();
        [$em, $user, $tenant, $pasta] = $this->criarBase();
        $em->flush();

        $this->logarComTenant($client, $user, $tenant);
        $crawler = $this->abrir($client, $pasta);

        $cartoes = $crawler->filter('#dados > .ps-grade > .ps-trilho > [data-trilho]');
        self::assertSame(
            ['prazos', 'clientes', 'documentos'],
            $cartoes->each(fn ($n) => $n->attr('data-trilho')),
            'ordem aprovada (revisão 28/08): Próximos prazos · Clientes · Documentos'
        );
    }

    #[TestDox('o histórico automático saiu da aba Dados e virou drawer, fora de .ps-page')]
    public function testHistoricoSaiuDaPaginaEViroudrawer(): void
    {
        $client                       = static::createClient();
        [$em, $user, $tenant, $pasta] = $this->criarBase();
        $em->flush();

        $this->logarComTenant($client, $user, $tenant);
        $crawler = $this->abrir($client, $pasta);

        /* Esta é a mudança estrutural que o redesenho existe para fazer: o
           histórico ocupava mais altura vertical que toda a informação do caso. */
        self::assertCount(0, $crawler->filter('#pasta-timeline-card'), 'o cartão "Relatórios" saiu da página');
        self::assertCount(0, $crawler->filter('#dados #psHistorico'), 'o drawer não mora dentro da aba');
        self::assertCount(1, $crawler->filter('#psHistorico.ps-drawer'), 'o drawer existe, fora do fluxo');
        self::assertCount(1, $crawler->filter('#psHistoricoOverlay'));
        self::assertCount(
            0,
            $crawler->filter('.ps-page #psHistorico'),
            'drawer e overlay são fixos e cobrem a tela: ficam FORA de .ps-page'
        );
    }

    #[TestDox('o compositor de anotações continua sendo o mesmo formulário que o JS conhece')]
    public function testContratoDoCompositor(): void
    {
        $client                       = static::createClient();
        [$em, $user, $tenant, $pasta] = $this->criarBase();
        $em->flush();

        $this->logarComTenant($client, $user, $tenant);
        $crawler = $this->abrir($client, $pasta);

        /* Os id abaixo são contrato com o JS de enviar/editar/excluir do
           show.html.twig. Renomear qualquer um deles quebra o envio EM SILÊNCIO:
           nenhuma exceção, nenhum erro no log, só um botão que não faz nada. */
        foreach (['formTimelineMensagem', 'timelineConteudo', 'btnEnviarMensagem', 'timelineMensagemErro', 'timelineList', 'timeline-count'] as $id) {
            self::assertCount(
                1,
                $crawler->filter('#dados #' . $id),
                "#{$id} é contrato com o JS de anotações e tem de continuar dentro da aba Dados"
            );
        }
    }

    #[TestDox('os clientes ficam no cartão do trilho, e não sobrou cópia em lugar nenhum')]
    public function testClientesMoramNoTrilho(): void
    {
        $client                       = static::createClient();
        [$em, $user, $tenant, $pasta] = $this->criarBase();

        $cliente = new ClientePF();
        $cliente->setEmail('arr' . uniqid() . '@test.com');
        $cliente->setCep('80000-000');
        $cliente->setEndereco('Rua Um, 1');
        $cliente->setCidade('Curitiba');
        $cliente->setEstado('PR');
        $cliente->setTenant($tenant);
        $cliente->setNomeCompleto('Joao Batista Moreira');
        $cliente->setCpf('12345678901');
        $cliente->setRg('12.345.678-9');
        $cliente->setRgOrgaoExpedidor('SSP');
        $em->persist($cliente);
        $pasta->addCliente($cliente);
        $em->flush();

        $this->logarComTenant($client, $user, $tenant);
        $crawler = $this->abrir($client, $pasta);

        self::assertCount(
            1,
            $crawler->filter('.ps-trilho > [data-trilho="clientes"] #clientesList'),
            'a lista de clientes é do cartão Clientes do trilho'
        );
        self::assertCount(1, $crawler->filter('#clientesList'), 'existe UMA lista, não duas');
        self::assertCount(
            1,
            $crawler->filter('[data-trilho="clientes"] .ps-card-cab [data-bs-target="#modalAdicionarCliente"]'),
            'o botão "+ Vincular" fica no cabeçalho do cartão'
        );
        self::assertCount(
            1,
            $crawler->filter('#clientesList .cliente-principal .ps-selo-principal'),
            'o cliente principal ganha o selo âmbar do desenho'
        );
    }

    #[TestDox('quem é o principal se vê SEM passar o mouse: a estrela cheia e o selo são estruturais')]
    public function testSinalDoPrincipalNaoDependeDeHover(): void
    {
        $client                       = static::createClient();
        [$em, $user, $tenant, $pasta] = $this->criarBase();

        foreach (['Joao Batista Moreira da Silva Santos Junior', 'Maria Aparecida'] as $i => $nome) {
            $cliente = new ClientePF();
            $cliente->setEmail('sin' . $i . uniqid() . '@test.com');
            $cliente->setCep('80000-000');
            $cliente->setEndereco('Rua Um, 1');
            $cliente->setCidade('Curitiba');
            $cliente->setEstado('PR');
            $cliente->setTenant($tenant);
            $cliente->setNomeCompleto($nome);
            $cliente->setCpf('1234567890' . $i);
            $cliente->setRg('12.345.678-9');
            $cliente->setRgOrgaoExpedidor('SSP');
            $em->persist($cliente);
            $pasta->addCliente($cliente);
        }
        $em->flush();

        $this->logarComTenant($client, $user, $tenant);
        $crawler = $this->abrir($client, $pasta);

        /* A estrela CHEIA existe só na linha do principal, e é a classe abaixo
           que o CSS usa para mantê-la visível no repouso enquanto as outras
           ações esperam o hover. Se ela migrar para dentro de outro nó, ou o
           nome da classe mudar, o sinal volta a depender do mouse — e o teste
           não veria, porque opacidade é invisível para o PHPUnit. Travar a
           ESTRUTURA é o que dá para provar aqui; o resto é smoke. */
        self::assertCount(
            1,
            $crawler->filter('#clientesList .cliente-principal .js-cliente-acoes > .js-cliente-principal-estrela'),
            'a estrela cheia é filha DIRETA da caixa de ações da linha do principal'
        );
        self::assertCount(
            1,
            $crawler->filter('#clientesList .js-cliente-principal-estrela'),
            'e existe UMA só: nas outras linhas o controle é o form da estrela vazia'
        );
        self::assertCount(
            1,
            $crawler->filter('#clientesOutros form.js-ajax-cliente-principal'),
            'os clientes ocultos trazem o form de tornar principal, que é o que aparece no hover'
        );
    }

    #[TestDox('nome comprido trunca dentro do cartão em vez de empurrar a linha para fora')]
    public function testNomeCompridoNaoVazaDoCartao(): void
    {
        $client                       = static::createClient();
        [$em, $user, $tenant, $pasta] = $this->criarBase();

        $cliente = new ClientePF();
        $cliente->setEmail('long' . uniqid() . '@test.com');
        $cliente->setCep('80000-000');
        $cliente->setEndereco('Rua Um, 1');
        $cliente->setCidade('Curitiba');
        $cliente->setEstado('PR');
        $cliente->setTenant($tenant);
        $cliente->setNomeCompleto('Joao Batista Moreira da Silva Santos Junior Neto');
        $cliente->setCpf('12345678901');
        $cliente->setRg('12.345.678-9');
        $cliente->setRgOrgaoExpedidor('SSP');
        $em->persist($cliente);
        $pasta->addCliente($cliente);
        $em->flush();

        $this->logarComTenant($client, $user, $tenant);
        $crawler = $this->abrir($client, $pasta);

        /* O nome tem de estar num <span> PRÓPRIO. Solto ao lado do selo ele vira
           item anônimo de flex, que não encolhe: o `text-overflow: ellipsis`
           nunca dispara e quem cresce é a linha, que escapa do cartão de 356px.
           O selo fica FORA do span truncável — senão "Principal" viraria "…". */
        $base = '#clientesList .cliente-principal .cliente-nome';

        self::assertCount(1, $crawler->filter($base . ' > .cliente-nome-texto'), 'o texto do nome tem span próprio');
        self::assertSame(
            'JOAO BATISTA MOREIRA DA SILVA SANTOS JUNIOR NETO',
            trim($crawler->filter($base . ' > .cliente-nome-texto')->text())
        );
        self::assertCount(
            1,
            $crawler->filter($base . ' > .ps-selo-principal'),
            'o selo é IRMÃO do span do nome, não filho — dentro dele viraria reticência'
        );
        self::assertCount(
            0,
            $crawler->filter($base . ' > .cliente-nome-texto .ps-selo-principal'),
            'e não pode ter caído para dentro do span que trunca'
        );
    }

    #[TestDox('o cartão financeiro NÃO está mais no trilho: o financeiro tem aba própria')]
    public function testTrilhoNaoTemMaisCartaoFinanceiro(): void
    {
        $client                       = static::createClient();
        [$em, $user, $tenant, $pasta] = $this->criarBase();
        $em->flush();

        $this->logarComTenant($client, $user, $tenant);
        $crawler = $this->abrir($client, $pasta);

        /* Revisão de 28/08 do desenho: o cartão saiu do trilho. Repetir valor da
           causa e média aqui criava DOIS lugares para o mesmo número — e quem
           edita o valor edita num deles só. */
        self::assertCount(
            0,
            $crawler->filter('.ps-trilho > [data-trilho="financeiro"]'),
            'o cartão "Financeiro do caso" saiu do trilho da aba Dados'
        );

        /* E não voltou disfarçado: os rótulos do cartão não podem sobrar no
           trilho depois da remoção. O escopo é o trilho, não a página — o valor
           da causa segue legítimo na aba Financeiro. */
        $trilho = $crawler->filter('#dados > .ps-grade > .ps-trilho')->text();
        foreach (['Financeiro do caso', 'Valor da causa'] as $rotuloQueSaiu) {
            self::assertStringNotContainsString(
                $rotuloQueSaiu,
                $trilho,
                "\"{$rotuloQueSaiu}\" pertence à aba Financeiro, não ao trilho de Dados"
            );
        }
    }

    // =========================================================================
    // Dado real na tela
    // =========================================================================

    #[TestDox('o número do processo aparece MASCARADO, e igual no cabeçalho e na aba Processo')]
    public function testNumeroDoProcessoNaoDivergeEntreCabecalhoEAba(): void
    {
        $client                       = static::createClient();
        [$em, $user, $tenant, $pasta] = $this->criarBase();

        $processo = new Processo();
        $processo->setNumeroProcesso('10593167220224013400');
        $processo->setOrgaoJulgador('1ª Vara Federal');
        $processo->setSiglaTribunal('TJDFT');
        $processo->setClasseProcessual('Cumprimento de sentença');
        $processo->setAssuntoProcessual('Obrigação de fazer');
        $processo->setTenant($tenant);
        $em->persist($processo);

        $vinculo = new PastaProcesso($pasta, $processo);
        $vinculo->setPrincipal(true);
        $em->persist($vinculo);
        $pasta->getPastaProcessos()->add($vinculo);
        $em->flush();

        $this->logarComTenant($client, $user, $tenant);
        $crawler = $this->abrir($client, $pasta);

        $mascarado = '1059316-72.2022.4.01.3400';

        $noCabecalho = $crawler->filter('.ps-cab-dados [data-campo="processo"] a');
        self::assertCount(1, $noCabecalho);
        self::assertSame($mascarado, trim($noCabecalho->text()), 'o cabeçalho mostra o número formatado, não os 20 dígitos colados');

        /* O handoff é explícito: "Header e aba Processo leem a mesma fonte de
           dados — não podem divergir". Formatar num lugar só produziria dois
           números com cara diferente para o MESMO processo. */
        $naAba = $crawler->filter('#processo a[href="/processos/' . $processo->getId() . '"]')->reduce(
            fn ($n) => trim($n->text()) !== ''
        );
        self::assertGreaterThan(0, $naAba->count(), 'a aba Processo lista o processo');
        self::assertSame($mascarado, trim($naAba->first()->text()), 'a aba Processo mostra o MESMO número formatado');

        // O botão de copiar continua entregando os dígitos crus: é o que se cola
        // no sistema do tribunal.
        self::assertSame(
            '10593167220224013400',
            $crawler->filter('.ps-cab-dados [data-campo="processo"] .copy-btn')->attr('data-copy'),
            'copiar entrega o número cru, não a máscara'
        );

        /* Classe · tribunal embaixo do número, como no desenho — mas em
           MAIÚSCULAS, porque `Processo::setClasseProcessual()` grava
           `mb_strtoupper`. Normalizar esse rótulo para caixa mista está no
           handoff como parte da ABA PROCESSO, que é fatia própria: fazer aqui
           só no cabeçalho recriaria a divergência que este teste existe para
           impedir. */
        self::assertSame(
            'CUMPRIMENTO DE SENTENÇA · TJDFT',
            trim($crawler->filter('.ps-cab-dados [data-campo="processo"] .ps-dado-sub')->text())
        );
    }

    #[TestDox('o cartão de prazos traz as metas abertas mais próximas, com o selo colorido certo')]
    public function testCartaoDeProximosPrazos(): void
    {
        $client                       = static::createClient();
        [$em, $user, $tenant, $pasta] = $this->criarBase();

        foreach ([['Protocolar contestação', '+1 day'], ['Juntar documentos', '+20 days']] as [$titulo, $prazo]) {
            $meta = new Tarefa();
            $meta->setTitulo($titulo);
            $meta->setDescricao('...');
            $meta->setPrazo(new \DateTimeImmutable($prazo));
            $meta->setPasta($pasta);
            $meta->setTenant($tenant);
            $meta->setCriadoPor($user);
            $meta->addResponsavel($user);
            $em->persist($meta);
            // Lado inverso: a Pasta já está na identity map, e a coleção não se
            // atualiza sozinha ao persistir o lado dono.
            $pasta->getTarefas()->add($meta);
        }
        $em->flush();

        $this->logarComTenant($client, $user, $tenant);
        $crawler = $this->abrir($client, $pasta);

        $linhas = $crawler->filter('[data-trilho="prazos"] .ps-linha');
        self::assertCount(2, $linhas);

        // Ordem: a mais próxima primeiro.
        self::assertStringContainsString('Protocolar contestação', $linhas->eq(0)->text());
        self::assertSame('1 dia', trim($linhas->eq(0)->filter('.ps-selo')->text()));
        self::assertStringContainsString(
            'ps-selo--urgente',
            (string) $linhas->eq(0)->filter('.ps-selo')->attr('class'),
            'até 2 dias é vermelho'
        );
        self::assertStringContainsString(
            'ps-selo--tranquilo',
            (string) $linhas->eq(1)->filter('.ps-selo')->attr('class'),
            'acima de 8 dias é cinza — prazo distante não é "bom", só não é urgente'
        );

        // Com metas, a aba Metas passa a exibir a contagem.
        self::assertSame('2', trim($crawler->filter('#tarefas-tab .ps-aba-badge')->text()));
    }

    #[TestDox('pasta sem cliente cadastrado mostra o texto legado — o estado de 1.072 das 1.073 pastas em produção')]
    public function testTextoLegadoNoTrilho(): void
    {
        $client                       = static::createClient();
        [$em, $user, $tenant, $pasta] = $this->criarBase();
        $pasta->setNomeCliente('MARIA DAS GRACAS');
        $em->flush();

        $this->logarComTenant($client, $user, $tenant);
        $crawler = $this->abrir($client, $pasta);

        // No cabeçalho, como "Cliente principal".
        $cab = $crawler->filter('.ps-cab-dados [data-campo="cliente-principal"]');
        self::assertStringContainsString('MARIA DAS GRACAS', $cab->text());
        self::assertStringContainsString('não vinculado', $cab->text(), 'e dizendo que não é cadastro');

        // E no cartão do trilho, sem inventar documento.
        self::assertCount(
            0,
            $crawler->filter('[data-trilho="clientes"] .cliente-doc-rotulo'),
            'sem cadastro não há CPF; imprimir o rótulo vazio seria inventar dado'
        );
    }

    #[TestDox('o documento do trilho abre o MESMO pré-visualizador da aba Documentos')]
    public function testDocumentoDoTrilhoAbreOPreVisualizador(): void
    {
        $client                       = static::createClient();
        [$em, $user, $tenant, $pasta] = $this->criarBase();

        $doc = new PastaDocumento();
        $doc->setTitulo('Procuração');
        $doc->setCategoria(PastaDocumento::CATEGORIA_DEMAIS);
        $doc->setCaminhoArquivo('uploads/fake/procuracao.pdf');
        $doc->setNomeOriginal('procuracao.pdf');
        $doc->setMimeType('application/pdf');
        $doc->setTamanhoBytes(2048);
        $doc->setPasta($pasta);
        $doc->setTenant($tenant);
        $em->persist($doc);
        $pasta->addDocumento($doc);
        $em->flush();

        $this->logarComTenant($client, $user, $tenant);
        $crawler = $this->abrir($client, $pasta);

        $linha = $crawler->filter('[data-trilho="documentos"] a.ps-doc');
        self::assertCount(1, $linha, 'a linha inteira é o alvo do clique');

        /* Mesmo gesto e MESMO modal da aba Documentos: dois caminhos para o
           mesmo arquivo não podem se comportar diferente. */
        self::assertSame('#previewDocModal', $linha->attr('data-bs-target'));
        self::assertSame('modal', $linha->attr('data-bs-toggle'));
        self::assertSame('/pasta/documento/' . $doc->getId() . '/visualizar', $linha->attr('data-url'));
        self::assertSame('procuracao.pdf', $linha->attr('data-nome'));
        self::assertSame('application/pdf', $linha->attr('data-mime'));

        // Sem JS, o href leva ao arquivo em outra aba.
        self::assertSame('/pasta/documento/' . $doc->getId() . '/visualizar', $linha->attr('href'));
        self::assertSame('_blank', $linha->attr('target'));
    }
}
