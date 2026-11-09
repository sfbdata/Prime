<?php

declare(strict_types=1);

namespace App\Tests\AssinaturaEletronica\Functional;

use App\AssinaturaEletronica\Controller\ArquivosDaAssinaturaController;
use App\AssinaturaEletronica\Controller\AssinaturaEletronicaController;
use App\AssinaturaEletronica\Controller\DocumentosDaAssinaturaController;
use App\AssinaturaEletronica\Entity\DocumentoParaAssinar;
use App\AssinaturaEletronica\Repository\EventoDaTrilhaRepository;
use App\Shared\Armazenamento\CategoriaDeArquivo;
use App\Tests\AssinaturaEletronica\Support\NaTelaDeAssinatura;
use App\Tests\Functional\JusPrimeWebTestCase;
use App\Tests\Shared\Doubles\ArmazenamentoEmMemoriaNoContainer;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\TestDox;

/**
 * As rotas da tela Assinatura pela HTTP (contrato da etapa 1, seções 3 e 4): porta do módulo e da chave do Master,
 * CSRF, escritório alheio (404), rascunho com PDF e logo, Lixeira, excluir de vez, cor e duplicar. O armazenamento é
 * o dublê em memória (confere escopo e categoria de cada chave gravada); a entrega dos arquivos pelo disco real está
 * em {@see ArquivosDaAssinaturaControllerTest}. O banco é desfeito pelo DAMA ao fim de cada teste.
 */
#[CoversClass(AssinaturaEletronicaController::class)]
#[CoversClass(DocumentosDaAssinaturaController::class)]
#[CoversClass(ArquivosDaAssinaturaController::class)]
final class AssinaturaEletronicaControllerTest extends JusPrimeWebTestCase
{
    use NaTelaDeAssinatura;

