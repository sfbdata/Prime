<?php

declare(strict_types=1);

namespace App\Tests\Cobranca\Functional;

use App\Cobranca\Controller\ObjetoController;
use App\Cobranca\Entity\CasoCobranca;
use App\Cobranca\Entity\EventoHistorico;
use App\Cobranca\Enum\TipoEventoHistorico;
use App\Cobranca\Enum\TipoVinculo;
use App\Entity\Auth\User;
use App\Entity\Tenant\Tenant;
use App\Tests\Factory\Cobranca\ObrigacaoFactory;
use App\Tests\Factory\Cobranca\PessoaFactory;
use App\Tests\Factory\Cobranca\VinculoPessoaObjetoFactory;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\TestDox;

/**
 * Reorganização rápida da página de cobrança (SPEC "cobranca-ux-rapida-1-dia", 2026-07-26).
 *
 * A entrega é de UX: nenhuma regra de negócio mudou. O que estes testes travam é justamente o que um
 * refactor de tela costuma quebrar em silêncio — o campo que perde a barra de formatação, o botão que
 * volta a excluir sem confirmar, a aba que nasce mostrando número que o sistema não calcula, e o texto
 * que a validação apaga.
 *
 * O que NÃO está aqui, de propósito: contagem de dinheiro. Os valores continuam provados pelos testes
 * do domínio — se esta entrega tivesse mexido neles, seria pelo motivo errado.
 */
#[CoversClass(ObjetoController::class)]
final class ObjetoShowUxReorganizacaoTest extends CobrancaWebTestCase
{
    #[TestDox('SPEC §7: a aba Cobrança abre por padrão e o editor de anotação é o primeiro elemento útil')]
    public function testAbaCobrancaAbreComOEditorDeAnotacao(): void
    {
        $client = static::createClient();
        [, $tenant] = $this->criarAdminLogado($client);
        [, $caso] = $this->semearGrafo($tenant);

        $crawler = $client->request('GET', '/cobrancas/objetos/' . $caso->getObjeto()->getId());

        self::assertResponseIsSuccessful();
        // A aba que nasce ativa é a Cobrança.
        self::assertSelectorExists('#objetoTabs .nav-link.active[data-bs-target="#tab-cobranca"]');
        self::assertSelectorExists('#tab-cobranca.show.active');

        // E o editor vem ANTES da lista — é o primeiro elemento útil, não um campo no rodapé.
        self::assertSelectorExists('#tab-cobranca .cob-anotacao-nova textarea[data-editor-rico]');
        self::assertSelectorExists('#tab-cobranca .cob-anotacao-nova button[type="submit"]');

        // Ancorado no ÍCONE do cabeçalho da lista, não no rótulo: o texto pode ser reescrito sem que a
        // ordem dos blocos mude, e um teste que cai por renomear título não protege invariante nenhuma.
        $painel = $crawler->filter('#tab-cobranca')->html();
        $posLista = strpos($painel, 'bi-chat-left-text');
        self::assertIsInt($posLista, 'o cabeçalho da lista de anotações sumiu do painel');
        self::assertLessThan(
            $posLista,
            strpos($painel, 'cob-anotacao-nova'),
            'o editor precisa vir antes da lista de anotações',
        );
    }

    #[TestDox('SPEC §11: os campos narrativos acessíveis da página usam o editor rico; os estruturados, não')]
    public function testCamposNarrativosUsamEditorRico(): void
    {
        $client = static::createClient();
        [, $tenant] = $this->criarAdminLogado($client);
        [, $caso] = $this->semearGrafo($tenant);

        $crawler = $client->request('GET', '/cobrancas/objetos/' . $caso->getObjeto()->getId());
        self::assertResponseIsSuccessful();

        // Narrativos cobertos por esta entrega: anotação, ao criar e ao corrigir.
        self::assertSelectorExists('#tab-cobranca textarea[name="registrar_anotacao[texto]"][data-editor-rico]');
        self::assertSelectorExists('#modalEditarAnotacao textarea[data-editor-rico]');

        // Estruturados seguem simples — a SPEC proíbe editor rico em canal/data/resultado.
        self::assertCount(0, $crawler->filter('#modalRegistrarTentativa select[data-editor-rico]'), 'seletor não leva editor rico');
        self::assertCount(0, $crawler->filter('#modalRegistrarTentativa input[data-editor-rico]'), 'data/hora não leva editor rico');

        // O relato do contato ficou DELIBERADAMENTE de fora (ver RegistrarTentativaCobrancaType): sem
        // sanitização na escrita, ligar a barra aqui guardaria HTML cru e vazaria `<p>` literal para a
        // Central de Acompanhamento, que está em produção. Este assert existe para que religá-lo seja
        // uma decisão consciente, e não um efeito colateral de alguém "completando" a SPEC.
        self::assertCount(
            0,
            $crawler->filter('#modalRegistrarTentativa textarea[data-editor-rico]'),
            'relato do contato só pode ganhar editor rico junto com sanitização na escrita',
        );

        // A barra do editor é a do sistema — sem biblioteca nova nesta entrega.
        $html = (string) $client->getResponse()->getContent();
        self::assertStringContainsString('js/editor-rico.js', $html);
        self::assertStringContainsString('js/quill/quill.js', $html);
    }

