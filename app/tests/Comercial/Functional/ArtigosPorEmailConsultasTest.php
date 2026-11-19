<?php

declare(strict_types=1);

namespace App\Tests\Comercial\Functional;

use App\Comercial\Envio\MensagemDeEmail;
use App\Comercial\Envio\TipoDeEnvio;
use App\Comercial\Repository\CadastradosRepository;
use App\Comercial\Repository\EnvioDeEmailRepository;
use App\Tests\Comercial\Support\CriaCampanhas;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\TestDox;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/** As duas consultas dos artigos dos blogs por e-mail (spec blog-artigos-por-email) contra o Postgres. */
#[CoversClass(CadastradosRepository::class)]
#[CoversClass(EnvioDeEmailRepository::class)]
final class ArtigosPorEmailConsultasTest extends KernelTestCase
{
    use CriaCampanhas;

    #[TestDox('Público: cadastrado com vínculo válido e e-mail confirmado; quem ainda não confirmou fica de fora; sem repetidos')]
    public function testComEmailConfirmado(): void
    {
        self::bootKernel();
        $confirmada = $this->usuario('confirmada');
        $this->vincular($confirmada, true);
        $this->vincular($confirmada, false);
        $aConfirmar = $this->usuario('aconfirmar');
        $aConfirmar->marcarEmailAConfirmar(new \DateTimeImmutable());
        $this->vincular($aConfirmar, true);
        $inativo = $this->usuario('inativo', ativo: false);
        $this->vincular($inativo, true);

        $emails = array_column(static::getContainer()->get(CadastradosRepository::class)->comEmailConfirmado(), 'email');

        self::assertSame(1, \count(array_keys($emails, strtolower((string) $confirmada->getEmail()), true)));
        self::assertNotContains(strtolower((string) $aConfirmar->getEmail()), $emails);
        self::assertNotContains(strtolower((string) $inativo->getEmail()), $emails);
    }

    #[TestDox('Já enviados: os destinatários da origem, em minúsculas e sem repetidos; outra origem não conta')]
    public function testDestinatariosDaOrigem(): void
    {
        self::bootKernel();
        $envios = static::getContainer()->get(EnvioDeEmailRepository::class);
        $agora = new \DateTimeImmutable();
        foreach (['ana@x.com', 'ANA@x.com', 'bia@x.com'] as $email) {
            $m = new MensagemDeEmail($email, 'Assunto', '<p>Corpo</p>', TipoDeEnvio::Lista, 'blog_artigo:9001', lista: 'blog_artigos');
            $envios->inserir($m, bin2hex(random_bytes(16)), 'enfileirado', $agora, $agora);
        }
        $outra = new MensagemDeEmail('cid@x.com', 'Assunto', '<p>Corpo</p>', TipoDeEnvio::Lista, 'blog_artigo:9002', lista: 'blog_artigos');
        $envios->inserir($outra, bin2hex(random_bytes(16)), 'enfileirado', $agora, $agora);
        static::getContainer()->get(EntityManagerInterface::class)->clear();

        $lidos = $envios->destinatariosDaOrigem('blog_artigo:9001');
        sort($lidos);

        self::assertSame(['ana@x.com', 'bia@x.com'], $lidos);
    }
}
