<?php

declare(strict_types=1);

namespace App\Tests\Cobranca\Functional;

use App\Cobranca\Controller\ObjetoController;
use App\Cobranca\Entity\CasoCobranca;
use App\Cobranca\Entity\EventoHistorico;
use App\Cobranca\Enum\TipoEventoHistorico;
use App\Entity\Auth\User;
use App\Entity\Tenant\Tenant;
use App\Tests\Factory\Cobranca\ObrigacaoFactory;
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

        $painel = $crawler->filter('#tab-cobranca')->html();
        self::assertLessThan(
            strpos($painel, 'Anotações recentes'),
            strpos($painel, 'cob-anotacao-nova'),
            'o editor precisa vir antes das anotações recentes',
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

        // Narrativos: anotação (criar e corrigir) e o relato do contato.
        self::assertSelectorExists('#tab-cobranca textarea[name="registrar_anotacao[texto]"][data-editor-rico]');
        self::assertSelectorExists('#modalEditarAnotacao textarea[data-editor-rico]');
        self::assertSelectorExists('#modalRegistrarTentativa textarea[name="registrar_tentativa_cobranca[observacao]"][data-editor-rico]');

        // Estruturados do MESMO modal seguem simples — a SPEC proíbe editor rico em canal/data/resultado.
        self::assertCount(0, $crawler->filter('#modalRegistrarTentativa select[data-editor-rico]'), 'seletor não leva editor rico');
        self::assertCount(0, $crawler->filter('#modalRegistrarTentativa input[data-editor-rico]'), 'data/hora não leva editor rico');

        // A barra do editor é a do sistema — sem biblioteca nova nesta entrega.
        $html = (string) $client->getResponse()->getContent();
        self::assertStringContainsString('js/editor-rico.js', $html);
        self::assertStringContainsString('js/quill/quill.js', $html);
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