    #[TestDox('SPEC §8: TODAS as anotações aparecem, num bloco rolável — nenhuma fica escondida')]
    public function testTodasAsAnotacoesAparecemNoBlocoRolavel(): void
    {
        $client = static::createClient();
        [$usuario, $tenant] = $this->criarAdminLogado($client);
        [, $caso] = $this->semearGrafo($tenant);

        // 12 > o antigo corte de 10: se alguém reintroduzir um `slice`, este teste cai. A 1ª e a 12ª
        // são nomeadas porque o que precisa valer é que NENHUMA ponta suma — nem a mais nova (topo da
        // lista), nem a mais velha (só alcançável rolando).
        $this->semearAnotacao($caso, $tenant, $usuario, 'a mais antiga de todas');
        for ($i = 2; $i <= 11; ++$i) {
            $this->semearAnotacao($caso, $tenant, $usuario, 'anotação de enchimento ' . $i);
        }
        $this->semearAnotacao($caso, $tenant, $usuario, 'a mais recente de todas');

        $crawler = $client->request('GET', '/cobrancas/objetos/' . $caso->getObjeto()->getId());
        self::assertResponseIsSuccessful();

        self::assertCount(12, $crawler->filter('#tab-cobranca .cob-anotacoes .cob-anotacao'), 'as 12 anotações têm de estar no HTML');

        $lista = $crawler->filter('#tab-cobranca .cob-anotacoes');
        self::assertStringContainsString('a mais recente de todas', $lista->text());
        self::assertStringContainsString('a mais antiga de todas', $lista->text(), 'a mais velha não pode ser cortada');

        // A rolagem é CSS, mas a região precisa ser alcançável pelo teclado — isso o HTML garante.
        self::assertSame('0', $lista->attr('tabindex'), 'bloco rolável sem foco por teclado deixa o conteúdo inacessível');

        // E o corte antigo não pode voltar disfarçado de aviso.
        self::assertStringNotContainsString('mais recentes de', $crawler->filter('#tab-cobranca')->text());
    }

    #[TestDox('SPEC §10: excluir anotação exige confirmação e leva a URL e o CSRF daquela linha')]
    public function testExclusaoDeAnotacaoPassaPorConfirmacao(): void
    {
        $client = static::createClient();
        [$usuario, $tenant] = $this->criarAdminLogado($client);
        [, $caso] = $this->semearGrafo($tenant);
        $evento = $this->semearAnotacao($caso, $tenant, $usuario, 'anotação a confirmar');
        $id = (int) $evento->getId();

        $crawler = $client->request('GET', '/cobrancas/objetos/' . $caso->getObjeto()->getId());
        self::assertResponseIsSuccessful();

        // Existe o modal de confirmação e ele identifica a anotação (área do trecho).
        self::assertSelectorExists('#modalExcluirAnotacao');
        self::assertSelectorExists('#modalExcluirAnotacao #trechoExcluirAnotacao');
        self::assertSelectorExists('#formExcluirAnotacao input[name="_token"]');

        // O clique único não exclui: o botão da linha só ABRE o modal (não é submit de form próprio).
        $botao = $crawler->filter('.js-excluir-anotacao[data-url*="/anotacoes/' . $id . '/excluir"]')->eq(0);
        self::assertCount(1, $botao);
        self::assertSame('button', $botao->attr('type'), 'a lixeira não pode ser submit direto');
        self::assertNotEmpty($botao->attr('data-token'), 'o CSRF daquela anotação viaja no botão');
        self::assertNotEmpty($botao->attr('data-trecho'), 'a confirmação precisa dizer QUAL anotação some');

        // E o CSRF continua obrigatório: token vazio não exclui.
        $form = $crawler->filter('#formExcluirAnotacao')->form();
        $form->getNode()->setAttribute('action', (string) $botao->attr('data-url'));
        $form['_token'] = '';
        $client->submit($form);
        self::assertNotNull($this->buscarEvento($id), 'sem CSRF válido a anotação não pode sumir');
    }

