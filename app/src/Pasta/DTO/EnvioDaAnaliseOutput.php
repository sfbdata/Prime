<?php

declare(strict_types=1);

namespace App\Pasta\DTO;

use App\Pasta\Entity\EnvioAoCliente;

/**
 * O que o cartão da Análise IA mostra dos envios dela ao cliente (desenho `mailIAVals`, dc L.5241-5244):
 * "Enviado dd/mm às HH:MM" do último e, na dica, um envio por linha com a cópia.
 */
final readonly class EnvioDaAnaliseOutput
{
    public function __construct(
        public string $quando,
        public string $dica,
    ) {
    }

    /** @param non-empty-list<EnvioAoCliente> $envios do mais antigo ao mais recente */
    public static function de(array $envios): self
    {
        $linhas = array_map(static fn (EnvioAoCliente $e): string => self::dataHora($e->getEm()) . ' para ' . implode(', ', $e->getPara())
            . ($e->getCc() !== [] ? ' · cópia: ' . implode(', ', $e->getCc()) : ''), $envios);

        return new self('Enviado ' . self::dataHora($envios[\count($envios) - 1]->getEm()), implode("\n", $linhas));
    }

    public static function dataHora(\DateTimeImmutable $em): string
    {
        return $em->format('d/m') . ' às ' . $em->format('H:i');
    }
}