    private ArmazenamentoEmMemoriaNoContainer $disco;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $this->client->disableReboot();
        $this->disco = ArmazenamentoEmMemoriaNoContainer::instalarEm(static::getContainer());
        $this->csrfPrevisivel();
    }

    /** @return iterable<string, array{string, string}> */
    public static function rotas(): iterable
    {
        yield 'página' => ['GET', '/assinatura'];
        yield 'lista' => ['GET', '/assinatura/documentos'];
        yield 'detalhe' => ['GET', '/assinatura/documentos/1'];
        yield 'criar' => ['POST', '/assinatura/documentos'];
        yield 'salvar' => ['POST', '/assinatura/documentos/1'];
        yield 'duplicar' => ['POST', '/assinatura/documentos/1/duplicar'];
        yield 'lixeira' => ['POST', '/assinatura/documentos/lixeira'];
        yield 'restaurar' => ['POST', '/assinatura/documentos/restaurar'];
        yield 'excluir' => ['POST', '/assinatura/documentos/excluir'];
        yield 'cor' => ['POST', '/assinatura/documentos/cor'];
        yield 'arquivo' => ['GET', '/assinatura/documentos/1/arquivo'];
        yield 'logo' => ['GET', '/assinatura/documentos/1/logo'];
        yield 'logo padrão' => ['GET', '/assinatura/logo-padrao'];
    }

    #[DataProvider('rotas')]
    #[TestDox('sem o módulo: 403 em $_dataName')]
    public function testSemModulo(string $metodo, string $url): void
    {
        $this->entrar(false);

        $this->client->request($metodo, $url, [], [], ['HTTP_X_CSRF_TOKEN' => self::CSRF, 'CONTENT_TYPE' => 'application/json'], '{"ids":[1]}');

        self::assertResponseStatusCodeSame(403);
    }

    #[TestDox('com a chave do Master desligada (como nasce): 503 para quem não é Master')]
    public function testChaveDoMasterDesligada(): void
    {
        $this->entrar(true, chaveLigada: false);

        $this->client->request('GET', '/assinatura/documentos', [], [], ['HTTP_ACCEPT' => 'application/json']);

        self::assertResponseStatusCodeSame(503);
    }

    #[TestDox('POST sem o token CSRF é recusado e nada é gravado')]
    public function testSemCsrf(): void
    {
        $this->entrar(true);

        $this->client->request('POST', '/assinatura/documentos', ['dados' => json_encode(['nome' => 'Sem token'])]);

        self::assertResponseStatusCodeSame(403);
        self::assertSame([], $this->listar()['documentos']);
    }

    #[TestDox('criar sem arquivo: 201 com o id; o detalhe traz o rascunho, os signatários, a logo da BlueJus e "Rascunho salvo"')]
    public function testCriarSemArquivo(): void
    {
        $this->entrar(true);

        $id = $this->salvar(null, [
            'nome' => 'Contrato de honorários', 'pasta' => 'Silva', 'categoria' => 'Contratos', 'modelo' => 'honorarios',
            'blocos' => [['k' => 't', 'x' => 'CONTRATO', 'full' => 1, 'cor' => 'x'], ['k' => 'a', 'i' => 0, 'r' => 'Contratante']],
            'mensagem' => 'Assine, por favor', 'prazoDias' => 10,
            'signatarios' => [['nome' => 'Ana Lima', 'email' => 'ana@exemplo.com.br', 'cpf' => '123.456.789-09', 'papel' => 'Contratante', 'nivel' => 'reforcada']],
        ]);
        self::assertResponseStatusCodeSame(201);

        $detalhe = $this->detalhe($id);
        self::assertSame(['Contrato de honorários', 'Silva', 'rascunho', 'honorarios', 10], [$detalhe['nome'], $detalhe['pasta'], $detalhe['situacao'], $detalhe['modelo'], $detalhe['prazoDias']]);
        self::assertSame([['k' => 't', 'x' => 'CONTRATO', 'full' => 1], ['k' => 'a', 'i' => 0, 'r' => 'Contratante']], $detalhe['blocos'], 'campo desconhecido não é guardado');
        self::assertSame('bluejus', $detalhe['logo']['tipo']);
        self::assertStringEndsWith('images/assinatura/logo-bluejus.png', (string) $detalhe['logo']['url']);
        self::assertNull($detalhe['arquivo']);
        self::assertFalse($detalhe['naLixeira']);
        self::assertSame([0, 1], [$detalhe['assinados'], $detalhe['total']]);
        self::assertSame(['12345678909', 'reforcada', 'pendente'], [$detalhe['signatarios'][0]['cpf'], $detalhe['signatarios'][0]['nivel'], $detalhe['signatarios'][0]['situacao']]);
        self::assertSame(['Rascunho salvo'], array_column($detalhe['eventos'], 'texto'));

        $lista = $this->listar();
        self::assertSame(['Silva'], $lista['pastas']);
        self::assertSame([['nome' => 'Ana Lima', 'situacao' => 'pendente']], $lista['documentos'][0]['signatarios']);
    }

    #[TestDox('criar com PDF e logo própria como padrão: os três arquivos ficam no escritório, na categoria isolada')]
    public function testCriarComPdfELogo(): void
    {
        [, $tenant] = $this->entrar(true);

        $id = $this->salvar(null, [
            'nome' => 'Procuração', 'logo' => ['tipo' => 'propria'], 'usarLogoComoPadrao' => true,
            'blocos' => [['k' => 'pg', 'n' => 1]],
        ], $this->pdf(), $this->png());
        self::assertResponseStatusCodeSame(201);

        $detalhe = $this->detalhe($id);
        self::assertSame('contrato.pdf', $detalhe['arquivo']['nome']);
        self::assertSame('/assinatura/documentos/' . $id . '/arquivo', $detalhe['arquivo']['url']);
        self::assertSame(['tipo' => 'propria', 'url' => '/assinatura/documentos/' . $id . '/logo'], $detalhe['logo']);

        $gravadas = $this->disco->memoria->gravadas;
        self::assertCount(3, $gravadas, 'PDF, logo do documento e a cópia da logo padrão');
        foreach ($gravadas as $chave) {
            self::assertSame(CategoriaDeArquivo::ASSINATURA_DOCUMENTO, $chave->categoria);
            self::assertSame($tenant->getId(), $chave->escopo->tenantIdOuNull());
        }
        self::assertSame(self::PDF_MINIMO, $this->disco->memoria->ler($gravadas[0]));
    }

    #[TestDox('salvar sem PDF mantém o atual; com PDF novo, o antigo sai do disco depois do COMMIT')]
    public function testSalvarTrocaOuMantemOArquivo(): void
    {
        $this->entrar(true);
        $id = $this->salvar(null, ['nome' => 'Acordo'], $this->pdf());
        $primeiro = $this->disco->memoria->ultimaGravada();

        $this->salvar($id, ['nome' => 'Acordo revisto']);
        self::assertResponseIsSuccessful();
        self::assertSame(['id' => $id], $this->json());
        self::assertSame('contrato.pdf', $this->detalhe($id)['arquivo']['nome']);
        self::assertTrue($this->disco->memoria->existe($primeiro));

        $this->salvar($id, ['nome' => 'Acordo final'], $this->pdf('acordo.pdf'));
        self::assertResponseIsSuccessful();
        self::assertSame('acordo.pdf', $this->detalhe($id)['arquivo']['nome']);
        self::assertFalse($this->disco->memoria->existe($primeiro), 'o PDF trocado sai');
        self::assertSame(['Rascunho salvo', 'Rascunho salvo', 'Rascunho salvo'], array_column($this->detalhe($id)['eventos'], 'texto'));
    }

    #[TestDox('salvar fora do rascunho: 422 "Só rascunho pode ser alterado."')]
    public function testSalvarForaDoRascunho(): void
    {
        $this->entrar(true);
        $id = $this->salvar(null, ['nome' => 'Enviado']);
        $this->situacao($id, 'aguardando');

        $this->salvar($id, ['nome' => 'Mudado']);

        self::assertResponseStatusCodeSame(422);
        self::assertSame(['erro' => 'Só rascunho pode ser alterado.'], $this->json());
        self::assertSame('Enviado', $this->detalhe($id)['nome']);
    }

    #[TestDox('recusas antes de qualquer gravação: PDF que não é PDF, logo que não é imagem, logo própria sem imagem, página sem PDF, nome longo')]
    public function testArquivosRecusados(): void
    {
        $this->entrar(true);

        $this->salvar(null, ['nome' => 'X'], $this->temporario('falso.pdf', 'só texto'));
        self::assertResponseStatusCodeSame(422);
        self::assertSame(['erro' => 'Envie um arquivo PDF.'], $this->json());

        $this->salvar(null, ['nome' => 'X', 'logo' => ['tipo' => 'propria']], null, $this->temporario('logo.png', self::PDF_MINIMO));
        self::assertResponseStatusCodeSame(422);
        self::assertSame(['erro' => 'A logo deve ser uma imagem PNG ou JPEG.'], $this->json());

        $this->salvar(null, ['nome' => 'X', 'logo' => ['tipo' => 'propria']]);
        self::assertResponseStatusCodeSame(422);
        self::assertSame(['erro' => 'Envie a imagem da logo própria.'], $this->json());

        $this->salvar(null, ['nome' => 'X', 'blocos' => [['k' => 'pg', 'n' => 1]]]);
        self::assertResponseStatusCodeSame(422);

        $this->salvar(null, ['nome' => str_repeat('a', 201)]);
        self::assertResponseStatusCodeSame(422);
        self::assertSame(['erro' => 'O nome do documento pode ter até 200 caracteres.'], $this->json());

        self::assertSame([], $this->disco->memoria->gravadas);
        self::assertSame([], $this->listar()['documentos']);
    }

    #[TestDox('documento de outro escritório: 404 em todas as rotas com id, e o lote inteiro é recusado')]
    public function testOutroEscritorio(): void
    {
        $this->entrar(true);
        $alheio = $this->salvar(null, ['nome' => 'Do outro', 'logo' => ['tipo' => 'propria'], 'usarLogoComoPadrao' => true], $this->pdf(), $this->png());
        $this->entrar(true);
        $meu = $this->salvar(null, ['nome' => 'Meu']);

        foreach (['GET /assinatura/documentos/%d', 'POST /assinatura/documentos/%d/duplicar', 'GET /assinatura/documentos/%d/arquivo', 'GET /assinatura/documentos/%d/logo'] as $rota) {
            [$metodo, $url] = explode(' ', $rota);
            $this->client->request($metodo, sprintf($url, $alheio), [], [], ['HTTP_X_CSRF_TOKEN' => self::CSRF]);
            self::assertResponseStatusCodeSame(404, $rota);
        }

        $this->salvar($alheio, ['nome' => 'Invadido']);
        self::assertResponseStatusCodeSame(404);

        foreach (['lixeira', 'restaurar', 'excluir'] as $acao) {
            $this->postarJson('/assinatura/documentos/' . $acao, ['ids' => [$meu, $alheio]]);
            self::assertResponseStatusCodeSame(404, $acao);
        }
        $this->postarJson('/assinatura/documentos/cor', ['ids' => [$alheio], 'cor' => 'azul']);
        self::assertResponseStatusCodeSame(404);

        self::assertFalse($this->detalhe($meu)['naLixeira'], 'nada do lote foi feito');
        $this->client->request('GET', '/assinatura/logo-padrao');
        self::assertResponseStatusCodeSame(404, 'a logo padrão do outro escritório não vaza');
    }

    #[TestDox('Lixeira cancela o envio que aguardava; restaurar não o reativa; a lista mostra os da Lixeira')]
    public function testLixeiraERestaurar(): void
    {
        $this->entrar(true);
        $enviado = $this->salvar(null, ['nome' => 'Enviado']);
        $rascunho = $this->salvar(null, ['nome' => 'Rascunho']);
        $this->situacao($enviado, 'aguardando');

        $this->postarJson('/assinatura/documentos/lixeira', ['ids' => [$enviado, $rascunho]]);
        self::assertResponseIsSuccessful();
        self::assertSame(['ok' => true], $this->json());

        $detalhe = $this->detalhe($enviado);
        self::assertTrue($detalhe['naLixeira']);
        self::assertSame('cancelado', $detalhe['situacao']);
        self::assertSame([
            'Movido para a Lixeira. Evidências preservadas',
            'Envio cancelado pela exclusão. Links desativados',
            'Rascunho salvo',
        ], array_column($detalhe['eventos'], 'texto'));
        self::assertSame([true, true], array_column($this->listar()['documentos'], 'naLixeira'));

        $this->salvar($rascunho, ['nome' => 'Na Lixeira']);
        self::assertResponseStatusCodeSame(404, 'na Lixeira não se edita');

        $this->postarJson('/assinatura/documentos/restaurar', ['ids' => [$enviado]]);
        self::assertResponseIsSuccessful();
        $detalhe = $this->detalhe($enviado);
        self::assertFalse($detalhe['naLixeira']);
        self::assertSame('cancelado', $detalhe['situacao']);
        self::assertSame('Restaurado da Lixeira', $detalhe['eventos'][0]['texto']);
    }

    #[TestDox('excluir de vez: só rascunho na Lixeira, tudo ou nada; o enviado fica e o texto é o do desenho')]
    public function testExcluirSoRascunho(): void
    {
        $this->entrar(true);
        $rascunho = $this->salvar(null, ['nome' => 'Rascunho', 'logo' => ['tipo' => 'propria']], $this->pdf(), $this->png());
        $concluido = $this->salvar(null, ['nome' => 'Concluído']);
        $this->situacao($concluido, 'concluido');
        $arquivos = $this->disco->memoria->gravadas;

        $this->postarJson('/assinatura/documentos/excluir', ['ids' => [$rascunho]]);
        self::assertResponseStatusCodeSame(422, 'fora da Lixeira');
        self::assertSame(['erro' => 'Só documentos na Lixeira podem ser excluídos definitivamente.'], $this->json());

        $this->postarJson('/assinatura/documentos/lixeira', ['ids' => [$rascunho, $concluido]]);
        $this->postarJson('/assinatura/documentos/excluir', ['ids' => [$rascunho, $concluido]]);
        self::assertResponseStatusCodeSame(422);
        self::assertSame(['erro' => 'Documentos enviados permanecem na Lixeira para preservar as evidências.'], $this->json());
        self::assertCount(2, $this->listar()['documentos'], 'nada saiu');

        $this->postarJson('/assinatura/documentos/excluir', ['ids' => [$rascunho]]);
        self::assertResponseIsSuccessful();
        self::assertSame([$concluido], array_column($this->listar()['documentos'], 'id'));
        $this->client->request('GET', '/assinatura/documentos/' . $rascunho);
        self::assertResponseStatusCodeSame(404);
        self::assertCount(2, $arquivos);
        foreach ($arquivos as $chave) {
            self::assertFalse($this->disco->memoria->existe($chave), 'o PDF e a logo saem depois do COMMIT');
        }
        self::assertSame(0, (int) $this->conexao()->fetchOne('SELECT count(*) FROM assinatura_eletronica_evento WHERE documento_id = ?', [$rascunho]));
    }

    #[TestDox('cor da linha por documento; cor desconhecida ou ausente é recusada')]
    public function testCor(): void
    {
        $this->entrar(true);
        $id = $this->salvar(null, ['nome' => 'Colorido']);

        $this->postarJson('/assinatura/documentos/cor', ['ids' => [$id], 'cor' => 'verde']);
        self::assertResponseIsSuccessful();
        self::assertSame('verde', $this->listar()['documentos'][0]['cor']);

        $this->postarJson('/assinatura/documentos/cor', ['ids' => [$id], 'cor' => 'rosa']);
        self::assertResponseStatusCodeSame(422);
        $this->postarJson('/assinatura/documentos/cor', ['ids' => [$id]]);
        self::assertResponseStatusCodeSame(422);
        self::assertSame('verde', $this->listar()['documentos'][0]['cor']);
    }

    #[TestDox('duplicar: rascunho "(cópia)" com PDF e logo em chaves novas e um único evento; excluir o original não apaga os da cópia')]
    public function testDuplicar(): void
    {
        $this->entrar(true);
        $original = $this->salvar(null, ['nome' => 'Contrato', 'pasta' => 'Silva', 'logo' => ['tipo' => 'propria'], 'signatarios' => [['nome' => 'Ana']]], $this->pdf(), $this->png());
        $this->postarJson('/assinatura/documentos/cor', ['ids' => [$original], 'cor' => 'azul']);
        $this->situacao($original, 'concluido');
        $doOriginal = $this->disco->memoria->gravadas;

        $this->client->request('POST', '/assinatura/documentos/' . $original . '/duplicar', [], [], ['HTTP_X_CSRF_TOKEN' => self::CSRF]);
        self::assertResponseStatusCodeSame(201);
        $copia = (int) $this->json()['id'];

        $detalhe = $this->detalhe($copia);
        self::assertSame('Contrato (cópia)', $detalhe['nome']);
        self::assertSame(['rascunho', 'Silva', 'azul', 'propria'], [$detalhe['situacao'], $detalhe['pasta'], $detalhe['cor'], $detalhe['logo']['tipo']]);
        self::assertSame('contrato.pdf', $detalhe['arquivo']['nome']);
        self::assertSame(['Ana', 'pendente'], [$detalhe['signatarios'][0]['nome'], $detalhe['signatarios'][0]['situacao']]);
        self::assertSame(['Duplicado de Contrato'], array_column($detalhe['eventos'], 'texto'));
        $daCopia = \array_slice($this->disco->memoria->gravadas, \count($doOriginal));
        self::assertCount(2, $daCopia, 'PDF e logo copiados para chaves novas');

        $this->situacao($original, 'rascunho');
        $this->postarJson('/assinatura/documentos/lixeira', ['ids' => [$original]]);
        $this->postarJson('/assinatura/documentos/excluir', ['ids' => [$original]]);
        self::assertResponseIsSuccessful();

        foreach ($doOriginal as $chave) {
            self::assertFalse($this->disco->memoria->existe($chave));
        }
        foreach ($daCopia as $chave) {
            self::assertTrue($this->disco->memoria->existe($chave), 'os arquivos da cópia ficam');
        }
        self::assertSame(self::PDF_MINIMO, $this->disco->memoria->ler($daCopia[0]));
    }

    #[TestDox('a trilha gravada no banco é uma corrente: cada evento leva o hash do anterior e o hash confere ao reler')]
    public function testTrilhaEncadeadaNoBanco(): void
    {
        $this->entrar(true);
        $id = $this->salvar(null, ['nome' => 'Corrente']);
        $this->salvar($id, ['nome' => 'Corrente 2']);
        $this->postarJson('/assinatura/documentos/lixeira', ['ids' => [$id]]);
        $this->postarJson('/assinatura/documentos/restaurar', ['ids' => [$id]]);

        $em = static::getContainer()->get(EntityManagerInterface::class);
        $em->clear();
        $documento = $em->find(DocumentoParaAssinar::class, $id) ?? self::fail('documento não achado');
        $eventos = array_reverse(static::getContainer()->get(EventoDaTrilhaRepository::class)->doDocumento($documento));

        self::assertCount(4, $eventos);
        self::assertNull($eventos[0]->getHashAnterior());
        foreach ($eventos as $i => $evento) {
            self::assertTrue($evento->hashConfere(), 'evento ' . $i);
            if ($i > 0) {
                self::assertSame($eventos[$i - 1]->getHash(), $evento->getHashAnterior());
            }
        }
    }
}