    #[TestDox('SPEC §10: o modal único não permite cruzar o CSRF de uma anotação com a URL de outra')]
    public function testTokenDeUmaAnotacaoNaoExcluiOutra(): void
    {
        $client = static::createClient();
        [$usuario, $tenant] = $this->criarAdminLogado($client);
        [, $caso] = $this->semearGrafo($tenant);
        $alvo = $this->semearAnotacao($caso, $tenant, $usuario, 'esta NÃO pode sumir');
        $outra = $this->semearAnotacao($caso, $tenant, $usuario, 'a que emprestou o token');
        $idAlvo = (int) $alvo->getId();

        $crawler = $client->request('GET', '/cobrancas/objetos/' . $caso->getObjeto()->getId());

        // O modal passou a ser UM só, com action e token injetados por JS. Isso torna possível montar
        // um POST com o token de uma anotação e a URL de outra — cenário que não existia quando cada
        // linha tinha o próprio form. O servidor tem de recusar.
        $tokenDaOutra = (string) $crawler
            ->filter('.js-excluir-anotacao[data-url*="/anotacoes/' . $outra->getId() . '/excluir"]')
            ->eq(0)->attr('data-token');

        $form = $crawler->filter('#formExcluirAnotacao')->form();
        $form->getNode()->setAttribute('action', '/cobrancas/casos/anotacoes/' . $idAlvo . '/excluir');
        $form['_token'] = $tokenDaOutra;
        $client->submit($form);

        self::assertNotNull($this->buscarEvento($idAlvo), 'token de outra anotação não pode excluir esta');
    }

    #[TestDox('SPEC §7.3: erro de validação devolve o texto digitado em vez de apagá-lo')]
    public function testErroDeValidacaoNaoApagaOTextoDigitado(): void
    {
        $client = static::createClient();
        [, $tenant] = $this->criarAdminLogado($client);
        [, $caso] = $this->semearGrafo($tenant);

        $crawler = $client->request('GET', '/cobrancas/objetos/' . $caso->getObjeto()->getId());
        $form = $crawler->filter('form[action*="/anotacoes"]')->form();

        // Acima do limite de 5000 do DTO → inválido, e é justamente quando apagar dói mais.
        $textoLongo = str_repeat('a', 5200);
        $form['registrar_anotacao[texto]'] = $textoLongo;
        $client->submit($form);

        self::assertResponseRedirects();
        $depois = $client->followRedirect();

        $textarea = $depois->filter('textarea[name="registrar_anotacao[texto]"]');
        self::assertCount(1, $textarea);
        self::assertStringContainsString(
            substr($textoLongo, 0, 200),
            $textarea->text(),
            'o texto recusado tem de voltar no campo — a pessoa não pode perder o que escreveu',
        );
    }

    #[TestDox('SPEC §14.2: a aba Honorários mostra a configuração vigente sem inventar métrica')]
    public function testAbaHonorariosSoReorganizaOQueJaExiste(): void
    {
        $client = static::createClient();
        [, $tenant] = $this->criarAdminLogado($client);
        [, $caso] = $this->semearGrafo($tenant);
        // Sem obrigação a composição da dívida nem renderiza linha — e é exatamente a linha que precisa
        // continuar mostrando a coluna de honorários.
        ObrigacaoFactory::createOne([
            'tenant' => $tenant, 'caso' => $caso,
            'descricao' => 'Cota condominial', 'valorOriginal' => 120000, 'encargosReconhecidos' => 0,
        ]);

        $client->request('GET', '/cobrancas/objetos/' . $caso->getObjeto()->getId());
        self::assertResponseIsSuccessful();

        self::assertSelectorExists('#tab-honorarios .cob-hon-config');
        self::assertSelectorTextContains('#tab-honorarios', 'Forma configurada');
        self::assertSelectorTextContains('#tab-honorarios', 'Base de cálculo');
        self::assertSelectorTextContains('#tab-honorarios', 'Por obrigação');
        self::assertSelectorTextContains('#tab-honorarios', 'Por recebimento');

        // A coluna de honorários da dívida NÃO foi substituída — a SPEC exige as duas coisas.
        self::assertSelectorExists('#tab-divida #secao-divida .col-honorarios');

        // "Editar configuração de encargos" continua acessível no contexto financeiro, sem virar aba.
        self::assertSelectorExists('#tab-divida [data-bs-target="#modalConfigEncargosObjeto"]');
        self::assertSelectorNotExists('#objetoTabs [data-bs-target="#tab-encargos"]');
    }

