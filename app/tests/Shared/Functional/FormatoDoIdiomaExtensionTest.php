<?php

declare(strict_types=1);

namespace App\Tests\Shared\Functional;

use App\Shared\Formato\FormatoDoIdiomaExtension;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\TestDox;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Twig\Environment;

#[CoversClass(FormatoDoIdiomaExtension::class)]
final class FormatoDoIdiomaExtensionTest extends KernelTestCase
{
    #[TestDox('em português, |data, |data_hora, |hora e |numero saem iguais ao |date e ao |number_format de hoje')]
    public function testPortuguesIgualAoFormatoFixo(): void
    {
        $saida = $this->renderizar('pt_BR', "{{ d|data }}={{ d|date('d/m/Y') }}|{{ d|data_hora }}={{ d|date('d/m/Y H:i') }}|{{ d|hora }}={{ d|date('H:i') }}|{{ 1234.5|numero(2) }}={{ 1234.5|number_format(2, ',', '.') }}");

        foreach (explode('|', $saida) as $par) {
            [$novo, $fixo] = explode('=', $par);
            self::assertSame($fixo, $novo);
        }
    }

    #[TestDox('em inglês, o formato do idioma; nulo vira vazio; sem requisição, português')]
    public function testOutroIdiomaENulo(): void
    {
        self::assertSame('03/05/2026|1,234.50|', $this->renderizar('en', '{{ d|data }}|{{ 1234.5|numero(2) }}|{{ null|data }}'));
        self::assertSame('05/03/2026', $this->renderizar(null, '{{ d|data }}'));
    }

    #[TestDox('opções avançadas: formato fixo de data e de número; o fuso muda |data_hora e |hora, nunca |data (o dia do prazo)')]
    public function testOpcoesAvancadas(): void
    {
        $atributos = [
            FormatoDoIdiomaExtension::ATRIBUTO_FUSO   => 'America/Los_Angeles',
            FormatoDoIdiomaExtension::ATRIBUTO_DATA   => 'ymd',
            FormatoDoIdiomaExtension::ATRIBUTO_NUMERO => 'us',
        ];

        self::assertSame(
            '2026-03-05|2026-03-05 04:07|04:07|1,234.50',
            $this->renderizar('pt_BR', '{{ d|data }}|{{ d|data_hora }}|{{ d|hora }}|{{ 1234.5|numero(2) }}', $atributos),
        );
        self::assertSame('2026-03-05', $this->renderizar('pt_BR', '{{ meia_noite|data }}', $atributos), 'data pura não muda de dia com o fuso');
        self::assertSame('1.234,50|05/03/2026', $this->renderizar('pt_BR', '{{ 1234.5|numero(2) }}|{{ d|data }}', [FormatoDoIdiomaExtension::ATRIBUTO_DATA => 'xyz', FormatoDoIdiomaExtension::ATRIBUTO_NUMERO => 'fr']), 'código desconhecido vale como automático');
    }

    /** @param array<string, string> $atributos */
    private function renderizar(?string $locale, string $modelo, array $atributos = []): string
    {
        self::bootKernel();
        $pilha = static::getContainer()->get(RequestStack::class);
        if ($locale !== null) {
            $request = new Request();
            $request->setLocale($locale);
            $request->attributes->add($atributos);
            $pilha->push($request);
        }
        $twig = static::getContainer()->get(Environment::class);

        return $twig->createTemplate($modelo)->render(['d' => new \DateTimeImmutable('2026-03-05 09:07:00'), 'meia_noite' => new \DateTimeImmutable('2026-03-05 00:00:00')]);
    }
}
