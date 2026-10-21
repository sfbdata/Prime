<?php

declare(strict_types=1);

namespace App\Tests\Comercial\Support;

use App\Comercial\Service\MontadorDeEmail;
use App\Shared\Email\Descadastro\DescadastroDeEmailInterface;
use App\Shared\Email\IdentidadeDeEmail;
use Symfony\Component\HttpFoundation\UriSigner;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Twig\Environment;

/**
 * Monta o {@see MontadorDeEmail} com as peças reais do container, escolhendo as duas chaves do rastreio (a flag e a
 * constante da Política). O assinador é o mesmo do container: `kernel.secret` com o parâmetro `s`.
 * Para KernelTestCase/WebTestCase com o kernel já de pé.
 */
trait MontaOMontador
{
    private function montador(bool $rastreioLigado = false, bool $politicaCitaRastreio = false): MontadorDeEmail
    {
        $c = static::getContainer();

        return new MontadorDeEmail(
            $c->get(Environment::class),
            $c->get(IdentidadeDeEmail::class),
            $c->get(DescadastroDeEmailInterface::class),
            $c->get(UrlGeneratorInterface::class),
            self::assinadorDeClique(),
            $rastreioLigado,
            $politicaCitaRastreio,
        );
    }

    private static function assinadorDeClique(): UriSigner
    {
        $segredo = static::getContainer()->getParameter('kernel.secret');
        \assert(\is_string($segredo));

        return new UriSigner($segredo, 's');
    }
}