    #[TestDox('SPEC §11.2: anotação com script é exibida sanitizada, sem execução')]
    public function testAnotacaoComScriptSaiSanitizadaNaTela(): void
    {
        $client = static::createClient();
        [$usuario, $tenant] = $this->criarAdminLogado($client);
        [, $caso] = $this->semearGrafo($tenant);
        $this->semearAnotacao(
            $caso,
            $tenant,
            $usuario,
            '<p>combinado<script>alert(1)</script></p><a href="javascript:alert(2)">x</a>',
        );

        $client->request('GET', '/cobrancas/objetos/' . $caso->getObjeto()->getId());
        self::assertResponseIsSuccessful();

        $html = (string) $client->getResponse()->getContent();
        self::assertStringContainsString('combinado', $html, 'o texto legítimo continua visível');
        self::assertStringNotContainsString('<script>alert(1)</script>', $html, 'script não pode chegar ao navegador');
        self::assertStringNotContainsStringIgnoringCase('javascript:alert(2)', $html, 'href javascript: não pode chegar ao navegador');
    }

    #[TestDox('Aba Responsáveis: atual no topo e expandido, demais em accordion, com as ações consolidadas')]
    public function testAbaResponsaveisConsolidaPessoasEAcoes(): void
    {
        $client = static::createClient();
        [, $tenant] = $this->criarAdminLogado($client);
        [, $caso] = $this->semearGrafo($tenant);
        $objeto = $caso->getObjeto();

        // A pessoa cobrada atual…
        VinculoPessoaObjetoFactory::createOne([
            'tenant' => $tenant, 'objeto' => $objeto,
            'pessoa' => $caso->getPessoaCobradaAtual(), 'tipoVinculo' => TipoVinculo::Proprietario,
        ]);
        // …e uma segunda pessoa, que é quem prova o accordion e o "Definir como atual".
        $outra = PessoaFactory::createOne(['tenant' => $tenant, 'nome' => 'Maria Fiadora']);
        VinculoPessoaObjetoFactory::createOne([
            'tenant' => $tenant, 'objeto' => $objeto,
            'pessoa' => $outra, 'tipoVinculo' => TipoVinculo::Representante,
        ]);

        $crawler = $client->request('GET', '/cobrancas/objetos/' . $objeto->getId());
        self::assertResponseIsSuccessful();

        // Responsável atual: um só, no topo, fora do accordion (sempre visível).
        self::assertCount(1, $crawler->filter('#tab-responsaveis .cob-resp-atual'), 'exatamente um responsável atual, expandido');
        self::assertSelectorTextContains('#tab-responsaveis .cob-resp-atual', 'Responsável atual');

        // Demais: dentro do accordion, recolhidas.
        $itens = $crawler->filter('#tab-responsaveis .cob-resp-accordion .accordion-item');
        self::assertCount(1, $itens, 'a segunda pessoa entra no accordion');
        self::assertStringContainsString('Maria Fiadora', $itens->text());
        self::assertCount(1, $crawler->filter('#tab-responsaveis .accordion-collapse.collapse:not(.show)'), 'as demais nascem recolhidas');

        // Ações consolidadas na aba, reusando os modais existentes — sem rota nova.
        self::assertSelectorExists('#tab-responsaveis [data-bs-target="#modalVincularPessoaObjeto"]', 'Adicionar pessoa: vincular existente');
        self::assertSelectorExists('#tab-responsaveis [data-bs-target="#modalFichaPessoa"]', 'Adicionar pessoa: cadastrar nova');
        self::assertSelectorExists('#tab-responsaveis [data-bs-target="#modalEncerrarVinculo"]', 'Encerrar vínculo segue disponível');

        // "Definir como atual": um clique abre o modal de troca JÁ com a pessoa escolhida. O motivo
        // continua obrigatório (regra do domínio), por isso o fluxo passa pelo modal e não troca direto.
        $definir = $crawler->filter('#tab-responsaveis .js-definir-cobrada');
        self::assertCount(1, $definir, 'a pessoa não-atual ganha "Definir como atual"');
        self::assertSame('#modalAlterarPessoa', $definir->attr('data-bs-target'));
        self::assertSame((string) $outra->getId(), $definir->attr('data-pessoa-id'), 'o botão carrega a pessoa que será definida');
    }

