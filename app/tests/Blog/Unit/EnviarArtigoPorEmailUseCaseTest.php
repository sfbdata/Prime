<?php

declare(strict_types=1);

namespace App\Tests\Blog\Unit;

use App\Blog\Enum\BlogsDoArtigo;
use App\Blog\Repository\ArtigoDoBlogRepository;
use App\Blog\UseCase\EnviarArtigoPorEmailUseCase;
use App\Comercial\Envio\CarteiroInterface;
use App\Comercial\Envio\MensagemDeEmail;
use App\Comercial\Envio\ResultadoDoEnvio;
use App\Comercial\Envio\TipoDeEnvio;
use App\Comercial\Repository\CadastradosRepository;
use App\Comercial\Repository\EnvioDeEmailRepository;
use App\Shared\Email\Descadastro\DescadastroDeEmailInterface;
use App\Tests\Blog\ArtigosDeTeste;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;
use Twig\Environment;
use Twig\Loader\FilesystemLoader;

#[CoversClass(EnviarArtigoPorEmailUseCase::class)]
final class EnviarArtigoPorEmailUseCaseTest extends TestCase
{
    /** @var list<MensagemDeEmail> */
    private array $enviadas = [];

    private function useCase(?object $artigo, array $cadastrados, array $jaEnviados = [], array $descadastrados = []): EnviarArtigoPorEmailUseCase
    {
        $artigos = $this->createStub(ArtigoDoBlogRepository::class);
        $artigos->method('porId')->willReturn($artigo);
        $repoCadastrados = $this->createStub(CadastradosRepository::class);
        $repoCadastrados->method('comEmailConfirmado')->willReturn($cadastrados);
        $envios = $this->createMock(EnvioDeEmailRepository::class);
        $envios->method('destinatariosDaOrigem')->with('blog_artigo:7')->willReturn($jaEnviados);
        $descadastro = $this->createMock(DescadastroDeEmailInterface::class);
        $descadastro->method('filtrarDescadastrados')->willReturnCallback(
            static function (?object $tenant, array $emails, string $assunto) use ($descadastrados): array {
                self::assertNull($tenant, 'a lista é da plataforma');
                self::assertSame('blog_artigos', $assunto);

                return array_values(array_intersect($emails, $descadastrados));
            },
        );
        $descadastro->method('gerarLink')->willReturnCallback(
            static fn (?object $t, string $email, string $assunto): string => 'https://app.test/email/descadastro?d=' . $assunto . ':' . $email,
        );
        $carteiro = $this->createStub(CarteiroInterface::class);
        $carteiro->method('enviar')->willReturnCallback(function (MensagemDeEmail $m): ResultadoDoEnvio {
            $this->enviadas[] = $m;

            return new ResultadoDoEnvio(\count($this->enviadas), ResultadoDoEnvio::ENFILEIRADO);
        });
        $twig = new Environment(new FilesystemLoader(\dirname(__DIR__, 3) . '/templates'), ['strict_variables' => true]);

        return new EnviarArtigoPorEmailUseCase($artigos, $repoCadastrados, $envios, $descadastro, $carteiro, $twig);
    }

    private static function artigo(BlogsDoArtigo $blogs = BlogsDoArtigo::Normal): object
    {
        $artigo = ArtigosDeTeste::rascunhoCompleto('artigo-novo', $blogs);
        $artigo->publicar(new \DateTimeImmutable('2026-11-19 15:00'));
        (new \ReflectionProperty($artigo, 'id'))->setValue($artigo, 7);

        return $artigo;
    }

    #[TestDox('Um e-mail por cadastrado confirmado: tipo lista blog_artigos, título, resumo, link e "não receber mais"')]
    public function testEnviaAosCadastrados(): void
    {
        $artigo = self::artigo();
        $quantos = $this->useCase($artigo, [['email' => 'ana@x.com', 'nome' => 'Ana'], ['email' => 'bia@x.com', 'nome' => '']])->executar(7);

        self::assertSame(2, $quantos);
        self::assertSame(['ana@x.com', 'bia@x.com'], array_map(static fn (MensagemDeEmail $m): string => $m->para, $this->enviadas));
        $m = $this->enviadas[0];
        self::assertSame(TipoDeEnvio::Lista, $m->tipo);
        self::assertSame('blog_artigos', $m->lista);
        self::assertSame('blog_artigo:7', $m->origem);
        self::assertNull($m->escopo);
        self::assertStringContainsString($artigo->getTitulo(), $m->assunto);
        self::assertStringContainsString(htmlspecialchars($artigo->getResumo(), \ENT_QUOTES), $m->corpoHtml);
        self::assertStringContainsString('https://bluejus.com.br/blog/' . $artigo->getCategoria() . '/artigo-novo', $m->corpoHtml);
        self::assertStringContainsString('Não quer mais receber os artigos dos blogs?', $m->corpoHtml);
        self::assertStringContainsString('d=blog_artigos:ana@x.com', $m->corpoHtml);
        self::assertNull($this->enviadas[1]->nome);
    }

    #[TestDox('Idempotente e respeita o descadastro: pula quem já tem envio deste artigo e quem saiu da lista')]
    public function testPulaJaEnviadosEDescadastrados(): void
    {
        $cadastrados = [['email' => 'ana@x.com', 'nome' => 'Ana'], ['email' => 'bia@x.com', 'nome' => 'Bia'], ['email' => 'cid@x.com', 'nome' => 'Cid']];

        $quantos = $this->useCase(self::artigo(), $cadastrados, ['ana@x.com'], ['cid@x.com'])->executar(7);

        self::assertSame(1, $quantos);
        self::assertSame('bia@x.com', $this->enviadas[0]->para);
    }

    #[TestDox('Artigo inexistente ou que saiu do ar: nada é enviado')]
    public function testArtigoForaDoAr(): void
    {
        $artigo = self::artigo();
        $artigo->despublicar();

        self::assertSame(0, $this->useCase($artigo, [['email' => 'ana@x.com', 'nome' => 'Ana']])->executar(7));
        self::assertSame(0, $this->useCase(null, [['email' => 'ana@x.com', 'nome' => 'Ana']])->executar(7));
        self::assertSame([], $this->enviadas);
    }

    #[TestDox('Artigo só do BlueJus Grátis: o link vai para /gratis')]
    public function testLinkDoGratis(): void
    {
        $artigo = self::artigo(BlogsDoArtigo::Gratis);

        self::assertStringStartsWith('https://bluejus.com.br/gratis/', EnviarArtigoPorEmailUseCase::endereco($artigo));
        self::assertStringStartsWith('https://bluejus.com.br/blog/', EnviarArtigoPorEmailUseCase::endereco(self::artigo(BlogsDoArtigo::Ambos)));
    }
}
