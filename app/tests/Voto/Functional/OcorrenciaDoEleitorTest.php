<?php

declare(strict_types=1);

namespace App\Tests\Voto\Functional;

use App\Entity\Auth\User;
use App\Tests\Factory\Voto\AssembleiaFactory;
use App\Tests\Factory\Voto\PautaDaAssembleiaFactory;
use App\Tests\Factory\Voto\UnidadeDaAssembleiaFactory;
use App\Tests\Functional\JusPrimeWebTestCase;
use App\Voto\Controller\OcorrenciaDoEleitorController;
use App\Voto\Controller\OcorrenciasDaAssembleiaController;
use App\Voto\Entity\Assembleia;
use App\Voto\Entity\OcorrenciaDaVotacao;
use App\Voto\Entity\UnidadeDaAssembleia;
use App\Voto\Enum\PapelNaEquipe;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\TestDox;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Zenstruck\Foundry\Test\Factories;

/**
 * "Registrar ocorrência" do eleitor no Portal e a lista do gestor (pedido do Farlei de 26/11/2026): link discreto e janela
 * de confirmação, formulário com a qualificação, limite de 5, 404 fora da assembleia, lista só para quem gere e a seção
 * "Ocorrências registradas" na ata e na minuta.
 */
#[CoversClass(OcorrenciaDoEleitorController::class)]
#[CoversClass(OcorrenciasDaAssembleiaController::class)]
#[Group('voto')]
final class OcorrenciaDoEleitorTest extends JusPrimeWebTestCase
{
    use ComPessoasDoEscritorioDoVoto;
    use Factories;