    #[TestDox('Objeto com um vínculo só continua podendo trocar o responsável e encerrar o vínculo')]
    public function testFuncoesDoCardRemovidoSobrevivemComUmVinculoSo(): void
    {
        $client = static::createClient();
        [, $tenant] = $this->criarAdminLogado($client);
        [, $caso] = $this->semearGrafo($tenant);

        // O caso mais comum do banco real: a ÚNICA pessoa vinculada é a própria cobrada atual. Aqui o
        // accordion de "outras pessoas" fica vazio — se as ações vivessem só nele, trocar responsável e
        // encerrar vínculo teriam sumido da tela, que é a regressão que este teste existe para travar.
        $vinculo = VinculoPessoaObjetoFactory::createOne([
            'tenant' => $tenant, 'objeto' => $caso->getObjeto(),
            'pessoa' => $caso->getPessoaCobradaAtual(), 'tipoVinculo' => TipoVinculo::Proprietario,
        ]);

        $crawler = $client->request('GET', '/cobrancas/objetos/' . $caso->getObjeto()->getId());
        self::assertResponseIsSuccessful();

        self::assertCount(0, $crawler->filter('#tab-responsaveis .accordion-item'), 'não há outras pessoas neste cenário');

        // Trocar responsável: caminho universal, que lista todas as pessoas do escritório (inclusive
        // quem ainda não está vinculado) — era o que a barra oferecia antes da consolidação.
        self::assertSelectorExists('#tab-responsaveis [data-bs-target="#modalAlterarPessoa"]', 'trocar responsável sumiu quando só há a cobrada atual');
        // Encerrar vínculo da própria cobrada atual: o card removido oferecia, o UseCase aceita.
        // Etapa 6 (spec §2.1) MOVEU o botão do card da pessoa para o cabeçalho da aba, junto de
        // "Trocar responsável" e "Editar". A função é a mesma e o alvo (o vínculo da cobrada atual)
        // também — o que mudou foi o lugar do gatilho. As duas asserções abaixo travam as duas pontas:
        // ele existe no cabeçalho, e existe UMA vez só na aba (com um vínculo só, duplicar seria pôr o
        // mesmo gatilho do mesmo modal duas vezes na mesma dobra).
        $encerrar = $crawler->filter('#tab-responsaveis [data-bs-target="#modalEncerrarVinculo"]');
        self::assertCount(1, $encerrar, 'encerrar vínculo da cobrada atual sumiu (ou apareceu duplicado)');
        // Ancorado pela IDENTIDADE do vínculo, não pelo prefixo da URL: "contém /cobrancas/vinculos/"
        // aceitaria o vínculo de qualquer pessoa, e a asserção prometeria mais do que prova.
        self::assertSame(
            '/cobrancas/vinculos/' . $vinculo->getId() . '/encerrar',
            $encerrar->attr('data-acao-url'),
            'o botão do cabeçalho tem de carregar a URL de encerramento do vínculo da COBRADA ATUAL',
        );
    }

    #[TestDox('Pessoa homônima recebe "Definir como atual" habilitado e é selecionável no modal de troca')]
    public function testDefinirComoAtualFuncionaParaHomonimo(): void
    {
        $client = static::createClient();
        [, $tenant] = $this->criarAdminLogado($client);
        [, $caso] = $this->semearGrafo($tenant);
        $objeto = $caso->getObjeto();

        VinculoPessoaObjetoFactory::createOne([
            'tenant' => $tenant, 'objeto' => $objeto,
            'pessoa' => $caso->getPessoaCobradaAtual(), 'tipoVinculo' => TipoVinculo::Proprietario,
        ]);

        // Duas pessoas com o MESMO nome. Até 2026-07-26 `PessoaRepository::opcoesDoTenant` indexava as
        // opções pelo nome e uma delas sumia do modal — a UI mitigava desabilitando o botão. Com o mapa
        // indexado por rótulo desempatado, as duas existem: a ação volta a valer para homônimo.
        $vinculada = PessoaFactory::createOne(['tenant' => $tenant, 'nome' => 'JOSE DA SILVA', 'cpf' => '111.111.111-11']);
        $gemea = PessoaFactory::createOne(['tenant' => $tenant, 'nome' => 'JOSE DA SILVA', 'cpf' => '222.222.222-22']);
        VinculoPessoaObjetoFactory::createOne([
            'tenant' => $tenant, 'objeto' => $objeto,
            'pessoa' => $vinculada, 'tipoVinculo' => TipoVinculo::Representante,
        ]);

        $crawler = $client->request('GET', '/cobrancas/objetos/' . $objeto->getId());
        self::assertResponseIsSuccessful();

        // O modal oferece as DUAS, com ids diferentes — nenhuma foi engolida pela outra.
        $opcoes = $crawler->filter('#modalAlterarPessoa select option')->each(fn ($o) => $o->attr('value'));
        self::assertContains((string) $vinculada->getId(), $opcoes, 'a homônima vinculada tem de ser selecionável');
        self::assertContains((string) $gemea->getId(), $opcoes, 'a outra homônima também');

        // Botão habilitado, com o id certo — é ele que o JS injeta no select ao abrir o modal.
        $botoes = $crawler->filter('#tab-responsaveis .js-definir-cobrada');
        self::assertCount(1, $botoes, 'a ação aparece uma vez para a pessoa do accordion');
        self::assertSame((string) $vinculada->getId(), $botoes->attr('data-pessoa-id'));
        self::assertCount(
            0,
            $crawler->filter('#tab-responsaveis .accordion-body button[disabled]'),
            'a mitigação de homônimo saiu junto com o defeito',
        );
    }

    #[TestDox('Quem não gerencia vê a aba Responsáveis, mas sem as ações de mutação')]
    public function testAbaResponsaveisRespeitaOsGatesDeSempre(): void
    {
        $client = static::createClient();
        [, $tenant] = $this->criarOperadorSemCapacidade($client);
        [, $caso] = $this->semearGrafo($tenant);
        VinculoPessoaObjetoFactory::createOne([
            'tenant' => $tenant, 'objeto' => $caso->getObjeto(),
            'pessoa' => $caso->getPessoaCobradaAtual(), 'tipoVinculo' => TipoVinculo::Proprietario,
        ]);

        $client->request('GET', '/cobrancas/objetos/' . $caso->getObjeto()->getId());
        self::assertResponseIsSuccessful();

        // Ver quem responde pela unidade é leitura — o antigo collapse "Envolvidos" também não exigia
        // capacidade. O que não pode aparecer é gatilho de mutação.
        self::assertSelectorExists('#tab-responsaveis .cob-resp-atual');
        self::assertSelectorNotExists('#tab-responsaveis .js-definir-cobrada', 'sem capacidade não se troca o responsável');
        self::assertSelectorNotExists('#tab-responsaveis [data-bs-target="#modalVincularPessoaObjeto"]');
        self::assertSelectorNotExists('#tab-responsaveis [data-bs-target="#modalFichaPessoa"]');
        self::assertSelectorNotExists('#tab-responsaveis [data-bs-target="#modalEncerrarVinculo"]');
    }

    /** Lê o estado REAL do banco: o EM é resetado entre requisições e o cache de identidade mente. */
    private function buscarEvento(int $id): ?EventoHistorico
    {
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $em->clear();

        return $em->getRepository(EventoHistorico::class)->find($id);
    }

    private function semearAnotacao(CasoCobranca $caso, Tenant $tenant, User $autor, string $texto): EventoHistorico
    {
        $em = static::getContainer()->get(EntityManagerInterface::class);

        $evento = new EventoHistorico();
        $evento->setTenant($tenant)
            ->setCaso($caso)
            ->setTipo(TipoEventoHistorico::Anotacao)
            ->setUsuario($autor)
            ->setDescricao($texto)
            ->setOcorridoEm(new \DateTimeImmutable());

        $em->persist($evento);
        $em->flush();

        return $evento;
    }
}