    private KernelBrowser $client;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $this->client->disableReboot();
        $this->prepararEscritorioDoVoto();
    }

    #[TestDox('A cédula traz o link discreto e a janela de confirmação; "Sim" leva ao formulário com a qualificação só leitura')]
    public function testLinkJanelaEFormulario(): void
    {
        $a = $this->assembleiaComUnidades('aberta');
        $link = $this->link($a, '101');

        $cedula = $this->client->request('GET', $link);
        self::assertResponseIsSuccessful();
        self::assertSame('Registrar ocorrência', trim($cedula->filter('[data-vc-ocorrencia-abrir]')->text()));
        self::assertSame('Deseja registrar uma ocorrência nesta votação?', trim($cedula->filter('#janela-ocorrencia [role=dialog] b')->text()));
        self::assertSame($link . '/ocorrencia', $cedula->filter('#janela-ocorrencia a[data-vc-ocorrencia-sim]')->attr('href'));

        $form = $this->client->request('GET', $link . '/ocorrencia');
        self::assertResponseIsSuccessful();
        $qualificacao = $form->filter('[data-vc-ocorrencia-qualificacao]')->text();
        self::assertStringContainsString('Ana Apta', $qualificacao);
        self::assertStringContainsString('101', $qualificacao);
        self::assertSame('500', $form->filter('textarea[name=texto]')->attr('maxlength'));
        self::assertCount(1, $form->filter('[data-vc-ocorrencia-contador]'));
        self::assertSame('noindex, nofollow', $this->client->getResponse()->headers->get('X-Robots-Tag'));
    }

    #[TestDox('O eleitor registra: grava com a qualificação congelada e volta à cédula; vale com a votação encerrada')]
    public function testRegistraComVotacaoEncerrada(): void
    {
        $a = $this->assembleiaComUnidades('encerrada');
        $link = $this->link($a, '101');

        $this->enviar($link, 'Som muito baixo na sala');

        self::assertResponseRedirects($link);
        $linha = $this->ocorrencias($a)[0];
        self::assertSame('Som muito baixo na sala', $linha->getTexto());
        self::assertSame('Ana Apta', $linha->getNomeDoEleitor());
        self::assertSame('A · 101', $linha->getRotuloDaUnidade());
        self::assertNull($linha->getPauta());
        self::assertSame($this->escritorio->getId(), $linha->getTenant()->getId());
        $this->client->followRedirect();
        self::assertStringContainsString('Ocorrência registrada.', (string) $this->client->getResponse()->getContent());
    }

    #[TestDox('A sexta ocorrência da unidade volta ao formulário com a mensagem do limite e nada é gravado')]
    public function testLimiteDeCinco(): void
    {
        $a = $this->assembleiaComUnidades('aberta');
        $link = $this->link($a, '101');
        $csrf = (string) $this->client->request('GET', $link . '/ocorrencia')->filter('input[name=_token]')->attr('value');
        for ($i = 1; $i <= 5; ++$i) {
            $this->enviar($link, 'Ocorrência ' . $i, '', $csrf);
        }

        $this->enviar($link, 'Ocorrência 6', '', $csrf);

        self::assertResponseRedirects($link . '/ocorrencia');
        self::assertCount(5, $this->ocorrencias($a));
        $this->client->followRedirect();
        self::assertStringContainsString('Você já registrou o máximo de 5 ocorrências nesta assembleia.', (string) $this->client->getResponse()->getContent());
        self::assertCount(0, $this->client->getCrawler()->filter('form[data-vc-ocorrencia-form]'), 'sem formulário depois do limite');
    }

    #[TestDox('Texto vazio é recusado; token de outra pessoa não existe (404); CSRF inválido não grava')]
    public function testRecusasEPosse(): void
    {
        $a = $this->assembleiaComUnidades('aberta');
        $link = $this->link($a, '101');

        $this->enviar($link, '   ');
        self::assertResponseRedirects($link . '/ocorrencia');
        self::assertCount(0, $this->ocorrencias($a));

        $this->client->request('POST', $link . '/ocorrencia', ['_token' => 'invalido', 'texto' => 'Algo']);
        self::assertCount(0, $this->ocorrencias($a));

        $this->client->request('GET', '/votar/' . str_repeat('0', 40) . '/ocorrencia');
        self::assertResponseStatusCodeSame(404);
        $this->client->request('POST', '/votar/' . str_repeat('0', 40) . '/ocorrencia', ['_token' => 'x', 'texto' => 'Algo']);
        $this->client->followRedirect();
        self::assertResponseStatusCodeSame(404);
    }

    #[TestDox('Pauta de OUTRA assembleia (de outro escritório) é recusada: nenhuma ocorrência é gravada')]
    public function testPautaDeOutraAssembleia(): void
    {
        $a = $this->assembleiaComUnidades('aberta');
        $alheia = AssembleiaFactory::new()->publicada()->create()->_real();
        $pautaAlheia = PautaDaAssembleiaFactory::new(['assembleia' => $alheia])->comOpcoesPadrao()->create()->_real();
        $link = $this->link($a, '101');

        $this->enviar($link, 'Tentativa', (string) $pautaAlheia->getId());

        self::assertResponseRedirects($link . '/ocorrencia');
        self::assertCount(0, $this->ocorrencias($a));
        self::assertCount(0, $this->ocorrencias($alheia));
    }

    #[TestDox('O gestor vê a lista (data, qualificação, pauta, texto); o eleitor, sem login, não; quem é do outro escritório recebe 404')]
    public function testListaDoGestor(): void
    {
        $a = $this->assembleiaComUnidades('aberta');
        $pauta = $a->getPautas()->first();
        $link = $this->link($a, '101');
        $this->enviar($link, 'Fila na entrada', (string) $pauta->getId());
        $url = '/voto/assembleias/' . $a->getId() . '/ocorrencias';

        $this->client->request('GET', $url);
        self::assertResponseRedirects(null, 302, 'o eleitor (sem login) não vê a lista');

        $this->entrar($this->primario());
        $lista = $this->client->request('GET', $url);
        self::assertResponseIsSuccessful();
        self::assertSame('Ocorrências', trim($lista->filter('.voto-sub-abas .vTabOn')->text()));
        $itens = $lista->filter('[data-voto-ocorrencia]');
        self::assertCount(1, $itens);
        self::assertStringContainsString('Ana Apta · A · 101', $itens->text());
        self::assertStringContainsString('Fila na entrada', $itens->text());
        self::assertStringContainsString($pauta->getTitulo(), $itens->text());

        $alheia = AssembleiaFactory::new()->publicada()->create()->_real();
        $this->client->request('GET', '/voto/assembleias/' . $alheia->getId() . '/ocorrencias');
        self::assertResponseStatusCodeSame(404);
    }

    #[TestDox('Quem só visualiza (fiscal) ou não gere recebe 403 na lista; colega sem o módulo também')]
    public function testListaNegadaAQuemNaoGere(): void
    {
        $a = $this->assembleiaComUnidades('aberta');
        $fiscal = $this->colega();
        $this->membro($a, PapelNaEquipe::Fiscal, $fiscal);
        $url = '/voto/assembleias/' . $a->getId() . '/ocorrencias';

        $this->entrar($fiscal);
        $this->client->request('GET', $url);
        self::assertResponseStatusCodeSame(403);

        $this->entrar($this->colega(false));
        $this->client->request('GET', $url);
        self::assertResponseStatusCodeSame(403);
    }

    #[TestDox('A ata e a minuta trazem "Ocorrências registradas"; sem nenhuma, "Nenhuma ocorrência registrada"')]
    public function testAtaEMinuta(): void
    {
        $a = $this->assembleiaComUnidades('encerrada');
        $gestor = $this->primario();
        $this->entrar($gestor);

        $this->client->request('GET', '/voto/assembleias/' . $a->getId() . '/ata');
        $html = (string) $this->client->getResponse()->getContent();
        self::assertStringContainsString('Ocorrências registradas', $html);
        self::assertStringContainsString('Nenhuma ocorrência registrada', $html);

        $this->client->request('GET', '/logout');
        $this->enviar($this->link($a, '101'), 'Microfone falhou');
        $this->entrar($gestor);

        $this->client->request('GET', '/voto/assembleias/' . $a->getId() . '/ata');
        $html = (string) $this->client->getResponse()->getContent();
        self::assertStringContainsString('Ana Apta · A · 101', $html);
        self::assertStringContainsString('Microfone falhou', $html);
        self::assertStringNotContainsString('Nenhuma ocorrência registrada', $html);

        $this->client->request('POST', '/voto/assembleias/' . $a->getId() . '/minuta/docx', ['_token' => $this->token('voto_assembleia_' . $a->getId())]);
        self::assertResponseIsSuccessful();
        $documento = $this->documentoDoDocx((string) $this->client->getResponse()->getContent());
        self::assertStringContainsString('Ocorrências registradas', $documento);
        self::assertStringContainsString('Microfone falhou', $documento);
    }

    #[TestDox('LGPD: quem só visualiza (fiscal) vê a quantidade na ata e na minuta, nunca nome nem texto; quem gere vê tudo')]
    public function testAtaEMinutaSoComAQuantidadeParaQuemNaoGere(): void
    {
        $a = $this->assembleiaComUnidades('encerrada');
        $fiscal = $this->colega();
        $this->membro($a, PapelNaEquipe::Fiscal, $fiscal);
        $this->enviar($this->link($a, '101'), 'Segredo do eleitor');
        $this->entrar($fiscal);

        $this->client->request('GET', '/voto/assembleias/' . $a->getId() . '/ata');
        self::assertResponseIsSuccessful();
        $html = (string) $this->client->getResponse()->getContent();
        self::assertStringContainsString('1 ocorrência registrada; o conteúdo fica com a gestão da assembleia.', $html);
        self::assertStringNotContainsString('Segredo do eleitor', $html);
        self::assertStringNotContainsString('Ana Apta · A · 101', $html, 'a relação de votantes (outra seção) traz o nome; a ocorrência não');

        $this->client->request('POST', '/voto/assembleias/' . $a->getId() . '/minuta/docx', ['_token' => $this->token('voto_assembleia_' . $a->getId())]);
        self::assertResponseIsSuccessful();
        $documento = $this->documentoDoDocx((string) $this->client->getResponse()->getContent());
        self::assertStringContainsString('1 ocorrência registrada', $documento);
        self::assertStringNotContainsString('Segredo do eleitor', $documento);
        self::assertStringNotContainsString('Ana Apta', $documento, 'a minuta não leva a relação nominal');
    }

    #[TestDox('Votação arquivada recusa a ocorrência com a mensagem clara e nada é gravado')]
    public function testArquivadaRecusa(): void
    {
        $a = $this->assembleiaComUnidades('encerrada');
        $link = $this->link($a, '101');
        $csrf = (string) $this->client->request('GET', $link . '/ocorrencia')->filter('input[name=_token]')->attr('value');
        $a = $this->em->find(Assembleia::class, $a->getId());
        $a->arquivar('Encerrada', 'Gestor');
        $this->em->flush();

        $this->enviar($link, 'Tarde demais', '', $csrf);

        self::assertResponseRedirects($link . '/ocorrencia');
        self::assertCount(0, $this->ocorrencias($a));
        $this->client->followRedirect();
        self::assertStringContainsString('Esta votação foi arquivada: não é possível registrar ocorrência.', (string) $this->client->getResponse()->getContent());
    }

    private function enviar(string $link, string $texto, string $pauta = '', ?string $csrf = null): void
    {
        $csrf ??= (string) $this->client->request('GET', $link . '/ocorrencia')->filter('input[name=_token]')->attr('value');
        $this->client->request('POST', $link . '/ocorrencia', ['_token' => $csrf, 'texto' => $texto, 'pauta' => $pauta]);
    }

    /** @return list<OcorrenciaDaVotacao> */
    private function ocorrencias(Assembleia $a): array
    {
        $this->em->clear();

        return $this->em->getRepository(OcorrenciaDaVotacao::class)->findBy(['assembleia' => $a->getId()], ['id' => 'ASC']);
    }

    private function link(Assembleia $a, string $identificacao): string
    {
        $unidade = $this->em->find(UnidadeDaAssembleia::class, $this->unidadeDe($a, $identificacao)->getId());

        return '/votar/' . UnidadeDaAssembleiaFactory::tokenDe($unidade);
    }

    private function documentoDoDocx(string $conteudo): string
    {
        $caminho = (string) tempnam(sys_get_temp_dir(), 'minuta');
        file_put_contents($caminho, $conteudo);
        $zip = new \ZipArchive();
        self::assertTrue($zip->open($caminho, \ZipArchive::RDONLY), 'a resposta é um .docx (zip)');
        $documento = (string) $zip->getFromName('word/document.xml');
        $zip->close();
        unlink($caminho);

        return $documento;
    }

    private function entrar(User $user): void
    {
        $this->logarComTenant($this->client, $user, $this->escritorio);
    }

    private function token(string $id): string
    {
        $this->client->request('GET', '/voto/assembleias/nova');
        $sessao = $this->client->getRequest()->getSession();
        $sessao->set('_csrf/' . $id, 'token-de-teste-' . $id);
        $sessao->save();

        return 'token-de-teste-' . $id;
    }
}
